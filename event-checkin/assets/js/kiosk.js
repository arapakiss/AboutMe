/**
 * Event Check-in - Kiosk Mode JavaScript
 * Handles QR scanning, check-in flow, signature capture, and auto-reset.
 */
(function () {
    'use strict';

    var kiosk = {
        eventId: 0,
        requireSignature: false,
        scanner: null,
        signaturePad: null,
        resetTimer: null,
        countdownInterval: null,
        currentToken: null,
        isProcessing: false,
        lastScanTime: 0,
        scanDebounceMs: 2000,
        offlineQueue: [],

        init: function () {
            var el = document.getElementById('ec-kiosk');
            if (!el) return;

            this.eventId = parseInt(el.dataset.eventId, 10);
            this.requireSignature = el.dataset.requireSignature === '1';

            // Hide header/footer/title if configured (supports Elementor and popular themes).
            var hideHeader = el.dataset.hideHeader === '1';
            var hideFooter = el.dataset.hideFooter === '1';
            var hideTitle  = el.dataset.hideTitle === '1';

            var headerSels = 'header, .site-header, #masthead, .header, #header, .wp-site-header, [role="banner"], .elementor-location-header, [data-elementor-type="header"], .ehf-header, #ehf-header, .ast-above-header, .ast-main-header-wrap';
            var footerSels = 'footer, .site-footer, #colophon, .footer, #footer, .wp-site-footer, [role="contentinfo"], .elementor-location-footer, [data-elementor-type="footer"], .ehf-footer, #ehf-footer, .ast-footer-overlay';
            var titleSels = '.entry-title, .page-title, .post-title, .entry-header, .page-header, .elementor-page-title, .elementor-widget-theme-page-title, .ast-the-title, .wp-block-post-title, #page-title-wrapper';

            if (hideHeader) {
                document.querySelectorAll(headerSels).forEach(function(h) { h.classList.add('ec-hidden'); });
            }
            if (hideFooter) {
                document.querySelectorAll(footerSels).forEach(function(f) { f.classList.add('ec-hidden'); });
            }
            if (hideTitle) {
                document.querySelectorAll(titleSels).forEach(function(t) { t.classList.add('ec-hidden'); });
            }
            if (hideHeader || hideFooter) {
                var adminBar = document.getElementById('wpadminbar');
                if (adminBar) { adminBar.classList.add('ec-hidden'); }
                document.documentElement.style.marginTop = '0';
            }

            // Add fullwidth class to body for CSS :has() fallback.
            document.body.classList.add('ec-fullwidth-page');

            this.initScanner();
            this.initSignaturePad();
            this.updateStats();

            // Refresh stats every 30 seconds.
            setInterval(this.updateStats.bind(this), 30000);

            // Process offline queue.
            this.processOfflineQueue();

            // Prevent context menu and selection on kiosk.
            document.addEventListener('contextmenu', function (e) { e.preventDefault(); });
            document.addEventListener('selectstart', function (e) { e.preventDefault(); });
        },

        /**
         * Initialize the QR code scanner.
         */
        initScanner: function () {
            var self = this;

            try {
                this.scanner = new Html5Qrcode('ec-scanner-viewport');
                this.startScanning();
            } catch (err) {
                console.error('Scanner init error:', err);
                this.showScreen('error', ecKiosk.i18n.cameraError);
            }
        },

        startScanning: function () {
            var self = this;

            // Use a larger scan area and higher fps for better QR recognition.
            // formatsToSupport restricts to QR codes only (skips barcode detection).
            var qrboxSize = Math.min(350, Math.floor(window.innerWidth * 0.6));
            this.scanner.start(
                { facingMode: 'environment' },
                {
                    fps: 15,
                    qrbox: { width: qrboxSize, height: qrboxSize },
                    aspectRatio: 1.0,
                    formatsToSupport: [ Html5QrcodeSupportedFormats.QR_CODE ],
                    experimentalFeatures: {
                        useBarCodeDetectorIfSupported: true
                    }
                },
                function (decodedText) {
                    self.onScanSuccess(decodedText);
                },
                function () {
                    // Ignore scan failures (normal during scanning).
                }
            ).catch(function (err) {
                console.error('Camera error:', err);
                self.showScreen('error', ecKiosk.i18n.cameraError);
            });
        },

        /**
         * Handle successful QR scan.
         */
        onScanSuccess: function (decodedText) {
            if (this.isProcessing) return;

            // Debounce: ignore rapid duplicate scans.
            var now = Date.now();
            if (now - this.lastScanTime < this.scanDebounceMs) return;
            this.lastScanTime = now;

            this.isProcessing = true;

            // Extract token from URL or use raw value.
            var token = this.extractToken(decodedText);
            if (!token) {
                this.isProcessing = false;
                return;
            }

            this.currentToken = token;

            // Pause scanner.
            try {
                this.scanner.pause(true);
            } catch (e) {
                // Scanner might not support pause.
            }

            this.showScreen('processing');
            this.performCheckin(token);
        },

        /**
         * Extract token from a URL or raw QR content.
         */
        extractToken: function (text) {
            // Try to extract from URL parameter.
            try {
                var url = new URL(text);
                var token = url.searchParams.get('ec_token');
                if (token && token.length === 64) {
                    return token;
                }
            } catch (e) {
                // Not a URL, check if it's a raw token.
            }

            // Check if raw text is a valid token (64-char hex).
            if (/^[a-f0-9]{64}$/i.test(text)) {
                return text;
            }

            return null;
        },

        /**
         * Perform check-in via REST API.
         */
        performCheckin: function (token) {
            var self = this;

            fetch(ecKiosk.restUrl + '/checkin', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': ecKiosk.nonce
                },
                body: JSON.stringify({ qr_token: token })
            })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                self.handleCheckinResponse(data);
            })
            .catch(function (err) {
                console.error('Checkin error:', err);
                // Queue for offline retry.
                self.offlineQueue.push({ token: token, timestamp: Date.now() });
                self.saveOfflineQueue();
                self.showScreen('error', ecKiosk.i18n.error);
                self.scheduleReset();
            });
        },

        /**
         * Handle check-in API response.
         */
        handleCheckinResponse: function (data) {
            switch (data.status || data.code) {
                case 'success':
                    this.showScreen('success', null, data.data);
                    this.updateStats();
                    this.scheduleReset();
                    break;

                case 'needs_signature':
                    this.showSignatureScreen(data.data);
                    break;

                case 'already_checked_in':
                    this.showScreen('already', null, data.data);
                    this.scheduleReset();
                    break;

                case 'error':
                    this.showScreen('error', data.message || ecKiosk.i18n.error);
                    this.scheduleReset();
                    break;

                default:
                    this.showScreen('error', data.message || ecKiosk.i18n.notFound);
                    this.scheduleReset();
            }
        },

        /**
         * Show the signature capture screen.
         */
        showSignatureScreen: function (data) {
            document.getElementById('ec-signature-name').textContent = data.name || '';
            this.currentToken = data.qr_token || this.currentToken;

            if (this.signaturePad) {
                this.signaturePad.clear();
            }

            this.showScreen('signature');
        },

        /**
         * Initialize the signature pad.
         */
        initSignaturePad: function () {
            var self = this;
            var canvas = document.getElementById('ec-signature-pad');
            if (!canvas) return;

            // Resize canvas for device pixel ratio.
            var ratio = Math.max(window.devicePixelRatio || 1, 1);
            canvas.width = canvas.offsetWidth * ratio;
            canvas.height = canvas.offsetHeight * ratio;
            canvas.getContext('2d').scale(ratio, ratio);

            this.signaturePad = new SignaturePad(canvas, {
                backgroundColor: 'rgb(255, 255, 255)',
                penColor: 'rgb(0, 0, 0)'
            });

            // Clear button.
            var clearBtn = document.getElementById('ec-sig-clear');
            if (clearBtn) {
                clearBtn.addEventListener('click', function () {
                    self.signaturePad.clear();
                });
            }

            // Confirm button.
            var confirmBtn = document.getElementById('ec-sig-confirm');
            if (confirmBtn) {
                confirmBtn.addEventListener('click', function () {
                    self.submitSignature();
                });
            }
        },

        /**
         * Submit signature and complete check-in.
         */
        submitSignature: function () {
            if (this.signaturePad.isEmpty()) {
                // Shake the canvas as feedback.
                var wrapper = document.querySelector('.ec-signature-wrapper');
                wrapper.style.animation = 'none';
                wrapper.offsetHeight; // Trigger reflow.
                wrapper.style.animation = 'ec-shake 0.5s';
                return;
            }

            var signatureData = this.signaturePad.toDataURL('image/png');
            var self = this;

            this.showScreen('processing');

            fetch(ecKiosk.restUrl + '/signature', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': ecKiosk.nonce
                },
                body: JSON.stringify({
                    qr_token: this.currentToken,
                    signature: signatureData
                })
            })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (data.status === 'success' || data.status === 'already_checked_in') {
                    self.showScreen('success', null, data.data);
                    self.updateStats();
                } else {
                    self.showScreen('error', data.message || ecKiosk.i18n.error);
                }
                self.scheduleReset();
            })
            .catch(function (err) {
                console.error('Signature submission error:', err);
                self.showScreen('error', ecKiosk.i18n.error);
                self.scheduleReset();
            });
        },

        /**
         * Show a specific screen.
         */
        /**
         * Update the step progress indicator.
         */
        updateStepProgress: function (activeStep) {
            for (var i = 1; i <= 3; i++) {
                var el = document.getElementById('ec-step-' + i);
                if (!el) continue;
                el.classList.remove('active', 'done');
                if (i < activeStep) {
                    el.classList.add('done');
                } else if (i === activeStep) {
                    el.classList.add('active');
                }
            }
        },

        showScreen: function (screen, message, data) {
            // Hide all screens.
            var screens = document.querySelectorAll('.ec-kiosk-screen');
            for (var i = 0; i < screens.length; i++) {
                screens[i].classList.remove('active');
            }

            var targetId = 'ec-screen-' + screen;
            var target = document.getElementById(targetId);
            if (target) {
                target.classList.add('active');
            }

            // Update step progress.
            var stepMap = {
                'scanner': 1,
                'processing': 1,
                'signature': 2,
                'success': 3,
                'already': 3,
                'error': 1,
            };
            this.updateStepProgress(stepMap[screen] || 1);

            // Set dynamic content.
            switch (screen) {
                case 'success':
                    if (data && data.name) {
                        var nameEl = document.getElementById('ec-success-name');
                        if (nameEl) {
                            nameEl.textContent = ecKiosk.i18n.welcome.replace('%s', data.name);
                        }
                    }
                    break;

                case 'already':
                    if (data && data.name) {
                        var alreadyName = document.getElementById('ec-already-name');
                        if (alreadyName) {
                            alreadyName.textContent = data.name;
                        }
                    }
                    break;

                case 'error':
                    if (message) {
                        var errorMsg = document.getElementById('ec-error-message');
                        if (errorMsg) {
                            errorMsg.textContent = message;
                        }
                    }
                    break;
            }
        },

        /**
         * Schedule auto-reset to scanner screen.
         */
        scheduleReset: function (delay) {
            var self = this;
            delay = delay || ecKiosk.idleTimeout;

            this.clearReset();

            // Animate countdown bar.
            var fills = document.querySelectorAll('[id^="ec-countdown-fill"]');
            for (var i = 0; i < fills.length; i++) {
                fills[i].style.transition = 'none';
                fills[i].style.width = '100%';
                fills[i].offsetHeight; // Force reflow.
                fills[i].style.transition = 'width ' + (delay / 1000) + 's linear';
                fills[i].style.width = '0%';
            }

            this.resetTimer = setTimeout(function () {
                self.resetToScanner();
            }, delay);
        },

        clearReset: function () {
            if (this.resetTimer) {
                clearTimeout(this.resetTimer);
                this.resetTimer = null;
            }
        },

        /**
         * Reset to scanner screen.
         */
        resetToScanner: function () {
            this.isProcessing = false;
            this.currentToken = null;
            this.clearReset();

            this.showScreen('scanner');

            // Resume scanner.
            try {
                this.scanner.resume();
            } catch (e) {
                // If resume fails, restart.
                try {
                    this.scanner.stop().then(function () {
                        this.startScanning();
                    }.bind(this));
                } catch (e2) {
                    this.startScanning();
                }
            }
        },

        /**
         * Update event statistics display.
         */
        updateStats: function () {
            fetch(ecKiosk.restUrl + '/stats/' + this.eventId, {
                headers: {
                    'X-WP-Nonce': ecKiosk.nonce
                }
            })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                var totalEl = document.getElementById('ec-stats-total');
                var checkedInEl = document.getElementById('ec-stats-checkedin');
                if (totalEl) totalEl.textContent = data.total || 0;
                if (checkedInEl) checkedInEl.textContent = data.checked_in || 0;
            })
            .catch(function () {
                // Silently fail on stats update.
            });
        },

        /**
         * Offline queue management using localStorage.
         */
        saveOfflineQueue: function () {
            try {
                localStorage.setItem('ec_offline_queue', JSON.stringify(this.offlineQueue));
            } catch (e) {
                // localStorage might not be available.
            }
        },

        loadOfflineQueue: function () {
            try {
                var data = localStorage.getItem('ec_offline_queue');
                if (data) {
                    this.offlineQueue = JSON.parse(data) || [];
                }
            } catch (e) {
                this.offlineQueue = [];
            }
        },

        processOfflineQueue: function () {
            this.loadOfflineQueue();
            if (!this.offlineQueue.length) return;

            var self = this;
            var item = this.offlineQueue.shift();

            // Only retry items less than 1 hour old.
            if (Date.now() - item.timestamp > 3600000) {
                self.saveOfflineQueue();
                if (self.offlineQueue.length) {
                    self.processOfflineQueue();
                }
                return;
            }

            fetch(ecKiosk.restUrl + '/checkin', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': ecKiosk.nonce
                },
                body: JSON.stringify({ qr_token: item.token })
            })
            .then(function () {
                self.saveOfflineQueue();
                if (self.offlineQueue.length) {
                    self.processOfflineQueue();
                }
            })
            .catch(function () {
                // Put it back and try later.
                self.offlineQueue.unshift(item);
                self.saveOfflineQueue();
            });
        }
    };

    // Initialize when DOM is ready.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            kiosk.init();
        });
    } else {
        kiosk.init();
    }

})();
