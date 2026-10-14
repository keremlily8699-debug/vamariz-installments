<?php
/**
 * Plugin Name: خرید اقساطی ۴ ماهه واماریز (Vamareys Installments for WooCommerce)
 * Plugin URI: https://vamareys.com
 * Description: سیستم جامع، مستقل و اختصاصی خرید اقساطی در ۴ مرحله (۴ قسط ۲۵ درصدی) برای فروشگاه واماریز (Vamareys) به همراه سامانه پیامکی خدماتی ملی‌پیامک، مدیریت دعوت‌نامه‌ها، تفکیک نقش مشتری اقساطی و هماهنگی کامل با ووکامرس.
 * Version: 1.3.5
 * Author: واماریز (Vamareys)
 * Author URI: https://vamareys.com
 * Text Domain: vamariz-installments
 * Domain Path: /languages
 * Requires at least: 5.8
 * Tested up to: 6.7
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 9.3
 *
 * @package VamareysInstallments
 */

if (!defined('ABSPATH')) {
    exit;
}

define('VAMAREYS_INSTALLMENTS_VERSION', '1.3.5');
define('VAMARIZ_INSTALLMENTS_VERSION', '1.3.5');
define('VAMAREYS_INSTALLMENTS_FILE', __FILE__);
define('VAMARIZ_INSTALLMENTS_FILE', __FILE__);
define('VAMAREYS_INSTALLMENTS_PATH', plugin_dir_path(__FILE__));
define('VAMARIZ_INSTALLMENTS_PATH', plugin_dir_path(__FILE__));
define('VAMAREYS_INSTALLMENTS_URL', plugin_dir_url(__FILE__));
define('VAMARIZ_INSTALLMENTS_URL', plugin_dir_url(__FILE__));

// Declare High-Performance Order Storage (HPOS) Compatibility
add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

// Load Plugin Components
require_once VAMAREYS_INSTALLMENTS_PATH . 'includes/class-vamariz-roles.php';
require_once VAMAREYS_INSTALLMENTS_PATH . 'includes/class-vamariz-sms.php';
require_once VAMAREYS_INSTALLMENTS_PATH . 'includes/class-vamariz-snapppay.php';
require_once VAMAREYS_INSTALLMENTS_PATH . 'includes/class-vamariz-pdp-widget.php';
require_once VAMAREYS_INSTALLMENTS_PATH . 'includes/class-vamariz-engine.php';
require_once VAMAREYS_INSTALLMENTS_PATH . 'includes/class-vamariz-admin.php';

// Setup Class Aliases for Vamareys naming
if (!class_exists('Vamareys_Roles') && class_exists('Vamariz_Roles')) {
    class_alias('Vamariz_Roles', 'Vamareys_Roles');
}
if (!class_exists('Vamareys_SMS') && class_exists('Vamariz_SMS')) {
    class_alias('Vamariz_SMS', 'Vamareys_SMS');
}
if (!class_exists('Vamareys_SnappPay') && class_exists('Vamariz_SnappPay')) {
    class_alias('Vamariz_SnappPay', 'Vamareys_SnappPay');
}
if (!class_exists('Vamareys_PDP_Widget') && class_exists('Vamariz_PDP_Widget')) {
    class_alias('Vamariz_PDP_Widget', 'Vamareys_PDP_Widget');
}
if (!class_exists('Vamareys_Engine') && class_exists('Vamariz_Engine')) {
    class_alias('Vamariz_Engine', 'Vamareys_Engine');
}
if (!class_exists('Vamareys_Admin') && class_exists('Vamariz_Admin')) {
    class_alias('Vamariz_Admin', 'Vamareys_Admin');
}

/**
 * Main Plugin Orchestrator Class
 */
final class Vamariz_Installments_Plugin {

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_components();
    }

    private function init_components() {
        Vamariz_Roles::instance();
        Vamariz_SMS::instance();
        Vamariz_SnappPay::instance();
        Vamariz_PDP_Widget::instance();
        Vamariz_Engine::instance();

        if (is_admin()) {
            Vamariz_Admin::instance();
        }
    }

    public static function woocommerce_missing_notice() {
        echo '<div class="notice notice-error"><p>' .
            esc_html__('افزونه «خرید اقساطی ۴ ماهه واماریز» برای فعالیت نیازمند نصب و فعال بودن افزونه ووکامرس (WooCommerce) است.', 'vamariz-installments') .
            '</p></div>';
    }
}

if (!class_exists('Vamareys_Installments_Plugin') && class_exists('Vamariz_Installments_Plugin')) {
    class_alias('Vamariz_Installments_Plugin', 'Vamareys_Installments_Plugin');
}

add_action('plugins_loaded', function() {
    Vamariz_Installments_Plugin::instance();
}, 10);

// Plugin Activation Hook
register_activation_hook(__FILE__, function() {
    try {
        require_once VAMAREYS_INSTALLMENTS_PATH . 'includes/class-vamariz-roles.php';
        Vamariz_Roles::register_role();

        require_once VAMAREYS_INSTALLMENTS_PATH . 'includes/class-vamariz-sms.php';
        Vamariz_SMS::schedule_cron();
    } catch (\Throwable $e) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Vamareys Activation Error: ' . $e->getMessage());
        }
    }
});

// Plugin Deactivation Hook
register_deactivation_hook(__FILE__, function() {
    if (function_exists('wp_clear_scheduled_hook')) {
        wp_clear_scheduled_hook('vamariz_daily_installment_cron');
        wp_clear_scheduled_hook('vamareys_daily_installment_cron');
    }
});
