<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Model\Gemini;

use Magebit\AbandonedCart\Model\Config;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Phrase;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * HTTP transport for Google Gemini's generateContent endpoint.
 */
class Client
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    /**
     * @param Curl $curl
     * @param SerializerInterface $serializer
     * @param Config $config
     */
    public function __construct(
        private readonly Curl $curl,
        private readonly SerializerInterface $serializer,
        private readonly Config $config,
    ) {
    }

    /**
     * Send a generateContent request and return the decoded JSON response.
     *
     * @param int $storeId
     * @param array $payload
     * @phpstan-param array<string, mixed> $payload
     * @return array
     * @phpstan-return array<string, mixed>
     * @throws GeminiException
     */
    public function send(int $storeId, array $payload): array
    {
        $apiKey = $this->config->getGeminiApiKey($storeId);
        if ($apiKey === '') {
            throw new GeminiException(new Phrase('Gemini API key not configured.'));
        }

        $model = $this->config->getGeminiModel($storeId);
        if ($model === '') {
            throw new GeminiException(new Phrase('Gemini model not configured.'));
        }

        $timeout = max(5, $this->config->getGeminiTimeoutSeconds($storeId));
        $url = sprintf(self::ENDPOINT, rawurlencode($model)) . '?key=' . rawurlencode($apiKey);

        $body = $this->serializer->serialize($payload);
        if (!is_string($body)) {
            throw new GeminiException(new Phrase('Failed to serialize Gemini request.'));
        }

        $this->curl->setHeaders(['Content-Type' => 'application/json']);
        $this->curl->setOptions([
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_RETURNTRANSFER => true,
        ]);

        try {
            $this->curl->post($url, $body);
        } catch (\Exception $e) {
            throw new GeminiException(
                new Phrase('Gemini HTTP error: %1', [$e->getMessage()]),
                $e,
            );
        }

        $status = $this->curl->getStatus();
        $responseBody = $this->curl->getBody();

        if ($status < 200 || $status >= 300) {
            throw new GeminiException(
                new Phrase('Gemini API returned HTTP %1: %2', [$status, $responseBody]),
            );
        }

        $decoded = $this->serializer->unserialize($responseBody);
        if (!is_array($decoded)) {
            throw new GeminiException(new Phrase('Gemini API response is not a JSON object.'));
        }

        return $decoded;
    }
}
