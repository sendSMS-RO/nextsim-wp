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
		// Drop only the catalog sync jobs. Provisioning jobs for paid orders are left in
		// the queue on purpose: they are re-queued on reactivation (see Activator) so a
		// deactivate/reactivate cycle cannot strand a customer's eSIM.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Importer::HOOK_SYNC );
			as_unschedule_all_actions( Importer::HOOK_PAGE );
		}

		flush_rewrite_rules();
	}
}
