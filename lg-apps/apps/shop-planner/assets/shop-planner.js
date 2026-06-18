/**
 * The Roadman Shop Planner v1.0.0
 * Canvas-based shop layout planner running inside a WordPress modal.
 * Auto-saves to localStorage, supports JSON import/export and PDF.
 *
 * All DOM IDs are prefixed with "lgsp-" to avoid collisions.
 */
(function(){
"use strict";

const STORAGE_KEY = "lgsp_shop_layout";
let initialized = false;

/* ========== Feature Gating ========== */

function isGated(feature) {
  var g = window.lgapps_gating;
  if (!g) return false;
  if (g.logged_in) return false; // logged-in users never gated
  return g.gated_features && g.gated_features.indexOf(feature) !== -1;
}

function showGatePrompt(featureName) {
  var g = window.lgapps_gating || {};
  var loginUrl = g.login_url || '/wp-login.php';
  var registerUrl = g.register_url || '/wp-login.php?action=register';

  var overlay = document.createElement('div');
  overlay.style.cssText = 'position:fixed;inset:0;z-index:9999999;background:rgba(26,30,18,0.8);display:flex;align-items:center;justify-content:center;';
  overlay.innerHTML =
    '<div style="background:#fff;border-radius:8px;padding:32px;max-width:400px;text-align:center;font-family:system-ui,sans-serif;box-shadow:0 8px 32px rgba(0,0,0,0.3);">' +
      '<h3 style="margin:0 0 8px;color:#1A1E12;font-size:18px;">Members Only Feature</h3>' +
      '<p style="color:#666;font-size:14px;margin:0 0 20px;line-height:1.5;">' +
        featureName + ' is available to Looth Group members. Sign up free to save your shop layouts!' +
      '</p>' +
      '<div style="display:flex;gap:10px;justify-content:center;">' +
        '<a href="' + registerUrl + '" style="padding:10px 20px;background:#ECB351;color:#1A1E12;border-radius:5px;text-decoration:none;font-weight:600;font-size:14px;">Join Free</a>' +
        '<a href="' + loginUrl + '" style="padding:10px 20px;background:#fff;color:#1A1E12;border:1px solid #b5a880;border-radius:5px;text-decoration:none;font-size:14px;">Log In</a>' +
      '</div>' +
      '<button class="lgapps-gate-dismiss" style="margin-top:16px;background:none;border:none;color:#999;cursor:pointer;font-size:13px;">Continue without saving</button>' +
    '</div>';
  document.body.appendChild(overlay);
  overlay.querySelector('.lgapps-gate-dismiss').addEventListener('click', function() { overlay.remove(); });
  overlay.addEventListener('click', function(e) { if (e.target === overlay) overlay.remove(); });
}

/* ========== Modal Open / Close (LGApps framework) ========== */

const MODAL_ID = "lgapps-modal-shop-planner";

// Global open dispatcher — the framework calls lgapps_open('shop-planner')
if ( ! window.lgapps_open ) {
  window.lgapps_open = function(slug) {
    // Each app registers its own handler
    const handler = window['_lgapps_opener_' + slug.replace(/-/g, '_')];
    if (handler) handler();
  };
}

// Register this app's opener
window._lgapps_opener_shop_planner = function() {
  const modal = document.getElementById(MODAL_ID);
  if (!modal) return;
  modal.style.display = "flex";
  document.body.style.overflow = "hidden";

  if (!initialized) {
    try {
      init();
      initialized = true;
    } catch(e) {
      console.error("LGSP: init failed", e);
    }
  } else {
    resizeCanvas();
  }
};

function lgspClose() {
  const modal = document.getElementById(MODAL_ID);
  if (!modal) return;
  autoSave();
  modal.style.display = "none";
  document.body.style.overflow = "";
}

/* ========== Helpers: get elements by lgsp- prefix ========== */

function $(id) { return document.getElementById("lgsp-" + id); }
function $radio(name) { return document.querySelector('input[name="lgsp-' + name + '"]:checked'); }
function $radios(name) { return document.querySelectorAll('input[name="lgsp-' + name + '"]'); }

/* ========== Core Data ========== */

let canvas, ctx;
let room = { width: 10, height: 10, units: "ft" };
let baseScale = 10;
const padding = 30;

let items = [];
let walls = [];
let labels = [];
let doors = [];
let windowsArr = [];

let nextItemId = 1;
let nextWallId = 1;
let nextLabelId = 1;
let nextDoorId = 1;
let nextWindowId = 1;

let selectedItemId = null;
let selectedWallId = null;
let selectedLabelId = null;
let selectedDoorId = null;
let selectedWindowId = null;

let isDragging = false;
let dragItemId = null;
let dragOffset = { x:0, y:0 };

let isRotating = false;
let rotateItemId = null;
let rotateStartAngle = 0;
let rotateStartMouseAngle = 0;

let wallDragMode = null;
let wallDragData = null;

let labelDragId = null;
let labelDragOffset = { x:0, y:0 };

let doorDragId = null;
let windowDragId = null;

let isDirty = false;

/* History for Undo/Redo */
let history = [];
let historyIndex = -1;
const maxHistory = 100;

/* View */
let viewScale = 1;
let offsetX = 0;
let offsetY = 0;
let isPanning = false;
let panLastX = 0;
let panLastY = 0;

/* Tools */
let toolMode = "select";
let wallDraftStart = null;
let wallPreviewCurrent = null;
let currentSnapPoint = null;
let snapToGridEnabled = true;
let didDragThisInteraction = false;

/* Auto-save debounce */
let autoSaveTimer = null;

/* ========== localStorage Auto-save ========== */

function autoSave() {
  if (isGated('autosave')) return; // silently skip — don't pester on every keystroke
  try {
    const data = JSON.stringify({
      room, items, walls, labels, doors, windowsArr,
      nextItemId, nextWallId, nextLabelId, nextDoorId, nextWindowId
    });
    localStorage.setItem(STORAGE_KEY, data);
    showAutoSaveStatus("Saved");
  } catch(e) {
    console.warn("LGSP: localStorage save failed", e);
  }
}

function autoSaveDebounced() {
  clearTimeout(autoSaveTimer);
  autoSaveTimer = setTimeout(autoSave, 1500);
}

function showAutoSaveStatus(msg) {
  const el = $("autosaveStatus");
  if (!el) return;
  el.textContent = msg;
  setTimeout(() => { if (el.textContent === msg) el.textContent = ""; }, 2000);
}

function loadFromStorage() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return false;
    const obj = JSON.parse(raw);
    applyLoadedData(obj);
    return true;
  } catch(e) {
    console.warn("LGSP: localStorage load failed", e);
    return false;
  }
}

