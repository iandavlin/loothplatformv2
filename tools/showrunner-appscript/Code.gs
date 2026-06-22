// ============================================================
// LOOTH GROUP LIVE — Showrunner Tracker  v2
// Google Apps Script — paste into Extensions > Apps Script
// ============================================================

const TIMEZONE = 'America/New_York'; // EST/EDT — all times in Eastern Time
const CONFIG_SHEET_NAME = 'Config';  // name of the settings sheet
const WIP_SHEET_NAME   = 'Work In Progress';

const WIP_HEADERS = [
  'Episode Title',
  'Show Name',
  'Air Date',
  'Showrunner',
  'Topic / Description',
  'Guest',
  'Guest Suggestion',
  'Notes',
];

// ── ROSTER READERS (live from Config sheet) ───────────────────
function getShowrunners_() {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  const sheet = ss.getSheetByName(CONFIG_SHEET_NAME);
  if (!sheet) return [];
  const data = sheet.getRange('A3:E52').getValues(); // A:Name B:Email C:Color D:WP User ID E:Co-Showrunner
  return data
    .filter(r => r[0])
    .map(r => ({
      name:          String(r[0]).trim(),
      email:         String(r[1]).trim(),
      color:         String(r[2]).trim() || '#ffffff',
      wpUserId:      parseInt(r[3]) || 0,
      coShowrunner:  String(r[4] || '').trim(), // name of co-showrunner (must match roster)
    }));
}

// Optional Regions table (Config columns H-I — Name / Slug). Empty array if missing.
function getRegions_() {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  const sheet = ss.getSheetByName(CONFIG_SHEET_NAME);
  if (!sheet) return [];
  try {
    const data = sheet.getRange('I3:I52').getValues();
    return data.filter(r => r[0]).map(r => String(r[0]).trim());
  } catch(e) { return []; }
}

// Form Access whitelist (Config columns J-K — Email / Label).
// Returns array of { email (lowercase, trimmed), label }
function getFormAccessList_() {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  const sheet = ss.getSheetByName(CONFIG_SHEET_NAME);
  if (!sheet) return [];
  try {
    const data = sheet.getRange('K3:L52').getValues();
    return data
      .filter(r => r[0])
      .map(r => ({ email: String(r[0]).trim().toLowerCase(), label: String(r[1] || '').trim() }));
  } catch(e) { return []; }
}

function isFormAccessAllowed_(email) {
  if (!email) return false;
  const target = String(email).trim().toLowerCase();
  return getFormAccessList_().some(e => e.email === target);
}

function getShows_() {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  const sheet = ss.getSheetByName(CONFIG_SHEET_NAME);
  if (!sheet) return [];
  const data = sheet.getRange('F3:G52').getValues(); // up to 50 shows
  return data
    .filter(r => r[0])
    .map(r => ({ name: String(r[0]).trim(), color: String(r[1]).trim() || '#ffffff' }));
}

// ── CONFIGURATION ────────────────────────────────────────────
const CONFIG = {
  SHEET_NAME: 'Episodes',
  DRIVE_PARENT_FOLDER_NAME: 'Looth Group Live — Promo Assets',
  GOOGLE_CALENDAR_ID: 'c_e097e1a7d09bba9fe2e0a04c2a39e105142a04e9eedf7244283a192f4349aa6f@group.calendar.google.com',
  MAX_EMAIL: 'max@loothgroup.com',
  IAN_EMAIL: 'Ian.davlin@gmail.com',
  SENDER_NAME: 'Looth Group',

  // Days before air date to trigger each reminder
  REMINDER_1_DAYS: 21, // Showrunner only
  REMINDER_2_DAYS: 14, // Showrunner + Max CC'd
  REMINDER_3_DAYS: 7,  // Showrunner + Max + Ian CC'd
  OVERDUE_DAYS:    3,  // Showrunner + Max + Ian CC'd (URGENT)

  // Column indices (1-based)
  COL: {
    EPISODE_TITLE:    1,
    SHOW_NAME:        2,  // ← NEW
    AIR_DATE:         3,
    SHOWRUNNER:       4,  // dropdown — name only (email looked up via SHOWRUNNERS)
    TOPIC:            5,
    GUEST:            6,
    GUEST_EMAIL:      7,  // comma-separated — added as Calendar guests
    BLURB:            8,
    BLURB_STATUS:     9,
    H_THUMB_STATUS:   10,
    V_THUMB_STATUS:   11,
    DRIVE_FOLDER_URL: 12,
    CALENDAR_EVENT_ID:13,
    LAST_REMINDER:    14,
    REMINDER_COUNT:   15,
    NOTES:            16,
    EVENT_TIER:       17,
    REGION:           18,
    LANGUAGE:         19,
    WP_POST_URL:      20,
    FEATURED_IMAGE:   21,
    OTHER_ATTENDEES:  22,
    ZOOM_URL:         23, // ← virtual-attend link → WP `zoom_url` (the one gated field)
  },

  // Standing Looth Group virtual room. New events default to this (editable per row);
  // sent to WP as `zoom_url` → `zoom_url_for_looth_group_virtual_event` meta → the
  // gated "Join" CTA on the event-header block. Change here to roll the default room.
  DEFAULT_ZOOM_URL: 'https://us02web.zoom.us/j/87325405572?pwd=ZnA3NEtwTlNXN0RKQThCNVJ2YzZoQT09#success',

  // ── WP REST bridge (sheets-bot) ─────────────────────────────
  // Configure via menu: "Set WP Credentials" — values stored in Script Properties.
  // Falls back to these defaults if Script Properties not set.
  WP_DEFAULT_BASE_URL: 'https://dev.loothgroup.com',
  WP_TIER_OPTIONS: ['Public', 'Looth Lite', 'Looth Pro'],
};

// Asset status values
const STATUS = {
  PENDING:   '⏳ Pending',
  SUBMITTED: '✅ Submitted',
  APPROVED:  '🟢 Approved',
  NA:        '➖ N/A',
};

// ── COLUMN HEADERS ────────────────────────────────────────────
const HEADERS = [
  'Episode Title',
  'Show Name',          // ← NEW
  'Air Date',
  'Showrunner',         // dropdown
  'Topic / Description',
  'Guest',
  'Guest Email(s)',     // comma-separated — added as Calendar guests
  'Blurb Text',
  'Blurb Status',
  'H Thumb Status',
  'V Thumb Status',
  'Drive Folder',
  'Calendar Event ID',
  'Last Reminder Sent',
  'Reminder Count',
  'Notes',
  'Event Tier',         // ← NEW (WP)
  'Region',             // ← NEW (WP, optional)
  'Language',           // ← NEW (WP, optional, comma-separated)
  'WP Post URL',        // ← NEW (admin-only — filled after publish)
  'Featured Image',     // ← NEW (inline thumbnail preview)
  'Other Attendees',    // ← NEW (comma-separated guest emails for Calendar)
  'Zoom Link',          // ← NEW (WP) — zoom_url_for_looth_group_virtual_event (gated Join CTA)
];

// ── HELPERS ───────────────────────────────────────────────────
function getShowrunnerByName_(name) {
  return getShowrunners_().find(s => s.name === name) || null;
}

// ── MENU ─────────────────────────────────────────────────────
function onOpen() {
  SpreadsheetApp.getUi()
    .createMenu('🎙️ Looth Showrunners')
    // ── SETUP (run once, in order) ──
    .addItem('1. Setup Config Sheet  ⚠', 'setupConfigSheet')
    .addItem('2. Setup Sheet Headers  ⚠', 'setupSheet')
    .addItem('3. Set WP Credentials…', 'setWpCredentials')
    .addItem('4. Test WP Connection', 'testWpConnection')
    .addItem('5. Resolve WP User IDs (Config)', 'resolveWpUserIds')
    .addItem('6. Create / Update Episode Form  ⚠', 'createOrUpdateEpisodeForm')
    .addItem('7. Get Form URLs', 'getFormUrls')
    .addItem('8. Create View Sheet', 'createViewSheet')
    .addItem('9. Install Daily Reminder Trigger', 'installDailyTrigger')
    .addSeparator()
    // ── PUBLISH ──
    .addItem('Publish Selected Row to WP', 'publishSelectedRowToWp')
    .addSeparator()
    // ── INPUT HELPERS ──
    .addItem('Open Episode Form (modal)', 'openEpisodeWebAppModal')
    .addItem('Open Episode Form (Google Form)', 'openEpisodeForm')
    .addItem('Show Episode Web App URL', 'showWebAppUrl')
    .addItem('Pick Air Date + Time…', 'showAirDateTimePicker')
    .addSeparator()
    // ── PER-ROW HELPERS ──
    .addItem('Create Drive Folder for Selected Row', 'createFolderForSelectedRow')
    .addItem('Create Calendar Event for Selected Row', 'createCalendarEventForSelectedRow')
    .addItem('Update Calendar Event for Selected Row', 'updateCalendarEventForSelectedRow')
    .addItem('Update Episode Info .txt for Selected Row', 'updateEpisodeTxtForSelectedRow')
    .addItem('Create Drive + Calendar for ALL New Rows', 'provisionAllNewRows')
    .addSeparator()
    // ── REMINDERS ──
    .addItem('Send Reminders Now (dry run — logs only)', 'sendRemindersDryRun')
    .addItem('Send Reminders Now (live)', 'sendRemindersLive')
    .addSeparator()
    .addItem('Sort Episodes by Air Date', 'sortEpisodesByAirDate')
    .addItem('Flush Form Responses', 'flushFormResponses')
    .addSeparator()
    // ── VIEW SHEET ──
    .addItem('Check Calendar for Date…', 'showFreeTimeChecker')
    .addItem('Set Free Time Checker URL…', 'setCheckerUrl')
    .addItem('Show Free Time Checker URL', 'showFreeTimeCheckerUrl')
    .addItem('Sync View Sheet Now', 'syncViewSheet')
    .addItem('Get View Sheet URL', 'getViewSheetUrl')
    .addSeparator()
    // ── DANGER ──
    .addItem('Remove All Triggers  ⚠', 'removeAllTriggers')
    .addToUi();
}

// Confirmation guard — used by destructive setup functions.
// Returns true if user typed CONFIRM (case-insensitive), false otherwise.
function confirmDestructive_(title, message) {
  const ui = SpreadsheetApp.getUi();
  const r = ui.prompt(title, message + '\n\nType CONFIRM to proceed.', ui.ButtonSet.OK_CANCEL);
  if (r.getSelectedButton() !== ui.Button.OK) return false;
  return r.getResponseText().trim().toUpperCase() === 'CONFIRM';
}

// ── SETUP ─────────────────────────────────────────────────────
function setupSheet() {
  // Only require confirmation if Episodes sheet already has data
  const _ss = SpreadsheetApp.getActiveSpreadsheet();
  const _existing = _ss.getSheetByName(CONFIG.SHEET_NAME);
  if (_existing && _existing.getLastRow() > 1) {
    if (!confirmDestructive_('Setup Sheet Headers',
      'This will REWRITE row 1 of the Episodes sheet and reapply all dropdowns/widths/protections.\nData rows are NOT cleared, but column protections will reset.')) return;
  }
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  let sheet = ss.getSheetByName(CONFIG.SHEET_NAME);
  if (!sheet) sheet = ss.insertSheet(CONFIG.SHEET_NAME);

  // ── Clear existing protections and data validations ──────────
  // Remove all existing range protections (leaves sheet protection intact)
  sheet.getProtections(SpreadsheetApp.ProtectionType.RANGE)
    .forEach(p => p.remove());

  // Clear all data validation from the entire sheet
  sheet.getRange(1, 1, sheet.getMaxRows(), sheet.getMaxColumns())
    .clearDataValidations();

  // Write headers
  const headerRange = sheet.getRange(1, 1, 1, HEADERS.length);
  headerRange.setValues([HEADERS]);
  headerRange.setFontWeight('bold');
  headerRange.setBackground('#1a1a2e');
  headerRange.setFontColor('#ffffff');
  headerRange.setHorizontalAlignment('left');
  headerRange.setVerticalAlignment('middle');

  // Freeze header row
  sheet.setFrozenRows(1);

  // Column widths
  sheet.setColumnWidth(CONFIG.COL.EPISODE_TITLE,     220);
  sheet.getRange(2, CONFIG.COL.EPISODE_TITLE, 500, 1).setWrapStrategy(SpreadsheetApp.WrapStrategy.CLIP);
  sheet.setColumnWidth(CONFIG.COL.SHOW_NAME,         160);
  sheet.getRange(2, CONFIG.COL.SHOW_NAME, 500, 1).setWrapStrategy(SpreadsheetApp.WrapStrategy.CLIP);
  sheet.setColumnWidth(CONFIG.COL.AIR_DATE,          170);
  sheet.getRange(2, CONFIG.COL.AIR_DATE, 500, 1).setNumberFormat('dd MMM yyyy  HH:mm');
  // Clip date column so overflow doesn't leak into Showrunner
  sheet.getRange(2, CONFIG.COL.AIR_DATE, 500, 1).setWrapStrategy(SpreadsheetApp.WrapStrategy.CLIP);
  // Native date picker on double-click
  sheet.getRange(2, CONFIG.COL.AIR_DATE, 500, 1).setDataValidation(
    SpreadsheetApp.newDataValidation().requireDate().setAllowInvalid(true).build()
  );
  sheet.setColumnWidth(CONFIG.COL.SHOWRUNNER,        180);
  sheet.getRange(2, CONFIG.COL.SHOWRUNNER, 500, 1).setWrapStrategy(SpreadsheetApp.WrapStrategy.CLIP);
  sheet.setColumnWidth(CONFIG.COL.TOPIC,             240);
  sheet.setColumnWidth(CONFIG.COL.BLURB,             300);
  sheet.setColumnWidth(CONFIG.COL.GUEST,             160);
  sheet.setColumnWidth(CONFIG.COL.GUEST_EMAIL,        220);
  sheet.getRange(2, CONFIG.COL.GUEST_EMAIL, 500, 1).setWrapStrategy(SpreadsheetApp.WrapStrategy.CLIP);
  sheet.setColumnWidth(CONFIG.COL.DRIVE_FOLDER_URL,  200);
  sheet.setColumnWidth(CONFIG.COL.CALENDAR_EVENT_ID, 160);

  // Clip Blurb Text and fix row height so long entries never expand rows
  sheet.getRange(2, CONFIG.COL.BLURB, 500, 1).setWrapStrategy(SpreadsheetApp.WrapStrategy.CLIP);
  sheet.setRowHeightsForced(2, 500, 25); // lock all data rows to 25px

  // ── Showrunner dropdown ──
  const showrunnerNames = getShowrunners_().map(s => s.name);
  const showrunnerRule = SpreadsheetApp.newDataValidation()
    .requireValueInList(showrunnerNames, true)
    .build();
  sheet.getRange(2, CONFIG.COL.SHOWRUNNER, 500, 1).setDataValidation(showrunnerRule);

  // ── Show Name dropdown ──
  const showNames = getShows_().map(s => s.name);
  const showRule = SpreadsheetApp.newDataValidation()
    .requireValueInList(showNames, true)
    .build();
  sheet.getRange(2, CONFIG.COL.SHOW_NAME, 500, 1).setDataValidation(showRule);

  // ── Asset status dropdowns ──
  const statusCols = [
    CONFIG.COL.BLURB_STATUS, CONFIG.COL.H_THUMB_STATUS, CONFIG.COL.V_THUMB_STATUS,
  ];
  const statusRule = SpreadsheetApp.newDataValidation()
    .requireValueInList(Object.values(STATUS), true)
    .build();
  statusCols.forEach(col => {
    // Apply validation rule only — no pre-filling values.
    // Status cells get set to Pending row-by-row when episodes are added
    // (via onFormSubmit and provisionAllNewRows), so writing 500 rows here
    // causes getLastRow() to return 501 and corrupts form submission row detection.
    sheet.getRange(2, col, 500, 1).setDataValidation(statusRule);
  });

  // ── Event Tier dropdown ──
  const tierRule = SpreadsheetApp.newDataValidation()
    .requireValueInList(CONFIG.WP_TIER_OPTIONS, true).build();
  sheet.getRange(2, CONFIG.COL.EVENT_TIER, 500, 1).setDataValidation(tierRule);
  sheet.setColumnWidth(CONFIG.COL.EVENT_TIER, 110);

  // ── Region dropdown (sourced from Config "Regions" if present, else free text) ──
  const regions = getRegions_();
  if (regions.length) {
    const regionRule = SpreadsheetApp.newDataValidation()
      .requireValueInList(regions, true).build();
    sheet.getRange(2, CONFIG.COL.REGION, 500, 1).setDataValidation(regionRule);
  }
  sheet.setColumnWidth(CONFIG.COL.REGION, 140);

  // ── Language — free text, comma-separated ──
  sheet.setColumnWidth(CONFIG.COL.LANGUAGE, 140);

  // ── WP Post URL column ──
  sheet.setColumnWidth(CONFIG.COL.WP_POST_URL, 220);

  // ── Featured Image preview column ──
  sheet.setColumnWidth(CONFIG.COL.FEATURED_IMAGE, 140);

  // ── Other Attendees column ──
  sheet.setColumnWidth(CONFIG.COL.OTHER_ATTENDEES, 220);

  // ── Zoom Link column (WP gated Join CTA) ──
  sheet.setColumnWidth(CONFIG.COL.ZOOM_URL, 260);

  // ── Conditional formatting: highlight Episode Title green when WP Post URL is filled ──
  const rules = sheet.getConditionalFormatRules();
  const titleRange = sheet.getRange(2, CONFIG.COL.EPISODE_TITLE, 500, 1);
  const newRule = SpreadsheetApp.newConditionalFormatRule()
    .whenFormulaSatisfied('=NOT(ISBLANK(INDIRECT("R"&ROW()&"C' + CONFIG.COL.WP_POST_URL + '", FALSE)))')
    .setBackground('#d9ead3')
    .setRanges([titleRange])
    .build();
  // Replace any existing rule we previously set on this range (avoid stacking)
  const filtered = rules.filter(r => {
    const rngs = r.getRanges();
    if (!rngs.length) return true;
    return rngs[0].getColumn() !== CONFIG.COL.EPISODE_TITLE;
  });
  filtered.push(newRule);
  sheet.setConditionalFormatRules(filtered);

  // Protect admin-only columns
  protectAdminColumns_(sheet);

  // ── Basic Filter on header row (adds funnel icons for click-to-sort) ──
  const existingFilter = sheet.getFilter();
  if (existingFilter) existingFilter.remove();
  sheet.getRange(1, 1, sheet.getMaxRows(), HEADERS.length).createFilter();

  SpreadsheetApp.getUi().alert('Sheet set up! Headers, dropdowns, column protections, and filter applied.');
}


