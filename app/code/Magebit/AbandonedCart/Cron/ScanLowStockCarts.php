<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Cron;

use Magebit\AbandonedCart\Model\Config;
use Magebit\AbandonedCart\Model\Email\BrandVoiceEmailGenerator;
use Magebit\AbandonedCart\Model\Email\CartItemSummary;
use Magebit\AbandonedCart\Model\Email\EmailDispatcher;
use Magebit\AbandonedCart\Model\Finder\LowStockCartFinder;
use Magebit\AbandonedCart\Model\Log\SendLogRepository;
use Magebit\AbandonedCart\Service\Coupon\CouponIssuer;
use Magebit\AbandonedCart\Service\Coupon\GeneratedCoupon;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Cron entry point — fires the low-stock urgency email (with coupon) when items in
 * an active cart drop to or below the configured threshold.
 */
class ScanLowStockCarts
{
    private const STAGE_PREFIX = 'low_stock';
    private const RECOVERY_ROUTE = 'abandonedcart/recovery/index';
    private const UNSUBSCRIBE_ROUTE = 'abandonedcart/unsubscribe/index';

    /**
     * @param StoreManagerInterface $storeManager
     * @param Config $config
     * @param LowStockCartFinder $finder
     * @param BrandVoiceEmailGenerator $generator
     * @param EmailDispatcher $dispatcher
     * @param SendLogRepository $logRepository
     * @param CouponIssuer $couponIssuer
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config,
        private readonly LowStockCartFinder $finder,
        private readonly BrandVoiceEmailGenerator $generator,
        private readonly EmailDispatcher $dispatcher,
        private readonly SendLogRepository $logRepository,
        private readonly CouponIssuer $couponIssuer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Cron job entry point. One urgency email per cart per dedup window (daily by default).
     *
     * @return void
     */
    public function execute(): void
    {
        $stageKey = self::STAGE_PREFIX . ':' . date('Y-m-d');

        foreach ($this->storeManager->getStores() as $store) {
            if (!$store instanceof Store) {
                continue;
            }
            $storeIdRaw = $store->getId();
            if (!is_scalar($storeIdRaw)) {
                continue;
            }
            $storeId = (int) $storeIdRaw;
            if (!$this->config->isEnabled($storeId)) {
                continue;
            }

            foreach ($this->finder->findEligible($storeId, $stageKey) as $quote) {
                $this->processQuote($quote, $store, $stageKey);
            }
        }
    }

    /**
     * Generate, send, and log one urgency email for one quote.
     *
     * @param Quote $quote
     * @param Store $store
     * @param string $stageKey
     * @return void
     */
    private function processQuote(Quote $quote, Store $store, string $stageKey): void
    {
        try {
            $storeId = (int) $store->getId();
            $storeName = (string) $store->getName();

            $quoteIdRaw = $quote->getId();
            if (!is_scalar($quoteIdRaw)) {
                return;
            }
            $quoteId = (int) $quoteIdRaw;

            $emailRaw = $quote->getCustomerEmail();
            if (!is_string($emailRaw) || $emailRaw === '') {
                return;
            }

            $firstNameRaw = $quote->getCustomerFirstname();
            $firstName = is_string($firstNameRaw) ? $firstNameRaw : '';

            $subtotalRaw = $quote->getSubtotal();
            $subtotal = is_numeric($subtotalRaw) ? (float) $subtotalRaw : 0.0;

            $currencyRaw = $quote->getQuoteCurrencyCode();
            $currency = is_string($currencyRaw) ? $currencyRaw : '';

            $items = $this->extractItems($quote);

            $template = $this->config->getLowStockTemplate($storeId);
            if ($template === '') {
                return;
            }

            $coupon = $this->maybeIssueCoupon($storeId);

            $recoveryToken = bin2hex(random_bytes(32));
            $recoveryUrl = $store->getUrl(self::RECOVERY_ROUTE, [
                '_query' => ['t' => $recoveryToken],
            ]);
            $unsubscribeUrl = $store->getUrl(self::UNSUBSCRIBE_ROUTE, [
                '_query' => ['t' => $recoveryToken],
            ]);

            $generated = $this->generator->generate(
                self::STAGE_PREFIX,
                $storeId,
                $firstName,
                $storeName,
                $items,
                $subtotal,
                $currency,
                $coupon?->code,
            );

            $extraVars = [
                'recovery_url' => $recoveryUrl,
                'unsubscribe_url' => $unsubscribeUrl,
                'coupon_code' => $coupon !== null ? $coupon->code : '',
                'coupon_expires_at' => $coupon !== null && $coupon->expiresAtUnix !== null
                    ? date('M j, Y', $coupon->expiresAtUnix)
                    : '',
            ];

            $this->dispatcher->send(
                $storeId,
                $emailRaw,
                $firstName,
                $template,
                $generated,
                $extraVars,
            );

            $this->writeLog(
                $quoteId,
                $emailRaw,
                $storeId,
                $stageKey,
                $generated->aiGenerated,
                $recoveryToken,
                $coupon?->code,
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'Low-stock urgency send failed.',
                [
                    'quote_id' => $quote->getId(),
                    'error' => $e->getMessage(),
                ],
            );
        }
    }

    /**
     * Mint a coupon from the low-stock cart price rule, if configured.
     *
     * @param int $storeId
     * @return GeneratedCoupon|null
     */
    private function maybeIssueCoupon(int $storeId): ?GeneratedCoupon
    {
        $ruleId = $this->config->getLowStockCouponRuleId($storeId);
        if ($ruleId === 0) {
            return null;
        }
        $ttlHours = $this->config->getLowStockCouponTtlHours($storeId);
        return $this->couponIssuer->issue($ruleId, $ttlHours);
    }

    /**
     * Build CartItemSummary DTOs from quote items.
     *
     * @param Quote $quote
     * @return CartItemSummary[]
     */
    private function extractItems(Quote $quote): array
    {
        $items = [];
        foreach ($quote->getAllVisibleItems() as $item) {
            $nameRaw = $item->getName();
            $qtyRaw = $item->getQty();
            $rowRaw = $item->getRowTotal();
            $items[] = new CartItemSummary(
                name: is_string($nameRaw) ? $nameRaw : '',
                qty: is_numeric($qtyRaw) ? (float) $qtyRaw : 0.0,
                rowTotal: is_numeric($rowRaw) ? (float) $rowRaw : 0.0,
            );
        }
        return $items;
    }

    /**
     * Persist a send-log entry.
     *
     * @param int $quoteId
     * @param string $email
     * @param int $storeId
     * @param string $stageKey
     * @param bool $aiGenerated
     * @param string $recoveryToken
     * @param string|null $couponCode
     * @return void
     * @throws \Magento\Framework\Exception\AlreadyExistsException
     * @throws \Exception
     */
    private function writeLog(
        int $quoteId,
        string $email,
        int $storeId,
        string $stageKey,
        bool $aiGenerated,
        string $recoveryToken,
        ?string $couponCode,
    ): void {
        $log = $this->logRepository->create();
        $log->setQuoteId($quoteId);
        $log->setCustomerEmail($email);
        $log->setStoreId($storeId);
        $log->setEmailType(self::STAGE_PREFIX);
        $log->setStageKey($stageKey);
        $log->setStatus($aiGenerated ? 'sent' : 'fallback');
        $log->setAiGenerated($aiGenerated ? 1 : 0);
        $log->setRecoveryToken($recoveryToken);
        if ($couponCode !== null) {
            $log->setCouponCode($couponCode);
        }
        $this->logRepository->save($log);
    }
}
