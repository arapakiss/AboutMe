=== Event Check-in ===
Contributors: arapakiss
Tags: events, registration, qr-code, check-in, kiosk, signature
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Event registration system with QR codes, self-service kiosk check-in, digital signatures, and Excel export.

== Description ==

Event Check-in is a complete event registration and attendance management plugin for WordPress. It provides:

* **Online Registration Forms** - Customizable forms with dynamic fields via shortcode
* **QR Code Generation** - Unique QR codes sent via email upon registration
* **Self-Service Kiosk Mode** - Fullscreen tablet interface for attendee self check-in
* **Digital Signatures** - Optional signature capture during check-in
* **Excel/CSV Export** - Export all registration and attendance data
* **Event Dashboard** - Per-event management with stats, search, filter, bulk actions
* **Mobile Staff App** - Phone-friendly guest list, QR scanner, add guest, check-in history
* **Three User Layers** - Admin, Staff, and Public user roles

= How It Works =

1. Admin creates an event with custom registration fields
2. Embed the registration form using `[event_registration id="123"]`
3. Attendees register and receive a QR code via email
4. At the event, set up a tablet with `[event_kiosk id="123"]` for self check-in
5. Attendees scan their QR code to check in (with optional signature)
6. Export attendance data to Excel at any time

= Security Features =

* Rate limiting on registrations and check-in attempts
* CSRF protection via WordPress nonces
* Input sanitization on all user data
* Prepared SQL statements for all database queries
* Honeypot field for bot detection
* Cryptographically secure QR tokens

== Installation ==

1. Upload the `event-checkin` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to Event Check-in in the admin menu to create your first event

== Frequently Asked Questions ==

= What shortcodes are available? =

* `[event_registration id="123"]` - Renders the registration form for event ID 123
* `[event_kiosk id="123"]` - Renders the kiosk check-in interface (staff/admin only)
* `[event_staff_app id="123"]` - Mobile-first staff app with guest list, QR scanner, add guest, and check-in history (staff/admin only)

= Does the kiosk mode require internet? =

Yes, but it includes an offline queue that retries failed check-ins when connectivity is restored.

= Can I customize the registration fields? =

Yes, each event can have custom fields (text, textarea, select, checkbox) in addition to the default name, email, and phone fields.

== Changelog ==

= 1.2.1 =
* FIX: View QR code, Edit, and Resend Email modals not appearing when clicked
* FIX: Dashboard page JS/CSS not loading on some WordPress configurations
* FIX: Uninstall now cleans up ec_verifications table and email options
* FIX: Readme updated with complete feature documentation

= 1.2.0 =
* NEW: Event Dashboard - comprehensive per-event management view
* NEW: Stats cards showing registration counts, check-in progress, capacity usage
* NEW: Search registrations by name, email, or phone
* NEW: Filter registrations by status (registered, checked in, cancelled)
* NEW: Pagination for large registration lists
* NEW: Edit registration details via AJAX modal (name, email, phone, status, custom fields)
* NEW: Add registrations manually from the dashboard
* NEW: Resend confirmation email to original or alternate address
* NEW: Download individual QR codes as PNG files
* NEW: View QR code in a modal with full-size preview
* NEW: Regenerate QR code (delete old, create fresh) from the QR modal
* NEW: Bulk actions - select multiple registrations for bulk check-in, cancel, or resend email
* NEW: Manual check-in and cancel from dashboard
* NEW: Toast notifications for all dashboard actions
* NEW: Staff role capabilities for edit, resend email, download QR, and dashboard access
* NEW: Mobile Staff App shortcode `[event_staff_app]` - phone-friendly guest management
* NEW: Staff App guest list with search, filter, and one-tap check-in/undo
* NEW: Staff App QR scanner with guest profile overlay
* NEW: Staff App add-guest form with duplicate detection
* NEW: Staff App check-in history log with performer tracking
* FIX: QR scanner recognition - lowered error correction from H to M for less dense codes
* FIX: Disabled browser BarcodeDetector API that caused false negatives on some devices
* FIX: Removed forced 1:1 aspect ratio that cropped camera feed on some devices
* FIX: Improved token extraction with regex fallback and debug logging

= 1.0.0 =
* Initial release
* Event CRUD management
* Registration form with custom fields
* QR code generation and email delivery
* Self-service kiosk mode with camera QR scanning
* Digital signature capture
* Excel/CSV export
* Staff role with limited permissions
* Rate limiting and security hardening