// ── CONFIG SHEET SETUP ────────────────────────────────────────
function setupConfigSheet() {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  let sheet = ss.getSheetByName(CONFIG_SHEET_NAME);
  if (sheet && sheet.getLastRow() > 2) {
    if (!confirmDestructive_('Setup Config Sheet',
      'This will WIPE the Config tab and reseed it with the hardcoded showrunner/show roster. Any edits you made (added showrunners, custom colors, WP User IDs) will be lost.')) return;
  }
  if (!sheet) sheet = ss.insertSheet(CONFIG_SHEET_NAME);

  sheet.clearContents();
  sheet.clearFormats();

  // ── Showrunners table (columns A-D) ──
  const srHeader = sheet.getRange('A1:E1');
  srHeader.setValues([['SHOWRUNNERS', '', '', '', '']]);
  srHeader.merge();
  srHeader.setBackground('#1a1a2e').setFontColor('#ffffff').setFontWeight('bold').setHorizontalAlignment('center');

  sheet.getRange('A2:E2').setValues([['Name', 'Email', 'Color (hex)', 'WP User ID', 'Co-Showrunner']]);
  sheet.getRange('A2:E2').setFontWeight('bold').setBackground('#e8eaf6');

  // Cell notes on header — visible as hover tooltips with the red corner triangle
  sheet.getRange('A2').setNote('Showrunner name as it appears in the Episodes sheet and dropdowns.');
  sheet.getRange('B2').setNote('Email used to look up the WP User ID. Run "5. Resolve WP User IDs" after adding.');
  sheet.getRange('C2').setNote('Hex color (#RRGGBB) used to color this showrunner\'s cells in the Episodes sheet.');
  sheet.getRange('E2').setNote('Co-Showrunner — optional. Enter the Name of the co-showrunner exactly as it appears in this table (col A). When a Drive folder is created for an episode assigned to this showrunner, the co-showrunner will automatically be added as an editor.');
  sheet.getRange('D2').setNote(
    'WP User ID — REQUIRED for publishing this showrunner\'s episodes to WordPress.\n\n' +
    'To add a new showrunner:\n' +
    '  1) Type their Name, Email, and Color in columns A-C.\n' +
    '  2) Run menu: 🎙️ Looth Showrunners → "5. Resolve WP User IDs".\n' +
    '  3) Column D fills in automatically from WordPress.\n\n' +
    'If their email isn\'t in WordPress, the resolver will skip them. You can manually paste the WP user ID if you know it. Showrunners without a WP User ID will appear greyed out in the entry form.'
  );

  // Pre-fill with roster (WP User ID column blank — populate via "Resolve WP User IDs" menu)
  const srData = [
    ['Max',      'max@loothgroup.com',             '#FF8F78', 717,  ''],
    ['Ian',      'Ian.davlin@gmail.com',            '#FFD078', 1,    ''],
    ['Doug',     'guitarspecialist.ny@gmail.com',   '#E4FF78', 197,  ''],
    ['Giuliano', 'giuliano.nicoletti@gmail.com',    '#93FF78', 62,   ''],
    ['Brock',    'brock@stewmac.com',               '#78FFC2', 269,  ''],
    ['James',    'Jamesroadman@gmail.com',          '#78D7FF', 596,  ''],
    ['Brett',    'brett@contriverguitars.com',      '#787FFF', 613,  'Shaun'],
    ['Michael',  'michael@bashkinguitars.com',      '#D278FF', 135,  ''],
    ['Luke',     'whittlesticks@msn.com',           '#FF789E', 423,  ''],
    ['Shaun',    'shaunpenechar@gmail.com',          '#f7ac4a', 19,   'Brett'],
  ];
  sheet.getRange(3, 1, srData.length, 5).setValues(srData);

  // Color preview: shade Name/Email/Color cells (NOT the WP User ID col — keep that readable)
  srData.forEach((row, i) => {
    sheet.getRange(i + 3, 1, 1, 3).setBackground(row[2]);
    const dark = ['#274e13','#1a1a2e','#000000','#333333','#1c4587'];
    sheet.getRange(i + 3, 1, 1, 3).setFontColor(dark.includes(row[2]) ? '#ffffff' : '#000000');
  });

  // Instruction row below the showrunner table
  const instructionRow = Math.max(srData.length + 3, 14);
  sheet.getRange(instructionRow, 1, 1, 5).merge();
  sheet.getRange(instructionRow, 1)
    .setValue('ℹ️  To add a new showrunner: fill Name, Email, Color → then run menu "🎙️ Looth Showrunners → 5. Resolve WP User IDs" to populate the WP User ID. Without an ID they can\'t be picked in the entry form.')
    .setFontStyle('italic')
    .setFontColor('#555')
    .setBackground('#fff8dc')
    .setWrap(true)
    .setVerticalAlignment('middle');
  sheet.setRowHeight(instructionRow, 50);

  sheet.setColumnWidth(1, 140);
  sheet.setColumnWidth(2, 220);
  sheet.setColumnWidth(3, 110);
  sheet.setColumnWidth(4, 100); // WP User ID
  sheet.setColumnWidth(5, 140); // Co-Showrunner

  // ── Shows table (columns E-F) ──
  const showHeader = sheet.getRange('F1:G1');
  showHeader.setValues([['SHOWS', '']]);
  showHeader.merge();
  showHeader.setBackground('#1a1a2e').setFontColor('#ffffff').setFontWeight('bold').setHorizontalAlignment('center');

  sheet.getRange('F2:G2').setValues([['Name', 'Color (hex)']]);
  sheet.getRange('F2:G2').setFontWeight('bold').setBackground('#e8eaf6');

  const showData = [
    ['Acoustic guitar builders club', '#FF8F78'],
    ['Electric guitar builders club', '#FFD078'],
    ['3D club',                       '#E4FF78'],
    ['Loothing for dollars',          '#93FF78'],
    ['Back to basics',                '#78FFC2'],
    ['Marketing club',                '#78D7FF'],
    ['Looth pro',                     '#787FFF'],
    ['Council of Elders',             '#D278FF'],
    ['Interview',                     '#FF789E'],
    ['Violin repairs',                '#FFF788'],
    ['Acoustic Design',               '#fd4d4d'],
    ['Vintage smintage',              '#b37d5b'],
  ];
  sheet.getRange(3, 6, showData.length, 2).setValues(showData);

  showData.forEach((row, i) => {
    sheet.getRange(i + 3, 6, 1, 2).setBackground(row[1]);
  });

  sheet.setColumnWidth(6, 160); // Show Name
  sheet.setColumnWidth(7, 110); // Show Color

  // ── Form Access whitelist (columns J-K) ──
  const faHeader = sheet.getRange('K1:L1');
  faHeader.setValues([['FORM ACCESS', '']]);
  faHeader.merge();
  faHeader.setBackground('#1a1a2e').setFontColor('#ffffff').setFontWeight('bold').setHorizontalAlignment('center');

  sheet.getRange('K2:L2').setValues([['Email', 'Label']]);
  sheet.getRange('K2:L2').setFontWeight('bold').setBackground('#e8eaf6');

  // Form access whitelist — hardcoded to ensure exact emails and labels
  const faUnique = [
    ['max@loothgroup.com',             'Max (showrunner)'],
    ['Ian.davlin@gmail.com',           'Ian (showrunner)'],
    ['guitarspecialist.ny@gmail.com',  'Doug (showrunner)'],
    ['giuliano.nicoletti@gmail.com',   'Giuliano (showrunner)'],
    ['brock@stewmac.com',              'Brock (showrunner)'],
    ['Jamesroadman@gmail.com',         'James (showrunner)'],
    ['ian@loothgroup.com',             'Ian (admin)'],
    ['michael@bashkinguitars.com',     'Michael (showrunner)'],
    ['whittlesticks@msn.com',          'Luke (showrunner)'],
    ['brett@contriverguitars.com',     'Brett (showrunner)'],
    ['shaunpenechar@gmail.com',        'Shaun (showrunner)'],
  ];
  if (faUnique.length) {
    sheet.getRange(3, 11, faUnique.length, 2).setValues(faUnique);
  }

  sheet.setColumnWidth(8, 20);   // spacer column H (between Showrunners and Shows)
  sheet.setColumnWidth(10, 20);  // spacer column J (between Shows and Form Access)
  sheet.setColumnWidth(11, 220); // K — Email
  sheet.setColumnWidth(12, 180); // L — Label

  sheet.setFrozenRows(2);

  SpreadsheetApp.getUi().alert('Config sheet ready! Edit the tables to add/remove showrunners and shows.\n\nAfter making changes, re-run \'Setup Sheet Headers\' to refresh the dropdowns in the Episodes sheet.');
}

// ── COLOR CODING (called from onEdit) ────────────────────────
function applyShowrunnerColor_(sheet, row, name) {
  const sr = getShowrunnerByName_(name);
  if (!sr) return;
  const cell = sheet.getRange(row, CONFIG.COL.SHOWRUNNER);
  cell.setBackground(sr.color);
  // Use white text for dark backgrounds (simple luminance check via known dark colors)
  const darkBgs = ['#274e13', '#1a1a2e', '#000000', '#333333', '#1c4587'];
  cell.setFontColor(darkBgs.includes(sr.color) ? '#ffffff' : '#000000');
  cell.setFontWeight('bold');
  cell.setBorder(false, false, false, false, false, false);
}

function applyShowColor_(sheet, row, name) {
  const show = getShows_().find(s => s.name === name);
  if (!show) return;
  const cell = sheet.getRange(row, CONFIG.COL.SHOW_NAME);
  cell.setBackground(show.color);
  cell.setFontWeight('bold');
}

// Re-apply all colors across the whole sheet (useful after setup or bulk import)
function reapplyAllColors() {
  const sheet = SpreadsheetApp.getActiveSpreadsheet().getSheetByName(CONFIG.SHEET_NAME);
  if (!sheet) return;
  const lastRow = sheet.getLastRow();
  if (lastRow < 2) return;
  // Batch read both columns at once for performance
  const srNames   = sheet.getRange(2, CONFIG.COL.SHOWRUNNER, lastRow - 1, 1).getValues();
  const showNames = sheet.getRange(2, CONFIG.COL.SHOW_NAME,  lastRow - 1, 1).getValues();
  for (let i = 0; i < lastRow - 1; i++) {
    if (srNames[i][0])   applyShowrunnerColor_(sheet, i + 2, srNames[i][0]);
    if (showNames[i][0]) applyShowColor_(sheet, i + 2, showNames[i][0]);
  }
  SpreadsheetApp.getUi().alert('Colors reapplied to ' + (lastRow - 1) + ' rows.');
}

// ── DRIVE FOLDER CREATION ─────────────────────────────────────
function getOrCreateParentFolder_() {
  const folders = DriveApp.getFoldersByName(CONFIG.DRIVE_PARENT_FOLDER_NAME);
  if (folders.hasNext()) return folders.next();
  return DriveApp.createFolder(CONFIG.DRIVE_PARENT_FOLDER_NAME);
}

function createFolderForRow_(sheet, rowIndex) {
  const row = sheet.getRange(rowIndex, 1, 1, HEADERS.length).getValues()[0];
  const title       = row[CONFIG.COL.EPISODE_TITLE - 1];
  const airDate     = row[CONFIG.COL.AIR_DATE - 1];
  const showrunner  = row[CONFIG.COL.SHOWRUNNER - 1];
  const existingUrl = row[CONFIG.COL.DRIVE_FOLDER_URL - 1];

  if (!title || !airDate) return null;
  if (existingUrl) return existingUrl;

  const dateStr = airDate instanceof Date
    ? Utilities.formatDate(airDate, TIMEZONE, 'yyyy-MM-dd')
    : String(airDate);

  const folderName = `${dateStr} — ${title}${showrunner ? ' (' + showrunner + ')' : ''}`;
  const parent = getOrCreateParentFolder_();
  const folder = parent.createFolder(folderName);

  // Private — no public link access. Shared explicitly with Ian and the showrunner only.
  folder.setSharing(DriveApp.Access.PRIVATE, DriveApp.Permission.NONE);

  const url = folder.getUrl();
  sheet.getRange(rowIndex, CONFIG.COL.DRIVE_FOLDER_URL).setValue(url);

  // Add Ian and Max as editors
  try { folder.addEditor(CONFIG.IAN_EMAIL); } catch(e) { Logger.log('Could not add Ian as editor: ' + e.message); }
  try { folder.addEditor(CONFIG.MAX_EMAIL); } catch(e) { Logger.log('Could not add Max as editor: ' + e.message); }

  // Add the episode's showrunner as editor (looked up from roster)
  const sr = getShowrunnerByName_(showrunner);
  if (sr && sr.email) {
    try { folder.addEditor(sr.email); } catch(e) { Logger.log('Could not add showrunner as editor: ' + e.message); }
  }

  // Add co-showrunner if defined for this showrunner
  if (sr && sr.coShowrunner) {
    const coSr = getShowrunnerByName_(sr.coShowrunner);
    if (coSr && coSr.email) {
      try { folder.addEditor(coSr.email); Logger.log('Co-showrunner added: ' + coSr.name); }
      catch(e) { Logger.log('Could not add co-showrunner as editor: ' + e.message); }
    }
  }

  // Create episode info txt file in the folder
  const fullRow = sheet.getRange(rowIndex, 1, 1, HEADERS.length).getValues()[0];
  createEpisodeTxtFile_(folder, fullRow);

  return url;
}

function createFolderForSelectedRow() {
  const sheet = SpreadsheetApp.getActiveSpreadsheet().getSheetByName(CONFIG.SHEET_NAME);
  const row = sheet.getActiveCell().getRow();
  if (row < 2) { SpreadsheetApp.getUi().alert('Select a data row (not the header).'); return; }
  const url = createFolderForRow_(sheet, row);
  SpreadsheetApp.getUi().alert(url ? 'Folder created: ' + url : 'Row already has a folder or is missing title/date.');
}

// ── EPISODE INFO TXT FILE ─────────────────────────────────────
function createEpisodeTxtFile_(folder, row) {
  const title      = row[CONFIG.COL.EPISODE_TITLE - 1];
  const showName   = row[CONFIG.COL.SHOW_NAME - 1];
  const airDate    = row[CONFIG.COL.AIR_DATE - 1];
  const showrunner = row[CONFIG.COL.SHOWRUNNER - 1];
  const guest      = row[CONFIG.COL.GUEST - 1];
  const topic      = row[CONFIG.COL.TOPIC - 1];
  const blurb      = row[CONFIG.COL.BLURB - 1];
  const notes      = row[CONFIG.COL.NOTES - 1];

  const dateStr = airDate instanceof Date
    ? Utilities.formatDate(airDate, TIMEZONE, 'EEEE, MMMM d, yyyy  h:mm a z')
    : String(airDate);

  const lines = [
    '================================================',
    '  LOOTH GROUP LIVE — EPISODE INFO',
    '================================================',
    '',
    `TITLE:       ${title || '—'}`,
    `SHOW:        ${showName || '—'}`,
    `DATE:        ${dateStr}`,
    `SHOWRUNNER:  ${showrunner || '—'}`,
    `GUEST:       ${guest || '—'}`,
    `TOPIC:       ${topic || '—'}`,
    '',
    '------------------------------------------------',
    'BLURB',
    '------------------------------------------------',
    blurb || '(not yet provided)',
    '',
    '------------------------------------------------',
    'NOTES',
    '------------------------------------------------',
    notes || '(none)',
    '',
    '================================================',
    `Generated: ${Utilities.formatDate(new Date(), TIMEZONE, 'yyyy-MM-dd HH:mm z')}`,
    '================================================',
  ];

  const content = lines.join('\n');
  const fileName = `episode-info — ${title || 'untitled'}.txt`;

  // Delete any existing episode-info file before creating a fresh one
  const existing = folder.getFilesByName(fileName);
  while (existing.hasNext()) existing.next().setTrashed(true);

  folder.createFile(fileName, content, MimeType.PLAIN_TEXT);
}

function updateEpisodeTxtForSelectedRow() {
  const sheet = SpreadsheetApp.getActiveSpreadsheet().getSheetByName(CONFIG.SHEET_NAME);
  const rowIndex = sheet.getActiveCell().getRow();
  if (rowIndex < 2) { SpreadsheetApp.getUi().alert('Select a data row (not the header).'); return; }

  const row      = sheet.getRange(rowIndex, 1, 1, HEADERS.length).getValues()[0];
  const driveUrl = row[CONFIG.COL.DRIVE_FOLDER_URL - 1];
  if (!driveUrl) {
    SpreadsheetApp.getUi().alert('No Drive folder found for this row. Create the folder first.');
    return;
  }

  // Extract folder ID from URL and get folder
  const match = driveUrl.match(/[-\w]{25,}/);
  if (!match) { SpreadsheetApp.getUi().alert('Could not parse Drive folder URL.'); return; }
  const folder = DriveApp.getFolderById(match[0]);
  createEpisodeTxtFile_(folder, row);
  SpreadsheetApp.getUi().alert('Episode info .txt file updated in Drive folder.');
}

// ── CALENDAR EVENT CREATION ───────────────────────────────────
function createCalendarEventForRow_(sheet, rowIndex) {
  const row = sheet.getRange(rowIndex, 1, 1, HEADERS.length).getValues()[0];
  const title           = row[CONFIG.COL.EPISODE_TITLE - 1];
  const airDate         = row[CONFIG.COL.AIR_DATE - 1];
  const topic           = row[CONFIG.COL.TOPIC - 1];
  const showrunner      = row[CONFIG.COL.SHOWRUNNER - 1];
  const showName        = row[CONFIG.COL.SHOW_NAME - 1];
  const existingEventId = row[CONFIG.COL.CALENDAR_EVENT_ID - 1];
  const driveUrl        = row[CONFIG.COL.DRIVE_FOLDER_URL - 1];

  if (!title || !airDate) return null;
  if (existingEventId) return existingEventId;

  const cal = CalendarApp.getCalendarById(CONFIG.GOOGLE_CALENDAR_ID);
  Logger.log('Calendar lookup — ID: ' + CONFIG.GOOGLE_CALENDAR_ID);
  Logger.log('Calendar found: ' + (cal ? cal.getName() + ' (' + cal.getId() + ')' : 'NULL — falling back to default'));
  const activeCal = cal || CalendarApp.getDefaultCalendar();
  Logger.log('Using calendar: ' + activeCal.getName() + ' (' + activeCal.getId() + ')');

  const startTime = airDate instanceof Date ? airDate : new Date(airDate);
  const endTime = new Date(startTime.getTime() + 60 * 60 * 1000);
  Logger.log('Creating event: "' + title + '" on ' + startTime);

  const guest = row[CONFIG.COL.GUEST - 1];
  const blurb = row[CONFIG.COL.BLURB - 1];
  const description = [
    showName    ? `Show: ${showName}`         : '',
    showrunner  ? `Showrunner: ${showrunner}` : '',
    guest       ? `Guest: ${guest}`           : '',
    topic       ? `Topic: ${topic}`           : '',
    blurb       ? `\nBlurb:\n${blurb}`        : '',
    driveUrl    ? `Promo Assets: ${driveUrl}` : '',
  ].filter(Boolean).join('\n');

  const eventTitle = showName ? `🎙️ ${showName} — ${title}` : `🎙️ ${title}`;
  const event = activeCal.createEvent(eventTitle, startTime, endTime, { description });
  const eventId = event.getId();
  Logger.log('Event created — ID: ' + eventId);
  sheet.getRange(rowIndex, CONFIG.COL.CALENDAR_EVENT_ID).setValue(eventId);

  // Add showrunner, guest emails, and other attendees as Calendar guests
  try {
    const sr = getShowrunnerByName_(String(showrunner || '').trim());
    if (sr && sr.email) event.addGuest(sr.email);
    // Guest email(s) — comma-separated, triggers calendar invitation
    const guestEmailRaw = String(row[CONFIG.COL.GUEST_EMAIL - 1] || '').trim();
    if (guestEmailRaw) {
      guestEmailRaw.split(/[,;]/).map(s => s.trim()).filter(e => /@/.test(e))
        .forEach(email => { try { event.addGuest(email); } catch(e) { Logger.log('addGuest (guest) failed for ' + email + ': ' + e.message); } });
    }
    // Other attendees (co-hosts, crew etc.)
    const otherAttendeesRaw = String(row[CONFIG.COL.OTHER_ATTENDEES - 1] || '').trim();
    if (otherAttendeesRaw) {
      otherAttendeesRaw.split(/[,;]/).map(s => s.trim()).filter(e => /@/.test(e))
        .forEach(email => { try { event.addGuest(email); } catch(e) { Logger.log('addGuest (other) failed for ' + email + ': ' + e.message); } });
    }
  } catch(err) {
    Logger.log('Could not add guests: ' + err.message);
  }
  return eventId;
}

function createCalendarEventForSelectedRow() {
  const sheet = SpreadsheetApp.getActiveSpreadsheet().getSheetByName(CONFIG.SHEET_NAME);
  const row = sheet.getActiveCell().getRow();
  if (row < 2) { SpreadsheetApp.getUi().alert('Select a data row (not the header).'); return; }
  const id = createCalendarEventForRow_(sheet, row);
  SpreadsheetApp.getUi().alert(id ? 'Calendar event created.' : 'Row already has an event or is missing title/date.');
}

