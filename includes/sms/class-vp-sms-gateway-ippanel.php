<?php
/**
 * Adapter پنل پیامکی آی‌پی‌پنل (IPPanel)
 * مستندات عمومی API (RESTful v1): https://ippanel.com/dashboard/sms/api
 */

defined( 'ABSPATH' ) || exit;

class VP_SMS_Gateway_Ippanel extends VP_SMS_Gateway_Abstract {

    public static function key(): string {
        return 'ippanel';
    }

    public static function label(): string {
        return 'آی‌پی‌پنل (IPPanel)';
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

        if ( empty( $api_key ) || empty( $sender ) ) {
            return VP_SMS_Result::fail( 'کلید API یا شماره‌ی فرستنده‌ی آی‌پی‌پنل تنظیم نشده است.' );
        }

        $to  = $this->normalize_mobile( $to );
        $url = 'https://edge.ippanel.com/v1/api/send';

        $response = wp_remote_post( $url, [
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => $api_key,
            ],
            'body' => wp_json_encode( [
                'sending_type' => 'webservice',
                'from_number'  => $sender,
                'message'      => $text,
                'params'       => [ 'recipients' => [ $to ] ],
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            return VP_SMS_Result::fail( $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 ) {
            $err = $data['error_message'] ?? $data['message'] ?? 'خطای نامشخص از سمت آی‌پی‌پنل';
            return VP_SMS_Result::fail( $err, $data );
        }

        $message_id = $data['data']['message_id'] ?? null;
        return VP_SMS_Result::ok( $message_id ? (string) $message_id : null, $data );
    }
}