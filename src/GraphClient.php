<?php
/**
 * Cliente HTTP de la Graph API de Meta.
 *
 * @package Voceador
 */

namespace Voceador;

/**
 * Único punto del plugin que habla HTTP con Meta y que conoce sus códigos de error.
 */
final class GraphClient {

	/**
	 * Clases de error normalizadas.
	 */
	public const ERROR_CLASSES = array( 'transient', 'rate_limited', 'auth', 'permission', 'media', 'spam', 'fatal' );

	/**
	 * Cabeceras de uso que Meta devuelve.
	 */
	private const USAGE_HEADERS = array( 'x-app-usage', 'x-business-use-case-usage', 'x-ad-account-usage' );

	/**
	 * Uso reportado por la última respuesta.
	 *
	 * @var array
	 */
	private array $last_usage = array();

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Ajustes (versión de Graph).
	 * @param Logger   $logger   Log.
	 * @param string   $base_url Host de la API, sin barra final.
	 */
	public function __construct(
		private Settings $settings,
		private Logger $logger,
		private string $base_url = 'https://graph.facebook.com'
	) {}

	/**
	 * Mapa por defecto de código Graph → clase.
	 *
	 * @return array<string, int[]>
	 */
	public static function default_error_map(): array {
		return array(
			'transient'    => array( 1, 2, 4, 17, 32, 341 ),
			'rate_limited' => array( 613, 80001, 80002, 80003, 80004, 80005, 80006 ),
			'auth'         => array( 102, 190 ),
			'permission'   => array_merge( array( 10 ), range( 200, 299 ) ),
			'media'        => array( 324, 1366 ),
			'spam'         => array( 368 ),
		);
	}

	/**
	 * Petición GET.
	 *
	 * @param string $path  Ruta sin versión ("me", "123/photos").
	 * @param array  $query Parámetros.
	 * @param string $token Token de acceso.
	 * @return array|\WP_Error
	 */
	public function get( string $path, array $query = array(), string $token = '' ): array|\WP_Error {
		return $this->request( 'GET', $path, $query, array(), $token );
	}

	/**
	 * Petición POST con cuerpo de formulario.
	 *
	 * @param string $path  Ruta.
	 * @param array  $body  Campos.
	 * @param string $token Token.
	 * @return array|\WP_Error
	 */
	public function post( string $path, array $body = array(), string $token = '' ): array|\WP_Error {
		return $this->request( 'POST', $path, array(), array( 'body' => $body ), $token );
	}

	/**
	 * Petición POST multipart con un archivo.
	 *
	 * @param string $path       Ruta.
	 * @param array  $fields     Campos de texto.
	 * @param string $file_field Nombre del campo de archivo ("source").
	 * @param string $file_path  Ruta local del archivo.
	 * @param string $token      Token.
	 * @return array|\WP_Error
	 */
	public function post_multipart( string $path, array $fields, string $file_field, string $file_path, string $token = '' ): array|\WP_Error {
		if ( ! is_readable( $file_path ) ) {
			return new \WP_Error(
				'media',
				__( 'No se pudo leer el archivo de imagen.', 'voceador' ),
				array(
					'class'        => 'media',
					'http'         => 0,
					'graph_code'   => 0,
					'subcode'      => 0,
					'type'         => '',
					'fbtrace_id'   => '',
					'user_message' => '',
					'retry_after'  => 0,
				)
			);
		}

		$boundary = 'voceador' . wp_generate_password( 24, false );
		$type     = wp_check_filetype( $file_path )['type'];
		$mime     = $type ? $type : 'application/octet-stream';
		$body     = '';

		foreach ( $fields as $name => $value ) {
			$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
		}

		$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$file_field}\"; filename=\"" . basename( $file_path ) . "\"\r\n";
		$body .= "Content-Type: {$mime}\r\n\r\n";
		$body .= file_get_contents( $file_path ) . "\r\n"; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Lectura local de un adjunto; WP_Filesystem es innecesario.
		$body .= "--{$boundary}--\r\n";