// ── CALENDAR CLASH CHECK ──────────────────────────────────────
// Returns an array of clashing event objects { title, start, end }
// for the given startTime (1-hour slot). Empty array = no clash.
function getCalendarClashes_(startTime) {
  const cal = CalendarApp.getCalendarById(CONFIG.GOOGLE_CALENDAR_ID)
    || CalendarApp.getDefaultCalendar();
  const endTime = new Date(startTime.getTime() + 60 * 60 * 1000);
  const events = cal.getEvents(startTime, endTime);
  return events.map(ev => ({
    title: ev.getTitle(),
    start: Utilities.formatDate(ev.getStartTime(), TIMEZONE, 'dd MMM yyyy  HH:mm'),
    end:   Utilities.formatDate(ev.getEndTime(),   TIMEZONE, 'HH:mm'),
  }));
}

// Callable from the web app — returns clash info for a given ISO datetime string
function checkCalendarClashForWebApp(airDateIso) {
  try {
    if (!airDateIso) return { ok: true, clashes: [] };
    const m = String(airDateIso).match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/);
    if (!m) return { ok: true, clashes: [] };
    const tz = getTzOffsetString_(m[1], m[2], m[3], m[4], m[5]);
    const startTime = new Date(`${m[1]}-${m[2]}-${m[3]} ${m[4]}:${m[5]}:00 ${tz}`);
    if (isNaN(startTime.getTime())) return { ok: true, clashes: [] };
    const clashes = getCalendarClashes_(startTime);
    return { ok: true, clashes };
  } catch(err) {
    Logger.log('checkCalendarClashForWebApp error: ' + err.message);
    return { ok: false, error: err.message, clashes: [] };
  }
}

// Server function for the free time checker tab
function getCalendarEvents(isoDate) {
  try {
    const m = String(isoDate || '').match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (!m) return { ok: false, error: 'Invalid date', events: [] };
    const cal = CalendarApp.getCalendarById(CONFIG.GOOGLE_CALENDAR_ID)
      || CalendarApp.getDefaultCalendar();
    const dayStart = new Date(parseInt(m[1]), parseInt(m[2]) - 1, parseInt(m[3]), 0, 0, 0);
    const dayEnd   = new Date(parseInt(m[1]), parseInt(m[2]) - 1, parseInt(m[3]), 23, 59, 59);
    const events = cal.getEvents(dayStart, dayEnd);
    return {
      ok: true,
      calendarName: cal.getName(),
      events: events.map(ev => ({
        title: ev.getTitle(),
        start: Utilities.formatDate(ev.getStartTime(), TIMEZONE, 'HH:mm'),
        end:   Utilities.formatDate(ev.getEndTime(),   TIMEZONE, 'HH:mm'),
        allDay: ev.isAllDayEvent(),
      })),
    };
  } catch(err) {
    return { ok: false, error: err.message, events: [] };
  }
}

// ── PROVISION ALL NEW ROWS ────────────────────────────────────
function provisionAllNewRows() {
  const sheet = SpreadsheetApp.getActiveSpreadsheet().getSheetByName(CONFIG.SHEET_NAME);
  if (!sheet) { SpreadsheetApp.getUi().alert('Run Setup first.'); return; }
  const lastRow = sheet.getLastRow();
  let created = 0;
  for (let i = 2; i <= lastRow; i++) {
    const title = sheet.getRange(i, CONFIG.COL.EPISODE_TITLE).getValue();
    if (!title) continue;
    createFolderForRow_(sheet, i);
    createCalendarEventForRow_(sheet, i);
    created++;
  }
  SpreadsheetApp.getUi().alert(`Provisioned ${created} rows.`);
}

// ── REMINDER ENGINE ───────────────────────────────────────────
function allAssetsSubmitted_(row) {
  const statusCols = [
    CONFIG.COL.BLURB_STATUS, CONFIG.COL.H_THUMB_STATUS, CONFIG.COL.V_THUMB_STATUS,
  ];
  return statusCols.every(col => {
    const val = row[col - 1];
    return val === STATUS.SUBMITTED || val === STATUS.APPROVED || val === STATUS.NA;
  });
}

function getPendingAssets_(row) {
  const assetNames = ['Blurb', 'H Thumbnail', 'V Thumbnail'];
  const statusCols = [
    CONFIG.COL.BLURB_STATUS, CONFIG.COL.H_THUMB_STATUS, CONFIG.COL.V_THUMB_STATUS,
  ];
  return assetNames.filter((_, i) => {
    const val = row[statusCols[i] - 1];
    return val === STATUS.PENDING || !val;
  });
}

function sendReminders_(dryRun) {
  const sheet = SpreadsheetApp.getActiveSpreadsheet().getSheetByName(CONFIG.SHEET_NAME);
  if (!sheet) return;
  const lastRow = sheet.getLastRow();
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const log = [];

  for (let i = 2; i <= lastRow; i++) {
    const row = sheet.getRange(i, 1, 1, HEADERS.length).getValues()[0];
    const title          = row[CONFIG.COL.EPISODE_TITLE - 1];
    const airDateRaw     = row[CONFIG.COL.AIR_DATE - 1];
    const showrunnerName = row[CONFIG.COL.SHOWRUNNER - 1];
    const driveUrl       = row[CONFIG.COL.DRIVE_FOLDER_URL - 1];
    const lastReminderRaw= row[CONFIG.COL.LAST_REMINDER - 1];
    const reminderCount  = parseInt(row[CONFIG.COL.REMINDER_COUNT - 1]) || 0;

    if (!title || !airDateRaw || !showrunnerName) continue;
    if (allAssetsSubmitted_(row)) continue;

    // Look up email from roster
    const sr = getShowrunnerByName_(showrunnerName);
    if (!sr || !sr.email) continue;
    const showrunnerEmail = sr.email;

    const airDate = airDateRaw instanceof Date ? airDateRaw : new Date(airDateRaw);
    airDate.setHours(0, 0, 0, 0);
    const daysOut = Math.round((airDate - today) / (1000 * 60 * 60 * 24));

    if (daysOut < 0) continue;

    // 6-day cooldown
    if (lastReminderRaw) {
      const lastReminder = lastReminderRaw instanceof Date ? lastReminderRaw : new Date(lastReminderRaw);
      const daysSinceLast = Math.round((today - lastReminder) / (1000 * 60 * 60 * 24));
      if (daysSinceLast < 6) continue;
    }

    let ccMax = false;
    let ccIan = false;
    let urgency = '';
    if      (daysOut <= CONFIG.OVERDUE_DAYS)    { urgency = 'URGENT';        ccMax = true;  ccIan = true; }
    else if (daysOut <= CONFIG.REMINDER_3_DAYS) { urgency = 'One Week Out';  ccMax = true;  ccIan = true; }
    else if (daysOut <= CONFIG.REMINDER_2_DAYS) { urgency = 'Two Weeks Out'; ccMax = true; }
    else if (daysOut <= CONFIG.REMINDER_1_DAYS) { urgency = 'Heads Up'; }
    else { continue; }

    const pendingAssets = getPendingAssets_(row);
    const subject = `[Looth Group Live] ${urgency} — ${title} (${Utilities.formatDate(airDate, TIMEZONE, 'MMM d')})`;
    const body = buildEmailBody_(showrunnerName, title, airDate, daysOut, pendingAssets, driveUrl, urgency);

    log.push(`Row ${i}: "${title}" — ${daysOut} days out — ${urgency} — to: ${showrunnerEmail}${ccMax ? ' + Max' : ''}${ccIan ? ' + Ian' : ''}`);

    if (!dryRun) {
      const options = { name: CONFIG.SENDER_NAME };
      const ccList = [ccMax ? CONFIG.MAX_EMAIL : '', ccIan ? CONFIG.IAN_EMAIL : ''].filter(Boolean);
      if (ccList.length) options.cc = ccList.join(',');
      GmailApp.sendEmail(showrunnerEmail, subject, '', { ...options, htmlBody: body });
      sheet.getRange(i, CONFIG.COL.LAST_REMINDER).setValue(new Date());
      sheet.getRange(i, CONFIG.COL.REMINDER_COUNT).setValue(reminderCount + 1);
    }
  }

  if (dryRun) {
    const msg = log.length ? log.join('\n') : 'No reminders would be sent today.';
    SpreadsheetApp.getUi().alert('DRY RUN — would send:\n\n' + msg);
  }
  Logger.log(log.join('\n'));
}

function buildEmailBody_(name, title, airDate, daysOut, pending, driveUrl, urgency) {
  const dateStr = Utilities.formatDate(airDate, TIMEZONE, 'EEEE, MMMM d, yyyy');
  const urgencyColor = urgency === 'URGENT' ? '#c0392b' : urgency === 'One Week Out' ? '#e67e22' : '#2980b9';
  const pendingList = pending.map(a => `<li>${a}</li>`).join('');
  const folderSection = driveUrl
    ? `<p><a href="${driveUrl}" style="background:#1a1a2e;color:white;padding:10px 20px;border-radius:4px;text-decoration:none;display:inline-block;margin-top:8px;">📁 Open Your Submission Folder</a></p>`
    : '<p>Your submission folder will be sent shortly.</p>';

  return `
<div style="font-family:Georgia,serif;max-width:600px;margin:0 auto;color:#222;">
  <div style="background:#1a1a2e;padding:24px 32px;border-radius:8px 8px 0 0;">
    <p style="color:#aaa;margin:0;font-size:13px;letter-spacing:2px;text-transform:uppercase;">Looth Group Live</p>
    <h2 style="color:#fff;margin:8px 0 0;font-size:22px;">${title}</h2>
    <p style="color:#ccc;margin:4px 0 0;">Airing ${dateStr} &nbsp;·&nbsp; <strong style="color:${urgencyColor}">${daysOut} days away</strong></p>
  </div>
  <div style="background:#f9f9f9;padding:28px 32px;border-radius:0 0 8px 8px;border:1px solid #e0e0e0;border-top:none;">
    <p>Hey ${name || 'there'},</p>
    <p>Just a reminder that we still need the following assets for your upcoming episode:</p>
    <ul style="line-height:2;">${pendingList}</ul>
    <p>Please drop everything into your submission folder:</p>
    ${folderSection}
    <p style="margin-top:24px;font-size:13px;color:#888;">Questions? Reply to this email or reach out to Max.<br>— The Looth Group Team</p>
  </div>
</div>`;
}

function sendRemindersDryRun() { sendReminders_(true); }
function sendRemindersLive()   { sendReminders_(false); }

// ── DAILY TRIGGER ─────────────────────────────────────────────
function installDailyTrigger() {
  // Only remove existing daily reminder triggers — don't touch onFormSubmit
  ScriptApp.getProjectTriggers()
    .filter(t => t.getHandlerFunction() === 'sendRemindersLive')
    .forEach(t => ScriptApp.deleteTrigger(t));
  ScriptApp.newTrigger('sendRemindersLive')
    .timeBased()
    .atHour(8)
    .everyDays(1)
    .create();
  SpreadsheetApp.getUi().alert('Daily reminder trigger installed — runs every morning at 8am.');
}

function removeAllTriggers() {
  if (!confirmDestructive_('Remove All Triggers',
    'This will DELETE every trigger on this project — including the daily reminder trigger AND the onFormSubmit handler. Form submissions will stop creating Episodes rows until you re-run "Create / Update Episode Form".')) return;
  ScriptApp.getProjectTriggers().forEach(t => ScriptApp.deleteTrigger(t));
  SpreadsheetApp.getUi().alert('All triggers removed.');
}

// ── SORT EPISODES ────────────────────────────────────────────
function sortEpisodesByAirDate() {
  const sheet = SpreadsheetApp.getActiveSpreadsheet().getSheetByName(CONFIG.SHEET_NAME);
  if (!sheet) { SpreadsheetApp.getUi().alert('Episodes sheet not found.'); return; }

  const lastRow = sheet.getLastRow();
  if (lastRow < 3) { SpreadsheetApp.getUi().alert('Not enough rows to sort.'); return; }

  // Sort rows 2 onward by Air Date (col C) ascending — soonest at top
  sheet.getRange(2, 1, lastRow - 1, HEADERS.length)
    .sort({ column: CONFIG.COL.AIR_DATE, ascending: false });

  // Sync view sheet to reflect new order
  try { syncViewSheet(); } catch(e) { Logger.log('syncViewSheet after sort: ' + e.message); }

  SpreadsheetApp.getUi().alert('Episodes sorted by Air Date (ascending).');
}

// ── FLUSH FORM RESPONSES ──────────────────────────────────────
function flushFormResponses() {
  const ui = SpreadsheetApp.getUi();
  const r = ui.alert(
    'Flush Form Responses',
    'This will clear all form response history and relink the form to a fresh sheet.\n\nThe Episodes sheet is NOT affected. The form URL does not change.\n\nProceed?',
    ui.ButtonSet.YES_NO
  );
  if (r !== ui.Button.YES) return;

  const ss = SpreadsheetApp.getActiveSpreadsheet();
  const formId = PropertiesService.getScriptProperties().getProperty('FORM_ID');
  if (!formId) { ui.alert('No form linked. Run "Create / Update Episode Form" first.'); return; }

  let form;
  try { form = FormApp.openById(formId); }
  catch(e) { ui.alert('Could not open form: ' + e.message); return; }

  // 1. Remove onFormSubmit trigger FIRST — prevents it firing during relink
  ScriptApp.getProjectTriggers()
    .filter(t => t.getHandlerFunction() === 'onFormSubmit')
    .forEach(t => ScriptApp.deleteTrigger(t));

  // 2. Unlink form from spreadsheet
  try { form.removeDestination(); } catch(e) { /* already unlinked */ }

  // 3. Delete all existing Form Responses sheets
  ss.getSheets()
    .filter(s => s.getName().startsWith('Form Responses'))
    .forEach(s => { try { ss.deleteSheet(s); } catch(e) {} });

  // 4. Relink — creates a fresh empty Form Responses sheet
  // Trigger is not yet installed so relink cannot fire onFormSubmit
  form.setDestination(FormApp.DestinationType.SPREADSHEET, ss.getId());

  // 5. Reinstall trigger now that relink is complete
  ScriptApp.newTrigger('onFormSubmit')
    .forSpreadsheet(ss)
    .onFormSubmit()
    .create();

  ui.alert('Done. Form Responses cleared and relinked. Trigger reinstalled.');
}

// ── COLUMN PROTECTIONS ────────────────────────────────────────
function protectAdminColumns_(sheet) {
  const adminCols = [
    CONFIG.COL.CALENDAR_EVENT_ID,
    CONFIG.COL.LAST_REMINDER,
    CONFIG.COL.REMINDER_COUNT,
    CONFIG.COL.WP_POST_URL,
    CONFIG.COL.FEATURED_IMAGE,
  ];
  adminCols.forEach(col => {
    const protection = sheet.getRange(2, col, 500, 1).protect();
    protection.setDescription('Admin only — do not edit');
    protection.setWarningOnly(true);
  });
}

// ── ON EDIT TRIGGER ───────────────────────────────────────────
function onEdit(e) {
  const sheet = e.source.getActiveSheet();
  if (sheet.getName() !== CONFIG.SHEET_NAME) return;
  const col = e.range.getColumn();
  const row = e.range.getRow();
  if (row < 2) return;

  // Apply color when Showrunner is selected
  if (col === CONFIG.COL.SHOWRUNNER) {
    applyShowrunnerColor_(sheet, row, e.range.getValue());
  }

  // Apply color when Show Name is selected
  if (col === CONFIG.COL.SHOW_NAME) {
    applyShowColor_(sheet, row, e.range.getValue());
  }

  // Auto-provision Drive folder + Calendar event when title + date are both filled
  if (col === CONFIG.COL.EPISODE_TITLE || col === CONFIG.COL.AIR_DATE) {
    const title = sheet.getRange(row, CONFIG.COL.EPISODE_TITLE).getValue();
    const date  = sheet.getRange(row, CONFIG.COL.AIR_DATE).getValue();
    if (title && date) {
      try {
        sheet.setRowHeightsForced(row, 1, 25);
        createFolderForRow_(sheet, row);
        SpreadsheetApp.flush();
        createCalendarEventForRow_(sheet, row);
      } catch(err) {
        Logger.log('onEdit provisioning error: ' + err.message);
      }
    }
  }

  // If this row is already published to WP and the edit touched a WP-relevant field,
  // remind the user to re-publish so the live event matches the sheet.
  const wpUrl = sheet.getRange(row, CONFIG.COL.WP_POST_URL).getValue();
  if (wpUrl) {
    const wpRelevantCols = [
      CONFIG.COL.EPISODE_TITLE, CONFIG.COL.SHOW_NAME, CONFIG.COL.AIR_DATE,
      CONFIG.COL.SHOWRUNNER, CONFIG.COL.TOPIC, CONFIG.COL.BLURB,
      CONFIG.COL.EVENT_TIER, CONFIG.COL.REGION, CONFIG.COL.LANGUAGE,
      CONFIG.COL.ZOOM_URL,
    ];
    if (wpRelevantCols.indexOf(col) !== -1) {
      SpreadsheetApp.getActive().toast(
        'This event is live on WP. Re-run "Publish Selected Row to WP" to push the update.',
        '⚠ WP needs update',
        8);
    }
  }

  // Mirror to view sheet on every edit
  try { syncViewSheet(); } catch(err) { Logger.log('syncViewSheet error: ' + err.message); }
}

// ── UPDATE CALENDAR EVENT ─────────────────────────────────────
function updateCalendarEventForSelectedRow() {
  const sheet = SpreadsheetApp.getActiveSpreadsheet().getSheetByName(CONFIG.SHEET_NAME);
  const rowIndex = sheet.getActiveCell().getRow();
  if (rowIndex < 2) { SpreadsheetApp.getUi().alert('Select a data row (not the header).'); return; }

  const row        = sheet.getRange(rowIndex, 1, 1, HEADERS.length).getValues()[0];
  const title      = row[CONFIG.COL.EPISODE_TITLE - 1];
  const airDate    = row[CONFIG.COL.AIR_DATE - 1];
  const topic      = row[CONFIG.COL.TOPIC - 1];
  const showrunner = row[CONFIG.COL.SHOWRUNNER - 1];
  const showName   = row[CONFIG.COL.SHOW_NAME - 1];
  const guest      = row[CONFIG.COL.GUEST - 1];
  const blurb      = row[CONFIG.COL.BLURB - 1];
  const driveUrl   = row[CONFIG.COL.DRIVE_FOLDER_URL - 1];
  const eventId    = row[CONFIG.COL.CALENDAR_EVENT_ID - 1];

  if (!eventId) {
    SpreadsheetApp.getUi().alert('No Calendar event found for this row. Use "Create Calendar Event" first.');
    return;
  }

  const cal = CalendarApp.getCalendarById(CONFIG.GOOGLE_CALENDAR_ID)
    || CalendarApp.getDefaultCalendar();
  const event = cal.getEventById(eventId);
  if (!event) {
    SpreadsheetApp.getUi().alert('Event not found in Calendar — it may have been deleted. Clear the Calendar Event ID cell and recreate it.');
    return;
  }

  // Update title
  const eventTitle = showName ? `🎙️ ${showName} — ${title}` : `🎙️ ${title}`;
  event.setTitle(eventTitle);

  // Update time
  if (airDate instanceof Date) {
    const endTime = new Date(airDate.getTime() + 60 * 60 * 1000);
    event.setTime(airDate, endTime);
  }

  // Update description
  const description = [
    showName   ? `Show: ${showName}`         : '',
    showrunner ? `Showrunner: ${showrunner}` : '',
    guest      ? `Guest: ${guest}`           : '',
    topic      ? `Topic: ${topic}`           : '',
    blurb      ? `\nBlurb:\n${blurb}`        : '',
    driveUrl   ? `Promo Assets: ${driveUrl}` : '',
  ].filter(Boolean).join('\n');
  event.setDescription(description);

  // Sync guests: showrunner + guest email(s) + other attendees
  try {
    const sr = getShowrunnerByName_(String(showrunner || '').trim());
    if (sr && sr.email) event.addGuest(sr.email);
    const guestEmailRaw = String(row[CONFIG.COL.GUEST_EMAIL - 1] || '').trim();
    if (guestEmailRaw) {
      guestEmailRaw.split(/[,;]/).map(s => s.trim()).filter(e => /@/.test(e))
        .forEach(email => { try { event.addGuest(email); } catch(e) {} });
    }
    const otherAttendeesRaw = String(row[CONFIG.COL.OTHER_ATTENDEES - 1] || '').trim();
    if (otherAttendeesRaw) {
      otherAttendeesRaw.split(/[,;]/).map(s => s.trim()).filter(e => /@/.test(e))
        .forEach(email => { try { event.addGuest(email); } catch(e) {} });
    }
  } catch(err) { Logger.log('Update guests failed: ' + err.message); }

  SpreadsheetApp.getUi().alert('Calendar event updated.');
}


