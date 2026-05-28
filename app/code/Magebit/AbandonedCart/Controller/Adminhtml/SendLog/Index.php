<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Controller\Adminhtml\SendLog;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\Page;

/**
 * Admin → Marketing → Communications → Abandoned Cart Log
 *
 * Renders the send-log ui_component listing.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Magebit_AbandonedCart::log';

    /**
     * @param Context $context
     */
    public function __construct(Context $context)
    {
        parent::__construct($context);
    }

    /**
     * Render the listing page with active menu + title.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        /** @var Page $page */
        $page->setActiveMenu('Magebit_AbandonedCart::log');
        $page->getConfig()->getTitle()->prepend(__('Abandoned Cart Log'));
        return $page;
    }
}
