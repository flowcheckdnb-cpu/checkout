<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Model\Email;

use Magebit\AbandonedCart\Model\Config;
use Magebit\AbandonedCart\Service\Coupon\GeneratedCoupon;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Builds + dispatches a test email from a synthetic cart so admins can preview
 * the rendered design without waiting for a real abandonment.
 *
 * Synthetic on purpose: no real coupon is minted (no salesrule_coupon row
 * created), no quote is touched, no send-log entry is written.
 */
class TestEmailService
{
    private const SAMPLE_PRODUCT = 'Iris Workout Top (sample)';
    private const SAMPLE_PRICE = 49.99;
    private const SAMPLE_CURRENCY = 'USD';
    private const SAMPLE_FIRST_NAME = 'Demo';
    private const COUPON_TTL_HOURS = 168;

    /**
     * @param Config $config
     * @param BrandVoiceEmailGenerator $generator
     * @param EmailDispatcher $dispatcher
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly Config $config,
        private readonly BrandVoiceEmailGenerator $generator,
        private readonly EmailDispatcher $dispatcher,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    /**
     * Send one test email of the given type to the given recipient.
     *
     * @param string $emailType One of stage_1|stage_2|stage_3|low_stock.
     * @param string $recipientEmail
     * @param int $storeId
     * @return void
     * @throws LocalizedException
     */
    public function send(string $emailType, string $recipientEmail, int $storeId): void
    {
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

        $items = [
            new CartItemSummary(
                name: self::SAMPLE_PRODUCT,
                qty: 1.0,
                rowTotal: self::SAMPLE_PRICE,
            ),
        ];

        $coupon = $this->sampleCoupon($emailType);

        $generated = $this->generator->generate(
            $emailType,
            $storeId,
            self::SAMPLE_FIRST_NAME,
            (string) $store->getName(),
            $items,
            self::SAMPLE_PRICE,
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