function applyLoadedData(obj) {
  // --- Sanitization layer ---
  // Prevent prototype pollution
  if (obj == null || typeof obj !== "object" || Array.isArray(obj)) {
    throw new Error("Invalid layout data");
  }

  // String sanitizer: strip HTML tags, limit length
  function safeStr(val, maxLen) {
    if (typeof val !== "string") return "";
    // Strip any HTML tags
    var clean = val.replace(/<[^>]*>/g, "");
    // Strip common script injection patterns
    clean = clean.replace(/javascript:/gi, "").replace(/on\w+\s*=/gi, "");
    return clean.substring(0, maxLen || 100);
  }

  // Numeric sanitizer: enforce finite number within bounds
  function safeNum(val, fallback, min, max) {
    var n = parseFloat(val);
    if (!isFinite(n)) return fallback;
    if (typeof min === "number" && n < min) return min;
    if (typeof max === "number" && n > max) return max;
    return n;
  }

  // Integer ID sanitizer
  function safeId(val) {
    var n = parseInt(val, 10);
    return (isFinite(n) && n > 0) ? n : 1;
  }

  // Whitelist validator
  function safeEnum(val, allowed, fallback) {
    return allowed.indexOf(val) !== -1 ? val : fallback;
  }

  // Cap array sizes to prevent memory bombs
  var MAX_ITEMS = 500;
  var MAX_WALLS = 500;
  var MAX_LABELS = 200;
  var MAX_DOORS = 200;
  var MAX_WINDOWS = 200;

  // --- Room ---
  var r = (obj.room && typeof obj.room === "object" && !Array.isArray(obj.room)) ? obj.room : {};
  room = {
    width:  safeNum(r.width, 10, 1, 1000),
    height: safeNum(r.height, 10, 1, 1000),
    units:  safeEnum(r.units, ["ft", "m"], "ft")
  };

  // --- Items ---
  var rawItems = Array.isArray(obj.items) ? obj.items.slice(0, MAX_ITEMS) : [];
  items = rawItems.map(function(it) {
    if (it == null || typeof it !== "object" || Array.isArray(it)) return null;
    var type = safeEnum(it.type, ["rect", "circle"], "rect");
    var base = {
      id:    safeId(it.id),
      type:  type,
      name:  safeStr(it.name, 50) || "Item",
      x:     safeNum(it.x, room.width / 2, -1000, 1000),
      y:     safeNum(it.y, room.height / 2, -1000, 1000),
      color: safeEnum(it.color, ["blue", "green", "orange", "gray"], "blue"),
      rotation: safeNum(it.rotation, 0, -100, 100)
    };
    if (type === "rect") {
      base.width = safeNum(it.width, 1, 0.1, 500);
      base.depth = safeNum(it.depth, 1, 0.1, 500);
    } else {
      base.diameter = safeNum(it.diameter, 1, 0.1, 500);
    }
    return base;
  }).filter(Boolean);

  // --- Walls ---
  var rawWalls = Array.isArray(obj.walls) ? obj.walls.slice(0, MAX_WALLS) : [];
  walls = rawWalls.map(function(w) {
    if (w == null || typeof w !== "object" || Array.isArray(w)) return null;
    return {
      id:          safeId(w.id),
      x1:          safeNum(w.x1, 0, -1000, 1000),
      y1:          safeNum(w.y1, 0, -1000, 1000),
      x2:          safeNum(w.x2, 0, -1000, 1000),
      y2:          safeNum(w.y2, 0, -1000, 1000),
      style:       safeEnum(w.style, ["interior", "exterior"], "interior"),
      isPerimeter: w.isPerimeter === true,
      side:        w.isPerimeter ? safeEnum(w.side, ["top", "right", "bottom", "left"], "") : undefined
    };
  }).filter(Boolean);

  // --- Labels ---
  var rawLabels = Array.isArray(obj.labels) ? obj.labels.slice(0, MAX_LABELS) : [];
  labels = rawLabels.map(function(l) {
    if (l == null || typeof l !== "object" || Array.isArray(l)) return null;
    return {
      id:    safeId(l.id),
      text:  safeStr(l.text, 100) || "Label",
      x:     safeNum(l.x, room.width / 2, -1000, 1000),
      y:     safeNum(l.y, room.height / 2, -1000, 1000),
      size:  safeEnum(l.size, ["small", "medium", "large"], "medium"),
      color: safeEnum(l.color, ["black", "gray", "blue"], "black")
    };
  }).filter(Boolean);

  // --- Doors ---
  var rawDoors = Array.isArray(obj.doors) ? obj.doors.slice(0, MAX_DOORS) : [];
  doors = rawDoors.map(function(d) {
    if (d == null || typeof d !== "object" || Array.isArray(d)) return null;
    return {
      id:     safeId(d.id),
      wallId: safeId(d.wallId),
      offset: safeNum(d.offset, 0.5, 0, 1),
      width:  safeNum(d.width, 3, 0.1, 100),
      swing:  safeEnum(d.swing, ["none", "in-left", "in-right", "out-left", "out-right"], "none")
    };
  }).filter(Boolean);

  // --- Windows ---
  var rawWindows = Array.isArray(obj.windowsArr) ? obj.windowsArr.slice(0, MAX_WINDOWS) : [];
  windowsArr = rawWindows.map(function(w) {
    if (w == null || typeof w !== "object" || Array.isArray(w)) return null;
    return {
      id:     safeId(w.id),
      wallId: safeId(w.wallId),
      offset: safeNum(w.offset, 0.5, 0, 1),
      width:  safeNum(w.width, 3, 0.1, 100)
    };
  }).filter(Boolean);

  // --- Counters (capped to reasonable max) ---
  nextItemId   = safeNum(obj.nextItemId, 1, 1, 100000);
  nextWallId   = safeNum(obj.nextWallId, 1, 1, 100000);
  nextLabelId  = safeNum(obj.nextLabelId, 1, 1, 100000);
  nextDoorId   = safeNum(obj.nextDoorId, 1, 1, 100000);
  nextWindowId = safeNum(obj.nextWindowId, 1, 1, 100000);

  // Update room inputs
  $("roomWidth").value = room.width;
  $("roomHeight").value = room.height;
  $("roomUnits").value = room.units;

  // Sync units toggle button if initialized
  if (window._lgsp_updateUnitsBtn) window._lgsp_updateUnitsBtn();
}

/* ========== Dirty + History ========== */

function markDirty() {
  isDirty = true;
  autoSaveDebounced();
}

function markClean() {
  isDirty = false;
}

function snapshotState() {
  return JSON.parse(JSON.stringify({
    room, items, walls, labels, doors, windowsArr,
    nextItemId, nextWallId, nextLabelId, nextDoorId, nextWindowId
  }));
}

function pushHistory() {
  const snap = snapshotState();
  if (historyIndex < history.length - 1) {
    history = history.slice(0, historyIndex + 1);
  }
  history.push(snap);
  if (history.length > maxHistory) history.shift();
  historyIndex = history.length - 1;
}

function loadSnapshot(snap) {
  room = snap.room;
  items = snap.items || [];
  walls = snap.walls || [];
  labels = snap.labels || [];
  doors = snap.doors || [];
  windowsArr = snap.windowsArr || [];
  nextItemId = snap.nextItemId || 1;
  nextWallId = snap.nextWallId || 1;
  nextLabelId = snap.nextLabelId || 1;
  nextDoorId = snap.nextDoorId || 1;
  nextWindowId = snap.nextWindowId || 1;
  computeBaseScale();
  selectedItemId = selectedWallId = selectedLabelId = selectedDoorId = selectedWindowId = null;
  draw();
  updateSidebar();
}

function undo() {
  if (historyIndex <= 0) return;
  historyIndex--;
  loadSnapshot(history[historyIndex]);
  markDirty();
}

function redo() {
  if (historyIndex >= history.length - 1) return;
  historyIndex++;
  loadSnapshot(history[historyIndex]);
  markDirty();
}

/* ========== Grid & Scale ========== */

function getMinorGridStep() { return room.units === "m" ? 0.1 : 0.25; }
function getMajorGridStep() { return room.units === "m" ? 0.5 : 1.0; }
function snapToGridValue(v) {
  const step = getMinorGridStep();
  return Math.round(v / step) * step;
}

function resizeCanvas() {
  const container = document.querySelector(".lgsp-canvas-container");
  if (!container) return;
  const rect = container.getBoundingClientRect();
  canvas.width = rect.width;
  canvas.height = rect.height;
  computeBaseScale();
  draw();
}

function computeBaseScale() {
  const usableW = canvas.width - padding * 2;
  const usableH = canvas.height - padding * 2;
  baseScale = Math.min(usableW / room.width, usableH / room.height);
}

function effectiveScale() { return baseScale * viewScale; }

function roomToCanvas(x, y) {
  const s = effectiveScale();
  return { x: padding + offsetX + x * s, y: padding + offsetY + y * s };
}

function canvasToRoom(x, y) {
  const s = effectiveScale();
  return { x: (x - padding - offsetX) / s, y: (y - padding - offsetY) / s };
}

/* ========== Colors & Fonts ========== */

function getItemFillColor(color) {
  switch(color) {
    case "green":  return "#d6f5d6";
    case "orange": return "#ffe0b3";
    case "gray":   return "#e0e0e0";
    default:       return "#d5e8ff";
  }
}
function getItemStrokeColor(color) {
  switch(color) {
    case "green":  return "#3c7a3c";
    case "orange": return "#b36b00";
    case "gray":   return "#555555";
    default:       return "#555555";
  }
}
function getLabelFont(label) {
  switch(label.size) {
    case "small": return "12px system-ui";
    case "large": return "20px system-ui";
    default:      return "14px system-ui";
  }
}

/* ========== Drawing ========== */

function draw() {
  if (!ctx) return;
  ctx.clearRect(0, 0, canvas.width, canvas.height);
  drawGrid(ctx, roomToCanvas);
  drawWalls(ctx, roomToCanvas);
  drawItems(ctx, roomToCanvas);
  drawLabels(ctx, roomToCanvas);
  drawDimensions(ctx, roomToCanvas);
  drawWallPreview(ctx, roomToCanvas);
  drawSnapPreview(ctx, roomToCanvas);
}

function drawGrid(c, toCanvas) {
  const major = getMajorGridStep();
  const minor = getMinorGridStep();
  c.save();
  c.strokeStyle = "#eee";
  c.beginPath();
  for (let x = 0; x <= room.width + 1e-6; x += minor) {
    const p1 = toCanvas(x, 0), p2 = toCanvas(x, room.height);
    c.moveTo(p1.x, p1.y); c.lineTo(p2.x, p2.y);
  }
  for (let y = 0; y <= room.height + 1e-6; y += minor) {
    const p1 = toCanvas(0, y), p2 = toCanvas(room.width, y);
    c.moveTo(p1.x, p1.y); c.lineTo(p2.x, p2.y);
  }
  c.stroke();
  c.strokeStyle = "#ccc";
  c.beginPath();
  for (let x = 0; x <= room.width + 1e-6; x += major) {
    const p1 = toCanvas(x, 0), p2 = toCanvas(x, room.height);
    c.moveTo(p1.x, p1.y); c.lineTo(p2.x, p2.y);
  }
  for (let y = 0; y <= room.height + 1e-6; y += major) {
    const p1 = toCanvas(0, y), p2 = toCanvas(room.width, y);
    c.moveTo(p1.x, p1.y); c.lineTo(p2.x, p2.y);
  }
  c.stroke();
  c.restore();
}

/* ----- Walls, Doors, Windows ----- */

