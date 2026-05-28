<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\Phrase;

class Tone implements OptionSourceInterface
{
    public const FRIENDLY = 'friendly';
    public const PROFESSIONAL = 'professional';
    public const PLAYFUL = 'playful';
    public const LUXURY = 'luxury';
    public const URGENT = 'urgent';

    /**
     * Available brand voice tone options.
     *
     * @return array<int, array{value: string, label: Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::FRIENDLY, 'label' => __('Friendly')],
            ['value' => self::PROFESSIONAL, 'label' => __('Professional')],
            ['value' => self::PLAYFUL, 'label' => __('Playful')],
            ['value' => self::LUXURY, 'label' => __('Luxury')],
            ['value' => self::URGENT, 'label' => __('Urgent')],
        ];
    }
}
