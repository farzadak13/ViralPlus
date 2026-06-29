<?php
/**
 * Adapter پنل پیامکی کاوه‌نگار (Kavenegar)
 * مستندات عمومی API: https://kavenegar.com/rest.html
 */

defined( 'ABSPATH' ) || exit;

class VP_SMS_Gateway_Kavenegar extends VP_SMS_Gateway_Abstract {

    public static function key(): string {
        return 'kavenegar';
    }

    public static function label(): string {
        return 'کاوه‌نگار (Kavenegar)';
    }

    public static function fields(): array {
        return [
            [ 'key' => 'api_key', 'label' => 'API Key', 'type' => 'password' ],
            [ 'key' => 'sender',  'label' => 'شماره فرستنده', 'type' => 'text' ],
        ];
    }

    public function send( string $to, string $text ): VP_SMS_Result {
        $api_key = trim( (string) ( $this->settings['api_key'] ?? '' ) );
        $sender  = trim( (string) ( $this->settings['sender']  ?? '' ) );

        if ( empty( $api_key ) ) {
            return VP_SMS_Result::fail( 'کلید API کاوه‌نگار تنظیم نشده است.' );
        }

        $to  = $this->normalize_mobile( $to );
        $url = sprintf( 'https://api.kavenegar.com/v1/%s/sms/send.json', rawurlencode( $api_key ) );

        $body = [
            'receptor' => $to,
            'message'  => $text,
        ];
        if ( ! empty( $sender ) ) {
            $body['sender'] = $sender;
        }

        $response = wp_remote_post( $url, [
            'timeout' => 15,
            'body'    => $body,
        ] );

        if ( is_wp_error( $response ) ) {
            return VP_SMS_Result::fail( $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            $err = $data['return']['message'] ?? 'خطای نامشخص از سمت کاوه‌نگار';
            return VP_SMS_Result::fail( $err, $data );
        }

        $message_id = $data['entries'][0]['messageid'] ?? null;
        return VP_SMS_Result::ok( $message_id ? (string) $message_id : null, $data );
    }
}