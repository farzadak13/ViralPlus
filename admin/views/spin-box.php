<?php
/**
 * View: باکس سفیر در صفحه تشکر
 * متغیرهای در دسترس:
 *   $coupon_code, $referral_url, $level, $total_refs, $settings, $next_target
 */
defined( 'ABSPATH' ) || exit;

$level_labels = [
    'bronze' => [ 'label' => 'برنزی', 'emoji' => '🥉' ],
    'silver' => [ 'label' => 'نقره‌ای', 'emoji' => '🥈' ],
    'gold'   => [ 'label' => 'طلایی', 'emoji' => '🥇' ],
];
$current_level_label = $level_labels[ $level ] ?? $level_labels['bronze'];

$progress_percent = 0;
$progress_text    = '';
if ( $next_target ) {
    $prev_threshold = $level === 'silver' ? 5 : 0;
    $range          = $next_target - $prev_threshold;
    $done           = $total_refs - $prev_threshold;
    $progress_percent = $range > 0 ? min( 100, round( $done / $range * 100 ) ) : 0;
    $remaining        = max( 0, $next_target - $total_refs );
    $progress_text    = sprintf( '%d دعوت دیگه تا سطح بعدی', $remaining );
}
?>
<div class="vp-thankyou-box" style="
    direction: rtl;
    background: #f8f4ff;
    border: 1.5px solid #c4a8f7;
    border-radius: 12px;
    padding: 24px;
    margin: 24px 0;
    font-family: inherit;
">
    <h3 style="margin: 0 0 8px; color: #5b21b6; font-size: 1.1em;">
        🎉 لینک دعوت اختصاصی شما آماده‌ست!
    </h3>
    <p style="margin: 0 0 16px; color: #6b7280; font-size: 0.9em;">
        هر بار که یکی از دوستانت با کد شما خرید کنه، کمیسیون به کیف پولت واریز میشه.
    </p>

    <?php if ( $next_target ) : ?>
    <!-- نوار پیشرفت -->
    <div style="margin-bottom: 16px;">
        <div style="display: flex; justify-content: space-between; font-size: 0.82em; color: #6b7280; margin-bottom: 4px;">
            <span><?php echo esc_html( $current_level_label['emoji'] . ' ' . $current_level_label['label'] ); ?></span>
            <span><?php echo esc_html( $progress_text ); ?></span>
        </div>
        <div style="background: #e5e7eb; border-radius: 999px; height: 8px; overflow: hidden;">
            <div style="background: #7c3aed; height: 100%; border-radius: 999px; width: <?php echo esc_attr( $progress_percent ); ?>%; transition: width 0.6s ease;"></div>
        </div>
        <div style="font-size: 0.8em; color: #9ca3af; margin-top: 4px;">
            <?php echo esc_html( $total_refs ); ?> دعوت موفق از <?php echo esc_html( $next_target ); ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- کد تخفیف -->
    <div style="
        background: white;
        border: 1px dashed #a78bfa;
        border-radius: 8px;
        padding: 12px 16px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 12px;
    ">
        <div>
            <div style="font-size: 0.78em; color: #9ca3af; margin-bottom: 2px;">کد تخفیف دوستانت:</div>
            <div style="font-size: 1.4em; font-weight: 700; letter-spacing: 3px; color: #5b21b6;">
                <?php echo esc_html( strtoupper( $coupon_code ) ); ?>
            </div>
        </div>
        <button
            onclick="navigator.clipboard.writeText('<?php echo esc_js( strtoupper( $coupon_code ) ); ?>').then(() => this.textContent = '✓ کپی شد')"
            style="background: #7c3aed; color: white; border: none; border-radius: 6px; padding: 8px 14px; cursor: pointer; font-size: 0.85em;"
        >کپی کد</button>
    </div>

    <!-- لینک دعوت -->
    <div style="
        background: white;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        padding: 10px 14px;
        font-size: 0.8em;
        color: #6b7280;
        word-break: break-all;
        margin-bottom: 16px;
    ">
        <strong style="color: #374151;">لینک دعوت:</strong>
        <?php echo esc_url( $referral_url ); ?>
    </div>

    <!-- دکمه‌های اشتراک‌گذاری -->
    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
        <a href="https://wa.me/?text=<?php echo rawurlencode( 'با کد تخفیف من خرید کن: ' . strtoupper( $coupon_code ) . ' — ' . $referral_url ); ?>"
           target="_blank"
           style="background: #25d366; color: white; text-decoration: none; padding: 9px 16px; border-radius: 8px; font-size: 0.85em; display: inline-flex; align-items: center; gap: 6px;">
            واتساپ
        </a>
        <button
            onclick="navigator.clipboard.writeText('<?php echo esc_js( $referral_url ); ?>').then(() => this.textContent = '✓ کپی شد')"
            style="background: #e5e7eb; color: #374151; border: none; border-radius: 8px; padding: 9px 16px; cursor: pointer; font-size: 0.85em;">
            کپی لینک
        </button>
    </div>

    <!-- کمیسیون -->
    <div style="margin-top: 14px; padding-top: 14px; border-top: 1px solid #e5e7eb; font-size: 0.83em; color: #6b7280;">
        <?php echo esc_html( $current_level_label['emoji'] . ' سطح ' . $current_level_label['label'] ); ?> —
        کمیسیون <?php echo esc_html( $settings['commission_rate'] ); ?>٪ از هر خرید موفق
    </div>
</div>