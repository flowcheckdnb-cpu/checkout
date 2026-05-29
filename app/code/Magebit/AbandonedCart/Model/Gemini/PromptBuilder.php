<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Model\Gemini;

use Magebit\AbandonedCart\Model\BrandVoice\BrandVoiceProfile;
use Magebit\AbandonedCart\Model\Email\CartItemSummary;

/**
 * Assembles the Gemini generateContent request body for an abandoned-cart email.
 */
class PromptBuilder
{
    private const STAGE_DESCRIPTORS = [
        'stage_1' => 'first reminder, sent shortly after abandonment.'
            . ' Cart is warm. Gentle nudge, no pressure.',
        'stage_2' => 'follow-up reminder, sent the next day.'
            . ' Customer is drifting. Re-establish value.',
        'stage_3' => 'final outreach, sent ~72 hours after abandonment.'
            . ' Carries a discount code. Convert or lose them.',
        'low_stock' => 'urgency notice — items in the cart are running low.'
            . ' Carries a discount code. Scarcity is real, not invented.',
    ];

    /**
     * Distinct rhetorical approaches the AI rotates through to avoid stale phrasing.
     * One is picked at random per send.
     */
    private const STYLE_VARIANTS = [
        'Open with a curious question that invites reflection — no greeting line first.',
        'Lead with a vivid observation about the cart contents. Skip the greeting entirely.',
        'Be playful and a touch cheeky. Light humor, a bit of personality. Avoid corporate phrasing.',
        'Be unusually brief — three tight sentences, every word earned. Respect the reader\'s time.',
        'Open with a small, relatable moment (a thought, a scene, a Tuesday afternoon).'
            . ' Make it feel human.',
        'Write like a friend texting. Casual, contractions, warm. The customer is not a "valued shopper".',
        'Lead with a direct, confident statement of what you noticed. No fluff, no greeting boilerplate.',
    ];

