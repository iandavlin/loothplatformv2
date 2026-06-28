/* LG Weekly Digest — Compose Page JS */
jQuery( function ( $ ) {

    const { nonce, ajaxUrl } = lgWDCompose;

    // ── Shared TinyMCE config for HTML block editors ────────────────────────

    const htmlBlockEditorSettings = {
        tinymce: {
            wpautop: true,
            paste_data_images: true,
            plugins: 'charmap,colorpicker,hr,lists,paste,tabfocus,textcolor,wordpress,wpautoresize,wpeditimage,wplink,wptextpattern',
            toolbar1: 'formatselect,bold,italic,underline,strikethrough,separator,bullist,numlist,separator,blockquote,hr,separator,alignleft,aligncenter,alignright,separator,link,unlink,separator,wp_more,wp_adv',
            toolbar2: 'fontsizeselect,forecolor,backcolor,separator,pastetext,removeformat,separator,charmap,separator,outdent,indent,separator,undo,redo,separator,wp_help',
            block_formats: 'Paragraph=p;Heading 1=h1;Heading 2=h2;Heading 3=h3;Heading 4=h4;Preformatted=pre',
            content_style: 'body { font-family: Georgia, "Times New Roman", serif; font-size: 15px; color: #5C4E3A; line-height: 1.65; }',
        },
        quicktags: true,
        mediaButtons: true,
    };

    // ── Re-init PHP-rendered editors that failed to activate ─────────────────
    // wp_editor() sometimes fails to fully initialize TinyMCE on custom admin
    // pages. After the page is fully loaded, check each editor and re-init if
    // the TinyMCE instance is missing.

    $( window ).on( 'load', function () {
        $( '.lg-wd-compose-html-block' ).each( function () {
            const edId = $( this ).data( 'editor-id' );
            if ( ! edId || typeof wp === 'undefined' || ! wp.editor ) return;

            const existing = typeof tinyMCE !== 'undefined' && tinyMCE.get( edId );
            if ( ! existing ) {
                // TinyMCE failed to init — remove stale state and re-initialize
                wp.editor.remove( edId );
                wp.editor.initialize( edId, htmlBlockEditorSettings );
            }
        });
    });

    // ── Helpers ──────────────────────────────────────────────────────────────

    function showResponse( msg, type ) {
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

    /**
     * Collect the current state of all sections from the DOM.
     * Returns a JSON-serializable array.
     */
    function collectSections() {
        // Sync all TinyMCE editors to their textareas before collecting
        if ( typeof tinyMCE !== 'undefined' ) {
            tinyMCE.triggerSave();
        }

        const sections = [];
        $( '#lg-wd-sections-container .lg-wd-compose-section' ).each( function () {
            const $sec = $( this );
            const postIds = [];
            const manualItems = [];

            // Only include checked posts
            $sec.find( '.lg-wd-post-item:not(.lg-wd-manual-item) input[type="checkbox"]:checked' ).each( function () {
                postIds.push( parseInt( $( this ).data( 'post-id' ) ) );
            });

            // Collect checked manual (external) items
            $sec.find( '.lg-wd-manual-item input[type="checkbox"]:checked' ).each( function () {
                const $li = $( this ).closest( '.lg-wd-manual-item' );
                manualItems.push({
                    title:       $li.data( 'manual-title' ),
                    url:         $li.data( 'manual-url' ),
                    thumb_url:   $li.data( 'manual-thumb' ),
                    excerpt:     $li.data( 'manual-excerpt' ),
                    author_name: $li.data( 'manual-author-name' ),
                    author_url:  $li.data( 'manual-author-url' ),
                });
            });

            const isHeader    = $sec.data( 'is-header' ) === 1 || $sec.data( 'is-header' ) === '1';
            const isHtmlBlock = $sec.data( 'section-template' ) === 'html-block';

            const entry = {
                key:          $sec.data( 'section-key' ),
                label:        $sec.find( '.lg-wd-compose-section-header strong' ).text().replace( /^📌\s*/, '' ),
                is_header:    isHeader,
                template:     $sec.data( 'section-template' ),
                slug:         $sec.data( 'section-slug' ),
                post_ids:     isHeader || isHtmlBlock ? [] : postIds,
                manual_items: isHeader || isHtmlBlock ? [] : manualItems,
            };

            if ( isHtmlBlock ) {
                const editorId = $sec.data( 'editor-id' );
                // Try TinyMCE first (visual mode), fall back to textarea (text mode)
                const editor = typeof tinyMCE !== 'undefined' ? tinyMCE.get( editorId ) : null;
                entry.html_content = editor ? editor.getContent() : $( '#' + editorId ).val() || '';
                entry.html_header  = $sec.find( '.lg-wd-html-header-input' ).val() || '';
            }

            sections.push( entry );
        });
        return sections;
    }

    // ── Section sortable ─────────────────────────────────────────────────────

    $( '#lg-wd-sections-container' ).sortable({
        handle: '.lg-wd-drag-handle',
        axis: 'y',
        tolerance: 'pointer',
        items: '.lg-wd-compose-section',
        placeholder: 'lg-wd-sortable-placeholder',
        cancel: 'input,textarea,button,select,option,.mce-container,.wp-editor-wrap',
        start: function ( e, ui ) {
            // TinyMCE iframes break during drag — remove editors temporarily
            ui.item.find( '.lg-wd-compose-html-block' ).addBack().filter( '.lg-wd-compose-html-block' ).each( function () {
                const edId = $( this ).data( 'editor-id' );
                if ( edId && typeof tinyMCE !== 'undefined' && tinyMCE.get( edId ) ) {
                    tinyMCE.get( edId ).save();
                    wp.editor.remove( edId );
                }
            });
        },
        stop: function ( e, ui ) {
            // Re-initialize TinyMCE editors after drag completes
            ui.item.find( '.lg-wd-compose-html-block' ).addBack().filter( '.lg-wd-compose-html-block' ).each( function () {
                const edId = $( this ).data( 'editor-id' );
                if ( edId && typeof wp !== 'undefined' && wp.editor ) {
                    wp.editor.initialize( edId, htmlBlockEditorSettings );
                }
            });
        },
    });

    // ── Issue Picker ─────────────────────────────────────────────────────────

    $( '#lg-wd-issue-picker' ).on( 'change', function () {
        const url = $( this ).val();
        if ( url ) {
            window.location.href = url;
        }
    });

    // ── New Issue ─────────────────────────────────────────────────────────────

    $( document ).on( 'click', '#lg-wd-new-issue-btn, #lg-wd-new-issue-btn-empty', function () {
        const $btn = $( this );
        setLoading( $btn, true );

        $.post( ajaxUrl, { action: 'lg_wd_compose_new_issue', nonce }, function ( res ) {
            setLoading( $btn, false );
            if ( res.success ) {
                window.location.href = res.data.redirect;
            } else {
                showResponse( '✗ ' + ( res.data || 'Failed to create issue.' ), 'error' );
            }
        });
    });

    // ── Auto-Populate ────────────────────────────────────────────────────────

    $( '#lg-wd-populate-btn' ).on( 'click', function () {
        const $btn     = $( this );
        const dateFrom = $( '#lg-wd-date-from' ).val();
        const dateTo   = $( '#lg-wd-date-to' ).val();

        if ( ! dateFrom || ! dateTo ) {
            showResponse( 'Set both date fields first.', 'error' );
            return;
        }

        setLoading( $btn, true );

        $.post( ajaxUrl, {
            action:    'lg_wd_compose_populate',
            nonce,
            date_from: dateFrom,
            date_to:   dateTo,
        }, function ( res ) {
            setLoading( $btn, false );
            if ( res.success ) {
                $( '#lg-wd-sections-container' ).html( res.data.html );
                initPostSortable();
                showResponse( '✓ ' + res.data.message, 'success' );
            } else {
                showResponse( '✗ ' + ( res.data || 'Populate failed.' ), 'error' );
            }
        });
    });

    // ── Save Draft ───────────────────────────────────────────────────────────

    $( '#lg-wd-save-draft-btn' ).on( 'click', function () {
        const $btn    = $( this );
        const issueId = $( '#lg-wd-issue-id' ).val();
        if ( ! issueId ) return;

        setLoading( $btn, true );

        $.post( ajaxUrl, {
            action:    'lg_wd_compose_save',
            nonce,
            issue_id:  issueId,
            title:     $( '#lg-wd-issue-title' ).val(),
            date_from: $( '#lg-wd-date-from' ).val(),
            date_to:   $( '#lg-wd-date-to' ).val(),
            sections:  JSON.stringify( collectSections() ),
        }, function ( res ) {
            setLoading( $btn, false );
            if ( res.success ) {
                showResponse( '✓ ' + res.data.message, 'success' );
            } else {
                showResponse( '✗ ' + ( res.data || 'Save failed.' ), 'error' );
            }
        });
    });

    // ── Preview ──────────────────────────────────────────────────────────────

    $( '#lg-wd-preview-btn' ).on( 'click', function () {
        const $btn    = $( this );
        const issueId = $( '#lg-wd-issue-id' ).val();
        if ( ! issueId ) return;

        setLoading( $btn, true );

        $.post( ajaxUrl, {
            action:   'lg_wd_compose_preview',
            nonce,
            issue_id: issueId,
            sections: JSON.stringify( collectSections() ),
            date_from: $( '#lg-wd-date-from' ).val(),
            date_to:   $( '#lg-wd-date-to' ).val(),
        }, function ( res ) {
            setLoading( $btn, false );
            if ( res.success ) {
                const iframe = document.getElementById( 'lg-wd-preview-frame' );
                const doc = iframe.contentDocument || iframe.contentWindow.document;
                doc.open();
                doc.write( res.data.html );
                doc.close();
                // Suppress Chrome image download button in preview
                doc.querySelectorAll( 'img' ).forEach( function( img ) {
                    var wrap = doc.createElement( 'span' );
                    wrap.style.cssText = 'position:relative;display:inline-block;';
                    var overlay = doc.createElement( 'span' );
                    overlay.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:100%;z-index:1;';
                    img.parentNode.insertBefore( wrap, img );
                    wrap.appendChild( img );
                    wrap.appendChild( overlay );
                });
                $( '#lg-wd-preview-modal' ).show();
                $( 'body' ).css( 'overflow', 'hidden' );
            } else {
                showResponse( '✗ ' + ( res.data || 'Preview failed.' ), 'error' );
            }
        });
    });

    // Close modal
    $( document ).on( 'click', '.lg-wd-modal-overlay, .lg-wd-modal-close', function () {
        $( '#lg-wd-preview-modal, #lg-wd-custom-section-modal, #lg-wd-external-card-modal' ).hide();
        $( 'body' ).css( 'overflow', '' );
    });

    // ── Test Send ────────────────────────────────────────────────────────────

    $( '#lg-wd-test-btn' ).on( 'click', function () {
        const $btn    = $( this );
        const issueId = $( '#lg-wd-issue-id' ).val();
        const to      = $( '#lg-wd-test-email' ).val().trim();

        if ( ! issueId || ! to ) {
            showResponse( 'Enter a test email address.', 'error' );
            return;
        }

        setLoading( $btn, true );

        $.post( ajaxUrl, {
            action:   'lg_wd_compose_test_send',
            nonce,
            issue_id: issueId,
            to,
            sections: JSON.stringify( collectSections() ),
            date_from: $( '#lg-wd-date-from' ).val(),
            date_to:   $( '#lg-wd-date-to' ).val(),
        }, function ( res ) {
            setLoading( $btn, false );
            if ( res.success ) {
                showResponse( '✓ ' + res.data.message, 'success' );
            } else {
                showResponse( '✗ ' + ( res.data || 'Test send failed.' ), 'error' );
            }
        });
    });

    // ── Send Now ─────────────────────────────────────────────────────────────

    $( '#lg-wd-send-btn' ).on( 'click', function () {
        if ( ! confirm( 'Send this issue to all subscribers? This cannot be undone.' ) ) return;

        const $btn    = $( this );
        const issueId = $( '#lg-wd-issue-id' ).val();
        if ( ! issueId ) return;

        setLoading( $btn, true );

        $.post( ajaxUrl, {
            action:   'lg_wd_compose_send',
            nonce,
            issue_id: issueId,
            sections: JSON.stringify( collectSections() ),
            date_from: $( '#lg-wd-date-from' ).val(),
            date_to:   $( '#lg-wd-date-to' ).val(),
        }, function ( res ) {
            setLoading( $btn, false );
            if ( res.success ) {
                showResponse( '✓ ' + res.data.message, 'success' );
                $( '#lg-wd-send-btn' ).remove(); // Remove send button after successful send
            } else {
                showResponse( '✗ ' + ( res.data || 'Send failed.' ), 'error' );
            }
        }).fail( function ( xhr ) {
            setLoading( $btn, false );
            showResponse( '✗ Server error (' + xhr.status + '): ' + xhr.statusText, 'error' );
            console.error( 'Send AJAX failed:', xhr.responseText );
        });
    });

    // ── Reset to Draft ────────────────────────────────────────────────────

    $( '#lg-wd-reset-draft-btn' ).on( 'click', function () {
        if ( ! confirm( 'Reset this issue to draft status?' ) ) return;

        const $btn    = $( this );
        const issueId = $( '#lg-wd-issue-id' ).val();
        if ( ! issueId ) return;

        setLoading( $btn, true );

        $.post( ajaxUrl, {
            action:   'lg_wd_compose_reset_draft',
            nonce,
            issue_id: issueId,
        }, function ( res ) {
            setLoading( $btn, false );
            if ( res.success && res.data.redirect ) {
                window.location.href = res.data.redirect;
            } else {
                showResponse( '✗ ' + ( res.data || 'Reset failed.' ), 'error' );
            }
        }).fail( function ( xhr ) {
            setLoading( $btn, false );
            showResponse( '✗ Server error (' + xhr.status + ')', 'error' );
        });
    });

    // ── Clear Draft ─────────────────────────────────────────────────────────

    $( '#lg-wd-clear-draft-btn' ).on( 'click', function () {
        if ( ! confirm( 'Delete this draft and start fresh?' ) ) return;

        const issueId = $( '#lg-wd-issue-id' ).val();
        if ( issueId ) {
            $.post( ajaxUrl, {
                action:   'lg_wd_compose_delete_draft',
                nonce,
                issue_id: issueId,
            }, function ( res ) {
                if ( res.success && res.data.redirect ) {
                    window.location.href = res.data.redirect;
                } else {
                    showResponse( '✗ ' + ( res.data || 'Delete failed.' ), 'error' );
                }
            });
        }
    });

    // ── Remove section ───────────────────────────────────────────────────────

    $( document ).on( 'click', '.lg-wd-remove-section-btn', function () {
        if ( confirm( 'Remove this section from the issue?' ) ) {
            const $section = $( this ).closest( '.lg-wd-compose-section' );
            // Clean up TinyMCE editor if this is an HTML block
            const editorId = $section.data( 'editor-id' );
            if ( editorId && typeof wp !== 'undefined' && wp.editor ) {
                wp.editor.remove( editorId );
            }
            $section.fadeOut( 200, function () {
                $( this ).remove();
            });
        }
    });

    // ── Remove individual post ───────────────────────────────────────────────

    $( document ).on( 'click', '.lg-wd-post-remove', function () {
        $( this ).closest( '.lg-wd-post-item' ).fadeOut( 200, function () {
            const $section = $( this ).closest( '.lg-wd-compose-section' );
            $( this ).remove();
            updateSectionCount( $section );
        });
    });

    // ── Checkbox toggle updates count ────────────────────────────────────────

    $( document ).on( 'change', '.lg-wd-post-item input[type="checkbox"]', function () {
        const $section = $( this ).closest( '.lg-wd-compose-section' );
        updateSectionCount( $section );
        $( this ).closest( '.lg-wd-post-item' ).toggleClass( 'lg-wd-post-excluded', ! this.checked );
    });

    // ── Add Section ──────────────────────────────────────────────────────────

    $( '#lg-wd-add-section-btn' ).on( 'click', function () {
        const $select = $( '#lg-wd-add-section-select' );
        const val     = $select.val();

        if ( ! val ) {
            showResponse( 'Select a section type first.', 'error' );
            return;
        }

        if ( val === '__custom__' ) {
            $( '#lg-wd-custom-section-modal' ).show();
            return;
        }

        const $opt     = $select.find( ':selected' );
        const label    = $opt.data( 'label' );
        const template = $opt.data( 'template' ) || 'card';
        const isHeader = $opt.data( 'is-header' ) === 1 || $opt.data( 'is-header' ) === '1';
        const key      = val.replace( /[^a-z0-9_]/g, '_' );

        if ( isHeader ) {
            addHeaderDivider( key, label, val );
        } else {
            addEmptySection( key, label, template, val );
        }
        $select.val( '' );
    });

    // ── Quick Header (on-the-fly) ───────────────────────────────────────

    $( '#lg-wd-quick-header-btn' ).on( 'click', function () {
        const label = $( '#lg-wd-quick-header-input' ).val().trim();
        if ( ! label ) {
            showResponse( 'Enter a header label.', 'error' );
            return;
        }
        const slug = '_header_' + label.toLowerCase().replace( /[^a-z0-9]+/g, '_' ).replace( /^_|_$/g, '' );
        const key  = slug.replace( /[^a-z0-9_]/g, '_' );
        addHeaderDivider( key, label, slug );
        $( '#lg-wd-quick-header-input' ).val( '' );
        showResponse( '✓ Header "' + label + '" added.', 'success' );
    });

    $( '#lg-wd-quick-header-input' ).on( 'keydown', function ( e ) {
        if ( e.key === 'Enter' ) { e.preventDefault(); $( '#lg-wd-quick-header-btn' ).click(); }
    });

    $( '#lg-wd-custom-section-add' ).on( 'click', function () {
        const label = $( '#lg-wd-custom-label' ).val().trim();
        const key   = $( '#lg-wd-custom-key' ).val().trim().replace( /[^a-z0-9_]/g, '_' );

        if ( ! label || ! key ) {
            showResponse( 'Both label and key are required.', 'error' );
            return;
        }

        addEmptySection( key, label, 'card', '' );
        $( '#lg-wd-custom-label, #lg-wd-custom-key' ).val( '' );
        $( '#lg-wd-custom-section-modal' ).hide();
    });

    function addHeaderDivider( key, label, slug ) {
        const html = `
            <div class="lg-wd-compose-section lg-wd-compose-header" data-section-key="${key}" data-section-template="header" data-section-slug="${slug}" data-is-header="1">
              <div class="lg-wd-compose-section-header" style="background:#2B2318;color:#ECB351;border-left:4px solid #ECB351;">
                <span class="lg-wd-drag-handle" title="Drag to reorder" style="color:#ECB351;">⠿</span>
                <strong style="color:#ECB351;">📌 ${label}</strong>
                <span class="lg-wd-section-type-badge" style="background:#ECB351;color:#2B2318;">GROUP HEADER</span>
                <button type="button" class="button button-small lg-wd-remove-section-btn" title="Remove section" style="color:#ECB351;">✕</button>
              </div>
            </div>
        `;
        $( '#lg-wd-sections-container' ).append( html );
    }

    function addEmptySection( key, label, template, slug ) {
        const html = `
            <div class="lg-wd-compose-section" data-section-key="${key}" data-section-template="${template}" data-section-slug="${slug}" data-is-header="0">
              <div class="lg-wd-compose-section-header">
                <span class="lg-wd-drag-handle" title="Drag to reorder">⠿</span>
                <strong>${label}</strong>
                <span class="lg-wd-section-type-badge">${template.toUpperCase()}</span>
                <span class="lg-wd-section-count">0 items</span>
                <button type="button" class="button button-small lg-wd-add-external-btn" title="Add external card">+ External</button>
                <button type="button" class="button button-small lg-wd-remove-section-btn" title="Remove section">✕</button>
              </div>
              <div class="lg-wd-compose-section-body">
                <p class="lg-wd-empty-section">No posts in this section. Use search or + External to add items.</p>
              </div>
            </div>
        `;
        $( '#lg-wd-sections-container' ).append( html );
    }

    // ── HTML Block ──────────────────────────────────────────────────────────

    $( '#lg-wd-add-html-block-btn' ).on( 'click', function () {
        const key      = 'html_block_' + Date.now();
        const editorId = 'lg_wd_html_' + key;
        const label    = 'HTML Block';
        addHtmlBlock( key, editorId, label, '' );
        showResponse( '✓ HTML block added.', 'success' );
    });

    function addHtmlBlock( key, editorId, label, content, header ) {
        header = header || '';
        const esc = str => $( '<div>' ).text( str ).html();
        const html = `
            <div class="lg-wd-compose-section lg-wd-compose-html-block" data-section-key="${key}" data-section-template="html-block" data-section-slug="" data-is-header="0" data-editor-id="${editorId}">
              <div class="lg-wd-compose-section-header">
                <span class="lg-wd-drag-handle" title="Drag to reorder">⠿</span>
                <strong>${esc(label)}</strong>
                <span class="lg-wd-section-type-badge" style="background:#87986A;color:#fff;">HTML</span>
                <button type="button" class="button button-small lg-wd-remove-section-btn" title="Remove section">✕</button>
              </div>
              <div class="lg-wd-compose-section-body" style="padding:12px;">
                <div class="lg-wd-html-header-row" style="margin-bottom:10px;">
                  <label style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:#87986A;">Subsection Header <span style="font-weight:400;color:#999;">(optional)</span></label>
                  <input type="text" class="lg-wd-html-header-input" value="${esc(header)}" placeholder="e.g. A Note From The Editor" style="width:100%;margin-top:4px;padding:6px 10px;border:1px solid #ccc;border-radius:4px;" />
                </div>
                <textarea id="${editorId}" class="lg-wd-html-content" rows="8">${esc(content)}</textarea>
              </div>
            </div>
        `;
        $( '#lg-wd-sections-container' ).append( html );

        // Initialize WP editor (TinyMCE + quicktags) — defer to let DOM settle
        setTimeout( function () {
            if ( typeof wp !== 'undefined' && wp.editor ) {
                wp.editor.initialize( editorId, htmlBlockEditorSettings );
            }
        }, 100 );
    }

    // ── Archive Search ───────────────────────────────────────────────────────

    $( '#lg-wd-archive-search-btn' ).on( 'click', doSearch );
    $( '#lg-wd-archive-search' ).on( 'keydown', function ( e ) {
        if ( e.key === 'Enter' ) { e.preventDefault(); doSearch(); }
    });

    function doSearch() {
        const term = $( '#lg-wd-archive-search' ).val().trim();
        if ( term.length < 2 ) {
            showResponse( 'Type at least 2 characters.', 'error' );
            return;
        }

        const $btn = $( '#lg-wd-archive-search-btn' );
        setLoading( $btn, true );

        $.post( ajaxUrl, {
            action: 'lg_wd_compose_search',
            nonce,
            term,
        }, function ( res ) {
            setLoading( $btn, false );
            if ( res.success ) {
                renderSearchResults( res.data.results );
            } else {
                showResponse( '✗ ' + ( res.data || 'Search failed.' ), 'error' );
            }
        });
    }

    function renderSearchResults( results ) {
        const $container = $( '#lg-wd-search-results' );
        const $tbody     = $( '#lg-wd-search-results-table tbody' );
        $tbody.empty();

        if ( ! results.length ) {
            $tbody.append( '<tr><td colspan="4" style="color:#aaa;">No results found.</td></tr>' );
        } else {
            results.forEach( function ( r ) {
                // Build a dropdown of current sections to add to
                let sectionOpts = '';
                $( '#lg-wd-sections-container .lg-wd-compose-section' ).each( function () {
                    const key   = $( this ).data( 'section-key' );
                    const label = $( this ).find( '.lg-wd-compose-section-header strong' ).text();
                    sectionOpts += `<option value="${key}">${label}</option>`;
                });

                $tbody.append( `
                    <tr>
                      <td>${r.title}</td>
                      <td>${r.type_label}</td>
                      <td>${r.date}</td>
                      <td>
                        <select class="lg-wd-add-to-section" data-post-id="${r.id}" data-title="${r.title}" data-type="${r.type_label}" data-date="${r.date}">
                          ${sectionOpts}
                        </select>
                        <button class="button button-small lg-wd-add-post-btn" data-post-id="${r.id}">+ Add</button>
                      </td>
                    </tr>
                ` );
            });
        }

        $container.show();
    }

    // Add post from search results to a section
    $( document ).on( 'click', '.lg-wd-add-post-btn', function () {
        const $row      = $( this ).closest( 'tr' );
        const $select   = $row.find( '.lg-wd-add-to-section' );
        const sectionKey = $select.val();
        const postId    = parseInt( $( this ).data( 'post-id' ) );
        const title     = $select.data( 'title' );
        const typeLabel = $select.data( 'type' );
        const date      = $select.data( 'date' );

        if ( ! sectionKey ) return;

        // Find the section and add the post
        const $section = $( `.lg-wd-compose-section[data-section-key="${sectionKey}"]` );
        if ( ! $section.length ) return;

        // Check if already present
        if ( $section.find( `.lg-wd-post-item[data-post-id="${postId}"]` ).length ) {
            showResponse( 'Post already in this section.', 'error' );
            return;
        }

        // Remove empty message if present
        $section.find( '.lg-wd-empty-section' ).remove();

        // Add or create the list
        let $list = $section.find( '.lg-wd-post-list' );
        if ( ! $list.length ) {
            $section.find( '.lg-wd-compose-section-body' ).append(
                `<ul class="lg-wd-post-list" data-section-key="${sectionKey}"></ul>`
            );
            $list = $section.find( '.lg-wd-post-list' );
        }

        $list.append( `
            <li class="lg-wd-post-item" data-post-id="${postId}">
              <label class="lg-wd-post-check">
                <input type="checkbox" checked data-post-id="${postId}">
                <span class="lg-wd-post-title">${title}</span>
              </label>
              <span class="lg-wd-post-meta">
                <span class="lg-wd-post-type">${typeLabel}</span>
                <span class="lg-wd-post-date">${date}</span>
              </span>
              <button type="button" class="lg-wd-post-remove" title="Remove" data-post-id="${postId}">✕</button>
            </li>
        ` );

        updateSectionCount( $section );
        $row.fadeOut( 200 );
        showResponse( '✓ Added to ' + $section.find( 'strong' ).first().text(), 'success' );
    });

    // ── External Card ────────────────────────────────────────────────────────

    // Open external card modal for a section
    $( document ).on( 'click', '.lg-wd-add-external-btn', function () {
        const sectionKey = $( this ).closest( '.lg-wd-compose-section' ).data( 'section-key' );
        $( '#lg-wd-ext-target-section' ).val( sectionKey );
        // Clear form
        $( '#lg-wd-ext-title, #lg-wd-ext-url, #lg-wd-ext-thumb, #lg-wd-ext-excerpt, #lg-wd-ext-author-name, #lg-wd-ext-author-url' ).val( '' );
        $( '#lg-wd-external-card-modal' ).show();
    });

    // Add external card to section
    $( '#lg-wd-ext-add-btn' ).on( 'click', function () {
        const title      = $( '#lg-wd-ext-title' ).val().trim();
        const url        = $( '#lg-wd-ext-url' ).val().trim();
        const thumbUrl   = $( '#lg-wd-ext-thumb' ).val().trim();
        const excerpt    = $( '#lg-wd-ext-excerpt' ).val().trim();
        const authorName = $( '#lg-wd-ext-author-name' ).val().trim();
        const authorUrl  = $( '#lg-wd-ext-author-url' ).val().trim();
        const sectionKey = $( '#lg-wd-ext-target-section' ).val();

        if ( ! title || ! url ) {
            showResponse( 'Title and Post URL are required.', 'error' );
            return;
        }

        const $section = $( `.lg-wd-compose-section[data-section-key="${sectionKey}"]` );
        if ( ! $section.length ) return;

        // Remove empty message
        $section.find( '.lg-wd-empty-section' ).remove();

        // Ensure list exists
        let $list = $section.find( '.lg-wd-post-list' );
        if ( ! $list.length ) {
            $section.find( '.lg-wd-compose-section-body' ).append(
                `<ul class="lg-wd-post-list" data-section-key="${sectionKey}"></ul>`
            );
            $list = $section.find( '.lg-wd-post-list' );
        }

        // Escape for data attrs
        const esc = str => $( '<div>' ).text( str ).html();

        $list.append( `
            <li class="lg-wd-post-item lg-wd-manual-item"
                data-manual-title="${esc(title)}"
                data-manual-url="${esc(url)}"
                data-manual-thumb="${esc(thumbUrl)}"
                data-manual-excerpt="${esc(excerpt)}"
                data-manual-author-name="${esc(authorName)}"
                data-manual-author-url="${esc(authorUrl)}">
              <label class="lg-wd-post-check">
                <input type="checkbox" checked>
                <span class="lg-wd-post-title">${esc(title)}</span>
              </label>
              <span class="lg-wd-post-meta">
                <span class="lg-wd-post-type lg-wd-external-badge">EXTERNAL</span>
                <span class="lg-wd-post-date">${esc(authorName)}</span>
              </span>
              <button type="button" class="lg-wd-post-remove lg-wd-manual-remove" title="Remove">✕</button>
            </li>
        ` );

        updateSectionCount( $section );
        $( '#lg-wd-external-card-modal' ).hide();
        showResponse( '✓ External card added to ' + $section.find( 'strong' ).first().text(), 'success' );
    });

    // Remove manual item
    $( document ).on( 'click', '.lg-wd-manual-remove', function () {
        const $section = $( this ).closest( '.lg-wd-compose-section' );
        $( this ).closest( '.lg-wd-manual-item' ).fadeOut( 200, function () {
            $( this ).remove();
            updateSectionCount( $section );
        });
    });

    // ── Update section count to include manual items ──────────────────────────

    function updateSectionCount( $section ) {
        const wpCount     = $section.find( '.lg-wd-post-item:not(.lg-wd-manual-item) input[type="checkbox"]:checked' ).length;
        const manualCount = $section.find( '.lg-wd-manual-item input[type="checkbox"]:checked' ).length;
        $section.find( '.lg-wd-section-count' ).text( ( wpCount + manualCount ) + ' items' );
    }

    // ── Post sortable within sections ────────────────────────────────────────

    function initPostSortable() {
        $( '.lg-wd-post-list' ).sortable({
            connectWith: '.lg-wd-post-list',
            tolerance: 'pointer',
            placeholder: 'lg-wd-post-placeholder',
        });
    }

    initPostSortable();

});
