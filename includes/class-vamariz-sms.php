<?php
/**
 * Vamareys Melipayamak SMS Integration & Pattern Engine (وب‌سرویس خدماتی اشتراکی)
 *
 * @package VamareysInstallments
 */

if (!defined('ABSPATH')) {
    exit;
}

class Vamariz_SMS {

    const BASE_SERVICE_URL = 'https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber';
    const SIMPLE_API_URL   = 'https://console.melipayamak.com/api/send/simple/1cf6abc67a3c471e9a61c617e82e394f';
    const DEFAULT_FROM     = '50004001';

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
     * Translate Melipayamak BaseServiceNumber error codes according to official documentation.
     */
    public static function get_error_message_by_code($code) {
        $code = trim((string)$code);
        $map = array(
            '-110' => 'الزام استفاده از ApiKey به جای رمز عبور در تنظیمات پنل',
            '-109' => 'الزام تنظیم IP مجاز برای استفاده از API در سامانه ملی‌پیامک',
            '-108' => 'مسدود شدن IP به دلیل تلاش‌های ناموفق مکرر در سامانه',
            '-10'  => 'ممنوعیت ارسال لینک در متغیرها (طبق قوانین مخابرات متغیرها نباید شامل آدرس اینترنتی باشند)',
            '-6'   => 'خطای داخلی سامانه ملی‌پیامک رخ داده است. با پشتیبانی سامانه تماس بگیرید.',
            '-5'   => 'متن و تعداد متغیرهای ارسالی با الگوی ثبت‌شده در سامانه ملی‌پیامک همخوانی ندارد.',
            '-4'   => 'کد متن پیش‌فرض (BodyId) نامعتبر است و یا هنوز توسط مدیر سامانه تایید نشده است.',
            '-3'   => 'خط خدماتی ارسالی در سیستم تعریف نشده است.',
            '-2'   => 'محدودیت تعداد شماره (فقط ارسال به یک شماره موبایل در هر درخواست مجاز است).',
            '-1'   => 'دسترسی برای استفاده از این وب‌سرویس غیرفعال است. با پشتیبانی سامانه تماس بگیرید.',
            '0'    => 'نام کاربری یا کلمه عبور سامانه پیامک اشتباه است.',
            '2'    => 'اعتبار ریالی یا پیامکی پنل شما کافی نمی‌باشد.',
            '6'    => 'سامانه پیامک در حال بروزرسانی می‌باشد.',
            '7'    => 'متن حاوی کلمات فیلترشده است. با واحد اداری سامانه تماس بگیرید.',
            '10'   => 'کاربر مورد نظر در سامانه فعال نمی‌باشد.',
            '11'   => 'پیامک ارسال نشد.',
            '12'   => 'مدارک و احراز هویت کاربر در سامانه ملی‌پیامک کامل نمی‌باشد.',
            '18'   => 'شماره موبایل معتبر نمی‌باشد.',
            '19'   => 'سقف مجاز روزانه ارسال وب‌سرویس تکمیل شده است.',
        );

        return isset($map[$code]) ? $map[$code] : "خطای وب‌سرویس خدماتی (کد {$code})";
    }

