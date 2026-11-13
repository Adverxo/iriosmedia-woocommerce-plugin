<?php
// Double of WooCommerce's OrderUtil, in its own file because a namespace cannot be declared halfway through
// doubles.php. The tests set $hpos to choose which order storage the store uses.

namespace Automattic\WooCommerce\Utilities;

/** WooCommerce's own order tables (HPOS) instead of the posts table. */
class OrderUtil {
	public static bool $hpos = false;

	public static function custom_orders_table_usage_is_enabled(): bool {
		return self::$hpos;
	}
}
