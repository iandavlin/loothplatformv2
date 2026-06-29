<?php
defined( 'ABSPATH' ) || exit;

/**
 * LG_WD_PDF
 * Renders a weekly digest issue to a print-ready PDF (for one-click posting to
 * Patreon, where images can't be hot-linked and must travel embedded).
 *
 * Engine: headless Chrome (Chrome-for-Testing). The whole point of the PDF is
 * that EVERY image embeds, so we do NOT rely on the renderer fetching the
 * absolute loothgroup.com / R2 image URLs over the network (the cookie gate
 * can block server-side fetches). Instead every <img src> is resolved to its
 * bytes server-side — local uploads path first, HTTP fallback second — and
 * rewritten as a base64 data: URI BEFORE the HTML reaches Chrome. Chrome then
 * renders a fully self-contained document with no network dependency.
 */
class LG_WD_PDF {

    // ── Chrome discovery ──────────────────────────────────────────────────────

    /**
     * Candidate locations for the headless Chrome binary, in priority order.
     * Override with the LG_WD_CHROME_BIN constant or the 'lg_wd_chrome_bin' filter.
     */
    private static function chrome_candidates(): array {
        return [
            '/opt/lg-chrome/chrome-linux64/chrome',
            '/usr/bin/google-chrome-stable',
            '/usr/bin/google-chrome',
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
        ];
    }

