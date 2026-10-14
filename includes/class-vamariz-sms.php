<?php
/**
 * Vamariz Melipayamak SMS Integration & Notification Engine
 *
 * @package VamarizInstallments
 */

if (!defined('ABSPATH')) {
    exit;
}

class Vamariz_SMS {

    const DEFAULT_API_URL = 'https://console.melipayamak.com/api/send/simple/1cf6abc67a3c471e9a61c617e82e394f';
    const DEFAULT_FROM = '50004001';

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // Daily Cron for installment reminders
        add_action('vamariz_daily_installment_cron', array($this, 'run_daily_reminders'));
        add_action('init', array(__CLASS__, 'schedule_cron'));
    }

    /**
     * Schedule daily cron job at 09:00 AM.
     */
    public static function schedule_cron() {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_event')) {
            return;
        }

        if (!wp_next_scheduled('vamariz_daily_installment_cron')) {
            $timestamp = strtotime('tomorrow 09:00:00');
            wp_schedule_event($timestamp ? $timestamp : (time() + 86400), 'daily', 'vamariz_daily_installment_cron');
        }
    }

    /**
     * Send SMS via Melipayamak REST Simple API.
     *
     * @param string $to Recipient phone number (e.g. 09123456789).
     * @param string $text SMS message content.
     * @return array Result array with status, recId, and message.
     */
    public static function send_sms($to, $text) {
        $api_url = get_option('vamariz_sms_api_url', self::DEFAULT_API_URL);
        if (empty($api_url)) {
            $api_url = self::DEFAULT_API_URL;
        }

        $from = get_option('vamariz_sms_from_number', self::DEFAULT_FROM);
        if (empty($from)) {
            $from = self::DEFAULT_FROM;
        }

        $to = self::normalize_phone($to);
        if (empty($to) || empty($text)) {
            return array('success' => false, 'message' => 'شماره گیرنده یا متن پیامک نامعتبر است.');
        }

        $payload = array(
            'from' => $from,
            'to'   => $to,
            'text' => $text,
        );

        if (!function_exists('wp_remote_post')) {
            return array('success' => false, 'message' => 'WordPress HTTP API not loaded.');
        }

        $response = wp_remote_post($api_url, array(
            'method'      => 'POST',
            'timeout'     => 15,
            'redirection' => 5,
            'httpversion' => '1.1',
            'blocking'    => true,
            'headers'     => array(
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ),
            'body'        => wp_json_encode($payload),
            'sslverify'   => false,
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message(),
            );
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($code >= 200 && $code < 300) {
            $rec_id = isset($data['recId']) ? $data['recId'] : (isset($data['value']) ? $data['value'] : '');
            return array(
                'success' => true,
                'recId'   => $rec_id,
                'raw'     => $data,
                'message' => 'پیامک با موفقیت ارسال شد.',
            );
        }

        $err_msg = isset($data['status']) ? $data['status'] : (isset($data['message']) ? $data['message'] : 'خطا در وب‌سرویس ملی‌پیامک (کد ' . $code . ')');
        return array(
            'success' => false,
            'message' => $err_msg,
            'raw'     => $data,
        );
    }

    /**
     * Clean and normalize Iranian mobile numbers to 09XXXXXXXXX format.
     */
    public static function normalize_phone($phone) {
        if (empty($phone)) {
            return '';
        }

        // Convert Persian / Arabic numerals to English
        $persian = array('۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹');
        $arabic  = array('٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩');
        $english = array('0', '1', '2', '3', '4', '5', '6', '7', '8', '9');
        $phone   = str_replace($persian, $english, $phone);
        $phone   = str_replace($arabic, $english, $phone);

        // Strip non-digits
        $phone = preg_replace('/[^\d]/', '', $phone);

        // Strip leading prefixes (+98 or 0098 or 98)
        if (strpos($phone, '0098') === 0) {
            $phone = substr($phone, 4);
        } elseif (strpos($phone, '98') === 0 && strlen($phone) === 12) {
            $phone = substr($phone, 2);
        }

        // Ensure leading zero
        if (strpos($phone, '9') === 0 && strlen($phone) === 10) {
            $phone = '0' . $phone;
        }

        if (preg_match('/^09[0-9]{9}$/', $phone)) {
            return $phone;
        }

        return '';
    }

    /**
     * Order Confirmation SMS (sent after 1st installment paid).
     */
    public static function send_order_confirmation_sms($order_id) {
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : false;
        if (!$order) {
            return;
        }

        $phone = self::normalize_phone($order->get_billing_phone());
        if (empty($phone)) {
            return;
        }

        $name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        if (empty($name)) {
            $name = 'مشتری گرامی';
        }

        $schedule = $order->get_meta('_vamariz_payment_schedule', true);
        $schedule_lines = '';
        if (is_array($schedule)) {
            $step_num = 1;
            foreach ($schedule as $item) {
                $status_fa = ($item['status'] === 'completed' || $step_num === 1) ? 'پرداخت شد' : 'سررسید: ' . (isset($item['due_date_fa']) ? $item['due_date_fa'] : date('Y/m/d', $item['due_date']));
                $schedule_lines .= "\n" . sprintf('قسط %d: %s تومان (%s)', $step_num, number_format((float)$item['amount']), $status_fa);
                $step_num++;
            }
        }

        $text = "واماریز\n"
              . "سلام {$name} عزیز،\n"
              . "قسط اول سفارش #{$order->get_id()} با موفقیت ثبت شد. سفارش شما برای بسته‌بندی و ارسال فوری ارسال شد.\n"
              . "زمان‌بندی ۳ قسط باقیمانده:"
              . $schedule_lines . "\n"
              . "پیش از موعد هر قسط لینک پرداخت پیامک خواهد شد.\n"
              . "vamareys.com";

        self::send_sms($phone, $text);
    }

    /**
     * Installment Reminder SMS (3 days before or day of due).
     */
    public static function send_reminder_sms($sub_order_id, $days_left = 3) {
        $sub_order = function_exists('wc_get_order') ? wc_get_order($sub_order_id) : false;
        if (!$sub_order) {
            return;
        }

        $phone = self::normalize_phone($sub_order->get_billing_phone());
        if (empty($phone)) {
            return;
        }

        $name = trim($sub_order->get_billing_first_name() . ' ' . $sub_order->get_billing_last_name());
        if (empty($name)) {
            $name = 'مشتری گرامی';
        }

        $parent_id = $sub_order->get_meta('_vamariz_parent_order_id', true);
        $step      = $sub_order->get_meta('_vamariz_installment_step', true);
        $amount    = number_format((float) $sub_order->get_total());
        $pay_url   = method_exists($sub_order, 'get_checkout_payment_url') ? $sub_order->get_checkout_payment_url() : home_url();

        if ($days_left === 3) {
            $text = "واماریز\n"
                  . "سلام {$name} عزیز،\n"
                  . "یادآوری: ۳ روز تا موعد قسط {$step} سفارش #{$parent_id} به مبلغ {$amount} تومان باقیمانده است.\n"
                  . "لینک پرداخت آنلاین مستقیم:\n{$pay_url}\n"
                  . "vamareys.com";
        } else {
            $text = "واماریز\n"
                  . "سلام {$name} عزیز،\n"
                  . "امروز موعد پرداخت قسط {$step} سفارش #{$parent_id} به مبلغ {$amount} تومان است.\n"
                  . "لطفاً از طریق لینک زیر نسبت به تسویه آنلاین اقدام فرمایید:\n{$pay_url}\n"
                  . "با تشکر از خوش‌حسابی شما\nvamareys.com";
        }

        self::send_sms($phone, $text);
    }

    /**
     * Overdue Installment SMS.
     */
    public static function send_overdue_sms($sub_order_id) {
        $sub_order = function_exists('wc_get_order') ? wc_get_order($sub_order_id) : false;
        if (!$sub_order) {
            return;
        }

        $phone = self::normalize_phone($sub_order->get_billing_phone());
        if (empty($phone)) {
            return;
        }

        $name = trim($sub_order->get_billing_first_name() . ' ' . $sub_order->get_billing_last_name());
        if (empty($name)) {
            $name = 'مشتری گرامی';
        }

        $parent_id = $sub_order->get_meta('_vamariz_parent_order_id', true);
        $step      = $sub_order->get_meta('_vamariz_installment_step', true);
        $amount    = number_format((float) $sub_order->get_total());
        $pay_url   = method_exists($sub_order, 'get_checkout_payment_url') ? $sub_order->get_checkout_payment_url() : home_url();

        $text = "واماریز - اخطار سررسید قسط\n"
              . "سلام {$name} عزیز،\n"
              . "موعد قسط {$step} سفارش #{$parent_id} به مبلغ {$amount} تومان گذشته و معوقه شده است.\n"
              . "جهت حفظ امتیاز خرید اقساطی لطفاً همین حالا تسویه فرمایید:\n{$pay_url}\n"
              . "vamareys.com";

        self::send_sms($phone, $text);
    }

    /**
     * Payment Receipt SMS (sent upon sub-order payment complete).
     */
    public static function send_payment_receipt_sms($sub_order_id) {
        $sub_order = function_exists('wc_get_order') ? wc_get_order($sub_order_id) : false;
        if (!$sub_order) {
            return;
        }

        $phone = self::normalize_phone($sub_order->get_billing_phone());
        if (empty($phone)) {
            return;
        }

        $name = trim($sub_order->get_billing_first_name() . ' ' . $sub_order->get_billing_last_name());
        if (empty($name)) {
            $name = 'مشتری گرامی';
        }

        $parent_id = $sub_order->get_meta('_vamariz_parent_order_id', true);
        $step      = $sub_order->get_meta('_vamariz_installment_step', true);
        $amount    = number_format((float) $sub_order->get_total());

        $text = "واماریز\n"
              . "سلام {$name} عزیز،\n"
              . "قسط {$step} سفارش #{$parent_id} به مبلغ {$amount} تومان با موفقیت تسویه شد.\n"
              . "از خوش‌حسابی شما سپاسگزاریم.\n"
              . "vamareys.com";

        self::send_sms($phone, $text);
    }

    /**
     * Invitation SMS.
     */
    public static function send_invite_sms($phone, $name, $invite_url) {
        $clean_phone = self::normalize_phone($phone);
        if (empty($clean_phone)) {
            return array('success' => false, 'message' => 'شماره همراه نامعتبر است.');
        }

        $text = "واماریز\n"
              . "سلام {$name} عزیز،\n"
              . "دعوت‌نامه اختصاصی خرید اقساطی ۴ ماهه بدون سود و ضامن برای شما صادر شد.\n"
              . "جهت فعال‌سازی و خرید روی لینک زیر کلیک کنید:\n{$invite_url}\n"
              . "واماریز - خرید هوشمندانه و آسان\nvamareys.com";

        return self::send_sms($clean_phone, $text);
    }

    /**
     * Run daily reminders cron.
     */
    public function run_daily_reminders() {
        if (!function_exists('wc_get_orders')) {
            return;
        }

        $now = current_time('timestamp');
        $sub_orders = wc_get_orders(array(
            'status'     => array('pending', 'on-hold', 'wc-pending', 'wc-on-hold'),
            'limit'      => 100,
            'meta_key'   => '_vamariz_is_sub_order',
            'meta_value' => 'yes',
        ));

        if (empty($sub_orders)) {
            return;
        }

        foreach ($sub_orders as $sub_order) {
            $due_date = (int) $sub_order->get_meta('_vamariz_due_date', true);
            if (!$due_date) {
                continue;
            }

            // Check parent order status
            $parent_id = (int) $sub_order->get_meta('_vamariz_parent_order_id', true);
            $parent = function_exists('wc_get_order') ? wc_get_order($parent_id) : false;
            if ($parent && in_array($parent->get_status(), array('cancelled', 'refunded', 'failed', 'trash'), true)) {
                continue;
            }

            $diff_days = round(($due_date - $now) / 86400);

            // 1. 3 Days Before Due
            if ($diff_days <= 3 && $diff_days > 0) {
                $sent_3d = $sub_order->get_meta('_vamariz_reminder_sent_3d', true);
                if ($sent_3d !== 'yes') {
                    self::send_reminder_sms($sub_order->get_id(), 3);
                    $sub_order->update_meta_data('_vamariz_reminder_sent_3d', 'yes');
                    $sub_order->save();
                }
            }

            // 2. Day of Due Date
            if ($diff_days <= 0 && $diff_days >= -1) {
                $sent_day = $sub_order->get_meta('_vamariz_reminder_sent_day', true);
                if ($sent_day !== 'yes') {
                    self::send_reminder_sms($sub_order->get_id(), 0);
                    $sub_order->update_meta_data('_vamariz_reminder_sent_day', 'yes');
                    $sub_order->save();
                }
            }

            // 3. Overdue Notice (+1 day past due)
            if ($diff_days < -1) {
                $sent_overdue = $sub_order->get_meta('_vamariz_overdue_sent', true);
                if ($sent_overdue !== 'yes') {
                    self::send_overdue_sms($sub_order->get_id());
                    $sub_order->update_meta_data('_vamariz_overdue_sent', 'yes');
                    $sub_order->save();
                }
            }
        }
    }
}
