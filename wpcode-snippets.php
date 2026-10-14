<?php
/**
 * ============================================================================
 * کدهای آماده افزونه WPCode (یا فانکشن قالب functions.php) برای فروشگاه واماریز
 * ============================================================================
 * 
 * اگر به هر دلیلی تمایل داشتید بخش‌هایی از سیستم را بدون نصب افزونه، مستقیماً
 * از طریق افزونه WPCode (Code Snippets) اجرا کنید، هر یک از بخش‌های زیر را به عنوان
 * یک PHP Snippet جداگانه در پنل WPCode اضافه و ذخیره نمایید.
 */

// ============================================================================
// اسنیپت شماره ۱: ثبت نقش «مشتری اقساطی» و امکان فعال‌سازی در پروفایل کاربر
// محل اجرا: Run Everywhere
// ============================================================================
add_action('init', function () {
    if (!function_exists('get_role') || !function_exists('add_role')) return;
    $customer = get_role('customer');
    $caps = ($customer && !empty($customer->capabilities)) ? (array) $customer->capabilities : array('read' => true);
    $caps['use_vamariz_installments'] = true;
    if (!get_role('installment_customer')) {
        add_role('installment_customer', 'مشتری اقساطی', $caps);
    }
}, 5);

add_action('show_user_profile', 'vamariz_wpcode_render_profile');
add_action('edit_user_profile', 'vamariz_wpcode_render_profile');
function vamariz_wpcode_render_profile($user) {
    if (!current_user_can('manage_woocommerce') && !current_user_can('promote_users')) return;
    $is_installment = in_array('installment_customer', (array) $user->roles, true);
    wp_nonce_field('vamariz_save_role_nonce_wpcode', 'vamariz_role_nonce_wpcode');
    ?>
    <h3 style="margin-top:20px;">خرید اقساطی واماریز</h3>
    <table class="form-table">
        <tr>
            <th><label for="vamariz_is_installment">وضعیت مشتری اقساطی</label></th>
            <td>
                <label>
                    <input type="checkbox" name="vamariz_is_installment" id="vamariz_is_installment" value="1" <?php checked($is_installment, true); ?>>
                    <strong>فعال بودن دسترسی به خرید اقساطی ۴ ماهه واماریز</strong>
                </label>
            </td>
        </tr>
    </table>
    <?php
}

add_action('personal_options_update', 'vamariz_wpcode_save_profile');
add_action('edit_user_profile_update', 'vamariz_wpcode_save_profile');
function vamariz_wpcode_save_profile($user_id) {
    if (!current_user_can('manage_woocommerce') && !current_user_can('promote_users')) return;
    if (!isset($_POST['vamariz_role_nonce_wpcode']) || !wp_verify_nonce($_POST['vamariz_role_nonce_wpcode'], 'vamariz_save_role_nonce_wpcode')) return;
    $user = get_userdata($user_id);
    if (!$user) return;
    if (!empty($_POST['vamariz_is_installment'])) {
        $user->add_role('installment_customer');
    } else {
        $user->remove_role('installment_customer');
    }
}


// ============================================================================
// اسنیپت شماره ۲: مخفی‌سازی اسنپ‌پی برای مشتریان اقساطی (در صفحه محصول و تسویه‌حساب)
// محل اجرا: Frontend Only
// ============================================================================
function vamariz_wpcode_is_customer() {
    if (!is_user_logged_in()) return false;
    $user = wp_get_current_user();
    if (!$user) return false;
    if (in_array('administrator', (array) $user->roles, true)) return true;
    return in_array('installment_customer', (array) $user->roles, true);
}

// حذف فیلتر اسنپ‌پی برای جلوگیری از کندی و خطاهای نال‌پوینتر در PHP 8.1
add_action('wp', function () {
    if (!is_admin() && vamariz_wpcode_is_customer()) {
        remove_filter('woocommerce_available_payment_gateways', 'show_snapppay_payment_gateway_based_on_cart_amount', PHP_INT_MAX);
    }
}, 5);

