<?php
/**
 * Vamariz Admin Dashboard & Operations Center
 *
 * @package VamarizInstallments
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
            'اقساط واماریز',
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
                $invite_url = Vamariz_Roles::get_invite_url($invite['token']);
                $notice = sprintf('دعوت‌نامه با توکن %s ایجاد گردید.', $invite['token']);

                if ($sms) {
                    $sms_res = Vamariz_SMS::send_invite_sms($phone, $name, $invite_url);
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
                Vamariz_SMS::send_reminder_sms($sub_id, 0);
                set_transient('vamariz_admin_notice', array('type' => 'success', 'msg' => 'پیامک یادآوری دستی با موفقیت برای مشتری ارسال شد.'), 45);
            }
            wp_safe_redirect(admin_url('admin.php?page=vamariz-installments&tab=orders'));
            exit;
        }

        // 4. Test SMS
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

        // 5. Save Settings
        if (isset($_POST['vamariz_action']) && $_POST['vamariz_action'] === 'save_settings') {
            check_admin_referer('vamariz_admin_action', 'vamariz_nonce');
            if (isset($_POST['api_url'])) {
                update_option('vamariz_sms_api_url', esc_url_raw($_POST['api_url']));
            }
            if (isset($_POST['from_number'])) {
                update_option('vamariz_sms_from_number', sanitize_text_field($_POST['from_number']));
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
                    <h2><?php esc_html_e('پیشخوان مدیریت خرید اقساطی واماریز', 'vamariz-installments'); ?></h2>
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
                <a href="<?php echo esc_url(admin_url('admin.php?page=vamariz-installments&tab=sms_test')); ?>" class="nav-tab <?php echo $active_tab === 'sms_test' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('تست سامانه پیامک', 'vamariz-installments'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=vamariz-installments&tab=settings')); ?>" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('تنظیمات و درگاه پیامک', 'vamariz-installments'); ?>
                </a>
            </nav>

            <div class="vamariz-tab-content" style="margin-top: 20px;">
                <?php
                switch ($active_tab) {
                    case 'invites':
                        $this->render_invites_tab();
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
     * Orders & Matrix Tab.
     */
    private function render_orders_tab() {
        if (!function_exists('wc_get_orders')) {
            echo '<p>ووکامرس یافت نشد.</p>';
            return;
        }

        $orders = wc_get_orders(array(
            'limit'      => 50,
            'meta_key'   => '_vamariz_is_installment_order',
            'meta_value' => 'yes',
        ));

        // Stats calculation
        $total_orders = count($orders);
        $total_value  = 0;
        $collected    = 0;
        $overdue      = 0;
        $now          = current_time('timestamp');

        foreach ($orders as $o) {
            $total_value += (float) $o->get_meta('_vamariz_total_order_amount', true);
            $schedule = $o->get_meta('_vamariz_payment_schedule', true);
            if (is_array($schedule)) {
                foreach ($schedule as $step => $item) {
                    $amt = (float) $item['amount'];
                    $sub = !empty($item['order_id']) ? wc_get_order($item['order_id']) : null;
                    $is_paid = false;
                    if ($step === 1 && in_array($o->get_status(), array('processing', 'completed', 'wc-processing', 'wc-completed'), true)) {
                        $is_paid = true;
                    } elseif ($sub && in_array($sub->get_status(), array('processing', 'completed', 'wc-processing', 'wc-completed'), true)) {
                        $is_paid = true;
                    }

                    if ($is_paid) {
                        $collected += $amt;
                    } elseif (!empty($item['due_date']) && $now > $item['due_date']) {
                        $overdue += $amt;
                    }
                }
            }
        }
        ?>
        <!-- KPI Cards -->
        <div class="vamariz-stats-grid">
            <div class="vamariz-stat-card">
                <span class="vamariz-stat-label">کل سفارش‌های اقساطی</span>
                <span class="vamariz-stat-value"><?php echo esc_html(number_format($total_orders)); ?></span>
            </div>
            <div class="vamariz-stat-card">
                <span class="vamariz-stat-label">ارزش کل اقساط</span>
                <span class="vamariz-stat-value"><?php echo esc_html(number_format($total_value)); ?> <small>تومان</small></span>
            </div>
            <div class="vamariz-stat-card highlight-green">
                <span class="vamariz-stat-label">اقساط وصول شده</span>
                <span class="vamariz-stat-value"><?php echo esc_html(number_format($collected)); ?> <small>تومان</small></span>
            </div>
            <div class="vamariz-stat-card highlight-red">
                <span class="vamariz-stat-label">اقساط معوقه</span>
                <span class="vamariz-stat-value"><?php echo esc_html(number_format($overdue)); ?> <small>تومان</small></span>
            </div>
        </div>

        <!-- Orders Table -->
        <h3 style="margin-top: 25px;"><?php esc_html_e('ماتریس اقساط ۴ ماهه سفارش‌ها', 'vamariz-installments'); ?></h3>
        <table class="wp-list-table widefat fixed striped vamariz-matrix-table">
            <thead>
                <tr>
                    <th style="width: 80px;">سفارش</th>
                    <th>مشتری</th>
                    <th>تلفن</th>
                    <th>مبلغ کل</th>
                    <th>قسط اول (۲۵٪)</th>
                    <th>قسط دوم (۳۰ روز)</th>
                    <th>قسط سوم (۶۰ روز)</th>
                    <th>قسط چهارم (۹۰ روز)</th>
                    <th style="width: 140px;">عملیات یادآوری</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if (empty($orders)) {
                    echo '<tr><td colspan="9" style="text-align:center; padding:20px;">هیچ سفارش اقساطی تاکنون ثبت نشده است.</td></tr>';
                } else {
                    foreach ($orders as $order) {
                        $schedule = $order->get_meta('_vamariz_payment_schedule', true);
                        $phone = $order->get_billing_phone();
                        $name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
                        ?>
                        <tr>
                            <td><a href="<?php echo esc_url($order->get_edit_order_url()); ?>" target="_blank"><strong>#<?php echo esc_html($order->get_id()); ?></strong></a></td>
                            <td><?php echo esc_html($name ?: '—'); ?></td>
                            <td><code dir="ltr"><?php echo esc_html($phone ?: '—'); ?></code></td>
                            <td><strong><?php echo esc_html(number_format((float) $order->get_meta('_vamariz_total_order_amount', true))); ?></strong> تومان</td>
                            <?php
                            for ($step = 1; $step <= 4; $step++) {
                                $item = isset($schedule[$step]) ? $schedule[$step] : null;
                                echo '<td>';
                                if ($item) {
                                    $sub_id = !empty($item['order_id']) ? $item['order_id'] : 0;
                                    $sub = $sub_id ? wc_get_order($sub_id) : null;
                                    $is_paid = false;
                                    if ($step === 1 && in_array($order->get_status(), array('processing', 'completed'), true)) {
                                        $is_paid = true;
                                    } elseif ($sub && in_array($sub->get_status(), array('processing', 'completed'), true)) {
                                        $is_paid = true;
                                    }

                                    if ($is_paid) {
                                        echo '<span class="vamariz-badge-status paid">پرداخت شد</span>';
                                    } else {
                                        $due = (int) $item['due_date'];
                                        $is_overdue = $now > $due;
                                        echo '<span class="vamariz-badge-status ' . ($is_overdue ? 'overdue' : 'pending') . '">';
                                        echo $is_overdue ? 'معوقه' : 'در انتظار';
                                        echo '</span><br><small style="color:#777;">' . date('Y/m/d', $due) . '</small>';
                                        if ($sub && method_exists($sub, 'get_checkout_payment_url')) {
                                            echo '<br><a href="' . esc_url($sub->get_checkout_payment_url()) . '" target="_blank" style="font-size:11px;">لینک پرداخت</a>';
                                        }
                                    }
                                } else {
                                    echo '—';
                                }
                                echo '</td>';
                            }
                            ?>
                            <td>
                                <?php
                                // If any pending sub-order exists, show manual reminder button
                                $pending_sub_id = 0;
                                if (is_array($schedule)) {
                                    foreach ($schedule as $st => $it) {
                                        if ($st > 1 && !empty($it['order_id'])) {
                                            $s = wc_get_order($it['order_id']);
                                            if ($s && in_array($s->get_status(), array('pending', 'on-hold'), true)) {
                                                $pending_sub_id = $it['order_id'];
                                                break;
                                            }
                                        }
                                    }
                                }
                                if ($pending_sub_id > 0) {
                                    ?>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('آیا مایل به ارسال پیامک یادآوری به مشتری هستید؟');">
                                        <?php wp_nonce_field('vamariz_admin_action', 'vamariz_nonce'); ?>
                                        <input type="hidden" name="vamariz_action" value="manual_reminder">
                                        <input type="hidden" name="sub_order_id" value="<?php echo esc_attr($pending_sub_id); ?>">
                                        <button type="submit" class="button button-small button-secondary">
                                            ارسال پیامک یادآوری
                                        </button>
                                    </form>
                                    <?php
                                } else {
                                    echo '<span style="color:#27ae60;font-size:12px;">تسویه شده</span>';
                                }
                                ?>
                            </td>
                        </tr>
                        <?php
                    }
                }
                ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Invites Tab.
     */
    private function render_invites_tab() {
        $invites = Vamariz_Roles::get_all_invites();
        ?>
        <div style="display: grid; grid-template-columns: 350px 1fr; gap: 20px;">
            <div class="vamariz-card-box" style="background:#fff; padding:20px; border:1px solid #ccd0d4; border-radius:6px;">
                <h3 style="margin-top:0;">صدور دعوت‌نامه جدید</h3>
                <p style="color:#666; font-size:13px;">برای اعطای دسترسی خرید اقساطی به مشتری جدید، اطلاعات زیر را وارد نمایید:</p>
                <form method="post">
                    <?php wp_nonce_field('vamariz_admin_action', 'vamariz_nonce'); ?>
                    <input type="hidden" name="vamariz_action" value="create_invite">

                    <p>
                        <label><strong>نام و نام خانوادگی مشتری:</strong></label><br>
                        <input type="text" name="invite_name" class="regular-text" style="width:100%;" required placeholder="مثال: علی رضایی">
                    </p>
                    <p>
                        <label><strong>شماره همراه:</strong></label><br>
                        <input type="text" name="invite_phone" class="regular-text" style="width:100%;" dir="ltr" required placeholder="09123456789">
                    </p>
                    <p>
                        <label>
                            <input type="checkbox" name="send_sms" value="1" checked>
                            <strong>ارسال خودکار پیامک حاوی لینک دعوت به مشتری</strong>
                        </label>
                    </p>
                    <p style="margin-top:20px;">
                        <button type="submit" class="button button-primary" style="width:100%;">ایجاد و صدور دعوت‌نامه</button>
                    </p>
                </form>
            </div>

            <div class="vamariz-card-box" style="background:#fff; padding:20px; border:1px solid #ccd0d4; border-radius:6px;">
                <h3 style="margin-top:0;">لیست دعوت‌نامه‌های صادر شده</h3>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>توکن</th>
                            <th>نام مشتری</th>
                            <th>شماره همراه</th>
                            <th>وضعیت</th>
                            <th>تاریخ صدور</th>
                            <th>لینک دعوت</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if (empty($invites)) {
                            echo '<tr><td colspan="7" style="text-align:center; padding:15px;">هیچ دعوت‌نامه‌ای ایجاد نشده است.</td></tr>';
                        } else {
                            foreach (array_reverse($invites) as $inv) {
                                $url = Vamariz_Roles::get_invite_url($inv['token']);
                                echo '<tr>';
                                echo '<td><code>' . esc_html($inv['token']) . '</code></td>';
                                echo '<td>' . esc_html($inv['name']) . '</td>';
                                echo '<td><code dir="ltr">' . esc_html($inv['phone']) . '</code></td>';
                                echo '<td>';
                                if ($inv['status'] === 'pending') {
                                    echo '<span style="color:#e67e22; font-weight:bold;">در انتظار استفاده</span>';
                                } elseif ($inv['status'] === 'used') {
                                    echo '<span style="color:#27ae60; font-weight:bold;">استفاده شد</span>';
                                } else {
                                    echo '<span style="color:#999;">باطل شده</span>';
                                }
                                echo '</td>';
                                echo '<td>' . date('Y/m/d H:i', $inv['created_at']) . '</td>';
                                echo '<td><input type="text" value="' . esc_attr($url) . '" readonly style="width:100%;font-size:11px;" onclick="this.select(); document.execCommand(\'copy\'); alert(\'لینک کپی شد!\');"></td>';
                                echo '<td>';
                                if ($inv['status'] === 'pending') {
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
     * SMS Test Tab.
     */
    private function render_sms_test_tab() {
        ?>
        <div class="vamariz-card-box" style="background:#fff; padding:25px; border:1px solid #ccd0d4; border-radius:6px; max-width:600px;">
            <h3 style="margin-top:0;">تست ارسال پیامک وب‌سرویس ملی‌پیامک</h3>
            <p style="color:#666; font-size:13px;">برای اطمینان از صحت اتصال و سلامت خط و API Key ملی‌پیامک، یک پیامک تستی به شماره خود ارسال فرمایید:</p>
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
                    <button type="submit" class="button button-primary">ارسال پیامک آزمایشی</button>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Settings Tab.
     */
    private function render_settings_tab() {
        $api_url = get_option('vamariz_sms_api_url', Vamariz_SMS::DEFAULT_API_URL);
        $from    = get_option('vamariz_sms_from_number', Vamariz_SMS::DEFAULT_FROM);
        $min_ord = get_option('vamariz_min_order_total', 500000);
        ?>
        <div class="vamariz-card-box" style="background:#fff; padding:25px; border:1px solid #ccd0d4; border-radius:6px; max-width:700px;">
            <h3 style="margin-top:0;">تنظیمات سامانه و ملی‌پیامک</h3>
            <form method="post">
                <?php wp_nonce_field('vamariz_admin_action', 'vamariz_nonce'); ?>
                <input type="hidden" name="vamariz_action" value="save_settings">

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="api_url">آدرس REST API ملی‌پیامک</label></th>
                        <td>
                            <input type="url" name="api_url" id="api_url" class="regular-text" style="width:100%;" dir="ltr" value="<?php echo esc_attr($api_url); ?>" required>
                            <p class="description">آدرس ارسال ساده کنسول ملی‌پیامک اختصاصی واماریز</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="from_number">شماره فرستنده پیامک</label></th>
                        <td>
                            <input type="text" name="from_number" id="from_number" class="regular-text" dir="ltr" value="<?php echo esc_attr($from); ?>" required>
                            <p class="description">سرشماره ارسالی پنل ملی‌پیامک (مثال: 50004001)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="min_order">حداقل مبلغ سفارش برای اقساط (تومان)</label></th>
                        <td>
                            <input type="number" name="min_order" id="min_order" class="regular-text" value="<?php echo esc_attr($min_ord); ?>" required min="0" step="50000">
                            <p class="description">سبدهای خرید کمتر از این مبلغ مشمول اقساط نخواهند شد.</p>
                        </td>
                    </tr>
                </table>

                <p class="submit" style="margin-top:20px;">
                    <button type="submit" class="button button-primary">ذخیره تغییرات</button>
                </p>
            </form>
        </div>
        <?php
    }
}
