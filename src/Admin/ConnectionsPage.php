<?php
/**
 * Pantalla de conexiones.
 *
 * @package Voceador
 */

namespace Voceador\Admin;

use Voceador\AppCredentials;
use Voceador\ChannelRepository;
use Voceador\Channels\ChannelRegistry;
use Voceador\Installer;
use Voceador\Notices;
use Voceador\OAuth\Facebook;
use Voceador\Registrable;
use Voceador\TokenManager;

/**
 * Única pantalla de la fase 2: credenciales de la app, conexión con Facebook,
 * elección de Páginas y estado de los canales.
 */
final class ConnectionsPage implements Registrable {

	/**
	 * Slug del menú.
	 */
	public const SLUG = 'voceador';

	/**
	 * Acción de admin-post que guarda las credenciales.
	 */
	public const ACTION_SAVE_APP = 'voceador_save_app';

	/**
	 * Acción de admin-post de las acciones sobre un canal.
	 */
	public const ACTION_CHANNEL = 'voceador_channel_action';

	/**
	 * Constructor.
	 *
	 * @param AppCredentials    $app      Credenciales de la app.
	 * @param Facebook          $oauth    Conexión con Facebook.
	 * @param ChannelRepository $channels Canales.
	 * @param TokenManager      $tokens   Salud de los tokens.
	 * @param ChannelRegistry   $registry Tipos de canal.
	 */
	public function __construct(
		private AppCredentials $app,
		private Facebook $oauth,
		private ChannelRepository $channels,
		private TokenManager $tokens,
		private ChannelRegistry $registry
	) {}

	/**
	 * Engancha el menú, los avisos y los handlers.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
		add_action( 'admin_post_' . self::ACTION_SAVE_APP, array( $this, 'handle_save_app' ) );
		add_action( 'admin_post_' . self::ACTION_CHANNEL, array( $this, 'handle_channel_action' ) );
	}

	/**
	 * Añade el menú de primer nivel.
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'Voceador', 'voceador' ),
			__( 'Voceador', 'voceador' ),
			Installer::CAPABILITY,
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-megaphone',
			76
		);
	}

	/**
	 * Pinta la pantalla.
	 */
	public function render(): void {
		$candidates = $this->oauth->candidates();

		echo '<div class="wrap"><h1>' . esc_html__( 'Voceador · Conexiones', 'voceador' ) . '</h1>';

		$this->render_messages();
		$this->render_app_form();

		if ( $candidates ) {
			$this->render_candidates( $candidates );
		}

		$this->render_channels();

		echo '</div>';
	}

	/**
	 * Pinta los avisos persistentes, escapados.
	 */
	public function render_notices(): void {
		if ( ! current_user_can( Installer::CAPABILITY ) ) {
			return;
		}

		foreach ( Notices::all() as $key => $notice ) {
			printf(
				'<div class="notice notice-warning"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
				esc_html( (string) $notice['message'] ),
				esc_url( $this->channel_action_url( 'dismiss_notice', 0, $key ) ),
				esc_html__( 'Descartar', 'voceador' )
			);
		}
	}

	/**
	 * Guarda las credenciales enviadas por el formulario.
	 *
	 * @param array $input Datos ya sin barras invertidas.
	 */
	public function save_app_from( array $input ): void {
		$app_id     = sanitize_text_field( (string) ( $input['app_id'] ?? '' ) );
		$app_secret = trim( (string) ( $input['app_secret'] ?? '' ) );

		$this->app->save( $app_id, '' !== $app_secret ? $app_secret : null );
		$this->app->set_business_management( ! empty( $input['business_management'] ) );
	}

