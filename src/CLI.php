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
	 */
	public function __construct(
		private ChannelRepository $channels,
		private ChannelRegistry $registry,
		private Publisher $publisher,
		private JobRepository $jobs,
		private Queue $queue,
		private Logger $logger
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
		\WP_CLI::add_command( 'voceador publish', array( $this, 'cmd_publish' ) );
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
	 * `wp voceador channels add-facebook --page-id=<id> --token=<token> [--alias=<alias>]`
	 *
	 * @param array $args       Posicionales.
	 * @param array $assoc_args Opciones.
	 */
	public function cmd_channels_add_facebook( array $args, array $assoc_args ): void {
		$page_id = (string) ( $assoc_args['page-id'] ?? '' );
		$token   = (string) ( $assoc_args['token'] ?? '' );
		if ( '' === $page_id || '' === $token ) {
			\WP_CLI::error( 'Faltan --page-id o --token.' );
		}

		$result = $this->add_facebook_page( $page_id, $token, (string) ( $assoc_args['alias'] ?? '' ) );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
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

		$channel = isset( $assoc_args['channel'] ) ? (int) $assoc_args['channel'] : null;
		$force   = isset( $assoc_args['force'] );
		$result  = $this->publish( $post_id, $channel, (string) ( $assoc_args['source'] ?? 'cli' ), $force );

		if ( ! $result ) {
			\WP_CLI::warning( 'No hay canales activos para este post.' );
			return;
		}

		$rows = array();
		foreach ( $result as $channel_id => $status ) {
			$job    = $this->jobs->find_for_post_and_channel( $post_id, (int) $channel_id );
			$rows[] = array(
				'channel'    => $channel_id,
				'status'     => $status,
				'remote_url' => (string) ( $job->remote_url ?? '' ),
				'error'      => (string) ( $job->error_message ?? '' ),
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'channel', 'status', 'remote_url', 'error' ) );

		if ( in_array( 'failed', $result, true ) ) {
			\WP_CLI::halt( 1 );
		}
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

		if ( $status['recent_log'] ) {
			\WP_CLI\Utils\format_items( 'table', $status['recent_log'], array( 'created_at', 'level', 'event', 'message' ) );
		}
	}
}
