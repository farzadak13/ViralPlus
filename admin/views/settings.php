<?php
/**
 * View: صفحه تنظیمات
 */
defined( 'ABSPATH' ) || exit;
?>
<div class="wrap" style="direction: rtl;">
    <h1 style="display: flex; align-items: center; gap: 10px;">
        <span style="font-size: 1.3em;">⚙️</span> تنظیمات ViralPlus
    </h1>

    <?php settings_errors( 'viralplus_settings' ); ?>

    <form method="post" action="options.php">
        <?php settings_fields( 'viralplus_settings' ); ?>

        <table class="form-table" role="presentation" style="direction: rtl;">

            <tr>
                <th scope="row"><label for="vp_commission_hold_days">مدت معلق ماندن کمیسیون (روز)</label></th>
                <td>
                    <input name="vp_commission_hold_days" id="vp_commission_hold_days" type="number"
                           min="1" max="90"
                           value="<?php echo esc_attr( get_option( 'vp_commission_hold_days', 15 ) ); ?>"
                           class="small-text">
                    <p class="description">بعد از این مدت و در صورت عدم مرجوعی، کمیسیون به کیف پول سفیر واریز می‌شود.</p>
                </td>
            </tr>

            <tr>
                <th scope="row"><label for="vp_wallet_expire_days">انقضای اعتبار کیف پول (روز)</label></th>
                <td>
                    <input name="vp_wallet_expire_days" id="vp_wallet_expire_days" type="number"
                           min="0" max="3650"
                           value="<?php echo esc_attr( get_option( 'vp_wallet_expire_days', 365 ) ); ?>"
                           class="small-text">
                    <p class="description">صفر = بی‌انقضا. هشدار ۷ روز قبل از انقضا ارسال می‌شود.</p>
                </td>
            </tr>

            <tr>
                <th scope="row"><label for="vp_wallet_max_percent">حداکثر استفاده از کیف پول (%)</label></th>
                <td>
                    <input name="vp_wallet_max_percent" id="vp_wallet_max_percent" type="number"
                           min="1" max="100"
                           value="<?php echo esc_attr( get_option( 'vp_wallet_max_percent', 100 ) ); ?>"
                           class="small-text">
                    <p class="description">مثلاً ۵۰ یعنی کاربر می‌تواند حداکثر ۵۰٪ هر سفارش را با کیف پول بپردازد.</p>
                </td>
            </tr>

            <tr>
                <th scope="row">امکان انتقال کیف پول</th>
                <td>
                    <label>
                        <input type="checkbox" name="vp_wallet_allow_transfer" value="1"
                            <?php checked( 1, get_option( 'vp_wallet_allow_transfer', 1 ) ); ?>>
                        اجازه انتقال اعتبار بین کاربران
                    </label>
                </td>
            </tr>

            <tr>
                <th scope="row"><label for="vp_fraud_score_threshold">آستانه امتیاز تقلب</label></th>
                <td>
                    <input name="vp_fraud_score_threshold" id="vp_fraud_score_threshold" type="number"
                           min="10" max="100"
                           value="<?php echo esc_attr( get_option( 'vp_fraud_score_threshold', 50 ) ); ?>"
                           class="small-text">
                    <p class="description">امتیاز بالای این مقدار = تقلب. کمتر = هشدار. (پیش‌فرض: ۵۰)</p>
                </td>
            </tr>

            <tr>
                <th scope="row"><label for="vp_fraud_rapid_invites">سقف دعوت سریع</label></th>
                <td>
                    <input name="vp_fraud_rapid_invites" id="vp_fraud_rapid_invites" type="number"
                           min="2" max="20"
                           value="<?php echo esc_attr( get_option( 'vp_fraud_rapid_invites', 3 ) ); ?>"
                           class="small-text"> دعوت در
                    <input name="vp_fraud_rapid_window_hours" id="vp_fraud_rapid_window_hours" type="number"
                           min="1" max="24"
                           value="<?php echo esc_attr( get_option( 'vp_fraud_rapid_window_hours', 1 ) ); ?>"
                           class="small-text"> ساعت = مشکوک
                </td>
            </tr>

        </table>

        <?php submit_button( 'ذخیره تنظیمات' ); ?>
    </form>
</div>