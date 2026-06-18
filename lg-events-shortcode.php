<?php
/**
 * Plugin Name: Looth Events Shortcode
 * Description: [looth_events] — clean upcoming-events list rendered from the
 *              ACF `event` CPT. Drop-in replacement for the Dynamic.ooo
 *              widget that was crashing on /calendar/.
 * Version:     1.0.0
 *
 * Usage:
 *   [looth_events]                      defaults: limit=20, upcoming only
 *   [looth_events limit=10]
 *   [looth_events region="north-america"]
 *   [looth_events tier="looth-lite"]    only show looth-lite gated events
 *   [looth_events past=1]               show past events (newest first)
 *   [looth_events layout="list"]        single-column list (default is grid)
 */

if (!defined('ABSPATH')) exit;

/**
 * Build a normalized event row from a WP_Post + its ACF meta.
 * All field reads are guarded so the shortcode never fatals on a half-filled event.
 */
function lg_events_shortcode_row(WP_Post $p): array {
    $date = (string) get_post_meta($p->ID, 'events_start_date_and_time_', true);
    $time = (string) get_post_meta($p->ID, 'time_of_event', true);
    $start_ts = 0;
    if ($date !== '') {
        $ymd = (strlen($date) === 8) ? substr($date,0,4).'-'.substr($date,4,2).'-'.substr($date,6,2) : $date;
        $start_ts = strtotime($ymd . ' ' . ($time ?: '00:00:00') . ' UTC') ?: 0;
    }
    $region = null;
    $rid = get_post_meta($p->ID, 'region', true);
    if (is_numeric($rid) && (int)$rid > 0) {
        global $wpdb;
        $name = $wpdb->get_var($wpdb->prepare("SELECT name FROM {$wpdb->terms} WHERE term_id = %d", (int)$rid));
        if ($name) $region = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $tier = null;
    $terms = wp_get_object_terms($p->ID, 'tier');
    if (!is_wp_error($terms) && $terms) {
        $tier = $terms[0]->slug;
    }
    $thumb_id = (int) get_post_thumbnail_id($p->ID);
    $thumb = $thumb_id ? (wp_get_attachment_image_url($thumb_id, 'medium_large') ?: '') : '';
    $join = (string) get_post_meta($p->ID, 'zoom_url_for_looth_group_virtual_event', true);

    return [
        'id'         => $p->ID,
        'title'      => get_the_title($p) ?: '(untitled)',
        'url'        => get_permalink($p) ?: '#',
        'start_ts'   => $start_ts,
        'region'     => $region,
        'tier'       => $tier,
        'thumb'      => $thumb,
        'join_url'   => $join,
        'excerpt'    => has_excerpt($p->ID) ? get_the_excerpt($p) : '',
    ];
}

function lg_events_shortcode(array $atts = []): string {
    $atts = shortcode_atts([
        'limit'  => 20,
        'region' => '',
        'tier'   => '',
        'past'   => '0',
        'layout' => 'list',   // 'list' (default, img left/text right) or 'grid'
    ], $atts, 'looth_events');

    $limit = max(1, min(100, (int) $atts['limit']));
    $is_past = $atts['past'] === '1' || strtolower((string)$atts['past']) === 'true';
    $today_ymd = gmdate('Ymd');

    $meta_query = [
        [
            'key'     => 'events_start_date_and_time_',
            'value'   => $today_ymd,
            'compare' => $is_past ? '<' : '>=',
            'type'    => 'CHAR',
        ],
    ];
    if ($atts['tier'] !== '') {
        $tax_query[] = [
            'taxonomy' => 'tier',
            'field'    => 'slug',
            'terms'    => array_filter(array_map('trim', explode(',', $atts['tier']))),
        ];
    }
    if ($atts['region'] !== '') {
        $tax_query[] = [
            'taxonomy' => 'region',
            'field'    => 'slug',
            'terms'    => array_filter(array_map('trim', explode(',', $atts['region']))),
        ];
    }

    $args = [
        'post_type'      => 'event',
        'post_status'    => 'publish',
        'posts_per_page' => $limit,
        'meta_query'     => $meta_query,
        'orderby'        => 'meta_value',
        'meta_key'       => 'events_start_date_and_time_',
        'meta_type'      => 'CHAR',  // ← string compare on Ymd; avoids the cast-to-DATE that was crashing the old widget
        'order'          => $is_past ? 'DESC' : 'ASC',
        'no_found_rows'  => true,
    ];
    if (!empty($tax_query)) $args['tax_query'] = $tax_query;

    $q = new WP_Query($args);
    if (!$q->have_posts()) {
        return '<div class="lg-events lg-events--empty">No events to show.</div>';
    }

    $rows = [];
    while ($q->have_posts()) { $q->the_post(); $rows[] = lg_events_shortcode_row(get_post()); }
    wp_reset_postdata();

    ob_start();
    lg_events_shortcode_styles();
    $layout_cls = $atts['layout'] === 'list' ? 'lg-events--list' : 'lg-events--grid';
    ?>
    <div class="lg-events <?php echo esc_attr($layout_cls); ?>">
      <?php foreach ($rows as $r):
        $when = $r['start_ts'] ? wp_date('D, M j · g:i a T', $r['start_ts']) : '';
        $mon  = $r['start_ts'] ? strtoupper(wp_date('M', $r['start_ts'])) : '';
        $day  = $r['start_ts'] ? wp_date('j', $r['start_ts']) : '';
        $tier_label = match ($r['tier']) {
          'looth-pro', 'pro'   => 'Pro',
          'looth-lite', 'lite' => 'Lite',
          default              => null,
        };
        $tier_cls = $tier_label ? 'lge-tier--' . strtolower($tier_label) : '';
        // Card always links to the post permalink — gating + Zoom URL exposure
        // lives on the post template, not the card. Don't leak join_url to anon.
        $href = $r['url'];
      ?>
        <a class="lge-card" href="<?php echo esc_url($href); ?>">
          <?php if ($r['thumb']): ?>
            <img class="lge-img" src="<?php echo esc_url($r['thumb']); ?>" alt="" loading="lazy"
                 onerror="this.onerror=null;this.src='https://loothgroup.com/wp-content/uploads/2024/11/Featured-Image-Fallback-2.webp'">
          <?php endif; ?>
          <?php if ($mon && $day): ?>
            <div class="lge-date">
              <span class="lge-mon"><?php echo esc_html($mon); ?></span>
              <span class="lge-day"><?php echo esc_html($day); ?></span>
            </div>
          <?php endif; ?>
          <div class="lge-body">
            <h3 class="lge-title"><?php echo esc_html($r['title']); ?></h3>
            <?php if ($when): ?><div class="lge-when"><?php echo esc_html($when); ?></div><?php endif; ?>
            <div class="lge-meta">
              <?php if ($r['region']): ?><span class="lge-region"><?php echo esc_html($r['region']); ?></span><?php endif; ?>
              <?php if ($tier_label): ?><span class="lge-tier <?php echo esc_attr($tier_cls); ?>"><?php echo esc_html($tier_label); ?></span><?php endif; ?>
              <?php if ($r['start_ts']): ?>
                <button type="button" class="lge-cal" data-lge-ics
                  data-title="<?php echo esc_attr($r['title']); ?>"
                  data-start="<?php echo (int)$r['start_ts']; ?>"
                  data-end="<?php echo (int)$r['start_ts'] + 3600; ?>"
                  data-url="<?php echo esc_attr($r['join_url'] ?: $r['url']); ?>"
                  data-location="<?php echo esc_attr($r['region'] ?? ''); ?>"
                  aria-label="Add to calendar">📅 Add</button>
              <?php endif; ?>
              <?php if ($r['join_url']): ?><span class="lge-cta">Join →</span><?php else: ?><span class="lge-cta">Details →</span><?php endif; ?>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
    <?php lg_events_shortcode_ics_js(); ?>
    <?php
    return ob_get_clean();
}
add_shortcode('looth_events', 'lg_events_shortcode');

/** Inline CSS — small, scoped, mobile-first. Emitted once per page. */
function lg_events_shortcode_styles(): void {
    static $emitted = false;
    if ($emitted) return;
    $emitted = true;
    ?>
    <style>
      .lg-events { --lge-gap: 16px; display: grid; gap: var(--lge-gap); margin: 24px 0; }
      .lg-events--grid { grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); }
      .lg-events--list { grid-template-columns: 1fr; }
      .lg-events--empty { padding: 24px; color: #666; font-style: italic; }
      .lge-card {
        position: relative; display: flex; flex-direction: column;
        background: #fff; border: 1px solid #e5e7e0; border-radius: 8px;
        text-decoration: none; color: inherit; transition: box-shadow .15s ease, transform .15s ease;
        overflow: visible; /* allow image to overlap */
      }
      .lge-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.08); transform: translateY(-2px); }
      .lge-img { width: 100%; aspect-ratio: 16/9; object-fit: cover; background: #f3f1ea; display: block; border-radius: 8px 8px 0 0; }
      .lge-date {
        position: absolute; top: 12px; left: 12px;
        background: rgba(255,255,255,0.95); border-radius: 6px; padding: 6px 10px;
        display: flex; flex-direction: column; align-items: center; line-height: 1;
        box-shadow: 0 1px 3px rgba(0,0,0,0.12);
      }
      .lge-mon { font-size: 11px; font-weight: 700; letter-spacing: 0.06em; color: #87986a; }
      .lge-day { font-size: 22px; font-weight: 800; color: #2d3324; margin-top: 2px; }
      .lge-body { padding: 16px; display: flex; flex-direction: column; gap: 8px; flex: 1; }
      .lge-title { font: 700 18px/1.25 Georgia, serif; margin: 0; color: #2d3324; }
      .lge-when { font: 600 13px/1.2 system-ui, sans-serif; color: #87986a; }
      .lge-meta {
        margin-top: auto; padding-top: 8px; border-top: 1px solid #f0eee5;
        display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
        font: 600 12px/1 system-ui, sans-serif;
      }
      .lge-region { color: #6b6f6b; }
      .lge-tier { padding: 3px 7px; border-radius: 3px; background: #e8e9e0; color: #2d3324; }
      .lge-tier--pro  { background: #c9a96f; color: #fff; }
      .lge-tier--lite { background: #87986a; color: #fff; }
      .lge-cta { margin-left: auto; color: #87986a; }
      .lge-card:hover .lge-cta { color: #2d3324; }
      .lg-events--list { grid-template-columns: 1fr; max-width: 820px; }
      .lg-events--list .lge-card {
        flex-direction: row;
        align-items: stretch;
        min-height: 180px;
        padding-left: 12px;  /* room for image overlap on the left */
      }
      /* Image breaks out of the card by ~12px (≈3% on an 820px row) on
         right side, casts a soft shadow onto the text portion. */
      .lg-events--list .lge-img {
        width: 260px; flex: 0 0 260px;
        height: auto; aspect-ratio: auto;
        object-fit: contain;          /* show the WHOLE image, no crop */
        background: #f3f1ea;
        border-radius: 8px;
        margin: 12px -12px 12px -24px; /* overhang left, overlap right */
        box-shadow: 4px 0 12px rgba(0,0,0,0.18);
        position: relative; z-index: 2;
        align-self: center;
      }
      .lg-events--list .lge-body { padding: 16px 18px 16px 28px; }
      .lg-events--list .lge-date {
        top: 18px; left: 0; /* anchor to the overlapping image's left edge */
      }
      @media (max-width: 600px) {
        .lg-events--list { max-width: 100%; }
        .lg-events--list .lge-card {
          flex-direction: column; padding-left: 0;
        }
        .lg-events--list .lge-img {
          width: 100%; flex: none; aspect-ratio: 16/9;
          margin: 0; border-radius: 8px 8px 0 0; box-shadow: none;
        }
        .lg-events--list .lge-body { padding: 16px; }
      }
      .lge-cal {
        font: 600 12px/1 system-ui, sans-serif;
        background: transparent; border: 1px solid #d9d6c8; border-radius: 4px;
        padding: 4px 8px; cursor: pointer; color: #2d3324; position: relative;
      }
      .lge-cal:hover { background: #f3f1ea; border-color: #87986a; }
      .lge-cal-menu {
        position: absolute; z-index: 100; min-width: 180px;
        background: #fff; border: 1px solid #d9d6c8; border-radius: 6px;
        box-shadow: 0 4px 16px rgba(0,0,0,0.12); padding: 4px 0;
        font: 500 13px/1.3 system-ui, sans-serif;
      }
      .lge-cal-menu button {
        display: block; width: 100%; text-align: left;
        padding: 8px 14px; border: 0; background: transparent;
        cursor: pointer; color: #2d3324;
      }
      .lge-cal-menu button:hover { background: #f3f1ea; }
    </style>
    <?php
}

/** Inline JS — ICS download handler. Emitted once per page. */
function lg_events_shortcode_ics_js(): void {
    static $emitted = false;
    if ($emitted) return;
    $emitted = true;
    ?>
    <script>
    (function() {
      function pad(n){return String(n).padStart(2,'0');}
      function gcalDate(ts){
        var d = new Date(ts*1000);
        return d.getUTCFullYear() + pad(d.getUTCMonth()+1) + pad(d.getUTCDate())
             + 'T' + pad(d.getUTCHours()) + pad(d.getUTCMinutes()) + pad(d.getUTCSeconds()) + 'Z';
      }
      function isoDate(ts){ return new Date(ts*1000).toISOString(); }
      function icsEsc(s){return String(s||'').replace(/[\\,;]/g, function(m){return '\\'+m;}).replace(/\r?\n/g, '\\n');}

      function openGoogle(d) {
        var p = new URLSearchParams({
          action: 'TEMPLATE',
          text:   d.title || 'Event',
          dates:  gcalDate(d.start) + '/' + gcalDate(d.end),
        });
        if (d.url)      p.set('details',  d.url);
        if (d.location) p.set('location', d.location);
        window.open('https://calendar.google.com/calendar/render?' + p.toString(), '_blank', 'noopener');
      }
      function openOutlook(d) {
        var p = new URLSearchParams({
          path:    '/calendar/action/compose',
          rru:     'addevent',
          subject: d.title || 'Event',
          startdt: isoDate(d.start),
          enddt:   isoDate(d.end),
        });
        if (d.url)      p.set('body',     d.url);
        if (d.location) p.set('location', d.location);
        window.open('https://outlook.live.com/calendar/0/deeplink/compose?' + p.toString(), '_blank', 'noopener');
      }
      function downloadIcs(d) {
        var lines = [
          'BEGIN:VCALENDAR','VERSION:2.0','PRODID:-//Looth Group//events//EN',
          'BEGIN:VEVENT',
          'UID:lg-' + d.start + '-' + Math.random().toString(36).slice(2,8) + '@loothgroup.com',
          'DTSTAMP:'  + gcalDate(Math.floor(Date.now()/1000)),
          'DTSTART:'  + gcalDate(d.start),
          'DTEND:'    + gcalDate(d.end),
          'SUMMARY:'  + icsEsc(d.title),
          d.url      ? 'URL:'         + icsEsc(d.url)      : null,
          d.location ? 'LOCATION:'    + icsEsc(d.location) : null,
          d.url      ? 'DESCRIPTION:' + icsEsc(d.url)      : null,
          'END:VEVENT','END:VCALENDAR'
        ].filter(Boolean).join('\r\n');
        var blob = new Blob([lines], {type: 'text/calendar;charset=utf-8'});
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = (d.title || 'event').replace(/[^a-z0-9-_]+/gi,'-').slice(0,60) + '.ics';
        document.body.appendChild(a); a.click();
        setTimeout(function(){ URL.revokeObjectURL(a.href); a.remove(); }, 0);
      }

      function closeMenus() {
        document.querySelectorAll('.lge-cal-menu').forEach(function(m){ m.remove(); });
      }
      function openMenu(btn) {
        closeMenus();
        var d = {
          title:    btn.dataset.title,
          start:    parseInt(btn.dataset.start, 10),
          end:      parseInt(btn.dataset.end, 10) || (parseInt(btn.dataset.start, 10) + 3600),
          url:      btn.dataset.url,
          location: btn.dataset.location,
        };
        var menu = document.createElement('div');
        menu.className = 'lge-cal-menu';
        menu.innerHTML =
          '<button type="button" data-cal="google">Google Calendar</button>' +
          '<button type="button" data-cal="apple">Apple Calendar (.ics)</button>' +
          '<button type="button" data-cal="outlook">Outlook</button>' +
          '<button type="button" data-cal="ics">Download .ics</button>';
        menu.style.top    = (btn.offsetHeight + 4) + 'px';
        menu.style.left   = '0';
        btn.appendChild(menu);
        menu.addEventListener('click', function(e) {
          var b = e.target.closest('button[data-cal]');
          if (!b) return;
          e.preventDefault(); e.stopPropagation();
          if      (b.dataset.cal === 'google')  openGoogle(d);
          else if (b.dataset.cal === 'outlook') openOutlook(d);
          else                                  downloadIcs(d); // apple + ics → .ics
          closeMenus();
        });
      }

      document.addEventListener('click', function(e) {
        var btn = e.target.closest('[data-lge-ics]');
        if (btn) {
          e.preventDefault(); e.stopPropagation();
          openMenu(btn);
          return;
        }
        if (!e.target.closest('.lge-cal-menu')) closeMenus();
      });
      document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeMenus(); });
    })();
    </script>
    <?php
}
