<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Model\Recovery;

use Magebit\AbandonedCart\Model\Log\SendLogRepository;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Throwable;

/**
 * Token-based cart recovery: token → log row → restore quote into checkout session.
 *
 * If the originating quote belongs to a registered customer, the customer is
 * auto-logged-in as a side effect of the recovery (their cart is otherwise
 * invisible to guest visitors — Magento doesn't let guests view customer carts).
 */
class RecoveryService
{
    private const TOKEN_TTL_DAYS = 30;

    /**
     * @param SendLogRepository $logRepository
     * @param CartRepositoryInterface $cartRepository
     * @param CheckoutSession $checkoutSession
     * @param CustomerSession $customerSession
     * @param CustomerRepositoryInterface $customerRepository
     */
    public function __construct(
        private readonly SendLogRepository $logRepository,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly CheckoutSession $checkoutSession,
        private readonly CustomerSession $customerSession,
        private readonly CustomerRepositoryInterface $customerRepository,
    ) {
    }

    /**
     * Validate a recovery token, reactivate the linked quote, and bind it to the current session.
     *
     * @param string $token
     * @return int|null Restored quote id, or null if invalid/expired/missing.
     */
    public function restore(string $token): ?int
    {
        if ($token === '') {
            return null;
        }
        $log = $this->logRepository->findByRecoveryToken($token);
        if ($log === null) {
            return null;
        }

        $sentAtRaw = $log->getSentAt();
        if (is_string($sentAtRaw) && $sentAtRaw !== '') {
            $sentAtTs = strtotime($sentAtRaw);
            if ($sentAtTs !== false && (time() - $sentAtTs) > self::TOKEN_TTL_DAYS * 86400) {
                return null;
            }
        }

        $quoteIdRaw = $log->getQuoteId();
        if (!is_scalar($quoteIdRaw)) {
            return null;
        }
        $quoteId = (int) $quoteIdRaw;
        if ($quoteId === 0) {
            return null;
        }

        try {
            $quote = $this->cartRepository->get($quoteId);
        } catch (NoSuchEntityException) {
            return null;
        }
        if (!$quote instanceof Quote) {
            return null;
        }

        $quote->setIsActive(true);
        $this->cartRepository->save($quote);

        // If the quote belongs to a registered customer, log them in — Magento
        // hides customer-owned carts from guest visitors, so without this the
        // cart page renders empty on the redirect.
        $this->loginOwnerIfRegistered($quote);

        // replaceQuote() sets BOTH the session's quote_id AND the in-memory
        // checkout-session $_quote. Plain setQuoteId() leaves the in-memory
        // state stale and the cart page renders empty on the redirect.
        $this->checkoutSession->clearStorage();
        $this->checkoutSession->replaceQuote($quote);

        return $quoteId;
    }

    /**
     * Auto-login the cart owner if the quote has customer_id set and we can resolve them.
     *
     * @param Quote $quote
     * @return void
     */
    private function loginOwnerIfRegistered(Quote $quote): void
    {
        $customerIdRaw = $quote->getCustomerId();
        if (!is_scalar($customerIdRaw)) {
            return;
        }
        $customerId = (int) $customerIdRaw;
        if ($customerId === 0) {
            return;
        }
        try {
            $customer = $this->customerRepository->getById($customerId);
        } catch (Throwable) {
            return;
        }
        $this->customerSession->setCustomerDataAsLoggedIn($customer);
    }
}
