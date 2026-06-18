'use strict';

const VOWELS = new Set(['A', 'E', 'I', 'O', 'U']);

// Set by loadPhrase() before the game starts
let PHRASE        = '';
let PHRASE_LETTERS = '';
let PHRASE_ID     = 0;
let siteConfig    = {};

// Hardcore mode (opt-in toggle, persisted): reveals are capped by the
// puzzle's own difficulty. Default mode has NO cap — casual players can
// never run out (Ian 6/11: no new lose state). MOVE_CAP is computed per
// phrase in loadPhrase(); it only bites while HARDCORE is on.
let HARDCORE = false;
let MOVE_CAP = 0;

// ─────────────────────────────────────────────────────────────────────────────
//  LOOTH GROUP INTEGRATION (front-page embed + member score recording)
// ─────────────────────────────────────────────────────────────────────────────
// Embed mode: the front-page block iframes this page with ?embed=1 and sizes
// the frame from the height we postMessage up. Scores: logged-in members (WP
// cookie) get today's result recorded server-side for the future leaderboard;
// anonymous players just play — localStorage stats keep working for everyone.
const IS_EMBED  = new URLSearchParams(location.search).has('embed');
const SCORE_API = '/archive-api/v0/guitardle-score';
const BOARD_API = '/archive-api/v0/guitardle-board';
let scoreAuth   = { authenticated: false, nonce: '' };

// Audience: the front-page block passes ?aud=m (member) / ?aud=p (logged-out)
// from its SSR member check. Logged-out players get a DIFFERENT daily phrase
// (Ian 6/11) — same shared sequence, day index shifted by half its length, so
// the two tracks never collide on the same day. Cosmetic only: recording is
// still server-gated, so spoofing ?aud only changes which puzzle you see.
const AUD_MEMBER = new URLSearchParams(location.search).get('aud') === 'm';

// Saved-game snapshot (Ian 6/12: refresh-PROOF, the forfeit rule is gone).
// Written on every move, cleared on any end state. A reload mid-game restores
// the exact position. Keyed to date + phrase id, so a stale save from another
// day or the other audience track is discarded, never replayed.
const SAVE_KEY = 'guitardle_game';

// ─────────────────────────────────────────────────────────────────────────────
//  STATE
// ─────────────────────────────────────────────────────────────────────────────
const state = {
    moves:           0,
    revealedLetters: new Set(),
    purchasedVowels: new Set(),
    gameOver:        false,
};

const guessState = {
    active:        false,
    blankTiles:    [],
    activeTileIdx: 0,
    vowelSnapshot: [],
};

// ─────────────────────────────────────────────────────────────────────────────
//  DOM REFERENCES
// ─────────────────────────────────────────────────────────────────────────────
const moveCountEl     = document.getElementById('move-count');
const moveCounterEl   = document.getElementById('move-counter');
const phraseRowEl     = document.querySelector('.phrase-row');
const gameMainEl      = document.getElementById('game-main');
const guessAreaEl     = document.getElementById('guess-area');
const guessDefaultEl  = document.getElementById('guess-default');
const guessConfirmEl  = document.getElementById('guess-confirm-area');
const btnGuess        = document.getElementById('btn-guess');
const btnConfirm      = document.getElementById('btn-confirm');
const btnCancel       = document.getElementById('btn-cancel');
const btnBackspace    = document.getElementById('btn-backspace');
const vowelInstructEl = document.getElementById('vowel-instruction');
const keyboardEl      = document.getElementById('keyboard');
const endStateEl      = document.getElementById('end-state');
const btnShare        = document.getElementById('btn-share-score');

// ─────────────────────────────────────────────────────────────────────────────
//  DATE UTILITIES
// ─────────────────────────────────────────────────────────────────────────────

// Returns today's date as a YYYY-MM-DD string, local time, no time component.
function todayString() {
    const d = new Date();
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
}

// Days elapsed between two YYYY-MM-DD strings (date portion only, no time).
function daysBetween(startStr, endStr) {
    const MS_PER_DAY = 86400000;
    const start = new Date(startStr + 'T00:00:00');
    const end   = new Date(endStr   + 'T00:00:00');
    return Math.floor((end - start) / MS_PER_DAY);
}

function formatDate() {
    const d      = new Date();
    const months = ['January','February','March','April','May','June',
                    'July','August','September','October','November','December'];
    return `${d.getDate()} ${months[d.getMonth()]} ${d.getFullYear()}`;
}

