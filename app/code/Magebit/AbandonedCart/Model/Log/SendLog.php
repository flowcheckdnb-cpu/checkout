<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Model\Log;

use Magebit\AbandonedCart\Model\ResourceModel\SendLog as SendLogResource;
use Magento\Framework\Model\AbstractModel;

/**
 * Active-record model for one entry in magebit_abandoned_cart_log.
 *
 * @method int|null getQuoteId()
 * @method self setQuoteId(int $value)
 * @method string|null getCustomerEmail()
 * @method self setCustomerEmail(string $value)
 * @method int|null getStoreId()
 * @method self setStoreId(int $value)
 * @method string|null getEmailType()
 * @method self setEmailType(string $value)
 * @method string|null getStageKey()
 * @method self setStageKey(string $value)
 * @method string|null getSentAt()
 * @method string|null getStatus()
 * @method self setStatus(string $value)
 * @method string|null getProviderMessageId()
 * @method self setProviderMessageId(?string $value)
 * @method string|null getCouponCode()
 * @method self setCouponCode(?string $value)
 * @method string|null getRecoveryToken()
 * @method self setRecoveryToken(string $value)
 * @method int|null getAiGenerated()
 * @method self setAiGenerated(int $value)
 * @method string|null getRecoveredAt()
 * @method self setRecoveredAt(?string $value)
 * @method int|null getUnsubscribed()
 * @method self setUnsubscribed(int $value)
 */
class SendLog extends AbstractModel
{
    /**
     * Standard Magento init pattern.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(SendLogResource::class);
    }
}