	/**
	 * Ejecuta una acción sobre un canal.
	 *
	 * @param string $action     check, disconnect o dismiss_notice.
	 * @param int    $channel_id Canal.
	 * @param string $notice_key Clave del aviso, solo para dismiss_notice.
	 * @return string Resultado: checked, paused, disconnected, dismissed, missing o unknown.
	 */
	public function run_channel_action( string $action, int $channel_id, string $notice_key = '' ): string {
		if ( 'dismiss_notice' === $action ) {
			Notices::remove( $notice_key );

			return 'dismissed';
		}

		$channel = $this->channels->find( $channel_id );

		if ( null === $channel ) {
			return 'missing';
		}

		if ( 'disconnect' === $action ) {
			$this->channels->delete( $channel->id );
			Notices::remove( Notices::key_for_channel( $channel->id ) );

			return 'disconnected';
		}

		if ( 'check' === $action ) {
			$report = $this->tokens->check( $channel );
			$health = array(
				'valid'          => $report->valid,
				'message'        => $report->message,
				'scopes'         => $report->scopes,
				'missing_scopes' => $report->missing_scopes,
				'expires_at'     => $report->expires_at,
			);

			if ( $report->valid ) {
				$this->channels->set_status( $channel->id, $channel->status, $health );

				return 'checked';
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

			return 'paused';
		}//end if

		return 'unknown';
	}

	/**
	 * Handler de admin-post que guarda las credenciales.
	 */
	public function handle_save_app(): void {
		$this->authorize();
		check_admin_referer( self::ACTION_SAVE_APP );

		$this->save_app_from( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- save_app_from() sanea cada campo.

		$this->back( __( 'Credenciales guardadas.', 'voceador' ) );
	}

	/**
	 * Handler de admin-post de las acciones sobre canales.
	 */
	public function handle_channel_action(): void {
		$this->authorize();

		$action     = isset( $_GET['voceador_action'] ) ? sanitize_key( wp_unslash( $_GET['voceador_action'] ) ) : '';
		$channel_id = isset( $_GET['channel'] ) ? absint( wp_unslash( $_GET['channel'] ) ) : 0;
		$notice_key = isset( $_GET['notice'] ) ? sanitize_text_field( wp_unslash( $_GET['notice'] ) ) : '';

		check_admin_referer( self::ACTION_CHANNEL . '_' . $action . '_' . $channel_id . '|' . $notice_key );

		$result   = $this->run_channel_action( $action, $channel_id, $notice_key );
		$messages = array(
			'checked'      => __( 'El token sigue siendo válido.', 'voceador' ),
			'paused'       => __( 'El token ya no sirve: el canal quedó pausado.', 'voceador' ),
			'disconnected' => __( 'Canal desconectado.', 'voceador' ),
			'dismissed'    => __( 'Aviso descartado.', 'voceador' ),
			'missing'      => __( 'Ese canal ya no existe.', 'voceador' ),
		);

		$this->back( $messages[ $result ] ?? __( 'Acción desconocida.', 'voceador' ) );
	}

	/**
	 * Pinta los mensajes que llegan por la URL.
	 */
	private function render_messages(): void {
		// Mensajes propios tras una redirección; el nonce lo valida quien ejecuta la acción.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['voceador_msg'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['voceador_msg'] ) ) ) . '</p></div>';
		}
		if ( isset( $_GET['voceador_error'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['voceador_error'] ) ) ) . '</p></div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Pinta el formulario de la app de Meta.
	 */
	private function render_app_form(): void {
		echo '<h2>' . esc_html__( 'App de Meta', 'voceador' ) . '</h2>';
		echo '<p>' . esc_html__( 'Registra esta URI de redirección en tu app (Facebook Login → Settings → Valid OAuth Redirect URIs):', 'voceador' ) . '</p>';
		echo '<p><input type="text" class="large-text code" readonly value="' . esc_attr( $this->oauth->redirect_uri() ) . '" onfocus="this.select()" />';
		echo ' <button type="button" class="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value);this.textContent=' . esc_attr( wp_json_encode( __( '¡Copiada!', 'voceador' ) ) ) . ';">' . esc_html__( 'Copiar', 'voceador' ) . '</button></p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION_SAVE_APP );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_SAVE_APP ) . '" />';
		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row"><label for="voceador-app-id">' . esc_html__( 'App ID', 'voceador' ) . '</label></th>';
		echo '<td><input type="text" id="voceador-app-id" name="app_id" class="regular-text" value="' . esc_attr( $this->app->app_id() ) . '" /></td></tr>';

		echo '<tr><th scope="row"><label for="voceador-app-secret">' . esc_html__( 'App Secret', 'voceador' ) . '</label></th>';
		echo '<td><input type="password" id="voceador-app-secret" name="app_secret" class="regular-text" autocomplete="new-password" value="" /><p class="description">';
		echo $this->app->has_secret()
			? esc_html__( 'Ya hay un secreto guardado. Déjalo en blanco para conservarlo.', 'voceador' )
			: esc_html__( 'Se guarda cifrado y no vuelve a mostrarse.', 'voceador' );
		echo '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Business Manager', 'voceador' ) . '</th>';
		echo '<td><label><input type="checkbox" name="business_management" value="1" ' . checked( $this->app->business_management(), true, false ) . ' /> ';
		echo esc_html__( 'Mis Páginas están en un Business Manager', 'voceador' ) . '</label></td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Guardar credenciales', 'voceador' ) );
		echo '</form>';

