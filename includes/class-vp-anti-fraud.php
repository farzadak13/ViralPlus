<?php
/**
 * کلاس ضد تقلب چندلایه
 *
 * لایه‌های بررسی:
 *  ۱. IP مشترک بین سفیر و خریدار
 *  ۲. Device Fingerprint یکسان
 *  ۳. الگوریتم زمانی (۳ دعوت در ۱ ساعت)
 *  ۴. تطبیق آدرس
 *  ۵. هشدار خودکار به مدیر
 *  ۶. تعلیق موقت سفیر مشکوک
 */

defined( 'ABSPATH' ) || exit;

class VP_Anti_Fraud {

    private static ?VP_Anti_Fraud $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // تعلیق خودکار سفیر مشکوک
        add_action( 'vp_fraud_detected', [ $this, 'handle_fraud' ], 10, 4 );
    }

    /**
     * بررسی جامع تقلب
     *
     * @return array{is_fraud: bool, flags: string[], score: int}
     */
    public function check( int $ambassador_id, int $buyer_id, int $order_id ): array {
        $flags = [];
        $score = 0;

        $buyer_ip     = $this->get_current_ip();
        $ambassador_ip = $this->get_user_last_ip( $ambassador_id );

        // ── لایه ۱: IP مشترک ─────────────────────────────────────────────
        if ( ! empty( $buyer_ip ) && ! empty( $ambassador_ip )
            && $buyer_ip === $ambassador_ip ) {
            $flags[] = 'same_ip';
            $score  += 40;
        }

        // ── لایه ۲: Device Fingerprint ───────────────────────────────────
        $buyer_device     = WC()->session?->get( 'vp_device_hash' ) ?? '';
        $ambassador_device = get_user_meta( $ambassador_id, '_vp_device_hash', true );

        if ( ! empty( $buyer_device ) && ! empty( $ambassador_device )
            && $buyer_device === $ambassador_device ) {
            $flags[] = 'same_device';
            $score  += 40;
        }

        // ── لایه ۳: الگوریتم زمانی ───────────────────────────────────────
        $rapid_threshold = (int) get_option( 'vp_fraud_rapid_invites', 3 );
        $rapid_window    = (int) get_option( 'vp_fraud_rapid_window_hours', 1 );

        if ( $this->has_rapid_invites( $ambassador_id, $rapid_threshold, $rapid_window ) ) {
            $flags[] = 'rapid_invites';
            $score  += 25;
        }

        // ── لایه ۴: آدرس مشترک ───────────────────────────────────────────
        if ( $this->has_same_address( $ambassador_id, $buyer_id ) ) {
            $flags[] = 'same_address';
            $score  += 20;
        }

        // تعیین نتیجه
        $threshold = (int) get_option( 'vp_fraud_score_threshold', 50 );
        $is_fraud  = $score >= $threshold;

        if ( ! empty( $flags ) ) {
            $this->log_fraud_attempt( $ambassador_id, $buyer_ip, $flags, $score, $is_fraud );
        }

        return [
            'is_fraud' => $is_fraud,
            'flags'    => $flags,
            'score'    => $score,
        ];
    }

    /**
     * اقدام در صورت تشخیص تقلب
     */
    public function handle_fraud( int $ambassador_id, int $buyer_id, int $order_id, array $result ): void {
        $score = $result['score'];

        if ( $score >= 80 ) {
            // تعلیق فوری
            update_user_meta( $ambassador_id, VP_Referral::META_SUSPENDED, 1 );
            $action = 'suspend';
        } else {
            $action = 'warn';
        }

        // ارسال هشدار به مدیر
        $this->notify_admin( $ambassador_id, $result, $action );

        do_action( 'vp_fraud_action_taken', $ambassador_id, $action, $result );
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  بررسی‌های خاص
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * بررسی دعوت‌های سریع (X دعوت در Y ساعت)
     */
    private function has_rapid_invites( int $ambassador_id, int $count, int $hours ): bool {
        global $wpdb;
        $table = VP_Database::table( 'referrals' );

        $recent = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE ambassador_id = %d
               AND status = 'converted'
               AND converted_at >= DATE_SUB(NOW(), INTERVAL %d HOUR)",
            $ambassador_id, $hours
        ) );

        return $recent >= $count;
    }

    /**
     * بررسی آدرس مشترک سفیر و خریدار
     */
    private function has_same_address( int $ambassador_id, int $buyer_id ): bool {
        $amb_address  = get_user_meta( $ambassador_id, 'billing_address_1', true );
        $buyer_address = get_user_meta( $buyer_id, 'billing_address_1', true );

        if ( empty( $amb_address ) || empty( $buyer_address ) ) {
            return false;
        }

        return strtolower( trim( $amb_address ) ) === strtolower( trim( $buyer_address ) );
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  لاگ و اطلاع‌رسانی
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * ذخیره لاگ رفتار مشکوک
     */
    private function log_fraud_attempt(
        int $ambassador_id,
        string $referee_ip,
        array $flags,
        int $score,
        bool $is_fraud
    ): void {
        global $wpdb;

        $wpdb->insert(
            VP_Database::table( 'fraud_log' ),
            [
                'ambassador_id' => $ambassador_id,
                'referee_ip'    => sanitize_text_field( $referee_ip ),
                'ambassador_ip' => sanitize_text_field( $this->get_user_last_ip( $ambassador_id ) ),
                'flag_type'     => implode( ',', $flags ),
                'flag_detail'   => wp_json_encode( [ 'score' => $score, 'is_fraud' => $is_fraud ] ),
                'action_taken'  => $is_fraud ? 'suspend' : 'warn',
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s' ]
        );
    }

    /**
     * ارسال ایمیل هشدار به مدیر
     */
    private function notify_admin( int $ambassador_id, array $result, string $action ): void {
        $admin_email = get_option( 'admin_email' );
        $user        = get_userdata( $ambassador_id );
        $user_name   = $user ? $user->display_name : "کاربر #$ambassador_id";

        $subject = sprintf( '[ViralPlus] رفتار مشکوک — سفیر %s', $user_name );
        $body    = sprintf(
            "سفیر: %s (#%d)\nامتیاز تقلب: %d\nپرچم‌ها: %s\nاقدام: %s\n\nبرای بررسی وارد پنل مدیریت شوید.",
            $user_name,
            $ambassador_id,
            $result['score'],
            implode( ', ', $result['flags'] ),
            $action
        );

        wp_mail( $admin_email, $subject, $body );
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  متدهای کمکی
    // ─────────────────────────────────────────────────────────────────────────

    private function get_current_ip(): string {
        // همیشه اولویت با REMOTE_ADDR است چون در سطح شبکه توسط وب‌سرور ست می‌شود و قابل جعل توسط کاربر نیست
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        
        // اگر سرور شما به‌صورت قطعی پشت Cloudflare است و تنظیمات Nginx/Apache 
        // برای بازگردانی IP اصلی کانفیگ نشده، خطوط زیر را فعال کنید. در غیر این صورت، نیازی نیست.
        /*
        if ( isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
            $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
        }
        */
        
        return sanitize_text_field( $ip );
    }

    private function get_user_last_ip( int $user_id ): string {
        return (string) get_user_meta( $user_id, '_vp_last_ip', true );
    }
}