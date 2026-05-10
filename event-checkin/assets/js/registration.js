/**
 * Event Check-in - Registration Form JavaScript
 */
(function ($) {
    'use strict';

    $(document).on('submit', '.ec-registration-form', function (e) {
        e.preventDefault();

        var $form = $(this);
        var $btn = $form.find('.ec-submit-btn');
        var $msg = $form.find('.ec-message');
        var $wrapper = $form.closest('.ec-registration-wrapper');

        // Clear previous errors.
        $form.find('.ec-invalid').removeClass('ec-invalid');
        $form.find('.ec-field-error').remove();
        $msg.hide().removeClass('ec-message--error ec-message--success');

        // Client-side validation.
        var valid = true;
        $form.find('[required]').each(function () {
            var $field = $(this);
            var val = $field.val();

            if ($field.is(':checkbox') && !$field.is(':checked')) {
                valid = false;
                $field.addClass('ec-invalid');
                $field.closest('.ec-field').append(
                    '<span class="ec-field-error">' + ecRegistration.i18n.required + '</span>'
                );
            } else if (!val || !val.trim()) {
                valid = false;
                $field.addClass('ec-invalid');
                $field.closest('.ec-field').append(
                    '<span class="ec-field-error">' + ecRegistration.i18n.required + '</span>'
                );
            }
        });

        // Email validation.
        var $email = $form.find('input[type="email"]');
        if ($email.length && $email.val()) {
            var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test($email.val())) {
                valid = false;
                $email.addClass('ec-invalid');
                $email.closest('.ec-field').append(
                    '<span class="ec-field-error">' + ecRegistration.i18n.invalidEmail + '</span>'
                );
            }
        }

        if (!valid) {
            return;
        }

        // Disable button.
        $btn.prop('disabled', true).text(ecRegistration.i18n.submitting);

        $.ajax({
            url: ecRegistration.ajaxUrl,
            type: 'POST',
            data: $form.serialize(),
            dataType: 'json',
            success: function (response) {
                if (response.success) {
                    $form.hide();
                    var $success = $wrapper.find('.ec-success-panel');
                    $success.show();

                    if (response.data && response.data.qr_url) {
                        $success.find('.ec-qr-preview').html(
                            '<img src="' + response.data.qr_url + '" alt="QR Code">'
                        );
                    }
                } else {
                    var message = response.data && response.data.message
                        ? response.data.message
                        : ecRegistration.i18n.error;
                    $msg.addClass('ec-message--error').text(message).show();
                    $btn.prop('disabled', false).text($btn.data('original-text') || 'Register');
                }
            },
            error: function (xhr) {
                var message = ecRegistration.i18n.error;
                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    message = xhr.responseJSON.data.message;
                }
                $msg.addClass('ec-message--error').text(message).show();
                $btn.prop('disabled', false).text($btn.data('original-text') || 'Register');
            }
        });
    });

    // Store original button text.
    $('.ec-submit-btn').each(function () {
        $(this).data('original-text', $(this).text());
    });

})(jQuery);
