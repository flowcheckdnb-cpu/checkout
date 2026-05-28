<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Model\Finder;

use Magebit\AbandonedCart\Model\Config;
use Magebit\AbandonedCart\Model\Log\SendLogRepository;
use Magento\Framework\App\ResourceConnection;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Throwable;

/**
 * Finds active quotes that contain at least one item whose stock has dipped at or below
 * the configured low-stock threshold (and still has > 0 qty available).
 */
class LowStockCartFinder
{
    /**
     * @param Config $config
     * @param ResourceConnection $resource
     * @param CartRepositoryInterface $cartRepository
     * @param SendLogRepository $logRepository
     */
    public function __construct(
        private readonly Config $config,
        private readonly ResourceConnection $resource,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly SendLogRepository $logRepository,
    ) {
    }

    /**
     * Quotes eligible for a low-stock urgency email today.
     *
     * @param int $storeId
     * @param string $stageKey Dedup key (typically "low_stock:YYYY-MM-DD")
     * @return Quote[]
     */
    public function findEligible(int $storeId, string $stageKey): array
    {
        if (!$this->config->isLowStockEnabled($storeId)) {
            return [];
        }
        $threshold = $this->config->getLowStockThreshold($storeId);
        if ($threshold <= 0) {
            return [];
        }

        $conn = $this->resource->getConnection();
        $quoteTable = $this->resource->getTableName('quote');
        $quoteItemTable = $this->resource->getTableName('quote_item');
        $stockTable = $this->resource->getTableName('cataloginventory_stock_item');

        $select = $conn->select()
            ->distinct()
            ->from(['q' => $quoteTable], ['entity_id'])
            ->join(
                ['qi' => $quoteItemTable],
                'qi.quote_id = q.entity_id',
                [],
            )
            ->join(
                ['csi' => $stockTable],
                'csi.product_id = qi.product_id',
                [],
            )
            ->where('q.is_active = ?', 1)
            ->where('q.store_id = ?', $storeId)
            ->where("q.customer_email IS NOT NULL AND q.customer_email != ''")
            ->where('q.items_count > ?', 0)
            ->where('csi.qty <= ?', $threshold)
            ->where('csi.qty > ?', 0);

        $ids = $conn->fetchCol($select);

        $eligible = [];
        foreach ($ids as $idRaw) {
            if (!is_scalar($idRaw)) {
                continue;
            }
            $quoteId = (int) $idRaw;
            if ($quoteId === 0) {
                continue;
            }
            if ($this->logRepository->hasRecovered($quoteId)) {
                continue;
            }
            if ($this->logRepository->hasSent($quoteId, $stageKey)) {
                continue;
            }
            try {
                $cart = $this->cartRepository->get($quoteId);
            } catch (Throwable) {
                continue;
            }
            if (!$cart instanceof Quote) {
                continue;
            }
            $emailRaw = $cart->getCustomerEmail();
            if (is_string($emailRaw) && $this->logRepository->isUnsubscribed($emailRaw, $storeId)) {
                continue;
            }
            $eligible[] = $cart;
        }

        return $eligible;
    }
}
