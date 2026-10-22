<?php
// Tests without WordPress: pure logic is tested directly; code that calls WordPress/WooCommerce, with Brain Monkey
// (tests/WordPressTestCase.php) and the doubles in tests/doubles.php.
define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/doubles.php';
