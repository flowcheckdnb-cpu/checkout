<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Test\Unit\Service\Coupon;

use Magebit\AbandonedCart\Service\Coupon\CouponIssuer;
use Magento\SalesRule\Api\CouponManagementInterface;
use Magento\SalesRule\Api\Data\CouponGenerationSpecInterface;
use Magento\SalesRule\Api\Data\CouponGenerationSpecInterfaceFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \Magebit\AbandonedCart\Service\Coupon\CouponIssuer
 */
class CouponIssuerTest extends TestCase
{
    /**
     * @var CouponManagementInterface&MockObject
     */
    private CouponManagementInterface $couponManagement;

    /**
     * @var CouponGenerationSpecInterfaceFactory&MockObject
     */
    private CouponGenerationSpecInterfaceFactory $specFactory;

    /**
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    /**
     * @var CouponGenerationSpecInterface&MockObject
     */
    private CouponGenerationSpecInterface $spec;

    /**
     * @var CouponIssuer
     */
    private CouponIssuer $issuer;

    /**
     * Wire up mocks and a spec stub that records its setter calls.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->spec = $this->createMock(CouponGenerationSpecInterface::class);
        $this->specFactory = $this->createMock(CouponGenerationSpecInterfaceFactory::class);
        $this->specFactory->method('create')->willReturn($this->spec);

        $this->couponManagement = $this->createMock(CouponManagementInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->issuer = new CouponIssuer(
            $this->couponManagement,
            $this->specFactory,
            $this->logger,
        );
    }

    /**
     * ruleId=0 should short-circuit without touching the coupon-management API.
     *
     * @return void
     */
    public function testReturnsNullForZeroRuleId(): void
    {
        $this->couponManagement->expects(self::never())->method('generate');
        self::assertNull($this->issuer->issue(0, 168, 'Veronica'));
    }

    /**
     * A successful generation should return a GeneratedCoupon carrying the code.
     *
     * @return void
     */
    public function testReturnsGeneratedCouponOnSuccess(): void
    {
        $this->couponManagement->method('generate')->willReturn(['VERONICA-AB3F']);
        $coupon = $this->issuer->issue(3, 168, 'Veronica');
        self::assertNotNull($coupon);
        self::assertSame('VERONICA-AB3F', $coupon->code);
        self::assertNotNull($coupon->expiresAtUnix);
        self::assertGreaterThan(time(), $coupon->expiresAtUnix);
    }

    /**
     * A first-name prefix hint should be uppercased + dash-suffixed when set on the spec.
     *
     * @return void
     */
    public function testSanitizesPrefixUppercaseAndAppendsDash(): void
    {
        $this->spec->expects(self::once())->method('setPrefix')->with('VERONICA-');
        $this->spec->expects(self::once())->method('setLength')->with(6);
        $this->couponManagement->method('generate')->willReturn(['code']);
        $this->issuer->issue(3, 168, 'Veronica');
    }

    /**
     * Non-alphanumeric characters in the hint should be stripped before use.
     *
     * @return void
     */
    public function testStripsNonAlphanumericFromPrefix(): void
    {
        $this->spec->expects(self::once())->method('setPrefix')->with('OBRIEN-');
        $this->couponManagement->method('generate')->willReturn(['code']);
        $this->issuer->issue(3, 168, "O'Brien");
    }

    /**
     * Long prefix hints should be capped at 10 characters.
     *
     * @return void
     */
    public function testCapsPrefixAt10Characters(): void
    {
        $this->spec->expects(self::once())->method('setPrefix')->with('VERYLONGCU-');
        $this->couponManagement->method('generate')->willReturn(['code']);
        $this->issuer->issue(3, 168, 'verylongcustomername');
    }

    /**
     * No prefix hint should fall back to the longer 12-char alphanumeric default.
     *
     * @return void
     */
    public function testUsesDefaultLengthWhenNoPrefix(): void
    {
        $this->spec->expects(self::never())->method('setPrefix');
        $this->spec->expects(self::once())->method('setLength')->with(12);
        $this->couponManagement->method('generate')->willReturn(['code']);
        $this->issuer->issue(3, 168, null);
    }

    /**
     * Non-Latin scripts that strip to empty should not crash; fall back to no prefix.
     *
     * @return void
     */
    public function testEmptyAfterSanitizationFallsThroughToDefault(): void
    {
        $this->spec->expects(self::never())->method('setPrefix');
        $this->spec->expects(self::once())->method('setLength')->with(12);
        $this->couponManagement->method('generate')->willReturn(['code']);
        $this->issuer->issue(3, 168, '李');
    }

    /**
     * Failure inside CouponManagement::generate() should be caught and logged, with null returned.
     *
     * @return void
     */
    public function testReturnsNullAndLogsWarningOnFailure(): void
    {
        $this->couponManagement->method('generate')->willThrowException(new RuntimeException('rule not auto'));
        $this->logger->expects(self::once())->method('warning');
        self::assertNull($this->issuer->issue(3, 168, 'Veronica'));
    }

    /**
     * Zero TTL hours should produce a coupon with null expiry (open-ended).
     *
     * @return void
     */
    public function testZeroTtlYieldsNullExpiry(): void
    {
        $this->couponManagement->method('generate')->willReturn(['ABC']);
        $coupon = $this->issuer->issue(3, 0, 'Veronica');
        self::assertNotNull($coupon);
        self::assertNull($coupon->expiresAtUnix);
    }
}
