<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Controller\Adminhtml\SendLog;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page as BackendPage;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * Renders the test-send form. Submission posts to the sibling TestSendSave controller.
 */
class TestSend extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Magebit_AbandonedCart::log_test_send';

    /**
     * @param Context $context
     * @param PageFactory $pageFactory
     */
    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
    ) {
        parent::__construct($context);
    }

    /**
     * Render the form with active menu + title.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $page = $this->pageFactory->create();
        /** @var BackendPage $page */
        $page->setActiveMenu('Magebit_AbandonedCart::log');
        $page->getConfig()->getTitle()->prepend((string) __('Send Test Email'));
        return $page;
    }
}
