<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Model\Log;

use Magebit\AbandonedCart\Model\ResourceModel\SendLog as SendLogResource;
use Magebit\AbandonedCart\Model\ResourceModel\SendLog\CollectionFactory;
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
     */
    public function __construct(
        private readonly SendLogFactory $factory,
        private readonly CollectionFactory $collectionFactory,
        private readonly SendLogResource $resource,
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
}
