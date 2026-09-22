<?php
/**
 * @package Voceador
 */

namespace Voceador\Tests\Fixtures;

class GraphResponses {

	public static function ok( array $body, array $headers = array() ): array {
		return array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'headers'  => $headers,
			'body'     => wp_json_encode( $body ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	public static function error( int $http, int $code, string $message = 'boom', int $subcode = 0 ): array {
		return array(
			'response' => array(
				'code'    => $http,
				'message' => '',
			),
			'headers'  => array(),
			'body'     => wp_json_encode(
				array(
					'error' => array(
						'message'       => $message,
						'type'          => 'OAuthException',
						'code'          => $code,
						'error_subcode' => $subcode,
						'fbtrace_id'    => 'trace',
					),
				)
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	public static function transport( string $message = 'cURL error 28: timeout' ): \WP_Error {
		return new \WP_Error( 'http_request_failed', $message );
	}
}
