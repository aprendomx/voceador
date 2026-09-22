<?php
/**
 * @package Voceador
 */

use Voceador\ChannelRepository;
use Voceador\Crypto;
use Voceador\JobRepository;
use Voceador\Logger;
use Voceador\Queue;
use Voceador\Rules;
use Voceador\Schema;
use Voceador\Settings;
use Voceador\Trigger;

class TriggerTest extends WP_UnitTestCase {

	private Trigger $trigger;
	private JobRepository $jobs;
	private Settings $settings;
	private int $channel_id;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		delete_option( VOCEADOR_PREFIX . Settings::OPTION );
		_set_cron_array( array() );
		$schema           = new Schema( $wpdb );
		$logger           = new Logger( $wpdb, $schema, 'debug' );
		$this->settings   = new Settings();
		$this->jobs       = new JobRepository( $wpdb, $schema );
		$channels         = new ChannelRepository( $wpdb, $schema, new Crypto( str_repeat( 'k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) ) );
		$this->trigger    = new Trigger( new Rules( $channels, $this->settings ), $this->jobs, new Queue( $this->jobs, $logger ), $this->settings, $logger );
		$this->channel_id = $channels->insert(
			array(
				'type'      => 'facebook_page',
				'alias'     => 'A',
				'remote_id' => '1',
			)
		);
		$this->trigger->register_hooks();
	}

	public function test_first_publish_enqueues_one_job_per_channel_with_delay(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		wp_publish_post( $post_id );

		$job = $this->jobs->find_for_post_and_channel( $post_id, $this->channel_id );
		$this->assertNotNull( $job );
		$this->assertSame( 'auto', $job->source );
		$this->assertEqualsWithDelta( time() + 30, wp_next_scheduled( Queue::HOOK_RUN, array( $job->id ) ), 5 );
		$this->assertNotEmpty( get_post_meta( $post_id, Trigger::META_FIRST_PUBLISHED, true ) );
	}

	public function test_republish_follows_setting_but_never_duplicates_jobs(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		update_post_meta( $post_id, Trigger::META_FIRST_PUBLISHED, time() - 100 );

		$this->assertSame( 0, $this->trigger->on_transition( 'publish', 'draft', get_post( $post_id ) ), 'Por defecto una republicación no encola.' );

		$this->settings->update( array( 'execution' => array( 'publish_republished' => true ) ) );
		$this->assertSame( 1, $this->trigger->on_transition( 'publish', 'draft', get_post( $post_id ) ) );
		$this->assertSame( 0, $this->trigger->on_transition( 'publish', 'draft', get_post( $post_id ) ), 'El trabajo ya existe: nunca se duplica.' );
	}

	public function test_scheduled_posts_follow_setting(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'future',
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
			)
		);

		$this->assertSame( 1, $this->trigger->on_transition( 'publish', 'future', get_post( $post_id ) ) );

		$this->settings->update( array( 'execution' => array( 'publish_scheduled' => false ) ) );
		$other = self::factory()->post->create(
			array(
				'post_status' => 'future',
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
			)
		);
		$this->assertSame( 0, $this->trigger->on_transition( 'publish', 'future', get_post( $other ) ) );
	}

	public function test_ignores_non_publish_transitions_revisions_and_skipped_posts(): void {
		$post = get_post( self::factory()->post->create( array( 'post_status' => 'draft' ) ) );
		$this->assertSame( 0, $this->trigger->on_transition( 'draft', 'auto-draft', $post ) );
		$this->assertSame( 0, $this->trigger->on_transition( 'publish', 'publish', $post ) );

		$revision_id = wp_save_post_revision( $post->ID );
		if ( ! $revision_id ) {
			$revision_id = self::factory()->post->create(
				array(
					'post_type'   => 'revision',
					'post_parent' => $post->ID,
				)
			);
		}
		$revision = get_post( $revision_id );
		$this->assertSame( 0, $this->trigger->on_transition( 'publish', 'draft', $revision ) );

		update_post_meta( $post->ID, Rules::META_SKIP, '1' );
		$this->assertSame( 0, $this->trigger->on_transition( 'publish', 'draft', $post ) );
		$this->assertEmpty( get_post_meta( $post->ID, Trigger::META_FIRST_PUBLISHED, true ), 'Si no hay canales no se marca como publicado en redes.' );
	}

	public function test_enqueue_is_idempotent(): void {
		// No se puede crear el post directamente con post_status => 'publish': eso
		// dispara transition_post_status() de verdad, y $this->trigger ya está enganchado
		// a ese hook desde set_up() (register_hooks()), así que el propio trigger local
		// encolaría el trabajo antes de la primera llamada explícita de abajo, rompiendo
		// la aserción "1". Esto es independiente del Trigger global (desenganchado en
		// tests/bootstrap.php): ocurre aunque solo el trigger local esté enganchado. Se
		// crea como borrador (no dispara ninguna transición a publish) y se marca
		// "publish" solo en memoria para aislar la prueba de ese efecto colateral real.
		$post_id           = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$post              = get_post( $post_id );
		$post->post_status = 'publish';

		$this->assertSame( 1, $this->trigger->enqueue( $post ) );
		$this->assertSame( 0, $this->trigger->enqueue( $post ), 'El trabajo ya existía.' );
	}
}
