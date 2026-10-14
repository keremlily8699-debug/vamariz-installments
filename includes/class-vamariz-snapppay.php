<?php
/**
 * Vamareys SnappPay Conditional Suppression Engine
 *
 * @package VamareysInstallments
 */

if (!defined('ABSPATH')) {
    exit;
}

class Vamariz_SnappPay {

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // Remove SnappPay's hook before it runs to avoid slow external API calls and null-pointer errors
        add_action('wp', array($this, 'maybe_remove_snapppay_filter'), 5);
        add_action('woocommerce_init', array($this, 'maybe_remove_snapppay_filter'), 5);

        // Suppress SnappPay gateway at checkout for installment customers
        add_filter('woocommerce_available_payment_gateways', array($this, 'suppress_snapppay_gateway'), PHP_INT_MAX);

        // Suppress SnappPay PDP widget on single product page for installment customers
        add_action('wp_head', array($this, 'suppress_snapppay_pdp_css'), 999);
    }

    /**
     * Remove SnappPay filter callback for installment customers.
     */
    public function maybe_remove_snapppay_filter() {
        if (!is_admin() && Vamariz_Roles::is_installment_customer()) {
            remove_filter('woocommerce_available_payment_gateways', 'show_snapppay_payment_gateway_based_on_cart_amount', PHP_INT_MAX);
        }
    }

    /**
     * Disable SnappPay gateway in checkout if current user is an installment customer.
     */
    public function suppress_snapppay_gateway($gateways) {
        if (!is_admin() && Vamariz_Roles::is_installment_customer()) {
            if (is_array($gateways)) {
                foreach ($gateways as $id => $gateway) {
                    if (stripos($id, 'snapppay') !== false || (is_object($gateway) && stripos(get_class($gateway), 'snapppay') !== false)) {
                        unset($gateways[$id]);
                    }
                }
            }
        }
        return $gateways;
    }

    /**
     * Hide SnappPay PDP widget via CSS for installment customers.
     */
    public function suppress_snapppay_pdp_css() {
        if (!function_exists('is_product') || !is_product()) {
            return;
        }

        if (Vamariz_Roles::is_installment_customer()) {
            echo '<style id="vamariz-hide-snapppay-style">
                .snapppay-pdp-widget,
                .snapppay-widget,
                #snapppay-widget,
                .snapppay-pdp-container,
                [class*="snapppay"],
                [id*="snapppay"] {
                    display: none !important;
                }
            </style>';
        }
    }
}
