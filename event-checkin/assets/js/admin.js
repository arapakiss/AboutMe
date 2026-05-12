/**
 * Event Check-in - Admin JavaScript
 * Handles custom fields builder and dashboard interactions.
 */
(function ($) {
    'use strict';

    // =========================================================================
    // Custom Fields Builder (Event Form)
    // =========================================================================

    var fieldIndex = $('.ec-custom-field').length;

    $('#ec-add-field').on('click', function () {
        var html = '<div class="ec-custom-field" data-index="' + fieldIndex + '">' +
            '<input type="text" name="ec_cf_label[]" value="" placeholder="Field Label">' +
            '<select name="ec_cf_type[]">' +
            '<option value="text">Text</option>' +
            '<option value="textarea">Textarea</option>' +
            '<option value="select">Select</option>' +
            '<option value="checkbox">Checkbox</option>' +
            '</select>' +
            '<input type="text" name="ec_cf_options[]" value="" placeholder="Options (comma-separated, for select)">' +
            '<label><input type="checkbox" name="ec_cf_required[' + fieldIndex + ']" value="1"> Required</label>' +
            '<button type="button" class="button ec-remove-field">&times;</button>' +
            '</div>';
        $('#ec-custom-fields').append(html);
        fieldIndex++;
    });

    $(document).on('click', '.ec-remove-field', function () {
        $(this).closest('.ec-custom-field').remove();
    });

    // =========================================================================
    // Toast Notifications
    // =========================================================================

    function showToast(message, type) {
        type = type || 'success';
        var $container = $('#ec-toast-container');
        if (!$container.length) {
            $container = $('<div id="ec-toast-container"></div>').appendTo('body');
        }
        var icon = type === 'success' ? '&#10003;' : type === 'error' ? '&#10007;' : '&#9432;';
        var $toast = $('<div class="ec-toast ec-toast--' + type + '">' +
            '<span>' + icon + '</span> ' + message +
            '</div>');
        $container.append($toast);

        setTimeout(function () {
            $toast.css('animation', 'ec-toast-out 0.3s ease forwards');
            setTimeout(function () { $toast.remove(); }, 300);
        }, 4000);
    }

    // =========================================================================
    // Modal Helpers
    // =========================================================================

    function openModal(id) {
        $(id).css('display', 'flex');
        $('body').css('overflow', 'hidden');
    }

    function closeModal(id) {
        $(id).css('display', 'none');
        $('body').css('overflow', '');
    }

    // Close modals on overlay click or close button.
    $(document).on('click', '.ec-modal-overlay, .ec-modal-close', function () {
        $(this).closest('.ec-modal').css('display', 'none');
        $('body').css('overflow', '');
    });

    // Close modal on Escape key.
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') {
            $('.ec-modal').each(function () {
                if ($(this).css('display') !== 'none') {
                    $(this).css('display', 'none');
                }
            });
            $('body').css('overflow', '');
        }
    });

    // =========================================================================
    // Edit Registration
    // =========================================================================

    $(document).on('click', '.ec-btn-edit', function () {
        var regId = $(this).data('id');
        var $modal = $('#ec-modal-edit');
        var $title = $('#ec-modal-edit-title');

        // Clear form.
        $('#ec-edit-form')[0].reset();
        $('#ec-edit-reg-id').val(regId);

        if (regId) {
            $title.text(ecAdmin.i18n.editTitle);
            // Load data via AJAX.
            $.post(ecAdmin.ajaxUrl, {
                action: 'ec_get_registration',
                nonce: ecAdmin.nonce,
                reg_id: regId
            }, function (response) {
                if (response.success) {
                    var d = response.data;
                    $('#ec-edit-first-name').val(d.first_name);
                    $('#ec-edit-last-name').val(d.last_name);
                    $('#ec-edit-email').val(d.email);
                    $('#ec-edit-phone').val(d.phone);
                    $('#ec-edit-status').val(d.status);

                    // Fill custom fields.
                    if (d.custom_data) {
                        $.each(d.custom_data, function (key, value) {
                            var $field = $modal.find('[name="custom_' + key + '"]');
                            if ($field.is(':checkbox')) {
                                $field.prop('checked', value === '1' || value === 1);
                            } else {
                                $field.val(value);
                            }
                        });
                    }

                    openModal('#ec-modal-edit');
                } else {
                    showToast(response.data.message || ecAdmin.i18n.error, 'error');
                }
            });
        }
    });

    // Save edit.
    $('#ec-btn-save-edit').on('click', function () {
        var $btn = $(this);
        var regId = $('#ec-edit-reg-id').val();
        var isNew = !regId;

        $btn.prop('disabled', true).text(ecAdmin.i18n.saving);

        var formData = $('#ec-edit-form').serializeArray();
        formData.push({ name: 'nonce', value: ecAdmin.nonce });

        if (isNew) {
            formData.push({ name: 'action', value: 'ec_add_registration' });
        } else {
            formData.push({ name: 'action', value: 'ec_update_registration' });
        }

        $.post(ecAdmin.ajaxUrl, formData, function (response) {
            $btn.prop('disabled', false).text(isNew ? ecAdmin.i18n.addTitle : ecAdmin.i18n.editTitle);

            if (response.success) {
                closeModal('#ec-modal-edit');
                showToast(response.data.message, 'success');

                if (isNew) {
                    // Reload page to show new registration.
                    location.reload();
                } else {
                    // Update the row in-place.
                    var $row = $('#ec-reg-row-' + regId);
                    if ($row.length && response.data) {
                        var d = response.data;
                        $row.find('.ec-col-name strong').text(d.first_name + ' ' + d.last_name);
                        $row.find('.ec-col-email').text(d.email);
                        $row.find('.ec-col-phone').text(d.phone);
                        $row.find('.ec-col-status').html(
                            '<span class="ec-status ec-status--' + d.status + '">' +
                            d.status.replace('_', ' ').replace(/\b\w/g, function (l) { return l.toUpperCase(); }) +
                            '</span>'
                        );
                        $row.addClass('ec-row-updated');
                        setTimeout(function () { $row.removeClass('ec-row-updated'); }, 1500);

                        // Update action buttons based on status.
                        updateRowActions($row, d.status);
                    }
                }
            } else {
                showToast(response.data.message || ecAdmin.i18n.error, 'error');
            }
        }).fail(function () {
            $btn.prop('disabled', false).text(isNew ? ecAdmin.i18n.addTitle : ecAdmin.i18n.editTitle);
            showToast(ecAdmin.i18n.error, 'error');
        });
    });

    // =========================================================================
    // Add Registration
    // =========================================================================

    $('#ec-btn-add-registration').on('click', function () {
        var eventId = $(this).data('event-id');
        var $modal = $('#ec-modal-edit');

        // Clear form and set up for "add" mode.
        $('#ec-edit-form')[0].reset();
        $('#ec-edit-reg-id').val('');
        $('#ec-edit-event-id').val(eventId);
        $('#ec-edit-status').val('registered');
        $('#ec-modal-edit-title').text(ecAdmin.i18n.addTitle);
        $('#ec-btn-save-edit').text(ecAdmin.i18n.addTitle);

        openModal('#ec-modal-edit');
    });

    // =========================================================================
    // Resend Email
    // =========================================================================

    $(document).on('click', '.ec-btn-resend', function () {
        var regId = $(this).data('id');
        var email = $(this).data('email');

        $('#ec-resend-reg-id').val(regId);
        $('#ec-resend-original-email').text(email);
        $('input[name="ec_resend_target"][value="original"]').prop('checked', true);
        $('#ec-resend-custom-email').val('').hide();

        openModal('#ec-modal-resend');
    });

    // Toggle custom email input.
    $('input[name="ec_resend_target"]').on('change', function () {
        if ($(this).val() === 'custom') {
            $('#ec-resend-custom-email').show().focus();
        } else {
            $('#ec-resend-custom-email').hide();
        }
    });

    // Send email.
    $('#ec-btn-send-email').on('click', function () {
        var $btn = $(this);
        var regId = $('#ec-resend-reg-id').val();
        var target = $('input[name="ec_resend_target"]:checked').val();
        var targetEmail = '';

        if (target === 'custom') {
            targetEmail = $('#ec-resend-custom-email').val();
            if (!targetEmail || !targetEmail.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
                showToast('Please enter a valid email address.', 'error');
                return;
            }
        }

        $btn.prop('disabled', true).text(ecAdmin.i18n.sending);

        $.post(ecAdmin.ajaxUrl, {
            action: 'ec_resend_email',
            nonce: ecAdmin.nonce,
            reg_id: regId,
            target_email: targetEmail
        }, function (response) {
            $btn.prop('disabled', false).html('<span class="dashicons dashicons-email" style="vertical-align: text-bottom;"></span> Send Email');

            if (response.success) {
                closeModal('#ec-modal-resend');
                showToast(response.data.message, 'success');
            } else {
                showToast(response.data.message || ecAdmin.i18n.emailFailed, 'error');
            }
        }).fail(function () {
            $btn.prop('disabled', false).html('<span class="dashicons dashicons-email" style="vertical-align: text-bottom;"></span> Send Email');
            showToast(ecAdmin.i18n.error, 'error');
        });
    });

    // =========================================================================
    // Manual Check-in
    // =========================================================================

    $(document).on('click', '.ec-btn-checkin', function () {
        if (!confirm(ecAdmin.i18n.confirmCheckin)) {
            return;
        }

        var $btn = $(this);
        var regId = $btn.data('id');

        $btn.prop('disabled', true);

        $.post(ecAdmin.ajaxUrl, {
            action: 'ec_manual_checkin',
            nonce: ecAdmin.nonce,
            reg_id: regId
        }, function (response) {
            if (response.success) {
                showToast(response.data.message, 'success');

                var $row = $('#ec-reg-row-' + regId);
                $row.find('.ec-col-status').html(
                    '<span class="ec-status ec-status--checked_in">Checked In</span>'
                );
                $row.find('.ec-col-checkin').text(response.data.checked_in_at);
                $row.addClass('ec-row-updated');
                setTimeout(function () { $row.removeClass('ec-row-updated'); }, 1500);

                updateRowActions($row, 'checked_in');
            } else {
                showToast(response.data.message || ecAdmin.i18n.error, 'error');
                $btn.prop('disabled', false);
            }
        }).fail(function () {
            showToast(ecAdmin.i18n.error, 'error');
            $btn.prop('disabled', false);
        });
    });

    // =========================================================================
    // Cancel Registration
    // =========================================================================

    $(document).on('click', '.ec-btn-cancel', function () {
        if (!confirm(ecAdmin.i18n.confirmCancel)) {
            return;
        }

        var $btn = $(this);
        var regId = $btn.data('id');

        $btn.prop('disabled', true);

        $.post(ecAdmin.ajaxUrl, {
            action: 'ec_cancel_registration',
            nonce: ecAdmin.nonce,
            reg_id: regId
        }, function (response) {
            if (response.success) {
                showToast(response.data.message || ecAdmin.i18n.cancelSuccess, 'success');

                var $row = $('#ec-reg-row-' + regId);
                $row.find('.ec-col-status').html(
                    '<span class="ec-status ec-status--cancelled">Cancelled</span>'
                );
                $row.addClass('ec-row-updated');
                setTimeout(function () { $row.removeClass('ec-row-updated'); }, 1500);

                updateRowActions($row, 'cancelled');
            } else {
                showToast(response.data.message || ecAdmin.i18n.error, 'error');
                $btn.prop('disabled', false);
            }
        }).fail(function () {
            showToast(ecAdmin.i18n.error, 'error');
            $btn.prop('disabled', false);
        });
    });

    // =========================================================================
    // Helper: Update row action buttons based on new status
    // =========================================================================

    function updateRowActions($row, status) {
        // Remove check-in button if checked in or cancelled.
        if (status === 'checked_in' || status === 'cancelled') {
            $row.find('.ec-btn-checkin').remove();
        }
        // Remove cancel button if already cancelled.
        if (status === 'cancelled') {
            $row.find('.ec-btn-cancel').remove();
        }
    }

    // =========================================================================
    // View QR Code
    // =========================================================================

    $(document).on('click', '.ec-btn-view-qr', function () {
        var regId = $(this).data('id');
        var name = $(this).data('name');

        $('#ec-qr-reg-id').val(regId);
        $('#ec-qr-person-name').text(name);
        $('#ec-qr-image').hide();
        $('#ec-qr-loading').show();

        openModal('#ec-modal-qr');

        $.post(ecAdmin.ajaxUrl, {
            action: 'ec_view_qr',
            nonce: ecAdmin.nonce,
            reg_id: regId
        }, function (response) {
            $('#ec-qr-loading').hide();
            if (response.success) {
                var d = response.data;
                // Add cache-busting param to force reload after regenerate.
                $('#ec-qr-image').attr('src', d.qr_url + '?t=' + Date.now()).show();
                $('#ec-btn-download-qr-modal').attr('href', d.download_url);
            } else {
                showToast(response.data.message || ecAdmin.i18n.error, 'error');
                closeModal('#ec-modal-qr');
            }
        }).fail(function () {
            $('#ec-qr-loading').hide();
            showToast(ecAdmin.i18n.error, 'error');
        });
    });

    // Regenerate QR Code.
    $('#ec-btn-regenerate-qr').on('click', function () {
        var $btn = $(this);
        var regId = $('#ec-qr-reg-id').val();

        if (!confirm('Regenerate the QR code? The old image will be replaced.')) {
            return;
        }

        $btn.prop('disabled', true);
        $('#ec-qr-image').hide();
        $('#ec-qr-loading').show();

        $.post(ecAdmin.ajaxUrl, {
            action: 'ec_regenerate_qr',
            nonce: ecAdmin.nonce,
            reg_id: regId
        }, function (response) {
            $btn.prop('disabled', false);
            $('#ec-qr-loading').hide();

            if (response.success) {
                var d = response.data;
                $('#ec-qr-image').attr('src', d.qr_url + '?t=' + Date.now()).show();
                $('#ec-btn-download-qr-modal').attr('href', d.download_url);
                showToast(d.message, 'success');
            } else {
                showToast(response.data.message || ecAdmin.i18n.error, 'error');
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            $('#ec-qr-loading').hide();
            showToast(ecAdmin.i18n.error, 'error');
        });
    });

    // =========================================================================
    // Bulk Selection
    // =========================================================================

    function updateBulkBar() {
        var count = $('.ec-row-check:checked').length;
        $('#ec-bulk-count').text(count);
        if (count > 0) {
            $('#ec-bulk-bar').show();
        } else {
            $('#ec-bulk-bar').hide();
        }
    }

    // Select all checkbox.
    $('#ec-select-all').on('change', function () {
        var checked = $(this).is(':checked');
        $('.ec-row-check').prop('checked', checked);
        if (checked) {
            $('.ec-row-check').closest('tr').addClass('ec-row-selected');
        } else {
            $('.ec-row-check').closest('tr').removeClass('ec-row-selected');
        }
        updateBulkBar();
    });

    // Individual row checkbox.
    $(document).on('change', '.ec-row-check', function () {
        var $row = $(this).closest('tr');
        if ($(this).is(':checked')) {
            $row.addClass('ec-row-selected');
        } else {
            $row.removeClass('ec-row-selected');
            $('#ec-select-all').prop('checked', false);
        }

        // If all are checked, check the select-all too.
        if ($('.ec-row-check').length === $('.ec-row-check:checked').length && $('.ec-row-check').length > 0) {
            $('#ec-select-all').prop('checked', true);
        }

        updateBulkBar();
    });

    // Deselect all.
    $('#ec-bulk-deselect').on('click', function () {
        $('.ec-row-check').prop('checked', false);
        $('#ec-select-all').prop('checked', false);
        $('tr').removeClass('ec-row-selected');
        updateBulkBar();
    });

    // =========================================================================
    // Bulk Actions
    // =========================================================================

    $(document).on('click', '.ec-bulk-btn', function () {
        var action = $(this).data('action');
        var regIds = [];

        $('.ec-row-check:checked').each(function () {
            regIds.push($(this).val());
        });

        if (regIds.length === 0) {
            showToast('No registrations selected.', 'error');
            return;
        }

        var confirmMessages = {
            checkin: 'Check in ' + regIds.length + ' selected registration(s)?',
            cancel: 'Cancel ' + regIds.length + ' selected registration(s)? This cannot be easily undone.',
            resend: 'Resend confirmation email to ' + regIds.length + ' selected registration(s)?'
        };

        if (!confirm(confirmMessages[action] || 'Proceed with bulk action?')) {
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true);

        $.post(ecAdmin.ajaxUrl, {
            action: 'ec_bulk_action',
            nonce: ecAdmin.nonce,
            bulk_action: action,
            reg_ids: regIds
        }, function (response) {
            $btn.prop('disabled', false);

            if (response.success) {
                showToast(response.data.message, 'success');
                // Reload page to reflect changes.
                setTimeout(function () { location.reload(); }, 1200);
            } else {
                showToast(response.data.message || ecAdmin.i18n.error, 'error');
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            showToast(ecAdmin.i18n.error, 'error');
        });
    });

})(jQuery);
