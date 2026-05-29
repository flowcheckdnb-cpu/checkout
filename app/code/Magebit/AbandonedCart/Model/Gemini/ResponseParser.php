<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Model\Gemini;

use Magebit\AbandonedCart\Model\Email\GeneratedEmail;
use Magento\Framework\Phrase;

/**
 * Parses Gemini generateContent responses into GeneratedEmail DTOs.
 */
class ResponseParser
{
    private const SUBJECT_MAX = 60;
    private const PREHEADER_MAX = 90;

    /**
     * Convert a decoded Gemini response into a GeneratedEmail.
     *
     * @param array $response
     * @phpstan-param array<string, mixed> $response
     * @return GeneratedEmail
     * @throws GeminiException
     */
    public function parse(array $response): GeneratedEmail
    {
        $text = $this->extractText($response);
        $stripped = $this->stripFences($text);

        $decoded = json_decode($stripped, true);
        if (!is_array($decoded)) {
            throw new GeminiException(new Phrase('Gemini response is not valid JSON.'));
        }

        return new GeneratedEmail(
            subject: $this->stringField($decoded, 'subject', self::SUBJECT_MAX),
            preheader: $this->stringField($decoded, 'preheader', self::PREHEADER_MAX),
            bodyMarkdown: $this->bodyField($decoded),
            aiGenerated: true,
        );
    }

    /**
     * Convert a decoded Gemini batch response into a map of stage_key → GeneratedEmail.
     *
     * Per-stage entries that are missing or malformed are skipped silently — the
     * caller is expected to fall back to a static template for any stage not in
     * the returned map.
     *
     * @param array $response
     * @phpstan-param array<string, mixed> $response
     * @return array<string, GeneratedEmail>
     * @throws GeminiException
     */
    public function parseBatch(array $response): array
    {
        $text = $this->extractText($response);
        $stripped = $this->stripFences($text);

        $decoded = json_decode($stripped, true);
        if (!is_array($decoded)) {
            throw new GeminiException(new Phrase('Gemini batch response is not valid JSON.'));
        }

        $out = [];
        foreach ($decoded as $stage => $email) {
            if (!is_string($stage) || !is_array($email)) {
                continue;
            }
            try {
                $out[$stage] = new GeneratedEmail(
                    subject: $this->stringField($email, 'subject', self::SUBJECT_MAX),
                    preheader: $this->stringField($email, 'preheader', self::PREHEADER_MAX),
                    bodyMarkdown: $this->bodyField($email),
                    aiGenerated: true,
                );
            } catch (GeminiException $skipBadStage) {
                unset($skipBadStage);
            }
        }
        if ($out === []) {
            throw new GeminiException(new Phrase('Gemini batch response contained no usable stages.'));
        }
        return $out;
    }

    /**
     * Pull the model's text part from the response envelope.
     *
     * @param array $response
     * @phpstan-param array<string, mixed> $response
     * @return string
     * @throws GeminiException
     */
    private function extractText(array $response): string
    {
        $candidates = $response['candidates'] ?? null;
        if (!is_array($candidates) || !isset($candidates[0]) || !is_array($candidates[0])) {
            throw new GeminiException(new Phrase('Gemini response missing candidates.'));
        }
        $content = $candidates[0]['content'] ?? null;
        if (!is_array($content)) {
            throw new GeminiException(new Phrase('Gemini response missing content.'));
        }
        $parts = $content['parts'] ?? null;
        if (!is_array($parts) || !isset($parts[0]) || !is_array($parts[0])) {
            throw new GeminiException(new Phrase('Gemini response missing parts.'));
        }
        $text = $parts[0]['text'] ?? null;
        if (!is_string($text) || $text === '') {
            throw new GeminiException(new Phrase('Gemini response missing text.'));
        }
        return $text;
    }

    /**
     * Strip optional ``` fences and language tag.
     *
     * @param string $text
     * @return string
     */
    private function stripFences(string $text): string
    {
        $trimmed = trim($text);
        if (!str_starts_with($trimmed, '```')) {
            return $trimmed;
        }
        $trimmed = (string) preg_replace('/^```(?:json)?\s*/i', '', $trimmed);
        $trimmed = (string) preg_replace('/\s*```$/', '', $trimmed);
        return $trimmed;
    }

    /**
     * Extract and length-cap a string field.
     *
     * @param array $decoded
     * @phpstan-param array<mixed, mixed> $decoded
     * @param string $key
     * @param int $maxLength
     * @return string
     * @throws GeminiException
     */
    private function stringField(array $decoded, string $key, int $maxLength): string
    {
        $value = $decoded[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new GeminiException(new Phrase('Gemini response missing field: %1', [$key]));
        }
        $value = trim($value);
        if (mb_strlen($value) > $maxLength) {
            $value = mb_substr($value, 0, $maxLength);
        }
        return $value;
    }

    /**
     * Extract the body_markdown field.
     *
     * @param array $decoded
     * @phpstan-param array<mixed, mixed> $decoded
     * @return string
     * @throws GeminiException
     */
    private function bodyField(array $decoded): string
    {
        $value = $decoded['body_markdown'] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new GeminiException(new Phrase('Gemini response missing body_markdown.'));
        }
        return trim($value);
    }
}
