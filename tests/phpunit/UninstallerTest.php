<?php
/**
 * @package Voceador
 */

use Voceador\Installer;
use Voceador\Schema;
use Voceador\Uninstaller;

class UninstallerTest extends WP_UnitTestCase {

	public function tear_down(): void {
		global $wpdb;

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		delete_option( VOCEADOR_PREFIX . 'db_version' );
		( new Installer( new Schema( $wpdb ) ) )->maybe_install();

		parent::tear_down();
	}

	private function table_exists( string $name ): bool {
		global $wpdb;
		$table = ( new Schema( $wpdb ) )->table( $name );

		$suppress = $wpdb->suppress_errors( true );
		$columns  = $wpdb->get_col( 'DESCRIBE ' . $table, 0 );
		$wpdb->suppress_errors( $suppress );

		return ! empty( $columns );
	}

	public function test_removes_tables(): void {
		// El DDL (DROP TABLE) hace un commit implícito en MySQL/MariaDB y rompe el aislamiento
		// transaccional de WP_UnitTestCase; nada debe escribirse antes de clean_site() aquí.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$this->assertTrue( $this->table_exists( 'jobs' ) );

		Uninstaller::clean_site();

		foreach ( Schema::TABLES as $name ) {
			$this->assertFalse( $this->table_exists( $name ), "La tabla {$name} sigue existiendo." );
		}
	}

	public function test_removes_options_and_transients(): void {
		update_option( VOCEADOR_PREFIX . 'settings', array( 'rules' => array() ), false );
		update_option( VOCEADOR_PREFIX . 'app', array( 'app_id' => '1' ), false );
		add_option( VOCEADOR_PREFIX . 'crypto_seed', '', '', false );
		add_option( VOCEADOR_PREFIX . 'cron_last_run', time(), '', false );
		set_transient( VOCEADOR_PREFIX . 'lock_job_5', 1, 60 );

		Uninstaller::clean_site();
		// run() vacía la caché de objetos al terminar; clean_site() ya no lo hace (se
		// mueve a run() para no repetirlo por sitio en Multisite), así que el borrado
		// directo por SQL de los transients necesita el mismo flush aquí para que
		// get_transient() no lea un valor de caché obsoleto.
		wp_cache_flush();

		foreach ( Uninstaller::OPTIONS as $option ) {
			$this->assertFalse( get_option( VOCEADOR_PREFIX . $option ), "La opción {$option} sigue existiendo." );
		}
		$this->assertFalse( get_transient( VOCEADOR_PREFIX . 'lock_job_5' ) );
	}

	public function test_removes_capability_from_every_role(): void {
		get_role( 'editor' )->add_cap( Installer::CAPABILITY );

		Uninstaller::clean_site();

		$this->assertFalse( get_role( 'administrator' )->has_cap( Installer::CAPABILITY ) );
		$this->assertFalse( get_role( 'editor' )->has_cap( Installer::CAPABILITY ) );
	}

	public function test_clears_cron_hooks(): void {
		wp_schedule_event( time() + 3600, 'daily', VOCEADOR_PREFIX . 'check_tokens' );

		Uninstaller::clean_site();

		$this->assertFalse( wp_next_scheduled( VOCEADOR_PREFIX . 'check_tokens' ) );
	}

	public function test_clears_run_job_and_run_comment_cron_hooks(): void {
		wp_schedule_single_event( time() + 60, VOCEADOR_PREFIX . 'run_job', array( 1 ) );
		wp_schedule_single_event( time() + 60, VOCEADOR_PREFIX . 'run_comment', array( 1 ) );

		Uninstaller::clean_site();

		$this->assertFalse( wp_next_scheduled( VOCEADOR_PREFIX . 'run_job', array( 1 ) ) );
		$this->assertFalse( wp_next_scheduled( VOCEADOR_PREFIX . 'run_comment', array( 1 ) ) );
	}

	public function test_keeps_post_meta_by_default(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_voceador_message_fb', 'Hola' );

		Uninstaller::clean_site();

		$this->assertSame( 'Hola', get_post_meta( $post_id, '_voceador_message_fb', true ) );
	}

	public function test_removes_post_meta_when_configured(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_voceador_message_fb', 'Hola' );
		update_post_meta( $post_id, '_voceador_ig_cache', array( 'file' => 'x.jpg' ) );
		update_post_meta( $post_id, '_otro_meta', 'se queda' );
		update_option(
			VOCEADOR_PREFIX . 'settings',
			array( 'uninstall' => array( 'delete_meta' => true ) ),
			false
		);

		Uninstaller::clean_site();

		$this->assertSame( '', get_post_meta( $post_id, '_voceador_message_fb', true ) );
		$this->assertSame( '', get_post_meta( $post_id, '_voceador_ig_cache', true ) );
		$this->assertSame( 'se queda', get_post_meta( $post_id, '_otro_meta', true ) );
	}

	public function test_removes_generated_images_directory(): void {
		$dir = wp_upload_dir()['basedir'] . '/' . Uninstaller::UPLOADS_SUBDIR;
		wp_mkdir_p( $dir . '/2026/09' );
		file_put_contents( $dir . '/2026/09/nota.jpg', 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		Uninstaller::clean_site();

		$this->assertDirectoryDoesNotExist( $dir );
	}
}
