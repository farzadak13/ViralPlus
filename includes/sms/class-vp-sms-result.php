<?php
/**
 * نتیجه‌ی یک تلاش ارسال پیامک — برای یکسان‌سازی خروجی همه‌ی گیت‌وی‌ها
 * (هر پنل فرمت پاسخ خام متفاوتی دارد؛ این کلاس آن را به یک شکل ثابت تبدیل می‌کند)
 */

defined( 'ABSPATH' ) || exit;

final class VP_SMS_Result {

    public function __construct(
        public readonly bool   $success,
        public readonly string $message = '',
        public readonly mixed  $raw_response = null,
        public readonly ?string $provider_message_id = null
    ) {}

    public static function ok( ?string $message_id = null, mixed $raw = null ): self {
        return new self( true, 'ارسال موفق', $raw, $message_id );
    }

    public static function fail( string $error_message, mixed $raw = null ): self {
        return new self( false, $error_message, $raw, null );
    }
}