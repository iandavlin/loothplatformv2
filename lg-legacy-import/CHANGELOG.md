# lg-legacy-import changelog

Version reflected in the plugin header + `LG_LEGACY_IMPORT_VERSION` constant +
the small version stamp at the top of the wp-admin metabox.

## 0.2.2 — 2026-05-22

- **Fix: post Update bouncing to Posts list with classic-editor active.**
  The metabox previously rendered two nested `<form>` elements with hidden
  `<input name="action" value="lg_legacy_download|apply">` for the Download
  JSON / Apply buttons. Browsers silently drop nested `<form>` tags and
  absorb their child inputs into the outer `#post` form. PHP's `$_POST`
  keeps the last value for duplicate names, so WP's hidden `action=editpost`
  was being overwritten by `action=lg_legacy_apply` — `wp-admin/post.php`
  hit the default case and `wp_safe_redirect()`-ed to `edit.php`, with
  `save_post` never firing. Fix: render plain `<button type="button">`
  elements inside the metabox and emit the real `<form>` elements via
  `admin_footer`, which puts them outside `#post`. Buttons trigger the
  external forms via JS.

## 0.2.1 — 2026-05-22

- Move all handler breadcrumbs from `error_log()` to the plugin's own
  trace file (`lg-legacy-trace.log`) so we get a definitive read on
  whether the handlers run, independent of `WP_DEBUG_LOG` routing.

## 0.2.0 — 2026-05-22

- **Multi-CPT support.** Refactored Extractor into a dispatcher + six per-CPT
  Extractor classes (post-imgcap, post-type-videos, loothprint, loothcuts,
  shorty, useful_links) plus a BaselineExtractor for any unmapped CPT. Mapper
  consumes a single universal intermediate shape regardless of source.
- **Hero block.** Video CPTs (post-type-videos) now lead with their
  `youtube_link` rendered as an embed block at the top of the layout.
- **Apply-no-redirect.** The wp-admin Apply handler now renders a small
  in-place success page instead of `wp_safe_redirect()`-ing to the editor.
  Avoids a class of "form bounces to Posts list" failures on installs where
  the redirect chain is interrupted (siteurl/home mismatch, security plugins,
  etc.).
- **Diagnostic URL panel** in the metabox surfaces `admin_url()`,
  current page URL, `home`, and `siteurl` options so a host-mismatch root
  cause is visible without shell access.
- **Trace log** at `wp-content/plugins/lg-legacy-import/lg-legacy-trace.log`
  records handler entry/exit/rejection independent of `WP_DEBUG_LOG` routing.

## 0.1.0 — 2026-05-22

- Initial plugin. WP-CLI commands (`wp lg-legacy export|export_all|inspect`),
  classic-editor metabox with Download JSON / Preview / Apply buttons, scoped
  to `post-imgcap` only. Reads `img_cap_images_and_captions_repeater`.
