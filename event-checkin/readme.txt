=== Event Check-in ===
Contributors: arapakiss
Tags: events, registration, qr-code, check-in, kiosk, signature
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
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

= Does the kiosk mode require internet? =

Yes, but it includes an offline queue that retries failed check-ins when connectivity is restored.

= Can I customize the registration fields? =

Yes, each event can have custom fields (text, textarea, select, checkbox) in addition to the default name, email, and phone fields.

== Changelog ==

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
