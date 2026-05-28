<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Controller\Adminhtml\SendLog;

use Magebit\AbandonedCart\Model\Log\SendLog;
use Magebit\AbandonedCart\Model\ResourceModel\SendLog as SendLogResource;
use Magebit\AbandonedCart\Model\ResourceModel\SendLog\CollectionFactory;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Ui\Component\MassAction\Filter;
use Throwable;

/**
 * Mass-action handler: delete every selected log row.
 */
class MassDelete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magebit_AbandonedCart::log';

    /**
     * @param Context $context
     * @param Filter $filter
     * @param CollectionFactory $collectionFactory
     * @param SendLogResource $resource
     */
    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory,
        private readonly SendLogResource $resource,
    ) {
        parent::__construct($context);
    }

    /**
     * Delete selected rows and redirect back to the listing.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $deleted = 0;
        try {
            $collection = $this->filter->getCollection($this->collectionFactory->create());
            foreach ($collection->getItems() as $item) {
                if (!$item instanceof SendLog) {
                    continue;
                }
                $this->resource->delete($item);
                $deleted++;
            }
            $this->messageManager->addSuccessMessage(__('%1 row(s) deleted.', $deleted));
        } catch (Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        /** @var Redirect $redirect */
        $redirect->setPath('*/*/index');
        return $redirect;
    }
}
