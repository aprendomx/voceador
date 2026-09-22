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
	 * Nombre corto de la opción con la marca de tiempo de la última ejecución de la cola.
	 */
	public const OPTION_LAST_RUN = 'cron_last_run';

	/**
	 * Ventana desde la última ejecución dentro de la cual se considera que algo (un cron
	 * del sistema, típicamente) está ejecutando la cola.
	 */
	private const ACTIVITY_WINDOW = 2 * HOUR_IN_SECONDS;

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

		add_action( self::HOOK_RUN, array( $this, 'touch' ), 1 );
		add_action( self::HOOK_COMMENT, array( $this, 'touch' ), 1 );
		add_action( self::HOOK_SWEEP, array( $this, 'touch' ), 1 );
	}

	/**
	 * Registra que la cola acaba de ejecutarse, la ejecute quien la ejecute.
	 *
	 * Permite a cron_available() detectar un cron del sistema activo aunque WP-Cron
	 * esté deshabilitado (DISABLE_WP_CRON), que es la configuración de producción
	 * recomendada.
	 */
	public function touch(): void {
		$option = VOCEADOR_PREFIX . self::OPTION_LAST_RUN;

		if ( false === get_option( $option ) ) {
			add_option( $option, time(), '', false );
			return;
		}

		update_option( $option, time(), false );
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

		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Voceador: WP-Cron está deshabilitado (DISABLE_WP_CRON) y no hay Action Scheduler. Configura un cron del sistema que llame a wp-cron.php o instala un plugin que provea Action Scheduler; si no, las publicaciones no se ejecutarán. Este aviso desaparece solo en cuanto ese cron del sistema se ejecute.', 'voceador' ) . '</p></div>';
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
	 * DISABLE_WP_CRON con un cron del sistema llamando a wp-cron.php es la configuración
	 * de producción recomendada: en ese caso WP-Cron "está deshabilitado" pero la cola sí
	 * se ejecuta, así que además se comprueba si hubo actividad reciente (touch()).
	 *
	 * @return bool
	 */
	public function cron_available(): bool {
		$available = $this->uses_action_scheduler()
			|| ( defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON )
			|| ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON )
			|| ( time() - (int) get_option( VOCEADOR_PREFIX . self::OPTION_LAST_RUN, 0 ) ) <= self::ACTIVITY_WINDOW;

		/**
		 * Permite forzar el resultado de cron_available(), por ejemplo cuando se sabe
		 * por otra vía que un cron del sistema ejecuta la cola.
		 *
		 * @param bool $available Si algo va a ejecutar la cola.
		 */
		return (bool) apply_filters( 'voceador_cron_available', $available );
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
		$this->touch();

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
