<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Ui\Component\Listing\Column;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\Phrase;

/**
 * Source for the "Type" filter dropdown + column label rendering on the send-log grid.
 */
class EmailType implements OptionSourceInterface
{
    /**
     * Map of email_type values to human-readable labels.
     *
     * @return array<int, array{value: string, label: Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'stage_1', 'label' => __('Stage 1 — Initial')],
            ['value' => 'stage_2', 'label' => __('Stage 2 — Follow-up')],
            ['value' => 'stage_3', 'label' => __('Stage 3 — Final + Coupon')],
            ['value' => 'low_stock', 'label' => __('Low Stock Urgency')],
        ];
    }
}
