<?php
/**
 * Runs on plugin deactivation.
 *
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo;

use NextSIM\Woo\Import\Importer;

defined( 'ABSPATH' ) || exit;

final class Deactivator {

	public static function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), Importer::GROUP );
		}

		flush_rewrite_rules();
	}
}
