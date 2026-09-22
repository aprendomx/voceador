<?php
/**
 * @package Voceador
 */

use Voceador\Channels\Channel;
use Voceador\Settings;
use Voceador\Templates;

class TemplatesTest extends WP_UnitTestCase {

	private Templates $templates;
	private int $post_id;

	public function set_up(): void {
		parent::set_up();
		delete_option( VOCEADOR_PREFIX . Settings::OPTION );
		$this->templates = new Templates( new Settings() );

		$author        = self::factory()->user->create( array( 'display_name' => 'Ana Autora' ) );
		$cat_a         = self::factory()->category->create( array( 'name' => 'Política' ) );
		$cat_b         = self::factory()->category->create( array( 'name' => 'Local' ) );
		$this->post_id = self::factory()->post->create(
			array(
				'post_title'    => 'Título con &amp; entidad',
				'post_excerpt'  => 'Extracto <strong>rico</strong> [shortcode]',
				'post_content'  => 'Contenido',
				'post_author'   => $author,
				'post_category' => array( $cat_a, $cat_b ),
				'post_date'     => '2026-09-22 10:00:00',
			)
		);
	}

	private function channel( array $settings = array() ): Channel {
		return new Channel(
			array(
				'id'       => 1,
				'type'     => 'facebook_page',
				'settings' => $settings,
			)
		);
	}

	public function test_renders_basic_placeholders_clean(): void {
		$post = get_post( $this->post_id );
		$out  = $this->templates->render( "{title}\n{excerpt}\n{permalink}\n{site_name}\n{author}", $post );

		$this->assertStringContainsString( 'Título con & entidad', $out );
		$this->assertStringContainsString( 'Extracto rico', $out );
		$this->assertStringNotContainsString( '<strong>', $out );
		$this->assertStringNotContainsString( '[shortcode]', $out );
		$this->assertStringContainsString( get_permalink( $post ), $out );
		$this->assertStringContainsString( get_bloginfo( 'name' ), $out );
		$this->assertStringContainsString( 'Ana Autora', $out );
	}

	public function test_category_placeholders_and_date(): void {
		$post = get_post( $this->post_id );

		$this->assertSame( 'Local', $this->templates->render( '{category}', $post ), 'Primera categoría por orden alfabético de WP.' );
		$this->assertSame( 'Local, Política', $this->templates->render( '{categories}', $post ) );
		$this->assertSame( '22/09/2026', $this->templates->render( '{date}', $post ) );
	}

	public function test_unknown_placeholders_and_extra(): void {
		$post = get_post( $this->post_id );

		$this->assertSame( '{nope} X', $this->templates->render( '{nope} {custom}', $post, array( 'custom' => 'X' ) ) );
	}

	public function test_social_message_fallback_chain(): void {
		$post = get_post( $this->post_id );
		$this->assertSame( 'Extracto rico', $this->templates->render( '{social_message}', $post ) );

		update_post_meta( $this->post_id, '_voceador_message_fb', 'Texto social' );
		$this->assertSame( 'Texto social', $this->templates->render( '{social_message}', get_post( $this->post_id ) ) );

		( new Settings() )->update( array( 'templates' => array( 'social_fallback' => array( 'title' ) ) ) );
		$this->assertSame( 'Título con & entidad', $this->templates->render( '{social_message}', get_post( $this->post_id ) ) );
	}

	public function test_caption_uses_settings_channel_override_and_filter(): void {
		$post = get_post( $this->post_id );

		$this->assertSame( "Título con & entidad\n\nExtracto rico", $this->templates->caption( $this->channel(), $post ) );

		$channel = $this->channel( array( 'templates' => array( 'caption' => 'FB: {title}' ) ) );
		$this->assertSame( 'FB: Título con & entidad', $this->templates->caption( $channel, $post ) );

		add_filter( 'voceador_caption', static fn( string $text, WP_Post $p, Channel $c ) => $text . ' #f', 10, 3 ); // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $p y $c forman parte de la firma del filtro voceador_caption.
		$this->assertSame( 'FB: Título con & entidad #f', $this->templates->caption( $channel, $post ) );
	}

	public function test_comment_and_max_length(): void {
		$post = get_post( $this->post_id );

		$this->assertSame( get_permalink( $post ), $this->templates->comment( $this->channel(), $post ) );

		$channel = $this->channel(
			array(
				'templates' => array(
					'caption'    => str_repeat( 'palabra ', 20 ),
					'max_length' => 30,
				),
			)
		);
		$caption = $this->templates->caption( $channel, $post );
		$this->assertLessThanOrEqual( 30, mb_strlen( $caption ) );
		$this->assertStringEndsWith( '…', $caption );
		$this->assertStringNotContainsString( 'palabr…', $caption, 'Recorta en un espacio, no a mitad de palabra.' );
	}

	public function test_truncate(): void {
		$this->assertSame( 'corto', Templates::truncate( 'corto', 10 ) );
		$this->assertSame( 'uno dos…', Templates::truncate( 'uno dos tres cuatro', 9 ) );
		$this->assertSame( 'abcdefgh…', Templates::truncate( 'abcdefghijkl', 9 ), 'Sin espacios recorta duro.' );
	}
}
