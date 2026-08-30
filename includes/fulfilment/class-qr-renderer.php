<?php
/**
 * Renders an eSIM LPA activation string as a QR code (SVG data URI).
 *
 * SVG is pure-PHP (no Imagick/GD needed) and renders in browsers on the order page.
 * Falls back to an empty string if the QR library is unavailable, so callers can
 * degrade gracefully to showing the activation code and install links.
 *
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo\Fulfilment;

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
}
