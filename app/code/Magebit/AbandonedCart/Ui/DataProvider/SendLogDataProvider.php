<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Ui\DataProvider;

use Magebit\AbandonedCart\Model\ResourceModel\SendLog\CollectionFactory;
use Magento\Ui\DataProvider\AbstractDataProvider;

/**
 * Feeds the send-log ui_component listing with rows from
 * magebit_abandoned_cart_log via its declared Collection.
 */
class SendLogDataProvider extends AbstractDataProvider
{
    /**
     * @param string $name
     * @param string $primaryFieldName
     * @param string $requestFieldName
     * @param CollectionFactory $collectionFactory
     * @param array $meta
     * @param array $data
     * @phpstan-param array<string, mixed> $meta
     * @phpstan-param array<string, mixed> $data
     */
    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        CollectionFactory $collectionFactory,
        array $meta = [],
        array $data = [],
    ) {
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
        $this->collection = $collectionFactory->create();
    }
}