function drawWalls(c, toCanvas) {
  walls.forEach(wall => {
    const wallDoors = doors.filter(d => d.wallId === wall.id);
    const wallWindows = windowsArr.filter(w => w.wallId === wall.id);
    drawWallWithOpenings(c, toCanvas, wall, wallDoors, wallWindows);
  });
}

function drawWallWithOpenings(c, toCanvas, wall, wallDoors, wallWindows) {
  const p1W = { x: wall.x1, y: wall.y1 };
  const p2W = { x: wall.x2, y: wall.y2 };
  const dx = p2W.x - p1W.x;
  const dy = p2W.y - p1W.y;
  const len = Math.hypot(dx, dy);
  if (len === 0) return;

  const openings = [];
  wallDoors.forEach(door => {
    const half = (door.width / len) / 2;
    let s = Math.max(0, Math.min(1, door.offset - half));
    let e = Math.max(0, Math.min(1, door.offset + half));
    if (e > s) openings.push({ start: s, end: e });
  });
  wallWindows.forEach(win => {
    const half = (win.width / len) / 2;
    let s = Math.max(0, Math.min(1, win.offset - half));
    let e = Math.max(0, Math.min(1, win.offset + half));
    if (e > s) openings.push({ start: s, end: e });
  });
  openings.sort((a, b) => a.start - b.start);

  let segments = [];
  let cur = 0;
  openings.forEach(op => {
    if (op.start > cur) segments.push({ start: cur, end: op.start });
    cur = Math.max(cur, op.end);
  });
  if (cur < 1) segments.push({ start: cur, end: 1 });

  const isSelected = (wall.id === selectedWallId);
  const scaleFactor = Math.sqrt(viewScale);
  let lineWidth;
  if (wall.style === "exterior") {
    c.strokeStyle = isSelected ? "#f00" : "#000";
    lineWidth = 4 * scaleFactor;
    if (isSelected) lineWidth *= 1.3;
  } else {
    c.strokeStyle = isSelected ? "#f00" : "#666";
    lineWidth = 2 * scaleFactor;
    if (isSelected) lineWidth *= 1.3;
  }
  c.lineWidth = lineWidth;

  segments.forEach(seg => {
    const wx1 = p1W.x + dx * seg.start, wy1 = p1W.y + dy * seg.start;
    const wx2 = p1W.x + dx * seg.end,   wy2 = p1W.y + dy * seg.end;
    const c1 = toCanvas(wx1, wy1), c2 = toCanvas(wx2, wy2);
    c.beginPath();
    c.moveTo(c1.x, c1.y);
    c.lineTo(c2.x, c2.y);
    c.stroke();
  });

  if (isSelected) {
    const cp1 = toCanvas(wall.x1, wall.y1);
    const cp2 = toCanvas(wall.x2, wall.y2);
    c.fillStyle = "#fff";
    c.strokeStyle = "#f00";
    c.lineWidth = 2;
    [cp1, cp2].forEach(pt => {
      c.beginPath();
      c.arc(pt.x, pt.y, 6, 0, Math.PI * 2);
      c.fill();
      c.stroke();
    });
  }

  wallDoors.forEach(door => drawDoorSymbol(c, toCanvas, wall, door));
  wallWindows.forEach(win => drawWindowSymbol(c, toCanvas, wall, win));
}

function drawDoorSymbol(c, toCanvas, wall, door) {
  if (door.swing === "none") return;
  const dx = wall.x2 - wall.x1, dy = wall.y2 - wall.y1;
  const len = Math.hypot(dx, dy);
  if (len === 0) return;

  const halfFrac = (door.width / len) / 2;
  let s = Math.max(0, Math.min(1, door.offset - halfFrac));
  let e = Math.max(0, Math.min(1, door.offset + halfFrac));
  if (e <= s) return;

  const startX = wall.x1 + dx * s, startY = wall.y1 + dy * s;
  const endX   = wall.x1 + dx * e, endY   = wall.y1 + dy * e;

  let hingeX, hingeY, leafX, leafY;
  if (door.swing.endsWith("left")) {
    hingeX = startX; hingeY = startY; leafX = endX; leafY = endY;
  } else {
    hingeX = endX; hingeY = endY; leafX = startX; leafY = startY;
  }

  const hingeCanvas = toCanvas(hingeX, hingeY);
  const leafCanvas  = toCanvas(leafX, leafY);
  const leafRadius  = Math.hypot(leafCanvas.x - hingeCanvas.x, leafCanvas.y - hingeCanvas.y);
  const baseAngle = Math.atan2(leafCanvas.y - hingeCanvas.y, leafCanvas.x - hingeCanvas.x);
  let direction = (door.swing === "in-left" || door.swing === "out-right") ? 1 : -1;
  const endAngle = baseAngle + direction * (Math.PI / 2);

  c.save();
  c.strokeStyle = "#888";
  c.lineWidth = 1;
  c.beginPath();
  c.moveTo(hingeCanvas.x, hingeCanvas.y);
  c.lineTo(leafCanvas.x, leafCanvas.y);
  c.stroke();

  c.strokeStyle = "#444";
  c.lineWidth = 1.4;
  c.beginPath();
  c.arc(hingeCanvas.x, hingeCanvas.y, leafRadius, baseAngle, endAngle, direction < 0);
  c.stroke();
  c.restore();
}

function drawWindowSymbol(c, toCanvas, wall, win) {
  const dx = wall.x2 - wall.x1, dy = wall.y2 - wall.y1;
  const len = Math.hypot(dx, dy);
  if (len === 0) return;

  const centerX = wall.x1 + dx * win.offset;
  const centerY = wall.y1 + dy * win.offset;
  const s = effectiveScale();
  const half = (win.width * s) / 2;
  const wallAngle = Math.atan2(dy, dx);
  const centerCanvas = toCanvas(centerX, centerY);

  c.save();
  c.translate(centerCanvas.x, centerCanvas.y);
  c.rotate(wallAngle);
  const h = 6;
  c.fillStyle = "#cce6ff";
  c.strokeStyle = (win.id === selectedWindowId ? "#06c" : "#004488");
  c.lineWidth = 1.5;
  c.beginPath();
  c.rect(-half, -h / 2, win.width * s, h);
  c.fill();
  c.stroke();
  c.restore();
}

/* ----- Items ----- */

function drawItems(c, toCanvas) {
  items.forEach(item => {
    if (item.type === "circle") drawCircleItem(c, toCanvas, item);
    else drawRectItem(c, toCanvas, item);
  });
}

function drawRectItem(c, toCanvas, item) {
  const center = toCanvas(item.x, item.y);
  const s = effectiveScale();
  const w = item.width * s, h = item.depth * s;
  const fillCol = getItemFillColor(item.color);
  const strokeCol = getItemStrokeColor(item.color);

  c.save();
  c.translate(center.x, center.y);
  c.rotate(item.rotation || 0);
  c.fillStyle = fillCol;
  c.strokeStyle = (item.id === selectedItemId ? "#06c" : strokeCol);
  c.lineWidth = (item.id === selectedItemId ? 2 : 1.2);
  c.fillRect(-w / 2, -h / 2, w, h);
  c.strokeRect(-w / 2, -h / 2, w, h);

  c.save();
  c.rotate(-(item.rotation || 0));
  c.fillStyle = "#000";
  c.font = "13px system-ui";
  c.textAlign = "center";
  c.textBaseline = "middle";
  c.fillText(item.name, 0, 0);
  c.restore();
  c.restore();

  if (item.id === selectedItemId) drawRotateHandle(c, toCanvas, item);
}

function drawCircleItem(c, toCanvas, item) {
  const center = toCanvas(item.x, item.y);
  const s = effectiveScale();
  const r = (item.diameter / 2) * s;
  const fillCol = getItemFillColor(item.color);
  const strokeCol = getItemStrokeColor(item.color);

  c.save();
  c.beginPath();
  c.arc(center.x, center.y, r, 0, Math.PI * 2);
  c.fillStyle = fillCol;
  c.fill();
  c.strokeStyle = (item.id === selectedItemId ? "#06c" : strokeCol);
  c.lineWidth = (item.id === selectedItemId ? 2 : 1.2);
  c.stroke();

  c.fillStyle = "#000";
  c.font = "13px system-ui";
  c.textAlign = "center";
  c.textBaseline = "middle";
  c.fillText(item.name, center.x, center.y);
  c.restore();
}

/* ----- Labels ----- */

function drawLabels(c, toCanvas) {
  c.save();
  c.textAlign = "center";
  c.textBaseline = "middle";
  labels.forEach(label => {
    const p = toCanvas(label.x, label.y);
    c.font = getLabelFont(label);
    c.fillStyle = label.color || "#444";
    c.fillText(label.text, p.x, p.y);
    if (label.id === selectedLabelId) {
      const pad = 4;
      const w = c.measureText(label.text).width + pad * 2;
      const h = 20;
      c.strokeStyle = "#f00";
      c.lineWidth = 1;
      c.strokeRect(p.x - w / 2, p.y - h / 2, w, h);
    }
  });
  c.restore();
}

/* ----- Dimensions ----- */

