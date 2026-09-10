<?php
/**
 * Renders a compact, device-agnostic "how to install your eSIM" guide, shown next
 * to the QR code on the order page and in the customer's account. Reduces the most
 * common support question ("I got a QR — now what?").
 *
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo\Frontend;

defined( 'ABSPATH' ) || exit;

final class Install_Guide {

	/**
	 * A collapsible install guide. Static and self-contained so it can be dropped in
	 * wherever a QR is shown.
	 */
	public static function html(): string {
		$intro = esc_html__( 'Scan the QR code with the phone that will use the eSIM, or add it manually with the activation code above.', 'nextsim-woo' );

		$ios = array(
			esc_html__( 'Open Settings > Cellular (or Mobile Data).', 'nextsim-woo' ),
			esc_html__( 'Tap Add eSIM, then Use QR Code, and scan the code above.', 'nextsim-woo' ),
			esc_html__( 'Follow the prompts to finish. For data abroad, turn on Data Roaming for this eSIM.', 'nextsim-woo' ),
		);

		$android = array(
			esc_html__( 'Open Settings > Network & internet > SIMs.', 'nextsim-woo' ),
			esc_html__( 'Tap Add eSIM (or the + next to SIMs), then Scan the QR code above.', 'nextsim-woo' ),
			esc_html__( 'Follow the prompts to finish. For data abroad, turn on Roaming for this eSIM.', 'nextsim-woo' ),
		);

		$out  = '<details class="nextsim-install-guide"><summary>' . esc_html__( 'How to install your eSIM', 'nextsim-woo' ) . '</summary>';
		$out .= '<p>' . $intro . '</p>';
		$out .= '<div class="nextsim-guide-cols">';
		$out .= self::steps( __( 'iPhone (iOS)', 'nextsim-woo' ), $ios );
		$out .= self::steps( __( 'Android', 'nextsim-woo' ), $android );
		$out .= '</div>';
		$out .= '<p class="nextsim-guide-note">' . esc_html__( 'Tip: install over Wi-Fi before you travel. The data allowance usually starts when the eSIM first connects to a network abroad.', 'nextsim-woo' ) . '</p>';
		$out .= '</details>';

		return $out;
	}

	/**
	 * @param array<int, string> $steps Pre-escaped step strings.
	 */
	private static function steps( string $heading, array $steps ): string {
		$out = '<div class="nextsim-guide-col"><h5>' . esc_html( $heading ) . '</h5><ol>';
		foreach ( $steps as $step ) {
			$out .= '<li>' . $step . '</li>';
		}

		return $out . '</ol></div>';
	}
}
