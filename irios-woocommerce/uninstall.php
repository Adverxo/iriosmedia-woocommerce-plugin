<?php
/**
 * Uninstall: deletes the settings and the new-version notice data. The orders' `_irios_uid` metas are kept (they are
 * part of the order history and the sync service may need them).
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'woocommerce_irios_settings' );
// New-version notice (Updates): last release checked and notices dismissed by each user
delete_transient( 'irios_wc_latest_release' );
delete_metadata( 'user', 0, 'irios_wc_dismissed_release', '', true );