function drawDimensions(c, toCanvas) {
  c.save();
  c.strokeStyle = "#999";
  c.fillStyle = "#555";
  c.lineWidth = 1;
  c.font = "12px system-ui";
  c.textAlign = "center";
  c.textBaseline = "middle";

  const tl = toCanvas(0, 0);
  const tr = toCanvas(room.width, 0);
  const bl = toCanvas(0, room.height);
  const arrow = 5;
  const unitLabel = room.units === "m" ? "m" : room.units;

  // Width dimension
  const yOff = tl.y - 20;
  c.beginPath(); c.moveTo(tl.x, yOff); c.lineTo(tr.x, yOff); c.stroke();
  c.beginPath();
  c.moveTo(tl.x, yOff); c.lineTo(tl.x + arrow, yOff - arrow);
  c.moveTo(tl.x, yOff); c.lineTo(tl.x + arrow, yOff + arrow);
  c.moveTo(tr.x, yOff); c.lineTo(tr.x - arrow, yOff - arrow);
  c.moveTo(tr.x, yOff); c.lineTo(tr.x - arrow, yOff + arrow);
  c.stroke();
  c.fillText(room.width.toFixed(2) + " " + unitLabel, (tl.x + tr.x) / 2, yOff - 10);

  // Height dimension
  const xOff = tl.x - 20;
  c.beginPath(); c.moveTo(xOff, tl.y); c.lineTo(xOff, bl.y); c.stroke();
  c.beginPath();
  c.moveTo(xOff, tl.y); c.lineTo(xOff - arrow, tl.y + arrow);
  c.moveTo(xOff, tl.y); c.lineTo(xOff + arrow, tl.y + arrow);
  c.moveTo(xOff, bl.y); c.lineTo(xOff - arrow, bl.y - arrow);
  c.moveTo(xOff, bl.y); c.lineTo(xOff + arrow, bl.y - arrow);
  c.stroke();
  c.save();
  c.translate(xOff - 15, (tl.y + bl.y) / 2);
  c.rotate(-Math.PI / 2);
  c.fillText(room.height.toFixed(2) + " " + unitLabel, 0, 0);
  c.restore();
  c.restore();
}

/* ----- Previews ----- */

function drawWallPreview(c, toCanvas) {
  if (!wallDraftStart || !wallPreviewCurrent) return;
  const p1 = toCanvas(wallDraftStart.x, wallDraftStart.y);
  const p2 = toCanvas(wallPreviewCurrent.x, wallPreviewCurrent.y);
  c.save();
  c.strokeStyle = "rgba(0,0,0,0.5)";
  c.setLineDash([5, 4]);
  c.lineWidth = 1;
  c.beginPath(); c.moveTo(p1.x, p1.y); c.lineTo(p2.x, p2.y); c.stroke();
  c.setLineDash([]);
  c.restore();
}

function drawSnapPreview(c, toCanvas) {
  if (toolMode !== "wall" || !currentSnapPoint) return;
  const p = toCanvas(currentSnapPoint.x, currentSnapPoint.y);
  c.save();
  c.fillStyle = "#ff8800";
  c.strokeStyle = "#cc6600";
  c.lineWidth = 1.5;
  c.beginPath();
  c.arc(p.x, p.y, 5, 0, Math.PI * 2);
  c.fill(); c.stroke();
  c.restore();
}

/* ----- Rotate Handle ----- */

function drawRotateHandle(c, toCanvas, item) {
  if (item.type !== "rect") return;
  const center = toCanvas(item.x, item.y);
  const s = effectiveScale();
  const half = (item.depth * s) / 2;
  const L = half + 20;
  const a = item.rotation || 0;
  const hx = center.x + L * Math.sin(a);
  const hy = center.y - L * Math.cos(a);

  c.save();
  c.fillStyle = "#fff";
  c.strokeStyle = "#06c";
  c.lineWidth = 2;
  c.beginPath(); c.arc(hx, hy, 7, 0, Math.PI * 2); c.fill(); c.stroke();
  c.beginPath(); c.moveTo(center.x, center.y); c.lineTo(hx, hy); c.stroke();
  c.restore();
}

/* ========== Hit Testing ========== */

function isRotateHandle(px, py, item) {
  if (item.type !== "rect") return false;
  const c = roomToCanvas(item.x, item.y);
  const s = effectiveScale();
  const L = (item.depth * s) / 2 + 20;
  const a = item.rotation || 0;
  const hx = c.x + L * Math.sin(a);
  const hy = c.y - L * Math.cos(a);
  return Math.hypot(px - hx, py - hy) <= 10;
}

function isInsideItem(px, py, item) {
  if (item.type === "circle") {
    const c = roomToCanvas(item.x, item.y);
    const r = (item.diameter / 2) * effectiveScale();
    return Math.hypot(px - c.x, py - c.y) <= r;
  }
  const c = roomToCanvas(item.x, item.y);
  let dx = px - c.x, dy = py - c.y;
  const a = item.rotation || 0;
  const cos = Math.cos(-a), sin = Math.sin(-a);
  const x = dx * cos - dy * sin;
  const y = dx * sin + dy * cos;
  const s = effectiveScale();
  const hw = item.width * s / 2, hh = item.depth * s / 2;
  return (x >= -hw && x <= hw && y >= -hh && y <= hh);
}

function getHit(px, py) {
  for (let i = items.length - 1; i >= 0; i--) {
    const item = items[i];
    if (item.type === "rect" && isRotateHandle(px, py, item)) return { item, mode: "rotate" };
    if (isInsideItem(px, py, item)) return { item, mode: "move" };
  }
  return null;
}

function segmentIntersectionWorld(w1, w2) {
  const x1=w1.x1,y1=w1.y1,x2=w1.x2,y2=w1.y2;
  const x3=w2.x1,y3=w2.y1,x4=w2.x2,y4=w2.y2;
  const denom = (x1-x2)*(y3-y4)-(y1-y2)*(x3-x4);
  if (Math.abs(denom)<1e-9) return null;
  const t = ((x1-x3)*(y3-y4)-(y1-y3)*(x3-x4))/denom;
  const u = ((x1-x3)*(y1-y2)-(y1-y3)*(x1-x2))/denom;
  if (t<0||t>1||u<0||u>1) return null;
  return { x: x1 + t*(x2-x1), y: y1 + t*(y2-y1) };
}

function getSnappedPoint(world, px, py, mode) {
  const useGrid = snapToGridEnabled;
  let basePoint = useGrid
    ? { x: snapToGridValue(world.x), y: snapToGridValue(world.y) }
    : { x: world.x, y: world.y };

  if (mode !== "wall" && mode !== "wallEnd") return basePoint;

  const snapRadiusPx = 12;
  let bestPoint = null;
  let bestDist = Infinity;
  function consider(cx, cy) {
    const cc = roomToCanvas(cx, cy);
    const d = Math.hypot(cc.x - px, cc.y - py);
    if (d < snapRadiusPx && d < bestDist) { bestDist = d; bestPoint = { x: cx, y: cy }; }
  }
  walls.forEach(w => { consider(w.x1, w.y1); consider(w.x2, w.y2); });
  for (let i = 0; i < walls.length; i++) {
    for (let j = i + 1; j < walls.length; j++) {
      const p = segmentIntersectionWorld(walls[i], walls[j]);
      if (p) consider(p.x, p.y);
    }
  }
  return bestPoint || basePoint;
}

function hitTestWall(px, py) {
  for (let i = walls.length - 1; i >= 0; i--) {
    const wall = walls[i];
    const p1 = roomToCanvas(wall.x1, wall.y1);
    const p2 = roomToCanvas(wall.x2, wall.y2);
    if (Math.hypot(px - p1.x, py - p1.y) <= 10) return { wall, mode: "end1" };
    if (Math.hypot(px - p2.x, py - p2.y) <= 10) return { wall, mode: "end2" };
    const dx = p2.x - p1.x, dy = p2.y - p1.y;
    const len2 = dx * dx + dy * dy;
    if (len2 === 0) continue;
    let t = ((px - p1.x) * dx + (py - p1.y) * dy) / len2;
    t = Math.max(0, Math.min(1, t));
    const dist = Math.hypot(px - (p1.x + t * dx), py - (p1.y + t * dy));
    if (dist <= 6) return { wall, mode: "line" };
  }
  return null;
}

function hitTestDoor(px, py) {
  for (let i = doors.length - 1; i >= 0; i--) {
    const door = doors[i];
    const wall = walls.find(w => w.id === door.wallId);
    if (!wall) continue;
    const cx = wall.x1 + (wall.x2 - wall.x1) * door.offset;
    const cy = wall.y1 + (wall.y2 - wall.y1) * door.offset;
    const c = roomToCanvas(cx, cy);
    if (Math.hypot(px - c.x, py - c.y) <= 16) return door;
  }
  return null;
}

function hitTestWindow(px, py) {
  for (let i = windowsArr.length - 1; i >= 0; i--) {
    const win = windowsArr[i];
    const wall = walls.find(w => w.id === win.wallId);
    if (!wall) continue;
    const cx = wall.x1 + (wall.x2 - wall.x1) * win.offset;
    const cy = wall.y1 + (wall.y2 - wall.y1) * win.offset;
    const c = roomToCanvas(cx, cy);
    if (Math.hypot(px - c.x, py - c.y) <= 16) return win;
  }
  return null;
}

