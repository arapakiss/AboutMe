/**
 * Event Check-in - Staff Mobile App JavaScript
 * SPA-like mobile interface with bottom navigation.
 */
(function ($) {
    'use strict';

    var $app = $('#ec-staff-app');
    if (!$app.length) return;

    var eventId = $app.data('event-id');
    var API = ecStaffApp.restUrl;
    var i18n = ecStaffApp.i18n;

    // State
    var currentScreen = 'guests';
    var guestPage = 1;
    var guestTotal = 0;
    var guestTotalPages = 0;
    var searchDebounce = null;
    var qrScanner = null;
    var scannerRunning = false;
    var previousScreen = 'guests';

    // Hide WP admin bar and chrome.
    $('body').addClass('ec-staff-app-active');

    // =========================================================================
    // Navigation
    // =========================================================================

    $('.ec-sa-nav-item').on('click', function () {
        var screen = $(this).data('screen');
        switchScreen(screen);
    });

    function switchScreen(screen) {
        // Hide profile overlay if showing.
        $('#ec-sa-screen-profile').hide().removeClass('active');

        // Deactivate all screens and nav items.
        $('.ec-sa-screen').removeClass('active');
        $('.ec-sa-nav-item').removeClass('active');

        // Activate the target.
        $('#ec-sa-screen-' + screen).addClass('active');
        $('.ec-sa-nav-item[data-screen="' + screen + '"]').addClass('active');

        // Stop scanner if leaving scan screen.
        if (currentScreen === 'scan' && screen !== 'scan') {
            stopScanner();
        }

        previousScreen = currentScreen;
        currentScreen = screen;

        // Load data for the screen.
        if (screen === 'guests') {
            loadGuests(true);
        } else if (screen === 'scan') {
            startScanner();
        } else if (screen === 'history') {
            loadHistory();
        } else if (screen === 'add') {
            // Reset form.
            $('#ec-sa-add-form')[0].reset();
            $('#ec-sa-add-message').hide();
        }
    }

    // =========================================================================
    // Stats Badge
    // =========================================================================

    function refreshStats() {
        $.ajax({
            url: API + '/stats/' + eventId,
            headers: { 'X-WP-Nonce': ecStaffApp.nonce },
            success: function (data) {
                $('#ec-sa-stats-badge').text(data.checked_in + ' / ' + data.total);
            }
        });
    }

    // =========================================================================
    // Guest List
    // =========================================================================

    function loadGuests(reset) {
        if (reset) {
            guestPage = 1;
            $('#ec-sa-guest-list').html('<div class="ec-sa-loading">' + i18n.loading + '</div>');
        }

        var search = $('#ec-sa-search').val() || '';
        var status = $('#ec-sa-status-filter').val() || '';

        $.ajax({
            url: API + '/registrations/' + eventId,
            headers: { 'X-WP-Nonce': ecStaffApp.nonce },
            data: { s: search, status: status, page: guestPage, per_page: 30 },
            success: function (data) {
                guestTotal = data.total;
                guestTotalPages = data.total_pages;

                if (reset) {
                    $('#ec-sa-guest-list').empty();
                }

                if (data.items.length === 0 && guestPage === 1) {
                    $('#ec-sa-guest-list').html(
                        '<div class="ec-sa-empty">' +
                        '<div class="ec-sa-empty-icon">&#128100;</div>' +
                        '<div class="ec-sa-empty-text">' + i18n.noGuests + '</div>' +
                        '</div>'
                    );
                    $('#ec-sa-load-more').hide();
                    return;
                }

                data.items.forEach(function (guest) {
                    $('#ec-sa-guest-list').append(renderGuestItem(guest));
                });

                if (guestPage < guestTotalPages) {
                    $('#ec-sa-load-more').show();
                } else {
                    $('#ec-sa-load-more').hide();
                }
            },
            error: function () {
                $('#ec-sa-guest-list').html(
                    '<div class="ec-sa-empty"><div class="ec-sa-empty-text">' + i18n.error + '</div></div>'
                );
            }
        });
    }

    function renderGuestItem(guest) {
        var initials = (guest.first_name.charAt(0) + guest.last_name.charAt(0)).toUpperCase();
        var statusClass = 'ec-sa-badge--' + guest.status;
        var statusText = guest.status === 'checked_in' ? i18n.checkedIn :
                         guest.status === 'cancelled' ? i18n.cancelled : i18n.registered;

        return '<div class="ec-sa-guest-item" data-id="' + guest.id + '">' +
            '<div class="ec-sa-guest-avatar">' + initials + '</div>' +
            '<div class="ec-sa-guest-info">' +
            '<div class="ec-sa-guest-name">' + escHtml(guest.first_name + ' ' + guest.last_name) + '</div>' +
            '<div class="ec-sa-guest-email">' + escHtml(guest.email) + '</div>' +
            '</div>' +
            '<div class="ec-sa-guest-status">' +
            '<span class="ec-sa-badge ' + statusClass + '">' + statusText + '</span>' +
            '</div>' +
            '</div>';
    }

    // Search with debounce.
    $('#ec-sa-search').on('input', function () {
        clearTimeout(searchDebounce);
        searchDebounce = setTimeout(function () {
            loadGuests(true);
        }, 300);
    });

    // Status filter.
    $('#ec-sa-status-filter').on('change', function () {
        loadGuests(true);
    });

    // Load more.
    $('#ec-sa-btn-load-more').on('click', function () {
        guestPage++;
        loadGuests(false);
    });

    // Guest item click -> profile.
    $(document).on('click', '.ec-sa-guest-item', function () {
        var id = $(this).data('id');
        openProfile(id);
    });

    // =========================================================================
    // Guest Profile
    // =========================================================================

    function openProfile(regId) {
        var $screen = $('#ec-sa-screen-profile');
        var $content = $('#ec-sa-profile-content');

        $content.html('<div class="ec-sa-loading">' + i18n.loading + '</div>');
        $screen.show().addClass('active');

        $.ajax({
            url: API + '/registration/' + regId,
            headers: { 'X-WP-Nonce': ecStaffApp.nonce },
            success: function (data) {
                $content.html(renderProfileFull(data));
            },
            error: function () {
                $content.html('<div class="ec-sa-empty"><div class="ec-sa-empty-text">' + i18n.error + '</div></div>');
            }
        });
    }

    function renderProfileFull(data) {
        var initials = (data.first_name.charAt(0) + data.last_name.charAt(0)).toUpperCase();
        var statusText = data.status === 'checked_in' ? i18n.checkedIn :
                         data.status === 'cancelled' ? i18n.cancelled : i18n.registered;

        var html = '<div class="ec-sa-profile-card">';

        // Header.
        html += '<div class="ec-sa-profile-card-header">';
        html += '<div class="ec-sa-profile-avatar-lg">' + initials + '</div>';
        html += '<div class="ec-sa-profile-name">' + escHtml(data.first_name + ' ' + data.last_name) + '</div>';
        html += '<div class="ec-sa-profile-email-header">' + escHtml(data.email) + '</div>';
        html += '</div>';

        // Body.
        html += '<div class="ec-sa-profile-card-body">';
        html += renderDetailRow(i18n.status, '<span class="ec-sa-badge ec-sa-badge--' + data.status + '">' + statusText + '</span>');
        html += renderDetailRow(i18n.email, escHtml(data.email));
        if (data.phone) {
            html += renderDetailRow(i18n.phone || 'Phone', escHtml(data.phone));
        }
        html += renderDetailRow(i18n.registeredAt, formatDate(data.created_at));
        if (data.checked_in_at) {
            html += renderDetailRow(i18n.checkedInAt, formatDate(data.checked_in_at));
        }
        if (data.signature) {
            html += renderDetailRow('Signature', '<span style="color:#059669;">&#10003; Signed</span>');
        }

        // Custom fields.
        if (data.custom_fields && data.custom_fields.length > 0) {
            data.custom_fields.forEach(function (cf) {
                if (cf.value) {
                    html += renderDetailRow(cf.label, escHtml(cf.value));
                }
            });
        }

        // QR Code.
        if (data.qr_url) {
            html += '<div style="text-align:center; padding: 16px 0;">';
            html += '<img src="' + data.qr_url + '" alt="QR" style="width:140px; height:140px; border: 2px solid #002d72;">';
            html += '</div>';
        }

        html += '</div>';

        // Actions.
        html += '<div class="ec-sa-profile-actions">';
        if (data.status === 'registered') {
            html += '<button type="button" class="ec-sa-btn ec-sa-btn--success ec-sa-btn--full ec-sa-btn--lg ec-sa-btn-toggle-checkin" data-id="' + data.id + '">';
            html += '&#10003; ' + i18n.checkIn;
            html += '</button>';
        } else if (data.status === 'checked_in') {
            html += '<button type="button" class="ec-sa-btn ec-sa-btn--warning ec-sa-btn--full ec-sa-btn--lg ec-sa-btn-toggle-checkin" data-id="' + data.id + '">';
            html += '&#8634; ' + i18n.undoCheckIn;
            html += '</button>';
        }
        html += '</div>';

        html += '</div>';
        return html;
    }

    function renderProfileCard(data) {
        // Compact profile card for scan result.
        var initials = (data.first_name.charAt(0) + data.last_name.charAt(0)).toUpperCase();
        var statusText = data.status === 'checked_in' ? i18n.checkedIn :
                         data.status === 'cancelled' ? i18n.cancelled : i18n.registered;

        var html = '<div class="ec-sa-profile-card">';
        html += '<div class="ec-sa-profile-card-header">';
        html += '<div class="ec-sa-profile-avatar-lg">' + initials + '</div>';
        html += '<div class="ec-sa-profile-name">' + escHtml(data.first_name + ' ' + data.last_name) + '</div>';
        html += '<div class="ec-sa-profile-email-header">' + escHtml(data.email) + '</div>';
        html += '</div>';

        html += '<div class="ec-sa-profile-card-body">';
        html += renderDetailRow(i18n.status, '<span class="ec-sa-badge ec-sa-badge--' + data.status + '">' + statusText + '</span>');
        if (data.phone) {
            html += renderDetailRow(i18n.phone || 'Phone', escHtml(data.phone));
        }
        html += renderDetailRow(i18n.registeredAt, formatDate(data.created_at));
        if (data.checked_in_at) {
            html += renderDetailRow(i18n.checkedInAt, formatDate(data.checked_in_at));
        }
        if (!data.same_event) {
            html += renderDetailRow('Event', '<span style="color: #dc2626; font-weight: 700;">&#9888; ' + escHtml(data.event_title) + '</span>');
        }
        html += '</div>';

        html += '<div class="ec-sa-profile-actions">';
        if (data.status === 'registered' && data.same_event !== false) {
            html += '<button type="button" class="ec-sa-btn ec-sa-btn--success ec-sa-btn--full ec-sa-btn--lg ec-sa-btn-toggle-checkin" data-id="' + data.id + '">';
            html += '&#10003; ' + i18n.checkIn;
            html += '</button>';
        } else if (data.status === 'checked_in') {
            html += '<button type="button" class="ec-sa-btn ec-sa-btn--warning ec-sa-btn--full ec-sa-btn--lg ec-sa-btn-toggle-checkin" data-id="' + data.id + '">';
            html += '&#8634; ' + i18n.undoCheckIn;
            html += '</button>';
        }
        html += '<button type="button" class="ec-sa-btn ec-sa-btn--secondary ec-sa-btn--full" id="ec-sa-btn-scan-again">';
        html += i18n.scanAgain;
        html += '</button>';
        html += '</div>';

        html += '</div>';
        return html;
    }

    function renderDetailRow(label, value) {
        return '<div class="ec-sa-profile-detail">' +
            '<span class="ec-sa-profile-detail-label">' + label + '</span>' +
            '<span class="ec-sa-profile-detail-value">' + value + '</span>' +
            '</div>';
    }

    // Back from profile.
    $('#ec-sa-btn-back-profile').on('click', function () {
        $('#ec-sa-screen-profile').hide().removeClass('active');
    });

    // Toggle check-in from profile.
    $(document).on('click', '.ec-sa-btn-toggle-checkin', function () {
        var $btn = $(this);
        var regId = $btn.data('id');

        $btn.prop('disabled', true);

        $.ajax({
            url: API + '/toggle-checkin',
            method: 'POST',
            headers: { 'X-WP-Nonce': ecStaffApp.nonce },
            data: { reg_id: regId },
            success: function (data) {
                showToast(data.message, 'success');
                refreshStats();

                // If in profile overlay, reload it.
                if ($('#ec-sa-screen-profile').hasClass('active')) {
                    openProfile(regId);
                }

                // If in scan result, update the card.
                if ($('#ec-sa-scan-result').is(':visible')) {
                    // Re-lookup to refresh.
                    $.ajax({
                        url: API + '/registration/' + regId,
                        headers: { 'X-WP-Nonce': ecStaffApp.nonce },
                        success: function (regData) {
                            $('#ec-sa-scan-profile').html(renderProfileCard(regData));
                        }
                    });
                }

                // Refresh guest list if it's the current screen.
                if (currentScreen === 'guests') {
                    loadGuests(true);
                }
            },
            error: function (xhr) {
                var msg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : i18n.error;
                showToast(msg, 'error');
                $btn.prop('disabled', false);
            }
        });
    });

    // =========================================================================
    // QR Scanner
    // =========================================================================

    function startScanner() {
        $('#ec-sa-scan-result').hide();
        $('#ec-sa-scan-hint').text(i18n.pointCamera);

        if (scannerRunning) return;

        var viewportId = 'ec-sa-scanner-viewport';

        try {
            qrScanner = new Html5Qrcode(viewportId);

            qrScanner.start(
                { facingMode: 'environment' },
                {
                    fps: 10,
                    qrbox: function (viewfinderWidth, viewfinderHeight) {
                        var size = Math.min(viewfinderWidth, viewfinderHeight) * 0.75;
                        return { width: size, height: size };
                    },
                    aspectRatio: 1
                },
                onScanSuccess,
                function () {} // ignore scan failure (continuous scanning)
            ).then(function () {
                scannerRunning = true;
            }).catch(function (err) {
                $('#ec-sa-scan-hint').text(i18n.cameraError);
            });
        } catch (e) {
            $('#ec-sa-scan-hint').text(i18n.cameraError);
        }
    }

    function stopScanner() {
        if (qrScanner && scannerRunning) {
            try {
                qrScanner.stop().then(function () {
                    scannerRunning = false;
                    qrScanner.clear();
                }).catch(function () {
                    scannerRunning = false;
                });
            } catch (e) {
                scannerRunning = false;
            }
        }
    }

    function onScanSuccess(decodedText) {
        // Stop scanning while we process.
        stopScanner();

        // Extract token from URL. The QR encodes a URL like: https://example.com/?ec_token=abc123...
        var token = extractToken(decodedText);

        if (!token) {
            showToast(i18n.notFound, 'error');
            // Restart scanner after delay.
            setTimeout(startScanner, 2000);
            return;
        }

        $('#ec-sa-scan-hint').text(i18n.loading);

        $.ajax({
            url: API + '/lookup-token',
            method: 'POST',
            headers: { 'X-WP-Nonce': ecStaffApp.nonce },
            data: { token: token, event_id: eventId },
            success: function (data) {
                // Show profile card in scan result area.
                $('#ec-sa-scan-profile').html(renderProfileCard(data));
                $('#ec-sa-scan-result').show();
            },
            error: function (xhr) {
                var msg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : i18n.notFound;
                showToast(msg, 'error');
                setTimeout(startScanner, 2000);
            }
        });
    }

    function extractToken(text) {
        // Try to extract ec_token from URL.
        try {
            var url = new URL(text);
            var token = url.searchParams.get('ec_token');
            if (token) return token;
        } catch (e) {
            // Not a URL, maybe it's just the token itself.
        }

        // Check if it's a 64-char hex string (raw token).
        if (/^[a-f0-9]{64}$/i.test(text)) {
            return text;
        }

        // Try to find ec_token in the text.
        var match = text.match(/ec_token=([a-f0-9]{64})/i);
        if (match) return match[1];

        return null;
    }

    // Scan again button.
    $(document).on('click', '#ec-sa-btn-scan-again', function () {
        $('#ec-sa-scan-result').hide();
        startScanner();
    });

    // =========================================================================
    // Add Guest
    // =========================================================================

    $('#ec-sa-add-form').on('submit', function (e) {
        e.preventDefault();

        var $btn = $('#ec-sa-btn-add');
        var $msg = $('#ec-sa-add-message');
        var firstName = $('#ec-sa-add-fname').val().trim();
        var lastName = $('#ec-sa-add-lname').val().trim();
        var email = $('#ec-sa-add-email').val().trim();
        var phone = $('#ec-sa-add-phone').val().trim();

        // Validation.
        $('.ec-sa-field-error').removeClass('ec-sa-field-error');
        $msg.hide();

        var hasError = false;
        if (!firstName) { $('#ec-sa-add-fname').addClass('ec-sa-field-error'); hasError = true; }
        if (!lastName) { $('#ec-sa-add-lname').addClass('ec-sa-field-error'); hasError = true; }
        if (!email || !email.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) { $('#ec-sa-add-email').addClass('ec-sa-field-error'); hasError = true; }

        if (hasError) {
            showFormMessage($msg, i18n.required, 'error');
            return;
        }

        $btn.prop('disabled', true).text(i18n.adding);

        $.ajax({
            url: API + '/add-registration',
            method: 'POST',
            headers: { 'X-WP-Nonce': ecStaffApp.nonce },
            data: {
                event_id: eventId,
                first_name: firstName,
                last_name: lastName,
                email: email,
                phone: phone
            },
            success: function (data) {
                $btn.prop('disabled', false).text(i18n.submit);
                showFormMessage($msg, data.message || i18n.addSuccess, 'success');
                $('#ec-sa-add-form')[0].reset();
                refreshStats();
                showToast(data.message || i18n.addSuccess, 'success');
            },
            error: function (xhr) {
                $btn.prop('disabled', false).text(i18n.submit);
                var msg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : i18n.addError;
                showFormMessage($msg, msg, 'error');
            }
        });
    });

    function showFormMessage($el, text, type) {
        $el.text(text)
           .removeClass('ec-sa-form-message--success ec-sa-form-message--error')
           .addClass('ec-sa-form-message--' + type)
           .show();
    }

    // =========================================================================
    // History
    // =========================================================================

    function loadHistory() {
        var $list = $('#ec-sa-history-list');
        $list.html('<div class="ec-sa-loading">' + i18n.loading + '</div>');

        $.ajax({
            url: API + '/history/' + eventId,
            headers: { 'X-WP-Nonce': ecStaffApp.nonce },
            data: { page: 1 },
            success: function (data) {
                $list.empty();

                if (data.items.length === 0) {
                    $list.html(
                        '<div class="ec-sa-empty">' +
                        '<div class="ec-sa-empty-icon">&#128339;</div>' +
                        '<div class="ec-sa-empty-text">' + i18n.noHistory + '</div>' +
                        '</div>'
                    );
                    return;
                }

                data.items.forEach(function (item) {
                    $list.append(renderHistoryItem(item));
                });
            },
            error: function () {
                $list.html('<div class="ec-sa-empty"><div class="ec-sa-empty-text">' + i18n.error + '</div></div>');
            }
        });
    }

    function renderHistoryItem(item) {
        var isCheckin = item.action.indexOf('checkin') !== -1 && item.action.indexOf('undo') === -1;
        var iconClass = isCheckin ? 'ec-sa-history-icon--checkin' : 'ec-sa-history-icon--undo';
        var icon = isCheckin ? '&#10003;' : '&#8634;';

        var actionText = item.action;
        if (item.action === 'staff_checkin' || item.action === 'manual_checkin' || item.action === 'checkin') {
            actionText = i18n.manualCheckin;
        } else if (item.action === 'undo_checkin') {
            actionText = i18n.manualUncheckin;
        } else if (item.action === 'qr_checkin') {
            actionText = i18n.qrCheckin;
        }

        var time = formatTime(item.created_at);
        var date = formatDateShort(item.created_at);

        return '<div class="ec-sa-history-item" data-reg-id="' + item.registration_id + '">' +
            '<div class="ec-sa-history-icon ' + iconClass + '">' + icon + '</div>' +
            '<div class="ec-sa-history-info">' +
            '<div class="ec-sa-history-name">' + escHtml(item.first_name + ' ' + item.last_name) + '</div>' +
            '<div class="ec-sa-history-action">' + actionText + ' &middot; ' + escHtml(item.performed_by) + '</div>' +
            '</div>' +
            '<div class="ec-sa-history-time">' +
            time +
            '<span class="ec-sa-history-time-date">' + date + '</span>' +
            '</div>' +
            '</div>';
    }

    // Click history item -> profile.
    $(document).on('click', '.ec-sa-history-item', function () {
        var regId = $(this).data('reg-id');
        if (regId) openProfile(regId);
    });

    // =========================================================================
    // Toast
    // =========================================================================

    function showToast(message, type) {
        type = type || 'success';
        var $container = $('#ec-sa-toast-container');
        var $toast = $('<div class="ec-sa-toast ec-sa-toast--' + type + '">' + escHtml(message) + '</div>');
        $container.append($toast);

        setTimeout(function () {
            $toast.fadeOut(200, function () { $toast.remove(); });
        }, 3500);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    function escHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

    function formatDate(dateStr) {
        if (!dateStr) return '-';
        var d = new Date(dateStr.replace(' ', 'T'));
        return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) +
            ' ' + d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
    }

    function formatTime(dateStr) {
        if (!dateStr) return '';
        var d = new Date(dateStr.replace(' ', 'T'));
        return d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
    }

    function formatDateShort(dateStr) {
        if (!dateStr) return '';
        var d = new Date(dateStr.replace(' ', 'T'));
        return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    }

    // =========================================================================
    // Init
    // =========================================================================

    // Set search placeholder.
    $('#ec-sa-search').attr('placeholder', i18n.searchPlaceholder);

    // Load initial data.
    loadGuests(true);
    refreshStats();

    // Refresh stats every 30 seconds.
    setInterval(refreshStats, 30000);

})(jQuery);
