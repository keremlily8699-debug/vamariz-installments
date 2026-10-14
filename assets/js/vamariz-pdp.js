/**
 * Vamareys PDP Dynamic Installment Calculator & Modal Interactivity
 * High-precision calculation, Persian numerals, and accessible focus management.
 * Compatible with WooCommerce core & Woodmart AJAX variations.
 */
jQuery(document).ready(function ($) {
    'use strict';

    var minOrder = 500000;
    var basePrice = 0;

    if (typeof vamarizData !== 'undefined') {
        if (vamarizData.minOrderTotal) {
            minOrder = parseFloat(vamarizData.minOrderTotal);
        }
        if (vamarizData.basePrice) {
            basePrice = parseFloat(vamarizData.basePrice);
        }
    }

    /**
     * Convert English digits to Persian digits.
     */
    function toPersianDigits(str) {
        if (str === null || str === undefined) return '';
        var faDigits = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        return str.toString().replace(/[0-9]/g, function (w) {
            return faDigits[+w];
        });
    }

    /**
     * Format number with comma thousands separator and Persian digits.
     */
    function formatMoneyFa(num) {
        var rounded = Math.round(num);
        var formatted = rounded.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        return toPersianDigits(formatted);
    }

    /**
     * Update installment figures across widget and modal with exact 4-stage breakdown.
     */
    function updateInstallments(price) {
        price = parseFloat(price);
        if (!price || isNaN(price) || price <= 0) {
            return;
        }

        var $widget = $('#vamariz-installment-widget');

        // Check against minimum order threshold
        if (price < minOrder) {
            $widget.stop(true, true).slideUp(200);
            return;
        } else {
            $widget.stop(true, true).slideDown(200);
        }

        var q1 = Math.round(price / 4);
        var q2 = Math.round(price / 4);
        var q3 = Math.round(price / 4);
        var q4 = Math.round(price) - (q1 + q2 + q3); // Reconcile odd remainder

        var formattedQuarter = formatMoneyFa(q1);
        var formattedTotal = formatMoneyFa(price);

        // Update widget primary price
        var $calcEl = $('.vamariz-calc-installment');
        $calcEl.fadeOut(120, function () {
            $(this).text(formattedQuarter).attr('data-raw', q1).fadeIn(150);
        });

        // Update modal values
        $('.vamariz-modal-total-display').text(formattedTotal);
        $('.vamariz-modal-inst-1').text(formatMoneyFa(q1));
        $('.vamariz-modal-inst-2').text(formatMoneyFa(q2));
        $('.vamariz-modal-inst-3').text(formatMoneyFa(q3));
        $('.vamariz-modal-inst-4').text(formatMoneyFa(q4));
    }

    // Initialize with localized base price
    if (basePrice > 0) {
        updateInstallments(basePrice);
    }

    // Support for variable products in WooCommerce & Woodmart
    $(document).on('found_variation', 'form.variations_form', function (event, variation) {
        if (variation) {
            var varPrice = null;
            if (typeof variation.display_price !== 'undefined') {
                varPrice = variation.display_price;
            } else if (typeof variation.price !== 'undefined') {
                varPrice = variation.price;
            }
            if (varPrice !== null && varPrice > 0) {
                updateInstallments(varPrice);
            }
        }
    });

    $(document).on('reset_data', 'form.variations_form', function () {
        if (basePrice > 0) {
            updateInstallments(basePrice);
        }
    });

    // Observer for dynamic variation price changes in Woodmart swatches
    var priceContainer = document.querySelector('.single-product-page .summary-inner .price, .single-product-page .woocommerce-variation-price');
    if (priceContainer && window.MutationObserver) {
        var observer = new MutationObserver(function () {
            var rawText = $(priceContainer).find('.amount bdi, .amount').last().text();
            if (rawText) {
                // Strip non-numeric chars except digits
                var clean = rawText.replace(/[^\d]/g, '');
                if (clean && clean.length >= 5) {
                    var parsed = parseFloat(clean);
                    if (parsed >= 10000) {
                        updateInstallments(parsed);
                    }
                }
            }
        });
        observer.observe(priceContainer, { childList: true, subtree: true, characterData: true });
    }

    // =========================================================================
    // Modal Interactivity & Accessible Focus Management
    // =========================================================================
    var $triggerBtn = $('#vamariz-open-modal-btn');
    var $modalOverlay = $('#vamariz-modal-overlay');
    var $modalDialog = $('#vamariz-modal-dialog');
    var $closeBtn = $('#vamariz-modal-close-btn');

    function openModal() {
        $modalOverlay.css('display', 'flex').hide().fadeIn(220, function () {
            $modalOverlay.attr('aria-hidden', 'false');
            $triggerBtn.attr('aria-expanded', 'true');
            $modalDialog.focus();
        });
        $('body').css('overflow', 'hidden');
    }

    function closeModal() {
        $modalOverlay.fadeOut(180, function () {
            $modalOverlay.attr('aria-hidden', 'true');
            $triggerBtn.attr('aria-expanded', 'false').focus();
        });
        $('body').css('overflow', '');
    }

    $(document).on('click', '#vamariz-open-modal-btn', function (e) {
        e.preventDefault();
        openModal();
    });

    $(document).on('click', '#vamariz-modal-close-btn, #vamariz-close-modal-confirm', function (e) {
        e.preventDefault();
        closeModal();
    });

    $modalOverlay.on('click', function (e) {
        if ($(e.target).is('#vamariz-modal-overlay')) {
            closeModal();
        }
    });

    // Keyboard navigation (Escape key and Tab focus trapping)
    $(document).on('keydown', function (e) {
        if (!$modalOverlay.is(':visible')) {
            return;
        }

        if (e.key === 'Escape' || e.keyCode === 27) {
            closeModal();
            return;
        }

        if (e.key === 'Tab' || e.keyCode === 9) {
            var focusableElements = $modalDialog.find('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
            if (focusableElements.length === 0) return;

            var firstEl = focusableElements.first();
            var lastEl = focusableElements.last();

            if (e.shiftKey) {
                if (document.activeElement === firstEl[0]) {
                    lastEl.focus();
                    e.preventDefault();
                }
            } else {
                if (document.activeElement === lastEl[0]) {
                    firstEl.focus();
                    e.preventDefault();
                }
            }
        }
    });
});
