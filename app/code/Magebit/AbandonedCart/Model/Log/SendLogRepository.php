<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Model\Log;

use Magebit\AbandonedCart\Model\ResourceModel\SendLog as SendLogResource;
use Magebit\AbandonedCart\Model\ResourceModel\SendLog\CollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\AlreadyExistsException;

/**
 * Thin repository over magebit_abandoned_cart_log.
 */
class SendLogRepository
{
    /**
     * @param SendLogFactory $factory
     * @param CollectionFactory $collectionFactory
     * @param SendLogResource $resource
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly SendLogFactory $factory,
        private readonly CollectionFactory $collectionFactory,
        private readonly SendLogResource $resource,
        private readonly ResourceConnection $resourceConnection,
    ) {
    }

    /**
     * Instantiate a fresh, unsaved SendLog.
     *
     * @return SendLog
     */
    public function create(): SendLog
    {
        return $this->factory->create();
    }

    /**
     * Persist a SendLog row.
     *
     * @param SendLog $log
     * @return SendLog
     * @throws AlreadyExistsException
     */
    public function save(SendLog $log): SendLog
    {
        $this->resource->save($log);
        return $log;
    }

    /**
     * Whether a row with this (quote_id, stage_key) pair already exists.
     *
     * @param int $quoteId
     * @param string $stageKey
     * @return bool
     */
    public function hasSent(int $quoteId, string $stageKey): bool
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('quote_id', ['eq' => $quoteId]);
        $collection->addFieldToFilter('stage_key', ['eq' => $stageKey]);
        $collection->setPageSize(1);
        return $collection->getSize() > 0;
    }

    /**
     * Whether any prior send for this quote has been marked recovered (order placed).
     *
     * @param int $quoteId
     * @return bool
     */
    public function hasRecovered(int $quoteId): bool
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('quote_id', ['eq' => $quoteId]);
        $collection->addFieldToFilter('recovered_at', ['notnull' => true]);
        $collection->setPageSize(1);
        return $collection->getSize() > 0;
    }

    /**
     * Stamp recovered_at on every log row for the given quote.
     *
     * @param int $quoteId
     * @return void
     */
    public function markRecovered(int $quoteId): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('magebit_abandoned_cart_log');
        $connection->update(
            $table,
            ['recovered_at' => $connection->fromUnixtime(time())],
            ['quote_id = ?' => $quoteId, 'recovered_at IS NULL'],
        );
    }

    /**
     * Find a SendLog by its recovery token (UNIQUE).
     *
     * @param string $token
     * @return SendLog|null
     */
    public function findByRecoveryToken(string $token): ?SendLog
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('recovery_token', ['eq' => $token]);
        $collection->setPageSize(1);
        /** @var SendLog|false $row */
        $row = $collection->getFirstItem();
        if (!$row instanceof SendLog || $row->getId() === null) {
            return null;
        }
        return $row;
    }
}
