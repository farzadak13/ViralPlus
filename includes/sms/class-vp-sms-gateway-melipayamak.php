<?php
/**
 * Adapter پنل پیامکی ملی‌پیامک (MeliPayamak)
 * مستندات عمومی API (RESTful SendSMS): https://www.melipayamak.com/api/
 */

defined( 'ABSPATH' ) || exit;

class VP_SMS_Gateway_Melipayamak extends VP_SMS_Gateway_Abstract {

    public static function key(): string {
        return 'melipayamak';
    }

    public static function label(): string {
        return 'ملی‌پیامک (MeliPayamak)';
    }

    public static function fields(): array {
        return [
            [ 'key' => 'username', 'label' => 'نام کاربری', 'type' => 'text' ],
            [ 'key' => 'password', 'label' => 'رمز عبور / API Key', 'type' => 'password' ],
            [ 'key' => 'sender',   'label' => 'شماره فرستنده', 'type' => 'text' ],
        ];
    }

    public function send( string $to, string $text ): VP_SMS_Result {
        $username = trim( (string) ( $this->settings['username'] ?? '' ) );
        $password = trim( (string) ( $this->settings['password'] ?? '' ) );
        $sender   = trim( (string) ( $this->settings['sender']   ?? '' ) );

        if ( empty( $username ) || empty( $password ) || empty( $sender ) ) {
            return VP_SMS_Result::fail( 'نام‌کاربری، رمز یا شماره‌ی فرستنده‌ی ملی‌پیامک تنظیم نشده است.' );
        }

        $to  = $this->normalize_mobile( $to );
        $url = 'https://rest.payamak-panel.com/api/SendSMS/SendSMS';

        $response = wp_remote_post( $url, [
            'timeout' => 15,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [
                'username' => $username,
                'password' => $password,
                'to'       => $to,
                'from'     => $sender,
                'text'     => $text,
                'isflash'  => false,
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            return VP_SMS_Result::fail( $response->get_error_message() );
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        // ملی‌پیامک معمولاً RetStatus=1 را به‌عنوان موفق برمی‌گرداند
        $ret_status = $data['RetStatus'] ?? 0;
        if ( (int) $ret_status !== 1 ) {
            $err = $data['StrRetStatus'] ?? 'خطای نامشخص از سمت ملی‌پیامک';
            return VP_SMS_Result::fail( $err, $data );
        }

        $message_id = $data['Value'] ?? null;
        return VP_SMS_Result::ok( $message_id ? (string) $message_id : null, $data );
    }
}