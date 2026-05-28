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
}
