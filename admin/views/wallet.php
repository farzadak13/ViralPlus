<?php defined('ABSPATH') || exit; ?>
<div class="wrap" style="direction:rtl;">
    <h1>💰 مدیریت کیف پول</h1>

    <!-- شارژ دستی -->
    <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:20px;margin-bottom:24px;max-width:500px;">
        <h3 style="margin-top:0;">شارژ دستی کیف پول</h3>
        <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
            <div>
                <label style="display:block;font-size:0.85em;margin-bottom:4px;">شناسه کاربر</label>
                <input type="number" id="vp-charge-user" class="regular-text" placeholder="مثلاً ۵">
            </div>
            <div>
                <label style="display:block;font-size:0.85em;margin-bottom:4px;">مبلغ (تومان)</label>
                <input type="number" id="vp-charge-amount" class="regular-text" placeholder="مثلاً ۱۰۰۰۰۰">
            </div>
            <button id="vp-charge-btn" class="button button-primary">شارژ</button>
        </div>
        <div id="vp-charge-result" style="margin-top:10px;font-size:0.9em;"></div>
    </div>

    <!-- تاریخچه تراکنش‌ها -->
    <?php
    global $wpdb;
    $table   = VP_Database::table('wallet_txns');
    $results = $wpdb->get_results(
        "SELECT t.*, u.display_name FROM {$table} t
         LEFT JOIN {$wpdb->users} u ON u.ID = t.user_id
         ORDER BY t.created_at DESC LIMIT 100"
    );
    if ( $results ) : ?>
    <table class="wp-list-table widefat fixed striped" style="direction:rtl;">
        <thead><tr>
            <th>تاریخ</th><th>کاربر</th><th>نوع</th>
            <th>منبع</th><th>مبلغ</th><th>موجودی بعد</th><th>توضیح</th>
        </tr></thead>
        <tbody>
        <?php foreach ( $results as $row ) : ?>
        <tr>
            <td><?php echo esc_html( wp_date('Y/m/d H:i', strtotime($row->created_at)) ); ?></td>
            <td><?php echo esc_html( $row->display_name ?: '#'.$row->user_id ); ?></td>
            <td style="color:<?php echo $row->type==='credit' ? '#16a34a' : '#dc2626'; ?>">
                <?php echo $row->type==='credit' ? '↑ واریز' : '↓ برداشت'; ?></td>
            <td><?php echo esc_html( $row->source ); ?></td>
            <td><?php echo esc_html( number_format($row->amount) ); ?>ت</td>
            <td><?php echo esc_html( number_format($row->balance_after) ); ?>ت</td>
            <td><?php echo esc_html( $row->description ?: '—' ); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else : ?>
    <p>هنوز تراکنشی ثبت نشده است.</p>
    <?php endif; ?>
</div>

<script>
(function($) {
    $('#vp-charge-btn').on('click', function() {
        var userId  = $('#vp-charge-user').val();
        var amount  = $('#vp-charge-amount').val();
        var $result = $('#vp-charge-result');

        if ( !userId || !amount || amount <= 0 ) {
            $result.html('<span style="color:red">لطفاً شناسه کاربر و مبلغ را وارد کنید.</span>');
            return;
        }

        $.post(ajaxurl, {
            action:    'vp_admin_action',
            nonce:     VP_Admin.nonce,
            vp_action: 'credit_wallet',
            item_id:   userId,
            amount:    amount
        }, function(res) {
            $result.html(res.success
                ? '<span style="color:#16a34a">✓ ' + res.data + '</span>'
                : '<span style="color:red">خطا: ' + res.data + '</span>'
            );
        });
    });
})(jQuery);
</script>