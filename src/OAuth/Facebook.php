<?php
/**
 * Conexión con Facebook por OAuth.
 *
 * @package Voceador
 */

namespace Voceador\OAuth;

use Voceador\AppCredentials;
use Voceador\ChannelRepository;
use Voceador\Channels\FacebookPageAdapter;
use Voceador\Crypto;
use Voceador\GraphClient;
use Voceador\Installer;
use Voceador\Logger;
use Voceador\Notices;
use Voceador\Registrable;
use Voceador\Settings;

/**
 * Lleva al administrador por el diálogo de Facebook y da de alta las Páginas elegidas.
 *
 * El token de usuario nunca se guarda: solo se conservan los Page tokens, que
 * derivados de un token de usuario de larga duración no expiran.
 */
final class Facebook implements Registrable {

	/**
	 * Acción de admin-post que abre el diálogo.
	 */
	public const ACTION_START = 'voceador_oauth_fb_start';

	/**
	 * Acción de admin-post a la que vuelve Facebook.
	 */
	public const ACTION_CALLBACK = 'voceador_oauth_fb';

	/**
	 * Acción de admin-post que da de alta las Páginas elegidas.
	 */
	public const ACTION_CONNECT = 'voceador_oauth_fb_connect';

	/**
	 * Permisos que se piden siempre.
	 */
	public const SCOPES = array( 'pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'pages_manage_engagement' );

	/**
	 * Vida de los transients de state y de candidatas, en segundos.
	 */
	private const TTL = 600;

	/**
	 * Páginas de resultados que se siguen como mucho al listar Páginas.
	 */
	private const MAX_PAGES = 5;

	/**
	 * Constructor.
	 *
	 * @param AppCredentials    $app      Credenciales de la app.
	 * @param GraphClient       $graph    Cliente de Graph.
	 * @param ChannelRepository $channels Canales.
	 * @param Crypto            $crypto   Cifrado.
	 * @param Settings          $settings Ajustes.
	 * @param Logger            $logger   Log.
	 */
	public function __construct(
		private AppCredentials $app,
		private GraphClient $graph,
		private ChannelRepository $channels,
		private Crypto $crypto,
		private Settings $settings,
		private Logger $logger
	) {}

	/**
	 * Engancha los tres handlers de admin-post.
	 */
	public function register_hooks(): void {
		add_action( 'admin_post_' . self::ACTION_START, array( $this, 'handle_start' ) );
		add_action( 'admin_post_' . self::ACTION_CALLBACK, array( $this, 'handle_callback' ) );
		add_action( 'admin_post_' . self::ACTION_CONNECT, array( $this, 'handle_connect' ) );
	}

	/**
	 * URI de redirección que hay que registrar en la app de Meta.
	 *
	 * @return string
	 */
	public function redirect_uri(): string {
		return admin_url( 'admin-post.php' ) . '?action=' . self::ACTION_CALLBACK;
	}

	/**
	 * Permisos que se piden en el diálogo.
	 *
	 * @return string[]
	 */
	public function scopes(): array {
		$scopes = self::SCOPES;

		if ( $this->app->business_management() ) {
			$scopes[] = 'business_management';
		}

		/**
		 * Permite ajustar los permisos que se piden a Facebook.
		 *
		 * @param string[] $scopes Permisos.
		 */
		return array_values( array_unique( (array) apply_filters( 'voceador_oauth_scopes', $scopes ) ) );
	}

	/**
	 * URL del diálogo de autorización.
	 *
	 * @param string $state Valor del parámetro state.
	 * @return string
	 */
	public function authorize_url( string $state ): string {
		$version = (string) $this->settings->get( 'graph_version' );

		return add_query_arg(
			array(
				'client_id'     => rawurlencode( $this->app->app_id() ),
				'redirect_uri'  => rawurlencode( $this->redirect_uri() ),
				'state'         => rawurlencode( $state ),
				'response_type' => 'code',
				'scope'         => rawurlencode( implode( ',', $this->scopes() ) ),
			),
			'https://www.facebook.com/' . $version . '/dialog/oauth'
		);
	}

	/**
	 * Crea un state de un solo uso ligado al usuario actual.
	 *
	 * @return string
	 */
	public function create_state(): string {
		$state = wp_generate_password( 32, false );

		set_transient( $this->state_key( $state ), get_current_user_id(), self::TTL );

		return $state;
	}

	/**
	 * Comprueba y consume un state.
	 *
	 * @param string $state Valor recibido de Facebook.
	 * @return bool
	 */
	public function consume_state( string $state ): bool {
		if ( '' === $state ) {
			return false;
		}

		$key   = $this->state_key( $state );
		$owner = get_transient( $key );

		if ( false === $owner || get_current_user_id() !== (int) $owner ) {
			return false;
		}

		delete_transient( $key );

		return true;
	}

	/**
	 * Intercambia el código por un token de usuario de corta duración.
	 *
	 * @param string $code Código devuelto por Facebook.
	 * @return string|\WP_Error
	 */
	public function exchange_code( string $code ): string|\WP_Error {
		$response = $this->graph->get(
			'oauth/access_token',
			array(
				'client_id'     => $this->app->app_id(),
				'client_secret' => $this->app->app_secret(),
				'redirect_uri'  => $this->redirect_uri(),
				'code'          => $code,
			)
		);

		return is_wp_error( $response ) ? $response : $this->token_from( $response );
	}

	/**
	 * Convierte un token de usuario en uno de larga duración.
	 *
	 * @param string $user_token Token de corta duración.
	 * @return string|\WP_Error
	 */
	public function long_lived( string $user_token ): string|\WP_Error {
		$response = $this->graph->get(
			'oauth/access_token',
			array(
				'grant_type'        => 'fb_exchange_token',
				'client_id'         => $this->app->app_id(),
				'client_secret'     => $this->app->app_secret(),
				'fb_exchange_token' => $user_token,
			)
		);

		return is_wp_error( $response ) ? $response : $this->token_from( $response );
	}

	/**
	 * Páginas que administra el usuario del token.
	 *
	 * @param string $user_token Token de usuario de larga duración.
	 * @return array|\WP_Error Lista de array{id,name,access_token,tasks,picture}.
	 */
	public function pages( string $user_token ): array|\WP_Error {
		$found = array();
		$query = array(
			'fields' => 'id,name,access_token,tasks,picture{url}',
			'limit'  => 100,
		);
		$path  = 'me/accounts';

		for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
			$response = $this->graph->get( $path, $query, $user_token );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			foreach ( (array) ( $response['data'] ?? array() ) as $row ) {
				$found[] = array(
					'id'           => (string) ( $row['id'] ?? '' ),
					'name'         => (string) ( $row['name'] ?? '' ),
					'access_token' => (string) ( $row['access_token'] ?? '' ),
					'tasks'        => array_values( (array) ( $row['tasks'] ?? array() ) ),
					'picture'      => (string) ( $row['picture']['data']['url'] ?? '' ),
				);
			}

			$next = (string) ( $response['paging']['next'] ?? '' );

			if ( '' === $next ) {
				break;
			}

			// GraphClient antepone host y versión a la ruta, así que de la URL de
			// paginación solo se aprovecha el cursor.
			parse_str( (string) wp_parse_url( $next, PHP_URL_QUERY ), $next_query );
			$after = (string) ( $next_query['after'] ?? '' );

			if ( '' === $after ) {
				break;
			}

			$query['after'] = $after;
		}//end for

		return $found;
	}

