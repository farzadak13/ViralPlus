<?php
/**
 * Adapter پنل پیامکی فراز اس‌ام‌اس (FarazSMS / Farapayamak)
 * مستندات عمومی API (RESTful): https://farazsms.com/api_sms.php
 */

defined( 'ABSPATH' ) || exit;

class VP_SMS_Gateway_Farazsms extends VP_SMS_Gateway_Abstract {

    public static function key(): string {
        return 'farazsms';
    }

    public static function label(): string {
        return 'فراز اس‌ام‌اس (FarazSMS)';
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
            return VP_SMS_Result::fail( 'کلید API یا شماره‌ی فرستنده‌ی فراز اس‌ام‌اس تنظیم نشده است.' );
        }

        $to  = $this->normalize_mobile( $to );
        $url = 'https://api.farazsms.com/v1/sms/send/simple';

        $response = wp_remote_post( $url, [
            'timeout' => 15,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'AccessKey ' . $api_key,
            ],
            'body' => wp_json_encode( [
                'recipient' => $to,
                'sender'    => $sender,
                'message'   => $text,
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            return VP_SMS_Result::fail( $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 ) {
            $err = $data['message'] ?? 'خطای نامشخص از سمت فراز اس‌ام‌اس';
            return VP_SMS_Result::fail( $err, $data );
        }

        $message_id = $data['data']['packId'] ?? null;
        return VP_SMS_Result::ok( $message_id ? (string) $message_id : null, $data );
    }
}