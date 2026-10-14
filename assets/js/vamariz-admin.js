/**
 * Vamariz Admin Dashboard Interactions
 */
jQuery(document).ready(function ($) {
    // Copy link helper
    $('.vamariz-copy-btn').on('click', function (e) {
        e.preventDefault();
        var targetInput = $(this).data('target');
        var input = $(targetInput);
        if (input.length) {
            input.select();
            document.execCommand('copy');
            var originalText = $(this).text();
            var btn = $(this);
            btn.text('کپی شد!');
            setTimeout(function () {
                btn.text(originalText);
            }, 2000);
        }
    });
});