// ── VIEW SHEET (read-only mirror) ────────────────────────────
function createViewSheet() {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  const source = ss.getSheetByName(CONFIG.SHEET_NAME);
  if (!source) { SpreadsheetApp.getUi().alert('Run Setup Sheet Headers first.'); return; }

  // Check if one already exists
  const existingId = PropertiesService.getScriptProperties().getProperty('VIEW_SHEET_SS_ID');
  if (existingId) {
    try {
      const existing = SpreadsheetApp.openById(existingId);
      const url = existing.getUrl();
      SpreadsheetApp.getUi().alert('View sheet already exists:\n' + url + '\n\nUse "Sync View Sheet Now" to force a refresh, or "Get View Sheet URL" to get the link.');
      return;
    } catch(e) {
      // It was deleted — fall through and create a new one
    }
  }

  // Create a new standalone spreadsheet
  const viewSs = SpreadsheetApp.create('Looth Group Live — Episodes (View Only)');
  PropertiesService.getScriptProperties().setProperty('VIEW_SHEET_SS_ID', viewSs.getId());

  // Do the first sync (Episodes tab)
  syncViewSheet_();

  // Protect the Episodes tab — warning only so it doesn't block viewing
  const episodesTab = viewSs.getSheets()[0];
  episodesTab.setName('Episodes');
  const protection = episodesTab.protect();
  protection.setDescription('Read-only mirror — do not edit');
  protection.setWarningOnly(true);

  // Create the WIP tab — fully editable, open to anyone with the link
  let wipTab = viewSs.getSheetByName(WIP_SHEET_NAME);
  if (!wipTab) wipTab = viewSs.insertSheet(WIP_SHEET_NAME);
  setupWipTabInViewSheet_(wipTab);

  // Share the whole view spreadsheet as editable (anyone with link)
  DriveApp.getFileById(viewSs.getId()).setSharing(
    DriveApp.Access.ANYONE_WITH_LINK,
    DriveApp.Permission.EDIT
  );

  // Create the Free Time Checker tab
  let checkerTab = viewSs.getSheetByName('Free Time Checker');
  if (!checkerTab) checkerTab = viewSs.insertSheet('Free Time Checker');
  setupFreeTimeCheckerTab_(checkerTab);

  const url = viewSs.getUrl();
  SpreadsheetApp.getUi().alert('View sheet created!\n\n' + url + '\n\nEpisodes tab: read-only mirror\nWork In Progress tab: editable by anyone with the link\nFree Time Checker tab: check calendar availability');
}

function syncViewSheet() {
  const existingId = PropertiesService.getScriptProperties().getProperty('VIEW_SHEET_SS_ID');
  if (!existingId) return; // no view sheet set up yet — silently skip
  syncViewSheet_();
}

function syncViewSheet_() {
  const ss     = SpreadsheetApp.getActiveSpreadsheet();
  const source = ss.getSheetByName(CONFIG.SHEET_NAME);
  if (!source) return;

  const viewSsId = PropertiesService.getScriptProperties().getProperty('VIEW_SHEET_SS_ID');
  if (!viewSsId) return;

  let viewSs;
  try { viewSs = SpreadsheetApp.openById(viewSsId); }
  catch(e) { Logger.log('syncViewSheet: could not open view sheet — ' + e.message); return; }

  const viewSheet = viewSs.getSheets()[0];

  const lastRow = source.getLastRow();
  if (lastRow < 1) return;

  const lastCol = HEADERS.length;
  const data = source.getRange(1, 1, lastRow, lastCol).getValues();

  // Hidden cols (0-based): status cols 8-14 (Blurb Status through Reminder Count), plus Featured Image (20)
  // Visible: A-H (0-7: title,show,date,showrunner,topic,guest,guestEmail,blurb),
  //          Notes(15), Event Tier(16), Region(17), Language(18), WP Post URL(19), Other Attendees(21)
  // = 14 visible columns
  const HIDDEN_COLS = new Set([8,9,10,11,12,13,14,20]);
  const viewColIndices = [...Array(lastCol).keys()].filter(i => !HIDDEN_COLS.has(i));

  // Build filtered data — only the visible columns
  const filteredData = data.map(row => viewColIndices.map(i => row[i]));
  const viewColCount = viewColIndices.length; // 13 visible columns

  // Clear and rewrite
  viewSheet.clearContents();
  viewSheet.clearFormats();
  viewSheet.getRange(1, 1, filteredData.length, viewColCount).setValues(filteredData);

  // Header styling
  const headerRange = viewSheet.getRange(1, 1, 1, viewColCount);
  headerRange.setFontWeight('bold');
  headerRange.setBackground('#1a1a2e');
  headerRange.setFontColor('#ffffff');
  viewSheet.setFrozenRows(1);

  // Column widths — 14 visible cols in view sheet order:
  // 1=Episode Title, 2=Show Name, 3=Air Date, 4=Showrunner, 5=Topic, 6=Guest, 7=Guest Email(s), 8=Blurb
  // 9=Notes, 10=Event Tier, 11=Region, 12=Language, 13=WP Post URL, 14=Other Attendees
  viewSheet.setColumnWidth(1, 220);  // Episode Title
  viewSheet.setColumnWidth(2, 160);  // Show Name
  viewSheet.setColumnWidth(3, 160);  // Air Date
  viewSheet.setColumnWidth(4, 140);  // Showrunner
  viewSheet.setColumnWidth(5, 240);  // Topic
  viewSheet.setColumnWidth(6, 160);  // Guest
  viewSheet.setColumnWidth(7, 220);  // Guest Email(s)
  viewSheet.setColumnWidth(8, 300);  // Blurb
  viewSheet.setColumnWidth(9, 200);  // Notes
  viewSheet.setColumnWidth(10, 120); // Event Tier
  viewSheet.setColumnWidth(11, 120); // Region
  viewSheet.setColumnWidth(12, 140); // Language
  viewSheet.setColumnWidth(13, 220); // WP Post URL
  viewSheet.setColumnWidth(14, 200); // Other Attendees

  // Format Air Date column (col 3 in view) as date + time
  if (lastRow > 1) {
    viewSheet.getRange(2, 3, lastRow - 1, 1)
      .setNumberFormat('dd MMM yyyy  HH:mm');
  }

  // Clip blurb (col 8), guest email (col 7), and other attendees (col 14) in view
  if (lastRow > 1) {
    viewSheet.getRange(2, 7, lastRow - 1, 1).setWrapStrategy(SpreadsheetApp.WrapStrategy.CLIP);
    viewSheet.getRange(2, 8, lastRow - 1, 1).setWrapStrategy(SpreadsheetApp.WrapStrategy.CLIP);
    viewSheet.getRange(2, 14, lastRow - 1, 1).setWrapStrategy(SpreadsheetApp.WrapStrategy.CLIP);
  }

  // Mirror showrunner and show name colors
  // In source: SHOWRUNNER=col 4, SHOW_NAME=col 2
  // In view:   SHOWRUNNER=col 4, SHOW_NAME=col 2 (same position since hidden cols start at col 8)
  if (lastRow > 1) {
    const srBgs     = source.getRange(2, CONFIG.COL.SHOWRUNNER, lastRow - 1, 1).getBackgrounds();
    const showBgs   = source.getRange(2, CONFIG.COL.SHOW_NAME,  lastRow - 1, 1).getBackgrounds();
    const srFonts   = source.getRange(2, CONFIG.COL.SHOWRUNNER, lastRow - 1, 1).getFontColors();
    const srWeights = source.getRange(2, CONFIG.COL.SHOWRUNNER, lastRow - 1, 1).getFontWeights();

    viewSheet.getRange(2, 4, lastRow - 1, 1).setBackgrounds(srBgs);
    viewSheet.getRange(2, 2, lastRow - 1, 1).setBackgrounds(showBgs);
    viewSheet.getRange(2, 4, lastRow - 1, 1).setFontColors(srFonts);
    viewSheet.getRange(2, 4, lastRow - 1, 1).setFontWeights(srWeights);
  }

  Logger.log('syncViewSheet: synced ' + lastRow + ' rows, ' + viewColCount + ' columns');

  // ── Sync WIP sheet into a second tab of the view spreadsheet ─
  syncWipToViewSheet_(ss, viewSs);
}

function setupWipTabInViewSheet_(sheet) {
  sheet.clearContents();
  sheet.clearFormats();

  // Headers
  const headerRange = sheet.getRange(1, 1, 1, WIP_HEADERS.length);
  headerRange.setValues([WIP_HEADERS]);
  headerRange.setFontWeight('bold');
  headerRange.setBackground('#1a1a2e');
  headerRange.setFontColor('#ffffff');
  sheet.setFrozenRows(1);

  // Column widths
  sheet.setColumnWidth(1, 220); // Episode Title
  sheet.setColumnWidth(2, 160); // Show Name
  sheet.setColumnWidth(3, 140); // Air Date
  sheet.setColumnWidth(4, 140); // Showrunner
  sheet.setColumnWidth(5, 240); // Topic
  sheet.setColumnWidth(6, 160); // Guest
  sheet.setColumnWidth(7, 200); // Guest Suggestion
  sheet.setColumnWidth(8, 280); // Notes

  // Showrunner dropdown — pulled from main file Config at sync time
  const showrunnerNames = getShowrunners_().map(s => s.name);
  if (showrunnerNames.length) {
    sheet.getRange(2, 4, 500, 1).setDataValidation(
      SpreadsheetApp.newDataValidation().requireValueInList(showrunnerNames, true).build()
    );
  }

  // Show Name dropdown
  const showNames = getShows_().map(s => s.name);
  if (showNames.length) {
    sheet.getRange(2, 2, 500, 1).setDataValidation(
      SpreadsheetApp.newDataValidation().requireValueInList(showNames, true).build()
    );
  }

  // Lock row heights
  sheet.setRowHeightsForced(2, 500, 25);
}

function syncWipToViewSheet_(ss, viewSs) {
  // WIP lives entirely in the view sheet — just ensure the tab exists and is set up.
  // We don't overwrite its contents since users edit it directly there.
  let wipView = viewSs.getSheetByName(WIP_SHEET_NAME);
  if (!wipView) {
    wipView = viewSs.insertSheet(WIP_SHEET_NAME);
    setupWipTabInViewSheet_(wipView);
    Logger.log('syncViewSheet: WIP tab created');
  }
  // Refresh dropdowns in case Config changed (but leave existing data alone)
  const showrunnerNames = getShowrunners_().map(s => s.name);
  const showNames = getShows_().map(s => s.name);
  if (showrunnerNames.length) {
    wipView.getRange(2, 4, 500, 1).setDataValidation(
      SpreadsheetApp.newDataValidation().requireValueInList(showrunnerNames, true).build()
    );
  }
  if (showNames.length) {
    wipView.getRange(2, 2, 500, 1).setDataValidation(
      SpreadsheetApp.newDataValidation().requireValueInList(showNames, true).build()
    );
  }
  Logger.log('syncViewSheet: WIP tab dropdowns refreshed');
}

function getViewSheetUrl() {
  const id = PropertiesService.getScriptProperties().getProperty('VIEW_SHEET_SS_ID');
  if (!id) { SpreadsheetApp.getUi().alert('No view sheet yet. Run "Create View Sheet" first.'); return; }
  try {
    const url = SpreadsheetApp.openById(id).getUrl();
    SpreadsheetApp.getUi().alert('View Sheet URL:\n' + url);
  } catch(e) {
    SpreadsheetApp.getUi().alert('View sheet not found — it may have been deleted. Run "Create View Sheet" to make a new one.');
  }
}


// ── FREE TIME CHECKER TAB ────────────────────────────────────
function setupFreeTimeCheckerTab_(sheet) {
  sheet.clearContents();
  sheet.clearFormats();

  // Merge A1:F1 for a wide header
  const h = sheet.getRange('A1:F1');
  h.merge();
  h.setValue('📅  Free Time Checker');
  h.setBackground('#1a1a2e').setFontColor('#ffffff').setFontWeight('bold')
   .setFontSize(14).setHorizontalAlignment('center').setVerticalAlignment('middle');
  sheet.setRowHeight(1, 44);

  // Subtitle
  const sub = sheet.getRange('A2:F2');
  sub.merge();
  sub.setValue('Check what\'s already booked on the Looth Group calendar before scheduling a new episode.');
  sub.setFontStyle('italic').setFontColor('#555').setHorizontalAlignment('center');
  sheet.setRowHeight(2, 28);

  // Big button cell — hyperlink filled in by updateFreeTimeCheckerLink_()
  const btn = sheet.getRange('C4:D5');
  btn.merge();
  btn.setBackground('#1a1a2e').setFontColor('#ffffff').setFontWeight('bold')
     .setFontSize(13).setHorizontalAlignment('center').setVerticalAlignment('middle')
     .setBorder(false, false, false, false, false, false);
  sheet.setRowHeight(4, 36);
  sheet.setRowHeight(5, 36);

  // Try to set the hyperlink now if the web app is already deployed
  const checkerUrl = PropertiesService.getScriptProperties().getProperty('FREE_TIME_CHECKER_URL')
    || (ScriptApp.getService().getUrl() ? ScriptApp.getService().getUrl() + '?page=checker' : '');

  // Clear the old menu instruction row
  sheet.getRange('A3').clearContent().clearFormat();

  // Merge first, flush to ensure it's applied, then set content
  btn.merge();
  SpreadsheetApp.flush();
  if (checkerUrl) {
    sheet.getRange('C4').setFormula('=HYPERLINK("' + checkerUrl + '","📅  Open Free Time Checker")');
  } else {
    sheet.getRange('C4').setValue('📅  Open Free Time Checker');
    // Hint below
    const hint = sheet.getRange('A6:F6');
    hint.merge();
    SpreadsheetApp.flush();
    sheet.getRange('A6').setValue('ℹ️  Deploy the web app first, then run "Show Free Time Checker URL" from the main sheet menu to update this link.');
    hint.setFontStyle('italic').setFontColor('#888').setFontSize(11).setHorizontalAlignment('center');
    sheet.setRowHeight(6, 28);
  }

  sheet.setColumnWidth(1, 80);
  sheet.setColumnWidth(2, 80);
  sheet.setColumnWidth(3, 160);
  sheet.setColumnWidth(4, 160);
  sheet.setColumnWidth(5, 80);
  sheet.setColumnWidth(6, 80);
  sheet.setFrozenRows(1);
}

// Called after web app deployment to update the button link in the view sheet
function updateFreeTimeCheckerLink_() {
  const checkerUrl = PropertiesService.getScriptProperties().getProperty('FREE_TIME_CHECKER_URL')
    || (ScriptApp.getService().getUrl() + '?page=checker');
  if (!checkerUrl || checkerUrl === '?page=checker') return;
  const viewSsId = PropertiesService.getScriptProperties().getProperty('VIEW_SHEET_SS_ID');
  if (!viewSsId) return;
  try {
    const viewSs = SpreadsheetApp.openById(viewSsId);
    const tab = viewSs.getSheetByName('Free Time Checker');
    if (!tab) return;
    // Re-merge to be safe, flush, then set formula on the top-left cell only
    tab.getRange('C4:D5').merge();
    SpreadsheetApp.flush();
    tab.getRange('C4').setFormula('=HYPERLINK("' + checkerUrl + '","📅  Open Free Time Checker")');
  } catch(e) {
    Logger.log('updateFreeTimeCheckerLink_ error: ' + e.message);
  }
}

// ── FREE TIME CHECKER — sidebar ───────────────────────────────
// Opens a sidebar with a date picker; shows all events for the chosen day.
// Available from the main sheet menu so showrunners can check before submitting.
function showFreeTimeChecker() {
  const html = HtmlService.createHtmlOutput(`
<!doctype html>
<html><head><meta charset="utf-8">
<style>
  * { box-sizing: border-box; }
  body { font-family: Arial, sans-serif; margin: 0; padding: 16px; color: #222; font-size: 13px; }
  h3 { margin: 0 0 12px; font-size: 15px; color: #1a1a2e; }
  label { display: block; font-weight: bold; margin-bottom: 4px; }
  input[type="date"] { width: 100%; padding: 8px; font-size: 14px; border: 1px solid #ccc; border-radius: 4px; }
  button { background: #1a1a2e; color: #fff; border: 0; padding: 9px 18px; border-radius: 4px; cursor: pointer; font-size: 13px; margin-top: 10px; width: 100%; }
  .status { margin-top: 12px; font-size: 12px; color: #888; min-height: 16px; }
  .results { margin-top: 14px; }
  .cal-name { font-size: 11px; color: #888; margin-bottom: 8px; }
  .event { padding: 8px 10px; border-radius: 4px; margin-bottom: 6px; border-left: 3px solid #1a1a2e; background: #f5f5f7; }
  .event .time { font-weight: bold; font-size: 12px; color: #1a1a2e; }
  .event .title { margin-top: 2px; }
  .empty { color: #2d5a2d; font-weight: bold; padding: 10px; background: #eef7ee; border-radius: 4px; text-align: center; }
  .err { color: #c0392b; padding: 8px; background: #fdecec; border-radius: 4px; }
</style>
</head><body>
<h3>📅 Free Time Checker</h3>
<label for="d">Pick a date</label>
<input type="date" id="d">
<button onclick="check()">Check availability</button>
<div class="status" id="status"></div>
<div class="results" id="results"></div>
<script>
  // Default to today
  var today = new Date();
  document.getElementById('d').value = today.toISOString().substring(0,10);

  function escT(s){ var d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }

  function check() {
    var v = document.getElementById('d').value;
    if (!v) return;
    document.getElementById('status').textContent = 'Checking…';
    document.getElementById('results').innerHTML = '';
    google.script.run
      .withSuccessHandler(function(res){
        document.getElementById('status').textContent = '';
        var html = '';
        if (!res.ok) {
          html = '<div class="err">Error: ' + escT(res.error) + '</div>';
        } else if (!res.events.length) {
          html = '<div class="empty">✓ No events on this day — slot is free!</div>';
        } else {
          html += '<div class="cal-name">Calendar: ' + escT(res.calendarName) + '</div>';
          res.events.forEach(function(ev){
            html += '<div class="event">'
              + '<div class="time">' + (ev.allDay ? 'All day' : escT(ev.start) + ' – ' + escT(ev.end)) + '</div>'
              + '<div class="title">' + escT(ev.title) + '</div>'
              + '</div>';
          });
        }
        document.getElementById('results').innerHTML = html;
      })
      .withFailureHandler(function(err){
        document.getElementById('status').textContent = 'Error: ' + err.message;
      })
      .getCalendarEvents(v);
  }
</script>
</body></html>
  `).setTitle('Free Time Checker').setWidth(320);
  SpreadsheetApp.getUi().showSidebar(html);
}

