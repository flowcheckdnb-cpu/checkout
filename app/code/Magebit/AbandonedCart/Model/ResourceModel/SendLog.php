<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Resource model for magebit_abandoned_cart_log.
 */
class SendLog extends AbstractDb
{
    /**
     * Bind to the underlying table and primary key.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init('magebit_abandoned_cart_log', 'id');
    }
}
