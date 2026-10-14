/**
 * Vamareys Admin Dashboard Interactions
 */
jQuery(document).ready(function ($) {
    // 1. Copy pattern template text helper
    $(document).on('click', '.vamariz-btn-copy, .vamariz-copy-btn', function (e) {
        e.preventDefault();
        var targetId = $(this).data('target');
        var targetEl = document.getElementById(targetId);
        var btn = $(this);
        var originalText = btn.text();

        if (targetEl) {
            var textToCopy = targetEl.value || targetEl.innerText || '';
            copyTextToClipboard(textToCopy, function () {
                btn.text('✓ کپی شد!');
                setTimeout(function () {
                    btn.text(originalText);
                }, 2000);
            });
        }
    });

    // 2. Universal copy handler for payment links [data-copy-link]
    $(document).on('click', '.vamariz-copy-link-btn, [data-copy-link]', function (e) {
        e.preventDefault();
        var btn = $(this);
        var link = btn.attr('data-copy-link') || btn.data('link') || '';
        if (!link) return;

        var origHtml = btn.html();
        copyTextToClipboard(link, function () {
            btn.addClass('copied');
            btn.html('✓ کپی شد!');
            setTimeout(function () {
                btn.removeClass('copied');
                btn.html(origHtml);
            }, 2200);
        });
    });

    // 3. Quick Payment Link Box Tool Generator
    $('#vamariz-gen-paylink-btn').on('click', function (e) {
        e.preventDefault();
        var subId = parseInt($('#vamariz-quick-sub-id').val(), 10);
        if (!subId || isNaN(subId) || subId <= 0) {
            alert('لطفاً شناسه زیرسفارش معتبر را وارد نمایید.');
            return;
        }

        var baseUrl = window.location.origin || (window.location.protocol + '//' + window.location.host);
        var directUrl = baseUrl + '/?vamareys_pay=' + subId;
        var messageText = 'سلام،\nجهت تسویه آنلاین قسط سفارش خود در فروشگاه واماریز می‌توانید از لینک زیر استفاده فرمایید:\n' + directUrl + '\nبا تشکر از شما';

        $('#vamariz-quick-result-url').val(directUrl);
        $('#vamariz-quick-result-msg').val(messageText);
        $('#vamariz-quick-copy-link-btn').attr('data-copy-link', directUrl);
        $('#vamariz-quick-copy-msg-btn').attr('data-copy-link', messageText);
        $('#vamariz-quick-output-box').slideDown(200);
    });

    function copyTextToClipboard(text, callback) {
        if (!text) return;
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(function () {
                if (callback) callback();
            }).catch(function () {
                fallbackCopy(text, callback);
            });
        } else {
            fallbackCopy(text, callback);
        }
    }

    function fallbackCopy(text, callback) {
        var tempInput = document.createElement('textarea');
        tempInput.style.position = 'fixed';
        tempInput.style.left = '-9999px';
        tempInput.style.top = '0';
        tempInput.value = text;
        document.body.appendChild(tempInput);
        tempInput.focus();
        tempInput.select();
        try {
            document.execCommand('copy');
            if (callback) callback();
        } catch (err) {
            console.error('Failed to copy text', err);
        }
        document.body.removeChild(tempInput);
    }
});
