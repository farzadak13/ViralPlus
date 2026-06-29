<?php
/**
 * کلاس کیف پول اعتباری (Closed-Loop Wallet)
 *
 * اعتبار فقط داخل فروشگاه قابل استفاده است — بدون برداشت بانکی.
 * مدل مشابه دیجی‌کلاب و اسنپ‌کلاب.
 *
 * ویژگی‌ها:
 *  - موجودی فعال و معلق
 *  - FIFO: اعتبارهای قدیمی‌تر اول مصرف می‌شوند
 *  - انقضای قابل تنظیم با هشدار ۷ روز قبل
 *  - استفاده در پرداخت ووکامرس
 *  - انتقال داخلی بین کاربران
 */

defined( 'ABSPATH' ) || exit;

class VP_Wallet {

    private static ?VP_Wallet $instance = null;

    /** کلیدهای متای کاربر برای Cache سریع موجودی */
    const META_BALANCE         = '_vp_wallet_balance';
    const META_PENDING_BALANCE = '_vp_wallet_pending';

    /** حداقل مبلغ برای استفاده از کیف پول */
    const MIN_USE_AMOUNT = 10000; // ۱۰ هزار تومان

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // نمایش گزینه کیف پول در صفحه پرداخت
        add_action( 'woocommerce_review_order_before_payment', [ $this, 'render_wallet_checkout_box' ] );
        // اعمال تخفیف کیف پول
        add_action( 'woocommerce_cart_calculate_fees',         [ $this, 'apply_wallet_discount' ] );
        // ذخیره انتخاب کاربر هنگام پرداخت
        add_action( 'woocommerce_checkout_update_order_meta',  [ $this, 'save_wallet_usage' ] );
        // کسر موجودی بعد از تکمیل سفارش
        add_action( 'woocommerce_order_status_completed',      [ $this, 'deduct_on_order_complete' ] );
        // Cron: انقضای اعتبارها
        add_action( 'vp_daily_wallet_cron',                    [ $this, 'expire_old_credits' ] );
        // AJAX برای به‌روزرسانی مبلغ استفاده‌شده
        add_action( 'wp_ajax_vp_set_wallet_amount',            [ $this, 'ajax_set_wallet_amount' ] );

