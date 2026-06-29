<?php
/**
 * کلاس پایه‌ی انتزاعی برای همه‌ی Adapter های پنل پیامکی
 *
 * هر پنل پیامکی (کاوه‌نگار، ملی‌پیامک، فراز اس‌ام‌اس، آی‌پی‌پنل و...) باید این کلاس
 * را extend کند و متد send() را با فرمت API همان پنل پیاده‌سازی کند.
 *
 * این لایه‌ی انتزاع باعث می‌شود VP_SMS_Manager و بقیه‌ی افزونه هیچ‌وقت مستقیماً با
 * API خام یک پنل خاص کار نکنند — تغییر/افزودن پنل جدید فقط نیاز به یک Adapter
 * جدید دارد، بدون لمس بقیه‌ی کد.
 */

defined( 'ABSPATH' ) || exit;

abstract class VP_SMS_Gateway_Abstract {

    /** @var array تنظیمات این گیت‌وی (کلید API، شماره فرستنده، ...) — از گزینه‌های وردپرس خوانده می‌شود */
    protected array $settings;

    public function __construct( array $settings = [] ) {
        $this->settings = $settings;
    }

    /**
     * شناسه‌ی یکتای این گیت‌وی — باید در فایل خودش override شود
     * مثلاً: 'kavenegar', 'melipayamak', 'farapayamak', 'ippanel'
     */
    abstract public static function key(): string;

    /**
     * نام نمایشی فارسی برای نمایش در پنل تنظیمات
     */
    abstract public static function label(): string;

    /**
     * فیلدهای تنظیماتی مورد نیاز این گیت‌وی (برای رندر خودکار فرم در صفحه‌ی تنظیمات)
     * هر فیلد: ['key' => 'api_key', 'label' => 'کلید API', 'type' => 'text|password']
     *
     * @return array<int, array{key:string,label:string,type:string}>
     */
    abstract public static function fields(): array;

    /**
     * ارسال واقعی پیامک — هر Adapter این را با فرمت API پنل خودش پیاده‌سازی می‌کند
     *
     * @param string $to   شماره موبایل گیرنده (فرمت 09xxxxxxxxx)
     * @param string $text متن پیام
     * @return VP_SMS_Result
     */
    abstract public function send( string $to, string $text ): VP_SMS_Result;

    /**
     * نرمال‌سازی شماره موبایل ایران به فرمت 09xxxxxxxxx
     * بسیاری از پنل‌ها فرمت‌های ورودی متفاوت می‌خواهند؛ این یک پایه‌ی مشترک است.
     */
    protected function normalize_mobile( string $mobile ): string {
        $mobile = preg_replace( '/\D/', '', $mobile ); // فقط رقم

        if ( str_starts_with( $mobile, '98' ) ) {
            $mobile = '0' . substr( $mobile, 2 );
        } elseif ( str_starts_with( $mobile, '+98' ) ) {
            $mobile = '0' . substr( $mobile, 3 );
        } elseif ( ! str_starts_with( $mobile, '0' ) ) {
            $mobile = '0' . $mobile;
        }

        return $mobile;
    }
}