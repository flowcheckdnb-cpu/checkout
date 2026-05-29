<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Model\Email;

use Magebit\AbandonedCart\Model\Config;
use Magebit\AbandonedCart\Service\Coupon\CouponIssuer;
use Magebit\AbandonedCart\Service\Coupon\GeneratedCoupon;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Notification\NotifierInterface;
use Magento\Framework\Phrase;
use Magento\Framework\UrlInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Throwable;

/**
 * Builds + dispatches a test email from a synthetic cart so admins can preview
 * the rendered design without waiting for a real abandonment.
 *
 * Synthetic on purpose: no real coupon is minted (no salesrule_coupon row
 * created), no quote is touched, no send-log entry is written. The sample
 * product is pulled from the live catalog when available so the email looks
 * like a real send (with thumbnail + clickable name).
 */
class TestEmailService
{
    public const ALL_STAGES = ['stage_1', 'stage_2', 'stage_3', 'low_stock'];
    private const SAMPLE_SKUS = ['24-MB01', 'WS03', 'WS04', 'WJ01'];
    private const FALLBACK_PRODUCT_NAME = 'Iris Workout Top (sample)';
    private const FALLBACK_PRICE = 49.99;
    private const SAMPLE_CURRENCY = 'USD';
    private const FALLBACK_FIRST_NAME = 'there';
    private const COUPON_TTL_HOURS = 168;

    /**
     * @param Config $config
     * @param BrandVoiceEmailGenerator $generator
     * @param EmailDispatcher $dispatcher
     * @param StoreManagerInterface $storeManager
     * @param ProductRepositoryInterface $productRepository
     * @param NotifierInterface $notifier
     * @param BackendUrl $backendUrl
     * @param CustomerRepositoryInterface $customerRepository
     * @param CartRepositoryInterface $cartRepository
     * @param CouponIssuer $couponIssuer
     */
    public function __construct(
        private readonly Config $config,
        private readonly BrandVoiceEmailGenerator $generator,
        private readonly EmailDispatcher $dispatcher,
        private readonly StoreManagerInterface $storeManager,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly NotifierInterface $notifier,
        private readonly BackendUrl $backendUrl,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly CouponIssuer $couponIssuer,
    ) {
    }

    /**
     * Send one or all test emails to the given recipient.
     *
     * @param string $emailType "all" to fire every stage in sequence, otherwise one of
     *                          stage_1|stage_2|stage_3|low_stock.
     * @param string $recipientEmail
     * @param int $storeId
     * @return void
     * @throws LocalizedException
     */
    public function send(string $emailType, string $recipientEmail, int $storeId): void
    {
        if ($emailType === 'all') {
            foreach (self::ALL_STAGES as $stage) {
                $this->send($stage, $recipientEmail, $storeId);
            }
            $this->pushSummaryNotification($recipientEmail);
            return;
        }

        if ($recipientEmail === '' || filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new LocalizedException(new Phrase('Invalid recipient email.'));
        }

        $store = $this->storeManager->getStore($storeId);
        if (!$store instanceof Store) {
            throw new LocalizedException(new Phrase('Invalid store id: %1', [$storeId]));
        }

        $template = $this->resolveTemplate($emailType, $storeId);
        if ($template === '') {
            throw new LocalizedException(
                new Phrase('No template configured for email type: %1', [$emailType]),
            );
        }

        $recipientFirstName = $this->resolveFirstName($recipientEmail, $store);
        $realCartItems = $this->loadRealCartItems($recipientEmail, $store);
        if ($realCartItems !== []) {
            $items = $realCartItems;
            $subtotal = 0.0;
            foreach ($items as $row) {
                $subtotal += $row->rowTotal;
            }
        } else {
            $items = [$this->buildSampleItem($store, $storeId)];
            $subtotal = $items[0]->rowTotal;
        }

        $coupon = $this->sampleCoupon($emailType, $storeId, $recipientFirstName);

        $generated = $this->generator->generate(
            $emailType,
            $storeId,
            $recipientFirstName,
            (string) $store->getName(),
            $items,
            $subtotal,
            self::SAMPLE_CURRENCY,
            $coupon !== null ? $coupon->code : null,
        );

        // $store->getUrl() resolves via the area-scoped UrlInterface — under
        // adminhtml that's Backend\Model\Url which prepends /admin/ and the
        // admin secret key. For preview emails we always want frontend URLs,
        // so we build them from the store's frontend base URL directly.
        $baseUrl = rtrim($store->getBaseUrl(UrlInterface::URL_TYPE_LINK), '/');
        $extraVars = [
            'recovery_url' => $baseUrl . '/checkout/cart/',
            'unsubscribe_url' => $baseUrl . '/cms/noroute/',
            'coupon_code' => $coupon !== null ? $coupon->code : '',
            'coupon_expires_at' => $coupon !== null && $coupon->expiresAtUnix !== null
                ? date('M j, Y', $coupon->expiresAtUnix)
                : '',
        ];

        $this->dispatcher->send(
            $storeId,
            $recipientEmail,
            $recipientFirstName,
            $template,
            $generated,
            $items,
            self::SAMPLE_CURRENCY,
            $extraVars,
        );
    }

