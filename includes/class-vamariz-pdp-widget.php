<?php
/**
 * Vamareys Single Product Page Widget and Modal
 * Modern Luxury Design System for Fashion eCommerce
 *
 * @package VamareysInstallments
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
     * Check if the installment widget should be displayed on product pages.
     */
    public static function should_display_widget() {
        if (Vamariz_Roles::is_installment_customer()) {
            return true;
        }

        $preview_enabled = get_option('vamariz_widget_public_preview', 'yes');
        return ($preview_enabled === 'yes');
    }

    /**
     * Hook widget into single product summary.
     */
    public function setup_product_page_hooks() {
        if (!function_exists('is_product') || !is_product()) {
            return;
        }

        if (self::should_display_widget()) {
            // Priority 25 places it right below price / before add-to-cart in WooCommerce & Woodmart
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

        if (!self::should_display_widget() && !Vamariz_Roles::is_installment_customer()) {
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
            'basePrice'     => $price,
            'minOrderTotal' => (float) get_option('vamariz_min_order_total', 500000),
            'currency'      => function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : 'تومان',
            'currencyText'  => 'تومان',
            'isEligible'    => Vamariz_Roles::is_installment_customer(),
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
     * Render the 4-installment luxury widget on single product page.
     */
    public function render_pdp_widget() {
        global $product;
        if (!$product || !is_a($product, 'WC_Product')) {
            return;
        }

        $min_order_total = (float) get_option('vamariz_min_order_total', 500000);
        $price = function_exists('wc_get_price_to_display') ? (float) wc_get_price_to_display($product) : (float) $product->get_price();

        $is_below_min = ($price > 0 && $price < $min_order_total);
        $widget_style = $is_below_min ? 'display: none;' : '';

        $installment_amount = $price > 0 ? (int) round($price / 4) : 0;
        $formatted_fa = self::to_fa_digits(number_format($installment_amount));
        $is_eligible = Vamariz_Roles::is_installment_customer();
        ?>
        <div class="vamariz-installment-widget" id="vamariz-installment-widget" role="region" aria-label="<?php esc_attr_e('طرح خرید اقساطی ۴ ماهه واماریز (Vamareys)', 'vamariz-installments'); ?>" style="<?php echo esc_attr($widget_style); ?>">
            <!-- Top Row: Luxury Brand Badge & Prepayment Highlight -->
            <div class="vamariz-widget-header">
                <div class="vamariz-brand-tag">
                    <span class="vamariz-brand-dot" aria-hidden="true"></span>
                    <span class="vamariz-brand-name"><?php esc_html_e('واماریز', 'vamariz-installments'); ?></span>
                    <span class="vamariz-brand-divider" aria-hidden="true">/</span>
                    <span class="vamariz-brand-plan"><?php esc_html_e('خرید در ۴ قسط ماهانه', 'vamariz-installments'); ?></span>
                </div>
                <div class="vamariz-upfront-badge">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    <span><?php esc_html_e('پیش‌پرداخت امروز: فقط ۲۵٪', 'vamariz-installments'); ?></span>
                </div>
            </div>

            <!-- Middle Row: Monthly Installment Price + Action Button -->
            <div class="vamariz-widget-main">
                <div class="vamariz-price-block">
                    <span class="vamariz-price-label"><?php esc_html_e('مبلغ هر قسط ماهانه:', 'vamariz-installments'); ?></span>
                    <div class="vamariz-amount-wrapper">
                        <strong class="vamariz-calc-installment" data-raw="<?php echo esc_attr($installment_amount); ?>"><?php echo esc_html($formatted_fa); ?></strong>
                        <span class="vamariz-currency-unit"><?php esc_html_e('تومان / ماه', 'vamariz-installments'); ?></span>
                    </div>
                </div>
                <div class="vamariz-action-block">
                    <button type="button" 
                            class="vamariz-modal-trigger" 
                            id="vamariz-open-modal-btn" 
                            aria-haspopup="dialog" 
                            aria-expanded="false" 
                            aria-controls="vamariz-modal-dialog">
                        <span><?php esc_html_e('شرایط و جدول اقساط', 'vamariz-installments'); ?></span>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"></polyline></svg>
                    </button>
                </div>
            </div>

            <!-- Visual 4-Stage Stepper Track -->
            <div class="vamariz-mini-stepper" aria-hidden="true">
                <div class="vamariz-step-track">
                    <div class="vamariz-track-node is-active">
                        <span class="vamariz-node-bullet">۱</span>
                        <span class="vamariz-node-label"><?php esc_html_e('امروز (۲۵٪)', 'vamariz-installments'); ?></span>
                    </div>
                    <div class="vamariz-track-line"></div>
                    <div class="vamariz-track-node">
                        <span class="vamariz-node-bullet">۲</span>
                        <span class="vamariz-node-label"><?php esc_html_e('۳۰ روز بعد', 'vamariz-installments'); ?></span>
                    </div>
                    <div class="vamariz-track-line"></div>
                    <div class="vamariz-track-node">
                        <span class="vamariz-node-bullet">۳</span>
                        <span class="vamariz-node-label"><?php esc_html_e('۶۰ روز بعد', 'vamariz-installments'); ?></span>
                    </div>
                    <div class="vamariz-track-line"></div>
                    <div class="vamariz-track-node">
                        <span class="vamariz-node-bullet">۴</span>
                        <span class="vamariz-node-label"><?php esc_html_e('۹۰ روز بعد', 'vamariz-installments'); ?></span>
                    </div>
                </div>
            </div>

            <!-- Trust & Benefit Perks -->
            <div class="vamariz-widget-perks">
                <div class="vamariz-perk-item">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
                    <span><?php esc_html_e('ارسال فوری سفارش پس از قسط اول', 'vamariz-installments'); ?></span>
                </div>
                <div class="vamariz-perk-item">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 14 14"></polyline></svg>
                    <span><?php esc_html_e('بدون سود و کارمزد اضافه (۰٪)', 'vamariz-installments'); ?></span>
                </div>
                <div class="vamariz-perk-item">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                    <span><?php esc_html_e('بدون نیاز به چک یا ضامن', 'vamariz-installments'); ?></span>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the detailed modal markup in wp_footer.
     */
    public function render_installment_modal() {
        if (!function_exists('is_product') || !is_product() || !self::should_display_widget()) {
            return;
        }

        global $product;
        $price = ($product && is_a($product, 'WC_Product')) ? (float) (function_exists('wc_get_price_to_display') ? wc_get_price_to_display($product) : $product->get_price()) : 0;
        
        $q1 = round($price / 4);
        $q2 = round($price / 4);
        $q3 = round($price / 4);
        $q4 = $price - ($q1 + $q2 + $q3);

        $price_fa = self::to_fa_digits(number_format($price));
        $q1_fa    = self::to_fa_digits(number_format($q1));
        $q2_fa    = self::to_fa_digits(number_format($q2));
        $q3_fa    = self::to_fa_digits(number_format($q3));
        $q4_fa    = self::to_fa_digits(number_format($q4));
        $is_eligible = Vamariz_Roles::is_installment_customer();
        ?>
        <div class="vamariz-modal-overlay" id="vamariz-modal-overlay" style="display:none;" aria-hidden="true">
            <div class="vamariz-modal-dialog" 
                 id="vamariz-modal-dialog" 
                 role="dialog" 
                 aria-modal="true" 
                 aria-labelledby="vamariz-modal-title" 
                 aria-describedby="vamariz-modal-desc" 
                 tabindex="-1">
                
                <!-- Modal Header -->
                <div class="vamariz-modal-header">
                    <div class="vamariz-modal-header-info">
                        <div class="vamariz-fintech-icon" aria-hidden="true">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line><line x1="5" y1="15" x2="8" y2="15"></line></svg>
                        </div>
                        <div>
                            <h3 id="vamariz-modal-title"><?php esc_html_e('طرح خرید اقساطی ۴ ماهه واماریز (Vamareys)', 'vamariz-installments'); ?></h3>
                            <p class="vamariz-modal-subtitle"><?php esc_html_e('بدون کارمزد • بدون نیاز به ضامن • ارسال فوری سفارش', 'vamariz-installments'); ?></p>
                        </div>
                    </div>
                    <button type="button" 
                            class="vamariz-modal-close" 
                            id="vamariz-modal-close-btn" 
                            aria-label="<?php esc_attr_e('بستن پنجره', 'vamariz-installments'); ?>">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="19" y1="5" x2="5" y2="19"></line><line x1="5" y1="5" x2="19" y2="19"></line></svg>
                    </button>
                </div>

                <!-- Modal Body -->
                <div class="vamariz-modal-body" id="vamariz-modal-desc">
                    <!-- Dynamic Installment Breakdown Card -->
                    <div class="vamariz-breakdown-card">
                        <div class="vamariz-card-header">
                            <span class="vamariz-card-header-label">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                                <?php esc_html_e('جدول دقیق مبالغ و سررسید ۴ مرحله‌ای:', 'vamariz-installments'); ?>
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
                                <span class="vamariz-step-amount"><strong class="vamariz-modal-inst-1"><?php echo esc_html($q1_fa); ?></strong> <small><?php esc_html_e('تومان', 'vamariz-installments'); ?></small></span>
                                <span class="vamariz-step-date highlight-date"><?php esc_html_e('امروز هنگام خرید', 'vamariz-installments'); ?></span>
                            </div>
                            <div class="vamariz-step-item">
                                <span class="vamariz-step-num"><?php esc_html_e('قسط دوم (۲۵٪)', 'vamariz-installments'); ?></span>
                                <span class="vamariz-step-amount"><strong class="vamariz-modal-inst-2"><?php echo esc_html($q2_fa); ?></strong> <small><?php esc_html_e('تومان', 'vamariz-installments'); ?></small></span>
                                <span class="vamariz-step-date"><?php esc_html_e('۳۰ روز بعد', 'vamariz-installments'); ?></span>
                            </div>
                            <div class="vamariz-step-item">
                                <span class="vamariz-step-num"><?php esc_html_e('قسط سوم (۲۵٪)', 'vamariz-installments'); ?></span>
                                <span class="vamariz-step-amount"><strong class="vamariz-modal-inst-3"><?php echo esc_html($q3_fa); ?></strong> <small><?php esc_html_e('تومان', 'vamariz-installments'); ?></small></span>
                                <span class="vamariz-step-date"><?php esc_html_e('۶۰ روز بعد', 'vamariz-installments'); ?></span>
                            </div>
                            <div class="vamariz-step-item">
                                <span class="vamariz-step-num"><?php esc_html_e('قسط چهارم (۲۵٪)', 'vamariz-installments'); ?></span>
                                <span class="vamariz-step-amount"><strong class="vamariz-modal-inst-4"><?php echo esc_html($q4_fa); ?></strong> <small><?php esc_html_e('تومان', 'vamariz-installments'); ?></small></span>
                                <span class="vamariz-step-date"><?php esc_html_e('۹۰ روز بعد', 'vamariz-installments'); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Process Steps (How It Works) -->
                    <div class="vamariz-info-section">
                        <h4><?php esc_html_e('مراحل خرید و تحویل کالا در واماریز (Vamareys)', 'vamariz-installments'); ?></h4>
                        <ul class="vamariz-info-steps">
                            <li>
                                <div class="vamariz-circle-num" aria-hidden="true">۱</div>
                                <div>
                                    <strong><?php esc_html_e('ثبت سفارش و پرداخت قسط اول (۲۵٪)', 'vamariz-installments'); ?></strong>
                                    <p><?php esc_html_e('کالای مورد نظر خود را به سبد خرید اضافه کنید. در صفحه تسویه‌حساب گزینه خرید اقساطی واماریز را انتخاب کرده و تنها ۲۵٪ کل مبلغ را به‌صورت آنلاین پرداخت نمایید.', 'vamariz-installments'); ?></p>
                                </div>
                            </li>
                            <li>
                                <div class="vamariz-circle-num" aria-hidden="true">۲</div>
                                <div>
                                    <strong><?php esc_html_e('ارسال فوری و کامل سفارش توسط واماریز', 'vamariz-installments'); ?></strong>
                                    <p><?php esc_html_e('نیازی به تسویه کامل یا ارائه چک و ضامن نیست! سفارش شما بلافاصله پس از پرداخت قسط اول بسته‌بندی شده و تحویل پست / پیک می‌گردد.', 'vamariz-installments'); ?></p>
                                </div>
                            </li>
                            <li>
                                <div class="vamariz-circle-num" aria-hidden="true">۳</div>
                                <div>
                                    <strong><?php esc_html_e('یادآوری خودکار و پرداخت آسان آنلاین', 'vamariz-installments'); ?></strong>
                                    <p><?php esc_html_e('در سررسیدهای ۳۰، ۶۰ و ۹۰ روز دیگر، پیامک یادآوری حاوی لینک پرداخت مستقیم برای شما ارسال می‌شود و تسویه هر قسط با یک کلیک انجام می‌پذیرد.', 'vamariz-installments'); ?></p>
                                </div>
                            </li>
                        </ul>
                    </div>

                    <!-- Customer Eligibility Status Box -->
                    <?php if ($is_eligible): ?>
                        <div class="vamariz-eligibility-card is-active-member">
                            <div class="vamariz-card-icon" aria-hidden="true">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#15803d" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                            </div>
                            <div>
                                <strong><?php esc_html_e('حساب کاربری شما دارای امتیاز خرید اقساطی فعال است.', 'vamariz-installments'); ?></strong>
                                <p><?php esc_html_e('شما می‌توانید همین حالا با افزودن این کالا به سبد خرید، آن را در ۴ قسط بدون سود و کارمزد سفارش دهید.', 'vamariz-installments'); ?></p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="vamariz-eligibility-card is-guest-preview">
                            <div class="vamariz-card-icon" aria-hidden="true">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#b49b78" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                            </div>
                            <div>
                                <strong><?php esc_html_e('باشگاه مشتریان اقساطی واماریز (Vamareys):', 'vamariz-installments'); ?></strong>
                                <p><?php esc_html_e('این طرح ویژه مشتریان خوش‌حساب و دارای کد دعوت است. در صورت داشتن حساب کاربری در سایت وارد شوید، یا جهت دریافت دعوت‌نامه با پشتیبانی واماریز در ارتباط باشید.', 'vamariz-installments'); ?></p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Modal Footer -->
                <div class="vamariz-modal-footer">
                    <button type="button" class="vamariz-btn-primary" id="vamariz-close-modal-confirm">
                        <?php esc_html_e('متوجه شدم، بازگشت به صفحه کالا', 'vamariz-installments'); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }
}
