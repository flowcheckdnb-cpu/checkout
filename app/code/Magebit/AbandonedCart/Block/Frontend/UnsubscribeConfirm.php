<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Block\Frontend;

use Magento\Framework\View\Element\Template;

/**
 * Backing block for the unsubscribe confirmation page.
 *
 * The Unsubscribe controller looks this block up via its layout name and
 * stores the unsubscribed email address on it as block data. The phtml
 * reads it back via {@see self::getUnsubscribedEmail()} — no globals,
 * no Registry.
 */
class UnsubscribeConfirm extends Template
{
    public const BLOCK_NAME = 'magebit.abandonedcart.unsubscribe.confirm';
    public const DATA_KEY_EMAIL = 'unsubscribed_email';

    /**
     * Return the email address that was just unsubscribed, or empty if unknown.
     *
     * @return string
     */
    public function getUnsubscribedEmail(): string
    {
        $raw = $this->getData(self::DATA_KEY_EMAIL);
        return is_string($raw) ? $raw : '';
    }
}
