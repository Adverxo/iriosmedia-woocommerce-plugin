<?php
/**
 * Only during bin/import-store.sh: the WordPress importer treats a post with the same title and date as another one
 * as a duplicate, and variations of the same product usually match on both. Without this, variations are lost.
 */
add_filter(
	'wp_import_existing_post',
	static function ( $post_exists, $post ) {
		return isset( $post['post_type'] ) && 'product_variation' === $post['post_type'] ? 0 : $post_exists;
	},
	10,
	2
);
