<?php
/**
 * Create a repository/deployment-sized fleet import tree from original photos.
 *
 * Run outside WordPress with the production image's PHP/Imagick build:
 * php /migration/optimize-fleet-source.php SOURCE_DIR DESTINATION_DIR
 *
 * Originals are never modified. Images become stripped, max-1920px JPEGs at
 * quality 82; sidecar files are copied unchanged. The importer performs the
 * same final constraint, so using these sources does not change site behavior.
 */

if ( PHP_SAPI !== 'cli' || 3 !== $argc ) {
	fwrite( STDERR, "Usage: php optimize-fleet-source.php SOURCE_DIR DESTINATION_DIR\n" );
	exit( 2 );
}

$source = realpath( $argv[1] );
$target = rtrim( $argv[2], DIRECTORY_SEPARATOR );
if ( false === $source || ! is_dir( $source ) ) {
	fwrite( STDERR, "Source directory does not exist.\n" );
	exit( 2 );
}
if ( file_exists( $target ) ) {
	fwrite( STDERR, "Destination already exists; refusing to overwrite it.\n" );
	exit( 2 );
}
if ( ! extension_loaded( 'imagick' ) ) {
	fwrite( STDERR, "The Imagick PHP extension is required.\n" );
	exit( 2 );
}

mkdir( $target, 0755, true );
Imagick::setResourceLimit( Imagick::RESOURCETYPE_THREAD, 1 );
Imagick::setResourceLimit( Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024 );
Imagick::setResourceLimit( Imagick::RESOURCETYPE_MAP, 512 * 1024 * 1024 );

$images = 0;
$files  = 0;
$input  = 0;
$output = 0;
$walk   = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
	RecursiveIteratorIterator::SELF_FIRST
);

foreach ( $walk as $item ) {
	$relative = substr( $item->getPathname(), strlen( $source ) + 1 );
	if ( $item->isDir() ) {
		mkdir( $target . DIRECTORY_SEPARATOR . $relative, 0755, true );
		continue;
	}

	$input += $item->getSize();
	if ( ! preg_match( '/\.(?:jpe?g|png|webp)$/i', $item->getFilename() ) ) {
		$destination = $target . DIRECTORY_SEPARATOR . $relative;
		copy( $item->getPathname(), $destination );
		$output += filesize( $destination );
		$files++;
		continue;
	}

	$relative_dir = dirname( $relative );
	$name         = pathinfo( $relative, PATHINFO_FILENAME ) . '.jpg';
	$destination  = $target . DIRECTORY_SEPARATOR
		. ( '.' === $relative_dir ? '' : $relative_dir . DIRECTORY_SEPARATOR )
		. $name;

	$image = new Imagick();
	$image->readImage( $item->getPathname() );
	$image->setIteratorIndex( 0 );
	if ( method_exists( $image, 'autoOrient' ) ) {
		$image->autoOrient();
	} elseif ( method_exists( $image, 'autoOrientImage' ) ) {
		$image->autoOrientImage();
	}
	$image->thumbnailImage( 1920, 1920, true );
	$image->setImageBackgroundColor( 'white' );
	$image = $image->mergeImageLayers( Imagick::LAYERMETHOD_FLATTEN );
	$image->stripImage();
	$image->setImageFormat( 'jpeg' );
	$image->setImageCompression( Imagick::COMPRESSION_JPEG );
	$image->setImageCompressionQuality( 82 );
	$image->setSamplingFactors( [ '2x2', '1x1', '1x1' ] );
	if ( ! $image->writeImage( $destination ) ) {
		throw new RuntimeException( 'Failed to write ' . $destination );
	}
	$image->clear();
	$image->destroy();
	$output += filesize( $destination );
	$images++;
}

printf(
	"Optimized %d images and copied %d sidecars: %.1f MiB -> %.1f MiB\n",
	$images,
	$files,
	$input / 1048576,
	$output / 1048576
);