    /**
     * Build the full Gemini API request payload.
     *
     * @param string $stageKey
     * @param BrandVoiceProfile $voice
     * @param string $customerFirstName
     * @param string $storeName
     * @param CartItemSummary[] $cartItems
     * @param float $cartSubtotal
     * @param string $currency
     * @param string|null $couponCode
     * @return array<string, mixed>
     */
    public function build(
        string $stageKey,
        BrandVoiceProfile $voice,
        string $customerFirstName,
        string $storeName,
        array $cartItems,
        float $cartSubtotal,
        string $currency,
        ?string $couponCode = null,
    ): array {
        $style = $this->pickStyle();

        return [
            'systemInstruction' => [
                'parts' => [['text' => $this->systemText($voice)]],
            ],
            'contents' => [[
                'role' => 'user',
                'parts' => [[
                    'text' => $this->userText(
                        $stageKey,
                        $customerFirstName,
                        $storeName,
                        $cartItems,
                        $cartSubtotal,
                        $currency,
                        $couponCode,
                        $style,
                    ),
                ]],
            ]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'subject' => ['type' => 'STRING'],
                        'preheader' => ['type' => 'STRING'],
                        'body_markdown' => ['type' => 'STRING'],
                    ],
                    'required' => ['subject', 'preheader', 'body_markdown'],
                ],
                'temperature' => 0.95,
            ],
        ];
    }

    /**
     * Build a single Gemini payload that asks for all four stage emails in one response.
     *
     * The response is an OBJECT keyed by stage name, each value being the same
     * {subject, preheader, body_markdown} shape returned by a per-stage call.
     * Quotas-friendly: one API call instead of four for the "All 4 stages" demo.
     *
     * @param string[] $stageKeys Ordered list of stages to generate (e.g. all 4).
     * @param BrandVoiceProfile $voice
     * @param string $customerFirstName
     * @param string $storeName
     * @param CartItemSummary[] $cartItems
     * @param float $cartSubtotal
     * @param string $currency
     * @param array $couponCodesByStage Map of stage_key → coupon code (or null).
     * @phpstan-param array<string, string|null> $couponCodesByStage
     * @return array<string, mixed>
     */
    public function buildBatch(
        array $stageKeys,
        BrandVoiceProfile $voice,
        string $customerFirstName,
        string $storeName,
        array $cartItems,
        float $cartSubtotal,
        string $currency,
        array $couponCodesByStage,
    ): array {
        $emailSchema = [
            'type' => 'OBJECT',
            'properties' => [
                'subject' => ['type' => 'STRING'],
                'preheader' => ['type' => 'STRING'],
                'body_markdown' => ['type' => 'STRING'],
            ],
            'required' => ['subject', 'preheader', 'body_markdown'],
        ];

        $properties = [];
        foreach ($stageKeys as $stage) {
            $properties[$stage] = $emailSchema;
        }

        return [
            'systemInstruction' => [
                'parts' => [['text' => $this->systemTextBatch($voice, count($stageKeys))]],
            ],
            'contents' => [[
                'role' => 'user',
                'parts' => [[
                    'text' => $this->userTextBatch(
                        $stageKeys,
                        $customerFirstName,
                        $storeName,
                        $cartItems,
                        $cartSubtotal,
                        $currency,
                        $couponCodesByStage,
                    ),
                ]],
            ]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema' => [
                    'type' => 'OBJECT',
                    'properties' => $properties,
                    'required' => $stageKeys,
                ],
                'temperature' => 0.95,
            ],
        ];
    }

    /**
     * Compose the cached system instruction.
     *
     * @param BrandVoiceProfile $voice
     * @return string
     */
    private function systemText(BrandVoiceProfile $voice): string
    {
        $brand = $voice->brandName;
        $voiceDesc = $voice->voiceDescription !== ''
            ? $voice->voiceDescription
            : 'Warm, helpful, customer-first.';

        return implode("\n", [
            "You are an e-commerce email copywriter for {$brand}.",
            '',
            "Brand voice: {$voiceDesc}",
            "Tone: {$voice->tone}",
            "Locale: {$voice->locale} — respond in this language.",
            '',
            'You will craft one abandoned-cart recovery email.',
            '',
            'Hard rules:',
            '- Output strictly valid JSON matching the provided schema.',
            '- Subject: ≤60 characters, attention-grabbing, no clickbait.',
            '- Preheader: ≤90 characters, complements the subject.',
            '- Body: 2-3 short paragraphs of markdown.'
                . ' Use plain paragraphs, **bold** for emphasis.'
                . ' No headings, no images, no bullet lists unless they read naturally.',
            '- Do NOT include any links, URLs, button text, or "click here" phrases in the body.'
                . ' A "Return to your cart" CTA button is added by the email template separately —'
                . ' never write your own. Refer to the cart conceptually only.',
            '- Never fabricate discounts, stock numbers, shipping promises,'
                . ' or product claims not provided in the user content.',
            '- Anti-staleness: do NOT default to greeting-line openings like "Hey {name}",'
                . ' "Hi there", "Hello {name}", or "Psst...".'
                . ' The "Style for this email" hint in the user content tells you which approach to take.',
            '- The customer\'s first name may be referenced ONCE at most, naturally.'
                . ' Many emails should not use it at all.',
            '- Vary subject line shapes (questions, statements, observations, mid-thought fragments).'
                . ' Never start a subject with the customer\'s name.',
        ]);
    }

    /**
     * Compose the per-call user content.
     *
     * @param string $stageKey
     * @param string $customerFirstName
     * @param string $storeName
     * @param CartItemSummary[] $cartItems
     * @param float $cartSubtotal
     * @param string $currency
     * @param string|null $couponCode
     * @param string $style Random rhetorical-approach hint for this email.
     * @return string
     */
    private function userText(
        string $stageKey,
        string $customerFirstName,
        string $storeName,
        array $cartItems,
        float $cartSubtotal,
        string $currency,
        ?string $couponCode = null,
        string $style = '',
    ): string {
        $descriptor = self::STAGE_DESCRIPTORS[$stageKey] ?? 'general abandoned-cart recovery email.';
        $name = $customerFirstName !== '' ? $customerFirstName : 'there';

        $lines = [];
        $lines[] = "Stage: {$descriptor}";
        if ($style !== '') {
            $lines[] = "Style for this email: {$style}";
        }
        $lines[] = "Customer first name: {$name}";
        $lines[] = "Store: {$storeName}";
        $lines[] = '';
        $lines[] = 'Cart items:';
        foreach ($cartItems as $item) {
            $qty = rtrim(rtrim(number_format($item->qty, 2, '.', ''), '0'), '.');
            $price = number_format($item->rowTotal, 2, '.', '');
            $lines[] = "- {$item->name} × {$qty} — {$currency} {$price}";
        }
        $lines[] = '';
        $lines[] = 'Cart subtotal: ' . $currency . ' ' . number_format($cartSubtotal, 2, '.', '');

        if ($couponCode !== null && $couponCode !== '') {
            $lines[] = '';
            $lines[] = "Discount code (mention it naturally; the template displays it separately): {$couponCode}";
        }

        $lines[] = '';
        $lines[] = 'Write the recovery email now. Respond with JSON only.';

        return implode("\n", $lines);
    }

    /**
     * Randomly pick one rhetorical approach for this email.
     *
     * @return string
     */
    private function pickStyle(): string
    {
        return self::STYLE_VARIANTS[array_rand(self::STYLE_VARIANTS)];
    }

    /**
     * System instruction tuned for a batch request producing N distinct emails.
     *
     * @param BrandVoiceProfile $voice
     * @param int $stageCount
     * @return string
     */
    private function systemTextBatch(BrandVoiceProfile $voice, int $stageCount): string
    {
        $brand = $voice->brandName;
        $voiceDesc = $voice->voiceDescription !== ''
            ? $voice->voiceDescription
            : 'Warm, helpful, customer-first.';

        return implode("\n", [
            "You are an e-commerce email copywriter for {$brand}.",
            '',
            "Brand voice: {$voiceDesc}",
            "Tone: {$voice->tone}",
            "Locale: {$voice->locale} — respond in this language.",
            '',
            "You will craft {$stageCount} distinct abandoned-cart recovery emails in one response,"
                . ' keyed by stage name in the output JSON.',
            '',
            'Hard rules (apply to every email):',
            '- Output strictly valid JSON matching the provided schema.'
                . " The top-level object MUST contain exactly {$stageCount} keys (one per requested stage).",
            '- Subject: ≤60 characters, attention-grabbing, no clickbait.',
            '- Preheader: ≤90 characters, complements the subject.',
            '- Body: 2-3 short paragraphs of markdown.'
                . ' Use plain paragraphs, **bold** for emphasis.'
                . ' No headings, no images, no bullet lists unless they read naturally.',
            '- Do NOT include any links, URLs, button text, or "click here" phrases in the body.'
                . ' A "Return to your cart" CTA button is added by the email template separately.',
            '- Never fabricate discounts, stock numbers, shipping promises,'
                . ' or product claims not provided in the user content.',
            '- Anti-staleness: do NOT default to greeting-line openings like "Hey {name}",'
                . ' "Hi there", "Hello {name}", or "Psst...".'
                . ' Each stage carries its own "Style" hint — follow it.',
            '- The customer\'s first name may be referenced ONCE at most across all emails combined.',
            '- Vary subject line shapes across the set (questions, statements, observations,'
                . ' mid-thought fragments). Make the four emails feel like a coherent escalation,'
                . ' not four copies of the same idea. Never start a subject with the customer\'s name.',
        ]);
    }

    /**
     * User content tuned for a batch request: shared cart context + per-stage descriptors + styles.
     *
     * @param string[] $stageKeys
     * @param string $customerFirstName
     * @param string $storeName
     * @param CartItemSummary[] $cartItems
     * @param float $cartSubtotal
     * @param string $currency
     * @param array $couponCodesByStage
     * @phpstan-param array<string, string|null> $couponCodesByStage
     * @return string
     */
    private function userTextBatch(
        array $stageKeys,
        string $customerFirstName,
        string $storeName,
        array $cartItems,
        float $cartSubtotal,
        string $currency,
        array $couponCodesByStage,
    ): string {
        $name = $customerFirstName !== '' ? $customerFirstName : 'there';

        $lines = [];
        $lines[] = "Customer first name: {$name}";
        $lines[] = "Store: {$storeName}";
        $lines[] = '';
        $lines[] = 'Cart items:';
        foreach ($cartItems as $item) {
            $qty = rtrim(rtrim(number_format($item->qty, 2, '.', ''), '0'), '.');
            $price = number_format($item->rowTotal, 2, '.', '');
            $lines[] = "- {$item->name} × {$qty} — {$currency} {$price}";
        }
        $lines[] = '';
        $lines[] = 'Cart subtotal: ' . $currency . ' ' . number_format($cartSubtotal, 2, '.', '');
        $lines[] = '';
        $lines[] = 'Requested stages (write one email per stage, keyed by stage name):';

        $styles = $this->pickDistinctStyles(count($stageKeys));
        foreach ($stageKeys as $i => $stage) {
            $descriptor = self::STAGE_DESCRIPTORS[$stage] ?? 'general abandoned-cart recovery email.';
            $lines[] = '';
            $lines[] = "### {$stage}";
            $lines[] = "Stage: {$descriptor}";
            $lines[] = 'Style for this email: ' . ($styles[$i] ?? '');
            $coupon = $couponCodesByStage[$stage] ?? null;
            if ($coupon !== null && $coupon !== '') {
                $lines[] = "Discount code for this stage"
                    . " (mention naturally; the template displays it separately): {$coupon}";
            }
        }

        $lines[] = '';
        $lines[] = 'Respond with JSON only. Top-level object keyed by stage name.';

        return implode("\n", $lines);
    }

    /**
     * Pick N distinct rhetorical styles so the batched emails don't all open the same way.
     *
     * @param int $count
     * @return string[]
     */
    private function pickDistinctStyles(int $count): array
    {
        $variants = self::STYLE_VARIANTS;
        shuffle($variants);
        return array_slice($variants, 0, max(0, $count));
    }
}
