<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Model\Email;

use Magebit\AbandonedCart\Model\Config;
use Magento\Framework\App\Area;
use Magento\Framework\Mail\Template\TransportBuilder;

/**
 * TransportBuilder wrapper. Renders markdown body to HTML, renders the cart-items
 * grid, then dispatches the email and returns send status.
 */
class EmailDispatcher
{
    /**
     * @param TransportBuilder $transportBuilder
     * @param MarkdownRenderer $markdownRenderer
     * @param CartItemsRenderer $cartItemsRenderer
     * @param Config $config
     */
    public function __construct(
        private readonly TransportBuilder $transportBuilder,
        private readonly MarkdownRenderer $markdownRenderer,
        private readonly CartItemsRenderer $cartItemsRenderer,
        private readonly Config $config,
    ) {
    }

    /**
     * Render and dispatch one email.
     *
     * @param int $storeId
     * @param string $recipientEmail
     * @param string $recipientName
     * @param string $templateId
     * @param GeneratedEmail $generated
     * @param CartItemSummary[] $cartItems
     * @param string $currency
     * @param array $extraVars
     * @phpstan-param array<string, mixed> $extraVars
     * @return void
     */
    public function send(
        int $storeId,
        string $recipientEmail,
        string $recipientName,
        string $templateId,
        GeneratedEmail $generated,
        array $cartItems,
        string $currency,
        array $extraVars = [],
    ): void {
        $bodyHtml = $this->markdownRenderer->render($generated->bodyMarkdown);
        $cartItemsHtml = $this->cartItemsRenderer->render($cartItems, $currency);

        $vars = array_merge(
            [
                'subject' => $generated->subject,
                'preheader' => $generated->preheader,
                'body_html' => $bodyHtml,
                'cart_items_html' => $cartItemsHtml,
                'customer_name' => $recipientName,
            ],
            $extraVars,
        );

        $transport = $this->transportBuilder
            ->setTemplateIdentifier($templateId)
            ->setTemplateOptions([
                'area' => Area::AREA_FRONTEND,
                'store' => $storeId,
            ])
            ->setTemplateVars($vars)
            ->setFromByScope($this->config->getSenderIdentity($storeId), $storeId)
            ->addTo($recipientEmail, $recipientName)
            ->getTransport();

        $transport->sendMessage();
    }
}