    public static function find_chrome(): ?string {
        if ( defined( 'LG_WD_CHROME_BIN' ) && LG_WD_CHROME_BIN && @is_executable( LG_WD_CHROME_BIN ) ) {
            return LG_WD_CHROME_BIN;
        }
        $filtered = apply_filters( 'lg_wd_chrome_bin', '' );
        if ( $filtered && @is_executable( $filtered ) ) {
            return $filtered;
        }
        foreach ( self::chrome_candidates() as $c ) {
            if ( @is_executable( $c ) ) return $c;
        }
        return null;
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Build a PDF for an issue.
     *
     * @return array{pdf:string,filename:string,img_total:int,img_embedded:int}|WP_Error
     */
    public static function build_for_issue( int $issue_id ) {
        $issue_data = LG_WD_Issue::get_data( $issue_id );
        if ( empty( $issue_data['sections'] ) ) {
            return new WP_Error( 'lg_wd_pdf_empty', 'Issue has no sections.' );
        }

        $payload = LG_WD_Query::build_payload_from_issue( $issue_data );
        if ( empty( $payload ) ) {
            return new WP_Error( 'lg_wd_pdf_empty', 'No content in issue.' );
        }

        $html    = LG_WD_Email_Builder::build( $payload );
        $subject = LG_WD_Email_Builder::build_subject( $payload );

        // Stage everything into one throwaway work dir: the resolved images as
        // real files (referenced by relative name) plus the HTML, then render.
        $workdir = self::make_workdir();
        if ( is_wp_error( $workdir ) ) return $workdir;

        $stats = [ 'total' => 0, 'embedded' => 0, 'missing' => [] ];
        $html  = self::stage_images( $html, $workdir, $stats );

        // Render as ONE continuous tall page (no letter pagination): inject a
        // script that sizes @page to the measured content box.
        $html = self::inject_single_page_script( $html );

        $pdf = self::render_pdf( $html, $workdir );
        self::rrmdir( $workdir );
        if ( is_wp_error( $pdf ) ) return $pdf;

        return [
            'pdf'          => $pdf,
            'filename'     => self::filename_for( $issue_id, $subject ),
            'img_total'    => $stats['total'],
            'img_embedded' => $stats['embedded'],
            'img_missing'  => $stats['missing'],
        ];
    }

    private static function make_workdir() {
        $dir = trailingslashit( get_temp_dir() ) . 'lg-wd-pdf-' . wp_generate_password( 12, false );
        if ( ! wp_mkdir_p( $dir ) ) {
            return new WP_Error( 'lg_wd_pdf_tmp', 'Could not create the temp work directory.' );
        }
        // Always return a trailing-slashed path — callers concatenate file names
        // directly onto it (e.g. $workdir . 'img-1.png').
        return trailingslashit( $dir );
    }

    /** Max single-page height in CSS px (~200in @96dpi). Chrome paginates by
     *  @page, so anything taller spills to a second page. */
    const MAX_PAGE_PX = 14400;

    /**
     * Make the PDF render as ONE continuous tall page instead of letter
     * pagination. Injects a script that, after all images have loaded,
     * measures the email content box and sets @page { size: width × height }.
     * Chrome then produces a single page exactly as tall as the content
     * (capped at MAX_PAGE_PX). A small buffer avoids a sub-pixel spill onto a
     * phantom second page.
     */
    private static function inject_single_page_script( string $html ): string {
        $cap = (int) apply_filters( 'lg_wd_pdf_max_height_px', self::MAX_PAGE_PX );

        // NB: images are force-loaded (loading=eager) before measuring, because
        // a lazily-loaded image below the fold would otherwise be 0-height at
        // measure time and the page would be cut short.
        $js = '<script>(function(){'
            . 'function m(){'
            . 'var c=document.querySelector(".email-container")||document.body;'
            . 'var r=c.getBoundingClientRect();'
            . 'var w=Math.ceil(r.width);'
            . 'var h=Math.min(Math.ceil(Math.max(document.body.scrollHeight,r.bottom))+16,' . $cap . ');'
            . 'var s=document.createElement("style");'
            . 's.textContent="@page{size:"+w+"px "+h+"px;margin:0}html,body{margin:0;padding:0;background:#FAF6EE}";'
            . 'document.head.appendChild(s);'
            . '}'
            . 'var imgs=[].slice.call(document.images);'
            . 'imgs.forEach(function(i){try{i.loading="eager";}catch(e){}});'
            . 'var p=imgs.filter(function(i){return !i.complete;});'
            . 'if(!p.length){m();return;}'
            . 'var d=0;p.forEach(function(i){var f=function(){if(++d>=p.length)m();};'
            . 'i.addEventListener("load",f);i.addEventListener("error",f);});'
            . '})();</script>';

        if ( stripos( $html, '</body>' ) !== false ) {
            return str_ireplace( '</body>', $js . '</body>', $html );
        }
        return $html . $js;
    }

    private static function filename_for( int $issue_id, string $subject ): string {
        $title = get_the_title( $issue_id );
        $base  = $title ?: ( $subject ?: ( 'weekly-digest-' . $issue_id ) );
        $slug  = sanitize_title( $base );
        if ( ! $slug ) $slug = 'weekly-digest-' . $issue_id;
        return $slug . '.pdf';
    }

    // ── Image staging ───────────────────────────────────────────────────────────

    /** 1x1 transparent GIF, used to neutralise images that can't be resolved. */
    const TRANSPARENT_PX = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    /**
     * Resolve every <img src> in $html to its bytes (local uploads path first,
     * HTTP fallback second), write each to a real file inside $workdir, and
     * rewrite the src to that file's relative name.
     *
     * Why files and not data: URIs — Chrome's --print-to-pdf hangs on large
     * inline data: URIs, but reads local file:// images instantly. Either way
     * the renderer never has to fetch over the network / past the cookie gate,
     * so every resolvable image is guaranteed to embed.
     *
     * $stats is filled with ['total'=>N,'embedded'=>M,'missing'=>[urls]].
     */
    public static function stage_images( string $html, string $workdir, array &$stats = null ): string {
        $cache    = [];   // url => relative filename | TRANSPARENT_PX
        $total    = 0;
        $embedded = 0;
        $missing  = [];
        $seq      = 0;

        $out = preg_replace_callback(
            '/(<img\b[^>]*?\bsrc=)(["\'])(.*?)\2/i',
            function ( $m ) use ( &$cache, &$total, &$embedded, &$missing, &$seq, $workdir ) {
                $url = html_entity_decode( $m[3], ENT_QUOTES );
                if ( $url === '' || stripos( $url, 'data:' ) === 0 ) {
                    return $m[0]; // already inline or empty — skip, not counted
                }
                $total++;

                if ( ! array_key_exists( $url, $cache ) ) {
                    $resolved = self::fetch_bytes( $url );
                    if ( $resolved === null ) {
                        // Couldn't resolve (file missing from this box's uploads
                        // mirror, or gated CDN). Neutralise to a tiny transparent
                        // data: px so the renderer makes zero network requests.
                        $cache[ $url ] = self::TRANSPARENT_PX;
                        $missing[]     = $url;
                    } else {
                        $name = 'img-' . ( ++$seq ) . '.' . $resolved['ext'];
                        if ( false === file_put_contents( $workdir . $name, $resolved['bytes'] ) ) {
                            $cache[ $url ] = self::TRANSPARENT_PX;
                            $missing[]     = $url;
                        } else {
                            $cache[ $url ] = $name;
                            $embedded++;
                        }
                    }
                }

                return $m[1] . $m[2] . $cache[ $url ] . $m[2];
            },
            $html
        );

        if ( $stats !== null ) {
            $stats['total']    = $total;
            $stats['embedded'] = $embedded;
            $stats['missing']  = $missing;
        }
        return $out;
    }

    /**
     * @return array{bytes:string,ext:string}|null
     */
    private static function fetch_bytes( string $url ): ?array {
        $bytes = self::read_local( $url );
        if ( $bytes === null ) {
            $bytes = self::read_remote( $url );
        }
        if ( $bytes === null || $bytes === '' ) return null;

        $mime = self::sniff_mime( $url, $bytes );
        $ext  = self::mime_ext( $mime );
        return [ 'bytes' => $bytes, 'ext' => $ext ];
    }

    private static function mime_ext( string $mime ): string {
        $map = [
            'image/png'     => 'png',
            'image/jpeg'    => 'jpg',
            'image/gif'     => 'gif',
            'image/webp'    => 'webp',
            'image/svg+xml' => 'svg',
            'image/avif'    => 'avif',
        ];
        return $map[ $mime ] ?? 'img';
    }

    /**
     * Map a site URL to a local file under uploads / wp-content / ABSPATH and
     * read it. Scheme-insensitive (uploads baseurl may be https while
     * content_url() reports http). Returns null if not a local file.
     */
    private static function read_local( string $url ): ?string {
        // Drop query string / fragment for filesystem mapping.
        $clean = preg_replace( '/[?#].*$/', '', $url );

        $up   = wp_upload_dir();
        $maps = [
            $up['baseurl'] => $up['basedir'],
            content_url()  => WP_CONTENT_DIR,
            site_url()     => untrailingslashit( ABSPATH ),
            home_url()     => untrailingslashit( ABSPATH ),
        ];

        $clean_ns = self::strip_scheme( $clean );
        foreach ( $maps as $base_url => $base_dir ) {
            if ( ! $base_url || ! $base_dir ) continue;
            $base_ns = self::strip_scheme( untrailingslashit( $base_url ) );
            if ( strpos( $clean_ns, $base_ns ) === 0 ) {
                $rel  = substr( $clean_ns, strlen( $base_ns ) );
                $path = $base_dir . $rel;
                $path = self::safe_realpath( $path, $base_dir );
                if ( $path && is_readable( $path ) ) {
                    $bytes = file_get_contents( $path );
                    return $bytes === false ? null : $bytes;
                }
            }
        }
        return null;
    }

    /**
     * Resolve $path and confirm it stays inside $base_dir (no ../ traversal).
     */
    private static function safe_realpath( string $path, string $base_dir ): ?string {
        $real = realpath( $path );
        if ( $real === false ) return null;
        $base = realpath( $base_dir );
        if ( $base === false ) return null;
        if ( strpos( $real, $base ) !== 0 ) return null;
        return $real;
    }

    private static function read_remote( string $url ): ?string {
        // Only http(s) and protocol-relative URLs are fetchable.
        if ( strpos( $url, '//' ) === 0 ) {
            $url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
        }
        if ( ! preg_match( '#^https?://#i', $url ) ) return null;

        $resp = wp_remote_get( $url, [
            'timeout'    => 15,
            'user-agent' => 'LG-Weekly-Digest-PDF/1.0',
            'sslverify'  => true,
        ] );
        if ( is_wp_error( $resp ) ) return null;
        if ( (int) wp_remote_retrieve_response_code( $resp ) !== 200 ) return null;

        $body = wp_remote_retrieve_body( $resp );
        return ( $body === '' ) ? null : $body;
    }

    private static function strip_scheme( string $url ): string {
        return preg_replace( '#^https?:#i', '', $url );
    }

    private static function sniff_mime( string $url, string $bytes ): string {
        // Magic-byte sniff first (most reliable), then extension fallback.
        if ( strncmp( $bytes, "\x89PNG", 4 ) === 0 ) return 'image/png';
        if ( strncmp( $bytes, "\xFF\xD8\xFF", 3 ) === 0 ) return 'image/jpeg';
        if ( strncmp( $bytes, 'GIF8', 4 ) === 0 ) return 'image/gif';
        if ( strncmp( $bytes, 'RIFF', 4 ) === 0 && substr( $bytes, 8, 4 ) === 'WEBP' ) return 'image/webp';
        if ( strncmp( $bytes, '<svg', 4 ) === 0 || stripos( substr( $bytes, 0, 256 ), '<svg' ) !== false ) return 'image/svg+xml';

        $ext = strtolower( pathinfo( preg_replace( '/[?#].*$/', '', $url ), PATHINFO_EXTENSION ) );
        $map = [
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'svg'  => 'image/svg+xml',
            'avif' => 'image/avif',
        ];
        return $map[ $ext ] ?? 'image/png';
    }

    // ── Chrome rendering ───────────────────────────────────────────────────────

    /**
     * Render HTML (with images already staged as local files in $workdir) to
     * PDF bytes via headless Chrome. The HTML and the throwaway browser profile
     * both live inside $workdir; the caller owns cleanup of $workdir.
     * @return string|WP_Error
     */
    private static function render_pdf( string $html, string $workdir ) {
        $chrome = self::find_chrome();
        if ( ! $chrome ) {
            return new WP_Error(
                'lg_wd_no_chrome',
                'Headless Chrome was not found on this server. Install Chrome-for-Testing '
                . '(see the lane handoff) or define LG_WD_CHROME_BIN.'
            );
        }
        if ( ! self::can_exec() ) {
            return new WP_Error( 'lg_wd_no_exec', 'PHP exec() is disabled; cannot run the PDF renderer.' );
        }

        $html_file = $workdir . 'page.html';
        $pdf_file  = $workdir . 'out.pdf';

        if ( false === file_put_contents( $html_file, $html ) ) {
            return new WP_Error( 'lg_wd_pdf_tmp', 'Could not write the temp HTML file.' );
        }

        // Deliberately NO --user-data-dir: pinning a persistent profile makes
        // headless Chrome run a first-run/profile init that hangs in this
        // server environment (no login session / dbus). Letting Chrome use its
        // own ephemeral profile renders in ~2s. HOME and TMPDIR point at the
        // writable work dir (Chrome writes its temp profile + config there),
        // and the whole thing is wrapped in `timeout` as a final backstop.
        // All images are local files, so no network/CDN/gate fetch is needed.
        //
        // --window-size 1000 wide lets the 960px email container reach its full
        // design width before measuring. --virtual-time-budget +
        // --run-all-compositor-stages-before-draw give the injected
        // measure-and-size-@page script time to run (after images load) before
        // Chrome prints, so the single-tall-page sizing takes effect.
        $hard_timeout = (int) apply_filters( 'lg_wd_pdf_timeout', 60 );

        $cmd = sprintf(
            'HOME=%1$s TMPDIR=%1$s timeout %4$d %2$s --headless --no-sandbox '
            . '--disable-gpu --disable-dev-shm-usage --window-size=1000,1400 '
            . '--run-all-compositor-stages-before-draw --virtual-time-budget=15000 '
            . '--no-pdf-header-footer --print-to-pdf=%3$s %5$s 2>&1',
            escapeshellarg( $workdir ),
            escapeshellarg( $chrome ),
            escapeshellarg( $pdf_file ),
            $hard_timeout,
            escapeshellarg( 'file://' . $html_file )
        );

        $out  = [];
        $code = 0;
        exec( $cmd, $out, $code );

        $pdf = is_readable( $pdf_file ) ? file_get_contents( $pdf_file ) : '';

        if ( $pdf === '' || $pdf === false ) {
            $tail = implode( ' | ', array_slice( $out, -4 ) );
            return new WP_Error( 'lg_wd_pdf_fail', 'Chrome did not produce a PDF. ' . $tail );
        }
        return $pdf;
    }

    private static function can_exec(): bool {
        if ( ! function_exists( 'exec' ) ) return false;
        $disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
        return ! in_array( 'exec', $disabled, true );
    }

    private static function rrmdir( string $dir ): void {
        if ( ! is_dir( $dir ) ) return;
        $items = scandir( $dir );
        if ( $items === false ) return;
        foreach ( $items as $item ) {
            if ( $item === '.' || $item === '..' ) continue;
            $path = $dir . '/' . $item;
            if ( is_dir( $path ) && ! is_link( $path ) ) {
                self::rrmdir( $path );
            } else {
                @unlink( $path );
            }
        }
        @rmdir( $dir );
    }
}
