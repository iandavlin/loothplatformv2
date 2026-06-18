<?php
/**
 * LG App: The Roadman Shop Planner
 *
 * Drag-and-drop shop layout planner for luthiers by J. Roadman.
 * Self-registers with the LGApps framework.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ─── Register assets ─── */

add_action( 'wp_enqueue_scripts', function() {
    $base_url = LGAPPS_URL . 'apps/shop-planner/assets/';

    wp_register_style(
        'lgapps-shop-planner',
        $base_url . 'shop-planner.css',
        [ 'lgapps-base' ],
        LGAPPS_VERSION
    );

    wp_register_script(
        'jspdf',
        'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js',
        [],
        '2.5.1',
        true
    );

    wp_register_script(
        'lgapps-shop-planner',
        $base_url . 'shop-planner.js',
        [ 'jspdf' ],
        LGAPPS_VERSION,
        true
    );
});

/* ─── Register with framework ─── */

add_action( 'plugins_loaded', function() {
    LGApps_Registry::register( 'shop-planner', [
        'title'        => 'The Roadman Shop Planner',
        'description'  => 'Drag-and-drop shop layout planner for luthiers by J. Roadman.',
        'scripts'      => [ 'jspdf', 'lgapps-shop-planner' ],
        'styles'       => [ 'lgapps-shop-planner' ],
        'render_modal' => 'lgapps_shop_planner_render_modal',
        'shortcode'    => 'shop_planner',
    ] );
}, 20 ); // priority 20 so it runs after the main plugin's plugins_loaded at default (10)

/* ─── Modal markup ─── */

