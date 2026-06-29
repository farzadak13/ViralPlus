<?php
/**
 * View: داشبورد مدیریتی
 */
defined( 'ABSPATH' ) || exit;

global $wpdb;

// ── آمار کلی ─────────────────────────────────────────────────────────────────
$ref_table  = VP_Database::table( 'referrals' );
$com_table  = VP_Database::table( 'commissions' );
$wal_table  = VP_Database::table( 'wallet_txns' );
$spin_table = VP_Database::table( 'spins' );

$total_refs       = (int)   $wpdb->get_var( "SELECT COUNT(*) FROM {$ref_table} WHERE status = 'converted'" );
$pending_com      = (float) $wpdb->get_var( "SELECT COALESCE(SUM(amount),0) FROM {$com_table} WHERE status = 'pending'" );
$total_wallet_bal = (float) $wpdb->get_var( "SELECT COALESCE(SUM(amount),0) FROM {$wal_table} WHERE type='credit' AND status='active'" )
                  - (float) $wpdb->get_var( "SELECT COALESCE(SUM(amount),0) FROM {$wal_table} WHERE type='debit'" );
$fraud_pending    = (int)   $wpdb->get_var( "SELECT COUNT(*) FROM " . VP_Database::table('fraud_log') . " WHERE resolved = 0" );

$gamification_enabled = (bool) get_option( VP_Gamification::OPTION_ENABLED, 0 );
$total_spins_week      = 0;
$total_spin_rewards     = 0;
if ( $gamification_enabled ) {
    $total_spins_week  = (int)   $wpdb->get_var( "SELECT COUNT(*) FROM {$spin_table} WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)" );
    $total_spin_rewards = (float) $wpdb->get_var( "SELECT COALESCE(SUM(prize_amount),0) FROM {$spin_table} WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)" );
}
?>
<div class="wrap" style="direction: rtl;">
    <h1>📊 داشبورد ViralPlus</h1>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin: 20px 0;">

        <div style="background: #f0f4ff; border: 1px solid #c7d2fe; border-radius: 10px; padding: 20px; text-align: center;">
            <div style="font-size: 2em; font-weight: 700; color: #4338ca;"><?php echo esc_html( number_format( $total_refs ) ); ?></div>
            <div style="color: #6b7280; font-size: 0.9em; margin-top: 4px;">دعوت موفق</div>
        </div>

        <div style="background: #fefce8; border: 1px solid #fde68a; border-radius: 10px; padding: 20px; text-align: center;">
            <div style="font-size: 2em; font-weight: 700; color: #92400e;"><?php echo esc_html( number_format( $pending_com ) ); ?></div>
            <div style="color: #6b7280; font-size: 0.9em; margin-top: 4px;">کمیسیون معلق (تومان)</div>
        </div>

        <div style="background: #f0fdf4; border: 1px solid #86efac; border-radius: 10px; padding: 20px; text-align: center;">
            <div style="font-size: 2em; font-weight: 700; color: #166534;"><?php echo esc_html( number_format( max( 0, $total_wallet_bal ) ) ); ?></div>
            <div style="color: #6b7280; font-size: 0.9em; margin-top: 4px;">جمع کیف پول‌ها (تومان)</div>
        </div>

        <?php if ( $fraud_pending > 0 ) : ?>
        <div style="background: #fff1f2; border: 1px solid #fecaca; border-radius: 10px; padding: 20px; text-align: center;">
            <div style="font-size: 2em; font-weight: 700; color: #be123c;"><?php echo esc_html( $fraud_pending ); ?></div>
            <div style="color: #6b7280; font-size: 0.9em; margin-top: 4px;">⚠️ رفتار مشکوک بررسی‌نشده</div>
        </div>
        <?php endif; ?>

    </div>

    <p style="color: #9ca3af; font-size: 0.85em;">
        ViralPlus نسخه <?php echo esc_html( VP_VERSION ); ?> —
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=viralplus-settings' ) ); ?>">تنظیمات</a>
    </p>
</div>