// ─────────────────────────────────────────────────────────────────────────────
//  PHRASE LOADING
// ─────────────────────────────────────────────────────────────────────────────
async function loadPhrase() {
    const [seqRes, csvRes, cfgRes] = await Promise.all([
        fetch('assets/sequence.json'),
        fetch('assets/guitardle_phrases.csv'),
        fetch('assets/config.json'),
    ]);

    const seqData = await seqRes.json();
    const csvText = await csvRes.text();
    siteConfig    = await cfgRes.json();

    // Parse CSV — columns are always: id, phrase, active
    // Phrase may contain commas, so: id = first segment, active = last segment,
    // phrase = everything in between re-joined.
    const lines = csvText.trim().split('\n');
    // Skip header row (line 0)

    const phraseMap = new Map();
    for (let i = 1; i < lines.length; i++) {
        const parts  = lines[i].split(',');
        const id     = parseInt(parts[0].trim(), 10);
        const active = parts[parts.length - 1].trim();
        const phrase = parts.slice(1, parts.length - 1).join(',').trim();
        if (active === '1') {
            phraseMap.set(id, phrase);
        }
    }

    // Calculate today's phrase index. Logged-out players run half a sequence
    // ahead of members so the two audiences get different daily phrases.
    const today   = todayString();
    const len     = seqData.sequence.length;
    const elapsed = daysBetween(seqData.startDate, today)
                  + (AUD_MEMBER ? 0 : Math.floor(len / 2));
    const idx     = ((elapsed % len) + len) % len;
    const phraseId = seqData.sequence[idx];

    PHRASE        = phraseMap.get(phraseId).toUpperCase();
    PHRASE_LETTERS = PHRASE.replace(/[-\s]/g, '');
    PHRASE_ID     = phraseId;

    // Hardcore reveal budget, scaled to the puzzle's own difficulty: the
    // full-reveal cost (1/distinct consonant, 2/distinct vowel) minus 3,
    // floor 5 — generous, but the whole phrase can never be revealed, so the
    // one guess is always a genuine guess. Hitting the cap only ends REVEALS;
    // the guess stays live (losing = wrong guess or forfeit, same as ever).
    const distinct = new Set(PHRASE_LETTERS);
    let revealCost = 0;
    distinct.forEach(L => { revealCost += VOWELS.has(L) ? 2 : 1; });
    MOVE_CAP = Math.max(revealCost - 3, 5);
}

// ─────────────────────────────────────────────────────────────────────────────
//  HARDCORE MODE
// ─────────────────────────────────────────────────────────────────────────────
function capActive() {
    return HARDCORE && MOVE_CAP > 0;
}

function outOfReveals() {
    return capActive() && state.moves >= MOVE_CAP;
}

// Counter shows the budget only in hardcore ("3/13"); plain count otherwise.
function renderMoves() {
    moveCountEl.textContent = capActive() ? `${state.moves}/${MOVE_CAP}` : String(state.moves);
}

function refreshCapState() {
    const notice = document.getElementById('cap-notice');
    const capped = outOfReveals() && !state.gameOver;
    keyboardEl.classList.toggle('capped', capped);
    notice.style.display = capped ? '' : 'none';
}

// Visibly disable the checkbox once today's game is underway (first click)
// or finished — the mode you started with is the mode you're scored on.
// Re-enabled by tomorrow's fresh load.
function lockHardcoreToggle() {
    document.getElementById('hardcore-toggle').disabled = true;
}

function initHardcoreToggle() {
    HARDCORE = localStorage.getItem('guitardle_hardcore') === '1';
    const box = document.getElementById('hardcore-toggle');
    box.checked = HARDCORE;
    box.addEventListener('change', () => {
        // Backstop for the disabled attribute: never switch mid-game (off =
        // cap escape hatch, on = could instantly starve a live game).
        if (state.moves > 0 || state.gameOver) {
            box.checked = HARDCORE;
            return;
        }
        HARDCORE = box.checked;
        localStorage.setItem('guitardle_hardcore', HARDCORE ? '1' : '0');
        renderMoves();
        refreshCapState();
    });
}

// ─────────────────────────────────────────────────────────────────────────────
//  MEMBER SCORE SYNC
// ─────────────────────────────────────────────────────────────────────────────
// Ask the API who we are. Anonymous (or endpoint missing) → authenticated:false
// and the game plays exactly as before, local-only.
let scoreSyncPromise = Promise.resolve();
function initScoreSync() {
    scoreSyncPromise = (async () => {
        try {
            const res = await fetch(SCORE_API, { credentials: 'same-origin' });
            if (res.ok) scoreAuth = await res.json();
        } catch (e) { /* offline/anon — local play unaffected */ }
    })();
}

