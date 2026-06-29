<?php
/**
 * کلاس دیتابیس — ساخت و مدیریت جداول ViralPlus
 *
 * جداول:
 *  - vp_referrals       : اطلاعات هر دعوت (سفیر، خریدار، کد، وضعیت)
 *  - vp_commissions     : کمیسیون‌های محاسبه‌شده (معلق/تأیید/لغو)
 *  - vp_wallet_txns     : تراکنش‌های کیف پول هر کاربر
 *  - vp_fraud_log       : لاگ رفتارهای مشکوک
 *  - vp_spins           : تاریخچه‌ی چرخش جعبه‌شانس (گیمیفیکیشن)
 */

defined( 'ABSPATH' ) || exit;

class VP_Database {

    /** نسخه ساختار دیتابیس — برای migration های آینده */
    const DB_VERSION = '1.1.0';
    const OPTION_KEY = 'vp_db_version';

    /**
     * نصب — فقط اگر نسخه تغییر کرده باشد اجرا می‌شود (هنگام فعال‌سازی افزونه)
     */
    public static function install(): void {
        $installed = get_option( self::OPTION_KEY, '0.0.0' );

        if ( version_compare( $installed, self::DB_VERSION, '>=' ) ) {
            return;
        }

        self::create_tables();
        self::migrate_enum_columns();
        update_option( self::OPTION_KEY, self::DB_VERSION );
    }

    /**
     * بررسی نسخه روی هر بارگذاری افزونه — برای کاربرانی که فایل افزونه را آپدیت
     * کرده‌اند اما غیرفعال/فعال مجدد نکرده‌اند (در نتیجه register_activation_hook
     * صدا زده نشده و جداول جدید مثل vp_spins ساخته نشده‌اند).
     * dbDelta در create_tables() خودش idempotent است، پس صدازدن دوباره‌اش امن است.
     */
    public static function maybe_upgrade(): void {
        $installed = get_option( self::OPTION_KEY, '0.0.0' );

        if ( version_compare( $installed, self::DB_VERSION, '>=' ) ) {
            return;
        }

        self::create_tables();
        self::migrate_enum_columns();
        update_option( self::OPTION_KEY, self::DB_VERSION );
    }

    /**
     * dbDelta به‌صورت قابل‌اعتماد مقادیر ستون‌های ENUM موجود را تغییر نمی‌دهد
     * (فقط افزودن ستون/ایندکس جدید را خوب مدیریت می‌کند). برای نسخه‌ی ۱.۱.۰ که
     * مقادیر 'spin_reward' و 'challenge_reward' به ستون wallet_txns.source اضافه
     * شده، یک ALTER صریح و idempotent اجرا می‌کنیم تا روی نصب‌های قبلی هم تضمین‌شده اعمال شود.
     */
    private static function migrate_enum_columns(): void {
        global $wpdb;
        $table = self::table( 'wallet_txns' );

        // اگر جدول هنوز وجود ندارد (نصب کاملاً تازه)، create_tables() همین الان
        // با تعریف نهایی ساخته‌اش؛ پس فقط در صورت وجود قبلی جدول این ALTER لازم است.
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( ! $exists ) {
            return;
        }

        $wpdb->query(
            "ALTER TABLE {$table} MODIFY COLUMN source
             ENUM('commission','cashback','gift','admin','purchase','transfer_in','transfer_out','spin_reward','challenge_reward')
             NOT NULL"
        );
    }

