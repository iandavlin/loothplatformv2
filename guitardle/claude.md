# Guitardle — Project Brief

## What is Guitardle?
A daily phrase-guessing game embedded in the Looth Group WordPress website at loothgroup.com/guitardle. Players guess a hidden guitar-related phrase by revealing letters progressively, in the spirit of Wheel of Fortune. Free to play, no login required.

## Tech Stack
- Vanilla HTML, CSS, JavaScript
- Embedded in WordPress via a plugin or shortcode
- No external dependencies unless absolutely necessary
- Jost font via Google Fonts
- Two data files stored at /wp-content/guitardle/
  - phrases.csv — the phrase library
  - sequence.json — the shuffled play order

## Brand
- Font: Jost (Google Fonts)
- Dark green headers/structure: #87986A
- Mid green: #A8BE8B
- Light greens for backgrounds: #EAF0DD, #D4E0B8, #C2D5AA
- Amber for vowels and active states: #ECB351, #F1DE83
- Red accent (use sparingly): #FE654F
- Dark green for cards/backgrounds: #3a4a2e

## Game Mechanics
- A hidden guitar-related phrase is shown as blank tile boxes, one box per letter, words separated by spaces
- All consonants available from the start — selecting one reveals it in all correct positions — costs one move
- Y is treated as a consonant — free
- Vowels (A, E, I, O, U) are locked by default
- Two-tap vowel mechanic:
  - First tap purchases the vowel — costs one move, key shows as unlocked
  - Second tap reveals the vowel in all correct positions
  - Once revealed, vowel key moves to same used state as a consonant
- Player has one single guess attempt — wrong guess ends the game immediately
- Every distinct action costs one move — consonant reveal, vowel purchase, or guess attempt
- Lower move count is better

## Guess Workflow
- Player taps Guess the Phrase button
- Guess mode activates — blank tiles become editable
- Keyboard reappears with backspace key added
- Active tile has amber border with pulsing animation
- Cursor moves automatically between blank editable tiles, skipping revealed letters
- Backspace moves backwards through blank editable tiles only — cannot delete revealed letters
- Already-revealed tiles are fixed and uneditable
- Confirm button is inactive/greyed until all blank tiles are filled
- When all blanks filled, Confirm button activates and shifts to amber
- Cancel option available as an understated text link — exits guess mode with no penalty
- Correct guess = win, wrong guess = game over immediately

## End States

### Solved
- All tiles show complete phrase
- Display: Solved in X moves
- Colour block row animates in one block at a time, 150-200ms per block with pop/bounce
- Colour tiers: 1-4 moves = green, 5-7 = yellow, 8-9 = orange, 10+ = red
- Shareable result card is generated
- Share button copies text to clipboard
- Streak badge shown on card if streak is 2 or more days

### Unsolved
- All blank tiles fill in to reveal the complete phrase
- All other screen elements reduce to low opacity
- Revealed phrase is the focal point
- No shareable card
- Streak resets to zero

## Shareable Result Card (Solved Only)
- Square format
- Guitardle logotype
- Date
- Streak badge (only if streak >= 2 days)
- Solved in X moves as hero element
- Colour block row (static, same tiers as above)
- loothgroup.com/guitardle URL
- Dark green background, amber score, light green secondary text

## Share Text Format (Copy to Clipboard)
```
🎸 Guitardle · 29 March

🟩🟩🟩🟩🟨🟨
Solved in 6 moves
🔥 7 day streak

loothgroup.com/guitardle
```
- Streak line omitted if streak < 2 days
- Colour blocks match scoring tiers above

## Streaks
- Tracked via browser localStorage — no login required
- Streak badge and share line appear from 2 consecutive days onwards
- Streak resets to zero on any unsolved day
- Device and browser specific — accepted limitation for v1

## Phrase Management
- phrases.csv columns: id, phrase, active
- active = 1 to serve, active = 0 to retire without breaking sequence
- sequence.json stores the shuffled play order as an array of phrase IDs
- Daily phrase = position [days elapsed since launch date] in sequence.json
- Same phrase served to all players on the same day
- On update: new phrase IDs are shuffled into the remaining unplayed portion of sequence.json — already-played positions are never touched
- New phrases always appended to end of phrases.csv — never inserted mid-list

## UI Notes
- Mobile first — most players will be on phones
- Keyboard: consonants are white tiles, vowels are dashed/greyed with +1 cost label, purchased vowels show as amber, used keys show as grey
- Vowel key alignment: letter must be vertically centred in full key height regardless of cost label presence
- Move counter visible in header throughout
- One attempt only warning shown beneath Guess button
- Backspace key only appears during guess mode
- No category tag shown — phrase stands alone

## Key Files to Create
- index.html — main game interface
- style.css — all styling
- game.js — all game logic
- share.js — shareable card and clipboard logic
- sequence-generator.js — utility script to generate/update sequence.json
- guitardle-plugin.php — WordPress plugin to embed the game

## WordPress Embedding
- Game lives at loothgroup.com/guitardle
- Embedded via a simple WordPress plugin using a shortcode
- Plugin serves the static files from /wp-content/guitardle/
- phrases.csv and sequence.json stored in same folder
- Updated via FTP when new phrase batches are added

## Development Approach
- Build and test core game mechanic first — phrase display, keyboard, letter reveal
- Then add guess workflow
- Then end states and animations
- Then shareable card and clipboard
- Then streak tracking
- Then phrase management and sequence generator
- Then WordPress plugin wrapper last