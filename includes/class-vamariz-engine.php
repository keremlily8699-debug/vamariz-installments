<?php
/**
 * Vamareys 4-Stage Installments Calculation & Order Lifecycle Engine
 *
 * @package VamareysInstallments
 */

if (!defined('ABSPATH')) {
    exit;
}

class Vamariz_Engine {

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // Checkout radio choice for installment customers
        add_action('woocommerce_review_order_before_payment', array($this, 'render_checkout_installment_toggle'));
        add_action('woocommerce_checkout_update_order_review', array($this, 'handle_checkout_ajax_toggle'));

        // Adjust checkout fees and totals if installment is selected
        add_action('woocommerce_cart_calculate_fees', array($this, 'apply_installment_split_fee'), 20, 1);
        add_filter('woocommerce_calculated_total', array($this, 'filter_calculated_cart_total'), 9999, 2);

        // Render clear installment breakdown in checkout order review
        add_action('woocommerce_review_order_after_order_total', array($this, 'render_checkout_installment_breakdown'));

        // Order creation & meta recording
        add_action('woocommerce_checkout_create_order', array($this, 'record_installment_meta_on_create'), 20, 2);
        add_action('woocommerce_checkout_order_processed', array($this, 'on_order_processed'), 20, 3);

        // Gateway total filters (guarantees payment gateways receive only the 25% down payment)
        add_filter('woocommerce_order_amount_total_IRANIAN_gateways_before_check_currency', array($this, 'filter_gateway_order_amount'), 999, 2);
        add_filter('woocommerce_order_amount_total_Zibal_gateway', array($this, 'filter_gateway_order_amount'), 999, 2);

        // Payment status transitions & child order creation
        add_filter('woocommerce_payment_complete_order_status', array($this, 'filter_payment_complete_status'), 25, 2);
        add_action('woocommerce_payment_complete', array($this, 'on_payment_complete'), 20);
        add_action('woocommerce_order_status_processing', array($this, 'on_order_status_processing'), 20);
        add_action('woocommerce_order_status_completed', array($this, 'on_order_status_completed'), 20);

        // Cancellation cascade
        add_action('woocommerce_order_status_cancelled', array($this, 'on_parent_order_cancelled_or_refunded'), 20);
        add_action('woocommerce_order_status_refunded', array($this, 'on_parent_order_cancelled_or_refunded'), 20);
        add_action('woocommerce_order_status_failed', array($this, 'on_parent_order_cancelled_or_refunded'), 20);

        // Cart debt validation
        add_action('woocommerce_check_cart_items', array($this, 'validate_customer_debt'));

