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

        // 3.1 Manually mark installment sub-order as paid (Card-to-card / in-person)
        if (isset($_POST['vamariz_action']) && $_POST['vamariz_action'] === 'mark_installment_paid') {
            check_admin_referer('vamariz_admin_action', 'vamariz_nonce');
            $sub_id = absint($_POST['sub_order_id'] ?? 0);
            if ($sub_id > 0 && function_exists('wc_get_order')) {
                $sub_order = wc_get_order($sub_id);
                if ($sub_order) {
                    $sub_order->payment_complete();
                    set_transient('vamariz_admin_notice', array(
                        'type' => 'success',
                        'msg'  => sprintf('قسط شماره #%d با موفقیت به عنوان تسویه شده ثبت گردید.', $sub_id)
                    ), 45);
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

        $current_filter = sanitize_text_field($_GET['order_filter'] ?? 'active');
        $search_query   = sanitize_text_field($_GET['s'] ?? '');
        $now            = current_time('timestamp');

        // Fetch installment orders
        $raw_orders = wc_get_orders(array(
            'limit'      => 150,
            'meta_key'   => '_vamariz_is_installment_order',
            'meta_value' => 'yes',
            'orderby'    => 'date',
            'order'      => 'DESC',
        ));

        $valid_orders_count = 0;
        $total_amount_sum   = 0;
        $total_collected    = 0;
        $total_overdue      = 0;
        $active_count       = 0;
        $completed_count    = 0;
        $failed_count       = 0;

        $analyzed_orders = array();

        foreach ($raw_orders as $o) {
            $status     = $o->get_status();
            $schedule   = $o->get_meta('_vamariz_payment_schedule', true);
            if (!is_array($schedule)) {
                $schedule = array();
            }

            // Step 1 check
            $step1_paid = isset($schedule[1]['status']) && $schedule[1]['status'] === 'completed';
            $is_failed  = in_array($status, array('failed', 'cancelled', 'refunded', 'pending', 'wc-failed', 'wc-cancelled', 'wc-refunded', 'wc-pending'), true) && !$step1_paid;

            if ($is_failed) {
                $order_type = 'failed';
                $sub_status = 'failed';
                $failed_count++;
                $paid_steps = 0;
            } else {
                $order_type = 'valid';
                $valid_orders_count++;

                $tot = (float) $o->get_meta('_vamariz_total_order_amount', true);
                if ($tot <= 0) {
                    $tot = (float) $o->get_total() * 4;
                }
                $total_amount_sum += $tot;

                $paid_steps  = 0;
                $has_overdue = false;

                for ($s = 1; $s <= 4; $s++) {
                    $st = $schedule[$s] ?? null;
                    $is_done = ($st && isset($st['status']) && $st['status'] === 'completed');
                    if ($is_done) {
                        $paid_steps++;
                        $total_collected += (float) ($st['amount'] ?? ($tot / 4));
                    } else {
                        $due = isset($st['due_date']) ? (int) $st['due_date'] : 0;
                        if ($due > 0 && $due < $now) {
                            $has_overdue = true;
                            $total_overdue++;
                        }
                    }
                }

                if ($paid_steps === 4 || $status === 'completed') {
                    $sub_status = 'completed';
                    $completed_count++;
                } elseif ($has_overdue) {
                    $sub_status = 'overdue';
                    $active_count++;
                } else {
                    $sub_status = 'active';
                    $active_count++;
                }
            }

            $analyzed_orders[] = array(
                'order'      => $o,
                'order_type' => $order_type,
                'sub_status' => $sub_status,
                'schedule'   => $schedule,
                'paid_steps' => $paid_steps,
            );
        }

        // Apply tab filter & search
        $display_orders = array();
        foreach ($analyzed_orders as $item) {
            $match = false;
            if ($current_filter === 'active' && $item['order_type'] === 'valid' && $item['sub_status'] !== 'completed') {
                $match = true;
            } elseif ($current_filter === 'overdue' && $item['order_type'] === 'valid' && $item['sub_status'] === 'overdue') {
                $match = true;
            } elseif ($current_filter === 'completed' && $item['order_type'] === 'valid' && $item['sub_status'] === 'completed') {
                $match = true;
            } elseif ($current_filter === 'all_valid' && $item['order_type'] === 'valid') {
                $match = true;
            } elseif ($current_filter === 'failed' && $item['order_type'] === 'failed') {
                $match = true;
            }

            if ($match && !empty($search_query)) {
                $po       = $item['order'];
                $haystack = $po->get_id() . ' ' . $po->get_billing_first_name() . ' ' . $po->get_billing_last_name() . ' ' . $po->get_billing_phone();
                if (stripos($haystack, $search_query) === false) {
                    $match = false;
                }
            }

            if ($match) {
                $display_orders[] = $item;
            }
        }
        ?>
        <!-- Accurate KPI Cards (Excludes Failed Orders) -->
        <div class="vamariz-stats-grid">
            <div class="vamariz-stat-card">
                <span class="vamariz-stat-label">اقساط فعال و جاری</span>
                <span class="vamariz-stat-value"><?php echo esc_html(number_format($active_count)); ?> <small>سفارش</small></span>
            </div>
            <div class="vamariz-stat-card highlight-green">
                <span class="vamariz-stat-label">مجموع مبالغ وصول‌شده</span>
                <span class="vamariz-stat-value"><?php echo esc_html(number_format($total_collected)); ?> <small>تومان</small></span>
            </div>
            <div class="vamariz-stat-card">
                <span class="vamariz-stat-label">ارزش کل اقساط معتبر</span>
                <span class="vamariz-stat-value"><?php echo esc_html(number_format($total_amount_sum)); ?> <small>تومان</small></span>
            </div>
            <div class="vamariz-stat-card <?php echo $total_overdue > 0 ? 'highlight-red' : ''; ?>">
                <span class="vamariz-stat-label">اقساط معوقه</span>
                <span class="vamariz-stat-value"><?php echo esc_html(number_format($total_overdue)); ?> <small>قسط</small></span>
            </div>
            <div class="vamariz-stat-card">
                <span class="vamariz-stat-label">تسویه‌شده کامل</span>
                <span class="vamariz-stat-value"><?php echo esc_html(number_format($completed_count)); ?> <small>سفارش</small></span>
            </div>
            <div class="vamariz-stat-card" style="opacity:0.85;">
                <span class="vamariz-stat-label">پیش‌پرداخت ناموفق</span>
                <span class="vamariz-stat-value" style="color:#64748b;"><?php echo esc_html(number_format($failed_count)); ?> <small>سفارش</small></span>
            </div>
        </div>

        <!-- Quick Payment Link Generator Card -->
        <div class="vamariz-quick-box" style="margin-top:22px;">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                <div>
                    <h4 style="margin:0 0 5px 0; font-size:15px; color:#1e293b;">
                        📋 ابزار کپی و ارسال سریع لینک پرداخت قسط به مشتری
                    </h4>
                    <span style="font-size:12px; color:#64748b;">
                        شناسه زیرسفارش قسط (Sub-order ID) را وارد کنید تا لینک پرداخت اختصاصی و پیامک آماده کپی تولید گردد:
                    </span>
                </div>
                <div style="display:flex; gap:8px; align-items:center;">
                    <input type="number" id="vamariz-quick-sub-id" placeholder="شناسه قسط (مثال: 1089)" style="width:170px; font-family:monospace; font-size:13px;" dir="ltr">
                    <button type="button" class="button button-primary" id="vamariz-gen-paylink-btn">تولید لینک پرداخت</button>
                </div>
            </div>
            <div id="vamariz-quick-output-box" style="display:none; margin-top:14px; padding-top:12px; border-top:1px dashed #cbd5e1;">
                <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                    <div style="flex:1; min-width:280px;">
                        <label style="font-size:11px; font-weight:600; display:block; margin-bottom:3px; color:#475569;">لینک مستقیم پرداخت قسط:</label>
                        <div style="display:flex; gap:6px;">
                            <input type="text" id="vamariz-quick-result-url" readonly style="width:100%; background:#fff; font-family:monospace; font-size:12px;" dir="ltr">
                            <button type="button" class="button button-secondary vamariz-copy-link-btn" id="vamariz-quick-copy-link-btn" data-copy-link="">کپی لینک</button>
                        </div>
                    </div>
                    <div style="flex:1.5; min-width:320px;">
                        <label style="font-size:11px; font-weight:600; display:block; margin-bottom:3px; color:#475569;">متن آماده جهت ارسال در واتساپ، بله یا پیامک عادی:</label>
                        <div style="display:flex; gap:6px;">
                            <input type="text" id="vamariz-quick-result-msg" readonly style="width:100%; background:#fff; font-size:12px;">
                            <button type="button" class="button button-secondary vamariz-copy-link-btn" id="vamariz-quick-copy-msg-btn" data-copy-link="">کپی پیام کامل</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Orders Table Card -->
        <div class="vamariz-card-box" style="background:#fff; padding:20px; border:1px solid #ccd0d4; border-radius:8px;">
            <!-- Filter Bar & Search -->
            <div class="vamariz-filters-row">
                <div class="vamariz-filter-group">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=vamariz-installments&tab=orders&order_filter=active')); ?>" 
                       class="vamariz-filter-btn <?php echo $current_filter === 'active' ? 'active' : ''; ?>">
                        اقساط فعال و جاری
                        <span class="vamariz-filter-count"><?php echo esc_html($active_count); ?></span>
                    </a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=vamariz-installments&tab=orders&order_filter=overdue')); ?>" 
                       class="vamariz-filter-btn <?php echo $current_filter === 'overdue' ? 'active' : ''; ?>" style="<?php echo $total_overdue > 0 ? 'color:#b91c1c;' : ''; ?>">
                        دارای قسط معوقه
                        <span class="vamariz-filter-count" style="<?php echo $total_overdue > 0 ? 'background:#fee2e2; color:#b91c1c;' : ''; ?>"><?php echo esc_html($total_overdue); ?></span>
                    </a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=vamariz-installments&tab=orders&order_filter=completed')); ?>" 
                       class="vamariz-filter-btn <?php echo $current_filter === 'completed' ? 'active' : ''; ?>">
                        تسویه‌شده کامل
                        <span class="vamariz-filter-count"><?php echo esc_html($completed_count); ?></span>
                    </a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=vamariz-installments&tab=orders&order_filter=all_valid')); ?>" 
                       class="vamariz-filter-btn <?php echo $current_filter === 'all_valid' ? 'active' : ''; ?>">
                        همه سفارش‌های معتبر
                        <span class="vamariz-filter-count"><?php echo esc_html($valid_orders_count); ?></span>
                    </a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=vamariz-installments&tab=orders&order_filter=failed')); ?>" 
                       class="vamariz-filter-btn <?php echo $current_filter === 'failed' ? 'active' : ''; ?>" style="color:#64748b;">
                        پیش‌پرداخت ناموفق / انصرافی
                        <span class="vamariz-filter-count"><?php echo esc_html($failed_count); ?></span>
                    </a>
                </div>

                <!-- Search Box -->
                <form method="get" style="display:flex; gap:6px;">
                    <input type="hidden" name="page" value="vamariz-installments">
                    <input type="hidden" name="tab" value="orders">
                    <input type="hidden" name="order_filter" value="<?php echo esc_attr($current_filter); ?>">
                    <input type="search" name="s" value="<?php echo esc_attr($search_query); ?>" placeholder="شماره سفارش، نام، موبایل..." style="width:200px; font-size:13px;">
                    <button type="submit" class="button">جستجو</button>
                    <?php if (!empty($search_query)) : ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=vamariz-installments&tab=orders&order_filter=' . $current_filter)); ?>" class="button button-link-delete" style="line-height:28px;">پاکسازی</a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Table -->
            <table class="wp-list-table widefat fixed striped vamariz-matrix-table">
                <thead>
                    <tr>
                        <th style="width: 125px;">سفارش و وضعیت</th>
                        <th style="width: 150px;">اطلاعات خریدار</th>
                        <th style="width: 130px;">مبلغ و پیشرفت</th>
                        <th>قسط اول (پیش‌پرداخت)</th>
                        <th>قسط دوم (۳۰ روزه)</th>
                        <th>قسط سوم (۶۰ روزه)</th>
                        <th>قسط چهارم (۹۰ روزه)</th>
                        <th style="width: 140px;">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if (empty($display_orders)) {
                        echo '<tr><td colspan="8" style="text-align:center; padding:35px 20px; color:#64748b;">';
                        if ($current_filter === 'failed') {
                            echo 'هیچ سفارش ناموفق یا انصرافی ثبت نشده است.';
                        } else {
                            echo 'هیچ سفارشی در این دسته‌بندی یافت نشد.';
                        }
                        echo '</td></tr>';
                    } else {
                        foreach ($display_orders as $item) {
                            $po         = $item['order'];
                            $order_id   = $po->get_id();
                            $status     = $po->get_status();
                            $order_type = $item['order_type'];
                            $schedule   = $item['schedule'];
                            $paid_steps = $item['paid_steps'];

                            $name  = trim($po->get_billing_first_name() . ' ' . $po->get_billing_last_name());
                            if (empty($name)) $name = 'مشتری گرامی';
                            $phone = $po->get_billing_phone();
                            $total = (float) $po->get_meta('_vamariz_total_order_amount', true);
                            if ($total <= 0) $total = (float) $po->get_total() * 4;

                            $pct = ($order_type === 'failed') ? 0 : round(($paid_steps / 4) * 100);

                            // Clean phone for WhatsApp
                            $clean_phone = preg_replace('/[^\d]/', '', $phone);
                            if (strpos($clean_phone, '0') === 0) {
                                $clean_phone = '98' . substr($clean_phone, 1);
                            }
                            ?>
                            <tr style="<?php echo $order_type === 'failed' ? 'background:#fafafa; opacity:0.85;' : ''; ?>">
                                <!-- 1. Order ID & Status -->
                                <td>
                                    <strong>
                                        <a href="<?php echo esc_url($po->get_edit_order_url()); ?>" title="مشاهده سفارش در ووکامرس">
                                            #<?php echo esc_html($order_id); ?>
                                        </a>
                                    </strong>
                                    <div style="margin-top:4px;">
                                        <?php
                                        if ($order_type === 'failed') {
                                            echo '<span class="vamariz-pill vamariz-pill-danger">پیش‌پرداخت ناموفق</span>';
                                        } elseif ($status === 'completed') {
                                            echo '<span class="vamariz-pill vamariz-pill-success">تکمیل‌شده (تسویه)</span>';
                                        } elseif ($status === 'processing') {
                                            echo '<span class="vamariz-pill vamariz-pill-success">پیش‌پرداخت تایید شد</span>';
                                        } elseif ($status === 'on-hold') {
                                            echo '<span class="vamariz-pill vamariz-pill-warning">در انتظار بررسی</span>';
                                        } else {
                                            echo '<span class="vamariz-pill vamariz-pill-secondary">' . esc_html(wc_get_order_status_name($status)) . '</span>';
                                        }
                                        ?>
                                    </div>
                                    <span style="font-size:11px; color:#888; display:block; margin-top:4px;">
                                        <?php echo esc_html($po->get_date_created() ? $po->get_date_created()->date('Y/m/d H:i') : ''); ?>
                                    </span>
                                </td>

                                <!-- 2. Customer Info -->
                                <td>
                                    <strong style="color:#1e293b;"><?php echo esc_html($name); ?></strong>
                                    <?php if (!empty($phone)) : ?>
                                        <div style="margin-top:3px; display:flex; align-items:center; gap:6px;">
                                            <a href="tel:<?php echo esc_attr($phone); ?>" dir="ltr" style="font-size:12px; font-family:monospace; color:#475569; text-decoration:none;">
                                                <?php echo esc_html($phone); ?>
                                            </a>
                                            <?php if (!empty($clean_phone)) : ?>
                                                <a href="https://wa.me/<?php echo esc_attr($clean_phone); ?>" target="_blank" class="vamariz-wa-btn" title="ارسال پیام در واتساپ">واتساپ</a>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <!-- 3. Amount & Progress -->
                                <td>
                                    <strong><?php echo esc_html(number_format($total)); ?></strong> <small>تومان</small>
                                    <div class="vamariz-progress-wrap">
                                        <div class="vamariz-progress-bar-bg">
                                            <div class="vamariz-progress-bar-fill" style="width: <?php echo esc_attr($pct); ?>%;"></div>
                                        </div>
                                        <div class="vamariz-progress-text">
                                            <span>پیشرفت: <?php echo esc_html($pct); ?>٪</span>
                                            <span><?php echo esc_html($paid_steps); ?> از ۴ قسط</span>
                                        </div>
                                    </div>
                                </td>

                                <!-- 4. Steps 1 to 4 -->
                                <?php
                                for ($step = 1; $step <= 4; $step++) {
                                    echo '<td>';
                                    if ($order_type === 'failed') {
                                        echo '<div class="vamariz-step-cell is-failed">';
                                        if ($step === 1) {
                                            echo '<span class="vamariz-badge-status failed">عدم پرداخت</span>';
                                            echo '<div class="vamariz-step-date">پیش‌پرداخت ناموفق</div>';
                                        } else {
                                            echo '<span style="color:#94a3b8; font-size:11px;">صادر نشده</span>';
                                        }
                                        echo '</div>';
                                    } else {
                                        $step_data = $schedule[$step] ?? null;
                                        $is_done   = ($step_data && isset($step_data['status']) && $step_data['status'] === 'completed');
                                        $due       = isset($step_data['due_date']) ? (int) $step_data['due_date'] : 0;
                                        $sub_id    = isset($step_data['order_id']) ? (int) $step_data['order_id'] : 0;
                                        $is_late   = (!$is_done && $due > 0 && $due < $now);

                                        $cell_class = $is_done ? 'is-paid' : ($is_late ? 'is-overdue' : '');
                                        echo '<div class="vamariz-step-cell ' . esc_attr($cell_class) . '">';

                                        if ($is_done) {
                                            echo '<span class="vamariz-badge-status paid">✓ پرداخت شد</span>';
                                            $paid_at = isset($step_data['paid_at']) ? (int) $step_data['paid_at'] : 0;
                                            if ($paid_at > 0) {
                                                echo '<div class="vamariz-step-date">' . esc_html(date('Y/m/d', $paid_at)) . '</div>';
                                            } else {
                                                echo '<div class="vamariz-step-date">تسویه آنلاین</div>';
                                            }
                                        } else {
                                            if ($is_late) {
                                                $days_over = max(1, round(($now - $due) / 86400));
                                                echo '<span class="vamariz-badge-status overdue">معوقه (' . esc_html($days_over) . ' روز)</span>';
                                            } else {
                                                echo '<span class="vamariz-badge-status pending">در انتظار</span>';
                                            }

                                            echo '<div class="vamariz-step-date">سررسید: ' . ($due > 0 ? esc_html(date('m/d', $due)) : '—') . '</div>';

                                            if ($sub_id > 0) {
                                                $pay_link = home_url('/?vamareys_pay=' . $sub_id);
                                                echo '<div class="vamariz-step-actions">';
                                                echo '<button type="button" class="vamariz-copy-link-btn" data-copy-link="' . esc_attr($pay_link) . '" title="کپی لینک مستقیم پرداخت جهت ارسال دستی به مشتری">📋 کپی لینک پرداخت</button>';

                                                // Mark paid manually
                                                echo '<form method="post" style="display:inline;" onsubmit="return confirm(\'آیا قسط شماره ' . $sub_id . ' به صورت کارت به کارت پرداخت شده و می‌خواهید آن را تسویه ثبت کنید؟\');">';
                                                wp_nonce_field('vamariz_admin_action', 'vamariz_nonce');
                                                echo '<input type="hidden" name="vamariz_action" value="mark_installment_paid">';
                                                echo '<input type="hidden" name="sub_order_id" value="' . esc_attr($sub_id) . '">';
                                                echo '<button type="submit" class="button button-small" style="font-size:10px; padding:1px 6px; height:auto; color:#059669; border-color:#a7f3d0;" title="ثبت دستی پرداخت (کارت به کارت)">✓ ثبت تسویه</button>';
                                                echo '</form>';
                                                echo '</div>';
                                            }
                                        }
                                        echo '</div>';
                                    }
                                    echo '</td>';
                                }
                                ?>

                                <!-- 5. Global Row Actions -->
                                <td>
                                    <?php
                                    if ($order_type === 'failed') {
                                        echo '<span style="color:#94a3b8; font-size:12px;">سفارش لغوشده</span>';
                                    } else {
                                        // Find next unpaid sub-order
                                        $sub_orders = wc_get_orders(array(
                                            'parent'     => $order_id,
                                            'status'     => array('pending', 'on-hold', 'wc-pending', 'wc-on-hold'),
                                            'meta_key'   => '_vamariz_is_sub_order',
                                            'meta_value' => 'yes',
                                            'limit'      => 1,
                                            'orderby'    => 'date',
                                            'order'      => 'ASC',
                                        ));

                                        if (!empty($sub_orders)) {
                                            $next_sub  = reset($sub_orders);
                                            $n_sub_id  = $next_sub->get_id();
                                            $next_link = home_url('/?vamareys_pay=' . $n_sub_id);

                                            echo '<div style="display:flex; flex-direction:column; gap:5px;">';
                                            echo '<button type="button" class="button button-secondary vamariz-copy-link-btn" data-copy-link="' . esc_attr($next_link) . '" title="کپی لینک پرداخت قسط جاری">📋 کپی لینک قسط جاری</button>';

                                            echo '<form method="post" style="display:inline;" onsubmit="return confirm(\'آیا از ارسال پیامک یادآوری خدماتی برای مشتری مطمئن هستید؟\');">';
                                            wp_nonce_field('vamariz_admin_action', 'vamariz_nonce');
                                            echo '<input type="hidden" name="vamariz_action" value="manual_reminder">';
                                            echo '<input type="hidden" name="sub_order_id" value="' . esc_attr($n_sub_id) . '">';
                                            echo '<button type="submit" class="button button-primary button-small" style="width:100%;">✉️ ارسال پیامک یادآوری</button>';
                                            echo '</form>';
                                            echo '</div>';
                                        } else {
                                            echo '<span class="vamariz-pill vamariz-pill-success" style="font-size:12px;">🎉 تسویه کامل ۴ قسط</span>';
                                        }
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php }
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
