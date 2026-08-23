import { ref } from 'vue';

/**
 * The alert chimes, and the browser's permission to make them.
 *
 * Every browser refuses to play audio until the page has been interacted
 * with at least once. It refuses silently: an AudioContext created before
 * that gesture is born `suspended`, its clock does not advance, and notes
 * scheduled against it are simply never heard. No error, no warning.
 *
 * That is why the service chime "sometimes" worked. A screen somebody had
 * tapped played it; a waiter board mounted on a wall and untouched since
 * opening never did — and a table calling for service on a screen that
 * cannot make a sound is a call nobody hears.
 *
 * Two things fix it, and the old code did neither:
 *
 *  1. **One context, resumed.** It used to build a brand new AudioContext
 *     per chime and never call resume(), so every chime inherited the
 *     blocked state afresh. Browsers also cap how many contexts one page
 *     may hold, which a per-chime context walks straight into on a busy
 *     evening.
 *
 *  2. **Say so when it is blocked.** If the browser will not let us make a
 *     sound, the screen has to admit it rather than pretend the alert was
 *     delivered. `blocked` drives that; one tap anywhere clears it.
 *
 * Everything is synthesised rather than loaded from a file on purpose: the
 * app ships no audio assets, and a packaged desktop install has no network
 * to fetch one from. Oscillators also start instantly — an <audio> element
 * that has not finished buffering is one more way for the first alert of
 * the evening to be the one nobody hears.
 */

/** True while the browser is refusing to play anything. */
export const soundBlocked = ref(false);

/**
 * How loud, 0..1. Exposed as a ref so a settings screen can bind to it
 * later without this module needing to know that screen exists.
 */
export const alertVolume = ref(0.6);

let context = null;
let listening = false;

/**
 * Distinct voices, so a busy floor can tell what happened without looking.
 *
 * Each entry is a list of [frequency, startOffset, duration] notes.
 *
 *  - `order`     — a rising major triad (C6-E6-G6). Open and bright: this
 *                  is the good news, a table has ordered.
 *  - `addition`  — the same triad's top two notes, twice, quicker. Reads as
 *                  "again" rather than "new", which is exactly what it is.
 *  - `service`   — the original rising B5-D6-G6. Deliberately unchanged:
 *                  the floor already knows this one as "a table wants you".
 */
const TONES = {
    order: [
        [1046.5, 0, 0.3],
        [1318.51, 0.16, 0.3],
        [1567.98, 0.32, 0.42],
    ],
    addition: [
        [1318.51, 0, 0.22],
        [1567.98, 0.12, 0.26],
        [1318.51, 0.3, 0.22],
        [1567.98, 0.42, 0.32],
    ],
    service: [
        [987.77, 0, 0.3],
        [1174.66, 0.16, 0.3],
        [1567.98, 0.32, 0.3],
    ],
};

/**
 * When the context's clock is next free of scheduled notes.
 *
 * Four tables ordering at once used to schedule four chimes at the same
 * instant, which does not sound like four alerts — it sounds like one
 * louder, muddier alert, and the staff learn nothing from it. Notes are
 * queued onto the end of whatever is already playing instead, so a burst
 * arrives as a countable sequence.
 */
let freeAt = 0;

/** Past this, a backlog is stale news — better late alerts than a queue
 *  still chiming a minute after the rush. */
const MAX_QUEUE_SECONDS = 6;

function audioContext() {
    const AudioContextClass = window.AudioContext || window.webkitAudioContext;

    if (!AudioContextClass) {
        return null;
    }

    if (!context || context.state === 'closed') {
        context = new AudioContextClass();
    }

    return context;
}

/**
 * Takes the first user gesture as permission and never asks again.
 *
 * Bound once per page, on the events browsers accept as intent. They fire
 * for a tap on a tablet as readily as a click, which matters because the
 * screens this runs on are mostly touched, not clicked.
 */
function listenForFirstGesture() {
    if (listening) {
        return;
    }

    listening = true;

    const unlock = () => {
        const ctx = audioContext();

        ctx?.resume().then(() => {
            soundBlocked.value = ctx.state !== 'running';
        }).catch(() => {});
    };

    ['pointerdown', 'keydown', 'touchstart'].forEach((event) => {
        window.addEventListener(event, unlock, { passive: true });
    });
}

/**
 * Plays one named alert, queued behind anything already sounding.
 *
 * @param {'order'|'addition'|'service'} type
 */
export function playNotification(type = 'service') {
    const ctx = audioContext();

    if (!ctx) {
        soundBlocked.value = true;

        return;
    }

    listenForFirstGesture();

    // Harmless when already running, and the one thing that turns a
    // silently-suspended context into an audible one.
    ctx.resume().catch(() => {});

    if (ctx.state !== 'running') {
        // Scheduling into a suspended context produces nothing and reports
        // nothing. Saying it out loud is the whole point.
        soundBlocked.value = true;

        return;
    }

    soundBlocked.value = false;

    try {
        const notes = TONES[type] ?? TONES.service;
        const now = ctx.currentTime;

        // Start after whatever is still playing, but never build a backlog
        // longer than MAX_QUEUE_SECONDS — a rush of twenty orders should
        // not still be chiming when the next one arrives.
        const start = Math.min(Math.max(freeAt, now), now + MAX_QUEUE_SECONDS);
        const peak = Math.max(0, Math.min(1, alertVolume.value));

        notes.forEach(([frequency, offset, duration]) => {
            const at = start + offset;
            const oscillator = ctx.createOscillator();
            const gain = ctx.createGain();

            oscillator.type = 'triangle';
            oscillator.frequency.value = frequency;

            gain.gain.setValueAtTime(0, at);
            gain.gain.linearRampToValueAtTime(peak, at + 0.015);
            gain.gain.exponentialRampToValueAtTime(0.001, at + duration);

            oscillator.connect(gain);
            gain.connect(ctx.destination);
            oscillator.start(at);
            oscillator.stop(at + duration + 0.05);
        });

        const last = notes[notes.length - 1];

        // A short gap after the tail, so two alerts read as two.
        freeAt = start + last[1] + last[2] + 0.12;
    } catch {
        // A note that will not play is not worth taking the alert down for:
        // the banner and the popup still carry it.
        soundBlocked.value = true;
    }
}

/**
 * The service chime, by its original name.
 *
 * Kept because the waiter-call path and the unlock notice both call it, and
 * because "the sound a table makes when it wants you" is a thing the floor
 * already recognises.
 */
export function playAlertChime() {
    playNotification('service');
}

/**
 * Starts watching for the gesture before any call arrives, so the very
 * first one is audible rather than the second.
 */
export function prepareAlertSound() {
    const ctx = audioContext();

    listenForFirstGesture();

    soundBlocked.value = ctx === null || ctx.state !== 'running';

    ctx?.resume().then(() => {
        soundBlocked.value = ctx.state !== 'running';
    }).catch(() => {});
}
