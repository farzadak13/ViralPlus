<?php
/**
 * Plugin Name: ViralPlus
 * Plugin URI:  https://viralplus.ir
 * Description: اکوسیستم کامل فروش — سفیر + کیف پول اعتباری + گیمیفیکیشن + هوش مصنوعی
 * Version:     1.0.0
 * Author:      ViralPlus
 * Text Domain: viralplus
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 7.0
 */

defined( 'ABSPATH' ) || exit;

// ── ثابت‌های پایه ────────────────────────────────────────────────────────────
define( 'VP_VERSION',  '1.0.0' );
define( 'VP_FILE',     __FILE__ );
define( 'VP_DIR',      plugin_dir_path( __FILE__ ) );
define( 'VP_URL',      plugin_dir_url( __FILE__ ) );
define( 'VP_BASENAME', plugin_basename( __FILE__ ) );

/**
 * بررسی پیش‌نیازها قبل از بارگذاری افزونه
 */
function vp_check_requirements(): bool {
    $errors = [];

    if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
        $errors[] = sprintf(
            'ViralPlus نیاز به PHP 8.0 یا بالاتر دارد. نسخه فعلی شما: %s',
            PHP_VERSION
        );
    }

    if ( ! defined( 'WC_VERSION' ) ) {
        $errors[] = 'ViralPlus نیاز به WooCommerce دارد. لطفاً ابتدا WooCommerce را نصب و فعال کنید.';
    } elseif ( version_compare( WC_VERSION, '7.0', '<' ) ) {
        $errors[] = sprintf(
            'ViralPlus نیاز به WooCommerce 7.0 یا بالاتر دارد. نسخه فعلی: %s',
            WC_VERSION
        );
    }

    if ( ! empty( $errors ) ) {
        add_action( 'admin_notices', function() use ( $errors ) {
            foreach ( $errors as $error ) {
                echo '<div class="notice notice-error"><p><strong>ViralPlus:</strong> '
                    . esc_html( $error ) . '</p></div>';
            }
        } );
        return false;
    }

    return true;
}

/**
 * بارگذاری اصلی افزونه
 */
function vp_init(): void {
    // بارگذاری فایل زبان
    load_plugin_textdomain( 'viralplus', false, dirname( VP_BASENAME ) . '/languages' );

    // بارگذاری کلاس‌های هسته
    require_once VP_DIR . 'includes/class-vp-database.php';
    require_once VP_DIR . 'includes/class-vp-referral.php';
    require_once VP_DIR . 'includes/class-vp-commission.php';
    require_once VP_DIR . 'includes/class-vp-wallet.php';
    require_once VP_DIR . 'includes/class-vp-anti-fraud.php';
    require_once VP_DIR . 'includes/class-vp-gamification.php';

    // بارگذاری زیرساخت پیامک (ترتیب مهم است: کلاس پایه و نتیجه قبل از Adapter ها)
    require_once VP_DIR . 'includes/sms/class-vp-sms-result.php';
    require_once VP_DIR . 'includes/sms/class-vp-sms-gateway-abstract.php';
    require_once VP_DIR . 'includes/sms/class-vp-sms-gateway-kavenegar.php';
    require_once VP_DIR . 'includes/sms/class-vp-sms-gateway-melipayamak.php';
    require_once VP_DIR . 'includes/sms/class-vp-sms-gateway-farazsms.php';
    require_once VP_DIR . 'includes/sms/class-vp-sms-gateway-ippanel.php';
    require_once VP_DIR . 'includes/class-vp-sms-manager.php';

    // در صورت آپدیت افزونه بدون غیرفعال/فعال مجدد، جداول جدید را همگام کن
    VP_Database::maybe_upgrade();

    // بارگذاری پنل مدیریتی
    if ( is_admin() ) {
        require_once VP_DIR . 'admin/class-vp-admin.php';
        VP_Admin::instance();
    }

    // راه‌اندازی هسته‌ها
    VP_Referral::instance();
    VP_Commission::instance();
    VP_Wallet::instance();
    VP_Anti_Fraud::instance();
    VP_Gamification::instance();
    VP_SMS_Manager::instance();
}

/**
 * نصب افزونه — ساخت جداول دیتابیس
 */
function vp_activate(): void {
    require_once VP_DIR . 'includes/class-vp-database.php';
    VP_Database::install();
    flush_rewrite_rules();
}

/**
 * غیرفعال‌سازی — پاک‌سازی Cache ها
 */
/**
 * غیرفعال‌سازی — پاک‌سازی هوک‌های زمان‌بندی‌شده
 */
function vp_deactivate(): void {
    wp_clear_scheduled_hook( 'vp_daily_wallet_cron' );
    wp_clear_scheduled_hook( 'vp_daily_commission_cron' );
    flush_rewrite_rules();
}

// ── هوک‌های اصلی ─────────────────────────────────────────────────────────────
register_activation_hook( VP_FILE,   'vp_activate' );
register_deactivation_hook( VP_FILE, 'vp_deactivate' );

add_action( 'plugins_loaded', function() {
    if ( vp_check_requirements() ) {
        vp_init();
    }
} );

add_action( 'before_woocommerce_init', function() {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', VP_FILE, true );
    }
} );