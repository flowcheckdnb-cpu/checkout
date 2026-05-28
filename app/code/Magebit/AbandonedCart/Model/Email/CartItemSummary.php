<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Model\Email;

/**
 * Lightweight per-item snapshot passed into AI prompts and email templates.
 */
class CartItemSummary
{
    /**
     * @param string $name
     * @param float $qty
     * @param float $rowTotal
     * @param string $imageUrl
     * @param string $productUrl
     */
    public function __construct(
        public readonly string $name,
        public readonly float $qty,
        public readonly float $rowTotal,
        public readonly string $imageUrl = '',
        public readonly string $productUrl = '',
    ) {
    }
}