	/**
	 * Permisos que el usuario concedió realmente a la app.
	 *
	 * @param string $user_token Token de usuario.
	 * @return string[] Nombres de los permisos con status "granted"; array() si Graph falla.
	 */
	public function permissions( string $user_token ): array {
		$response = $this->graph->get( 'me/permissions', array(), $user_token );

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$granted = array();

		foreach ( (array) ( $response['data'] ?? array() ) as $row ) {
			if ( 'granted' === ( $row['status'] ?? '' ) ) {
				$granted[] = (string) ( $row['permission'] ?? '' );
			}
		}

		return array_values( array_filter( $granted, static fn( string $name ): bool => '' !== $name ) );
	}

	/**
	 * Guarda cifradas las Páginas candidatas del usuario actual.
	 *
	 * @param array $pages Lista de Páginas.
	 */
	public function store_candidates( array $pages ): void {
		set_transient( $this->candidates_key(), $this->crypto->encrypt( (string) wp_json_encode( $pages ) ), self::TTL );
	}

	/**
	 * Páginas candidatas del usuario actual.
	 *
	 * @return array
	 */
	public function candidates(): array {
		$stored = get_transient( $this->candidates_key() );

		if ( ! is_string( $stored ) || '' === $stored ) {
			return array();
		}

		$plain = $this->crypto->decrypt( $stored );

		if ( is_wp_error( $plain ) ) {
			return array();
		}

		$decoded = json_decode( $plain, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Olvida las Páginas candidatas del usuario actual.
	 */
	public function forget_candidates(): void {
		delete_transient( $this->candidates_key() );
	}

	/**
	 * Da de alta o actualiza los canales de las Páginas elegidas.
	 *
	 * @param array<string, string> $selection Id de Página => alias.
	 * @return array{connected:int, updated:int, errors:string[]}
	 */
	public function connect( array $selection ): array {
		$candidates = array();
		foreach ( $this->candidates() as $page ) {
			$candidates[ (string) $page['id'] ] = $page;
		}

		$result = array(
			'connected' => 0,
			'updated'   => 0,
			'errors'    => array(),
		);

		foreach ( $selection as $page_id => $alias ) {
			$page_id = (string) $page_id;

			if ( ! isset( $candidates[ $page_id ] ) ) {
				$result['errors'][] = sprintf( /* translators: %s: id de la Página */ __( 'La Página %s ya no está en la lista de la conexión; vuelve a conectar.', 'voceador' ), $page_id );
				continue;
			}

			$page  = $candidates[ $page_id ];
			$tasks = array_values( (array) ( $page['tasks'] ?? array() ) );

			if ( $tasks && ! in_array( 'CREATE_CONTENT', $tasks, true ) ) {
				$result['errors'][] = sprintf( /* translators: %s: nombre de la Página */ __( 'La Página "%s" no te permite crear contenido, así que no se puede conectar.', 'voceador' ), (string) $page['name'] );
				continue;
			}

			$existing        = $this->channels->find_by_remote( FacebookPageAdapter::type(), $page_id );
			$submitted_alias = trim( (string) $alias );
			// Un alias en blanco conserva el alias existente al reconectar; solo cae al
			// nombre de la Página cuando el canal es nuevo.
			$fallback_alias = null !== $existing ? $existing->alias : (string) $page['name'];

			$data = array(
				'alias'             => '' !== $submitted_alias ? $submitted_alias : $fallback_alias,
				'remote_name'       => (string) $page['name'],
				'avatar_url'        => (string) ( $page['picture'] ?? '' ),
				'connection_method' => 'oauth',
				'credentials'       => array( 'access_token' => (string) $page['access_token'] ),
				'scopes'            => array_values( (array) ( $page['granted_scopes'] ?? array() ) ),
				'status'            => 'active',
			);

			if ( null !== $existing ) {
				if ( ! $this->channels->update( $existing->id, $data ) ) {
					$result['errors'][] = sprintf( /* translators: %s: alias del canal */ __( 'No se pudo actualizar el canal "%s".', 'voceador' ), $existing->alias );
					continue;
				}

				Notices::remove( Notices::key_for_channel( $existing->id ) );

				++$result['updated'];
				$this->logger->info( 'channel_reconnected', 'Canal reconectado', array( 'channel_id' => $existing->id ) );
				continue;
			}

			$inserted = $this->channels->insert(
				array_merge(
					$data,
					array(
						'type'      => FacebookPageAdapter::type(),
						'remote_id' => $page_id,
					)
				)
			);

			if ( is_wp_error( $inserted ) ) {
				$result['errors'][] = $inserted->get_error_message();
				continue;
			}

			++$result['connected'];
			$this->logger->info( 'channel_connected', 'Canal conectado', array( 'channel_id' => $inserted ) );
		}//end foreach

		return $result;
	}

	/**
	 * Abre el diálogo de autorización.
	 */
	public function handle_start(): void {
		$this->authorize();
		check_admin_referer( self::ACTION_START );

		if ( ! $this->app->is_configured() ) {
			$this->back( '', __( 'Configura primero el App ID y el App Secret.', 'voceador' ) );
		}

		$url = $this->authorize_url( $this->create_state() );

		// wp_safe_redirect() solo permite el propio host: aquí el destino es Facebook.
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Destino externo conocido: el diálogo de Facebook.
		exit;
	}

	/**
	 * Recibe la vuelta de Facebook y guarda las Páginas candidatas.
	 */
	public function handle_callback(): void {
		$this->authorize();

		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- El "state" es el nonce de un solo uso del flujo OAuth, verificado en consume_state().

		if ( ! $this->consume_state( $state ) ) {
			$this->back( '', __( 'La conexión caducó o no se pudo verificar. Inténtalo otra vez.', 'voceador' ) );
		}

		if ( isset( $_GET['error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- El "state" ya verificado arriba cubre este callback.
			$description = isset( $_GET['error_description'] ) ? sanitize_text_field( wp_unslash( $_GET['error_description'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- El "state" ya verificado arriba cubre este callback.
			$this->back( '', '' !== $description ? $description : __( 'Facebook no autorizó la conexión.', 'voceador' ) );
		}

		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- El "state" ya verificado arriba cubre este callback.

		if ( '' === $code ) {
			$this->back( '', __( 'Facebook no devolvió el código de autorización.', 'voceador' ) );
		}

		$short = $this->exchange_code( $code );
		if ( is_wp_error( $short ) ) {
			$this->fail( $short );
		}

		$long = $this->long_lived( $short );
		if ( is_wp_error( $long ) ) {
			$this->fail( $long );
		}

		$pages = $this->pages( $long );
		if ( is_wp_error( $pages ) ) {
			$this->fail( $pages );
		}

		if ( ! $pages ) {
			$this->back( '', __( 'Tu cuenta no administra ninguna Página. Si las Páginas están en un Business Manager, activa esa casilla y vuelve a conectar.', 'voceador' ) );
		}

		$granted = $this->permissions( $long );
		foreach ( $pages as &$page ) {
			$page['granted_scopes'] = $granted;
		}
		unset( $page );

		$this->store_candidates( $pages );
		$this->back( __( 'Elige las Páginas que quieres conectar.', 'voceador' ), '', array( 'voceador_step' => 'pages' ) );
	}

	/**
	 * Da de alta las Páginas marcadas en el formulario.
	 */
	public function handle_connect(): void {
		$this->authorize();
		check_admin_referer( self::ACTION_CONNECT );

		$selection = array();
		$raw       = isset( $_POST['pages'] ) ? wp_unslash( $_POST['pages'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cada elemento se sanea justo debajo.

		foreach ( (array) $raw as $page_id => $alias ) {
			if ( ! is_scalar( $alias ) ) {
				continue;
			}

			$selection[ sanitize_text_field( (string) $page_id ) ] = sanitize_text_field( (string) $alias );
		}

		if ( ! $selection ) {
			$this->back( '', __( 'No marcaste ninguna Página.', 'voceador' ) );
		}

		$result = $this->connect( $selection );
		$this->forget_candidates();

		if ( $result['errors'] ) {
			$this->back( '', implode( ' ', $result['errors'] ) );
		}

		$this->back(
			sprintf(
				/* translators: 1: Páginas conectadas, 2: Páginas actualizadas */
				__( 'Listo: %1$d Página(s) conectada(s) y %2$d actualizada(s).', 'voceador' ),
				$result['connected'],
				$result['updated']
			)
		);
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
	 * Vuelve a la pantalla de conexiones con un mensaje.
	 *
	 * @param string $message Mensaje de éxito.
	 * @param string $error   Mensaje de error.
	 * @param array  $extra   Parámetros adicionales de la URL.
	 */
	private function back( string $message = '', string $error = '', array $extra = array() ): never {
		$args = $extra;

		if ( '' !== $message ) {
			$args['voceador_msg'] = rawurlencode( $message );
		}
		if ( '' !== $error ) {
			$args['voceador_error'] = rawurlencode( $error );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=voceador' ) ) );
		exit;
	}

	/**
	 * Registra un error de Graph y vuelve a la pantalla.
	 *
	 * @param \WP_Error $error Error normalizado.
	 */
	private function fail( \WP_Error $error ): never {
		$message = Logger::redact_string( $error->get_error_message() );
		$data    = (array) $error->get_error_data();

		$this->logger->error(
			'oauth_failed',
			$message,
			array(
				'class'      => $error->get_error_code(),
				'graph_code' => $data['graph_code'] ?? 0,
			)
		);

		$this->back( '', $message );
	}

	/**
	 * Extrae el token de una respuesta de oauth/access_token.
	 *
	 * @param array $response Respuesta.
	 * @return string|\WP_Error
	 */
	private function token_from( array $response ): string|\WP_Error {
		$token = (string) ( $response['access_token'] ?? '' );

		if ( '' === $token ) {
			return new \WP_Error( 'fatal', __( 'Facebook no devolvió ningún token de acceso.', 'voceador' ), array( 'class' => 'fatal' ) );
		}

		return $token;
	}

	/**
	 * Clave del transient de state.
	 *
	 * @param string $state Valor del state.
	 * @return string
	 */
	private function state_key( string $state ): string {
		return VOCEADOR_PREFIX . 'oauth_state_' . md5( $state );
	}

	/**
	 * Clave del transient de Páginas candidatas.
	 *
	 * @return string
	 */
	private function candidates_key(): string {
		return VOCEADOR_PREFIX . 'oauth_pages_' . get_current_user_id();
	}
}
