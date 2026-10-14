<?php
/**
 * Vamareys Admin Dashboard & Operations Center
 *
 * @package VamareysInstallments
 */

if (!defined('ABSPATH')) {
    exit;
}

class Vamariz_Admin {

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action('admin_menu', array($this, 'register_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('admin_init', array($this, 'handle_admin_actions'));
    }

    public function register_admin_menu() {
        add_menu_page(
            'اقساط واماریز (Vamareys)',
            'اقساط واماریز',
            'manage_woocommerce',
            'vamariz-installments',
            array($this, 'render_dashboard_page'),
            'dashicons-calendar-alt',
            56
        );
    }

    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'vamariz-installments') === false) {
            return;
        }

        wp_enqueue_style(
            'vamariz-admin-css',
            VAMARIZ_INSTALLMENTS_URL . 'assets/css/vamariz-admin.css',
            array(),
            VAMARIZ_INSTALLMENTS_VERSION
        );

        wp_enqueue_script(
            'vamariz-admin-js',
            VAMARIZ_INSTALLMENTS_URL . 'assets/js/vamariz-admin.js',
            array('jquery'),
            VAMARIZ_INSTALLMENTS_VERSION,
            true
        );
    }

    /**
     * Handle Admin Form Submissions.
     */
    public function handle_admin_actions() {
        if (!function_exists('current_user_can') || !current_user_can('manage_woocommerce')) {
            return;
        }

        // 1. Create Invite
        if (isset($_POST['vamariz_action']) && $_POST['vamariz_action'] === 'create_invite') {
            check_admin_referer('vamariz_admin_action', 'vamariz_nonce');
            $name  = sanitize_text_field($_POST['invite_name'] ?? '');
            $phone = sanitize_text_field($_POST['invite_phone'] ?? '');
            $sms   = !empty($_POST['send_sms']);

            if (!empty($name) && !empty($phone)) {
                $invite = Vamariz_Roles::create_invite($name, $phone);
                $notice = sprintf('دعوت‌نامه با توکن %s ایجاد گردید.', $invite['token']);

                if ($sms) {
                    $sms_res = Vamariz_SMS::send_invite_sms($phone, $name, $invite['token']);
                    if ($sms_res['success']) {
                        $notice .= ' پیامک دعوت با موفقیت ارسال شد.';
                    } else {
                        $notice .= ' ارسال پیامک با خطا مواجه شد: ' . $sms_res['message'];
                    }
                }
                set_transient('vamariz_admin_notice', array('type' => 'success', 'msg' => $notice), 45);
            }
            wp_safe_redirect(admin_url('admin.php?page=vamariz-installments&tab=invites'));
            exit;
        }

        // 2. Revoke Invite
        if (isset($_GET['vamariz_action']) && $_GET['vamariz_action'] === 'revoke_invite' && !empty($_GET['token'])) {
            check_admin_referer('vamariz_revoke_' . sanitize_text_field($_GET['token']));
            Vamariz_Roles::revoke_invite(sanitize_text_field($_GET['token']));
            set_transient('vamariz_admin_notice', array('type' => 'success', 'msg' => 'دعوت‌نامه با موفقیت باطل شد.'), 45);
            wp_safe_redirect(admin_url('admin.php?page=vamariz-installments&tab=invites'));
            exit;
        }

        // 3. Send Manual Reminder SMS
        if (isset($_POST['vamariz_action']) && $_POST['vamariz_action'] === 'manual_reminder') {
            check_admin_referer('vamariz_admin_action', 'vamariz_nonce');
            $sub_id = (int) ($_POST['sub_order_id'] ?? 0);
            if ($sub_id > 0) {
                $res = Vamariz_SMS::send_reminder_sms($sub_id, 0);
                if (is_array($res) && !empty($res['success'])) {
                    set_transient('vamariz_admin_notice', array('type' => 'success', 'msg' => 'پیامک یادآوری با موفقیت برای مشتری ارسال شد.'), 45);
                } else {
                    $err = is_array($res) && !empty($res['message']) ? $res['message'] : 'خطا در ارسال پیامک.';
                    set_transient('vamariz_admin_notice', array('type' => 'error', 'msg' => $err), 45);
                }
            }
            wp_safe_redirect(admin_url('admin.php?page=vamariz-installments&tab=orders'));
            exit;
        }

        // 4. Save Patterns
        if (isset($_POST['vamariz_action']) && $_POST['vamariz_action'] === 'save_patterns') {
            check_admin_referer('vamariz_admin_action', 'vamariz_nonce');
            $defs = Vamariz_SMS::get_pattern_definitions();
            foreach ($defs as $key => $def) {
                $body_id = absint($_POST['pattern_' . $key . '_id'] ?? 0);
                $enabled = !empty($_POST['pattern_' . $key . '_enabled']) ? 'yes' : 'no';
                update_option('vamariz_pattern_' . $key . '_id', $body_id);
                update_option('vamariz_pattern_' . $key . '_enabled', $enabled);
            }
            set_transient('vamariz_admin_notice', array('type' => 'success', 'msg' => 'تنظیمات الگوهای پیامک خدماتی با موفقیت ذخیره شدند.'), 45);
            wp_safe_redirect(admin_url('admin.php?page=vamariz-installments&tab=patterns'));
            exit;
        }

        // 5. Test Pattern SMS (BaseServiceNumber)
        if (isset($_POST['vamariz_action']) && $_POST['vamariz_action'] === 'test_pattern_sms') {
            check_admin_referer('vamariz_admin_action', 'vamariz_nonce');
            $test_phone   = sanitize_text_field($_POST['test_phone'] ?? '');
            $test_body_id = absint($_POST['test_body_id'] ?? 0);
            $raw_vars     = sanitize_text_field($_POST['test_variables'] ?? '');

            // Parse variables separated by ; or comma
            $vars = array();
            if (!empty($raw_vars)) {
                $raw_split = strpos($raw_vars, ';') !== false ? explode(';', $raw_vars) : explode(',', $raw_vars);
                foreach ($raw_split as $v) {
                    $vars[] = trim($v);
                }
            }

            $res = Vamariz_SMS::send_pattern_sms($test_phone, $test_body_id, $vars);
            if ($res['success']) {
                $msg = 'پیامک الگو با موفقیت ارسال شد. شناسه پیگیری در سامانه: ' . ($res['recId'] ?? '');
                set_transient('vamariz_admin_notice', array('type' => 'success', 'msg' => $msg), 45);
            } else {
                $msg = 'خطا در ارسال پیامک خدماتی الگو: ' . $res['message'];
                set_transient('vamariz_admin_notice', array('type' => 'error', 'msg' => $msg), 45);
            }
            wp_safe_redirect(admin_url('admin.php?page=vamariz-installments&tab=sms_test'));
            exit;
        }

        // 6. Test Direct SMS
        if (isset($_POST['vamariz_action']) && $_POST['vamariz_action'] === 'test_sms') {
            check_admin_referer('vamariz_admin_action', 'vamariz_nonce');
            $test_phone = sanitize_text_field($_POST['test_phone'] ?? '');
            $test_text  = sanitize_text_field($_POST['test_text'] ?? '');
            $res = Vamariz_SMS::send_sms($test_phone, $test_text);
            if ($res['success']) {
                $msg = 'پیامک تست با موفقیت ارسال شد. کد پیگیری: ' . ($res['recId'] ?? '');
                set_transient('vamariz_admin_notice', array('type' => 'success', 'msg' => $msg), 45);
            } else {
                $msg = 'خطا در ارسال پیامک تست: ' . $res['message'];
                set_transient('vamariz_admin_notice', array('type' => 'error', 'msg' => $msg), 45);
            }
            wp_safe_redirect(admin_url('admin.php?page=vamariz-installments&tab=sms_test'));
            exit;
        }

        // 7. Save Credentials & General Settings
        if (isset($_POST['vamariz_action']) && $_POST['vamariz_action'] === 'save_settings') {
            check_admin_referer('vamariz_admin_action', 'vamariz_nonce');
            if (isset($_POST['username'])) {
                update_option('vamariz_sms_username', sanitize_text_field($_POST['username']));
            }
            if (isset($_POST['password'])) {
                // If password was changed
                $pwd = sanitize_text_field($_POST['password']);
                if (!empty($pwd) && $pwd !== '********') {
                    update_option('vamariz_sms_password', $pwd);
                }
            }
            if (isset($_POST['from_number'])) {
                update_option('vamariz_sms_from_number', sanitize_text_field($_POST['from_number']));
            }
            if (isset($_POST['api_url'])) {
                update_option('vamariz_sms_api_url', esc_url_raw($_POST['api_url']));
            }
            if (isset($_POST['min_order'])) {
                update_option('vamariz_min_order_total', (float) $_POST['min_order']);
            }
            set_transient('vamariz_admin_notice', array('type' => 'success', 'msg' => 'تنظیمات با موفقیت ذخیره شدند.'), 45);
            wp_safe_redirect(admin_url('admin.php?page=vamariz-installments&tab=settings'));
            exit;
        }
    }

    /**
     * Render the Top-Level Dashboard.
     */
    public function render_dashboard_page() {
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'orders';
        $notice = get_transient('vamariz_admin_notice');
        if ($notice) {
            delete_transient('vamariz_admin_notice');
            echo '<div class="notice notice-' . esc_attr($notice['type']) . ' is-dismissible" style="margin-top:15px;"><p>' . esc_html($notice['msg']) . '</p></div>';
        }
        ?>
        <div class="wrap vamariz-dashboard-wrap" style="direction:rtl; text-align:right;">
            <div class="vamariz-dash-header">
                <div class="vamariz-dash-brand">
                    <h2><?php esc_html_e('پیشخوان مدیریت خرید اقساطی واماریز (Vamareys)', 'vamariz-installments'); ?></h2>
                    <span class="vamariz-badge-version">نسخه <?php echo esc_html(VAMARIZ_INSTALLMENTS_VERSION); ?></span>
                </div>
            </div>

            <nav class="nav-tab-wrapper wp-clearfix" style="margin-top: 15px;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=vamariz-installments&tab=orders')); ?>" class="nav-tab <?php echo $active_tab === 'orders' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('سفارش‌های اقساطی و پیگیری', 'vamariz-installments'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=vamariz-installments&tab=invites')); ?>" class="nav-tab <?php echo $active_tab === 'invites' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('مدیریت دعوت‌نامه‌ها', 'vamariz-installments'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=vamariz-installments&tab=patterns')); ?>" class="nav-tab <?php echo $active_tab === 'patterns' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('الگوهای وب‌سرویس خدماتی (الگو)', 'vamariz-installments'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=vamariz-installments&tab=sms_test')); ?>" class="nav-tab <?php echo $active_tab === 'sms_test' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('تست سامانه و الگوها', 'vamariz-installments'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=vamariz-installments&tab=settings')); ?>" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('تنظیمات پنل پیامک', 'vamariz-installments'); ?>
                </a>
            </nav>

            <div class="vamariz-tab-content" style="margin-top: 20px;">
                <?php
                switch ($active_tab) {
                    case 'invites':
                        $this->render_invites_tab();
                        break;
                    case 'patterns':
                        $this->render_patterns_tab();
                        break;
                    case 'sms_test':
                        $this->render_sms_test_tab();
                        break;
                    case 'settings':
                        $this->render_settings_tab();
                        break;
                    case 'orders':
                    default:
                        $this->render_orders_tab();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Orders & Installment Matrix Tab.
     */
    private function render_orders_tab() {
        if (!function_exists('wc_get_orders')) {
            echo '<p>ووکامرس فعال نیست.</p>';
            return;
        }

        $parent_orders = wc_get_orders(array(
            'limit'      => 50,
            'meta_key'   => '_vamariz_is_installment_order',
            'meta_value' => 'yes',
            'orderby'    => 'date',
            'order'      => 'DESC',
        ));

        $total_installments_count = count($parent_orders);
        $total_amount_sum = 0;
        $total_collected  = 0;
        $overdue_count    = 0;
        $now = current_time('timestamp');

        foreach ($parent_orders as $o) {
            $tot = (float) $o->get_meta('_vamariz_total_order_amount', true);
            $total_amount_sum += $tot;

            $schedule = $o->get_meta('_vamariz_payment_schedule', true);
            if (is_array($schedule)) {
                foreach ($schedule as $st) {
                    if (isset($st['status']) && $st['status'] === 'completed') {
                        $total_collected += (float) ($st['amount'] ?? 0);
                    } elseif (isset($st['due_date']) && $st['due_date'] < $now && isset($st['status']) && $st['status'] !== 'completed') {
                        $overdue_count++;
                    }
                }
            }
        }
        ?>
        <div class="vamariz-kpi-grid">
            <div class="vamariz-kpi-card">
                <span class="vamariz-kpi-title">تعداد سفارش‌های اقساطی</span>
                <span class="vamariz-kpi-value"><?php echo esc_html(number_format($total_installments_count)); ?></span>
            </div>
            <div class="vamariz-kpi-card">
                <span class="vamariz-kpi-title">ارزش کل خریدهای اقساطی</span>
                <span class="vamariz-kpi-value"><?php echo esc_html(number_format($total_amount_sum)); ?> تومان</span>
            </div>
            <div class="vamariz-kpi-card vamariz-kpi-success">
                <span class="vamariz-kpi-title">مجموع مبالغ وصول‌شده</span>
                <span class="vamariz-kpi-value"><?php echo esc_html(number_format($total_collected)); ?> تومان</span>
            </div>
            <div class="vamariz-kpi-card vamariz-kpi-warning">
                <span class="vamariz-kpi-title">اقساط معوقه</span>
                <span class="vamariz-kpi-value"><?php echo esc_html(number_format($overdue_count)); ?> قسط</span>
            </div>
        </div>

        <div class="vamariz-card-box" style="margin-top:25px; background:#fff; padding:20px; border:1px solid #ccd0d4; border-radius:6px;">
            <h3 style="margin-top:0;">جدول وضعیت اقساط سفارش‌ها</h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 80px;">سفارش</th>
                        <th style="width: 140px;">مشتری</th>
                        <th style="width: 110px;">مبلغ کل</th>
                        <th>قسط اول (۲۵٪)</th>
                        <th>قسط دوم (۳۰ روز)</th>
                        <th>قسط سوم (۶۰ روز)</th>
                        <th>قسط چهارم (۹۰ روز)</th>
                        <th style="width: 140px;">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if (empty($parent_orders)) {
                        echo '<tr><td colspan="8" style="text-align:center; padding:20px;">هیچ سفارش اقساطی یافت نشد.</td></tr>';
                    } else {
                        foreach ($parent_orders as $po) {
                            $order_id = $po->get_id();
                            $name     = trim($po->get_billing_first_name() . ' ' . $po->get_billing_last_name());
                            $phone    = $po->get_billing_phone();
                            $total    = number_format((float)$po->get_meta('_vamariz_total_order_amount', true));
                            $schedule = $po->get_meta('_vamariz_payment_schedule', true);

                            echo '<tr>';
                            echo '<td><strong><a href="' . esc_url($po->get_edit_order_url()) . '">#' . esc_html($order_id) . '</a></strong></td>';
                            echo '<td>' . esc_html($name) . '<br><small dir="ltr">' . esc_html($phone) . '</small></td>';
                            echo '<td>' . esc_html($total) . ' ت</td>';

                            for ($s = 1; $s <= 4; $s++) {
                                echo '<td>';
                                if (isset($schedule[$s])) {
                                    $step_data = $schedule[$s];
                                    $is_done   = ($step_data['status'] === 'completed');
                                    $due       = isset($step_data['due_date']) ? (int)$step_data['due_date'] : 0;
                                    $is_late   = (!$is_done && $due > 0 && $due < $now);

                                    if ($is_done) {
                                        echo '<span class="vamariz-pill vamariz-pill-success">پرداخت شد</span>';
                                    } elseif ($is_late) {
                                        echo '<span class="vamariz-pill vamariz-pill-danger">معوقه (' . date('m/d', $due) . ')</span>';
                                    } else {
                                        echo '<span class="vamariz-pill vamariz-pill-pending">سررسید: ' . ($due > 0 ? date('m/d', $due) : '—') . '</span>';
                                    }
                                } else {
                                    echo '—';
                                }
                                echo '</td>';
                            }

                            echo '<td>';
                            $sub_orders = wc_get_orders(array(
                                'parent'     => $order_id,
                                'status'     => array('pending', 'on-hold', 'wc-pending', 'wc-on-hold'),
                                'meta_key'   => '_vamariz_is_sub_order',
                                'meta_value' => 'yes',
                                'limit'      => 1,
                            ));
                            if (!empty($sub_orders)) {
                                $next_sub = reset($sub_orders);
                                echo '<form method="post" style="display:inline;">';
                                wp_nonce_field('vamariz_admin_action', 'vamariz_nonce');
                                echo '<input type="hidden" name="vamariz_action" value="manual_reminder">';
                                echo '<input type="hidden" name="sub_order_id" value="' . esc_attr($next_sub->get_id()) . '">';
                                echo '<button type="submit" class="button button-small" onclick="return confirm(\'آیا از ارسال پیامک یادآوری مطمئن هستید؟\');">ارسال پیامک یادآوری</button>';
                                echo '</form>';
                            } else {
                                echo '<span style="color:#46b450;">تسویه کامل</span>';
                            }
                            echo '</td>';
                            echo '</tr>';
                        }
                    }
                    ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Invites Tab.
     */
    private function render_invites_tab() {
        $invitations = Vamariz_Roles::get_all_invites();
        ?>
        <div style="display:flex; gap:20px; flex-wrap:wrap;">
            <div class="vamariz-card-box" style="flex:1; min-width:320px; background:#fff; padding:20px; border:1px solid #ccd0d4; border-radius:6px;">
                <h3 style="margin-top:0;">صدور دعوت‌نامه جدید برای مشتری</h3>
                <form method="post">
                    <?php wp_nonce_field('vamariz_admin_action', 'vamariz_nonce'); ?>
                    <input type="hidden" name="vamariz_action" value="create_invite">

                    <p>
                        <label><strong>نام و نام خانوادگی مشتری:</strong></label><br>
                        <input type="text" name="invite_name" class="regular-text" style="width:100%;" required placeholder="مثلاً: علی رضایی">
                    </p>
                    <p>
                        <label><strong>شماره موبایل مشتری:</strong></label><br>
                        <input type="text" name="invite_phone" class="regular-text" style="width:100%;" dir="ltr" required placeholder="09123456789">
                    </p>
                    <p>
                        <label>
                            <input type="checkbox" name="send_sms" value="1" checked>
                            <strong>ارسال خودکار پیامک حاوی لینک فعال‌سازی برای کاربر</strong>
                        </label>
                    </p>
                    <p style="margin-top:20px;">
                        <button type="submit" class="button button-primary">صدور و ثبت دعوت‌نامه</button>
                    </p>
                </form>
            </div>

            <div class="vamariz-card-box" style="flex:2; min-width:480px; background:#fff; padding:20px; border:1px solid #ccd0d4; border-radius:6px;">
                <h3 style="margin-top:0;">لیست دعوت‌نامه‌های صادر شده</h3>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>نام</th>
                            <th>شماره همراه</th>
                            <th>وضعیت</th>
                            <th>لینک دعوت</th>
                            <th style="width:70px;">عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if (empty($invitations)) {
                            echo '<tr><td colspan="5" style="text-align:center; padding:15px;">دعوت‌نامه‌ای ثبت نشده است.</td></tr>';
                        } else {
                            foreach (array_reverse($invitations) as $inv) {
                                $is_used = !empty($inv['used']);
                                $link    = Vamariz_Roles::get_invite_url($inv['token']);
                                echo '<tr>';
                                echo '<td><strong>' . esc_html($inv['name']) . '</strong></td>';
                                echo '<td dir="ltr">' . esc_html($inv['phone']) . '</td>';
                                echo '<td>';
                                if ($is_used) {
                                    echo '<span class="vamariz-pill vamariz-pill-success">استفاده شده (کاربر #' . esc_html($inv['user_id'] ?? '') . ')</span>';
                                } else {
                                    echo '<span class="vamariz-pill vamariz-pill-pending">فعال و منتظر ورود</span>';
                                }
                                echo '</td>';
                                echo '<td><input type="text" readonly value="' . esc_attr($link) . '" class="code" style="width:100%;" onclick="this.select();"></td>';
                                echo '<td>';
                                if (!$is_used) {
                                    $revoke_url = wp_nonce_url(admin_url('admin.php?page=vamariz-installments&vamariz_action=revoke_invite&token=' . $inv['token']), 'vamariz_revoke_' . $inv['token']);
                                    echo '<a href="' . esc_url($revoke_url) . '" class="button button-small button-link-delete" onclick="return confirm(\'آیا از ابطال این دعوت‌نامه اطمینان دارید؟\');">ابطال</a>';
                                } else {
                                    echo '—';
                                }
                                echo '</td>';
                                echo '</tr>';
                            }
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    /**
     * Patterns Management Tab (وب‌سرویس خدماتی الگو).
     */
    private function render_patterns_tab() {
        $defs = Vamariz_SMS::get_pattern_definitions();
        ?>
        <div class="vamariz-notice-box" style="background:#e7f3fe; border-right:4px solid #2196F3; padding:15px 20px; border-radius:4px; margin-bottom:20px;">
            <h4 style="margin:0 0 8px 0; color:#0b518c; font-size:15px;">راهنمای وب‌سرویس خدماتی اشتراکی (الگوهای پیامک بدون بلک‌لیست)</h4>
            <p style="margin:0; font-size:13px; line-height:1.7; color:#1a364e;">
                طبق قوانین مخابرات و سامانه ملی‌پیامک، پیامک‌های حاوی لینک در متغیر با خطای <strong>۱۰- (ممنوعیت ارسال لینک در متغیرها)</strong> مواجه می‌شوند.
                در سیستم واماریز، کلیه آدرس‌ها و دامنه‌ها به صورت <strong>ثابت در متن الگو</strong> قرار داده شده‌اند و فقط شناسه‌ها به عنوان متغیر ارسال می‌شوند.
                <br>
                برای راه‌اندازی، متن هر یک از الگوهای زیر را کپی کرده و در پنل ملی‌پیامک در مسیر <strong>توسعه‌دهندگان &gt; وب‌سرویس خدماتی (الگو)</strong> ثبت نمایید.
                پس از تایید توسط پشتیبانی ملی‌پیامک، <strong>کد الگو (BodyId)</strong> را در کادر مربوطه در زیر وارد کرده و دکمه ذخیره را بزنید.
            </p>
        </div>

        <form method="post">
            <?php wp_nonce_field('vamariz_admin_action', 'vamariz_nonce'); ?>
            <input type="hidden" name="vamariz_action" value="save_patterns">

            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(450px, 1fr)); gap:20px;">
                <?php
                foreach ($defs as $key => $def) {
                    $saved_id = get_option('vamariz_pattern_' . $key . '_id', 0);
                    $enabled  = get_option('vamariz_pattern_' . $key . '_enabled', 'yes') === 'yes';
                    ?>
                    <div class="vamariz-card-box" style="background:#fff; border:1px solid #ccd0d4; border-radius:6px; padding:20px; display:flex; flex-direction:column; justify-content:space-between;">
                        <div>
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; border-bottom:1px solid #eee; padding-bottom:10px;">
                                <h3 style="margin:0; font-size:16px; color:#23282d;">
                                    <?php echo esc_html($def['title']); ?>
                                </h3>
                                <label style="cursor:pointer;">
                                    <input type="checkbox" name="pattern_<?php echo esc_attr($key); ?>_enabled" value="1" <?php checked($enabled, true); ?>>
                                    <span style="font-weight:600; font-size:13px;"><?php echo $enabled ? '<span style="color:#46b450;">فعال</span>' : '<span style="color:#999;">غیرفعال</span>'; ?></span>
                                </label>
                            </div>

                            <p style="color:#666; font-size:13px; margin:5px 0 12px 0;">
                                <?php echo esc_html($def['description']); ?>
                            </p>

                            <div style="margin-bottom:15px;">
                                <label for="pattern_<?php echo esc_attr($key); ?>_id" style="display:block; font-weight:bold; margin-bottom:5px;">
                                    کد متن پیش‌فرض / الگو (BodyId در سامانه ملی‌پیامک):
                                </label>
                                <input type="number" 
                                       name="pattern_<?php echo esc_attr($key); ?>_id" 
                                       id="pattern_<?php echo esc_attr($key); ?>_id" 
                                       value="<?php echo $saved_id ? esc_attr($saved_id) : ''; ?>" 
                                       placeholder="مثلاً: 184592" 
                                       class="regular-text" 
                                       style="width:100%; font-size:15px; font-family:monospace; font-weight:bold;" dir="ltr">
                            </div>

                            <div style="margin-bottom:15px;">
                                <label style="display:block; font-weight:bold; margin-bottom:5px;">
                                    متن الگو جهت ثبت در پنل ملی‌پیامک (دقیقاً کپی کنید):
                                </label>
                                <div style="position:relative;">
                                    <textarea id="tpl_text_<?php echo esc_attr($key); ?>" 
                                              readonly 
                                              rows="5" 
                                              style="width:100%; background:#f9f9f9; font-size:13px; line-height:1.6; border:1px solid #ddd; border-radius:4px; padding:10px; font-family:inherit; resize:none;"><?php echo esc_textarea($def['template']); ?></textarea>
                                    <button type="button" 
                                            class="button button-secondary vamariz-btn-copy" 
                                            data-target="tpl_text_<?php echo esc_attr($key); ?>" 
                                            style="margin-top:5px;">
                                        کپی متن الگو
                                    </button>
                                </div>
                            </div>

                            <div style="background:#f6f7f7; padding:10px 12px; border-radius:4px; font-size:12px; color:#555;">
                                <strong>متغیرهای ارسالی سیستم:</strong>
                                <ul style="margin:5px 15px 0 0; list-style-type:disc;">
                                    <?php foreach ($def['vars'] as $var_tag => $var_desc) : ?>
                                        <li><code><?php echo esc_html($var_tag); ?></code>: <?php echo esc_html($var_desc); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                <?php } ?>
            </div>

            <p class="submit" style="margin-top:25px;">
                <button type="submit" class="button button-primary button-large" style="font-size:15px; padding:5px 25px;">
                    ذخیره تغییرات و کدهای الگو
                </button>
            </p>
        </form>
        <?php
    }

    /**
     * SMS Test Tab.
     */
    private function render_sms_test_tab() {
        $defs = Vamariz_SMS::get_pattern_definitions();
        ?>
        <div style="display:flex; gap:20px; flex-wrap:wrap;">
            <!-- 1. Test BaseServiceNumber Pattern -->
            <div class="vamariz-card-box" style="flex:1; min-width:350px; background:#fff; padding:25px; border:1px solid #ccd0d4; border-radius:6px;">
                <h3 style="margin-top:0; color:#0b518c;">تست وب‌سرویس خدماتی الگو (BaseServiceNumber)</h3>
                <p style="color:#666; font-size:13px;">تست ارسال با الگو از خط خدماتی اشتراکی بدون مسدودی بلک‌لیست:</p>
                
                <form method="post">
                    <?php wp_nonce_field('vamariz_admin_action', 'vamariz_nonce'); ?>
                    <input type="hidden" name="vamariz_action" value="test_pattern_sms">

                    <p>
                        <label><strong>شماره گیرنده تست:</strong></label><br>
                        <input type="text" name="test_phone" class="regular-text" style="width:100%;" dir="ltr" required placeholder="09123456789">
                    </p>
                    <p>
                        <label><strong>کد الگو (BodyId):</strong></label><br>
                        <input type="number" name="test_body_id" class="regular-text" style="width:100%;" dir="ltr" required placeholder="مثلاً: 123456">
                    </p>
                    <p>
                        <label><strong>متغیرهای ارسالی (با سمی‌کالن ; جدا کنید):</strong></label><br>
                        <input type="text" name="test_variables" class="regular-text" style="width:100%;" placeholder="علی رضایی;1050;1250000">
                        <span class="description" style="display:block; margin-top:4px;">مثال برای ۳ متغیر: <code>علی رضایی;1050;1250000</code></span>
                    </p>
                    <p style="margin-top:20px;">
                        <button type="submit" class="button button-primary">ارسال پیامک تستی با الگو</button>
                    </p>
                </form>
            </div>

            <!-- 2. Test Simple SMS -->
            <div class="vamariz-card-box" style="flex:1; min-width:350px; background:#fff; padding:25px; border:1px solid #ccd0d4; border-radius:6px;">
                <h3 style="margin-top:0;">تست ارسال ساده متنی (Simple API)</h3>
                <p style="color:#666; font-size:13px;">تست مستقیم با ارسال متن سفارشی (اختیاری):</p>
                <form method="post">
                    <?php wp_nonce_field('vamariz_admin_action', 'vamariz_nonce'); ?>
                    <input type="hidden" name="vamariz_action" value="test_sms">

                    <p>
                        <label><strong>شماره گیرنده تست:</strong></label><br>
                        <input type="text" name="test_phone" class="regular-text" style="width:100%;" dir="ltr" required placeholder="09123456789">
                    </p>
                    <p>
                        <label><strong>متن پیامک:</strong></label><br>
                        <textarea name="test_text" rows="4" style="width:100%;" required>واماریز
این یک پیامک آزمایشی جهت تایید صحت اتصال سامانه ملی‌پیامک است.
vamareys.com</textarea>
                    </p>
                    <p style="margin-top:20px;">
                        <button type="submit" class="button button-secondary">ارسال پیامک ساده</button>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Settings Tab.
     */
    private function render_settings_tab() {
        $username = get_option('vamariz_sms_username', '');
        $password = get_option('vamariz_sms_password', '');
        $from     = get_option('vamariz_sms_from_number', Vamariz_SMS::DEFAULT_FROM);
        $api_url  = get_option('vamariz_sms_api_url', Vamariz_SMS::SIMPLE_API_URL);
        $min_ord  = get_option('vamariz_min_order_total', 500000);
        ?>
        <div class="vamariz-card-box" style="background:#fff; padding:25px; border:1px solid #ccd0d4; border-radius:6px; max-width:750px;">
            <h3 style="margin-top:0;">تنظیمات احراز هویت وب‌سرویس ملی‌پیامک و فروشگاه</h3>
            <form method="post">
                <?php wp_nonce_field('vamariz_admin_action', 'vamariz_nonce'); ?>
                <input type="hidden" name="vamariz_action" value="save_settings">

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="username">نام کاربری سامانه ملی‌پیامک (Username)</label></th>
                        <td>
                            <input type="text" name="username" id="username" class="regular-text" style="width:100%;" dir="ltr" value="<?php echo esc_attr($username); ?>" required placeholder="نام کاربری ورود به پنل payamak-panel.com">
                            <p class="description">نام کاربری حساب کاربری شما در سامانه ملی‌پیامک.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="password">کلمه عبور سامانه / ApiKey</label></th>
                        <td>
                            <input type="password" name="password" id="password" class="regular-text" style="width:100%;" dir="ltr" value="<?php echo !empty($password) ? '********' : ''; ?>" placeholder="کلمه عبور یا کلید دسترسی وب‌سرویس">
                            <p class="description">رمز عبور ورود به سامانه یا کلید API اختصاصی شما.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="from_number">شماره فرستنده / خط خدماتی</label></th>
                        <td>
                            <input type="text" name="from_number" id="from_number" class="regular-text" dir="ltr" value="<?php echo esc_attr($from); ?>" required>
                            <p class="description">سرشماره خط خدماتی اشتراکی یا اختصاصی (پیش‌فرض: 50004001)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="min_order">حداقل مبلغ سبد خرید برای اقساط (تومان)</label></th>
                        <td>
                            <input type="number" name="min_order" id="min_order" class="regular-text" value="<?php echo esc_attr($min_ord); ?>" required min="0" step="50000">
                            <p class="description">سفارش‌های با مبلغ کمتر از این مقدار، امکان انتخاب خرید اقساطی ۴ ماهه را نخواهند داشت.</p>
                        </td>
                    </tr>
                </table>

                <p class="submit" style="margin-top:20px;">
                    <button type="submit" class="button button-primary">ذخیره تنظیمات</button>
                </p>
            </form>
        </div>
        <?php
    }
}
