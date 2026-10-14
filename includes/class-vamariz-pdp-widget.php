<?php
/**
 * Vamariz Single Product Page Widget and Modal
 *
 * @package VamarizInstallments
 */

if (!defined('ABSPATH')) {
    exit;
}

class Vamariz_PDP_Widget {

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action('wp', array($this, 'setup_product_page_hooks'));
        add_action('wp_footer', array($this, 'render_installment_modal'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
    }

    /**
     * Hook widget into single product summary if user is installment customer.
     */
    public function setup_product_page_hooks() {
        if (!function_exists('is_product') || !is_product()) {
            return;
        }

        if (Vamariz_Roles::is_installment_customer()) {
            // Priority 25 places it right below price / before add-to-cart
            add_action('woocommerce_single_product_summary', array($this, 'render_pdp_widget'), 25);
        }
    }

    /**
     * Enqueue styles and scripts for PDP widget.
     */
    public function enqueue_frontend_assets() {
        if (!function_exists('is_product') || (!is_product() && !is_cart() && !is_checkout())) {
            return;
        }

        if (!Vamariz_Roles::is_installment_customer()) {
            return;
        }

        wp_enqueue_style(
            'vamariz-pdp-css',
            VAMARIZ_INSTALLMENTS_URL . 'assets/css/vamariz-pdp.css',
            array(),
            VAMARIZ_INSTALLMENTS_VERSION
        );

        wp_enqueue_script(
            'vamariz-pdp-js',
            VAMARIZ_INSTALLMENTS_URL . 'assets/js/vamariz-pdp.js',
            array('jquery'),
            VAMARIZ_INSTALLMENTS_VERSION,
            true
        );

        global $product;
        $price = 0;
        if (is_product() && is_a($product, 'WC_Product')) {
            $price = function_exists('wc_get_price_to_display') ? (float) wc_get_price_to_display($product) : (float) $product->get_price();
        }

        wp_localize_script('vamariz-pdp-js', 'vamarizData', array(
            'basePrice'    => $price,
            'currency'     => function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : 'تومان',
            'currencyText' => 'تومان',
        ));
    }

    /**
     * Helper to format numbers to Persian digits.
     */
    public static function to_fa_digits($number) {
        $en = array('0', '1', '2', '3', '4', '5', '6', '7', '8', '9');
        $fa = array('۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹');
        return str_replace($en, $fa, (string) $number);
    }

    /**
     * Render the 4-installment widget on single product page.
     */
    public function render_pdp_widget() {
        global $product;
        if (!$product || !is_a($product, 'WC_Product')) {
            return;
        }

        $min_order_total = (float) get_option('vamariz_min_order_total', 500000);
        $price = function_exists('wc_get_price_to_display') ? (float) wc_get_price_to_display($product) : (float) $product->get_price();

        if ($price > 0 && $price < $min_order_total) {
            return;
        }

        $installment_amount = $price > 0 ? round($price / 4) : 0;
        $formatted_fa = self::to_fa_digits(number_format($installment_amount));
        ?>
        <div class="vamariz-installment-widget" id="vamariz-installment-widget" aria-label="<?php esc_attr_e('طرح خرید اقساطی ۴ ماهه واماریز', 'vamariz-installments'); ?>">
            <div class="vamariz-widget-header-row">
                <div class="vamariz-widget-badge">
                    <span class="vamariz-badge-dot"></span>
                    <span><?php esc_html_e('خرید اقساطی اختصاصی واماریز', 'vamariz-installments'); ?></span>
                </div>
                <div class="vamariz-upfront-badge">
                    <span><?php esc_html_e('پیش‌پرداخت امروز: فقط ۲۵٪', 'vamariz-installments'); ?></span>
                </div>
            </div>

            <div class="vamariz-widget-content">
                <div class="vamariz-widget-text">
                    <span class="vamariz-widget-title"><?php esc_html_e('در ۴ قسط ماهانه:', 'vamariz-installments'); ?></span>
                    <span class="vamariz-installment-amount" data-raw="<?php echo esc_attr($installment_amount); ?>">
                        <strong class="vamariz-calc-installment"><?php echo esc_html($formatted_fa); ?></strong>
                        <span class="vamariz-currency"><?php esc_html_e('تومان / ماه', 'vamariz-installments'); ?></span>
                    </span>
                </div>
                <button type="button" 
                        class="vamariz-modal-trigger" 
                        id="vamariz-open-modal-btn" 
                        aria-haspopup="dialog" 
                        aria-expanded="false" 
                        aria-controls="vamariz-modal-dialog">
                    <span><?php esc_html_e('شرایط و جدول اقساط', 'vamariz-installments'); ?></span>
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"></polyline></svg>
                </button>
            </div>

            <div class="vamariz-widget-sub">
                <span class="vamariz-sub-pill">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    <?php esc_html_e('ارسال فوری کالا پس از پرداخت اول', 'vamariz-installments'); ?>
                </span>
                <span class="vamariz-sub-pill">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    <?php esc_html_e('بدون سود و کارمزد اضافه', 'vamariz-installments'); ?>
                </span>
                <span class="vamariz-sub-pill">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    <?php esc_html_e('بدون نیاز به چک یا ضامن', 'vamariz-installments'); ?>
                </span>
            </div>
        </div>
        <?php
    }

    /**
     * Render the detailed modal markup in wp_footer.
     */
    public function render_installment_modal() {
        if (!function_exists('is_product') || !is_product() || !Vamariz_Roles::is_installment_customer()) {
            return;
        }

        global $product;
        $price = ($product && is_a($product, 'WC_Product')) ? (float) (function_exists('wc_get_price_to_display') ? wc_get_price_to_display($product) : $product->get_price()) : 0;
        $quarter = $price > 0 ? round($price / 4) : 0;

        $price_fa   = self::to_fa_digits(number_format($price));
        $quarter_fa = self::to_fa_digits(number_format($quarter));
        ?>
        <div class="vamariz-modal-overlay" id="vamariz-modal-overlay" style="display:none;" aria-hidden="true">
            <div class="vamariz-modal-dialog" 
                 id="vamariz-modal-dialog" 
                 role="dialog" 
                 aria-modal="true" 
                 aria-labelledby="vamariz-modal-title" 
                 aria-describedby="vamariz-modal-desc" 
                 tabindex="-1">
                
                <div class="vamariz-modal-header">
                    <div class="vamariz-header-title">
                        <div class="vamariz-fintech-icon" aria-hidden="true">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line><line x1="5" y1="15" x2="8" y2="15"></line></svg>
                        </div>
                        <div>
                            <h3 id="vamariz-modal-title"><?php esc_html_e('طرح خرید اقساطی ۴ ماهه واماریز', 'vamariz-installments'); ?></h3>
                            <span class="vamariz-header-sub"><?php esc_html_e('بدون سود، بدون ضامن، با ارسال آنی سفارش', 'vamariz-installments'); ?></span>
                        </div>
                    </div>
                    <button type="button" 
                            class="vamariz-modal-close" 
                            id="vamariz-modal-close-btn" 
                            aria-label="<?php esc_attr_e('بستن پنجره جزئیات اقساط', 'vamariz-installments'); ?>">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                    </button>
                </div>

                <div class="vamariz-modal-body" id="vamariz-modal-desc">
                    <!-- Dynamic Installment Breakdown Table -->
                    <div class="vamariz-breakdown-card">
                        <div class="vamariz-card-header">
                            <span class="vamariz-card-header-label">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                                <?php esc_html_e('محاسبه دقیق سررسید و مبالغ اقساط این کالا', 'vamariz-installments'); ?>
                            </span>
                            <span class="vamariz-total-pill">
                                <?php esc_html_e('قیمت کل کالا:', 'vamariz-installments'); ?>
                                <strong class="vamariz-modal-total-display"><?php echo esc_html($price_fa); ?></strong> <?php esc_html_e('تومان', 'vamariz-installments'); ?>
                            </span>
                        </div>
                        <div class="vamariz-steps-grid">
                            <div class="vamariz-step-item active-step">
                                <span class="vamariz-step-badge"><?php esc_html_e('پیش‌پرداخت', 'vamariz-installments'); ?></span>
                                <span class="vamariz-step-num"><?php esc_html_e('قسط اول (۲۵٪)', 'vamariz-installments'); ?></span>
                                <span class="vamariz-step-amount"><strong class="vamariz-modal-inst-1"><?php echo esc_html($quarter_fa); ?></strong> <small>تومان</small></span>
                                <span class="vamariz-step-date highlight-date"><?php esc_html_e('امروز (هنگام خرید)', 'vamariz-installments'); ?></span>
                            </div>
                            <div class="vamariz-step-item">
                                <span class="vamariz-step-num"><?php esc_html_e('قسط دوم (۲۵٪)', 'vamariz-installments'); ?></span>
                                <span class="vamariz-step-amount"><strong class="vamariz-modal-inst-2"><?php echo esc_html($quarter_fa); ?></strong> <small>تومان</small></span>
                                <span class="vamariz-step-date"><?php esc_html_e('۳۰ روز بعد', 'vamariz-installments'); ?></span>
                            </div>
                            <div class="vamariz-step-item">
                                <span class="vamariz-step-num"><?php esc_html_e('قسط سوم (۲۵٪)', 'vamariz-installments'); ?></span>
                                <span class="vamariz-step-amount"><strong class="vamariz-modal-inst-3"><?php echo esc_html($quarter_fa); ?></strong> <small>تومان</small></span>
                                <span class="vamariz-step-date"><?php esc_html_e('۶۰ روز بعد', 'vamariz-installments'); ?></span>
                            </div>
                            <div class="vamariz-step-item">
                                <span class="vamariz-step-num"><?php esc_html_e('قسط چهارم (۲۵٪)', 'vamariz-installments'); ?></span>
                                <span class="vamariz-step-amount"><strong class="vamariz-modal-inst-4"><?php echo esc_html($quarter_fa); ?></strong> <small>تومان</small></span>
                                <span class="vamariz-step-date"><?php esc_html_e('۹۰ روز بعد', 'vamariz-installments'); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Process Steps -->
                    <div class="vamariz-info-section">
                        <h4><?php esc_html_e('مراحل دریافت کالا و پرداخت اقساط', 'vamariz-installments'); ?></h4>
                        <ul class="vamariz-info-steps">
                            <li>
                                <div class="vamariz-circle-num" aria-hidden="true">۱</div>
                                <div>
                                    <strong><?php esc_html_e('ثبت سفارش و پرداخت قسط اول (۲۵٪)', 'vamariz-installments'); ?></strong>
                                    <p><?php esc_html_e('در صفحه تسویه‌حساب گزینه خرید اقساطی واماریز را انتخاب کرده و تنها ۲۵٪ مبلغ را به صورت آنلاین واریز کنید.', 'vamariz-installments'); ?></p>
                                </div>
                            </li>
                            <li>
                                <div class="vamariz-circle-num" aria-hidden="true">۲</div>
                                <div>
                                    <strong><?php esc_html_e('ارسال فوری و کامل کالا توسط واماریز', 'vamariz-installments'); ?></strong>
                                    <p><?php esc_html_e('نیازی به تسویه کامل برای دریافت کالا نیست! سفارش شما بلافاصله پس از پرداخت قسط اول بسته‌بندی و تحویل پست / پیک می‌گردد.', 'vamariz-installments'); ?></p>
                                </div>
                            </li>
                            <li>
                                <div class="vamariz-circle-num" aria-hidden="true">۳</div>
                                <div>
                                    <strong><?php esc_html_e('یادآوری خودکار و پرداخت آسان آنلاین', 'vamariz-installments'); ?></strong>
                                    <p><?php esc_html_e('۳ روز پیش از موعد هر قسط و در روز سررسید، پیامک یادآوری حاوی لینک مستقیم تسویه برای شما ارسال خواهد شد.', 'vamariz-installments'); ?></p>
                                </div>
                            </li>
                        </ul>
                    </div>

                    <!-- Terms & Conditions -->
                    <div class="vamariz-terms-box">
                        <strong>
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                            <?php esc_html_e('شرایط و قوانین خوش‌حسابی:', 'vamariz-installments'); ?>
                        </strong>
                        <p><?php esc_html_e('این طرح ویژه مشتریان خوش‌حساب واماریز بوده و بدون نیاز به چک، سفته یا ضامن ارائه می‌شود. در صورت تاخیر در تسویه هر قسط، امتیاز ثبت خریدهای اقساطی بعدی به صورت موقت معلق خواهد شد تا اقساط معوقه تسویه گردند.', 'vamariz-installments'); ?></p>
                    </div>
                </div>

                <div class="vamariz-modal-footer">
                    <button type="button" class="vamariz-btn-primary" id="vamariz-close-modal-confirm">
                        <?php esc_html_e('متوجه شدم، بازگشت به خرید کالا', 'vamariz-installments'); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }
}