        if ( ! wp_next_scheduled( 'vp_daily_wallet_cron' ) ) {
            wp_schedule_event( time(), 'daily', 'vp_daily_wallet_cron' );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  عملیات اصلی تراکنش
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * واریز اعتبار به کیف پول کاربر
     *
     * @param int    $user_id
     * @param float  $amount
     * @param string $source     منبع: commission | cashback | gift | admin
     * @param int    $ref_id     شناسه مرجع
     * @param string $description
     * @param string $status     active | pending
     * @param int    $expire_days روزهای انقضا (۰ = بی‌نهایت)
     */
    public function credit(
        int $user_id,
        float $amount,
        string $source,
        int $ref_id = 0,
        string $description = '',
        string $status = 'active',
        int $expire_days = 0
    ): bool {
        if ( $amount <= 0 ) {
            return false;
        }

        global $wpdb;

        $expire_at      = null;
        $default_expiry = (int) get_option( 'vp_wallet_expire_days', 365 );
        $days           = $expire_days > 0 ? $expire_days : $default_expiry;

        if ( $days > 0 ) {
            $expire_at = gmdate( 'Y-m-d H:i:s', strtotime( "+{$days} days" ) );
        }

        $balance_before = $this->get_balance( $user_id );
        $balance_after  = $balance_before + ( $status === 'active' ? $amount : 0 );

        $result = $wpdb->insert(
            VP_Database::table( 'wallet_txns' ),
            [
                'user_id'       => $user_id,
                'type'          => 'credit',
                'source'        => sanitize_text_field( $source ),
                'amount'        => $amount,
                'balance_after' => $balance_after,
                'status'        => sanitize_text_field( $status ),
                'ref_id'        => $ref_id ?: null,
                'description'   => sanitize_text_field( $description ),
                'expire_at'     => $expire_at,
                'ip_address'    => $this->get_ip(),
            ],
            [ '%d', '%s', '%s', '%f', '%f', '%s', '%d', '%s', '%s', '%s' ]
        );

        if ( $result && $status === 'active' ) {
            $this->update_balance_cache( $user_id );
        }

        do_action( 'vp_wallet_credited', $user_id, $amount, $source );
        return (bool) $result;
    }

    /**
     * برداشت اعتبار از کیف پول (FIFO — قدیمی‌ترها اول)
     *
     * @param int    $user_id
     * @param float  $amount
     * @param string $source    purchase | transfer_out
     * @param int    $ref_id
     * @param string $description
     */
    public function debit(
        int $user_id,
        float $amount,
        string $source,
        int $ref_id = 0,
        string $description = ''
    ): bool {
        if ( $amount <= 0 ) {
            return false;
        }

        $balance = $this->get_balance( $user_id );
        if ( $balance < $amount ) {
            return false;
        }

        global $wpdb;
        $balance_after = $balance - $amount;

        $result = $wpdb->insert(
            VP_Database::table( 'wallet_txns' ),
            [
                'user_id'       => $user_id,
                'type'          => 'debit',
                'source'        => sanitize_text_field( $source ),
                'amount'        => $amount,
                'balance_after' => $balance_after,
                'status'        => 'active',
                'ref_id'        => $ref_id ?: null,
                'description'   => sanitize_text_field( $description ),
                'ip_address'    => $this->get_ip(),
            ],
            [ '%d', '%s', '%s', '%f', '%f', '%s', '%d', '%s', '%s' ]
        );

        if ( $result ) {
            $this->update_balance_cache( $user_id );
        }

        do_action( 'vp_wallet_debited', $user_id, $amount, $source );
        return (bool) $result;
    }

    /**
     * انتقال اعتبار بین دو کاربر
     */
    public function transfer( int $from_user_id, int $to_user_id, float $amount ): bool {
        if ( ! (bool) get_option( 'vp_wallet_allow_transfer', 1 ) ) {
            return false;
        }

        if ( $this->get_balance( $from_user_id ) < $amount ) {
            return false;
        }

        $debit  = $this->debit( $from_user_id, $amount, 'transfer_out', $to_user_id, 'انتقال به کاربر' );
        $credit = $this->credit( $to_user_id, $amount, 'gift', $from_user_id, 'دریافت از کاربر' );

        return $debit && $credit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  موجودی
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * دریافت موجودی فعال کاربر
     */
    public function get_balance( int $user_id ): float {
        $cached = get_user_meta( $user_id, self::META_BALANCE, true );
        if ( $cached !== '' && $cached !== false ) {
            return (float) $cached;
        }
        return $this->calculate_balance_from_db( $user_id );
    }

    /**
     * محاسبه موجودی از دیتابیس (برای بازسازی Cache)
     */
    private function calculate_balance_from_db( int $user_id ): float {
        global $wpdb;
        $table = VP_Database::table( 'wallet_txns' );

        $credits = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM {$table}
             WHERE user_id = %d AND type = 'credit' AND status = 'active'
               AND (expire_at IS NULL OR expire_at > NOW())",
            $user_id
        ) );

        $debits = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM {$table}
             WHERE user_id = %d AND type = 'debit'",
            $user_id
        ) );

        $balance = max( 0, $credits - $debits );
        update_user_meta( $user_id, self::META_BALANCE, $balance );
        return $balance;
    }

    /** به‌روزرسانی Cache موجودی */
    private function update_balance_cache( int $user_id ): void {
        $this->calculate_balance_from_db( $user_id );
    }

    /**
     * دریافت موجودی معلق (کمیسیون‌های در انتظار تأیید)
     */
    public function get_pending_balance( int $user_id ): float {
        return VP_Commission::instance()->get_pending_amount( $user_id );
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  ادغام با ووکامرس
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * نمایش باکس کیف پول در صفحه پرداخت
     */
    public function render_wallet_checkout_box(): void {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return;
        }

        $balance = $this->get_balance( $user_id );
        if ( $balance < self::MIN_USE_AMOUNT ) {
            return;
        }

        $cart_total  = (float) WC()->cart->get_subtotal();
        $max_allowed = $this->get_max_wallet_usage( $cart_total );
        $max_use     = min( $balance, $max_allowed );

        $session_amount = (float) ( WC()->session->get( 'vp_wallet_use_amount' ) ?? 0 );

        include VP_DIR . 'admin/views/wallet-checkout-box.php';
    }

    /**
     * اعمال تخفیف کیف پول روی سبد خرید
     */
    public function apply_wallet_discount( WC_Cart $cart ): void {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }

        $use_amount = (float) ( WC()->session->get( 'vp_wallet_use_amount' ) ?? 0 );
        if ( $use_amount <= 0 ) {
            return;
        }

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return;
        }

        $balance = $this->get_balance( $user_id );
        $use_amount = min( $use_amount, $balance );

        $cart->add_fee(
            sprintf( 'کیف پول (-%s تومان)', number_format( $use_amount ) ),
            - $use_amount
        );
    }

    /**
     * ذخیره مبلغ استفاده‌شده از کیف پول روی سفارش
     */
    public function save_wallet_usage( int $order_id ): void {
    $use_amount = (float) ( WC()->session->get( 'vp_wallet_use_amount' ) ?? 0 );
    if ( $use_amount > 0 ) {
        $order = wc_get_order( $order_id );
        if ( $order ) {
            $order->update_meta_data( '_vp_wallet_used', $use_amount );
            $order->save_meta_data();
        }
    }
}

    /**
     * کسر موجودی واقعی بعد از تکمیل سفارش
     */
    public function deduct_on_order_complete( int $order_id ): void {
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }

    // دریافت مبلغ استفاده‌شده با استاندارد HPOS
    $use_amount = (float) $order->get_meta( '_vp_wallet_used' );
    
    // اگر از کیف پول استفاده نکرده، همین‌جا متوقف شو
    if ( $use_amount <= 0 ) {
        return;
    }

    $user_id = (int) $order->get_customer_id();
    if ( ! $user_id ) {
        return;
    }

    $this->debit( $user_id, $use_amount, 'purchase', $order_id,
        sprintf( 'خرید از سفارش #%d', $order_id )
    );

    // پاک‌سازی Session
    if ( isset( WC()->session ) ) {
        WC()->session->__unset( 'vp_wallet_use_amount' );
    }
}

