<?php
/**
 * مدیر مرکزی پیامک — رجیستری گیت‌وی‌ها و نقطه‌ی ورود واحد برای ارسال پیامک
 *
 * مسئولیت‌ها:
 *  - نگه‌داری لیست همه‌ی Adapter های پنل پیامکی موجود
 *  - خواندن تنظیمات (پنل فعال + کلیدهای API) از گزینه‌های وردپرس
 *  - فراهم‌کردن متد send() که بقیه‌ی افزونه (کیف‌پول، گیمیفیکیشن، ضد تقلب) صدا می‌زنند
 *  - اتصال خودکار به رویدادهای کلیدی افزونه برای ارسال پیامک‌های از پیش‌تعریف‌شده
 *    (واریز کمیسیون، هشدار انقضای کیف‌پول، هشدار تقلب) — هرکدام جدا قابل فعال/غیرفعال
 *
 * افزودن یک پنل جدید در آینده فقط نیاز به نوشتن یک Adapter جدید (مطابق
 * VP_SMS_Gateway_Abstract) و افزودن آن به متد get_available_gateways() دارد؛
 * هیچ تغییری در بقیه‌ی افزونه لازم نیست.
 */

defined( 'ABSPATH' ) || exit;

class VP_SMS_Manager {

    private static ?VP_SMS_Manager $instance = null;

    const OPTION_ACTIVE_GATEWAY = 'vp_sms_active_gateway';
    const OPTION_GATEWAY_CONFIG = 'vp_sms_gateway_config'; // آرایه‌ی [gateway_key => [field => value]]
    const OPTION_ENABLED        = 'vp_sms_enabled';
    const OPTION_EVENTS         = 'vp_sms_events'; // آرایه‌ی [event_key => 0|1]

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // اتصال به رویدادهای موجود افزونه — هرکدام داخل متد خودش چک می‌کند که
        // فعال است یا نه، تا اگر مدیر فروشگاه پیامک را کلاً غیرفعال کرده باشد
        // هیچ هزینه‌ی اضافه‌ای (حتی چک ساده) در مسیر اصلی ایجاد نشود مگر لازم باشد.
        add_action( 'vp_commission_approved',     [ $this, 'on_commission_approved' ], 10, 2 );
        add_action( 'vp_wallet_expiring_soon',    [ $this, 'on_wallet_expiring_soon' ], 10, 2 );
        add_action( 'vp_fraud_detected',          [ $this, 'on_fraud_detected' ], 10, 4 );
        add_action( 'vp_level_updated',           [ $this, 'on_level_updated' ], 10, 3 );
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  رجیستری گیت‌وی‌ها
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * لیست همه‌ی Adapter های شناخته‌شده — برای افزودن پنل جدید فقط این آرایه
     * و فایل Adapter جدید لازم است.
     *
     * @return array<string, class-string<VP_SMS_Gateway_Abstract>>
     */
    public static function get_available_gateways(): array {
        $gateways = [
            'kavenegar'   => VP_SMS_Gateway_Kavenegar::class,
            'melipayamak' => VP_SMS_Gateway_Melipayamak::class,
            'farazsms'    => VP_SMS_Gateway_Farazsms::class,
            'ippanel'     => VP_SMS_Gateway_Ippanel::class,
        ];

        /**
         * فیلتر برای افزودن گیت‌وی‌های شخص‌ثالث توسط دیگر افزونه‌ها/توسعه‌دهنده‌ها
         * بدون نیاز به ویرایش هسته‌ی ViralPlus.
         */
        return apply_filters( 'vp_sms_available_gateways', $gateways );
    }

    /**
     * نمونه‌ی فعال گیت‌وی بر اساس تنظیمات فعلی — یا null اگر چیزی انتخاب/فعال نشده
     */
    public function get_active_gateway(): ?VP_SMS_Gateway_Abstract {
        if ( ! $this->is_enabled() ) {
            return null;
        }

        $active_key = get_option( self::OPTION_ACTIVE_GATEWAY, '' );
        $gateways   = self::get_available_gateways();

        if ( empty( $active_key ) || ! isset( $gateways[ $active_key ] ) ) {
            return null;
        }

        $all_config = get_option( self::OPTION_GATEWAY_CONFIG, [] );
        $config     = $all_config[ $active_key ] ?? [];

        $class = $gateways[ $active_key ];
        return new $class( $config );
    }

    public function is_enabled(): bool {
        return (bool) get_option( self::OPTION_ENABLED, 0 );
    }

