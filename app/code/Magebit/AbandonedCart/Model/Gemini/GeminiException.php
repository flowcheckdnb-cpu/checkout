<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Model\Gemini;

use Magento\Framework\Exception\LocalizedException;

/**
 * Raised when a Gemini API call or response parse fails. Triggers static-template fallback.
 */
class GeminiException extends LocalizedException
{
}
