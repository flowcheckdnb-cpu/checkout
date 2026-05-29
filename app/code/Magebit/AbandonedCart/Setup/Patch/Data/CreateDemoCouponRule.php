<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Setup\Patch\Data;

use Magebit\AbandonedCart\Model\ConfigPath;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\SalesRule\Model\ResourceModel\Rule as RuleResource;
use Magento\SalesRule\Model\ResourceModel\Rule\CollectionFactory as RuleCollectionFactory;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\Rule\Condition\Combine as ConditionsCombine;
use Magento\SalesRule\Model\Rule\Condition\Product\Combine as ActionsCombine;
use Magento\SalesRule\Model\RuleFactory;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Provisions a self-contained cart price rule that stage_3 + low_stock coupons can mint
 * codes against without depending on sample data.
 *
 * Idempotent: looks for an existing rule with the marker name before creating one.
 * Re-running `bin/magento setup:upgrade` is safe.
 */
class CreateDemoCouponRule implements DataPatchInterface
{
    private const RULE_NAME = 'Magebit AbandonedCart — Demo Coupon (any cart)';
    private const DISCOUNT_PERCENT = 20;

    /**
     * @param RuleFactory $ruleFactory
     * @param RuleResource $ruleResource
     * @param RuleCollectionFactory $ruleCollectionFactory
     * @param WriterInterface $configWriter
     * @param StoreManagerInterface $storeManager
     * @param GroupRepositoryInterface $groupRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param State $appState
     */
    public function __construct(
        private readonly RuleFactory $ruleFactory,
        private readonly RuleResource $ruleResource,
        private readonly RuleCollectionFactory $ruleCollectionFactory,
        private readonly WriterInterface $configWriter,
        private readonly StoreManagerInterface $storeManager,
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly State $appState,
    ) {
    }

    /**
     * Apply the patch: create-or-find the demo rule, wire it into module config.
     *
     * @return $this
     */
    public function apply(): static
    {
        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        } catch (LocalizedException $alreadySet) {
            // Area was already set on this request (e.g. from a sibling patch). Safe to ignore.
            unset($alreadySet);
        }

        $rule = $this->findExistingRule() ?? $this->createRule();
        $ruleIdRaw = $rule->getId();
        if (!is_scalar($ruleIdRaw)) {
            return $this;
        }
        $ruleId = (int) $ruleIdRaw;
        if ($ruleId === 0) {
            return $this;
        }

        $this->configWriter->save(ConfigPath::STAGE_3_COUPON_RULE, (string) $ruleId);
        $this->configWriter->save(ConfigPath::LOW_STOCK_COUPON_RULE, (string) $ruleId);

        return $this;
    }

    /**
     * Look for an already-installed marker rule by name.
     *
     * @return Rule|null
     */
    private function findExistingRule(): ?Rule
    {
        $collection = $this->ruleCollectionFactory->create();
        $collection->addFieldToFilter('name', ['eq' => self::RULE_NAME]);
        $collection->setPageSize(1);
        $row = $collection->getFirstItem();
        if (!$row instanceof Rule) {
            return null;
        }
        if ($row->getId() === null) {
            return null;
        }
        return $row;
    }

    /**
     * Create the no-conditions, all-websites, all-groups, auto-coupon rule.
     *
     * @return Rule
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Exception
     */
    private function createRule(): Rule
    {
        $rule = $this->ruleFactory->create();
        $rule->setName(self::RULE_NAME);
        $rule->setDescription(
            'Auto-created by Magebit_AbandonedCart for stage_3 + low_stock coupons.'
            . ' Safe to delete in production once a real rule is configured.',
        );
        $rule->setIsActive(1);
        $rule->setWebsiteIds($this->getAllWebsiteIds());
        $rule->setCustomerGroupIds($this->getAllCustomerGroupIds());
        $rule->setCouponType(Rule::COUPON_TYPE_AUTO);
        $rule->setUseAutoGeneration(1);
        $rule->setSimpleAction(Rule::BY_PERCENT_ACTION);
        $rule->setDiscountAmount(self::DISCOUNT_PERCENT);
        $rule->setStopRulesProcessing(0);
        $rule->setSortOrder(0);

        $rule->setConditionsSerialized((string) json_encode([
            'type' => ConditionsCombine::class,
            'attribute' => null,
            'operator' => null,
            'value' => '1',
            'is_value_processed' => null,
            'aggregator' => 'all',
            'conditions' => [],
        ]));

        $rule->setActionsSerialized((string) json_encode([
            'type' => ActionsCombine::class,
            'attribute' => null,
            'operator' => null,
            'value' => '1',
            'is_value_processed' => null,
            'aggregator' => 'all',
        ]));

        $this->ruleResource->save($rule);
        return $rule;
    }

    /**
     * Collect every website ID — the rule applies everywhere by default.
     *
     * @return int[]
     */
    private function getAllWebsiteIds(): array
    {
        $ids = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $idRaw = $website->getId();
            if (is_scalar($idRaw)) {
                $ids[] = (int) $idRaw;
            }
        }
        return $ids;
    }

    /**
     * Collect every customer-group ID — the rule applies to all groups.
     *
     * @return int[]
     */
    private function getAllCustomerGroupIds(): array
    {
        $criteria = $this->searchCriteriaBuilder->create();
        $result = $this->groupRepository->getList($criteria);
        $ids = [];
        foreach ($result->getItems() as $group) {
            $idRaw = $group->getId();
            if (is_scalar($idRaw)) {
                $ids[] = (int) $idRaw;
            }
        }
        return $ids;
    }

    /**
     * Patches this one must run after — none.
     *
     * @return string[]
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * Prior patch class names this one supersedes — none.
     *
     * @return string[]
     */
    public function getAliases(): array
    {
        return [];
    }
}