// ── FREE TIME CHECKER WEB APP PAGE ───────────────────────────
function buildFreeTimeCheckerHtml_() {
  return `<!doctype html>
<html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Free Time Checker — Looth Group Live</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: Arial, sans-serif; background: #f5f5f7; margin: 0; padding: 24px; color: #222; }
  .card { max-width: 500px; margin: 0 auto; background: #fff; border-radius: 10px; box-shadow: 0 2px 12px rgba(0,0,0,.08); overflow: hidden; }
  .header { background: #1a1a2e; padding: 24px 28px; }
  .header .sub { color: #aaa; font-size: 12px; letter-spacing: 2px; text-transform: uppercase; margin-bottom: 6px; }
  .header h1 { color: #fff; margin: 0; font-size: 22px; }
  .body { padding: 22px 28px 28px; }
  label { display: block; font-weight: bold; font-size: 13px; margin-bottom: 6px; }
  input[type="date"] { width: 100%; padding: 10px; font-size: 15px; border: 1px solid #ccc; border-radius: 4px; }
  button { background: #1a1a2e; color: #fff; border: 0; padding: 11px 20px; border-radius: 4px; cursor: pointer; font-size: 14px; margin-top: 12px; width: 100%; font-weight: bold; }
  button:disabled { opacity: .5; cursor: wait; }
  .status { margin-top: 10px; font-size: 12px; color: #888; min-height: 16px; }
  .results { margin-top: 16px; }
  .cal-name { font-size: 11px; color: #888; margin-bottom: 10px; }
  .event { padding: 10px 12px; border-radius: 6px; margin-bottom: 8px; border-left: 4px solid #1a1a2e; background: #f5f5f7; }
  .event .time { font-weight: bold; font-size: 13px; color: #1a1a2e; }
  .event .title { margin-top: 3px; font-size: 14px; }
  .empty { color: #2d5a2d; font-weight: bold; padding: 14px; background: #eef7ee; border-radius: 6px; text-align: center; font-size: 15px; }
  .err { color: #c0392b; padding: 10px; background: #fdecec; border-radius: 6px; }
</style>
</head><body>
<div class="card">
  <div class="header">
    <div class="sub">Looth Group Live</div>
    <h1>📅 Free Time Checker</h1>
  </div>
  <div class="body">
    <label for="d">Pick a date to check availability</label>
    <input type="date" id="d">
    <button id="btn" onclick="check()">Check calendar</button>
    <div class="status" id="status"></div>
    <div class="results" id="results"></div>
  </div>
</div>
<script>
  var today = new Date();
  document.getElementById('d').value = today.toISOString().substring(0,10);

  function escT(s){ var d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }

  function check() {
    var v = document.getElementById('d').value;
    if (!v) return;
    var btn = document.getElementById('btn');
    btn.disabled = true;
    document.getElementById('status').textContent = 'Checking…';
    document.getElementById('results').innerHTML = '';
    google.script.run
      .withSuccessHandler(function(res){
        btn.disabled = false;
        document.getElementById('status').textContent = '';
        var html = '';
        if (!res.ok) {
          html = '<div class="err">Error: ' + escT(res.error) + '</div>';
        } else if (!res.events.length) {
          html = '<div class="empty">✓ No events on this day — time slot is free!</div>';
        } else {
          html += '<div class="cal-name">Calendar: ' + escT(res.calendarName) + '</div>';
          res.events.forEach(function(ev){
            html += '<div class="event">'
              + '<div class="time">' + (ev.allDay ? 'All day' : escT(ev.start) + ' – ' + escT(ev.end)) + '</div>'
              + '<div class="title">' + escT(ev.title) + '</div>'
              + '</div>';
          });
        }
        document.getElementById('results').innerHTML = html;
      })
      .withFailureHandler(function(err){
        btn.disabled = false;
        document.getElementById('status').textContent = 'Error: ' + err.message;
      })
      .getCalendarEvents(v);
  }
</script>
</body></html>`;
}
function setCheckerUrl() {
  // Run this once after deploying the public web app to store the correct URL.
  // Replace the URL below with your actual /exec URL.
  const ui = SpreadsheetApp.getUi();
  const r = ui.prompt(
    'Set Free Time Checker URL',
    'Paste the full /exec?page=checker URL of the public web app deployment:',
    ui.ButtonSet.OK_CANCEL
  );
  if (r.getSelectedButton() !== ui.Button.OK) return;
  const url = r.getResponseText().trim();
  if (!url || !url.includes('/exec')) { ui.alert('Invalid URL — must contain /exec'); return; }
  PropertiesService.getScriptProperties().setProperty('FREE_TIME_CHECKER_URL', url);
  updateFreeTimeCheckerLink_();
  ui.alert('Saved! View sheet button updated.');
}

function showFreeTimeCheckerUrl() {
  const stored = PropertiesService.getScriptProperties().getProperty('FREE_TIME_CHECKER_URL');
  if (stored) {
    updateFreeTimeCheckerLink_();
    SpreadsheetApp.getUi().alert('Free Time Checker URL:\n\n' + stored + '\n\nThe view sheet button has been updated.');
    return;
  }
  // Fall back to ScriptApp (may return domain-restricted URL)
  const url = ScriptApp.getService().getUrl();
  if (!url) {
    SpreadsheetApp.getUi().alert(
      'No URL stored yet.\n\nRun "Set Free Time Checker URL…" from the menu and paste in the public /exec?page=checker URL.'
    );
    return;
  }
  const checkerUrl = url + '?page=checker';
  updateFreeTimeCheckerLink_();
  SpreadsheetApp.getUi().alert('Free Time Checker URL:\n\n' + checkerUrl + '\n\nIf this looks wrong (contains /a/loothgroup.com/), run "Set Free Time Checker URL…" to override it.');
}

// ── GOOGLE FORM ───────────────────────────────────────────────
function createOrUpdateEpisodeForm() {
  // Always confirm — this wipes all form questions and the Form Responses sheet
  if (!confirmDestructive_('Create / Update Episode Form',
    'This will DELETE all questions on the linked form and DELETE the "Form Responses" sheet, then rebuild from scratch. Existing submissions in the Episodes sheet are NOT affected, but historical Form Responses rows will be lost.')) return;
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  const showrunners = getShowrunners_().map(s => s.name);
  const shows = getShows_().map(s => s.name);

  // Check if a form is already linked
  const existingEditUrl = PropertiesService.getScriptProperties().getProperty('FORM_EDIT_URL');
  let form;

  if (existingEditUrl) {
    try {
      form = FormApp.openByUrl(existingEditUrl);
      form.getItems().forEach(item => form.deleteItem(item));
    } catch(e) {
      form = null;
    }
  }

  if (!form) {
    form = FormApp.create('🎙️ Looth Group Live — New Episode');
  }

  form.setTitle('🎙️ Looth Group Live — New Episode')
    .setDescription('Fill in the details for a new episode. A Drive folder and Calendar event will be created automatically.')
    .setCollectEmail(true)  // needed so we can email the Drive folder link after submission
    .setShowLinkToRespondAgain(true)
    .setConfirmationMessage('Episode submitted! The Drive folder and Calendar event will be created shortly. You will receive the folder link by email.');

  // Episode Title
  form.addTextItem().setTitle('Episode Title').setRequired(true);

  // Show Name
  const showItem = form.addListItem();
  showItem.setTitle('Show Name').setRequired(true);
  if (shows.length) showItem.setChoiceValues(shows);

  // Air Date — native date picker
  form.addDateItem()
    .setTitle('Air Date')
    .setHelpText('The episode air date (Eastern Time).')
    .setRequired(true);

  // Air Time — dropdown of half-hour slots
  const timeSlots = [];
  for (let h = 0; h < 24; h++) {
    ['00', '30'].forEach(m => {
      const hour12 = h % 12 === 0 ? 12 : h % 12;
      const ampm   = h < 12 ? 'AM' : 'PM';
      timeSlots.push(`${String(hour12).padStart(2,'0')}:${m} ${ampm} ET`);
    });
  }
  form.addListItem()
    .setTitle('Air Time (Eastern Time)')
    .setHelpText('Select the start time of the episode.')
    .setChoiceValues(timeSlots)
    .setRequired(true);

  // Showrunner
  const srItem = form.addListItem();
  srItem.setTitle('Showrunner').setRequired(true);
  if (showrunners.length) srItem.setChoiceValues(showrunners);

  // Event Tier — required for WP publish
  form.addListItem()
    .setTitle('Event Tier')
    .setHelpText('Who can see this event on the site.')
    .setChoiceValues(CONFIG.WP_TIER_OPTIONS)
    .setRequired(true);

  // Region (optional)
  const regions = getRegions_();
  if (regions.length) {
    form.addListItem()
      .setTitle('Region')
      .setHelpText('Optional. Where the event is geographically based.')
      .setChoiceValues(regions)
      .setRequired(false);
  }

  // Language (optional, multi-select)
  form.addCheckboxItem()
    .setTitle('Language')
    .setHelpText('Optional. Pick all languages this episode will be spoken in.')
    .setChoiceValues(['English','Spanish','Portuguese','Italian','French','German','Dutch','Swedish'])
    .setRequired(false);

  // Guest
  form.addTextItem()
    .setTitle('Guest Name')
    .setHelpText('Leave blank if no guest.')
    .setRequired(false);

  // Guest email(s)
  form.addTextItem()
    .setTitle('Guest Email(s)')
    .setHelpText('Optional. Email address(es) of the guest(s) — comma-separated if multiple. They will receive a Google Calendar invitation.')
    .setRequired(false);

  // Topic
  form.addTextItem().setTitle('Topic / Description').setRequired(false);

  // Blurb
  form.addParagraphTextItem()
    .setTitle('Blurb Text')
    .setHelpText('The episode description shown to the audience.')
    .setRequired(false);

  // Notes
  form.addParagraphTextItem()
    .setTitle('Notes')
    .setHelpText('Internal notes — not shown publicly.')
    .setRequired(false);

  // ── Link form to this spreadsheet automatically ─────────────
  // Remove existing destination first (avoids duplicate response sheets)
  try { form.removeDestination(); } catch(e) {}

  // Delete any stale Form Responses sheets so we start clean
  ss.getSheets()
    .filter(s => s.getName().startsWith('Form Responses'))
    .forEach(s => {
      try { ss.deleteSheet(s); } catch(e) {}
    });

  // Re-link — this creates a fresh Form Responses sheet with correct columns
  form.setDestination(FormApp.DestinationType.SPREADSHEET, ss.getId());

  // Save URLs
  PropertiesService.getScriptProperties().setProperty('FORM_EDIT_URL',    form.getEditUrl());
  PropertiesService.getScriptProperties().setProperty('FORM_PUBLISH_URL', form.getPublishedUrl());
  PropertiesService.getScriptProperties().setProperty('FORM_ID',          form.getId());
  PropertiesService.getScriptProperties().setProperty('SPREADSHEET_ID',   ss.getId());

  // Install trigger — remove any stale duplicates first
  ScriptApp.getProjectTriggers()
    .filter(t => t.getHandlerFunction() === 'onFormSubmit')
    .forEach(t => ScriptApp.deleteTrigger(t));

  ScriptApp.newTrigger('onFormSubmit')
    .forSpreadsheet(ss)
    .onFormSubmit()
    .create();

  const ui = SpreadsheetApp.getUi();
  ui.alert(
    'Form ready and linked!\n\n' +
    'Edit URL (manage the form):\n' + form.getEditUrl() + '\n\n' +
    'Share URL (for data entry):\n' + form.getPublishedUrl()
  );
}

function getFormUrls() {
  const editUrl    = PropertiesService.getScriptProperties().getProperty('FORM_EDIT_URL');
  const publishUrl = PropertiesService.getScriptProperties().getProperty('FORM_PUBLISH_URL');
  if (!editUrl) {
    SpreadsheetApp.getUi().alert('No form created yet. Run "Create / Update Episode Form" first.');
    return;
  }
  SpreadsheetApp.getUi().alert(
    'Episode Entry Form\n\n' +
    'Edit URL (manage form):\n' + editUrl + '\n\n' +
    'Share URL (data entry):\n' + publishUrl
  );
}

// ── FORM SUBMIT HANDLER ─────────────────────────────────────
// Uses e.namedValues from a spreadsheet-level onFormSubmit trigger.
// Field names must exactly match the form question titles.
function onFormSubmit(e) {
  try {
    const ss = SpreadsheetApp.getActiveSpreadsheet();
    const sheet = ss.getSheetByName(CONFIG.SHEET_NAME);
    if (!sheet) { Logger.log('onFormSubmit: Episodes sheet not found'); return; }

    // e.namedValues is a map of { 'Question Title': ['answer'] }
    // This is populated reliably on spreadsheet-level triggers
    const r = e.namedValues || {};
    Logger.log('onFormSubmit namedValues keys: ' + JSON.stringify(Object.keys(r)));
    Logger.log('onFormSubmit namedValues: ' + JSON.stringify(r));

    function val(key) { return ((r[key] || [''])[0] || '').toString().trim(); }

    const title      = val('Episode Title');
    const showName   = val('Show Name');
    const airDateRaw = val('Air Date');
    const airTimeRaw = val('Air Time (Eastern Time)');
    const showrunner = val('Showrunner');
    const eventTier  = val('Event Tier');
    const region     = val('Region');
    const language   = (r['Language'] || []).join(', '); // checkbox question — multi-value
    const guest      = val('Guest Name');
    const guestEmail = val('Guest Email(s)');
    const topic      = val('Topic / Description');
    const blurb      = val('Blurb Text');
    const notes      = val('Notes');

    Logger.log('onFormSubmit parsed — title: ' + title + ', show: ' + showName + ', showrunner: ' + showrunner + ', date: ' + airDateRaw + ', time: ' + airTimeRaw);

    if (!title) { Logger.log('onFormSubmit: no title found, skipping'); return; }

    // Build air date + time — always interpreted as Eastern Time (TIMEZONE)
    // Google Forms date picker sends MM/DD/YYYY; time dropdown sends e.g. "02:00 PM ET"
    let airDate = null;
    if (airDateRaw) {
      const dateParts = airDateRaw.match(/(\d{1,2})\/(\d{1,2})\/(\d{4})/);
      if (dateParts) {
        const yyyy = dateParts[3];
        const mm   = dateParts[1].padStart(2, '0');
        const dd   = dateParts[2].padStart(2, '0');
        // Parse time from dropdown e.g. "02:00 PM ET"
        let hh = '00', mi = '00';
        const timeParts = airTimeRaw.match(/(\d{1,2}):(\d{2})\s+(AM|PM)/i);
        if (timeParts) {
          let hours = parseInt(timeParts[1]) % 12;
          if (timeParts[3].toUpperCase() === 'PM') hours += 12;
          hh = String(hours).padStart(2, '0');
          mi = timeParts[2];
        }
        // Use getTzOffsetString_ to pin the time to TIMEZONE (America/New_York)
        // This is the same method used by the air date picker sidebar
        const tzOffset = getTzOffsetString_(yyyy, mm, dd, hh, mi);
        airDate = new Date(`${yyyy}-${mm}-${dd} ${hh}:${mi}:00 ${tzOffset}`);
        if (isNaN(airDate.getTime())) {
          Logger.log('onFormSubmit: date construction failed, falling back');
          airDate = null;
        } else {
          Logger.log('onFormSubmit: airDate parsed as ET: ' + Utilities.formatDate(airDate, TIMEZONE, 'dd MMM yyyy HH:mm z'));
        }
      } else {
        Logger.log('onFormSubmit: could not parse date: ' + airDateRaw);
      }
    }

    // Find the true last data row by scanning Episode Title column
    // getLastRow() is unreliable when validation rules are applied to empty rows
    const titleCol = sheet.getRange(2, CONFIG.COL.EPISODE_TITLE, sheet.getMaxRows() - 1, 1).getValues();
    let newRow = 2;
    for (let i = titleCol.length - 1; i >= 0; i--) {
      if (titleCol[i][0] !== '') { newRow = i + 3; break; }
    }
    Logger.log('onFormSubmit: writing to Episodes row ' + newRow);

    sheet.setRowHeightsForced(newRow, 1, 25); // prevent blurb from expanding row
    sheet.getRange(newRow, CONFIG.COL.EPISODE_TITLE).setValue(title);
    sheet.getRange(newRow, CONFIG.COL.SHOW_NAME).setValue(showName);
    if (airDate) {
      const dateCell = sheet.getRange(newRow, CONFIG.COL.AIR_DATE);
      dateCell.setNumberFormat('dd MMM yyyy  HH:mm');
      dateCell.setValue(airDate);
    }
    sheet.getRange(newRow, CONFIG.COL.SHOWRUNNER).setValue(showrunner);
    sheet.getRange(newRow, CONFIG.COL.GUEST).setValue(guest);
    sheet.getRange(newRow, CONFIG.COL.GUEST_EMAIL).setValue(guestEmail);
    sheet.getRange(newRow, CONFIG.COL.TOPIC).setValue(topic);
    sheet.getRange(newRow, CONFIG.COL.BLURB).setValue(blurb);
    sheet.getRange(newRow, CONFIG.COL.NOTES).setValue(notes);
    if (eventTier) sheet.getRange(newRow, CONFIG.COL.EVENT_TIER).setValue(eventTier);
    if (region)    sheet.getRange(newRow, CONFIG.COL.REGION).setValue(region);
    if (language)  sheet.getRange(newRow, CONFIG.COL.LANGUAGE).setValue(language);

    // Zoom Link — the Google Form has no Zoom question, so a form-created row gets
    // the standing default room (still editable in the sheet before publishing).
    sheet.getRange(newRow, CONFIG.COL.ZOOM_URL).setValue(CONFIG.DEFAULT_ZOOM_URL);

    // Default statuses — Blurb flips to Submitted if blurb text was provided in form;
    // image asset statuses stay Pending (Google Form has no image upload).
    const formBlurbProvided = !!(blurb && blurb.trim());
    sheet.getRange(newRow, CONFIG.COL.BLURB_STATUS).setValue(formBlurbProvided ? STATUS.SUBMITTED : STATUS.PENDING);
    [
      CONFIG.COL.H_THUMB_STATUS, CONFIG.COL.V_THUMB_STATUS,
    ].forEach(col => sheet.getRange(newRow, col).setValue(STATUS.PENDING));

    // Colors
    if (showrunner) applyShowrunnerColor_(sheet, newRow, showrunner);
    if (showName)   applyShowColor_(sheet, newRow, showName);

    // Provision Drive folder + Calendar event + txt file
    // Check for calendar clash first — if clash found, still create the row and folder
    // but skip the calendar event and flag it in the notes
    if (airDate) {
      const clashes = getCalendarClashes_(airDate);
      if (clashes.length) {
        const clashMsg = clashes.map(c => c.title + ' (' + c.start + ' – ' + c.end + ')').join('; ');
        Logger.log('onFormSubmit: calendar clash detected — ' + clashMsg);
        const existing = String(sheet.getRange(newRow, CONFIG.COL.NOTES).getValue() || '');
        sheet.getRange(newRow, CONFIG.COL.NOTES).setValue(
          (existing ? existing + '\n' : '') +
          '⚠ CALENDAR CLASH: ' + clashMsg + '. Event not created — please reschedule.'
        );
      } else {
        // Folder must be created first so Drive URL is in sheet before calendar event reads it
        createFolderForRow_(sheet, newRow);
        SpreadsheetApp.flush(); // ensure Drive URL is written before calendar reads it
        createCalendarEventForRow_(sheet, newRow);
      }
    } else {
      createFolderForRow_(sheet, newRow);
    }

    // Email submitter the Drive folder link
    const driveUrl = sheet.getRange(newRow, CONFIG.COL.DRIVE_FOLDER_URL).getValue();
    const submitterEmail = val('Email address') || val('Email Address') || (e.response ? e.response.getRespondentEmail() : '');
    if (driveUrl && submitterEmail) {
      const subject = '[Looth Group Live] Drive folder ready — ' + title;
      const body =
        '<div style="font-family:Arial,sans-serif;max-width:600px;color:#222;">' +
        '<div style="background:#1a1a2e;padding:20px 28px;border-radius:8px 8px 0 0;">' +
        '<p style="color:#aaa;margin:0;font-size:12px;text-transform:uppercase;letter-spacing:2px;">Looth Group Live</p>' +
        '<h2 style="color:#fff;margin:8px 0 0;">' + title + '</h2></div>' +
        '<div style="background:#f9f9f9;padding:24px 28px;border-radius:0 0 8px 8px;border:1px solid #e0e0e0;border-top:none;">' +
        '<p>Your episode has been submitted. Drop your promo assets into the folder below:</p>' +
        '<p>The horizontal thumbnail for the event should be named <code>featured.*</code> (e.g. <code>featured.jpg</code>).</p>' +
        '<p><a href="' + driveUrl + '" style="background:#1a1a2e;color:white;padding:10px 20px;border-radius:4px;text-decoration:none;display:inline-block;">Open Episode Folder</a></p>' +
        '<p style="font-size:13px;color:#888;margin-top:20px;">Questions? Reply to this email or reach out to Max.<br>— The Looth Group Team</p>' +
        '</div></div>';
      try {
        GmailApp.sendEmail(submitterEmail, subject, '', { htmlBody: body, name: CONFIG.SENDER_NAME });
      } catch(err) {
        Logger.log('Could not send folder email: ' + err.message);
      }
    }

    Logger.log('onFormSubmit: done — row ' + newRow + ' for "' + title + '"');

  } catch(err) {
    Logger.log('onFormSubmit ERROR: ' + err.message + '\n' + err.stack);
  }
}

