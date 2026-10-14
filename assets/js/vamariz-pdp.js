/**
 * Vamareys PDP Dynamic Price Calculator & Modal Interactivity
 * Modern Persian Numerals & Accessible Focus Management
 */
jQuery(document).ready(function ($) {
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
     * Format number with standard thousands separator and Persian digits.
     */
    function formatMoneyFa(num) {
        var rounded = Math.round(num);
        var formatted = rounded.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
        return toPersianDigits(formatted);
    }

    /**
     * Update installment figures across widget and modal.
     */
    function updateInstallments(price) {
        if (!price || price <= 0) return;
        var quarter = Math.round(price / 4);
        var formattedQuarter = formatMoneyFa(quarter);
        var formattedTotal = formatMoneyFa(price);

        // Update widget
        $('.vamariz-calc-installment').text(formattedQuarter);
        $('.vamariz-installment-amount').attr('data-raw', quarter);

        // Update modal
        $('.vamariz-modal-total-display').text(formattedTotal);
        $('.vamariz-modal-inst-1, .vamariz-modal-inst-2, .vamariz-modal-inst-3, .vamariz-modal-inst-4').text(formattedQuarter);
    }

    // Initialize with localized base price
    if (typeof vamarizData !== 'undefined' && vamarizData.basePrice > 0) {
        updateInstallments(parseFloat(vamarizData.basePrice));
    }

    // Support for variable products (Woodmart / WooCommerce standard variations form)
    $(document).on('found_variation', 'form.variations_form', function (event, variation) {
        if (variation && variation.display_price) {
            updateInstallments(parseFloat(variation.display_price));
        }
    });

    $(document).on('reset_data', 'form.variations_form', function () {
        if (typeof vamarizData !== 'undefined' && vamarizData.basePrice > 0) {
            updateInstallments(parseFloat(vamarizData.basePrice));
        }
    });

    // Modal Focus & ARIA Management
    var $triggerBtn = $('#vamariz-open-modal-btn');
    var $modalOverlay = $('#vamariz-modal-overlay');
    var $modalDialog = $('#vamariz-modal-dialog');
    var $closeBtn = $('#vamariz-modal-close-btn');

    function openModal() {
        $modalOverlay.fadeIn(200, function () {
            $modalOverlay.attr('aria-hidden', 'false');
            $triggerBtn.attr('aria-expanded', 'true');
            $closeBtn.focus();
        });
        $('body').css('overflow', 'hidden');
    }

    function closeModal() {
        $modalOverlay.fadeOut(150, function () {
            $modalOverlay.attr('aria-hidden', 'true');
            $triggerBtn.attr('aria-expanded', 'false').focus();
        });
        $('body').css('overflow', '');
    }

    $triggerBtn.on('click', function (e) {
        e.preventDefault();
        openModal();
    });

    $('#vamariz-modal-close-btn, #vamariz-close-modal-confirm').on('click', function (e) {
        e.preventDefault();
        closeModal();
    });

    $modalOverlay.on('click', function (e) {
        if ($(e.target).is('#vamariz-modal-overlay')) {
            closeModal();
        }
    });

    // Keyboard support: Escape to close and Tab trapping
    $(document).on('keydown', function (e) {
        if (!$modalOverlay.is(':visible')) {
            return;
        }

        if (e.key === 'Escape') {
            closeModal();
            return;
        }

        if (e.key === 'Tab') {
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
