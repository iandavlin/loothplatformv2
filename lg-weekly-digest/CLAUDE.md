# LG Weekly Digest — WordPress Plugin

## Overview

A WordPress plugin (v2.0.0) that powers a curated weekly email digest for **The Looth Group**, a guitar repair & restoration community. Admins compose issues from any registered CPT, preview inline, and send via FluentCRM or `wp_mail`.

## Architecture

```
lg-weekly-digest.php          # Plugin bootstrap, constants, includes, boot hooks
includes/
  class-lg-wd-settings.php    # Global settings (schedule, display, from details) stored in wp_options
  class-lg-wd-cpt-registry.php # Registers WP CPTs as available email sections (builtins + custom)
  class-lg-wd-issue.php       # `weekly_email` CPT — the issue data model (sections + post IDs as meta)
  class-lg-wd-query.php       # Two modes: auto-populate (date range) and issue-based (curated payload)
  class-lg-wd-email-builder.php # Renders full HTML email with inline CSS, section-type renderers
  class-lg-wd-sender.php      # Pluggable sender: FluentCRM (default) or wp_mail, with factory + interface
  class-lg-wd-admin.php       # Admin pages: Settings, CPT Registry, Email Design, Send History
  class-lg-wd-compose.php     # Compose page: create/edit issues, auto-populate, search, preview, send
  class-lg-wd-cron.php        # WP Cron: single-event scheduling, auto-create + send on fire
templates/
  email.php                   # HTML email template (table-based, inline styles, FluentCRM smart codes)
assets/
  admin.js                    # Settings page JS (save, media uploader, registry CRUD, sortable sections)
  compose.js                  # Compose page JS (populate, save, preview, test/send, search, drag-and-drop)
  admin.css                   # Shared admin styles (brand palette, cards, toggles, modals, compose UI)
```

## Key Concepts

- **Issue**: A `weekly_email` CPT post. Stores curated sections + post IDs as `_lg_wd_issue_data` post meta. Lifecycle: draft -> sent (published).
- **Section**: A content block in an issue (e.g., "Upcoming Events", "From the Forum"). Defined by the CPT Registry.
- **CPT Registry**: Maps WordPress post types to email sections. Has 4 built-in types (events, forum, member-spotlight, sponsor-post) and supports custom additions. Stored in `lg_wd_cpt_registry` option. Built-in overrides stored separately in `lg_wd_builtin_overrides`.
- **Sender**: Pluggable via `LG_WD_Sender_Interface`. Default is FluentCRM if available, otherwise `wp_mail`. Extensible via `lg_wd_sender` filter.

## Built-in Section Types

| Slug | Label | Type | Query behavior |
|------|-------|------|----------------|
| `event` | Upcoming Events | `events` | Future events via `events_start_date_and_time_` meta, fallback to past |
| `topic` | From the Forum | `forum` | bbPress topics by date range |
| `member-spotlight` | Member Highlight | `spotlight` | Single CPT post |
| `sponsor-post` | Sponsor Post | `sponsor` | Single CPT post |

## Admin Pages

- **Settings** (`lg-weekly-digest`): Schedule (day/time), display toggles, from details, subject template
- **Compose** (`lg-wd-compose`): Issue editor with auto-populate, drag-and-drop sections/posts, archive search, inline preview, test send, and full send
- **All Issues**: Standard WP list table for the `weekly_email` CPT

## AJAX Endpoints

All require `manage_options` capability and nonce verification.

**Settings page** (`admin.js`):
- `lg_wd_save` — Save global settings
- `lg_wd_registry_add` — Add custom CPT to registry
- `lg_wd_registry_remove` — Remove custom CPT from registry

**Compose page** (`compose.js`):
- `lg_wd_compose_save` — Save issue draft
- `lg_wd_compose_populate` — Auto-populate sections from date range
- `lg_wd_compose_search` — Search posts across registered CPTs
- `lg_wd_compose_preview` — Generate email HTML preview
- `lg_wd_compose_test_send` — Send test email to a single address
- `lg_wd_compose_send` — Send to all subscribers (marks issue as sent)
- `lg_wd_compose_new_issue` — Create a new draft issue

## Cron

Uses `wp_schedule_single_event` (not recurring) to avoid drift. After each fire, reschedules for the next occurrence. Timezone: `America/New_York`. If no draft issue exists at fire time, auto-creates one and populates from the last 7 days.

## Email Rendering

- Table-based HTML with inline CSS for email client compatibility
- Brand palette: Gold `#ECB351`, Dark `#2B2318`, Mint `#87986A`/`#D4E0B8`, Light `#FAF6EE`
- Section renderers: `render_post`, `render_event`, `render_forum_item`, `render_spotlight`, `render_sponsor`
- Subject template supports `{{week_date}}`, `{{site_name}}`, `{{item_count}}` tokens
- Unsubscribe uses FluentCRM `{{unsubscribe_url}}` smart code

## FluentCRM Integration

- Targets List ID `3` ("Weekly News Letter"), tag `all`
- Creates a campaign with `scheduled` status, resolves subscribers, creates CampaignEmail rows, sets to `working`
- Test sends use `wp_mail` regardless of sender

## wp_options Keys

- `lg_wd_settings` — Global plugin settings
- `lg_wd_cpt_registry` — Custom (non-builtin) registered sections
- `lg_wd_builtin_overrides` — Label/max_items/enabled overrides for built-in sections
- `lg_wd_send_history` — Last 50 send records

## Development Notes

- PHP 8.1+ required (uses `match`, named args, arrow functions, typed properties)
- jQuery + jQuery UI Sortable for admin JS (no build step)
- No REST API endpoints — everything is admin-ajax
- All admin pages gated behind `manage_options` capability
- The `{includes,templates,assets}` directory in root appears to be an artifact and should be cleaned up