		if ( $this->app->is_configured() ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=' . Facebook::ACTION_START ), Facebook::ACTION_START );
			echo '<p><a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Conectar con Facebook', 'voceador' ) . '</a></p>';
		}
	}

	/**
	 * Pinta el selector de Páginas tras volver de Facebook.
	 *
	 * @param array $candidates Páginas candidatas.
	 */
	private function render_candidates( array $candidates ): void {
		echo '<h2>' . esc_html__( 'Páginas disponibles', 'voceador' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( Facebook::ACTION_CONNECT );
		echo '<input type="hidden" name="action" value="' . esc_attr( Facebook::ACTION_CONNECT ) . '" />';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Conectar', 'voceador' ) . '</th><th>' . esc_html__( 'Página', 'voceador' ) . '</th><th>' . esc_html__( 'Alias interno', 'voceador' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $candidates as $page ) {
			$id   = (string) ( $page['id'] ?? '' );
			$name = (string) ( $page['name'] ?? '' );

			echo '<tr><td><input type="checkbox" name="connect[]" value="' . esc_attr( $id ) . '" checked /></td>';
			echo '<td>' . esc_html( $name ) . '<br /><code>' . esc_html( $id ) . '</code></td>';
			echo '<td><input type="text" name="pages[' . esc_attr( $id ) . ']" class="regular-text" value="' . esc_attr( $name ) . '" /></td></tr>';
		}

		echo '</tbody></table>';
		submit_button( __( 'Conectar las Páginas marcadas', 'voceador' ) );
		echo '</form>';
	}

	/**
	 * Pinta la tabla de canales conectados.
	 */
	private function render_channels(): void {
		$channels = $this->channels->all();

		echo '<h2>' . esc_html__( 'Canales conectados', 'voceador' ) . '</h2>';

		if ( ! $channels ) {
			echo '<p>' . esc_html__( 'Todavía no hay ningún canal conectado.', 'voceador' ) . '</p>';
			return;
		}

		$labels = $this->registry->types();

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Alias', 'voceador' ) . '</th><th>' . esc_html__( 'Tipo', 'voceador' ) . '</th><th>' . esc_html__( 'Página', 'voceador' ) . '</th><th>' . esc_html__( 'Estado', 'voceador' ) . '</th>';
		echo '<th>' . esc_html__( 'Última revisión', 'voceador' ) . '</th><th>' . esc_html__( 'Acciones', 'voceador' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $channels as $channel ) {
			$health         = isset( $channel->health['message'] ) ? (string) $channel->health['message'] : '';
			$scopes         = isset( $channel->health['scopes'] ) ? (array) $channel->health['scopes'] : array();
			$missing_scopes = isset( $channel->health['missing_scopes'] ) ? (array) $channel->health['missing_scopes'] : array();

			echo '<tr>';
			echo '<td>' . esc_html( $channel->alias ) . '</td>';
			echo '<td>' . esc_html( $labels[ $channel->type ] ?? $channel->type ) . '</td>';
			echo '<td>' . esc_html( $channel->remote_name ) . '<br /><code>' . esc_html( $channel->remote_id ) . '</code></td>';
			echo '<td>' . esc_html( $channel->status );
			if ( '' !== $health ) {
				echo '<br /><span class="description">' . esc_html( $health ) . '</span>';
			}
			if ( $scopes ) {
				echo '<br /><span class="description">' . esc_html(
					sprintf(
						/* translators: %s: lista de permisos */
						__( 'Permisos: %s', 'voceador' ),
						implode( ', ', $scopes )
					)
				) . '</span>';
			}
			if ( $missing_scopes ) {
				echo '<br /><strong>' . esc_html(
					sprintf(
						/* translators: %s: lista de permisos */
						__( 'Faltan: %s', 'voceador' ),
						implode( ', ', $missing_scopes )
					)
				) . '</strong>';
			}
			echo '</td>';
			$expires = isset( $channel->health['expires_at'] ) ? (string) $channel->health['expires_at'] : '';
			echo '<td>' . esc_html( (string) ( $channel->health_checked_at ?? '—' ) );
			if ( '' !== $expires ) {
				echo '<br /><span class="description">' . esc_html( sprintf( /* translators: %s: fecha */ __( 'Caduca: %s', 'voceador' ), $expires ) ) . '</span>';
			}
			echo '</td>';
			echo '<td><a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . Facebook::ACTION_START ), Facebook::ACTION_START ) ) . '">' . esc_html__( 'Reconectar', 'voceador' ) . '</a> | ';
			echo '<a href="' . esc_url( $this->channel_action_url( 'check', $channel->id ) ) . '">' . esc_html__( 'Revisar ahora', 'voceador' ) . '</a> | ';
			echo '<a href="' . esc_url( $this->channel_action_url( 'disconnect', $channel->id ) ) . '" onclick="return confirm(' . esc_attr( wp_json_encode( __( '¿Desconectar este canal?', 'voceador' ) ) ) . ');">' . esc_html__( 'Desconectar', 'voceador' ) . '</a></td>';
			echo '</tr>';
		}//end foreach

		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Para reconectar un canal pausado, vuelve a pulsar "Conectar con Facebook": se actualizará su token sin perder su configuración.', 'voceador' ) . '</p>';
	}

	/**
	 * URL firmada de una acción sobre un canal.
	 *
	 * @param string $action     Acción.
	 * @param int    $channel_id Canal.
	 * @param string $notice_key Clave del aviso.
	 * @return string
	 */
	private function channel_action_url( string $action, int $channel_id, string $notice_key = '' ): string {
		$url = add_query_arg(
			array(
				'action'          => self::ACTION_CHANNEL,
				'voceador_action' => $action,
				'channel'         => $channel_id,
				'notice'          => rawurlencode( $notice_key ),
			),
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url( $url, self::ACTION_CHANNEL . '_' . $action . '_' . $channel_id . '|' . $notice_key );
	}

	/**
	 * Corta la ejecución si el usuario no puede gestionar el plugin.
	 */
	private function authorize(): void {
		if ( ! current_user_can( Installer::CAPABILITY ) ) {
			wp_die( esc_html__( 'No tienes permiso para gestionar las conexiones de Voceador.', 'voceador' ), 403 );
		}
	}

	/**
	 * Vuelve a la pantalla con un mensaje.
	 *
	 * @param string $message Mensaje.
	 */
	private function back( string $message ): void {
		wp_safe_redirect( add_query_arg( array( 'voceador_msg' => rawurlencode( $message ) ), admin_url( 'admin.php?page=' . self::SLUG ) ) );
		exit;
	}
}
