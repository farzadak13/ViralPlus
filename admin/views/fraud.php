<?php defined('ABSPATH') || exit; ?>
<div class="wrap" style="direction:rtl;">
    <h1>🛡️ گزارش ضد تقلب</h1>
    <?php
    global $wpdb;
    $table   = VP_Database::table('fraud_log');
    $results = $wpdb->get_results(
        "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 100"
    );
    if ( $results ) : ?>
    <table class="wp-list-table widefat fixed striped" style="direction:rtl;">
        <thead><tr>
            <th>تاریخ</th><th>سفیر</th><th>نوع تخلف</th>
            <th>امتیاز</th><th>اقدام</th><th>وضعیت</th>
        </tr></thead>
        <tbody>
        <?php foreach ( $results as $row ) :
            $user    = get_userdata( (int) $row->ambassador_id );
            $detail  = json_decode( $row->flag_detail, true );
            $score   = $detail['score'] ?? 0;
        ?>
        <tr>
            <td><?php echo esc_html( wp_date('Y/m/d H:i', strtotime($row->created_at)) ); ?></td>
            <td><?php echo esc_html( $user ? $user->display_name : '#'.$row->ambassador_id ); ?></td>
            <td><?php echo esc_html( $row->flag_type ); ?></td>
            <td style="color:<?php echo $score >= 80 ? '#dc2626' : ($score >= 50 ? '#d97706' : '#374151'); ?>">
                <?php echo esc_html( $score ); ?></td>
            <td><?php echo esc_html( $row->action_taken ); ?></td>
            <td><?php echo $row->resolved ? '<span style="color:#16a34a">حل شده</span>' : '<span style="color:#dc2626">بررسی نشده</span>'; ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else : ?>
    <p>✅ هیچ رفتار مشکوکی ثبت نشده است.</p>
    <?php endif; ?>
</div>