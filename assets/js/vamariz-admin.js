/**
 * Vamariz Admin Dashboard Interactions
 */
jQuery(document).ready(function ($) {
    // Copy pattern template text helper
    $(document).on('click', '.vamariz-btn-copy, .vamariz-copy-btn', function (e) {
        e.preventDefault();
        var targetId = $(this).data('target');
        var targetEl = document.getElementById(targetId);
        var btn = $(this);
        var originalText = btn.text();

        if (targetEl) {
            var textToCopy = targetEl.value || targetEl.innerText || '';
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(textToCopy).then(function () {
                    btn.text('✓ کپی شد!');
                    setTimeout(function () {
                        btn.text(originalText);
                    }, 2000);
                });
            } else {
                targetEl.select();
                document.execCommand('copy');
                btn.text('✓ کپی شد!');
                setTimeout(function () {
                    btn.text(originalText);
                }, 2000);
            }
        }
    });
});
