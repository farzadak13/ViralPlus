<?php
/**
 * View: باکس کیف پول در صفحه پرداخت
 * متغیرهای در دسترس:
 *   $balance, $max_use, $cart_total, $session_amount
 */
defined( 'ABSPATH' ) || exit;
?>
<div class="vp-wallet-checkout" style="
    direction: rtl;
    background: #f0fdf4;
    border: 1.5px solid #86efac;
    border-radius: 10px;
    padding: 16px 20px;
    margin: 16px 0;
">
    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
        <span style="font-size: 1.3em;">💰</span>
        <strong style="color: #166534;">کیف پول من: <?php echo esc_html( number_format( $balance ) ); ?> تومان</strong>
    </div>

    <div style="display: flex; align-items: center; gap: 10px;">
        <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; font-size: 0.9em; color: #374151;">
            <input type="checkbox" id="vp-use-wallet"
                <?php checked( $session_amount > 0 ); ?>
                style="width: 16px; height: 16px; cursor: pointer; accent-color: #16a34a;"
            >
            استفاده از کیف پول
        </label>
    </div>

    <div id="vp-wallet-amount-wrap" style="margin-top: 10px; <?php echo $session_amount > 0 ? '' : 'display:none'; ?>">
        <label style="font-size: 0.85em; color: #6b7280; display: block; margin-bottom: 4px;">
            مبلغ استفاده (حداکثر <?php echo esc_html( number_format( $max_use ) ); ?> تومان):
        </label>
        <div style="display: flex; gap: 8px;">
            <input
                type="number"
                id="vp-wallet-amount"
                value="<?php echo esc_attr( $session_amount ?: $max_use ); ?>"
                min="0"
                max="<?php echo esc_attr( $max_use ); ?>"
                step="1000"
                style="border: 1px solid #d1d5db; border-radius: 6px; padding: 6px 10px; width: 150px; font-size: 0.9em;"
            >
            <button type="button" id="vp-wallet-apply"
                style="background: #16a34a; color: white; border: none; border-radius: 6px; padding: 6px 14px; cursor: pointer; font-size: 0.85em;">
                اعمال
            </button>
        </div>
        <div id="vp-wallet-feedback" style="font-size: 0.8em; color: #16a34a; margin-top: 4px; min-height: 18px;"></div>
    </div>
</div>

<script>
(function($) {
    var nonce = '<?php echo esc_js( wp_create_nonce( 'vp_wallet_nonce' ) ); ?>';

    $('#vp-use-wallet').on('change', function() {
        var $wrap = $('#vp-wallet-amount-wrap');
        if (this.checked) {
            $wrap.show();
        } else {
            $wrap.hide();
            sendAmount(0);
        }
    });

    $('#vp-wallet-apply').on('click', function() {
        sendAmount($('#vp-wallet-amount').val());
    });

    function sendAmount(amount) {
        $.post(wc_checkout_params.ajax_url, {
            action: 'vp_set_wallet_amount',
            nonce:  nonce,
            amount: amount
        }, function(res) {
            if (res.success) {
                $('#vp-wallet-feedback').text(amount > 0 ? '✓ ' + Number(res.data.amount).toLocaleString('fa') + ' تومان از کیف پول کسر می‌شود' : '');
                $(document.body).trigger('update_checkout');
            }
        });
    }
})(jQuery);
</script>