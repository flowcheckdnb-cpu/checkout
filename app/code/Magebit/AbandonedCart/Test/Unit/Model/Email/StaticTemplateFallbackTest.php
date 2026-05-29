<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Test\Unit\Model\Email;

use Magebit\AbandonedCart\Model\Email\StaticTemplateFallback;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Magebit\AbandonedCart\Model\Email\StaticTemplateFallback
 */
class StaticTemplateFallbackTest extends TestCase
{
    private StaticTemplateFallback $fallback;

    /**
     * Instantiate the fallback before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->fallback = new StaticTemplateFallback();
    }

    /**
     * Every known stage key must yield non-empty subject / preheader / body and an aiGenerated=false flag.
     *
     * @return void
     */
    public function testEveryStageYieldsCompleteEmail(): void
    {
        foreach (['stage_1', 'stage_2', 'stage_3', 'low_stock'] as $stage) {
            $email = $this->fallback->generate($stage, 'Veronica', 'Acme');
            $this->assertNotSame('', $email->subject, "stage {$stage}: empty subject");
            $this->assertNotSame('', $email->preheader, "stage {$stage}: empty preheader");
            $this->assertNotSame('', $email->bodyMarkdown, "stage {$stage}: empty body");
            $this->assertFalse($email->aiGenerated, "stage {$stage}: aiGenerated should be false");
        }
    }

    /**
     * The {name} placeholder in the body should be substituted with the supplied first name.
     *
     * @return void
     */
    public function testSubstitutesFirstName(): void
    {
        $email = $this->fallback->generate('stage_1', 'Veronica', 'Acme');
        $this->assertStringContainsString('Veronica', $email->bodyMarkdown);
        $this->assertStringNotContainsString('{name}', $email->bodyMarkdown);
    }

    /**
     * The {brand} placeholder in the body should be substituted with the supplied brand name.
     *
     * @return void
     */
    public function testSubstitutesBrandName(): void
    {
        $email = $this->fallback->generate('stage_1', 'Veronica', 'Acme Store');
        $this->assertStringContainsString('Acme Store', $email->bodyMarkdown);
        $this->assertStringNotContainsString('{brand}', $email->bodyMarkdown);
    }

    /**
     * Missing first name should fall back to "there".
     *
     * @return void
     */
    public function testEmptyFirstNameFallsBackToThere(): void
    {
        $email = $this->fallback->generate('stage_1', '', 'Acme');
        $this->assertStringContainsString('there', $email->bodyMarkdown);
    }

    /**
     * Unknown stage keys should still produce a usable email (silently using stage_1 copy).
     *
     * @return void
     */
    public function testUnknownStageStillProducesOutput(): void
    {
        $email = $this->fallback->generate('not_a_real_stage', 'Veronica', 'Acme');
        $this->assertNotSame('', $email->subject);
        $this->assertNotSame('', $email->bodyMarkdown);
    }
}
