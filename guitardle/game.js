'use strict';

const VOWELS = new Set(['A', 'E', 'I', 'O', 'U']);

// Set by loadPhrase() before the game starts
let PHRASE        = '';
let PHRASE_LETTERS = '';
let siteConfig    = {};

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

    // Calculate today's phrase index
    const today   = todayString();
    const elapsed = daysBetween(seqData.startDate, today);
    const idx     = ((elapsed % seqData.sequence.length) + seqData.sequence.length) % seqData.sequence.length;
    const phraseId = seqData.sequence[idx];

    PHRASE        = phraseMap.get(phraseId).toUpperCase();
    PHRASE_LETTERS = PHRASE.replace(/[-\s]/g, '');
}

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
    moveCountEl.textContent = state.moves;
    updateScoreBox(state.moves);
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
    if (state.revealedLetters.has(letter)) return;
    state.revealedLetters.add(letter);
    revealTiles(letter);
    incrementMoves();
    keyEl.classList.add('used');
    keyEl.disabled = true;
}

function handleVowel(letter, keyEl) {
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
    checkConfirmButton();
}

function exitGuessMode(cancelled) {
    guessState.active = false;

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
    exitGuessMode(false);

    phraseRowEl.querySelectorAll('.tile.editable').forEach(tile => {
        tile.classList.remove('editable');
        tile.classList.add('revealed');
    });

    const streak = updateStreak(true);
    showEndState(true, streak);
}

function handleLoss() {
    state.gameOver = true;
    exitGuessMode(false);

    phraseRowEl.querySelectorAll('.tile.blank, .tile.editable').forEach(tile => {
        tile.classList.remove('blank', 'editable');
        tile.classList.add('revealed-loss');
        tile.textContent = tile.dataset.letter;
    });

    updateStreak(false);
    showEndState(false, 0);
}

function showEndState(won, streak) {
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
// Reveals the full phrase but shows no move count or score.
function showAlreadyPlayed() {
    state.gameOver = true;

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
    lossCard.querySelector('.result-subline').textContent  = 'Come back tomorrow.';
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
function initMenuBar() {
    const btnMenu    = document.getElementById('btn-menu');
    const dropdown   = document.getElementById('menu-dropdown');

    // Populate dropdown from config
    dropdown.innerHTML = '';
    (siteConfig.menuLinks || []).forEach(({ label, url }) => {
        const li = document.createElement('li');
        const a  = document.createElement('a');
        a.href        = url;
        a.textContent = label;
        a.target      = '_blank';
        a.rel         = 'noopener noreferrer';
        li.appendChild(a);
        dropdown.appendChild(li);
    });

    // Toggle dropdown, positioned below the hamburger button
    btnMenu.addEventListener('click', (e) => {
        e.stopPropagation();
        const isOpen = dropdown.classList.contains('open');
        if (isOpen) {
            dropdown.classList.remove('open');
            btnMenu.setAttribute('aria-expanded', 'false');
        } else {
            const rect = btnMenu.getBoundingClientRect();
            dropdown.style.top  = `${rect.bottom + 4}px`;
            dropdown.style.left = `${rect.left}px`;
            dropdown.classList.add('open');
            btnMenu.setAttribute('aria-expanded', 'true');
        }
    });

    // Close on outside click
    document.addEventListener('click', () => {
        if (dropdown.classList.contains('open')) {
            dropdown.classList.remove('open');
            btnMenu.setAttribute('aria-expanded', 'false');
        }
    });

    // Stats
    document.getElementById('btn-stats').addEventListener('click', () => {
        openStats();
    });

    // Help — opens instructions overlay
    document.getElementById('btn-help').addEventListener('click', () => {
        openInstructions();
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
//  INSTRUCTIONS OVERLAY
// ─────────────────────────────────────────────────────────────────────────────
function openInstructions() {
    document.getElementById('overlay-instructions').style.display = 'flex';
}

function closeInstructions() {
    document.getElementById('overlay-instructions').style.display = 'none';
}

function initInstructions() {
    const overlay = document.getElementById('overlay-instructions');
    const closeBtn = document.getElementById('btn-overlay-close');

    closeBtn.addEventListener('click', closeInstructions);

    // Clicking the backdrop (outside the panel) closes the overlay
    overlay.addEventListener('click', (e) => {
        if (e.target === overlay) closeInstructions();
    });

    // Show automatically on first visit
    if (!localStorage.getItem('guitardle_visited')) {
        localStorage.setItem('guitardle_visited', '1');
        openInstructions();
    }
}

// ─────────────────────────────────────────────────────────────────────────────
//  INIT
// ─────────────────────────────────────────────────────────────────────────────
async function init() {
    await loadPhrase();

    renderPhrase();
    attachKeyboardListeners();
    initMenuBar();
    initStats();
    initInstructions();
    updateScoreBox(0);

    // Check if already played today
    if (localStorage.getItem('guitardle_lastPlayed') === todayString()) {
        showAlreadyPlayed();
    }
}

document.addEventListener('DOMContentLoaded', init);
