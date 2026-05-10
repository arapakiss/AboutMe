# Event Check-in Plugin v2 - Production Readiness Plan

## Changes Required

### 1. HTML Email Template System
- Add admin settings page with WYSIWYG email template editor
- Support HTML + plain text fallback
- Template variables: `{first_name}`, `{last_name}`, `{event_title}`, `{event_date}`, `{event_location}`, `{qr_code_url}`, `{qr_code_inline}`, `{site_name}`
- Default template with Istanbul Edition styling (navy/cyan branding)
- Store templates as WP options (per-event override possible)

### 2. Istanbul Edition Theme
Apply the provided design language across all interfaces:
- **Colors**: Navy `#002d72`, Cyan `#0babe4`, Dark `#00122e`, White
- **Font**: Plus Jakarta Sans (Google Fonts)
- **Style**: Bold uppercase headings, clean borders, minimal

#### Kiosk: Split-panel layout
- Left panel: navy background with ghost text, branding
- Right panel: step-based workflow (Language > Scan QR > Signature > Done)
- Progress indicators with numbered steps
- Large touch-friendly buttons with border style

#### Registration Form
- Clean card layout with Istanbul Edition styling
- Bordered inputs, uppercase labels, accent colors

#### Admin Panel
- Consistent navy/cyan accent colors
- Status badges matching the theme

### 3. Load & Concurrency Hardening (1000+ simultaneous users)

#### Database
- Add composite indexes for hot queries
- Use `SELECT ... FOR UPDATE` with row-level locks (already done)
- Add `SKIP LOCKED` for non-blocking reads where appropriate
- Pagination on registration list queries

#### Registration Form
- **WordPress transient caching** for event data (avoid DB hit on every page load)
- **Object caching** compatibility (Redis/Memcached)
- AJAX submissions already avoid full page reloads
- Add request deduplication (idempotency key per submission)

#### Email
- **WP Cron-based email queue** instead of synchronous wp_mail()
- Batch process: max 50 emails per cron tick
- Retry failed emails up to 3 times
- This prevents registration response time from being blocked by SMTP

#### Kiosk
- Already uses REST API (stateless, cacheable)
- Offline queue already implemented
- Add debouncing on QR scan (prevent rapid duplicate submissions)

### 4. Weak Spots After Fixes

| Area | Risk Level | Mitigation |
|------|-----------|------------|
| QR code library | Medium | Bundled implementation is basic; for production, recommend replacing with `chillerlan/php-qrcode` via Composer |
| Email deliverability | Medium | Plugin uses wp_mail() which depends on server config; recommend SMTP plugin (WP Mail SMTP) |
| No CAPTCHA | Low | Honeypot is present; for high-traffic public events, add reCAPTCHA integration |
| Single DB server | Low | Transactions use InnoDB locks; works fine for 1000 concurrent but not 10,000+ |
| CDN dependencies | Low | html5-qrcode and signature_pad loaded from CDN; should bundle for offline kiosk reliability |
| No WebSocket | Low | Kiosk stats poll every 30s; for real-time dashboards, would need WebSocket/SSE |
| Excel export memory | Low | Already streams output; very large exports (50k+) might need background processing |
