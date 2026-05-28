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
    private const LENGTH = 12;
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
     * @param int $ruleId
     * @param int $ttlHours Hours until expiry (encoded into GeneratedCoupon, not pushed to Magento yet).
     * @return GeneratedCoupon|null Null on configuration error, rule lookup failure, or rule lacking auto-generation.
     */
    public function issue(int $ruleId, int $ttlHours): ?GeneratedCoupon
    {
        if ($ruleId === 0) {
            return null;
        }

        try {
            $spec = $this->specFactory->create();
            $spec->setRuleId($ruleId);
            $spec->setQuantity(self::QUANTITY);
            $spec->setLength(self::LENGTH);
            $spec->setFormat(self::FORMAT);

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
}
