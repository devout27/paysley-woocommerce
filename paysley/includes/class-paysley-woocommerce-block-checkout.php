<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_Paysley_Blocks extends AbstractPaymentMethodType {

    private $gateway;
    protected $name = 'Paysley';// your payment gateway name

    public function initialize() {
        $this->settings = get_option( 'woocommerce_paysley_settings', [] );
        $this->gateway = new Paysley();
        $this->settings['icon'] = WP_PLUGIN_URL . '/' . plugin_basename(dirname(dirname(__FILE__))) . '/assets/img/py-logo.png';
    }

    public function is_active() {
        return $this->gateway->is_available();
    }

    public function get_payment_method_script_handles() {

        // wp_register_script(
        //     'wc-paysley-blocks-integration',
        //     plugin_dir_url(__FILE__) . 'block/checkout.js',
        //     [
        //         'wc-blocks-registry',
        //         'wc-settings',
        //         'wp-element',
        //         'wp-html-entities',
        //         'wp-i18n',
        //     ],
        //     null,
        //     true
        // );

        wp_register_script(
            'wc-paysley-blocks-integration',
            plugin_dir_url(__FILE__) . 'block/checkout.js?v='.time(),
            [
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
                'wp-i18n',
            ],
            true,
            true
        );
        wp_enqueue_script( 'wc-paysley-blocks-integration' );

        wp_localize_script('wc-paysley-blocks-integration','paysley_settings',$this->settings);
        if( function_exists( 'wp_set_script_translations' ) ) {            
            // wp_set_script_translations( 'wc-phonepe-blocks-integration', 'wc-phonepe', SGPPY_PLUGIN_PATH. 'languages/' );
            wp_set_script_translations( 'Paysley', 'Paysley', dirname(plugin_basename(__FILE__)). 'languages/' );
            
        }
        return [ 'wc-paysley-blocks-integration' ];
    }

    public function get_payment_method_data() {
        return [
            'title' => $this->gateway->title,
            'description' => $this->gateway->description,
        ];
    }

}