// ============================================================
// ── WP REST BRIDGE ──────────────────────────────────────────
// Publishes a row to the WordPress `event` CPT on dev (or prod).
// Required Script Properties (set via "Set WP Credentials…" menu):
//   WP_BASE_URL       e.g. https://dev.loothgroup.com
//   WP_USERNAME       e.g. sheets-bot
//   WP_APP_PASSWORD   the WP Application Password (no spaces)
// ============================================================

function setWpCredentials() {
  const ui = SpreadsheetApp.getUi();
  const props = PropertiesService.getScriptProperties();
  const r1 = ui.prompt('WP Base URL', 'e.g. https://dev.loothgroup.com (no trailing slash)', ui.ButtonSet.OK_CANCEL);
  if (r1.getSelectedButton() !== ui.Button.OK) return;
  const baseUrl = r1.getResponseText().trim().replace(/\/$/, '');
  if (!baseUrl) { ui.alert('Base URL required.'); return; }

  const r2 = ui.prompt('WP Username', 'e.g. sheets-bot', ui.ButtonSet.OK_CANCEL);
  if (r2.getSelectedButton() !== ui.Button.OK) return;
  const username = r2.getResponseText().trim();
  if (!username) { ui.alert('Username required.'); return; }

  const r3 = ui.prompt('WP Application Password', 'Paste the Application Password (with or without spaces).', ui.ButtonSet.OK_CANCEL);
  if (r3.getSelectedButton() !== ui.Button.OK) return;
  const password = r3.getResponseText().replace(/\s+/g, '');
  if (!password) { ui.alert('Password required.'); return; }

  props.setProperty('WP_BASE_URL', baseUrl);
  props.setProperty('WP_USERNAME', username);
  props.setProperty('WP_APP_PASSWORD', password);
  ui.alert('Saved. Use "Test WP Connection" to verify.');
}

function wpConfig_() {
  const p = PropertiesService.getScriptProperties();
  const baseUrl = p.getProperty('WP_BASE_URL') || CONFIG.WP_DEFAULT_BASE_URL;
  const username = p.getProperty('WP_USERNAME') || '';
  const password = p.getProperty('WP_APP_PASSWORD') || '';
  if (!username || !password) {
    throw new Error('WP credentials not set. Run "Set WP Credentials…" first.');
  }
  return { baseUrl, username, password };
}

function wpRequest_(path, method, payload) {
  const cfg = wpConfig_();
  const url = cfg.baseUrl + path;
  const headers = {
    Authorization: 'Basic ' + Utilities.base64Encode(cfg.username + ':' + cfg.password),
  };
  const opts = {
    method: method || 'get',
    headers: headers,
    muteHttpExceptions: true,
    contentType: 'application/json',
  };
  if (payload !== undefined && payload !== null) {
    opts.payload = JSON.stringify(payload);
  }
  const resp = UrlFetchApp.fetch(url, opts);
  const code = resp.getResponseCode();
  const text = resp.getContentText();
  let json = null;
  try { json = JSON.parse(text); } catch(e) {}
  if (code < 200 || code >= 300) {
    const msg = (json && (json.message || json.code)) ? (json.code + ': ' + json.message) : ('HTTP ' + code + ' — ' + text.substring(0, 400));
    throw new Error(msg);
  }
  return json;
}

function testWpConnection() {
  const ui = SpreadsheetApp.getUi();
  try {
    const res = wpRequest_('/wp-json/loothdev/v1/user-search?per_page=1', 'get', null);
    ui.alert('OK — connected. Sample user: ' + (res[0] ? res[0].display_name + ' (id ' + res[0].id + ')' : '(no users returned)'));
  } catch(err) {
    ui.alert('FAILED: ' + err.message);
  }
}

function resolveWpUserIds() {
  const ui = SpreadsheetApp.getUi();
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  const sheet = ss.getSheetByName(CONFIG_SHEET_NAME);
  if (!sheet) { ui.alert('Config sheet not found. Run "Setup Config Sheet" first.'); return; }

  const data = sheet.getRange('A3:E52').getValues();
  let resolved = 0, skipped = 0, missing = 0;
  const report = [];
  for (let i = 0; i < data.length; i++) {
    const name  = String(data[i][0]).trim();
    const email = String(data[i][1]).trim();
    const id    = parseInt(data[i][3]);
    if (!name) continue;
    if (id > 0) { skipped++; report.push(name + ': already has ID ' + id); continue; }
    if (!email) { missing++; report.push(name + ': NO EMAIL — skipped'); continue; }
    try {
      const res = wpRequest_('/wp-json/loothdev/v1/user-search?email=' + encodeURIComponent(email), 'get', null);
      if (res && res.length && res[0].id) {
        sheet.getRange(i + 3, 4).setValue(res[0].id);
        resolved++;
        report.push(name + ' (' + email + ') → ' + res[0].id);
      } else {
        report.push(name + ' (' + email + '): no WP user found');
      }
    } catch(err) {
      report.push(name + ': ERROR — ' + err.message);
    }
  }
  ui.alert('Resolved ' + resolved + ' · already-had ' + skipped + ' · missing-email ' + missing + '\n\n' + report.join('\n'));
}

// ── Featured image helpers ───────────────────────────────────
function slugify_(s) {
  return String(s || '').toLowerCase()
    .replace(/&/g, ' and ')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');
}

function monthName_(d) {
  return Utilities.formatDate(d, TIMEZONE, 'MMMM').toLowerCase();
}

function buildFeaturedFilename_(airDate, showName, originalExt) {
  const month = monthName_(airDate);
  const slug  = slugify_(showName) || 'show';
  const rand  = String(Math.floor(1000 + Math.random() * 9000));
  const ext   = (originalExt || 'jpg').toLowerCase().replace(/^\./, '');
  return `${month}-${slug}-${rand}.${ext}`;
}

// Find a featured image file in the row's Drive folder.
// Priority: file named "featured.*" → first JPG/WEBP/PNG that isn't "episode-info"
function pickFeaturedImageFromFolder_(folder) {
  const imageMimes = ['image/jpeg', 'image/webp', 'image/png'];
  // 1) Look for files starting with "featured"
  const all = [];
  const it = folder.getFiles();
  while (it.hasNext()) {
    const f = it.next();
    const name = f.getName();
    if (name.startsWith('episode-info')) continue;
    const mime = f.getMimeType();
    if (imageMimes.indexOf(mime) === -1) continue;
    all.push(f);
  }
  const featured = all.find(f => /^featured\b/i.test(f.getName()));
  if (featured) return featured;
  // 2) Fallback: any image file (largest = most likely featured)
  if (!all.length) return null;
  all.sort((a, b) => b.getSize() - a.getSize());
  return all[0];
}

// ── Publish row → WP ─────────────────────────────────────────
function publishSelectedRowToWp() {
  const ui = SpreadsheetApp.getUi();
  const sheet = SpreadsheetApp.getActiveSpreadsheet().getSheetByName(CONFIG.SHEET_NAME);
  if (!sheet) { ui.alert('Episodes sheet not found.'); return; }
  const rowIndex = sheet.getActiveCell().getRow();
  if (rowIndex < 2) { ui.alert('Select a data row (not the header).'); return; }
  try {
    publishRowToWp_(rowIndex, /*allowDraftOverride*/ true);
  } catch(err) {
    ui.alert('Publish failed:\n\n' + err.message);
  }
}

function publishRowToWp_(rowIndex, allowDraftOverride) {
  const ui = SpreadsheetApp.getUi();
  const sheet = SpreadsheetApp.getActiveSpreadsheet().getSheetByName(CONFIG.SHEET_NAME);
  const row = sheet.getRange(rowIndex, 1, 1, HEADERS.length).getValues()[0];

  const title       = String(row[CONFIG.COL.EPISODE_TITLE - 1] || '').trim();
  const showName    = String(row[CONFIG.COL.SHOW_NAME - 1] || '').trim();
  const airDateRaw  = row[CONFIG.COL.AIR_DATE - 1];
  const showrunner  = String(row[CONFIG.COL.SHOWRUNNER - 1] || '').trim();
  const topic       = String(row[CONFIG.COL.TOPIC - 1] || '').trim();
  const blurb       = String(row[CONFIG.COL.BLURB - 1] || '').trim();
  const driveUrl    = String(row[CONFIG.COL.DRIVE_FOLDER_URL - 1] || '').trim();
  const tier        = String(row[CONFIG.COL.EVENT_TIER - 1] || '').trim();
  const region      = String(row[CONFIG.COL.REGION - 1] || '').trim();
  const languageRaw = String(row[CONFIG.COL.LANGUAGE - 1] || '').trim();
  const zoom        = String(row[CONFIG.COL.ZOOM_URL - 1] || '').trim();
  const wpPostUrl   = String(row[CONFIG.COL.WP_POST_URL - 1] || '').trim();

  // Resolve showrunner → WP user ID via Config
  const sr = getShowrunnerByName_(showrunner);
  const authorId = sr ? sr.wpUserId : 0;

  // Resolve featured image (look in row's Drive folder)
  let featuredImageFile = null;
  if (driveUrl) {
    const m = driveUrl.match(/[-\w]{25,}/);
    if (m) {
      try {
        const folder = DriveApp.getFolderById(m[0]);
        featuredImageFile = pickFeaturedImageFromFolder_(folder);
      } catch(e) { /* ignore — will fail validation below */ }
    }
  }

  // Validate
  const missing = [];
  if (!title)      missing.push('Episode Title');
  if (!airDateRaw) missing.push('Air Date');
  if (!showrunner) missing.push('Showrunner');
  if (!authorId)   missing.push('Showrunner → WP User ID (run "Resolve WP User IDs")');
  if (!tier)       missing.push('Event Tier');
  if (!featuredImageFile) missing.push('Featured Image (no `featured.*` file in Drive folder)');

  let status = 'publish';
  if (missing.length) {
    if (!allowDraftOverride) throw new Error('Validation failed: ' + missing.join(', '));
    const ans = ui.alert(
      'Validation failed',
      'Missing required fields:\n  • ' + missing.join('\n  • ') + '\n\nPost as DRAFT instead?',
      ui.ButtonSet.YES_NO);
    if (ans !== ui.Button.YES) return;
    status = 'draft';
  }

  // Build airDate
  const airDate = airDateRaw instanceof Date ? airDateRaw : new Date(airDateRaw);
  const startDate = Utilities.formatDate(airDate, TIMEZONE, 'yyyy-MM-dd');
  const timeOfEvent = Utilities.formatDate(airDate, TIMEZONE, 'h:mm a').toLowerCase();

  // Existing WP post? Parse ID from URL (?p=NNN) or trailing /NNN/
  let wpPostId = 0;
  if (wpPostUrl) {
    const m1 = wpPostUrl.match(/[?&]p=(\d+)/);
    const m2 = wpPostUrl.match(/wp_post_id=(\d+)/);
    if (m1) wpPostId = parseInt(m1[1]);
    else if (m2) wpPostId = parseInt(m2[1]);
  }

  // Build payload
  const payload = {
    title:         title,
    author_id:     authorId,
    status:        status,
    start_date:    startDate,
    time_of_event: timeOfEvent,
    tier:          tier || 'Public', // fallback if draft override and tier missing
    blurb:         blurb,
    topic:         topic,
  };
  if (wpPostId > 0) payload.wp_post_id = wpPostId;
  if (region) payload.region = region;
  if (languageRaw) {
    payload.languages = languageRaw.split(',').map(s => s.trim()).filter(Boolean);
  }
  if (zoom) payload.zoom_url = zoom; // gated Join CTA on the event page

  // Attach image if present
  if (featuredImageFile) {
    const blob = featuredImageFile.getBlob();
    const origName = featuredImageFile.getName();
    const extMatch = origName.match(/\.([a-z0-9]+)$/i);
    const ext = extMatch ? extMatch[1] : 'jpg';
    const filename = buildFeaturedFilename_(airDate, showName, ext);
    payload.image = {
      filename: filename,
      mime: blob.getContentType(),
      data_b64: Utilities.base64Encode(blob.getBytes()),
    };
  }

  const result = wpRequest_('/wp-json/loothdev/v1/events', 'post', payload);
  if (!result || !result.ok) {
    throw new Error('Unexpected response: ' + JSON.stringify(result));
  }

  // Write WP Post URL back
  sheet.getRange(rowIndex, CONFIG.COL.WP_POST_URL).setValue(result.view_url || result.edit_url);

  ui.alert(
    'Posted to WP as ' + result.status.toUpperCase() + '!\n\n' +
    'View: ' + (result.view_url || '(n/a)') + '\n' +
    'Edit: ' + (result.edit_url || '(n/a)') + '\n\n' +
    'Post ID: ' + result.wp_post_id
  );
}

// ============================================================
// ── AIR DATE + TIME PICKER (HTML sidebar) ───────────────────
// Uses browser-native <input type="datetime-local"> — calendar
// + time selector in one widget. Writes a real Date object back
// to the Air Date cell of the currently selected row.
// ============================================================

function showAirDateTimePicker() {
  const sheet = SpreadsheetApp.getActiveSpreadsheet().getActiveSheet();
  if (sheet.getName() !== CONFIG.SHEET_NAME) {
    SpreadsheetApp.getUi().alert('Open the Episodes tab and select a row first.');
    return;
  }
  const row = sheet.getActiveCell().getRow();
  if (row < 2) {
    SpreadsheetApp.getUi().alert('Select a data row (not the header).');
    return;
  }

  // Pre-fill with the current value if one exists
  const existing = sheet.getRange(row, CONFIG.COL.AIR_DATE).getValue();
  let isoLocal = '';
  if (existing instanceof Date) {
    isoLocal = Utilities.formatDate(existing, TIMEZONE, "yyyy-MM-dd'T'HH:mm");
  }

  const title = String(sheet.getRange(row, CONFIG.COL.EPISODE_TITLE).getValue() || '(untitled row)');
  const html = HtmlService.createHtmlOutput(`
    <style>
      body { font-family: Arial, sans-serif; margin: 16px; color: #222; }
      h3 { margin: 0 0 4px; font-size: 14px; }
      .row { color: #666; font-size: 12px; margin-bottom: 12px; }
      label { display: block; font-weight: bold; margin: 12px 0 4px; font-size: 12px; }
      input[type="datetime-local"] { width: 100%; padding: 8px; font-size: 14px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
      .btns { margin-top: 16px; display: flex; gap: 8px; }
      button { padding: 8px 14px; border-radius: 4px; border: 0; cursor: pointer; font-size: 13px; }
      .primary { background: #1a1a2e; color: #fff; }
      .secondary { background: #eee; color: #333; }
      .hint { color: #888; font-size: 11px; margin-top: 6px; }
      .status { margin-top: 10px; font-size: 12px; color: #2980b9; min-height: 16px; }
    </style>
    <h3>Air Date + Time</h3>
    <div class="row">Row ${row} — ${title}</div>
    <label for="dt">Pick date and time (Eastern Time)</label>
    <input type="datetime-local" id="dt" value="${isoLocal}" step="60">
    <div class="hint">Calendar opens on click. Time uses your browser locale; saved as Eastern Time.</div>
    <div class="btns">
      <button class="primary" onclick="save()">Save</button>
      <button class="secondary" onclick="google.script.host.close()">Cancel</button>
    </div>
    <div class="status" id="status"></div>
    <script>
      function save() {
        var v = document.getElementById('dt').value;
        if (!v) { document.getElementById('status').textContent = 'Pick a date and time first.'; return; }
        document.getElementById('status').textContent = 'Saving…';
        google.script.run
          .withSuccessHandler(function(){ google.script.host.close(); })
          .withFailureHandler(function(err){ document.getElementById('status').textContent = 'Error: ' + err.message; })
          .setAirDateForRow(${row}, v);
      }
    </script>
  `).setTitle('Air Date + Time').setWidth(320);

  SpreadsheetApp.getUi().showSidebar(html);
}

// Called from the sidebar. value is "YYYY-MM-DDTHH:MM" (datetime-local string).
function setAirDateForRow(row, value) {
  if (!value) throw new Error('No value provided');
  // Parse as Eastern Time. datetime-local has no timezone — we treat it as TIMEZONE.
  const m = value.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/);
  if (!m) throw new Error('Could not parse datetime: ' + value);
  // Build a Date object that represents the user's chosen wall-clock time in TIMEZONE.
  // Apps Script runs in script timezone — easiest reliable construction:
  const tzString = `${m[1]}-${m[2]}-${m[3]} ${m[4]}:${m[5]}:00 ${getTzOffsetString_(m[1], m[2], m[3], m[4], m[5])}`;
  const d = new Date(tzString);
  if (isNaN(d.getTime())) throw new Error('Invalid date: ' + tzString);

  const sheet = SpreadsheetApp.getActiveSpreadsheet().getSheetByName(CONFIG.SHEET_NAME);
  const cell  = sheet.getRange(row, CONFIG.COL.AIR_DATE);
  cell.setNumberFormat('dd MMM yyyy  HH:mm');
  cell.setValue(d);

  // Trigger the existing auto-provision logic if title is also filled
  const title = sheet.getRange(row, CONFIG.COL.EPISODE_TITLE).getValue();
  if (title) {
    try {
      sheet.setRowHeightsForced(row, 1, 25);
      createFolderForRow_(sheet, row);
      createCalendarEventForRow_(sheet, row);
    } catch(err) {
      Logger.log('Auto-provision after picker: ' + err.message);
    }
  }
}

// Format a Date object's TZ offset as "-05:00" or "-04:00" for a given wall-clock moment.
// Uses Apps Script's Utilities.formatDate which respects TIMEZONE.
function getTzOffsetString_(yyyy, mm, dd, hh, mi) {
  // Construct a probe date in UTC at that wall clock, then ask formatDate for its TZ offset.
  // Simpler: format an arbitrary Date in TIMEZONE and read the offset.
  const probe = new Date(Date.UTC(parseInt(yyyy), parseInt(mm) - 1, parseInt(dd), parseInt(hh), parseInt(mi)));
  // ZZZZZ returns offset like "-0500"
  const raw = Utilities.formatDate(probe, TIMEZONE, 'ZZZZZ');
  // Convert "-0500" to "-05:00"
  if (/^[+-]\d{4}$/.test(raw)) return raw.substring(0, 3) + ':' + raw.substring(3);
  return raw;
}

