/* LG Weekly Digest — Admin JS */
jQuery( function ( $ ) {

    const { nonce, ajaxUrl, adminEmail } = lgWD;

    // ── Response helper ───────────────────────────────────────────────────────

    function showResponse( msg, type = 'success' ) {
        const $r = $( '#lg-wd-response' );
        $r.removeClass( 'success error' )
          .addClass( type )
          .html( msg )
          .show();
        setTimeout( () => $r.fadeOut(), 5000 );
    }

    function setLoading( $btn, loading ) {
        if ( loading ) {
            $btn.data( 'orig', $btn.text() ).text( 'Working…' ).prop( 'disabled', true );
        } else {
            $btn.text( $btn.data( 'orig' ) || $btn.text() ).prop( 'disabled', false );
        }
    }

    // ── Save (form submit — works as standard POST, with AJAX enhancement) ──

    $( '#lg-wd-form' ).on( 'submit', function ( e ) {
        // Serialize footer links into hidden field before submit
        serializeFooterLinks();

        // Try AJAX first
        e.preventDefault();
        const $btn = $( '#lg-wd-save-btn' );
        setLoading( $btn, true );

        const formData = $( this ).serializeArray();
        formData.push( { name: 'action', value: 'lg_wd_save' } );
        formData.push( { name: 'nonce', value: nonce } );

        $.post( ajaxUrl, formData, function ( res ) {
            setLoading( $btn, false );
            if ( res.success ) {
                showResponse( '✓ ' + res.data.message, 'success' );
            } else {
                showResponse( '✗ ' + ( res.data || 'Save failed.' ), 'error' );
            }
        } ).fail( function () {
            // AJAX failed — fall back to standard form POST
            setLoading( $btn, false );
            e.target.submit();
        });
    });

    // ── WP Media uploader for header image ───────────────────────────────────

    let mediaUploader;

    $( '#lg-wd-choose-img' ).on( 'click', function ( e ) {
        e.preventDefault();

        if ( mediaUploader ) {
            mediaUploader.open();
            return;
        }

        mediaUploader = wp.media({
            title:    'Select Header Image',
            button:   { text: 'Use this image' },
            multiple: false,
            library:  { type: 'image' },
        });

        mediaUploader.on( 'select', function () {
            const attachment = mediaUploader.state().get( 'selection' ).first().toJSON();
            $( '#lg-wd-header-img-url' ).val( attachment.url );
            $( '#lg-wd-choose-img' ).text( 'Change Image' );

            const $prev = $( '.lg-wd-current-img' );
            if ( $prev.length ) {
                $prev.find( 'img' ).attr( 'src', attachment.url );
            } else {
                $( '#lg-wd-choose-img' ).before(
                    `<div class="lg-wd-current-img">
                       <img src="${attachment.url}" style="max-height:60px;max-width:100%;display:block;">
                       <button type="button" class="button button-small" id="lg-wd-remove-img">Remove</button>
                     </div>`
                );
            }
        });

        mediaUploader.open();
    });

    $( document ).on( 'click', '#lg-wd-remove-img', function () {
        $( '#lg-wd-header-img-url' ).val( '' );
        $( '.lg-wd-current-img' ).remove();
        $( '#lg-wd-choose-img' ).text( 'Choose Header Image' );
    });

    // ── CPT Registry: Add ────────────────────────────────────────────────────

    $( '#lg-wd-reg-add-btn' ).on( 'click', function ( e ) {
        e.preventDefault();
        const $btn       = $( this );
        const slug       = $( '#lg-wd-reg-slug' ).val();
        const label      = $( '#lg-wd-reg-label' ).val().trim();
        const template   = $( '#lg-wd-reg-template' ).val() || 'card';
        const sort_mode  = $( '#lg-wd-reg-sort' ).val() || 'newest';
        const tag_filter = $( '#lg-wd-reg-tag' ).val().trim();
        const taxonomy   = $( '#lg-wd-reg-taxonomy' ).val().trim() || 'post_tag';
        const max        = parseInt( $( '#lg-wd-reg-max' ).val() ) || 5;
        const excerpt_length = parseInt( $( '#lg-wd-reg-excerpt-length' ).val() ) || 20;

        if ( slug === '_header' ) {
            // Group headers only need a label
            if ( ! label ) {
                showResponse( 'Enter a label for the group header.', 'error' );
                return;
            }
        } else if ( ! slug || ! label ) {
            showResponse( 'Select a type and enter a label.', 'error' );
            return;
        }

        setLoading( $btn, true );

        $.post( ajaxUrl, {
            action:       'lg_wd_registry_add',
            nonce,
            slug,
            label,
            template,
            sort_mode,
            tag_filter,
            tag_taxonomy:   taxonomy,
            max_items:      max,
            excerpt_length: excerpt_length,
        }, function ( res ) {
            setLoading( $btn, false );
            if ( res.success ) {
                showResponse( '✓ ' + res.data.message, 'success' );
                location.reload();
            } else {
                showResponse( '✗ ' + ( res.data || 'Failed to add.' ), 'error' );
            }
        });
    });

    // ── CPT Registry: Toggle fields when header selected ────────────────────
    $( '#lg-wd-reg-slug' ).on( 'change', function () {
        const isHeader = $( this ).val() === '_header';
        // Hide template, sort, tag, taxonomy, max when adding a group header
        $( '#lg-wd-reg-template, #lg-wd-reg-sort, #lg-wd-reg-tag, #lg-wd-reg-taxonomy, #lg-wd-reg-max' )
            .closest( '.lg-wd-form-group' ).toggle( ! isHeader );
    });

    // ── CPT Registry: Remove ─────────────────────────────────────────────────

    $( document ).on( 'click', '.lg-wd-registry-remove', function ( e ) {
        e.preventDefault();
        if ( ! confirm( 'Remove this content type from the registry?' ) ) return;

        const $btn = $( this );
        const slug = $btn.data( 'slug' );

        setLoading( $btn, true );

        $.post( ajaxUrl, {
            action: 'lg_wd_registry_remove',
            nonce,
            slug,
        }, function ( res ) {
            setLoading( $btn, false );
            if ( res.success ) {
                $btn.closest( 'tr' ).fadeOut( 200, function () { $( this ).remove(); });
                showResponse( '✓ ' + res.data.message, 'success' );
            } else {
                showResponse( '✗ ' + ( res.data || 'Failed to remove.' ), 'error' );
            }
        });
    });

    // ── CPT Registry: Edit (modal) ────────────────────────────────────────────

    const $modal = $( '#lg-wd-reg-edit-modal' );

    $( document ).on( 'click', '.lg-wd-registry-edit', function ( e ) {
        e.preventDefault();
        const $row     = $( this ).closest( 'tr' );
        const isHeader = $row.data( 'is-header' ) === 1 || $row.data( 'is-header' ) === '1';
        $( '#lg-wd-edit-slug' ).val( $row.data( 'slug' ) );
        $( '#lg-wd-edit-is-header' ).val( isHeader ? '1' : '0' );
        $( '#lg-wd-edit-label' ).val( $row.data( 'label' ) );
        $( '#lg-wd-edit-template' ).val( $row.data( 'template' ) );
        $( '#lg-wd-edit-sort' ).val( $row.data( 'sort-mode' ) );
        $( '#lg-wd-edit-tag' ).val( $row.data( 'tag-filter' ) );
        $( '#lg-wd-edit-taxonomy' ).val( $row.data( 'tag-taxonomy' ) );
        $( '#lg-wd-edit-max' ).val( $row.data( 'max-items' ) );
        $( '#lg-wd-edit-excerpt-length' ).val( $row.data( 'excerpt-length' ) || 20 );
        $( '#lg-wd-edit-enabled' ).val( $row.data( 'enabled' ) );
        // Show/hide CPT-only fields for group headers
        $modal.find( '.lg-wd-edit-cpt-only' ).toggle( ! isHeader );
        $modal.css( 'display', 'flex' );
    });

    $( '#lg-wd-edit-cancel' ).on( 'click', function ( e ) { e.preventDefault(); $modal.hide(); } );
    $modal.on( 'click', function ( e ) {
        if ( e.target === this ) $modal.hide();
    });

    $( '#lg-wd-edit-save' ).on( 'click', function ( e ) {
        e.preventDefault();
        const $btn = $( this );
        const slug = $( '#lg-wd-edit-slug' ).val();
        setLoading( $btn, true );

        $.post( ajaxUrl, {
            action:       'lg_wd_registry_update',
            nonce,
            slug,
            label:        $( '#lg-wd-edit-label' ).val(),
            template:     $( '#lg-wd-edit-template' ).val(),
            sort_mode:    $( '#lg-wd-edit-sort' ).val(),
            tag_filter:   $( '#lg-wd-edit-tag' ).val(),
            tag_taxonomy: $( '#lg-wd-edit-taxonomy' ).val(),
            max_items:      $( '#lg-wd-edit-max' ).val(),
            excerpt_length: $( '#lg-wd-edit-excerpt-length' ).val(),
            enabled:        $( '#lg-wd-edit-enabled' ).val(),
        }, function ( res ) {
            setLoading( $btn, false );
            if ( res.success ) {
                showResponse( '✓ ' + res.data.message, 'success' );
                $modal.hide();
                location.reload();
            } else {
                showResponse( '✗ ' + ( res.data || 'Failed to update.' ), 'error' );
            }
        });
    });

    // ── CPT Registry: Drag-and-drop reorder ───────────────────────────────────

    if ( $.fn.sortable && $( '#lg-wd-registry-sortable' ).length ) {
        $( '#lg-wd-registry-sortable' ).sortable({
            handle: '.lg-wd-drag-handle',
            axis: 'y',
            helper: function ( e, tr ) {
                // Preserve column widths while dragging
                const $originals = tr.children();
                const $helper = tr.clone();
                $helper.children().each( function ( i ) {
                    $( this ).width( $originals.eq( i ).width() );
                });
                return $helper;
            },
            placeholder: 'lg-wd-registry-placeholder',
            update: function () {
                const order = [];
                $( '#lg-wd-registry-sortable tr[data-slug]' ).each( function () {
                    order.push( $( this ).data( 'slug' ) );
                });
                $.post( ajaxUrl, {
                    action: 'lg_wd_registry_reorder',
                    nonce,
                    order,
                }, function ( res ) {
                    if ( res.success ) {
                        showResponse( '✓ Order saved.', 'success' );
                    }
                });
            },
        });
    }

    // ── Footer Links Repeater ────────────────────────────────────────────────

    const $footerList = $( '#lg-wd-footer-links-list' );
    const $footerJson = $( '#lg-wd-footer-links-json' );

    function renderFooterLinks() {
        if ( ! $footerList.length ) return;

        let links = [];
        try { links = JSON.parse( $footerJson.val() || '[]' ); } catch ( e ) {}
        if ( ! Array.isArray( links ) ) links = [];

        $footerList.empty();
        links.forEach( function ( link, i ) {
            $footerList.append( footerLinkRow( link.label || '', link.url || '' ) );
        });
    }

    function footerLinkRow( label, url ) {
        return `<div class="lg-wd-footer-link-row">
            <input type="text" class="lg-wd-input lg-wd-fl-label" value="${escAttr( label )}" placeholder="Label" style="flex:1;">
            <input type="text" class="lg-wd-input lg-wd-fl-url" value="${escAttr( url )}" placeholder="https://..." style="flex:2;">
            <button type="button" class="button button-small lg-wd-fl-remove">✕</button>
        </div>`;
    }

    function escAttr( str ) {
        return String( str ).replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' ).replace( /</g, '&lt;' );
    }

    function serializeFooterLinks() {
        if ( ! $footerList.length ) return;
        const links = [];
        $footerList.find( '.lg-wd-footer-link-row' ).each( function () {
            const label = $( this ).find( '.lg-wd-fl-label' ).val().trim();
            const url   = $( this ).find( '.lg-wd-fl-url' ).val().trim();
            if ( label && url ) {
                links.push( { label, url } );
            }
        });
        $footerJson.val( JSON.stringify( links ) );
    }

    $( '#lg-wd-footer-link-add' ).on( 'click', function () {
        $footerList.append( footerLinkRow( '', '' ) );
    });

    $( document ).on( 'click', '.lg-wd-fl-remove', function () {
        $( this ).closest( '.lg-wd-footer-link-row' ).remove();
    });

    renderFooterLinks();

    // ── UTM Toggle ───────────────────────────────────────────────────────────

    $( '[name="utm_enabled"]' ).on( 'change', function () {
        $( '#lg-wd-utm-fields' ).toggle( this.checked );
    });

});
