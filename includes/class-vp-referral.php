<?php
/**
 * کلاس هسته سفیر (Viral Engine)
 *
 * مسئولیت‌ها:
 *  - تولید کد تخفیف منحصربه‌فرد برای هر سفیر
 *  - ساخت لینک دعوت شخصی
 *  - ردیابی کلیک و تبدیل دعوت به خرید
 *  - نمایش اطلاعات در صفحه تشکر
 *  - مدیریت سطح‌بندی سفیر
 */

defined( 'ABSPATH' ) || exit;

class VP_Referral {

    /** نمونه Singleton */
    private static ?VP_Referral $instance = null;

    /** نام Query String برای ردیابی */
    const TRACKING_PARAM = 'vp_ref';

    /** کلیدهای متای کاربر */
    const META_COUPON      = '_vp_coupon_code';
    const META_LEVEL       = '_vp_level';        // bronze | silver | gold
    const META_TOTAL_REFS  = '_vp_total_refs';
    const META_SUSPENDED   = '_vp_suspended';

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // بعد از تکمیل سفارش → تولید کد + ذخیره دعوت
        add_action( 'woocommerce_order_status_completed',      [ $this, 'handle_order_completed' ], 10, 1 );
        // ردیابی کلیک لینک دعوت
        add_action( 'init',                                    [ $this, 'track_referral_click' ] );
        // اعمال تخفیف خودکار اگر کاربر از لینک دعوت آمده باشد
        add_action( 'woocommerce_before_calculate_totals',     [ $this, 'maybe_apply_referral_coupon' ] );
        // نمایش باکس اطلاعات سفیر در صفحه تشکر
        add_action( 'woocommerce_thankyou',                    [ $this, 'render_thankyou_box' ], 15 );
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  تولید کد تخفیف
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * تولید کد تخفیف منحصربه‌فرد برای کاربر
     * فرمت: ۳ حرف اول نام (بزرگ) + ۲ عدد تصادفی — مثال: ALI47
     *
     * @param int $user_id شناسه کاربر وردپرس
     * @return string کد تخفیف
     */
    public function get_or_create_coupon( int $user_id ): string {
        // اگر قبلاً ساخته شده برگردان
        $existing = get_user_meta( $user_id, self::META_COUPON, true );
        if ( ! empty( $existing ) ) {
            return $existing;
        }

        $code = $this->generate_unique_coupon_code( $user_id );
        $this->create_wc_coupon( $code, $user_id );
        update_user_meta( $user_id, self::META_COUPON, $code );

        return $code;
    }

    /**
     * ساخت کد تخفیف بر اساس نام کاربر
     */
    private function generate_unique_coupon_code( int $user_id ): string {
        $user      = get_userdata( $user_id );
        $first     = $user ? $user->first_name : '';
        $display   = $user ? $user->display_name : '';

        // استخراج ۳ حرف اول نام (فقط حروف انگلیسی)
        $name_raw  = ! empty( $first ) ? $first : $display;
        $name_slug = strtoupper( preg_replace( '/[^a-zA-Z]/', '', $name_raw ) );
        $prefix    = substr( $name_slug, 0, 3 );

        // اگر نام فارسی یا خالی بود از VP استفاده کن
        if ( strlen( $prefix ) < 2 ) {
            $prefix = 'VP';
        }

        // تضمین یکتا بودن با تلاش تا ۱۰ بار
        $attempts = 0;
        do {
            $code = $prefix . wp_rand( 10, 99 );
            $attempts++;
        } while ( $this->coupon_exists( $code ) && $attempts < 10 );

        // fallback: prefix + user_id
        if ( $attempts >= 10 ) {
            $code = $prefix . $user_id;
        }

        return $code;
    }

    /**
     * بررسی وجود کوپن در ووکامرس
     */
    private function coupon_exists( string $code ): bool {
        $coupon = new WC_Coupon( strtolower( $code ) );
        return (bool) $coupon->get_id();
    }

