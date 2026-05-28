<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Model\BrandVoice;

use Magebit\AbandonedCart\Model\Config;

/**
 * Builds a BrandVoiceProfile from store-scoped config.
 */
class BrandVoiceProfileFactory
{
    /**
     * @param Config $config
     */
    public function __construct(
        private readonly Config $config,
    ) {
    }

    /**
     * Assemble the brand voice profile for a store view.
     *
     * @param int $storeId
     * @return BrandVoiceProfile
     */
    public function forStore(int $storeId): BrandVoiceProfile
    {
        $brand = $this->config->getBrandName($storeId);
        if ($brand === '') {
            $brand = 'Our Store';
        }

        $locale = $this->config->getBrandLocale($storeId);
        if ($locale === '') {
            $locale = 'en_US';
        }

        $tone = $this->config->getBrandTone($storeId);
        if ($tone === '') {
            $tone = 'friendly';
        }

        return new BrandVoiceProfile(
            brandName: $brand,
            voiceDescription: $this->config->getVoiceDescription($storeId),
            tone: $tone,
            locale: $locale,
        );
    }
}
