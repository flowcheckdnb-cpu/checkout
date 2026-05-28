<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\Phrase;

class GeminiModel implements OptionSourceInterface
{
    public const DEFAULT_MODEL = 'gemini-2.5-flash';

    /**
     * Available Gemini API model identifiers.
     *
     * @return array<int, array{value: string, label: Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'gemini-2.5-flash', 'label' => __('Gemini 2.5 Flash (fast, free tier)')],
            ['value' => 'gemini-2.5-pro', 'label' => __('Gemini 2.5 Pro (higher quality, paid)')],
        ];
    }
}