    /**
     * Resolve the configured email template id for the given type.
     *
     * @param string $emailType
     * @param int $storeId
     * @return string
     */
    private function resolveTemplate(string $emailType, int $storeId): string
    {
        if ($emailType === 'low_stock') {
            return $this->config->getLowStockTemplate($storeId);
        }
        return $this->config->getStageTemplate($emailType, $storeId);
    }

    /**
     * Build a CartItemSummary from a real sample product, falling back to a name-only placeholder.
     *
     * @param Store $store
     * @param int $storeId
     * @return CartItemSummary
     */
    private function buildSampleItem(Store $store, int $storeId): CartItemSummary
    {
        $product = $this->loadSampleProduct($storeId);
        if ($product === null) {
            return new CartItemSummary(
                name: self::FALLBACK_PRODUCT_NAME,
                qty: 1.0,
                rowTotal: self::FALLBACK_PRICE,
            );
        }

        $mediaBase = $store->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
        $imageUrl = '';
        $thumbRaw = $product->getData('thumbnail');
        if (is_string($thumbRaw) && $thumbRaw !== '' && $thumbRaw !== 'no_selection') {
            $imageUrl = rtrim($mediaBase, '/') . '/catalog/product/' . ltrim($thumbRaw, '/');
        }

        $productUrl = '';
        $urlRaw = $product->getProductUrl();
        if (is_string($urlRaw)) {
            $productUrl = $urlRaw;
        }

        $nameRaw = $product->getName();
        $name = is_string($nameRaw) && $nameRaw !== '' ? $nameRaw : self::FALLBACK_PRODUCT_NAME;

        $priceRaw = $product->getFinalPrice();
        $price = is_numeric($priceRaw) && (float) $priceRaw > 0
            ? (float) $priceRaw
            : self::FALLBACK_PRICE;

        return new CartItemSummary(
            name: $name,
            qty: 1.0,
            rowTotal: $price,
            imageUrl: $imageUrl,
            productUrl: $productUrl,
        );
    }

    /**
     * Try a small list of known sample-data SKUs; return the first one that exists in the store.
     *
     * @param int $storeId
     * @return Product|null
     */
    private function loadSampleProduct(int $storeId): ?Product
    {
        foreach (self::SAMPLE_SKUS as $sku) {
            try {
                $product = $this->productRepository->get($sku, false, $storeId);
            } catch (NoSuchEntityException) {
                continue;
            } catch (Throwable) {
                return null;
            }
            if ($product instanceof Product) {
                return $product;
            }
        }
        return null;
    }

    /**
     * Mint a real (persisted) coupon via CouponIssuer for stage_3 / low_stock previews.
     *
     * Falls back to a synthetic local-only code if no rule is configured for the
     * given stage or if minting fails, so the email still renders. Note that a
     * synthetic code will not validate at checkout.
     *
     * @param string $emailType
     * @param int $storeId
     * @param string $recipientFirstName
     * @return GeneratedCoupon|null
     */
    private function sampleCoupon(
        string $emailType,
        int $storeId,
        string $recipientFirstName,
    ): ?GeneratedCoupon {
        if ($emailType !== 'stage_3' && $emailType !== 'low_stock') {
            return null;
        }
        [$ruleId, $ttlHours] = $emailType === 'stage_3'
            ? [$this->config->getStage3CouponRuleId($storeId), $this->config->getStage3CouponTtlHours($storeId)]
            : [$this->config->getLowStockCouponRuleId($storeId), $this->config->getLowStockCouponTtlHours($storeId)];

        if ($ruleId !== 0) {
            try {
                return $this->couponIssuer->issue($ruleId, $ttlHours, $recipientFirstName);
            } catch (Throwable $mintFailed) {
                unset($mintFailed);
            }
        }

        $cleaned = preg_replace('/[^A-Z]/', '', strtoupper($recipientFirstName));
        $prefix = is_string($cleaned) && $cleaned !== '' ? substr($cleaned, 0, 10) : 'DEMO';
        $suffix = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        return new GeneratedCoupon(
            code: $prefix . '-' . $suffix,
            expiresAtUnix: time() + self::COUPON_TTL_HOURS * 3600,
        );
    }

