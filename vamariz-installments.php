<?php
/**
 * Plugin Name: خرید اقساطی ۴ ماهه واماریز
 * Plugin URI: https://vamareys.com
 * Description: سیستم جامع، مستقل و اختصاصی خرید اقساطی در ۴ مرحله (۴ قسط ۲۵ درصدی) به همراه سامانه پیامکی ملی‌پیامک، مدیریت دعوت‌نامه‌ها، تفکیک نقش مشتری اقساطی و هماهنگی کامل با ووکامرس.
 * Version: 1.0.0
 * Author: واماریز (Vamariz)
 * Author URI: https://vamareys.com
 * Text Domain: vamariz-installments
 * Domain Path: /languages
 * Requires at least: 5.8
 * Tested up to: 6.7
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 9.3
 *
 * @package VamarizInstallments
 */

if (!defined('ABSPATH')) {
    exit;
}

define('VAMARIZ_INSTALLMENTS_VERSION', '1.0.0');
define('VAMARIZ_INSTALLMENTS_FILE', __FILE__);
define('VAMARIZ_INSTALLMENTS_PATH', plugin_dir_path(__FILE__));
define('VAMARIZ_INSTALLMENTS_URL', plugin_dir_url(__FILE__));

// Declare High-Performance Order Storage (HPOS) Compatibility
add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

// Load Plugin Components
require_once VAMARIZ_INSTALLMENTS_PATH . 'includes/class-vamariz-roles.php';
require_once VAMARIZ_INSTALLMENTS_PATH . 'includes/class-vamariz-sms.php';
require_once VAMARIZ_INSTALLMENTS_PATH . 'includes/class-vamariz-snapppay.php';
require_once VAMARIZ_INSTALLMENTS_PATH . 'includes/class-vamariz-pdp-widget.php';
require_once VAMARIZ_INSTALLMENTS_PATH . 'includes/class-vamariz-engine.php';
require_once VAMARIZ_INSTALLMENTS_PATH . 'includes/class-vamariz-admin.php';

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

    public function __construct() {
        // Initialize Core Components
        Vamariz_Roles::instance();
        Vamariz_SMS::instance();
        Vamariz_SnappPay::instance();
        Vamariz_PDP_Widget::instance();
        Vamariz_Engine::instance();

        if (is_admin()) {
            Vamariz_Admin::instance();
            add_action('admin_notices', array($this, 'check_woocommerce_dependency'));
        }
    }

    public function check_woocommerce_dependency() {
        if (!class_exists('WooCommerce')) {
            echo '<div class="notice notice-error is-dismissible"><p>' .
                esc_html__('افزونه «خرید اقساطی ۴ ماهه واماریز» برای فعالیت نیازمند نصب و فعال بودن افزونه ووکامرس (WooCommerce) است.', 'vamariz-installments') .
                '</p></div>';
        }
    }
}

// Bootstrap on plugins_loaded
add_action('plugins_loaded', function() {
    Vamariz_Installments_Plugin::instance();
}, 10);

// Plugin Activation Hook
register_activation_hook(__FILE__, function() {
    try {
        require_once VAMARIZ_INSTALLMENTS_PATH . 'includes/class-vamariz-roles.php';
        Vamariz_Roles::register_role();

        require_once VAMARIZ_INSTALLMENTS_PATH . 'includes/class-vamariz-sms.php';
        Vamariz_SMS::schedule_cron();
    } catch (\Throwable $e) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Vamariz Activation Error: ' . $e->getMessage());
        }
    }
});

// Plugin Deactivation Hook
register_deactivation_hook(__FILE__, function() {
    try {
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook('vamariz_daily_installment_cron');
        }
    } catch (\Throwable $e) {
        // Silent fail
    }
});
