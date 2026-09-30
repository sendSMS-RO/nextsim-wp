<?php
/**
 * Renders an eSIM LPA activation string as a QR code: an SVG data URI for the
 * browser, or a PNG for email (mail clients block data-URI and SVG images).
 *
 * SVG is pure-PHP (no Imagick/GD needed) and renders in browsers on the order page;
 * the PNG needs the GD extension. Both fall back to an empty string when the QR
 * library (or GD) is unavailable, so callers can degrade gracefully to showing the
 * activation code and install links.
 *
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo\Fulfilment;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

defined( 'ABSPATH' ) || exit;

class Qr_Renderer {

	public function svg_data_uri( string $lpa, int $size = 220 ): string {
		if ( '' === $lpa || ! class_exists( Writer::class ) ) {
			return '';
		}

		try {
			$renderer = new ImageRenderer( new RendererStyle( $size ), new SvgImageBackEnd() );
			$svg      = ( new Writer( $renderer ) )->writeString( $lpa );
		} catch ( \Throwable $e ) {
			return '';
		}

		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	/**
	 * The QR code as PNG binary data, black on white with the standard 4-module quiet
	 * zone, at least $min_size pixels wide.
	 */
	public function png( string $lpa, int $min_size = 330 ): string {
		if ( '' === $lpa || ! class_exists( Encoder::class ) || ! function_exists( 'imagecreate' ) || ! function_exists( 'imagepng' ) ) {
			return '';
		}

		try {
			$matrix  = Encoder::encode( $lpa, ErrorCorrectionLevel::M() )->getMatrix();
			$modules = $matrix->getWidth();
			$quiet   = 4;
			$scale   = max( 1, (int) ceil( $min_size / ( $modules + 2 * $quiet ) ) );
			$size    = ( $modules + 2 * $quiet ) * $scale;

			$image = imagecreate( $size, $size );
			if ( false === $image ) {
				return '';
			}

			// The first allocated colour is the background.
			imagecolorallocate( $image, 255, 255, 255 );
			$black = (int) imagecolorallocate( $image, 0, 0, 0 );

			for ( $y = 0; $y < $modules; $y++ ) {
				for ( $x = 0; $x < $modules; $x++ ) {
					if ( 1 === $matrix->get( $x, $y ) ) {
						$left = ( $x + $quiet ) * $scale;
						$top  = ( $y + $quiet ) * $scale;
						imagefilledrectangle( $image, $left, $top, $left + $scale - 1, $top + $scale - 1, $black );
					}
				}
			}

			ob_start();
			imagepng( $image );
			$png = (string) ob_get_clean();
		} catch ( \Throwable $e ) {
			return '';
		}

		return $png;
	}
}
