<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Typed wrapper around module config xpaths, scope=store throughout.
 */
class Config
{
    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface $encryptor
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
    ) {
    }

    /**
     * Whether the module is enabled for a given store.
     *
     * @param int $storeId
     * @return bool
     */
    public function isEnabled(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(
            ConfigPath::GENERAL_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId,
        );
    }

    /**
     * Configured sender identity key (e.g. sales, support).
     *
     * @param int $storeId
     * @return string
     */
    public function getSenderIdentity(int $storeId): string
    {
        return $this->getString(ConfigPath::GENERAL_SENDER_EMAIL, $storeId);
    }

    /**
     * Frontend route used for unsubscribe links.
     *
     * @param int $storeId
     * @return string
     */
    public function getUnsubscribeRoute(int $storeId): string
    {
        return $this->getString(ConfigPath::GENERAL_UNSUBSCRIBE_ROUTE, $storeId);
    }

    /**
     * Delay in minutes before a stage email fires.
     *
     * @param string $stageKey
     * @param int $storeId
     * @return int
     */
    public function getStageDelayMinutes(string $stageKey, int $storeId): int
    {
        $paths = ConfigPath::STAGE_PATHS[$stageKey] ?? null;
        if ($paths === null) {
            return 0;
        }
        return $this->getInt($paths[0], $storeId);
    }

    /**
     * Email template id for the given stage.
     *
     * @param string $stageKey
     * @param int $storeId
     * @return string
     */
    public function getStageTemplate(string $stageKey, int $storeId): string
    {
        $paths = ConfigPath::STAGE_PATHS[$stageKey] ?? null;
        if ($paths === null) {
            return '';
        }
        return $this->getString($paths[1], $storeId);
    }

    /**
     * Cart price rule id used to generate stage-3 coupons.
     *
     * @param int $storeId
     * @return int
     */
    public function getStage3CouponRuleId(int $storeId): int
    {
        return $this->getInt(ConfigPath::STAGE_3_COUPON_RULE, $storeId);
    }

    /**
     * Stage-3 coupon validity window in hours.
     *
     * @param int $storeId
     * @return int
     */
    public function getStage3CouponTtlHours(int $storeId): int
    {
        return $this->getInt(ConfigPath::STAGE_3_COUPON_TTL_HOURS, $storeId);
    }

    /**
     * Whether the low-stock urgency email type is enabled.
     *
     * @param int $storeId
     * @return bool
     */
    public function isLowStockEnabled(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(
            ConfigPath::LOW_STOCK_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId,
        );
    }

    /**
     * Stock-qty threshold below which a cart item triggers urgency emails.
     *
     * @param int $storeId
     * @return int
     */
    public function getLowStockThreshold(int $storeId): int
    {
        return $this->getInt(ConfigPath::LOW_STOCK_THRESHOLD, $storeId);
    }

    /**
     * Email template id for the low-stock urgency email.
     *
     * @param int $storeId
     * @return string
     */
    public function getLowStockTemplate(int $storeId): string
    {
        return $this->getString(ConfigPath::LOW_STOCK_TEMPLATE, $storeId);
    }

    /**
     * Cart price rule id used to generate low-stock urgency coupons.
     *
     * @param int $storeId
     * @return int
     */
    public function getLowStockCouponRuleId(int $storeId): int
    {
        return $this->getInt(ConfigPath::LOW_STOCK_COUPON_RULE, $storeId);
    }

    /**
     * Low-stock coupon validity window in hours.
     *
     * @param int $storeId
     * @return int
     */
    public function getLowStockCouponTtlHours(int $storeId): int
    {
        return $this->getInt(ConfigPath::LOW_STOCK_COUPON_TTL_HOURS, $storeId);
    }

    /**
     * Minimum hours between low-stock emails to the same cart.
     *
     * @param int $storeId
     * @return int
     */
    public function getLowStockFrequencyCapHours(int $storeId): int
    {
        return $this->getInt(ConfigPath::LOW_STOCK_FREQUENCY_CAP, $storeId);
    }

    /**
     * Configured brand name (used in greetings and AI prompt).
     *
     * @param int $storeId
     * @return string
     */
    public function getBrandName(int $storeId): string
    {
        return $this->getString(ConfigPath::BRAND_NAME, $storeId);
    }

    /**
     * Free-text voice description shaping AI output.
     *
     * @param int $storeId
     * @return string
     */
    public function getVoiceDescription(int $storeId): string
    {
        return $this->getString(ConfigPath::BRAND_VOICE_DESC, $storeId);
    }

    /**
     * Selected tone keyword (friendly|professional|playful|luxury|urgent).
     *
     * @param int $storeId
     * @return string
     */
    public function getBrandTone(int $storeId): string
    {
        return $this->getString(ConfigPath::BRAND_TONE, $storeId);
    }

    /**
     * Configured locale for AI output, e.g. en_US.
     *
     * @param int $storeId
     * @return string
     */
    public function getBrandLocale(int $storeId): string
    {
        return $this->getString(ConfigPath::BRAND_LOCALE, $storeId);
    }

    /**
     * Whether AI generation is enabled (kill-switch).
     *
     * @param int $storeId
     * @return bool
     */
    public function isAiEnabled(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(
            ConfigPath::GEMINI_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId,
        );
    }

    /**
     * Decrypted Gemini API key.
     *
     * @param int $storeId
     * @return string
     */
    public function getGeminiApiKey(int $storeId): string
    {
        $encrypted = $this->getString(ConfigPath::GEMINI_API_KEY, $storeId);
        if ($encrypted === '') {
            return '';
        }
        return $this->encryptor->decrypt($encrypted);
    }

    /**
     * Configured Gemini model identifier.
     *
     * @param int $storeId
     * @return string
     */
    public function getGeminiModel(int $storeId): string
    {
        return $this->getString(ConfigPath::GEMINI_MODEL, $storeId);
    }

    /**
     * HTTP request timeout for Gemini calls, in seconds.
     *
     * @param int $storeId
     * @return int
     */
    public function getGeminiTimeoutSeconds(int $storeId): int
    {
        return $this->getInt(ConfigPath::GEMINI_TIMEOUT, $storeId);
    }

    /**
     * Read a config value as string, defensively.
     *
     * @param string $path
     * @param int $storeId
     * @return string
     */
    private function getString(string $path, int $storeId): string
    {
        $value = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Read a config value as int, defensively.
     *
     * @param string $path
     * @param int $storeId
     * @return int
     */
    private function getInt(string $path, int $storeId): int
    {
        $value = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
        return is_numeric($value) ? (int) $value : 0;
    }
}
