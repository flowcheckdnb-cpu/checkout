<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Test\Unit\Model\Email;

use Magebit\AbandonedCart\Model\Email\MarkdownRenderer;
use Magento\Framework\Escaper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Magebit\AbandonedCart\Model\Email\MarkdownRenderer
 */
class MarkdownRendererTest extends TestCase
{
    private MarkdownRenderer $renderer;

    /**
     * Set up a pass-through Escaper so the test asserts on what the renderer produces,
     * not on Magento's escape implementation.
     *
     * @return void
     */
    protected function setUp(): void
    {
        /** @var Escaper&MockObject $escaper */
        $escaper = $this->createMock(Escaper::class);
        $escaper->method('escapeHtml')->willReturnCallback(
            static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        );
        $escaper->method('escapeUrl')->willReturnCallback(
            static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        );
        $this->renderer = new MarkdownRenderer($escaper);
    }

    /**
     * Paragraphs separated by blank lines should each render as <p>...</p>.
     *
     * @return void
     */
    public function testWrapsParagraphsInPTags(): void
    {
        $html = $this->renderer->render("First para.\n\nSecond para.");
        $this->assertStringContainsString('<p>First para.</p>', $html);
        $this->assertStringContainsString('<p>Second para.</p>', $html);
    }

    /**
     * **bold** markdown should render as <strong>.
     *
     * @return void
     */
    public function testRendersBoldAsStrong(): void
    {
        $html = $this->renderer->render('Hello **world**');
        $this->assertStringContainsString('<strong>world</strong>', $html);
    }

    /**
     * [text](url) should render as <a> only for http(s) URLs.
     *
     * @return void
     */
    public function testRendersHttpLinks(): void
    {
        $html = $this->renderer->render('Visit [our site](https://example.com)');
        $this->assertStringContainsString('<a href="https://example.com">our site</a>', $html);
    }

    /**
     * Non-http schemes (javascript:, data:, mailto:) should be stripped to plain text.
     *
     * @return void
     */
    public function testStripsNonHttpLinks(): void
    {
        $html = $this->renderer->render('Click [here](javascript:alert(1))');
        $this->assertStringContainsString('here', $html);
        $this->assertStringNotContainsString('javascript', $html);
        $this->assertStringNotContainsString('<a href', $html);
    }

    /**
     * HTML in the source must be escaped so the AI cannot inject raw tags.
     *
     * @return void
     */
    public function testEscapesRawHtml(): void
    {
        $html = $this->renderer->render('Hello <script>alert(1)</script>');
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /**
     * Empty input should produce empty output.
     *
     * @return void
     */
    public function testEmptyInputProducesEmptyOutput(): void
    {
        $this->assertSame('<p></p>', $this->renderer->render(''));
    }
}
