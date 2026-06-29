<?php
/**
 * کلاس گیمیفیکیشن — جعبه‌شانس (گردونه‌ی شانس)
 *
 * مسئولیت‌ها:
 *  - فعال/غیرفعال‌سازی کامل ماژول توسط مدیر فروشگاه (هر فروشگاه می‌تواند خودش انتخاب کند)
 *  - تعریف جوایز قابل‌تنظیم از پنل مدیریت (بدون نیاز به ویرایش کد)
 *  - اعطای «چرخش رایگان» بر اساس رویدادهای موجود افزونه (دعوت موفق / تکمیل سفارش)
 *  - چرخش امن سمت سرور (تصمیم‌گیری برنده‌شدن همیشه در PHP انجام می‌شود، نه جاوااسکریپت)
 *  - واریز خودکار جایزه‌ی کیف‌پولی از طریق VP_Wallet موجود (بدون منطق پرداخت جدید)
 *
 * این ماژول کاملاً اختیاری است: اگر vp_gamification_enabled صفر باشد، هیچ هوکی
 * اثر عملی ندارد و هیچ‌چیزی به مشتری نمایش داده نمی‌شود.
 */

defined( 'ABSPATH' ) || exit;

class VP_Gamification {

    private static ?VP_Gamification $instance = null;

    const OPTION_ENABLED        = 'vp_gamification_enabled';        // 0|1 — کلید اصلی فعال/غیرفعال (هر فروشگاه)
    const OPTION_PRIZES         = 'vp_gamification_prizes';         // آرایه‌ی تعریف جوایز
    const OPTION_EARN_REFERRAL  = 'vp_gamification_earn_referral';  // 0|1 — آیا دعوت موفق چرخش رایگان می‌دهد
    const OPTION_EARN_ORDER     = 'vp_gamification_earn_order';     // 0|1 — آیا هر خرید چرخش رایگان می‌دهد
    const OPTION_MAX_SPINS_DAY  = 'vp_gamification_max_spins_day';  // سقف روزانه‌ی چرخش هر کاربر (ضد سوءاستفاده)

    const META_AVAILABLE_SPINS = '_vp_available_spins'; // تعداد چرخش‌های ذخیره‌شده‌ی هر کاربر

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if ( ! $this->is_enabled() ) {
            return; // غیرفعال در این فروشگاه → هیچ هوکی ثبت نمی‌شود
        }

        // اعطای چرخش رایگان بر اساس رویدادهای موجود افزونه
        add_action( 'vp_referral_converted',               [ $this, 'on_referral_converted' ], 10, 3 );
        add_action( 'woocommerce_order_status_completed',   [ $this, 'on_order_completed' ], 20, 1 );

        // نمایش ویجت جعبه‌شانس در صفحه‌ی تشکر (در صورت داشتن چرخش موجود)
        add_action( 'woocommerce_thankyou', [ $this, 'render_spin_widget' ], 20 );

        // شورت‌کد برای نمایش جعبه‌شانس در هر صفحه‌ی دیگر (مثلاً صفحه‌ی حساب کاربری)
        add_shortcode( 'viralplus_spin_box', [ $this, 'render_spin_shortcode' ] );

