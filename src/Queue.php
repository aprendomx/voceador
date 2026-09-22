<?php
/**
 * Cola de trabajos.
 *
 * @package Voceador
 */

namespace Voceador;

/**
 * Programa la ejecución de trabajos y comentarios, y barre los perdidos.
 *
 * Usa Action Scheduler si otro plugin lo provee; si no, WP-Cron.
 */
final class Queue implements Registrable {

	/**
	 * Hook que ejecuta un trabajo. Recibe el id del trabajo.
	 */
	public const HOOK_RUN = 'voceador_run_job';

	/**
	 * Hook que publica el comentario de un trabajo. Recibe el id del trabajo.
	 */
	public const HOOK_COMMENT = 'voceador_run_comment';

	/**
	 * Hook del barrido periódico.
	 */
	public const HOOK_SWEEP = 'voceador_sweep';

	/**
	 * Grupo de Action Scheduler.
	 */
	private const AS_GROUP = 'voceador';

	/**
	 * Segundos en running tras los que un trabajo se considera perdido.
	 */
	private const STALE_AFTER = 900;

	/**
	 * Constructor.
	 *
	 * @param JobRepository $jobs   Trabajos.
	 * @param Logger        $logger Log.
	 */
	public function __construct(
		private JobRepository $jobs,
		private Logger $logger
	) {}

	/**
	 * Engancha el barrido y avisa si no hay cron.
	 */
	public function register_hooks(): void {
		add_action( self::HOOK_SWEEP, array( $this, 'sweep' ) );
		add_action( 'init', array( $this, 'ensure_sweep_scheduled' ) );
		add_action( 'admin_notices', array( $this, 'maybe_notice_no_cron' ) );
	}

	/**
	 * Programa el barrido horario si no existe.
	 */
	public function ensure_sweep_scheduled(): void {
		if ( ! wp_next_scheduled( self::HOOK_SWEEP ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOOK_SWEEP );
		}
	}

	/**
	 * Aviso en el admin cuando ni WP-Cron ni Action Scheduler pueden ejecutar la cola.
	 */
	public function maybe_notice_no_cron(): void {
		if ( $this->cron_available() || ! current_user_can( Installer::CAPABILITY ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Voceador: WP-Cron está deshabilitado (DISABLE_WP_CRON) y no hay Action Scheduler. Configura un cron del sistema que llame a wp-cron.php o instala un plugin que provea Action Scheduler; si no, las publicaciones no se ejecutarán.', 'voceador' ) . '</p></div>';
	}

	/**
	 * Indica si Action Scheduler está disponible.
	 *
	 * @return bool
	 */
	public function uses_action_scheduler(): bool {
		return function_exists( 'as_schedule_single_action' ) && function_exists( 'as_unschedule_action' );
	}

	/**
	 * Indica si algo va a ejecutar la cola.
	 *
	 * @return bool
	 */
	public function cron_available(): bool {
		if ( $this->uses_action_scheduler() ) {
			return true;
		}
		if ( defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON ) {
			return true;
		}

		return ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );
	}

	/**
	 * Programa la ejecución de un trabajo.
	 *
	 * @param int $job_id        Id.
	 * @param int $delay_seconds Retraso.
	 */
	public function schedule_job( int $job_id, int $delay_seconds ): void {
		$this->schedule( self::HOOK_RUN, $job_id, $delay_seconds );
	}

	/**
	 * Programa el comentario de un trabajo.
	 *
	 * @param int $job_id        Id.
	 * @param int $delay_seconds Retraso.
	 */
	public function schedule_comment( int $job_id, int $delay_seconds ): void {
		$this->schedule( self::HOOK_COMMENT, $job_id, $delay_seconds );
	}

	/**
	 * Reprograma trabajos vencidos y marca como no verificados los atascados.
	 *
	 * @return array{pending:int,rate_limited:int,stale:int}
	 */
	public function sweep(): array {
		$counts = array(
			'pending'      => 0,
			'rate_limited' => 0,
			'stale'        => 0,
		);

		foreach ( $this->jobs->due() as $job ) {
			$this->schedule_job( $job->id, 0 );
			++$counts['pending'];
		}

		foreach ( $this->jobs->due_rate_limited() as $job ) {
			$this->schedule_job( $job->id, 0 );
			++$counts['rate_limited'];
		}

		foreach ( $this->jobs->stale_running( self::STALE_AFTER ) as $job ) {
			$this->jobs->mark_unverified( $job->id, __( 'El proceso se interrumpió durante la publicación. Verifica en la red antes de reintentar.', 'voceador' ) );
			$this->logger->warning(
				'job_stale',
				'Trabajo atascado en running marcado como no verificado',
				array(
					'job_id'     => $job->id,
					'post_id'    => $job->post_id,
					'channel_id' => $job->channel_id,
				)
			);
			++$counts['stale'];
		}

		if ( array_sum( $counts ) > 0 ) {
			$this->logger->info( 'sweep', 'Barrido de la cola', $counts );
		}

		return $counts;
	}

	/**
	 * Programa (o reprograma) un hook para un trabajo.
	 *
	 * @param string $hook          Hook.
	 * @param int    $job_id        Id.
	 * @param int    $delay_seconds Retraso.
	 */
	private function schedule( string $hook, int $job_id, int $delay_seconds ): void {
		/**
		 * Permite ajustar el retraso de un trabajo en la cola.
		 *
		 * @param int    $delay_seconds Retraso.
		 * @param int    $job_id        Id del trabajo.
		 * @param string $hook          Hook a ejecutar.
		 */
		$delay = max( 0, (int) apply_filters( 'voceador_queue_delay', $delay_seconds, $job_id, $hook ) );
		$when  = time() + $delay;
		$args  = array( $job_id );

		if ( $this->uses_action_scheduler() ) {
			as_unschedule_action( $hook, $args, self::AS_GROUP );
			as_schedule_single_action( $when, $hook, $args, self::AS_GROUP );
			return;
		}

		wp_clear_scheduled_hook( $hook, $args );
		wp_schedule_single_event( $when, $hook, $args );
	}
}
