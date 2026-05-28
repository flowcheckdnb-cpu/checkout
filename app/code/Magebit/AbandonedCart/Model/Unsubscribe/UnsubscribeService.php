<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Model\Unsubscribe;

use Magebit\AbandonedCart\Model\Log\SendLogRepository;

/**
 * Resolves an unsubscribe token to a customer-email/store pair and suppresses all
 * current + future abandoned-cart emails for that pair.
 */
class UnsubscribeService
{
    /**
     * @param SendLogRepository $logRepository
     */
    public function __construct(
        private readonly SendLogRepository $logRepository,
    ) {
    }

    /**
     * Look up the originating log row by token and mark all rows for that (email, store)
     * pair as unsubscribed=1.
     *
     * @param string $token
     * @return string|null The unsubscribed email on success, null if the token is missing/invalid.
     */
    public function unsubscribeByToken(string $token): ?string
    {
        if ($token === '') {
            return null;
        }
        $log = $this->logRepository->findByRecoveryToken($token);
        if ($log === null) {
            return null;
        }
        $emailRaw = $log->getCustomerEmail();
        $storeIdRaw = $log->getStoreId();
        if (!is_string($emailRaw) || $emailRaw === '' || !is_scalar($storeIdRaw)) {
            return null;
        }
        $this->logRepository->markUnsubscribed($emailRaw, (int) $storeIdRaw);
        return $emailRaw;
    }
}
