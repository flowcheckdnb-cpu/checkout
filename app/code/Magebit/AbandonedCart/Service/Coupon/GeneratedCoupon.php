<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Service\Coupon;

/**
 * Result of one coupon generation — code + optional expiry timestamp.
 */
class GeneratedCoupon
{
    /**
     * @param string $code
     * @param int|null $expiresAtUnix
     */
    public function __construct(
        public readonly string $code,
        public readonly ?int $expiresAtUnix,
    ) {
    }
}
