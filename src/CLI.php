<?php
/**
 * Comandos WP-CLI.
 *
 * @package Voceador
 */

namespace Voceador;

use Voceador\Channels\ChannelRegistry;
use Voceador\Channels\FacebookPageAdapter;

/**
 * Lógica de los comandos `wp voceador …`. Los subcomandos son envoltorios finos
 * sobre métodos que devuelven arrays o WP_Error, para poder probarlos sin WP_CLI.
 */
final class CLI {

	/**
	 * Constructor.
	 *
	 * @param ChannelRepository $channels  Canales.
	 * @param ChannelRegistry   $registry  Adaptadores.
	 * @param Publisher         $publisher Publicador.
	 * @param JobRepository     $jobs      Trabajos.
	 * @param Queue             $queue     Cola.
	 * @param Logger            $logger    Log.
	 * @param TokenManager      $tokens    Salud de los tokens.
	 * @param AppCredentials    $app       Credenciales de la app de Meta.
	 */
	public function __construct(
		private ChannelRepository $channels,
		private ChannelRegistry $registry,
		private Publisher $publisher,
		private JobRepository $jobs,
		private Queue $queue,
		private Logger $logger,
		private TokenManager $tokens,
		private AppCredentials $app
	) {}

	/**
	 * Registra el comando en WP-CLI.
	 */
	public function register(): void {
		if ( ! class_exists( '\WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command( 'voceador channels add-facebook', array( $this, 'cmd_channels_add_facebook' ) );
		\WP_CLI::add_command( 'voceador channels list', array( $this, 'cmd_channels_list' ) );
		\WP_CLI::add_command( 'voceador channels delete', array( $this, 'cmd_channels_delete' ) );
		\WP_CLI::add_command( 'voceador channels check', array( $this, 'cmd_channels_check' ) );
		\WP_CLI::add_command( 'voceador publish', array( $this, 'cmd_publish' ) );
		\WP_CLI::add_command( 'voceador retry', array( $this, 'cmd_retry' ) );
		\WP_CLI::add_command( 'voceador status', array( $this, 'cmd_status' ) );
	}

	/**
	 * Conecta una Página de Facebook con un Page Access Token.
	 *
	 * @param string $page_id Id de la Página.
	 * @param string $token   Page Access Token.
	 * @param string $alias   Alias interno (por defecto el nombre de la Página).
	 * @return int|\WP_Error Id del canal.
	 */
	public function add_facebook_page( string $page_id, string $token, string $alias = '' ): int|\WP_Error {
		$probe  = new Channels\Channel(
			array(
				'type'        => FacebookPageAdapter::type(),
				'remote_id'   => $page_id,
				'credentials' => array( 'access_token' => $token ),
			)
		);
		$report = $this->registry->adapter( FacebookPageAdapter::type() )->validate_credentials( $probe );

		if ( ! $report->valid ) {
			return new \WP_Error( 'voceador_invalid_token', $report->message );
		}

		$name = (string) ( $report->raw['name'] ?? '' );

		return $this->channels->insert(
			array(
				'type'              => FacebookPageAdapter::type(),
				'alias'             => '' !== $alias ? $alias : $name,
				'remote_id'         => $page_id,
				'remote_name'       => $name,
				'connection_method' => 'manual',
				'credentials'       => array( 'access_token' => $token ),
				'health'            => array( 'message' => $name ),
				'health_checked_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Canales sin secretos, listos para tabular.
	 *
	 * @return array[]
	 */
	public function list_channels(): array {
		return array_map(
			static fn( Channels\Channel $c ) => array(
				'id'                => $c->id,
				'type'              => $c->type,
				'alias'             => $c->alias,
				'remote_id'         => $c->remote_id,
				'remote_name'       => $c->remote_name,
				'status'            => $c->status,
				'health_checked_at' => (string) $c->health_checked_at,
			),
			$this->channels->all()
		);
	}

	/**
	 * Borra un canal.
	 *
	 * @param int $id Id.
	 * @return bool
	 */
	public function delete_channel( int $id ): bool {
		return $this->channels->delete( $id );
	}

	/**
	 * Revisa la salud de uno o de todos los canales sin cambiar su estado.
	 *
	 * @param int|null $channel_id Canal concreto, o null para todos.
	 * @return array[] Filas con el resultado de cada canal.
	 */
	public function check_channels( ?int $channel_id = null ): array {
		$channels = null === $channel_id ? $this->channels->all() : array_filter( array( $this->channels->find( $channel_id ) ) );
		$rows     = array();

		foreach ( $channels as $channel ) {
			$report = $this->tokens->check( $channel );

			$rows[] = array(
				'channel'        => $channel->id,
				'alias'          => $channel->alias,
				'valid'          => $report->valid,
				'message'        => $report->message,
				'expires_at'     => (string) ( $report->expires_at ?? '' ),
				'missing_scopes' => implode( ',', $report->missing_scopes ),
			);
		}

		return $rows;
	}

	/**
	 * Publica un post ahora.
	 *
	 * @param int      $post_id    Post.
	 * @param int|null $channel_id Canal o null para todos.
	 * @param string   $source     Origen.
	 * @param bool     $force      Si true, reintenta también trabajos marcados como no verificados (unverified).
	 * @return array<int, string>
	 */
	public function publish( int $post_id, ?int $channel_id, string $source = 'cli', bool $force = false ): array {
		return $this->publisher->publish_now( $post_id, $channel_id, $source, $force );
	}

	/**
	 * Reintenta los trabajos ya existentes de un post.
	 *
	 * Sin $comment: solo actúa sobre trabajos en failed/skipped/rate_limited (release() +
	 * run()); los demás se ignoran (ya están en curso, publicados o pendientes de por sí).
	 * Con $comment: reintenta el comentario (retry_comment() + run_comment()) de cada
	 * trabajo del post o canal; run_comment() ya es un no-op si el comentario no estaba
	 * en un estado reintentable.
	 *
	 * @param int      $post_id    Post.
	 * @param int|null $channel_id Canal concreto, o null para todos los trabajos del post.
	 * @param bool     $comment    Si true, reintenta el comentario en vez de la publicación.
	 * @param bool     $force      Si true, permite reintentar un trabajo "unverified" (ignorado con $comment).
	 * @return array<int, string> Canal => estado.
	 */
	public function retry( int $post_id, ?int $channel_id, bool $comment = false, bool $force = false ): array {
		$jobs = null === $channel_id
			? $this->jobs->find_for_post( $post_id )
			: array_filter( array( $this->jobs->find_for_post_and_channel( $post_id, $channel_id ) ) );

		$result = array();

		foreach ( $jobs as $job ) {
			if ( $comment ) {
				$this->jobs->retry_comment( $job->id );
				$result[ $job->channel_id ] = $this->publisher->run_comment( $job->id );
				continue;
			}

			if ( ! in_array( $job->status, array( 'failed', 'skipped', 'rate_limited' ), true ) ) {
				continue;
			}

			$this->jobs->release( $job->id, current_time( 'mysql', true ) );
			$result[ $job->channel_id ] = $this->publisher->run( $job->id, $force );
		}

		return $result;
	}

	/**
	 * Resuelve el --channel de la línea de comandos.
	 *
	 * @param array $assoc_args Opciones.
	 * @return int|null|\WP_Error Id del canal, null si no se indicó --channel, o WP_Error si no es válido.
	 */
	public function resolve_channel( array $assoc_args ): int|null|\WP_Error {
		if ( ! isset( $assoc_args['channel'] ) ) {
			return null;
		}

		$raw = $assoc_args['channel'];
		if ( ! is_numeric( $raw ) || null === $this->channels->find( (int) $raw ) ) {
			return new \WP_Error( 'voceador_channel_missing', 'No existe ese canal.' );
		}

		return (int) $raw;
	}

	/**
	 * Lee el Page Access Token de --token-file (o STDIN si es "-", con trim) o --token.
	 *
	 * @param array $assoc_args Opciones.
	 * @return string|\WP_Error
	 */
	public function read_token( array $assoc_args ): string|\WP_Error {
		if ( isset( $assoc_args['token-file'] ) ) {
			$file = (string) $assoc_args['token-file'];

			if ( '-' === $file ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Lee STDIN (php://stdin), no un archivo remoto: WP_Filesystem no aplica.
				$token = trim( (string) file_get_contents( 'php://stdin' ) );
			} else {
				if ( ! is_readable( $file ) ) {
					return new \WP_Error( 'voceador_token_file', sprintf( 'No se pudo leer %s.', $file ) );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Lectura local de la ruta que indicó el operador en la línea de comandos.
				$token = trim( (string) file_get_contents( $file ) );
			}

			if ( '' === $token ) {
				return new \WP_Error( 'voceador_token_file', 'El archivo de token está vacío.' );
			}

			return $token;
		}

		$token = (string) ( $assoc_args['token'] ?? '' );
		if ( '' === $token ) {
			return new \WP_Error( 'voceador_token_missing', 'Indica --token-file=- (recomendado, evita que el token quede en el historial y en el log de sudo) o --token.' );
		}

		return $token;
	}

	/**
	 * Resumen del estado del plugin.
	 *
	 * @return array
	 */
	public function status(): array {
		$channels = array(
			'active'   => 0,
			'paused'   => 0,
			'error'    => 0,
			'disabled' => 0,
		);
		foreach ( $this->channels->all() as $c ) {
			$channels[ $c->status ] = ( $channels[ $c->status ] ?? 0 ) + 1;
		}

		return array(
			'channels'         => $channels,
			'jobs'             => $this->jobs->count_by_status(),
			'action_scheduler' => $this->queue->uses_action_scheduler(),
			'cron_available'   => $this->queue->cron_available(),
			'app'              => array(
				'configured'   => $this->app->is_configured(),
				'app_id'       => $this->app->app_id(),
				'redirect_uri' => admin_url( 'admin-post.php' ) . '?action=voceador_oauth_fb',
			),
			'recent_log'       => array_map(
				static fn( array $row ) => array(
					'created_at' => $row['created_at'],
					'level'      => $row['level'],
					'event'      => $row['event'],
					'message'    => $row['message'],
				),
				$this->logger->recent( array( 'limit' => 10 ) )
			),
		);
	}

	/**
	 * `wp voceador channels add-facebook --page-id=<id> [--token=<token>] [--token-file=<ruta|->] [--alias=<alias>]`
	 *
	 * @param array $args       Posicionales.
	 * @param array $assoc_args Opciones.
	 */
	public function cmd_channels_add_facebook( array $args, array $assoc_args ): void {
		$page_id = (string) ( $assoc_args['page-id'] ?? '' );
		if ( '' === $page_id ) {
			\WP_CLI::error( 'Falta --page-id.' );
		}

		$token = $this->read_token( $assoc_args );
		if ( is_wp_error( $token ) ) {
			\WP_CLI::error( $token->get_error_message() );
		}

		$result = $this->add_facebook_page( $page_id, $token, (string) ( $assoc_args['alias'] ?? '' ) );
		if ( is_wp_error( $result ) ) {
			// Defensa en profundidad: el mensaje puede venir de Graph (vía validate_credentials()),
			// que ya redacta en origen, pero se redacta también aquí antes de imprimirlo.
			\WP_CLI::error( Logger::redact_string( $result->get_error_message() ) );
		}

		\WP_CLI::success( sprintf( 'Canal %d conectado.', $result ) );
	}

	/**
	 * `wp voceador channels list [--format=<format>]`
	 *
	 * @param array $args       Posicionales.
	 * @param array $assoc_args Opciones.
	 */
	public function cmd_channels_list( array $args, array $assoc_args ): void {
		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $this->list_channels(), array( 'id', 'type', 'alias', 'remote_id', 'remote_name', 'status', 'health_checked_at' ) );
	}

	/**
	 * `wp voceador channels delete <id>`
	 *
	 * @param array $args Posicionales.
	 */
	public function cmd_channels_delete( array $args ): void {
		$id = (int) ( $args[0] ?? 0 );
		if ( $id <= 0 || ! $this->delete_channel( $id ) ) {
			\WP_CLI::error( 'No existe ese canal.' );
		}
		\WP_CLI::success( sprintf( 'Canal %d eliminado.', $id ) );
	}

	/**
	 * `wp voceador channels check [<id>] [--all]`
	 *
	 * @param array $args       Posicionales.
	 * @param array $assoc_args Opciones.
	 */
	public function cmd_channels_check( array $args, array $assoc_args ): void {
		if ( ! empty( $assoc_args['all'] ) ) {
			$result = $this->tokens->check_all();
			\WP_CLI::success( sprintf( '%d canal(es) revisado(s), %d pausado(s).', $result['checked'], $result['paused'] ) );

			return;
		}

		$channel_id = isset( $args[0] ) ? absint( $args[0] ) : null;
		$rows       = $this->check_channels( $channel_id );

		if ( ! $rows ) {
			\WP_CLI::warning( 'No hay canales que revisar.' );

			return;
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'channel', 'alias', 'valid', 'message', 'expires_at', 'missing_scopes' ) );
	}

	/**
	 * `wp voceador publish <post_id> [--channel=<id>] [--source=<source>] [--force]`
	 *
	 * @param array $args       Posicionales.
	 * @param array $assoc_args Opciones.
	 */
	public function cmd_publish( array $args, array $assoc_args ): void {
		$post_id = (int) ( $args[0] ?? 0 );
		if ( $post_id <= 0 || ! get_post( $post_id ) instanceof \WP_Post ) {
			\WP_CLI::error( 'Post inexistente.' );
		}

		$channel = $this->resolve_channel( $assoc_args );
		if ( is_wp_error( $channel ) ) {
			\WP_CLI::error( $channel->get_error_message() );
		}

		$force  = isset( $assoc_args['force'] );
		$result = $this->publish( $post_id, $channel, (string) ( $assoc_args['source'] ?? 'cli' ), $force );

		if ( ! $result ) {
			\WP_CLI::warning( 'No hay canales activos para este post.' );
			return;
		}

		$this->print_result_table( $post_id, $result );
	}

	/**
	 * `wp voceador retry <post_id> [--channel=<id>] [--comment] [--force]`
	 *
	 * @param array $args       Posicionales.
	 * @param array $assoc_args Opciones.
	 */
	public function cmd_retry( array $args, array $assoc_args ): void {
		$post_id = (int) ( $args[0] ?? 0 );
		if ( $post_id <= 0 || ! get_post( $post_id ) instanceof \WP_Post ) {
			\WP_CLI::error( 'Post inexistente.' );
		}

		$channel = $this->resolve_channel( $assoc_args );
		if ( is_wp_error( $channel ) ) {
			\WP_CLI::error( $channel->get_error_message() );
		}

		$comment = isset( $assoc_args['comment'] );
		$force   = isset( $assoc_args['force'] );
		$result  = $this->retry( $post_id, $channel, $comment, $force );

		if ( ! $result ) {
			\WP_CLI::warning( 'No hay trabajos que reintentar para este post.' );
			return;
		}

		$this->print_result_table( $post_id, $result );
	}

	/**
	 * `wp voceador status`
	 */
	public function cmd_status(): void {
		$status = $this->status();

		\WP_CLI::line( 'Canales: ' . wp_json_encode( $status['channels'] ) );
		\WP_CLI::line( 'Trabajos: ' . wp_json_encode( $status['jobs'] ) );
		\WP_CLI::line( 'Action Scheduler: ' . ( $status['action_scheduler'] ? 'sí' : 'no' ) );
		\WP_CLI::line( 'Cron disponible: ' . ( $status['cron_available'] ? 'sí' : 'no' ) );
		\WP_CLI::line( 'App de Meta: ' . ( $status['app']['configured'] ? 'configurada (' . $status['app']['app_id'] . ')' : 'sin configurar' ) );
		\WP_CLI::line( 'Redirect URI: ' . $status['app']['redirect_uri'] );

		if ( $status['recent_log'] ) {
			// Defensa en profundidad: el mensaje ya viene redactado de Logger::log(), pero
			// se redacta también aquí antes de imprimirlo.
			$rows = array_map(
				static function ( array $row ): array {
					$row['message'] = Logger::redact_string( $row['message'] );
					return $row;
				},
				$status['recent_log']
			);
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'created_at', 'level', 'event', 'message' ) );
		}
	}

	/**
	 * Tabla channel/status/remote_url/error de un resultado canal => estado; sale con
	 * código 1 si algún canal quedó en failed. Común a cmd_publish() y cmd_retry().
	 *
	 * @param int   $post_id Post.
	 * @param array $result  Canal => estado.
	 */
	private function print_result_table( int $post_id, array $result ): void {
		$rows = array();
		foreach ( $result as $channel_id => $status ) {
			$job    = $this->jobs->find_for_post_and_channel( $post_id, (int) $channel_id );
			$rows[] = array(
				'channel'    => $channel_id,
				'status'     => $status,
				'remote_url' => (string) ( $job->remote_url ?? '' ),
				// Defensa en profundidad: error_message ya viene redactado desde
				// Publisher::handle_error(), pero se redacta también aquí antes de imprimirlo.
				'error'      => Logger::redact_string( (string) ( $job->error_message ?? '' ) ),
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'channel', 'status', 'remote_url', 'error' ) );

		if ( in_array( 'failed', $result, true ) ) {
			\WP_CLI::halt( 1 );
		}
	}
}
