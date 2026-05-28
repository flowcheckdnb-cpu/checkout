<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\Phrase;
use Magento\SalesRule\Model\ResourceModel\Rule\CollectionFactory;
use Magento\SalesRule\Model\Rule;

class CartPriceRule implements OptionSourceInterface
{
    /**
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
    ) {
    }

    /**
     * Active cart price rules eligible as coupon sources.
     *
     * @return array<int, array{value: string, label: Phrase|string}>
     */
    public function toOptionArray(): array
    {
        $options = [];
        $options[] = ['value' => '', 'label' => __('-- Select a rule --')];

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('is_active', ['eq' => '1']);

        /** @var Rule $rule */
        foreach ($collection->getItems() as $rule) {
            $id = $rule->getId();
            if (!is_scalar($id)) {
                continue;
            }
            $name = $rule->getName();
            $options[] = [
                'value' => (string) $id,
                'label' => is_string($name) && $name !== '' ? $name : (string) __('Unnamed Rule'),
            ];
        }

        return $options;
    }
}
