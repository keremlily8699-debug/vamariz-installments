<?php
/**
 * Vamariz Roles and Invitation Token Manager
 *
 * @package VamarizInstallments
 */

if (!defined('ABSPATH')) {
    exit;
}

class Vamariz_Roles {

    const ROLE_SLUG = 'installment_customer';
    const ROLE_NAME = 'مشتری اقساطی';
    const OPTION_INVITES = 'vamariz_invitations';

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action('init', array(__CLASS__, 'register_role'), 5);

        // User edit screen hooks
        add_action('show_user_profile', array($this, 'render_user_profile_fields'));
        add_action('edit_user_profile', array($this, 'render_user_profile_fields'));
        add_action('personal_options_update', array($this, 'save_user_profile_fields'));
        add_action('edit_user_profile_update', array($this, 'save_user_profile_fields'));

        // Capture invite parameter from URL
        add_action('init', array($this, 'capture_invite_token'), 10);
        add_action('template_redirect', array($this, 'capture_invite_token'), 10);

        // Registration & Login handling with invite
        add_action('woocommerce_register_form', array($this, 'render_invite_form_notice'));
        add_action('woocommerce_after_checkout_registration_form', array($this, 'render_invite_form_notice'));
        add_action('woocommerce_before_checkout_form', array($this, 'render_invite_form_notice'));
        add_action('woocommerce_created_customer', array($this, 'handle_registration_with_invite'), 10, 3);
        add_action('user_register', array($this, 'handle_registration_with_invite'), 10, 1);
        add_action('wp_login', array($this, 'handle_login_with_invite'), 10, 2);
    }

    /**
     * Register or update the installment_customer role.
     */
    public static function register_role() {
        if (!function_exists('get_role') || !function_exists('add_role')) {
            return;
        }

        $customer_role = get_role('customer');
        $caps = ($customer_role && !empty($customer_role->capabilities)) ? (array) $customer_role->capabilities : array('read' => true);
        $caps['use_vamariz_installments'] = true;

        $existing_role = get_role(self::ROLE_SLUG);
        if (!$existing_role) {
            add_role(self::ROLE_SLUG, self::ROLE_NAME, $caps);
        } else {
            foreach ($caps as $cap => $grant) {
                if ($grant && !$existing_role->has_cap($cap)) {
                    $existing_role->add_cap($cap);
                }
            }
        }
    }

    /**
     * Check if a given or current user is eligible for Vamariz installments.
     *
     * @param int|null $user_id Optional user ID.
     * @return bool
     */
    public static function is_installment_customer($user_id = null) {
        if (!function_exists('is_user_logged_in') || !is_user_logged_in()) {
            return false;
        }

        if (is_null($user_id) && function_exists('wp_get_current_user')) {
            $user = wp_get_current_user();
        } else {
            if (!function_exists('get_userdata')) {
                return false;
            }
            $user = get_userdata((int) $user_id);
        }

        if (!$user || !is_object($user) || empty($user->roles) || !is_array($user->roles)) {
            return false;
        }

        // Administrators can always test and preview installments
        if (in_array('administrator', (array) $user->roles, true)) {
            return true;
        }

        return in_array(self::ROLE_SLUG, (array) $user->roles, true);
    }

    /**
     * Render role assignment toggle in user edit screen.
     */
    public function render_user_profile_fields($user) {
        if (!function_exists('current_user_can') || (!current_user_can('manage_woocommerce') && !current_user_can('promote_users'))) {
            return;
        }

        $is_installment = in_array(self::ROLE_SLUG, (array) $user->roles, true);
        wp_nonce_field('vamariz_save_user_role', 'vamariz_user_role_nonce');
        ?>
        <h3 style="margin-top: 25px; padding-top: 15px; border-top: 1px solid #ddd;"><?php esc_html_e('خرید اقساطی واماریز', 'vamariz-installments'); ?></h3>
        <table class="form-table">
            <tr>
                <th><label for="vamariz_is_installment_customer"><?php esc_html_e('وضعیت مشتری اقساطی', 'vamariz-installments'); ?></label></th>
                <td>
                    <label>
                        <input type="checkbox" name="vamariz_is_installment_customer" id="vamariz_is_installment_customer" value="1" <?php checked($is_installment, true); ?>>
                        <strong><?php esc_html_e('فعال بودن دسترسی به خرید اقساطی ۴ ماهه واماریز', 'vamariz-installments'); ?></strong>
                    </label>
                    <p class="description"><?php esc_html_e('با انتخاب این گزینه، نقش این کاربر به «مشتری اقساطی» ارتقا یافته و ویجت و شرایط اقساط واماریز برای وی نمایش داده می‌شود.', 'vamariz-installments'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Save user profile fields.
     */
    public function save_user_profile_fields($user_id) {
        if (!function_exists('current_user_can') || (!current_user_can('manage_woocommerce') && !current_user_can('promote_users'))) {
            return;
        }

        if (!isset($_POST['vamariz_user_role_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['vamariz_user_role_nonce'])), 'vamariz_save_user_role')) {
            return;
        }

        $user = function_exists('get_userdata') ? get_userdata($user_id) : false;
        if (!$user) {
            return;
        }

        $should_have_role = !empty($_POST['vamariz_is_installment_customer']);
        $has_role = in_array(self::ROLE_SLUG, (array) $user->roles, true);

        if ($should_have_role && !$has_role) {
            $user->add_role(self::ROLE_SLUG);
        } elseif (!$should_have_role && $has_role) {
            $user->remove_role(self::ROLE_SLUG);
        }
    }

    /**
     * Capture invitation token from URL and store in cookie or immediately upgrade logged in user.
     */
    public function capture_invite_token() {
        if (empty($_GET['vamariz_invite'])) {
            return;
        }

        $token = sanitize_text_field(wp_unslash($_GET['vamariz_invite']));
        if (empty($token)) {
            return;
        }

        $invite = self::get_invite($token);
        if (!$invite || $invite['status'] !== 'pending') {
            return;
        }

        // 1. If user is already logged in, upgrade immediately
        if (function_exists('is_user_logged_in') && is_user_logged_in() && function_exists('get_current_user_id')) {
            $user_id = get_current_user_id();
            if ($user_id > 0) {
                $user = function_exists('get_userdata') ? get_userdata($user_id) : false;
                if ($user) {
                    if (!in_array(self::ROLE_SLUG, (array) $user->roles, true)) {
                        $user->add_role(self::ROLE_SLUG);
                    }
                    self::mark_invite_used($token, $user_id);
                    if (!headers_sent()) {
                        $cookie_path   = defined('COOKIEPATH') ? COOKIEPATH : '/';
                        $cookie_domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
                        setcookie('vamariz_invite_token', '', time() - 3600, $cookie_path, $cookie_domain);
                    }
                    return;
                }
            }
        }

        // 2. If guest, preserve token in cookie for checkout / registration
        if (!headers_sent()) {
            $cookie_path   = defined('COOKIEPATH') ? COOKIEPATH : '/';
            $cookie_domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
            setcookie('vamariz_invite_token', $token, time() + 86400 * 3, $cookie_path, $cookie_domain, is_ssl(), true);
        }
    }

    /**
     * Display invite notice on registration / checkout.
     */
    public function render_invite_form_notice() {
        static $rendered = false;
        if ($rendered) {
            return;
        }

        $token = '';
        if (!empty($_GET['vamariz_invite'])) {
            $token = sanitize_text_field(wp_unslash($_GET['vamariz_invite']));
        } elseif (!empty($_POST['vamariz_invite_token'])) {
            $token = sanitize_text_field(wp_unslash($_POST['vamariz_invite_token']));
        } elseif (!empty($_COOKIE['vamariz_invite_token'])) {
            $token = sanitize_text_field(wp_unslash($_COOKIE['vamariz_invite_token']));
        }

        if (empty($token)) {
            return;
        }

        $invite = self::get_invite($token);
        if (!$invite || $invite['status'] !== 'pending') {
            return;
        }

        $rendered = true;
        echo '<div class="woocommerce-message" style="background:#f4fbf7; border-color:#2ecc71; color:#27ae60; font-size:14px; margin-bottom:15px; padding:12px 15px; border-radius:6px; direction:rtl; text-align:right;">'
            . sprintf(
                esc_html__('سلام %s عزیز! شما با دعوت‌نامه اختصاصی واماریز وارد شده‌اید. پس از ثبت‌نام یا خرید، حساب شما بلافاصله به عنوان «مشتری اقساطی» فعال خواهد شد.', 'vamariz-installments'),
                '<strong>' . esc_html($invite['name']) . '</strong>'
            )
            . '</div>';
        echo '<input type="hidden" name="vamariz_invite_token" value="' . esc_attr($token) . '" />';
    }

    /**
     * Automatically upgrade user on registration if valid invite token is present.
     */
    public function handle_registration_with_invite($customer_id, $new_customer_data = array(), $password_generated = false) {
        $token = '';
        if (!empty($_POST['vamariz_invite_token'])) {
            $token = sanitize_text_field(wp_unslash($_POST['vamariz_invite_token']));
        } elseif (!empty($_GET['vamariz_invite'])) {
            $token = sanitize_text_field(wp_unslash($_GET['vamariz_invite']));
        } elseif (!empty($_COOKIE['vamariz_invite_token'])) {
            $token = sanitize_text_field(wp_unslash($_COOKIE['vamariz_invite_token']));
        }

        if (empty($token)) {
            return;
        }

        $invite = self::get_invite($token);
        if ($invite && $invite['status'] === 'pending') {
            $user = function_exists('get_userdata') ? get_userdata($customer_id) : false;
            if ($user) {
                self::mark_invite_used($token, $customer_id);
                $user->add_role(self::ROLE_SLUG);
                if (!headers_sent()) {
                    $cookie_path   = defined('COOKIEPATH') ? COOKIEPATH : '/';
                    $cookie_domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
                    setcookie('vamariz_invite_token', '', time() - 3600, $cookie_path, $cookie_domain);
                }
            }
        }
    }

    /**
     * Automatically upgrade user on login if valid invite token is present.
     */
    public function handle_login_with_invite($user_login, $user) {
        $token = '';
        if (!empty($_GET['vamariz_invite'])) {
            $token = sanitize_text_field(wp_unslash($_GET['vamariz_invite']));
        } elseif (!empty($_COOKIE['vamariz_invite_token'])) {
            $token = sanitize_text_field(wp_unslash($_COOKIE['vamariz_invite_token']));
        }

        if (empty($token) || !is_object($user) || empty($user->ID)) {
            return;
        }

        $invite = self::get_invite($token);
        if ($invite && $invite['status'] === 'pending') {
            self::mark_invite_used($token, $user->ID);
            if (!in_array(self::ROLE_SLUG, (array) $user->roles, true)) {
                $user->add_role(self::ROLE_SLUG);
            }
            if (!headers_sent()) {
                $cookie_path   = defined('COOKIEPATH') ? COOKIEPATH : '/';
                $cookie_domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
                setcookie('vamariz_invite_token', '', time() - 3600, $cookie_path, $cookie_domain);
            }
        }
    }

    /**
     * Create a new invite token.
     */
    public static function create_invite($name, $phone) {
        $invites = get_option(self::OPTION_INVITES, array());
        if (!is_array($invites)) {
            $invites = array();
        }

        $token = 'vm_' . wp_generate_password(16, false);
        $invite = array(
            'token'      => $token,
            'name'       => sanitize_text_field($name),
            'phone'      => sanitize_text_field($phone),
            'status'     => 'pending',
            'created_at' => current_time('timestamp'),
            'used_by'    => 0,
            'used_at'    => 0,
        );

        $invites[$token] = $invite;
        update_option(self::OPTION_INVITES, $invites);

        return $invite;
    }

    public static function get_invite($token) {
        $invites = get_option(self::OPTION_INVITES, array());
        return isset($invites[$token]) ? $invites[$token] : null;
    }

    public static function mark_invite_used($token, $user_id) {
        $invites = get_option(self::OPTION_INVITES, array());
        if (isset($invites[$token])) {
            $invites[$token]['status']  = 'used';
            $invites[$token]['used_by'] = (int) $user_id;
            $invites[$token]['used_at'] = current_time('timestamp');
            update_option(self::OPTION_INVITES, $invites);
        }
    }

    public static function revoke_invite($token) {
        $invites = get_option(self::OPTION_INVITES, array());
        if (isset($invites[$token])) {
            $invites[$token]['status'] = 'revoked';
            update_option(self::OPTION_INVITES, $invites);
            return true;
        }
        return false;
    }

    public static function get_all_invites() {
        $invites = get_option(self::OPTION_INVITES, array());
        return is_array($invites) ? $invites : array();
    }

    public static function get_invite_url($token) {
        $account_url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : '';
        if (!$account_url) {
            $account_url = function_exists('home_url') ? home_url('/my-account/') : '/my-account/';
        }
        return add_query_arg('vamariz_invite', $token, $account_url);
    }
}
