<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Model\Email;

/**
 * Result of one AI generation (or static fallback) — the dynamic content fed into the email template.
 */
class GeneratedEmail
{
    /**
     * @param string $subject
     * @param string $preheader
     * @param string $bodyMarkdown
     * @param bool $aiGenerated
     */
    public function __construct(
        public readonly string $subject,
        public readonly string $preheader,
        public readonly string $bodyMarkdown,
        public readonly bool $aiGenerated,
    ) {
    }
}
