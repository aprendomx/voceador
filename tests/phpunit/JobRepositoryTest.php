<?php
/**
 * @package Voceador
 */

use Voceador\Job;
use Voceador\JobRepository;
use Voceador\Schema;

class JobRepositoryTest extends WP_UnitTestCase {

	private JobRepository $repo;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->repo = new JobRepository( $wpdb, new Schema( $wpdb ) );
	}

	public function test_create_if_absent_is_idempotent(): void {
		$id = $this->repo->create_if_absent( 10, 1 );
		$this->assertIsInt( $id );
		$this->assertNull( $this->repo->create_if_absent( 10, 1 ) );
		$this->assertIsInt( $this->repo->create_if_absent( 10, 2 ) );

		$job = $this->repo->find( $id );
		$this->assertInstanceOf( Job::class, $job );
		$this->assertSame( 'pending', $job->status );
		$this->assertSame( 'none', $job->comment_status );
		$this->assertSame( 'auto', $job->source );
		$this->assertSame( 0, $job->attempts );
		$this->assertNull( $job->scheduled_at );
	}

	public function test_create_with_source_and_schedule(): void {
		$id  = $this->repo->create_if_absent( 11, 1, 'manual', '2026-01-01 00:00:00' );
		$job = $this->repo->find( $id );

		$this->assertSame( 'manual', $job->source );
		$this->assertSame( '2026-01-01 00:00:00', $job->scheduled_at );
	}

	public function test_find_helpers(): void {
		$a = $this->repo->create_if_absent( 12, 1 );
		$this->repo->create_if_absent( 12, 2 );
		$this->repo->create_if_absent( 13, 1 );

		$this->assertCount( 2, $this->repo->find_for_post( 12 ) );
		$this->assertSame( $a, $this->repo->find_for_post_and_channel( 12, 1 )->id );
		$this->assertNull( $this->repo->find_for_post_and_channel( 12, 9 ) );
		$this->assertNull( $this->repo->find( 999999 ) );
	}

	public function test_claim_is_atomic_and_counts_attempts(): void {
		$id = $this->repo->create_if_absent( 14, 1 );

		$this->assertTrue( $this->repo->claim( $id ) );
		$this->assertFalse( $this->repo->claim( $id ), 'Un trabajo en running no se puede reclamar.' );

		$job = $this->repo->find( $id );
		$this->assertSame( 'running', $job->status );
		$this->assertSame( 1, $job->attempts );

		$this->repo->mark_failed( $id, 'transient', 'timeout' );
		$this->assertTrue( $this->repo->claim( $id ), 'Un trabajo fallido se puede reclamar.' );
		$this->assertSame( 2, $this->repo->find( $id )->attempts );
	}

	public function test_mark_published_and_comment_lifecycle(): void {
		$id = $this->repo->create_if_absent( 15, 1 );
		$this->repo->claim( $id );

		$this->assertTrue( $this->repo->mark_published( $id, '1001_2002', 'https://facebook.com/1001_2002', true ) );
		$job = $this->repo->find( $id );
		$this->assertTrue( $job->is_published() );
		$this->assertSame( '1001_2002', $job->remote_id );
		$this->assertSame( 'pending', $job->comment_status );
		$this->assertNotNull( $job->published_at );
		$this->assertNull( $job->error_code );

		$this->assertTrue( $this->repo->mark_comment_failed( $id, 'spam' ) );
		$job = $this->repo->find( $id );
		$this->assertSame( 'failed', $job->comment_status );
		$this->assertSame( 1, $job->comment_attempts );
		$this->assertSame( 'spam', $job->error_message );
		$this->assertSame( 'published', $job->status, 'El fallo del comentario no toca el estado principal.' );

		$this->assertTrue( $this->repo->mark_comment_done( $id, '1001_2002_3003' ) );
		$job = $this->repo->find( $id );
		$this->assertSame( 'done', $job->comment_status );
		$this->assertSame( '1001_2002_3003', $job->remote_comment_id );
	}

	public function test_published_without_comment(): void {
		$id = $this->repo->create_if_absent( 16, 1 );
		$this->repo->mark_published( $id, 'r', '', false );
		$this->assertSame( 'none', $this->repo->find( $id )->comment_status );
	}

	public function test_failed_rate_limited_release_and_skipped(): void {
		$id = $this->repo->create_if_absent( 17, 1 );

		$this->assertTrue( $this->repo->mark_failed( $id, 'auth', 'Token expirado' ) );
		$job = $this->repo->find( $id );
		$this->assertSame( 'failed', $job->status );
		$this->assertSame( 'auth', $job->error_code );
		$this->assertSame( 'Token expirado', $job->error_message );

		$this->assertTrue( $this->repo->mark_rate_limited( $id, '2030-01-01 00:00:00', 'Sin cupo' ) );
		$job = $this->repo->find( $id );
		$this->assertSame( 'rate_limited', $job->status );
		$this->assertSame( '2030-01-01 00:00:00', $job->scheduled_at );

		$this->assertTrue( $this->repo->release( $id, '2030-02-01 00:00:00' ) );
		$job = $this->repo->find( $id );
		$this->assertSame( 'pending', $job->status );
		$this->assertSame( '2030-02-01 00:00:00', $job->scheduled_at );
		$this->assertSame( 'Sin cupo', $job->error_message, 'release conserva el último error para diagnóstico.' );

		$this->assertTrue( $this->repo->mark_skipped( $id, 'Sin imagen destacada' ) );
		$this->assertSame( 'skipped', $this->repo->find( $id )->status );
	}

	public function test_due_returns_pending_jobs_in_order(): void {
		$late  = $this->repo->create_if_absent( 18, 1, 'auto', '2020-01-02 00:00:00' );
		$early = $this->repo->create_if_absent( 18, 2, 'auto', '2020-01-01 00:00:00' );
		$now   = $this->repo->create_if_absent( 18, 3 );
		$this->repo->create_if_absent( 18, 4, 'auto', '2099-01-01 00:00:00' );
		$claimed = $this->repo->create_if_absent( 18, 5 );
		$this->repo->claim( $claimed );

		$ids = array_map( static fn( Job $j ) => $j->id, $this->repo->due() );

		$this->assertSame( array( $now, $early, $late ), $ids );
		$this->assertCount( 2, $this->repo->due( 2 ) );
	}

	public function test_count_by_status(): void {
		$a = $this->repo->create_if_absent( 19, 1 );
		$this->repo->create_if_absent( 19, 2 );
		$this->repo->mark_failed( $a, 'fatal', 'x' );

		$counts = $this->repo->count_by_status();

		$this->assertSame( 1, $counts['pending'] );
		$this->assertSame( 1, $counts['failed'] );
		$this->assertSame( 0, $counts['published'] );
	}

	public function test_error_messages_are_redacted(): void {
		$id     = $this->repo->create_if_absent( 21, 1 );
		$secret = 'Bad token EAA' . str_repeat( 'Ab1', 10 );

		$this->repo->mark_failed( $id, 'auth', $secret );
		$message = $this->repo->find( $id )->error_message;
		$this->assertStringNotContainsString( 'EAAAb1', $message );
		$this->assertStringContainsString( '[redactado]', $message );

		$this->repo->mark_comment_failed( $id, $secret );
		$message = $this->repo->find( $id )->error_message;
		$this->assertStringNotContainsString( 'EAAAb1', $message );
		$this->assertStringContainsString( '[redactado]', $message );
	}

	public function test_transitions_are_idempotent(): void {
		$id = $this->repo->create_if_absent( 20, 1 );

		$this->assertTrue( $this->repo->mark_failed( $id, 'auth', 'Token expirado' ) );
		$this->assertTrue( $this->repo->mark_failed( $id, 'auth', 'Token expirado' ) );

		$this->assertTrue( $this->repo->mark_comment_done( $id, 'c1' ) );
		$this->assertTrue( $this->repo->mark_comment_done( $id, 'c1' ) );

		$job = $this->repo->find( $id );
		$this->assertSame( 'failed', $job->status );
		$this->assertSame( 'c1', $job->remote_comment_id );
	}

	public function test_claim_from_rate_limited_does_not_consume_attempt(): void {
		$id = $this->repo->create_if_absent( 30, 1 );
		$this->repo->claim( $id );
		$this->assertSame( 1, $this->repo->find( $id )->attempts );

		$this->repo->mark_rate_limited( $id, '2020-01-01 00:00:00', 'Sin cupo' );
		$this->assertTrue( $this->repo->claim( $id ) );
		$this->assertSame( 1, $this->repo->find( $id )->attempts, 'Reanudar tras límite de uso no cuenta como intento.' );

		$this->repo->mark_failed( $id, 'transient', 'x' );
		$this->assertTrue( $this->repo->claim( $id ) );
		$this->assertSame( 2, $this->repo->find( $id )->attempts );
	}

	public function test_due_rate_limited(): void {
		$past   = $this->repo->create_if_absent( 31, 1 );
		$future = $this->repo->create_if_absent( 31, 2 );
		$this->repo->mark_rate_limited( $past, '2020-01-01 00:00:00', 'x' );
		$this->repo->mark_rate_limited( $future, '2099-01-01 00:00:00', 'x' );

		$ids = array_map( static fn( Job $j ) => $j->id, $this->repo->due_rate_limited() );

		$this->assertSame( array( $past ), $ids );
	}

	public function test_stale_running(): void {
		global $wpdb;
		$old = $this->repo->create_if_absent( 32, 1 );
		$new = $this->repo->create_if_absent( 32, 2 );
		$this->repo->claim( $old );
		$this->repo->claim( $new );
		$wpdb->update( ( new Schema( $wpdb ) )->table( 'jobs' ), array( 'updated_at' => '2020-01-01 00:00:00' ), array( 'id' => $old ) );

		$ids = array_map( static fn( Job $j ) => $j->id, $this->repo->stale_running( 900 ) );

		$this->assertSame( array( $old ), $ids );
	}

	public function test_retry_comment_only_from_failed_on_published_jobs(): void {
		$id = $this->repo->create_if_absent( 33, 1 );
		$this->repo->mark_published( $id, 'r', '', true );
		$this->repo->mark_comment_failed( $id, 'spam' );

		$this->assertTrue( $this->repo->retry_comment( $id ) );
		$this->assertSame( 'pending', $this->repo->find( $id )->comment_status );
		$this->assertFalse( $this->repo->retry_comment( $id ), 'Ya está pendiente.' );

		$other = $this->repo->create_if_absent( 33, 2 );
		$this->repo->mark_comment_failed( $other, 'x' );
		$this->assertFalse( $this->repo->retry_comment( $other ), 'No está publicado.' );
	}

	public function test_mark_unverified(): void {
		$id = $this->repo->create_if_absent( 34, 1 );
		$this->repo->claim( $id );

		$this->assertTrue( $this->repo->mark_unverified( $id, 'Timeout tras enviar EAA' . str_repeat( 'Ab1', 10 ) ) );
		$job = $this->repo->find( $id );
		$this->assertSame( 'failed', $job->status );
		$this->assertSame( 'unverified', $job->error_code );
		$this->assertStringNotContainsString( 'EAAAb1', $job->error_message );
	}
}
