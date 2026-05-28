<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Ui\Component\Listing\Column;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\Phrase;

/**
 * Source for the "Status" filter dropdown on the send-log grid.
 */
class Status implements OptionSourceInterface
{
    /**
     * Map of status values to human-readable labels.
     *
     * @return array<int, array{value: string, label: Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'sent', 'label' => __('Sent (AI)')],
            ['value' => 'fallback', 'label' => __('Sent (Static Fallback)')],
            ['value' => 'failed', 'label' => __('Failed')],
        ];
    }
}