        // Direct sub-order payment redirection (?vamareys_pay=ID or ?vamariz_pay=ID)
        add_action('init', array($this, 'handle_direct_payment_redirect'));
    }

    /**
     * Check if installment choice is selected in session, POST, or defaults to installment.
     */
    public static function is_installment_chosen() {
        if (!Vamariz_Roles::is_installment_customer()) {
            return false;
        }

        // 1. Direct form submission POST check
        if (isset($_POST['vamariz_payment_plan'])) {
            return sanitize_text_field($_POST['vamariz_payment_plan']) === 'installment';
        }
        if (isset($_POST['vamareys_payment_plan'])) {
            return sanitize_text_field($_POST['vamareys_payment_plan']) === 'installment';
        }

        // 2. Serialized post_data in WooCommerce checkout AJAX
        if (!empty($_POST['post_data'])) {
            parse_str($_POST['post_data'], $params);
            if (isset($params['vamariz_payment_plan'])) {
                return sanitize_text_field($params['vamariz_payment_plan']) === 'installment';
            }
            if (isset($params['vamareys_payment_plan'])) {
                return sanitize_text_field($params['vamareys_payment_plan']) === 'installment';
            }
        }

        // 3. Session state check
        if (function_exists('WC') && WC()->session) {
            $val = WC()->session->get('vamariz_chosen_payment_plan');
            if ($val !== null) {
                return $val === 'installment';
            }
            $val2 = WC()->session->get('vamareys_chosen_payment_plan');
            if ($val2 !== null) {
                return $val2 === 'installment';
            }
        }

        // Default for installment customers is true (installment plan)
        return true;
    }

    /**
     * Render the payment choice toggle on checkout page.
     */
    public function render_checkout_installment_toggle() {
        if (!Vamariz_Roles::is_installment_customer()) {
            return;
        }

        $cart = (function_exists('WC') && WC()->cart) ? WC()->cart : false;
        if (!$cart) {
            return;
        }

        // Compute full cart total (items + shipping)
        $items_total = (float) $cart->get_cart_contents_total() + (float) $cart->get_cart_contents_tax();
        $shipping_total = (float) $cart->get_shipping_total() + (float) $cart->get_shipping_tax();
        $cart_total = round($items_total + $shipping_total);

        $min_order  = (float) get_option('vamariz_min_order_total', 500000);
        if ($cart_total < $min_order) {
            return;
        }

        $chosen = self::is_installment_chosen();
        $quarter = round($cart_total / 4);
        ?>
        <div class="vamariz-checkout-plan-box" style="margin: 20px 0; padding: 18px; border: 2px solid #2ecc71; border-radius: 8px; background: #fbfdfc; direction: rtl; text-align: right;">
            <h4 style="margin: 0 0 12px; color: #27ae60; font-size: 15px; font-weight: bold;">
                <?php esc_html_e('نحوه پرداخت سفارش (ویژه مشتریان واماریز):', 'vamariz-installments'); ?>
            </h4>
            <div style="margin-bottom: 12px;">
                <label style="cursor: pointer; display: flex; align-items: center; gap: 8px; font-size: 14px;">
                    <input type="radio" name="vamariz_payment_plan" value="installment" <?php checked($chosen, true); ?> class="vamariz-plan-radio">
                    <strong><?php esc_html_e('خرید در ۴ قسط ماهانه (پرداخت ۲۵٪ قسط اول الان)', 'vamariz-installments'); ?></strong>
                </label>
                <div style="margin-right: 25px; color: #555; font-size: 13px; margin-top: 4px; line-height: 1.6;">
                    <?php echo sprintf(
                        esc_html__('پیش‌پرداخت قسط اول: %s تومان + ۳ قسط ماهانه %s تومانی (ارسال فوری سفارش)', 'vamariz-installments'),
                        '<strong style="color:#27ae60;">' . esc_html(number_format($quarter)) . '</strong>',
                        '<strong>' . esc_html(number_format($quarter)) . '</strong>'
                    ); ?>
                </div>
            </div>
            <div>
                <label style="cursor: pointer; display: flex; align-items: center; gap: 8px; font-size: 14px;">
                    <input type="radio" name="vamariz_payment_plan" value="full" <?php checked(!$chosen, true); ?> class="vamariz-plan-radio">
                    <span><?php esc_html_e('پرداخت کامل نقدی کل مبلغ سفارش', 'vamariz-installments'); ?></span>
                </label>
            </div>
        </div>
        <script>
        jQuery(function($){
            $(document).on('change', '.vamariz-plan-radio', function(){
                $('body').trigger('update_checkout');
            });
        });
        </script>
        <?php
    }

    /**
     * Handle AJAX update checkout when radio changes.
     */
    public function handle_checkout_ajax_toggle($post_data) {
        if (!function_exists('WC') || !WC()->session) {
            return;
        }

        parse_str($post_data, $params);
        $plan = '';
        if (isset($params['vamariz_payment_plan'])) {
            $plan = sanitize_text_field($params['vamariz_payment_plan']);
        } elseif (isset($params['vamareys_payment_plan'])) {
            $plan = sanitize_text_field($params['vamareys_payment_plan']);
        }

        if (!empty($plan)) {
            WC()->session->set('vamariz_chosen_payment_plan', $plan);
            WC()->session->set('vamareys_chosen_payment_plan', $plan);
        }
    }

    /**
     * Apply 25% installment calculation to cart fees.
     */
    public function apply_installment_split_fee($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        if (!Vamariz_Roles::is_installment_customer() || !self::is_installment_chosen()) {
            return;
        }

        $items_total = (float) $cart->get_cart_contents_total() + (float) $cart->get_cart_contents_tax();
        $shipping_total = (float) $cart->get_shipping_total() + (float) $cart->get_shipping_tax();
        $full_total = round($items_total + $shipping_total);

        $min_order = (float) get_option('vamariz_min_order_total', 500000);
        if ($full_total < $min_order) {
            return;
        }

        $first_installment = round($full_total / 4);
        $deferred = $full_total - $first_installment;

        if ($deferred > 0) {
            // Apply negative fee as deferred amount
            $cart->add_fee(
                __('کسر اقساط ۳ ماهه باقیمانده (سررسیدهای ۳۰، ۶۰ و ۹۰ روز)', 'vamariz-installments'),
                -$deferred,
                false
            );
        }
    }

    /**
     * Filter calculated cart total to strictly enforce 25% down payment.
     */
    public function filter_calculated_cart_total($total, $cart) {
        if (!Vamariz_Roles::is_installment_customer() || !self::is_installment_chosen()) {
            return $total;
        }

        $min_order = (float) get_option('vamariz_min_order_total', 500000);
        if ($total < $min_order && $total <= 0) {
            return $total;
        }

        // Check if negative fee is already present in cart
        $has_installment_fee = false;
        if ($cart && method_exists($cart, 'get_fees')) {
            foreach ($cart->get_fees() as $fee) {
                if ($fee->amount < 0) {
                    $has_installment_fee = true;
                    break;
                }
            }
        }

        // If fee was somehow not applied, enforce 25%
        if (!$has_installment_fee && $total >= $min_order) {
            return round($total / 4);
        }

        return $total;
    }

    /**
     * Render detailed installment breakdown under order total on checkout review.
     */
    public function render_checkout_installment_breakdown() {
        if (!Vamariz_Roles::is_installment_customer() || !self::is_installment_chosen()) {
            return;
        }

        $cart = (function_exists('WC') && WC()->cart) ? WC()->cart : false;
        if (!$cart) {
            return;
        }

        $items_total = (float) $cart->get_cart_contents_total() + (float) $cart->get_cart_contents_tax();
        $shipping_total = (float) $cart->get_shipping_total() + (float) $cart->get_shipping_tax();
        $full_total = round($items_total + $shipping_total);

        $min_order = (float) get_option('vamariz_min_order_total', 500000);
        if ($full_total < $min_order) {
            return;
        }

        $q1 = round($full_total / 4);
        $q2 = round($full_total / 4);
        $q3 = round($full_total / 4);
        $q4 = $full_total - ($q1 + $q2 + $q3);
        $deferred = $q2 + $q3 + $q4;
        ?>
        <tr class="vamariz-checkout-breakdown-row">
            <td colspan="2" style="padding: 14px; background: #f0fdf4; border: 1.5px solid #86efac; border-radius: 8px; margin: 12px 0;">
                <div style="font-weight: 700; color: #166534; font-size: 13.5px; margin-bottom: 8px; display: flex; align-items: center; gap: 6px;">
                    <span style="display:inline-block; width:8px; height:8px; background:#16a34a; border-radius:50%;"></span>
                    <?php esc_html_e('برنامه زمان‌بندی پرداخت ۴ مرحله‌ای واماریز:', 'vamariz-installments'); ?>
                </div>
                <div style="font-size: 12.5px; color: #15803d; line-height: 2;">
                    <div>• <?php esc_html_e('مبلغ کل سفارش:', 'vamariz-installments'); ?> <strong><?php echo wc_price($full_total); ?></strong></div>
                    <div>• <?php esc_html_e('مبلغ قابل پرداخت امروز (قسط اول - ۲۵٪):', 'vamariz-installments'); ?> <strong style="color: #16a34a; font-size: 14px;"><?php echo wc_price($q1); ?></strong> <span style="color:#666;">(قابل پرداخت آنلاین)</span></div>
                    <div>• <?php esc_html_e('اقساط باقیمانده (۳ قسط ماهانه):', 'vamariz-installments'); ?> <strong><?php echo wc_price($deferred); ?></strong> (۳ قسط ماهانه در سررسیدهای ۳۰، ۶۰ و ۹۰ روز دیگر)</div>
                </div>
            </td>
        </tr>
        <?php
    }

    /**
     * Record installment metadata on parent order creation.
     */
    public function record_installment_meta_on_create($order, $data) {
        if (!Vamariz_Roles::is_installment_customer() || !self::is_installment_chosen()) {
            return;
        }

        $cart = (function_exists('WC') && WC()->cart) ? WC()->cart : false;
        if (!$cart) {
            return;
        }

        $items_total = (float) $cart->get_cart_contents_total() + (float) $cart->get_cart_contents_tax();
        $shipping_total = (float) $cart->get_shipping_total() + (float) $cart->get_shipping_tax();
        $full_total = round($items_total + $shipping_total);

        $min_order = (float) get_option('vamariz_min_order_total', 500000);
        if ($full_total < $min_order) {
            return;
        }

        $q1 = round($full_total / 4);
        $q2 = round($full_total / 4);
        $q3 = round($full_total / 4);
        $q4 = $full_total - ($q1 + $q2 + $q3);

        $now = current_time('timestamp');
        $schedule = array(
            1 => array('step' => 1, 'amount' => $q1, 'due_date' => $now, 'status' => 'pending', 'order_id' => 0),
            2 => array('step' => 2, 'amount' => $q2, 'due_date' => $now + 86400 * 30, 'status' => 'pending', 'order_id' => 0),
            3 => array('step' => 3, 'amount' => $q3, 'due_date' => $now + 86400 * 60, 'status' => 'pending', 'order_id' => 0),
            4 => array('step' => 4, 'amount' => $q4, 'due_date' => $now + 86400 * 90, 'status' => 'pending', 'order_id' => 0),
        );

        $order->update_meta_data('_vamariz_is_installment_order', 'yes');
        $order->update_meta_data('_vamariz_total_order_amount', $full_total);
        $order->update_meta_data('_vamariz_installment_amount', $q1);
        $order->update_meta_data('_vamariz_payment_schedule', $schedule);

        // Explicitly set order total to the 25% first installment
        $order->set_total($q1);
    }

    /**
     * On order processed, set parent ID on schedule and enforce 25% order total.
     */
    public function on_order_processed($order_id, $posted_data, $order) {
        if (!$order) {
            return;
        }
        $is_installment = $order->get_meta('_vamariz_is_installment_order', true);
        if ($is_installment === 'yes') {
            $q1 = (float) $order->get_meta('_vamariz_installment_amount', true);
            if ($q1 > 0) {
                $order->set_total($q1);
            }
            $schedule = $order->get_meta('_vamariz_payment_schedule', true);
            if (is_array($schedule) && isset($schedule[1])) {
                $schedule[1]['order_id'] = $order_id;
                $order->update_meta_data('_vamariz_payment_schedule', $schedule);
            }
            $order->save();
        }
    }

    /**
     * Filter gateway payment total for Iranian gateways (Zibal, Mellat, Parsian, etc.).
     */
    public function filter_gateway_order_amount($amount, $currency = '') {
        $order_id = 0;
        if (!empty($GLOBALS['order_id'])) {
            $order_id = absint($GLOBALS['order_id']);
        } elseif (!empty($_GET['order-pay'])) {
            $order_id = absint($_GET['order-pay']);
        } elseif (!empty($_GET['order_id'])) {
            $order_id = absint($_GET['order_id']);
        }

        if ($order_id > 0 && function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
            if ($order && $order->get_meta('_vamariz_is_installment_order', true) === 'yes') {
                $quarter = (float) $order->get_meta('_vamariz_installment_amount', true);
                if ($quarter > 0) {
                    return $quarter;
                }
            }
        }
        return $amount;
    }

    /**
     * Transition parent order directly to processing upon 1st installment payment.
     */
    public function filter_payment_complete_status($status, $order_id) {
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : false;
        if ($order) {
            $is_sub = $order->get_meta('_vamariz_is_sub_order', true);
            if ($is_sub === 'yes') {
                return 'completed';
            }
            $is_installment = $order->get_meta('_vamariz_is_installment_order', true);
            if ($is_installment === 'yes') {
                return 'processing';
            }
        }
        return $status;
    }

    /**
     * Handle payment completion: Create 3 child sub-orders and dispatch SMS.
     */
    public function on_payment_complete($order_id) {
        $this->handle_installment_fulfillment($order_id);
    }

    public function on_order_status_processing($order_id) {
        $this->handle_installment_fulfillment($order_id);
    }

    public function on_order_status_completed($order_id) {
        $this->handle_installment_fulfillment($order_id);
    }

    private function handle_installment_fulfillment($order_id) {
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : false;
        if (!$order) {
            return;
        }

        // If this is a child sub-order that was paid
        $is_sub = $order->get_meta('_vamariz_is_sub_order', true);
        if ($is_sub === 'yes') {
            $parent_id = (int) $order->get_meta('_vamariz_parent_order_id', true);
            $step      = (int) $order->get_meta('_vamariz_installment_step', true);

            if ($parent_id > 0 && function_exists('wc_get_order')) {
                $parent = wc_get_order($parent_id);
                if ($parent) {
                    $schedule = $parent->get_meta('_vamariz_payment_schedule', true);
                    if (is_array($schedule) && isset($schedule[$step])) {
                        $schedule[$step]['status']  = 'completed';
                        $schedule[$step]['paid_at'] = current_time('timestamp');
                        $parent->update_meta_data('_vamariz_payment_schedule', $schedule);

                        // Check if all 4 installments are completed
                        $all_done = true;
                        for ($s = 1; $s <= 4; $s++) {
                            if (!isset($schedule[$s]['status']) || $schedule[$s]['status'] !== 'completed') {
                                $all_done = false;
                                break;
                            }
                        }
                        if ($all_done) {
                            $parent->update_status('completed', __('تمامی اقساط ۴ ماهه واماریز با موفقیت تسویه گردید.', 'vamariz-installments'));
                        }
                        $parent->save();
                    }
                }
            }

            $receipt_sent = $order->get_meta('_vamariz_receipt_sent', true);
            if ($receipt_sent !== 'yes') {
                Vamariz_SMS::send_payment_receipt_sms($order_id);
                $order->update_meta_data('_vamariz_receipt_sent', 'yes');
                $order->save();
            }
            return;
        }

        // If this is the parent installment order
        $is_installment = $order->get_meta('_vamariz_is_installment_order', true);
        if ($is_installment !== 'yes') {
            return;
        }

        // Mark 1st installment paid in schedule
        $schedule = $order->get_meta('_vamariz_payment_schedule', true);
        if (!is_array($schedule)) {
            return;
        }

        if (isset($schedule[1])) {
            $schedule[1]['status']  = 'completed';
            $schedule[1]['paid_at'] = current_time('timestamp');
        }

        // Check if child sub-orders 2, 3, 4 already created
        $created_children = $order->get_meta('_vamariz_children_created', true);
        if ($created_children !== 'yes') {
            $total = (float) $order->get_meta('_vamariz_total_order_amount', true);
            $quarter = (float) $order->get_meta('_vamariz_installment_amount', true);
            if ($quarter <= 0 && $total > 0) {
                $quarter = round($total / 4);
            }

            $user_id = $order->get_customer_id();
            $now = current_time('timestamp');

            for ($step = 2; $step <= 4; $step++) {
                $due_days = ($step - 1) * 30;
                $due_time = $now + (86400 * $due_days);

                $sub = wc_create_order(array(
                    'status'      => 'pending',
                    'customer_id' => $user_id,
                    'parent'      => $order_id,
                ));

                if ($sub && !is_wp_error($sub)) {
                    $item = new WC_Order_Item_Fee();
                    $item->set_name(sprintf('قسط شماره %d سفارش #%d واماریز', $step, $order_id));
                    $item->set_total($quarter);
                    $sub->add_item($item);

                    // Copy customer billing details
                    $sub->set_billing_first_name($order->get_billing_first_name());
                    $sub->set_billing_last_name($order->get_billing_last_name());
                    $sub->set_billing_phone($order->get_billing_phone());
                    $sub->set_billing_email($order->get_billing_email());
                    $sub->set_billing_address_1($order->get_billing_address_1());
                    $sub->set_billing_city($order->get_billing_city());

                    $sub->calculate_totals();

                    $sub->update_meta_data('_vamariz_is_sub_order', 'yes');
                    $sub->update_meta_data('_vamariz_parent_order_id', $order_id);
                    $sub->update_meta_data('_vamariz_installment_step', $step);
                    $sub->update_meta_data('_vamariz_due_date', $due_time);
                    $sub->update_meta_data('_vamariz_sub_amount', $quarter);
                    $sub->save();

                    $schedule[$step]['order_id'] = $sub->get_id();
                    $schedule[$step]['status']   = 'pending';
                    $schedule[$step]['due_date'] = $due_time;
                }
            }

            $order->update_meta_data('_vamariz_children_created', 'yes');
            $order->update_meta_data('_vamariz_payment_schedule', $schedule);
            $order->save();

            // Dispatch confirmation SMS
            Vamariz_SMS::send_order_confirmation_sms($order_id);
        }
    }

    /**
     * Cascade cancellation to pending child sub-orders.
     */
    public function on_parent_order_cancelled_or_refunded($order_id) {
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : false;
        if (!$order) {
            return;
        }

        $is_installment = $order->get_meta('_vamariz_is_installment_order', true);
        if ($is_installment !== 'yes') {
            return;
        }

        $schedule = $order->get_meta('_vamariz_payment_schedule', true);
        if (!is_array($schedule)) {
            return;
        }

        foreach ($schedule as $step => $item) {
            if ($step > 1 && !empty($item['order_id'])) {
                $sub = wc_get_order($item['order_id']);
                if ($sub && in_array($sub->get_status(), array('pending', 'on-hold', 'wc-pending', 'wc-on-hold'), true)) {
                    $sub->update_status('cancelled', __('لغو خودکار به دلیل لغو یا استرداد سفارش اصلی واماریز', 'vamariz-installments'));
                }
            }
        }
    }

    /**
     * Validate overdue debt in cart/checkout.
     */
    public function validate_customer_debt() {
        if (!Vamariz_Roles::is_installment_customer() || !self::is_installment_chosen()) {
            return;
        }

        $user_id = function_exists('get_current_user_id') ? get_current_user_id() : 0;
        if ($user_id <= 0) {
            return;
        }

        $now = current_time('timestamp');
        $overdue_sub_orders = wc_get_orders(array(
            'customer_id' => $user_id,
            'status'      => array('pending', 'on-hold', 'wc-pending', 'wc-on-hold'),
            'meta_key'    => '_vamariz_is_sub_order',
            'meta_value'  => 'yes',
            'limit'       => 20,
        ));

        if (!empty($overdue_sub_orders)) {
            foreach ($overdue_sub_orders as $sub) {
                $due_date = (int) $sub->get_meta('_vamariz_due_date', true);
                if ($due_date > 0 && ($now - $due_date) > (86400 * 3)) {
                    $parent_id = $sub->get_meta('_vamariz_parent_order_id', true);
                    $step      = $sub->get_meta('_vamariz_installment_step', true);
                    $pay_url   = $sub->get_checkout_payment_url();

                    if (function_exists('wc_add_notice')) {
                        wc_add_notice(
                            sprintf(
                                __('شما قسط معوقه شماره %d برای سفارش #%d دارید که از سررسید آن گذشته است. جهت ثبت سفارش اقساطی جدید، لطفاً ابتدا <a href="%s" style="font-weight:bold;text-decoration:underline;">قسط معوقه خود را تسویه فرمایید</a>.', 'vamariz-installments'),
                                esc_html($step),
                                esc_html($parent_id),
                                esc_url($pay_url)
                            ),
                            'error'
                        );
                    }
                    break;
                }
            }
        }
    }

    /**
     * Direct sub-order payment redirection (?vamareys_pay=ID or ?vamariz_pay=ID).
     */
    public function handle_direct_payment_redirect() {
        $sub_id = 0;
        if (!empty($_GET['vamareys_pay'])) {
            $sub_id = absint($_GET['vamareys_pay']);
        } elseif (!empty($_GET['vamariz_pay'])) {
            $sub_id = absint($_GET['vamariz_pay']);
        }

        if ($sub_id > 0 && function_exists('wc_get_order')) {
            $order = wc_get_order($sub_id);
            if ($order) {
                if ($order->is_paid() || in_array($order->get_status(), array('processing', 'completed'), true)) {
                    $view_url = method_exists($order, 'get_view_order_url') ? $order->get_view_order_url() : (function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url('orders') : home_url());
                    if (function_exists('wc_add_notice')) {
                        wc_add_notice('این قسط قبلاً با موفقیت تسویه شده است. از حسن توجه و خوش‌حسابی شما سپاسگزاریم.', 'success');
                    }
                    wp_safe_redirect($view_url);
                    exit;
                }

                $pay_url = method_exists($order, 'get_checkout_payment_url') ? $order->get_checkout_payment_url() : '';
                if (!empty($pay_url)) {
                    wp_redirect($pay_url);
                    exit;
                }
            }
        }
    }
}


