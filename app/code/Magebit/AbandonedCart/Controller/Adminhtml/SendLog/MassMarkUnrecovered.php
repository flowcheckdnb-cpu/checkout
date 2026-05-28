<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Controller\Adminhtml\SendLog;

use Magebit\AbandonedCart\Model\Log\SendLog;
use Magebit\AbandonedCart\Model\ResourceModel\SendLog\CollectionFactory;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Ui\Component\MassAction\Filter;
use Throwable;

/**
 * Mass-action handler: clear recovered_at on every selected log row.
 *
 * Useful when the order-place observer fires against the wrong quote
 * (e.g. admin places an order on behalf of a different customer).
 */
class MassMarkUnrecovered extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magebit_AbandonedCart::log';

    /**
     * @param Context $context
     * @param Filter $filter
     * @param CollectionFactory $collectionFactory
     * @param ResourceConnection $resource
     */
    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory,
        private readonly ResourceConnection $resource,
    ) {
        parent::__construct($context);
    }

    /**
     * Clear recovered_at on selected rows and redirect back to the listing.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $count = 0;
        try {
            $collection = $this->filter->getCollection($this->collectionFactory->create());
            $ids = [];
            foreach ($collection->getItems() as $item) {
                if (!$item instanceof SendLog) {
                    continue;
                }
                $idRaw = $item->getId();
                if (!is_scalar($idRaw)) {
                    continue;
                }
                $ids[] = (int) $idRaw;
            }
            if (count($ids) > 0) {
                $connection = $this->resource->getConnection();
                $count = $connection->update(
                    $this->resource->getTableName('magebit_abandoned_cart_log'),
                    ['recovered_at' => null],
                    ['id IN (?)' => $ids],
                );
            }
            $this->messageManager->addSuccessMessage(__('%1 row(s) marked unrecovered.', $count));
        } catch (Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        /** @var Redirect $redirect */
        $redirect->setPath('*/*/index');
        return $redirect;
    }
}
