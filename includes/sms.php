<?php
/**
 * View: صفحه‌ی تنظیمات اتصال به پنل‌های پیامکی
 */
defined( 'ABSPATH' ) || exit;

$gateways      = VP_SMS_Manager::get_available_gateways();
$active_key    = get_option( VP_SMS_Manager::OPTION_ACTIVE_GATEWAY, '' );
$all_config    = get_option( VP_SMS_Manager::OPTION_GATEWAY_CONFIG, [] );
$events        = get_option( VP_SMS_Manager::OPTION_EVENTS, [] );

$event_labels = [
    'commission_approved' => 'تأیید و واریز کمیسیون به سفیر',
    'wallet_expiring'     => 'هشدار انقضای اعتبار کیف‌پول',
    'fraud_detected'      => 'هشدار تشخیص تقلب (به مدیر فروشگاه)',
    'level_up'            => 'ارتقای سطح سفیر',
];
?>
<div class="wrap" style="direction: rtl;">
    <h1 style="display: flex; align-items: center; gap: 10px;">
        <span style="font-size: 1.3em;">📲</span> اتصال به پنل پیامکی
    </h1>
    <p class="description" style="max-width: 700px;">
        با اتصال یکی از پنل‌های پیامکی معروف ایران، ViralPlus می‌تواند برای رویدادهای مهم
        (واریز کمیسیون، هشدار انقضای کیف‌پول و...) به‌صورت خودکار پیامک ارسال کند.
    </p>

    <?php settings_errors( 'viralplus_sms' ); ?>

    <form method="post" action="options.php">
        <?php settings_fields( 'viralplus_sms' ); ?>

        <table class="form-table" role="presentation" style="direction: rtl;">

            <tr>
                <th scope="row">فعال‌سازی ارسال پیامک</th>
                <td>
                    <label>
                        <input type="checkbox" name="<?php echo esc_attr( VP_SMS_Manager::OPTION_ENABLED ); ?>" value="1"
                            <?php checked( 1, get_option( VP_SMS_Manager::OPTION_ENABLED, 0 ) ); ?>>
                        ارسال پیامک برای این فروشگاه فعال باشد
                    </label>
                </td>
            </tr>

            <tr>
                <th scope="row"><label for="vp_sms_gateway">پنل پیامکی</label></th>
                <td>
                    <select name="<?php echo esc_attr( VP_SMS_Manager::OPTION_ACTIVE_GATEWAY ); ?>" id="vp_sms_gateway">
                        <option value="">— انتخاب کنید —</option>
                        <?php foreach ( $gateways as $key => $class_name ) : ?>
                            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $active_key, $key ); ?>>
                                <?php echo esc_html( $class_name::label() ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>

            <?php foreach ( $gateways as $key => $class_name ) :
                $fields       = $class_name::fields();
                $this_config  = $all_config[ $key ] ?? [];
            ?>
            <tr class="vp-sms-gateway-fields" data-gateway="<?php echo esc_attr( $key ); ?>"
                style="<?php echo $active_key === $key ? '' : 'display:none;'; ?>">
                <th scope="row"><?php echo esc_html( $class_name::label() ); ?></th>
                <td>
                    <?php foreach ( $fields as $field ) :
                        $field_value = $this_config[ $field['key'] ] ?? '';
                        $input_name  = VP_SMS_Manager::OPTION_GATEWAY_CONFIG . '[' . $key . '][' . $field['key'] . ']';
                    ?>
                        <p>
                            <label style="display:inline-block; min-width:140px;"><?php echo esc_html( $field['label'] ); ?></label>
                            <input type="<?php echo esc_attr( $field['type'] === 'password' ? 'password' : 'text' ); ?>"
                                   name="<?php echo esc_attr( $input_name ); ?>"
                                   value="<?php echo esc_attr( $field_value ); ?>"
                                   class="regular-text" autocomplete="off">
                        </p>
                    <?php endforeach; ?>
                </td>
            </tr>
            <?php endforeach; ?>

            <tr>
                <th scope="row"><label for="vp_sms_admin_mobile">شماره موبایل مدیر فروشگاه</label></th>
                <td>
                    <input type="text" id="vp_sms_admin_mobile" name="vp_sms_admin_mobile"
                           value="<?php echo esc_attr( get_option( 'vp_sms_admin_mobile', '' ) ); ?>"
                           class="regular-text" placeholder="09xxxxxxxxx">
                    <p class="description">برای دریافت پیامک هشدار تقلب.</p>
                </td>
            </tr>

            <tr>
                <th scope="row">رویدادهای پیامکی فعال</th>
                <td>
                    <?php foreach ( $event_labels as $event_key => $label ) : ?>
                        <label style="display:block; margin-bottom: 6px;">
                            <input type="checkbox"
                                   name="<?php echo esc_attr( VP_SMS_Manager::OPTION_EVENTS ); ?>[<?php echo esc_attr( $event_key ); ?>]"
                                   value="1" <?php checked( 1, $events[ $event_key ] ?? 0 ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </label>
                    <?php endforeach; ?>
                    <p class="description">هر رویداد جدا قابل فعال/غیرفعال است؛ پیش‌فرض همه خاموش‌اند تا هزینه‌ی پیامک کنترل‌شده بماند.</p>
                </td>
            </tr>

        </table>

        <?php submit_button( 'ذخیره تنظیمات پیامک' ); ?>
    </form>

    <hr>

    <h2>تست ارسال</h2>
    <p>
        <input type="text" id="vp-test-sms-mobile" class="regular-text" placeholder="09xxxxxxxxx">
        <button type="button" class="button button-secondary" id="vp-test-sms-btn">ارسال پیامک تست</button>
        <span id="vp-test-sms-result" style="margin-right: 10px;"></span>
    </p>
    <p class="description">قبل از تست، حتماً تنظیمات بالا را ذخیره کنید — تست از آخرین تنظیمات ذخیره‌شده استفاده می‌کند.</p>
</div>

<script>
(function () {
    var select = document.getElementById('vp_sms_gateway');
    var rows   = document.querySelectorAll('.vp-sms-gateway-fields');

    function updateVisibility() {
        var val = select.value;
        rows.forEach(function (row) {
            row.style.display = row.getAttribute('data-gateway') === val ? '' : 'none';
        });
    }
    select.addEventListener('change', updateVisibility);

    document.getElementById('vp-test-sms-btn').addEventListener('click', function () {
        var btn    = this;
        var mobile = document.getElementById('vp-test-sms-mobile').value.trim();
        var result = document.getElementById('vp-test-sms-result');

        if (!mobile) {
            result.textContent = 'شماره موبایل را وارد کنید.';
            result.style.color = '#dc2626';
            return;
        }

        btn.disabled = true;
        result.textContent = 'در حال ارسال...';
        result.style.color = '#6b7280';

        jQuery.post(ajaxurl, {
            action: 'vp_admin_action',
            nonce: VP_Admin.nonce,
            vp_action: 'test_sms',
            mobile: mobile
        }, function (res) {
            btn.disabled = false;
            result.style.color = res.success ? '#16a34a' : '#dc2626';
            result.textContent = res.data;
        });
    });
})();
</script>