// ── OPEN FORM IN A MODAL ─────────────────────────────────────
function openEpisodeForm() {
  const ui = SpreadsheetApp.getUi();
  const publishUrl = PropertiesService.getScriptProperties().getProperty('FORM_PUBLISH_URL');
  if (!publishUrl) {
    ui.alert('No form linked yet. Run "6. Create / Update Episode Form" first.');
    return;
  }
  // Convert /viewform to embedded form
  const embedUrl = publishUrl.replace(/\/viewform.*$/, '/viewform?embedded=true');
  const html = HtmlService.createHtmlOutput(`
    <style>
      html, body { margin: 0; padding: 0; height: 100%; }
      iframe { width: 100%; height: 100%; border: 0; display: block; }
      .footer { position: fixed; bottom: 0; right: 0; padding: 6px 10px; font-size: 11px; color: #666; background: rgba(255,255,255,.9); }
      .footer a { color: #1a73e8; }
    </style>
    <iframe src="${embedUrl}" allowfullscreen></iframe>
    <div class="footer">Form not loading? <a href="${publishUrl}" target="_blank">Open in new tab</a></div>
  `).setWidth(720).setHeight(720);
  ui.showModalDialog(html, 'New Episode');
}

// ============================================================
// ── EPISODE WEB APP (replaces the Google Form for entry) ────
// Deploy: Apps Script editor → Deploy → New deployment →
//   Type: Web app
//   Execute as: Me (the sheet owner)
//   Who has access: Anyone (or restrict to your domain)
// After deploy you get a /exec URL — share that instead of the
// Google Form. The form still works alongside if you want both.
// ============================================================

function doGet(e) {
  // Route: ?page=checker → free time checker (no auth required)
  const page = (e && e.parameter && e.parameter.page) || '';
  if (page === 'checker') {
    return HtmlService.createHtmlOutput(buildFreeTimeCheckerHtml_())
      .setTitle('Free Time Checker — Looth Group Live')
      .addMetaTag('viewport', 'width=device-width, initial-scale=1')
      .setSandboxMode(HtmlService.SandboxMode.IFRAME)
      .setXFrameOptionsMode(HtmlService.XFrameOptionsMode.ALLOWALL);
  }

  // Default route → episode entry form (existing auth check)
  const email = (Session.getActiveUser().getEmail() || '').toLowerCase();
  if (!email) {
    return HtmlService.createHtmlOutput(buildAccessDeniedHtml_(
      'Sign in required',
      'You need to be signed into a Google account to use this form. The deployment may also be misconfigured — it must require a Google account, not "Anyone".'
    )).setTitle('Sign in required');
  }
  if (!isFormAccessAllowed_(email)) {
    return HtmlService.createHtmlOutput(buildAccessDeniedHtml_(
      'Access denied',
      'Your Google account (<strong>' + escapeHtml_(email) + '</strong>) is not on the Form Access list. Ask Ian to add you to the FORM ACCESS table in the Config sheet.'
    )).setTitle('Access denied');
  }
  return HtmlService.createHtmlOutput(buildEpisodeWebAppHtml_(email))
    .setTitle('New Episode — Looth Group Live')
    .addMetaTag('viewport', 'width=device-width, initial-scale=1')
    .setSandboxMode(HtmlService.SandboxMode.IFRAME)
    .setXFrameOptionsMode(HtmlService.XFrameOptionsMode.ALLOWALL);
}

function buildAccessDeniedHtml_(heading, body) {
  return `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  body { font-family: Georgia, serif; background: #f5f5f7; margin: 0; padding: 40px 20px; color: #222; }
  .card { max-width: 480px; margin: 0 auto; background: #fff; border-radius: 10px; box-shadow: 0 2px 12px rgba(0,0,0,.08); overflow: hidden; }
  .header { background: #1a1a2e; color: #fff; padding: 24px 28px; }
  .header h1 { margin: 0; font-size: 20px; }
  .header .sub { color: #aaa; font-size: 12px; letter-spacing: 2px; text-transform: uppercase; margin-bottom: 6px; }
  .body { padding: 22px 28px 28px; line-height: 1.5; color: #444; font-size: 14px; }
</style></head><body>
<div class="card">
  <div class="header">
    <div class="sub">Looth Group Live</div>
    <h1>${escapeHtml_(heading)}</h1>
  </div>
  <div class="body">${body}</div>
</div></body></html>`;
}

function showWebAppUrl() {
  const url = ScriptApp.getService().getUrl();
  const ui = SpreadsheetApp.getUi();
  if (!url) {
    ui.alert(
      'Web App not deployed yet.\n\n' +
      'In the Apps Script editor:\n' +
      '1. Click "Deploy" (top right)\n' +
      '2. New deployment → Type: Web app\n' +
      '3. Execute as: Me\n' +
      '4. Who has access: Anyone with the link\n' +
      '5. Deploy. Re-run this menu item to see the URL.'
    );
    return;
  }
  ui.alert('Episode entry web app:\n\n' + url);
}

// Server endpoint — live search hitting WP /user-search
function searchWpUsersForForm(q) {
  q = String(q || '').trim();
  const path = q
    ? '/wp-json/loothdev/v1/user-search?per_page=15&q=' + encodeURIComponent(q)
    : '/wp-json/loothdev/v1/user-search?per_page=15';
  return wpRequest_(path, 'get', null);
}

// Server endpoint — receives the submitted form payload and writes a sheet row.
// payload: { title, showName, airDateIso, showrunnerName, showrunnerWpUserId,
//            eventTier, region, languages[], guest, topic, blurb, notes,
//            submitterEmail }
function submitEpisodeFromWebApp(payload) {
  try {
    if (!payload || typeof payload !== 'object') throw new Error('Bad payload');

    const title = String(payload.title || '').trim();
    if (!title) throw new Error('Episode Title is required');

    const ss = SpreadsheetApp.getActiveSpreadsheet();
    const sheet = ss.getSheetByName(CONFIG.SHEET_NAME);
    if (!sheet) throw new Error('Episodes sheet not found. Run setup first.');

    // Find the true last data row (scan title column — getLastRow is unreliable)
    const titleCol = sheet.getRange(2, CONFIG.COL.EPISODE_TITLE, sheet.getMaxRows() - 1, 1).getValues();
    let newRow = 2;
    for (let i = titleCol.length - 1; i >= 0; i--) {
      if (titleCol[i][0] !== '') { newRow = i + 3; break; }
    }

    // Parse air date — datetime-local "YYYY-MM-DDTHH:MM" interpreted as TIMEZONE
    let airDate = null;
    if (payload.airDateIso) {
      const m = String(payload.airDateIso).match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/);
      if (m) {
        const tz = getTzOffsetString_(m[1], m[2], m[3], m[4], m[5]);
        airDate = new Date(`${m[1]}-${m[2]}-${m[3]} ${m[4]}:${m[5]}:00 ${tz}`);
        if (isNaN(airDate.getTime())) airDate = null;
      }
    }

    sheet.setRowHeightsForced(newRow, 1, 25);
    sheet.getRange(newRow, CONFIG.COL.EPISODE_TITLE).setValue(title);
    if (payload.showName)   sheet.getRange(newRow, CONFIG.COL.SHOW_NAME).setValue(payload.showName);
    if (airDate) {
      const dateCell = sheet.getRange(newRow, CONFIG.COL.AIR_DATE);
      dateCell.setNumberFormat('dd MMM yyyy  HH:mm');
      dateCell.setValue(airDate);
    }
    if (payload.showrunnerName) sheet.getRange(newRow, CONFIG.COL.SHOWRUNNER).setValue(payload.showrunnerName);
    if (payload.guest)      sheet.getRange(newRow, CONFIG.COL.GUEST).setValue(payload.guest);
    if (payload.guestEmail) sheet.getRange(newRow, CONFIG.COL.GUEST_EMAIL).setValue(payload.guestEmail);
    if (payload.topic)      sheet.getRange(newRow, CONFIG.COL.TOPIC).setValue(payload.topic);
    if (payload.blurb)      sheet.getRange(newRow, CONFIG.COL.BLURB).setValue(payload.blurb);
    if (payload.notes)      sheet.getRange(newRow, CONFIG.COL.NOTES).setValue(payload.notes);
    if (payload.eventTier)  sheet.getRange(newRow, CONFIG.COL.EVENT_TIER).setValue(payload.eventTier);
    if (payload.region)     sheet.getRange(newRow, CONFIG.COL.REGION).setValue(payload.region);
    if (payload.languages && payload.languages.length) {
      sheet.getRange(newRow, CONFIG.COL.LANGUAGE).setValue(payload.languages.join(', '));
    }
    if (payload.otherAttendees) {
      sheet.getRange(newRow, CONFIG.COL.OTHER_ATTENDEES).setValue(payload.otherAttendees);
    }
    // Zoom Link — the modal pre-fills the default; honor an edited value, else default.
    sheet.getRange(newRow, CONFIG.COL.ZOOM_URL).setValue(
      (payload.zoom && payload.zoom.trim()) ? payload.zoom.trim() : CONFIG.DEFAULT_ZOOM_URL);

    // Default asset statuses — Pending unless the form provided the asset
    const blurbProvided = !!(payload.blurb && payload.blurb.trim());
    const imageProvided = !!(payload.featuredImage && payload.featuredImage.data_b64);
    sheet.getRange(newRow, CONFIG.COL.BLURB_STATUS).setValue(blurbProvided ? STATUS.SUBMITTED : STATUS.PENDING);
    sheet.getRange(newRow, CONFIG.COL.H_THUMB_STATUS).setValue(imageProvided ? STATUS.SUBMITTED : STATUS.PENDING);
    sheet.getRange(newRow, CONFIG.COL.V_THUMB_STATUS).setValue(STATUS.PENDING);

    // If a showrunnerWpUserId was selected from live search and the row's
    // showrunner doesn't yet exist in Config, we add them so the publish
    // path can resolve the author. (Skip if name already in roster.)
    const showrunnerName = (payload.showrunnerName || '').trim();
    const showrunnerWpId = parseInt(payload.showrunnerWpUserId) || 0;
    if (showrunnerName && showrunnerWpId && !getShowrunnerByName_(showrunnerName)) {
      const configSheet = ss.getSheetByName(CONFIG_SHEET_NAME);
      if (configSheet) {
        // Find first empty showrunner row (col A blank) in A3:A52
        const names = configSheet.getRange('A3:A52').getValues();
        let target = 0;
        for (let i = 0; i < names.length; i++) {
          if (!String(names[i][0]).trim()) { target = i + 3; break; }
        }
        if (target) {
          configSheet.getRange(target, 1, 1, 4).setValues([[
            showrunnerName,
            (payload.showrunnerEmail || ''),
            '#cccccc',
            showrunnerWpId,
          ]]);
        }
      }
    }

    // Apply colors
    if (payload.showrunnerName) applyShowrunnerColor_(sheet, newRow, payload.showrunnerName);
    if (payload.showName)       applyShowColor_(sheet, newRow, payload.showName);

    // Provision Drive folder + Calendar event + txt
    let calendarClashes = [];
    try {
      createFolderForRow_(sheet, newRow);
      SpreadsheetApp.flush(); // ensure Drive URL is written before calendar reads it
      if (airDate) {
        calendarClashes = getCalendarClashes_(airDate);
        if (!calendarClashes.length) {
          createCalendarEventForRow_(sheet, newRow);
        } else {
          const clashMsg = calendarClashes.map(c => c.title + ' (' + c.start + ' – ' + c.end + ')').join('; ');
          Logger.log('submitEpisodeFromWebApp: calendar clash — ' + clashMsg);
          const existing = String(sheet.getRange(newRow, CONFIG.COL.NOTES).getValue() || '');
          sheet.getRange(newRow, CONFIG.COL.NOTES).setValue(
            (existing ? existing + '\n' : '') +
            '⚠ CALENDAR CLASH: ' + clashMsg + '. Event not created — please reschedule.'
          );
        }
      }
    } catch(err) {
      Logger.log('submitEpisodeFromWebApp provisioning error: ' + err.message);
    }

    // Save uploaded featured image (if any) into the row's Drive folder as featured.<ext>
    let imageUploaded = false;
    let imageError = '';
    const driveUrl = sheet.getRange(newRow, CONFIG.COL.DRIVE_FOLDER_URL).getValue();
    if (payload.featuredImage && driveUrl) {
      try {
        const fim = payload.featuredImage;
        if (fim.data_b64 && fim.filename) {
          const folderIdMatch = String(driveUrl).match(/[-\w]{25,}/);
          if (folderIdMatch) {
            const folder = DriveApp.getFolderById(folderIdMatch[0]);
            // Delete any existing featured.* files so the picker grabs the new one
            const existing = folder.getFiles();
            while (existing.hasNext()) {
              const f = existing.next();
              if (/^featured\b/i.test(f.getName())) f.setTrashed(true);
            }
            const extMatch = String(fim.filename).match(/\.([a-z0-9]+)$/i);
            const ext = (extMatch ? extMatch[1] : 'jpg').toLowerCase();
            const bytes = Utilities.base64Decode(fim.data_b64);
            const blob = Utilities.newBlob(bytes, fim.mime || 'image/jpeg', 'featured.' + ext);
            const newFile = folder.createFile(blob);
            // Make just THIS file public-link-viewable so =IMAGE() can render it
            // for anyone viewing the sheet. The parent folder stays private.
            try { newFile.setSharing(DriveApp.Access.ANYONE_WITH_LINK, DriveApp.Permission.VIEW); }
            catch(e) { Logger.log('Could not relax file sharing: ' + e.message); }
            // Inline preview via Sheets' =IMAGE — lh3 URL works without auth
            const previewUrl = 'https://lh3.googleusercontent.com/d/' + newFile.getId();
            sheet.getRange(newRow, CONFIG.COL.FEATURED_IMAGE)
              .setFormula('=IMAGE("' + previewUrl + '")');
            sheet.setRowHeightsForced(newRow, 1, 80);
            imageUploaded = true;
          }
        }
      } catch(err) {
        imageError = err.message;
        Logger.log('Image upload to Drive failed: ' + err.message);
      }
    }

    // Email submitter the Drive folder link
    const submitter = (payload.submitterEmail || '').trim();
    if (driveUrl && submitter) {
      try {
        GmailApp.sendEmail(submitter,
          '[Looth Group Live] Drive folder ready — ' + title, '',
          { name: CONFIG.SENDER_NAME, htmlBody:
            '<p>Your episode "<strong>' + title + '</strong>" has been submitted.</p>' +
            '<p><a href="' + driveUrl + '">Open Episode Folder</a></p>' +
            (imageUploaded
              ? '<p>Your featured image was uploaded into the folder.</p>'
              : '<p>Drop your featured image (named <code>featured.jpg</code>) and other promo assets there.</p>') +
            '<p>— The Looth Group Team</p>'
          });
      } catch(err) { Logger.log('Folder email failed: ' + err.message); }
    }

    // Evaluate WP publish eligibility — all required fields present?
    const publishEligible = !!(
      title
      && airDate
      && payload.showrunnerWpUserId
      && payload.eventTier
      && imageUploaded
    );

    return {
      ok: true,
      row: newRow,
      driveUrl: driveUrl,
      imageUploaded: imageUploaded,
      imageError: imageError,
      publishEligible: publishEligible,
      calendarClashes: calendarClashes,
    };
  } catch(err) {
    Logger.log('submitEpisodeFromWebApp ERROR: ' + err.message + '\n' + err.stack);
    return { ok: false, error: err.message };
  }
}