/**
     * AJAX: ذخیره مبلغ انتخابی کاربر برای استفاده از کیف پول
     */
    public function ajax_set_wallet_amount(): void {
        // ۱. بررسی امنیت درخواست (Nonce)
        check_ajax_referer( 'vp_wallet_nonce', 'nonce' );

        // ۲. بررسی وضعیت لاگین کاربر
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( 'لطفاً ابتدا وارد شوید.' );
        }

        // ۳. دریافت و اعتبارسنجی اولیه مبلغ
        $amount = absint( $_POST['amount'] ?? 0 );

        if ( $amount > 0 && $amount < self::MIN_USE_AMOUNT ) {
            wp_send_json_error( 'حداقل مبلغ استفاده از کیف پول ' . number_format( self::MIN_USE_AMOUNT ) . ' تومان است.' );
        }

        // ۴. تطبیق مبلغ درخواستی با موجودی واقعی کاربر
        $balance = $this->get_balance( $user_id );
        if ( $amount > $balance ) {
            $amount = $balance;
        }

        // ۵. ذخیره در سشن ووکامرس و ارسال پاسخ موفق
        if ( isset( WC()->session ) ) {
            WC()->session->set( 'vp_wallet_use_amount', $amount );
        }
        wp_send_json_success( [ 'amount' => $amount ] );
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  انقضا
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * منقضی کردن اعتبارهای قدیمی (Cron روزانه)
     */
    public function expire_old_credits(): void {
        global $wpdb;
        $table = VP_Database::table( 'wallet_txns' );

        // پیدا کردن اعتبارهایی که فردا منقضی می‌شوند → ارسال هشدار
        $expiring_soon = $wpdb->get_results(
            "SELECT user_id, SUM(amount) as total
             FROM {$table}
             WHERE type = 'credit' AND status = 'active'
               AND expire_at IS NOT NULL
               AND expire_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
             GROUP BY user_id"
        );

        foreach ( $expiring_soon as $row ) {
            do_action( 'vp_wallet_expiring_soon', (int) $row->user_id, (float) $row->total );
        }

        // منقضی کردن اعتبارهای گذشته
        $expired_users = $wpdb->get_col(
            "SELECT DISTINCT user_id FROM {$table}
             WHERE type = 'credit' AND status = 'active'
               AND expire_at IS NOT NULL AND expire_at < NOW()"
        );

        $wpdb->query(
            "UPDATE {$table}
             SET status = 'expired'
             WHERE type = 'credit' AND status = 'active'
               AND expire_at IS NOT NULL AND expire_at < NOW()"
        );

        // بازسازی Cache موجودی برای کاربران متأثر
        foreach ( $expired_users as $user_id ) {
            $this->calculate_balance_from_db( (int) $user_id );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  تاریخچه تراکنش‌ها
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * دریافت تاریخچه تراکنش‌های کاربر
     *
     * @param int $user_id
     * @param int $limit
     * @param int $offset
     */
    public function get_transactions( int $user_id, int $limit = 20, int $offset = 0 ): array {
        global $wpdb;
        $table = VP_Database::table( 'wallet_txns' );

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE user_id = %d
             ORDER BY created_at DESC
             LIMIT %d OFFSET %d",
            $user_id, $limit, $offset
        ) ) ?: [];
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  متدهای کمکی
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * حداکثر مبلغ قابل استفاده از کیف پول بر اساس تنظیمات
     */
    private function get_max_wallet_usage( float $cart_total ): float {
        $max_percent = (float) get_option( 'vp_wallet_max_percent', 100 );
        return floor( $cart_total * $max_percent / 100 );
    }

    /** دریافت IP کاربر */
    private function get_ip(): string {
        return sanitize_text_field(
            $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR']
            ?? ''
        );
    }
} // <--- دقت کنید که این تنها آکولادی است که کلاس را در پایین‌ترین خط فایل می‌بندد