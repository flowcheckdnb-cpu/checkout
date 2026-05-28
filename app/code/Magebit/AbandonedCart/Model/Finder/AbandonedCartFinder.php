<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Model\Finder;

use DateInterval;
use DateTimeImmutable;
use Magebit\AbandonedCart\Model\Config;
use Magebit\AbandonedCart\Model\Log\SendLogRepository;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\ResourceModel\Quote\CollectionFactory as QuoteCollectionFactory;

/**
 * Selects active quotes whose updated_at has aged past the configured stage delay
 * and which have not yet been sent that stage's email.
 */
class AbandonedCartFinder
{
    private const BATCH_SIZE = 50;

    /**
     * @param Config $config
     * @param QuoteCollectionFactory $quoteCollectionFactory
     * @param SendLogRepository $logRepository
     */
    public function __construct(
        private readonly Config $config,
        private readonly QuoteCollectionFactory $quoteCollectionFactory,
        private readonly SendLogRepository $logRepository,
    ) {
    }

    /**
     * Return quotes eligible to receive the given stage's email for the given store.
     *
     * @param string $stageKey
     * @param int $storeId
     * @return Quote[]
     */
    public function findEligible(string $stageKey, int $storeId): array
    {
        $delayMinutes = $this->config->getStageDelayMinutes($stageKey, $storeId);
        if ($delayMinutes <= 0) {
            return [];
        }

        $threshold = (new DateTimeImmutable())
            ->sub(new DateInterval('PT' . $delayMinutes . 'M'))
            ->format('Y-m-d H:i:s');

        $collection = $this->quoteCollectionFactory->create();
        $collection->addFieldToFilter('store_id', ['eq' => $storeId]);
        $collection->addFieldToFilter('is_active', ['eq' => '1']);
        $collection->addFieldToFilter('items_count', ['gt' => 0]);
        $collection->addFieldToFilter('customer_email', ['notnull' => true]);
        $collection->addFieldToFilter('updated_at', ['lt' => $threshold]);
        $collection->setPageSize(self::BATCH_SIZE);

        $eligible = [];
        foreach ($collection->getItems() as $quote) {
            if (!$quote instanceof Quote) {
                continue;
            }
            $quoteIdRaw = $quote->getId();
            if (!is_scalar($quoteIdRaw)) {
                continue;
            }
            $quoteId = (int) $quoteIdRaw;
            if ($quoteId === 0) {
                continue;
            }
            if ($this->logRepository->hasRecovered($quoteId)) {
                continue;
            }
            if ($this->logRepository->hasSent($quoteId, $stageKey)) {
                continue;
            }
            $eligible[] = $quote;
        }

        return $eligible;
    }
}