function buildEpisodeWebAppHtml_(signedInEmail) {
  const shows = getShows_().map(s => s.name);
  const regions = getRegions_();
  const tiers = CONFIG.WP_TIER_OPTIONS;
  const languages = ['English','Spanish','Portuguese','Italian','French','German','Dutch','Swedish'];
  const prefillEmail = escapeHtml_(signedInEmail || '');

  return `<!doctype html>
<html><head><meta charset="utf-8"><title>New Episode</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: Georgia, serif; background: #f5f5f7; margin: 0; padding: 24px; color: #222; }
  .card { max-width: 640px; margin: 0 auto; background: #fff; border-radius: 10px; box-shadow: 0 2px 12px rgba(0,0,0,.08); overflow: hidden; }
  .header { background: #1a1a2e; color: #fff; padding: 24px 28px; }
  .header h1 { margin: 0; font-size: 22px; }
  .header .sub { color: #aaa; font-size: 12px; letter-spacing: 2px; text-transform: uppercase; margin-bottom: 6px; }
  .body { padding: 22px 28px 28px; }
  label { display: block; font-weight: bold; font-size: 13px; margin: 14px 0 4px; }
  label .req { color: #c0392b; }
  input, select, textarea { width: 100%; padding: 10px; font-size: 14px; border: 1px solid #ccc; border-radius: 4px; font-family: inherit; }
  textarea { min-height: 70px; resize: vertical; }
  .help { color: #888; font-size: 11px; margin-top: 3px; }
  .search-wrap { position: relative; }
  .search-results { position: absolute; left: 0; right: 0; top: 100%; background: #fff; border: 1px solid #ddd; border-top: 0; max-height: 220px; overflow-y: auto; z-index: 10; display: none; }
  .search-results.show { display: block; }
  .search-results .item { padding: 8px 10px; cursor: pointer; font-size: 13px; border-bottom: 1px solid #eee; }
  .search-results .item:hover, .search-results .item.active { background: #f0f4ff; }
  .search-results .item .meta { color: #888; font-size: 11px; }
  .picked { background: #eef7ee; border: 1px solid #b6dab6; padding: 8px 10px; border-radius: 4px; font-size: 13px; margin-top: 6px; display: none; }
  .picked.show { display: flex; justify-content: space-between; align-items: center; }
  .picked button { background: none; border: 0; color: #c0392b; cursor: pointer; font-size: 12px; }
  .checkboxes { display: grid; grid-template-columns: repeat(2, 1fr); gap: 4px 12px; margin-top: 4px; }
  .checkboxes label { font-weight: normal; font-size: 13px; margin: 0; }
  .checkboxes input { width: auto; margin-right: 6px; }
  .row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
  .submit { background: #1a1a2e; color: #fff; padding: 12px 24px; font-size: 14px; border-radius: 4px; border: 0; cursor: pointer; margin-top: 22px; width: 100%; font-weight: bold; }
  .submit:disabled { opacity: .5; cursor: wait; }
  .status { margin-top: 14px; padding: 12px; border-radius: 4px; display: none; font-size: 13px; }
  .status.ok { background: #eef7ee; color: #2d5a2d; border: 1px solid #b6dab6; display: block; }
  .status.err { background: #fdecec; color: #8a2a2a; border: 1px solid #f1b6b6; display: block; }
  .status a { color: inherit; font-weight: bold; }
</style>
</head><body>
  <div class="card">
    <div class="header">
      <div class="sub">Looth Group Live</div>
      <h1>🎙️ New Episode</h1>
    </div>
    <form id="ep" class="body" autocomplete="off">

      <label>Submitter email <span class="req">*</span></label>
      <input type="email" id="submitterEmail" required placeholder="you@example.com" value="${prefillEmail}" readonly>
      <div class="help">We'll email the Drive folder link here. (Locked to your signed-in account.)</div>

      <label>Episode Title <span class="req">*</span></label>
      <input type="text" id="title" required>

      <label>Show Name <span class="req">*</span></label>
      <select id="showName" required>
        <option value="">— pick a show —</option>
        ${shows.map(s => `<option>${escapeHtml_(s)}</option>`).join('')}
      </select>

      <label>Air Date + Time (Eastern) <span class="req">*</span></label>
      <input type="datetime-local" id="airDateIso" required step="60">
      <div id="clashStatus" class="help" style="min-height:16px;"></div>

      <label>Showrunner <span class="req">*</span></label>
      <select id="showrunnerSelect">
        <option value="">— pick a showrunner —</option>
        ${(function(){
          return getShowrunners_().map(function(sr){
            const disabled = sr.wpUserId ? '' : ' disabled';
            const label = sr.wpUserId ? sr.name : (sr.name + ' (no WP user ID — resolve in Config)');
            return '<option value="' + escapeHtml_(sr.name) + '" data-id="' + (sr.wpUserId || '') + '" data-email="' + escapeHtml_(sr.email || '') + '"' + disabled + '>' + escapeHtml_(label) + '</option>';
          }).join('');
        })()}
      </select>
      <div class="help">Picks from your Config sheet roster. Don't see them?</div>
      <button type="button" id="srToggleSearch" style="background:none;border:0;color:#1a73e8;cursor:pointer;padding:0;font-size:12px;text-decoration:underline;margin-bottom:8px;">Search all WP users instead</button>
      <div id="srSearchWrap" style="display:none;">
        <div class="search-wrap" style="display:flex;gap:6px;align-items:stretch;">
          <input type="text" id="showrunnerSearch" placeholder="Type a name or email…" autocomplete="off" style="flex:1;">
          <button type="button" id="srRefresh" title="Re-test WP connection and reload search" style="padding:0 12px;font-size:14px;border:1px solid #ccc;background:#fafafa;border-radius:4px;cursor:pointer;">↻</button>
          <div id="srResults" class="search-results" style="left:0;right:48px;"></div>
        </div>
        <div id="srStatus" class="help" style="min-height:14px;"></div>
        <div class="help">Live search of all WP users. The picked user will be auto-added to the Config roster on submit.</div>
      </div>
      <div id="srPicked" class="picked"></div>

      <label>Event Tier <span class="req">*</span></label>
      <select id="eventTier" required>
        <option value="">— pick tier —</option>
        ${tiers.map(t => `<option>${escapeHtml_(t)}</option>`).join('')}
      </select>

      ${regions.length ? `
        <label>Region</label>
        <select id="region">
          <option value="">— none —</option>
          ${regions.map(r => `<option>${escapeHtml_(r)}</option>`).join('')}
        </select>
      ` : ''}

      <label>Language</label>
      <div class="checkboxes">
        ${languages.map((l,i) => `<label><input type="checkbox" name="lang" value="${escapeHtml_(l)}"> ${escapeHtml_(l)}</label>`).join('')}
      </div>

      <label>Guest Name</label>
      <input type="text" id="guest" placeholder="Leave blank if no guest">

      <label>Guest Email(s)</label>
      <input type="text" id="guestEmail" placeholder="guest@example.com, guest2@example.com">
      <div class="help">Optional. Comma-separated if multiple. Each guest will receive a Google Calendar invitation.</div>

      <label>Topic / Description</label>
      <input type="text" id="topic">

      <label>Blurb Text</label>
      <textarea id="blurb" placeholder="The episode description shown to the audience."></textarea>

      <label>Zoom Link (virtual attend)</label>
      <input type="url" id="zoomUrl" value="${escapeHtml_(CONFIG.DEFAULT_ZOOM_URL)}" placeholder="https://us02web.zoom.us/j/...">
      <div class="help">Pre-filled with the standing Looth Group room — edit per-episode if needed. Becomes the gated "Join" link on the event page (shown only to satisfied tiers).</div>

      <label>Other Attendees (Calendar guests)</label>
      <input type="text" id="otherAttendees" placeholder="guest@example.com, cohost@example.com">
      <div class="help">Optional. Comma-separated emails — added as guests on the Google Calendar event.</div>

      <label>Notes</label>
      <textarea id="notes" placeholder="Internal notes — not shown publicly."></textarea>

      <label>Featured Image</label>
      <input type="file" id="featuredImage" accept="image/jpeg,image/webp,image/png">
      <div class="help">Optional. Upload the episode's featured image (JPG/PNG/WebP, max ~25 MB). Saved as <code>featured.&lt;ext&gt;</code> in the Drive folder.</div>

      <button type="submit" class="submit" id="submitBtn">Submit Episode</button>
      <div id="status" class="status"></div>
    </form>
  </div>

<script>
  // Showrunner — Config dropdown (primary) + WP live search (fallback)
  const showrunnerSelect = document.getElementById('showrunnerSelect');
  const searchInput = document.getElementById('showrunnerSearch');
  const resultsBox  = document.getElementById('srResults');
  const pickedBox   = document.getElementById('srPicked');
  const searchWrap  = document.getElementById('srSearchWrap');
  let pickedUser = null;
  let searchTimer = null;
  let activeIdx = -1;
  let currentResults = [];

  // Picking from the Config dropdown
  showrunnerSelect.addEventListener('change', function(){
    const opt = showrunnerSelect.selectedOptions[0];
    if (!opt || !opt.value) { pickedUser = null; pickedBox.classList.remove('show'); return; }
    pickedUser = {
      id: parseInt(opt.dataset.id) || 0,
      display_name: opt.value,
      email: opt.dataset.email || '',
    };
    if (!pickedUser.id) {
      pickedBox.classList.remove('show');
      return;
    }
    pickedBox.innerHTML = 'From Config: <strong>' + escapeText(pickedUser.display_name) + '</strong>'
      + (pickedUser.email ? ' <span style="color:#666;font-size:11px">('+escapeText(pickedUser.email)+' · id '+pickedUser.id+')</span>' : '');
    pickedBox.classList.add('show');
  });

  // Toggle the WP search fallback
  document.getElementById('srToggleSearch').addEventListener('click', function(){
    const visible = searchWrap.style.display !== 'none';
    searchWrap.style.display = visible ? 'none' : 'block';
    if (!visible) searchInput.focus();
  });

  function escapeText(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }

  function renderResults(items) {
    currentResults = items || [];
    activeIdx = -1;
    if (!currentResults.length) {
      resultsBox.innerHTML = '<div class="item" style="color:#999;cursor:default">No users found</div>';
      resultsBox.classList.add('show');
      return;
    }
    resultsBox.innerHTML = currentResults.map(function(u, i){
      return '<div class="item" data-i="'+i+'">'
        + escapeText(u.display_name)
        + ' <span class="meta">'+ escapeText(u.email) +' · id '+u.id+'</span>'
        + '</div>';
    }).join('');
    resultsBox.classList.add('show');
    Array.from(resultsBox.querySelectorAll('.item')).forEach(function(el){
      el.addEventListener('click', function(){
        pickUser(currentResults[parseInt(el.dataset.i)]);
      });
    });
  }

  function pickUser(u) {
    pickedUser = u;
    pickedBox.innerHTML = 'Picked: <strong>' + escapeText(u.display_name) + '</strong> '
      + '<span style="color:#666;font-size:11px">('+escapeText(u.email)+' · id '+u.id+')</span>'
      + '<button type="button" id="clearPick">clear</button>';
    pickedBox.classList.add('show');
    document.getElementById('clearPick').onclick = function(){
      pickedUser = null;
      pickedBox.classList.remove('show');
      searchInput.value = '';
      searchInput.focus();
    };
    resultsBox.classList.remove('show');
    searchInput.value = u.display_name;
  }

  function runSearch(q, statusMsg) {
    var statusEl = document.getElementById('srStatus');
    if (statusMsg) { statusEl.textContent = statusMsg; statusEl.style.color = '#888'; }
    google.script.run
      .withSuccessHandler(function(rows){
        statusEl.textContent = (rows && rows.length ? rows.length + ' result(s)' : 'no results');
        statusEl.style.color = '#888';
        renderResults(rows);
      })
      .withFailureHandler(function(err){
        statusEl.textContent = 'Error: ' + err.message;
        statusEl.style.color = '#c0392b';
        resultsBox.innerHTML = '<div class="item" style="color:#c0392b">Error: '+escapeText(err.message)+'</div>';
        resultsBox.classList.add('show');
      })
      .searchWpUsersForForm(q);
  }

  searchInput.addEventListener('input', function(){
    pickedUser = null;
    pickedBox.classList.remove('show');
    var q = searchInput.value.trim();
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(function(){ runSearch(q, ''); }, 250);
  });

  document.getElementById('srRefresh').addEventListener('click', function(){
    var q = searchInput.value.trim();
    runSearch(q, 'Reconnecting…');
    searchInput.focus();
  });

  searchInput.addEventListener('keydown', function(e){
    if (!resultsBox.classList.contains('show')) return;
    if (e.key === 'ArrowDown') { e.preventDefault(); activeIdx = Math.min(activeIdx + 1, currentResults.length - 1); updateActive(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); activeIdx = Math.max(activeIdx - 1, 0); updateActive(); }
    else if (e.key === 'Enter' && activeIdx >= 0) { e.preventDefault(); pickUser(currentResults[activeIdx]); }
    else if (e.key === 'Escape') { resultsBox.classList.remove('show'); }
  });
  function updateActive() {
    Array.from(resultsBox.querySelectorAll('.item')).forEach(function(el, i){
      el.classList.toggle('active', i === activeIdx);
    });
  }
  document.addEventListener('click', function(e){
    if (!searchInput.contains(e.target) && !resultsBox.contains(e.target)) resultsBox.classList.remove('show');
  });

  // Live calendar clash check when datetime is picked
  var clashCheckTimer = null;
  document.getElementById('airDateIso').addEventListener('change', function(){
    var v = this.value;
    var el = document.getElementById('clashStatus');
    if (!v) { el.textContent = ''; return; }
    el.textContent = 'Checking calendar…';
    el.style.color = '#888';
    if (clashCheckTimer) clearTimeout(clashCheckTimer);
    clashCheckTimer = setTimeout(function(){
      google.script.run
        .withSuccessHandler(function(res){
          if (res && res.clashes && res.clashes.length) {
            el.style.color = '#c0392b';
            el.innerHTML = '⚠ <strong>Calendar clash:</strong> ' +
              res.clashes.map(function(c){ return escapeText(c.title) + ' (' + c.start + ' – ' + c.end + ')'; }).join(', ') +
              ' — please pick a different time.';
          } else {
            el.style.color = '#2d5a2d';
            el.textContent = '✓ Time slot is free.';
          }
        })
        .withFailureHandler(function(){ el.style.color = '#888'; el.textContent = ''; })
        .checkCalendarClashForWebApp(v);
    }, 400);
  });

  // Submit
  document.getElementById('ep').addEventListener('submit', function(e){
    e.preventDefault();
    var status = document.getElementById('status');
    status.className = 'status'; status.textContent = '';

    if (!pickedUser) {
      status.className = 'status err'; status.textContent = 'Pick a Showrunner from the live-search dropdown.';
      return;
    }

    var langs = Array.from(document.querySelectorAll('input[name=lang]:checked')).map(function(c){ return c.value; });
    var payload = {
      submitterEmail:        document.getElementById('submitterEmail').value.trim(),
      title:                 document.getElementById('title').value.trim(),
      showName:              document.getElementById('showName').value,
      airDateIso:            document.getElementById('airDateIso').value,
      showrunnerName:        pickedUser.display_name,
      showrunnerEmail:       pickedUser.email,
      showrunnerWpUserId:    pickedUser.id,
      eventTier:             document.getElementById('eventTier').value,
      region:                (document.getElementById('region') ? document.getElementById('region').value : ''),
      languages:             langs,
      guest:                 document.getElementById('guest').value.trim(),
      guestEmail:            document.getElementById('guestEmail').value.trim(),
      topic:                 document.getElementById('topic').value.trim(),
      blurb:                 document.getElementById('blurb').value.trim(),
      notes:                 document.getElementById('notes').value.trim(),
      otherAttendees:        document.getElementById('otherAttendees').value.trim(),
      zoom:                  document.getElementById('zoomUrl').value.trim(),
    };

    // Block submit if a clash was detected
    var clashEl = document.getElementById('clashStatus');
    if (clashEl && clashEl.style.color === 'rgb(192, 57, 43)' && clashEl.innerHTML.indexOf('⚠') !== -1) {
      status.className = 'status err';
      status.textContent = 'Please pick a different time — there is a calendar clash.';
      return;
    }

    var btn = document.getElementById('submitBtn');
    var fileInput = document.getElementById('featuredImage');
    var file = fileInput.files && fileInput.files[0];

    function send() {
      btn.disabled = true; btn.textContent = 'Submitting…';
      google.script.run
        .withSuccessHandler(function(res){
          btn.disabled = false; btn.textContent = 'Submit Episode';
          if (res && res.ok) {
            status.className = 'status ok';
            var clashWarn = '';
            if (res.calendarClashes && res.calendarClashes.length) {
              clashWarn = '<br><strong style="color:#c0392b">⚠ Calendar clash — event NOT added to calendar:</strong> '
                + res.calendarClashes.map(function(c){ return escapeText(c.title) + ' (' + c.start + ' – ' + c.end + ')'; }).join(', ')
                + '. Please reschedule and update the air date.';
            }
            status.innerHTML = 'Episode submitted! Row ' + res.row + '.'
              + (res.driveUrl ? ' <a href="'+res.driveUrl+'" target="_blank">Open Drive folder</a>' : '')
              + (res.imageUploaded ? ' Featured image saved.' : '')
              + (res.imageError ? ' <span style="color:#c0392b">Image upload failed: '+res.imageError+'</span>' : '')
              + clashWarn;
            document.getElementById('ep').reset();
            pickedUser = null; pickedBox.classList.remove('show');

            // All required WP fields present → offer to publish now
            if (res.publishEligible) {
              if (confirm('All required fields are filled.\\n\\nPublish this event to the website NOW?')) {
                status.innerHTML += '<br>Publishing to WordPress…';
                btn.disabled = true;
                google.script.run
                  .withSuccessHandler(function(pres){
                    btn.disabled = false;
                    if (pres && pres.ok) {
                      status.className = 'status ok';
                      status.innerHTML += '<br><strong>Published as '+pres.status.toUpperCase()+'!</strong>'
                        + (pres.viewUrl ? ' <a href="'+pres.viewUrl+'" target="_blank">View event</a>' : '');
                    } else {
                      status.className = 'status err';
                      status.innerHTML += '<br>Publish failed: ' + (pres && pres.error ? pres.error : 'unknown');
                    }
                  })
                  .withFailureHandler(function(err){
                    btn.disabled = false;
                    status.className = 'status err';
                    status.innerHTML += '<br>Publish failed: ' + err.message;
                  })
                  .publishRowFromWebApp(res.row);
              }
            }
          } else {
            status.className = 'status err';
            status.textContent = 'Error: ' + (res && res.error ? res.error : 'unknown');
          }
        })
        .withFailureHandler(function(err){
          btn.disabled = false; btn.textContent = 'Submit Episode';
          status.className = 'status err';
          status.textContent = 'Error: ' + err.message;
        })
        .submitEpisodeFromWebApp(payload);
    }

    if (file) {
      // Hard cap to avoid hitting google.script.run payload limits (~50MB).
      // Base64 inflates by ~33%, so 25MB raw is a safe ceiling.
      if (file.size > 25 * 1024 * 1024) {
        status.className = 'status err';
        status.textContent = 'Image too large (' + (file.size/1024/1024).toFixed(1) + ' MB). Max 25 MB.';
        return;
      }
      btn.disabled = true; btn.textContent = 'Reading image…';
      var reader = new FileReader();
      reader.onload = function(e) {
        var dataUrl = e.target.result; // "data:image/jpeg;base64,..."
        var comma = dataUrl.indexOf(',');
        payload.featuredImage = {
          filename: file.name,
          mime: file.type || 'image/jpeg',
          data_b64: dataUrl.substring(comma + 1),
        };
        send();
      };
      reader.onerror = function() {
        btn.disabled = false; btn.textContent = 'Submit Episode';
        status.className = 'status err';
        status.textContent = 'Could not read image file.';
      };
      reader.readAsDataURL(file);
    } else {
      send();
    }
  });
</script>
</body></html>`;
}

// Tiny server-side HTML escape for values pasted into the template
function escapeHtml_(s) {
  return String(s || '')
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

// ── Open the same web-app form as a modal inside the sheet ──
// No deployment required — the modal users are already authorized via sheet access.
function openEpisodeWebAppModal() {
  const email = Session.getActiveUser().getEmail() || '';
  const html = HtmlService.createHtmlOutput(buildEpisodeWebAppHtml_(email))
    .setWidth(720).setHeight(720);
  SpreadsheetApp.getUi().showModalDialog(html, '🎙️ New Episode');
}

// ── Publish an existing row from the web app (no UI alerts) ──
// Called from the form's "Publish now?" prompt after a successful submission.
function publishRowFromWebApp(rowIndex) {
  try {
    const sheet = SpreadsheetApp.getActiveSpreadsheet().getSheetByName(CONFIG.SHEET_NAME);
    if (!sheet) throw new Error('Episodes sheet not found');
    const row = sheet.getRange(rowIndex, 1, 1, HEADERS.length).getValues()[0];

    const title       = String(row[CONFIG.COL.EPISODE_TITLE - 1] || '').trim();
    const showName    = String(row[CONFIG.COL.SHOW_NAME - 1] || '').trim();
    const airDateRaw  = row[CONFIG.COL.AIR_DATE - 1];
    const showrunner  = String(row[CONFIG.COL.SHOWRUNNER - 1] || '').trim();
    const topic       = String(row[CONFIG.COL.TOPIC - 1] || '').trim();
    const blurb       = String(row[CONFIG.COL.BLURB - 1] || '').trim();
    const driveUrl    = String(row[CONFIG.COL.DRIVE_FOLDER_URL - 1] || '').trim();
    const tier        = String(row[CONFIG.COL.EVENT_TIER - 1] || '').trim();
    const region      = String(row[CONFIG.COL.REGION - 1] || '').trim();
    const languageRaw = String(row[CONFIG.COL.LANGUAGE - 1] || '').trim();
    const zoom        = String(row[CONFIG.COL.ZOOM_URL - 1] || '').trim();

    if (!title || !airDateRaw || !tier) throw new Error('Missing title, air date, or tier');

    const sr = getShowrunnerByName_(showrunner);
    const authorId = sr ? sr.wpUserId : 0;
    if (!authorId) throw new Error('Showrunner WP User ID not resolved in Config');

    let featuredImageFile = null;
    if (driveUrl) {
      const m = driveUrl.match(/[-\w]{25,}/);
      if (m) {
        try { featuredImageFile = pickFeaturedImageFromFolder_(DriveApp.getFolderById(m[0])); } catch(e) {}
      }
    }
    if (!featuredImageFile) throw new Error('No featured image in Drive folder');

    const airDate = airDateRaw instanceof Date ? airDateRaw : new Date(airDateRaw);
    const startDate = Utilities.formatDate(airDate, TIMEZONE, 'yyyy-MM-dd');
    const timeOfEvent = Utilities.formatDate(airDate, TIMEZONE, 'h:mm a').toLowerCase();

    const blob = featuredImageFile.getBlob();
    const origName = featuredImageFile.getName();
    const extMatch = origName.match(/\.([a-z0-9]+)$/i);
    const ext = extMatch ? extMatch[1] : 'jpg';
    const filename = buildFeaturedFilename_(airDate, showName, ext);

    const wpPayload = {
      title:         title,
      author_id:     authorId,
      status:        'publish',
      start_date:    startDate,
      time_of_event: timeOfEvent,
      tier:          tier,
      blurb:         blurb,
      topic:         topic,
      image: {
        filename: filename,
        mime: blob.getContentType(),
        data_b64: Utilities.base64Encode(blob.getBytes()),
      },
    };
    if (region) wpPayload.region = region;
    if (languageRaw) wpPayload.languages = languageRaw.split(',').map(s => s.trim()).filter(Boolean);
    if (zoom) wpPayload.zoom_url = zoom; // gated Join CTA on the event page

    const result = wpRequest_('/wp-json/loothdev/v1/events', 'post', wpPayload);
    if (!result || !result.ok) throw new Error('Bad WP response: ' + JSON.stringify(result));

    sheet.getRange(rowIndex, CONFIG.COL.WP_POST_URL).setValue(result.view_url || result.edit_url);
    return {
      ok: true,
      viewUrl: result.view_url,
      editUrl: result.edit_url,
      status: result.status,
      postId: result.wp_post_id,
    };
  } catch(err) {
    Logger.log('publishRowFromWebApp ERROR: ' + err.message);
    return { ok: false, error: err.message };
  }
}