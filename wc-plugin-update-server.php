<?php
/**
 * Plugin Name:     WooCommerce and UpdatePulse Server integration
 * Plugin URI:      http://rwsite.ru
 * Description:     WooCommerce and UpdatePulse Server integration
 * Version:         2.0.1
 * Author:          Aleksei Tikhomirov
 * Author URI:      http://rwsite.ru
 * Text Domain:     wc-pus
 * Domain Path:     /languages
 *
 * Tested up to: 6.8.1
 * Requires at least: 6.7
 * Requires PHP: 8.3+
 *
 * WC requires at least: 8.6
 * WC tested up to: 9.9.4
 *
 *
 * @author          <alex@rwsite.ru>
 * @copyright       Copyright (c) Aleksei Tikhomirov
 * @license         http://www.gnu.org/licenses/gpl-3.0.html
 *
 */

// Exit if accessed directly
use Automattic\WooCommerce\Utilities\FeaturesUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'LicPlugin' ) || defined( 'WCPUS' ) ) {
	return;
}

define( 'WCPUS', __FILE__ );

spl_autoload_register( function ( $className ) {
	$path = realpath( __DIR__ . '/includes/' . strtr( $className, '/', DIRECTORY_SEPARATOR ) . '.php' );
	if ( is_readable( $path ) ) {
		include_once $path;
	}
} );

register_activation_hook( __FILE__, [ 'LicPlugin', 'activation' ] );
register_uninstall_hook( __FILE__, [ 'LicPlugin', 'uninstall' ] );

add_action( 'init', [ 'LicPlugin', 'getInstance' ], 20 );

add_action( 'before_woocommerce_init', function () {
    if( class_exists( FeaturesUtil::class ) ) {
        FeaturesUtil::declare_compatibility('custom_order_tables', WCPUS);
    }
});
