<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Model\ResourceModel\SendLog;

use Magebit\AbandonedCart\Model\Log\SendLog;
use Magebit\AbandonedCart\Model\ResourceModel\SendLog as SendLogResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * Collection of send-log rows.
 */
class Collection extends AbstractCollection
{
    /**
     * @var string
     */
    protected $_idFieldName = 'id';

    /**
     * Bind model + resource model.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(SendLog::class, SendLogResource::class);
    }
}
