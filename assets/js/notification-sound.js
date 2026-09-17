/**
 * assets/js/notification-sound.js
 *
 * One short chime when new notifications arrive. Shared by every panel that
 * polls a bell -- owner, manager, admin and the customer navbar -- so the four
 * of them can't drift into four different sounds.
 *
 * ONE SOUND PER ARRIVAL, NOT PER NOTIFICATION. The callers fire this when the
 * unread COUNT goes up, not once per item in the list: a poll that brings back
 * five new notifications is still a single chime. Five overlapping beeps for
 * one glance at the bell is how a notification sound becomes the thing people
 * turn off first.
 *
 * The tone is synthesised with the Web Audio API rather than loaded from an
 * .mp3, because there is no audio asset in this project and a two-note chime
 * is a handful of lines -- no file to ship, cache-bust or 404.
 */
(function () {
    'use strict';

    var ctx = null;

    /**
     * Browsers refuse to start audio until the user has interacted with the
     * page, and a context created before that opens 'suspended'. Rather than
     * fail on the first chime, we try to resume on any first gesture and stay
     * quiet until then -- a missed first beep is better than a console full of
     * rejected play() promises.
     */
    function unlock() {
        if (ctx && ctx.state === 'suspended') {
            ctx.resume().catch(function () { /* still blocked; try again next gesture */ });
        }
    }
    ['pointerdown', 'keydown'].forEach(function (evt) {
        document.addEventListener(evt, unlock, { passive: true });
    });

    /**
     * Two soft notes, a rising minor third. Deliberately short (~0.35s) and
     * quiet (peak gain 0.07) -- this fires in a working POS/back office, not a
     * game.
     */
    window.opoNotificationChime = function opoNotificationChime() {
        try {
            var AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) return;                       // very old browser: no sound, no error

            if (!ctx) ctx = new AudioCtx();
            if (ctx.state === 'suspended') {             // not unlocked by a gesture yet
                ctx.resume().catch(function () {});
                if (ctx.state === 'suspended') return;
            }

            var now = ctx.currentTime;
            [{ f: 880, at: 0 }, { f: 1174.66, at: 0.12 }].forEach(function (note) {
                var osc = ctx.createOscillator();
                var gain = ctx.createGain();
                osc.type = 'sine';
                osc.frequency.value = note.f;

                // Fade in and out: a bare start/stop on a sine wave clicks.
                var t = now + note.at;
                gain.gain.setValueAtTime(0.0001, t);
                gain.gain.exponentialRampToValueAtTime(0.07, t + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.0001, t + 0.22);

                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.start(t);
                osc.stop(t + 0.24);
            });
        } catch (e) {
            /* Sound is a nicety -- never let it break the polling that calls it. */
        }
    };
})();
