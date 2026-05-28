<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Controller\Adminhtml\SendLog;

use Magebit\AbandonedCart\Model\Email\TestEmailService;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface;
use Throwable;

/**
 * POST handler for the test-send form. Validates input, dispatches one test
 * email via TestEmailService, surfaces success/error via flash messages,
 * redirects back to the form.
 */
class TestSendSave extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magebit_AbandonedCart::log_test_send';

    /**
     * @param Context $context
     * @param RequestInterface $req
     * @param ResultFactory $results
     * @param ManagerInterface $messages
     * @param TestEmailService $testEmailService
     */
    public function __construct(
        Context $context,
        private readonly RequestInterface $req,
        private readonly ResultFactory $results,
        private readonly ManagerInterface $messages,
        private readonly TestEmailService $testEmailService,
    ) {
        parent::__construct($context);
    }

    /**
     * Read form params, dispatch the test email, redirect back to the form.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $typeRaw = $this->req->getParam('email_type');
        $emailRaw = $this->req->getParam('recipient_email');
        $storeRaw = $this->req->getParam('store_id');

        $type = is_string($typeRaw) ? $typeRaw : '';
        $email = is_string($emailRaw) ? trim($emailRaw) : '';
        $storeId = is_scalar($storeRaw) ? (int) $storeRaw : 1;

        try {
            $this->testEmailService->send($type, $email, $storeId);
            $this->messages->addSuccessMessage(
                (string) __('Test %1 email sent to %2.', $type, $email),
            );
        } catch (Throwable $e) {
            $this->messages->addErrorMessage($e->getMessage());
        }

        $redirect = $this->results->create(ResultFactory::TYPE_REDIRECT);
        /** @var Redirect $redirect */
        $redirect->setPath('magebit_abandonedcart/sendlog/testsend');
        return $redirect;
    }
}
