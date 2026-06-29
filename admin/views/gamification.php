<?php
/**
 * View: صفحه‌ی تنظیمات جعبه‌شانس (گیمیفیکیشن)
 */
defined( 'ABSPATH' ) || exit;

$prizes = get_option( VP_Gamification::OPTION_PRIZES, [] );
if ( empty( $prizes ) ) {
    // همان مقادیر پیش‌فرض کلاس را برای نمایش اولیه در فرم بیاور
    $prizes = VP_Gamification::instance()->get_prizes();
}
?>
<div class="wrap" style="direction: rtl;">
    <h1 style="display: flex; align-items: center; gap: 10px;">
        <span style="font-size: 1.3em;">🎁</span> جعبه‌شانس — گیمیفیکیشن
    </h1>
    <p class="description" style="max-width: 700px;">
        با فعال‌کردن این بخش، مشتریان شما بعد از دعوت موفق (یا هر خرید، در صورت تمایل) یک
        چرخش رایگان جعبه‌شانس می‌گیرند و می‌توانند جایزه‌ی نقدی به کیف‌پول‌شان برنده شوند.
        این ماژول کاملاً اختیاری است و تا وقتی آن را فعال نکنید، هیچ‌چیزی به مشتریان نمایش داده نمی‌شود.
    </p>

    <?php settings_errors( 'viralplus_gamification' ); ?>

    <form method="post" action="options.php">
        <?php settings_fields( 'viralplus_gamification' ); ?>

        <table class="form-table" role="presentation" style="direction: rtl;">

            <tr>
                <th scope="row">فعال‌سازی جعبه‌شانس</th>
                <td>
                    <label>
                        <input type="checkbox" name="<?php echo esc_attr( VP_Gamification::OPTION_ENABLED ); ?>" value="1"
                            <?php checked( 1, get_option( VP_Gamification::OPTION_ENABLED, 0 ) ); ?>>
                        جعبه‌شانس برای مشتریان این فروشگاه فعال باشد
                    </label>
                    <p class="description">با خاموش‌بودن این گزینه، هیچ بخشی از گیمیفیکیشن (ویجت، AJAX، اعطای چرخش) اجرا نمی‌شود.</p>
                </td>
            </tr>

            <tr>
                <th scope="row">منبع چرخش رایگان</th>
                <td>
                    <label style="display:block; margin-bottom:6px;">
                        <input type="checkbox" name="<?php echo esc_attr( VP_Gamification::OPTION_EARN_REFERRAL ); ?>" value="1"
                            <?php checked( 1, get_option( VP_Gamification::OPTION_EARN_REFERRAL, 1 ) ); ?>>
                        به‌ازای هر دعوت موفق سفیر، یک چرخش رایگان بده
                    </label>
                    <label style="display:block;">
                        <input type="checkbox" name="<?php echo esc_attr( VP_Gamification::OPTION_EARN_ORDER ); ?>" value="1"
                            <?php checked( 1, get_option( VP_Gamification::OPTION_EARN_ORDER, 0 ) ); ?>>
                        به‌ازای هر خرید تکمیل‌شده (هر مشتری)، یک چرخش رایگان بده
                    </label>
                </td>
            </tr>

            <tr>
                <th scope="row"><label for="vp_gam_max_spins">سقف چرخش روزانه‌ی هر کاربر</label></th>
                <td>
                    <input type="number" id="vp_gam_max_spins" name="<?php echo esc_attr( VP_Gamification::OPTION_MAX_SPINS_DAY ); ?>"
                           min="1" max="100" class="small-text"
                           value="<?php echo esc_attr( get_option( VP_Gamification::OPTION_MAX_SPINS_DAY, 10 ) ); ?>">
                    <p class="description">جلوگیری از سوءاستفاده در صورت انباشت چرخش رایگان زیاد.</p>
                </td>
            </tr>

            <tr>
                <th scope="row">جوایز گردونه</th>
                <td>
                    <table class="widefat" id="vp-prizes-table" style="max-width: 720px;">
                        <thead>
                            <tr>
                                <th>برچسب نمایشی</th>
                                <th>نوع جایزه</th>
                                <th>مقدار</th>
                                <th>وزن شانس</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $prizes as $i => $p ) : ?>
                            <tr>
                                <td>
                                    <input type="text" name="<?php echo esc_attr( VP_Gamification::OPTION_PRIZES ); ?>[<?php echo (int) $i; ?>][label]"
                                           value="<?php echo esc_attr( $p['label'] ); ?>" class="regular-text">
                                    <input type="hidden" name="<?php echo esc_attr( VP_Gamification::OPTION_PRIZES ); ?>[<?php echo (int) $i; ?>][key]"
                                           value="<?php echo esc_attr( $p['key'] ); ?>">
                                </td>
                                <td>
                                    <select name="<?php echo esc_attr( VP_Gamification::OPTION_PRIZES ); ?>[<?php echo (int) $i; ?>][type]">
                                        <option value="none" <?php selected( $p['type'], 'none' ); ?>>بدون جایزه</option>
                                        <option value="wallet_fixed" <?php selected( $p['type'], 'wallet_fixed' ); ?>>مقدار ثابت کیف‌پول (تومان)</option>
                                        <option value="wallet_percent" <?php selected( $p['type'], 'wallet_percent' ); ?>>درصدی از آخرین سفارش</option>
                                    </select>
                                </td>
                                <td>
                                    <input type="number" step="0.01" min="0"
                                           name="<?php echo esc_attr( VP_Gamification::OPTION_PRIZES ); ?>[<?php echo (int) $i; ?>][amount]"
                                           value="<?php echo esc_attr( $p['amount'] ); ?>" class="small-text">
                                </td>
                                <td>
                                    <input type="number" min="0"
                                           name="<?php echo esc_attr( VP_Gamification::OPTION_PRIZES ); ?>[<?php echo (int) $i; ?>][weight]"
                                           value="<?php echo esc_attr( $p['weight'] ); ?>" class="small-text">
                                </td>
                                <td>
                                    <button type="button" class="button vp-remove-prize-row">حذف</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p>
                        <button type="button" class="button" id="vp-add-prize-row">+ افزودن جایزه</button>
                    </p>
                    <p class="description">
                        «وزن شانس» عددی نسبی است (نه درصد دقیق) — مثلاً اگر همه‌ی ردیف‌ها وزن ۱۰ داشته باشند، شانس همه برابر است.
                        وزن بیشتر = شانس برد بیشتر. برای جوایز درصدی، مبنای محاسبه آخرین سفارش کاربر است.
                    </p>
                </td>
            </tr>

        </table>

        <?php submit_button( 'ذخیره تنظیمات جعبه‌شانس' ); ?>
    </form>