    /**
     * Send SMS via Melipayamak BaseServiceNumber Pattern API (وب‌سرویس خدماتی اشتراکی).
     *
     * @param string $to Recipient phone number (e.g. 09123456789).
     * @param int $body_id Approved pattern ID from Melipayamak.
     * @param array $variables Ordered array of variables for the pattern.
     * @return array Result array with status, recId, and message.
     */
    public static function send_pattern_sms($to, $body_id, array $variables = array()) {
        $username = trim((string) get_option('vamariz_sms_username', ''));
        $password = trim((string) get_option('vamariz_sms_password', ''));

        if (empty($username) || empty($password)) {
            return array(
                'success' => false,
                'message' => 'نام کاربری یا کلمه عبور وب‌سرویس ملی‌پیامک در تنظیمات ثبت نشده است.',
            );
        }

        $clean_to = self::normalize_phone($to);
        if (empty($clean_to)) {
            return array(
                'success' => false,
                'message' => 'شماره موبایل گیرنده معتبر نیست (باید 11 رقمی با فرمت 09 باشد).',
            );
        }

        $body_id = absint($body_id);
        if ($body_id <= 0) {
            return array(
                'success' => false,
                'message' => 'کد الگوی وب‌سرویس (BodyId) نامعتبر یا خالی است.',
            );
        }

        // Strictly clean variables: strip tags and protocols (prevent error -10)
        $cleaned_vars = array();
        foreach ($variables as $var) {
            $val = strip_tags((string) $var);
            // Remove protocol to strictly avoid -10 error if url was passed by accident
            $val = preg_replace('#^https?://#i', '', $val);
            $val = str_replace(array(';', "\r", "\n"), array(' ', ' ', ' '), $val);
            $cleaned_vars[] = trim($val);
        }

        // Variables must be separated by semicolon (;) according to Payamak-Panel docs
        $text = implode(';', $cleaned_vars);

        $payload = array(
            'username' => $username,
            'password' => $password,
            'text'     => $text,
            'to'       => $clean_to,
            'bodyId'   => $body_id,
        );

        if (!function_exists('wp_remote_post')) {
            return array('success' => false, 'message' => 'WordPress HTTP API not loaded.');
        }

        $endpoint = self::BASE_SERVICE_URL;

        $response = wp_remote_post($endpoint, array(
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
                'message' => 'خطا در برقراری ارتباط با وب‌سرویس: ' . $response->get_error_message(),
            );
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!$data || !is_array($data)) {
            return array(
                'success' => false,
                'message' => 'پاسخ نامعتبر از سرور ملی‌پیامک دریافت شد (کد ' . $code . ').',
                'raw'     => $body,
            );
        }

        $ret_status     = isset($data['RetStatus']) ? (int) $data['RetStatus'] : -999;
        $str_ret_status = isset($data['StrRetStatus']) ? (string) $data['StrRetStatus'] : '';
        $value          = isset($data['Value']) ? (string) $data['Value'] : '';

        // Success condition: RetStatus == 1 AND StrRetStatus == "Ok" (Value contains recId)
        if ($ret_status === 1 && (strcasecmp($str_ret_status, 'ok') === 0 || strlen($value) >= 10)) {
            return array(
                'success' => true,
                'recId'   => $value,
                'raw'     => $data,
                'message' => 'پیامک خدماتی با شناسه پیگیری ' . $value . ' با موفقیت ارسال شد.',
            );
        }

        // Handle error from Value or RetStatus
        $err_code = !empty($value) ? $value : (string)$ret_status;
        $persian_err = self::get_error_message_by_code($err_code);

        return array(
            'success' => false,
            'code'    => $err_code,
            'message' => $persian_err,
            'raw'     => $data,
        );
    }

