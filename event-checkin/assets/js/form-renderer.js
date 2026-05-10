/**
 * Event Check-in - Multi-Step Form Renderer
 * Handles step navigation, validation, verification, signatures, and submission.
 */
(function($) {
    'use strict';

    var FR = {
        currentStep: 0,
        totalSteps: 0,
        schema: null,
        signaturePads: {},
        verifiedFields: {},

        translations: {},
        currentLang: 'en',

        init: function() {
            var $app = $('#ec-form-app');
            if (!$app.length) return;

            this.schema = JSON.parse($app.attr('data-schema') || 'null');
            if (!this.schema) return;

            this.totalSteps = $('.ec-form-panel').length;

            // Hide header/footer if configured.
            this.applyLayoutMode($app);

            this.bindNavigation();
            this.bindVerification();
            this.bindWebsitePreviews();
            this.bindSignatures();
            this.bindOtpInputs();
            this.bindSubmit();
            this.bindLanguageSwitcher();
            this.bindCompanyCard();
        },

        // ── Layout Mode: hide header/footer, fill viewport ──
        applyLayoutMode: function($app) {
            var hideHeader = $app.data('hide-header') === 1 || $app.data('hide-header') === '1';
            var hideFooter = $app.data('hide-footer') === 1 || $app.data('hide-footer') === '1';

            if (hideHeader || hideFooter) {
                // Common WP theme header/footer selectors.
                var headerSelectors = 'header, .site-header, #masthead, .header, #header, .wp-site-header, [role="banner"]';
                var footerSelectors = 'footer, .site-footer, #colophon, .footer, #footer, .wp-site-footer, [role="contentinfo"]';

                if (hideHeader) {
                    $(headerSelectors).addClass('ec-hidden');
                }
                if (hideFooter) {
                    $(footerSelectors).addClass('ec-hidden');
                }

                // Add fullscreen class to the app.
                $app.addClass('ec-form-fullscreen');

                // Also hide WP admin bar if present.
                $('#wpadminbar').addClass('ec-hidden');
                $('html').css('margin-top', '0');
            }
        },

        // ── Navigation ──
        bindNavigation: function() {
            var self = this;

            $(document).on('click', '.ec-form-next', function() { self.goNext(); });
            $(document).on('click', '.ec-form-prev', function() { self.goPrev(); });

            // Step nav clicks.
            $('#ec-form-step-nav .ec-form-step-link').on('click', function() {
                var step = $(this).data('step');
                if (step === 'review') {
                    self.goTo(self.totalSteps - 1);
                } else {
                    self.goTo(parseInt(step, 10));
                }
            });
        },

        goNext: function() {
            if (!this.validateCurrentStep()) return;
            this.goTo(this.currentStep + 1);
        },

        goPrev: function() {
            this.goTo(this.currentStep - 1);
        },

        goTo: function(step) {
            if (step < 0 || step >= this.totalSteps) return;

            this.currentStep = step;
            var $panels = $('.ec-form-panel');
            $panels.removeClass('active');
            $panels.eq(step).addClass('active');

            // Update step nav.
            var $navLinks = $('#ec-form-step-nav .ec-form-step-link');
            $navLinks.removeClass('is-active');
            $navLinks.eq(step).addClass('is-active');

            // Update progress.
            $('#ec-form-progress .ec-form-progress-dot').each(function(i) {
                $(this).toggleClass('on', i <= step);
            });

            // Update top bar.
            var panelData = $panels.eq(step).data('step-index');
            if (panelData === 'review') {
                $('#ec-form-top-title').text('Review');
                this.buildReview();
            } else {
                var stepData = this.schema.steps[panelData] || {};
                $('#ec-form-top-title').text(stepData.title || 'Step ' + (step + 1));
            }
            $('#ec-form-top-kicker').text('Step ' + (step + 1) + ' of ' + this.totalSteps);

            // Init signatures for this step.
            this.initStepSignatures(step);

            // Scroll to top.
            $('.ec-form-right').scrollTop(0);
        },

        // ── Validation ──
        validateCurrentStep: function() {
            var $panel = $('.ec-form-panel').eq(this.currentStep);
            var valid = true;

            // Clear previous errors.
            $panel.find('.ec-ff-error').remove();
            $panel.find('.ec-ff-input, .ec-ff-textarea, .ec-ff-select').css('border-color', '');

            // Check required fields.
            $panel.find('[required]').each(function() {
                var $field = $(this);
                var val = $field.val();

                if (!val || !val.trim()) {
                    valid = false;
                    $field.css('border-color', '#dc2626');
                    $field.closest('.ec-ff').append('<div class="ec-ff-error">This field is required</div>');
                }
            });

            // Email validation.
            $panel.find('input[type="email"]').each(function() {
                var val = $(this).val();
                if (val && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) {
                    valid = false;
                    $(this).css('border-color', '#dc2626');
                    $(this).closest('.ec-ff').append('<div class="ec-ff-error">Invalid email address</div>');
                }
            });

            // URL validation.
            $panel.find('input[type="url"]').each(function() {
                var val = $(this).val();
                if (val && !/^https?:\/\/.+/.test(val)) {
                    valid = false;
                    $(this).css('border-color', '#dc2626');
                    $(this).closest('.ec-ff').append('<div class="ec-ff-error">Invalid URL</div>');
                }
            });

            // Check verification fields.
            $panel.find('.ec-ff[data-verify="1"]').each(function() {
                var fieldId = $(this).data('field-id');
                var $token = $(this).find('.ec-verify-token');
                if ($token.length && !$token.val() && $(this).find('[required]').length) {
                    valid = false;
                    $(this).append('<div class="ec-ff-error">Please verify this field</div>');
                }
            });

            return valid;
        },

        // ── Verification ──
        bindVerification: function() {
            var self = this;

            $(document).on('click', '.ec-verify-send', function() {
                var $btn = $(this);
                var type = $btn.data('type');
                var fieldId = $btn.data('field');
                var eventId = $btn.data('event');
                var $field = $('#' + fieldId);
                var identifier = $field.val();

                if (type === 'sms') {
                    var code = $field.closest('.ec-ff').find('select').val() || '';
                    identifier = code + identifier;
                }

                if (!identifier) return;

                $btn.prop('disabled', true).text('Sending...');

                $.ajax({
                    url: ecFormRenderer.restUrl + '/verify/send',
                    method: 'POST',
                    headers: { 'X-WP-Nonce': ecFormRenderer.restNonce },
                    contentType: 'application/json',
                    data: JSON.stringify({ identifier: identifier, type: type, event_id: eventId }),
                    success: function(res) {
                        $btn.text('Sent!');
                        $('#otp-' + fieldId).slideDown();
                    },
                    error: function(xhr) {
                        var msg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Error sending code';
                        $btn.prop('disabled', false).text('Retry');
                        alert(msg);
                    }
                });
            });

            $(document).on('click', '.ec-verify-check', function() {
                var $btn = $(this);
                var type = $btn.data('type');
                var fieldId = $btn.data('field');
                var $otp = $('#otp-' + fieldId);
                var code = '';
                $otp.find('.ec-otp-digit').each(function() { code += $(this).val(); });

                if (code.length < 6) return;

                var $field = $('#' + fieldId);
                var identifier = $field.val();
                if (type === 'sms') {
                    var countryCode = $field.closest('.ec-ff').find('select').val() || '';
                    identifier = countryCode + identifier;
                }

                $.ajax({
                    url: ecFormRenderer.restUrl + '/verify/check',
                    method: 'POST',
                    headers: { 'X-WP-Nonce': ecFormRenderer.restNonce },
                    contentType: 'application/json',
                    data: JSON.stringify({ identifier: identifier, code: code, type: type }),
                    success: function(res) {
                        if (res.status === 'verified') {
                            $field.closest('.ec-ff').find('.ec-verify-token').val(res.token);
                            $field.closest('.ec-ff').find('.ec-verify-send').addClass('verified').text('Verified ✓').prop('disabled', true);
                            $otp.slideUp();
                            self.verifiedFields[fieldId] = true;
                        }
                    },
                    error: function(xhr) {
                        var msg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Invalid code';
                        alert(msg);
                    }
                });
            });
        },

        // ── OTP auto-advance ──
        bindOtpInputs: function() {
            $(document).on('input', '.ec-otp-digit', function() {
                var val = $(this).val();
                if (val.length === 1) {
                    $(this).next('.ec-otp-digit').focus();
                }
            });

            $(document).on('keydown', '.ec-otp-digit', function(e) {
                if (e.key === 'Backspace' && !$(this).val()) {
                    $(this).prev('.ec-otp-digit').focus();
                }
            });
        },

        // ── Website Preview ──
        bindWebsitePreviews: function() {
            $(document).on('input', '.ec-website-input', function() {
                var val = $(this).val().trim();
                var clean = val.replace(/^https?:\/\//, '').replace(/\/$/, '');
                var $wrap = $(this).closest('.ec-ff-website-wrap');
                $wrap.find('.ec-website-preview-url').text(clean || 'example.com');
                var title = clean ? clean.split('.')[0].replace(/-/g, ' ').replace(/\b\w/g, function(c) { return c.toUpperCase(); }) : 'Website';
                $wrap.find('.ec-website-preview-title').text(title);
            });
        },

        // ── Signatures ──
        bindSignatures: function() {
            var self = this;
            $(document).on('click', '.ec-sig-clear', function() {
                var fieldId = $(this).data('field');
                if (self.signaturePads[fieldId]) {
                    self.signaturePads[fieldId].clear();
                    $('#sig-data-' + fieldId).val('');
                }
            });
        },

        initStepSignatures: function(stepIndex) {
            var self = this;
            var $panel = $('.ec-form-panel').eq(stepIndex);
            $panel.find('.ec-ff-signature').each(function() {
                var id = this.id.replace('sig-', '');
                if (self.signaturePads[id]) return; // Already initialized.

                var canvas = document.getElementById('sig-canvas-' + id);
                if (!canvas) return;

                var box = this;
                setTimeout(function() {
                    var ratio = Math.max(window.devicePixelRatio || 1, 1);
                    canvas.width = box.clientWidth * ratio;
                    canvas.height = box.clientHeight * ratio;
                    canvas.getContext('2d').scale(ratio, ratio);

                    var pad = new SignaturePad(canvas, {
                        backgroundColor: 'rgb(255, 255, 255)',
                        penColor: 'rgb(0, 45, 114)'
                    });

                    pad.addEventListener('endStroke', function() {
                        $('#sig-data-' + id).val(pad.toDataURL('image/png'));
                    });

                    self.signaturePads[id] = pad;
                }, 100);
            });
        },

        // ── Review Step ──
        buildReview: function() {
            var $review = $('#ec-form-review-data');
            $review.empty();

            var $form = $('#ec-form-main');
            $form.find('.ec-ff').each(function() {
                var $ff = $(this);
                var $label = $ff.find('.ec-ff-label');
                if (!$label.length) return;

                var label = $label.text().replace(' *', '');
                var value = '';

                // Get value based on input type.
                var $input = $ff.find('input[type="text"], input[type="email"], input[type="tel"], input[type="url"], input[type="date"], input[type="datetime-local"], input[type="time"]');
                var $textarea = $ff.find('textarea');
                var $select = $ff.find('select');
                var $radio = $ff.find('input[type="radio"]:checked');
                var $checkboxes = $ff.find('input[type="checkbox"]:checked');
                var $range = $ff.find('input[type="range"]');

                if ($input.length) {
                    value = $input.val() || '';
                } else if ($textarea.length) {
                    value = $textarea.val() || '';
                } else if ($select.length) {
                    value = $select.find('option:selected').text() || '';
                } else if ($radio.length) {
                    value = $radio.closest('.ec-ff-choice').find('b').text() || $radio.val();
                } else if ($checkboxes.length) {
                    var vals = [];
                    $checkboxes.each(function() {
                        vals.push($(this).closest('label').text().trim());
                    });
                    value = vals.join(', ');
                } else if ($range.length) {
                    value = $range.val();
                }

                if (!value && !$ff.find('.ec-ff-signature').length) return;

                if ($ff.find('.ec-ff-signature').length) {
                    value = $ff.find('input[type="hidden"]').val() ? 'Signed' : 'Not signed';
                }

                $review.append(
                    '<div class="ec-ff-review-item"><span>' + label + '</span><b>' + $('<span>').text(value).html() + '</b></div>'
                );
            });
        },

        // ── Language Switcher ──
        bindLanguageSwitcher: function() {
            var self = this;
            var $switcher = $('#ec-form-language');
            if (!$switcher.length) return;

            // Store original English labels for restoring later.
            var originals = {};
            $('.ec-ff-label').each(function() {
                var text = $(this).text().replace(' *', '').trim();
                originals[text] = text;
            });
            $('.ec-form-step-title').each(function() {
                var text = $(this).text().trim();
                originals[text] = text;
            });
            $('input[placeholder], textarea[placeholder]').each(function() {
                var ph = $(this).attr('placeholder');
                if (ph) originals[ph] = ph;
            });
            originals['Continue'] = 'Continue';
            originals['Back'] = 'Back';
            self.translations['en'] = originals;

            $switcher.on('change', function() {
                var lang = $(this).val();
                self.currentLang = lang;
                self.loadTranslation(lang);
            });
        },

        loadTranslation: function(lang) {
            var self = this;
            if (lang === 'en') {
                // Reset to original English labels from stored originals.
                if (self.translations['en']) {
                    self.applyTranslation(self.translations['en']);
                }
                return;
            }

            // Check if already cached.
            if (self.translations[lang]) {
                self.applyTranslation(self.translations[lang]);
                return;
            }

            var eventId = $('#ec-form-app').data('event-id');
            var url = ecFormRenderer.restUrl + '/translations/' + eventId + '/' + lang;

            $.ajax({
                url: url,
                method: 'GET',
                headers: { 'X-WP-Nonce': ecFormRenderer.restNonce },
                success: function(res) {
                    if (res && typeof res === 'object') {
                        self.translations[lang] = res;
                        self.applyTranslation(res);
                    }
                },
                error: function() {
                    // Translation file not available; keep English.
                }
            });
        },

        applyTranslation: function(trans) {
            // Use data-original-text attributes to always look up the English key,
            // regardless of what language the label is currently in.

            // Translate labels.
            $('.ec-ff-label').each(function() {
                var $el = $(this);
                if (!$el.data('original-text')) {
                    $el.data('original-text', $el.text().replace(' *', '').trim());
                    $el.data('is-required', $el.text().indexOf('*') !== -1);
                }
                var key = $el.data('original-text');
                if (trans[key]) {
                    $el.text(trans[key] + ($el.data('is-required') ? ' *' : ''));
                }
            });

            // Translate step titles.
            $('.ec-form-step-title').each(function() {
                var $el = $(this);
                if (!$el.data('original-text')) {
                    $el.data('original-text', $el.text().trim());
                }
                var key = $el.data('original-text');
                if (trans[key]) $el.text(trans[key]);
            });

            // Translate button labels.
            $('.ec-form-next').each(function() {
                var t = trans['Continue'];
                if (t) $(this).html(t + ' &rarr;');
            });
            $('.ec-form-prev').each(function() {
                var t = trans['Back'];
                if (t) $(this).text(t);
            });

            // Translate placeholders.
            $('input[placeholder], textarea[placeholder]').each(function() {
                var $el = $(this);
                if (!$el.data('original-placeholder')) {
                    $el.data('original-placeholder', $el.attr('placeholder'));
                }
                var key = $el.data('original-placeholder');
                if (key && trans[key]) {
                    $el.attr('placeholder', trans[key]);
                }
            });

            // Translate select option labels.
            $('select option').each(function() {
                var $el = $(this);
                if (!$el.data('original-text')) {
                    $el.data('original-text', $el.text().trim());
                }
                var key = $el.data('original-text');
                if (key && trans[key]) {
                    $el.text(trans[key]);
                }
            });
        },

        // ── Company Info Card ──
        bindCompanyCard: function() {
            if (!$('.ec-ff-company-card').length) return;

            // Use exact field ID selectors for reliability.
            var fid = function(name) { return '[data-field-id="field_' + name + '"]'; };

            // Live-update the company card as users fill in company fields.
            $(document).on('input change', fid('company_name') + ' input', function() {
                $('#ec-company-card-name').text($(this).val() || 'Company Name');
            });

            $(document).on('change', fid('company_type') + ' select', function() {
                var text = $(this).find('option:selected').text();
                if (text && text !== '-- Select --') {
                    $('#ec-company-card-type').text(text);
                }
            });

            $(document).on('input', fid('company_website') + ' input', function() {
                var val = $(this).val();
                var clean = val ? val.replace(/^https?:\/\//, '').replace(/\/$/, '') : '';
                $('#ec-company-card-website').text(clean || '\u2014');
            });

            $(document).on('input change', fid('company_city') + ' input, ' + fid('company_country') + ' select', function() {
                var city = $(fid('company_city') + ' input').val() || '';
                var country = $(fid('company_country') + ' select option:selected').text() || '';
                if (country === '-- Select Country --') country = '';
                var loc = [city, country].filter(Boolean).join(', ');
                $('#ec-company-card-location').text(loc || '\u2014');
            });

            $(document).on('input', fid('company_email') + ' input', function() {
                $('#ec-company-card-email').text($(this).val() || '\u2014');
            });

            $(document).on('input', fid('company_phone') + ' input[type="tel"]', function() {
                var code = $(this).closest('.ec-ff').find('select').val() || '';
                $('#ec-company-card-phone').text((code + ' ' + $(this).val()).trim() || '\u2014');
            });

            $(document).on('input', fid('company_description') + ' textarea', function() {
                $('#ec-company-card-desc').text($(this).val());
            });

            // Logo preview.
            $(document).on('change', fid('company_logo') + ' input[type="file"]', function() {
                var file = this.files && this.files[0];
                if (!file) return;
                var reader = new FileReader();
                reader.onload = function(e) {
                    var $logo = $('#ec-company-logo-preview');
                    $logo.html('<img src="' + e.target.result + '" alt="Logo">');
                };
                reader.readAsDataURL(file);
            });
        },

        // ── Form Submission ──
        bindSubmit: function() {
            var self = this;

            $('#ec-form-main').on('submit', function(e) {
                e.preventDefault();

                if (!self.validateCurrentStep()) return;

                var $form = $(this);
                var $btn = $form.find('[type="submit"]');
                $btn.prop('disabled', true).text('Submitting...');

                var formData = new FormData(this);

                // Add reCAPTCHA token if enabled.
                var doSubmit = function(recaptchaToken) {
                    if (recaptchaToken) {
                        formData.append('ec_recaptcha_token', recaptchaToken);
                    }

                    $.ajax({
                        url: ecFormRenderer.ajaxUrl,
                        type: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                        dataType: 'json',
                        success: function(res) {
                            if (res.success) {
                                $form.hide();
                                var $success = $('#ec-form-success');
                                $success.addClass('active');
                                if (res.data && res.data.qr_url) {
                                    $('#ec-form-success-qr').html('<img src="' + res.data.qr_url + '" alt="QR Code" style="border:3px solid #002d72;max-width:200px;">');
                                }
                            } else {
                                alert(res.data && res.data.message ? res.data.message : 'Registration failed.');
                                $btn.prop('disabled', false).text('Submit Registration →');
                            }
                        },
                        error: function(xhr) {
                            var msg = 'An error occurred.';
                            if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                                msg = xhr.responseJSON.data.message;
                            }
                            alert(msg);
                            $btn.prop('disabled', false).text('Submit Registration →');
                        }
                    });
                };

                if (ecFormRenderer.recaptchaEnabled && typeof grecaptcha !== 'undefined') {
                    grecaptcha.ready(function() {
                        grecaptcha.execute(ecFormRenderer.recaptchaSiteKey, { action: 'ec_register' })
                            .then(doSubmit)
                            .catch(function() { doSubmit(''); });
                    });
                } else {
                    doSubmit('');
                }
            });
        }
    };

    $(document).ready(function() { FR.init(); });

})(jQuery);
