# Advanced Form Builder - Architecture Plan (v4)

## Overview
Replace the simple custom_fields JSON with a full drag-and-drop form builder system inspired by Gravity Forms, rendered in the Istanbul Edition conversational multi-step theme.

## Form Builder Architecture

### Data Model
Each event's form is stored as a JSON schema in `ec_events.form_schema`:

```json
{
  "steps": [
    {
      "id": "step_1",
      "title": "Your Profile",
      "subtitle": "Basic identity",
      "kicker": "Step 1",
      "fields": [
        {
          "id": "field_abc123",
          "type": "short_text",
          "label": "Full Name",
          "placeholder": "Alexandra Morgan",
          "required": true,
          "width": "half",
          "validation": {},
          "conditions": []
        }
      ]
    }
  ],
  "settings": {
    "submit_label": "Submit Registration",
    "success_message": "Registration complete!",
    "enable_review_step": true,
    "enable_progress_bar": true
  }
}
```

### Field Types Registry (15 types)

| Type | Key | Settings | Special Features |
|------|-----|----------|------------------|
| Short Text | `short_text` | label, placeholder, required, maxlength, pattern, width | Basic text input |
| Long Text | `long_text` | label, placeholder, required, maxlength, rows, width | Textarea with auto-resize |
| Email | `email` | label, placeholder, required, verify, width | HTML5 validation + optional email verification (sends code) |
| Phone | `phone` | label, placeholder, required, country_codes, verify, width | Country code dropdown + optional SMS OTP verification |
| Website | `website` | label, placeholder, required, show_preview, width | URL validation + live preview card |
| Radio | `radio` | label, options[], required, layout(list/cards), width | Card-style or list radio buttons |
| Checkbox | `checkbox` | label, options[], required, min_select, max_select, layout(chips/cards), width | Chip-style or card checkboxes |
| Dropdown | `dropdown` | label, options[], required, searchable, width | Select with optional search |
| Date/Time | `datetime` | label, required, mode(date/time/both), min_date, max_date, time_slots[], width | Calendar picker + time slot grid |
| File Upload | `file_upload` | label, required, accept, max_size_mb, multiple, width | Drag-drop upload with type filtering |
| Range Slider | `range` | label, min, max, step, default, unit, width | Styled range with live value display |
| Signature | `signature` | label, required, width | Canvas signature pad |
| Social Links | `social` | label, platforms[], width | Platform-specific inputs with preview |
| Hidden | `hidden` | name, value | Hidden field for tracking |
| Section Break | `section_break` | title, description | Visual separator (not a real field) |

### Width System (Column Layout)
Each field has a `width` property:
- `full` = 100% (1 column)
- `half` = 50% (2 columns)
- `third` = 33% (3 columns)
- `two_thirds` = 66%

Fields flow in a CSS Grid with `grid-template-columns: repeat(6, 1fr)` and fields span the appropriate number of columns.

### Admin Form Builder UI

**Layout**: Split-panel like the conversational form theme
- Left sidebar: field type palette (draggable), step list
- Center: live form preview with drag-drop zones
- Right panel: selected field settings editor

**Drag and Drop**: Use SortableJS (bundled) for:
- Dragging field types from palette onto form
- Reordering fields within a step
- Reordering steps
- Moving fields between steps

**Field Settings Panel** (slides in when a field is selected):
- Common: label, placeholder, required, width, CSS class
- Type-specific: validation rules, options editor, verification toggle
- Conditions: show/hide based on other field values (future)

### Frontend Form Rendering

**Conversational Multi-Step Layout** (Istanbul Edition):
- Left panel: step navigation, event branding, ghost text
- Right panel: current step with animated transitions
- Progress bar at top
- Back/Continue navigation
- Final review step (optional)

**Rendering Pipeline**:
1. PHP renders the form container + step shells
2. JS hydrates with field components
3. Each field type has its own render function
4. Validation runs client-side first, then server-side
5. Multi-step navigation with transition animations

### Verification Flows

**Email Verification**:
1. User enters email
2. Click "Verify" button
3. Plugin generates 6-digit code, stores in transient (5 min TTL)
4. Sends code via wp_mail
5. User enters code in inline OTP input
6. AJAX verification, stores verified flag in form state

**Phone/SMS Verification**:
1. User enters phone with country code
2. Click "Send SMS"
3. Plugin calls configurable SMS provider (Twilio/Vonage/generic webhook)
4. OTP stored in transient (5 min TTL)
5. User enters 4-digit code
6. AJAX verification
7. Settings page for SMS provider config (API key, webhook URL)

### Default Form Page Toggle
- Admin setting: "Create default registration page"
- When toggled on, auto-creates a WP page with the `[event_registration]` shortcode
- Stores page ID in options
- Shows the page URL in admin for easy sharing
- Can be toggled off to delete/unpublish the page

### DB Schema Changes
- Add `form_schema LONGTEXT` column to `ec_events` table (replaces `custom_fields`)
- Migration: convert existing `custom_fields` JSON to new `form_schema` format
- Add `ec_verifications` table for email/phone verification codes

```sql
{prefix}_ec_verifications
- id BIGINT PK
- identifier VARCHAR(255) -- email or phone
- code VARCHAR(10)
- type ENUM('email', 'sms')
- verified TINYINT DEFAULT 0
- attempts INT DEFAULT 0
- expires_at DATETIME
- created_at DATETIME
```

### New Files
```
includes/class-form-builder.php    -- Admin builder UI + save/load
includes/class-form-renderer.php   -- Frontend multi-step rendering
includes/class-form-fields.php     -- Field type registry + render functions
includes/class-verification.php    -- Email/SMS verification logic
assets/js/form-builder.js          -- Admin drag-drop builder
assets/js/form-renderer.js         -- Frontend multi-step form engine
assets/js/vendor/sortable.min.js   -- SortableJS for drag-drop
assets/css/form-builder.css        -- Admin builder styles
assets/css/form-frontend.css       -- Istanbul Edition conversational form
```

### Implementation Order
1. Field type registry (`class-form-fields.php`)
2. Form schema save/load in admin
3. Admin form builder UI with drag-drop
4. Frontend multi-step renderer
5. Verification system (email + SMS)
6. Website preview, social preview, file upload
7. Default page toggle
8. Migration from old custom_fields