// Record today's result for a logged-in member. Server keys on (user, date) and
// keeps the FIRST result, so a replay from a cleared browser can't overwrite.
// Waits for the auth handshake — a forfeit fires at page load, which can be
// before initScoreSync() resolves.
function postScore(won, streak) {
    const moves = state.moves;
    scoreSyncPromise.then(() => {
        if (!scoreAuth.authenticated || !scoreAuth.nonce) return;
        return fetch(SCORE_API, {
            method:      'POST',
            credentials: 'same-origin',
            headers:     { 'Content-Type': 'application/json', 'X-WP-Nonce': scoreAuth.nonce },
            body: JSON.stringify({
                phrase_id: PHRASE_ID,
                won:       !!won,
                moves:     moves,
                streak:    streak,
                hardcore:  HARDCORE,   // 2× points on the weekly board
            }),
        });
    }).catch(() => {});
}

// ─────────────────────────────────────────────────────────────────────────────
//  EMBED MODE (front-page block iframe)
// ─────────────────────────────────────────────────────────────────────────────
function initEmbedMode() {
    if (!IS_EMBED) return;
    document.body.classList.add('is-embed');
    // Height = the game wrapper's real bottom edge (+ breathing room), NOT
    // body height or documentElement.scrollHeight: the end-state card can
    // overflow the body without growing it (clipped the card bottom on the
    // front page), and scrollHeight never reports below the iframe viewport
    // so the frame could grow but never shrink back.
    const wrap = document.querySelector('.game-wrap');
    const post = () => {
        const h = Math.max(
            document.body.getBoundingClientRect().bottom,
            wrap ? wrap.getBoundingClientRect().bottom : 0
        ) + (window.scrollY || 0) + 16;
        parent.postMessage({ type: 'guitardle:height', height: h }, '*');
    };
    const ro = new ResizeObserver(post);
    ro.observe(document.body);
    if (wrap) ro.observe(wrap);
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(post);
    post();
}
// (The embed-mode in-modal weekly board is gone — Ian 6/12: the front-page
// card is the always-visible board; the trophy overlay is the full list.)

// ─────────────────────────────────────────────────────────────────────────────
//  PHRASE RENDERING
// ─────────────────────────────────────────────────────────────────────────────
function renderPhrase() {
    const words = PHRASE.split(' ');

    const allSegments   = words.flatMap(w => w.split('-'));
    const maxSegmentLen = Math.max(...allSegments.map(s => s.length));
    document.documentElement.style.setProperty('--max-tiles', maxSegmentLen);

    phraseRowEl.innerHTML = '';

    words.forEach(word => {
        const segments = word.split('-');
        segments.forEach((segment, i) => {
            const wordEl = document.createElement('div');
            wordEl.className = 'word';

            [...segment].forEach(char => {
                const tile = document.createElement('div');
                tile.className = 'tile blank';
                tile.dataset.letter = char;
                wordEl.appendChild(tile);
            });

            if (i < segments.length - 1) {
                const hyphen = document.createElement('span');
                hyphen.className = 'tile-hyphen';
                hyphen.textContent = '-';
                hyphen.setAttribute('aria-hidden', 'true');
                wordEl.appendChild(hyphen);
            }

            phraseRowEl.appendChild(wordEl);
        });
    });
}

// ─────────────────────────────────────────────────────────────────────────────
//  SCORE BOX
// ─────────────────────────────────────────────────────────────────────────────
function updateScoreBox(moves) {
    const tier = moves <= 4 ? 0 : moves <= 7 ? 1 : moves <= 9 ? 2 : 3;
    moveCounterEl.className = moveCounterEl.className
        .replace(/\bscore-tier-\d\b/g, '')
        .trim() + ` score-tier-${tier}`;
}

function incrementMoves() {
    state.moves++;
    renderMoves();
    updateScoreBox(state.moves);
    refreshCapState();
    lockHardcoreToggle();   // mode is committed from the first click
    saveGame();             // refresh-proof: every move snapshots the position
}

// ─────────────────────────────────────────────────────────────────────────────
//  SAVED GAME (refresh-proof)
// ─────────────────────────────────────────────────────────────────────────────
function saveGame() {
    localStorage.setItem(SAVE_KEY, JSON.stringify({
        date:      todayString(),
        phraseId:  PHRASE_ID,
        hardcore:  HARDCORE,
        moves:     state.moves,
        revealed:  [...state.revealedLetters],
        purchased: [...state.purchasedVowels],
    }));
}