    /**
     * Look up the recipient's active cart and return its items as DTOs, or [] if none.
     *
     * @param string $recipientEmail
     * @param Store $store
     * @return CartItemSummary[]
     */
    private function loadRealCartItems(string $recipientEmail, Store $store): array
    {
        $quote = $this->loadActiveQuoteFor($recipientEmail, $store);
        if ($quote === null) {
            return [];
        }
        $mediaBase = $store->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
        $rows = [];
        foreach ($quote->getAllVisibleItems() as $item) {
            $rows[] = $this->quoteItemToSummary($item, $mediaBase);
        }
        return $rows;
    }

    /**
     * Resolve the recipient's active customer quote, or null if not found.
     *
     * @param string $recipientEmail
     * @param Store $store
     * @return Quote|null
     */
    private function loadActiveQuoteFor(string $recipientEmail, Store $store): ?Quote
    {
        $websiteIdRaw = $store->getWebsiteId();
        if (!is_scalar($websiteIdRaw)) {
            return null;
        }
        try {
            $customer = $this->customerRepository->get($recipientEmail, (int) $websiteIdRaw);
            $customerIdRaw = $customer->getId();
            if (!is_scalar($customerIdRaw)) {
                return null;
            }
            $quote = $this->cartRepository->getActiveForCustomer((int) $customerIdRaw);
        } catch (Throwable) {
            return null;
        }
        return $quote instanceof Quote ? $quote : null;
    }

    /**
     * Convert one quote item into a CartItemSummary, resolving image + URL from its product.
     *
     * @param \Magento\Quote\Model\Quote\Item $item
     * @param string $mediaBase
     * @return CartItemSummary
     */
    private function quoteItemToSummary(\Magento\Quote\Model\Quote\Item $item, string $mediaBase): CartItemSummary
    {
        $nameRaw = $item->getName();
        $qtyRaw = $item->getQty();
        $rowRaw = $item->getRowTotal();
        $imageUrl = '';
        $productUrl = '';
        $product = $item->getProduct();
        if ($product !== null) {
            $thumbRaw = $product->getData('thumbnail');
            if (is_string($thumbRaw) && $thumbRaw !== '' && $thumbRaw !== 'no_selection') {
                $imageUrl = rtrim($mediaBase, '/') . '/catalog/product/' . ltrim($thumbRaw, '/');
            }
            $urlRaw = $product->getProductUrl();
            if (is_string($urlRaw)) {
                $productUrl = $urlRaw;
            }
        }
        return new CartItemSummary(
            name: is_string($nameRaw) ? $nameRaw : '',
            qty: is_numeric($qtyRaw) ? (float) $qtyRaw : 0.0,
            rowTotal: is_numeric($rowRaw) ? (float) $rowRaw : 0.0,
            imageUrl: $imageUrl,
            productUrl: $productUrl,
        );
    }

    /**
     * Resolve the recipient's first name via customer lookup, falling back to a polite generic.
     *
     * @param string $recipientEmail
     * @param Store $store
     * @return string
     */
    private function resolveFirstName(string $recipientEmail, Store $store): string
    {
        $websiteIdRaw = $store->getWebsiteId();
        if (!is_scalar($websiteIdRaw)) {
            return self::FALLBACK_FIRST_NAME;
        }
        try {
            $customer = $this->customerRepository->get($recipientEmail, (int) $websiteIdRaw);
        } catch (Throwable) {
            return self::FALLBACK_FIRST_NAME;
        }
        $firstNameRaw = $customer->getFirstname();
        if (!is_string($firstNameRaw) || $firstNameRaw === '') {
            return self::FALLBACK_FIRST_NAME;
        }
        return $firstNameRaw;
    }

    /**
     * Drop a summary line into the admin's bell-icon inbox after a successful
     * "all stages" demo run, with a click-through to the send-log grid.
     *
     * @param string $recipientEmail
     * @return void
     */
    private function pushSummaryNotification(string $recipientEmail): void
    {
        $url = $this->backendUrl->getUrl('magebit_abandonedcart/sendlog/index');
        $this->notifier->addMajor(
            (string) __('Abandoned-cart demo emails dispatched'),
            (string) __(
                '4 preview emails (stage 1, 2, 3, low-stock) were sent to %1.'
                . ' Open Mailpit to view them.',
                $recipientEmail,
            ),
            $url,
        );
    }
}