    /**
     * آیا یک رویداد خاص (مثلاً واریز کمیسیون) باید پیامک بفرستد؟
     * پیش‌فرض: false — مدیر باید صراحتاً هر رویداد را از پنل تنظیمات فعال کند.
     */
    public function is_event_enabled( string $event_key ): bool {
        $events = get_option( self::OPTION_EVENTS, [] );
        return ! empty( $events[ $event_key ] );
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  نقطه‌ی ورود عمومی ارسال
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * ارسال پیامک به یک کاربر وردپرسی (با خوانی شماره از user_meta استاندارد ووکامرس)
     *
     * @param int    $user_id شناسه‌ی کاربر گیرنده
     * @param string $text    متن پیام (از قبل رندر شده — جای‌گذاری متغیرها قبل از این متد انجام می‌شود)
     */
    public function send_to_user( int $user_id, string $text ): VP_SMS_Result {
        $mobile = get_user_meta( $user_id, 'billing_phone', true );

        if ( empty( $mobile ) ) {
            return VP_SMS_Result::fail( 'شماره موبایل برای این کاربر ثبت نشده است.' );
        }

        return $this->send( $mobile, $text );
    }

    /**
     * ارسال مستقیم به یک شماره — لایه‌ی نهایی که همه‌چیز از آن عبور می‌کند
     */
    public function send( string $mobile, string $text ): VP_SMS_Result {
        $gateway = $this->get_active_gateway();

        if ( null === $gateway ) {
            return VP_SMS_Result::fail( 'هیچ پنل پیامکی فعال/تنظیم‌شده‌ای وجود ندارد.' );
        }

        $result = $gateway->send( $mobile, $text );

        do_action( 'vp_sms_sent', $mobile, $text, $result );

        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  قالب‌های پیام پیش‌فرض (قابل تغییر با فیلتر)
    // ─────────────────────────────────────────────────────────────────────────

    private function render_template( string $event_key, array $vars ): string {
        $defaults = [
            'commission_approved' => 'سلام {name}، کمیسیون {amount} تومانی شما تأیید و به کیف پول واریز شد.',
            'wallet_expiring'     => 'سلام {name}، {amount} تومان از اعتبار کیف پول شما تا ۷ روز دیگر منقضی می‌شود. فرصت استفاده را از دست ندهید!',
            'fraud_detected'      => 'هشدار ViralPlus: رفتار مشکوک برای سفیر #{ambassador_id} ثبت شد. لطفاً از پنل مدیریت بررسی کنید.',
            'level_up'            => 'سلام {name}، تبریک! سطح شما در برنامه‌ی سفیر فروش به «{level}» ارتقا یافت.',
        ];

        $template = apply_filters( 'vp_sms_template_' . $event_key, $defaults[ $event_key ] ?? '', $vars );

        foreach ( $vars as $key => $value ) {
            $template = str_replace( '{' . $key . '}', (string) $value, $template );
        }

        return $template;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Handler های رویدادهای موجود افزونه
    // ─────────────────────────────────────────────────────────────────────────

    /** بعد از تأیید نهایی کمیسیون (vp_commission_approved, $commission_id, $ambassador_id) */
    public function on_commission_approved( int $commission_id, int $ambassador_id ): void {
        if ( ! $this->is_event_enabled( 'commission_approved' ) ) {
            return;
        }

        global $wpdb;
        $table  = VP_Database::table( 'commissions' );
        $amount = (float) $wpdb->get_var( $wpdb->prepare( "SELECT amount FROM {$table} WHERE id = %d", $commission_id ) );

        $user = get_userdata( $ambassador_id );
        $text = $this->render_template( 'commission_approved', [
            'name'   => $user ? $user->display_name : '',
            'amount' => number_format( $amount ),
        ] );

        $this->send_to_user( $ambassador_id, $text );
    }

    /** هشدار انقضای کیف‌پول (vp_wallet_expiring_soon, $user_id, $amount) */
    public function on_wallet_expiring_soon( int $user_id, float $amount ): void {
        if ( ! $this->is_event_enabled( 'wallet_expiring' ) ) {
            return;
        }

        $user = get_userdata( $user_id );
        $text = $this->render_template( 'wallet_expiring', [
            'name'   => $user ? $user->display_name : '',
            'amount' => number_format( $amount ),
        ] );

        $this->send_to_user( $user_id, $text );
    }

    /** تشخیص تقلب (vp_fraud_detected, $ambassador_id, $buyer_id, $order_id, $result) — به مدیر فروشگاه */
    public function on_fraud_detected( int $ambassador_id, int $buyer_id, int $order_id, array $result ): void {
        if ( ! $this->is_event_enabled( 'fraud_detected' ) ) {
            return;
        }

        $admin_mobile = get_option( 'vp_sms_admin_mobile', '' );
        if ( empty( $admin_mobile ) ) {
            return;
        }

        $text = $this->render_template( 'fraud_detected', [ 'ambassador_id' => $ambassador_id ] );
        $this->send( $admin_mobile, $text );
    }

    /** ارتقای سطح سفیر (vp_level_updated, $user_id, $level, $total) */
    public function on_level_updated( int $user_id, string $level, int $total ): void {
        if ( ! $this->is_event_enabled( 'level_up' ) ) {
            return;
        }
        // سطح برنزی پیش‌فرض شروع است؛ پیامک فقط برای ارتقای واقعی (نقره/طلایی) ارسال شود
        if ( $level === 'bronze' ) {
            return;
        }

        $user  = get_userdata( $user_id );
        $label = $level === 'gold' ? 'طلایی' : 'نقره‌ای';
        $text  = $this->render_template( 'level_up', [
            'name'  => $user ? $user->display_name : '',
            'level' => $label,
        ] );

        $this->send_to_user( $user_id, $text );
    }
}