    /**
     * ساخت همه جداول با dbDelta
     */
    private static function create_tables(): void {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();
        $prefix  = $wpdb->prefix . 'vp_';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // ── جدول دعوت‌ها ─────────────────────────────────────────────────────
        dbDelta( "CREATE TABLE {$prefix}referrals (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ambassador_id BIGINT UNSIGNED NOT NULL COMMENT 'شناسه سفیر (wp_users.ID)',
            referee_id    BIGINT UNSIGNED DEFAULT NULL COMMENT 'شناسه خریدار جدید',
            order_id      BIGINT UNSIGNED DEFAULT NULL COMMENT 'شناسه سفارش ووکامرس',
            coupon_code   VARCHAR(32)     NOT NULL COMMENT 'کد تخفیف منحصربه‌فرد',
            referral_url  VARCHAR(512)    NOT NULL COMMENT 'لینک دعوت کامل',
            status        ENUM('pending','converted','cancelled') NOT NULL DEFAULT 'pending',
            click_count   INT UNSIGNED    NOT NULL DEFAULT 0,
            ip_address    VARCHAR(45)     DEFAULT NULL COMMENT 'IP کلیک اول',
            device_hash   VARCHAR(64)     DEFAULT NULL COMMENT 'Fingerprint دستگاه',
            created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            converted_at  DATETIME        DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY idx_ambassador (ambassador_id),
            KEY idx_coupon     (coupon_code),
            KEY idx_status     (status)
        ) $charset;" );

        // ── جدول کمیسیون‌ها ──────────────────────────────────────────────────
        dbDelta( "CREATE TABLE {$prefix}commissions (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            referral_id   BIGINT UNSIGNED NOT NULL,
            ambassador_id BIGINT UNSIGNED NOT NULL,
            order_id      BIGINT UNSIGNED NOT NULL,
            order_total   DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
            rate          DECIMAL(5,2)    NOT NULL DEFAULT 0.00 COMMENT 'درصد کمیسیون',
            amount        DECIMAL(12,2)   NOT NULL DEFAULT 0.00 COMMENT 'مبلغ کمیسیون تومان',
            status        ENUM('pending','approved','cancelled','paid') NOT NULL DEFAULT 'pending',
            hold_until    DATETIME        DEFAULT NULL COMMENT 'تاریخ پایان دوره معلق',
            approved_at   DATETIME        DEFAULT NULL,
            note          VARCHAR(255)    DEFAULT NULL,
            created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_ambassador (ambassador_id),
            KEY idx_status     (status),
            KEY idx_hold_until (hold_until)
        ) $charset;" );

        // ── جدول تراکنش‌های کیف پول ──────────────────────────────────────────
        dbDelta( "CREATE TABLE {$prefix}wallet_txns (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id       BIGINT UNSIGNED NOT NULL,
            type          ENUM('credit','debit') NOT NULL,
            source        ENUM('commission','cashback','gift','admin','purchase','transfer_in','transfer_out','spin_reward','challenge_reward') NOT NULL,
            amount        DECIMAL(12,2)   NOT NULL,
            balance_after DECIMAL(12,2)   NOT NULL COMMENT 'موجودی بعد از تراکنش',
            status        ENUM('active','pending','expired') NOT NULL DEFAULT 'active',
            ref_id        BIGINT UNSIGNED DEFAULT NULL COMMENT 'شناسه مرجع (سفارش/کمیسیون)',
            description   VARCHAR(255)    DEFAULT NULL,
            expire_at     DATETIME        DEFAULT NULL COMMENT 'تاریخ انقضا اعتبار',
            ip_address    VARCHAR(45)     DEFAULT NULL,
            created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_user      (user_id),
            KEY idx_status    (status),
            KEY idx_expire    (expire_at)
        ) $charset;" );

        // ── جدول لاگ ضد تقلب ─────────────────────────────────────────────────
        dbDelta( "CREATE TABLE {$prefix}fraud_log (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ambassador_id BIGINT UNSIGNED NOT NULL,
            referee_ip    VARCHAR(45)     DEFAULT NULL,
            ambassador_ip VARCHAR(45)     DEFAULT NULL,
            device_hash   VARCHAR(64)     DEFAULT NULL,
            flag_type     VARCHAR(64)     NOT NULL COMMENT 'نوع تخلف شناسایی‌شده',
            flag_detail   TEXT            DEFAULT NULL,
            action_taken  ENUM('warn','suspend','block') NOT NULL DEFAULT 'warn',
            resolved      TINYINT(1)      NOT NULL DEFAULT 0,
            created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_ambassador (ambassador_id),
            KEY idx_resolved   (resolved)
        ) $charset;" );

        // ── جدول چرخش جعبه‌شانس (گیمیفیکیشن) ─────────────────────────────────
        dbDelta( "CREATE TABLE {$prefix}spins (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id        BIGINT UNSIGNED NOT NULL,
            trigger_type   VARCHAR(32)     NOT NULL COMMENT 'منشأ آزادشدن این چرخش: referral_converted | order_completed | manual',
            trigger_ref_id BIGINT UNSIGNED DEFAULT NULL COMMENT 'شناسه‌ی مرجع (دعوت/سفارش) که این چرخش را آزاد کرده',
            prize_key      VARCHAR(64)     NOT NULL COMMENT 'کلید جایزه‌ی برده‌شده، از تنظیمات گردونه',
            prize_label    VARCHAR(128)    NOT NULL COMMENT 'برچسب نمایشی جایزه در لحظه‌ی چرخش (جدا از تنظیمات فعلی تا تاریخچه عوض نشود)',
            prize_amount   DECIMAL(12,2)   NOT NULL DEFAULT 0.00 COMMENT 'مقدار ریالی جایزه (در صورت جایزه‌ی کیف‌پولی)',
            wallet_txn_id  BIGINT UNSIGNED DEFAULT NULL COMMENT 'در صورت واریز کیف‌پولی، شناسه‌ی تراکنش مربوطه',
            ip_address     VARCHAR(45)     DEFAULT NULL,
            created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_user         (user_id),
            KEY idx_trigger_ref  (trigger_type, trigger_ref_id),
            KEY idx_created      (created_at)
        ) $charset;" );
    }

    // ── متدهای کمکی برای کوئری ──────────────────────────────────────────────

    /** نام جدول با پیشوند صحیح */
    public static function table( string $name ): string {
        global $wpdb;
        return $wpdb->prefix . 'vp_' . $name;
    }
}