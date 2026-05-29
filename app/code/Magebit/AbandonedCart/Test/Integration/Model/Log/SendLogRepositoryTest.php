<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Test\Integration\Model\Log;

use Magebit\AbandonedCart\Model\Log\SendLog;
use Magebit\AbandonedCart\Model\Log\SendLogRepository;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Exercises SendLogRepository against a real MariaDB to confirm the UNIQUE
 * (quote_id, stage_key) constraint actually fires.
 *
 * Run via:
 *   vendor/bin/phpunit -c dev/tests/integration/phpunit.xml.dist \
 *     app/code/Magebit/AbandonedCart/Test/Integration
 *
 * Requires dev/tests/integration/etc/install-config-mysql.php (copy from .dist
 * and fill in sandbox DB credentials). First run installs Magento into the
 * sandbox; subsequent runs reuse it.
 *
 * @covers \Magebit\AbandonedCart\Model\Log\SendLogRepository
 */
class SendLogRepositoryTest extends TestCase
{
    /**
     * @var SendLogRepository
     */
    private SendLogRepository $repository;

    /**
     * Resolve the repository via Magento's test ObjectManager.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $om = Bootstrap::getObjectManager();
        $repo = $om->get(SendLogRepository::class);
        self::assertInstanceOf(SendLogRepository::class, $repo);
        $this->repository = $repo;

        // The log table has a FK to quote.entity_id (good for production
        // cascade-on-delete). These tests assert dedup / recovery / unsubscribe
        // semantics, not the FK — disable checks so we can use synthetic
        // quote_ids without seeding unrelated fixture data.
        $resource = $om->get(ResourceConnection::class);
        self::assertInstanceOf(ResourceConnection::class, $resource);
        $resource->getConnection()->query('SET FOREIGN_KEY_CHECKS=0');
    }

    /**
     * Inserting two rows with the same (quote_id, stage_key) must fail on the
     * UNIQUE constraint — proves dedup is enforced at the DB layer, not just
     * the application layer.
     *
     * @magentoDbIsolation enabled
     * @return void
     */
    public function testDuplicateStageKeyTripsUniqueConstraint(): void
    {
        $first = $this->makeLog(quoteId: 999, stageKey: 'stage_1', token: 'token-A');
        $this->repository->save($first);

        $second = $this->makeLog(quoteId: 999, stageKey: 'stage_1', token: 'token-B');

        $this->expectException(AlreadyExistsException::class);
        $this->repository->save($second);
    }

    /**
     * Same quote_id with different stage_key is allowed — that's the whole point of
     * stage-keyed dedup: stage_1 can land before stage_2 without colliding.
     *
     * @magentoDbIsolation enabled
     * @return void
     */
    public function testDifferentStagesForSameQuoteAreAllowed(): void
    {
        $this->repository->save($this->makeLog(quoteId: 998, stageKey: 'stage_1', token: 'token-C'));
        $this->repository->save($this->makeLog(quoteId: 998, stageKey: 'stage_2', token: 'token-D'));

        self::assertTrue($this->repository->hasSent(998, 'stage_1'));
        self::assertTrue($this->repository->hasSent(998, 'stage_2'));
        self::assertFalse($this->repository->hasSent(998, 'stage_3'));
    }

    /**
     * hasRecovered should return false until markRecovered is called.
     *
     * @magentoDbIsolation enabled
     * @return void
     */
    public function testMarkRecoveredFlagsAllRowsForQuote(): void
    {
        $this->repository->save($this->makeLog(quoteId: 997, stageKey: 'stage_1', token: 'token-E'));
        $this->repository->save($this->makeLog(quoteId: 997, stageKey: 'stage_2', token: 'token-F'));

        self::assertFalse($this->repository->hasRecovered(997));

        $this->repository->markRecovered(997);

        self::assertTrue($this->repository->hasRecovered(997));
    }

    /**
     * Unsubscribing one log row should suppress sends for the whole (email, store) pair.
     *
     * @magentoDbIsolation enabled
     * @return void
     */
    public function testUnsubscribeAppliesPerEmailAndStore(): void
    {
        $this->repository->save(
            $this->makeLog(quoteId: 996, stageKey: 'stage_1', token: 'token-G', email: 'a@example.com', storeId: 1),
        );
        self::assertFalse($this->repository->isUnsubscribed('a@example.com', 1));

        $this->repository->markUnsubscribed('a@example.com', 1);

        self::assertTrue($this->repository->isUnsubscribed('a@example.com', 1));
        self::assertFalse(
            $this->repository->isUnsubscribed('a@example.com', 2),
            'unsubscribe must be per-store; store 2 still allowed',
        );
    }

    /**
     * Build a fresh SendLog with sensible defaults.
     *
     * @param int $quoteId
     * @param string $stageKey
     * @param string $token
     * @param string $email
     * @param int $storeId
     * @return SendLog
     */
    private function makeLog(
        int $quoteId,
        string $stageKey,
        string $token,
        string $email = 'demo@example.com',
        int $storeId = 1,
    ): SendLog {
        $log = $this->repository->create();
        $log->setQuoteId($quoteId);
        $log->setCustomerEmail($email);
        $log->setStoreId($storeId);
        $log->setEmailType($stageKey === 'low_stock' ? 'low_stock' : $stageKey);
        $log->setStageKey($stageKey);
        $log->setStatus('sent');
        $log->setAiGenerated(1);
        $log->setRecoveryToken($token);
        return $log;
    }
}
