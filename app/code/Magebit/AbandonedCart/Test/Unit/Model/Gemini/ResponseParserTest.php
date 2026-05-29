<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Test\Unit\Model\Gemini;

use Magebit\AbandonedCart\Model\Gemini\GeminiException;
use Magebit\AbandonedCart\Model\Gemini\ResponseParser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Magebit\AbandonedCart\Model\Gemini\ResponseParser
 */
class ResponseParserTest extends TestCase
{
    private ResponseParser $parser;

    /**
     * Instantiate the parser before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->parser = new ResponseParser();
    }

    /**
     * A well-formed Gemini response should produce a fully-populated GeneratedEmail
     * with aiGenerated=true.
     *
     * @return void
     */
    public function testParsesValidResponse(): void
    {
        $response = $this->envelope('{"subject":"Hey!","preheader":"Check this","body_markdown":"Hello world."}');

        $email = $this->parser->parse($response);

        $this->assertSame('Hey!', $email->subject);
        $this->assertSame('Check this', $email->preheader);
        $this->assertSame('Hello world.', $email->bodyMarkdown);
        $this->assertTrue($email->aiGenerated);
    }

    /**
     * Free-tier Gemini sometimes wraps JSON in ``` fences even with responseMimeType set.
     * The parser must strip them.
     *
     * @return void
     */
    public function testStripsBacktickFences(): void
    {
        $fenced = "```json\n"
            . '{"subject":"Hey","preheader":"X","body_markdown":"Body"}'
            . "\n```";
        $email = $this->parser->parse($this->envelope($fenced));
        $this->assertSame('Hey', $email->subject);
    }

    /**
     * Subjects exceeding the 60-char cap should be truncated rather than rejected.
     *
     * @return void
     */
    public function testTruncatesOversizedSubject(): void
    {
        $longSubject = str_repeat('A', 80);
        $response = $this->envelope('{"subject":"' . $longSubject
            . '","preheader":"X","body_markdown":"Body"}');

        $email = $this->parser->parse($response);

        $this->assertSame(60, mb_strlen($email->subject));
    }

    /**
     * Missing the candidates array → GeminiException.
     *
     * @return void
     */
    public function testRejectsMissingCandidates(): void
    {
        $this->expectException(GeminiException::class);
        $this->parser->parse([]);
    }

    /**
     * Body is required; an empty body_markdown must trigger fallback via exception.
     *
     * @return void
     */
    public function testRejectsEmptyBody(): void
    {
        $this->expectException(GeminiException::class);
        $this->parser->parse($this->envelope('{"subject":"Hey","preheader":"X","body_markdown":"   "}'));
    }

    /**
     * Malformed JSON in the model output must fail parsing.
     *
     * @return void
     */
    public function testRejectsMalformedJson(): void
    {
        $this->expectException(GeminiException::class);
        $this->parser->parse($this->envelope('{"subject": this is not valid'));
    }

    /**
     * Build the Gemini response envelope shape around a model text payload.
     *
     * @param string $modelText
     * @return array
     * @phpstan-return array<string, mixed>
     */
    private function envelope(string $modelText): array
    {
        return [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [['text' => $modelText]],
                        'role' => 'model',
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
        ];
    }
}
