<?php
/**
 * @package Voceador
 */

use Voceador\JobRepository;
use Voceador\Logger;
use Voceador\Queue;
use Voceador\Schema;

class QueueTest extends WP_UnitTestCase {

	private Queue $queue;
	private JobRepository $jobs;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->jobs  = new JobRepository( $wpdb, new Schema( $wpdb ) );
		$this->queue = new Queue( $this->jobs, new Logger( $wpdb, new Schema( $wpdb ), 'debug' ) );
		_set_cron_array( array() );
	}

	public function test_hooks_constants(): void {
		$this->assertSame( 'voceador_run_job', Queue::HOOK_RUN );
		$this->assertSame( 'voceador_run_comment', Queue::HOOK_COMMENT );
		$this->assertSame( 'voceador_sweep', Queue::HOOK_SWEEP );
	}

	public function test_schedule_job_with_wp_cron(): void {
		$this->assertFalse( $this->queue->uses_action_scheduler(), 'wp-env no trae Action Scheduler.' );

		$this->queue->schedule_job( 42, 30 );

		$ts = wp_next_scheduled( Queue::HOOK_RUN, array( 42 ) );
		$this->assertNotFalse( $ts );
		$this->assertEqualsWithDelta( time() + 30, $ts, 5 );
	}

	public function test_reschedule_replaces_previous_event(): void {
		$this->queue->schedule_job( 42, 300 );
		$this->queue->schedule_job( 42, 10 );

		$this->assertEqualsWithDelta( time() + 10, wp_next_scheduled( Queue::HOOK_RUN, array( 42 ) ), 5 );
		$this->assertCount( 1, array_filter( array_map( static fn( $events ) => isset( $events[ Queue::HOOK_RUN ] ), _get_cron_array() ) ), 'Un solo evento para el trabajo.' );
	}

	public function test_schedule_comment_and_delay_filter(): void {
		add_filter( 'voceador_queue_delay', static fn( int $delay, int $job_id, string $hook ) => Queue::HOOK_COMMENT === $hook ? 5 : $delay, 10, 3 );

		$this->queue->schedule_comment( 7, 60 );

		$this->assertEqualsWithDelta( time() + 5, wp_next_scheduled( Queue::HOOK_COMMENT, array( 7 ) ), 5 );
	}

	public function test_cron_available_by_default(): void {
		// El bootstrap de PHPUnit de WordPress core define DISABLE_WP_CRON en true de
		// forma incondicional (includes/bootstrap.php e includes/install.php: el cron
		// real haría una petición HTTP que siempre falla en CLI). Sin Action Scheduler
		// ni ALTERNATE_WP_CRON, cron_available() refleja correctamente ese estado.
		$this->assertFalse( $this->queue->cron_available() );
	}

	public function test_register_hooks_schedules_sweep(): void {
		$this->queue->register_hooks();
		do_action( 'init' );

		$this->assertNotFalse( wp_next_scheduled( Queue::HOOK_SWEEP ) );
		$this->assertSame( 10, has_action( Queue::HOOK_SWEEP, array( $this->queue, 'sweep' ) ) );
	}

	public function test_sweep_reschedules_due_and_marks_stale(): void {
		global $wpdb;
		$pending = $this->jobs->create_if_absent( 50, 1, 'auto', '2020-01-01 00:00:00' );
		$limited = $this->jobs->create_if_absent( 50, 2 );
		$this->jobs->mark_rate_limited( $limited, '2020-01-01 00:00:00', 'x' );
		$stale = $this->jobs->create_if_absent( 50, 3 );
		$this->jobs->claim( $stale );
		$wpdb->update( ( new Schema( $wpdb ) )->table( 'jobs' ), array( 'updated_at' => '2020-01-01 00:00:00' ), array( 'id' => $stale ) );
		$fresh = $this->jobs->create_if_absent( 50, 4 );
		$this->jobs->claim( $fresh );

		$counts = $this->queue->sweep();

		$this->assertSame(
			array(
				'pending'      => 1,
				'rate_limited' => 1,
				'stale'        => 1,
			),
			$counts
		);
		$this->assertNotFalse( wp_next_scheduled( Queue::HOOK_RUN, array( $pending ) ) );
		$this->assertNotFalse( wp_next_scheduled( Queue::HOOK_RUN, array( $limited ) ) );
		$this->assertSame( 'unverified', $this->jobs->find( $stale )->error_code );
		$this->assertSame( 'running', $this->jobs->find( $fresh )->status );
	}
}
