<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Cron;

use Magebit\AbandonedCart\Model\Config;
use Magebit\AbandonedCart\Model\Email\BrandVoiceEmailGenerator;
use Magebit\AbandonedCart\Model\Email\CartItemSummary;
use Magebit\AbandonedCart\Model\Email\EmailDispatcher;
use Magebit\AbandonedCart\Model\Finder\AbandonedCartFinder;
use Magebit\AbandonedCart\Model\Log\SendLogRepository;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Cron entry point — scans for stage_1-eligible quotes and dispatches recovery emails.
 */
class ScanAbandonedCarts
{
    private const STAGE = 'stage_1';

    /**
     * @param StoreManagerInterface $storeManager
     * @param Config $config
     * @param AbandonedCartFinder $finder
     * @param BrandVoiceEmailGenerator $generator
     * @param EmailDispatcher $dispatcher
     * @param SendLogRepository $logRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config,
        private readonly AbandonedCartFinder $finder,
        private readonly BrandVoiceEmailGenerator $generator,
        private readonly EmailDispatcher $dispatcher,
        private readonly SendLogRepository $logRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Cron job entry point. Iterates stores, finds eligible quotes, dispatches one email per quote.
     *
     * @return void
     */
    public function execute(): void
    {
        foreach ($this->storeManager->getStores() as $store) {
            $storeIdRaw = $store->getId();
            if (!is_scalar($storeIdRaw)) {
                continue;
            }
            $storeId = (int) $storeIdRaw;
            if (!$this->config->isEnabled($storeId)) {
                continue;
            }

            $storeName = (string) $store->getName();

            foreach ($this->finder->findEligible(self::STAGE, $storeId) as $quote) {
                $this->processQuote($quote, $storeId, $storeName);
            }
        }
    }

    /**
     * Generate, send, and log one recovery email for one quote.
     *
     * @param Quote $quote
     * @param int $storeId
     * @param string $storeName
     * @return void
     */
    private function processQuote(Quote $quote, int $storeId, string $storeName): void
    {
        try {
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

            $template = $this->config->getStageTemplate(self::STAGE, $storeId);
            if ($template === '') {
                return;
            }

            $generated = $this->generator->generate(
                self::STAGE,
                $storeId,
                $firstName,
                $storeName,
                $items,
                $subtotal,
                $currency,
            );

            $this->dispatcher->send(
                $storeId,
                $emailRaw,
                $firstName,
                $template,
                $generated,
            );

            $this->writeLog($quoteId, $emailRaw, $storeId, $generated->aiGenerated);
        } catch (Throwable $e) {
            $this->logger->error(
                'Abandoned cart send failed.',
                [
                    'quote_id' => $quote->getId(),
                    'error' => $e->getMessage(),
                ],
            );
        }
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
     * @param bool $aiGenerated
     * @return void
     * @throws \Magento\Framework\Exception\AlreadyExistsException
     * @throws \Exception
     */
    private function writeLog(int $quoteId, string $email, int $storeId, bool $aiGenerated): void
    {
        $log = $this->logRepository->create();
        $log->setQuoteId($quoteId);
        $log->setCustomerEmail($email);
        $log->setStoreId($storeId);
        $log->setEmailType(self::STAGE);
        $log->setStageKey(self::STAGE);
        $log->setStatus($aiGenerated ? 'sent' : 'fallback');
        $log->setAiGenerated($aiGenerated ? 1 : 0);
        $log->setRecoveryToken(bin2hex(random_bytes(32)));
        $this->logRepository->save($log);
    }
}
