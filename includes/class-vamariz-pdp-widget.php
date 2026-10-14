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
        ?>
        <div class="vamariz-installment-widget" id="vamariz-installment-widget">
            <div class="vamariz-widget-badge">
                <span class="vamariz-badge-dot"></span>
                <span><?php esc_html_e('خرید اقساطی اختصاصی واماریز', 'vamariz-installments'); ?></span>
            </div>
            <div class="vamariz-widget-content">
                <div class="vamariz-widget-text">
                    <span class="vamariz-widget-title"><?php esc_html_e('در ۴ قسط ماهانه:', 'vamariz-installments'); ?></span>
                    <span class="vamariz-installment-amount" data-raw="<?php echo esc_attr($installment_amount); ?>">
                        <strong class="vamariz-calc-installment"><?php echo esc_html(number_format($installment_amount)); ?></strong>
                        <span class="vamariz-currency"><?php esc_html_e('تومان / ماه', 'vamariz-installments'); ?></span>
                    </span>
                </div>
                <button type="button" class="vamariz-modal-trigger" id="vamariz-open-modal-btn">
                    <span><?php esc_html_e('شرایط و مراحل اقساط', 'vamariz-installments'); ?></span>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
                </button>
            </div>
            <div class="vamariz-widget-sub">
                <?php esc_html_e('۲۵٪ در لحظه خرید + ارسال فوری کالا + ۳ قسط ماهانه بدون سود و ضامن', 'vamariz-installments'); ?>
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
        ?>
        <div class="vamariz-modal-overlay" id="vamariz-modal-overlay" style="display:none;">
            <div class="vamariz-modal-dialog">
                <div class="vamariz-modal-header">
                    <div class="vamariz-header-title">
                        <span class="vamariz-logo-icon">V</span>
                        <h3><?php esc_html_e('خرید اقساطی ۴ ماهه واماریز', 'vamariz-installments'); ?></h3>
                    </div>
                    <button type="button" class="vamariz-modal-close" id="vamariz-modal-close-btn">&times;</button>
                </div>

                <div class="vamariz-modal-body">
                    <!-- Dynamic Installment Breakdown Table -->
                    <div class="vamariz-breakdown-card">
                        <div class="vamariz-card-header">
                            <span><?php esc_html_e('محاسبه لحظه‌ای اقساط این محصول', 'vamariz-installments'); ?></span>
                            <span class="vamariz-total-pill">
                                <?php esc_html_e('قیمت کالا:', 'vamariz-installments'); ?>
                                <strong class="vamariz-modal-total-display"><?php echo esc_html(number_format($price)); ?></strong> <?php esc_html_e('تومان', 'vamariz-installments'); ?>
                            </span>
                        </div>
                        <div class="vamariz-steps-grid">
                            <div class="vamariz-step-item active-step">
                                <span class="vamariz-step-num"><?php esc_html_e('قسط اول', 'vamariz-installments'); ?></span>
                                <span class="vamariz-step-amount"><strong class="vamariz-modal-inst-1"><?php echo esc_html(number_format($quarter)); ?></strong> تومان</span>
                                <span class="vamariz-step-date"><?php esc_html_e('امروز (هنگام خرید)', 'vamariz-installments'); ?></span>
                            </div>
                            <div class="vamariz-step-item">
                                <span class="vamariz-step-num"><?php esc_html_e('قسط دوم', 'vamariz-installments'); ?></span>
                                <span class="vamariz-step-amount"><strong class="vamariz-modal-inst-2"><?php echo esc_html(number_format($quarter)); ?></strong> تومان</span>
                                <span class="vamariz-step-date"><?php esc_html_e('۳۰ روز بعد', 'vamariz-installments'); ?></span>
                            </div>
                            <div class="vamariz-step-item">
                                <span class="vamariz-step-num"><?php esc_html_e('قسط سوم', 'vamariz-installments'); ?></span>
                                <span class="vamariz-step-amount"><strong class="vamariz-modal-inst-3"><?php echo esc_html(number_format($quarter)); ?></strong> تومان</span>
                                <span class="vamariz-step-date"><?php esc_html_e('۶۰ روز بعد', 'vamariz-installments'); ?></span>
                            </div>
                            <div class="vamariz-step-item">
                                <span class="vamariz-step-num"><?php esc_html_e('قسط چهارم', 'vamariz-installments'); ?></span>
                                <span class="vamariz-step-amount"><strong class="vamariz-modal-inst-4"><?php echo esc_html(number_format($quarter)); ?></strong> تومان</span>
                                <span class="vamariz-step-date"><?php esc_html_e('۹۰ روز بعد', 'vamariz-installments'); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Process Steps -->
                    <div class="vamariz-info-section">
                        <h4><?php esc_html_e('مراحل دریافت کالا و پرداخت اقساط', 'vamariz-installments'); ?></h4>
                        <ul class="vamariz-info-steps">
                            <li>
                                <div class="vamariz-circle-num">۱</div>
                                <div>
                                    <strong><?php esc_html_e('ثبت سفارش و پرداخت قسط اول', 'vamariz-installments'); ?></strong>
                                    <p><?php esc_html_e('تنها با پرداخت ۲۵٪ از مبلغ کل کالا در صفحه تسویه‌حساب، سفارش شما ثبت می‌شود.', 'vamariz-installments'); ?></p>
                                </div>
                            </li>
                            <li>
                                <div class="vamariz-circle-num">۲</div>
                                <div>
                                    <strong><?php esc_html_e('ارسال فوری کالا توسط واماریز', 'vamariz-installments'); ?></strong>
                                    <p><?php esc_html_e('سفارش شما بلافاصله پس از پرداخت قسط اول بسته‌بندی و تحویل پست / پیک می‌گردد.', 'vamariz-installments'); ?></p>
                                </div>
                            </li>
                            <li>
                                <div class="vamariz-circle-num">۳</div>
                                <div>
                                    <strong><?php esc_html_e('یادآوری پیامکی و پرداخت آنلاین آسان', 'vamariz-installments'); ?></strong>
                                    <p><?php esc_html_e('پیش از موعد هر قسط، پیامک یادآوری حاوی لینک مستقیم پرداخت بانکی برای شما ارسال خواهد شد.', 'vamariz-installments'); ?></p>
                                </div>
                            </li>
                        </ul>
                    </div>

                    <!-- Terms & Conditions -->
                    <div class="vamariz-terms-box">
                        <strong><?php esc_html_e('شرایط و قوانین خوش‌حسابی:', 'vamariz-installments'); ?></strong>
                        <p><?php esc_html_e('این طرح ویژه مشتریان منتخب و وفادار واماریز بوده و بدون نیاز به چک، سفته یا ضامن ارائه می‌شود. در صورت تاخیر در تسویه هر قسط، امتیاز خرید اقساطی بعدی به صورت موقت معلق خواهد شد تا اقساط معوقه تسویه گردند.', 'vamariz-installments'); ?></p>
                    </div>
                </div>

                <div class="vamariz-modal-footer">
                    <button type="button" class="vamariz-btn-primary" id="vamariz-close-modal-confirm">
                        <?php esc_html_e('متوجه شدم، بازگشت به خرید', 'vamariz-installments'); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }
}