// مخفی کردن درگاه اسنپ‌پی در تسویه‌حساب برای مشتریان اقساطی
add_filter('woocommerce_available_payment_gateways', function ($gateways) {
    if (!is_admin() && vamariz_wpcode_is_customer()) {
        foreach ($gateways as $id => $gateway) {
            if (stripos($id, 'snapppay') !== false || (is_object($gateway) && stripos(get_class($gateway), 'snapppay') !== false)) {
                unset($gateways[$id]);
            }
        }
    }
    return $gateways;
}, PHP_INT_MAX);

// مخفی کردن ویجت اسنپ‌پی در صفحه محصول
add_action('wp_head', function () {
    if (is_product() && vamariz_wpcode_is_customer()) {
        echo '<style id="vamariz-hide-snapppay">.snapppay-pdp-widget, .snapppay-widget, #snapppay-widget, [class*="snapppay"] { display: none !important; }</style>';
    }
}, 999);


// ============================================================================
// اسنیپت شماره ۳: ارسال پیامک با وب‌سرویس خدماتی الگو (BaseServiceNumber) ملی‌پیامک
// محل اجرا: Run Everywhere
// ============================================================================
function vamariz_send_melipayamak_pattern_sms($to, $body_id, array $variables = array()) {
    $username = get_option('vamariz_sms_username', '');
    $password = get_option('vamariz_sms_password', '');

    if (empty($username) || empty($password)) {
        return array('success' => false, 'message' => 'نام کاربری یا کلمه عبور ملی‌پیامک ثبت نشده است.');
    }

    // نرمال‌سازی شماره گیرنده
    $persian = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    $arabic  = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    $english = ['0','1','2','3','4','5','6','7','8','9'];
    $phone   = str_replace($persian, $english, $to);
    $phone   = str_replace($arabic, $english, $phone);
    $phone   = preg_replace('/[^\d]/', '', $phone);
    if (strpos($phone, '0098') === 0) $phone = substr($phone, 4);
    elseif (strpos($phone, '98') === 0 && strlen($phone) === 12) $phone = substr($phone, 2);
    if (strpos($phone, '9') === 0 && strlen($phone) === 10) $phone = '0' . $phone;

    // پاکسازی متغیرها و حذف پروتکل جهت جلوگیری از خطای ۱۰-
    $cleaned = array();
    foreach ($variables as $v) {
        $val = strip_tags((string)$v);
        $val = preg_replace('#^https?://#i', '', $val);
        $val = str_replace([';', "\r", "\n"], [' ', ' ', ' '], $val);
        $cleaned[] = trim($val);
    }

    $payload = array(
        'username' => $username,
        'password' => $password,
        'text'     => implode(';', $cleaned),
        'to'       => $phone,
        'bodyId'   => absint($body_id),
    );

    $response = wp_remote_post('https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber', array(
        'method'    => 'POST',
        'timeout'   => 15,
        'headers'   => array('Content-Type' => 'application/json', 'Accept' => 'application/json'),
        'body'      => wp_json_encode($payload),
        'sslverify' => false,
    ));

    if (is_wp_error($response)) {
        return array('success' => false, 'message' => $response->get_error_message());
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (isset($data['RetStatus']) && (int)$data['RetStatus'] === 1) {
        return array('success' => true, 'recId' => $data['Value'] ?? '');
    }

    return array('success' => false, 'code' => $data['Value'] ?? '', 'message' => 'خطا در ارسال پیامک خدماتی.');
}


// ============================================================================
// اسنیپت شماره ۴: ریدایرکت مستقیم پرداخت اقساط (?vamariz_pay=ID)
// محل اجرا: Run Everywhere
// ============================================================================
add_action('init', function () {
    if (!empty($_GET['vamariz_pay'])) {
        $sub_id = absint($_GET['vamariz_pay']);
        if ($sub_id > 0 && function_exists('wc_get_order')) {
            $order = wc_get_order($sub_id);
            if ($order) {
                if ($order->is_paid() || in_array($order->get_status(), array('processing', 'completed'), true)) {
                    $view_url = method_exists($order, 'get_view_order_url') ? $order->get_view_order_url() : wc_get_account_endpoint_url('orders');
                    if (function_exists('wc_add_notice')) {
                        wc_add_notice('این قسط قبلاً با موفقیت تسویه شده است.', 'success');
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
});
