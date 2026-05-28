<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Model\Email;

/**
 * Returns hard-coded brand-voice-free copy when AI generation is disabled or fails.
 *
 * Keeps the recovery pipeline running so customers are never silently dropped.
 */
class StaticTemplateFallback
{
    private const FALLBACKS = [
        'stage_1' => [
            'subject' => 'You left something in your cart',
            'preheader' => 'Come back any time — your cart is saved.',
            'body' => 'Hi {name},'
                . "\n\n"
                . 'It looks like you left a few things behind.'
                . ' We saved your cart so you can pick up right where you left off.'
                . "\n\n"
                . 'Thanks for shopping with {brand}.',
        ],
        'stage_2' => [
            'subject' => 'Still thinking it over?',
            'preheader' => 'Your cart is waiting whenever you are.',
            'body' => 'Hi {name},'
                . "\n\n"
                . 'Just a friendly nudge — the items in your cart are still here.'
                . ' Take another look when you have a moment.',
        ],
        'stage_3' => [
            'subject' => 'A little something to help you decide',
            'preheader' => 'Coupon inside — valid for a limited time.',
            'body' => 'Hi {name},'
                . "\n\n"
                . 'We held onto your cart and added a discount code to make the choice easier.'
                . ' We hope to see you back at {brand} soon.',
        ],
        'low_stock' => [
            'subject' => 'Items in your cart are running low',
            'preheader' => 'Reserve your selection before it sells out.',
            'body' => 'Hi {name},'
                . "\n\n"
                . 'One or more items in your cart are running low in stock.'
                . ' To help, we have included a discount code below — use it before the items sell out.',
        ],
    ];

    /**
     * Produce a static GeneratedEmail for the given stage with the given brand name and customer name.
     *
     * @param string $stageKey
     * @param string $customerFirstName
     * @param string $brandName
     * @return GeneratedEmail
     */
    public function generate(string $stageKey, string $customerFirstName, string $brandName): GeneratedEmail
    {
        $template = self::FALLBACKS[$stageKey] ?? self::FALLBACKS['stage_1'];

        $name = $customerFirstName !== '' ? $customerFirstName : 'there';
        $brand = $brandName !== '' ? $brandName : 'us';

        $body = str_replace(
            ['{name}', '{brand}'],
            [$name, $brand],
            $template['body'],
        );

        return new GeneratedEmail(
            subject: $template['subject'],
            preheader: $template['preheader'],
            bodyMarkdown: $body,
            aiGenerated: false,
        );
    }
}
