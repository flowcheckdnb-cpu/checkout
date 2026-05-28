<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Model\Email;

use Magento\Framework\Escaper;

/**
 * Minimal markdown→HTML renderer for AI-generated body copy.
 *
 * Supports paragraphs, **bold**, and [text](url) links. Escapes everything else.
 * Intentionally narrow — the AI is instructed to produce only these elements.
 */
class MarkdownRenderer
{
    /**
     * @param Escaper $escaper
     */
    public function __construct(
        private readonly Escaper $escaper,
    ) {
    }

    /**
     * Render markdown body to HTML safe for email.
     *
     * @param string $markdown
     * @return string
     */
    public function render(string $markdown): string
    {
        $paragraphs = preg_split("/\n\s*\n/", trim($markdown));
        if (!is_array($paragraphs)) {
            return '';
        }

        $rendered = [];
        foreach ($paragraphs as $paragraph) {
            $rendered[] = '<p>' . $this->inline(trim($paragraph)) . '</p>';
        }

        return implode("\n", $rendered);
    }

    /**
     * Apply inline transforms: escape first, then re-inject bold and links.
     *
     * @param string $text
     * @return string
     */
    private function inline(string $text): string
    {
        $escapedRaw = $this->escaper->escapeHtml($text);
        $escaped = is_string($escapedRaw) ? $escapedRaw : '';

        $escaper = $this->escaper;
        $escaped = (string) preg_replace_callback(
            '/\[([^\]]+)\]\(([^\s)]+)\)/',
            static function (array $m) use ($escaper): string {
                $linkText = $m[1];
                $href = $m[2];
                if (preg_match('#^https?://#i', $href) !== 1) {
                    return $linkText;
                }
                return '<a href="' . $escaper->escapeUrl($href) . '">' . $linkText . '</a>';
            },
            $escaped,
        );

        $escaped = (string) preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $escaped);

        return nl2br($escaped, false);
    }
}
