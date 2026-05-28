<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Observer;

use Magebit\AbandonedCart\Model\Log\SendLogRepository;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;

/**
 * Marks all abandoned-cart log rows for this quote as recovered, preventing future stage sends.
 */
class StopOnOrderPlace implements ObserverInterface
{
    /**
     * @param SendLogRepository $logRepository
     */
    public function __construct(
        private readonly SendLogRepository $logRepository,
    ) {
    }

    /**
     * Stamp recovered_at on every log row linked to the order's underlying quote.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');
        if (!$order instanceof Order) {
            return;
        }
        $quoteIdRaw = $order->getQuoteId();
        if (!is_scalar($quoteIdRaw)) {
            return;
        }
        $quoteId = (int) $quoteIdRaw;
        if ($quoteId === 0) {
            return;
        }
        $this->logRepository->markRecovered($quoteId);
    }
}
