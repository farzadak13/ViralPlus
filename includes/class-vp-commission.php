<?php
/**
 * کلاس کمیسیون معلق
 *
 * منطق کار:
 *  ۱. بعد از تبدیل دعوت → کمیسیون محاسبه و در حالت 'pending' ذخیره می‌شود
 *  ۲. Cron روزانه → کمیسیون‌هایی که hold_until گذشته → تبدیل به 'approved' و واریز به کیف پول
 *  ۳. مرجوعی سفارش → کمیسیون مربوطه 'cancelled' می‌شود
 */

defined( 'ABSPATH' ) || exit;

class VP_Commission {

    private static ?VP_Commission $instance = null;

    /** مدت زمان معلق بودن کمیسیون (روز) — قابل تنظیم از پنل */
    const DEFAULT_HOLD_DAYS = 15;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // بعد از تبدیل دعوت → محاسبه کمیسیون
        add_action( 'vp_referral_converted',        [ $this, 'calculate_commission' ], 10, 3 );

        // مرجوعی → لغو کمیسیون
        add_action( 'woocommerce_order_status_refunded',   [ $this, 'cancel_commission_by_order' ] );
        add_action( 'woocommerce_order_status_cancelled',  [ $this, 'cancel_commission_by_order' ] );

        // Cron روزانه برای تأیید کمیسیون‌های بالغ‌شده
        add_action( 'vp_daily_commission_cron',     [ $this, 'approve_matured_commissions' ] );

        if ( ! wp_next_scheduled( 'vp_daily_commission_cron' ) ) {
            wp_schedule_event( time(), 'daily', 'vp_daily_commission_cron' );
        }
    }

    /**
     * محاسبه کمیسیون بعد از دعوت موفق
     *
     * @param int $referral_id  شناسه ردیف در جدول referrals
     * @param int $ambassador_id
     * @param int $order_id
     */
    public function calculate_commission( int $referral_id, int $ambassador_id, int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $referral_instance = VP_Referral::instance();
        $level             = $referral_instance->get_user_level( $ambassador_id );
        $settings          = $referral_instance->get_level_settings( $level );
        $rate              = (float) $settings['commission_rate'];

        $order_total = (float) $order->get_total() - (float) $order->get_shipping_total() - (float) $order->get_total_tax();
        $order_total = max( 0, $order_total );
        $amount      = round( $order_total * $rate / 100, 2 );

        // بررسی سقف هوشمند کمیسیون
        $amount = $this->apply_commission_cap( $amount, $ambassador_id, $order_total );

        if ( $amount <= 0 ) {
            return;
        }

        $hold_days  = (int) get_option( 'vp_commission_hold_days', self::DEFAULT_HOLD_DAYS );
        $hold_until = gmdate( 'Y-m-d H:i:s', strtotime( "+{$hold_days} days" ) );

        global $wpdb;
        $wpdb->insert(
            VP_Database::table( 'commissions' ),
            [
                'referral_id'   => $referral_id,
                'ambassador_id' => $ambassador_id,
                'order_id'      => $order_id,
                'order_total'   => $order_total,
                'rate'          => $rate,
                'amount'        => $amount,
                'status'        => 'pending',
                'hold_until'    => $hold_until,
                'note'          => sprintf( 'سطح %s — نرخ %s%%', $level, $rate ),
            ],
            [ '%d', '%d', '%d', '%f', '%f', '%f', '%s', '%s', '%s' ]
        );

        do_action( 'vp_commission_created', (int) $wpdb->insert_id, $ambassador_id, $amount );
    }

    /**
     * تأیید و واریز کمیسیون‌هایی که دوره معلق‌شان تمام شده
     * این متد توسط Cron روزانه فراخوانی می‌شود
     */
    public function approve_matured_commissions(): void {
        global $wpdb;
        $table = VP_Database::table( 'commissions' );

        $matured = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE status = 'pending'
               AND hold_until <= %s
             LIMIT 100",
            current_time( 'mysql' )
        ) );

        foreach ( $matured as $commission ) {
            $this->approve_commission( (int) $commission->id );
        }
    }

    /**
     * تأیید یک کمیسیون و واریز به کیف پول
     */
    public function approve_commission( int $commission_id ): void {
        global $wpdb;
        $table = VP_Database::table( 'commissions' );

        $commission = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND status = 'pending'",
            $commission_id
        ) );

        if ( ! $commission ) {
            return;
        }

        // واریز به کیف پول
        VP_Wallet::instance()->credit(
            (int) $commission->ambassador_id,
            (float) $commission->amount,
            'commission',
            $commission_id,
            sprintf( 'کمیسیون سفارش #%d', $commission->order_id )
        );

        // به‌روزرسانی وضعیت
        $wpdb->update(
            $table,
            [ 'status' => 'approved', 'approved_at' => current_time( 'mysql' ) ],
            [ 'id' => $commission_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        do_action( 'vp_commission_approved', $commission_id, (int) $commission->ambassador_id );
    }

    /**
     * لغو کمیسیون در صورت مرجوعی سفارش
     */
    public function cancel_commission_by_order( int $order_id ): void {
        global $wpdb;
        $table = VP_Database::table( 'commissions' );

        $commission = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE order_id = %d AND status = 'pending'",
            $order_id
        ) );

        if ( ! $commission ) {
            return;
        }

        $wpdb->update(
            $table,
            [ 'status' => 'cancelled', 'note' => 'مرجوعی سفارش' ],
            [ 'id' => (int) $commission->id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        do_action( 'vp_commission_cancelled', (int) $commission->id, $order_id );
    }

    /**
     * اعمال سقف کمیسیون ماهانه برای هر سفیر
     *
     * @param float $amount       مبلغ محاسبه‌شده
     * @param int   $ambassador_id
     * @param float $order_total
     * @return float  مبلغ پس از اعمال سقف
     */
    private function apply_commission_cap( float $amount, int $ambassador_id, float $order_total ): float {
        $level    = VP_Referral::instance()->get_user_level( $ambassador_id );
        $settings = VP_Referral::instance()->get_level_settings( $level );
        $cap      = (float) $settings['monthly_limit']; // صفر = نامحدود

        if ( $cap <= 0 ) {
            return $amount;
        }

        // جمع کمیسیون‌های تأییدشده و معلق در ماه جاری
        global $wpdb;
        $table = VP_Database::table( 'commissions' );

        $month_total = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM {$table}
             WHERE ambassador_id = %d
               AND status IN ('pending','approved')
               AND YEAR(created_at)  = YEAR(NOW())
               AND MONTH(created_at) = MONTH(NOW())",
            $ambassador_id
        ) );

        $remaining = $cap - $month_total;
        if ( $remaining <= 0 ) {
            return 0;
        }

        return min( $amount, $remaining );
    }

    /**
     * دریافت جمع کمیسیون‌های معلق یک سفیر
     */
    public function get_pending_amount( int $ambassador_id ): float {
        global $wpdb;
        $table = VP_Database::table( 'commissions' );

        return (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM {$table}
             WHERE ambassador_id = %d AND status = 'pending'",
            $ambassador_id
        ) );
    }
}