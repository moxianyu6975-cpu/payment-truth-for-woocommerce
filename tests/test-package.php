<?php
/**
 * Static release-safety checks.
 *
 * @package PaymentTruthForWooCommerce
 */

$root  = dirname( __DIR__ );
$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );
$php   = '';

foreach ( $files as $file ) {
	if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) && false === strpos( $file->getPathname(), DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR ) ) {
		$php .= file_get_contents( $file->getPathname() );
	}
}

$required = array(
	'Plugin Name: Payment Truth for WooCommerce',
	'declare_compatibility',
	'custom_order_tables',
	'check_admin_referer',
	'current_user_can',
	'wp_safe_remote_post',
	'WC_Stripe_API::retrieve',
);

foreach ( $required as $needle ) {
	if ( false === strpos( $php, $needle ) ) {
		fwrite( STDERR, "FAIL: missing required release marker: {$needle}\n" );
		exit( 1 );
	}
}

$forbidden = array(
	'eval' . '(',
	'base64_' . 'decode(',
	'->update_status(',
	'->payment_complete(',
	'wp_remote_post(',
);

foreach ( $forbidden as $needle ) {
	if ( false !== strpos( $php, $needle ) ) {
		fwrite( STDERR, "FAIL: forbidden release pattern: {$needle}\n" );
		exit( 1 );
	}
}

if ( ! is_file( $root . '/readme.txt' ) || ! is_file( $root . '/uninstall.php' ) || ! is_file( $root . '/LICENSE' ) ) {
	fwrite( STDERR, "FAIL: package metadata is incomplete.\n" );
	exit( 1 );
}

fwrite( STDOUT, "PASS: static release checks\n" );