    /**
     * Fallback or direct text SMS via Melipayamak Simple API.
     */
    public static function send_sms($to, $text) {
        $api_url = get_option('vamariz_sms_api_url', self::SIMPLE_API_URL);
        if (empty($api_url)) {
            $api_url = self::SIMPLE_API_URL;
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
     * Definitions and recommended template texts for all 6 patterns.
     */
    public static function get_pattern_definitions() {
        return array(
            'order_confirmation' => array(
                'title'       => 'تایید ثبت سفارش و طرح اقساطی',
                'description' => 'بلافاصله پس از پرداخت موفق قسط اول و ثبت سفارش ارسال می‌شود.',
                'template'    => "واماریز\nسلام {0} عزیز،\nقسط اول سفارش {1} با موفقیت ثبت شد و سفارش شما آماده ارسال است.\nمبلغ هر یک از اقساط بعدی: {2} تومان\nمشاهده سفارش: vamareys.com/my-account/orders",
                'vars'        => array(
                    '{0}' => 'نام مشتری (مثال: علی رضایی)',
                    '{1}' => 'شماره سفارش اصلی (مثال: 1045)',
                    '{2}' => 'مبلغ هر قسط به تومان (مثال: 1,250,000)',
                ),
            ),
            'reminder_3d' => array(
                'title'       => 'یادآوری ۳ روز پیش از سررسید قسط',
                'description' => 'توسط کران‌جاب روزانه، ۳ روز مانده به سررسید ارسال می‌گردد.',
                'template'    => "واماریز\nسلام {0} عزیز،\nیادآوری: ۳ روز تا موعد قسط {1} سفارش {2} به مبلغ {3} تومان باقیمانده است.\nپرداخت آنلاین: vamareys.com/?vamareys_pay={4}",
                'vars'        => array(
                    '{0}' => 'نام مشتری (مثال: علی رضایی)',
                    '{1}' => 'شماره قسط (مثال: دوم)',
                    '{2}' => 'شماره سفارش اصلی (مثال: 1045)',
                    '{3}' => 'مبلغ قسط به تومان (مثال: 1,250,000)',
                    '{4}' => 'شناسه پرداخت آنلاین (فقط عدد، مثال: 1089)',
                ),
            ),
            'reminder_day' => array(
                'title'       => 'یادآوری روز موعد قسط',
                'description' => 'در روز سررسید قسط در ساعت ۹:۰۰ صبح ارسال می‌شود.',
                'template'    => "واماریز\nسلام {0} عزیز،\nامروز موعد پرداخت قسط {1} سفارش {2} به مبلغ {3} تومان می‌باشد.\nپرداخت آنلاین: vamareys.com/?vamareys_pay={4}\nبا تشکر از خوش‌حسابی شما",
                'vars'        => array(
                    '{0}' => 'نام مشتری',
                    '{1}' => 'شماره قسط (مثال: دوم)',
                    '{2}' => 'شماره سفارش اصلی',
                    '{3}' => 'مبلغ قسط به تومان',
                    '{4}' => 'شناسه پرداخت آنلاین (فقط عدد)',
                ),
            ),
            'overdue' => array(
                'title'       => 'اخطار قسط معوقه',
                'description' => 'در صورت سپری شدن موعد و عدم تسویه قسط ارسال می‌شود.',
                'template'    => "واماریز\nسلام {0} عزیز،\nموعد قسط {1} سفارش {2} به مبلغ {3} تومان سپری شده است.\nجهت حفظ امتیاز خرید اقساطی نسبت به تسویه اقدام فرمایید:\nپرداخت آنلاین: vamareys.com/?vamareys_pay={4}",
                'vars'        => array(
                    '{0}' => 'نام مشتری',
                    '{1}' => 'شماره قسط (مثال: دوم)',
                    '{2}' => 'شماره سفارش اصلی',
                    '{3}' => 'مبلغ قسط به تومان',
                    '{4}' => 'شناسه پرداخت آنلاین (فقط عدد)',
                ),
            ),
            'receipt' => array(
                'title'       => 'رسید پرداخت موفق قسط',
                'description' => 'بلافاصله پس از پرداخت موفق هر قسط توسط مشتری ارسال می‌شود.',
                'template'    => "واماریز\nسلام {0} عزیز،\nقسط {1} سفارش {2} به مبلغ {3} تومان با موفقیت تسویه شد.\nاز خوش‌حسابی شما سپاسگزاریم.\nواماریز: vamareys.com",
                'vars'        => array(
                    '{0}' => 'نام مشتری',
                    '{1}' => 'شماره قسط (مثال: دوم)',
                    '{2}' => 'شماره سفارش اصلی',
                    '{3}' => 'مبلغ قسط به تومان',
                ),
            ),
            'invite' => array(
                'title'       => 'ارسال دعوت‌نامه اقساطی',
                'description' => 'هنگام صدور دعوت‌نامه برای مشتری جدید از پیشخوان ارسال می‌شود.',
                'template'    => "واماریز\nسلام {0} عزیز،\nدعوت‌نامه خرید اقساطی ۴ ماهه بدون کارمزد برای شما صادر شد.\nفعال‌سازی در لینک: vamareys.com/?vamareys_invite={1}",
                'vars'        => array(
                    '{0}' => 'نام مشتری (مثال: علی رضایی)',
                    '{1}' => 'توکن دعوت (رشته متنی، مثال: vmz_a8b9c1)',
                ),
            ),
        );
    }

    /**
     * Convert step number to Persian word.
     */
    public static function get_step_label($step) {
        switch ((int) $step) {
            case 1: return 'اول';
            case 2: return 'دوم';
            case 3: return 'سوم';
            case 4: return 'چهارم';
            default: return (string) $step;
        }
    }

    /**
     * Send Order Confirmation SMS.
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

        $total_amount = (float) $order->get_meta('_vamariz_total_order_amount', true);
        if (!$total_amount) {
            $total_amount = (float) $order->get_total() * 4;
        }
        $step_amount = number_format(round($total_amount / 4));

        $body_id = absint(get_option('vamariz_pattern_order_confirmation_id', 0));
        $enabled = get_option('vamariz_pattern_order_confirmation_enabled', 'yes') === 'yes';

        if ($enabled && $body_id > 0) {
            // Pattern parameters: {0} Name, {1} Order ID, {2} Step Amount
            return self::send_pattern_sms($phone, $body_id, array($name, $order->get_id(), $step_amount));
        }

        // Fallback to text message if pattern is not configured
        $text = "واماریز\n"
              . "سلام {$name} عزیز،\n"
              . "قسط اول سفارش #{$order->get_id()} با موفقیت ثبت شد و سفارش شما آماده ارسال است.\n"
              . "مبلغ هر قسط: {$step_amount} تومان\n"
              . "مشاهده سفارش: vamareys.com/my-account/orders";
        return self::send_sms($phone, $text);
    }

    /**
     * Send Reminder SMS (3 days before or day of due date).
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

        $parent_id  = $sub_order->get_meta('_vamariz_parent_order_id', true);
        $step_num   = (int) $sub_order->get_meta('_vamariz_installment_step', true);
        $step_label = self::get_step_label($step_num);
        $amount     = number_format((float) $sub_order->get_total());
        $sub_id     = $sub_order->get_id();

        if ($days_left === 3) {
            $body_id = absint(get_option('vamariz_pattern_reminder_3d_id', 0));
            $enabled = get_option('vamariz_pattern_reminder_3d_enabled', 'yes') === 'yes';
            if ($enabled && $body_id > 0) {
                // Pattern parameters: {0} Name, {1} Step Label, {2} Parent ID, {3} Amount, {4} Sub ID
                return self::send_pattern_sms($phone, $body_id, array($name, $step_label, $parent_id, $amount, $sub_id));
            }
        } else {
            $body_id = absint(get_option('vamariz_pattern_reminder_day_id', 0));
            $enabled = get_option('vamariz_pattern_reminder_day_enabled', 'yes') === 'yes';
            if ($enabled && $body_id > 0) {
                // Pattern parameters: {0} Name, {1} Step Label, {2} Parent ID, {3} Amount, {4} Sub ID
                return self::send_pattern_sms($phone, $body_id, array($name, $step_label, $parent_id, $amount, $sub_id));
            }
        }

        // Fallback to text message
        $pay_url = home_url('/?vamareys_pay=' . $sub_id);
        $text = "واماریز\n"
              . "سلام {$name} عزیز،\n"
              . "موعد قسط {$step_label} سفارش #{$parent_id} به مبلغ {$amount} تومان فرا رسیده است.\n"
              . "پرداخت آنلاین:\n{$pay_url}\n"
              . "vamareys.com";
        return self::send_sms($phone, $text);
    }

    /**
     * Send Overdue Installment SMS.
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

        $parent_id  = $sub_order->get_meta('_vamariz_parent_order_id', true);
        $step_num   = (int) $sub_order->get_meta('_vamariz_installment_step', true);
        $step_label = self::get_step_label($step_num);
        $amount     = number_format((float) $sub_order->get_total());
        $sub_id     = $sub_order->get_id();

        $body_id = absint(get_option('vamariz_pattern_overdue_id', 0));
        $enabled = get_option('vamariz_pattern_overdue_enabled', 'yes') === 'yes';

        if ($enabled && $body_id > 0) {
            // Pattern parameters: {0} Name, {1} Step Label, {2} Parent ID, {3} Amount, {4} Sub ID
            return self::send_pattern_sms($phone, $body_id, array($name, $step_label, $parent_id, $amount, $sub_id));
        }

        // Fallback
        $pay_url = home_url('/?vamareys_pay=' . $sub_id);
        $text = "واماریز - اخطار سررسید قسط\n"
              . "سلام {$name} عزیز،\n"
              . "موعد قسط {$step_label} سفارش #{$parent_id} گذشته است.\n"
              . "پرداخت آنلاین:\n{$pay_url}\n"
              . "vamareys.com";
        return self::send_sms($phone, $text);
    }

    /**
     * Send Payment Receipt SMS.
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

        $parent_id  = $sub_order->get_meta('_vamariz_parent_order_id', true);
        $step_num   = (int) $sub_order->get_meta('_vamariz_installment_step', true);
        $step_label = self::get_step_label($step_num);
        $amount     = number_format((float) $sub_order->get_total());

        $body_id = absint(get_option('vamariz_pattern_receipt_id', 0));
        $enabled = get_option('vamariz_pattern_receipt_enabled', 'yes') === 'yes';

        if ($enabled && $body_id > 0) {
            // Pattern parameters: {0} Name, {1} Step Label, {2} Parent ID, {3} Amount
            return self::send_pattern_sms($phone, $body_id, array($name, $step_label, $parent_id, $amount));
        }

        // Fallback
        $text = "واماریز\n"
              . "سلام {$name} عزیز،\n"
              . "قسط {$step_label} سفارش #{$parent_id} به مبلغ {$amount} تومان با موفقیت تسویه شد.\n"
              . "از خوش‌حسابی شما سپاسگزاریم.\nvamareys.com";
        return self::send_sms($phone, $text);
    }

    /**
     * Send Invitation SMS.
     */
    public static function send_invite_sms($phone, $name, $invite_token) {
        $clean_phone = self::normalize_phone($phone);
        if (empty($clean_phone)) {
            return array('success' => false, 'message' => 'شماره همراه نامعتبر است.');
        }

        $body_id = absint(get_option('vamariz_pattern_invite_id', 0));
        $enabled = get_option('vamariz_pattern_invite_enabled', 'yes') === 'yes';

        if ($enabled && $body_id > 0) {
            // Pattern parameters: {0} Name, {1} Invite Token string (zero protocols or links)
            return self::send_pattern_sms($clean_phone, $body_id, array($name, $invite_token));
        }

        // Fallback
        $invite_url = home_url('/?vamareys_invite=' . urlencode($invite_token));
        $text = "واماریز\n"
              . "سلام {$name} عزیز،\n"
              . "دعوت‌نامه خرید اقساطی ۴ ماهه بدون کارمزد برای شما صادر شد.\n"
              . "فعال‌سازی در لینک:\n{$invite_url}\n"
              . "vamareys.com";
        return self::send_sms($clean_phone, $text);
    }

    /**
     * Run daily reminders cron at 09:00 AM.
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
