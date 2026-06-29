<?php
/**
 * کلاس پنل مدیریتی ViralPlus
 *
 * منوها:
 *  - داشبورد: آمار کلی
 *  - سفیرها: لیست، سطح، فعالیت
 *  - کیف پول: شارژ دستی، تاریخچه
 *  - کمیسیون‌ها: تأیید/رد دستی
 *  - ضد تقلب: گزارش رفتارهای مشکوک
 *  - تنظیمات: همه پارامترهای قابل تنظیم
 */

defined( 'ABSPATH' ) || exit;

class VP_Admin {

    private static ?VP_Admin $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu',              [ $this, 'register_menus' ] );
        add_action( 'admin_enqueue_scripts',   [ $this, 'enqueue_assets' ] );
        add_action( 'admin_init',              [ $this, 'register_settings' ] );
        add_action( 'wp_ajax_vp_admin_action', [ $this, 'handle_admin_action' ] );
    }

    /**
     * ثبت منوهای مدیریتی
     */
    public function register_menus(): void {
        add_menu_page(
            'ViralPlus',
            'ViralPlus',
            'manage_woocommerce',
            'viralplus',
            [ $this, 'page_dashboard' ],
            'dashicons-share-alt2',
            56
        );

        $subpages = [
            [ 'viralplus',              'داشبورد',       'page_dashboard' ],
            [ 'viralplus-ambassadors',  'سفیرها',        'page_ambassadors' ],
            [ 'viralplus-wallet',       'کیف پول',       'page_wallet' ],
            [ 'viralplus-commissions',  'کمیسیون‌ها',    'page_commissions' ],
            [ 'viralplus-fraud',        'ضد تقلب',       'page_fraud' ],
            [ 'viralplus-gamification', 'جعبه‌شانس',     'page_gamification' ],
            [ 'viralplus-sms',          'پنل پیامکی',    'page_sms' ],
            [ 'viralplus-settings',     'تنظیمات',       'page_settings' ],
        ];

        foreach ( $subpages as [ $slug, $title, $callback ] ) {
            add_submenu_page(
                'viralplus',
                $title . ' — ViralPlus',
                $title,
                'manage_woocommerce',
                $slug,
                [ $this, $callback ]
            );
        }
    }

    /**
     * بارگذاری فایل‌های CSS/JS پنل
     */
    public function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'viralplus' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'viralplus-admin',
            VP_URL . 'assets/css/admin.css',
            [],
            VP_VERSION
        );

        wp_enqueue_script(
            'viralplus-admin',
            VP_URL . 'assets/js/admin.js',
            [ 'jquery' ],
            VP_VERSION,
            true
        );

        wp_localize_script( 'viralplus-admin', 'VP_Admin', [
            'nonce'   => wp_create_nonce( 'vp_admin_nonce' ),
            'ajax_url' => admin_url( 'admin-ajax.php' ),
        ] );
    }

    /**
     * ثبت تنظیمات قابل تنظیم
     */
    public function register_settings(): void {
        $settings = [
            'vp_commission_hold_days'       => 15,
            'vp_wallet_expire_days'         => 365,
            'vp_wallet_max_percent'         => 100,
            'vp_wallet_allow_transfer'      => 1,
            'vp_fraud_score_threshold'      => 50,
            'vp_fraud_rapid_invites'        => 3,
            'vp_fraud_rapid_window_hours'   => 1,
        ];

        foreach ( $settings as $key => $default ) {
            register_setting( 'viralplus_settings', $key, [
                'default'           => $default,
                'sanitize_callback' => 'absint',
            ] );
        }

        // ── تنظیمات گیمیفیکیشن (جعبه‌شانس) ──────────────────────────────────
        register_setting( 'viralplus_gamification', VP_Gamification::OPTION_ENABLED, [
            'default'           => 0,
            'sanitize_callback' => 'absint',
        ] );
        register_setting( 'viralplus_gamification', VP_Gamification::OPTION_EARN_REFERRAL, [
            'default'           => 1,
            'sanitize_callback' => 'absint',
        ] );
        register_setting( 'viralplus_gamification', VP_Gamification::OPTION_EARN_ORDER, [
            'default'           => 0,
            'sanitize_callback' => 'absint',
        ] );
        register_setting( 'viralplus_gamification', VP_Gamification::OPTION_MAX_SPINS_DAY, [
            'default'           => 10,
            'sanitize_callback' => 'absint',
        ] );
        register_setting( 'viralplus_gamification', VP_Gamification::OPTION_PRIZES, [
            'default'           => [],
            'sanitize_callback' => [ $this, 'sanitize_prizes' ],
        ] );

        // ── تنظیمات پنل پیامکی ──────────────────────────────────────────────
        register_setting( 'viralplus_sms', VP_SMS_Manager::OPTION_ENABLED, [
            'default'           => 0,
            'sanitize_callback' => 'absint',
        ] );
        register_setting( 'viralplus_sms', VP_SMS_Manager::OPTION_ACTIVE_GATEWAY, [
            'default'           => '',
            'sanitize_callback' => 'sanitize_key',
        ] );
        register_setting( 'viralplus_sms', VP_SMS_Manager::OPTION_GATEWAY_CONFIG, [
            'default'           => [],
            'sanitize_callback' => [ $this, 'sanitize_gateway_config' ],
        ] );
        register_setting( 'viralplus_sms', VP_SMS_Manager::OPTION_EVENTS, [
            'default'           => [],
            'sanitize_callback' => [ $this, 'sanitize_sms_events' ],
        ] );
        register_setting( 'viralplus_sms', 'vp_sms_admin_mobile', [
            'default'           => '',
            'sanitize_callback' => 'sanitize_text_field',
        ] );
    }

    /**
     * پاک‌سازی آرایه‌ی جوایز جعبه‌شانس قبل از ذخیره — هر ردیف باید فیلدهای
     * لازم را داشته باشد، در غیر این صورت نادیده گرفته می‌شود.
     */
    public function sanitize_prizes( $input ): array {
        if ( ! is_array( $input ) ) {
            return [];
        }

        $clean = [];
        foreach ( $input as $row ) {
            if ( empty( $row['key'] ) || empty( $row['label'] ) ) {
                continue;
            }
            $clean[] = [
                'key'    => sanitize_key( $row['key'] ),
                'label'  => sanitize_text_field( $row['label'] ),
                'type'   => in_array( $row['type'] ?? '', [ 'none', 'wallet_fixed', 'wallet_percent' ], true ) ? $row['type'] : 'none',
                'amount' => (float) ( $row['amount'] ?? 0 ),
                'weight' => max( 0, (int) ( $row['weight'] ?? 0 ) ),
            ];
        }

        return $clean;
    }

    /** پاک‌سازی تنظیمات هر گیت‌وی پیامکی (آرایه‌ای از کلید/مقدار، مثل api_key) */
    public function sanitize_gateway_config( $input ): array {
        if ( ! is_array( $input ) ) {
            return [];
        }

        $clean = [];
        foreach ( $input as $gateway_key => $fields ) {
            if ( ! is_array( $fields ) ) {
                continue;
            }
            $gateway_key = sanitize_key( $gateway_key );
            foreach ( $fields as $field_key => $value ) {
                $clean[ $gateway_key ][ sanitize_key( $field_key ) ] = sanitize_text_field( $value );
            }
        }

        return $clean;
    }

    /** پاک‌سازی فعال/غیرفعال هر رویداد پیامکی */
    public function sanitize_sms_events( $input ): array {
        if ( ! is_array( $input ) ) {
            return [];
        }
        $clean = [];
        foreach ( $input as $event_key => $value ) {
            $clean[ sanitize_key( $event_key ) ] = absint( $value );
        }
        return $clean;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  صفحات مدیریتی
    // ─────────────────────────────────────────────────────────────────────────

    public function page_dashboard(): void     { include VP_DIR . 'admin/views/dashboard.php'; }
    public function page_ambassadors(): void   { include VP_DIR . 'admin/views/ambassadors.php'; }
    public function page_wallet(): void        { include VP_DIR . 'admin/views/wallet.php'; }
    public function page_commissions(): void   { include VP_DIR . 'admin/views/commissions.php'; }
    public function page_fraud(): void         { include VP_DIR . 'admin/views/fraud.php'; }
    public function page_gamification(): void  { include VP_DIR . 'admin/views/gamification.php'; }
    public function page_sms(): void           { include VP_DIR . 'admin/views/sms.php'; }
    public function page_settings(): void      { include VP_DIR . 'admin/views/settings.php'; }

    // ─────────────────────────────────────────────────────────────────────────
    //  AJAX اقدامات مدیریتی
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * مدیریت اقدامات AJAX از پنل (تأیید، رد، بلاک)
     */
    public function handle_admin_action(): void {
        check_ajax_referer( 'vp_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'دسترسی غیرمجاز' );
        }

        $action  = sanitize_key( $_POST['vp_action'] ?? '' );
        $item_id = absint( $_POST['item_id'] ?? 0 );

        switch ( $action ) {
            case 'approve_commission':
                VP_Commission::instance()->approve_commission( $item_id );
                wp_send_json_success( 'کمیسیون تأیید شد.' );
                break;

            case 'cancel_commission':
                global $wpdb;
                $wpdb->update(
                    VP_Database::table( 'commissions' ),
                    [ 'status' => 'cancelled', 'note' => 'لغو دستی مدیر' ],
                    [ 'id' => $item_id ],
                    [ '%s', '%s' ],
                    [ '%d' ]
                );
                wp_send_json_success( 'کمیسیون لغو شد.' );
                break;

            case 'suspend_ambassador':
                update_user_meta( $item_id, VP_Referral::META_SUSPENDED, 1 );
                wp_send_json_success( 'سفیر معلق شد.' );
                break;

            case 'unsuspend_ambassador':
                delete_user_meta( $item_id, VP_Referral::META_SUSPENDED );
                wp_send_json_success( 'تعلیق رفع شد.' );
                break;

            case 'credit_wallet':
                $amount = absint( $_POST['amount'] ?? 0 );
                VP_Wallet::instance()->credit( $item_id, $amount, 'admin', 0, 'شارژ دستی مدیر' );
                wp_send_json_success( 'کیف پول شارژ شد.' );
                break;

            case 'test_sms':
                $mobile = sanitize_text_field( $_POST['mobile'] ?? '' );
                if ( empty( $mobile ) ) {
                    wp_send_json_error( 'شماره موبایل برای تست وارد نشده است.' );
                }
                $result = VP_SMS_Manager::instance()->send( $mobile, 'این یک پیامک تست از افزونه‌ی ViralPlus است.' );
                if ( $result->success ) {
                    wp_send_json_success( 'پیامک تست با موفقیت ارسال شد.' );
                } else {
                    wp_send_json_error( 'ارسال ناموفق: ' . $result->message );
                }
                break;

            default:
                wp_send_json_error( 'اقدام ناشناخته' );
        }
    }
}