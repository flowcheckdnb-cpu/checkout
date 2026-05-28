<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Controller\Recovery;

use Magebit\AbandonedCart\Model\Recovery\RecoveryService;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;

/**
 * Frontend endpoint that recovers a customer's quote from a signed link in their email.
 *
 * URL pattern: /abandonedcart/recovery/index?t={recovery_token}
 */
class Index implements HttpGetActionInterface, ActionInterface
{
    /**
     * @param RequestInterface $request
     * @param ResultFactory $resultFactory
     * @param RecoveryService $recoveryService
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly ResultFactory $resultFactory,
        private readonly RecoveryService $recoveryService,
    ) {
    }

    /**
     * Dispatch: verify token, restore cart, redirect to checkout/cart (or noroute on failure).
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $tokenRaw = $this->request->getParam('t');
        $token = is_string($tokenRaw) ? $tokenRaw : '';

        $quoteId = $this->recoveryService->restore($token);

        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        if ($quoteId === null) {
            $redirect->setPath('cms/noroute');
            return $redirect;
        }
        $redirect->setPath('checkout/cart');
        return $redirect;
    }
}