function clearSavedGame() {
    localStorage.removeItem(SAVE_KEY);
}

// Restore a mid-game snapshot from earlier today. Replays the saved position
// onto the board: tiles, keyboard key states, move count, hardcore lock.
function restoreSavedGame() {
    let saved = null;
    try { saved = JSON.parse(localStorage.getItem(SAVE_KEY) || 'null'); }
    catch (e) { /* corrupt save — treat as none */ }
    if (!saved) return;
    if (saved.date !== todayString() || saved.phraseId !== PHRASE_ID) {
        clearSavedGame();   // another day / other audience track / resequenced
        return;
    }

    // The mode you started with is the mode you resume in.
    HARDCORE = !!saved.hardcore;
    const box = document.getElementById('hardcore-toggle');
    box.checked = HARDCORE;
    localStorage.setItem('guitardle_hardcore', HARDCORE ? '1' : '0');

    state.moves           = saved.moves | 0;
    state.revealedLetters = new Set(saved.revealed || []);
    state.purchasedVowels = new Set(saved.purchased || []);

    state.revealedLetters.forEach(letter => {
        revealTiles(letter);
        const keyEl = keyboardEl.querySelector(`.key[data-letter="${letter}"]`);
        if (keyEl) { keyEl.classList.add('used'); keyEl.disabled = true; }
    });
    state.purchasedVowels.forEach(letter => {
        const keyEl = keyboardEl.querySelector(`.key[data-letter="${letter}"]`);
        if (keyEl) keyEl.classList.add('purchased');
    });

    renderMoves();
    updateScoreBox(state.moves);
    refreshCapState();
    if (state.moves > 0) lockHardcoreToggle();
}

// ─────────────────────────────────────────────────────────────────────────────
//  NORMAL PLAY — reveal letters
// ─────────────────────────────────────────────────────────────────────────────
function revealTiles(letter) {
    phraseRowEl.querySelectorAll(`.tile[data-letter="${letter}"]`).forEach(tile => {
        tile.classList.remove('blank');
        tile.classList.add('revealed');
        tile.textContent = letter;
    });
}

function handleConsonant(letter, keyEl) {
    if (outOfReveals()) return;
    if (state.revealedLetters.has(letter)) return;
    state.revealedLetters.add(letter);
    revealTiles(letter);
    incrementMoves();
    keyEl.classList.add('used');
    keyEl.disabled = true;
}

