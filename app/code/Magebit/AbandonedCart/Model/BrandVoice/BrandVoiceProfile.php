<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Model\BrandVoice;

/**
 * Brand voice configuration captured per store-view, fed to the AI prompt.
 */
class BrandVoiceProfile
{
    /**
     * @param string $brandName
     * @param string $voiceDescription
     * @param string $tone
     * @param string $locale
     */
    public function __construct(
        public readonly string $brandName,
        public readonly string $voiceDescription,
        public readonly string $tone,
        public readonly string $locale,
    ) {
    }
}