function hitTestLabel(px, py) {
  for (let i = labels.length - 1; i >= 0; i--) {
    const label = labels[i];
    const p = roomToCanvas(label.x, label.y);
    if (Math.hypot(px - p.x, py - p.y) <= 10) return label;
  }
  return null;
}

/* ========== Tool Buttons ========== */

function setToolMode(mode) {
  toolMode = mode;
  wallDraftStart = wallPreviewCurrent = currentSnapPoint = null;
  ["toolSelectBtn","toolWallBtn","toolDoorBtn","toolWindowBtn","toolLabelBtn"].forEach(id => {
    const el = $(id);
    if (el) el.classList.remove("lgsp-active");
  });
  const map = { select:"toolSelectBtn", wall:"toolWallBtn", door:"toolDoorBtn", window:"toolWindowBtn", label:"toolLabelBtn" };
  if (map[mode]) { const el = $(map[mode]); if (el) el.classList.add("lgsp-active"); }
  draw();
}

function snapRotation(item) {
  const targets = [0, 90, 180, 270].map(d => d * Math.PI / 180);
  let a = (item.rotation || 0) % (2 * Math.PI);
  if (a < 0) a += 2 * Math.PI;
  let best = a, min = 999;
  targets.forEach(t => {
    let diff = Math.abs(a - t);
    diff = Math.min(diff, 2 * Math.PI - diff);
    if (diff < min) { min = diff; best = t; }
  });
  if (min < 5 * Math.PI / 180) item.rotation = best;
}

/* ========== Perimeter Walls ========== */

function ensurePerimeterWalls() {
  if (walls.some(w => w.isPerimeter)) return;
  const W = room.width, H = room.height;
  walls.push(
    { id: nextWallId++, x1:0,y1:0, x2:W,y2:0, style:"exterior", isPerimeter:true, side:"top" },
    { id: nextWallId++, x1:W,y1:0, x2:W,y2:H, style:"exterior", isPerimeter:true, side:"right" },
    { id: nextWallId++, x1:W,y1:H, x2:0,y2:H, style:"exterior", isPerimeter:true, side:"bottom" },
    { id: nextWallId++, x1:0,y1:H, x2:0,y2:0, style:"exterior", isPerimeter:true, side:"left" }
  );
}

function updatePerimeterWallGeometry() {
  const W = room.width, H = room.height;
  walls.forEach(w => {
    if (!w.isPerimeter) return;
    switch(w.side) {
      case "top":    w.x1=0;w.y1=0;w.x2=W;w.y2=0; break;
      case "right":  w.x1=W;w.y1=0;w.x2=W;w.y2=H; break;
      case "bottom": w.x1=W;w.y1=H;w.x2=0;w.y2=H; break;
      case "left":   w.x1=0;w.y1=H;w.x2=0;w.y2=0; break;
    }
  });
}

/* ========== Sidebar Update ========== */

function updateSidebar() {
  const sidebarTitle = $("sidebarTitle");
  const noSel   = $("noSelection");
  const itemEd  = $("itemEditor");
  const wallEd  = $("wallEditor");
  const doorEd  = $("doorEditor");
  const winEd   = $("windowEditor");
  const labelEd = $("labelEditor");
  const rectFields = $("rectFields");
  const circleFields = $("circleFields");

  noSel.style.display = "block";
  itemEd.style.display = wallEd.style.display = doorEd.style.display = winEd.style.display = labelEd.style.display = "none";
  sidebarTitle.textContent = "Nothing Selected";

  if (selectedItemId) {
    const item = items.find(i => i.id === selectedItemId);
    if (!item) return;
    noSel.style.display = "none";
    itemEd.style.display = "block";
    sidebarTitle.textContent = "Item Details";
    $("editName").value = item.name;
    if (item.type === "rect") {
      rectFields.style.display = "block"; circleFields.style.display = "none";
      $("editWidth").value = item.width;
      $("editDepth").value = item.depth;
      $("editRotation").value = ((item.rotation || 0) * 180 / Math.PI).toFixed(2);
    } else {
      rectFields.style.display = "none"; circleFields.style.display = "block";
      $("editDiameter").value = item.diameter;
    }
    $radios("editColor").forEach(r => { r.checked = (r.value === item.color); });
    return;
  }
  if (selectedWallId) {
    const wall = walls.find(w => w.id === selectedWallId);
    if (!wall) return;
    noSel.style.display = "none"; wallEd.style.display = "block";
    sidebarTitle.textContent = "Wall Details";
    $radios("wallEditType").forEach(r => { r.checked = (r.value === wall.style); });
    return;
  }
  if (selectedDoorId) {
    const door = doors.find(d => d.id === selectedDoorId);
    if (!door) return;
    noSel.style.display = "none"; doorEd.style.display = "block";
    sidebarTitle.textContent = "Door Details";
    $("editDoorWidth").value = door.width;
    $radios("doorSwing").forEach(r => { r.checked = (r.value === (door.swing || "none")); });
    return;
  }
  if (selectedWindowId) {
    const win = windowsArr.find(w => w.id === selectedWindowId);
    if (!win) return;
    noSel.style.display = "none"; winEd.style.display = "block";
    sidebarTitle.textContent = "Window Details";
    $("editWindowWidth").value = win.width;
    return;
  }
  if (selectedLabelId) {
    const label = labels.find(l => l.id === selectedLabelId);
    if (!label) return;
    noSel.style.display = "none"; labelEd.style.display = "block";
    sidebarTitle.textContent = "Label Details";
    $("editLabelText").value = label.text;
    $radios("labelSize").forEach(r => { r.checked = (r.value === (label.size || "medium")); });
    $radios("labelColor").forEach(r => { r.checked = (r.value === (label.color || "black")); });
    return;
  }
}

/* ========== Sample Shop Layout ========== */

function loadSampleShop() {
  // 20x16 ft workshop
  room = { width: 20, height: 16, units: "ft" };
  $("roomWidth").value = 20;
  $("roomHeight").value = 16;
  $("roomUnits").value = "ft";

  // Perimeter walls
  walls = [
    { id: 1, x1:0,y1:0, x2:20,y2:0, style:"exterior", isPerimeter:true, side:"top" },
    { id: 2, x1:20,y1:0, x2:20,y2:16, style:"exterior", isPerimeter:true, side:"right" },
    { id: 3, x1:20,y1:16, x2:0,y2:16, style:"exterior", isPerimeter:true, side:"bottom" },
    { id: 4, x1:0,y1:16, x2:0,y2:0, style:"exterior", isPerimeter:true, side:"left" }
  ];

  // Interior wall dividing a small finish room (back-left corner)
  walls.push(
    { id: 5, x1:0, y1:10, x2:7, y2:10, style:"interior", isPerimeter:false }
  );

  nextWallId = 6;

  // Door in the bottom wall (main entry)
  doors = [
    { id: 1, wallId: 3, offset: 0.75, width: 3, swing: "in-left" },
    // Door into finish room
    { id: 2, wallId: 5, offset: 0.6, width: 2.5, swing: "in-right" }
  ];
  nextDoorId = 3;

  // Windows
  windowsArr = [
    { id: 1, wallId: 1, offset: 0.25, width: 4 },
    { id: 2, wallId: 1, offset: 0.65, width: 4 },
    { id: 3, wallId: 4, offset: 0.25, width: 3 }
  ];
  nextWindowId = 4;

  // Workbenches, tools, storage
  items = [
    // Main workbench
    { id: 1, type:"rect", name:"Workbench", width:6, depth:2.5, x:10, y:3.25, rotation:0, color:"blue" },
    // Assembly table
    { id: 2, type:"rect", name:"Assembly Table", width:4, depth:3, x:16.5, y:6.5, rotation:0, color:"blue" },
    // Band saw
    { id: 3, type:"rect", name:"Band Saw", width:2, depth:2, x:3, y:3, rotation:0, color:"green" },
    // Drill press
    { id: 4, type:"circle", name:"Drill Press", diameter:1.5, x:6.5, y:2, rotation:0, color:"green" },
    // Router table
    { id: 5, type:"rect", name:"Router Table", width:2.5, depth:2, x:3, y:7, rotation:0, color:"green" },
    // Tool cabinet
    { id: 6, type:"rect", name:"Tool Cabinet", width:4, depth:1.5, x:16.5, y:0.75, rotation:0, color:"gray" },
    // Wood storage rack
    { id: 7, type:"rect", name:"Wood Storage", width:1.5, depth:5, x:19.25, y:12, rotation:0, color:"orange" },
    // Spray booth / finish area
    { id: 8, type:"rect", name:"Spray Booth", width:3, depth:3, x:3, y:13, rotation:0, color:"orange" },
    // Stool
    { id: 9, type:"circle", name:"Stool", diameter:1.5, x:10, y:6, rotation:0, color:"gray" }
  ];
  nextItemId = 10;

  // Welcome label with instructions
  labels = [
    { id: 1, text:"Welcome! This is a sample layout.", x: 10, y: 9.5, size: "large", color: "black" },
    { id: 2, text:"Drag items to rearrange. Use Clear All to start your own.", x: 10, y: 10.8, size: "medium", color: "gray" },
    { id: 3, text:"Finish Room", x: 3.5, y: 12, size: "small", color: "blue" }
  ];
  nextLabelId = 4;

  computeBaseScale();
}

