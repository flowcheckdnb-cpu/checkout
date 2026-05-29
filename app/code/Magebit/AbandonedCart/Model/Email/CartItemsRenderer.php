<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Model\Email;

use Magento\Framework\Escaper;

/**
 * Renders a cart-items table into email-client-safe HTML (table layout + inline styles).
 *
 * Designed to survive Gmail / Outlook / Apple Mail: explicit dimensions on
 * <img>, no flexbox/grid, no <style> dependency.
 */
class CartItemsRenderer
{
    /**
     * @param Escaper $escaper
     */
    public function __construct(
        private readonly Escaper $escaper,
    ) {
    }

    /**
     * Render cart items as an HTML table.
     *
     * @param CartItemSummary[] $items
     * @param string $currency
     * @return string
     */
    public function render(array $items, string $currency): string
    {
        if (count($items) === 0) {
            return '';
        }

        $rows = [];
        foreach ($items as $item) {
            $rows[] = $this->row($item, $currency);
        }

        return '<table cellpadding="0" cellspacing="0" border="0" width="100%"'
            . ' style="border-collapse:collapse;width:100%">'
            . implode('', $rows)
            . '</table>';
    }

    /**
     * Build one <tr> for one cart item.
     *
     * @param CartItemSummary $item
     * @param string $currency
     * @return string
     */
    private function row(CartItemSummary $item, string $currency): string
    {
        $nameEsc = $this->escString($item->name);
        $qty = $this->formatQty($item->qty);
        $price = $currency . ' ' . number_format($item->rowTotal, 2, '.', '');
        $priceEsc = $this->escString($price);
        $hasUrl = $item->productUrl !== '';
        $urlEsc = $hasUrl ? $this->escaper->escapeUrl($item->productUrl) : '';

        $thumb = $this->thumbCell($item, $nameEsc, $urlEsc);
        $linkOpen = $hasUrl ? '<a href="' . $urlEsc . '" style="color:#111827;text-decoration:none">' : '';
        $linkClose = $hasUrl ? '</a>' : '';

        return '<tr>'
            . '<td valign="top" width="80" style="padding:12px 16px 12px 0">' . $thumb . '</td>'
            . '<td valign="top" style="padding:12px 0;font-family:-apple-system,Segoe UI,Arial,sans-serif">'
                . '<div style="font-size:15px;font-weight:600;color:#111827;margin-bottom:4px">'
                    . $linkOpen . $nameEsc . $linkClose
                . '</div>'
                . '<div style="font-size:13px;color:#6b7280">'
                    . 'Qty: ' . $qty . ' &middot; ' . $priceEsc
                . '</div>'
            . '</td>'
            . '</tr>';
    }

    /**
     * Build the thumbnail cell with fixed dimensions so the layout survives image-blocking clients.
     *
     * @param CartItemSummary $item
     * @param string $altEsc Pre-escaped product name.
     * @param string $urlEsc Pre-escaped product URL (empty if none).
     * @return string
     */
    private function thumbCell(CartItemSummary $item, string $altEsc, string $urlEsc): string
    {
        if ($item->imageUrl === '') {
            return '<div style="width:64px;height:64px;background:#f3f4f6;border-radius:8px"></div>';
        }
        $srcEsc = $this->escaper->escapeUrl($item->imageUrl);
        $img = '<img src="' . $srcEsc . '" alt="' . $altEsc . '"'
            . ' width="64" height="64"'
            . ' style="display:block;width:64px;height:64px;'
            . 'object-fit:cover;border-radius:8px;border:1px solid #e5e7eb">';
        if ($urlEsc !== '') {
            return '<a href="' . $urlEsc . '">' . $img . '</a>';
        }
        return $img;
    }

    /**
     * Format qty without trailing zeros.
     *
     * @param float $qty
     * @return string
     */
    private function formatQty(float $qty): string
    {
        $formatted = number_format($qty, 2, '.', '');
        return rtrim(rtrim($formatted, '0'), '.');
    }

    /**
     * HTML-escape a string, normalizing escapeHtml's string|array return to a plain string.
     *
     * @param string $raw
     * @return string
     */
    private function escString(string $raw): string
    {
        $escaped = $this->escaper->escapeHtml($raw);
        if (is_array($escaped)) {
            return implode('', array_map('strval', $escaped));
        }
        return $escaped;
    }
}