		return $this->request(
			'POST',
			$path,
			array(),
			array(
				'body'    => $body,
				'headers' => array( 'Content-Type' => "multipart/form-data; boundary={$boundary}" ),
			),
			$token
		);
	}

	/**
	 * Uso reportado en las cabeceras de la última respuesta.
	 *
	 * @return array Cabecera => array decodificado.
	 */
	public function last_usage(): array {
		return $this->last_usage;
	}

	/**
	 * Convierte un error de Graph en WP_Error con clase normalizada.
	 *
	 * @param int   $http_code   Código HTTP.
	 * @param array $error       Objeto "error" de la respuesta (puede estar vacío).
	 * @param int   $retry_after Segundos de la cabecera Retry-After, si la hubo.
	 * @return \WP_Error
	 */
	public function normalize_error( int $http_code, array $error, int $retry_after = 0 ): \WP_Error {
		$code    = (int) ( $error['code'] ?? 0 );
		$subcode = (int) ( $error['error_subcode'] ?? 0 );
		$class   = $this->classify( $http_code, $code );
		// Se redacta aquí, en el origen del error, para que ningún consumidor (CLI,
		// Publisher, adaptadores) pueda recibir un token en el mensaje.
		$message = Logger::redact_string( (string) ( $error['message'] ?? sprintf( /* translators: %d: código HTTP */ __( 'Respuesta HTTP %d de la Graph API.', 'voceador' ), $http_code ) ) );

		return new \WP_Error(
			$class,
			$message,
			array(
				'class'        => $class,
				'http'         => $http_code,
				'graph_code'   => $code,
				'subcode'      => $subcode,
				'type'         => (string) ( $error['type'] ?? '' ),
				'fbtrace_id'   => (string) ( $error['fbtrace_id'] ?? '' ),
				'user_message' => Logger::redact_string( (string) ( $error['error_user_msg'] ?? '' ) ),
				'retry_after'  => $retry_after,
			)
		);
	}

	/**
	 * Ejecuta la petición y normaliza la respuesta.
	 *
	 * @param string $method GET o POST.
	 * @param string $path   Ruta.
	 * @param array  $query  Parámetros de URL.
	 * @param array  $extra  body y/o headers adicionales.
	 * @param string $token  Token.
	 * @return array|\WP_Error
	 */
	private function request( string $method, string $path, array $query, array $extra, string $token ): array|\WP_Error {
		$url = $this->url( $path, $query );

		$headers = array_merge(
			array( 'Accept' => 'application/json' ),
			$extra['headers'] ?? array()
		);
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$args = array(
			'method'  => $method,
			'headers' => $headers,
			'body'    => $extra['body'] ?? null,
			/**
			 * Timeout de las peticiones a la Graph API, en segundos.
			 *
			 * @param int $timeout Segundos.
			 */
			'timeout' => (int) apply_filters( 'voceador_http_timeout', 30 ),
		);

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$error = new \WP_Error(
				'transient',
				$response->get_error_message(),
				array(
					'class'        => 'transient',
					'http'         => 0,
					'graph_code'   => 0,
					'subcode'      => 0,
					'type'         => 'transport',
					'fbtrace_id'   => '',
					'user_message' => '',
					'retry_after'  => 0,
				)
			);
			$this->log_error( $method, $path, $error );
			return $error;
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		$this->remember_usage( $response );

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$retry   = (int) wp_remote_retrieve_header( $response, 'retry-after' );

		if ( is_array( $decoded ) && isset( $decoded['error'] ) && is_array( $decoded['error'] ) ) {
			$error = $this->normalize_error( $http_code, $decoded['error'], $retry );
			$this->log_error( $method, $path, $error );
			return $error;
		}

		if ( $http_code >= 400 || ! is_array( $decoded ) ) {
			$error = $this->normalize_error( $http_code >= 400 ? $http_code : 0, array(), $retry );
			if ( $http_code < 400 ) {
				$error = new \WP_Error(
					'fatal',
					__( 'La Graph API devolvió una respuesta que no es JSON.', 'voceador' ),
					array_merge(
						$error->get_error_data(),
						array(
							'class' => 'fatal',
							'http'  => $http_code,
						)
					)
				);
			}
			$this->log_error( $method, $path, $error );
			return $error;
		}

		$this->logger->debug( 'graph_request', $method . ' ' . $path, array( 'http' => $http_code ) );

		return $decoded;
	}

	/**
	 * URL completa con versión.
	 *
	 * Los valores escalares se codifican tal cual; los que no lo son (Graph
	 * acepta JSON en parámetros complejos como "fields") se serializan a JSON
	 * antes de codificarlos.
	 *
	 * @param string $path  Ruta.
	 * @param array  $query Parámetros.
	 * @return string
	 */
	private function url( string $path, array $query ): string {
		$version = (string) $this->settings->get( 'graph_version' );
		$url     = rtrim( $this->base_url, '/' ) . '/' . $version . '/' . ltrim( $path, '/' );

		if ( ! $query ) {
			return $url;
		}

		$encoded = array_map(
			static function ( $value ) {
				$value = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );

				return rawurlencode( $value );
			},
			$query
		);

		return add_query_arg( $encoded, $url );
	}

	/**
	 * Clasifica un error.
	 *
	 * @param int $http_code Código HTTP.
	 * @param int $code      Código Graph (0 si no hay).
	 * @return string
	 */
	private function classify( int $http_code, int $code ): string {
		/**
		 * Permite ajustar qué códigos de Graph caen en cada clase de error.
		 *
		 * @param array<string, int[]> $map Clase => códigos.
		 */
		$map = (array) apply_filters( 'voceador_error_map', self::default_error_map() );

		if ( 0 !== $code ) {
			foreach ( $map as $class => $codes ) {
				if ( in_array( $class, self::ERROR_CLASSES, true ) && in_array( $code, array_map( 'intval', (array) $codes ), true ) ) {
					return $class;
				}
			}
		}

		if ( 429 === $http_code ) {
			return 'rate_limited';
		}

		if ( 408 === $http_code ) {
			return 'transient';
		}

		if ( $http_code >= 500 ) {
			return 'transient';
		}

		return 'fatal';
	}

	/**
	 * Guarda las cabeceras de uso de la respuesta.
	 *
	 * @param array $response Respuesta de wp_remote_request.
	 */
	private function remember_usage( array $response ): void {
		$this->last_usage = array();

		foreach ( self::USAGE_HEADERS as $header ) {
			$raw = wp_remote_retrieve_header( $response, $header );
			if ( '' === $raw || is_array( $raw ) ) {
				continue;
			}
			$decoded = json_decode( (string) $raw, true );
			if ( is_array( $decoded ) ) {
				$this->last_usage[ $header ] = $decoded;
			}
		}
	}

	/**
	 * Registra un error sin secretos.
	 *
	 * @param string    $method Método.
	 * @param string    $path   Ruta.
	 * @param \WP_Error $error  Error normalizado.
	 */
	private function log_error( string $method, string $path, \WP_Error $error ): void {
		$data = (array) $error->get_error_data();

		$this->logger->warning(
			'graph_error',
			$method . ' ' . $path . ': ' . Logger::redact_string( $error->get_error_message() ),
			array(
				'class'      => $data['class'] ?? '',
				'http'       => $data['http'] ?? 0,
				'graph_code' => $data['graph_code'] ?? 0,
				'subcode'    => $data['subcode'] ?? 0,
				'fbtrace_id' => $data['fbtrace_id'] ?? '',
			)
		);
	}
}
