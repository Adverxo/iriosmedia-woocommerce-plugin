<?php
// Tests without WordPress: pure logic is tested directly; code that calls WordPress/WooCommerce, with Brain Monkey
// (tests/WordPressTestCase.php) and the doubles in tests/doubles.php.
define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
// The plugin's main file: the code reads its folder to find assets/ (Push serves the service worker from there)
define( 'IRIOS_WC_FILE', dirname( __DIR__ ) . '/irios-woocommerce.php' );
define( 'IRIOS_WC_VERSION', '0.0.0-test' );
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/doubles.php';
require __DIR__ . '/doubles-hpos.php';
