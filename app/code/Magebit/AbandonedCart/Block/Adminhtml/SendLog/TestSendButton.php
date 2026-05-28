<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Block\Adminhtml\SendLog;

use Magento\Backend\Model\UrlInterface;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

/**
 * Toolbar button on the send-log listing that links to the test-send form.
 */
class TestSendButton implements ButtonProviderInterface
{
    /**
     * @param UrlInterface $url
     */
    public function __construct(
        private readonly UrlInterface $url,
    ) {
    }

    /**
     * Toolbar button definition pointing at the test-send form.
     *
     * @return array<string, mixed>
     */
    public function getButtonData(): array
    {
        return [
            'label' => __('Send Test Email'),
            'on_click' => sprintf(
                "location.href = '%s';",
                $this->url->getUrl('magebit_abandonedcart/sendlog/testsend'),
            ),
            'class' => 'primary',
            'sort_order' => 100,
        ];
    }
}