</div>

<script>
(function () {
    var optionKey = '<?php echo esc_js( VP_Gamification::OPTION_PRIZES ); ?>';
    var table = document.getElementById('vp-prizes-table').querySelector('tbody');

    document.getElementById('vp-add-prize-row').addEventListener('click', function () {
        var idx = table.querySelectorAll('tr').length;
        var randomKey = 'prize_' + Date.now();
        var row = document.createElement('tr');
        row.innerHTML =
            '<td><input type="text" name="' + optionKey + '[' + idx + '][label]" class="regular-text" placeholder="مثلاً ۱۰٬۰۰۰ تومان کیف‌پول">' +
            '<input type="hidden" name="' + optionKey + '[' + idx + '][key]" value="' + randomKey + '"></td>' +
            '<td><select name="' + optionKey + '[' + idx + '][type]">' +
            '<option value="none">بدون جایزه</option>' +
            '<option value="wallet_fixed" selected>مقدار ثابت کیف‌پول (تومان)</option>' +
            '<option value="wallet_percent">درصدی از آخرین سفارش</option>' +
            '</select></td>' +
            '<td><input type="number" step="0.01" min="0" name="' + optionKey + '[' + idx + '][amount]" class="small-text" value="0"></td>' +
            '<td><input type="number" min="0" name="' + optionKey + '[' + idx + '][weight]" class="small-text" value="10"></td>' +
            '<td><button type="button" class="button vp-remove-prize-row">حذف</button></td>';
        table.appendChild(row);
    });

    table.addEventListener('click', function (e) {
        if (e.target.classList.contains('vp-remove-prize-row')) {
            e.target.closest('tr').remove();
        }
    });
})();
</script>