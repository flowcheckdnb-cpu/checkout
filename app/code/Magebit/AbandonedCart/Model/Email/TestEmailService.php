<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Model\Email;

use Magebit\AbandonedCart\Model\Config;
use Magebit\AbandonedCart\Service\Coupon\GeneratedCoupon;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Framework\UrlInterface;
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
    private const SAMPLE_FIRST_NAME = 'Demo';
    private const COUPON_TTL_HOURS = 168;

    /**
     * @param Config $config
     * @param BrandVoiceEmailGenerator $generator
     * @param EmailDispatcher $dispatcher
     * @param StoreManagerInterface $storeManager
     * @param ProductRepositoryInterface $productRepository
     */
    public function __construct(
        private readonly Config $config,
        private readonly BrandVoiceEmailGenerator $generator,
        private readonly EmailDispatcher $dispatcher,
        private readonly StoreManagerInterface $storeManager,
        private readonly ProductRepositoryInterface $productRepository,
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

        $items = [$this->buildSampleItem($store, $storeId)];
        $subtotal = $items[0]->rowTotal;

        $coupon = $this->sampleCoupon($emailType);

        $generated = $this->generator->generate(
            $emailType,
            $storeId,
            self::SAMPLE_FIRST_NAME,
            (string) $store->getName(),
            $items,
            $subtotal,
            self::SAMPLE_CURRENCY,
            $coupon !== null ? $coupon->code : null,
        );

        $extraVars = [
            'recovery_url' => $store->getUrl('checkout/cart'),
            'unsubscribe_url' => $store->getUrl('cms/noroute'),
            'coupon_code' => $coupon !== null ? $coupon->code : '',
            'coupon_expires_at' => $coupon !== null && $coupon->expiresAtUnix !== null
                ? date('M j, Y', $coupon->expiresAtUnix)
                : '',
        ];

        $this->dispatcher->send(
            $storeId,
            $recipientEmail,
            self::SAMPLE_FIRST_NAME,
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
     * Mint a synthetic (non-persisted) coupon for stage_3 / low_stock previews.
     *
     * @param string $emailType
     * @return GeneratedCoupon|null
     */
    private function sampleCoupon(string $emailType): ?GeneratedCoupon
    {
        if ($emailType !== 'stage_3' && $emailType !== 'low_stock') {
            return null;
        }
        $suffix = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        return new GeneratedCoupon(
            code: 'DEMO-' . $suffix,
            expiresAtUnix: time() + self::COUPON_TTL_HOURS * 3600,
        );
    }
}