function handleVowel(letter, keyEl) {
    if (outOfReveals()) return;
    if (state.revealedLetters.has(letter)) return;

    if (!state.purchasedVowels.has(letter)) {
        state.purchasedVowels.add(letter);
        incrementMoves();
        keyEl.classList.add('purchased');
    } else {
        state.purchasedVowels.delete(letter);
        state.revealedLetters.add(letter);
        revealTiles(letter);
        incrementMoves();
        keyEl.classList.remove('purchased');
        keyEl.classList.add('used');
        keyEl.disabled = true;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
//  GUESS MODE
// ─────────────────────────────────────────────────────────────────────────────
function enterGuessMode() {
    guessState.vowelSnapshot = [];
    document.querySelectorAll('.key.vowel').forEach(keyEl => {
        guessState.vowelSnapshot.push({
            keyEl,
            className: keyEl.className,
            disabled:  keyEl.disabled,
        });
        const letter      = keyEl.dataset.letter;
        const isPurchased = state.purchasedVowels.has(letter);
        const isUsed      = keyEl.classList.contains('used');
        if (!isPurchased && !isUsed) {
            keyEl.disabled = false;
            keyEl.classList.add('guess-mode');
        }
    });

    guessState.blankTiles = [...phraseRowEl.querySelectorAll('.tile.blank')];
    guessState.blankTiles.forEach(t => {
        t.classList.remove('blank');
        t.classList.add('editable');
    });

    guessState.activeTileIdx = 0;
    setActiveTile(0);

    guessDefaultEl.style.display = 'none';
    guessConfirmEl.style.display = 'flex';
    btnBackspace.style.display   = '';

    guessState.active = true;
    keyboardEl.classList.add('guessing');   // lift the hardcore key-dim while typing
    checkConfirmButton();
}

function exitGuessMode(cancelled) {
    guessState.active = false;
    keyboardEl.classList.remove('guessing');

    if (cancelled) {
        guessState.blankTiles.forEach(t => {
            t.textContent = '';
            t.classList.remove('active', 'editable');
            t.classList.add('blank');
        });
        guessState.vowelSnapshot.forEach(({ keyEl, className, disabled }) => {
            keyEl.className = className;
            keyEl.disabled  = disabled;
        });
    } else {
        phraseRowEl.querySelectorAll('.tile.active').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.key.vowel.guess-mode').forEach(k => k.classList.remove('guess-mode'));
    }

    guessDefaultEl.style.display = '';
    guessConfirmEl.style.display = 'none';
    btnBackspace.style.display   = 'none';
    btnConfirm.disabled = true;
}

function setActiveTile(idx) {
    guessState.blankTiles.forEach(t => t.classList.remove('active'));
    if (idx >= 0 && idx < guessState.blankTiles.length) {
        guessState.blankTiles[idx].classList.add('active');
    }
    guessState.activeTileIdx = idx;
}

function checkConfirmButton() {
    const allFilled = guessState.blankTiles.every(t => t.textContent !== '');
    btnConfirm.disabled = !allFilled;
}

function handleGuessKey(letter) {
    const { blankTiles, activeTileIdx } = guessState;
    if (activeTileIdx >= blankTiles.length) return;

    blankTiles[activeTileIdx].textContent = letter;

    const nextIdx = activeTileIdx + 1;
    if (nextIdx < blankTiles.length) {
        setActiveTile(nextIdx);
    } else {
        blankTiles[activeTileIdx].classList.remove('active');
        guessState.activeTileIdx = nextIdx;
    }
    checkConfirmButton();
}

function handleBackspace() {
    const { blankTiles } = guessState;
    let idx = guessState.activeTileIdx;

    if (idx >= blankTiles.length) {
        idx = blankTiles.length - 1;
        blankTiles[idx].textContent = '';
        setActiveTile(idx);
        checkConfirmButton();
        return;
    }

    const current = blankTiles[idx];
    if (current.textContent) {
        current.textContent = '';
        setActiveTile(idx);
    } else if (idx > 0) {
        blankTiles[idx - 1].textContent = '';
        setActiveTile(idx - 1);
    }
    checkConfirmButton();
}

function confirmGuess() {
    const guessed = [...phraseRowEl.querySelectorAll('.tile')]
        .map(t => t.textContent.trim().toUpperCase())
        .join('');

    if (guessed === PHRASE_LETTERS) {
        handleWin();
    } else {
        handleLoss();
    }
}

// ─────────────────────────────────────────────────────────────────────────────
//  STREAK TRACKING
// ─────────────────────────────────────────────────────────────────────────────

// Returns the new streak value after updating localStorage.
function updateStreak(won) {
    const today      = todayString();
    const yesterday  = (() => {
        const d = new Date(today + 'T00:00:00');
        d.setDate(d.getDate() - 1);
        return d.toISOString().slice(0, 10);
    })();

    const lastPlayed = localStorage.getItem('guitardle_lastPlayed');
    const prevStreak = parseInt(localStorage.getItem('guitardle_streak') || '0', 10);

    let newStreak;
    if (!won) {
        newStreak = 0;
    } else if (lastPlayed === yesterday) {
        newStreak = prevStreak + 1;
    } else {
        // No previous record, or gap of more than one day
        newStreak = 1;
    }

    const bestStreak = Math.max(
        parseInt(localStorage.getItem('guitardle_bestStreak') || '0', 10),
        newStreak
    );

    // Games played / won
    const gamesPlayed = parseInt(localStorage.getItem('guitardle_gamesPlayed') || '0', 10) + 1;
    const gamesWon    = parseInt(localStorage.getItem('guitardle_gamesWon')    || '0', 10) + (won ? 1 : 0);

    // Score distribution — update the appropriate band on a win
    const dist = JSON.parse(localStorage.getItem('guitardle_scoreDistribution') || '{"1-4":0,"5-7":0,"8-9":0,"10+":0}');
    if (won) {
        const moves = state.moves;
        const band  = moves <= 4 ? '1-4' : moves <= 7 ? '5-7' : moves <= 9 ? '8-9' : '10+';
        dist[band] = (dist[band] || 0) + 1;
    }

    localStorage.setItem('guitardle_streak',            String(newStreak));
    localStorage.setItem('guitardle_bestStreak',        String(bestStreak));
    localStorage.setItem('guitardle_lastPlayed',        today);
    localStorage.setItem('guitardle_gamesPlayed',       String(gamesPlayed));
    localStorage.setItem('guitardle_gamesWon',          String(gamesWon));
    localStorage.setItem('guitardle_scoreDistribution', JSON.stringify(dist));

    return newStreak;
}

// ─────────────────────────────────────────────────────────────────────────────
//  END STATES
// ─────────────────────────────────────────────────────────────────────────────
function handleWin() {
    state.gameOver = true;
    clearSavedGame();
    exitGuessMode(false);

    phraseRowEl.querySelectorAll('.tile.editable').forEach(tile => {
        tile.classList.remove('editable');
        tile.classList.add('revealed');
    });

    const streak = updateStreak(true);
    postScore(true, streak);
    showEndState(true, streak);
}

function handleLoss() {
    state.gameOver = true;
    clearSavedGame();
    exitGuessMode(false);

    phraseRowEl.querySelectorAll('.tile.blank, .tile.editable').forEach(tile => {
        tile.classList.remove('blank', 'editable');
        tile.classList.add('revealed-loss');
        tile.textContent = tile.dataset.letter;
    });

    updateStreak(false);
    postScore(false, 0);
    showEndState(false, 0);
}

function showEndState(won, streak) {
    lockHardcoreToggle();
    guessAreaEl.style.display     = 'none';
    vowelInstructEl.style.display = 'none';
    keyboardEl.style.display      = 'none';
    gameMainEl.classList.add('game-over');

    if (won) {
        const moves      = state.moves;
        const solvedText = `Solved in ${moves} move${moves === 1 ? '' : 's'}`;

        document.getElementById('share-date').textContent   = `🎸 Guitardle · ${formatDate()}`;
        document.getElementById('share-solved').textContent = solvedText;

        buildScoreBar(moves);

        // Show streak line only for streaks of 2 or more
        if (streak >= 2) {
            const streakEl = document.getElementById('share-streak');
            streakEl.textContent    = `🔥 ${streak} day streak`;
            streakEl.style.display  = '';
        }

        document.getElementById('result-win').style.display = 'flex';
    } else {
        document.getElementById('result-loss').style.display = 'flex';
    }

    endStateEl.style.display = 'flex';
}

// Show a locked end state when the player has already played today.
// Reveals the full phrase but shows no move count or score. `result`
// (optional, from the server) personalizes the recap for members.
function showAlreadyPlayed(result) {
    state.gameOver = true;
    clearSavedGame();
    lockHardcoreToggle();

    // Reveal all tiles
    phraseRowEl.querySelectorAll('.tile.blank').forEach(tile => {
        tile.classList.remove('blank');
        tile.classList.add('revealed');
        tile.textContent = tile.dataset.letter;
    });

    guessAreaEl.style.display     = 'none';
    vowelInstructEl.style.display = 'none';
    keyboardEl.style.display      = 'none';
    gameMainEl.classList.add('game-over');

    // Disable all keyboard keys
    document.querySelectorAll('.key').forEach(k => { k.disabled = true; });

    // Show a minimal already-played message in the loss card
    const lossCard = document.getElementById('result-loss');
    lossCard.querySelector('.result-emoji').textContent    = '🎸';
    lossCard.querySelector('.result-headline').textContent = 'Already played today!';
    lossCard.querySelector('.result-subline').textContent  = result
        ? (result.won
            ? `You solved it in ${result.moves} move${result.moves === 1 ? '' : 's'} — come back tomorrow.`
            : 'No luck this time — come back tomorrow.')
        : 'Come back tomorrow.';
    lossCard.style.display = 'flex';
    endStateEl.style.display = 'flex';
}

function buildScoreBar(moves) {
    const bar = document.getElementById('score-bar');
    bar.innerHTML = '';
    for (let i = 1; i <= moves; i++) {
        const seg = document.createElement('div');
        seg.className = 'score-seg';
        seg.style.backgroundColor =
            i <= 4 ? '#7EBF1D' :
            i <= 7 ? '#E8D633' :
            i <= 9 ? '#DD8F33' : '#D7523F';
        bar.appendChild(seg);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
//  KEYBOARD LISTENERS
// ─────────────────────────────────────────────────────────────────────────────
function attachKeyboardListeners() {
    document.querySelectorAll('.key[data-letter]').forEach(keyEl => {
        const letter = keyEl.dataset.letter;
        keyEl.addEventListener('click', () => {
            if (state.gameOver) return;

            if (guessState.active) {
                handleGuessKey(letter);
            } else if (VOWELS.has(letter)) {
                handleVowel(letter, keyEl);
            } else {
                handleConsonant(letter, keyEl);
            }
        });
    });

    btnBackspace.addEventListener('click', () => {
        if (guessState.active) handleBackspace();
    });

    btnGuess.addEventListener('click', () => {
        if (state.gameOver) return;
        enterGuessMode();
    });

    btnConfirm.addEventListener('click', () => {
        if (!btnConfirm.disabled) confirmGuess();
    });

    btnCancel.addEventListener('click', () => {
        exitGuessMode(true);
    });

    btnShare.addEventListener('click', () => {
        const moves  = state.moves;
        const streak = parseInt(localStorage.getItem('guitardle_streak') || '0', 10);

        // Build emoji score blocks — one per move, colour by tier
        const blocks = Array.from({ length: moves }, (_, i) => {
            const n = i + 1;
            return n <= 4 ? '🟩' : n <= 7 ? '🟨' : n <= 9 ? '🟧' : '🟥';
        }).join('');

        // Format date as "6 Apr 2026"
        const d       = new Date();
        const months  = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        const dateStr = `${d.getDate()} ${months[d.getMonth()]} ${d.getFullYear()}`;

        const solvedText = `Solved in ${moves} move${moves === 1 ? '' : 's'}`;

        const lines = [
            `🎸 Guitardle · ${dateStr}`,
            blocks,
            solvedText,
        ];
        if (streak >= 2) lines.push(`🔥 ${streak} day streak`);
        lines.push('', siteConfig.gameUrl || 'loothgroup.com/guitardle');

        navigator.clipboard.writeText(lines.join('\n')).then(() => {
            const copiedEl = document.getElementById('share-copied');
            // Reset animation by removing and re-adding the element's class
            copiedEl.style.display  = '';
            copiedEl.style.animation = 'none';
            // Force reflow so animation restarts cleanly
            void copiedEl.offsetHeight;
            copiedEl.style.animation = '';
            // Hide after animation completes
            setTimeout(() => { copiedEl.style.display = 'none'; }, 4000);
        });
    });
}

// ─────────────────────────────────────────────────────────────────────────────
//  MENU BAR
// ─────────────────────────────────────────────────────────────────────────────
// The hamburger site-nav is gone (Ian 6/11) — the game lives on the front page,
// so it doesn't need its own navigation. Its slot shows the weekly #1 (crown).
function initMenuBar() {
    document.getElementById('btn-board').addEventListener('click', openBoard);

    // Stats
    document.getElementById('btn-stats').addEventListener('click', () => {
        openStats();
    });

}

// ─────────────────────────────────────────────────────────────────────────────
//  WEEKLY LEADERBOARD
// ─────────────────────────────────────────────────────────────────────────────
// Data from /archive-api/v0/guitardle-board: this week's members ranked by
// points (each win = 11 − moves pts, min 1, hardcore 2×; losses 0), full
// list, no cap. The front page's side panel shows the same board.
let boardData = null;

async function initBoard() {
    try {
        const res = await fetch(BOARD_API, { credentials: 'same-origin' });
        if (!res.ok) return;
        boardData = await res.json();
    } catch (e) { /* board is decoration — never block the game */ }
}

function openBoard() {
    const list  = document.getElementById('board-list');
    const empty = document.getElementById('board-empty');
    const leaders = (boardData && boardData.leaders) || [];

    list.innerHTML = '';
    empty.style.display = leaders.length ? 'none' : '';
    leaders.forEach((l, i) => {
        const li = document.createElement('li');
        li.className = 'board-row' + (i === 0 ? ' board-row--first' : '');
        const rank = document.createElement('span');
        rank.className = 'board-rank';
        rank.textContent = i === 0 ? '👑' : String(i + 1);
        // Name links to the member's profile when we have one. target=_top:
        // this page is usually iframed by the front page — navigate the
        // whole tab, not the game frame.
        const name = document.createElement(l.profile_url ? 'a' : 'span');
        name.className = 'board-name';
        name.textContent = l.name;
        if (l.profile_url) {
            name.href = l.profile_url;
            name.target = '_top';
        }
        const meta = document.createElement('span');
        meta.className = 'board-meta';
        meta.textContent = `${l.points} pt${l.points === 1 ? '' : 's'} · ${l.wins} win${l.wins === 1 ? '' : 's'}`;
        li.append(rank, name, meta);
        list.appendChild(li);
    });

    document.getElementById('overlay-board').style.display = 'flex';
}

function closeBoard() {
    document.getElementById('overlay-board').style.display = 'none';
}

function initBoardOverlay() {
    const overlay  = document.getElementById('overlay-board');
    document.getElementById('btn-board-close').addEventListener('click', closeBoard);
    overlay.addEventListener('click', (e) => {
        if (e.target === overlay) closeBoard();
    });
}

// ─────────────────────────────────────────────────────────────────────────────
//  STATS OVERLAY
// ─────────────────────────────────────────────────────────────────────────────
function openStats() {
    const played   = parseInt(localStorage.getItem('guitardle_gamesPlayed') || '0', 10);
    const won      = parseInt(localStorage.getItem('guitardle_gamesWon')    || '0', 10);
    const streak   = parseInt(localStorage.getItem('guitardle_streak')      || '0', 10);
    const best     = parseInt(localStorage.getItem('guitardle_bestStreak')  || '0', 10);
    const dist     = JSON.parse(localStorage.getItem('guitardle_scoreDistribution') || '{"1-4":0,"5-7":0,"8-9":0,"10+":0}');

    const winRate  = played > 0 ? Math.round((won / played) * 100) : 0;

    document.getElementById('stat-played').textContent  = played;
    document.getElementById('stat-winrate').textContent = `${winRate}%`;
    document.getElementById('stat-streak').textContent  = streak;
    document.getElementById('stat-best').textContent    = best;

    // Score distribution bars — scale so the max count fills 90% width
    const bands   = ['1-4', '5-7', '8-9', '10+'];
    const barIds  = { '1-4': 'dist-bar-1-4', '5-7': 'dist-bar-5-7', '8-9': 'dist-bar-8-9', '10+': 'dist-bar-10' };
    const counts  = bands.map(b => dist[b] || 0);
    const maxCount = Math.max(...counts, 1); // avoid division by zero

    bands.forEach((band, i) => {
        const count  = counts[i];
        const pct    = Math.max(Math.round((count / maxCount) * 90), 15);
        const barEl  = document.getElementById(barIds[band]);
        barEl.style.width = `${pct}%`;
        barEl.querySelector('.dist-count').textContent = count;
    });

    document.getElementById('overlay-stats').style.display = 'flex';
}

function closeStats() {
    document.getElementById('overlay-stats').style.display = 'none';
}

function initStats() {
    const overlay  = document.getElementById('overlay-stats');
    const closeBtn = document.getElementById('btn-stats-close');

    closeBtn.addEventListener('click', closeStats);
    overlay.addEventListener('click', (e) => {
        if (e.target === overlay) closeStats();
    });
}

// ─────────────────────────────────────────────────────────────────────────────
//  INIT
// ─────────────────────────────────────────────────────────────────────────────
async function init() {
    // Testing door: ?reset=1 wipes this browser's guitardle state (streaks,
    // played-today lock, saved game), then strips itself from the URL —
    // ONE-SHOT, so refreshing the tab doesn't keep re-wiping the lock and
    // granting endless replays. For members the server lock below still
    // rules: a recorded result locks the day no matter what storage says.
    if (new URLSearchParams(location.search).has('reset')) {
        Object.keys(localStorage)
            .filter(k => k.startsWith('guitardle_'))
            .forEach(k => localStorage.removeItem(k));
        const u = new URL(location.href);
        u.searchParams.delete('reset');
        history.replaceState(null, '', u);
    }
    initEmbedMode();
    initScoreSync();   // fire-and-forget; nonce arrives long before game end
    initBoard();       // fire-and-forget; crown chip pops in when it lands
    await loadPhrase();

    renderPhrase();
    attachKeyboardListeners();
    initMenuBar();
    initBoardOverlay();
    initStats();
    initHardcoreToggle();
    updateScoreBox(0);
    renderMoves();

    // Already finished today → locked recap. Otherwise restore any mid-game
    // snapshot from earlier today — refresh/close is a non-event, the game
    // resumes exactly where it was (Ian 6/12: forfeit rule retired).
    if (localStorage.getItem('guitardle_lastPlayed') === todayString()) {
        clearSavedGame();   // stale save from a finished game
        showAlreadyPlayed();
    } else {
        restoreSavedGame();
    }

    // SERVER lock for members: localStorage is wipeable (reset door, site-data
    // clear, another device), but a recorded result means today is DONE. When
    // the auth handshake lands, lock the board if the server already has a
    // row — even if a fresh local game snuck in a move or two meanwhile.
    scoreSyncPromise.then(() => {
        if (scoreAuth.authenticated && scoreAuth.today && !state.gameOver) {
            localStorage.setItem('guitardle_lastPlayed', todayString());
            showAlreadyPlayed(scoreAuth.today);
        }
    });
}

document.addEventListener('DOMContentLoaded', init);

// 2026-06-13 buck: embed-mode card is now content-sized via style.css body.is-embed block (no JS change; this note bumps mtime for the iframe ?v= cache-bust).
// 6/12 pass 2: color-scheme light (force-dark fix) + hardcore contrast + side art — mtime bump again.
