<?php defined('ABSPATH') || exit; ?>
<div class="wrap" style="direction:rtl;">
    <h1>👥 سفیرها</h1>
    <?php
    global $wpdb;
    $ref_table = VP_Database::table('referrals');
    $results = $wpdb->get_results(
        "SELECT ambassador_id, COUNT(*) as total, MAX(converted_at) as last_ref
         FROM {$ref_table} WHERE status='converted'
         GROUP BY ambassador_id ORDER BY total DESC LIMIT 50"
    );
    if ( $results ) : ?>
    <table class="wp-list-table widefat fixed striped" style="direction:rtl;">
        <thead><tr>
            <th>نام سفیر</th><th>سطح</th><th>دعوت موفق</th>
            <th>موجودی کیف پول</th><th>آخرین دعوت</th><th>اقدام</th>
        </tr></thead>
        <tbody>
        <?php foreach ( $results as $row ) :
            $user     = get_userdata( (int) $row->ambassador_id );
            $level    = VP_Referral::instance()->get_user_level( (int) $row->ambassador_id );
            $balance  = VP_Wallet::instance()->get_balance( (int) $row->ambassador_id );
            $suspended = get_user_meta( (int) $row->ambassador_id, VP_Referral::META_SUSPENDED, true );
            $level_icons = ['bronze'=>'🥉','silver'=>'🥈','gold'=>'🥇'];
        ?>
        <tr>
            <td><?php echo esc_html( $user ? $user->display_name : '#'.$row->ambassador_id ); ?></td>
            <td><?php echo esc_html( ($level_icons[$level]??'') . ' ' . $level ); ?></td>
            <td><?php echo esc_html( $row->total ); ?></td>
            <td><?php echo esc_html( number_format( $balance ) ); ?> ت</td>
            <td><?php echo esc_html( $row->last_ref ? wp_date( 'Y/m/d', strtotime( $row->last_ref ) ) : '—' ); ?></td>
            <td>
                <?php if ( $suspended ) : ?>
                <button class="button button-small vp-admin-btn"
                    data-action="unsuspend_ambassador" data-id="<?php echo esc_attr($row->ambassador_id); ?>">رفع تعلیق</button>
                <?php else : ?>
                <button class="button button-small vp-admin-btn"
                    data-action="suspend_ambassador" data-id="<?php echo esc_attr($row->ambassador_id); ?>">تعلیق</button>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else : ?>
    <p>هنوز هیچ سفیری ثبت نشده است.</p>
    <?php endif; ?>
</div>