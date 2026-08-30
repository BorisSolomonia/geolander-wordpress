<?php
/** Generate WebP versions of theme art. Run: wp eval-file /migration/make-webp.php */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Run via wp eval-file\n" );
}

$jobs = [
	// [file, max width, quality]
	[ get_theme_file_path( 'assets/img/hero.jpg' ), 1920, 72 ],
	[ get_theme_file_path( 'assets/img/cta-bg.jpg' ), 1600, 70 ],
	[ get_theme_file_path( 'assets/img/routes/gergeti_trinity.jpg' ), 800, 72 ],
	[ get_theme_file_path( 'assets/img/routes/sighnaghi.jpg' ), 800, 72 ],
	[ get_theme_file_path( 'assets/img/routes/ushguli.jpg' ), 800, 72 ],
	[ get_theme_file_path( 'assets/img/routes/batumi_boulevard.jpg' ), 800, 72 ],
];

foreach ( $jobs as [ $file, $max_w, $q ] ) {
	if ( ! file_exists( $file ) ) {
		WP_CLI::warning( "missing {$file}" );
		continue;
	}
	$editor = wp_get_image_editor( $file );
	if ( is_wp_error( $editor ) ) {
		WP_CLI::warning( $editor->get_error_message() );
		continue;
	}
	$size = $editor->get_size();
	if ( $size['width'] > $max_w ) {
		$editor->resize( $max_w, null );
	}
	$editor->set_quality( $q );
	$dest  = preg_replace( '/\.jpg$/', '.webp', $file );
	$saved = $editor->save( $dest, 'image/webp' );
	if ( is_wp_error( $saved ) ) {
		WP_CLI::warning( $saved->get_error_message() );
		continue;
	}
	WP_CLI::log( sprintf( '  %s: %dKB (jpg %dKB)', basename( $dest ), filesize( $saved['path'] ) / 1024, filesize( $file ) / 1024 ) );
}
WP_CLI::success( 'WebP done.' );