function lgapps_shop_planner_render_modal() {
    ?>
    <div id="lgapps-modal-shop-planner" class="lgapps-modal" style="display:none;" role="dialog" aria-modal="true" aria-label="The Roadman Shop Planner">
      <div class="lgapps-modal-inner">

        <div class="lgapps-modal-header">
          <span class="lgapps-modal-title">The Roadman Shop Planner <a href="https://www.instagram.com/jroadman/" target="_blank" rel="noopener noreferrer" style="color:#F1DE83;font-size:13px;font-weight:400;margin-left:8px;text-decoration:none;opacity:0.85;">@jroadman</a></span>
          <button class="lgapps-close-btn" data-lgapps-close="shop-planner" aria-label="Close planner">&times;</button>
        </div>

        <div class="lgapps-controls">
          <!-- Row 1: Room + Shapes -->
          <div class="lgapps-controls-row">
            <div class="lgapps-row-left">
              <label>Room W:</label>
              <input type="number" id="lgsp-roomWidth" value="10" step="any">
              <label>H:</label>
              <input type="number" id="lgsp-roomHeight" value="10" step="any">
              <select id="lgsp-roomUnits" style="display:none;">
                <option value="ft" selected>Feet</option>
                <option value="m">Metric</option>
              </select>
              <button type="button" id="lgsp-unitsToggleBtn">Feet</button>
              <button id="lgsp-applyRoomBtn" class="lgapps-primary">Apply Room</button>
            </div>
            <div class="lgapps-row-right" style="display:flex;flex-direction:column;gap:6px;align-items:flex-end;">
              <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;">
                <label>Rect:</label>
                <input type="text" id="lgsp-itemName" placeholder="Label" size="10">
                <label>W:</label>
                <input type="number" id="lgsp-itemWidth" value="3" step="any">
                <label>D:</label>
                <input type="number" id="lgsp-itemDepth" value="2" step="any">
                <button id="lgsp-addItemBtn">Add Rect</button>
              </div>
              <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;">
                <label>Circle:</label>
                <input type="text" id="lgsp-circleName" placeholder="Label" size="10">
                <label>Dia:</label>
                <input type="number" id="lgsp-circleDiameter" value="2" step="any">
                <button id="lgsp-addCircleBtn">Add Circle</button>
              </div>
            </div>
          </div>

          <!-- Row 2: Save/Load/PDF -->
          <div class="lgapps-controls-row">
            <div class="lgapps-row-left">
              <button id="lgsp-downloadBtn">Download JSON</button>
              <button id="lgsp-uploadBtn">Upload JSON</button>
              <input type="file" id="lgsp-fileInput" accept="application/json" style="display:none;">
              <label>PDF Header:</label>
              <input type="text" id="lgsp-pdfHeader" value="The Roadman Shop Planner">
              <button id="lgsp-pdfBtn">Save PDF</button>
              <span class="lgapps-autosave-status" id="lgsp-autosaveStatus"></span>
            </div>
          </div>

          <!-- Row 3: Tools -->
          <div class="lgapps-controls-row">
            <div class="lgapps-row-left">
              <label>Tool:</label>
              <button id="lgsp-toolSelectBtn" class="lgapps-active">Select / Items</button>
              <button id="lgsp-toolWallBtn">Add Wall</button>
              <button id="lgsp-toolDoorBtn">Add Door</button>
              <button id="lgsp-toolWindowBtn">Add Window</button>
              <button id="lgsp-toolLabelBtn">Add Label</button>
              <span class="lgapps-separator">|</span>
              <button id="lgsp-undoBtn">Undo</button>
              <button id="lgsp-redoBtn">Redo</button>
              <button id="lgsp-snapToggleBtn">Snap: On</button>
            </div>
            <div class="lgapps-row-right">
              <label>Wall Type:</label>
              <label><input type="radio" name="lgsp-wallType" value="interior" checked>Interior</label>
              <label><input type="radio" name="lgsp-wallType" value="exterior">Exterior</label>
            </div>
          </div>
        </div>

        <div style="flex:1;display:flex;flex-direction:row;overflow:hidden;min-height:0;">
          <div class="lgsp-canvas-container" style="flex:1;min-width:300px;border-right:1px solid #ccc;background:#fff;display:flex;position:relative;">
            <canvas id="lgsp-layoutCanvas" tabindex="0" style="flex:1;display:block;width:100%;height:100%;outline:none;"></canvas>
          </div>

          <div class="lgapps-sidebar">
            <h3 id="lgsp-sidebarTitle">Nothing Selected</h3>
            <div id="lgsp-noSelection"><p>No item, wall, door, window, or label selected.</p></div>

            <!-- Item editor -->
            <div id="lgsp-itemEditor" style="display:none;">
              <label>Name:</label>
              <input type="text" id="lgsp-editName">
              <div id="lgsp-rectFields">
                <label>Width:</label>
                <input type="number" id="lgsp-editWidth" step="any">
                <label>Depth:</label>
                <input type="number" id="lgsp-editDepth" step="any">
                <label>Rotation (deg):</label>
                <input type="number" id="lgsp-editRotation" step="any">
              </div>
              <div id="lgsp-circleFields" style="display:none;">
                <label>Diameter:</label>
                <input type="number" id="lgsp-editDiameter" step="any">
              </div>
              <div>
                <label>Color:</label><br>
                <label><input type="radio" name="lgsp-editColor" value="blue" checked>Blue</label>
                <label><input type="radio" name="lgsp-editColor" value="green">Green</label>
                <label><input type="radio" name="lgsp-editColor" value="orange">Orange</label>
                <label><input type="radio" name="lgsp-editColor" value="gray">Gray</label>
              </div>
              <button id="lgsp-applyEditBtn" class="lgapps-primary">Apply Changes</button>
              <button id="lgsp-deleteItemBtn" class="lgapps-danger">Delete Item</button>
            </div>

            <!-- Wall editor -->
            <div id="lgsp-wallEditor" style="display:none;">
              <label>Wall Type:</label><br>
              <label><input type="radio" name="lgsp-wallEditType" value="interior">Interior</label><br>
              <label><input type="radio" name="lgsp-wallEditType" value="exterior">Exterior</label><br><br>
              <button id="lgsp-applyWallEditBtn" class="lgapps-primary">Apply Changes</button>
              <button id="lgsp-deleteWallBtn" class="lgapps-danger">Delete Wall</button>
            </div>

            <!-- Door editor -->
            <div id="lgsp-doorEditor" style="display:none;">
              <label>Door Width:</label>
              <input type="number" id="lgsp-editDoorWidth" step="any">
              <label>Swing:</label><br>
              <label><input type="radio" name="lgsp-doorSwing" value="none" checked>None</label><br>
              <label><input type="radio" name="lgsp-doorSwing" value="in-left">Inward – Left</label><br>
              <label><input type="radio" name="lgsp-doorSwing" value="in-right">Inward – Right</label><br>
              <label><input type="radio" name="lgsp-doorSwing" value="out-left">Outward – Left</label><br>
              <label><input type="radio" name="lgsp-doorSwing" value="out-right">Outward – Right</label><br><br>
              <button id="lgsp-applyDoorEditBtn" class="lgapps-primary">Apply Changes</button>
              <button id="lgsp-deleteDoorBtn" class="lgapps-danger">Delete Door</button>
            </div>

            <!-- Window editor -->
            <div id="lgsp-windowEditor" style="display:none;">
              <label>Window Width:</label>
              <input type="number" id="lgsp-editWindowWidth" step="any">
              <button id="lgsp-applyWindowEditBtn" class="lgapps-primary">Apply Changes</button>
              <button id="lgsp-deleteWindowBtn" class="lgapps-danger">Delete Window</button>
            </div>

            <!-- Label editor -->
            <div id="lgsp-labelEditor" style="display:none;">
              <label>Text:</label>
              <input type="text" id="lgsp-editLabelText">
              <label>Size:</label><br>
              <label><input type="radio" name="lgsp-labelSize" value="small">Small</label><br>
              <label><input type="radio" name="lgsp-labelSize" value="medium" checked>Medium</label><br>
              <label><input type="radio" name="lgsp-labelSize" value="large">Large</label><br><br>
              <label>Color:</label><br>
              <label><input type="radio" name="lgsp-labelColor" value="black" checked>Black</label><br>
              <label><input type="radio" name="lgsp-labelColor" value="gray">Gray</label><br>
              <label><input type="radio" name="lgsp-labelColor" value="blue">Blue</label><br><br>
              <button id="lgsp-applyLabelEditBtn" class="lgapps-primary">Apply Changes</button>
              <button id="lgsp-deleteLabelBtn" class="lgapps-danger">Delete Label</button>
            </div>

            <div class="lgapps-sidebar-footer">
              <button id="lgsp-clearAllBtn" class="lgapps-danger">Clear All &amp; Start Fresh</button>
            </div>
          </div>
        </div>

        <div class="lgapps-hint">
          Click to select, drag to move, [ ] to rotate rects. Right-click+drag = pan. Scroll = zoom.
          Walls: choose tool, click start &amp; end. Doors/windows: choose tool, click near wall.
          Your layout auto-saves to this browser. Use Download JSON to back up or transfer.
        </div>

      </div>
    </div>
    <?php
}
