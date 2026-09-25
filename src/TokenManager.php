<?php
/**
 * Salud de los tokens de los canales.
 *
 * @package Voceador
 */

namespace Voceador;

use Voceador\Channels\Channel;
use Voceador\Channels\HealthReport;

/**
 * Consulta debug_token y pausa los canales cuyo token ha dejado de servir.
 *
 * Nunca reactiva un canal: eso solo ocurre al reconectarlo, porque un token
 * válido no garantiza que el administrador siga queriendo publicar ahí.
 */
final class TokenManager implements Registrable {

	/**
	 * Hook del cron diario.
	 */
	public const HOOK = 'voceador_check_tokens';

	/**
	 * Permisos que necesita un canal de Página para funcionar.
	 */
	public const REQUIRED_SCOPES = array( 'pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'pages_manage_engagement' );

	/**
	 * Estados que no se revisan.
	 */
	private const SKIPPED_STATUSES = array( 'disabled' );

	/**
	 * Constructor.
	 *
	 * @param AppCredentials    $app      Credenciales de la app.
	 * @param GraphClient       $graph    Cliente de Graph.
	 * @param ChannelRepository $channels Canales.
	 * @param Logger            $logger   Log.
	 */
	public function __construct(
		private AppCredentials $app,
		private GraphClient $graph,
		private ChannelRepository $channels,
		private Logger $logger
	) {}

	/**
	 * Engancha la revisión diaria.
	 */
	public function register_hooks(): void {
		add_action( self::HOOK, array( $this, 'check_all' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
	}

	/**
	 * Programa el cron diario si no existe.
	 */
	public function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Comprueba el token de un canal contra debug_token.
	 *
	 * @param Channel $channel Canal.
	 * @return HealthReport
	 */
	public function check( Channel $channel ): HealthReport {
		$token = (string) $channel->credential( 'access_token' );

		if ( '' === $token ) {
			return new HealthReport( false, __( 'El canal no tiene un token utilizable.', 'voceador' ) );
		}

		if ( ! $this->app->is_configured() ) {
			return new HealthReport( false, __( 'Configura el App ID y el App Secret para poder revisar los tokens.', 'voceador' ) );
		}

		$response = $this->graph->get( 'debug_token', array( 'input_token' => $token ), $this->app->app_token() );

		if ( is_wp_error( $response ) ) {
			return new HealthReport( false, Logger::redact_string( $response->get_error_message() ), array(), array(), null, (array) $response->get_error_data() );
		}

		$data = (array) ( $response['data'] ?? array() );

		return $this->report_from( $channel, $data );
	}

	/**
	 * Revisa todos los canales y pausa los que ya no sirven.
	 *
	 * @return array{checked:int, paused:int}
	 */
	public function check_all(): array {
		$result = array(
			'checked' => 0,
			'paused'  => 0,
		);

		foreach ( $this->channels->all() as $channel ) {
			if ( in_array( $channel->status, self::SKIPPED_STATUSES, true ) ) {
				continue;
			}

			++$result['checked'];
			$report = $this->check( $channel );
			$health = $this->health_from( $report );

			if ( $report->valid ) {
				// Se actualiza la salud sin tocar el estado: reactivar es cosa del reconectado.
				$this->channels->set_status( $channel->id, $channel->status, $health );
				continue;
			}

			$this->channels->set_status( $channel->id, 'paused', $health );
			Notices::add(
				Notices::key_for_channel( $channel->id ),
				sprintf(
					/* translators: 1: alias del canal, 2: motivo */
					__( 'Voceador pausó el canal "%1$s": %2$s Vuelve a conectarlo desde Voceador → Conexiones.', 'voceador' ),
					$channel->alias,
					$report->message
				)
			);
			$this->logger->error( 'token_invalid', $report->message, array( 'channel_id' => $channel->id ) );
			++$result['paused'];
		}//end foreach

		if ( $result['checked'] > 0 ) {
			$this->logger->info( 'tokens_checked', 'Revisión de tokens', $result );
		}

		return $result;
	}

	/**
	 * Traduce la respuesta de debug_token a un informe de salud.
	 *
	 * @param Channel $channel Canal.
	 * @param array   $data    Objeto data de la respuesta.
	 * @return HealthReport
	 */
	private function report_from( Channel $channel, array $data ): HealthReport {
		$scopes     = array_values( (array) ( $data['scopes'] ?? array() ) );
		$missing    = array_values( array_diff( self::REQUIRED_SCOPES, $scopes ) );
		$expires_at = (int) ( $data['expires_at'] ?? 0 );
		$expires    = $expires_at > 0 ? gmdate( 'Y-m-d H:i:s', $expires_at ) : null;

		if ( isset( $data['error'] ) && is_array( $data['error'] ) ) {
			$message = Logger::redact_string( (string) ( $data['error']['message'] ?? __( 'Token rechazado por Facebook.', 'voceador' ) ) );

			return new HealthReport( false, $message, $scopes, $missing, $expires, $data );
		}

		if ( empty( $data['is_valid'] ) ) {
			return new HealthReport( false, __( 'Facebook marca el token como no válido.', 'voceador' ), $scopes, $missing, $expires, $data );
		}

		$app_id = (string) ( $data['app_id'] ?? '' );
		if ( '' !== $app_id && $app_id !== $this->app->app_id() ) {
			return new HealthReport( false, __( 'El token pertenece a otra app de Meta.', 'voceador' ), $scopes, $missing, $expires, $data );
		}

		$profile_id = (string) ( $data['profile_id'] ?? '' );
		if ( '' !== $profile_id && '' !== $channel->remote_id && $profile_id !== $channel->remote_id ) {
			return new HealthReport( false, __( 'El token pertenece a otra Página.', 'voceador' ), $scopes, $missing, $expires, $data );
		}

		$message = $missing
			? sprintf(
				/* translators: %s: lista de permisos */
				__( 'Token válido, pero faltan permisos: %s', 'voceador' ),
				implode( ', ', $missing )
			)
			: __( 'Token válido.', 'voceador' );

		return new HealthReport( true, $message, $scopes, $missing, $expires, $data );
	}

	/**
	 * Convierte el informe en el array que se guarda en channels.health.
	 *
	 * @param HealthReport $report Informe.
	 * @return array
	 */
	private function health_from( HealthReport $report ): array {
		return array(
			'valid'          => $report->valid,
			'message'        => $report->message,
			'scopes'         => $report->scopes,
			'missing_scopes' => $report->missing_scopes,
			'expires_at'     => $report->expires_at,
			'type'           => (string) ( $report->raw['type'] ?? '' ),
		);
	}
}
