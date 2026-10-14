/**
 * Vamariz PDP Dynamic Price Calculator & Modal Interactivity
 */
jQuery(document).ready(function ($) {
    function formatMoney(num) {
        return Math.round(num).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    }

    function updateInstallments(price) {
        if (!price || price <= 0) return;
        var quarter = Math.round(price / 4);
        var formattedQuarter = formatMoney(quarter);
        var formattedTotal = formatMoney(price);

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

    // Support for variable products (Woodmart / WooCommerce standard)
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

    // Modal Events
    $('#vamariz-open-modal-btn').on('click', function (e) {
        e.preventDefault();
        $('#vamariz-modal-overlay').fadeIn(200);
        $('body').css('overflow', 'hidden');
    });

    $('#vamariz-modal-close-btn, #vamariz-close-modal-confirm').on('click', function (e) {
        e.preventDefault();
        $('#vamariz-modal-overlay').fadeOut(150);
        $('body').css('overflow', '');
    });

    $('#vamariz-modal-overlay').on('click', function (e) {
        if ($(e.target).is('#vamariz-modal-overlay')) {
            $(this).fadeOut(150);
            $('body').css('overflow', '');
        }
    });

    $(document).on('keydown', function (e) {
        if (e.key === 'Escape' && $('#vamariz-modal-overlay').is(':visible')) {
            $('#vamariz-modal-overlay').fadeOut(150);
            $('body').css('overflow', '');
        }
    });
});
