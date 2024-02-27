<?php

/**
 * Plugin Name:          Paysley
 * Plugin URI:           https://github.com/PaysleyLLC/paysley-woocommerce
 * Description:          Receive payments using Paysley.
 * Version:              2.0.1
 * Author:               Paysley
 * Author URI:           https://paysley.com
 * License:              GPL v3 or later
 * License URI:          https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:          paysley
 * Domain Path:          /languages
 *
 * @package Paysley
 */

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

define('PAYSLEY_PLUGIN_VERSION', '2.0.0');

register_activation_hook(__FILE__, 'paysley_activate_plugin');
register_uninstall_hook(__FILE__, 'paysley_uninstall_plugin');

/**
 * Process when activate plugin.
 */
function paysley_activate_plugin()
{
	// add or update plugin version to database.
	$paysley_plugin_version = get_option('paysley_plugin_version');
	if (!$paysley_plugin_version) {
		add_option('paysley_plugin_version', PAYSLEY_PLUGIN_VERSION);
	} else {
		update_option('paysley_plugin_version', PAYSLEY_PLUGIN_VERSION);
	}
}

/**
 * Process when delete plugin.
 */
function paysley_uninstall_plugin()
{
	delete_option('paysley_plugin_version');
	delete_option('woocommerce_paysley_settings');
}

/**
 * Initial plugin.
 */
function paysley_init()
{
	if (!class_exists('WC_Payment_Gateway')) {
		return;
	}

	load_plugin_textdomain('paysley', false, dirname(plugin_basename(__FILE__)) . '/languages');
	require_once plugin_basename('includes/class-paysley.php');
}
add_action('plugins_loaded', 'paysley_init', 0);

/**
 * Add paysley to woocommerce payment gateway.
 *
 * @param array $methods Payment methods.
 */
function paysley_add_gateway($methods)
{
	$methods[] = 'paysley';
	return $methods;
}
add_filter('woocommerce_payment_gateways', 'paysley_add_gateway');
/**
 * Add plugin settings link.
 *
 * @param array $links Links.
 */
function paysley_plugin_links($links)
{
	$settings_url = add_query_arg(
		array(
			'page'    => 'wc-settings',
			'tab'     => 'checkout',
			'section' => 'paysley',
		),
		admin_url('admin.php')
	);

	$plugin_links = array(
		'<a href="' . esc_url($settings_url) . '">' . __('Settings', 'paysley') . '</a>',
	);

	return array_merge($plugin_links, $links);
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'paysley_plugin_links');

/**
 * Add paysley query vars.
 *
 * @param array $vars Query vars.
 */
function paysley_add_query_vars_filter($vars)
{
	$vars[] = 'response';
	$vars[] = 'py_token';
	return $vars;
}
add_filter('query_vars', 'paysley_add_query_vars_filter');

add_action('woocommerce_new_product', 'update_product_on_paysley', 10, 1);
add_action('woocommerce_update_product', 'update_product_on_paysley', 10, 1);
function update_product_on_paysley($product_id)
{
	Paysley::updateProductOnPaysley($product_id);
}


/**
 * Custom function to declare compatibility with cart_checkout_blocks feature
 */
function declare_cart_checkout_blocks_compatibility()
{
	if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
	}
}

// Hook th custom function to the 'before_woocommerce_init' action
add_action('before_woocommerce_init', 'declare_cart_checkout_blocks_compatibility');


// Hook the custom function to the 'woocommerce_blocks_loaded' action
add_action('woocommerce_blocks_loaded', 'oawoo_register_order_approval_payment_method_type');

/**
 * Custom function to register a payment method type
 */
function oawoo_register_order_approval_payment_method_type()
{
	if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
		return;
	}
	// Include the custom Blocks Checkout class
	require_once plugin_basename('includes/class-paysley-woocommerce-block-checkout.php');
	// Hook the registration function to the 'woocommerce_blocks_payment_method_type_registration' action
	add_action('woocommerce_blocks_payment_method_type_registration', function (Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
		// Register an instance of WC_Paysley_Blocks
		$payment_method_registry->register(new WC_Paysley_Blocks);
	});
}