/* ========== Init ========== */

function init() {
  canvas = $("layoutCanvas");
  if (!canvas) return;
  ctx = canvas.getContext("2d");
  canvas.addEventListener("contextmenu", e => e.preventDefault());

  // Load saved data or start fresh with sample layout
  const loaded = loadFromStorage();
  if (!loaded) {
    loadSampleShop();
  }

  resizeCanvas();
  computeBaseScale();
  draw();
  updateSidebar();
  pushHistory();
  markClean();

  // Resize handler
  window.addEventListener("resize", resizeCanvas);

  // Close button (uses data-attribute from the modal markup)
  document.querySelector('[data-lgapps-close="shop-planner"]').addEventListener("click", lgspClose);

  // Escape key closes modal
  document.addEventListener("keydown", function(e) {
    if (e.key === "Escape") {
      const modal = document.getElementById(MODAL_ID);
      if (modal && modal.style.display !== "none") {
        lgspClose();
        e.preventDefault();
      }
    }
  });

  // Click outside modal content to close
  document.getElementById(MODAL_ID).addEventListener("click", function(e) {
    if (e.target === this) lgspClose();
  });

  // Tool buttons
  $("toolSelectBtn").addEventListener("click", () => setToolMode("select"));
  $("toolWallBtn").addEventListener("click",   () => setToolMode("wall"));
  $("toolDoorBtn").addEventListener("click",   () => setToolMode("door"));
  $("toolWindowBtn").addEventListener("click", () => setToolMode("window"));
  $("toolLabelBtn").addEventListener("click",  () => setToolMode("label"));

  $("snapToggleBtn").addEventListener("click", () => {
    snapToGridEnabled = !snapToGridEnabled;
    $("snapToggleBtn").textContent = snapToGridEnabled ? "Snap: On" : "Snap: Off";
    draw();
  });

  $("undoBtn").addEventListener("click", undo);
  $("redoBtn").addEventListener("click", redo);

  // Units toggle button
  var unitsBtn = $("unitsToggleBtn");
  window._lgsp_updateUnitsBtn = function() {
    var isFt = $("roomUnits").value === "ft";
    unitsBtn.textContent = isFt ? "Feet" : "Metric";
    if (!isFt) {
      unitsBtn.classList.add("lgapps-active");
    } else {
      unitsBtn.classList.remove("lgapps-active");
    }
  };
  var updateUnitsBtn = window._lgsp_updateUnitsBtn;
  updateUnitsBtn();

  unitsBtn.addEventListener("click", () => {
    var sel = $("roomUnits");
    var oldUnits = sel.value;
    var newUnits = oldUnits === "ft" ? "m" : "ft";
    sel.value = newUnits;

    // Convert room dimensions
    var factor = (newUnits === "m") ? 0.3048 : 3.28084;
    var newW = parseFloat(($("roomWidth").value * factor).toFixed(2));
    var newH = parseFloat(($("roomHeight").value * factor).toFixed(2));
    $("roomWidth").value = newW;
    $("roomHeight").value = newH;
    room.width = newW;
    room.height = newH;
    room.units = newUnits;

    // Convert all item dimensions
    items.forEach(function(it) {
      it.x *= factor; it.y *= factor;
      if (it.type === "rect") { it.width *= factor; it.depth *= factor; }
      else { it.diameter *= factor; }
    });

    // Convert walls
    walls.forEach(function(w) {
      w.x1 *= factor; w.y1 *= factor;
      w.x2 *= factor; w.y2 *= factor;
    });

    // Convert labels
    labels.forEach(function(l) { l.x *= factor; l.y *= factor; });

    // Convert doors (width is in room units, offset is 0-1 so no change)
    doors.forEach(function(d) { d.width *= factor; });

    // Convert windows
    windowsArr.forEach(function(w) { w.width *= factor; });

    updatePerimeterWallGeometry();
    computeBaseScale();
    updateUnitsBtn();
    markDirty(); pushHistory(); draw(); updateSidebar();
  });

  // Room
  $("applyRoomBtn").addEventListener("click", () => {
    const w = parseFloat($("roomWidth").value);
    const h = parseFloat($("roomHeight").value);
    if (!(w > 0 && h > 0)) return alert("Invalid room dimensions.");
    room.width = w;
    room.height = h;
    room.units = $("roomUnits").value;
    updatePerimeterWallGeometry();
    computeBaseScale();
    markDirty(); pushHistory(); draw();
  });

  // Add items
  $("addItemBtn").addEventListener("click", () => {
    const name = $("itemName").value || "Item";
    const w = parseFloat($("itemWidth").value);
    const d = parseFloat($("itemDepth").value);
    if (!(w > 0 && d > 0)) return alert("Invalid rectangle size.");
    const item = { id: nextItemId++, type:"rect", name, width:w, depth:d, x:room.width/2, y:room.height/2, rotation:0, color:"blue" };
    items.push(item);
    selectedItemId = item.id;
    selectedWallId = selectedDoorId = selectedLabelId = selectedWindowId = null;
    markDirty(); pushHistory(); draw(); updateSidebar();
  });

  $("addCircleBtn").addEventListener("click", () => {
    const name = $("circleName").value || "Item";
    const dia = parseFloat($("circleDiameter").value);
    if (!(dia > 0)) return alert("Invalid circle diameter.");
    const item = { id: nextItemId++, type:"circle", name, diameter:dia, x:room.width/2, y:room.height/2, rotation:0, color:"blue" };
    items.push(item);
    selectedItemId = item.id;
    selectedWallId = selectedDoorId = selectedLabelId = selectedWindowId = null;
    markDirty(); pushHistory(); draw(); updateSidebar();
  });

  // Sidebar editors
  $("applyEditBtn").addEventListener("click", () => {
    const item = items.find(i => i.id === selectedItemId);
    if (!item) return;
    item.name = $("editName").value || "Item";
    if (item.type === "rect") {
      let w = parseFloat($("editWidth").value); if (!(w > 0)) w = item.width;
      let d = parseFloat($("editDepth").value); if (!(d > 0)) d = item.depth;
      item.width = w; item.depth = d;
      let rDeg = parseFloat($("editRotation").value);
      if (!isNaN(rDeg)) { item.rotation = rDeg * Math.PI / 180; snapRotation(item); }
    } else {
      let dia = parseFloat($("editDiameter").value); if (!(dia > 0)) dia = item.diameter;
      item.diameter = dia;
    }
    const colorInput = $radio("editColor");
    if (colorInput) item.color = colorInput.value;
    markDirty(); pushHistory(); draw(); updateSidebar();
  });

  $("deleteItemBtn").addEventListener("click", () => {
    items = items.filter(i => i.id !== selectedItemId);
    selectedItemId = null;
    markDirty(); pushHistory(); draw(); updateSidebar();
  });

  $("applyWallEditBtn").addEventListener("click", () => {
    const wall = walls.find(w => w.id === selectedWallId);
    if (!wall) return;
    const r = $radio("wallEditType");
    if (r) wall.style = r.value || "interior";
    markDirty(); pushHistory(); draw(); updateSidebar();
  });

  $("deleteWallBtn").addEventListener("click", () => {
    const wallId = selectedWallId;
    walls = walls.filter(w => w.id !== wallId);
    doors = doors.filter(d => d.wallId !== wallId);
    windowsArr = windowsArr.filter(w => w.wallId !== wallId);
    selectedWallId = selectedDoorId = selectedWindowId = null;
    markDirty(); pushHistory(); draw(); updateSidebar();
  });

  $("applyDoorEditBtn").addEventListener("click", () => {
    const door = doors.find(d => d.id === selectedDoorId);
    if (!door) return;
    let w = parseFloat($("editDoorWidth").value); if (!(w > 0)) w = door.width;
    door.width = w;
    const swingRadio = $radio("doorSwing");
    if (swingRadio) door.swing = swingRadio.value;
    markDirty(); pushHistory(); draw(); updateSidebar();
  });

  $("deleteDoorBtn").addEventListener("click", () => {
    doors = doors.filter(d => d.id !== selectedDoorId);
    selectedDoorId = null;
    markDirty(); pushHistory(); draw(); updateSidebar();
  });

  $("applyWindowEditBtn").addEventListener("click", () => {
    const win = windowsArr.find(w => w.id === selectedWindowId);
    if (!win) return;
    let w = parseFloat($("editWindowWidth").value); if (!(w > 0)) w = win.width;
    win.width = w;
    markDirty(); pushHistory(); draw(); updateSidebar();
  });

  $("deleteWindowBtn").addEventListener("click", () => {
    windowsArr = windowsArr.filter(w => w.id !== selectedWindowId);
    selectedWindowId = null;
    markDirty(); pushHistory(); draw(); updateSidebar();
  });

  $("applyLabelEditBtn").addEventListener("click", () => {
    const label = labels.find(l => l.id === selectedLabelId);
    if (!label) return;
    label.text = $("editLabelText").value || "Label";
    const s = $radio("labelSize"); if (s) label.size = s.value;
    const c = $radio("labelColor"); if (c) label.color = c.value;
    markDirty(); pushHistory(); draw(); updateSidebar();
  });

  $("deleteLabelBtn").addEventListener("click", () => {
    labels = labels.filter(l => l.id !== selectedLabelId);
    selectedLabelId = null;
    markDirty(); pushHistory(); draw(); updateSidebar();
  });

  // Clear all
  $("clearAllBtn").addEventListener("click", () => {
    if (!confirm("Clear your entire layout and start fresh? This cannot be undone.")) return;
    room = { width: 10, height: 10, units: "ft" };
    items = []; walls = []; labels = []; doors = []; windowsArr = [];
    nextItemId = nextWallId = nextLabelId = nextDoorId = nextWindowId = 1;
    selectedItemId = selectedWallId = selectedDoorId = selectedLabelId = selectedWindowId = null;
    viewScale = 1; offsetX = 0; offsetY = 0;
    history = []; historyIndex = -1;
    $("roomWidth").value = 10; $("roomHeight").value = 10; $("roomUnits").value = "ft";
    if (window._lgsp_updateUnitsBtn) window._lgsp_updateUnitsBtn();
    ensurePerimeterWalls();
    updatePerimeterWallGeometry();
    computeBaseScale();
    markDirty(); pushHistory(); draw(); updateSidebar();
    localStorage.removeItem(STORAGE_KEY);
  });

  // JSON Download
  $("downloadBtn").addEventListener("click", () => {
    if (isGated('json_download')) { showGatePrompt('Downloading layouts'); return; }
    const blob = new Blob(
      [JSON.stringify({ room, items, walls, labels, doors, windowsArr, nextItemId, nextWallId, nextLabelId, nextDoorId, nextWindowId }, null, 2)],
      { type: "application/json" }
    );
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url; a.download = "shop_layout.json"; a.click();
    URL.revokeObjectURL(url);
    markClean();
  });

  // JSON Upload
  const fileInput = $("fileInput");
  $("uploadBtn").addEventListener("click", () => {
    if (isGated('json_upload')) { showGatePrompt('Uploading layouts'); return; }
    fileInput.click();
  });
  fileInput.addEventListener("change", e => {
    const file = e.target.files[0];
    if (!file) return;
    // File size limit: 1MB max
    if (file.size > 1024 * 1024) {
      alert("File too large. Layout files should be under 1MB.");
      fileInput.value = "";
      return;
    }
    const reader = new FileReader();
    reader.onload = function(evt) {
      try {
        const obj = JSON.parse(evt.target.result);
        applyLoadedData(obj);
        computeBaseScale();
        selectedItemId = selectedWallId = selectedDoorId = selectedLabelId = selectedWindowId = null;
        markClean(); pushHistory(); draw(); updateSidebar();
        autoSave();
      } catch(err) {
        alert("Could not parse that JSON file.");
      }
    };
    reader.readAsText(file);
    fileInput.value = "";
  });

  // PDF Export
  $("pdfBtn").addEventListener("click", () => {
    if (isGated('pdf_export')) { showGatePrompt('PDF export'); return; }
    if (typeof window.jspdf === "undefined") { alert("PDF library not loaded."); return; }
    const { jsPDF } = window.jspdf;
    const headerText = $("pdfHeader").value || "The Roadman Shop Planner";
    const pdf = new jsPDF({
      orientation: room.width >= room.height ? "landscape" : "portrait",
      unit: "pt", format: "letter"
    });
    const pageWidth = pdf.internal.pageSize.getWidth();
    const pageHeight = pdf.internal.pageSize.getHeight();
    const headerSpace = 40;
    const availW = pageWidth - 40, availH = pageHeight - headerSpace - 40;
    const aspectRoom = room.width / room.height;
    const aspectPage = availW / availH;
    let drawW, drawH;
    if (aspectRoom > aspectPage) { drawW = availW; drawH = drawW / aspectRoom; }
    else { drawH = availH; drawW = drawH * aspectRoom; }

    const offCanvas = document.createElement("canvas");
    offCanvas.width = Math.round(drawW);
    offCanvas.height = Math.round(drawH);
    const offCtx = offCanvas.getContext("2d");
    const localPad = 20;
    const pdfScale = Math.min((offCanvas.width - 2 * localPad) / room.width, (offCanvas.height - 2 * localPad) / room.height);

    function roomToCanvasPdf(x, y) {
      return { x: localPad + x * pdfScale, y: localPad + y * pdfScale };
    }

    offCtx.fillStyle = "#fff";
    offCtx.fillRect(0, 0, offCanvas.width, offCanvas.height);
    drawGrid(offCtx, roomToCanvasPdf);
    drawWalls(offCtx, roomToCanvasPdf);

    offCtx.save();
    const tl = roomToCanvasPdf(0, 0);
    const br = roomToCanvasPdf(room.width, room.height);
    offCtx.beginPath();
    offCtx.rect(tl.x, tl.y, br.x - tl.x, br.y - tl.y);
    offCtx.clip();
    drawItems(offCtx, roomToCanvasPdf);
    drawLabels(offCtx, roomToCanvasPdf);
    offCtx.restore();
    drawDimensions(offCtx, roomToCanvasPdf);

    const dataUrl = offCanvas.toDataURL("image/png");
    pdf.setFontSize(14);
    const textWidth = pdf.getTextWidth(headerText);
    pdf.text(headerText, (pageWidth - textWidth) / 2, 24);
    pdf.addImage(dataUrl, "PNG", (pageWidth - drawW) / 2, headerSpace, drawW, drawH);
    pdf.save("shop_layout.pdf");
  });

  /* ========== Canvas Mouse Events ========== */

  canvas.addEventListener("mousedown", e => {
    const rect = canvas.getBoundingClientRect();
    const px = e.clientX - rect.left, py = e.clientY - rect.top;

    if (e.button === 2) {
      isPanning = true; panLastX = e.clientX; panLastY = e.clientY; return;
    }
    if (e.button !== 0) return;

    const world = canvasToRoom(px, py);
    didDragThisInteraction = false;

    // Wall tool
    if (toolMode === "wall") {
      const snapped = getSnappedPoint(world, px, py, "wall");
      currentSnapPoint = snapped;
      if (!wallDraftStart) {
        wallDraftStart = snapped; wallPreviewCurrent = snapped;
      } else {
        const wt = $radio("wallType");
        walls.push({ id: nextWallId++, x1: wallDraftStart.x, y1: wallDraftStart.y, x2: snapped.x, y2: snapped.y, style: wt ? wt.value : "interior", isPerimeter: false });
        wallDraftStart = wallPreviewCurrent = currentSnapPoint = null;
        markDirty(); pushHistory(); setToolMode("select");
      }
      draw(); return;
    }

    // Door tool
    if (toolMode === "door") {
      let nearestWall = null, nearestDist = Infinity, nearestT = 0;
      walls.forEach(w => {
        const p1 = roomToCanvas(w.x1, w.y1), p2 = roomToCanvas(w.x2, w.y2);
        const dx = p2.x - p1.x, dy = p2.y - p1.y, len2 = dx * dx + dy * dy;
        if (len2 === 0) return;
        let t = ((px - p1.x) * dx + (py - p1.y) * dy) / len2;
        t = Math.max(0, Math.min(1, t));
        const dist = Math.hypot(px - (p1.x + t * dx), py - (p1.y + t * dy));
        if (dist < nearestDist) { nearestDist = dist; nearestWall = w; nearestT = t; }
      });
      if (!nearestWall || nearestDist > 20) { alert("Click closer to a wall to place a door."); return; }
      const wallLen = Math.hypot(nearestWall.x2 - nearestWall.x1, nearestWall.y2 - nearestWall.y1);
      const door = { id: nextDoorId++, wallId: nearestWall.id, offset: nearestT, width: Math.min(3, wallLen), swing: "none" };
      doors.push(door);
      selectedDoorId = door.id;
      selectedItemId = selectedWallId = selectedLabelId = selectedWindowId = null;
      markDirty(); pushHistory(); setToolMode("select"); draw(); updateSidebar(); return;
    }

    // Window tool
    if (toolMode === "window") {
      let nearestWall = null, nearestDist = Infinity, nearestT = 0;
      walls.forEach(w => {
        const p1 = roomToCanvas(w.x1, w.y1), p2 = roomToCanvas(w.x2, w.y2);
        const dx = p2.x - p1.x, dy = p2.y - p1.y, len2 = dx * dx + dy * dy;
        if (len2 === 0) return;
        let t = ((px - p1.x) * dx + (py - p1.y) * dy) / len2;
        t = Math.max(0, Math.min(1, t));
        const dist = Math.hypot(px - (p1.x + t * dx), py - (p1.y + t * dy));
        if (dist < nearestDist) { nearestDist = dist; nearestWall = w; nearestT = t; }
      });
      if (!nearestWall || nearestDist > 20) { alert("Click closer to a wall to place a window."); return; }
      const wallLen = Math.hypot(nearestWall.x2 - nearestWall.x1, nearestWall.y2 - nearestWall.y1);
      const win = { id: nextWindowId++, wallId: nearestWall.id, offset: nearestT, width: Math.min(3, wallLen || 3) };
      windowsArr.push(win);
      selectedWindowId = win.id;
      selectedDoorId = selectedItemId = selectedWallId = selectedLabelId = null;
      markDirty(); pushHistory(); setToolMode("select"); draw(); updateSidebar(); return;
    }

    // Label tool
    if (toolMode === "label") {
      const text = prompt("Label text:", "Label");
      if (text && text.trim() !== "") {
        labels.push({ id: nextLabelId++, text: text.trim(), x: world.x, y: world.y, size: "medium", color: "black" });
        markDirty(); pushHistory(); draw();
      }
      setToolMode("select"); return;
    }

    // Select / drag
    const hit = getHit(px, py);
    if (hit) {
      selectedItemId = hit.item.id;
      selectedWallId = selectedDoorId = selectedLabelId = selectedWindowId = null;
      updateSidebar();
      if (hit.mode === "move") {
        isDragging = true; dragItemId = hit.item.id;
        dragOffset.x = world.x - hit.item.x; dragOffset.y = world.y - hit.item.y;
      }
      if (hit.mode === "rotate") {
        isRotating = true; rotateItemId = hit.item.id;
        const c = roomToCanvas(hit.item.x, hit.item.y);
        rotateStartMouseAngle = Math.atan2(py - c.y, px - c.x);
        rotateStartAngle = hit.item.rotation || 0;
      }
      draw(); return;
    }

    const doorHit = hitTestDoor(px, py);
    if (doorHit) {
      selectedDoorId = doorHit.id;
      selectedItemId = selectedWallId = selectedLabelId = selectedWindowId = null;
      doorDragId = doorHit.id;
      updateSidebar(); draw(); return;
    }

    const windowHit = hitTestWindow(px, py);
    if (windowHit) {
      selectedWindowId = windowHit.id;
      selectedDoorId = selectedItemId = selectedWallId = selectedLabelId = null;
      windowDragId = windowHit.id;
      updateSidebar(); draw(); return;
    }

    const wallHit = hitTestWall(px, py);
    if (wallHit) {
      selectedWallId = wallHit.wall.id;
      selectedItemId = selectedDoorId = selectedLabelId = selectedWindowId = null;
      wallDragMode = wallHit.mode;
      wallDragData = { wallId: wallHit.wall.id, startWorld: world, orig: { x1: wallHit.wall.x1, y1: wallHit.wall.y1, x2: wallHit.wall.x2, y2: wallHit.wall.y2 } };
      updateSidebar(); draw(); return;
    }

    const labelHit = hitTestLabel(px, py);
    if (labelHit) {
      selectedLabelId = labelHit.id;
      selectedItemId = selectedWallId = selectedDoorId = selectedWindowId = null;
      labelDragId = labelHit.id;
      labelDragOffset.x = world.x - labelHit.x; labelDragOffset.y = world.y - labelHit.y;
      updateSidebar(); draw(); return;
    }

    // Deselect all
    selectedItemId = selectedWallId = selectedDoorId = selectedLabelId = selectedWindowId = null;
    wallDragMode = wallDragData = null;
    labelDragId = doorDragId = windowDragId = null;
    updateSidebar(); draw();
  });

  canvas.addEventListener("mousemove", e => {
    const rect = canvas.getBoundingClientRect();
    const px = e.clientX - rect.left, py = e.clientY - rect.top;
    const world = canvasToRoom(px, py);

    if (isPanning) {
      offsetX += e.clientX - panLastX; offsetY += e.clientY - panLastY;
      panLastX = e.clientX; panLastY = e.clientY;
      draw(); return;
    }

    if (toolMode === "wall" && !wallDraftStart) {
      currentSnapPoint = getSnappedPoint(world, px, py, "wall"); draw(); return;
    }
    if (toolMode === "wall" && wallDraftStart) {
      const snapped = getSnappedPoint(world, px, py, "wall");
      wallPreviewCurrent = snapped; currentSnapPoint = snapped; draw(); return;
    }

    if (isDragging && dragItemId) {
      const item = items.find(i => i.id === dragItemId);
      if (item) { item.x = world.x - dragOffset.x; item.y = world.y - dragOffset.y; markDirty(); didDragThisInteraction = true; draw(); updateSidebar(); }
      return;
    }
    if (isRotating && rotateItemId) {
      const item = items.find(i => i.id === rotateItemId);
      if (item && item.type === "rect") {
        const c = roomToCanvas(item.x, item.y);
        item.rotation = rotateStartAngle + (Math.atan2(py - c.y, px - c.x) - rotateStartMouseAngle);
        snapRotation(item); markDirty(); didDragThisInteraction = true; draw(); updateSidebar();
      }
      return;
    }

    if (wallDragMode && wallDragData) {
      const wall = walls.find(w => w.id === wallDragData.wallId);
      if (!wall) return;
      if (wallDragMode === "line") {
        const snapped = getSnappedPoint(world, px, py, "wall");
        const ddx = snapped.x - wallDragData.startWorld.x, ddy = snapped.y - wallDragData.startWorld.y;
        wall.x1 = wallDragData.orig.x1 + ddx; wall.y1 = wallDragData.orig.y1 + ddy;
        wall.x2 = wallDragData.orig.x2 + ddx; wall.y2 = wallDragData.orig.y2 + ddy;
      } else if (wallDragMode === "end1") {
        const snapped = getSnappedPoint(world, px, py, "wallEnd");
        wall.x1 = snapped.x; wall.y1 = snapped.y;
      } else if (wallDragMode === "end2") {
        const snapped = getSnappedPoint(world, px, py, "wallEnd");
        wall.x2 = snapped.x; wall.y2 = snapped.y;
      }
      markDirty(); didDragThisInteraction = true; draw(); return;
    }

    if (labelDragId) {
      const label = labels.find(l => l.id === labelDragId);
      if (label) { label.x = world.x - labelDragOffset.x; label.y = world.y - labelDragOffset.y; markDirty(); didDragThisInteraction = true; draw(); }
      return;
    }

    if (doorDragId) {
      const door = doors.find(d => d.id === doorDragId);
      if (!door) return;
      const wall = walls.find(w => w.id === door.wallId);
      if (!wall) return;
      const p1 = roomToCanvas(wall.x1, wall.y1), p2 = roomToCanvas(wall.x2, wall.y2);
      const ddx = p2.x - p1.x, ddy = p2.y - p1.y, len2 = ddx * ddx + ddy * ddy;
      if (len2 === 0) return;
      door.offset = Math.max(0, Math.min(1, ((px - p1.x) * ddx + (py - p1.y) * ddy) / len2));
      markDirty(); didDragThisInteraction = true; draw(); updateSidebar(); return;
    }

    if (windowDragId) {
      const win = windowsArr.find(w => w.id === windowDragId);
      if (!win) return;
      const wall = walls.find(w => w.id === win.wallId);
      if (!wall) return;
      const p1 = roomToCanvas(wall.x1, wall.y1), p2 = roomToCanvas(wall.x2, wall.y2);
      const ddx = p2.x - p1.x, ddy = p2.y - p1.y, len2 = ddx * ddx + ddy * ddy;
      if (len2 === 0) return;
      win.offset = Math.max(0, Math.min(1, ((px - p1.x) * ddx + (py - p1.y) * ddy) / len2));
      markDirty(); didDragThisInteraction = true; draw(); updateSidebar(); return;
    }
  });

  window.addEventListener("mouseup", e => {
    if (e.button === 2) isPanning = false;
    if (didDragThisInteraction) pushHistory();
    isDragging = false; dragItemId = null;
    isRotating = false; rotateItemId = null;
    wallDragMode = wallDragData = null;
    labelDragId = doorDragId = windowDragId = null;
    didDragThisInteraction = false;
  });

  // Zoom
  canvas.addEventListener("wheel", e => {
    e.preventDefault();
    const rect = canvas.getBoundingClientRect();
    const px = e.clientX - rect.left, py = e.clientY - rect.top;
    const world = canvasToRoom(px, py);
    const factor = (e.deltaY < 0 ? 1.05 : 0.95);
    viewScale = Math.max(0.9, Math.min(6, viewScale * factor));
    const s = effectiveScale();
    offsetX = px - padding - world.x * s;
    offsetY = py - padding - world.y * s;
    draw();
  }, { passive: false });

  // Keyboard
  document.addEventListener("keydown", e => {
    // Only handle if modal is visible
    const modal = document.getElementById(MODAL_ID);
    if (!modal || modal.style.display === "none") return;

    const key = e.key.toLowerCase();
    if ((e.ctrlKey || e.metaKey) && !e.shiftKey && key === "z") { e.preventDefault(); undo(); return; }
    if ((e.ctrlKey || e.metaKey) && e.shiftKey && key === "z") { e.preventDefault(); redo(); return; }

    if (!selectedItemId) return;
    const item = items.find(i => i.id === selectedItemId);
    if (!item || item.type !== "rect") return;
    const step = 5 * Math.PI / 180;
    if (key === "[") item.rotation = (item.rotation || 0) - step;
    if (key === "]") item.rotation = (item.rotation || 0) + step;
    snapRotation(item);
    markDirty(); pushHistory(); draw(); updateSidebar();
  });
}

})();