    /**
     * ساخت کوپن واقعی در ووکامرس
     */
    private function create_wc_coupon( string $code, int $user_id ): void {
        $settings        = $this->get_level_settings( $this->get_user_level( $user_id ) );
        $discount_amount = apply_filters( 'vp_coupon_discount_amount', 10, $user_id );

        $coupon = new WC_Coupon();
        $coupon->set_code( strtolower( $code ) );
        $coupon->set_discount_type( 'percent' );
        $coupon->set_amount( $discount_amount );
        $coupon->set_individual_use( false );
        $coupon->set_usage_limit( 0 );          // بی‌نهایت
        $coupon->set_usage_limit_per_user( 1 ); // هر خریدار جدید فقط یک بار
        $coupon->add_meta_data( '_vp_ambassador_id', $user_id, true );
        $coupon->save();
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  لینک دعوت
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * تولید لینک دعوت شخصی سفیر
     *
     * @param int    $user_id   شناسه سفیر
     * @param string $page_url  صفحه مقصد (پیش‌فرض: صفحه اصلی)
     */
    public function get_referral_url( int $user_id, string $page_url = '' ): string {
        $base = ! empty( $page_url ) ? $page_url : home_url( '/' );
        $code = $this->get_or_create_coupon( $user_id );

        return add_query_arg(
            [ self::TRACKING_PARAM => $code ],
            $base
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  ردیابی کلیک
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * ردیابی ورود از لینک دعوت و ذخیره در Session
     */
    public function track_referral_click(): void {
        $code = isset( $_GET[ self::TRACKING_PARAM ] )
            ? sanitize_text_field( $_GET[ self::TRACKING_PARAM ] )
            : '';

        if ( empty( $code ) ) {
            return;
        }

        // پیدا کردن سفیر از روی کد
        $ambassador_id = $this->get_ambassador_by_coupon( $code );
        if ( ! $ambassador_id ) {
            return;
        }

        // اگر خود سفیر کلیک کرده، نادیده بگیر
        if ( get_current_user_id() === $ambassador_id ) {
            return;
        }

        // ذخیره در Session ووکامرس
        if ( ! WC()->session ) {
            WC()->initialize_session();
        }
        WC()->session->set( 'vp_referral_code',   $code );
        WC()->session->set( 'vp_ambassador_id',   $ambassador_id );
        WC()->session->set( 'vp_referral_time',   time() );

        // افزایش تعداد کلیک
        $this->increment_click_count( $code );

        do_action( 'vp_referral_clicked', $code, $ambassador_id );
    }

    /**
     * اعمال خودکار کد تخفیف سفیر برای خریدار جدید
     */
    public function maybe_apply_referral_coupon( WC_Cart $cart ): void {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }

        if ( ! WC()->session ) {
            return;
        }

        $code = WC()->session->get( 'vp_referral_code' );
        if ( empty( $code ) ) {
            return;
        }

        // بررسی که کوپن قبلاً اعمال نشده باشد
        if ( ! $cart->has_discount( strtolower( $code ) ) ) {
            $cart->apply_coupon( strtolower( $code ) );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  مدیریت سفارش تکمیل‌شده
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * بعد از تکمیل سفارش:
     *  ۱. اگر خریدار از لینک دعوت آمده → ثبت دعوت موفق
     *  ۲. ساخت/تأیید کد تخفیف سفیر برای این مشتری
     */
    public function handle_order_completed( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $buyer_id = (int) $order->get_customer_id();

        // ── بخش ۱: ثبت دعوت اگر از لینک سفیر آمده ──────────────────────────
        $ref_code      = $order->get_meta( '_vp_referral_code' );
        $ambassador_id = $order->get_meta( '_vp_ambassador_id' );

        if ( empty( $ref_code ) && WC()->session ) {
            $ref_code      = WC()->session->get( 'vp_referral_code' );
            $ambassador_id = WC()->session->get( 'vp_ambassador_id' );
        }

        if ( ! empty( $ref_code ) && $ambassador_id && $ambassador_id !== $buyer_id ) {
            $this->record_conversion( (int) $ambassador_id, $buyer_id, $order_id, $ref_code );

            // پاک‌سازی Session
            if ( WC()->session ) {
                WC()->session->__unset( 'vp_referral_code' );
                WC()->session->__unset( 'vp_ambassador_id' );
            }
        }

        // ── بخش ۲: ساخت کد تخفیف برای این مشتری (تبدیلش به سفیر بالقوه) ───
        if ( $buyer_id ) {
            $this->get_or_create_coupon( $buyer_id );
        }
    }

    /**
     * ثبت دعوت موفق در دیتابیس
     */
    private function record_conversion( int $ambassador_id, int $buyer_id, int $order_id, string $code ): void {
        global $wpdb;

        $table = VP_Database::table( 'referrals' );

        // جلوگیری از ثبت تکراری
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE order_id = %d",
            $order_id
        ) );

        if ( $exists ) {
            return;
        }

        // بررسی تقلب قبل از ثبت
        $fraud_check = VP_Anti_Fraud::instance()->check( $ambassador_id, $buyer_id, $order_id );
        if ( $fraud_check['is_fraud'] ) {
            do_action( 'vp_fraud_detected', $ambassador_id, $buyer_id, $order_id, $fraud_check );
            return;
        }

        $wpdb->insert(
            $table,
            [
                'ambassador_id' => $ambassador_id,
                'referee_id'    => $buyer_id,
                'order_id'      => $order_id,
                'coupon_code'   => sanitize_text_field( $code ),
                'referral_url'  => $this->get_referral_url( $ambassador_id ),
                'status'        => 'converted',
                'converted_at'  => current_time( 'mysql' ),
            ],
            [ '%d', '%d', '%d', '%s', '%s', '%s', '%s' ]
        );

        $referral_id = (int) $wpdb->insert_id;

        // به‌روزرسانی تعداد دعوت‌های موفق سفیر
        $total = (int) get_user_meta( $ambassador_id, self::META_TOTAL_REFS, true );
        update_user_meta( $ambassador_id, self::META_TOTAL_REFS, $total + 1 );

        // به‌روزرسانی سطح سفیر
        $this->update_ambassador_level( $ambassador_id );

        // راه‌اندازی محاسبه کمیسیون
        do_action( 'vp_referral_converted', $referral_id, $ambassador_id, $order_id );
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  سطح‌بندی سفیر
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * به‌روزرسانی سطح بر اساس تعداد دعوت‌های موفق
     */
    public function update_ambassador_level( int $user_id ): string {
        $total = (int) get_user_meta( $user_id, self::META_TOTAL_REFS, true );
        $level = $this->calculate_level( $total );
        update_user_meta( $user_id, self::META_LEVEL, $level );
        do_action( 'vp_level_updated', $user_id, $level, $total );
        return $level;
    }

    /**
     * محاسبه سطح بر اساس تعداد دعوت
     */
    public function calculate_level( int $total_refs ): string {
        $levels = apply_filters( 'vp_level_thresholds', [
            'gold'   => 15,
            'silver' => 5,
        ] );

        if ( $total_refs >= $levels['gold'] ) {
            return 'gold';
        }
        if ( $total_refs >= $levels['silver'] ) {
            return 'silver';
        }
        return 'bronze';
    }

    /**
     * تنظیمات هر سطح (نرخ کمیسیون، سقف برداشت)
     */
    public function get_level_settings( string $level ): array {
        $defaults = [
            'bronze' => [ 'commission_rate' => 5,  'cashback_rate' => 0, 'monthly_limit' => 500000 ],
            'silver' => [ 'commission_rate' => 7,  'cashback_rate' => 2, 'monthly_limit' => 2000000 ],
            'gold'   => [ 'commission_rate' => 10, 'cashback_rate' => 5, 'monthly_limit' => 0 ],
        ];

        return apply_filters(
            'vp_level_settings',
            $defaults[ $level ] ?? $defaults['bronze'],
            $level
        );
    }

    /**
     * دریافت سطح فعلی کاربر
     */
    public function get_user_level( int $user_id ): string {
        return get_user_meta( $user_id, self::META_LEVEL, true ) ?: 'bronze';
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  صفحه تشکر
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * نمایش باکس اطلاعات سفیر در صفحه تشکر
     */
    public function render_thankyou_box( int $order_id ): void {
        $order   = wc_get_order( $order_id );
        $user_id = (int) $order?->get_customer_id();

        if ( ! $user_id ) {
            return;
        }

        $coupon_code  = $this->get_or_create_coupon( $user_id );
        $referral_url = $this->get_referral_url( $user_id );
        $level        = $this->get_user_level( $user_id );
        $total_refs   = (int) get_user_meta( $user_id, self::META_TOTAL_REFS, true );
        $settings     = $this->get_level_settings( $level );

        // مرحله بعدی
        $thresholds  = apply_filters( 'vp_level_thresholds', [ 'gold' => 15, 'silver' => 5 ] );
        $next_target = $level === 'bronze' ? $thresholds['silver'] : ( $level === 'silver' ? $thresholds['gold'] : null );

        include VP_DIR . 'admin/views/thankyou-box.php';
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  متدهای کمکی
    // ─────────────────────────────────────────────────────────────────────────

    /** پیدا کردن سفیر از روی کد تخفیف */
    public function get_ambassador_by_coupon( string $code ): int {
        $coupon = new WC_Coupon( strtolower( $code ) );
        if ( ! $coupon->get_id() ) {
            return 0;
        }
        return (int) $coupon->get_meta( '_vp_ambassador_id' );
    }

    /** افزایش شمارنده کلیک */
    private function increment_click_count( string $code ): void {
        global $wpdb;
        $table = VP_Database::table( 'referrals' );
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET click_count = click_count + 1 WHERE coupon_code = %s",
            $code
        ) );
    }
}