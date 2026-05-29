<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Controller\Unsubscribe;

use Magebit\AbandonedCart\Block\Frontend\UnsubscribeConfirm;
use Magebit\AbandonedCart\Model\Unsubscribe\UnsubscribeService;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

/**
 * One-click unsubscribe endpoint.
 *
 * URL pattern: /abandonedcart/unsubscribe/index?t={recovery_token}
 *
 * GET-only: visiting the link unsubscribes the (email, store) pair captured on the
 * originating log row and renders a confirmation page. Reversible: future emails to
 * the same address can be re-enabled by an admin or by clearing the unsubscribed flag
 * in the send-log table.
 */
class Index implements HttpGetActionInterface, ActionInterface
{
    /**
     * @param RequestInterface $request
     * @param ResultFactory $resultFactory
     * @param PageFactory $pageFactory
     * @param UnsubscribeService $unsubscribeService
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly ResultFactory $resultFactory,
        private readonly PageFactory $pageFactory,
        private readonly UnsubscribeService $unsubscribeService,
    ) {
    }

    /**
     * Dispatch: unsubscribe and render confirmation, or redirect to noroute on invalid token.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $tokenRaw = $this->request->getParam('t');
        $token = is_string($tokenRaw) ? $tokenRaw : '';

        $email = $this->unsubscribeService->unsubscribeByToken($token);

        if ($email === null) {
            $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
            $redirect->setPath('cms/noroute');
            return $redirect;
        }

        $page = $this->pageFactory->create();
        /** @var Page $page */
        $block = $page->getLayout()->getBlock(UnsubscribeConfirm::BLOCK_NAME);
        if ($block instanceof UnsubscribeConfirm) {
            $block->setData(UnsubscribeConfirm::DATA_KEY_EMAIL, $email);
        }
        return $page;
    }
}
