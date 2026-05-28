<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Model\Recovery;

use Magebit\AbandonedCart\Model\Log\SendLogRepository;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;

/**
 * Token-based cart recovery: token → log row → restore quote into checkout session.
 */
class RecoveryService
{
    private const TOKEN_TTL_DAYS = 30;

    /**
     * @param SendLogRepository $logRepository
     * @param CartRepositoryInterface $cartRepository
     * @param CheckoutSession $checkoutSession
     */
    public function __construct(
        private readonly SendLogRepository $logRepository,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly CheckoutSession $checkoutSession,
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

        $quote->setIsActive(true);
        $this->cartRepository->save($quote);

        $this->checkoutSession->setQuoteId($quoteId);

        return $quoteId;
    }
}
