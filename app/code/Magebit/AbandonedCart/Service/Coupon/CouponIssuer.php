<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Service\Coupon;

use Magento\SalesRule\Api\CouponManagementInterface;
use Magento\SalesRule\Api\Data\CouponGenerationSpecInterfaceFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Wraps Magento\SalesRule\Api\CouponManagementInterface to mint single-use coupons
 * from an admin-configured cart price rule that has "Use Auto Generation" enabled.
 */
class CouponIssuer
{
    private const QUANTITY = 1;
    private const LENGTH_DEFAULT = 12;
    private const LENGTH_WITH_PREFIX = 6;
    private const PREFIX_MAX_LENGTH = 10;
    private const FORMAT = 'alphanum';

    /**
     * @param CouponManagementInterface $couponManagement
     * @param CouponGenerationSpecInterfaceFactory $specFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CouponManagementInterface $couponManagement,
        private readonly CouponGenerationSpecInterfaceFactory $specFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Issue one unique coupon code for the given cart price rule.
     *
     * When $prefixHint is provided (typically customer first name), the resulting code
     * looks like "VERONICA-AB3F"; otherwise it's a plain 12-char alphanumeric.
     *
     * @param int $ruleId
     * @param int $ttlHours Hours until expiry (encoded into GeneratedCoupon, not pushed to Magento yet).
     * @param string|null $prefixHint Customer name or email-local-part; sanitized into a prefix.
     * @return GeneratedCoupon|null Null on configuration error, rule lookup failure, or rule lacking auto-generation.
     */
    public function issue(int $ruleId, int $ttlHours, ?string $prefixHint = null): ?GeneratedCoupon
    {
        if ($ruleId === 0) {
            return null;
        }

        $prefix = $this->sanitizePrefix($prefixHint);

        try {
            $spec = $this->specFactory->create();
            $spec->setRuleId($ruleId);
            $spec->setQuantity(self::QUANTITY);
            $spec->setFormat(self::FORMAT);
            if ($prefix !== '') {
                $spec->setPrefix($prefix . '-');
                $spec->setLength(self::LENGTH_WITH_PREFIX);
            } else {
                $spec->setLength(self::LENGTH_DEFAULT);
            }

            $codes = $this->couponManagement->generate($spec);
            if (!is_array($codes) || count($codes) === 0) {
                return null;
            }
            $first = $codes[0] ?? null;
            if (!is_string($first) || $first === '') {
                return null;
            }

            $expires = $ttlHours > 0 ? time() + ($ttlHours * 3600) : null;

            return new GeneratedCoupon(code: $first, expiresAtUnix: $expires);
        } catch (Throwable $e) {
            $this->logger->warning(
                'Magebit AbandonedCart coupon issue failed.',
                [
                    'rule_id' => $ruleId,
                    'error' => $e->getMessage(),
                ],
            );
            return null;
        }
    }

    /**
     * Strip a free-text hint down to an uppercase alphanumeric prefix safe for coupon codes.
     *
     * @param string|null $hint
     * @return string
     */
    private function sanitizePrefix(?string $hint): string
    {
        if ($hint === null || $hint === '') {
            return '';
        }
        $stripped = preg_replace('/[^A-Za-z0-9]/', '', $hint);
        if (!is_string($stripped) || $stripped === '') {
            return '';
        }
        return strtoupper(substr($stripped, 0, self::PREFIX_MAX_LENGTH));
    }
}
