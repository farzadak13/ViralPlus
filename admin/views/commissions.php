<?php defined('ABSPATH') || exit; ?>
<div class="wrap" style="direction:rtl;">
    <h1>💸 کمیسیون‌ها</h1>
    <?php
    global $wpdb;
    $table   = VP_Database::table('commissions');
    $results = $wpdb->get_results(
        "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 100"
    );
    $status_labels = [
        'pending'  => '<span style="color:#d97706">معلق</span>',
        'approved' => '<span style="color:#16a34a">تأیید</span>',
        'cancelled'=> '<span style="color:#dc2626">لغو</span>',
        'paid'     => '<span style="color:#2563eb">پرداخت</span>',
    ];
    if ( $results ) : ?>
    <table class="wp-list-table widefat fixed striped" style="direction:rtl;">
        <thead><tr>
            <th>#</th><th>سفیر</th><th>سفارش</th>
            <th>مبلغ</th><th>نرخ%</th><th>کمیسیون</th>
            <th>وضعیت</th><th>سررسید</th><th>اقدام</th>
        </tr></thead>
        <tbody>
        <?php foreach ( $results as $row ) :
            $user = get_userdata( (int) $row->ambassador_id );
        ?>
        <tr>
            <td><?php echo esc_html( $row->id ); ?></td>
            <td><?php echo esc_html( $user ? $user->display_name : '#'.$row->ambassador_id ); ?></td>
            <td><a href="<?php echo esc_url( get_edit_post_link( $row->order_id ) ); ?>">#<?php echo esc_html($row->order_id); ?></a></td>
            <td><?php echo esc_html( number_format($row->order_total) ); ?>ت</td>
            <td><?php echo esc_html( $row->rate ); ?>%</td>
            <td><?php echo esc_html( number_format($row->amount) ); ?>ت</td>
            <td><?php echo $status_labels[$row->status] ?? esc_html($row->status); ?></td>
            <td><?php echo esc_html( $row->hold_until ? wp_date('Y/m/d', strtotime($row->hold_until)) : '—' ); ?></td>
            <td>
                <?php if ( $row->status === 'pending' ) : ?>
                <button class="button button-small vp-admin-btn"
                    data-action="approve_commission" data-id="<?php echo esc_attr($row->id); ?>">تأیید</button>
                <button class="button button-small vp-admin-btn"
                    data-action="cancel_commission" data-id="<?php echo esc_attr($row->id); ?>">لغو</button>
                <?php else : echo '—'; endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else : ?>
    <p>هنوز کمیسیونی ثبت نشده است.</p>
    <?php endif; ?>
</div>