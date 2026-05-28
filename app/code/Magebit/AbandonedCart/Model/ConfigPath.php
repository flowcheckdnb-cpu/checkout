<?php

declare(strict_types=1);

namespace Magebit\AbandonedCart\Model;

/**
 * Module config xpath constants.
 */
class ConfigPath
{
    public const GENERAL_ENABLED = 'magebit_abandonedcart/general/enabled';
    public const GENERAL_SENDER_EMAIL = 'magebit_abandonedcart/general/sender_email';
    public const GENERAL_UNSUBSCRIBE_ROUTE = 'magebit_abandonedcart/general/unsubscribe_route';

    public const STAGE_1_DELAY = 'magebit_abandonedcart/stages/stage_1/delay_minutes';
    public const STAGE_1_TEMPLATE = 'magebit_abandonedcart/stages/stage_1/template';
    public const STAGE_2_DELAY = 'magebit_abandonedcart/stages/stage_2/delay_minutes';
    public const STAGE_2_TEMPLATE = 'magebit_abandonedcart/stages/stage_2/template';
    public const STAGE_3_DELAY = 'magebit_abandonedcart/stages/stage_3/delay_minutes';
    public const STAGE_3_TEMPLATE = 'magebit_abandonedcart/stages/stage_3/template';
    public const STAGE_3_COUPON_RULE = 'magebit_abandonedcart/stages/stage_3/coupon_rule_id';
    public const STAGE_3_COUPON_TTL_HOURS = 'magebit_abandonedcart/stages/stage_3/coupon_ttl_hours';

    public const LOW_STOCK_ENABLED = 'magebit_abandonedcart/low_stock/enabled';
    public const LOW_STOCK_THRESHOLD = 'magebit_abandonedcart/low_stock/threshold_qty';
    public const LOW_STOCK_TEMPLATE = 'magebit_abandonedcart/low_stock/template';
    public const LOW_STOCK_COUPON_RULE = 'magebit_abandonedcart/low_stock/coupon_rule_id';
    public const LOW_STOCK_COUPON_TTL_HOURS = 'magebit_abandonedcart/low_stock/coupon_ttl_hours';
    public const LOW_STOCK_FREQUENCY_CAP = 'magebit_abandonedcart/low_stock/frequency_cap_hours';

    public const BRAND_NAME = 'magebit_abandonedcart/brand_voice/brand_name';
    public const BRAND_VOICE_DESC = 'magebit_abandonedcart/brand_voice/voice_description';
    public const BRAND_TONE = 'magebit_abandonedcart/brand_voice/tone';
    public const BRAND_LOCALE = 'magebit_abandonedcart/brand_voice/locale';

    public const GEMINI_ENABLED = 'magebit_abandonedcart/gemini_api/enable_ai';
    public const GEMINI_API_KEY = 'magebit_abandonedcart/gemini_api/api_key';
    public const GEMINI_MODEL = 'magebit_abandonedcart/gemini_api/model';
    public const GEMINI_TIMEOUT = 'magebit_abandonedcart/gemini_api/request_timeout_seconds';

    /**
     * Map stage key → [delay path, template path].
     */
    public const STAGE_PATHS = [
        'stage_1' => [self::STAGE_1_DELAY, self::STAGE_1_TEMPLATE],
        'stage_2' => [self::STAGE_2_DELAY, self::STAGE_2_TEMPLATE],
        'stage_3' => [self::STAGE_3_DELAY, self::STAGE_3_TEMPLATE],
    ];
}
