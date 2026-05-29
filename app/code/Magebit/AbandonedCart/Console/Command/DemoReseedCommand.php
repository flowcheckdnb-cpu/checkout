<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Console\Command;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Dev-only helper: reseeds a quote with a sample product so the recovery-email
 * demo can be re-run after the cart was emptied (typically by placing the
 * order during the previous take). Safe to run repeatedly.
 */
class DemoReseedCommand extends Command
{
    private const NAME = 'magebit:abandoned-cart:demo-reseed';
    private const OPT_QUOTE_ID = 'quote-id';
    private const OPT_SKU = 'sku';
    private const OPT_QTY = 'qty';
    private const OPT_STORE_ID = 'store-id';
    private const DEFAULT_QUOTE_ID = '1';
    private const DEFAULT_SKU = '24-MB01';
    private const DEFAULT_QTY = '1';
    private const DEFAULT_STORE_ID = '1';

    /**
     * @param State $appState
     * @param CartRepositoryInterface $cartRepository
     * @param ProductRepositoryInterface $productRepository
     */
    public function __construct(
        private readonly State $appState,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly ProductRepositoryInterface $productRepository,
    ) {
        parent::__construct();
    }

    /**
     * Configure the command name, description, and options.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName(self::NAME);
        $this->setDescription(
            'Demo helper — adds a sample product to a quote and reactivates it, '
            . 'so the recovery-email demo can be re-run.',
        );
        $this->addOption(
            self::OPT_QUOTE_ID,
            null,
            InputOption::VALUE_REQUIRED,
            'Quote (cart) id to reseed.',
            self::DEFAULT_QUOTE_ID,
        );
        $this->addOption(
            self::OPT_SKU,
            null,
            InputOption::VALUE_REQUIRED,
            'SKU of a simple product to add.',
            self::DEFAULT_SKU,
        );
        $this->addOption(
            self::OPT_QTY,
            null,
            InputOption::VALUE_REQUIRED,
            'Quantity to add.',
            self::DEFAULT_QTY,
        );
        $this->addOption(
            self::OPT_STORE_ID,
            null,
            InputOption::VALUE_REQUIRED,
            'Store id whose pricing/availability to use.',
            self::DEFAULT_STORE_ID,
        );
        parent::configure();
    }

    /**
     * Add the sample product, reactivate the quote, persist.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        } catch (Throwable $alreadySet) {
            unset($alreadySet);
        }

        $quoteId = (int) $this->resolveOption($input, self::OPT_QUOTE_ID, self::DEFAULT_QUOTE_ID);
        $sku = $this->resolveOption($input, self::OPT_SKU, self::DEFAULT_SKU);
        $qty = (float) $this->resolveOption($input, self::OPT_QTY, self::DEFAULT_QTY);
        $storeId = (int) $this->resolveOption($input, self::OPT_STORE_ID, self::DEFAULT_STORE_ID);

        if ($quoteId === 0 || $sku === '' || $qty <= 0.0) {
            $output->writeln('<error>quote-id, sku, qty must all be non-empty/positive.</error>');
            return Command::INVALID;
        }

        try {
            $quote = $this->cartRepository->get($quoteId);
        } catch (NoSuchEntityException) {
            $output->writeln(sprintf('<error>Quote %d not found.</error>', $quoteId));
            return Command::FAILURE;
        }
        if (!$quote instanceof Quote) {
            $output->writeln('<error>Unexpected quote type returned by repository.</error>');
            return Command::FAILURE;
        }

        try {
            $product = $this->productRepository->get($sku, false, $storeId);
        } catch (NoSuchEntityException) {
            $output->writeln(sprintf('<error>Product SKU "%s" not found in store %d.</error>', $sku, $storeId));
            return Command::FAILURE;
        }
        if (!$product instanceof Product) {
            $output->writeln('<error>Unexpected product type returned by repository.</error>');
            return Command::FAILURE;
        }

        try {
            $addResult = $quote->addProduct($product, $qty);
        } catch (Throwable $e) {
            $output->writeln(sprintf('<error>addProduct failed: %s</error>', $e->getMessage()));
            return Command::FAILURE;
        }
        if (is_string($addResult)) {
            // Magento returns a localized error message string on validation failure.
            $output->writeln(sprintf('<error>addProduct rejected: %s</error>', $addResult));
            return Command::FAILURE;
        }

        $quote->setIsActive(true);
        $quote->collectTotals();
        $this->cartRepository->save($quote);

        $output->writeln(sprintf(
            '<info>Reseeded quote %d with %s (qty %s). items_count=%s items_qty=%s</info>',
            $quoteId,
            $sku,
            (string) $qty,
            (string) $quote->getItemsCount(),
            (string) $quote->getItemsQty(),
        ));

        return Command::SUCCESS;
    }

    /**
     * Read an option, falling back to its default if the input layer returns null/non-scalar.
     *
     * @param InputInterface $input
     * @param string $name
     * @param string $default
     * @return string
     */
    private function resolveOption(InputInterface $input, string $name, string $default): string
    {
        $raw = $input->getOption($name);
        if (is_scalar($raw)) {
            $value = (string) $raw;
            if ($value !== '') {
                return $value;
            }
        }
        return $default;
    }
}
