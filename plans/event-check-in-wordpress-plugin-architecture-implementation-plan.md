# Event Check-in WordPress Plugin

## Overview
A WordPress plugin that provides a complete event registration and check-in system with QR codes, digital signatures, self-service kiosk mode, and Excel export capabilities.

## User Flow
1. **Admin** creates an event with custom registration fields
2. **User** fills out a registration form (shortcode-based)
3. System generates a unique QR code and emails it to the user
4. **At the event**: tablet kiosk in self-service mode scans QR codes
5. After scan, optional digital signature capture
6. Check-in is recorded with timestamp
7. **Admin/Staff** can export all registrations + attendance to Excel

## Architecture

### Three User Layers
- **WordPress Admin**: Full control - create events, manage registrations, configure settings, export data
- **Staff**: Access to kiosk mode, view registrations, manual check-in capability
- **User (Public)**: Registration form, receive QR code, self check-in at kiosk

### Database Schema (4 custom tables)

```
{prefix}_ec_events
- id (BIGINT, PK, AUTO_INCREMENT)
- title (VARCHAR 255)
- description (TEXT)
- event_date (DATETIME)
- location (VARCHAR 255)
- max_capacity (INT, nullable)
- registration_deadline (DATETIME, nullable)
- require_signature (TINYINT, default 0)
- custom_fields (JSON) -- dynamic form fields config
- status (ENUM: draft, published, closed, archived)
- created_at (DATETIME)
- updated_at (DATETIME)

{prefix}_ec_registrations
- id (BIGINT, PK, AUTO_INCREMENT)
- event_id (BIGINT, FK)
- qr_token (VARCHAR 64, UNIQUE, indexed)
- first_name (VARCHAR 100)
- last_name (VARCHAR 100)
- email (VARCHAR 255)
- phone (VARCHAR 50)
- custom_data (JSON) -- stores dynamic field values
- status (ENUM: registered, checked_in, cancelled)
- checked_in_at (DATETIME, nullable)
- signature_data (LONGTEXT, nullable) -- base64 signature image
- ip_address (VARCHAR 45)
- created_at (DATETIME)
- updated_at (DATETIME)
- INDEX(event_id, status)
- INDEX(email, event_id)

{prefix}_ec_checkin_log
- id (BIGINT, PK, AUTO_INCREMENT)
- registration_id (BIGINT, FK)
- event_id (BIGINT, FK)
- action (ENUM: checkin, undo_checkin)
- performed_by (BIGINT, nullable) -- WP user_id or null for self
- ip_address (VARCHAR 45)
- user_agent (VARCHAR 255)
- created_at (DATETIME)

{prefix}_ec_rate_limits
- id (BIGINT, PK, AUTO_INCREMENT)
- ip_address (VARCHAR 45)
- action (VARCHAR 50)
- attempts (INT)
- window_start (DATETIME)
- INDEX(ip_address, action)
```

### Plugin File Structure

```
event-checkin/
├── event-checkin.php              # Main plugin bootstrap
├── readme.txt                     # WP readme
├── uninstall.php                  # Clean uninstall
├── includes/
│   ├── class-activator.php        # DB creation on activate
│   ├── class-admin.php            # Admin pages & CRUD
│   ├── class-rest-api.php         # REST API endpoints
│   ├── class-registration.php     # Public registration form
│   ├── class-qrcode.php           # QR generation (bundled lib)
│   ├── class-checkin.php          # Check-in + kiosk logic
│   ├── class-signature.php        # Digital signature handling
│   ├── class-export.php           # Excel/CSV export
│   ├── class-security.php         # Rate limiting, validation
│   └── class-roles.php            # Staff role management
├── assets/
│   ├── css/
│   │   ├── admin.css              # Admin styles
│   │   ├── public.css             # Registration form styles
│   │   └── kiosk.css              # Kiosk fullscreen styles
│   └── js/
│       ├── admin.js               # Admin interactions
│       ├── registration.js        # Form validation
│       ├── kiosk.js               # QR scanner (html5-qrcode)
│       └── signature.js           # Signature pad
├── templates/
│   ├── registration-form.php      # Form template
│   ├── kiosk.php                  # Kiosk mode template
│   ├── checkin-success.php        # Post-checkin screen
│   └── email-confirmation.php     # Email with QR
└── lib/
    └── phpqrcode.php              # Bundled QR library
```

### Security Measures
1. **Nonce verification** on all forms and AJAX requests
2. **Rate limiting** -- max 5 registrations per IP per hour, max 30 scan attempts per minute
3. **Input sanitization** -- all inputs sanitized with WP functions
4. **Capability checks** -- `manage_options` for admin, custom `ec_staff` cap for staff
5. **QR tokens** -- cryptographically random 32-byte hex tokens (non-guessable)
6. **Prepared statements** -- all DB queries via `$wpdb->prepare()`
7. **CSRF protection** -- WP nonces on all state-changing operations
8. **Kiosk mode** -- locked-down interface, auto-reset after idle timeout
9. **Duplicate check-in prevention** -- idempotent check-in operations

### Edge Cases & Resilience
- **Duplicate registrations**: Unique constraint on (email + event_id)
- **Concurrent check-ins**: DB-level locking with `FOR UPDATE` on check-in
- **Network failures during kiosk**: Offline queue with retry (localStorage)
- **QR code reuse**: Token invalidated after check-in, shows "already checked in"
- **Capacity limits**: Atomic capacity check during registration
- **Large exports**: Streaming CSV/Excel generation to avoid memory issues
- **Session timeout**: Kiosk auto-resets to scanner after 15s idle

### REST API Endpoints
```
POST   /wp-json/event-checkin/v1/register          # Public registration
POST   /wp-json/event-checkin/v1/checkin            # QR check-in
POST   /wp-json/event-checkin/v1/signature          # Save signature
GET    /wp-json/event-checkin/v1/events             # List events (staff+)
GET    /wp-json/event-checkin/v1/registrations/{id} # Get registration (staff+)
GET    /wp-json/event-checkin/v1/export/{event_id}  # Export Excel (admin)
```

### Frontend Libraries (CDN/bundled)
- **html5-qrcode** -- camera-based QR scanning for kiosk
- **Signature Pad** -- canvas-based signature capture
- **phpqrcode** -- server-side QR generation (bundled PHP)

### Shortcodes
- `[event_registration id="123"]` -- renders registration form
- `[event_kiosk id="123"]` -- renders kiosk check-in interface (staff only)

## Implementation Order
1. Plugin bootstrap + database schema
2. Admin panel (event CRUD)
3. Registration form + QR generation + email
4. REST API for check-in
5. Kiosk mode with QR scanner
6. Digital signature
7. Excel export
8. Security hardening + rate limiting
9. Staff role management
