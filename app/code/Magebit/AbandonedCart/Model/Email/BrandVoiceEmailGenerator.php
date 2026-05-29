<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Model\Email;

use Magebit\AbandonedCart\Model\BrandVoice\BrandVoiceProfileFactory;
use Magebit\AbandonedCart\Model\Config;
use Magebit\AbandonedCart\Model\Gemini\Client;
use Magebit\AbandonedCart\Model\Gemini\GeminiException;
use Magebit\AbandonedCart\Model\Gemini\PromptBuilder;
use Magebit\AbandonedCart\Model\Gemini\ResponseParser;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates AI-first email body generation, with a static fallback on any Gemini failure.
 */
class BrandVoiceEmailGenerator
{
    /**
     * @param PromptBuilder $promptBuilder
     * @param Client $client
     * @param ResponseParser $parser
     * @param StaticTemplateFallback $fallback
     * @param BrandVoiceProfileFactory $profileFactory
     * @param Config $config
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly PromptBuilder $promptBuilder,
        private readonly Client $client,
        private readonly ResponseParser $parser,
        private readonly StaticTemplateFallback $fallback,
        private readonly BrandVoiceProfileFactory $profileFactory,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Generate emails for multiple stages in a single Gemini call.
     *
     * Per-stage entries Gemini fails to return are filled in from the static
     * fallback template, so the caller always gets one GeneratedEmail per
     * requested stage. The whole batch falls back if Gemini is disabled,
     * uncredentialed, or the request throws.
     *
     * @param string[] $stageKeys
     * @param int $storeId
     * @param string $customerFirstName
     * @param string $storeName
     * @param CartItemSummary[] $cartItems
     * @param float $cartSubtotal
     * @param string $currency
     * @param array $couponCodesByStage Map of stage → coupon code (or null).
     * @phpstan-param array<string, string|null> $couponCodesByStage
     * @return array<string, GeneratedEmail>
     */
    public function generateBatch(
        array $stageKeys,
        int $storeId,
        string $customerFirstName,
        string $storeName,
        array $cartItems,
        float $cartSubtotal,
        string $currency,
        array $couponCodesByStage,
    ): array {
        $profile = $this->profileFactory->forStore($storeId);

        if (!$this->config->isAiEnabled($storeId) || $this->config->getGeminiApiKey($storeId) === '') {
            return $this->fallbackBatch($stageKeys, $customerFirstName, $profile->brandName);
        }

        try {
            $payload = $this->promptBuilder->buildBatch(
                $stageKeys,
                $profile,
                $customerFirstName,
                $storeName,
                $cartItems,
                $cartSubtotal,
                $currency,
                $couponCodesByStage,
            );
            $response = $this->client->send($storeId, $payload);
            $parsed = $this->parser->parseBatch($response);
        } catch (GeminiException $e) {
            $this->logger->warning(
                'Gemini batch generation failed; using static fallback.',
                ['stages' => $stageKeys, 'store_id' => $storeId, 'error' => $e->getMessage()],
            );
            return $this->fallbackBatch($stageKeys, $customerFirstName, $profile->brandName);
        }

        $out = [];
        foreach ($stageKeys as $stage) {
            $out[$stage] = $parsed[$stage]
                ?? $this->fallback->generate($stage, $customerFirstName, $profile->brandName);
        }
        return $out;
    }

    /**
     * Fill every requested stage from the static fallback. One call per stage, no Gemini.
     *
     * @param string[] $stageKeys
     * @param string $customerFirstName
     * @param string $brandName
     * @return array<string, GeneratedEmail>
     */
    private function fallbackBatch(array $stageKeys, string $customerFirstName, string $brandName): array
    {
        $out = [];
        foreach ($stageKeys as $stage) {
            $out[$stage] = $this->fallback->generate($stage, $customerFirstName, $brandName);
        }
        return $out;
    }

    /**
     * Generate the dynamic email content for a given stage and cart.
     *
     * @param string $stageKey
     * @param int $storeId
     * @param string $customerFirstName
     * @param string $storeName
     * @param CartItemSummary[] $cartItems
     * @param float $cartSubtotal
     * @param string $currency
     * @param string|null $couponCode
     * @return GeneratedEmail
     */
    public function generate(
        string $stageKey,
        int $storeId,
        string $customerFirstName,
        string $storeName,
        array $cartItems,
        float $cartSubtotal,
        string $currency,
        ?string $couponCode = null,
    ): GeneratedEmail {
        $profile = $this->profileFactory->forStore($storeId);

        if (!$this->config->isAiEnabled($storeId) || $this->config->getGeminiApiKey($storeId) === '') {
            return $this->fallback->generate($stageKey, $customerFirstName, $profile->brandName);
        }

        try {
            $payload = $this->promptBuilder->build(
                $stageKey,
                $profile,
                $customerFirstName,
                $storeName,
                $cartItems,
                $cartSubtotal,
                $currency,
                $couponCode,
            );
            $response = $this->client->send($storeId, $payload);
            return $this->parser->parse($response);
        } catch (GeminiException $e) {
            $this->logger->warning(
                'Gemini email generation failed; using static fallback.',
                [
                    'stage' => $stageKey,
                    'store_id' => $storeId,
                    'error' => $e->getMessage(),
                ],
            );
            return $this->fallback->generate($stageKey, $customerFirstName, $profile->brandName);
        }
    }
}