        // AJAX چرخش — تنها مسیر واقعی برنده‌شدن
        add_action( 'wp_ajax_vp_do_spin', [ $this, 'ajax_do_spin' ] );
    }

    public function is_enabled(): bool {
        return (bool) get_option( self::OPTION_ENABLED, 0 );
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  تعریف جوایز
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * جوایز پیش‌فرض — مدیر می‌تواند از پنل تنظیمات این لیست را کامل تغییر دهد.
     * هر جایزه: کلید، برچسب نمایشی، نوع (wallet_fixed | wallet_percent | none)،
     * مقدار، و وزن احتمال (weight — عددی نسبی، نه درصد دقیق؛ جمع وزن‌ها مهم نیست،
     * نسبت هرکدام به کل تعیین‌کننده‌ی شانس برد است).
     */
    public function get_prizes(): array {
        $default = [
            [ 'key' => 'none',       'label' => 'بدون جایزه — شانس بعدی! 🍀', 'type' => 'none',           'amount' => 0,  'weight' => 40 ],
            [ 'key' => 'small_cash', 'label' => '۵٬۰۰۰ تومان کیف پول',         'type' => 'wallet_fixed',   'amount' => 5000,  'weight' => 30 ],
            [ 'key' => 'mid_cash',   'label' => '۲۰٬۰۰۰ تومان کیف پول',        'type' => 'wallet_fixed',   'amount' => 20000, 'weight' => 15 ],
            [ 'key' => 'percent_5',  'label' => '۵٪ اعتبار از خریدهای بعدی',    'type' => 'wallet_percent', 'amount' => 5,  'weight' => 10 ],
            [ 'key' => 'jackpot',    'label' => '۱۰۰٬۰۰۰ تومان جکپات! 🎉',      'type' => 'wallet_fixed',   'amount' => 100000, 'weight' => 5 ],
        ];

        $saved = get_option( self::OPTION_PRIZES, [] );
        $prizes = ! empty( $saved ) ? $saved : $default;

        return apply_filters( 'vp_gamification_prizes', $prizes );
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  اعطای چرخش رایگان (Earning Rules)
    // ─────────────────────────────────────────────────────────────────────────

    /** بعد از دعوت موفق سفیر → یک چرخش رایگان به سفیر بده */
    public function on_referral_converted( int $referral_id, int $ambassador_id, int $order_id ): void {
        if ( ! (bool) get_option( self::OPTION_EARN_REFERRAL, 1 ) ) {
            return;
        }
        $this->grant_spin( $ambassador_id, 'referral_converted', $referral_id );
    }

    /** بعد از هر خرید تکمیل‌شده → یک چرخش رایگان به خریدار بده (اختیاری، پیش‌فرض خاموش) */
    public function on_order_completed( int $order_id ): void {
        if ( ! (bool) get_option( self::OPTION_EARN_ORDER, 0 ) ) {
            return;
        }
        $order = wc_get_order( $order_id );
        $buyer_id = $order ? (int) $order->get_customer_id() : 0;
        if ( $buyer_id ) {
            $this->grant_spin( $buyer_id, 'order_completed', $order_id );
        }
    }

    /**
     * اعطای N چرخش رایگان به کاربر (در متای کاربر ذخیره می‌شود، نه دیتابیس spins —
     * جدول spins فقط تاریخچه‌ی چرخش‌های *انجام‌شده* است، نه چرخش‌های در انتظار)
     */
    public function grant_spin( int $user_id, string $reason = 'manual', int $ref_id = 0, int $count = 1 ): void {
        $current = (int) get_user_meta( $user_id, self::META_AVAILABLE_SPINS, true );
        update_user_meta( $user_id, self::META_AVAILABLE_SPINS, $current + $count );

        do_action( 'vp_gamification_spin_granted', $user_id, $reason, $ref_id, $count );
    }

    public function get_available_spins( int $user_id ): int {
        return (int) get_user_meta( $user_id, self::META_AVAILABLE_SPINS, true );
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  چرخش امن سمت سرور
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * انتخاب یک جایزه بر اساس وزن احتمال (weighted random) — این تابع خالص
     * است (بدون side effect) تا قابل تست باشد.
     */
    public function pick_prize( array $prizes ): array {
        $total_weight = array_sum( array_column( $prizes, 'weight' ) );
        if ( $total_weight <= 0 ) {
            return $prizes[0] ?? [ 'key' => 'none', 'label' => 'بدون جایزه', 'type' => 'none', 'amount' => 0, 'weight' => 1 ];
        }

        $rand_point = wp_rand( 1, (int) $total_weight );
        $cumulative = 0;

        foreach ( $prizes as $prize ) {
            $cumulative += (int) $prize['weight'];
            if ( $rand_point <= $cumulative ) {
                return $prize;
            }
        }

        return end( $prizes );
    }

    /**
     * اجرای واقعی یک چرخش برای کاربر: کسر از موجودی چرخش، انتخاب جایزه،
     * واریز جایزه (اگر کیف‌پولی باشد) و ثبت در تاریخچه.
     *
     * @return array{ok:bool, message?:string, prize?:array}
     */
    public function perform_spin( int $user_id, string $trigger_type = 'manual', int $trigger_ref_id = 0 ): array {
        $available = $this->get_available_spins( $user_id );
        if ( $available <= 0 ) {
            return [ 'ok' => false, 'message' => 'چرخش رایگان موجود نیست.' ];
        }

        // سقف روزانه برای جلوگیری از سوءاستفاده در صورت باگ در منطق اعطای چرخش
        $max_per_day = (int) get_option( self::OPTION_MAX_SPINS_DAY, 10 );
        if ( $max_per_day > 0 && $this->count_spins_today( $user_id ) >= $max_per_day ) {
            return [ 'ok' => false, 'message' => 'سقف چرخش امروز شما تمام شده است. فردا دوباره تلاش کنید.' ];
        }

        $prizes = $this->get_prizes();
        $prize  = $this->pick_prize( $prizes );

        // کسر یک چرخش از موجودی — قبل از واریز جایزه، تا در صورت خطای همزمانی
        // حداقل موجودی چرخش درست بماند (واریز جایزه idempotent نیست اما نادر/کم‌ریسک است)
        update_user_meta( $user_id, self::META_AVAILABLE_SPINS, $available - 1 );

        $wallet_txn_id = null;
        if ( in_array( $prize['type'], [ 'wallet_fixed', 'wallet_percent' ], true ) && (float) $prize['amount'] > 0 ) {
            $amount = $this->resolve_prize_amount( $prize, $user_id );
            if ( $amount > 0 ) {
                VP_Wallet::instance()->credit(
                    $user_id,
                    $amount,
                    'spin_reward',
                    $trigger_ref_id,
                    'جایزه‌ی جعبه‌شانس: ' . $prize['label']
                );
                $wallet_txn_id = $this->get_last_wallet_txn_id( $user_id );
            }
        }

        $this->record_spin_history( $user_id, $trigger_type, $trigger_ref_id, $prize, $wallet_txn_id );

        do_action( 'vp_gamification_spin_performed', $user_id, $prize );

        return [ 'ok' => true, 'prize' => $prize ];
    }

    /** محاسبه‌ی مقدار واقعی جایزه — برای نوع درصدی، بر اساس میانگین خرید کاربر تخمین می‌زند */
    private function resolve_prize_amount( array $prize, int $user_id ): float {
        if ( $prize['type'] === 'wallet_fixed' ) {
            return (float) $prize['amount'];
        }

        if ( $prize['type'] === 'wallet_percent' ) {
            // برای جایزه‌ی درصدی، مبنا را آخرین سفارش کاربر در نظر می‌گیریم (ساده و قابل‌فهم برای کاربر)
            $orders = wc_get_orders( [ 'customer_id' => $user_id, 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC' ] );
            $base   = ! empty( $orders ) ? (float) $orders[0]->get_total() : 0;
            return round( $base * ( (float) $prize['amount'] / 100 ) );
        }

        return 0;
    }

    private function get_last_wallet_txn_id( int $user_id ): ?int {
        global $wpdb;
        $table = VP_Database::table( 'wallet_txns' );
        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE user_id = %d ORDER BY id DESC LIMIT 1",
            $user_id
        ) );
        return $id ? (int) $id : null;
    }

    private function record_spin_history( int $user_id, string $trigger_type, int $trigger_ref_id, array $prize, ?int $wallet_txn_id ): void {
        global $wpdb;
        $wpdb->insert(
            VP_Database::table( 'spins' ),
            [
                'user_id'        => $user_id,
                'trigger_type'   => sanitize_text_field( $trigger_type ),
                'trigger_ref_id' => $trigger_ref_id ?: null,
                'prize_key'      => sanitize_text_field( $prize['key'] ),
                'prize_label'    => sanitize_text_field( $prize['label'] ),
                'prize_amount'   => $this->resolve_prize_amount( $prize, $user_id ),
                'wallet_txn_id'  => $wallet_txn_id,
                'ip_address'     => $this->get_ip(),
            ],
            [ '%d', '%s', '%d', '%s', '%s', '%f', '%d', '%s' ]
        );
    }

    private function count_spins_today( int $user_id ): int {
        global $wpdb;
        $table = VP_Database::table( 'spins' );
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND created_at >= %s",
            $user_id,
            gmdate( 'Y-m-d 00:00:00' )
        ) );
    }

    private function get_ip(): string {
        return sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' );
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  AJAX — تنها مسیر مجاز چرخش (هرگز سمت کلاینت تصمیم‌گیری نمی‌شود)
    // ─────────────────────────────────────────────────────────────────────────

    public function ajax_do_spin(): void {
        check_ajax_referer( 'vp_spin_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( 'لطفاً ابتدا وارد حساب کاربری خود شوید.' );
        }

        $result = $this->perform_spin( $user_id, 'manual' );

        if ( ! $result['ok'] ) {
            wp_send_json_error( $result['message'] );
        }

        wp_send_json_success( [
            'prize'           => $result['prize'],
            'remaining_spins' => $this->get_available_spins( $user_id ),
        ] );
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  نمایش فرانت‌اند
    // ─────────────────────────────────────────────────────────────────────────

    /** نمایش خودکار در صفحه‌ی تشکر، فقط اگر کاربر چرخش موجود داشته باشد */
    public function render_spin_widget( int $order_id ): void {
        $order   = wc_get_order( $order_id );
        $user_id = $order ? (int) $order->get_customer_id() : get_current_user_id();

        if ( ! $user_id || $this->get_available_spins( $user_id ) <= 0 ) {
            return;
        }

        $this->render_spin_box( $user_id );
    }

    /** شورت‌کد [viralplus_spin_box] برای استفاده در هر صفحه‌ی دیگر */
    public function render_spin_shortcode(): string {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return '';
        }

        ob_start();
        $this->render_spin_box( $user_id );
        return ob_get_clean();
    }

    private function render_spin_box( int $user_id ): void {
        $available = $this->get_available_spins( $user_id );
        $prizes    = $this->get_prizes();
        include VP_DIR . 'views/spin-box.php';
    }
}