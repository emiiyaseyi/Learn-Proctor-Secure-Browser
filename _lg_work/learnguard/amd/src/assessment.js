// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.

/**
 * LearnGuard SPA assessment controller.
 *
 * IMPORTANT: Proctoring (fullscreen, tab block, key block, webcam) is ONLY
 * activated after the student passes the pre-exam wizard and launches the
 * assessment. Teachers / managers / admins NEVER trigger proctoring, regardless
 * of whether they also hold the :attempt capability.
 *
 * @module     mod_learnguard/assessment
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([
    'core/ajax',
    'core/notification',
    'core/str',
], function(Ajax, Notification, Str) {
    'use strict';

    /* -----------------------------------------------------------------------
     * State
     * -------------------------------------------------------------------- */
    var cfg = {};
    var state = {
        attemptid:        0,
        currentslot:      1,
        totalquestions:   0,
        timelimit:        0,
        timestart:        0,
        navigation:       [],
        flags:            {},    // {slotnum: true} for flagged questions
        finished:         false,
        suspended:        false,
        paused:           false,
        riskScore:        0,
        riskLevel:        'low',
        webcamStream:     null,
        snapshotTimer:    null,
        pollTimer:        null,
        autosaveTimer:    null,
        countdownTimer:   null,
        scheduleTimer:    null,
        lastMessageTime:  0,
        proctoringActive:   false,
        fsBlocked:          false, // true while fullscreen-required blocker is visible
        screenSharePaused:  false, // true while waiting for learner to reshare screen
        serverOffset:     0,
        reinstatementMode:          false,  // true after a proctor reinstates a suspended attempt
        reinstateSuspendThreshold:  0,      // post-reinstate threshold; 0 = use cfg.autosuspend_score
        suspendedAt:                0,      // Unix timestamp (s) when attempt was suspended; 0 = not suspended
        selfPaused:                 false,  // true when the current suspension is a voluntary self-pause (resumable without a proctor)
        confirmedSuspension:        false,  // true when the proctor has clicked "Confirm Suspension" — a final decision, not pending review
        examStarted:                false,  // true once Q1 has actually rendered (fullscreen confirmed). Before this, missing fullscreen just blocks entry — it is not scored as a violation.
        reviewMode:                 false,  // true once the student has committed to a Continue & Review precheck (about to activate the review timer)
        reviewPrecheckDone:         false,  // true once the reopened-for-review "must redo precheck" screen has been shown this session
        reopenedForReview:          false,  // true for the rest of the session once a Continue & Review attempt actually starts — question HTML is cached client-side and answers are kept locally, saved to the server only on final submit (all questions were already answered once; nothing new to lose on a crash the way a first attempt would)
        localAnswers:               {},     // {slot: formData[]} — locally held edits for a reopened-for-review session, flushed to the server on submit
        questionCache:              {},     // {slot: html} — preloaded/cached question HTML for the whole exam, so navigation is instant
        qtypeCache:                 {},     // {slot: qtype} — parallel to questionCache; lets the client know the CURRENT slot's question type
        currentqtype:               '',     // qtype of the slot currently on screen — used to relax head-down/noise scoring while writing an Essay/Choice Essay answer
        unsavedAnswers:             {},     // {slot: formData[]} — answers that failed to save after retries; lgFlushUnsavedAnswers() keeps retrying these in the background
        lastWsFailureTime:          0,      // Date.now() ms of the most recent WS failure of any kind — used to tag proctoring events that land shortly after (see callWS() and logProctoringEvent())
        serverReachable:            true,   // updated by the get_messages poll — real reachability to OUR server, distinct from navigator.onLine (device-level, can be true while our server times out)
        serverUnreachableSince:     0,      // Date.now() ms when serverReachable last flipped to false; 0 = currently reachable
        // Check-in pop-up state.
        checkInViolationCount: 0,   // running count of scored face_missing/audio_clip_started violations
        checkInDisabled:       false, // true when student clicks "Don't Show Again" this session
        checkInOpen:           false, // true while check-in modal is visible (pauses timer + violations)
        autoSuspendFired:      false, // true after first auto-suspend fires; reset to false after reinstatement
    };

    // 360° environment recording captured during precheck (before the attempt
    // exists) — uploaded once launchAssessment() obtains a real attemptid.
    var pending360Video = null;

    // True when the precheck's face-detection step fell all the way through to a
    // student self-tick with no automatic OR proctor verification (wiz_face()'s
    // showFallback() + AUTO-mode checkbox) — applied once launchAssessment() obtains a
    // real attemptid, same deferred pattern as pending360Video above.
    var pendingIdentityUnverified = false;

    // Typing Pattern Baseline (precheck's keystroke step) — average ms between
    // keystrokes while typing the fixed sentence, computed once and kept for the whole
    // page session (no need to fetch it back from the server: precheck and the exam run
    // in the same page load). Persisted server-side too (mod_learnguard_save_typing_baseline,
    // flushed once a real attemptid exists) purely for audit/report visibility — the live
    // comparison below reads this in-memory value directly.
    var typingBaselineAvg = 0;
    var lgTypingLiveBuffer = [];
    var lgTypingLastKeyAt = 0;
    var lgTypingLastFlagAt = 0;

    function el(id) { return document.getElementById(id); }

    // Adds up to 50% random jitter on top of a base delay. Every retry backoff in this
    // file previously used a fixed formula (e.g. 1500ms * attempt number) with NO
    // randomization — harmless for one student, but when a whole cohort starts (or
    // stumbles into a shared slow moment) at nearly the same time, every client's
    // retries land at the exact same relative offset too, creating a synchronised
    // "thundering herd" that re-hits the server in a wave rather than smoothing load
    // out — the opposite of what a retry is supposed to achieve at scale. Applied to
    // every setTimeout-based retry/stagger in this file.
    function lgJitter(baseMs) {
        return baseMs + Math.random() * baseMs * 0.5;
    }

    // True while the question currently on screen is a free-typing question type
    // (Essay or Choice Essay) — used to relax head-down/keyboard-noise scoring, both of
    // which are triggered by completely normal behavior while composing a long written
    // answer but would be genuinely suspicious on any other question type.
    function lgIsEssayQtype(qtype) {
        return qtype === 'essay' || qtype === 'choiceessay';
    }

    // Current time in seconds, corrected for any drift between the client
    // clock and the server clock (so a wrong client clock can't be used to
    // dodge a scheduled exam window).
    function nowTs() {
        return Math.floor(Date.now() / 1000) + state.serverOffset;
    }

    // Default timeout for every WS call. Without this, a stalled request (server under
    // heavy concurrent load, a dropped connection that never errors) leaves the caller's
    // promise pending forever — "Loading question…" spinners and disabled Next/Save
    // buttons that never recover, since both only re-enable inside the response handler.
    // Forcing a rejection after this many ms lets the existing .catch() handlers run,
    // release the UI, and show the student something actionable instead of a dead screen.
    var LG_WS_TIMEOUT_MS = 25000;

    // state.lastWsFailureTime, updated on every callWS() rejection below, is a single,
    // general "server distress" signal used to tag proctoring events (tab_switch/
    // fullscreen_exit especially) that land shortly after a real failure, so a proctor
    // reviewing the risk score later can see it may have been a recovery attempt rather
    // than a genuine violation.
    function callWS(method, args, timeoutMs) {
        return new Promise(function(resolve, reject) {
            var settled = false;
            var timer = setTimeout(function() {
                if (settled) { return; }
                settled = true;
                state.lastWsFailureTime = Date.now();
                reject({message: 'Request timed out. Please check your connection and try again.'});
            }, timeoutMs || LG_WS_TIMEOUT_MS);
            Ajax.call([{methodname: method, args: args,
                done: function(data) {
                    if (settled) { return; }
                    settled = true;
                    clearTimeout(timer);
                    resolve(data);
                },
                fail: function(err) {
                    if (settled) { return; }
                    settled = true;
                    clearTimeout(timer);
                    state.lastWsFailureTime = Date.now();
                    reject(err);
                }}]);
        });
    }

    /* =======================================================================
     * FULLSCREEN
     * ====================================================================== */
    var fsBlocker = null;

    function fsIsActive() {
        return !!(document.fullscreenElement ||
                  document.webkitFullscreenElement ||
                  document.mozFullScreenElement);
    }

    // Guard prevents a second requestFullscreen() while one is already in flight.
    var fsRequestPending = false;
    function enterFS() {
        if (fsRequestPending) { return; }
        var docEl = document.documentElement;
        var fn = docEl.requestFullscreen || docEl.webkitRequestFullscreen || docEl.mozRequestFullScreen;
        // No Fullscreen API support at all — nothing to request, so the student must be
        // shown the blocker rather than silently proceeding without fullscreen.
        if (!fn) { showFsBlocker(); return; }
        fsRequestPending = true;
        try {
            var p = fn.call(docEl);
            if (p && typeof p.then === 'function') {
                p.then(function() {
                    fsRequestPending = false;
                    // Defensive: resolved without rejecting, but somehow still not
                    // active (seen on some embedded/kiosk browser contexts).
                    if (!fsIsActive()) { showFsBlocker(); }
                }).catch(function() {
                    fsRequestPending = false;
                    showFsBlocker();
                });
            } else {
                // Some browsers return undefined instead of a Promise — there is no
                // resolve/reject to hook, so verify the actual result shortly after
                // instead of assuming success.
                fsRequestPending = false;
                setTimeout(function() { if (!fsIsActive()) { showFsBlocker(); } }, 300);
            }
        } catch (err) {
            // This call can throw SYNCHRONOUSLY — e.g. requestFullscreen() invoked
            // without a direct user gesture, which is exactly what happens here since
            // enterFS() runs automatically several async steps after the button click
            // that started the precheck wizard, not from the click itself. Previously
            // this branch only cleared the pending flag and did nothing else — the
            // student was left with no fullscreen AND no blocker, free to answer the
            // whole exam in a normal windowed browser.
            fsRequestPending = false;
            showFsBlocker();
        }
    }

    // Direct requestFullscreen call used by the blocker — no guards, no pending flag,
    // so an explicit user click always reaches the browser API immediately.
    //
    // Previously this call's failure (rejected promise OR a synchronous throw) was
    // silently swallowed with zero feedback — the blocker just sat there completely
    // unchanged, with no way for the student to tell whether their click even
    // registered. That is indistinguishable from "the button doesn't work" and matches
    // a real live report (a student's own browser screenshot showing normal window
    // chrome still visible behind "Fullscreen Required" with no error, no indication
    // anything had been tried). Every failure path now shows a visible, specific
    // message via lgFsBlockerError() instead of doing nothing.
    function fsEnterDirect() {
        var docEl = document.documentElement;
        var fn = docEl.requestFullscreen || docEl.webkitRequestFullscreen || docEl.mozRequestFullScreen;
        if (!fn) {
            lgFsBlockerError('Your browser does not support fullscreen mode. Please contact your proctor.');
            return;
        }
        try {
            var p = fn.call(docEl);
            if (p && typeof p.then === 'function') {
                p.then(function() {
                    // The fullscreenchange listener in initFullscreen() normally hides the
                    // blocker already — this is just a defensive fallback for contexts
                    // where that event doesn't fire even though the promise resolved.
                    if (fsIsActive()) { hideFsBlocker(); }
                }).catch(function() {
                    lgFsBlockerError('Your browser blocked fullscreen. Click the button again, or check your browser’s site permissions for this page.');
                });
            } else {
                // Some browsers return undefined instead of a Promise — verify the
                // actual result shortly after instead of silently assuming success.
                setTimeout(function() {
                    if (fsIsActive()) { hideFsBlocker(); }
                    else { lgFsBlockerError('Fullscreen did not activate. Click the button again, or check your browser’s site permissions for this page.'); }
                }, 300);
            }
        } catch (err) {
            lgFsBlockerError('Your browser blocked fullscreen. Click the button again, or check your browser’s site permissions for this page.');
        }
    }

    // Shows a visible, specific reason directly on the fullscreen blocker when a
    // re-entry attempt fails, instead of leaving the student staring at an unchanged
    // screen with no idea whether their click did anything.
    function lgFsBlockerError(msg) {
        if (!fsBlocker) { return; }
        var errEl = document.getElementById('lg-fs-error');
        if (!errEl) {
            errEl = document.createElement('p');
            errEl.id = 'lg-fs-error';
            errEl.style.cssText = 'margin-top:14px;font-size:13px;color:#ff8080;max-width:400px;';
            fsBlocker.appendChild(errEl);
        }
        errEl.textContent = '⚠️ ' + msg;
    }

    function showFsBlocker() {
        if (!cfg.enablefullscreen || state.finished || !state.proctoringActive) { return; }
        state.fsBlocked = true; // pause risk accumulation while waiting for FS (timer keeps running)
        if (fsBlocker) { fsBlocker.style.display = 'flex'; return; }

        fsBlocker = document.createElement('div');
        fsBlocker.id = 'lg-fs-blocker';
        // cursor:pointer on the whole overlay — clicking anywhere re-enters fullscreen.
        // z-index is deliberately higher than every other overlay in this file (check-in
        // modal, halfway-warning modal, etc.) — those are now guarded not to open while
        // fsBlocked, but this is a second line of defense: the path back into fullscreen
        // must never end up hidden behind another overlay.
        fsBlocker.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.95);' +
            'display:flex;flex-direction:column;align-items:center;justify-content:center;' +
            'z-index:2147483000;color:#fff;font-family:system-ui,sans-serif;text-align:center;' +
            'padding:40px;cursor:pointer;';

        fsBlocker.innerHTML =
            '<div style="font-size:64px;margin-bottom:20px;">🛡️</div>' +
            '<h2 style="margin-bottom:12px;color:#4ade80;">Fullscreen Required</h2>' +
            '<p style="color:#ccc;margin-bottom:24px;max-width:400px;">This proctored assessment ' +
            'must be taken in fullscreen mode.</p>' +
            '<button id="lg-fs-return-btn" style="background:#0f6cbf;color:#fff;border:none;' +
            'border-radius:6px;padding:14px 32px;font-size:18px;cursor:pointer;' +
            'animation:lgFsPulse 1.5s ease-in-out infinite;">↗ Return to Fullscreen</button>' +
            '<p style="margin-top:16px;font-size:13px;color:#9ca3af;">Or click anywhere on this screen</p>' +
            '<style>@keyframes lgFsPulse{0%,100%{box-shadow:0 0 0 0 rgba(15,108,191,0.7)}' +
            '50%{box-shadow:0 0 0 12px rgba(15,108,191,0)}}</style>';

        document.body.appendChild(fsBlocker);

        // Clicking anywhere on the overlay (including the button) re-enters fullscreen.
        fsBlocker.addEventListener('click', function() {
            fsEnterDirect();
        });
    }

    function hideFsBlocker() {
        if (fsBlocker) { fsBlocker.style.display = 'none'; }
        state.fsBlocked = false; // resume timer + risk events
    }

    function initFullscreen() {
        if (!cfg.enablefullscreen) { return; }
        enterFS();
        document.addEventListener('fullscreenchange', function() {
            if (!fsIsActive() && !state.finished && state.proctoringActive) {
                // Only score this once the student has actually started the exam
                // (state.examStarted, set once Q1 renders after fullscreen is first
                // confirmed). Before that, missing/lost fullscreen is just the entry
                // gate blocking them — not something they should be scored for.
                // Log the violation BEFORE showFsBlocker sets state.fsBlocked=true,
                // otherwise logProctoringEvent's fsBlocked guard would swallow the event.
                if (state.examStarted) {
                    logProctoringEvent('fullscreen_exit', {}, 'medium',
                        (cfg.scoreconfig && cfg.scoreconfig.fullscreenexit) || 10);
                }
                showFsBlocker();
            } else { hideFsBlocker(); }
        });
        document.addEventListener('webkitfullscreenchange', function() {
            if (!fsIsActive() && !state.finished && state.proctoringActive) { showFsBlocker(); }
            else { hideFsBlocker(); }
        });
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden && !fsIsActive() && !state.finished && state.proctoringActive) {
                showFsBlocker();
            }
        });
        window.addEventListener('blur', function() {
            setTimeout(function() {
                if (!fsIsActive() && !state.finished && state.proctoringActive) { showFsBlocker(); }
            }, 50);
        });
    }

    /* =======================================================================
     * TAB BLOCKING
     * ====================================================================== */
    var tabBlocker = null;

    function showTabBlocker() {
        if (!cfg.enabletabdetect || state.finished || !state.proctoringActive) { return; }
        if (tabBlocker) { tabBlocker.style.display = 'flex'; return; }

        tabBlocker = document.createElement('div');
        tabBlocker.id = 'lg-tab-blocker';
        tabBlocker.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.92);' +
            'display:flex;flex-direction:column;align-items:center;justify-content:center;' +
            'z-index:999998;color:#fff;font-family:system-ui,sans-serif;text-align:center;padding:40px;';

        tabBlocker.innerHTML =
            '<div style="font-size:64px;margin-bottom:20px;">🚫</div>' +
            '<h2 style="margin-bottom:12px;color:#ff6b6b;">Tab Switching Is Not Allowed</h2>' +
            '<p style="color:#ccc;margin-bottom:8px;max-width:440px;">You switched away from the assessment. ' +
            'This violation has been logged.</p>' +
            '<p style="color:#fd7e14;margin-bottom:24px;font-size:14px;">Repeated violations may result in ' +
            'automatic suspension of your attempt.</p>' +
            '<button id="lg-tab-return-btn" style="background:#0f6cbf;color:#fff;border:none;' +
            'border-radius:6px;padding:14px 32px;font-size:18px;cursor:pointer;">↩ Return to Assessment</button>';

        document.body.appendChild(tabBlocker);
        el('lg-tab-return-btn').addEventListener('click', function() {
            tabBlocker.style.display = 'none';
            if (cfg.enablefullscreen && !fsIsActive()) { enterFS(); }
        });
    }

    // Set by initKeyBlock() when PrintScreen is pressed so initTabDetection()
    // can suppress the OS-focus-steal blur that fires immediately after PrtScr.
    var lgLastPrintScreenAt = 0;

    function initTabDetection() {
        if (!cfg.enabletabdetect) { return; }
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                logProctoringEvent('tab_switch', {}, 'medium', (cfg.scoreconfig && cfg.scoreconfig.tabswitch) || 5);
            } else {
                showTabBlocker();
            }
        });
        window.addEventListener('blur', function() {
            if (!state.finished && state.proctoringActive) {
                // Suppress blur-based tab_switch if the user just pressed PrintScreen —
                // the OS briefly steals focus to capture the screen, which is NOT a real tab switch.
                if (Date.now() - lgLastPrintScreenAt < 600) { return; }
                logProctoringEvent('tab_switch', {trigger: 'blur'}, 'medium', (cfg.scoreconfig && cfg.scoreconfig.tabswitch) || 5);
            }
        });
        window.addEventListener('focus', function() {
            if (!state.finished && state.proctoringActive && !fsIsActive()) { showTabBlocker(); }
        });
    }

    /* =======================================================================
     * KEY BLOCKING
     * ====================================================================== */
    function initKeyBlock() {
        // This listener is registered once and enforces blocking whenever proctoring is active.
        // The five user-requested keys (Windows/Meta, Alt combos, Tab, PrintScreen, Esc) are
        // ALWAYS blocked — no toggle needed. Additional keys respect cfg.enablekeyblock.
        var criticalKeys         = ['Escape', 'Meta', 'OS'];
        var secondaryBlocked     = ['F1', 'F5', 'F11', 'F12'];
        var secondaryBlockedCtrl = ['a','c','v','x','u','s','p','j','i','k'];

        // Base "Blocked Key Pressed" score from admin settings — the milder key variants
        // below (Escape/Tab/Alt-release, previously hardcoded to 3/2/2 regardless of the
        // configured base) scale proportionally off it instead of using their own fixed
        // numbers, so they still move with the admin's setting rather than being frozen
        // at whatever ratio happened to make sense when the base default was 5.
        function lgKeyScore() { return (cfg.scoreconfig && cfg.scoreconfig.keyviolation) || 5; }

        // Some keyboards/OSes/VM or remote-desktop drivers report e.key generically
        // (usually "Meta"/"OS") for a physical key that should be one of the specific
        // keys this exam tracks (Esc, Tab, Ctrl, Alt, PrintScreen, F1-F12) — reported
        // live as every one of those showing up mislabeled as "Blocked key pressed:
        // meta" regardless of what was actually pressed. e.code reflects the PHYSICAL
        // key far more reliably than e.key in exactly this situation, so it's resolved
        // to one of these tracked names FIRST, before any of the key-specific branches
        // below ever run — e.key is only trusted when e.code doesn't recognise the key
        // as one of these.
        var CODE_KEY_MAP = {
            'Escape': 'Escape', 'Tab': 'Tab', 'PrintScreen': 'PrintScreen',
            'ControlLeft': 'Control', 'ControlRight': 'Control',
            'AltLeft': 'Alt', 'AltRight': 'Alt',
            'F1': 'F1', 'F2': 'F2', 'F3': 'F3', 'F4': 'F4', 'F5': 'F5', 'F6': 'F6',
            'F7': 'F7', 'F8': 'F8', 'F9': 'F9', 'F10': 'F10', 'F11': 'F11', 'F12': 'F12',
        };

        function blockKey(e) {
            if (!state.proctoringActive) { return; }
            var k = CODE_KEY_MAP[e.code] || e.key;

            // 1. Windows key (Meta / OS) — only reached when e.code didn't resolve to one
            // of the tracked keys above, i.e. a genuine Windows/Cmd key press.
            if (k === 'Meta' || k === 'OS') {
                e.preventDefault(); e.stopPropagation();
                showToast('🔒 Windows key blocked during exam.', 'warning', 2000);
                logProctoringEvent('key_violation', {key: 'meta'}, 'medium', lgKeyScore());
                return;
            }
            // 2. Escape
            if (k === 'Escape') {
                e.preventDefault(); e.stopPropagation();
                logProctoringEvent('key_violation', {key: 'escape'}, 'low', Math.round(lgKeyScore() * 0.6));
                showToast('🔒 Escape key is not permitted during this exam.', 'warning', 2000);
                return;
            }
            // 3. Tab (all forms — alone or with Alt/Shift)
            if (k === 'Tab') {
                e.preventDefault(); e.stopPropagation();
                logProctoringEvent('key_violation', {key: 'tab'}, 'low', Math.round(lgKeyScore() * 0.4));
                return;
            }
            // 4. Ctrl — pressed alone (a combo like Ctrl+C is handled by the secondary
            // block below, which already labels it correctly as "ctrl+c" etc.).
            if (k === 'Control') {
                e.preventDefault(); e.stopPropagation();
                showToast('🔒 Key blocked during exam.', 'warning', 2000);
                logProctoringEvent('key_violation', {key: 'ctrl'}, 'medium', lgKeyScore());
                return;
            }
            // 5. Alt — solo press, or a combo (Alt+Tab, Alt+F4, Alt+Esc switch windows/
            // close browser). Exception: Alt+C (calculator) and Alt+N (notepad) are
            // allowed exam shortcuts. Solo Alt (k==='Alt') is labelled just "alt", not
            // "alt+Alt" — e.altKey is already true on the very keydown that sets it.
            if (k === 'Alt' || e.altKey) {
                if (k !== 'Alt' && (k.toLowerCase() === 'c' || k.toLowerCase() === 'n' ||
                        k === '=' || k === '+' || k === '-')) { return; }
                e.preventDefault(); e.stopPropagation();
                logProctoringEvent('key_violation', {key: (k === 'Alt') ? 'alt' : ('alt+' + k)}, 'medium', lgKeyScore());
                return;
            }
            // 6. PrintScreen — record timestamp so blur-based tab_switch is suppressed.
            if (k === 'PrintScreen' || k === 'PrtScr' || k === 'Snapshot') {
                e.preventDefault(); e.stopPropagation();
                lgLastPrintScreenAt = Date.now();
                logProctoringEvent('printscreen', {key: k}, 'medium', (cfg.scoreconfig && cfg.scoreconfig.printscreen) || 10);
                showToast('⚠️ Screenshot attempt blocked and logged.', 'warning', 3000);
                return;
            }

            // Secondary blocking — only when enablekeyblock is configured ON. Deliberately
            // unscored (0) — these are logged for visibility, not tied to the "Blocked Key
            // Pressed" setting.
            if (!cfg.enablekeyblock) { return; }
            if (secondaryBlocked.indexOf(k) !== -1) {
                e.preventDefault(); e.stopPropagation();
                logProctoringEvent('key_violation', {key: k}, 'low', 0); return;
            }
            if ((e.ctrlKey || e.metaKey) && secondaryBlockedCtrl.indexOf(k.toLowerCase()) !== -1) {
                e.preventDefault(); e.stopPropagation();
                logProctoringEvent('key_violation', {key: 'ctrl+' + k}, 'low', 0);
            }
        }

        document.addEventListener('keydown', blockKey, true);
        document.addEventListener('keyup', function(e) {
            // Prevent Alt key from activating browser menus on keyup too.
            if (state.proctoringActive && e.key === 'Alt') {
                e.preventDefault();
                logProctoringEvent('key_violation', {key: 'alt'}, 'low', Math.round(lgKeyScore() * 0.4));
            }
        }, true);
        document.addEventListener('contextmenu', function(e) {
            if (state.proctoringActive) { e.preventDefault(); }
        });
    }

    /* =======================================================================
     * CLIPBOARD
     * ====================================================================== */
    function initClipboard() {
        if (!cfg.enableclipboard) { return; }
        ['copy', 'paste', 'cut'].forEach(function(evt) {
            document.addEventListener(evt, function() {
                if (state.proctoringActive) { logProctoringEvent('clipboard_' + evt, {}, 'low', (cfg.scoreconfig && cfg.scoreconfig.copypaste) || 5); }
            });
        });
    }

    /* =======================================================================
     * TYPING PATTERN BASELINE — live comparison against precheck's recorded baseline
     * ====================================================================== */
    // Compares the rhythm of live typing (while composing an Essay/Choice Essay answer)
    // against the fixed-sentence baseline recorded during precheck's keystroke step —
    // a large, sustained deviation is flagged as a possible impersonation signal (a
    // different person now typing than the one who did precheck), not proof on its own.
    // Only ever runs on a real contenteditable answer box, and only when a baseline was
    // actually recorded (typingBaselineAvg > 0 — a student who skipped/failed that
    // precheck step, or an activity where precheck itself is disabled, has nothing to
    // compare against and this stays a no-op).
    function initTypingPatternMonitor() {
        if (!typingBaselineAvg) { return; }
        var MIN_SAMPLES  = 12;   // enough keystrokes for a stable rolling average
        var DEVIATION_PCT = 0.65; // 65% faster/slower than the recorded baseline
        var FLAG_COOLDOWN_MS = 3 * 60 * 1000; // don't re-flag more than once per 3 minutes

        document.addEventListener('keydown', function(e) {
            if (!state.proctoringActive) { return; }
            var target = document.activeElement;
            if (!target || !target.isContentEditable) { lgTypingLastKeyAt = 0; return; }
            // Ignore modifier/navigation keys — they don't reflect typing rhythm and would
            // skew the average toward whatever shortcut habits a student happens to use.
            if (e.ctrlKey || e.metaKey || e.altKey || e.key.length > 1) { return; }

            var now = Date.now();
            if (lgTypingLastKeyAt > 0) {
                var interval = now - lgTypingLastKeyAt;
                // A gap this long means the student paused (thinking, looked away) rather
                // than a continuous typing rhythm — excluded so one long pause doesn't
                // drag the whole rolling average toward a false mismatch.
                if (interval < 5000) {
                    lgTypingLiveBuffer.push(interval);
                    if (lgTypingLiveBuffer.length > 30) { lgTypingLiveBuffer.shift(); }
                }
            }
            lgTypingLastKeyAt = now;

            if (lgTypingLiveBuffer.length < MIN_SAMPLES) { return; }
            if (now - lgTypingLastFlagAt < FLAG_COOLDOWN_MS) { return; }

            var liveAvg = lgTypingLiveBuffer.reduce(function(a, b) { return a + b; }, 0) / lgTypingLiveBuffer.length;
            var deviation = Math.abs(liveAvg - typingBaselineAvg) / typingBaselineAvg;
            if (deviation >= DEVIATION_PCT) {
                lgTypingLastFlagAt = now;
                logProctoringEvent('typing_pattern_mismatch', {
                    baseline_avg_ms: typingBaselineAvg,
                    live_avg_ms: Math.round(liveAvg),
                    deviation_pct: Math.round(deviation * 100),
                }, 'medium', (cfg.scoreconfig && cfg.scoreconfig.typingmismatch) || 8);
                // Reset the window so the same sustained rhythm doesn't re-trigger every
                // single keystroke once past the cooldown — a fresh comparison starts here.
                lgTypingLiveBuffer = [];
            }
        }, true);
    }

    /* =======================================================================
     * MULTIPLE MONITOR DETECTION
     * ====================================================================== */
    function initMultiMonitorDetection() {
        function checkMultiMonitor() {
            var extended = false;
            // Modern API
            if ('screen' in window && 'isExtended' in window.screen) {
                extended = window.screen.isExtended;
            }
            // Heuristic: screen width much larger than window width suggests dual monitor
            if (!extended && window.screen.width > 2560) {
                extended = true;
            }
            if (extended && !state.multiMonitorLogged) {
                state.multiMonitorLogged = true;
                logProctoringEvent('multi_monitor', {screens: window.screen.width}, 'medium',
                    (cfg.scoreconfig && cfg.scoreconfig.multimonitor) || 15);
                showToast('⚠️ Multiple monitors detected — this has been logged.', 'warning');
            }
        }
        checkMultiMonitor();
        // Re-check periodically (user might connect a monitor after start).
        setInterval(checkMultiMonitor, 30000);
    }

    /* =======================================================================
     * INTERNET CONNECTION STRENGTH INDICATOR
     * ====================================================================== */
    // Standalone (not nested in initConnectionMonitor()) so startPolling()'s
    // get_messages result — the actual server-reachability signal — can refresh this
    // badge too, not just the browser's own online/offline + connection-type events.
    var lgConnWasOffline = false;
    function lgUpdateConnectionBadge() {
        var badge = el('lg-connection');
        if (!badge) { return; }

        if (!navigator.onLine) {
            badge.innerHTML = '❌ <span style="font-size:11px;">Offline</span>';
            badge.style.color = '#ff6b6b';
            if (!lgConnWasOffline) {
                lgConnWasOffline = true;
                showToast('⚠️ Internet connection lost! Your progress is auto-saved.', 'danger', 6000);
                logProctoringEvent('connection_lost', {}, 'medium', 0);
            }
            return;
        }
        lgConnWasOffline = false;

        // NOTE: server-reachability (state.serverReachable, tracked by startPolling()'s
        // get_messages result) is deliberately NOT surfaced here. Per explicit
        // correction: that diagnosis is backend/proctor-facing only (see the
        // connection_server_unreachable/_reachable events logged in startPolling()
        // below, and the possible_connection_issue tag on nearby violations) — the
        // student only ever sees their own device's generic network status, exactly
        // like before any of this was added.
        var conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
        var type = conn ? (conn.effectiveType || conn.type || 'online') : 'online';
        var isWeak = (type === 'slow-2g' || type === '2g');
        var icon   = isWeak ? '⚠️' : '📶';
        var label  = type === 'online' ? 'Online' : type.toUpperCase();
        badge.innerHTML = icon + ' <span style="font-size:11px;">' + label + '</span>';
        badge.style.color = isWeak ? '#ffd93d' : 'rgba(255,255,255,0.9)';
        if (isWeak) {
            showToast('⚠️ Weak connection detected (' + type + '). Your answers are still being saved.', 'warning', 5000);
        }
    }

    function initConnectionMonitor() {
        if (!el('lg-connection')) { return; }

        lgUpdateConnectionBadge();
        window.addEventListener('online',  lgUpdateConnectionBadge);
        window.addEventListener('offline', lgUpdateConnectionBadge);
        if (navigator.connection) {
            navigator.connection.addEventListener('change', lgUpdateConnectionBadge);
        }
    }

    /* =======================================================================
     * OFFLINE / RECONNECTION RECOVERY
     * ====================================================================== */
    var offlineBlocker = null;
    function initReconnectionHandler() {
        window.addEventListener('offline', function() {
            if (state.finished || !state.proctoringActive) { return; }
            if (offlineBlocker) { return; }
            offlineBlocker = document.createElement('div');
            offlineBlocker.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.88);' +
                'display:flex;flex-direction:column;align-items:center;justify-content:center;' +
                'z-index:999996;color:#fff;font-family:system-ui,sans-serif;text-align:center;padding:40px;';
            offlineBlocker.innerHTML =
                '<div style="font-size:64px;margin-bottom:20px;">📡</div>' +
                '<h2 style="margin-bottom:10px;color:#fd7e14;">Connection Lost</h2>' +
                '<p style="color:#ccc;margin-bottom:8px;max-width:440px;">Your internet connection has been interrupted. ' +
                'Please restore your connection to continue the assessment.</p>' +
                '<p style="color:#6c757d;font-size:13px;">Your answers are automatically saved.</p>' +
                '<div id="lg-offline-spinner" style="margin-top:20px;width:40px;height:40px;' +
                'border:4px solid #555;border-top-color:#fd7e14;border-radius:50%;' +
                'animation:lg-spin 0.8s linear infinite;"></div>';
            document.body.appendChild(offlineBlocker);
        });

        window.addEventListener('online', function() {
            if (offlineBlocker) {
                document.body.removeChild(offlineBlocker);
                offlineBlocker = null;
                showToast('✅ Connection restored.', 'success', 3000);
                // Re-enter fullscreen if needed.
                if (cfg.enablefullscreen && !fsIsActive()) { enterFS(); }
            }
        });
    }

    /* =======================================================================
     * DICTIONARY / SPELLCHECK DISABLE during assessment
     * ====================================================================== */
    function initSpellcheckDisable() {
        // Disable browser spellcheck and autocorrect on all form inputs.
        function applyToElement(el) {
            el.setAttribute('spellcheck', 'false');
            el.setAttribute('autocorrect', 'off');
            el.setAttribute('autocomplete', 'off');
            el.setAttribute('autocapitalize', 'off');
        }
        // Apply to existing inputs.
        document.querySelectorAll('input[type=text], textarea').forEach(applyToElement);
        // Watch for new inputs added by question rendering.
        var obs = new MutationObserver(function(muts) {
            muts.forEach(function(m) {
                m.addedNodes.forEach(function(node) {
                    if (node.querySelectorAll) {
                        node.querySelectorAll('input[type=text], textarea').forEach(applyToElement);
                    }
                });
            });
        });
        obs.observe(document.body, {childList: true, subtree: true});
    }

    /* =======================================================================
     * CALCULATOR WIDGET — floating, draggable, keyboard-aware
     * ====================================================================== */
    var calcVisible = false;
    function toggleCalculator() {
        // Remove-on-close / recreate-on-open — bulletproof toggle with no display-state ambiguity.
        var c = document.getElementById('lg-calc');
        if (c) { c.parentNode.removeChild(c); calcVisible = false; return; }

        calcVisible = true;
        var calc = document.createElement('div');
        calc.id = 'lg-calc';
        calc.style.cssText = 'position:fixed;right:220px;bottom:80px;z-index:99990;' +
            'background:#fff;border:1px solid #dee2e6;border-radius:12px;' +
            'box-shadow:0 8px 32px rgba(0,0,0,0.25);width:220px;font-family:system-ui,sans-serif;' +
            'user-select:none;';
        calc.innerHTML =
            '<div id="lg-calc-handle" style="background:#0f6cbf;color:#fff;padding:8px 12px;' +
            'border-radius:12px 12px 0 0;display:flex;justify-content:space-between;cursor:grab;">' +
            '<span style="font-weight:700;font-size:13px;">🔢 Calculator</span>' +
            '<button onclick="window.lgToggleCalc()" ' +
            'style="background:none;border:none;color:#fff;cursor:pointer;font-size:18px;line-height:1;padding:0;">×</button></div>' +
            '<div id="lg-calc-display" style="padding:10px 12px;font-size:22px;font-weight:700;' +
            'text-align:right;background:#f8f9fa;border-bottom:1px solid #dee2e6;' +
            'min-height:44px;word-break:break-all;">0</div>' +
            '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:2px;padding:4px;">' + calcButtons() + '</div>';
        document.body.appendChild(calc);
        makeDraggable(document.getElementById('lg-calc-handle'), calc);
        initCalcLogic();
    }

    function calcButtons() {
        var rows = [
            ['C', '±', '%', '÷'],
            ['7', '8', '9', '×'],
            ['4', '5', '6', '−'],
            ['1', '2', '3', '+'],
            ['0', '.', '⌫', '='],
        ];
        var html = '';
        rows.forEach(function(row) {
            row.forEach(function(btn) {
                var isOp  = ['÷','×','−','+','='].indexOf(btn) >= 0;
                var isCls = ['C','⌫'].indexOf(btn) >= 0;
                html += '<button onclick="window.lgCalcBtn(\'' + btn + '\')" ' +
                    'style="padding:12px 4px;font-size:16px;font-weight:700;border:1px solid #dee2e6;' +
                    'border-radius:4px;cursor:pointer;' +
                    'background:' + (btn === '=' ? '#0f6cbf' : isOp ? '#6c757d' : isCls ? '#fd7e14' : '#fff') + ';' +
                    'color:' + (isOp || isCls || btn === '=' ? '#fff' : '#212529') + ';">' +
                    btn + '</button>';
            });
        });
        return html;
    }

    function initCalcLogic() {
        var display = document.getElementById('lg-calc-display');
        var expr = '0'; var lastOp = false;
        window.lgCalcBtn = function(btn) {
            if (btn === 'C')  { expr = '0'; lastOp = false; }
            else if (btn === '⌫') { expr = expr.length > 1 ? expr.slice(0,-1) : '0'; }
            else if (btn === '=') {
                try {
                    var e = expr.replace(/÷/g,'/').replace(/×/g,'*').replace(/−/g,'-');
                    expr = String(eval(e));
                    lastOp = true;
                } catch(er) { expr = 'Error'; }
            }
            else if (btn === '±') { expr = expr.startsWith('-') ? expr.slice(1) : '-' + expr; }
            else if (btn === '%') { try { expr = String(eval(expr) / 100); } catch(er){} }
            else {
                if (lastOp) { expr = ''; lastOp = false; }
                if (expr === '0' && ['0','1','2','3','4','5','6','7','8','9'].indexOf(btn) >= 0) { expr = btn; }
                else { expr += btn; }
            }
            if (display) { display.textContent = expr; }
        };
    }

    /* =======================================================================
     * SCRATCH PAD — floating notepad, auto-saved to sessionStorage
     * ====================================================================== */
    var padVisible = false;
    function toggleScratchPad() {
        // Remove-on-close / recreate-on-open — preserves notes via sessionStorage.
        var p = document.getElementById('lg-scratchpad');
        if (p) { p.parentNode.removeChild(p); padVisible = false; return; }

        padVisible = true;
        var pad = document.createElement('div');
        pad.id  = 'lg-scratchpad';
        pad.style.cssText = 'position:fixed;left:20px;bottom:80px;z-index:99990;' +
            'background:#fff;border:1px solid #dee2e6;border-radius:12px;' +
            'box-shadow:0 8px 32px rgba(0,0,0,0.2);width:280px;font-family:system-ui,sans-serif;';
        pad.innerHTML =
            '<div id="lg-pad-handle" style="background:#fd7e14;color:#fff;padding:8px 12px;' +
            'border-radius:12px 12px 0 0;display:flex;justify-content:space-between;align-items:center;cursor:grab;">' +
            '<span style="font-weight:700;font-size:13px;">📝 Scratch Pad</span>' +
            '<button onclick="window.lgTogglePad()" ' +
            'style="background:none;border:none;color:#fff;cursor:pointer;font-size:18px;line-height:1;padding:0;">×</button></div>' +
            '<textarea id="lg-pad-txt" style="width:100%;height:200px;border:none;padding:12px;' +
            'font-size:14px;line-height:1.5;resize:none;border-radius:0 0 12px 12px;' +
            'font-family:system-ui,sans-serif;box-sizing:border-box;" placeholder="Write notes here…" ' +
            'spellcheck="false"></textarea>';
        document.body.appendChild(pad);
        // Restore saved notes
        var txt = document.getElementById('lg-pad-txt');
        txt.value = sessionStorage.getItem('lg_scratchpad') || '';
        txt.addEventListener('input', function() {
            sessionStorage.setItem('lg_scratchpad', this.value);
        });
        makeDraggable(document.getElementById('lg-pad-handle'), pad);
    }

    /* =======================================================================
     * TEXT ZOOM — increase/decrease question font size
     * ====================================================================== */
    var textZoomLevel = 100;
    function changeTextZoom(delta) {
        textZoomLevel = Math.max(80, Math.min(160, textZoomLevel + delta));
        // Apply to lg-question-scroll ONLY — lg-question-container is nested INSIDE it,
        // so a percentage font-size already cascades down via normal CSS inheritance to
        // every rendered question element. Setting the same percentage on BOTH (as this
        // used to do) compounds them multiplicatively — at max zoom (160%) that rendered
        // at 160% x 160% = 256% of normal size, blowing questions/options far outside the
        // visible area faster than the scroll container could keep up, effectively
        // hiding the rest of the question text and options from the student.
        var node = el('lg-question-scroll');
        if (node) { node.style.fontSize = textZoomLevel + '%'; }
        var badge = el('lg-zoom-badge');
        if (badge) { badge.textContent = textZoomLevel + '%'; }
        showToast((delta > 0 ? '🔍 Text enlarged' : '🔍 Text reduced') + ' — ' + textZoomLevel + '%', 'info', 1500);
    }

    /* =======================================================================
     * KEYBOARD NAVIGATION during assessment
     * Arrow Left/Right = navigate questions
     * Alt+C = calculator, Alt+N = notepad
     * ====================================================================== */
    // Hide ALL external LMS chat widgets during exam — nuclear approach.
    function disableExternalChatWidgets() {
        if (document.getElementById('lg-hide-external-chat')) { return; }

        // 1. Nuclear CSS — forces z-index below everything; also targets :fullscreen context.
        var lgChatSels =
            '#tawkchat-container,#tawkchat-status-text,#tawkchat-minified-box,' +
            '#tawk-bubble,[id^="tawk_"],' +
            'iframe[title*="chat" i],iframe[title*="tawk" i],' +
            'iframe[src*="tawk.to" i],iframe[src*="embed.tawk" i],' +
            'iframe[src*="intercom" i],iframe[src*="crisp.chat" i],' +
            'iframe[src*="drift" i],iframe[src*="tidio" i],' +
            'iframe[src*="freshdesk" i],iframe[src*="zendesk" i],' +
            '.tawk-icon-container,.tawk-min-container,.tawk-max-container,' +
            '.intercom-lightweight-app,.intercom-launcher,' +
            '.crisp-client,#drift-frame-controller,#drift-frame-chat,' +
            '#hubspot-messages-iframe-container,' +
            '#launcher,#webWidget,.tidio-chat,' +
            '[id*="tawk"],[class*="tawk"],' +
            '[id*="livechat"],[class*="livechat"],' +
            '[id*="chat-widget"],[class*="chat-widget"],[id*="chat_widget"],' +
            '[id*="freshdesk"],[class*="freshdesk"],' +
            '[id*="zendesk"],[class*="zendesk"]';
        var lgHideProps =
            '{display:none!important;visibility:hidden!important;' +
            'pointer-events:none!important;z-index:-99999!important;' +
            'width:0!important;height:0!important;max-width:0!important;' +
            'max-height:0!important;opacity:0!important;overflow:hidden!important;}';
        var lgFsSels = lgChatSels.split(',').map(function(r) { return ':fullscreen ' + r.trim(); }).join(',') + ',' +
            lgChatSels.split(',').map(function(r) { return ':-webkit-full-screen ' + r.trim(); }).join(',') + ',' +
            lgChatSels.split(',').map(function(r) { return ':-moz-full-screen ' + r.trim(); }).join(',');
        var s = document.createElement('style');
        s.id  = 'lg-hide-external-chat';
        s.textContent = lgChatSels + lgHideProps + lgFsSels + lgHideProps;
        document.head.appendChild(s);

        // 2. Override chat provider APIs BEFORE they boot.
        window.Tawk_API = window.Tawk_API || {};
        window.Tawk_API.onLoad  = function() { try { window.Tawk_API.hideWidget(); } catch(e) {} };
        window.Tawk_API.onBeforeLoad = function() { try { window.Tawk_API.hideWidget(); } catch(e) {} };
        try { if (window.Tawk_API.hideWidget)  { window.Tawk_API.hideWidget(); }  } catch(e) {}
        try { if (window.Intercom)             { window.Intercom('hide'); }        } catch(e) {}
        try { if (window.$crisp)               { window.$crisp.push(['do','chat:hide']); } } catch(e) {}
        try { if (window.drift && window.drift.hide) { window.drift.hide(); }      } catch(e) {}

        // 3. Interval brute-force — re-hides any element that tries to show itself.
        var chatSel = '#tawkchat-container,#tawkchat-minified-box,#tawk-bubble,' +
            '[id*="tawk"],[class*="tawk"],iframe[src*="tawk"],' +
            '[id*="chat-widget"],[class*="chat-widget"],' +
            '.intercom-launcher,.crisp-client,.tidio-chat';
        function nukeChatEls() {
            document.querySelectorAll(chatSel).forEach(function(node) {
                node.style.setProperty('display',    'none',   'important');
                node.style.setProperty('visibility', 'hidden', 'important');
                node.style.setProperty('z-index',    '-99999', 'important');
            });
            try { if (window.Tawk_API && window.Tawk_API.hideWidget) { window.Tawk_API.hideWidget(); } } catch(e) {}
        }
        nukeChatEls();
        setInterval(nukeChatEls, 800);

        // 4. MutationObserver — removes newly injected chat nodes from DOM entirely.
        var chatPat = /tawk|livechat|chat.widget|intercom|crisp|drift|tidio|freshdesk|zendesk/i;
        var obs = new MutationObserver(function(mutations) {
            mutations.forEach(function(m) {
                m.addedNodes.forEach(function(node) {
                    if (node.nodeType !== 1) { return; }
                    var id  = node.id    || '';
                    var cls = typeof node.className === 'string' ? node.className : '';
                    var src = node.src   || (node.getAttribute ? node.getAttribute('src') || '' : '');
                    if (chatPat.test(id) || chatPat.test(cls) || chatPat.test(src)) {
                        node.style.setProperty('display',    'none',   'important');
                        node.style.setProperty('visibility', 'hidden', 'important');
                        node.style.setProperty('z-index',    '-99999', 'important');
                        setTimeout(function() {
                            try { if (node.parentNode) { node.parentNode.removeChild(node); } } catch(e) {}
                        }, 50);
                    }
                });
            });
        });
        obs.observe(document.body, {childList: true, subtree: true});
    }

    function initKeyboardNav() {
        document.addEventListener('keydown', function(e) {
            if (!state.proctoringActive && !state.attemptid) { return; }
            var tag = document.activeElement ? document.activeElement.tagName.toLowerCase() : '';
            // Essay/Choice Essay's answer box is a contenteditable <div>, not a real
            // <textarea> — without this check, typing any letter A-F while composing an
            // essay answer was silently swallowed by the MCQ option-select/Flag-question
            // shortcuts below instead of being typed (e.g. pressing "f" toggled the
            // question flag instead of typing the letter).
            var inInput = ['input', 'textarea', 'select'].indexOf(tag) >= 0 ||
                (document.activeElement && document.activeElement.isContentEditable);

            if (e.altKey && e.key.toLowerCase() === 'c') { e.preventDefault(); toggleCalculator(); return; }
            if (e.altKey && e.key.toLowerCase() === 'n') { e.preventDefault(); toggleScratchPad(); return; }
            if (e.altKey && (e.key === '=' || e.key === '+')) { e.preventDefault(); changeTextZoom(10); return; }
            if (e.altKey && e.key === '-') { e.preventDefault(); changeTextZoom(-10); return; }

            if (!inInput) {
                if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
                    e.preventDefault();
                    if (state.currentslot < state.totalquestions) { submitCurrentAnswer(false, 0); }
                }
                if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (state.currentslot > 1) { submitCurrentAnswer(false, state.currentslot - 1); }
                }
                // Item 6: A-F keys select answer options (A=first, B=second, etc.)
                // Works for multichoice, multicheck, true/false.
                if (/^[a-fA-F]$/.test(e.key) && !e.ctrlKey && !e.metaKey && !e.altKey) {
                    var optIdx = e.key.toLowerCase().charCodeAt(0) - 97; // a=0, b=1, c=2…
                    var opts = document.querySelectorAll(
                        '#lg-question-container .lg-mc-answers label, ' +
                        '#lg-question-container .lg-tf-wrap label, ' +
                        '#lg-question-container .lg-mc-check label'
                    );
                    if (opts[optIdx]) {
                        e.preventDefault();
                        opts[optIdx].click();
                    }
                }
                // F key = Flag current question.
                if (e.key === 'f' || e.key === 'F') {
                    if (!e.ctrlKey && !e.metaKey && !e.altKey) {
                        e.preventDefault();
                        toggleFlag(state.currentslot);
                    }
                }
            }
        });
    }

    /* =======================================================================
     * TECHNICAL DIFFICULTY REPORTING
     * ====================================================================== */
    function showTechnicalDifficultyModal() {
        var modal = document.createElement('div');
        modal.style.cssText = 'position:fixed;inset:0;z-index:9999800;display:flex;align-items:center;' +
            'justify-content:center;background:rgba(0,0,0,0.6);font-family:system-ui,sans-serif;';
        modal.innerHTML =
            '<div style="background:#fff;border-radius:12px;padding:28px;max-width:440px;width:92%;' +
            'box-shadow:0 16px 48px rgba(0,0,0,0.4);">' +
            '<h3 style="margin-bottom:16px;">🆘 Report Technical Issue</h3>' +
            '<label style="font-weight:600;display:block;margin-bottom:6px;">Issue type:</label>' +
            '<select id="lg-issue-type" class="form-control" style="margin-bottom:14px;">' +
            '<option value="camera">Camera not working</option>' +
            '<option value="audio">Microphone issue</option>' +
            '<option value="fullscreen">Cannot enter fullscreen</option>' +
            '<option value="connection">Internet connection problem</option>' +
            '<option value="freeze">Screen frozen or loading slow</option>' +
            '<option value="other">Other issue</option>' +
            '</select>' +
            '<label style="font-weight:600;display:block;margin-bottom:6px;">Describe the problem:</label>' +
            '<textarea id="lg-issue-desc" rows="3" style="width:100%;padding:10px;border:1px solid #ccc;' +
            'border-radius:6px;font-size:14px;margin-bottom:16px;" ' +
            'placeholder="Optional: describe what is happening…"></textarea>' +
            '<div style="display:flex;gap:10px;">' +
            '<button id="lg-issue-cancel" style="flex:1;background:#6c757d;color:#fff;border:none;' +
            'border-radius:8px;padding:11px;font-size:15px;cursor:pointer;">Cancel</button>' +
            '<button id="lg-issue-submit" style="flex:1;background:#dc3545;color:#fff;border:none;' +
            'border-radius:8px;padding:11px;font-size:15px;font-weight:700;cursor:pointer;">Report Issue</button>' +
            '</div></div>';
        document.body.appendChild(modal);
        modal.querySelector('#lg-issue-cancel').addEventListener('click', function() {
            document.body.removeChild(modal);
        });
        modal.querySelector('#lg-issue-submit').addEventListener('click', function() {
            var issueType = document.getElementById('lg-issue-type').value;
            var issueDesc = document.getElementById('lg-issue-desc').value;
            logProctoringEvent('tech_difficulty', {type: issueType, description: issueDesc}, 'low', 0);
            document.body.removeChild(modal);
            showToast('✅ Issue reported to your proctor. Continue your exam.', 'success', 5000);
        });
    }

    /* =======================================================================
     * Make any element draggable by its handle
     * ====================================================================== */
    function makeDraggable(handle, container) {
        var drag = false, ox = 0, oy = 0;
        handle.addEventListener('mousedown', function(e) {
            drag = true;
            ox = e.clientX - container.offsetLeft;
            oy = e.clientY - container.offsetTop;
            container.style.right  = 'auto';
            container.style.bottom = 'auto';
        });
        document.addEventListener('mousemove', function(e) {
            if (!drag) { return; }
            container.style.left = Math.max(0, Math.min(window.innerWidth  - 60, e.clientX - ox)) + 'px';
            container.style.top  = Math.max(0, Math.min(window.innerHeight - 60, e.clientY - oy)) + 'px';
        });
        document.addEventListener('mouseup', function() { drag = false; });
    }

    /* =======================================================================
     * VOICE / AUDIO MONITORING during exam (volume level only, not recorded)
     * ====================================================================== */
    function initVoiceMonitoring() {
        if (!cfg.enableaudio) { return; }
        // autoGainControl must be OFF for level monitoring: with it on (the browser
        // default), the browser actively boosts a near-silent mic signal toward a
        // target loudness, so a genuinely quiet room can still measure as "noisy" —
        // this was the real cause behind "flagged with no noise whatsoever" reports,
        // not just the 0-255→0-100 scale (fixed separately, still needed on top of this).
        navigator.mediaDevices.getUserMedia({
            audio: {autoGainControl: false, noiseSuppression: true, echoCancellation: true},
            video: false,
        })
            .then(function(stream) {
                var AC = window.AudioContext || window.webkitAudioContext;
                if (!AC) { return; }
                var ctx = new AC(); var analyser = ctx.createAnalyser();
                analyser.fftSize = 512;
                ctx.createMediaStreamSource(stream).connect(analyser);
                var lastLog = 0;
                var THRESHOLD = (cfg.noisethreshold > 0) ? cfg.noisethreshold : 8;

                setInterval(function() {
                    if (!state.proctoringActive) { return; }
                    var data = new Uint8Array(analyser.frequencyBinCount);
                    analyser.getByteFrequencyData(data);
                    // getByteFrequencyData values are 0-255; rescale to the 0-100 scale the
                    // admin-configured threshold is documented (and entered) in.
                    var raw   = data.reduce(function(a, b) { return a + b; }, 0) / data.length;
                    var level = Math.round((raw / 255) * 100);

                    // Update live noise meter if it exists on the exam screen
                    updateNoiseMeter(level, THRESHOLD);

                    var now = Date.now();
                    if (level > 5 && now - lastLog > 20000) {
                        lastLog = now;
                        logProctoringEvent('audio_detected', {level: level}, 'low', 0);
                    }
                }, 500);
            })
            .catch(function() {});
    }

    function updateNoiseMeter(level, threshold) {
        var meter = document.getElementById('lg-noise-meter');
        var label = document.getElementById('lg-noise-label');
        if (!meter) { return; }
        var pct = Math.min(100, Math.round((level / threshold) * 100));
        meter.style.width = pct + '%';
        if (level >= threshold) {
            meter.style.background = '#dc3545'; // red = noisy (above threshold)
            if (label) { label.textContent = '🔴 Loud'; label.style.color = '#dc3545'; }
        } else if (level >= threshold * 0.6) {
            meter.style.background = '#ffc107'; // amber = getting loud
            if (label) { label.textContent = '🟡 Rising'; label.style.color = '#856404'; }
        } else {
            meter.style.background = '#28a745'; // green = quiet
            if (label) { label.textContent = '🟢 Quiet'; label.style.color = '#28a745'; }
        }
    }

    function injectNoiseMeter() {
        if (document.getElementById('lg-noise-meter-wrap')) { return; }
        var toolbar = document.getElementById('lg-right-toolbar');
        if (!toolbar) { return; }
        var wrap = document.createElement('div');
        wrap.id = 'lg-noise-meter-wrap';
        wrap.style.cssText = 'background:rgba(255,255,255,0.97);border-radius:10px;' +
            'box-shadow:0 2px 12px rgba(0,0,0,0.12);padding:8px 12px;pointer-events:none;';
        wrap.innerHTML =
            '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">' +
            '<span style="font-size:11px;font-weight:700;color:#495057;">🎤 Noise</span>' +
            '<span id="lg-noise-label" style="font-size:11px;font-weight:700;color:#28a745;">🟢 Quiet</span>' +
            '</div>' +
            '<div style="width:100%;height:8px;background:#e9ecef;border-radius:4px;overflow:hidden;">' +
            '<div id="lg-noise-meter" style="height:100%;width:0%;background:#28a745;transition:width .3s,background .3s;border-radius:4px;"></div>' +
            '</div>';
        toolbar.appendChild(wrap);
    }

    /* =======================================================================
     * EYE TRACKING & LIVENESS MONITORING (requires face-api)
     * ====================================================================== */
    function initFaceMonitoring() {
        if (typeof faceapi === 'undefined' || !cfg.modelurl) { return; }
        // NOTE: do NOT grab el('lg-webcam-video') here — initFaceMonitoring() is
        // called from initProctoring() which runs before initWebcam() creates that
        // element. Look it up fresh inside each interval instead.

        // Liveness (blink detection).
        var lastBlink = Date.now();
        var prevEar   = null;
        var warned    = false;

        // Eye tracking (gaze).
        var lastGazeLog = 0;
        var lastMultiFaceLog = 0;

        // Dedicated fast face-presence check (every 1s) — independent of the
        // 5s blink/gaze/multi-face loop below. Detects "no face" within ~1s
        // for a responsive learner-facing flag, but the score/flag itself is
        // debounced to once per 30s so a continued absence doesn't rack up
        // hundreds of points (and trigger near-instant auto-suspend).
        // Pre-set to now so the webcam has 30 s to initialise before the first
        // "camera unavailable" toast is allowed to fire (prevents a false-positive
        // toast on startup when readyState < 2 for only a brief moment).
        var lastFaceMissingLog = Date.now();
        var faceMissStreak = 0; // consecutive 1s ticks with no face detected
        var faceDetectBusy = false; // true while a detectAllFaces() call is in flight
        setInterval(function() {
            if (!state.proctoringActive) { return; }
            var video = el('lg-webcam-video');
            if (!video) { return; }
            // Camera blocked/denied/disconnected — no stream to analyse at all.
            if (video.readyState < 2) {
                faceMissStreak++;
                if (faceMissStreak >= 5) { // 5 consecutive misses (~5s) before logging
                    var nowCb = Date.now();
                    if (nowCb - lastFaceMissingLog > 30000) {
                        lastFaceMissingLog = nowCb;
                        if (logProctoringEvent('face_missing', {reason: 'camera_unavailable'}, 'high',
                            (cfg.scoreconfig && cfg.scoreconfig.facemissing) || 10)) {
                            showToast('⚠️ Camera not available — please enable your webcam.', 'warning', 6000);
                        }
                    }
                }
                return;
            }
            // Skip this tick if the previous detection hasn't resolved yet. Without this,
            // a slow tick (busy device, momentary lag) can resolve AFTER a faster later
            // tick — its stale "no face" result then lands last and silently overwrites a
            // correct faceMissStreak=0 reset, corrupting the streak with an out-of-order
            // result even though the face was actually visible moments before.
            if (faceDetectBusy) { return; }
            faceDetectBusy = true;
            // Use lower scoreThreshold + larger inputSize for better detection of
            // darker skin tones and backlit conditions.
            var faceOpts = new faceapi.TinyFaceDetectorOptions({scoreThreshold: 0.3, inputSize: 224});
            faceapi.detectAllFaces(video, faceOpts).then(function(dets) {
                faceDetectBusy = false;
                if (dets.length) { faceMissStreak = 0; return; } // face found — reset streak
                faceMissStreak++;
                // Require 15 consecutive missed detections (~15s) before logging.
                // 5s was too low — students naturally look down while typing essays,
                // causing false positives even with face clearly visible in the frame.
                if (faceMissStreak < 15) { return; }
                var now = Date.now();
                if (now - lastFaceMissingLog > 30000) {
                    lastFaceMissingLog = now;
                    if (logProctoringEvent('face_missing', {}, 'high',
                        (cfg.scoreconfig && cfg.scoreconfig.facemissing) || 10)) {
                        showToast('⚠️ Face not visible — please face the camera.', 'warning', 6000);
                    }
                }
            }).catch(function() { faceDetectBusy = false; });
        }, 1000);

        setInterval(function() {
            var video = el('lg-webcam-video');
            if (!state.proctoringActive || !video || video.readyState < 2) { return; }
            faceapi.detectAllFaces(video, new faceapi.TinyFaceDetectorOptions()).withFaceLandmarks(true).then(function(dets) {
                if (!dets.length) { return; }
                if (dets.length > 1) {
                    var nowMf = Date.now();
                    if (nowMf - lastMultiFaceLog > 30000) {
                        lastMultiFaceLog = nowMf;
                        if (logProctoringEvent('multiple_faces_detected', {count: dets.length}, 'high',
                            (cfg.scoreconfig && cfg.scoreconfig.faceanomaly) || 10)) {
                            showToast('🚫 Multiple faces detected on webcam!', 'danger', 8000);
                            // Tag matches attemptdetail.php's Evidence-column lookup
                            // ('violation_' + event_type) — previously 'multiple_faces',
                            // which never matched anything and was silently invisible.
                            // logProctoringEvent()'s own generic auto-capture already
                            // fires for this event too (impact > 0); this explicit call
                            // is now a genuine second, independent evidence attempt
                            // instead of wasted duplicate work.
                            captureAndUploadSnapshot('violation_multiple_faces_detected');
                        }
                    }
                }
                var lm  = dets[0].landmarks;
                var ear = function(pts) {
                    var h = Math.abs(pts[1].y - pts[5].y) + Math.abs(pts[2].y - pts[4].y);
                    var w = Math.abs(pts[0].x - pts[3].x);
                    return w > 0 ? h / (2 * w) : 1;
                };
                var avgEar = (ear(lm.getLeftEye()) + ear(lm.getRightEye())) / 2;

                // Blink detection: relative EAR drop from cfg, period from cfg.
                var earDrop    = cfg.no_blink_ear_drop  || 0.08;
                var blinkPeriod= (cfg.no_blink_period   || 300) * 1000;
                var blinkScore = (cfg.scoreconfig && cfg.scoreconfig.noblink != null) ? cfg.scoreconfig.noblink : 10;
                if (prevEar !== null && (prevEar - avgEar) > earDrop) {
                    lastBlink = Date.now();
                    warned = false;
                }
                prevEar = avgEar;
                if (!warned && (Date.now() - lastBlink) > blinkPeriod) {
                    warned = true;
                    logProctoringEvent('no_blink_5min', {}, 'medium', blinkScore);
                }

                // Gaze direction.
                var now = Date.now();
                var le = lm.getLeftEye(); var re = lm.getRightEye();
                var gazeL = Math.abs(le[0].x + le[3].x - 2 * le[1].x);
                var gazeR = Math.abs(re[0].x + re[3].x - 2 * re[1].x);
                var ew    = Math.abs(le[0].x - le[3].x);
                if (ew > 0 && (gazeL + gazeR) / 2 > ew * 0.3 && now - lastGazeLog > 15000) {
                    lastGazeLog = now;
                    logProctoringEvent('gaze_away', {}, 'low', 0);
                }
            }).catch(function() {});
        }, 5000);
    }

    /* =======================================================================
     * BEFORE-UNLOAD WARNING
     * ====================================================================== */
    function initBeforeUnload() {
        window.addEventListener('beforeunload', function(e) {
            if (!state.proctoringActive || state.finished) { return; }
            logProctoringEvent('page_unload', {url: window.location.href}, 'medium', 0);
            e.preventDefault();
            e.returnValue = 'Leaving this page will be logged. Are you sure?';
            return e.returnValue;
        });
    }

    /* makeDraggable is now defined in the Phase 1 section above */

    /* =======================================================================
     * PHASE 2: IDENTITY & SCREEN SECURITY
     * ====================================================================== */

    // ---- VPN / Proxy detection (via ipapi.co free tier) ----
    function detectVPN() {
        fetch('https://ipapi.co/json/', {signal: AbortSignal.timeout ? AbortSignal.timeout(4000) : undefined})
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.threat) {
                    var threat = d.threat;
                    if (threat.is_tor || threat.is_proxy || threat.is_datacenter) {
                        logProctoringEvent('vpn_detected', {
                            ip: d.ip, type: JSON.stringify(threat)
                        }, 'high', 20);
                        showToast('⚠️ VPN or proxy connection detected and logged.', 'warning', 6000);
                    }
                }
                // Timezone mismatch (browser timezone vs IP-geolocation-inferred timezone)
                // was removed as a violation — too many legitimate causes (travel, VPN for
                // legitimate reasons, corporate network routing, IP geolocation database
                // inaccuracy) for it to be a reliable integrity signal.
            })
            .catch(function() { /* API unreachable — skip silently */ });
    }

    // ---- Screenshot blocking: show black overlay on PrintScreen ----
    function initScreenshotBlocking() {
        // Note: logging + toast for PrintScreen are handled by initKeyBlock() to avoid duplicate
        // events. This listener only shows the black screen overlay to ruin the screenshot.
        document.addEventListener('keydown', function(e) {
            if (!state.proctoringActive) { return; }
            if (e.key === 'PrintScreen' || e.key === 'PrtScr' || e.key === 'Snapshot') {
                // Flash a black overlay — ruins any screenshot captured by the OS.
                var overlay = document.createElement('div');
                overlay.style.cssText = 'position:fixed;inset:0;background:#000;z-index:9999999;pointer-events:none;';
                document.body.appendChild(overlay);
                setTimeout(function() {
                    if (overlay.parentNode) { overlay.parentNode.removeChild(overlay); }
                }, 300);
            }
        }, true);
        // Clipboard API interception.
        document.addEventListener('copy', function() {
            if (!state.proctoringActive) { return; }
            setTimeout(function() {
                if (navigator.clipboard) {
                    navigator.clipboard.writeText('').catch(function() {});
                }
            }, 10);
        });
    }

    // ---- Developer tools detection ----
    function initDevToolsDetection() {
        var threshold = 160;
        var check = function() {
            if (!state.proctoringActive) { return; }
            var widthThreshold  = window.outerWidth  - window.innerWidth  > threshold;
            var heightThreshold = window.outerHeight - window.innerHeight > threshold;
            if (widthThreshold || heightThreshold) {
                logProctoringEvent('devtools_open', {method: 'size_heuristic'}, 'high', 15);
                showToast('⚠️ Developer tools detected and logged.', 'danger');
            }
        };
        setInterval(check, 5000);
        // F12 is already blocked in initKeyBlock — add logging here too.
        document.addEventListener('keydown', function(e) {
            if (state.proctoringActive && e.key === 'F12') {
                logProctoringEvent('devtools_f12', {}, 'medium', 10);
            }
        }, true);
    }

    // ---- Remote desktop / screen share detection (heuristics) ----
    function detectRemoteDesktop() {
        // Detect screen sharing via navigator.mediaDevices screen capture.
        if (!navigator.mediaDevices) { return; }
        // If getDisplayMedia is actively being used, flag it.
        var origGetDM = navigator.mediaDevices.getDisplayMedia;
        if (origGetDM) {
            navigator.mediaDevices.getDisplayMedia = function() {
                logProctoringEvent('screen_share_attempt', {}, 'high',
                    (cfg.scoreconfig && cfg.scoreconfig.screenshareattempt) || 25);
                showToast('⚠️ Screen sharing is not allowed during this assessment.', 'danger');
                return Promise.reject(new Error('Screen sharing blocked'));
            };
        }
        // Check for unusual window/screen ratio (TeamViewer often uses remote resolutions).
        var checkRatio = function() {
            if (!state.proctoringActive) { return; }
            var ratio = window.screen.width / window.screen.height;
            // Unusual ratios typical of remote display (very wide or very tall).
            if (ratio > 3 || ratio < 0.4) {
                logProctoringEvent('unusual_display', {ratio: ratio.toFixed(2)}, 'medium',
                    (cfg.scoreconfig && cfg.scoreconfig.unusualdisplay) || 10);
            }
        };
        setTimeout(checkRatio, 2000);
    }

    // ---- Lighting check (webcam brightness analysis) ----
    function detectPoorLighting() {
        if (!cfg.enablewebcam) { return; }
        setTimeout(function() {
            var video = el('lg-webcam-video');
            if (!video || video.readyState < 2) { return; }
            var canvas = document.createElement('canvas');
            canvas.width = 80; canvas.height = 60;
            var ctx = canvas.getContext('2d');
            ctx.drawImage(video, 0, 0, 80, 60);
            var data = ctx.getImageData(0, 0, 80, 60).data;
            var total = 0;
            for (var i = 0; i < data.length; i += 4) {
                total += (data[i] + data[i+1] + data[i+2]) / 3;
            }
            var brightness = total / (80 * 60);
            if (brightness < 30) {
                logProctoringEvent('poor_lighting', {brightness: Math.round(brightness)}, 'medium', 5);
                showToast('⚠️ Your camera feed is too dark. Please improve your lighting.', 'warning', 8000);
            }
        }, 10000); // Check 10s after start.
    }

    // ---- Background blur detection ----
    function detectBackgroundBlur() {
        if (!cfg.enablewebcam) { return; }
        setInterval(function() {
            if (!state.proctoringActive) { return; }
            var video = el('lg-webcam-video');
            if (!video || video.readyState < 2) { return; }
            var c = document.createElement('canvas');
            c.width = 80; c.height = 60;
            var ctx = c.getContext('2d');
            ctx.drawImage(video, 0, 0, 80, 60);
            var d = ctx.getImageData(0, 0, 80, 60).data;
            // Variance check: uniformly blurred background has low variance at edges.
            var edges = [];
            for (var x = 1; x < 79; x++) {
                var idx = (x) * 4;
                edges.push(Math.abs(d[idx] - d[idx + 4]));
            }
            var avg = edges.reduce(function(a, b) { return a + b; }, 0) / edges.length;
            if (avg < 3 && state.riskScore > 0) { // Very uniform = possible blur.
                logProctoringEvent('background_blur_suspected', {variance: avg.toFixed(2)}, 'low', 0);
            }
        }, 120000); // Check every 2 min.
    }

    // ---- Virtual machine detection (canvas fingerprint heuristic) ----
    function detectVirtualMachine() {
        // VMs often have different canvas rendering.
        var c = document.createElement('canvas');
        var ctx = c.getContext('2d');
        if (!ctx) { return; }
        ctx.fillStyle = 'rgba(100,200,150,0.7)';
        ctx.fillRect(0, 0, 10, 10);
        var data = ctx.getImageData(0, 0, 1, 1).data;
        // VMs running software rendering produce slightly different values.
        // Also check CPU concurrency (VMs often have 1-2 cores).
        var cores = navigator.hardwareConcurrency || 0;
        var vmSuspected = cores <= 2 && window.screen.width <= 1024;
        if (vmSuspected) {
            logProctoringEvent('vm_suspected', {cores: cores, screen: window.screen.width + 'x' + window.screen.height}, 'medium', 10);
        }
        // Log hardware fingerprint regardless.
        logProctoringEvent('hardware_fingerprint', {
            cores: cores,
            screen: window.screen.width + 'x' + window.screen.height,
            colorDepth: window.screen.colorDepth,
            deviceMemory: navigator.deviceMemory || 'unknown',
            platform: navigator.platform,
        }, 'low', 0);
    }

    // ---- Head pose estimation (looking down repeatedly) ----
    function initHeadPoseMonitoring() {
        if (typeof faceapi === 'undefined' || !cfg.modelurl) { return; }
        // NOTE: do NOT grab el('lg-webcam-video') here — look it up inside the interval.
        var downCount = 0;
        var hdThreshold   = cfg.head_down_threshold   || 80;
        var hdConsecutive = cfg.head_down_consecutive || 5;
        var hdScore       = (cfg.scoreconfig && cfg.scoreconfig.headdown != null) ? cfg.scoreconfig.headdown : 5;
        setInterval(function() {
            var video = el('lg-webcam-video');
            if (!state.proctoringActive || !video || video.readyState < 2) { return; }
            faceapi.detectAllFaces(video, new faceapi.TinyFaceDetectorOptions()).withFaceLandmarks(true).then(function(dets) {
                if (!dets.length) { return; }
                var lm  = dets[0].landmarks;
                var nose  = lm.getNose();
                var leftEye  = lm.getLeftEye();
                var rightEye = lm.getRightEye();
                var eyeMidY = (leftEye[0].y + rightEye[0].y) / 2;
                var noseTipY = nose[6].y;
                if (noseTipY - eyeMidY > hdThreshold) {
                    downCount++;
                    if (downCount >= hdConsecutive) {
                        downCount = 0;
                        // Writing a long Essay/Choice Essay answer routinely involves
                        // looking down at the keyboard/hands for well over the 40s this
                        // needs to fire — completely normal for that question type, unlike
                        // MCQ where there's no reason to look away from the screen. Still
                        // logged (visible to a proctor reviewing the attempt) but scores 0
                        // while an essay-writing question is the one currently on screen.
                        var hdImpact = lgIsEssayQtype(state.currentqtype) ? 0 : hdScore;
                        logProctoringEvent('head_down', {}, 'medium', hdImpact);
                    }
                } else {
                    downCount = 0;
                }
            }).catch(function() {});
        }, 8000);
    }

    // ---- Timezone verification ----
    function verifyTimezone() {
        var tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
        logProctoringEvent('timezone_logged', {timezone: tz, utcOffset: new Date().getTimezoneOffset()}, 'low', 0);
    }

    /* =======================================================================
     * INITIATE ALL PROCTORING — called once, after wizard completes
     * ====================================================================== */
    var lgProctoringInited = false;
    function initProctoring() {
        if (lgProctoringInited) { state.proctoringActive = true; return; }
        lgProctoringInited = true;
        state.proctoringActive = true;
        state.multiMonitorLogged = false;
        initFullscreen();
        initTabDetection();
        initKeyBlock();
        initClipboard();
        initTypingPatternMonitor();
        initMultiMonitorDetection();
        initConnectionMonitor();
        initReconnectionHandler();
        initSpellcheckDisable();
        initVoiceMonitoring();
        initBeforeUnload();
        initFaceMonitoring();
        initKeyboardNav();
        // Phase 2: Identity & Screen Security.
        detectVPN();
        initScreenshotBlocking();
        initDevToolsDetection();
        detectRemoteDesktop();
        detectVirtualMachine();
        verifyTimezone();
        detectPoorLighting();
        detectBackgroundBlur();
        initHeadPoseMonitoring();
        // Phase 3: Advanced AI Detection (client-side — runs on student's device).
        if (cfg.enablewebcam) {
            initAudioClipRecording();       // Record 5-10s when noise threshold exceeded.
            initObjectDetection();          // Phone, paper, 2nd person via TensorFlow.js.
            initFaceIdentityMatching();     // Compare live face to wizard face descriptor.
            initBackgroundChangeDetection();// Flag sudden background changes.
        }

        // Heartbeat — keeps session visible on live proctor dashboard.
        setInterval(function() {
            if (!state.proctoringActive || state.finished) { return; }
            logProctoringEvent('session_heartbeat', {}, 'low', 0);
        }, 60000);

        // Periodic risk-check safety net — fires halfwarn and auto-suspend even when
        // logProctoringEvent is blocked (e.g. by halfwarn/checkIn modals). Runs every 5 s.
        setInterval(function() {
            // fsBlocked: don't stack the half-warn modal on top of the fullscreen blocker —
            // it renders at a higher z-index and can hide the "Return to Fullscreen" button/
            // click-anywhere area, leaving the student stuck with no visible way back in.
            if (!state.proctoringActive || state.finished || state.suspended || state.fsBlocked) { return; }
            // Half-warn check.
            var threshold = (cfg.autosuspend_score > 0) ? cfg.autosuspend_score : 80;
            var half = Math.floor(threshold * 0.5);
            if (!lgHalfWarnShown && state.riskScore >= half) {
                lgHalfWarnShown = true;
                lgShowHalfWarning(state.riskScore, threshold);
            }
            // Auto-suspend check.
            lgCheckAutoSuspend();
        }, 5000);

        // Periodic screen capture — uses the screen stream from wizard screen-share step.
        initPeriodicScreenCapture();

        // If webcam is disabled, the snapshot timer won't be started by initWebcam().
        // Start it here so screen-capture fallback still fires every snapshot interval.
        if (!cfg.enablewebcam && !state.snapshotTimer) {
            startSnapshotTimer();
        }
    }

    function initPeriodicScreenCapture() {
        // Capture a frame at the same interval as the webcam snapshot timer so
        // both sources produce matching counts. Falls back to 15 s if not configured.
        var scrnInterval = cfg.snapinterval || 15000;
        // Same cohort-synchronisation concern as startSnapshotTimer() above — a random
        // one-time delay before the first tick spreads each student's screen-capture
        // grid independently instead of the whole cohort uploading in lockstep every
        // ~15s. Uses its own random offset (not the same one as the webcam timer) so
        // the two capture types don't cluster together either.
        setTimeout(function() {
            function tick() {
                if (!state.proctoringActive || !state.screenStream || state.finished) { return; }
                captureScreenSnapshot('screen_capture');
            }
            tick();
            setInterval(tick, scrnInterval);
        }, Math.random() * scrnInterval);
    }

    /* =======================================================================
     * AUDIO CLIP RECORDING
     * Records 5-10 seconds of audio when background noise exceeds threshold.
     * Runs entirely in browser — no AI server needed.
     * ====================================================================== */
    var audioClipRecorder  = null;
    var audioClipRecording = false;
    var audioClipLastTime  = 0;
    var audioClipCooldown  = 10000;   // 10s cooldown — just prevents overlap with the
                                       // 8s recording itself; every sustained noise
                                       // crossing gets its own +5 score event.
    var violationAudioStream = null;   // stored so logProctoringEvent can trigger short clips.

    function initAudioClipRecording() {
        if (!cfg.enableaudio) { return; }
        // autoGainControl OFF — see initVoiceMonitoring() above for why: with it on, the
        // browser boosts a near-silent room toward a target loudness, causing false
        // "noise detected" flags in genuinely quiet environments.
        navigator.mediaDevices.getUserMedia({
            audio: {autoGainControl: false, noiseSuppression: true, echoCancellation: true},
            video: false,
        })
            .then(function(stream) {
                violationAudioStream = stream;  // store for violation evidence captures
                var AC = window.AudioContext || window.webkitAudioContext;
                if (!AC) { return; }
                var ctx      = new AC();
                var analyser = ctx.createAnalyser();
                analyser.fftSize = 512;
                ctx.createMediaStreamSource(stream).connect(analyser);

                var THRESHOLD    = (cfg.noisethreshold > 0) ? cfg.noisethreshold : 8;
                var DURATION_MS  = 8000; // 8 seconds recording.
                var highCount    = 0;
                // 7 ticks @ 300ms = 2.1s — noise must be sustained above threshold for
                // more than 2 seconds before it counts as a violation, not just a brief spike.
                var TRIGGER_TICKS = 7;

                setInterval(function() {
                    if (!state.proctoringActive || audioClipRecording) { return; }
                    var data  = new Uint8Array(analyser.frequencyBinCount);
                    analyser.getByteFrequencyData(data);
                    // getByteFrequencyData values are 0-255; rescale to the 0-100 scale the
                    // admin-configured threshold is documented (and entered) in.
                    var raw   = data.reduce(function(a,b){return a+b;},0) / data.length;
                    var level = Math.round((raw / 255) * 100);

                    if (level > THRESHOLD) {
                        highCount++;
                        if (highCount >= TRIGGER_TICKS) {
                            var now = Date.now();
                            if (now - audioClipLastTime > audioClipCooldown) {
                                startAudioClipRecording(stream, level, DURATION_MS);
                                highCount = 0;
                            }
                        }
                    } else {
                        highCount = Math.max(0, highCount - 1);
                    }
                }, 300);
            })
            .catch(function() {});
    }

    function startAudioClipRecording(stream, noiseLevel, durationMs, logAsViolation) {
        if (audioClipRecording || !state.attemptid) { return; }
        if (logAsViolation === undefined) { logAsViolation = true; }
        audioClipRecording = true;
        audioClipLastTime  = Date.now();

        var chunks = [];
        var mime   = (typeof MediaRecorder !== 'undefined' &&
                      MediaRecorder.isTypeSupported('audio/webm;codecs=opus'))
                     ? 'audio/webm;codecs=opus' : 'audio/webm';
        try {
            audioClipRecorder = new MediaRecorder(stream, {mimeType: mime, audioBitsPerSecond: 32000});
        } catch (e) {
            audioClipRecorder = new MediaRecorder(stream);
        }

        audioClipRecorder.ondataavailable = function(e) {
            if (e.data.size > 0) { chunks.push(e.data); }
        };
        audioClipRecorder.onstop = function() {
            audioClipRecording = false;
            var blob   = new Blob(chunks, {type: mime});
            var reader = new FileReader();
            reader.onloadend = function() {
                var b64 = reader.result;
                callWS('mod_learnguard_upload_audio_clip', {
                    attemptid:   state.attemptid,
                    audiodata:   b64,
                    duration:    Math.round(durationMs / 1000),
                    noise_level: noiseLevel,
                }).then(function() {
                    showToast('🎤 Audio clip saved (noise level: ' + noiseLevel + ')', 'info', 3000);
                }).catch(function() {});
            };
            reader.readAsDataURL(blob);
        };

        audioClipRecorder.start(1000);
        // Only a GENUINE noise-threshold crossing (the interval loop above) should log
        // its own scored violation. When this recording was started as a side-effect of
        // auto-capturing evidence for a DIFFERENT, already-scored violation (Face Missing,
        // Fullscreen Exit, etc. — see logProctoringEvent's evidence-capture block), logging
        // ANOTHER 'audio_clip_started' violation on top double-scores the same moment and
        // mislabels it as a noise event even when noiseLevel is 0 (no noise involved at all).
        if (logAsViolation) {
            // Continuous typing on an Essay/Choice Essay question can easily sustain
            // keyboard-clatter noise above the threshold for the whole time a student is
            // composing an answer — completely normal, unlike MCQ where there's no
            // ongoing typing at all. Still recorded/logged as evidence, just scores 0
            // while an essay-writing question is the one currently on screen.
            var noiseImpact = lgIsEssayQtype(state.currentqtype) ? 0 :
                ((cfg.scoreconfig && cfg.scoreconfig.audioclip !== undefined) ? cfg.scoreconfig.audioclip : 5);
            logProctoringEvent('audio_clip_started', {noise_level: noiseLevel}, 'medium', noiseImpact);
        }

        setTimeout(function() {
            if (audioClipRecorder && audioClipRecorder.state !== 'inactive') {
                audioClipRecorder.stop();
            }
        }, durationMs);
    }

    /* =======================================================================
     * TENSORFLOW.JS OBJECT DETECTION
     * Detects phones, books/paper, 2nd person on webcam frames.
     * Uses COCO-SSD model loaded from CDN — runs on student's device CPU.
     * For 10-300 learners: each student's own device processes, no server load.
     * ====================================================================== */
    var tfModel        = null;
    var tfModelLoading = false;
    var lgOrtSession   = null;
    var prevFrameData  = null;

    // COCO class names for YOLOv5 (80 classes).
    var LG_YOLO_CLASSES = [
        'person','bicycle','car','motorcycle','airplane','bus','train','truck','boat',
        'traffic light','fire hydrant','stop sign','parking meter','bench','bird','cat',
        'dog','horse','sheep','cow','elephant','bear','zebra','giraffe','backpack',
        'umbrella','handbag','tie','suitcase','frisbee','skis','snowboard','sports ball',
        'kite','baseball bat','baseball glove','skateboard','surfboard','tennis racket',
        'bottle','wine glass','cup','fork','knife','spoon','bowl','banana','apple',
        'sandwich','orange','broccoli','carrot','hot dog','pizza','donut','cake','chair',
        'couch','potted plant','bed','dining table','toilet','tv','laptop','mouse',
        'remote','keyboard','cell phone','microwave','oven','toaster','sink',
        'refrigerator','book','clock','vase','scissors','teddy bear','hair drier','toothbrush'
    ];

    var OBJECT_VIOLATIONS = {
        'cell phone': {severity: 'high',   score: 25, msg: 'Mobile phone detected in exam area.'},
        'book':       {severity: 'medium', score: 10, msg: 'Book/printed material detected.'},
        'laptop':     {severity: 'high',   score: 20, msg: 'Additional laptop/screen detected.'},
        'remote':     {severity: 'low',    score: 5,  msg: 'Remote control detected.'},
        'headphones': {severity: 'medium', score: 15, msg: 'Earphones/headphones detected.'},
        'keyboard':   {severity: 'medium', score: 10, msg: 'Keyboard detected (verify it is yours).'},
    };

    function initObjectDetection() {
        if (tfModelLoading) { return; }
        tfModelLoading = true;
        // Try bundled ONNX YOLOv5n first.
        if (typeof ort !== 'undefined' && cfg.onnxurl) {
            setTimeout(function() {
                ort.InferenceSession.create(cfg.onnxurl + '/yolov5n.onnx', {
                    executionProviders: ['wasm'],
                    graphOptimizationLevel: 'all',
                }).then(function(session) {
                    lgOrtSession = session;
                    showToast('🔍 Object detection active (YOLOv5n)', 'info', 3000);
                    setTimeout(function() { lgRunYolo(); }, 8000);
                    setInterval(function() { lgRunYolo(); }, 25000);
                }).catch(function() { lgFallbackCocoSsd(); });
            }, 5000);
        } else {
            lgFallbackCocoSsd();
        }
    }

    // Maps an OBJECT_VIOLATIONS class to its scoreconfig key, so every detected object
    // type reads its admin-configured score instead of the hardcoded map default —
    // 'cell phone' was already the only one wired this way; generalised to the rest.
    var OBJECT_SCORE_KEYS = {
        'cell phone': 'phone', 'book': 'objectbook', 'laptop': 'objectlaptop',
        'remote': 'objectremote', 'keyboard': 'objectkeyboard', 'headphones': 'objectheadphones',
    };
    function lgObjectScore(cls, fallback) {
        var key = OBJECT_SCORE_KEYS[cls];
        return (key && cfg.scoreconfig && cfg.scoreconfig[key] !== undefined) ? cfg.scoreconfig[key] : fallback;
    }

    var LG_YOLO_SIZE = 416; // 416x416 is 2.4x faster than 640x640, still accurate.

    function lgRunYolo() {
        if (!state.proctoringActive || !lgOrtSession) { return; }
        var video = el('lg-webcam-video');
        if (!video || video.readyState < 2) { return; }
        var sz = LG_YOLO_SIZE;
        var c = document.createElement('canvas');
        c.width = sz; c.height = sz;
        c.getContext('2d').drawImage(video, 0, 0, sz, sz);
        var d = c.getContext('2d').getImageData(0, 0, sz, sz).data;
        var t = new Float32Array(3 * sz * sz);
        for (var i = 0; i < sz * sz; i++) {
            t[i]             = d[i * 4]     / 255;
            t[i + sz * sz]   = d[i * 4 + 1] / 255;
            t[i + 2*sz * sz] = d[i * 4 + 2] / 255;
        }
        lgOrtSession.run({images: new ort.Tensor('float32', t, [1, 3, sz, sz])})
            .then(function(out) { lgProcessYoloPreds(out[Object.keys(out)[0]].data); })
            .catch(function() {});
    }

    function lgProcessYoloPreds(preds) {
        var personCount = 0;
        for (var r = 0; r < preds.length; r += 85) {
            var conf = preds[r + 4];
            if (conf < 0.4) { continue; }
            var maxScore = 0; var classId = 0;
            for (var c = 5; c < 85; c++) {
                if (preds[r + c] > maxScore) { maxScore = preds[r + c]; classId = c - 5; }
            }
            var score = conf * maxScore;
            if (score < 0.35) { continue; }
            var cls = (LG_YOLO_CLASSES[classId] || '').toLowerCase();
            if (cls === 'person') { personCount++; continue; }
            var viol = OBJECT_VIOLATIONS[cls];
            if (viol) {
                var vscore = lgObjectScore(cls, viol.score);
                logProctoringEvent('object_detected_' + cls.replace(' ','_'),
                    {class: cls, confidence: Math.round(score * 100)}, viol.severity, vscore);
                showToast('⚠️ ' + viol.msg, 'warning', 6000);
                // Tag matches the Evidence-column lookup ('violation_' + event_type,
                // where event_type is 'object_detected_'+class) — previously
                // 'object_'+class, which never matched anything.
                captureAndUploadSnapshot('violation_object_detected_' + cls.replace(' ','_'));
            }
        }
        if (personCount > 1) {
            logProctoringEvent('multiple_persons_detected', {count: personCount}, 'high',
                (cfg.scoreconfig && cfg.scoreconfig.multiface) || 25);
            showToast('🚫 Multiple people detected in frame!', 'danger', 8000);
            captureAndUploadSnapshot('violation_multiple_persons_detected');
        }
    }

    function lgFallbackCocoSsd() {
        // CDN fallback if ONNX not available.
        if (typeof cocoSsd === 'undefined') { return; }
        cocoSsd.load().then(function(model) {
            tfModel = model;
            showToast('🔍 Object detection active', 'info', 3000);
            function run() {
                if (!state.proctoringActive || !tfModel) { return; }
                var video = el('lg-webcam-video');
                if (video && video.readyState >= 2) { runObjectDetection(video); }
            }
            setTimeout(run, 8000);
            setInterval(run, 25000);
        }).catch(function() { tfModelLoading = false; });
    }

    function runObjectDetection(video) {
        if (!tfModel) { return; }
        tfModel.detect(video).then(function(predictions) {
            var personCount = 0;
            predictions.forEach(function(pred) {
                if (pred.score < 0.35) { return; }
                var cls = pred.class.toLowerCase();
                if (cls === 'person') { personCount++; return; }
                var viol = OBJECT_VIOLATIONS[cls];
                if (viol) {
                    var vscore = (cls === 'cell phone' && cfg.scoreconfig && cfg.scoreconfig.phone)
                        ? cfg.scoreconfig.phone : viol.score;
                    logProctoringEvent('object_detected_' + cls.replace(' ','_'),
                        {class: cls, confidence: Math.round(pred.score * 100)}, viol.severity, vscore);
                    showToast('⚠️ ' + viol.msg, 'warning', 6000);
                    // Tag matches the Evidence-column lookup ('violation_' + event_type,
                    // where event_type is 'object_detected_'+class) — previously
                    // 'object_'+class, which never matched anything.
                    captureAndUploadSnapshot('violation_object_detected_' + cls.replace(' ','_'));
                }
            });
            if (personCount > 1) {
                logProctoringEvent('multiple_persons_detected', {count: personCount}, 'high',
                    (cfg.scoreconfig && cfg.scoreconfig.multiface) || 25);
                showToast('🚫 Multiple people detected in frame!', 'danger', 8000);
                captureAndUploadSnapshot('violation_multiple_persons_detected');
            }
        }).catch(function() {});
    }

    /* =======================================================================
     * BACKGROUND CHANGE DETECTION
     * Compares consecutive webcam frames — sudden large difference = swap.
     * No model needed — pure pixel math in the browser.
     * ====================================================================== */
    function initBackgroundChangeDetection() {
        var canvas  = document.createElement('canvas');
        canvas.width = 64; canvas.height = 48;
        var ctx     = canvas.getContext('2d');
        var lastPx  = null;
        var THRESH  = 35;   // Mean pixel difference threshold.

        setInterval(function() {
            if (!state.proctoringActive) { return; }
            var video = el('lg-webcam-video');
            if (!video || video.readyState < 2) { return; }

            ctx.drawImage(video, 0, 0, 64, 48);
            var data = ctx.getImageData(0, 0, 64, 48).data;

            if (lastPx) {
                var diff = 0;
                for (var i = 0; i < data.length; i += 4) {
                    diff += Math.abs(data[i]   - lastPx[i])   +
                            Math.abs(data[i+1] - lastPx[i+1]) +
                            Math.abs(data[i+2] - lastPx[i+2]);
                }
                var meanDiff = diff / (data.length / 4 * 3);
                if (meanDiff > THRESH) {
                    logProctoringEvent('background_change', {mean_diff: Math.round(meanDiff)}, 'medium',
                        (cfg.scoreconfig && cfg.scoreconfig.bgchange) || 10);
                    showToast('⚠️ Sudden background change detected.', 'warning', 5000);
                    captureAndUploadSnapshot('violation_background_change');
                }
            }
            lastPx = new Uint8ClampedArray(data);
        }, 15000); // Check every 15 seconds.
    }

    /* =======================================================================
     * FACE IDENTITY MATCHING
     * Stores student's face descriptor in the wizard (step 7).
     * Every 5 minutes during exam, compares live face to stored descriptor.
     * If Euclidean distance > 0.6 → different person → high violation.
     * Runs entirely in browser via face-api.js — no server needed.
     * ====================================================================== */
    var storedFaceDescriptor = null;

    function storeFaceDescriptor(videoElement) {
        if (typeof faceapi === 'undefined' || !faceApiReady) { return; }
        faceapi.detectSingleFace(videoElement, new faceapi.TinyFaceDetectorOptions())
            .withFaceLandmarks(true)
            .withFaceDescriptor()
            .then(function(det) {
                if (det && det.descriptor) {
                    storedFaceDescriptor = Array.from(det.descriptor);
                    try {
                        sessionStorage.setItem('lg_face_desc_' + cfg.userid,
                            JSON.stringify(storedFaceDescriptor));
                    } catch (e) {}
                }
            }).catch(function() {});
    }

    function initFaceIdentityMatching() {
        // Try to restore descriptor from session (in case of resume).
        if (!storedFaceDescriptor) {
            try {
                var stored = sessionStorage.getItem('lg_face_desc_' + cfg.userid);
                if (stored) { storedFaceDescriptor = JSON.parse(stored); }
            } catch (e) {}
        }

        if (typeof faceapi === 'undefined' || !faceApiReady) { return; }

        // Match every 5 minutes.
        setInterval(function() {
            if (!state.proctoringActive || !storedFaceDescriptor) { return; }
            var video = el('lg-webcam-video');
            if (!video || video.readyState < 2) { return; }

            faceapi.detectSingleFace(video, new faceapi.TinyFaceDetectorOptions())
                .withFaceLandmarks(true)
                .withFaceDescriptor()
                .then(function(det) {
                    if (!det || !det.descriptor) {
                        // No face found — already handled by initFaceMonitoring.
                        return;
                    }
                    // Euclidean distance: < 0.4 same person, > 0.6 different person.
                    var stored  = new Float32Array(storedFaceDescriptor);
                    var current = det.descriptor;
                    var dist    = faceapi.euclideanDistance(stored, current);

                    if (dist > 0.6) {
                        logProctoringEvent('face_identity_mismatch',
                            {distance: dist.toFixed(3), threshold: 0.6}, 'high',
                            (cfg.scoreconfig && cfg.scoreconfig.faceanomaly) || 10);
                        showToast('🚨 Identity mismatch detected!', 'danger', 10000);
                        captureAndUploadSnapshot('violation_face_identity_mismatch');
                    }
                }).catch(function() {});
        }, 300000); // Every 5 minutes.
    }

    /* =======================================================================
     * WEBCAM
     * ====================================================================== */
    function initWebcam() {
        if (!cfg.enablewebcam) { return; }

        // Camera box: fixed top-right, sits inside the right toolbar cam section.
        var camSection = document.getElementById('lg-cam-section');
        if (!document.getElementById('lg-webcam-box')) {
            var bp = cfg.brandprimary || '#0f6cbf';
            var box = document.createElement('div');
            box.id = 'lg-webcam-box';
            if (camSection) {
                // Inside the fixed right toolbar — no position needed
                box.style.cssText = 'width:100%;border-radius:10px;overflow:hidden;' +
                    'box-shadow:0 4px 20px rgba(0,0,0,0.4);background:#111;';
            } else {
                // Fallback: fixed position if shell not ready yet
                box.style.cssText = 'position:fixed;top:64px;right:8px;z-index:9990;' +
                    'width:180px;border-radius:10px;overflow:hidden;' +
                    'box-shadow:0 4px 20px rgba(0,0,0,0.4);background:#111;';
            }
            box.innerHTML =
                '<div style="background:' + bp + ';color:#fff;' +
                'padding:4px 10px;font-size:11px;font-weight:700;' +
                'display:flex;justify-content:space-between;align-items:center;">' +
                '<span>🔒 ' + (cfg.brandname || 'LearnGuard') + '</span>' +
                '<span id="lg-cam-status" style="opacity:.7;font-size:9px;">●</span>' +
                '</div>' +
                '<video id="lg-webcam-video" autoplay muted playsinline ' +
                'style="width:100%;display:block;background:#111;"></video>';
            if (camSection) { camSection.appendChild(box); }
            else { document.body.appendChild(box); }
        }

        navigator.mediaDevices.getUserMedia({video: true, audio: false})
            .then(function(stream) {
                state.webcamStream = stream;
                var camEl = el('lg-webcam-video');
                if (camEl) { camEl.srcObject = stream; camEl.play().catch(function() {}); }
                startSnapshotTimer();
            })
            .catch(function(err) {
                if (logProctoringEvent('webcam_denied', {error: err.message || 'denied'}, 'high',
                        (cfg.scoreconfig && cfg.scoreconfig.webcamdenied) || 20)) {
                    showToast('⚠️ Webcam access denied — this has been logged.', 'danger');
                }
                // Still start the snapshot timer so periodic captures run even without a
                // webcam stream — captureAndUploadSnapshot will fall back to screen capture
                // or log a violation if neither source is available.
                startSnapshotTimer();
            });
    }

    var snapshotTimerStarted = false;
    function startSnapshotTimer() {
        // Guard: only one snapshot timer may run at a time.
        // initWebcam() is called from both launchAssessment() and
        // beginResumedAssessment(), so without this guard a
        // reinstatement-resume creates a second timer and doubles capture rate.
        if (snapshotTimerStarted) { return; }
        snapshotTimerStarted = true;
        var interval = cfg.snapinterval || 15000; // Default 15 seconds.
        // A cohort exam typically opens for everyone at the same scheduled instant, so
        // every student's browser would otherwise start this interval within the same
        // few seconds of each other — meaning every ~15s, the WHOLE cohort's webcam
        // uploads (a real file, not a small JSON ping) land on the server in the same
        // synchronised burst rather than spread out. A random one-time delay before the
        // FIRST tick shifts each student's whole 15s grid independently; the steady-
        // state cadence (every `interval` ms after that) is unchanged.
        setTimeout(function() {
            if (!state.proctoringActive) { return; }
            captureAndUploadSnapshot('periodic');
            state.snapshotTimer = setInterval(function() {
                captureAndUploadSnapshot('periodic');
            }, interval);
        }, Math.random() * interval);
        startRandomScreenshotTimer();
    }

    function startRandomScreenshotTimer() {
        var windowMs = (cfg.rndscreenshotinterval || 60) * 1000;
        if (windowMs <= 0) { return; }
        function scheduleNext() {
            var delay = Math.floor(windowMs * 0.3 + Math.random() * windowMs * 0.7);
            setTimeout(function() {
                if (!state.proctoringActive) { return; }
                // Prefer screen capture if the student shared their screen, else webcam
                if (state.screenStream &&
                    state.screenStream.getVideoTracks()[0] &&
                    state.screenStream.getVideoTracks()[0].readyState === 'live') {
                    captureScreenSnapshot('screen_capture');
                } else {
                    captureAndUploadSnapshot('random_screenshot');
                }
                scheduleNext();
            }, delay);
        }
        scheduleNext();
    }

    function captureAndUploadSnapshot(type) {
        if (!state.attemptid) { return; }

        // Fallback hierarchy: webcam → screen stream → log violation.
        var video = el('lg-webcam-video');

        // If the webcam stream is live but the video element has lost sync
        // (e.g. after a page resume), reconnect the srcObject so subsequent
        // ticks succeed.  This fixes the low webcam-capture count reported
        // when the periodic timer fires but readyState is still 0/1.
        if (state.webcamStream && video && video.readyState < 2) {
            var wt = state.webcamStream.getVideoTracks()[0];
            if (wt && wt.readyState === 'live') {
                if (video.srcObject !== state.webcamStream) {
                    video.srcObject = state.webcamStream;
                    video.play().catch(function() {});
                }
            }
        }

        if (!state.webcamStream || !video || video.readyState < 2) {
            // No working webcam — try screen capture instead.
            if (state.screenStream &&
                state.screenStream.getVideoTracks()[0] &&
                state.screenStream.getVideoTracks()[0].readyState === 'live') {
                captureScreenSnapshot(type || 'webcam_fallback_screen');
            }
            // Violation already logged by face-presence check (camera_unavailable every 30s).
            return;
        }

        var canvas = document.createElement('canvas');
        canvas.width = 320; canvas.height = 240;
        var ctx = canvas.getContext('2d');
        ctx.drawImage(video, 0, 0, 320, 240);

        // Blank/covered camera guard: if the frame is nearly all black, log a violation.
        var pxData = ctx.getImageData(0, 0, 320, 240).data;
        var totalBrightness = 0;
        for (var pi = 0; pi < pxData.length; pi += 4) {
            totalBrightness += (pxData[pi] + pxData[pi+1] + pxData[pi+2]) / 3;
        }
        var avgBrightness = totalBrightness / (pxData.length / 4);
        if (avgBrightness < 10) {
            if (logProctoringEvent('camera_covered', {brightness: Math.round(avgBrightness)}, 'high',
                    (cfg.scoreconfig && cfg.scoreconfig.cameracovered) || 20)) {
                showToast('⚠️ Camera appears covered or blocked!', 'danger', 6000);
            }
        }

        uploadSnapshot(canvas, type, false);
    }

    /* =======================================================================
     * RISK SCORE DISPLAY
     * ====================================================================== */
    var lgHalfWarnShown = false;
    var lgHalfWarnOpen  = false; // true while the mid-point warning modal is visible — pauses scoring

    function updateRiskDisplay() {
        var badge = el('lg-risk-badge');
        if (!badge) { return; }
        // "Low" risk shown red (not green) and the badge blinks at all risk
        // levels — a constant visible-monitoring reminder rather than a
        // "you're safe" green signal.
        if (!el('lg-risk-blink-style')) {
            var rbs = document.createElement('style');
            rbs.id = 'lg-risk-blink-style';
            rbs.textContent = '@keyframes lg-risk-blink{0%,100%{opacity:1;}50%{opacity:.35;}}' +
                '.lg-risk-blink{animation:lg-risk-blink 1s infinite;}';
            document.head.appendChild(rbs);
        }
        badge.classList.add('lg-risk-blink');
        var colours = {low: '#dc3545', medium: '#fd7e14', high: '#dc3545'};
        badge.style.background = colours[state.riskLevel] || '#6c757d';
        badge.textContent = '🛡 Risk: ' + state.riskScore + ' (' + state.riskLevel + ')';

        // 50% threshold warning — fires once. Skipped while fsBlocked — see the fsBlocked
        // comment on the periodic safety-net interval for why (avoids stacking on top of
        // the fullscreen blocker and hiding the way back into fullscreen).
        var threshold = (cfg.autosuspend_score > 0) ? cfg.autosuspend_score : 80;
        var half = Math.floor(threshold * 0.5);
        if (!lgHalfWarnShown && state.riskScore >= half && state.proctoringActive && !state.finished && !state.fsBlocked) {
            lgHalfWarnShown = true;
            lgShowHalfWarning(state.riskScore, threshold);
        }
    }

    function lgCheckAutoSuspend() {
        if (state.autoSuspendFired || state.suspended || state.finished || !state.proctoringActive || !state.attemptid) { return; }
        var suspendThreshold = (state.reinstateSuspendThreshold > 0)
            ? state.reinstateSuspendThreshold
            : cfg.autosuspend_score;
        if (suspendThreshold > 0 && state.riskScore >= suspendThreshold) {
            state.autoSuspendFired = true;
            state.suspended = true;
            lgFlushUnsavedAnswers(); // best-effort — last chance to sync before the session freezes
            callWS('mod_learnguard_auto_suspend', {
                attemptid: state.attemptid,
                reason: 'Risk score ' + state.riskScore + ' reached suspend threshold of ' + suspendThreshold,
            }).catch(function() {});
            showSuspendedScreen();
        }
    }

    function lgShowHalfWarning(current, threshold) {
        var existing = document.getElementById('lg-threshold-warning-modal');
        if (existing) { existing.remove(); }
        lgHalfWarnOpen = true; // freeze scoring while modal is visible
        var modal = document.createElement('div');
        modal.id = 'lg-threshold-warning-modal';
        modal.style.cssText = 'position:fixed;inset:0;z-index:9999999;background:rgba(0,0,0,0.87);' +
            'display:flex;align-items:center;justify-content:center;font-family:system-ui,sans-serif;';
        modal.innerHTML =
            '<div style="background:#fff;border-radius:14px;padding:32px 36px;max-width:480px;width:92%;' +
            'text-align:center;box-shadow:0 16px 48px rgba(0,0,0,0.5);border-top:6px solid #fd7e14;">' +
            '<div style="font-size:52px;margin-bottom:12px;">⚠️</div>' +
            '<h2 style="font-size:20px;font-weight:800;color:#dc3545;margin-bottom:10px;">Integrity Warning</h2>' +
            '<p style="font-size:15px;color:#495057;margin-bottom:8px;">' +
            'Your integrity risk score is now <strong style="color:#fd7e14;">' + current + ' points</strong>. ' +
            'You have passed the <strong>halfway mark</strong> toward the suspension threshold of <strong>' + threshold + ' points</strong>.</p>' +
            '<p style="font-size:14px;color:#6c757d;margin-bottom:20px;">' +
            'If your score reaches <strong>' + threshold + ' points</strong>, your exam will be ' +
            '<strong style="color:#dc3545;">automatically and permanently suspended</strong> and cannot be reversed.</p>' +
            '<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:12px 16px;' +
            'text-align:left;font-size:13px;margin-bottom:20px;">' +
            '<strong>To avoid suspension:</strong><br>' +
            '• Stay in fullscreen mode<br>• Keep your face visible<br>' +
            '• Do not switch tabs or applications<br>• Stay quiet and alone</div>' +
            '<button id="lg-threshold-dismiss" style="background:#0f6cbf;color:#fff;border:none;' +
            'border-radius:8px;padding:12px 32px;font-size:15px;font-weight:700;cursor:pointer;width:100%;">' +
            'I Understand — Continue Exam <span id="lg-threshold-countdown">(10)</span></button></div>';
        document.body.appendChild(modal);

        function dismissHalfWarning() {
            lgHalfWarnOpen = false; // resume scoring on dismiss
            clearInterval(thresholdCountdownTimer);
            modal.remove();
        }

        // Auto-dismisses after 10s if the student doesn't click — this is a one-time
        // informational warning, not a decision the student needs to make, so it must
        // not be able to sit open indefinitely (scoring is frozen while it's open).
        var thresholdSecondsLeft = 10;
        var thresholdCountdownEl = document.getElementById('lg-threshold-countdown');
        var thresholdCountdownTimer = setInterval(function() {
            thresholdSecondsLeft--;
            if (thresholdCountdownEl) { thresholdCountdownEl.textContent = '(' + thresholdSecondsLeft + ')'; }
            if (thresholdSecondsLeft <= 0) { clearInterval(thresholdCountdownTimer); dismissHalfWarning(); }
        }, 1000);

        document.getElementById('lg-threshold-dismiss').addEventListener('click', dismissHalfWarning);
    }

    /* =======================================================================
     * TIMER
     * ====================================================================== */
    function initTimer() {
        var timerEl = el('lg-timer');
        if (!timerEl) { return; }

        // ── FIX: sanitize timelimit ────────────────────────────────────────
        // A Unix timestamp accidentally stored as timelimit causes absurd hours.
        // Max valid exam = 10 hours (36000s). Anything larger = treat as unlimited.
        if (state.timelimit > 36000) {
            state.timelimit = 0;
        }
        // Also ensure timestart is a recent unix timestamp (not 0 or garbage).
        var nowSec = Math.floor(Date.now() / 1000);
        if (state.timestart < 1000000000 || state.timestart > nowSec + 60) {
            state.timestart = nowSec;
        }

        function pad(n) { return n < 10 ? '0' + n : String(n); }

        var warned5min = false;
        var warned1min = false;

        state.countdownTimer = setInterval(function() {
            // Freeze the timer during suspension, approved break, screen-share-pause,
            // check-in modal, or the fullscreen blocker. Fullscreen previously did NOT
            // freeze the timer ("so learners cannot gain extra time by exiting
            // fullscreen") — but a student who genuinely cannot get back into
            // fullscreen (browser/OS quirk, not gaming) had zero protection and could
            // lose their entire remaining time trapped behind this exact screen,
            // reported live. Gaming is still deterred independently: every fullscreen
            // exit is scored as a violation (see initFullscreen()'s fullscreenchange
            // handler) regardless of whether the timer pauses, so repeatedly exiting to
            // "steal" paused time still pushes the student toward auto-suspension.
            if (state.screenSharePaused || state.suspended || state.onBreak || state.checkInOpen || state.fsBlocked) { state.timestart++; return; }
            var elapsed = Math.floor(Date.now() / 1000) - state.timestart;

            // Format helper: always show mm:ss, show h:mm:ss when ≥1 hour
            function fmt(secs) {
                var h = Math.floor(secs / 3600);
                var m = Math.floor((secs % 3600) / 60);
                var s = secs % 60;
                return (h > 0 ? h + ':' + pad(m) : pad(m)) + ':' + pad(s);
            }

            if (state.timelimit > 0) {
                // ── ELAPSED / TOTAL MODE ────────────────────────────
                var effectiveLimit = state.timelimit + (state.extratime || 0);
                var remaining = effectiveLimit - elapsed;
                if (remaining <= 0) {
                    clearInterval(state.countdownTimer);
                    timerEl.innerHTML = fmt(effectiveLimit) + '<br><span style="font-size:11px;opacity:.8;">/ ' + fmt(effectiveLimit) + '</span>';
                    timerEl.style.background = 'rgba(220,53,69,0.8)';
                    autoFinishAttempt();
                    return;
                }
                // Show: "used / total" — e.g. 05:30 / 60:00
                timerEl.innerHTML = fmt(elapsed) +
                    '<span style="font-size:13px;font-weight:500;opacity:.75;"> / ' + fmt(effectiveLimit) + '</span>';

                // Colour urgency based on remaining.
                if (remaining <= 60) {
                    timerEl.style.background = 'rgba(220,53,69,0.85)';
                    timerEl.style.fontWeight = '900';
                } else if (remaining <= 300) {
                    timerEl.style.background = 'rgba(253,126,20,0.8)';
                    timerEl.style.fontWeight = '800';
                } else {
                    timerEl.style.background = 'rgba(0,0,0,0.2)';
                }
                // Urgency toasts (once only).
                if (!warned5min && remaining <= 300 && remaining > 60) {
                    warned5min = true;
                    showToast('⏱ 5 minutes remaining!', 'warning', 6000);
                }
                if (!warned1min && remaining <= 60) {
                    warned1min = true;
                    showToast('⏱ 1 minute remaining!', 'danger', 8000);
                }
            } else {
                // ── ELAPSED ONLY (no time limit) ────────────────────
                timerEl.innerHTML = fmt(elapsed) +
                    '<span style="font-size:13px;font-weight:500;opacity:.75;"> / ∞</span>';
            }
        }, 1000);
        // Show initial value immediately.
        timerEl.textContent = '00:00';
    }

    function autoFinishAttempt() {
        if (state.finished) { return; }
        // Cancel any pending debounce — the explicit save below covers the current answer.
        if (answerDebounce) { clearTimeout(answerDebounce); answerDebounce = null; }
        showToast('⏱ Time is up! Saving your answer and submitting…', 'warning');

        // Reopened-for-review: nothing has been saved to the server yet except via
        // submitCurrentAnswer() on navigation — flush the CURRENT question (never
        // navigated away from, so never cached) plus every other locally-held edit,
        // not just the current slot, or earlier answers from this session would be
        // lost when the granted review time runs out.
        if (state.reopenedForReview) {
            state.localAnswers[state.currentslot] = collectCurrentFormData();
            flushReviewAnswers(function() { finishAttempt(); });
            return;
        }

        // Save the current question's response before finishing so no typed
        // answer is ever lost when the timer expires or the schedule window closes.
        callWS('mod_learnguard_submit_answer', {
            attemptid:    state.attemptid,
            slot:         state.currentslot,
            responsedata: collectCurrentFormData(),
            targetslot:   0,
        }).then(function() {
            finishAttempt();
        }).catch(function() {
            // Even if the save call fails, still finish the attempt.
            finishAttempt();
        });
    }

    // Live-attempt countdown to the learner's scheduled "timeclose" (if any).
    // This is independent of the exam time-limit timer (#lg-timer) — it
    // represents the per-learner scheduling window set via schedule.php.
    function startScheduleCountdown() {
        var badge = el('lg-schedule-timer');
        // Reopened-for-review: cfg.timeclose is the ORIGINAL activity/personal window,
        // which has normally already passed by the time a review is granted (that's
        // the whole reason review is needed) — the review's own timer (extra_time,
        // driven by initTimer()) is what actually governs this session's deadline.
        // Without this guard, the very first tick below saw a deeply-negative
        // "remaining" and immediately auto-submitted the review attempt before the
        // learner could answer or change anything.
        if (state.reopenedForReview) { return; }
        if (!badge || !cfg.timeclose || cfg.timeclose <= 0) { return; }

        var warned5min = false;
        var warned1min = false;

        state.scheduleTimer = setInterval(function() {
            if (state.finished) { return; }

            var remaining = cfg.timeclose - nowTs();
            if (remaining <= 0) {
                clearInterval(state.scheduleTimer);
                badge.textContent = '⏳ Window closes in: 00:00:00';
                badge.style.background = 'rgba(220,53,69,0.85)';
                showToast('⏱ Your scheduled time has ended. Submitting your assessment…', 'warning');
                autoFinishAttempt();
                return;
            }

            badge.textContent = '⏳ Window closes in: ' + formatCountdown(remaining);

            if (remaining <= 60) {
                badge.style.background = 'rgba(220,53,69,0.85)';
                badge.style.fontWeight = '900';
            } else if (remaining <= 300) {
                badge.style.background = 'rgba(253,126,20,0.8)';
                badge.style.fontWeight = '800';
            } else {
                badge.style.background = 'rgba(0,0,0,0.2)';
            }

            if (!warned5min && remaining <= 300 && remaining > 60) {
                warned5min = true;
                showToast('⏱ Your scheduled window closes in 5 minutes!', 'warning', 6000);
            }
            if (!warned1min && remaining <= 60) {
                warned1min = true;
                showToast('⏱ Your scheduled window closes in 1 minute!', 'danger', 8000);
            }
        }, 1000);
    }

    /* =======================================================================
     * POLLING & AUTOSAVE
     * ====================================================================== */
    function startPolling() {
        state.pollTimer = setInterval(function() {
            if (!state.attemptid || state.finished) { return; }
            callWS('mod_learnguard_get_messages', {
                attemptid: state.attemptid,
                since: state.lastMessageTime,
            }).then(function(data) {
                state.lastMessageTime = Math.floor(Date.now() / 1000);
                // Real reachability to OUR server (this poll succeeded), distinct from
                // navigator.onLine which only reflects the device having SOME network —
                // that can stay "online" for the whole duration of a server-side outage.
                // Deliberately backend/proctor-facing only (see lgUpdateConnectionBadge())
                // — logged here as a low-severity, 0-score event purely for visibility on
                // attemptdetail.php's Proctoring Events table, not shown to the student.
                if (!state.serverReachable && state.serverUnreachableSince) {
                    var downForSec = Math.round((Date.now() - state.serverUnreachableSince) / 1000);
                    logProctoringEvent('connection_server_reachable', {down_for_seconds: downForSec}, 'low', 0);
                }
                state.serverReachable = true;
                state.serverUnreachableSince = 0;
                lgUpdateConnectionBadge();
                if (data.paused && !state.paused) {
                    state.paused = true;
                    showPausedScreen();
                } else if (!data.paused && state.paused) {
                    state.paused = false;
                    hidePausedScreen();
                }
                if (data.suspended && !state.suspended) {
                    state.suspended = true;
                    state.confirmedSuspension = !!data.is_flagged;
                    lgFlushUnsavedAnswers(); // best-effort — last chance to sync before the session freezes
                    showSuspendedScreen();
                }
                state.riskScore = data.risk_score;
                state.riskLevel = data.risk_level;
                updateRiskDisplay();

                // Phase 3: break approval notification.
                if (data.break_approved && data.break_approved > 0 && !breakOverlay) {
                    var bkDeadline = parseInt(data.break_deadline, 10) || 0;
                    var bkDuration = parseInt(data.break_duration, 10) || 0;
                    showBreakOverlay(bkDeadline, bkDuration);
                }

                // Phase 3: extra time granted — update extratime, not the base timelimit.
                if (data.extra_time && data.extra_time > 0 && state.timelimit > 0) {
                    var newExtra = parseInt(data.extra_time, 10);
                    if (newExtra !== (state.extratime || 0)) {
                        state.extratime = newExtra;
                        showToast('⏱ Extra time granted! Timer updated.', 'success', 5000);
                    }
                }

                // Phase 3: incoming chat messages from proctor.
                if (data.messages && data.messages.length) {
                    data.messages.forEach(function(m) {
                        appendChatMessage('Proctor', m.message, false);
                        if (!chatBtnVisible) {
                            chatUnread++;
                            updateChatBadge();
                            showToast('💬 Proctor: ' + m.message.substring(0, 60), 'info', 6000);
                        }
                    });
                }
            }).catch(function() {
                if (state.serverReachable) {
                    state.serverReachable = false;
                    state.serverUnreachableSince = Date.now();
                }
                lgUpdateConnectionBadge();
            });
        }, 15000);
    }

    function startAutosave() {
        // Reopened-for-review: no periodic server saves — see submitCurrentAnswer()'s
        // reopenedForReview branch and flushReviewAnswers() for why and where saving
        // actually happens for this mode (once, on final submit).
        if (state.reopenedForReview) { return; }
        var lastAutoSaveSlot = 0;
        var lastAutoSaveData = '';
        state.autosaveTimer = setInterval(function() {
            if (state.finished || !state.attemptid) { return; }
            var formData = collectCurrentFormData();
            if (!formData.length) { return; }
            // Only send if slot or content has actually changed since last save.
            var fingerprint = state.currentslot + '|' + JSON.stringify(formData);
            if (fingerprint === lastAutoSaveData) { return; }
            lastAutoSaveData = fingerprint;
            lastAutoSaveSlot = state.currentslot;
            var autoSaveSlot = state.currentslot;
            callWS('mod_learnguard_autosave', {
                attemptid:    state.attemptid,
                slot:         autoSaveSlot,
                responsedata: formData,
            }).catch(function() {
                // A failed periodic autosave used to be silently dropped with no retry at
                // all — worse than submitCurrentAnswer()'s own failure path, which already
                // queues into this same background flush. Reusing it here means a long
                // Essay/Choice Essay response being composed over many minutes (autosave
                // ticks every 5s while typing) can never silently lose an in-progress
                // answer to a transient save failure — it just keeps retrying quietly
                // every 12s until it succeeds, exactly like an explicit Save & Next would.
                state.unsavedAnswers[autoSaveSlot] = formData;
                startUnsavedAnswerFlush();
            });
        }, 5000);
    }

    function collectCurrentFormData() {
        var container = el('lg-question-container');
        if (!container) { return []; }
        var data = [];
        container.querySelectorAll('input, select, textarea').forEach(function(inp) {
            if (!inp.name) { return; }
            if ((inp.type === 'checkbox' || inp.type === 'radio') && !inp.checked) { return; }
            data.push({name: inp.name, value: inp.value || ''});
        });
        return data;
    }

    /* =======================================================================
     * PROCTORING EVENT LOGGER
     * ====================================================================== */
    var eventMeta = {
        tab_switch:      {severity: 'medium', score: 10},
        fullscreen_exit: {severity: 'medium', score: 15},
        face_missing:    {severity: 'high',   score: 10},
        face_multiple:   {severity: 'high',   score: 25},
        clipboard_copy:  {severity: 'low',    score: 5},
        clipboard_paste: {severity: 'low',    score: 5},
        clipboard_cut:   {severity: 'low',    score: 5},
        key_violation:   {severity: 'low',    score: 0},
        webcam_denied:   {severity: 'high',   score: 20},
    };

    var flaggedLog = [];   // local list of flagged items shown to candidate

    // Violation category → event type mapping used by exclude_violations override.
    var EXCL_CAT = {
        camera:   ['webcam_denied', 'camera_unavailable', 'camera_covered'],
        face:     ['face_missing', 'multiple_faces_detected', 'face_identity_mismatch',
                   'no_blink_5min', 'gaze_away', 'head_down', 'poor_lighting'],
        audio:    ['audio_clip_started', 'audio_detected'],
        screen:   ['screen_share_attempt', 'screen_share_stopped'],
        objects:  ['multiple_persons_detected'],   // object_detected_* matched by prefix below
        tab:      ['tab_switch', 'fullscreen_exit', 'page_unload', 'multi_monitor'],
        keyboard: ['key_violation', 'clipboard_copy', 'clipboard_paste', 'clipboard_cut',
                   'screenshot_attempt'],
    };

    function isEventExcluded(eventtype) {
        var excl = cfg.exclude_violations;
        if (!excl || !excl.length) { return false; }
        for (var i = 0; i < excl.length; i++) {
            var cat = excl[i];
            // object_detected_* events: prefix match under the 'objects' category.
            if (cat === 'objects' && eventtype.indexOf('object_detected_') === 0) { return true; }
            var evts = EXCL_CAT[cat] || [];
            if (evts.indexOf(eventtype) !== -1) { return true; }
        }
        return false;
    }

    function logProctoringEvent(eventtype, data, severity, score) {
        // Never log events while suspended, finished, or while the halfway-warning or
        // check-in modal is open (prevents score accumulation in those states).
        if (!state.attemptid || state.suspended || state.finished || lgHalfWarnOpen || state.checkInOpen) { return; }
        // fsBlocked/screenSharePaused also blanket-suppress events — intentional for
        // passive detections (face_missing, gaze_away, etc.) so a student already stuck
        // on the fullscreen/screen-share prompt doesn't ALSO rack up unrelated points —
        // but tab_switch is a distinct, deliberate action, not a passive detection, and
        // was being silently swallowed by this same guard when a student switched tabs
        // WHILE also out of fullscreen, even though that is exactly the "using the
        // blocker as cover to look something up" scenario proctoring exists to catch.
        if (eventtype !== 'tab_switch' && (state.fsBlocked || state.screenSharePaused)) { return; }
        // Per-user violation category exclusions set by admin in Assessment Manager.
        if (isEventExcluded(eventtype)) { return; }
        var meta    = eventMeta[eventtype] || {};
        var sev     = severity || meta.severity || 'low';
        var impact  = (score !== undefined) ? score : (meta.score || 0);

        // Tag this event if it's landing shortly after a real WS failure (question load,
        // answer save, or anything else) — a proctor reviewing the risk score later can
        // then see it may have been the STUDENT recovering (retrying a stuck save,
        // checking their connection) rather than a genuine violation, without this
        // changing the score itself. Directly addresses a formal complaint where
        // Tab Switch/Fullscreen Exit flags landed in the exact window of reported
        // repeated timeouts.
        if (state.lastWsFailureTime && (Date.now() - state.lastWsFailureTime) < 30000) {
            data = data || {};
            data.possible_connection_issue = true;
        }

        // Add to local flagged log so candidate can see what's been flagged.
        if (sev !== 'low' || impact > 0) {
            var readableLabels = {
                'tab_switch':          'Tab / window switch detected',
                'fullscreen_exit':     'Fullscreen exited',
                'face_missing':        'Face not visible on webcam',
                'key_violation':       'Blocked key pressed: ' + (data && data.key ? data.key : ''),
                'clipboard_copy':      'Copy attempt blocked',
                'clipboard_paste':     'Paste attempt blocked',
                'phone_detected':      'Mobile phone detected',
                'object_detected':     'Object detected on webcam',
                'multiple_persons_detected': 'Multiple people in frame',
                'multiple_faces_detected':   'Multiple faces detected on webcam',
                'background_change':   'Background change detected',
                'camera_covered':      'Camera appears covered',
                'printscreen':         'PrintScreen key blocked',
                'audio_clip_started':  'Loud noise detected — audio clip recorded',
                'face_mismatch':       'Face identity mismatch',
                'vpn_detected':        'VPN / proxy detected',
                'devtools_detected':   'Developer tools opened',
            };
            var label = readableLabels[eventtype] || eventtype.replace(/_/g, ' ');
            flaggedLog.push({
                time:     new Date().toLocaleTimeString(),
                label:    label,
                severity: sev,
                score:    impact,
            });
            updateFlaggedPanel();
            // Float "+Xpts" badge near the risk badge so it's always visible.
            if (impact > 0) {
                var riskBadge = document.getElementById('lg-risk-badge');
                if (riskBadge) {
                    if (!document.getElementById('lg-pts-float-style')) {
                        var sty = document.createElement('style');
                        sty.id = 'lg-pts-float-style';
                        sty.textContent = '@keyframes lgPtsFloat{0%{opacity:1;transform:translateY(0) scale(1);}' +
                            '60%{opacity:1;transform:translateY(-22px) scale(1.1);}' +
                            '100%{opacity:0;transform:translateY(-38px) scale(0.9);}}' +
                            '.lg-pts-float{position:absolute;font-size:13px;font-weight:800;' +
                            'color:#fff;background:#dc3545;border-radius:20px;padding:2px 9px;' +
                            'pointer-events:none;white-space:nowrap;z-index:9999998;' +
                            'animation:lgPtsFloat 1.8s ease forwards;}';
                        document.head.appendChild(sty);
                    }
                    var rect = riskBadge.getBoundingClientRect();
                    var ptsEl = document.createElement('span');
                    ptsEl.className = 'lg-pts-float';
                    ptsEl.textContent = '+' + impact + ' pts';
                    ptsEl.style.left = rect.left + 'px';
                    ptsEl.style.top  = (rect.top + window.scrollY - 4) + 'px';
                    document.body.appendChild(ptsEl);
                    setTimeout(function() { if (ptsEl.parentNode) { ptsEl.parentNode.removeChild(ptsEl); } }, 1900);
                }
            }
        }

        // Auto-capture evidence for every scored violation (score > 0), plus a small
        // allowlist of unscored-but-reviewable event types where the admin still wants
        // photographic evidence to verify the flag (gaze_away is informational/0-score
        // by design, but still shown in the proctoring events table).
        var evidenceEvenIfUnscored = {gaze_away: true};
        if ((impact > 0 || evidenceEvenIfUnscored[eventtype]) && state.proctoringActive && !state.finished) {
            var evTag = 'violation_' + eventtype;
            captureAndUploadSnapshot(evTag);      // webcam photo (falls back to screen if no webcam)
            captureScreenSnapshot(evTag);         // screen capture (separate shot)
            // Audio clips are reserved EXCLUSIVELY for genuine noise-threshold crossings
            // (the interval loop in initAudioClipRecording). Recording one here too — as
            // "bonus" evidence for an unrelated violation with no real noise level to
            // report — always saved with noise_level=0, which flooded the Audio Clips /
            // Noise Triggered gallery with silent, unrelated recordings even after the
            // fake-scoring side of this bug was fixed. Webcam/screen evidence above is
            // unaffected — this only removes the audio portion of that "bonus" capture.
        }

        callWS('mod_learnguard_log_event', {
            attemptid: state.attemptid, eventtype: eventtype,
            eventdata: JSON.stringify(data || {}), severity: sev, scoreimpact: impact,
        }).then(function(resp) {
            var prevRiskScore = state.riskScore;
            state.riskScore = resp.risk_score;
            state.riskLevel = resp.risk_level;
            // Apply any live admin-side cfg changes (autosuspend_score, scoreconfig)
            // so current attempts pick up new settings without a page reload.
            if (resp.autosuspend_score) { cfg.autosuspend_score = resp.autosuspend_score; }
            if (resp.scoreconfig) {
                try { cfg.scoreconfig = JSON.parse(resp.scoreconfig); } catch (e) {}
            }
            updateRiskDisplay();
            // Server just enforced the suspend threshold itself (attempt_manager::log_event()
            // — a backstop independent of this client's own auto-suspend check below, which
            // a student with DevTools open could tamper with or block). React immediately
            // instead of waiting up to 15s for the next get_messages poll to notice.
            if (resp.suspended && !state.suspended) {
                state.autoSuspendFired = true;
                state.suspended = true;
                lgFlushUnsavedAnswers();
                showSuspendedScreen();
                return;
            }
            // Auto-suspend: fire once when score meets or exceeds the threshold.
            // Use !state.autoSuspendFired flag (not a crossing check) so it fires
            // even if events were blocked (by halfwarn/checkIn) while score crossed.
            // After reinstatement use the higher post-reinstate threshold instead.
            lgCheckAutoSuspend();

            // Check-in pop-up: track scored face_missing / audio_clip_started violations.
            // Skipped while fsBlocked — its overlay renders at a higher z-index than the
            // fullscreen blocker and would otherwise hide the "Return to Fullscreen" button,
            // leaving the student stuck outside fullscreen with no visible way back in.
            var checkInThreshold = (cfg.checkin_violation_threshold > 0) ? cfg.checkin_violation_threshold : 0;
            var checkInEvents    = ['face_missing', 'audio_clip_started'];
            if (checkInThreshold > 0 && impact > 0 && !state.checkInDisabled && !state.checkInOpen &&
                !state.suspended && !state.finished && !state.fsBlocked && checkInEvents.indexOf(eventtype) !== -1) {
                state.checkInViolationCount++;
                if (state.checkInViolationCount >= checkInThreshold) {
                    state.checkInViolationCount = 0; // reset counter for the next cycle
                    showCheckInModal();
                }
            }
        }).catch(function() {});
        return true;
    }

    function showCheckInModal() {
        if (state.checkInOpen || !state.attemptid) { return; }
        state.checkInOpen = true;

        callWS('mod_learnguard_log_event', {
            attemptid: state.attemptid, eventtype: 'checkin_prompted',
            eventdata: JSON.stringify({count: cfg.checkin_violation_threshold || 5}),
            severity: 'low', scoreimpact: 0,
        }).catch(function() {});

        var overlay = document.createElement('div');
        overlay.id  = 'lg-checkin-overlay';
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.65);' +
            'display:flex;align-items:center;justify-content:center;z-index:9999995;' +
            'font-family:system-ui,sans-serif;';
        overlay.innerHTML =
            '<div style="background:#fff;border-radius:16px;padding:36px 40px;max-width:480px;width:90%;' +
            'box-shadow:0 8px 48px rgba(0,0,0,0.4);border-top:6px solid #fd7e14;text-align:center;">' +
            '<div style="font-size:56px;margin-bottom:12px;">🔔</div>' +
            '<h3 style="color:#fd7e14;margin:0 0 10px;font-size:21px;">Check-in Required</h3>' +
            '<p style="color:#495057;margin:0 0 8px;font-size:14px;line-height:1.6;">' +
            'We have detected repeated issues with your camera or background noise. ' +
            'Your exam timer has been paused.</p>' +
            '<p style="color:#495057;margin:0 0 24px;font-size:13px;">' +
            'Please ensure your face is clearly visible and your environment is quiet before continuing.</p>' +
            '<div style="display:flex;flex-direction:column;gap:10px;">' +
            '<button id="lg-ci-continue" style="background:#28a745;color:#fff;border:none;' +
            'border-radius:8px;padding:12px 20px;font-size:15px;font-weight:700;cursor:pointer;">' +
            '▶ Continue Assessment <span id="lg-ci-countdown">(5)</span></button>' +
            '<button id="lg-ci-dismiss" style="background:#f8f9fa;color:#6c757d;' +
            'border:1px solid #dee2e6;border-radius:8px;padding:10px 20px;font-size:13px;cursor:pointer;">' +
            '🚫 Don\'t Show Again This Session</button>' +
            '</div></div>';
        document.body.appendChild(overlay);

        function closeModal() {
            state.checkInOpen = false;
            clearInterval(ciCountdownTimer);
            if (overlay.parentNode) { overlay.parentNode.removeChild(overlay); }
        }

        function doContinue() {
            callWS('mod_learnguard_log_event', {
                attemptid: state.attemptid, eventtype: 'checkin_continued',
                eventdata: '{}', severity: 'low', scoreimpact: 0,
            }).catch(function() {});
            showToast('▶ Assessment resumed. Ensure your face is visible and surroundings are quiet.', 'success', 5000);
            closeModal();
        }

        // Auto-resumes on its own after 5s if the student doesn't click anything — this
        // pop-up is an informational nudge (unlike the auto-suspend flow, nothing here
        // requires an actual decision), so leaving it stuck open indefinitely would just
        // cost exam time for no reason if the student stepped away or missed it.
        var ciSecondsLeft = 5;
        var ciCountdownEl = document.getElementById('lg-ci-countdown');
        var ciCountdownTimer = setInterval(function() {
            ciSecondsLeft--;
            if (ciCountdownEl) { ciCountdownEl.textContent = '(' + ciSecondsLeft + ')'; }
            if (ciSecondsLeft <= 0) { clearInterval(ciCountdownTimer); doContinue(); }
        }, 1000);

        document.getElementById('lg-ci-continue').addEventListener('click', doContinue);

        document.getElementById('lg-ci-dismiss').addEventListener('click', function() {
            state.checkInDisabled = true;
            clearInterval(ciCountdownTimer);
            callWS('mod_learnguard_log_event', {
                attemptid: state.attemptid, eventtype: 'checkin_dismissed',
                eventdata: '{}', severity: 'low', scoreimpact: 0,
            }).catch(function() {});
            showToast('🚫 Check-in pop-up disabled for this session.', 'info', 4000);
            closeModal();
        });
    }

    function updateFlaggedPanel() {
        var panel = document.getElementById('lg-flagged-panel');
        if (!panel) { return; }
        var badge = document.getElementById('lg-flagged-count');
        if (badge) { badge.textContent = flaggedLog.length; badge.style.display = 'inline-block'; }
        var list  = document.getElementById('lg-flagged-list');
        if (!list) { return; }
        list.innerHTML = '';
        var last = flaggedLog.slice(-10).reverse();
        last.forEach(function(item) {
            var col = item.severity === 'high' ? '#dc3545' : item.severity === 'medium' ? '#fd7e14' : '#6c757d';
            var row = document.createElement('div');
            row.style.cssText = 'display:flex;gap:8px;align-items:flex-start;padding:6px 0;border-bottom:1px solid #f0f0f0;';
            row.innerHTML = '<span style="font-size:10px;color:#6c757d;white-space:nowrap;">' + item.time + '</span>' +
                '<span style="flex:1;font-size:12px;color:#212529;">' + item.label + '</span>' +
                (item.score > 0 ? '<span style="font-size:11px;font-weight:700;color:' + col + ';">+' + item.score + 'pts</span>' : '');
            list.appendChild(row);
        });
    }

    /* =======================================================================
     * TOAST
     * ====================================================================== */
    var toastContainer = null;
    // Tracks currently-visible toasts by message text so a repeated failure (e.g. a
    // student stuck retrying a save that keeps failing) shows/refreshes ONE toast with a
    // counter instead of stacking a new identical box every click — a wall of duplicate
    // red "Error saving answer" toasts can cover most of the exam screen and makes an
    // already-stressful moment worse instead of just informing the student once.
    var activeToastsByMsg = {};
    function showToast(message, type, duration) {
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.style.cssText = 'position:fixed;top:16px;right:16px;z-index:9998;' +
                'display:flex;flex-direction:column;gap:8px;max-width:360px;';
            document.body.appendChild(toastContainer);
        }
        var delay = typeof duration === 'number' ? duration : 4000;
        var existing = activeToastsByMsg[message];
        if (existing && existing.el.parentNode) {
            existing.count++;
            existing.el.textContent = message + ' (×' + existing.count + ')';
            clearTimeout(existing.timer);
            existing.timer = setTimeout(function() { dismissToast(message); }, delay);
            return;
        }
        var colours = {info: '#0d6efd', success: '#198754', warning: '#fd7e14', danger: '#dc3545'};
        var toast = document.createElement('div');
        toast.style.cssText = 'background:' + (colours[type] || colours.info) +
            ';color:#fff;padding:10px 16px;border-radius:6px;font-size:14px;' +
            'font-family:system-ui,sans-serif;box-shadow:0 4px 12px rgba(0,0,0,0.3);transition:opacity 0.3s;';
        toast.textContent = message;
        toastContainer.appendChild(toast);
        var timer = setTimeout(function() { dismissToast(message); }, delay);
        activeToastsByMsg[message] = {el: toast, timer: timer, count: 1};
    }
    function dismissToast(message) {
        var entry = activeToastsByMsg[message];
        if (!entry) { return; }
        delete activeToastsByMsg[message];
        entry.el.style.opacity = '0';
        setTimeout(function() { if (entry.el.parentNode) { entry.el.parentNode.removeChild(entry.el); } }, 300);
    }

    /* =======================================================================
     * SECTION DIVIDERS + PER-SECTION TIMERS
     * ====================================================================== */

    // Build state.sections (one entry per pool, ordered by sortorder) and
    // state.navSectionMap (slot → section index) from cfg.sections + state.navigation.
    // Called after state.navigation is populated.
    function buildSections() {
        state.sections       = [];
        state.navSectionMap  = {};
        state.sectionTimers  = {};
        // Section breaks disabled by admin, or no pool banks configured.
        if (!cfg.show_section_breaks || !cfg.sections || cfg.sections.length === 0) { return; }

        // Map catid → section config from the server-supplied pool list.
        var cfgByOrder = cfg.sections.slice().sort(function(a, b) { return a.sortorder - b.sortorder; });
        cfgByOrder.forEach(function(sec, idx) {
            state.sections.push({
                catid:      sec.catid,
                name:       sec.name || ('Section ' + (idx + 1)),
                drawcount:  sec.drawcount,
                totalinbank:sec.totalinbank,
                timelimit:  sec.timelimit || 0,
                sortorder:  sec.sortorder,
                slots:      [],
                visited:    false,
            });
        });

        // Assign each slot to the section whose catid matches navigation catname/catid.
        state.navigation.forEach(function(n) {
            var assigned = false;
            for (var i = 0; i < state.sections.length; i++) {
                if ((int(n.catid) > 0 && int(n.catid) === state.sections[i].catid) ||
                    (n.catname && n.catname === state.sections[i].name)) {
                    state.sections[i].slots.push(n.slot);
                    state.navSectionMap[n.slot] = i;
                    assigned = true;
                    break;
                }
            }
            if (!assigned) {
                // Fixed slots or unmatched — put in a catch-all "General" section at index 0.
                if (!state.navSectionMap[n.slot] && state.navSectionMap[n.slot] !== 0) {
                    state.navSectionMap[n.slot] = -1;
                }
            }
        });

        // Only activate section UI when there are 2+ distinct pools.
        if (state.sections.length < 2) {
            state.sections      = [];
            state.navSectionMap = {};
        }
    }

    function int(v) { return parseInt(v, 10) || 0; }

    function getSectionIndex(slot) {
        if (!state.navSectionMap) { return -1; }
        var idx = state.navSectionMap[slot];
        return (idx !== undefined && idx >= 0) ? idx : -1;
    }

    // Show a full-screen section intro overlay. Calls callback() when student clicks Begin.
    function showSectionIntro(sectionIdx, callback) {
        var sec = (state.sections && state.sections[sectionIdx]) || null;
        if (!sec) { if (callback) { callback(); } return; }

        var overlay = document.createElement('div');
        overlay.id  = 'lg-section-intro-overlay';
        overlay.style.cssText =
            'position:fixed;inset:0;z-index:99990;display:flex;align-items:center;justify-content:center;' +
            'background:rgba(0,0,0,0.85);';

        var bp  = cfg.brandprimary  || '#0f6cbf';
        var bac = cfg.brandaccent   || '#f0a500';
        var timerLine = sec.timelimit > 0
            ? '<p style="font-size:15px;color:#ffe8b0;margin:0 0 8px;">⏱ Time for this section: ' +
              '<strong>' + Math.round(sec.timelimit / 60) + ' minutes</strong></p>'
            : '';

        overlay.innerHTML =
            '<div style="background:#1a1a2e;border:2px solid ' + bp + ';border-radius:16px;' +
            'padding:40px 48px;max-width:480px;width:90%;text-align:center;">' +
            '<div style="font-size:36px;margin-bottom:12px;">📂</div>' +
            '<div style="font-size:11px;font-weight:700;letter-spacing:2px;text-transform:uppercase;' +
            'color:#aaa;margin-bottom:6px;">Section ' + (sectionIdx + 1) + ' of ' + state.sections.length + '</div>' +
            '<h2 style="font-size:26px;font-weight:900;color:#fff;margin:0 0 16px;">' +
            escHtml(sec.name) + '</h2>' +
            '<p style="font-size:15px;color:#ccc;margin:0 0 8px;">' +
            '<strong style="color:#fff;">' + sec.slots.length + '</strong> question' +
            (sec.slots.length !== 1 ? 's' : '') +
            (sec.totalinbank > sec.drawcount
                ? ' drawn randomly from a bank of <strong style="color:#fff;">' + sec.totalinbank + '</strong>'
                : '') + '</p>' +
            timerLine +
            '<p style="font-size:13px;color:#888;margin:0 0 28px;">Once you begin this section the timer starts.</p>' +
            '<button id="lg-section-begin-btn" style="background:' + bp + ';color:#fff;border:none;' +
            'border-radius:8px;padding:14px 36px;font-size:17px;font-weight:700;cursor:pointer;' +
            'transition:opacity .2s;">▶ Begin Section</button>' +
            '</div>';

        document.body.appendChild(overlay);

        overlay.querySelector('#lg-section-begin-btn').addEventListener('click', function() {
            sec.visited = true;
            overlay.remove();
            if (sec.timelimit > 0 && sec.catid > 0) {
                // Tell the server the section has started; use its authoritative deadline.
                callWS('mod_learnguard_begin_section', {attemptid: state.attemptid, catid: sec.catid})
                    .then(function(d) {
                        startSectionCountdownFromDeadline(sectionIdx, d.deadline);
                        if (callback) { callback(); }
                    })
                    .catch(function() {
                        // Fallback to client-side deadline if WS fails.
                        startSectionCountdownFromDeadline(sectionIdx,
                            Math.floor(Date.now() / 1000) + sec.timelimit);
                        if (callback) { callback(); }
                    });
            } else {
                updateSectionTimerDisplay();
                if (callback) { callback(); }
            }
        });
    }

    var sectionCountdownInterval = null;

    // Start a section countdown from a server-authoritative Unix deadline.
    function startSectionCountdownFromDeadline(sectionIdx, deadline) {
        if (!deadline || deadline <= 0) { updateSectionTimerDisplay(); return; }

        if (sectionCountdownInterval) { clearInterval(sectionCountdownInterval); sectionCountdownInterval = null; }

        state.sectionTimers[sectionIdx] = deadline;

        sectionCountdownInterval = setInterval(function() {
            var remaining = deadline - Math.floor(Date.now() / 1000);
            if (remaining <= 0) {
                clearInterval(sectionCountdownInterval);
                sectionCountdownInterval = null;
                updateSectionTimerDisplay();
                onSectionTimeExpired(sectionIdx);
                return;
            }
            updateSectionTimerDisplay();
            var timerEl = el('lg-section-timer');
            if (timerEl) {
                var m = Math.floor(remaining / 60);
                var s = remaining % 60;
                timerEl.textContent = '📂 ' + m + ':' + (s < 10 ? '0' : '') + s;
                timerEl.style.color = remaining <= 60 ? '#ff6b6b' : '#ffe8b0';
            }
        }, 1000);
        updateSectionTimerDisplay();
    }

    function startSectionCountdown(sectionIdx) {
        var sec = state.sections && state.sections[sectionIdx];
        if (!sec || sec.timelimit <= 0) { updateSectionTimerDisplay(); return; }
        startSectionCountdownFromDeadline(sectionIdx, Math.floor(Date.now() / 1000) + sec.timelimit);
    }

    function updateSectionTimerDisplay() {
        var timerEl = el('lg-section-timer');
        if (!timerEl) { return; }
        var idx = state.currentSectionIdx >= 0 ? state.currentSectionIdx : 0;
        var sec = state.sections && state.sections[idx];
        if (!sec || sec.timelimit <= 0 || !state.sectionTimers[idx]) {
            timerEl.style.display = 'none';
            return;
        }
        timerEl.style.display = '';
    }

    function onSectionTimeExpired(sectionIdx) {
        var sec = state.sections && state.sections[sectionIdx];
        var secName = sec ? sec.name : ('Section ' + (sectionIdx + 1));
        showToast('⏱ Time for ' + secName + ' has ended. Moving to next section…', 5000);

        // Auto-navigate to the first slot of the next section.
        var nextIdx = sectionIdx + 1;
        if (state.sections && state.sections[nextIdx] && state.sections[nextIdx].slots.length > 0) {
            setTimeout(function() {
                navigateToSlot(state.sections[nextIdx].slots[0]);
            }, 2000);
        }
    }

    function escHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /* =======================================================================
     * NAVIGATION PANEL
     * ====================================================================== */
    function renderNavigation() {
        var nav = el('lg-nav-panel');
        if (!nav) { return; }
        nav.innerHTML = '';
        var bp = cfg.brandprimary || '#0f6cbf';

        var lastSectionIdx = -2; // sentinel so section 0 label is always rendered
        state.navigation.forEach(function(item) {
            // Insert a section label divider whenever the section changes.
            if (state.sections && state.sections.length > 1) {
                var secIdx = getSectionIndex(item.slot);
                if (secIdx !== lastSectionIdx) {
                    lastSectionIdx = secIdx;
                    var sec = secIdx >= 0 ? state.sections[secIdx] : null;
                    if (sec) {
                        var lbl = document.createElement('div');
                        lbl.style.cssText =
                            'width:100%;flex-basis:100%;font-size:10px;font-weight:700;' +
                            'text-transform:uppercase;letter-spacing:1px;color:#6c757d;' +
                            'padding:4px 0 2px;border-top:' +
                            (secIdx > 0 ? '1px solid #dee2e6;margin-top:4px;' : 'none;');
                        lbl.textContent = '📂 ' + sec.name;
                        nav.appendChild(lbl);
                    }
                }
            }

            var btn = document.createElement('button');
            var isCurrent  = item.slot === state.currentslot;
            var isFlagged  = !!state.flags[item.slot];
            var isAnswered = !!item.answered;

            btn.textContent = isFlagged ? '🚩' : item.slot;
            btn.style.cssText =
                'width:36px;height:36px;flex-shrink:0;border-radius:6px;cursor:pointer;font-size:12px;' +
                'font-weight:700;transition:all .15s;line-height:1;' +
                'border:2px solid ' + (isCurrent ? bp : isFlagged ? '#ffc107' : '#dee2e6') + ';' +
                'background:' + (isFlagged ? '#fff3cd' : isAnswered ? '#28a745' : '#f8f9fa') + ';' +
                'color:' + (isFlagged ? '#856404' : isAnswered ? '#fff' : '#6c757d') + ';' +
                (isCurrent ? 'box-shadow:0 0 0 3px rgba(15,108,191,0.25);' : '');
            btn.title = 'Question ' + item.slot + (isFlagged ? ' (Flagged)' : isAnswered ? ' (Answered)' : '');
            btn.addEventListener('click', function() { navigateToSlot(item.slot); });
            nav.appendChild(btn);
        });

        // ── Update summary stats ──────────────────────────────────────────
        var answered  = state.navigation.filter(function(n) { return n.answered; }).length;
        var total     = state.navigation.length;
        var remaining = total - answered;
        var flagged   = Object.keys(state.flags).filter(function(k) { return state.flags[k]; }).length;
        var pct       = total > 0 ? Math.round((answered / total) * 100) : 0;

        // Top-bar progress text + bar.
        var progText = el('lg-progress');
        if (progText) { progText.textContent = 'Q ' + state.currentslot + ' of ' + total + ' — ' + answered + ' answered'; }
        var progBar = el('lg-progress-bar');
        if (progBar) { progBar.style.width = pct + '%'; }

        // Thin completion bar below top bar.
        var compBar = el('lg-completion-bar');
        if (compBar) { compBar.style.width = pct + '%'; }

        // Summary rows.
        var sa = el('lg-sum-answered');
        if (sa) { var saSpan = sa.querySelector('span:last-child'); if (saSpan) { saSpan.textContent = answered; } }
        var sr = el('lg-sum-remaining');
        if (sr) { var srSpan = sr.querySelector('span:last-child'); if (srSpan) { srSpan.textContent = remaining; } }
        var sf = el('lg-sum-flagged');
        if (sf) { var sfSpan = sf.querySelector('span:last-child'); if (sfSpan) { sfSpan.textContent = flagged; } }
    }

    function updateNavState(slot, answered) {
        state.navigation.forEach(function(n) {
            if (n.slot === slot) { n.answered = answered; n.state = answered ? 'complete' : 'todo'; }
        });
        renderNavigation();
    }

    function navigateToSlot(slot) {
        if (slot < 1 || slot > state.totalquestions || slot === state.currentslot) { return; }
        submitCurrentAnswer(false, slot);
    }

    /* =======================================================================
     * QUESTION LOADING
     * ====================================================================== */

    // Re-apply locally held edits (from a reopened-for-review session) into freshly
    // rendered/cached question HTML, overriding whatever the server baked in at fetch
    // time — otherwise revisiting a question the student already edited this session
    // would show their older, pre-edit answer instead of the change they just made.
    function rehydrateFormData(container, formData) {
        if (!formData || !formData.length) { return; }
        container.querySelectorAll('input[type="radio"], input[type="checkbox"]').forEach(function(el) {
            el.checked = false;
        });
        formData.forEach(function(item) {
            var els = container.querySelectorAll('[name="' + item.name + '"]');
            els.forEach(function(el) {
                if (el.type === 'checkbox' || el.type === 'radio') {
                    if (el.value === item.value) { el.checked = true; }
                } else {
                    el.value = item.value;
                }
            });
        });
    }

    // Finish rendering a question's HTML into the container — shared by the normal
    // server-fetch path and the reopened-for-review cached-HTML path below.
    function renderQuestionInto(container, slot, html, totalquestions) {
        container.innerHTML = html;
        state.currentslot = slot;
        container.querySelectorAll('script').forEach(function(old) {
            var s = document.createElement('script');
            if (old.src) { s.src = old.src; } else { s.textContent = old.textContent; }
            old.parentNode.replaceChild(s, old);
        });
        if (state.reopenedForReview && state.localAnswers[slot]) {
            rehydrateFormData(container, state.localAnswers[slot]);
        }
        // Re-trigger MathJax rendering for LaTeX in newly loaded question.
        if (window.MathJax) {
            try {
                if (MathJax.typesetPromise) { MathJax.typesetPromise([container]); }
                else if (MathJax.Hub) { MathJax.Hub.Queue(['Typeset', MathJax.Hub, container]); }
            } catch (e) {}
        }
        renderNavigation();
        updateNavigationButtons(slot, totalquestions);
        attachAnswerListeners(slot);
    }

    // Inner loader — fetches and renders a question with no section intercept.
    // retryCount: 0 on the first attempt; incremented on each automatic silent retry.
    // Real-world reports showed sustained multi-minute server distress, not just an
    // isolated blip, so this retries up to twice (3 attempts total, 1.5s/3s backoff)
    // before finally showing the error + manual Retry button.
    var LG_LOAD_MAX_RETRIES = 2;
    function doLoadQuestion(slot, retryCount) {
        retryCount = retryCount || 0;
        var container = el('lg-question-container');
        var progress  = el('lg-progress');
        if (!container) { return; }

        // Cache-first for EVERY session, not just reopened-for-review: preloadRemainingQuestions()
        // (fired right after the first question loads) fills this cache in the background
        // for the whole exam, so navigation after Q1 is normally instant with zero
        // additional round trips — this is what actually eliminates "Failed to load
        // question" for questions 2+, rather than just retrying a fetch that keeps failing.
        // A cache miss (preload not finished yet, or it failed) falls straight through to
        // the normal live fetch below exactly as before this existed.
        if (state.questionCache[slot]) {
            setNavBusy(false);
            state.currentqtype = state.qtypeCache[slot] || '';
            if (progress) { progress.textContent = 'Question ' + slot + ' of ' + state.totalquestions; }
            renderQuestionInto(container, slot, state.questionCache[slot], state.totalquestions);
            return;
        }

        container.innerHTML = '<div style="text-align:center;padding:40px;color:#6c757d;">' +
            '<div class="lg-spinner"></div><p>Loading question…</p></div>';
        if (progress) { progress.textContent = 'Question ' + slot + ' of ' + state.totalquestions; }

        callWS('mod_learnguard_get_question', {attemptid: state.attemptid, slot: slot, review: false})
            .then(function(data) {
                setNavBusy(false); // release before updateNavigationButtons sets the final per-slot state
                state.questionCache[data.slot] = data.html;
                state.qtypeCache[data.slot] = data.qtype || '';
                state.currentqtype = data.qtype || '';
                renderQuestionInto(container, data.slot, data.html, data.totalquestions);
            })
            .catch(function(err) {
                // Silent automatic retries (with backoff) before bothering the student —
                // recovers from transient network blips or slow-response timeouts with no
                // action needed. Only after LG_LOAD_MAX_RETRIES consecutive failures does
                // the error + manual Retry button show.
                if (retryCount < LG_LOAD_MAX_RETRIES) {
                    setTimeout(function() { doLoadQuestion(slot, retryCount + 1); }, lgJitter(1500 * (retryCount + 1)));
                    return;
                }
                setNavBusy(false);
                container.innerHTML = '<div class="alert alert-danger" style="text-align:center;">' +
                    'Failed to load question.<br><small>' + (err.message || '') + '</small><br>' +
                    '<button id="lg-retry-load-q" class="btn btn-sm btn-primary" style="margin-top:12px;">🔄 Retry</button></div>';
                var retryBtn = document.getElementById('lg-retry-load-q');
                if (retryBtn) { retryBtn.addEventListener('click', function() { loadQuestion(slot); }); }
            });
    }

    // Outer loader — checks for a new section on first entry and shows the intro overlay.
    function loadQuestion(slot) {
        if (state.sections && state.sections.length > 1) {
            var newIdx = getSectionIndex(slot);
            if (newIdx >= 0 && newIdx !== state.currentSectionIdx) {
                var sec = state.sections[newIdx];
                if (sec && !sec.visited) {
                    state.currentSectionIdx = newIdx;
                    updateSectionTimerDisplay();
                    // Show intro; once dismissed, actually load the question.
                    showSectionIntro(newIdx, function() { doLoadQuestion(slot); });
                    return;
                }
                // Section already visited — just update tracking.
                state.currentSectionIdx = newIdx;
                updateSectionTimerDisplay();
            }
        }
        doLoadQuestion(slot);
    }

    // Fired once, right after the first question has been requested (so nothing delays
    // Q1 itself), to fetch the HTML for every remaining question in ONE background call
    // and drop it straight into state.questionCache — doLoadQuestion() then serves every
    // later navigation instantly from that cache with zero further round trips. This is
    // what actually eliminates "Failed to load question" for questions 2+ rather than
    // just retrying a fetch that keeps failing under server load. Best-effort only: a
    // failure here is silent and harmless — doLoadQuestion() falls back to its normal
    // (already retry-protected) per-question fetch for any slot the cache doesn't have,
    // exactly the same as before this existed.
    // Real-world report: a student had "persistent freezing and slow server response"
    // throughout an entire 30-question attempt despite this preload existing — this had
    // ZERO retry, so a single failure (most likely exactly when many students start
    // together — the worst possible moment for one large request to compete for server
    // capacity) permanently left that whole attempt with no cache at all, silently
    // falling back to a live per-question fetch for all 30 questions, fully exposed to
    // server load for the entire exam instead of just the first one. Retries up to
    // twice with backoff, same pattern as every other exam-critical WS call.
    var LG_PRELOAD_MAX_RETRIES = 2;
    var lgPreloadStarted = false;
    function preloadRemainingQuestions(retryCount) {
        retryCount = retryCount || 0;
        if (retryCount === 0) {
            if (lgPreloadStarted || !state.attemptid) { return; }
            lgPreloadStarted = true;
        }
        function fire() {
            callWS('mod_learnguard_get_all_questions', {attemptid: state.attemptid}, 60000)
                .then(function(data) {
                    (data.questions || []).forEach(function(q) {
                        state.questionCache[q.slot] = q.html;
                        state.qtypeCache[q.slot] = q.qtype || '';
                    });
                })
                .catch(function() {
                    if (retryCount < LG_PRELOAD_MAX_RETRIES) {
                        setTimeout(function() { preloadRemainingQuestions(retryCount + 1); }, lgJitter(3000 * (retryCount + 1)));
                    }
                    // Final failure after all retries: still silent and harmless —
                    // doLoadQuestion() falls back to its own (already retry-protected)
                    // per-question fetch for any slot the cache doesn't have.
                });
        }
        if (retryCount === 0) {
            // Stagger the FIRST attempt across a random 0-4s window per student — a
            // cohort exam typically opens for everyone at the same scheduled instant,
            // so without this every single student's browser fires this same
            // moderately-large request in the exact same instant, concentrating peak
            // server load into the worst possible moment instead of spreading it out.
            // Q1 itself is unaffected — it's a separate, already-fired request.
            setTimeout(fire, Math.random() * 4000);
        } else {
            fire();
        }
    }

    // Preloads every question in the attempt, retrying SILENTLY AND INDEFINITELY (no
    // error is ever shown, no matter how long it takes) until it fully succeeds — used
    // to guarantee state.questionCache holds every question BEFORE the exam clock ever
    // starts and the exam UI is revealed (see launchAssessment()/proceedResumedAttempt()).
    // Unlike the best-effort preloadRemainingQuestions() above (fire-and-forget, gives up
    // after 2 retries since doLoadQuestion()'s own per-question fallback already covers a
    // miss), this one MUST eventually succeed — onDone only fires once the whole exam is
    // cached. This is safe specifically BECAUSE the clock has not started yet at this
    // point: a real formal complaint showed a student lose 14 of their 17 minutes to
    // "Failed to load question" with the clock already silently running the whole time —
    // waiting here, however long it takes, costs the student nothing.
    function preloadAllQuestionsBlocking(onDone, attemptNum) {
        attemptNum = attemptNum || 0;
        function fire() {
            callWS('mod_learnguard_get_all_questions', {attemptid: state.attemptid}, 60000)
                .then(function(data) {
                    (data.questions || []).forEach(function(q) {
                        state.questionCache[q.slot] = q.html;
                        state.qtypeCache[q.slot] = q.qtype || '';
                    });
                    onDone();
                })
                .catch(function() {
                    // Backoff grows with each failure but caps at 20s so a long outage
                    // still retries at a sane pace rather than hammering an
                    // already-struggling server.
                    var delay = lgJitter(Math.min(20000, 2000 * (attemptNum + 1)));
                    setTimeout(function() { preloadAllQuestionsBlocking(onDone, attemptNum + 1); }, delay);
                });
        }
        if (attemptNum === 0) {
            // Same reasoning as preloadRemainingQuestions() above: a cohort exam
            // typically opens for everyone at the same scheduled instant, so without a
            // stagger every student's browser fires this same bulk request in the exact
            // same moment, concentrating peak load into the worst possible instant
            // instead of spreading it out. Only the FIRST attempt is staggered — a
            // retry after a real failure should not wait an extra random delay on top
            // of its own backoff.
            setTimeout(fire, Math.random() * 4000);
        } else {
            fire();
        }
    }

    // Idempotently tells the server to start this attempt's exam clock (see
    // start_clock() / start_exam_clock() server-side). Retries a few times before
    // giving up on blocking the student any further — the reveal proceeds either way,
    // since initTimer() already falls back safely to the client's own "now" when
    // state.timestart is still unset. But server-side time-limit enforcement
    // (learnguard_attempt_timelimit_expired(), checked on every submit_answer/autosave)
    // depends on a real timestart eventually being recorded — leaving it at 0 forever
    // would silently disable that backstop for this one attempt. So after the blocking
    // retries are exhausted, this keeps quietly retrying in the background every 30s
    // until it succeeds (safe to call repeatedly — start_clock() is idempotent).
    function lgStartExamClockRobust(cb, retryCount) {
        retryCount = retryCount || 0;
        callWS('mod_learnguard_start_exam_clock', {attemptid: state.attemptid})
            .then(function(clockData) {
                state.timestart = clockData.timestart;
                cb();
            })
            .catch(function() {
                if (retryCount < 3) {
                    setTimeout(function() { lgStartExamClockRobust(cb, retryCount + 1); }, lgJitter(1500 * (retryCount + 1)));
                    return;
                }
                cb();
                var lgClockBgRetry = setInterval(function() {
                    if (state.finished || state.suspended) { clearInterval(lgClockBgRetry); return; }
                    callWS('mod_learnguard_start_exam_clock', {attemptid: state.attemptid})
                        .then(function() { clearInterval(lgClockBgRetry); })
                        .catch(function() {});
                }, 30000);
            });
    }

    // Guards against spam-clicking Next/Prev/Submit while a save+load round trip is in
    // flight — without this, a slow response (e.g. server under heavy concurrent load)
    // led students to click repeatedly, firing overlapping submit_answer/get_question
    // calls that raced each other, added more load to an already-strained server, and
    // made navigation look "stuck" or unresponsive.
    var navBusy = false;
    function setNavBusy(busy) {
        navBusy = busy;
        ['lg-prev-btn', 'lg-next-btn', 'lg-submit-btn'].forEach(function(id) {
            var b = el(id);
            if (!b) { return; }
            b.disabled = busy;
            b.style.opacity = busy ? '0.5' : '';
            b.style.cursor  = busy ? 'wait' : '';
        });
    }

    var answerDebounce = null;
    function attachAnswerListeners(slot) {
        var container = el('lg-question-container');
        if (!container) { return; }
        function triggerSave() {
            // Reopened-for-review: no per-change server save — submitCurrentAnswer()
            // captures the current form into state.localAnswers on navigation, and
            // everything is flushed once on final submit. Nothing to do here but keep
            // the nav dot state current, which the change listeners below already do
            // optimistically before calling this.
            if (state.reopenedForReview) { return; }
            callWS('mod_learnguard_submit_answer', {
                attemptid: state.attemptid, slot: slot,
                responsedata: collectCurrentFormData(), targetslot: 0,
            }).then(function(d) {
                if (d.section_expired) {
                    showToast('⏱ Time for this section has ended. Your answer was not saved.', 4000);
                    updateNavState(slot, false);
                    return;
                }
                var ans = (d.answered !== undefined) ? !!d.answered : (collectCurrentFormData().length > 0);
                updateNavState(slot, ans);
            }).catch(function() {});
        }
        // Radio/checkbox — save immediately on selection.
        container.querySelectorAll('input[type=radio], input[type=checkbox]').forEach(function(inp) {
            inp.addEventListener('change', function() {
                updateNavState(slot, true); // optimistic update
                triggerSave();
            });
        });
        // Textarea/text/number — mark answered when non-empty; save after 2s idle.
        container.querySelectorAll('textarea, input[type=text], input[type=number]').forEach(function(inp) {
            inp.addEventListener('input', function() {
                if (inp.value.trim()) { updateNavState(slot, true); }
                if (answerDebounce) { clearTimeout(answerDebounce); }
                answerDebounce = setTimeout(triggerSave, 2000);
            });
        });
        // Essay/Choice Essay's rich-text answer box is a contenteditable div, not a
        // textarea — its own hidden sync textarea (see question_engine.php) is only ever
        // updated programmatically, which never dispatches a real "input" event, so the
        // listener above never fires for it. Without this, typing into the formatted essay
        // box would neither light up the nav dot nor trigger the 2s idle autosave until
        // the student actually navigated away. .textContent (not .value — contenteditable
        // has no .value) strips markup so a stray "<br>" left behind after deleting
        // everything is correctly still treated as blank.
        container.querySelectorAll('[contenteditable]').forEach(function(ce) {
            ce.addEventListener('input', function() {
                if ((ce.textContent || '').trim()) { updateNavState(slot, true); }
                if (answerDebounce) { clearTimeout(answerDebounce); }
                answerDebounce = setTimeout(triggerSave, 2000);
            });
        });
    }

    function updateNavigationButtons(slot, total) {
        var prevBtn   = el('lg-prev-btn');
        var nextBtn   = el('lg-next-btn');
        var submitBtn = el('lg-submit-btn');
        var flagBtn   = el('lg-flag-btn');

        if (prevBtn) {
            prevBtn.disabled = (slot <= 1);
            prevBtn.style.opacity = slot <= 1 ? '0.4' : '1';
        }
        if (nextBtn)   { nextBtn.style.display  = (slot < total)  ? 'flex' : 'none'; }
        if (submitBtn) { submitBtn.style.display = (slot >= total) ? 'inline-flex' : 'none'; }

        // Sync flag button with current question's flag state.
        if (flagBtn) {
            var flagged = !!state.flags[slot];
            flagBtn.style.background  = flagged ? '#fff3cd' : '#fff';
            flagBtn.style.borderColor = flagged ? '#ffc107' : '#dee2e6';
            flagBtn.style.color       = flagged ? '#856404' : '#6c757d';
            flagBtn.innerHTML = (flagged ? '🚩 Flagged for Review' : '🚩 Flag for Review');
        }
    }

    /* =======================================================================
     * ANSWER SUBMISSION
     * ====================================================================== */
    // Ordered "next slot after this one" per state.navigation — used for reopened-for-
    // review navigation, which has no server round trip to ask for it.
    function getNextSlotFromNav(currentSlot) {
        if (!state.navigation) { return 0; }
        for (var i = 0; i < state.navigation.length; i++) {
            if (state.navigation[i].slot === currentSlot) {
                return (i + 1 < state.navigation.length) ? state.navigation[i + 1].slot : 0;
            }
        }
        return 0;
    }

    // Flush every locally held edit from a reopened-for-review session to the server,
    // one at a time, before finishing — the only point during a review session where
    // answers actually get saved. Calls cb() once every slot has been submitted (or
    // immediately if there is nothing to flush).
    function flushReviewAnswers(cb) {
        var slots = Object.keys(state.localAnswers);
        function next(i) {
            if (i >= slots.length) { cb(); return; }
            var slot = parseInt(slots[i], 10);
            callWS('mod_learnguard_submit_answer', {
                attemptid: state.attemptid, slot: slot,
                responsedata: state.localAnswers[slot], targetslot: 0,
            }).then(function() { next(i + 1); }).catch(function() { next(i + 1); });
        }
        next(0);
    }

    function submitCurrentAnswer(andFinish, targetSlot) {
        if (navBusy) { return; } // ignore extra clicks while a save/load is already in flight.
        setNavBusy(true);
        // Clear any pending debounce — this explicit save covers the current answer,
        // and the debounce firing after navigation could send a wrong slot/data combo.
        if (answerDebounce) { clearTimeout(answerDebounce); answerDebounce = null; }

        // Reopened-for-review: hold the edit locally instead of a server round trip for
        // every navigation click — every question was already answered once, so there is
        // nothing new here at risk of being lost the way there would be on a first
        // attempt. Everything is flushed to the server in one pass on final submit.
        if (state.reopenedForReview) {
            var formData = collectCurrentFormData();
            state.localAnswers[state.currentslot] = formData;
            updateNavState(state.currentslot, formData.length > 0);
            if (andFinish) {
                flushReviewAnswers(function() { setNavBusy(false); finishAttempt(); });
            } else {
                var goToLocal = (targetSlot > 0) ? targetSlot : getNextSlotFromNav(state.currentslot);
                if (goToLocal > 0) { state.currentslot = goToLocal; loadQuestion(goToLocal); }
                else {
                    setNavBusy(false);
                    showToast('Could not move to that question — please try again or refresh the page.', 'danger');
                }
            }
            return;
        }

        // Capture the answer once, before any retry — resend the exact same submission
        // on a retry rather than re-collecting from the DOM (which could have changed,
        // or the question could have re-rendered from a concurrent action).
        var savedSlot     = state.currentslot;
        var savedResponse = collectCurrentFormData();

        // Real-world reports showed sustained multi-minute server distress (not just an
        // isolated blip) causing "Error saving answer: Request timed out" with no
        // recovery — this is worse than a slow question load since it's a save, not a
        // read. Retries silently up to LG_SAVE_MAX_RETRIES times (backoff) before
        // finally showing the error toast. save_response() on the server is a safe
        // upsert keyed on attemptid+slotid, so resending the identical answer on a
        // retry can never create a duplicate or corrupt state.
        var LG_SAVE_MAX_RETRIES = 2;
        function doSubmit(retryCount) {
            callWS('mod_learnguard_submit_answer', {
                attemptid: state.attemptid, slot: savedSlot,
                responsedata: savedResponse,
                targetslot: (targetSlot > 0) ? targetSlot : 0,
            }).then(function(data) {
                if (data.window_closed) {
                    showToast('⏳ The assessment window has closed. Your attempt has been submitted.', 5000);
                    setNavBusy(false);
                    clearTimers();
                    finishAttempt();
                    return;
                }
                if (data.section_expired) {
                    showToast('⏱ Time for this section has ended. Your answer was not saved.', 4000);
                    // Still navigate if the student pressed Next/Previous.
                    if (!andFinish) {
                        var goTo = (targetSlot > 0) ? targetSlot : data.nextslot;
                        if (goTo > 0) { state.currentslot = goTo; loadQuestion(goTo); return; }
                    }
                    setNavBusy(false);
                    return;
                }
                // Use server-confirmed answered flag; fall back to checking local form data
                // if WS cache is stale and data.answered is not yet available.
                var wasAnswered = (data.answered !== undefined && data.answered !== null)
                    ? !!data.answered
                    : (savedResponse.length > 0);
                updateNavState(savedSlot, wasAnswered);
                if (andFinish) { setNavBusy(false); finishAttempt(); }
                else {
                    var goTo = (targetSlot > 0) ? targetSlot : data.nextslot;
                    if (goTo > 0) { state.currentslot = goTo; loadQuestion(goTo); }
                    else {
                        setNavBusy(false);
                        showToast('Could not move to that question — please try again or refresh the page.', 'danger');
                    }
                }
            }).catch(function() {
                if (retryCount < LG_SAVE_MAX_RETRIES) {
                    setTimeout(function() { doSubmit(retryCount + 1); }, lgJitter(1500 * (retryCount + 1)));
                    return;
                }
                // All 3 attempts failed — rather than blocking or alarming the student
                // (explicit instruction: keep retrying silently, no error needed), queue
                // the answer locally and let them keep moving with zero visible sign
                // anything went wrong. lgFlushUnsavedAnswers() keeps quietly retrying this
                // specific slot in the background every 12s, indefinitely, and a final
                // sweep runs at submit/suspend so nothing is lost as long as the tab stays
                // open — see that function for the trade-off this accepts.
                state.unsavedAnswers[savedSlot] = savedResponse;
                updateNavState(savedSlot, savedResponse.length > 0);
                setNavBusy(false);
                if (andFinish) { finishAttempt(); }
                else {
                    var goTo = (targetSlot > 0) ? targetSlot : getNextSlotFromNav(savedSlot);
                    if (goTo > 0) { state.currentslot = goTo; loadQuestion(goTo); }
                }
            });
        }
        doSubmit(0);
    }

    // Background safety net for answers that failed to save after submitCurrentAnswer()'s
    // own retries — resends each pending slot's exact captured response every 12s until
    // it succeeds. targetslot is set to the CURRENT slot (not the pending slot itself, and
    // not 0) so this resync's server-side currentpage write can only reaffirm wherever the
    // student actually is by now, never regress their resume position backward to an old
    // slot they've since moved past. Also swept synchronously (best-effort) at final
    // submit and at suspension — the two points where the tab might not stay open much
    // longer, so anything still unsaved needs to reach the server before then.
    var lgUnsavedFlushBusy = false;
    function lgFlushUnsavedAnswers(cb) {
        var slots = Object.keys(state.unsavedAnswers);
        if (!slots.length || lgUnsavedFlushBusy) { if (cb) { cb(); } return; }
        lgUnsavedFlushBusy = true;
        var remaining = slots.length;
        function done() {
            remaining--;
            if (remaining <= 0) { lgUnsavedFlushBusy = false; if (cb) { cb(); } }
        }
        slots.forEach(function(slotKey) {
            var slot = parseInt(slotKey, 10);
            callWS('mod_learnguard_submit_answer', {
                attemptid: state.attemptid, slot: slot,
                responsedata: state.unsavedAnswers[slot],
                targetslot: state.currentslot || 0,
            }).then(function() { delete state.unsavedAnswers[slot]; done(); })
              .catch(function() { done(); });
        });
    }

    var lgUnsavedFlushStarted = false;
    function startUnsavedAnswerFlush() {
        if (lgUnsavedFlushStarted) { return; }
        lgUnsavedFlushStarted = true;
        // This is precisely the recovery mechanism that activates during a widespread
        // server-distress event — exactly the moment a fixed, unjittered interval would
        // do the most damage, since every affected student's browser would then retry
        // in the same synchronised wave right as the server is already struggling.
        // Self-rescheduling with jitter each cycle (rather than a single setInterval)
        // instead of a one-time stagger, so it keeps desynchronising over time.
        function scheduleNext() {
            setTimeout(function() {
                if (state.attemptid && !state.finished && !state.suspended) {
                    lgFlushUnsavedAnswers();
                }
                scheduleNext();
            }, lgJitter(12000));
        }
        scheduleNext();
    }

    // finish_attempt is idempotent server-side (an already-'finished' attempt just
    // returns its existing grade rather than re-processing) — safe to retry. This is
    // the single most damaging call to leave unprotected: a timeout here left the
    // student not knowing whether their completed exam actually submitted.
    var LG_FINISH_MAX_RETRIES = 2;
    function finishAttempt(retryCount) {
        retryCount = retryCount || 0;
        if (state.finished) { return; }
        function doFinish() {
            callWS('mod_learnguard_finish_attempt', {attemptid: state.attemptid})
                .then(function(data) {
                    state.finished = true; clearTimers(); showResultScreen(data);
                })
                .catch(function(err) {
                    if (retryCount < LG_FINISH_MAX_RETRIES) {
                        setTimeout(function() { finishAttempt(retryCount + 1); }, lgJitter(1500 * (retryCount + 1)));
                        return;
                    }
                    showToast('Failed to submit: ' + (err.message || 'Please try again.'), 'danger');
                });
        }
        // Best-effort final sweep of anything still queued from a failed background save —
        // only on the top-level call (a retry of finish itself has nothing new to flush).
        // Proceeds to actually finishing regardless of whether the flush succeeds; this
        // is the last realistic chance to reach the server before the attempt is sealed.
        if (retryCount === 0) {
            lgFlushUnsavedAnswers(doFinish);
        } else {
            doFinish();
        }
    }

    /* =======================================================================
     * PRE-EXAM WIZARD — 10 steps matching LearnShield's flow
     *
     * 1. Welcome  2. Browser  3. Internet  4. Camera  5. Audio
     * 6. Face+Liveness  7. Environment 360°  8. Privacy  9. Fullscreen  10. Ready
     *
     * Design: full-screen dark overlay → white card → LearnShield style.
     * ====================================================================== */
    var wizardStep   = 0;
    var cameraStream = null;
    var faceApiReady = false;

    var PRIVACY_POLICY = 'LEARNGUARD — PRIVACY & DATA PROTECTION NOTICE\n\n' +
        'This exam is proctored by LearnGuard. By proceeding, you agree to the following:\n\n' +
        'DATA COLLECTED\n' +
        '• Webcam snapshots taken at regular intervals during your exam\n' +
        '• A 360° environment recording before the exam starts\n' +
        '• Audio activity levels (volume only — not recorded as audio file)\n' +
        '• Browser events: tab switches, keyboard shortcuts, fullscreen exits\n' +
        '• Session metadata: IP address, browser type, operating system\n' +
        '• Face detection results and integrity risk score\n\n' +
        'PURPOSE\n' +
        'All data is collected solely to maintain academic integrity.\n\n' +
        'STORAGE\n' +
        'All data is stored on your institution\'s Moodle server.\n\n' +
        'RETENTION\n' +
        '• Webcam snapshots: 14 days\n' +
        '• Environment recordings: 7 days\n' +
        '• Event logs: 180 days\n\n' +
        'CONSENT\n' +
        'Proceeding constitutes your explicit consent to the above data processing.';

    // -----------------------------------------------------------------------
    // Wizard overlay — matches LearnShield card design exactly
    // -----------------------------------------------------------------------
    function buildWizardOverlay() {
        if (document.getElementById('lg-wizard-overlay')) { return; }
        var overlay = document.createElement('div');
        overlay.id = 'lg-wizard-overlay';
        // padding-top reserves space so the browser's own "sharing your screen"/recording
        // indicator bar (shown once screen sharing starts, before this exam ever reaches
        // fullscreen) still overlapped the Continue/Start buttons after the first fix
        // (56px top padding, still center-aligned) — reported live again: on some
        // browsers this indicator floats as a bottom-center pill, not a top bar, which
        // vertical centering does nothing to avoid. Anchoring the whole card toward the
        // TOP of the viewport instead of centering it moves the footer buttons as far
        // away from that bottom-center zone as the content allows.
        overlay.style.cssText = 'position:fixed;inset:0;z-index:9999999;' +
            'background:rgba(10,10,30,.97);display:flex;align-items:flex-start;' +
            'justify-content:center;font-family:system-ui,sans-serif;' +
            'padding:40px 16px 80px;box-sizing:border-box;overflow-y:auto;';
        var bp = cfg.brandprimary || '#0f6cbf';
        overlay.innerHTML =
            '<div id="lg-wiz-card" style="background:#fff;border-radius:12px;' +
            'width:min(640px,96vw);max-height:80vh;overflow:hidden;display:flex;' +
            'flex-direction:column;box-shadow:0 8px 40px rgba(0,0,0,.6);">' +

            // Header.
            '<div style="background:' + bp + ';color:#fff;padding:18px 24px;' +
            'display:flex;align-items:center;gap:12px;flex-shrink:0;">' +
            '<div style="font-size:28px;" id="lg-wiz-icon">🛡</div>' +
            '<div style="flex:1;">' +
            '<div style="font-size:18px;font-weight:700;" id="lg-wiz-title">LearnGuard Setup</div>' +
            '<div style="font-size:12px;opacity:.85;" id="lg-wiz-subtitle">Secure exam preparation</div>' +
            '</div>' +
            '<div style="font-size:12px;opacity:.8;white-space:nowrap;" id="lg-wiz-stepcount"></div>' +
            '</div>' +

            // Progress bar.
            '<div style="height:4px;background:#cde;">' +
            '<div id="lg-wiz-progress" style="height:100%;background:' + bp + ';transition:width .4s;width:0%;"></div>' +
            '</div>' +

            // Body — text-align:left so individual renderers can override where needed.
            '<div id="lg-wiz-body" style="flex:1;overflow-y:auto;padding:24px 28px;"></div>' +

            // Footer.
            '<div style="padding:14px 24px;border-top:1px solid #e9ecef;display:flex;' +
            'justify-content:space-between;align-items:center;flex-shrink:0;gap:12px;">' +
            '<div id="lg-wiz-status" style="font-size:13px;color:#6c757d;flex:1;"></div>' +
            '<div style="display:flex;gap:8px;">' +
            '<button id="lg-wiz-back-btn" style="display:none;background:#6c757d;color:#fff;border:none;' +
            'border-radius:8px;padding:10px 20px;cursor:pointer;font-size:14px;">← Back</button>' +
            '<button id="lg-wiz-next-btn" style="background:' + bp + ';color:#fff;border:none;' +
            'border-radius:8px;padding:12px 28px;font-size:15px;font-weight:700;cursor:pointer;' +
            'min-width:130px;">Continue →</button>' +
            '</div>' +
            '</div>' +

            '</div>'; // card end

        document.body.appendChild(overlay);
    }

    // Per-step cleanup callbacks — run before rendering each new step so that
    // scroll listeners, intervals, and timers from a previous step can't leak
    // onto the next step and inadvertently enable/disable the Continue button.
    var wizAudioCleanup  = null;  // audio step noise-check cleanup
    var wizScrollCleanup = null;  // closetabs (and similar) body-scroll cleanup

    var WIZARD_STEPS = [
        {id: 'welcome',     icon: '🎓', title: 'Welcome to Your Proctored Exam'},
        {id: 'closetabs',   icon: '🚫', title: 'Close All Other Apps & Tabs'},
        {id: 'browser',     icon: '🌐', title: 'Browser Compatibility'},
        {id: 'internet',    icon: '📡', title: 'Internet Connection'},
        {id: 'camera',      icon: '📷', title: 'Camera / Webcam'},
        {id: 'audio',       icon: '🎤', title: 'Microphone (Audio)'},
        {id: 'photoid',     icon: '🪪', title: 'Identity Verification (Photo ID)'},
        {id: 'face',        icon: '👤', title: 'Face Detection & Liveness'},
        {id: 'screenshare', icon: '🖥', title: 'Screen Sharing (Monitoring)'},
        {id: 'keystroke',   icon: '⌨️', title: 'Typing Pattern Baseline'},
        {id: 'monitor',     icon: '🖥', title: 'Single Screen Required'},
        {id: 'environment', icon: '🔭', title: 'Environment Recording (360°)'},
        {id: 'privacy',     icon: '🔒', title: 'Privacy & Data Protection'},
        {id: 'fullscreen',  icon: '⛶', title: 'Fullscreen Mode'},
        {id: 'ready',       icon: '✅', title: 'Ready to Begin'},
    ];

    function renderWizardStep(idx) {
        if (wizAudioCleanup)  { wizAudioCleanup();  wizAudioCleanup  = null; }
        if (wizScrollCleanup) { wizScrollCleanup(); wizScrollCleanup = null; }
        var step  = WIZARD_STEPS[idx];
        var total = WIZARD_STEPS.length;
        var bp    = cfg.brandprimary || '#0f6cbf';

        el('lg-wiz-icon').textContent     = step.icon;
        el('lg-wiz-title').textContent    = step.title;
        el('lg-wiz-stepcount').textContent = 'Step ' + (idx + 1) + ' of ' + total;
        el('lg-wiz-progress').style.width  = Math.round(((idx + 1) / total) * 100) + '%';

        // Report step to server so proctor can see precheck progress in real-time.
        if (cfg.cmid) {
            callWS('mod_learnguard_report_precheck', {
                cmid: cfg.cmid,
                step: step.id,
                stepnumber: idx,
                totalsteps: total,
            }).catch(function() {});
        }

        var body   = el('lg-wiz-body');
        var next   = el('lg-wiz-next-btn');
        var back   = el('lg-wiz-back-btn');
        var status = el('lg-wiz-status');
        body.innerHTML     = '';
        body.scrollTop     = 0;   // Always start each step from the top.
        status.textContent = '';
        next.disabled      = false;
        next.textContent   = (idx === total - 1) ? '🚀 Start Exam Now' : 'Continue →';
        next.style.background = bp;
        back.style.display = (idx > 0) ? 'inline-block' : 'none';

        switch (step.id) {
            case 'welcome':     body.innerHTML = wiz_welcome(); next.disabled = true; wiz_welcome_hooks(); break;
            case 'closetabs':   body.innerHTML = wiz_closetabs_html(); next.disabled = true; wiz_closetabs(); break;
            case 'browser':     body.innerHTML = wiz_autocheck('browser'); wiz_browser(); break;
            case 'internet':    body.innerHTML = wiz_autocheck('internet'); wiz_internet(); break;
            case 'camera':      body.innerHTML = wiz_camera_html(); next.disabled = true; wiz_camera(); break;
            case 'audio':       body.innerHTML = wiz_audio_html(); next.disabled = true; wiz_audio(); break;
            case 'photoid':     body.innerHTML = wiz_photoid_html(); next.disabled = true; wiz_photoid(); break;
            case 'face':        body.innerHTML = wiz_face_html(); next.disabled = true; wiz_face(); break;
            case 'screenshare': body.innerHTML = wiz_screenshare_html(); next.disabled = true; wiz_screenshare(); break;
            case 'keystroke':   body.innerHTML = wiz_keystroke_html(); next.disabled = true; wiz_keystroke(); break;
            case 'monitor':     body.innerHTML = wiz_monitor_html(); break;
            case 'environment': body.innerHTML = wiz_env_html(); next.disabled = true; wiz_env(); break;
            case 'privacy':     body.innerHTML = wiz_privacy_html(); next.disabled = true; wiz_privacy_hooks(); break;
            case 'fullscreen':  body.innerHTML = wiz_fs_html(); next.disabled = true; setTimeout(wiz_fs_hooks, 80); break;
            case 'ready':       body.innerHTML = wiz_ready_html(); next.disabled = true; wiz_ready_hooks(); break;
        }

        next.onclick = function() {
            if (next.disabled) { return; } // Belt-and-suspenders: never advance if gated step not completed.

            // Extra step-specific guards — enforce requirements even if disabled state was bypassed.
            if (step.id === 'closetabs') {
                var ctChk = document.getElementById('lg-closetabs-chk');
                if (!ctChk || !ctChk.checked) {
                    showToast('⚠️ Please tick the confirmation checkbox before continuing.', 'warning', 4000);
                    return;
                }
            }
            if (step.id === 'privacy') {
                var pvChk = document.getElementById('lg-privacy-chk');
                if (!pvChk || !pvChk.checked) {
                    showToast('⚠️ Please scroll to the bottom and accept the Privacy notice before continuing.', 'warning', 4000);
                    return;
                }
            }
            if (step.id === 'fullscreen') {
                var inFs = !!(document.fullscreenElement || document.webkitFullscreenElement ||
                               document.mozFullScreenElement || document.msFullscreenElement);
                if (!inFs) {
                    showToast('⚠️ You must enter fullscreen before continuing.', 'warning', 4000);
                    return;
                }
            }
            if (step.id === 'environment') {
                // Must have a successful upload status (contains "✅") or a pending deferred upload.
                var envStatus = document.getElementById('lg-env-status');
                var envOk = envStatus && (
                    envStatus.innerHTML.indexOf('✅') !== -1 ||
                    envStatus.textContent.indexOf('✅') !== -1
                );
                if (!envOk) {
                    showToast('⚠️ Please record and upload your environment video before continuing.', 'warning', 4000);
                    return;
                }
            }
            if (step.id === 'face') {
                // Require passport photo uploaded AND the confirm checkbox ticked.
                var faceChk   = document.getElementById('lg-face-confirm-chk');
                var passPreviewEl = document.getElementById('lg-face-passport-preview');
                var photoUploaded = passPreviewEl && passPreviewEl.src && passPreviewEl.src.length > 4;
                if (!photoUploaded) {
                    showToast('⚠️ Please upload your passport/ID photo before continuing.', 'warning', 4000);
                    return;
                }
                if (!faceChk || !faceChk.checked) {
                    showToast('⚠️ Please complete the identity verification and tick the confirmation checkbox before continuing.', 'warning', 4000);
                    return;
                }
            }
            if (step.id === 'screenshare') {
                var ssLive = state.screenStream &&
                             state.screenStream.getVideoTracks &&
                             state.screenStream.getVideoTracks().length > 0 &&
                             state.screenStream.getVideoTracks()[0].readyState === 'live';
                if (!ssLive) {
                    showToast('⚠️ Please share your screen before continuing. Click "Share My Screen" and select your entire screen.', 'warning', 4000);
                    return;
                }
            }
            if (step.id === 'ready') { wiz_complete(); return; }
            wizardStep++; renderWizardStep(wizardStep);
        };
        back.onclick = function() { wizardStep--; renderWizardStep(wizardStep); };
    }

    // ---- Step content ----
    function wiz_welcome() {
        var brand = cfg.brandname || 'LearnGuard';
        var inst  = cfg.instructions
            ? '<div style="background:#f0f4ff;border-left:4px solid ' + (cfg.brandprimary || '#0f6cbf') +
              ';padding:12px 16px;border-radius:4px;margin:14px 0;">' +
              '<strong>📋 Exam Instructions</strong><br>' + cfg.instructions + '</div>' : '';
        return '<div id="lg-welcome-tophint" style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;' +
            'padding:8px 12px;font-size:12px;color:#856404;margin-bottom:12px;text-align:center;">' +
            '↓ Scroll down to read everything below — Continue unlocks once you reach the bottom.' +
            '</div>' +
            '<div style="text-align:center;margin-bottom:16px;">' +
            '<div style="font-size:52px;margin-bottom:8px;">🛡</div>' +
            '<h3 style="margin:0 0 4px;font-size:18px;">Welcome to Your Proctored Exam</h3>' +
            '<p style="color:#6c757d;font-size:13px;margin:0;">Powered by ' + brand + ' — AI-assisted integrity verification</p>' +
            '</div>' + inst +

            // What is precheck
            '<div style="background:#f0f4ff;border-radius:8px;padding:14px 16px;margin-bottom:14px;">' +
            '<strong style="font-size:14px;color:#0f6cbf;">ℹ️ What is the Pre-Check?</strong>' +
            '<p style="margin:6px 0 0;font-size:13px;color:#495057;line-height:1.6;">Before your exam starts, we run a series of automated checks to verify your identity, ' +
            'confirm your environment is suitable, and set up monitoring. This typically takes <strong>2–5 minutes</strong>. ' +
            'Each check must pass before you can proceed.</p>' +
            '</div>' +

            // What you need
            '<div style="background:#d4edda;border-radius:8px;padding:14px 16px;margin-bottom:14px;">' +
            '<strong style="font-size:14px;color:#155724;">✅ What You Need Before Starting</strong>' +
            '<ul style="margin:8px 0 0;padding-left:18px;font-size:13px;color:#155724;line-height:2;">' +
            '<li>📷 A working <strong>webcam</strong> (built-in or external)</li>' +
            (cfg.enableaudio ? '<li>🎤 A working <strong>microphone</strong></li>' : '') +
            '<li>🌐 A stable <strong>internet connection</strong></li>' +
            '<li>🪪 A valid <strong>photo ID</strong> — national ID, passport, driver\'s licence, or official employee/staff ID card</li>' +
            '<li>🤫 A <strong>quiet, private room</strong> — no other people allowed</li>' +
            '<li>💡 <strong>Good lighting</strong> — your face must be clearly visible</li>' +
            '<li>💻 A <strong>desktop or laptop computer</strong> (mobile phones are not permitted)</li>' +
            '</ul>' +
            '</div>' +

            // Steps overview
            '<strong style="font-size:13px;color:#6c757d;text-transform:uppercase;letter-spacing:.5px;">Steps you will complete:</strong>' +
            '<div style="display:flex;flex-wrap:wrap;gap:8px;margin:8px 0 14px;">' +
            lgWizBadge('🗂', 'Close Tabs') + lgWizBadge('🌐', 'Browser') + lgWizBadge('📡', 'Internet') +
            lgWizBadge('📷', 'Camera') +
            (cfg.enableaudio ? lgWizBadge('🎤', 'Microphone') : '') +
            lgWizBadge('🪪', 'Photo ID') + lgWizBadge('👤', 'Face Check') +
            lgWizBadge('🖥', 'Screen Share') +
            (cfg.enableenvrecord ? lgWizBadge('🔭', '360° Record') : '') +
            lgWizBadge('🔒', 'Privacy') +
            (cfg.enablefullscreen ? lgWizBadge('⛶', 'Fullscreen') : '') +
            lgWizBadge('✅', 'Begin Exam') +
            '</div>' +

            // During exam warning
            '<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:12px 16px;font-size:13px;">' +
            '<strong>⚠️ During the exam these are continuously active:</strong><br>' +
            '<span style="color:#856404;">Fullscreen enforcement • Webcam monitoring • Tab-switch detection • ' +
            'Key-blocking • Clipboard monitoring • Audio monitoring • Object detection (phone/book) • ' +
            'Face identity matching • Screen sharing</span>' +
            '</div>' +

            '<p id="lg-welcome-hint" style="font-size:12px;color:#856404;margin-top:10px;text-align:center;">' +
            '↓ Scroll down to read all instructions, then Continue will be enabled.' +
            '</p>';
    }

    function wiz_welcome_hooks() {
        var body    = el('lg-wiz-body');
        var next    = el('lg-wiz-next-btn');
        var hint    = document.getElementById('lg-welcome-hint');
        var topHint = document.getElementById('lg-welcome-tophint');
        if (!body || !next) { return; }

        function unlock() {
            next.disabled = false;
            if (hint) { hint.style.display = 'none'; }
            if (topHint) { topHint.style.display = 'none'; }
        }

        // Unlock when the wizard body is scrolled to bottom.
        body.addEventListener('scroll', function() {
            if (this.scrollTop + this.clientHeight >= this.scrollHeight - 20) {
                unlock();
            }
        });
        // Unlock immediately if content is short enough not to need scrolling.
        setTimeout(function() {
            if (body.scrollHeight <= body.clientHeight) { unlock(); }
        }, 100);
    }

    function lgWizBadge(icon, label) {
        return '<span style="background:#fff;border:1px solid #dee2e6;border-radius:20px;padding:4px 10px;' +
            'font-size:12px;font-weight:600;color:#495057;white-space:nowrap;">' + icon + ' ' + label + '</span>';
    }

    function escHtml(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function wiz_closetabs_html() {
        return '<div id="lg-closetabs-tophint" style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;' +
            'padding:8px 12px;font-size:12px;color:#856404;margin-bottom:12px;text-align:center;">' +
            '↓ Scroll down to read all items below — the checkbox unlocks once you reach the bottom.' +
            '</div>' +
            '<p style="margin-bottom:16px;font-size:15px;">Before the exam begins, you must <strong>close all other browser tabs, windows, and applications</strong> running on your computer.</p>' +
            '<div style="display:flex;flex-direction:column;gap:10px;margin-bottom:20px;">' +

            // Item 1
            '<div style="display:flex;align-items:flex-start;gap:12px;padding:14px 16px;background:#fff3cd;border:1px solid #ffc107;border-radius:10px;">' +
            '<span style="font-size:24px;flex-shrink:0;">🗂</span>' +
            '<div><strong style="font-size:14px;">Close all other browser tabs</strong>' +
            '<p style="margin:4px 0 0;font-size:13px;color:#6c757d;">Only this exam tab should be open. Use Ctrl+W (Windows) or Cmd+W (Mac) to close other tabs.</p></div>' +
            '</div>' +

            // Item 2
            '<div style="display:flex;align-items:flex-start;gap:12px;padding:14px 16px;background:#fff3cd;border:1px solid #ffc107;border-radius:10px;">' +
            '<span style="font-size:24px;flex-shrink:0;">💻</span>' +
            '<div><strong style="font-size:14px;">Close all other applications</strong>' +
            '<p style="margin:4px 0 0;font-size:13px;color:#6c757d;">Close messaging apps, email clients, music players, document editors, and any other software you have open.</p></div>' +
            '</div>' +

            // Item 3
            '<div style="display:flex;align-items:flex-start;gap:12px;padding:14px 16px;background:#fff3cd;border:1px solid #ffc107;border-radius:10px;">' +
            '<span style="font-size:24px;flex-shrink:0;">📱</span>' +
            '<div><strong style="font-size:14px;">Put your phone away</strong>' +
            '<p style="margin:4px 0 0;font-size:13px;color:#6c757d;">Place your phone face-down or out of reach. Using your phone during the exam is a violation and will be flagged.</p></div>' +
            '</div>' +

            // Item 4
            '<div style="display:flex;align-items:flex-start;gap:12px;padding:14px 16px;background:#fff3cd;border:1px solid #ffc107;border-radius:10px;">' +
            '<span style="font-size:24px;flex-shrink:0;">🔕</span>' +
            '<div><strong style="font-size:14px;">Disable notifications</strong>' +
            '<p style="margin:4px 0 0;font-size:13px;color:#6c757d;">Turn on Do Not Disturb mode to prevent pop-ups or notification sounds during the exam.</p></div>' +
            '</div></div>' +

            // Confirmation checkbox
            '<div style="background:#d4edda;border:2px solid #28a745;border-radius:10px;padding:16px 18px;">' +
            '<label style="display:flex;align-items:flex-start;gap:12px;cursor:pointer;">' +
            '<input type="checkbox" id="lg-closetabs-chk" disabled ' +
            'style="width:20px;height:20px;margin-top:2px;flex-shrink:0;accent-color:#28a745;cursor:not-allowed;opacity:0.5;">' +
            '<span style="font-size:14px;font-weight:600;color:#155724;">' +
            'I confirm I have closed all other tabs, applications and messaging software. ' +
            'My phone is put away and notifications are silenced. I am ready to begin the precheck.' +
            '</span></label></div>' +
            '<p id="lg-closetabs-hint" style="font-size:12px;color:#856404;margin-top:8px;">' +
            '↓ Scroll down to read all items above, then tick the checkbox.' +
            '</p>';
    }

    function wiz_closetabs() {
        var body    = el('lg-wiz-body');
        var chk     = document.getElementById('lg-closetabs-chk');
        var hint    = document.getElementById('lg-closetabs-hint');
        var topHint = document.getElementById('lg-closetabs-tophint');
        var nextBtn = el('lg-wiz-next-btn');
        if (!chk || !nextBtn) { return; }

        function unlockCheckbox() {
            chk.disabled = false;
            chk.style.cursor = 'pointer';
            chk.style.opacity = '1';
            if (hint) { hint.style.display = 'none'; }
            if (topHint) { topHint.style.display = 'none'; }
        }

        // Unlock when the wizard body is scrolled to bottom.
        // Store the handler reference so renderWizardStep can remove it before
        // rendering the next step — otherwise a checked closetabs checkbox
        // leaks into subsequent steps and enables Continue on scroll.
        var closetabsScrollFn = function() {
            if (this.scrollTop + this.clientHeight >= this.scrollHeight - 20) {
                unlockCheckbox();
            }
        };
        var closetabsTimer = null;
        if (body) {
            body.addEventListener('scroll', closetabsScrollFn);
            // Unlock immediately if content is short enough not to need scrolling.
            closetabsTimer = setTimeout(function() {
                if (body.scrollHeight <= body.clientHeight) { unlockCheckbox(); }
            }, 100);
        }
        wizScrollCleanup = function() {
            if (body) { body.removeEventListener('scroll', closetabsScrollFn); }
            if (closetabsTimer) { clearTimeout(closetabsTimer); closetabsTimer = null; }
        };

        nextBtn.disabled = true;
        chk.addEventListener('change', function() {
            nextBtn.disabled = !this.checked;
        });
    }

    function wiz_autocheck(id) {
        return '<div id="lg-chk-' + id + '" style="min-height:60px;padding:8px 0;">Checking…</div>';
    }

    function wiz_chkResult(ok, msg) {
        var bg  = ok ? '#d4edda' : '#fff3cd';
        var brd = ok ? '#c3e6cb' : '#ffc107';
        var col = ok ? '#155724' : '#856404';
        var ico = ok ? '✅' : '⚠️';
        return '<div style="background:' + bg + ';border:1px solid ' + brd + ';border-radius:6px;' +
               'padding:10px 14px;color:' + col + ';font-size:14px;margin-top:8px;">' + ico + ' ' + msg + '</div>';
    }

    function wiz_browser() {
        var issues = [];
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { issues.push('Camera API unavailable'); }
        if (!window.AudioContext && !window.webkitAudioContext) { issues.push('Audio API unavailable'); }
        if (typeof fetch === 'undefined') { issues.push('Browser too old — please use Chrome, Firefox, or Edge'); }
        var r = el('lg-chk-browser');
        var ua = navigator.userAgent;
        var name = ua.includes('Firefox') ? 'Firefox' : ua.includes('Edg') ? 'Edge' :
                   ua.includes('Chrome') ? 'Chrome' : ua.includes('Safari') ? 'Safari' : 'Browser';
        if (r) { r.innerHTML = issues.length
            ? wiz_chkResult(false, 'Issues found: ' + issues.join('. ') + ' You may still continue.')
            : wiz_chkResult(true, 'Compatible (' + name + ')'); }
    }

    function wiz_internet() {
        var r = el('lg-chk-internet');
        if (!navigator.onLine) {
            if (r) { r.innerHTML = wiz_chkResult(false, 'No internet connection detected!'); }
            return;
        }
        var t0 = Date.now();
        fetch(window.location.origin + '/favicon.ico?t=' + t0, {cache: 'no-store'})
            .then(function() {
                var ms = Date.now() - t0;
                var qual = ms < 200 ? 'Excellent' : ms < 500 ? 'Good' : ms < 1000 ? 'Moderate' : 'Slow';
                var ok   = ms < 1500;
                if (r) { r.innerHTML = wiz_chkResult(ok, qual + ' connection (' + ms + ' ms response time)'); }
            })
            .catch(function() {
                if (r) { r.innerHTML = wiz_chkResult(true, 'Connected (latency unmeasured)'); }
            });
    }

    function wiz_camera_html() {
        return '<p>We need webcam access to monitor you during the exam.</p>' +
            '<div style="text-align:center;margin:12px 0;">' +
            '<video id="lg-wiz-vid" autoplay playsinline muted ' +
            'style="width:100%;max-width:320px;border-radius:8px;border:2px solid #dee2e6;background:#111;"></video>' +
            '</div>' +
            '<div id="lg-cam-status" style="font-size:14px;color:#6c757d;text-align:center;">Requesting camera…</div>';
    }

    function wiz_camera() {
        var statusEl = el('lg-cam-status');
        navigator.mediaDevices.getUserMedia({video: {width: 320, height: 240}})
            .then(function(stream) {
                cameraStream = stream;
                var vid = el('lg-wiz-vid');
                if (vid) {
                    vid.srcObject = stream;
                    vid.play().catch(function(){});
                    // Only confirm "working" once the video is actually producing frames.
                    var checkFrames = function() {
                        if (vid.videoWidth > 0 && vid.readyState >= 2) {
                            if (statusEl) { statusEl.innerHTML = wiz_chkResult(true, 'Camera is working ✓'); }
                            el('lg-wiz-next-btn').disabled = false;
                        } else {
                            setTimeout(checkFrames, 200);
                        }
                    };
                    checkFrames();
                } else {
                    if (statusEl) { statusEl.innerHTML = wiz_chkResult(true, 'Camera is working ✓'); }
                    el('lg-wiz-next-btn').disabled = false;
                }
            })
            .catch(function(e) {
                // Camera is MANDATORY — block Next until permission is granted.
                if (statusEl) { statusEl.innerHTML = wiz_chkResult(false,
                    '<strong>Camera access is required for this exam.</strong><br>' +
                    'Please allow camera access in your browser, then click "Retry" below.'); }
                // Keep Next DISABLED — add a retry button instead
                var retryBtn = document.getElementById('wiz-cam-retry-btn');
                if (!retryBtn) {
                    retryBtn = document.createElement('button');
                    retryBtn.id = 'wiz-cam-retry-btn';
                    retryBtn.textContent = '↺ Retry Camera';
                    retryBtn.style.cssText = 'margin-top:10px;background:#0f6cbf;color:#fff;border:none;' +
                        'border-radius:8px;padding:10px 20px;cursor:pointer;font-size:14px;font-weight:700;';
                    retryBtn.addEventListener('click', function() {
                        retryBtn.parentNode.removeChild(retryBtn);
                        wiz_camera();
                    });
                    if (statusEl) { statusEl.parentNode.appendChild(retryBtn); }
                }
                el('lg-wiz-next-btn').disabled = true;
            });
    }

    function wiz_audio_html() {
        return '<p>Microphone required to monitor audio. <strong>Audio is not recorded</strong> — volume only.</p>' +
            '<ul style="color:#555;font-size:13px;margin-bottom:14px;line-height:2;">' +
            '<li>Stay quiet for 3 seconds (background noise check)</li>' +
            '<li>Then speak briefly to confirm your mic works</li>' +
            '</ul>' +
            '<div style="display:flex;align-items:center;gap:10px;margin:14px 0;">' +
            '<span style="font-size:20px;">🎤</span>' +
            '<div style="flex:1;background:#e9ecef;border-radius:8px;height:22px;overflow:hidden;">' +
            '<div id="lg-audio-meter" style="height:100%;background:#28a745;width:0%;transition:width .15s;border-radius:8px;"></div>' +
            '</div><span id="lg-audio-lbl" style="font-size:12px;min-width:70px;">Checking…</span></div>' +
            '<div id="lg-audio-prompt" style="display:none;background:#fff3cd;border:1px solid #ffc107;' +
            'border-radius:6px;padding:10px 14px;font-size:14px;margin-bottom:8px;"></div>' +
            '<div id="lg-audio-status" style="font-size:14px;color:#6c757d;">Requesting microphone…</div>';
    }

    function wiz_audio() {
        var statusEl = el('lg-audio-status');
        var promptEl = el('lg-audio-prompt');
        var meter    = el('lg-audio-meter');
        var lbl      = el('lg-audio-lbl');
        var BG_THRESHOLD = 22; // background-noise gate — Continue stays locked above this.
        var REQUIRED_QUIET_MS = 4000; // background must stay below threshold continuously for ~4s.

        // autoGainControl OFF — same reasoning as the exam-time noise monitors: with it
        // on, the browser boosts a near-silent room toward a target loudness, which could
        // keep this gate permanently locked ("stay quiet") even in a genuinely quiet room.
        navigator.mediaDevices.getUserMedia({audio: {autoGainControl: false, noiseSuppression: true, echoCancellation: true}})
            .then(function(stream) {
                var AC = window.AudioContext || window.webkitAudioContext;
                if (!AC) {
                    if (statusEl) { statusEl.innerHTML = wiz_chkResult(true, 'Mic available (AudioContext not supported for meter).'); }
                    el('lg-wiz-next-btn').disabled = false;
                    wizAudioCleanup = function() { stream.getTracks().forEach(function(t) { t.stop(); }); };
                    return;
                }
                var ctx = new AC(); var analyser = ctx.createAnalyser();
                analyser.fftSize = 512;
                ctx.createMediaStreamSource(stream).connect(analyser);
                var quietStreakStart = null, quietConfirmed = false, spoken = false;
                if (statusEl) { statusEl.textContent = '🔇 Checking background noise — please stay quiet…'; }
                var iv = setInterval(function() {
                    var data = new Uint8Array(analyser.frequencyBinCount);
                    analyser.getByteFrequencyData(data);
                    var level = Math.round(data.reduce(function(a,b){return a+b;}, 0) / data.length);
                    var pct   = Math.min(level * 4, 100);
                    if (meter) { meter.style.width = pct + '%'; meter.style.background = level > 20 ? '#dc3545' : '#28a745'; }
                    if (lbl)   { lbl.textContent = level > 20 ? '🔊 Loud' : level > 5 ? '🔉' : '🔇 Quiet'; }

                    if (!quietConfirmed) {
                        if (level > BG_THRESHOLD) {
                            // Noise detected — restart the continuous-quiet timer and keep Continue locked.
                            quietStreakStart = null;
                            if (statusEl) { statusEl.innerHTML = wiz_chkResult(false, '⚠️ Background noise too high. Please move to a quieter location — checking continuously…'); }
                            el('lg-wiz-next-btn').disabled = true;
                            return;
                        }
                        if (quietStreakStart === null) { quietStreakStart = Date.now(); }
                        if (Date.now() - quietStreakStart >= REQUIRED_QUIET_MS) {
                            quietConfirmed = true;
                            if (promptEl) { promptEl.style.display = 'block'; promptEl.textContent = '✓ Quiet. Now speak briefly to confirm your mic works…'; }
                            if (statusEl) { statusEl.textContent = '🎤 Please speak now…'; }
                        } else if (statusEl) {
                            statusEl.textContent = '🔇 Checking background noise — please stay quiet… (' +
                                Math.ceil((REQUIRED_QUIET_MS - (Date.now() - quietStreakStart)) / 1000) + 's)';
                        }
                    } else if (!spoken && level > 14) {
                        spoken = true; clearInterval(iv);
                        if (statusEl) { statusEl.innerHTML = wiz_chkResult(true, '✓ Microphone working. Background is quiet.'); }
                        if (promptEl) { promptEl.style.display = 'none'; }
                        el('lg-wiz-next-btn').disabled = false;
                    }
                }, 300);
                var toId = setTimeout(function() {
                    if (!spoken && quietConfirmed) {
                        clearInterval(iv);
                        if (statusEl) { statusEl.innerHTML = wiz_chkResult(true, 'Microphone connected (no speech detected — ensure your mic works).'); }
                        el('lg-wiz-next-btn').disabled = false;
                    }
                }, 15000);
                wizAudioCleanup = function() {
                    clearInterval(iv);
                    clearTimeout(toId);
                    stream.getTracks().forEach(function(t) { t.stop(); });
                    try { ctx.close(); } catch (e) {}
                };
            })
            .catch(function() {
                if (statusEl) { statusEl.innerHTML = wiz_chkResult(false,
                    '<strong>Microphone access denied.</strong> Audio monitoring will be disabled.<br>' +
                    'This will be noted by the proctor.'); }
                // Mic not strictly mandatory (some exams don't require it) — allow continue with warning
                el('lg-wiz-next-btn').disabled = false;
                var retryBtn = document.getElementById('wiz-audio-retry-btn');
                if (!retryBtn) {
                    retryBtn = document.createElement('button');
                    retryBtn.id = 'wiz-audio-retry-btn';
                    retryBtn.textContent = '↺ Retry Microphone';
                    retryBtn.style.cssText = 'margin-top:10px;background:#0f6cbf;color:#fff;border:none;' +
                        'border-radius:8px;padding:10px 20px;cursor:pointer;font-size:14px;font-weight:700;';
                    retryBtn.addEventListener('click', function() {
                        retryBtn.parentNode.removeChild(retryBtn);
                        if (statusEl) { statusEl.textContent = 'Requesting microphone…'; }
                        wiz_audio();
                    });
                    if (statusEl) { statusEl.parentNode.appendChild(retryBtn); }
                }
            });
    }

    function wiz_face_html() {
        return '<p style="margin-bottom:10px;">Your identity will be verified by matching your passport/ID photo to your live camera feed.</p>' +

            // Step 1: Upload passport photo (REQUIRED)
            '<div style="border:2px solid #0f6cbf;border-radius:8px;padding:14px;margin-bottom:14px;background:#f0f7ff;">' +
            '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">' +
            '<strong style="font-size:13px;">Step 1 — Upload Passport/ID Photo</strong>' +
            '<span style="background:#dc3545;color:#fff;font-size:10px;font-weight:700;padding:2px 7px;border-radius:10px;">REQUIRED</span>' +
            '</div>' +
            '<p style="font-size:12px;color:#6c757d;margin:0 0 6px;">Upload a clear, recent passport-style photo showing your face and any ID text visible.</p>' +
            '<p style="font-size:11px;color:#856404;background:#fff8e1;border-radius:4px;padding:4px 8px;margin:0 0 10px;">' +
            '⚠️ Use an original photo — background-removed, edited, or filtered images will fail detection.</p>' +
            '<div style="display:flex;gap:10px;align-items:flex-start;">' +
            '<div style="flex:1;">' +
            '<input type="file" id="lg-face-passport-input" accept="image/*" disabled ' +
            'style="width:100%;font-size:13px;padding:4px;border:1px solid #ccc;border-radius:4px;opacity:0.5;cursor:not-allowed;" ' +
            'title="Waiting for detection system to load…">' +
            '</div>' +
            '<img id="lg-face-passport-preview" src="" alt="" style="display:none;width:80px;height:80px;object-fit:cover;border-radius:6px;border:2px solid #28a745;">' +
            '</div>' +
            '<div id="lg-passport-status" style="font-size:12px;margin-top:8px;">' +
            '<span style="color:#856404;background:#fff3cd;border:1px solid #ffc107;border-radius:4px;padding:4px 10px;display:inline-block;">⏳ Loading identity verification system — please wait…</span>' +
            '</div>' +
            '</div>' +

            // Step 2: Live camera + face match (only active after passport upload)
            '<div id="lg-face-step2" style="border:1px solid #dee2e6;border-radius:8px;padding:14px;background:#f8f9fa;opacity:.5;pointer-events:none;">' +
            '<strong style="font-size:13px;">Step 2 — Live Face Match</strong>' +
            '<p style="font-size:12px;color:#6c757d;margin:6px 0 10px;">Look directly at your camera. Good lighting, no glasses or face covering.</p>' +
            '<div style="display:flex;gap:12px;justify-content:center;align-items:flex-start;flex-wrap:wrap;">' +
            '<div style="text-align:center;">' +
            '<video id="lg-wiz-vid2" autoplay playsinline muted ' +
            'style="width:200px;border-radius:8px;border:2px solid #dee2e6;background:#111;display:block;"></video>' +
            '<small style="color:#6c757d;font-size:11px;">Live camera</small></div>' +
            '<div style="text-align:center;">' +
            '<img id="lg-passport-thumb" src="" alt="" style="display:none;width:200px;height:150px;object-fit:cover;border-radius:8px;border:2px solid #0f6cbf;">' +
            '<small style="color:#6c757d;font-size:11px;">Uploaded passport</small></div>' +
            '</div>' +
            '<div id="lg-face-match-bar" style="margin-top:12px;display:none;">' +
            '<div style="display:flex;justify-content:space-between;margin-bottom:4px;">' +
            '<span style="font-size:13px;font-weight:700;">Face Match Score</span>' +
            '<span id="lg-face-match-pct" style="font-size:20px;font-weight:900;">—</span></div>' +
            '<div style="background:#dee2e6;border-radius:10px;height:14px;overflow:hidden;">' +
            '<div id="lg-face-match-fill" style="height:100%;width:0%;background:#28a745;border-radius:10px;transition:width .5s;"></div>' +
            '</div>' +
            '<div id="lg-face-match-msg" style="font-size:12px;margin-top:6px;text-align:center;"></div>' +
            '</div>' +
            '<div id="lg-face-status" style="text-align:center;font-size:13px;color:#6c757d;margin-top:8px;"></div>' +
            '<div id="lg-liveness-prompt" style="display:none;margin-top:10px;text-align:center;background:#fff3cd;padding:10px;border-radius:8px;"></div>' +
            '</div>' +

            // Confirmation box — visible from the start so students know what they need to do.
            // The checkbox stays disabled until a passport photo has been uploaded.
            '<div id="lg-face-confirm-wrap" style="margin-top:14px;border:2px solid #0f6cbf;border-radius:8px;padding:14px;background:#f0f7ff;">' +
            '<p id="lg-face-confirm-title" style="font-size:13px;font-weight:700;color:#0f6cbf;margin:0 0 6px;">📸 Confirm your identity</p>' +
            '<p id="lg-face-confirm-body" style="font-size:13px;color:#495057;margin:0 0 12px;">' +
            'Upload your passport or ID photo above, then tick the box below to confirm your identity and proceed.</p>' +
            '<label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;margin-bottom:10px;">' +
            '<input type="checkbox" id="lg-face-confirm-chk" disabled style="width:18px;height:18px;margin-top:2px;flex-shrink:0;opacity:0.4;cursor:not-allowed;">' +
            '<span id="lg-face-confirm-lbl" style="font-size:13px;color:#6c757d;">Upload your photo above first — then you can tick this box to continue.</span>' +
            '</label>' +
            '<div id="lg-approval-status" style="display:none;background:#d4edda;border-radius:6px;padding:10px;font-size:13px;color:#155724;text-align:center;margin-bottom:8px;">' +
            '⏳ Waiting for invigilator approval… Chat with your proctor below while you wait.' +
            '</div>' +
            '<div id="lg-face-chat-btn-wrap" style="display:none;">' +
            '<button onclick="window.lgToggleChat()" style="width:100%;background:#0d6efd;color:#fff;border:none;border-radius:8px;padding:10px;font-size:14px;font-weight:700;cursor:pointer;">💬 Chat with Proctor</button>' +
            '</div>' +
            '</div>';
    }

    var passportDescriptor = null;
    var faceMatchAttempts  = 0;
    var faceApprovalPending = false;

    function wiz_face() {
        var statusEl     = el('lg-face-status');
        var livenessEl   = el('lg-liveness-prompt');
        var confirmWrap  = el('lg-face-confirm-wrap');
        var confirmChk   = el('lg-face-confirm-chk');
        var approvalStat = el('lg-approval-status');
        var chatBtnWrap  = el('lg-face-chat-btn-wrap');
        var nextBtn      = el('lg-wiz-next-btn');
        var passInput    = el('lg-face-passport-input');
        var passPreview  = el('lg-face-passport-preview');
        var passThumb    = el('lg-passport-thumb');
        var passStat     = el('lg-passport-status');
        var matchBar     = el('lg-face-match-bar');
        var matchPct     = el('lg-face-match-pct');
        var matchFill    = el('lg-face-match-fill');
        var matchMsg     = el('lg-face-match-msg');
        var step2        = el('lg-face-step2');
        var vid2         = el('lg-wiz-vid2');

        // True once showFallback() has fired for this precheck — i.e. face-api never
        // successfully detected/matched a face at all. Read by the checkbox handler
        // below to decide whether the AUTO-mode self-tick path needs to flag the
        // eventual attempt for mandatory post-exam identity review, since no proctor
        // and no automatic system ever actually confirmed this student was present.
        var faceFallbackActive = false;

        // MANDATORY: Next is disabled until passport is uploaded
        if (nextBtn) { nextBtn.disabled = true; }

        // Checkbox → in AUTO mode: proceed immediately. In MANUAL mode: request invigilator approval.
        if (confirmChk) {
            confirmChk.addEventListener('change', function() {
                if (!this.checked) { return; }

                // AUTO mode: no invigilator needed — just enable next. But if face-api
                // never actually detected/matched a face at all (faceFallbackActive),
                // this self-tick is the ONLY thing standing in for identity
                // verification — flag the eventual attempt so it's impossible to miss
                // on review, rather than silently indistinguishable from a normal,
                // successfully-verified student.
                if (cfg.faceapprovalmode === 'auto' || cfg.faceapprovalmode !== 'manual') {
                    if (faceFallbackActive) { pendingIdentityUnverified = true; }
                    if (nextBtn) { nextBtn.disabled = false; }
                    return;
                }

                // MANUAL mode: request invigilator approval.
                faceApprovalPending = true;
                if (approvalStat) { approvalStat.style.display = 'block'; }
                if (chatBtnWrap)  { chatBtnWrap.style.display  = 'block'; }
                if (nextBtn) { nextBtn.disabled = true; }

                // Get best match % from match bar
                var bestPct = parseInt((el('lg-face-match-pct') || {}).textContent || '0', 10) || 0;

                // Get passport data if uploaded
                var passData = '';
                if (el('lg-face-passport-preview') && el('lg-face-passport-preview').src) {
                    passData = el('lg-face-passport-preview').src;
                }

                // Call WS — no attemptid needed, keyed by cmid + current user
                callWS('mod_learnguard_request_face_approval', {
                    cmid:         cfg.cmid,
                    passportdata: passData,
                    matchpct:     bestPct,
                }).then(function(resp) {
                    var approvalId = resp.approvalid;
                    showToast('✅ Approval request sent! Your invigilator has been notified.', 'info', 8000);

                    // Poll every 8s for approval decision
                    var poll = setInterval(function() {
                        callWS('mod_learnguard_check_face_approval', {cmid: cfg.cmid})
                            .then(function(r) {
                                // Display proctor chat messages in the chat area
                                if (r.messages && r.messages.length) {
                                    var chatHistory = document.getElementById('lg-fa-chat-hist');
                                    if (!chatHistory) {
                                        // Create chat history div inside chat btn wrap
                                        var hist = document.createElement('div');
                                        hist.id = 'lg-fa-chat-hist';
                                        hist.style.cssText = 'max-height:140px;overflow-y:auto;background:#f8f9fa;border-radius:6px;padding:8px;margin-bottom:8px;font-size:12px;display:flex;flex-direction:column;gap:6px;';
                                        if (chatBtnWrap) { chatBtnWrap.insertBefore(hist, chatBtnWrap.firstChild); }
                                        chatHistory = hist;
                                    }
                                    chatHistory.innerHTML = '';
                                    r.messages.forEach(function(m) {
                                        var d = document.createElement('div');
                                        var mine = (m.sendertype === 'student');
                                        d.style.cssText = 'padding:5px 8px;border-radius:6px;font-size:12px;max-width:85%;align-self:' +
                                            (mine ? 'flex-end;background:#0f6cbf;color:#fff;' : 'flex-start;background:#e9ecef;color:#212529;');
                                        d.innerHTML = '<div style="font-size:10px;opacity:.7;">' +
                                            (mine ? 'You' : m.sendername) + '</div>' +
                                            m.message.replace(/</g, '&lt;');
                                        chatHistory.appendChild(d);
                                    });
                                    chatHistory.scrollTop = chatHistory.scrollHeight;
                                }

                                if (r.status === 'approved') {
                                    clearInterval(poll);
                                    faceApprovalPending = false;
                                    if (approvalStat) {
                                        approvalStat.style.background = '#d4edda';
                                        approvalStat.innerHTML = '✅ <strong>Approved by invigilator!</strong> Click Next to continue.';
                                    }
                                    if (nextBtn) { nextBtn.disabled = false; }
                                    showToast('✅ Identity approved — you may now start the exam!', 'success', 0);
                                } else if (r.status === 'denied') {
                                    clearInterval(poll);
                                    if (approvalStat) {
                                        approvalStat.style.background = '#f8d7da';
                                        approvalStat.style.color = '#721c24';
                                        approvalStat.innerHTML = '❌ <strong>Access denied by invigilator.</strong>' +
                                            (r.reviewnote ? '<br>Reason: ' + r.reviewnote : '') +
                                            '<br>Please contact your proctor.';
                                    }
                                }
                            }).catch(function() {});
                    }, 8000);

                    // Add chat input below the approval status for student ↔ proctor messaging
                    if (chatBtnWrap) {
                        chatBtnWrap.innerHTML =
                            '<div id="lg-fa-chat-hist" style="max-height:120px;overflow-y:auto;background:#f8f9fa;border-radius:6px;padding:8px;margin-bottom:8px;font-size:12px;display:flex;flex-direction:column;gap:6px;"></div>' +
                            '<div style="display:flex;gap:6px;">' +
                            '<input type="text" id="lg-fa-student-msg" placeholder="Message the proctor…" maxlength="200" ' +
                            'style="flex:1;border:1px solid #dee2e6;border-radius:6px;padding:7px 10px;font-size:13px;">' +
                            '<button onclick="window.lgFaStudentSend()" ' +
                            'style="background:#0f6cbf;color:#fff;border:none;border-radius:6px;padding:7px 14px;cursor:pointer;font-size:13px;font-weight:700;">Send</button>' +
                            '</div>';

                        window.lgFaStudentSend = function() {
                            var inp = document.getElementById('lg-fa-student-msg');
                            if (!inp || !inp.value.trim()) { return; }
                            var msg = inp.value.trim();
                            inp.value = '';
                            callWS('mod_learnguard_face_approval_student_message', {
                                cmid: cfg.cmid, message: msg,
                            }).then(function() {
                                var hist = document.getElementById('lg-fa-chat-hist');
                                if (hist) {
                                    var d = document.createElement('div');
                                    d.style.cssText = 'padding:5px 8px;border-radius:6px;font-size:12px;max-width:85%;align-self:flex-end;background:#0f6cbf;color:#fff;';
                                    d.innerHTML = '<div style="font-size:10px;opacity:.7;">You</div>' + msg.replace(/</g, '&lt;');
                                    hist.appendChild(d);
                                    hist.scrollTop = hist.scrollHeight;
                                }
                            }).catch(function() {});
                        };

                        // Send on Enter key
                        var inp = document.getElementById('lg-fa-student-msg');
                        if (inp) {
                            inp.addEventListener('keydown', function(e) {
                                if (e.key === 'Enter') { window.lgFaStudentSend(); }
                            });
                        }
                    }
                }).catch(function() {
                    if (approvalStat) {
                        approvalStat.innerHTML = '⚠️ Could not send approval request. Please check your connection and try again.';
                    }
                });
            });
        }

        function unlockStep2() {
            if (step2) { step2.style.opacity = '1'; step2.style.pointerEvents = ''; }
            if (vid2 && cameraStream) { vid2.srcObject = cameraStream; vid2.play().catch(function(){}); }
            else if (vid2) {
                navigator.mediaDevices.getUserMedia({video: true}).then(function(s) {
                    cameraStream = s; vid2.srcObject = s; vid2.play().catch(function(){});
                }).catch(function() {});
            }
        }

        function showFallback(reason) {
            faceFallbackActive = true;
            // Update confirm box to "invigilator review" state.
            if (confirmWrap) {
                confirmWrap.style.borderColor = '#fd7e14';
                confirmWrap.style.background  = '#fff8f0';
                var ttl = document.getElementById('lg-face-confirm-title');
                var bdy = document.getElementById('lg-face-confirm-body');
                if (ttl) { ttl.textContent = '⚠️ Manual invigilator review required'; ttl.style.color = '#856404'; }
                if (bdy) { bdy.textContent = reason || 'We could not automatically verify your face. Upload your ID photo above if you have not already, then tick the box below — your invigilator will review your photo before the exam begins.'; }
            }
            if (statusEl) {
                statusEl.innerHTML = '<div style="font-size:13px;color:#856404;">' +
                    (reason || '⚠️ Could not complete automatic face match.') + '</div>';
            }
        }

        // ── Passport photo upload (REQUIRED) ───────────────────────────────
        if (passInput) {
            passInput.addEventListener('change', function() {
                var file = this.files[0];
                if (!file) { return; }
                var reader = new FileReader();
                reader.onload = function(ev) {
                    var dataUrl = ev.target.result;
                    if (passPreview) { passPreview.src = dataUrl; passPreview.style.display = 'block'; }
                    if (passThumb)   { passThumb.src   = dataUrl; passThumb.style.display   = 'block'; }
                    if (passStat) { passStat.textContent = '🔍 Extracting face from uploaded photo…'; }

                    // Unlock Step 2 now that a photo is uploaded
                    unlockStep2();

                    // Enable confirm checkbox now that a photo has been uploaded.
                    if (confirmChk) {
                        confirmChk.disabled = false;
                        confirmChk.style.opacity = '1';
                        confirmChk.style.cursor  = 'pointer';
                    }
                    var lbl = document.getElementById('lg-face-confirm-lbl');
                    if (lbl) { lbl.style.color = '#212529'; lbl.textContent = 'I confirm I have uploaded a valid ID photo and consent to my identity being verified.'; }

                    if (typeof faceapi !== 'undefined' && faceApiReady) {
                        var img = new Image();
                        img.onload = function() {
                            faceapi.detectSingleFace(img, new faceapi.TinyFaceDetectorOptions()).withFaceLandmarks(true).withFaceDescriptor()
                                .then(function(det) {
                                    if (!det) {
                                        if (passStat) { passStat.innerHTML = wiz_chkResult(false,
                                            '❌ No face detected in uploaded photo. Please use a clear, well-lit passport photo.'); }
                                        return;
                                    }
                                    passportDescriptor = det.descriptor;
                                    if (passStat) { passStat.innerHTML = wiz_chkResult(true, '✓ Face extracted. Now look at your camera for Step 2.'); }
                                    startLiveMatch();
                                }).catch(function() {
                                    if (passStat) { passStat.innerHTML = wiz_chkResult(false, '⚠️ Could not process photo. Try a clearer image.'); }
                                });
                        };
                        img.src = dataUrl;
                    } else {
                        if (passStat) { passStat.innerHTML = wiz_chkResult(true, '✓ Photo uploaded — will be reviewed by invigilator.'); }
                        // Store uploaded passport as snapshot for review
                        if (state.attemptid) {
                            callWS('mod_learnguard_send_snapshot', {
                                attemptid: state.attemptid, imagedata: dataUrl, event_type: 'photo_id',
                            }).catch(function() {});
                        }
                        showFallback(null);
                    }
                };
                reader.readAsDataURL(file);
            });
        }

        // ── Enable passport input once face-api has finished loading (or failed) ──
        function enablePassportInput(ready) {
            if (passInput) {
                passInput.disabled = false;
                passInput.style.opacity = '1';
                passInput.style.cursor = 'pointer';
                passInput.title = '';
            }
            if (passStat) {
                passStat.innerHTML = ready
                    ? '<span style="color:#155724;background:#d4edda;border:1px solid #c3e6cb;border-radius:4px;padding:4px 10px;display:inline-block;">✅ Detection system ready — upload your photo ID to continue.</span>'
                    : '<span style="color:#856404;background:#fff3cd;border:1px solid #ffc107;border-radius:4px;padding:4px 10px;display:inline-block;">⚠️ Photo not yet uploaded — this is required to continue.</span>';
            }
        }

        // ── Load face-api models in two phases so the file input is never blocked ──
        // Phase 1: two small models (~570 KB total) — unblocks passport upload within 3s.
        // Phase 2: large recognition net (~6 MB) loads in background while user uploads.
        function loadFaceModels(attempt) {
            // Hard 5-second timeout: if Phase 1 is still slow, unblock the user immediately.
            var phase1Fired = false;
            var phase1Timeout = setTimeout(function() {
                if (!phase1Fired) {
                    phase1Fired = true;
                    showFallback('⚠️ Auto face detection unavailable. Upload your photo and tick the checkbox below.');
                    enablePassportInput(false);
                }
            }, 5000);

            Promise.all([
                faceapi.nets.tinyFaceDetector.loadFromUri(cfg.modelurl),
                faceapi.nets.faceLandmark68TinyNet.loadFromUri(cfg.modelurl),
            ]).then(function() {
                clearTimeout(phase1Timeout);
                if (phase1Fired) { return; } // timeout already ran
                phase1Fired = true;
                if (statusEl) { statusEl.textContent = '✓ Detection ready — upload your passport photo above.'; }
                enablePassportInput(true);
                // Phase 2: recognition net loads quietly in background.
                // faceApiReady stays false until it finishes; if user uploads before it
                // completes, the fallback (manual invigilator review) activates automatically.
                faceapi.nets.faceRecognitionNet.loadFromUri(cfg.modelurl).then(function() {
                    faceApiReady = true;
                }).catch(function() { /* recognition unavailable — fallback already handles this */ });
            }).catch(function() {
                clearTimeout(phase1Timeout);
                if (phase1Fired) { return; }
                if (attempt < 3) {
                    if (statusEl) { statusEl.textContent = '⏳ Loading… (retry ' + (attempt + 1) + '/3)'; }
                    setTimeout(function() { loadFaceModels(attempt + 1); }, 1500);
                    return;
                }
                phase1Fired = true;
                showFallback('⚠️ Auto face detection unavailable. Upload your photo and tick the checkbox below.');
                enablePassportInput(false);
            });
        }

        if (typeof faceapi !== 'undefined' && cfg.modelurl && !faceApiReady) {
            loadFaceModels(1);
        } else if (faceApiReady) {
            if (statusEl) { statusEl.textContent = '✓ Face detection ready. Upload your passport photo above.'; }
            enablePassportInput(true);
        } else {
            showFallback('⚠️ Face detection could not load. Upload your photo and tick the checkbox to continue.');
            enablePassportInput(false);
        }

        // ── Live face matching against passport descriptor ────────────────
        function startLiveMatch() {
            if (!passportDescriptor || !faceApiReady) { return; }
            if (matchBar) { matchBar.style.display = 'block'; }
            if (statusEl) { statusEl.textContent = '🔍 Matching your face to the passport photo…'; }
            faceMatchAttempts = 0;

            var matchIv = setInterval(function() {
                if (!vid2 || vid2.readyState < 2) { return; }
                faceapi.detectSingleFace(vid2, new faceapi.TinyFaceDetectorOptions()).withFaceLandmarks(true).withFaceDescriptor()
                    .then(function(det) {
                        if (!det) {
                            if (statusEl) { statusEl.textContent = '🔍 No face detected — move into camera frame.'; }
                            return;
                        }
                        // Euclidean distance → similarity %
                        var dist = faceapi.euclideanDistance(passportDescriptor, det.descriptor);
                        var pct  = Math.max(0, Math.min(100, Math.round((1 - dist) * 100)));
                        if (matchPct)  { matchPct.textContent = pct + '%'; }
                        if (matchFill) {
                            matchFill.style.width = pct + '%';
                            matchFill.style.background = pct >= 50 ? '#28a745' : '#dc3545';
                        }

                        if (pct >= 50) {
                            clearInterval(matchIv);
                            if (matchMsg) { matchMsg.innerHTML = '<strong style="color:#28a745;">✅ Identity verified — ' + pct + '% match.</strong>'; }
                            if (statusEl) { statusEl.innerHTML = wiz_chkResult(true, 'Identity verified. Completing liveness check…'); }
                            // Store face descriptor for in-exam matching
                            storedFaceDescriptor = Array.from(det.descriptor);
                            try { sessionStorage.setItem('lg_face_desc_' + (cfg.userid || ''), JSON.stringify(storedFaceDescriptor)); } catch(e) {}
                            // Run liveness check
                            wiz_faceDetect(vid2, null, livenessEl, confirmChk, nextBtn);
                        } else {
                            faceMatchAttempts++;
                            var remaining = 3 - faceMatchAttempts;
                            if (matchMsg) {
                                matchMsg.innerHTML = '<strong style="color:#dc3545;">' + pct + '% — need 50%+</strong>. ' +
                                    (remaining > 0 ? remaining + ' attempt' + (remaining > 1 ? 's' : '') + ' remaining.' : '');
                            }
                            if (faceMatchAttempts >= 3) {
                                clearInterval(matchIv);
                                if (matchMsg) {
                                    matchMsg.innerHTML = '<strong style="color:#dc3545;">⚠️ Could not reach 50% after 3 attempts.</strong><br>' +
                                        'Tick the box below to request invigilator approval — you cannot start until they approve.';
                                }
                                showFallback(null);
                            }
                        }
                    }).catch(function() {});
            }, 2500);
        }
        // Expose for passport upload callback
        window._lgStartLiveMatch = startLiveMatch;
    }

    function wiz_faceDetect(vid2, statusEl, livenessEl, confirmChk, nextBtn) {
        var faceConfirmed = false, blinkDetected = false, prevEar = null, blocked = false;
        if (statusEl) { statusEl.textContent = '🔍 Scanning for your face…'; }
        var iv = setInterval(function() {
            if (blocked || !vid2 || vid2.readyState < 2) { return; }
            faceapi.detectAllFaces(vid2, new faceapi.TinyFaceDetectorOptions()).withFaceLandmarks(true).then(function(dets) {
                if (dets.length > 1) {
                    blocked = true; clearInterval(iv);
                    if (statusEl) { statusEl.innerHTML = wiz_chkResult(false, '🚫 ' + dets.length + ' faces detected. Ensure you are alone.'); }
                    if (confirmChk) { confirmChk.checked = false; confirmChk.disabled = true; }
                    if (nextBtn) { nextBtn.disabled = true; }
                    return;
                }
                if (!dets.length) {
                    if (!faceConfirmed && statusEl) {
                        statusEl.textContent = '🔍 No face detected — check lighting and camera position.';
                    }
                    return;
                }
                if (!faceConfirmed) {
                    faceConfirmed = true;
                    if (statusEl) { statusEl.innerHTML = wiz_chkResult(true, '✓ Face detected — one person confirmed.'); }
                    if (livenessEl) { livenessEl.style.display = 'block'; livenessEl.textContent = '👁 Liveness check: blink your eyes slowly once.'; }
                }
                var lm = dets[0].landmarks;
                var ear = function(pts) {
                    var h = Math.abs(pts[1].y - pts[5].y) + Math.abs(pts[2].y - pts[4].y);
                    var w = Math.abs(pts[0].x - pts[3].x);
                    return w > 0 ? h / (2 * w) : 1;
                };
                var avgEar = (ear(lm.getLeftEye()) + ear(lm.getRightEye())) / 2;
                if (prevEar !== null && prevEar > 0.2 && avgEar < 0.2) { blinkDetected = true; }
                prevEar = avgEar;
                if (blinkDetected) {
                    clearInterval(iv);
                    if (livenessEl) { livenessEl.style.background = '#d4edda'; livenessEl.innerHTML = '✅ <strong>Liveness confirmed</strong> — blink detected.'; }
                    // Store face descriptor for identity matching during exam.
                    if (vid2) { storeFaceDescriptor(vid2); }
                    // Update confirm box to green "verified" state.
                    var cw = el('lg-face-confirm-wrap');
                    if (cw) {
                        cw.style.borderColor = '#28a745';
                        cw.style.background  = '#f0fff4';
                        var ttl = document.getElementById('lg-face-confirm-title');
                        var bdy = document.getElementById('lg-face-confirm-body');
                        if (ttl) { ttl.textContent = '✅ Identity and liveness verified!'; ttl.style.color = '#155724'; }
                        if (bdy) { bdy.textContent = 'Tick the box below to confirm and proceed.'; }
                    }
                    if (confirmChk) {
                        confirmChk.checked  = false;
                        confirmChk.disabled = false;
                        confirmChk.style.opacity = '1';
                        confirmChk.style.cursor  = 'pointer';
                        var lbl = document.getElementById('lg-face-confirm-lbl');
                        if (lbl) { lbl.style.color = '#155724'; lbl.textContent = 'I confirm my identity has been verified and I am ready to proceed.'; }
                    }
                    // nextBtn stays disabled — checkbox change handler enables it when ticked.
                }
            }).catch(function() {});
        }, 500);
        setTimeout(function() {
            if (!blocked) {
                clearInterval(iv);
                if (!blinkDetected) {
                    // Liveness timed out — update confirm box to request invigilator review.
                    var cw = el('lg-face-confirm-wrap');
                    if (cw) {
                        cw.style.borderColor = '#fd7e14';
                        cw.style.background  = '#fff8f0';
                        var ttl = document.getElementById('lg-face-confirm-title');
                        var bdy = document.getElementById('lg-face-confirm-body');
                        if (ttl) { ttl.textContent = '⚠️ Liveness check could not be completed'; ttl.style.color = '#856404'; }
                        if (bdy) { bdy.textContent = 'Tick the box below — your invigilator will review your photo before the exam begins.'; }
                    }
                    if (!faceConfirmed && statusEl) {
                        statusEl.innerHTML = wiz_chkResult(false, 'Face or blink not confirmed. Tick the box below to proceed via invigilator approval.');
                    }
                }
            }
        }, 20000);
    }

    function wiz_screenshare_html() {
        return '<p style="margin-bottom:12px;">' +
            'This assessment requires <strong>screen sharing</strong> so the proctor can verify ' +
            'you are only working in the exam window. Your screen will be captured periodically.</p>' +
            '<div id="wiz-ss-status" style="background:#f8f9fa;border-radius:8px;padding:16px;' +
            'text-align:center;margin-bottom:14px;">' +
            '<div style="font-size:36px;margin-bottom:8px;">🖥</div>' +
            '<div id="wiz-ss-msg" style="font-size:14px;color:#6c757d;">Click the button below to share your screen.</div>' +
            '</div>' +
            '<div style="text-align:center;margin-bottom:12px;">' +
            '<button id="wiz-ss-btn" onclick="window.lgWizShareScreen()" ' +
            'style="background:#0f6cbf;color:#fff;border:none;border-radius:8px;' +
            'padding:12px 28px;font-size:15px;font-weight:700;cursor:pointer;">🖥 Share My Screen</button>' +
            '</div>' +
            '<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:10px 14px;font-size:13px;margin-top:8px;">' +
            '⚠️ <strong>Screen sharing is mandatory for this proctored exam.</strong> You must share your entire screen to proceed. Sharing only a tab or window is not accepted.' +
            '</div>';
    }

    function wiz_screenshare() {
        // If a screen share from the original session is already live (e.g. reinstatement
        // precheck), reuse it — don't prompt the browser for a new share permission.
        if (state.screenStream) {
            var existingTrack = state.screenStream.getVideoTracks()[0];
            if (existingTrack && existingTrack.readyState === 'live') {
                var existSurf = (existingTrack.getSettings) ? (existingTrack.getSettings().displaySurface || '') : '';
                if (existSurf === 'browser' || existSurf === 'window' || existSurf === 'application') {
                    // Wrong surface type from a previous share — stop it and force re-share.
                    state.screenStream.getTracks().forEach(function(t) { t.stop(); });
                    state.screenStream = null;
                    // Fall through to show the share button.
                } else {
                    var existBtn = document.getElementById('wiz-ss-btn');
                    var existBox = document.getElementById('wiz-ss-status');
                    var existNext = el('lg-wiz-next-btn');
                    if (existBox) {
                        existBox.style.background = '#d4edda';
                        existBox.innerHTML = '<div style="font-size:28px;margin-bottom:6px;">✅</div>' +
                            '<div style="font-size:14px;color:#155724;font-weight:700;">Screen sharing already active.</div>' +
                            '<div style="font-size:12px;color:#155724;margin-top:4px;">Your previous screen share session is still running.</div>';
                    }
                    if (existBtn) { existBtn.style.display = 'none'; }
                    if (existNext) { existNext.disabled = false; }
                    return;
                }
            }
        }
        window.lgWizShareScreen = function() {
            var btn    = document.getElementById('wiz-ss-btn');
            var msg    = document.getElementById('wiz-ss-msg');
            var box    = document.getElementById('wiz-ss-status');
            var nextBtn = el('lg-wiz-next-btn');
            if (btn) { btn.disabled = true; btn.textContent = 'Requesting…'; }
            if (msg) { msg.textContent = 'Select your ENTIRE SCREEN (not a window or tab)…'; }

            // Request full-screen sharing (monitor/screen, not a tab)
            var constraints = {video: true, audio: false};
            try { constraints.video = {displaySurface: 'monitor'}; } catch(e) {}
            navigator.mediaDevices.getDisplayMedia(constraints)
                .then(function(stream) {
                    var track = stream.getVideoTracks()[0];
                    var surf = (track && track.getSettings) ? (track.getSettings().displaySurface || '') : '';
                    // Block tab, window or application shares — only 'monitor'/'screen'/unknown pass.
                    if (surf === 'browser' || surf === 'window' || surf === 'application') {
                        stream.getTracks().forEach(function(t) { t.stop(); });
                        if (box) { box.style.background = '#fff3cd'; }
                        if (msg) { msg.innerHTML = '⚠️ You shared a <strong>' + (surf === 'browser' ? 'browser tab' : surf) + '</strong>. Please share your <strong>Entire Screen</strong> instead.'; }
                        if (btn) { btn.disabled = false; btn.textContent = '🖥 Try Again'; }
                        return;
                    }

                    // Unknown surface (browser didn't report displaySurface) — treat the
                    // same as a wrong-type share: stop it, warn, and force a retry.
                    if (surf === '') {
                        stream.getTracks().forEach(function(t) { t.stop(); });
                        if (box) { box.style.background = '#fff3cd'; }
                        if (msg) { msg.innerHTML = '⚠️ We couldn\'t confirm the screen type. Please click <strong>Share My Screen</strong> again and select <strong>Entire Screen</strong> (not a window or tab) from the sharing dialog.'; }
                        if (btn) { btn.disabled = false; btn.textContent = '🖥 Share My Screen'; }
                        return;
                    }

                    state.screenStream = stream;

                    // Monitor for stop — if student stops sharing during exam, pause it
                    track.addEventListener('ended', function() {
                        if (state.proctoringActive && !state.finished) {
                            logProctoringEvent('screen_share_stopped', {}, 'high', (cfg.scoreconfig && cfg.scoreconfig.screensharestopped) || 10);
                            showToast('⚠️ Screen sharing stopped! Please resume to continue.', 'danger', 0);
                            showScreenSharePausedOverlay();
                        }
                    });

                    if (box) {
                        box.style.background = '#d4edda';
                        box.innerHTML = '<div style="font-size:36px;margin-bottom:8px;">✅</div>' +
                            '<div style="font-size:14px;color:#155724;font-weight:700;">Full-screen sharing active!</div>' +
                            '<div style="font-size:12px;color:#155724;margin-top:4px;">Your entire screen will be monitored.</div>';
                    }
                    if (btn) { btn.style.display = 'none'; }
                    if (nextBtn) { nextBtn.disabled = false; }

                    // Hide the browser's sharing notification bar (Moodle adds one too)
                    if (!document.getElementById('lg-hide-share-banner')) {
                        var ssStyle = document.createElement('style');
                        ssStyle.id = 'lg-hide-share-banner';
                        ssStyle.textContent =
                            '.sharing-notification,.screensharing-banner,' +
                            '[data-region="screenshare-notification"],' +
                            '[class*="screenshare"],[id*="screenshare"]' +
                            '{display:none!important;}';
                        document.head.appendChild(ssStyle);
                    }
                })
                .catch(function() {
                    if (box) { box.style.background = '#f8d7da'; }
                    if (msg) { msg.innerHTML = '🚫 <strong>Screen sharing was declined.</strong><br>' +
                        'Screen sharing is <strong>mandatory</strong> for this exam. Click "Share My Screen" and select your <strong>Entire Screen</strong>.'; }
                    if (btn) { btn.disabled = false; btn.textContent = '🖥 Try Again'; }
                    // Keep next disabled — must successfully share before continuing.
                });
        };
    }

    function showScreenSharePausedOverlay() {
        if (document.getElementById('lg-ss-paused')) { return; }
        state.screenSharePaused = true; // Pause timer and violation logging.
        var overlay = document.createElement('div');
        overlay.id = 'lg-ss-paused';
        overlay.style.cssText = 'position:fixed;inset:0;z-index:99998;background:rgba(220,53,69,0.96);' +
            'display:flex;align-items:center;justify-content:center;flex-direction:column;' +
            'font-family:"Segoe UI",system-ui,sans-serif;color:#fff;padding:20px;text-align:center;';
        overlay.innerHTML =
            '<div style="font-size:64px;margin-bottom:16px;">🖥</div>' +
            '<h2 style="margin:0 0 12px;font-size:22px;font-weight:800;">Screen Sharing Stopped</h2>' +
            '<p style="margin:0 0 24px;font-size:15px;opacity:.9;max-width:440px;">' +
            'Your screen sharing has stopped. Timer and violation detection are paused.<br>' +
            'Resume screen sharing to continue your assessment.<br>' +
            '<strong>This has been flagged and your proctor has been notified.</strong></p>' +
            '<button id="lg-ss-resume-btn" ' +
            'style="background:#fff;color:#dc3545;border:none;border-radius:10px;' +
            'padding:14px 32px;font-size:15px;font-weight:700;cursor:pointer;box-shadow:0 4px 16px rgba(0,0,0,0.3);">' +
            '🖥 Resume Screen Sharing</button>' +
            '<p id="lg-ss-resume-msg" style="margin:12px 0 0;font-size:13px;opacity:.85;"></p>';
        document.body.appendChild(overlay);

        function attachResumeHandler() {
            var btn = document.getElementById('lg-ss-resume-btn');
            var msg = document.getElementById('lg-ss-resume-msg');
            if (!btn) { return; }
            btn.onclick = function() {
                btn.disabled = true;
                btn.textContent = '⏳ Requesting screen share…';
                if (msg) { msg.textContent = ''; }
                navigator.mediaDevices.getDisplayMedia({video: true, audio: false})
                    .then(function(newStream) {
                        var track = newStream.getVideoTracks()[0];
                        var surf = (track && track.getSettings) ? (track.getSettings().displaySurface || '') : '';
                        if (surf === 'browser' || surf === 'window' || surf === 'application') {
                            newStream.getTracks().forEach(function(t) { t.stop(); });
                            btn.disabled = false;
                            btn.textContent = '🖥 Try Again (Select Entire Screen)';
                            if (msg) { msg.textContent = '⚠️ Please select your ENTIRE SCREEN, not a window or tab.'; }
                            return;
                        }
                        state.screenStream = newStream;
                        state.screenSharePaused = false; // Resume timer and logging.
                        track.addEventListener('ended', function() {
                            if (state.proctoringActive && !state.finished) {
                                logProctoringEvent('screen_share_stopped', {}, 'high', (cfg.scoreconfig && cfg.scoreconfig.screensharestopped) || 10);
                                showScreenSharePausedOverlay();
                            }
                        });
                        var old = document.getElementById('lg-ss-paused');
                        if (old && old.parentNode) { old.parentNode.removeChild(old); }
                        showToast('✅ Screen sharing resumed. Timer and monitoring have restarted.', 'success', 4000);
                    })
                    .catch(function() {
                        btn.disabled = false;
                        btn.textContent = '🖥 Resume Screen Sharing';
                        if (msg) { msg.textContent = '⚠️ Share was declined. Click the button and try again.'; }
                    });
            };
        }
        attachResumeHandler();
    }

    // Max dimensions for screen captures — keeps the JPEG payload under ~150 KB.
    var SCREEN_CAP_W = 1280;
    var SCREEN_CAP_H = 720;

    // Detects a "warm-up" frame: right as getDisplayMedia() starts delivering frames,
    // the very first several grabs can be solid black — full-sized, non-zero dimensions
    // (so the existing zero-dimension guard doesn't catch it), just before the browser's
    // compositor actually starts handing over real screen content. Reported live: 24
    // consecutive black captures within ~4 seconds at the very start of a session,
    // immediately followed by normal, correctly-rendered captures — a warm-up window,
    // not a sharing failure. Samples a handful of points cheaply rather than the whole
    // image; every sampled point being identical (usually pure black) means this frame
    // is very likely a compositor warm-up artifact rather than genuine screen content.
    function lgIsBlankFrame(canvas) {
        try {
            var ctx = canvas.getContext('2d');
            var w = canvas.width, h = canvas.height;
            if (!w || !h) { return true; }
            var pts = [
                [0, 0], [w - 1, 0], [0, h - 1], [w - 1, h - 1],
                [Math.floor(w / 2), Math.floor(h / 2)],
                [Math.floor(w / 4), Math.floor(h / 4)],
                [Math.floor(3 * w / 4), Math.floor(3 * h / 4)],
            ];
            var first = null;
            for (var i = 0; i < pts.length; i++) {
                var d = ctx.getImageData(pts[i][0], pts[i][1], 1, 1).data;
                var key = d[0] + ',' + d[1] + ',' + d[2];
                if (first === null) { first = key; }
                else if (key !== first) { return false; }
            }
            return true;
        } catch (e) {
            return false; // sampling failed for any reason — don't block a real upload over it.
        }
    }

    var lgScreenCapWarnedAt = 0;
    function captureScreenSnapshot(type) {
        if (!state.attemptid || state.attemptid < 1) { return; }
        var track = state.screenStream ? state.screenStream.getVideoTracks()[0] : null;
        if (!state.screenStream || !track || track.readyState !== 'live') {
            // Previously a silent no-op here left zero trace of WHY a violation has no
            // screen evidence attached — indistinguishable from "there was nothing to
            // capture." Logged (0 score, no student-facing toast, at most once/minute so
            // a genuinely dead stream doesn't spam the events table) so a proctor
            // reviewing an incident later can see this was a technical gap in evidence
            // collection, not proof the student "got away with it" — directly the
            // ambiguity raised over missing Escape-key/DevTools screen evidence.
            var now = Date.now();
            if (state.proctoringActive && (now - lgScreenCapWarnedAt) > 60000) {
                lgScreenCapWarnedAt = now;
                logProctoringEvent('screen_capture_unavailable', {
                    'for': type || 'unknown',
                    reason: state.screenStream ? 'track_not_live' : 'no_stream',
                }, 'low', 0);
            }
            return;
        }
        if (typeof ImageCapture !== 'undefined') {
            var imgCapture = new ImageCapture(track);
            imgCapture.grabFrame().then(function(bitmap) {
                // Skip zero-dimension bitmaps (can occur when track just started
                // or is momentarily paused), which would create a 0×0 canvas
                // whose JPEG is either empty or unreadable.
                if (!bitmap || !bitmap.width || !bitmap.height) { return; }
                var scale = Math.min(1, SCREEN_CAP_W / bitmap.width, SCREEN_CAP_H / bitmap.height);
                var c = document.createElement('canvas');
                c.width  = Math.round(bitmap.width  * scale);
                c.height = Math.round(bitmap.height * scale);
                c.getContext('2d').drawImage(bitmap, 0, 0, c.width, c.height);
                if (lgIsBlankFrame(c)) { return; } // warm-up artifact — skip rather than upload a useless black square
                uploadSnapshot(c, type || 'screen_capture', true);
            }).catch(function() {
                captureScreenSnapshotFallback(type);
            });
        } else {
            captureScreenSnapshotFallback(type);
        }
    }

    function captureScreenSnapshotFallback(type) {
        if (!state.screenStream) { return; }
        var vid = document.createElement('video');
        vid.muted = true;
        vid.playsInline = true;
        vid.srcObject = state.screenStream;
        var done = false;
        var cleanup = function() { vid.pause(); vid.srcObject = null; };
        var doCapture = function() {
            if (done) { return; }
            done = true;
            var w = vid.videoWidth;
            var h = vid.videoHeight;
            cleanup();
            // Abort if no actual frame data — prevents blank/broken JPEG uploads.
            if (!w || !h) { return; }
            var scale = Math.min(1, SCREEN_CAP_W / w, SCREEN_CAP_H / h);
            var c = document.createElement('canvas');
            c.width  = Math.round(w * scale);
            c.height = Math.round(h * scale);
            c.getContext('2d').drawImage(vid, 0, 0, c.width, c.height);
            if (lgIsBlankFrame(c)) { return; } // warm-up artifact — skip rather than upload a useless black square
            uploadSnapshot(c, type || 'screen_capture', true);
        };
        // Wait until the stream has delivered at least one decodable frame.
        vid.addEventListener('loadeddata', doCapture);
        // Safety fallback: if no frame arrives within 2 s, abort silently.
        setTimeout(function() { if (!done) { done = true; cleanup(); } }, 2000);
        vid.play().catch(function() { done = true; cleanup(); });
    }

    // Previously a single WS failure here silently discarded the captured evidence
    // forever, with no retry — the same server-distress pattern already fixed for
    // question load/save/finish (v2.0.153) can just as easily eat a violation's only
    // evidence image. Retries twice with backoff before giving up, same pattern.
    var LG_SNAPSHOT_MAX_RETRIES = 2;
    function uploadSnapshot(canvas, type, isScreen) {
        // Encode once up front, then retry with the same data URL — never re-touches
        // the canvas, which may already be garbage-collected or reused by the time a
        // retry fires a couple seconds later.
        doUploadSnapshot(canvas.toDataURL('image/jpeg', 0.7), type, isScreen, 0);
    }
    function doUploadSnapshot(imagedata, type, isScreen, retryCount) {
        callWS('mod_learnguard_send_snapshot', {
            attemptid: state.attemptid,
            imagedata: imagedata,
            event_type: type,
            is_screen: !!isScreen,
        }, 30000).catch(function() {
            if (retryCount < LG_SNAPSHOT_MAX_RETRIES) {
                setTimeout(function() {
                    doUploadSnapshot(imagedata, type, isScreen, retryCount + 1);
                }, lgJitter(1500 * (retryCount + 1)));
            }
        });
    }

    function wiz_monitor_html() {
        var extended = ('screen' in window && window.screen.isExtended);
        var wide     = (window.screen.width > 2560);
        var detected = extended || wide;
        return '<p>Only one screen is permitted during this assessment.</p>' +
            '<div style="background:' + (detected ? '#f8d7da' : '#d4edda') + ';border-radius:8px;' +
            'padding:16px;margin-bottom:16px;text-align:center;">' +
            '<div style="font-size:40px;margin-bottom:8px;">' + (detected ? '⚠️' : '✅') + '</div>' +
            '<strong>' + (detected
                ? 'Multiple monitors detected! Please disconnect extra screens before continuing.'
                : 'Single screen confirmed — you are good to proceed.') +
            '</strong></div>' +
            '<p style="font-size:13px;color:#6c757d;">If you have a single screen but see a warning, ' +
            'try disconnecting any external displays and returning to this step.</p>';
    }

    function wiz_photoid_html() {
        return '<p>Hold a <strong>valid photo ID</strong> clearly in front of your camera so your invigilator can verify your identity.</p>' +
            '<div style="background:#e8f4f8;border:1px solid #bee5eb;border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:12px;">' +
            '<strong>Accepted ID types:</strong>' +
            '<ul style="margin:6px 0 0;padding-left:18px;line-height:2.0;">' +
            '<li>🪪 <strong>National ID card</strong></li>' +
            '<li>📘 <strong>International passport</strong></li>' +
            '<li>🚗 <strong>Driver\'s licence</strong></li>' +
            '<li>🏢 <strong>Official employee / staff ID card</strong> <em style="color:#6c757d;font-size:12px;">(for staff/employees only)</em></li>' +
            '</ul></div>' +
            '<div style="background:#f8f9fa;border:1px solid #dee2e6;border-radius:6px;' +
            'padding:8px 14px;font-size:13px;margin-bottom:12px;text-align:center;">' +
            '✓ Hold ID next to your face &nbsp;|&nbsp; ✓ Name &amp; photo must be readable &nbsp;|&nbsp; ✓ Good lighting' +
            '</div>' +
            // Live camera view
            '<div id="lg-id-live-wrap" style="text-align:center;margin:10px 0;">' +
            '<video id="lg-wiz-id-vid" autoplay playsinline muted ' +
            'style="width:100%;max-width:340px;border-radius:8px;border:2px solid #dee2e6;background:#111;display:block;margin:0 auto;"></video>' +
            '</div>' +
            // Captured preview (hidden until first capture)
            '<div id="lg-id-preview-wrap" style="display:none;text-align:center;margin:10px 0;">' +
            '<img id="lg-id-preview" src="" alt="Captured ID" ' +
            'style="width:100%;max-width:340px;border-radius:8px;border:3px solid #28a745;"/>' +
            '</div>' +
            // Buttons
            '<div style="text-align:center;margin-top:12px;display:flex;justify-content:center;gap:10px;">' +
            '<button id="lg-capture-id-btn" style="background:#0f6cbf;color:#fff;border:none;border-radius:8px;' +
            'padding:10px 24px;font-size:15px;cursor:pointer;font-weight:700;">📸 Capture Photo</button>' +
            '<button id="lg-retake-id-btn" style="display:none;background:#fd7e14;color:#fff;border:none;border-radius:8px;' +
            'padding:10px 24px;font-size:15px;cursor:pointer;font-weight:700;">↺ Retake</button>' +
            '</div>' +
            '<canvas id="lg-id-canvas" style="display:none;"></canvas>' +
            '<div id="lg-id-status" style="text-align:center;font-size:13px;color:#6c757d;margin-top:10px;">' +
            'Position your ID and face clearly, then click Capture.</div>';
    }

    function wiz_photoid() {
        var vid        = el('lg-wiz-id-vid');
        var statusEl   = el('lg-id-status');
        var captureBtn = el('lg-capture-id-btn');
        var retakeBtn  = el('lg-retake-id-btn');
        var preview    = el('lg-id-preview');
        var liveWrap   = el('lg-id-live-wrap');
        var prevWrap   = el('lg-id-preview-wrap');
        var nextBtn    = el('lg-wiz-next-btn');

        // Attach camera stream.
        function startCamera() {
            if (vid && cameraStream) {
                vid.srcObject = cameraStream; vid.play().catch(function(){});
            } else if (vid) {
                navigator.mediaDevices.getUserMedia({video: true}).then(function(s) {
                    cameraStream = s; vid.srcObject = s; vid.play().catch(function(){});
                }).catch(function() {
                    if (statusEl) { statusEl.innerHTML = wiz_chkResult(false, 'Camera not available.'); }
                    if (nextBtn) { nextBtn.disabled = false; }
                });
            }
        }
        startCamera();

        function doCapture() {
            var canvas = el('lg-id-canvas');
            if (!canvas || !vid) { return; }
            var w = vid.videoWidth  || 640;
            var h = vid.videoHeight || 480;
            if (w < 2 || h < 2) {
                if (statusEl) { statusEl.textContent = 'Camera not ready — please wait a moment and try again.'; }
                return;
            }
            canvas.width = w; canvas.height = h;
            canvas.getContext('2d').drawImage(vid, 0, 0);
            var dataUrl = canvas.toDataURL('image/jpeg', 0.85);

            // Show preview, hide live video
            if (preview)  { preview.src = dataUrl; }
            if (liveWrap) { liveWrap.style.display = 'none'; }
            if (prevWrap) { prevWrap.style.display = 'block'; }
            if (captureBtn){ captureBtn.style.display = 'none'; }
            if (retakeBtn) { retakeBtn.style.display = 'inline-block'; }
            if (statusEl) { statusEl.innerHTML = wiz_chkResult(true, 'ID photo captured — click Next to continue or Retake to try again.'); }
            if (nextBtn)  { nextBtn.disabled = false; }

            // Upload
            if (state.attemptid) {
                callWS('mod_learnguard_send_snapshot', {
                    attemptid: state.attemptid,
                    imagedata: dataUrl,
                    event_type: 'photo_id',
                }).catch(function() {});
            }
        }

        function doRetake() {
            // Restore live view
            if (liveWrap) { liveWrap.style.display = 'block'; }
            if (prevWrap) { prevWrap.style.display = 'none'; }
            if (captureBtn){ captureBtn.style.display = 'inline-block'; }
            if (retakeBtn) { retakeBtn.style.display = 'none'; }
            if (statusEl) { statusEl.textContent = 'Position your ID and face clearly, then click Capture.'; }
            if (nextBtn)  { nextBtn.disabled = true; }
            startCamera();
        }

        if (captureBtn) { captureBtn.addEventListener('click', doCapture); }
        if (retakeBtn)  { retakeBtn.addEventListener('click', doRetake);  }
        if (!captureBtn && nextBtn) { nextBtn.disabled = false; }
    }

    function wiz_keystroke_html() {
        return '<p>Type the sentence below to establish your typing pattern baseline. ' +
            'This helps verify your identity during the exam.</p>' +
            '<div style="background:#f0f4ff;border:1px solid #0f6cbf;border-radius:6px;' +
            'padding:10px 14px;font-size:15px;font-weight:600;margin-bottom:14px;text-align:center;">' +
            'The quick brown fox jumps over the lazy dog' +
            '</div>' +
            '<textarea id="lg-keystroke-txt" rows="3" style="width:100%;padding:10px;border:2px solid #ccc;' +
            'border-radius:6px;font-size:15px;" placeholder="Type the sentence above…" ' +
            'spellcheck="false"></textarea>' +
            '<div id="lg-ks-status" style="font-size:13px;color:#6c757d;margin-top:8px;">Type the full sentence to continue.</div>';
    }

    var keystrokeBaseline = [];
    function wiz_keystroke() {
        var txt = el('lg-keystroke-txt');
        var statusEl = el('lg-ks-status');
        var nextBtn = el('lg-wiz-next-btn');
        var TARGET = 'The quick brown fox jumps over the lazy dog';
        var lastTime = 0;

        if (txt) {
            // Disable paste and right-click — student must type manually.
            txt.addEventListener('paste', function(e) {
                e.preventDefault();
                if (statusEl) { statusEl.textContent = '⚠️ Paste is disabled. Please type the sentence manually.'; }
            });
            txt.addEventListener('contextmenu', function(e) { e.preventDefault(); });
            txt.addEventListener('drop', function(e) { e.preventDefault(); });

            txt.addEventListener('keydown', function(e) {
                var now = Date.now();
                if (lastTime > 0) {
                    keystrokeBaseline.push({key: e.key, interval: now - lastTime});
                }
                lastTime = now;
            });
            txt.addEventListener('input', function() {
                var val   = this.value;
                var typed = val.trim().toLowerCase();
                var exact = typed === TARGET.toLowerCase();
                var pct   = Math.min(100, Math.round((val.length / TARGET.length) * 100));

                if (exact) {
                    if (statusEl) {
                        statusEl.innerHTML = '<span style="color:#28a745;font-weight:600;">✓ Pattern recorded. You may continue.</span>';
                    }
                    if (txt) { txt.style.borderColor = '#28a745'; }
                    if (nextBtn) {
                        nextBtn.disabled = false;
                        // Compute and keep the baseline for the whole page session — this
                        // runs during precheck, before an attemptid exists, so it's kept in
                        // memory here (used directly by the live comparison during the exam)
                        // rather than depending on state.attemptid the way the old
                        // logProctoringEvent() call below used to (that gate meant this was
                        // never actually sent, since no attempt exists yet at this step —
                        // fixed by persisting it via a deferred WS call once launchAssessment()
                        // obtains a real attemptid, same pattern as the 360° video/identity
                        // flags).
                        if (keystrokeBaseline.length > 5 && typingBaselineAvg === 0) {
                            typingBaselineAvg = Math.round(
                                keystrokeBaseline.reduce(function(a, b) { return a + b.interval; }, 0) / keystrokeBaseline.length
                            );
                        }
                    }
                } else {
                    if (nextBtn) { nextBtn.disabled = true; }
                    if (txt) { txt.style.borderColor = val.length > 0 ? '#ffc107' : '#ccc'; }
                    if (statusEl) {
                        if (val.length === 0) {
                            statusEl.textContent = 'Type the full sentence to continue.';
                        } else if (pct < 100) {
                            statusEl.textContent = 'Progress: ' + pct + '% — keep typing…';
                        } else {
                            // Length matches but content differs — highlight mismatch.
                            statusEl.innerHTML = '<span style="color:#dc3545;font-weight:600;">⚠️ Text does not match. Please type exactly as shown above.</span>';
                            if (txt) { txt.style.borderColor = '#dc3545'; }
                        }
                    }
                }
            });
        }
    }

    function wiz_env_html() {
        return '<h4 style="margin-bottom:8px;">360° Environment Recording</h4>' +
            '<p style="color:#555;margin-bottom:6px;">Show your desk, walls, and surroundings to confirm you are alone in the room.</p>' +
            '<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:8px 14px;font-size:13px;margin-bottom:12px;">' +
            'ℹ️ Max 15 seconds. Retake as many times as needed. When satisfied, click <strong>Upload Video</strong> — your proctor will review the recording. Continue is only enabled after a successful upload.</div>' +
            '<div style="text-align:center;">' +
            '<video id="lg-env-preview" autoplay playsinline muted style="width:100%;max-width:360px;border-radius:8px;border:2px solid #dee2e6;background:#111;"></video>' +
            '<video id="lg-env-playback" controls style="display:none;width:100%;max-width:360px;border-radius:8px;margin-top:8px;"></video>' +
            '</div>' +
            '<div id="lg-env-timer" style="text-align:center;font-size:36px;font-weight:bold;color:#dc3545;margin:10px 0;display:none;">15</div>' +
            '<div style="text-align:center;margin-top:10px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">' +
            '<button id="lg-env-rec-btn" style="background:#dc3545;color:#fff;border:none;border-radius:8px;padding:12px 24px;font-size:16px;cursor:pointer;">🔴 Start Recording</button>' +
            '<button id="lg-env-stop-btn" style="display:none;background:#6c757d;color:#fff;border:none;border-radius:8px;padding:12px 24px;cursor:pointer;">⏹ Stop</button>' +
            '<button id="lg-env-retake-btn" style="display:none;background:#fd7e14;color:#fff;border:none;border-radius:8px;padding:10px 20px;cursor:pointer;">↺ Retake</button>' +
            '<button id="lg-env-upload-btn" style="display:none;background:#28a745;color:#fff;border:none;border-radius:8px;padding:12px 24px;font-size:16px;cursor:pointer;font-weight:700;">⬆ Upload Video</button>' +
            '</div>' +
            '<div id="lg-env-status" style="text-align:center;margin-top:10px;font-size:13px;color:#6c757d;">' +
            'Press "Start Recording" then slowly pan your camera around the room.</div>';
    }

    function wiz_env() {
        var preview    = el('lg-env-preview');
        var playback   = el('lg-env-playback');
        var recBtn     = el('lg-env-rec-btn');
        var stopBtn    = el('lg-env-stop-btn');
        var retakeBtn  = el('lg-env-retake-btn');
        var uploadBtn  = el('lg-env-upload-btn');
        var timerEl    = el('lg-env-timer');
        var statusEl   = el('lg-env-status');
        var nextBtn    = el('lg-wiz-next-btn');

        // Continue stays DISABLED until upload is confirmed.
        if (nextBtn) { nextBtn.disabled = true; }

        var recorder = null, chunks = [], timerIv = null, timeLeft = 15;

        if (preview && cameraStream) { preview.srcObject = cameraStream; preview.play(); }
        else if (preview) {
            navigator.mediaDevices.getUserMedia({video: true}).then(function(s) {
                preview.srcObject = s; preview.play();
            }).catch(function() { if (statusEl) { statusEl.textContent = 'Camera unavailable.'; } });
        }

        if (recBtn) { recBtn.addEventListener('click', function() {
            var stream = preview ? preview.srcObject : null;
            if (!stream) { if (statusEl) { statusEl.textContent = 'Camera not available.'; } return; }
            chunks = [];
            var mime = typeof MediaRecorder !== 'undefined' && MediaRecorder.isTypeSupported('video/webm;codecs=vp8') ? 'video/webm;codecs=vp8' : 'video/webm';
            try {
                recorder = new MediaRecorder(stream, {mimeType: mime, videoBitsPerSecond: 200000});
            } catch(e) {
                recorder = new MediaRecorder(stream);
            }
            recorder.ondataavailable = function(e) { if (e.data.size > 0) { chunks.push(e.data); } };
            recorder.onstop = function() {
                clearInterval(timerIv);
                if (timerEl) { timerEl.style.display = 'none'; }
                var blob = new Blob(chunks, {type: mime});
                var objectUrl = URL.createObjectURL(blob);
                if (playback) { playback.src = objectUrl; playback.style.display = 'block'; }
                if (preview) { preview.style.display = 'none'; }
                if (stopBtn) { stopBtn.style.display = 'none'; }
                if (recBtn) { recBtn.style.display = 'none'; }
                if (retakeBtn) { retakeBtn.style.display = 'inline-block'; }
                if (uploadBtn) { uploadBtn.style.display = 'inline-block'; }
                if (statusEl) {
                    statusEl.innerHTML = '<span style="color:#856404;font-size:13px;">' +
                        '✓ Recording complete (' + (15 - timeLeft) + 's). Review the video above, then click ' +
                        '<strong style="color:#28a745;">⬆ Upload Video</strong> to submit it to your proctor.</span>';
                }
                // Continue stays disabled until Upload is clicked.
                if (nextBtn) { nextBtn.disabled = true; }
            };
            recorder.start(1000);
            if (recBtn) { recBtn.style.display = 'none'; }
            if (stopBtn) { stopBtn.style.display = 'inline-block'; }
            timeLeft = 15;
            if (timerEl) { timerEl.style.display = 'block'; timerEl.textContent = '15'; }
            if (statusEl) { statusEl.textContent = '🔴 Recording… pan your camera around the room.'; }
            timerIv = setInterval(function() {
                timeLeft--;
                if (timerEl) { timerEl.textContent = timeLeft; }
                if (timeLeft <= 0 && recorder && recorder.state !== 'inactive') { recorder.stop(); clearInterval(timerIv); }
            }, 1000);
        }); }

        if (stopBtn) { stopBtn.addEventListener('click', function() {
            if (recorder && recorder.state !== 'inactive') { recorder.stop(); }
        }); }

        if (retakeBtn) { retakeBtn.addEventListener('click', function() {
            chunks = [];
            if (playback) { playback.style.display = 'none'; }
            if (preview) { preview.style.display = 'block'; }
            if (recBtn) { recBtn.style.display = 'inline-block'; }
            if (retakeBtn) { retakeBtn.style.display = 'none'; }
            if (uploadBtn) { uploadBtn.style.display = 'none'; }
            if (timerEl) { timerEl.style.display = 'none'; }
            if (nextBtn) { nextBtn.disabled = true; }
            if (statusEl) { statusEl.textContent = 'Press "Start Recording" when ready.'; }
        }); }

        if (uploadBtn) { uploadBtn.addEventListener('click', function() {
            var blob = new Blob(chunks, {type: 'video/webm'});
            if (!blob || blob.size === 0) { return; }
            uploadBtn.disabled = true;
            uploadBtn.textContent = '⏳ Uploading…';
            if (statusEl) { statusEl.innerHTML = '<span style="color:#0f6cbf;font-size:13px;">⏳ Uploading your recording to the server…</span>'; }

            var reader = new FileReader();
            reader.onloadend = function() {
                var b64 = reader.result;
                if (state.attemptid) {
                    var timedOut = false;
                    var uploadTimer = setTimeout(function() {
                        timedOut = true;
                        uploadBtn.disabled = false;
                        uploadBtn.textContent = '↺ Retry Upload';
                        if (statusEl) {
                            statusEl.innerHTML = wiz_chkResult(false,
                                '⚠️ Upload is taking too long. Please retry — you cannot continue until the recording is uploaded.');
                        }
                        // Continue remains DISABLED until a successful upload.
                    }, 12000);
                    callWS('mod_learnguard_upload_360', {
                        attemptid: state.attemptid,
                        videodata: b64,
                        duration:  15 - timeLeft,
                    }).then(function(data) {
                        clearTimeout(uploadTimer);
                        if (timedOut) { return; }
                        if (uploadBtn) { uploadBtn.style.display = 'none'; }
                        if (statusEl) {
                            statusEl.innerHTML = wiz_chkResult(true,
                                '✅ Environment recording uploaded successfully! Your proctor can now view it. Click <strong>Continue</strong> to proceed.' +
                                (data && data.size ? ' (' + Math.round(data.size / 1024) + ' KB)' : ''));
                        }
                        if (nextBtn) { nextBtn.disabled = false; }
                    }).catch(function() {
                        clearTimeout(uploadTimer);
                        if (timedOut) { return; }
                        uploadBtn.disabled = false;
                        uploadBtn.textContent = '↺ Retry Upload';
                        if (statusEl) {
                            statusEl.innerHTML = wiz_chkResult(false,
                                '⚠️ Upload failed. Please try again — you cannot continue until the recording is uploaded.');
                        }
                        // Continue remains DISABLED until a successful upload.
                    });
                } else {
                    // No attempt yet (still in precheck) — defer the actual
                    // upload until launchAssessment() obtains an attemptid.
                    pending360Video = {videodata: b64, duration: 15 - timeLeft};
                    if (uploadBtn) { uploadBtn.style.display = 'none'; }
                    if (statusEl) {
                        statusEl.innerHTML = wiz_chkResult(true,
                            '✅ Recording saved! It will be uploaded once your exam starts. Click <strong>Continue</strong> to proceed.');
                    }
                    if (nextBtn) { nextBtn.disabled = false; }
                }
            };
            reader.readAsDataURL(blob);
        }); }
    }

    function wiz_privacy_html() {
        var noticeText = cfg.privacynotice || PRIVACY_POLICY;
        return '<div id="lg-privacy-tophint" style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;' +
            'padding:6px 12px;font-size:12px;color:#856404;margin-bottom:6px;text-align:center;">' +
            '↓ Scroll down inside the box below to read the full privacy notice and unlock the checkbox.' +
            '</div>' +
            '<p style="margin:0 0 6px;">Please read the privacy notice. You must scroll to the bottom to accept.</p>' +
            '<div id="lg-privacy-scroll" style="height:360px;overflow-y:auto;border:1px solid #dee2e6;' +
            'border-radius:6px;padding:14px;font-size:13px;line-height:1.6;background:#f8f9fa;white-space:pre-wrap;">' +
            escHtml(noticeText) + '</div>' +
            '<div style="margin-top:12px;display:flex;align-items:center;gap:10px;">' +
            '<input type="checkbox" id="lg-privacy-chk" disabled style="width:18px;height:18px;cursor:pointer;"/>' +
            '<label for="lg-privacy-chk" style="cursor:pointer;font-weight:500;">' +
            'I have read and accept the Privacy &amp; Data Protection notice</label>' +
            '</div>' +
            '<p id="lg-privacy-hint" style="font-size:12px;color:#6c757d;margin-top:6px;">↓ Scroll to the bottom to enable acceptance</p>';
    }

    function wiz_privacy_hooks() {
        var scrollEl = el('lg-privacy-scroll');
        var chk      = el('lg-privacy-chk');
        var hint     = el('lg-privacy-hint');
        var topHint  = el('lg-privacy-tophint');
        var nextBtn  = el('lg-wiz-next-btn');

        var scrolledToBottom = false;

        // HARD-DISABLE: Continue only enabled when scrolled + checkbox ticked.
        function setNextState() {
            if (!nextBtn) { return; }
            nextBtn.disabled = !(scrolledToBottom && chk && !chk.disabled && chk.checked);
        }
        setNextState();

        function unlockCheckbox() {
            if (scrolledToBottom) { return; }
            scrolledToBottom = true;
            if (chk) { chk.disabled = false; }
            if (hint) { hint.style.display = 'none'; }
            if (topHint) { topHint.style.display = 'none'; }
            setNextState();
        }

        if (scrollEl) {
            scrollEl.addEventListener('scroll', function() {
                if (this.scrollTop + this.clientHeight >= this.scrollHeight - 10) {
                    unlockCheckbox();
                }
            });
            // If text fits without scrolling, unlock after render.
            setTimeout(function() {
                var s = el('lg-privacy-scroll');
                if (s && s.scrollHeight <= s.clientHeight) { unlockCheckbox(); }
            }, 150);
        }
        if (chk) {
            chk.addEventListener('change', function() { setNextState(); });
        }
    }

    function wiz_fs_html() {
        return '<div style="text-align:center;">' +
            '<div style="font-size:64px;margin-bottom:14px;">⛶</div>' +
            '<h4>Fullscreen Mode Required</h4>' +
            '<p style="color:#555;">This exam must be taken in fullscreen. All exits are detected and logged.</p>' +
            '<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:10px 14px;text-align:left;font-size:13px;margin:14px 0;">' +
            '<strong>During the exam:</strong>' +
            '<ul style="margin:6px 0 0;padding-left:18px;line-height:1.8;">' +
            '<li>Switching tabs is detected and logged</li>' +
            '<li>Exiting fullscreen is detected immediately</li>' +
            '<li>You will be prompted to return to fullscreen</li>' +
            '</ul></div>' +
            '<button id="lg-enter-fs-btn" style="background:#28a745;color:#fff;border:none;' +
            'border-radius:8px;padding:14px 36px;font-size:18px;cursor:pointer;font-weight:700;">' +
            '⛶ Enter Fullscreen Mode</button>' +
            '<div id="lg-fs-wiz-status" style="margin-top:10px;font-size:14px;color:#6c757d;">' +
            'Click above to enter fullscreen</div>' +
            '</div>';
    }

    function wiz_fs_hooks() {
        var btn      = el('lg-enter-fs-btn');
        var statusEl = el('lg-fs-wiz-status');
        if (!btn) { return; }
        btn.addEventListener('click', function() {
            var docEl = document.documentElement;
            var fn = docEl.requestFullscreen || docEl.webkitRequestFullscreen || docEl.mozRequestFullScreen;
            if (!fn) { if (statusEl) { statusEl.innerHTML = wiz_chkResult(false, 'Fullscreen not supported.'); } el('lg-wiz-next-btn').disabled = false; return; }
            fn.call(docEl)
                .then(function() {
                    btn.style.display = 'none';
                    if (statusEl) { statusEl.innerHTML = wiz_chkResult(true, '✓ Fullscreen active. You may continue.'); }
                    el('lg-wiz-next-btn').disabled = false;
                })
                .catch(function(e) {
                    if (statusEl) { statusEl.innerHTML = wiz_chkResult(false, 'Fullscreen blocked: ' + e.message + '. Try again.'); }
                });
        });
    }

    // Categorised list of prohibited items/behaviours shown on the final wizard
    // step. Deliberately shows NO point values — only what is prohibited.
    var PROHIBITED_CATEGORIES = [
        {
            icon: '📱', title: 'Devices & Materials',
            items: [
                'No mobile phones, tablets, smartwatches, or extra screens/monitors',
                'No books, notes, papers, or written material (unless explicitly allowed)',
                'No additional laptops or computers visible in frame',
            ],
        },
        {
            icon: '👤', title: 'People & Identity',
            items: [
                'You must remain alone in the room for the entire assessment',
                'Your face must stay visible and well-lit in the webcam at all times',
                'No other person may appear in your camera or speak in your room',
            ],
        },
        {
            icon: '🖥', title: 'Browser, Tabs & Screen',
            items: [
                'Do not switch tabs, open new windows, or minimize the browser',
                'Do not exit fullscreen mode once the exam begins',
                'Do not use copy, paste, print, screenshot, or developer tools',
            ],
        },
        {
            icon: '🔇', title: 'Audio & Environment',
            items: [
                'Keep your surroundings quiet — avoid talking, music, or other people\'s voices',
                'Do not cover or obstruct your webcam at any point',
                'Avoid sudden changes to your background or lighting',
            ],
        },
        {
            icon: '🌐', title: 'Network & Software',
            items: [
                'Do not use a VPN, proxy, or remote-access/screen-sharing software',
                'Do not close the page or lose your internet connection',
            ],
        },
    ];

    function wiz_ready_html() {
        var catHtml = PROHIBITED_CATEGORIES.map(function(cat) {
            return '<div style="margin-bottom:12px;">' +
                '<div style="font-weight:700;font-size:13px;color:#212529;margin-bottom:4px;">' +
                cat.icon + ' ' + cat.title + '</div>' +
                '<ul style="margin:0 0 0 22px;padding:0;font-size:13px;color:#495057;line-height:1.7;">' +
                cat.items.map(function(it) { return '<li>' + it + '</li>'; }).join('') +
                '</ul></div>';
        }).join('');

        return '<div id="lg-rules-tophint" style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;' +
            'padding:8px 12px;font-size:12px;color:#856404;margin-bottom:12px;text-align:center;">' +
            '↓ Scroll down inside the box below to read all the rules and unlock the checkbox.' +
            '</div>' +
            '<div style="text-align:center;">' +
            '<div style="font-size:64px;margin-bottom:14px;">✅</div>' +
            '<h4>You\'re All Set!</h4>' +
            '<p style="color:#555;">All checks have passed. Your exam environment is ready.</p>' +
            '<div style="background:#d4edda;border:1px solid #c3e6cb;border-radius:8px;padding:14px;text-align:left;margin:14px 0;">' +
            '<p style="margin:0;font-weight:600;color:#155724;">✓ System verified and secure</p>' +
            '<p style="margin:4px 0 0;color:#155724;font-size:13px;">Camera • Audio • Face detected • Fullscreen ready • Privacy accepted</p>' +
            '</div></div>' +

            '<div style="text-align:left;">' +
            '<p style="font-weight:600;margin-bottom:6px;">🚫 Prohibited During This Assessment</p>' +
            '<p style="font-size:13px;color:#6c757d;margin:0 0 10px;">Please review the following before you begin. Scroll down to continue.</p>' +
            '<div id="lg-rules-scroll" style="max-height:220px;overflow-y:auto;border:1px solid #dee2e6;' +
            'border-radius:6px;padding:14px;background:#f8f9fa;">' + catHtml + '</div>' +
            '<div style="margin-top:12px;display:flex;align-items:center;gap:10px;">' +
            '<input type="checkbox" id="lg-rules-chk" disabled style="width:18px;height:18px;cursor:pointer;"/>' +
            '<label for="lg-rules-chk" style="cursor:pointer;font-weight:500;">' +
            'I understand and agree to comply with the rules above</label>' +
            '</div>' +
            '<p id="lg-rules-hint" style="font-size:12px;color:#6c757d;margin-top:6px;">↓ Scroll down to enable acceptance</p>' +
            '</div>' +

            '<p style="color:#dc3545;font-weight:600;margin-top:14px;text-align:center;">' +
            'Once you click "Start Exam Now", the timer begins and all monitoring activates.</p>';
    }

    function wiz_ready_hooks() {
        var scrollEl = el('lg-rules-scroll');
        var chk      = el('lg-rules-chk');
        var hint     = el('lg-rules-hint');
        var topHint  = el('lg-rules-tophint');
        var nextBtn  = el('lg-wiz-next-btn');

        var scrolledToBottom = false;

        // HARD-DISABLE: Start Exam only enabled when scrolled + checkbox ticked.
        function setNextState() {
            if (!nextBtn) { return; }
            nextBtn.disabled = !(scrolledToBottom && chk && !chk.disabled && chk.checked);
        }
        setNextState();

        function unlockCheckbox() {
            if (scrolledToBottom) { return; }
            scrolledToBottom = true;
            if (chk) { chk.disabled = false; }
            if (hint) { hint.style.display = 'none'; }
            if (topHint) { topHint.style.display = 'none'; }
            setNextState();
        }

        if (scrollEl) {
            scrollEl.addEventListener('scroll', function() {
                if (this.scrollTop + this.clientHeight >= this.scrollHeight - 10) {
                    unlockCheckbox();
                }
            });
            // If text fits without scrolling, unlock after render.
            setTimeout(function() {
                var s = el('lg-rules-scroll');
                if (s && s.scrollHeight <= s.clientHeight) { unlockCheckbox(); }
            }, 150);
        }
        if (chk) {
            chk.addEventListener('change', function() { setNextState(); });
        }
    }

    function wiz_complete() {
        var overlay = document.getElementById('lg-wizard-overlay');
        if (overlay) { document.body.removeChild(overlay); }
        if (state.reinstatementMode) {
            // Reinstatement: don't start a new attempt — resume the existing one.
            state.reinstatementMode = false;
            beginResumedAssessment();
        } else {
            launchAssessment();
        }
    }

    function runPreExamWizard() {
        wizardStep = 0;
        buildWizardOverlay();
        renderWizardStep(0);
    }

    /* -- Legacy buildWizardSteps removed; replaced by renderWizardStep() above -- */
    function buildWizardSteps_REMOVED() {
        var steps = [];

        // Step 0: Overview.
        steps.push({
            id:    'overview',
            icon:  '🛡',
            title: 'Secure Assessment Check',
            body:  function() {
                return '<p style="margin-bottom:12px;">Before you begin, we need to verify your setup.</p>' +
                    '<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:14px;margin-bottom:16px;text-align:left;">' +
                    '<strong>This assessment is proctored. The following will be monitored:</strong>' +
                    '<ul style="margin:8px 0 0 16px;font-size:14px;">' +
                    (cfg.enablewebcam     ? '<li>Webcam (periodic snapshots)</li>' : '') +
                    (cfg.enabletabdetect  ? '<li>Tab / window switching (blocked)</li>' : '') +
                    (cfg.enablefullscreen ? '<li>Fullscreen enforcement</li>' : '') +
                    (cfg.enablekeyblock   ? '<li>Restricted keyboard shortcuts</li>' : '') +
                    (cfg.enableclipboard  ? '<li>Clipboard (copy/paste)</li>' : '') +
                    '</ul></div>' +
                    '<p style="font-size:13px;color:#6c757d;">By clicking <strong>Agree &amp; Continue</strong> you consent to this monitoring.</p>';
            },
            btnLabel: 'Agree & Continue →',
            onNext: null,
        });

        // Step 1: Browser check.
        steps.push({
            id:    'browser',
            icon:  '🌐',
            title: 'Browser & System Check',
            body:  function() {
                var ok = true;
                var items = [];
                if (window.self !== window.top) {
                    items.push({pass: false, text: 'Not running in an iframe'});
                    ok = false;
                } else { items.push({pass: true, text: 'Not running in an iframe'}); }

                var supportsFS = !!(document.documentElement.requestFullscreen ||
                    document.documentElement.webkitRequestFullscreen);
                items.push({pass: supportsFS, text: 'Fullscreen API supported'});

                var supportsMedia = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
                items.push({pass: supportsMedia, text: 'Camera/microphone access supported'});

                items.push({pass: true, text: 'JavaScript enabled'});

                var html = '<div style="text-align:left;margin-bottom:16px;">';
                items.forEach(function(item) {
                    html += '<div style="padding:8px 0;display:flex;align-items:center;gap:10px;">' +
                        '<span style="font-size:20px;">' + (item.pass ? '✅' : '❌') + '</span>' +
                        '<span>' + item.text + '</span></div>';
                });
                html += '</div>';
                if (!ok) {
                    html += '<div class="alert alert-danger" style="font-size:13px;">Some checks failed. ' +
                        'Please use a modern desktop browser (Chrome, Firefox, Edge, Safari).</div>';
                }
                return html;
            },
            btnLabel: 'Continue →',
            onNext: null,
        });

        // Step 2: Camera (if webcam enabled).
        if (cfg.enablewebcam) {
            steps.push({
                id:    'camera',
                icon:  '📷',
                title: 'Camera Check',
                body:  function() {
                    return '<p>We need to access your camera for monitoring.</p>' +
                        '<div id="lg-wiz-cam-wrap" style="margin:12px 0;">' +
                        '<video id="lg-wiz-cam" autoplay muted playsinline ' +
                        'style="width:280px;height:210px;background:#000;border-radius:8px;' +
                        'border:2px solid #dee2e6;display:block;margin:0 auto;"></video>' +
                        '</div>' +
                        '<p id="lg-wiz-cam-status" style="font-size:13px;color:#6c757d;">' +
                        'Requesting camera access…</p>';
                },
                btnLabel: 'Camera OK, Continue →',
                btnDisabled: true,
                onShow: function() {
                    navigator.mediaDevices.getUserMedia({video: true, audio: false})
                        .then(function(stream) {
                            cameraStream = stream;
                            var vid = el('lg-wiz-cam');
                            var status = el('lg-wiz-cam-status');
                            if (vid) { vid.srcObject = stream; vid.play().catch(function() {}); }
                            if (status) { status.textContent = '✅ Camera connected successfully.'; status.style.color = '#28a745'; }
                            var btn = el('lg-wiz-next-btn');
                            if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
                        })
                        .catch(function(err) {
                            var status = el('lg-wiz-cam-status');
                            if (status) {
                                status.innerHTML = '❌ Camera access denied: ' + (err.message || err.name) +
                                    '<br><span style="font-size:12px;">Please allow camera access and refresh, or contact your invigilator.</span>';
                                status.style.color = '#dc3545';
                            }
                        });
                },
                onNext: null,
            });
        }

        // Step 3: Microphone (if audio enabled).
        if (cfg.enableaudio) {
            steps.push({
                id:    'audio',
                icon:  '🎤',
                title: 'Microphone Check',
                body:  function() {
                    return '<p>We need to verify your microphone is working.</p>' +
                        '<div id="lg-wiz-audio-level" style="margin:16px auto;width:280px;height:20px;' +
                        'background:#dee2e6;border-radius:10px;overflow:hidden;">' +
                        '<div id="lg-wiz-audio-bar" style="height:100%;width:0;background:#28a745;transition:width 0.1s;"></div>' +
                        '</div>' +
                        '<p id="lg-wiz-audio-status" style="font-size:13px;color:#6c757d;">Requesting microphone…</p>';
                },
                btnLabel: 'Mic OK, Continue →',
                btnDisabled: true,
                onShow: function() {
                    navigator.mediaDevices.getUserMedia({audio: true, video: false})
                        .then(function(stream) {
                            var ctx = new (window.AudioContext || window.webkitAudioContext)();
                            var src = ctx.createMediaStreamSource(stream);
                            var analyser = ctx.createAnalyser();
                            analyser.fftSize = 256;
                            src.connect(analyser);
                            var buf = new Uint8Array(analyser.frequencyBinCount);
                            var detected = false;

                            var status = el('lg-wiz-audio-status');
                            if (status) { status.textContent = '🎤 Speak into your microphone to test…'; }

                            var anim = setInterval(function() {
                                analyser.getByteFrequencyData(buf);
                                var avg = buf.reduce(function(a, b) { return a + b; }, 0) / buf.length;
                                var bar = el('lg-wiz-audio-bar');
                                if (bar) { bar.style.width = Math.min(100, avg * 2) + '%'; }
                                if (avg > 15 && !detected) {
                                    detected = true;
                                    if (status) { status.textContent = '✅ Microphone detected and working.'; status.style.color = '#28a745'; }
                                    var btn = el('lg-wiz-next-btn');
                                    if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
                                }
                            }, 100);

                            // Stop mic tracks after wizard.
                            window._lgAudioCleanup = function() {
                                clearInterval(anim);
                                stream.getTracks().forEach(function(t) { t.stop(); });
                                try { ctx.close(); } catch (e) {}
                            };
                        })
                        .catch(function(err) {
                            var status = el('lg-wiz-audio-status');
                            if (status) {
                                status.innerHTML = '⚠️ Microphone access denied: ' + (err.message || err.name) +
                                    '<br><small>You may continue but audio monitoring may be affected.</small>';
                                status.style.color = '#fd7e14';
                            }
                            var btn = el('lg-wiz-next-btn');
                            if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
                        });
                },
                onNext: function() {
                    if (window._lgAudioCleanup) { window._lgAudioCleanup(); delete window._lgAudioCleanup; }
                },
            });
        }

        // Step: Multiple monitor check.
        steps.push({
            id:    'monitor',
            icon:  '🖥',
            title: 'Single Screen Required',
            body:  function() {
                var extended = ('screen' in window && window.screen.isExtended);
                var wide     = (window.screen.width > 2560);
                var detected = extended || wide;
                return '<p>Only one screen is permitted during this assessment.</p>' +
                    '<div style="background:' + (detected ? '#f8d7da' : '#d4edda') + ';border-radius:8px;' +
                    'padding:16px;margin-bottom:16px;text-align:center;">' +
                    '<div style="font-size:40px;margin-bottom:8px;">' + (detected ? '⚠️' : '✅') + '</div>' +
                    '<strong>' + (detected
                        ? 'Multiple monitors detected. Please disconnect extra screens and click Continue.'
                        : 'Single screen confirmed.') +
                    '</strong></div>' +
                    '<p style="font-size:13px;color:#6c757d;">If you are using a single screen and see a warning, ' +
                    'disconnect any external displays and refresh this step.</p>';
            },
            btnLabel: 'Continue →',
            onNext: null,
        });

        // Final step: Fullscreen & launch.
        steps.push({
            id:    'launch',
            icon:  '⛶',
            title: cfg.enablefullscreen ? 'Enter Fullscreen & Begin' : 'Ready to Begin',
            body:  function() {
                return (cfg.enablefullscreen
                    ? '<p>The assessment requires fullscreen mode.</p>' +
                      '<p style="color:#6c757d;font-size:14px;">Your browser will enter fullscreen when you click the button below. ' +
                      'Do not exit fullscreen during the assessment — violations are logged.</p>'
                    : '<p>All checks complete! Click <strong>Begin Assessment</strong> to start.</p>') +
                    '<div style="background:#d1e7dd;border-radius:8px;padding:12px 16px;font-size:14px;color:#0a3622;">' +
                    '✅ Pre-exam checks complete. Good luck!</div>';
            },
            btnLabel: cfg.enablefullscreen ? '⛶ Enter Fullscreen & Begin' : '▶ Begin Assessment',
            btnColour: '#28a745',
            onNext: function() { launchAssessment(); },
        });

        return steps;
    }

    /* showWizardStep and runPreExamWizard are now defined above using renderWizardStep */

    /* =======================================================================
     * LAUNCH ASSESSMENT (after wizard completes or when proctoring disabled)
     * ====================================================================== */
    // Pathway / role-based question selection (Choice Essay only): shown before ANY
    // attempt is created and before proctoring/camera setup, so — same principle as the
    // v2.0.167 clock-deferral fix — nothing about this screen ever costs the student exam
    // time; the clock only starts later, once content is actually ready to show.
    function showPathwaySelectionScreen(onChosen) {
        var spa = el('lg-spa-container');
        if (spa) {
            spa.innerHTML = '<div style="text-align:center;padding:60px;color:#6c757d;">' +
                '<div class="lg-spinner"></div><p>Loading pathways…</p></div>';
        }
        function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
        callWS('mod_learnguard_get_pathway_units', {cmid: cfg.cmid}).then(function(data) {
            var units = (data && data.units) || [];
            if (!units.length) {
                // No eligible units configured yet — proceed without one rather than
                // trapping the student; the server independently treats pathwayunitid=0
                // as "not a pathway attempt" and won't apply any pathway-only draw logic.
                onChosen(0);
                return;
            }
            var html = '<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:40px 20px;">' +
                '<div style="max-width:640px;width:100%;text-align:center;">' +
                '<h2 style="font-size:32px;margin-bottom:16px;">Select your Pathway Role</h2>' +
                '<p style="color:#6c757d;font-size:18px;line-height:1.6;margin-bottom:28px;">Choose the role/pathway you are ' +
                'being assessed for. Your questions will be generated based on this choice ' +
                '— you will not be able to change it once selected.</p>' +
                '<div style="display:flex;flex-direction:column;gap:14px;">';
            units.forEach(function(u) {
                html += '<button type="button" class="lg-pathway-choose" data-unitid="' + parseInt(u.id, 10) + '" ' +
                    'style="padding:20px 24px;font-size:19px;font-weight:600;border:2px solid #0f6cbf;' +
                    'border-radius:8px;background:#fff;color:#0f6cbf;cursor:pointer;">' +
                    esc(u.name) + '</button>';
            });
            html += '</div></div></div>';
            if (spa) { spa.innerHTML = html; }
            document.querySelectorAll('.lg-pathway-choose').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    onChosen(parseInt(this.dataset.unitid, 10) || 0);
                });
            });
        }).catch(function() {
            onChosen(0);
        });
    }

    // After a fresh pathway draw (N from the chosen role + M from the other pool), the
    // attempt already has all of them assigned as slots — but nothing has been shown or
    // answered yet, and the exam clock has not started. This shows every one of them at
    // once and requires the student to pick exactly ONE before proceeding; the choice is
    // then locked in server-side (mod_learnguard_lock_pathway_choice deletes the rest),
    // so the rest of the exam only ever has that single question in it. Reading these and
    // choosing is still entirely before the clock starts, same as the pathway-role screen.
    function showPathwayQuestionPicker(attemptid, onLocked) {
        var spa = el('lg-spa-container');
        if (spa) {
            spa.innerHTML = '<div style="text-align:center;padding:60px;color:#6c757d;">' +
                '<div class="lg-spinner"></div><p>Loading your questions…</p></div>';
        }
        callWS('mod_learnguard_get_pathway_previews', {attemptid: attemptid}).then(function(data) {
            var questions = (data && data.questions) || [];
            if (!questions.length) { onLocked(); return; }
            var html = '<div style="max-width:820px;margin:40px auto;padding:0 20px 60px;">' +
                '<h2 style="text-align:center;margin-bottom:8px;">Choose Your Question</h2>' +
                '<p style="text-align:center;color:#6c757d;font-size:16px;margin-bottom:8px;">' +
                'Read each question below, then choose ONE to answer.</p>' +
                '<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:8px;' +
                'padding:12px 18px;margin-bottom:24px;text-align:center;font-size:14px;color:#856404;font-weight:600;">' +
                '⚠️ Once you choose a question, you cannot go back and see the others — ' +
                'you will only be able to answer the one you pick. Be certain before proceeding.</div>';
            questions.forEach(function(q, idx) {
                html += '<div style="border:2px solid #dee2e6;border-radius:10px;padding:22px 26px;margin-bottom:16px;background:#fafafa;">' +
                    '<div style="font-size:11px;color:#6c757d;font-weight:700;letter-spacing:0.5px;' +
                    'margin-bottom:10px;text-transform:uppercase;">Question ' + (idx + 1) + ' &middot; ' + q.maxmark + ' marks</div>' +
                    '<div style="font-size:16px;line-height:1.7;color:#212529;margin-bottom:18px;">' + q.html + '</div>' +
                    '<button type="button" class="lg-pathway-q-choose" data-slotid="' + q.slotid + '" ' +
                    'style="background:#0f6cbf;color:#fff;border:none;border-radius:6px;' +
                    'padding:10px 22px;font-size:14px;font-weight:700;cursor:pointer;">Choose this question &rarr;</button>' +
                    '</div>';
            });
            html += '</div>';
            if (spa) { spa.innerHTML = html; }
            document.querySelectorAll('.lg-pathway-q-choose').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    if (btn.disabled) { return; }
                    document.querySelectorAll('.lg-pathway-q-choose').forEach(function(b) { b.disabled = true; });
                    var chosenSlotId = parseInt(this.dataset.slotid, 10);
                    var chosenMaxmark = 0;
                    for (var qi = 0; qi < questions.length; qi++) {
                        if (questions[qi].slotid === chosenSlotId) { chosenMaxmark = questions[qi].maxmark; break; }
                    }
                    if (spa) {
                        spa.innerHTML = '<div style="text-align:center;padding:60px;color:#6c757d;">' +
                            '<div class="lg-spinner"></div><p>Locking in your choice…</p></div>';
                    }
                    // Must not fail-open here: if this never actually reaches the server,
                    // the attempt still has every drawn question sitting in the DB, and
                    // proceeding as if it were narrowed to 1 would leave the client and
                    // server permanently disagreeing about the exam's own shape. Retries a
                    // few times before showing a clear retry screen instead.
                    function doLock(retryCount) {
                        callWS('mod_learnguard_lock_pathway_choice',
                            {attemptid: attemptid, chosenslotid: chosenSlotId})
                            .then(function() { onLocked(chosenSlotId, chosenMaxmark); })
                            .catch(function(err) {
                                if (retryCount < 2) {
                                    setTimeout(function() { doLock(retryCount + 1); }, lgJitter(1500 * (retryCount + 1)));
                                    return;
                                }
                                if (spa) {
                                    spa.innerHTML = '<div style="max-width:480px;margin:80px auto;text-align:center;">' +
                                        '<p style="color:#dc3545;font-weight:600;">Could not save your choice: ' +
                                        (err && err.message ? err.message : 'please check your connection.') + '</p>' +
                                        '<button type="button" id="lg-pathway-lock-retry" style="background:#0f6cbf;color:#fff;' +
                                        'border:none;border-radius:6px;padding:10px 22px;font-size:14px;font-weight:700;' +
                                        'cursor:pointer;">Try Again</button></div>';
                                    var retryBtn = el('lg-pathway-lock-retry');
                                    if (retryBtn) { retryBtn.addEventListener('click', function() { doLock(0); }); }
                                }
                            });
                    }
                    doLock(0);
                });
            });
        }).catch(function() {
            // Must not silently proceed here either — nothing has been chosen/locked yet,
            // so the attempt still has every drawn question. Show a retry rather than
            // fake a choice that was never made.
            if (spa) {
                spa.innerHTML = '<div style="max-width:480px;margin:80px auto;text-align:center;">' +
                    '<p style="color:#dc3545;font-weight:600;">Could not load your questions. Please check your connection.</p>' +
                    '<button type="button" id="lg-pathway-preview-retry" style="background:#0f6cbf;color:#fff;' +
                    'border:none;border-radius:6px;padding:10px 22px;font-size:14px;font-weight:700;' +
                    'cursor:pointer;">Try Again</button></div>';
                var retryBtn = el('lg-pathway-preview-retry');
                if (retryBtn) { retryBtn.addEventListener('click', function() { showPathwayQuestionPicker(attemptid, onLocked); }); }
            }
        });
    }

    function launchAssessment() {
        // A brand-new attempt on a pathway-enabled activity must ask which pathway the
        // student is choosing BEFORE anything else happens (attempt creation, proctoring
        // setup) — cfg.attemptid > 0 means this is actually a resume (page reload), which
        // already has its slots/pathway fixed from when the attempt was first created, so
        // the pathway screen must never show again for it.
        if (cfg.pathwayenabled && !cfg.attemptid) {
            showPathwaySelectionScreen(function(pathwayUnitId) {
                launchAssessmentWithPathway(pathwayUnitId);
            });
            return;
        }
        launchAssessmentWithPathway(0);
    }

    function launchAssessmentWithPathway(pathwayUnitId) {
        // Stop wizard camera stream (we'll restart it via initWebcam with proper state).
        if (cameraStream) {
            cameraStream.getTracks().forEach(function(t) { t.stop(); });
            cameraStream = null;
        }

        // Now init all proctoring. This is the ONLY place proctoring is activated.
        if (cfg.proctoring) { initProctoring(); }

        var spa = el('lg-spa-container');
        if (spa) {
            spa.innerHTML = '<div style="text-align:center;padding:60px;color:#6c757d;">' +
                '<div class="lg-spinner"></div><p>Starting assessment…</p></div>';
        }

        callWS('mod_learnguard_start_attempt', {cmid: cfg.cmid, pathwayunitid: pathwayUnitId || 0})
            .then(function(data) {
                state.attemptid      = data.attemptid;
                state.currentslot    = data.currentslot;
                state.totalquestions = data.totalquestions;
                state.timelimit      = data.timelimit;
                state.timestart      = data.timestart;
                state.navigation     = data.navigation;
                buildSections();
                state.currentSectionIdx = -1; // section intro will fire on first loadQuestion

                // Upload the 360° environment recording captured during precheck, if any.
                if (pending360Video) {
                    callWS('mod_learnguard_upload_360', {
                        attemptid: state.attemptid,
                        videodata: pending360Video.videodata,
                        duration:  pending360Video.duration,
                    }).catch(function() {});
                    pending360Video = null;
                }

                // Flag this attempt for mandatory identity review if precheck's face
                // step fell through to an unverified self-tick (see pendingIdentityUnverified).
                if (pendingIdentityUnverified) {
                    callWS('mod_learnguard_flag_identity_unverified', {attemptid: state.attemptid}).catch(function() {});
                    pendingIdentityUnverified = false;
                }

                // Persist the Typing Pattern Baseline for audit/report visibility — the
                // live comparison itself reads the in-memory typingBaselineAvg directly,
                // this is only so a proctor reviewing the attempt later can see it too.
                if (typingBaselineAvg > 0) {
                    callWS('mod_learnguard_save_typing_baseline',
                        {attemptid: state.attemptid, avginterval: typingBaselineAvg}).catch(function() {});
                }

                // Pathway draw not yet narrowed to one question — show every drawn
                // question (N from the chosen role + M from the other pool) and make the
                // student pick exactly ONE before anything else happens. Still entirely
                // before the exam clock starts, same guarantee as the pathway-role screen
                // itself — reading these and choosing costs zero exam time.
                if (data.pathwaypending) {
                    showPathwayQuestionPicker(state.attemptid, function(chosenSlotId, chosenMaxmark) {
                        state.totalquestions = 1;
                        state.currentslot    = 1;
                        // Rebuild to a single entry matching the locked-in choice — the nav
                        // bar (renderNavigation()) reads this directly, and it still held
                        // every originally-drawn question's entry otherwise, showing a full
                        // Q1/Q2/Q3 nav bar for an attempt that now only has one question.
                        state.navigation = [{
                            slot: 1, slotid: chosenSlotId || 0, state: 'todo',
                            answered: false, maxmark: chosenMaxmark || 0, catid: 0, catname: '',
                        }];
                        buildSections();
                        state.currentSectionIdx = -1;
                        proceedToPreloadAndReveal();
                    });
                    return;
                }
                proceedToPreloadAndReveal();

                // Q1 must not render until fullscreen is CONFIRMED active — previously the
                // exam shell rendered unconditionally the instant this WS call resolved,
                // regardless of whether the automatic enterFS() request (fired moments
                // earlier in initProctoring()) actually succeeded. A student could end up
                // answering questions in a normal windowed browser the whole time.
                // state.examStarted stays false until this gate clears, so the
                // fullscreenchange handler in initFullscreen() does not score this initial
                // gate as a fullscreen_exit violation — only fullscreen lost AFTER the
                // student has actually started counts against them.
                // Extracted into its own function so the pathway question-picker step
                // (above) can defer calling it until AFTER the student has narrowed a
                // fresh pathway draw down to one question — everything below still only
                // ever runs once, on the eventual single-question attempt either way.
                function proceedToPreloadAndReveal() {
                function lgRevealExamContent() {
                    hideFsBlocker();
                    showAssessmentShell();
                    startScheduleCountdown();
                    initWebcam();
                    initChatWidget();
                    startAutosave();
                    startPolling();
                    startUnsavedAnswerFlush();
                    loadQuestion(state.currentslot); // guaranteed a cache hit — see below.
                    renderNavigation();
                    state.examStarted = true;
                }

                // The exam clock (timestart) is left at 0 by start_or_resume_locked() —
                // it does NOT start here. It only starts once every question is preloaded
                // AND fullscreen (when required) is confirmed active, right at the instant
                // content is actually about to be shown. See start_exam_clock() in
                // classes/external.php for the full reasoning: a formal complaint showed a
                // student lose 14 of their 17 minutes to sustained "Failed to load
                // question" with the clock already running — none of the waiting below can
                // ever cost a student exam time, because the clock simply hasn't started.
                var lgPreloadDone = false;
                var lgFsReady = !(cfg.enablefullscreen && !fsIsActive());
                var lgPrepStart = Date.now();

                // Fixed 7-second minimum countdown, per direct request, to smooth out
                // "questions loading longer" — runs IN PARALLEL with the preload below
                // (which starts immediately, not after the countdown), so it's a
                // reassuring, predictable buffer in the common case rather than added
                // wait time on top of an already-slow load. It only ever ADDS a floor —
                // reveal still also waits for the real preload if that takes longer than
                // 7s, never revealing before content is actually ready either way.
                var LG_PREP_COUNTDOWN_SECS = 7;
                var lgCountdownLeft = LG_PREP_COUNTDOWN_SECS;
                var lgCountdownDone = false;
                var lgCountdownTimer = setInterval(function() {
                    lgCountdownLeft--;
                    if (lgCountdownLeft <= 0) {
                        clearInterval(lgCountdownTimer);
                        lgCountdownDone = true;
                        lgMaybeReveal();
                    } else {
                        lgShowPreparing();
                    }
                }, 1000);

                function lgShowPreparing() {
                    var spa = el('lg-spa-container');
                    // Skip while the fullscreen-required overlay is up — it sits at a
                    // higher z-index and takes visual priority; no point rendering
                    // underneath it.
                    if (!spa || !lgFsReady) { return; }
                    var longWait = (Date.now() - lgPrepStart) > 20000;
                    spa.innerHTML = '<div style="text-align:center;padding:60px;color:#6c757d;">' +
                        '<div class="lg-spinner"></div><p>Preparing your assessment…</p>' +
                        (lgCountdownLeft > 0 && !longWait
                            ? '<p style="font-size:28px;font-weight:800;color:#0f6cbf;margin:8px 0;">' + Math.max(0, lgCountdownLeft) + '</p>'
                            : '') +
                        '<p style="font-size:12px;color:#adb5bd;">' +
                        (longWait
                            ? 'Still preparing — thank you for your patience. Your time has not started yet, please do not close this page.'
                            : 'This can take a moment on a slow connection — your time has not started yet.') +
                        '</p></div>';
                }

                // Refreshes the "Preparing…" message periodically (e.g. to switch to the
                // reassuring long-wait wording) so a slow preload never looks frozen —
                // real-world reports of this exact kind of wait have run into the tens of
                // minutes under sustained server distress.
                var lgPrepRefresh = setInterval(lgShowPreparing, 5000);

                function lgMaybeReveal() {
                    if (!lgPreloadDone || !lgFsReady || !lgCountdownDone) { lgShowPreparing(); return; }
                    clearInterval(lgPrepRefresh);
                    clearInterval(lgCountdownTimer);
                    lgStartExamClockRobust(function() {
                        // initTimer() looks up #lg-timer, which showAssessmentShell()
                        // (called inside lgRevealExamContent()) is what actually creates —
                        // calling initTimer() first found no element, silently no-op'd
                        // (its own early-return guard), and nothing ever called it again:
                        // the exam timer never started at all on a fresh launch. The other
                        // two initTimer() call sites (resume / Continue & Review) are fine
                        // because they run AFTER showAssessmentShell() has already built the
                        // shell earlier in that same flow.
                        lgRevealExamContent();
                        initTimer();
                    });
                }

                lgShowPreparing();
                preloadAllQuestionsBlocking(function() {
                    lgPreloadDone = true;
                    lgMaybeReveal();
                });

                if (cfg.enablefullscreen && !fsIsActive()) {
                    showFsBlocker();
                    var lgFsGateTimer = setInterval(function() {
                        if (fsIsActive()) {
                            clearInterval(lgFsGateTimer);
                            hideFsBlocker();
                            lgFsReady = true;
                            lgMaybeReveal();
                        }
                    }, 400);
                }
                } // end proceedToPreloadAndReveal
            })
            .catch(function(err) {
                if (spa) {
                    spa.innerHTML = '<div class="alert alert-danger" style="margin:40px auto;max-width:600px;">' +
                        'Failed to start assessment: ' + (err.message || 'Please try again.') + '</div>';
                }
            });
    }

    /* =======================================================================
     * RESUME ASSESSMENT
     * ====================================================================== */
    // Stores the timesuspended value from the FIRST call to beginResumedAssessment()
    // so that the SECOND call (after reinstatement precheck completes) can use it
    // for timer restoration without hitting the "precheck needed" branch again.
    // 0 = first call; > 0 = precheck already triggered, skip the guard on next entry.
    var lgReinstateSuspendedAt = 0;

    // Screen sharing is mandatory whenever proctoring is on (enforced by the wizard's
    // 'screenshare' step on a fresh start), but a live getDisplayMedia() stream is a
    // page-scoped JS object — it cannot survive a reload/reconnect. beginResumedAssessment()
    // runs on every resume of an already-in-progress attempt, so without this gate a
    // student who refreshes, reopens a closed tab, or reconnects after a dropped
    // connection would silently continue the exam with zero further screen captures and
    // no re-prompt for the rest of the session. Blocks until a fresh full-screen share is
    // granted (or reuses one still live from earlier in this same page load, e.g. break resume).
    function requireScreenShareGate(onReady) {
        if (state.screenStream) {
            var liveTrack = state.screenStream.getVideoTracks()[0];
            if (liveTrack && liveTrack.readyState === 'live') {
                var liveSurf = (liveTrack.getSettings) ? (liveTrack.getSettings().displaySurface || '') : '';
                if (liveSurf !== 'browser' && liveSurf !== 'window' && liveSurf !== 'application') {
                    onReady();
                    return;
                }
                state.screenStream.getTracks().forEach(function(t) { t.stop(); });
                state.screenStream = null;
            }
        }

        var overlay = document.createElement('div');
        overlay.id = 'lg-resume-ss-gate';
        overlay.style.cssText = 'position:fixed;inset:0;z-index:99998;background:rgba(15,25,35,0.97);' +
            'display:flex;align-items:center;justify-content:center;flex-direction:column;' +
            'font-family:"Segoe UI",system-ui,sans-serif;color:#fff;padding:20px;text-align:center;';
        overlay.innerHTML =
            '<div style="font-size:64px;margin-bottom:16px;">🖥</div>' +
            '<h2 style="margin:0 0 12px;font-size:22px;font-weight:800;">Screen Sharing Required to Continue</h2>' +
            '<p style="margin:0 0 20px;font-size:15px;opacity:.9;max-width:460px;">' +
            'Screen sharing is mandatory for this proctored exam and must be re-shared after a reload. ' +
            'Click below and select your <strong>Entire Screen</strong> (not a window or tab) to resume.</p>' +
            '<button id="lg-resume-ss-btn" ' +
            'style="background:#0f6cbf;color:#fff;border:none;border-radius:10px;' +
            'padding:14px 32px;font-size:15px;font-weight:700;cursor:pointer;">🖥 Share My Screen</button>' +
            '<p id="lg-resume-ss-msg" style="margin:14px 0 0;font-size:13px;opacity:.9;max-width:440px;"></p>';
        document.body.appendChild(overlay);

        var btn = document.getElementById('lg-resume-ss-btn');
        var msg = document.getElementById('lg-resume-ss-msg');
        if (!btn) { return; }
        btn.onclick = function() {
            btn.disabled = true;
            btn.textContent = 'Requesting…';
            if (msg) { msg.textContent = ''; }
            var constraints = {video: true, audio: false};
            try { constraints.video = {displaySurface: 'monitor'}; } catch (e) {}
            navigator.mediaDevices.getDisplayMedia(constraints)
                .then(function(stream) {
                    var track = stream.getVideoTracks()[0];
                    var surf  = (track && track.getSettings) ? (track.getSettings().displaySurface || '') : '';
                    if (surf === 'browser' || surf === 'window' || surf === 'application') {
                        stream.getTracks().forEach(function(t) { t.stop(); });
                        btn.disabled = false;
                        btn.textContent = '🖥 Try Again (Select Entire Screen)';
                        if (msg) { msg.textContent = '⚠️ You shared a ' + (surf === 'browser' ? 'browser tab' : surf) + '. Please share your Entire Screen instead.'; }
                        return;
                    }
                    state.screenStream = stream;
                    track.addEventListener('ended', function() {
                        if (state.proctoringActive && !state.finished) {
                            logProctoringEvent('screen_share_stopped', {}, 'high', (cfg.scoreconfig && cfg.scoreconfig.screensharestopped) || 10);
                            showToast('⚠️ Screen sharing stopped! Please resume to continue.', 'danger', 0);
                            showScreenSharePausedOverlay();
                        }
                    });
                    var old = document.getElementById('lg-resume-ss-gate');
                    if (old && old.parentNode) { old.parentNode.removeChild(old); }
                    onReady();
                })
                .catch(function() {
                    btn.disabled = false;
                    btn.textContent = '🖥 Share My Screen';
                    if (msg) { msg.textContent = '🚫 Screen sharing was declined. It is mandatory — click the button and select your Entire Screen to continue.'; }
                });
        };
    }

    function beginResumedAssessment() {
        // Clear suspended flag NOW — precheck is done, proctoring is about to start.
        // (Kept true during the precheck wizard to block in-flight WS auto-suspend responses.)
        state.suspended = false;
        state.autoSuspendFired = false; // allow re-suspension at the new (higher) post-reinstate threshold
        state.attemptid = cfg.attemptid;

        // Self-paused attempts are still state='suspended' server-side — the student's own
        // "Redo Security Check & Resume" click only ran the precheck locally. Flip it to
        // inprogress here (self-service, no proctor needed) before checking attempt state,
        // otherwise the check below would just show the suspended screen again.
        // reviewMode: the student just finished the required precheck for a Continue &
        // Review session — activate its timer (server sets timestart=now) before proceeding.
        var preResume = state.selfPaused
            ? callWS('mod_learnguard_self_resume', {attemptid: state.attemptid}).catch(function() {})
            : (state.reviewMode
                ? callWS('mod_learnguard_start_review', {attemptid: state.attemptid}).catch(function() {})
                : Promise.resolve());

        preResume.then(function() {
        state.selfPaused = false;
        state.reviewMode = false;

        // Check state BEFORE initialising proctoring/UI — a suspended attempt
        // should go straight to the "Attempt Suspended" screen without
        // requesting the webcam/screen-share/fullscreen.
        callWS('mod_learnguard_get_attempt_state', {attemptid: state.attemptid})
            .then(function(data) {
                if (data.suspended) {
                    state.selfPaused = !!data.self_paused;
                    state.confirmedSuspension = !!data.is_flagged;
                    showSuspendedScreen();
                    return;
                }

                // Continue & Review: an already-finished attempt a proctor reopened via
                // Assessment Manager. Must redo the precheck once before continuing — shown
                // exactly once per page-load (reviewPrecheckDone guards the second entry,
                // after the precheck completes and start_review has activated the timer).
                if (data.reopened_for_review && !state.reviewPrecheckDone) {
                    state.reviewPrecheckDone = true;
                    showReviewReopenedScreen();
                    return;
                }

                // timesuspended > 0 means: attempt was suspended and proctor reinstated it,
                // but the student has not yet completed the required reinstatement precheck.
                // lgReinstateSuspendedAt > 0 means precheck was already triggered on the
                // FIRST call — skip this guard on the SECOND call (after precheck completes)
                // to avoid an infinite loop back to the suspended screen.
                if (data.timesuspended > 0 && cfg.proctoring && lgReinstateSuspendedAt === 0) {
                    lgReinstateSuspendedAt = data.timesuspended; // save for timer restoration
                    state.suspended = true; // block proctoring events until precheck completes
                    state.riskScore = data.risk_score;
                    state.riskLevel = data.risk_level;
                    var delta = (cfg.reinstate_threshold_delta > 0) ? cfg.reinstate_threshold_delta : 20;
                    state.reinstateSuspendThreshold = data.risk_score + delta;
                    showSuspendedScreen();
                    // Small delay so showSuspendedScreen() has rendered the DOM.
                    setTimeout(function() {
                        showReinstatementMessage(data.risk_score, state.reinstateSuspendThreshold);
                    }, 80);
                    return;
                }

                if (cfg.proctoring) {
                    requireScreenShareGate(function() { proceedResumedAttempt(data); });
                } else {
                    proceedResumedAttempt(data);
                }
            });
        });
    }

    function proceedResumedAttempt(data) {
                // Restore risk score BEFORE proctoring init so the halfway warning
                // is not falsely re-triggered on resume when the score was already past
                // the halfway mark (the student already saw it before suspension).
                state.riskScore = data.risk_score;
                state.riskLevel = data.risk_level;
                var halfwayMark = ((state.reinstateSuspendThreshold > 0
                    ? state.reinstateSuspendThreshold
                    : (cfg.autosuspend_score > 0 ? cfg.autosuspend_score : 80)) * 0.5);
                if (state.riskScore >= halfwayMark) { lgHalfWarnShown = true; }

                // Init proctoring immediately for resumed attempts.
                if (cfg.proctoring) { initProctoring(); }
                // Request fullscreen NOW, as close as possible to the user gesture that
                // got here (the "Redo Security Check" button / wizard completion, OR —
                // critically — a plain page reload, which carries NO fresh user gesture
                // at all and very often gets this automatic request silently refused by
                // the browser). Only mark examStarted once fullscreen is CONFIRMED active
                // instead of immediately: a reload always drops fullscreen, so setting
                // this flag up front scored the student for "losing" fullscreen they had
                // never actually gotten back yet — exactly the case a student refreshing
                // to recover from a stuck page would hit.
                if (cfg.enablefullscreen) {
                    enterFS();
                    if (fsIsActive()) {
                        state.examStarted = true;
                    } else {
                        var fsResumeWait = setInterval(function() {
                            if (fsIsActive()) {
                                clearInterval(fsResumeWait);
                                state.examStarted = true;
                            }
                        }, 400);
                    }
                } else {
                    state.examStarted = true;
                }

                showAssessmentShell();

                state.currentslot    = data.currentslot;
                state.navigation     = data.navigation;
                state.totalquestions = data.navigation.length;
                buildSections();
                // For resumed attempts, mark already-visited sections so no intro fires on reload.
                if (state.sections && state.sections.length > 1) {
                    state.navigation.forEach(function(n) {
                        if (n.answered) {
                            var ai = getSectionIndex(n.slot);
                            if (ai >= 0 && state.sections[ai]) { state.sections[ai].visited = true; }
                        }
                    });
                    var ci = getSectionIndex(state.currentslot);
                    state.currentSectionIdx = ci;
                    if (ci >= 0 && state.sections[ci]) { state.sections[ci].visited = true; }

                    // Restore per-section countdown timers from server-authoritative deadlines.
                    if (data.section_deadlines && data.section_deadlines.length) {
                        data.section_deadlines.forEach(function(sd) {
                            if (!sd.deadline || sd.deadline <= 0) { return; }
                            var remaining = sd.deadline - Math.floor(Date.now() / 1000);
                            if (remaining <= 0) { return; } // already expired
                            for (var si = 0; si < state.sections.length; si++) {
                                if (state.sections[si].catid === sd.catid) {
                                    startSectionCountdownFromDeadline(si, sd.deadline);
                                    break;
                                }
                            }
                        });
                    }
                }

                // Continue & Review: the granted review minutes (stored in extra_time by
                // the Assessment Manager override) ARE the full timer for this session,
                // independent of the original activity's time limit. Handled entirely
                // separately from the normal-resume path below: start_review() (external.php)
                // deliberately leaves timestart at 0 rather than starting it immediately —
                // every question needs to be re-fetched here (a review's questions were
                // never cached from any earlier session), so without this a slow/failing
                // load could burn straight into the granted review minutes with the
                // student seeing nothing but errors, exactly the pattern already reported
                // and fixed for a fresh attempt in launchAssessment().
                if (data.reopened_for_review) {
                    state.timelimit = data.extra_time || 0;
                    state.extratime = 0;
                    // Every question was already answered once in the original attempt —
                    // navigating between them doesn't need a server round trip each time,
                    // and there's no "new" answer at risk of being lost the way a first
                    // attempt would have one. Cache questions client-side and hold edits
                    // locally; everything is flushed to the server once, on final submit.
                    state.reopenedForReview = true;
                    state.timestart = 0;

                    var lgRevPrepStart = Date.now();
                    function lgShowReviewPreparing() {
                        var qc = el('lg-question-container');
                        if (!qc) { return; }
                        var longWait = (Date.now() - lgRevPrepStart) > 20000;
                        qc.innerHTML = '<div style="text-align:center;padding:60px;color:#6c757d;">' +
                            '<div class="lg-spinner"></div><p>Preparing your review…</p>' +
                            '<p style="font-size:12px;color:#adb5bd;">' +
                            (longWait
                                ? 'Still preparing — thank you for your patience. Your review time has not started yet.'
                                : 'Loading your previous answers — your review time has not started yet.') +
                            '</p></div>';
                    }
                    lgShowReviewPreparing();
                    var lgRevPrepRefresh = setInterval(lgShowReviewPreparing, 5000);

                    preloadAllQuestionsBlocking(function() {
                        clearInterval(lgRevPrepRefresh);
                        lgStartExamClockRobust(function() {
                            initTimer();
                            startScheduleCountdown();
                            initWebcam();
                            initChatWidget();
                            startAutosave();
                            startPolling();
                            startUnsavedAnswerFlush();
                            loadQuestion(state.currentslot);
                            renderNavigation();
                            updateRiskDisplay();
                        });
                    });
                    return;
                }

                if (!state.timelimit) { state.timelimit = cfg.timelimit || 0; }
                state.extratime = data.extra_time || 0;
                // Use lgReinstateSuspendedAt (stored on first call) if available,
                // otherwise fall back to data.timesuspended (normal resume, no precheck).
                var suspendedAt = (lgReinstateSuspendedAt > 0)  ? lgReinstateSuspendedAt
                                : (data.timesuspended > 0)      ? data.timesuspended
                                : (state.suspendedAt  > 0)      ? state.suspendedAt : 0;
                lgReinstateSuspendedAt = 0; // clear after use
                state.suspendedAt = 0;

                function lgFinishReveal() {
                    initTimer();
                    startScheduleCountdown();
                    initWebcam();
                    initChatWidget();
                    startAutosave();
                    startPolling();
                    startUnsavedAnswerFlush();
                    loadQuestion(state.currentslot);
                    renderNavigation();
                    updateRiskDisplay();
                }

                if (suspendedAt > 0) {
                    // Genuine reinstatement (proctor lifted a suspension; the student just
                    // redid the required precheck). resumedNow is deliberately captured
                    // LATE — right before reveal, after every question is confirmed
                    // preloaded — not immediately on entry here. Capturing it early (as
                    // this used to) meant the suspendedFor shift correctly excluded the
                    // precheck-redo time, but NOT any loading delay that happened after —
                    // the same class of bug reported and fixed for a fresh attempt and a
                    // Continue & Review session (see launchAssessment()): the clock would
                    // already be counting down while the student stared at "Failed to load
                    // question" with no way to recover except waiting out the retry limit.
                    var lgReinPrepStart = Date.now();
                    function lgShowReinPreparing() {
                        var qc = el('lg-question-container');
                        if (!qc) { return; }
                        var longWait = (Date.now() - lgReinPrepStart) > 20000;
                        qc.innerHTML = '<div style="text-align:center;padding:60px;color:#6c757d;">' +
                            '<div class="lg-spinner"></div><p>Resuming your assessment…</p>' +
                            '<p style="font-size:12px;color:#adb5bd;">' +
                            (longWait
                                ? 'Still preparing — thank you for your patience. Your remaining time has not resumed counting yet.'
                                : 'This can take a moment on a slow connection — your remaining time has not resumed counting yet.') +
                            '</p></div>';
                    }
                    lgShowReinPreparing();
                    var lgReinPrepRefresh = setInterval(lgShowReinPreparing, 5000);

                    preloadAllQuestionsBlocking(function() {
                        clearInterval(lgReinPrepRefresh);
                        var resumedNow   = Math.floor(Date.now() / 1000);
                        var suspendedFor = Math.max(0, resumedNow - suspendedAt);
                        // Shift timestart forward so the remaining time = what it was at
                        // suspension — now correctly excluding the loading wait too.
                        state.timestart  = (data.timestart || resumedNow) + suspendedFor;
                        // Ask the server to persist the same shift (pass 0 → server
                        // computes from its own timesuspended and clears it to prevent
                        // double-counting).
                        callWS('mod_learnguard_extend_attempt_time', {
                            attemptid:     state.attemptid,
                            extra_seconds: 0,
                        }).catch(function() {});
                        lgFinishReveal();
                    });
                    return;
                }

                // Plain resume (page reload / reopened tab mid-exam, no suspension
                // involved) — the server-side clock never stopped running, so there is no
                // window here where waiting for a full preload would save any time; it
                // would only add a delay on top of an already-ticking clock. Reveal
                // immediately as before, relying on doLoadQuestion()'s own cache-first +
                // retry-protected fallback for Q1, and the existing best-effort
                // preloadRemainingQuestions() in the background.
                state.timestart = data.timestart || Math.floor(Date.now() / 1000);
                lgFinishReveal();
                preloadRemainingQuestions();
    }

    /* =======================================================================
     * SCREENS
     * ====================================================================== */

    // Inject site-wide LearnGuard heading styles once.
    function injectGlobalStyles() {
        if (document.getElementById('lg-global-styles')) { return; }
        var s = document.createElement('style');
        s.id = 'lg-global-styles';
        s.textContent =
            '#learnguard-app h1, #learnguard-app h2, #learnguard-app h3, #learnguard-app h4 { text-align:center; }' +
            '#lg-wizard-overlay h4, #lg-wizard-overlay h5 { text-align:center; }' +
            '#lg-wizard-overlay .lg-wiz-body h4 { margin-bottom:12px; }' +
            // ── Question card ─────────────────────────────────────────────
            '.lg-question-card { transition:box-shadow .2s; }' +
            '.lg-question-card:hover { box-shadow:0 6px 24px rgba(0,0,0,0.14); }' +
            // ── Option hover ──────────────────────────────────────────────
            '.lg-mc-answers label { transition:border-color .15s, background .15s; }' +
            '.lg-mc-answers label:hover { border-color:#0f6cbf !important; background:#f0f7ff !important; }' +
            '.lg-mc-check label { transition:border-color .15s, background .15s; }' +
            '.lg-mc-check label:hover { border-color:#0f6cbf !important; background:#f0f7ff !important; }' +
            // ── Accessibility: focus rings ────────────────────────────────
            '.lg-mc-answers label:focus-within, .lg-mc-check label:focus-within { outline:3px solid #0f6cbf; outline-offset:2px; }' +
            '.lg-tf-wrap label:focus-within { outline:3px solid #0f6cbf; outline-offset:2px; }' +
            '#lg-question-container *:focus { outline:2px solid #0f6cbf; outline-offset:2px; }' +
            // ── High contrast text ────────────────────────────────────────
            '.lg-qtext { font-size:20px !important; line-height:1.75 !important; }' +
            // ── Right toolbar buttons ─────────────────────────────────────
            '#lg-right-toolbar button:hover { filter:brightness(1.08); }' +
            '#lg-right-toolbar button:focus { outline:2px solid #0f6cbf; outline-offset:2px; }' +
            // ── Skip-to-content link (screen-reader shortcut) ─────────────
            '.lg-skip-link { position:absolute;top:-40px;left:0;background:#0f6cbf;color:#fff;' +
            'padding:8px 14px;z-index:99999;font-weight:700;text-decoration:none;border-radius:0 0 6px 0; }' +
            '.lg-skip-link:focus { top:0; }';
        document.head.appendChild(s);
    }

    function hideLoadingScreen() {
        var loading = el('lg-loading');
        if (loading) { loading.style.display = 'none'; }
        injectGlobalStyles();
    }

    function showInstructionsScreen() {
        var spa = el('lg-spa-container');
        if (!spa) { return; }
        var bp           = cfg.brandprimary || '#0f6cbf';
        var instructions = cfg.instructions || '';
        var pool         = (int)(cfg.questionspool || 0);
        var numq         = (int)(cfg.numquestions  || 0);
        var randomize    = cfg.randomize;
        var timelimit    = parseInt(cfg.timelimit, 10) || 0;

        // Build the "exam at a glance" info cards.
        var actualQ = (randomize && numq > 0) ? numq : pool;

        var tInfo = timelimit > 0
            ? formatSeconds(timelimit) + ' total'
            : 'No time limit';

        var passmark = parseFloat(cfg.passmark || 0);

        var infoCards =
            '<div style="display:flex;gap:16px;flex-wrap:wrap;justify-content:center;margin-bottom:24px;">' +
            infoCard('❓', pool > 0 ? actualQ + ' Question' + (actualQ !== 1 ? 's' : '') : '—', 'to attempt', bp) +
            // Only show the time card when a real time limit is configured.
            (timelimit > 0 ? infoCard('⏱', formatSeconds(timelimit), 'time allowed', bp) : '') +
            // Show pass mark card when a pass mark is configured.
            (passmark > 0 ? infoCard('🎯', passmark + '%', 'pass mark', '#198754') : '') +
            // Randomisation is an anti-cheating mechanism — not shown to learners.
            (cfg.proctoring ? infoCard('🛡', 'Proctored', 'monitoring active', '#dc3545') : '') +
            '</div>';

        // ── "Your Attempts" history — attempted / in-progress / completed ──
        var myAttempts = cfg.myattempts || [];
        var attemptsHtml = '';
        if (myAttempts.length > 0) {
            var stateLabel = {
                inprogress: ['🟠 In Progress', '#fd7e14'],
                finished:   ['✅ Completed',   '#28a745'],
                abandoned:  ['⚪ Abandoned',    '#6c757d'],
                suspended:  ['🚫 Suspended',   '#dc3545']
            };
            var rows = '';
            for (var ai = 0; ai < myAttempts.length; ai++) {
                var at = myAttempts[ai];
                var lbl = stateLabel[at.state] || ['—', '#6c757d'];
                var started  = at.timestart  > 0 ? new Date(at.timestart  * 1000).toLocaleString() : '—';
                var finished = at.timefinish > 0 ? new Date(at.timefinish * 1000).toLocaleString() : '—';
                var scoreTxt = (at.state === 'finished' && at.sumgrades !== null)
                    ? Math.round(at.sumgrades * 100) / 100 + ' / ' + (cfg.maxgrade || 0)
                    : '—';
                rows += '<tr>' +
                    '<td style="padding:8px 10px;text-align:center;">' + at.attempt + '</td>' +
                    '<td style="padding:8px 10px;"><span style="color:' + lbl[1] + ';font-weight:700;">' + lbl[0] + '</span></td>' +
                    '<td style="padding:8px 10px;font-size:13px;">' + started + '</td>' +
                    '<td style="padding:8px 10px;font-size:13px;">' + finished + '</td>' +
                    '<td style="padding:8px 10px;text-align:center;">' + scoreTxt + '</td>' +
                    '</tr>';
            }
            var hasScoredAttempt = myAttempts.some(function(at) {
                return at.state === 'finished' && at.sumgrades !== null;
            });
            attemptsHtml =
                '<div style="margin-bottom:24px;">' +
                '<strong style="display:block;margin-bottom:8px;">📋 Your Attempts</strong>' +
                '<div style="overflow-x:auto;border:1px solid #e9ecef;border-radius:8px;">' +
                '<table style="width:100%;border-collapse:collapse;font-size:14px;">' +
                '<thead><tr style="background:#f8f9fa;">' +
                '<th style="padding:8px 10px;text-align:center;">#</th>' +
                '<th style="padding:8px 10px;text-align:left;">Status</th>' +
                '<th style="padding:8px 10px;text-align:left;">Started</th>' +
                '<th style="padding:8px 10px;text-align:left;">Finished</th>' +
                '<th style="padding:8px 10px;text-align:center;">Score</th>' +
                '</tr></thead><tbody>' + rows + '</tbody></table></div>' +
                (hasScoredAttempt
                    ? '<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:8px;' +
                      'padding:10px 14px;margin-top:10px;font-size:13px;color:#856404;">' +
                      '⚠️ Starting a new attempt will <strong>replace your recorded score above</strong> ' +
                      'once submitted — your new result overrides the previous one, even if it is lower.</div>'
                    : '') +
                '</div>';
        }

        // Choice Essay grading rubric preview — only ever present in cfg when the admin
        // has both enabled the rubric AND chosen to show it to students (view.php already
        // gates this server-side; an empty array here just means "don't show anything").
        var rubricHtml = '';
        if (cfg.rubric && cfg.rubric.length > 0) {
            var rubricRows = '';
            for (var ri = 0; ri < cfg.rubric.length; ri++) {
                var crit = cfg.rubric[ri];
                rubricRows += '<tr style="border-bottom:1px solid #f0f0f0;">' +
                    '<td style="padding:8px 10px;">' + (crit.name || '') +
                    (crit.focus ? '<div style="font-size:12px;color:#6c757d;margin-top:2px;">' + crit.focus + '</div>' : '') +
                    '</td><td style="padding:8px 10px;font-weight:700;text-align:center;">' + (crit.weight || 0) + '%</td></tr>';
            }
            rubricHtml =
                '<div style="background:#f8f9fa;border-radius:8px;padding:20px;margin-bottom:20px;">' +
                '<strong style="display:block;margin-bottom:10px;">📋 Choice Essay Grading Rubric</strong>' +
                '<p style="font-size:13px;color:#6c757d;margin-bottom:10px;">' +
                'Any Choice Essay question in this assessment is graded against these criteria.</p>' +
                '<table style="width:100%;border-collapse:collapse;font-size:14px;">' +
                '<thead><tr style="background:#eef2f7;text-align:left;">' +
                '<th style="padding:8px 10px;">Criteria</th><th style="padding:8px 10px;text-align:center;">Weight</th>' +
                '</tr></thead><tbody>' + rubricRows + '</tbody></table></div>';
        }

        // Proctoring notice.
        var procNotice = cfg.proctoring
            ? '<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:8px;' +
              'padding:16px 20px;margin-bottom:24px;">' +
              '<p style="margin:0 0 6px;font-weight:700;font-size:15px;">⚠️ This assessment is proctored by LearnGuard.</p>' +
              '<p style="margin:0;font-size:13px;color:#6c757d;">A system check will run before the assessment starts. ' +
              'Your webcam, screen, and audio are monitored throughout.</p>' +
              '</div>' : '';

        // ── Bottom action area: Begin button OR no-attempts message ──────
        var canStart   = (cfg.canstart !== false);   // default true for back-compat
        var bottomHtml = '';
        // A learner outside their assigned/instance window cannot begin yet —
        // the live countdown below explains why and re-renders this screen
        // once the window opens or closes.
        var withinWindow = (cfg.isavailable !== false);

        if (!canStart && !cfg.canManage) {
            var taken = int(cfg.attemptstaken || 0);
            var maxA  = int(cfg.maxattempts   || 0);
            bottomHtml =
                '<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:10px;' +
                'padding:20px 28px;text-align:center;margin-top:8px;">' +
                '<div style="font-size:40px;margin-bottom:10px;">🚫</div>' +
                '<h4 style="margin:0 0 8px;color:#856404;">No Attempts Remaining</h4>' +
                '<p style="color:#6c757d;margin:0;">You have used all <strong>' + maxA + '</strong> ' +
                'allowed attempt' + (maxA !== 1 ? 's' : '') + ' for this assessment.<br>' +
                'Please contact your proctor if you need further assistance.</p>' +
                '</div>';
        } else if (!withinWindow && !cfg.canManage) {
            // No button — the countdown notice above is the only thing shown.
            bottomHtml = '';
        } else {
            bottomHtml =
                '<div style="text-align:center;margin-top:8px;padding-bottom:32px;">' +
                '<button id="lg-start-btn" style="background:' + bp + ';color:#fff;border:none;' +
                'border-radius:10px;padding:18px 56px;font-size:19px;font-weight:700;cursor:pointer;' +
                'box-shadow:0 4px 16px rgba(0,0,0,0.2);letter-spacing:0.3px;">' +
                '▶ Begin Assessment</button>' +
                '</div>';
        }

        // ── Availability notice — live countdown to open, or to a closing
        // deadline, or a static "closed" message ─────────────────────────
        var availNotice = '';
        if (!cfg.canManage) {
            var availNow = nowTs();
            if (cfg.timeopen > 0 && availNow < cfg.timeopen) {
                var openDate = new Date(cfg.timeopen * 1000).toLocaleString();
                availNotice = '<div id="lg-open-countdown" style="background:#e8f4fd;border:1px solid #bee5eb;border-radius:8px;' +
                    'padding:14px 18px;margin-bottom:20px;text-align:center;">' +
                    '<strong>🕐 This assessment opens on:</strong><br>' +
                    '<span style="font-size:16px;font-weight:700;color:#0c5460;">' + openDate + '</span><br>' +
                    '<span style="font-size:13px;color:#0c5460;">Opens in</span><br>' +
                    '<span id="lg-open-countdown-clock" style="display:inline-block;margin-top:4px;font-size:28px;' +
                    'font-weight:900;color:#0f6cbf;font-variant-numeric:tabular-nums;letter-spacing:1px;">--:--:--</span>' +
                    '</div>';
            } else if (cfg.timeclose > 0 && availNow > cfg.timeclose) {
                var closeDate = new Date(cfg.timeclose * 1000).toLocaleString();
                availNotice = '<div style="background:#f8d7da;border:1px solid #f5c6cb;border-radius:8px;' +
                    'padding:14px 18px;margin-bottom:20px;text-align:center;">' +
                    '<strong>🔒 This assessment closed on:</strong><br>' +
                    '<span style="font-size:16px;font-weight:700;color:#721c24;">' + closeDate + '</span></div>';
            } else if (cfg.timeclose > 0) {
                var availCloseDate = new Date(cfg.timeclose * 1000).toLocaleString();
                availNotice = '<div id="lg-close-countdown" style="background:#fff8e1;border:1px solid #ffe082;border-radius:8px;' +
                    'padding:10px 18px;margin-bottom:20px;text-align:center;">' +
                    '<strong>⏳ Available until ' + availCloseDate + '</strong><br>' +
                    '<span style="font-size:13px;color:#856404;">Closes in</span><br>' +
                    '<span id="lg-close-countdown-clock" style="display:inline-block;margin-top:4px;font-size:26px;' +
                    'font-weight:900;color:#fd7e14;font-variant-numeric:tabular-nums;letter-spacing:1px;">--:--:--</span>' +
                    '</div>';
            }
        }

        spa.innerHTML =
            '<div style="max-width:720px;margin:0 auto;padding:40px 28px 24px;font-family:system-ui,sans-serif;">' +
            // Assessment title — centred.
            '<div style="text-align:center;margin-bottom:24px;">' +
            '<div style="display:inline-block;width:56px;height:4px;background:' + bp + ';border-radius:2px;margin-bottom:10px;"></div>' +
            '<h2 style="margin:0;font-size:26px;font-weight:800;color:#212529;">' + (cfg.assessmentname || 'Assessment') + '</h2>' +
            '</div>' +
            availNotice +
            infoCards +
            attemptsHtml +
            (instructions
                ? '<div style="background:#f8f9fa;border-radius:8px;padding:20px;margin-bottom:20px;font-size:15px;line-height:1.6;">' +
                  '<strong style="display:block;margin-bottom:8px;text-align:center;">📋 Instructions</strong>' +
                  instructions + '</div>'
                : '') +
            rubricHtml +
            procNotice +
            bottomHtml +
            '</div>';

        // Instructions must scroll naturally. Remove the height:100vh constraint
        // from the outer app shell so the browser's own scroll bar takes over.
        spa.style.display    = 'block';
        spa.style.overflow   = 'visible';
        spa.style.position   = 'relative';
        spa.style.flex       = '0 0 auto';
        var appEl = document.getElementById('learnguard-app');
        if (appEl) {
            appEl.style.height    = 'auto';
            appEl.style.minHeight = '100vh';
            appEl.style.overflowY = 'auto';
        }
        document.body.style.overflow = '';

        if (canStart || cfg.canManage) {
            var startBtn = el('lg-start-btn');
            if (startBtn) {
                startBtn.addEventListener('click', function() {
                    // Phase 3: PIN validation before proceeding.
                    if (cfg.requirepin) {
                        validatePinThenStart();
                    } else if (cfg.proctoring && !cfg.skip_precheck) {
                        runPreExamWizard();
                    } else {
                        launchAssessment();
                    }
                });
            }
        }

        startAvailabilityCountdown();
    }

    /**
     * Live "opens in" / "closes in" countdown on the pre-attempt instructions
     * screen. Re-renders the screen (showInstructionsScreen) once the window
     * opens or closes so the Begin button appears/disappears automatically.
     */
    function startAvailabilityCountdown() {
        var openEl  = el('lg-open-countdown-clock');
        var closeEl = el('lg-close-countdown-clock');
        if (!openEl && !closeEl) { return; }

        var timer = setInterval(function() {
            var t = nowTs();
            if (openEl) {
                var remainOpen = cfg.timeopen - t;
                if (remainOpen <= 0) {
                    clearInterval(timer);
                    cfg.isavailable = true;
                    showInstructionsScreen();
                    return;
                }
                openEl.textContent = formatCountdown(remainOpen);
                return;
            }
            if (closeEl) {
                var remainClose = cfg.timeclose - t;
                if (remainClose <= 0) {
                    clearInterval(timer);
                    cfg.isavailable = false;
                    showInstructionsScreen();
                    return;
                }
                closeEl.textContent = formatCountdown(remainClose);
            }
        }, 1000);
    }

    /* -----------------------------------------------------------------------
     * PHASE 3: PIN validation
     * -------------------------------------------------------------------- */
    function validatePinThenStart() {
        var modal = document.createElement('div');
        modal.style.cssText = 'position:fixed;inset:0;z-index:9999900;display:flex;align-items:center;' +
            'justify-content:center;background:rgba(0,0,0,0.6);font-family:system-ui,sans-serif;';
        var bp = cfg.brandprimary || '#0f6cbf';
        modal.innerHTML =
            '<div style="background:#fff;border-radius:14px;padding:36px 32px;max-width:400px;width:92%;' +
            'text-align:center;box-shadow:0 20px 60px rgba(0,0,0,0.4);">' +
            '<div style="font-size:48px;margin-bottom:12px;">🔐</div>' +
            '<h3 style="margin:0 0 8px;">Exam Access PIN</h3>' +
            '<p style="color:#6c757d;margin:0 0 20px;font-size:14px;">Enter the PIN provided by your invigilator to begin.</p>' +
            '<input id="lg-pin-input" type="password" maxlength="20" autocomplete="off" ' +
            'style="width:100%;text-align:center;font-size:22px;letter-spacing:6px;padding:12px;' +
            'border:2px solid #dee2e6;border-radius:8px;box-sizing:border-box;margin-bottom:16px;" ' +
            'placeholder="••••">' +
            '<div id="lg-pin-err" style="color:#dc3545;font-size:13px;min-height:18px;margin-bottom:12px;"></div>' +
            '<div style="display:flex;gap:10px;">' +
            '<button id="lg-pin-cancel" style="flex:1;background:#6c757d;color:#fff;border:none;' +
            'border-radius:8px;padding:12px;font-size:15px;cursor:pointer;">Cancel</button>' +
            '<button id="lg-pin-submit" style="flex:1;background:' + bp + ';color:#fff;border:none;' +
            'border-radius:8px;padding:12px;font-size:15px;font-weight:700;cursor:pointer;">Verify →</button>' +
            '</div></div>';
        document.body.appendChild(modal);

        var pinInput = modal.querySelector('#lg-pin-input');
        var pinErr   = modal.querySelector('#lg-pin-err');
        if (pinInput) { setTimeout(function() { pinInput.focus(); }, 50); }

        modal.querySelector('#lg-pin-cancel').addEventListener('click', function() {
            document.body.removeChild(modal);
        });
        modal.querySelector('#lg-pin-submit').addEventListener('click', function() {
            var pin = pinInput ? pinInput.value.trim() : '';
            if (!pin) { pinErr.textContent = 'Please enter the PIN.'; return; }
            pinErr.textContent = 'Verifying…';
            callWS('mod_learnguard_validate_pin', {cmid: cfg.cmid, pin: pin})
                .then(function(data) {
                    if (data.valid) {
                        document.body.removeChild(modal);
                        if (cfg.proctoring && !cfg.skip_precheck) { runPreExamWizard(); } else { launchAssessment(); }
                    } else {
                        pinErr.textContent = '❌ Incorrect PIN. Please try again.';
                        if (pinInput) { pinInput.value = ''; pinInput.focus(); }
                    }
                })
                .catch(function() { pinErr.textContent = 'Error verifying PIN. Try again.'; });
        });
        if (pinInput) {
            pinInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') { modal.querySelector('#lg-pin-submit').click(); }
            });
        }
    }

    /* -----------------------------------------------------------------------
     * PHASE 3: Chat widget (floating)
     * -------------------------------------------------------------------- */
    var chatLastTime = 0;
    var chatUnread   = 0;

    function initChatWidget() {
        if (!cfg.enablechat || !state.attemptid) { return; }
        if (document.getElementById('lg-chat-box')) { return; }

        var bp = cfg.brandprimary || '#0f6cbf';
        var box = document.createElement('div');
        box.id  = 'lg-chat-box';
        box.style.cssText = 'position:fixed;bottom:90px;left:16px;z-index:9990;' +
            'width:280px;border-radius:12px;overflow:hidden;' +
            'box-shadow:0 4px 24px rgba(0,0,0,0.3);background:#fff;display:none;' +
            'flex-direction:column;font-family:system-ui,sans-serif;';

        box.innerHTML =
            '<div id="lg-chat-handle" style="background:' + bp + ';color:#fff;padding:8px 12px;' +
            'display:flex;justify-content:space-between;align-items:center;cursor:grab;">' +
            '<span style="font-size:13px;font-weight:700;">💬 Proctor Chat</span>' +
            '<button onclick="window.lgToggleChat()" style="background:none;border:none;color:#fff;' +
            'cursor:pointer;font-size:16px;line-height:1;">×</button>' +
            '</div>' +
            '<div id="lg-chat-messages" style="height:200px;overflow-y:auto;padding:10px;' +
            'background:#f8f9fa;display:flex;flex-direction:column;gap:8px;font-size:13px;"></div>' +
            '<div style="padding:8px;border-top:1px solid #dee2e6;display:flex;gap:6px;">' +
            '<input id="lg-chat-input" type="text" placeholder="Type a message…" maxlength="200" ' +
            'style="flex:1;padding:6px 10px;border:1px solid #dee2e6;border-radius:6px;font-size:13px;">' +
            '<button id="lg-chat-send" style="background:' + bp + ';color:#fff;border:none;' +
            'border-radius:6px;padding:6px 12px;cursor:pointer;font-size:13px;">Send</button>' +
            '</div>';

        document.body.appendChild(box);
        makeDraggable(document.getElementById('lg-chat-handle'), box);

        document.getElementById('lg-chat-send').addEventListener('click', sendChatMessage);
        document.getElementById('lg-chat-input').addEventListener('keydown', function(e) {
            if (e.key === 'Enter') { sendChatMessage(); }
        });
    }

    function sendChatMessage() {
        var input = el('lg-chat-input');
        var msg = input ? input.value.trim() : '';
        if (!msg || !state.attemptid) { return; }
        input.value = '';
        appendChatMessage('You', msg, true);
        callWS('mod_learnguard_send_chat', {
            attemptid: state.attemptid, message: msg, sendertype: 'student'
        }).catch(function() {});
    }

    function appendChatMessage(sender, text, ismine) {
        var msgArea = el('lg-chat-messages');
        if (!msgArea) { return; }
        var div = document.createElement('div');
        div.style.cssText = 'max-width:85%;padding:6px 10px;border-radius:8px;font-size:13px;line-height:1.4;' +
            (ismine
                ? 'background:#0f6cbf;color:#fff;align-self:flex-end;'
                : 'background:#e9ecef;color:#212529;align-self:flex-start;');
        div.innerHTML = '<div style="font-size:10px;opacity:.7;margin-bottom:2px;">' + sender + '</div>' +
            '<div>' + htmlEscape(text) + '</div>';
        msgArea.appendChild(div);
        msgArea.scrollTop = msgArea.scrollHeight;
    }

    function htmlEscape(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    var chatBtnVisible = false;
    window.lgToggleChat = function() {
        var box = el('lg-chat-box');
        if (!box) { return; }
        chatBtnVisible = !chatBtnVisible;
        box.style.display = chatBtnVisible ? 'flex' : 'none';
        if (chatBtnVisible) { chatUnread = 0; updateChatBadge(); }
    };

    function updateChatBadge() {
        var btn = el('lg-chat-toggle-btn');
        if (!btn) { return; }
        btn.textContent = chatUnread > 0 ? '💬 Chat (' + chatUnread + ')' : '💬 Chat';
        btn.style.background = chatUnread > 0 ? '#dc3545' : '';
    }

    function pollChatMessages() {
        if (!state.attemptid || !cfg.enablechat) { return; }
        callWS('mod_learnguard_get_chat', {attemptid: state.attemptid, since: chatLastTime})
            .then(function(data) {
                if (!data.messages) { return; }
                data.messages.forEach(function(m) {
                    if (!m.ismine) {
                        appendChatMessage(m.sendername, m.message, false);
                        if (!chatBtnVisible) {
                            chatUnread++;
                            updateChatBadge();
                            showToast('💬 New message from proctor: ' + m.message.substring(0,60), 'info', 5000);
                        }
                    }
                    chatLastTime = Math.max(chatLastTime, m.timecreated);
                });
            })
            .catch(function() {});
    }

    /* -----------------------------------------------------------------------
     * PHASE 3: Break management
     * -------------------------------------------------------------------- */
    var breakOverlay = null;
    var breakCountdownTimer = null;

    function showBreakOverlay(deadline, durationMins) {
        if (breakOverlay) { return; }
        state.onBreak = true;

        breakOverlay = document.createElement('div');
        breakOverlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.88);' +
            'display:flex;flex-direction:column;align-items:center;justify-content:center;' +
            'z-index:999992;color:#fff;font-family:system-ui,sans-serif;text-align:center;padding:40px;';

        var durLabel = durationMins ? (' (' + durationMins + ' min)') : '';
        breakOverlay.innerHTML =
            '<div style="font-size:64px;margin-bottom:16px;">☕</div>' +
            '<h2 style="color:#fd7e14;margin-bottom:10px;">Break Approved' + durLabel + '</h2>' +
            '<p style="color:#ccc;max-width:440px;margin-bottom:16px;">Your invigilator has approved your break.<br>' +
            'Time remaining on your break:</p>' +
            '<div id="lg-break-countdown" style="font-size:48px;font-weight:700;color:#fd7e14;' +
            'font-variant-numeric:tabular-nums;letter-spacing:2px;margin-bottom:20px;">--:--</div>' +
            '<p style="color:#aaa;font-size:13px;margin-bottom:20px;">Your assessment timer is paused. ' +
            'The break ends automatically when the countdown reaches zero.</p>' +
            '<button id="lg-end-break-btn" style="margin-top:8px;background:#28a745;color:#fff;' +
            'border:none;border-radius:10px;padding:14px 36px;font-size:18px;cursor:pointer;font-weight:700;">' +
            '▶ Resume Assessment Now</button>';
        document.body.appendChild(breakOverlay);

        // Live countdown to break_deadline.
        function tickBreak() {
            var now  = Math.floor(Date.now() / 1000);
            var left = deadline ? Math.max(0, deadline - now) : 0;
            var el   = document.getElementById('lg-break-countdown');
            if (el) {
                var m = Math.floor(left / 60);
                var s = left % 60;
                el.textContent = (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
            }
            if (left <= 0) {
                clearInterval(breakCountdownTimer);
                breakCountdownTimer = null;
                // Auto-resume when break expires.
                callWS('mod_learnguard_end_break', {attemptid: state.attemptid})
                    .then(function() { hideBreakOverlay(); })
                    .catch(function() { hideBreakOverlay(); });
            }
        }
        if (deadline) {
            tickBreak();
            breakCountdownTimer = setInterval(tickBreak, 1000);
        } else {
            var cdEl = document.getElementById('lg-break-countdown');
            if (cdEl) { cdEl.textContent = '—'; }
        }

        document.getElementById('lg-end-break-btn').addEventListener('click', function() {
            callWS('mod_learnguard_end_break', {attemptid: state.attemptid})
                .then(function() { hideBreakOverlay(); })
                .catch(function() { hideBreakOverlay(); });
        });
    }

    function hideBreakOverlay() {
        if (breakCountdownTimer) { clearInterval(breakCountdownTimer); breakCountdownTimer = null; }
        if (breakOverlay) { document.body.removeChild(breakOverlay); breakOverlay = null; }
        state.onBreak = false;
        showToast('✅ Break ended — resuming your assessment.', 'success', 4000);
    }

    function requestBreak() {
        if (!state.attemptid) { return; }

        // Build duration selector from admin-configured min/max.
        var minMins = parseInt(cfg.break_min_minutes, 10) || 5;
        var maxMins = parseInt(cfg.break_max_minutes, 10) || 10;

        // Show a modal to let the student choose break duration.
        var modal = document.createElement('div');
        modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.7);display:flex;' +
            'align-items:center;justify-content:center;z-index:999993;font-family:system-ui,sans-serif;';
        var options = '';
        for (var m = minMins; m <= maxMins; m++) {
            options += '<option value="' + m + '">' + m + ' minutes</option>';
        }
        modal.innerHTML =
            '<div style="background:#23272b;border-radius:14px;padding:36px 40px;max-width:400px;' +
            'text-align:center;color:#fff;box-shadow:0 8px 40px rgba(0,0,0,0.7);">' +
            '<div style="font-size:48px;margin-bottom:12px;">☕</div>' +
            '<h3 style="color:#fd7e14;margin-bottom:10px;">Request a Break</h3>' +
            '<p style="color:#ccc;margin-bottom:16px;">Select how long you need. Your proctor must approve before the break starts.</p>' +
            '<select id="lg-break-dur-sel" style="width:100%;padding:10px;border-radius:8px;font-size:16px;' +
            'background:#343a40;color:#fff;border:1px solid #555;margin-bottom:20px;">' + options + '</select>' +
            '<div style="display:flex;gap:12px;justify-content:center;">' +
            '<button id="lg-break-cancel" style="background:#6c757d;color:#fff;border:none;border-radius:8px;' +
            'padding:10px 28px;font-size:15px;cursor:pointer;">Cancel</button>' +
            '<button id="lg-break-submit" style="background:#fd7e14;color:#fff;border:none;border-radius:8px;' +
            'padding:10px 28px;font-size:15px;cursor:pointer;font-weight:700;">☕ Request Break</button>' +
            '</div></div>';
        document.body.appendChild(modal);

        document.getElementById('lg-break-cancel').addEventListener('click', function() {
            document.body.removeChild(modal);
        });
        document.getElementById('lg-break-submit').addEventListener('click', function() {
            var dur = parseInt(document.getElementById('lg-break-dur-sel').value, 10) || minMins;
            document.body.removeChild(modal);
            callWS('mod_learnguard_request_break', {attemptid: state.attemptid, duration: dur})
                .then(function(data) {
                    if (data.status === 'requested') {
                        showToast('☕ Break requested (' + dur + ' min) — waiting for proctor approval.', 'info', 6000);
                    } else if (data.status === 'already_approved') {
                        showBreakOverlay(0, dur);
                    }
                })
                .catch(function() {});
        });
    }

    // Helper: format seconds as a countdown clock — "HH:MM:SS", or "Dd HH:MM:SS"
    // once the remaining time exceeds 24 hours.
    function formatCountdown(secs) {
        if (secs < 0) { secs = 0; }
        var d = Math.floor(secs / 86400);
        var h = Math.floor((secs % 86400) / 3600);
        var m = Math.floor((secs % 3600) / 60);
        var s = secs % 60;
        function pad(n) { return n < 10 ? '0' + n : String(n); }
        var hms = pad(h) + ':' + pad(m) + ':' + pad(s);
        return d > 0 ? (d + 'd ' + hms) : hms;
    }

    // Helper: format seconds as H hr MM min or MM:SS.
    function formatSeconds(secs) {
        var h = Math.floor(secs / 3600);
        var m = Math.floor((secs % 3600) / 60);
        var s = secs % 60;
        if (h > 0) { return h + ' hr ' + (m > 0 ? m + ' min' : ''); }
        if (m > 0) { return m + ' min' + (s > 0 ? ' ' + s + ' sec' : ''); }
        return s + ' sec';
    }

    function infoCard(icon, value, label, colour) {
        return '<div style="background:#f8f9fa;border:1px solid #dee2e6;border-radius:10px;' +
            'padding:16px 20px;min-width:130px;flex:1;text-align:center;">' +
            '<div style="font-size:28px;margin-bottom:4px;">' + icon + '</div>' +
            '<div style="font-size:20px;font-weight:800;color:' + colour + ';">' + value + '</div>' +
            '<div style="font-size:12px;color:#6c757d;">' + label + '</div>' +
            '</div>';
    }

    function int(v) { return parseInt(v, 10) || 0; }

    /* -----------------------------------------------------------------------
     * Custom confirm modal — replaces window.confirm() which exits fullscreen
     * -------------------------------------------------------------------- */
    function showConfirmModal(message, onConfirm) {
        var modal = document.createElement('div');
        modal.style.cssText = 'position:fixed;inset:0;z-index:9999900;display:flex;align-items:center;' +
            'justify-content:center;background:rgba(0,0,0,0.7);font-family:system-ui,sans-serif;';
        var bprim = cfg.brandprimary || '#0f6cbf';
        modal.innerHTML =
            '<div style="background:#fff;border-radius:12px;padding:36px 32px;max-width:460px;width:90%;' +
            'text-align:center;box-shadow:0 20px 60px rgba(0,0,0,0.5);">' +
            '<div style="font-size:48px;margin-bottom:12px;">📤</div>' +
            '<h3 style="margin-bottom:10px;font-size:20px;">Submit Assessment?</h3>' +
            '<p style="color:#6c757d;margin-bottom:28px;line-height:1.5;">' + message + '</p>' +
            '<div style="display:flex;gap:12px;justify-content:center;">' +
            '<button id="lg-modal-cancel" style="background:#6c757d;color:#fff;border:none;border-radius:8px;' +
            'padding:12px 28px;font-size:16px;cursor:pointer;min-width:110px;">Cancel</button>' +
            '<button id="lg-modal-ok" style="background:' + bprim + ';color:#fff;border:none;border-radius:8px;' +
            'padding:12px 28px;font-size:16px;cursor:pointer;min-width:110px;font-weight:700;">Submit ✔</button>' +
            '</div></div>';
        document.body.appendChild(modal);
        modal.querySelector('#lg-modal-ok').addEventListener('click', function() {
            document.body.removeChild(modal);
            onConfirm();
        });
        modal.querySelector('#lg-modal-cancel').addEventListener('click', function() {
            document.body.removeChild(modal);
        });
    }

    /* -----------------------------------------------------------------------
     * Flag for review
     * -------------------------------------------------------------------- */
    function toggleFlag(slot) {
        state.flags[slot] = !state.flags[slot];
        var btn = el('lg-flag-btn');
        if (btn) {
            var flagged = state.flags[slot];
            btn.style.background = flagged ? '#fff3cd' : '#fff';
            btn.style.borderColor = flagged ? '#ffc107' : '#dee2e6';
            btn.style.color = flagged ? '#856404' : '#6c757d';
            btn.innerHTML = flagged ? '🚩 Flagged' : '🚩 Flag for Review';
        }
        renderNavigation();
    }

    /* -----------------------------------------------------------------------
     * Tool button helper — used inside showAssessmentShell() template string
     * -------------------------------------------------------------------- */
    function toolBtn(icon, fnName, title) {
        return '<button onclick="window[\'' + fnName + '\']()" title="' + title + '" ' +
            'style="background:#f8f9fa;border:1px solid #dee2e6;border-radius:6px;' +
            'padding:7px 4px;cursor:pointer;font-size:13px;font-weight:600;' +
            'display:flex;align-items:center;justify-content:center;width:100%;">' +
            icon + '</button>';
    }

    /* -----------------------------------------------------------------------
     * REDESIGNED Assessment shell — full-width question, bottom navigator,
     * fixed top-right camera + floating right toolbar
     * -------------------------------------------------------------------- */
    function showAssessmentShell() {
        // Clean up any previous toolbar (e.g. on resume)
        var oldTb = document.getElementById('lg-right-toolbar');
        if (oldTb) { oldTb.parentNode.removeChild(oldTb); }

        // Hide any LMS chat widgets (Tawk.to, Intercom, etc.) that may float over the exam UI.
        disableExternalChatWidgets();
        // Inject noise meter into right toolbar after it's built below.
        setTimeout(injectNoiseMeter, 200);

        var spa = el('lg-spa-container');
        if (!spa) { return; }

        var bp  = cfg.brandprimary   || '#0f6cbf';
        var bac = cfg.brandaccent    || '#28a745';
        var bn  = cfg.brandname      || 'LearnGuard';
        var btc = cfg.brandtextcolor || '#ffffff';

        spa.style.cssText = 'position:fixed;inset:0;z-index:100;display:flex;' +
            'flex-direction:column;font-family:"Segoe UI",system-ui,sans-serif;background:#f0f2f5;';
        document.body.style.overflow = 'hidden';

        spa.innerHTML =
            // Skip-to-content link for keyboard / screen-reader users
            '<a href="#lg-question-container" class="lg-skip-link">Skip to question</a>' +

            // ══ TOP BAR ════════════════════════════════════════════════════
            '<div role="banner" style="background:' + bp + ';color:' + btc + ';display:flex;align-items:center;' +
            'padding:0 20px;height:54px;flex-shrink:0;box-shadow:0 2px 10px rgba(0,0,0,0.25);">' +
            '<div style="min-width:0;flex:0 0 200px;overflow:hidden;">' +
            '<div style="font-weight:800;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.25;">' + (cfg.assessmentname || 'Assessment') + '</div>' +
            '<div style="font-size:10px;opacity:0.75;font-weight:600;margin-top:1px;">🛡 ' + bn + '</div>' +
            '</div>' +
            '<div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;">' +
            '<div id="lg-progress" style="font-size:14px;font-weight:700;opacity:0.95;"></div>' +
            '<div style="width:200px;height:5px;background:rgba(255,255,255,0.25);border-radius:3px;overflow:hidden;">' +
            '<div id="lg-progress-bar" style="height:100%;background:rgba(255,255,255,0.9);width:0%;' +
            'transition:width .5s;border-radius:3px;"></div></div>' +
            '</div>' +
            '<div style="display:flex;align-items:center;gap:10px;min-width:160px;justify-content:flex-end;">' +
            '<div id="lg-connection" style="font-size:15px;opacity:0.8;">📶</div>' +
            (!cfg.canManage && cfg.timeclose > 0 ?
                '<div id="lg-schedule-timer" title="Your scheduled window closes at this time" ' +
                'style="font-size:12px;font-weight:700;font-variant-numeric:tabular-nums;' +
                'background:rgba(0,0,0,0.2);padding:5px 10px;border-radius:20px;white-space:nowrap;">' +
                '⏳ Window closes in: --:--:--</div>' : '') +
            '<div id="lg-section-timer" style="display:none;font-size:12px;font-weight:700;' +
            'font-variant-numeric:tabular-nums;background:rgba(255,140,0,0.25);padding:5px 10px;' +
            'border-radius:20px;white-space:nowrap;color:#ffe8b0;"></div>' +
            '<div id="lg-timer" style="font-size:20px;font-weight:900;font-variant-numeric:tabular-nums;' +
            'background:rgba(0,0,0,0.2);padding:5px 14px;border-radius:20px;letter-spacing:2px;">--:--</div>' +
            '</div></div>' +

            // ══ COMPLETION BAR ═════════════════════════════════════════════
            '<div style="height:3px;background:#e9ecef;flex-shrink:0;">' +
            '<div id="lg-completion-bar" style="height:100%;background:' + bac + ';' +
            'width:0%;transition:width .5s;"></div></div>' +

            // ══ QUESTION AREA (full-width, vertically centred) ═════════════
            '<div id="lg-question-scroll" role="main" aria-label="Exam question" style="flex:1;min-height:0;overflow-y:auto;' +
            '-webkit-overflow-scrolling:touch;overscroll-behavior:contain;display:flex;' +
            'flex-direction:column;padding:28px 210px 28px 28px;">' +
            '<div id="lg-question-container" style="width:100%;max-width:900px;margin:auto;">' +
            '</div></div>' +

            // ══ FLAGGED ITEMS PANEL ════════════════════════════════════════
            '<div id="lg-flagged-panel" style="flex-shrink:0;max-height:0;overflow:hidden;' +
            'transition:max-height .3s ease;border-top:2px solid #ffc107;background:#fffbf0;">' +
            '<div style="padding:10px 20px;">' +
            '<div style="font-size:12px;font-weight:700;color:#856404;margin-bottom:6px;">⚠️ Proctoring flags recorded this session — your proctor can see all of these:</div>' +
            '<div id="lg-flagged-list" style="max-height:120px;overflow-y:auto;"></div>' +
            '</div></div>' +

            // ══ COLLAPSIBLE NAVIGATOR STRIP ════════════════════════════════
            '<div id="lg-nav-strip" style="flex-shrink:0;max-height:0;overflow:hidden;' +
            'transition:max-height .3s ease;border-top:1px solid #dee2e6;background:#fff;">' +
            '<div style="padding:10px 20px 12px;">' +
            '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">' +
            '<div style="font-size:11px;font-weight:700;color:#6c757d;letter-spacing:1px;' +
            'text-transform:uppercase;">Question Navigator</div>' +
            '<div style="display:flex;gap:14px;font-size:11px;color:#6c757d;">' +
            '<span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;' +
            'background:#28a745;margin-right:4px;vertical-align:middle;"></span>Answered</span>' +
            '<span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;' +
            'background:#e9ecef;border:1px solid #dee2e6;margin-right:4px;vertical-align:middle;"></span>Not yet</span>' +
            '<span>🚩 Flagged</span>' +
            '</div></div>' +
            '<div id="lg-nav-panel" style="display:flex;flex-wrap:wrap;gap:5px;' +
            'max-height:320px;overflow-y:auto;padding-right:204px;box-sizing:border-box;"></div>' +
            '</div></div>' +

            // ══ BOTTOM NAVIGATION BAR ══════════════════════════════════════
            '<div style="flex-shrink:0;height:64px;border-top:1px solid #dee2e6;background:#fff;' +
            'display:flex;align-items:center;padding:0 24px;gap:8px;' +
            'box-shadow:0 -3px 12px rgba(0,0,0,0.07);">' +
            '<button id="lg-prev-btn" style="display:flex;align-items:center;gap:6px;background:#fff;' +
            'color:#495057;border:2px solid #dee2e6;border-radius:10px;padding:10px 18px;cursor:pointer;' +
            'font-size:14px;font-weight:600;min-width:120px;justify-content:center;transition:all .15s;">' +
            '← Previous</button>' +

            '<button id="lg-flag-btn" style="display:flex;align-items:center;gap:6px;background:#fff;' +
            'color:#6c757d;border:2px solid #dee2e6;border-radius:10px;padding:10px 14px;cursor:pointer;' +
            'font-size:13px;font-weight:600;transition:all .15s;" onclick="window.lgToggleFlag()">' +
            '🚩 Flag</button>' +

            '<button id="lg-nav-toggle-btn" onclick="window.lgToggleNav()" ' +
            'style="flex:1;display:flex;align-items:center;justify-content:center;gap:6px;' +
            'background:#f8f9fa;color:#495057;border:2px solid #dee2e6;border-radius:10px;' +
            'padding:10px 14px;cursor:pointer;font-size:13px;font-weight:600;transition:all .15s;">' +
            '📋 Navigator ▲</button>' +

            // Flagged items button — shows count badge
            '<button id="lg-flagged-toggle" onclick="window.lgToggleFlagged()" ' +
            'style="position:relative;background:#fff3cd;color:#856404;border:2px solid #ffc107;' +
            'border-radius:10px;padding:10px 14px;cursor:pointer;font-size:13px;font-weight:600;" ' +
            'title="View flagged proctoring items">' +
            '⚠️ Flags' +
            '<span id="lg-flagged-count" style="display:none;position:absolute;top:-8px;right:-8px;' +
            'background:#dc3545;color:#fff;border-radius:50%;width:18px;height:18px;font-size:10px;' +
            'font-weight:700;line-height:18px;text-align:center;">0</span>' +
            '</button>' +

            '<div style="display:flex;gap:8px;">' +
            '<button id="lg-next-btn" style="background:' + bp + ';color:#fff;border:none;' +
            'border-radius:10px;padding:11px 24px;cursor:pointer;font-size:15px;font-weight:700;' +
            'box-shadow:0 3px 10px rgba(0,0,0,0.15);transition:all .15s;">Save & Next →</button>' +
            '<button id="lg-submit-btn" style="display:none;background:#28a745;color:#fff;border:none;' +
            'border-radius:10px;padding:11px 22px;cursor:pointer;font-size:15px;font-weight:700;' +
            'box-shadow:0 3px 10px rgba(0,0,0,0.15);">✔ Submit All</button>' +
            '</div></div>';

        // ══ FLOATING RIGHT TOOLBAR (camera + tools, fixed top-right) ═══════
        var toolbar = document.createElement('div');
        toolbar.id = 'lg-right-toolbar';
        toolbar.style.cssText =
            'position:fixed;top:60px;right:0;width:196px;z-index:9980;' +
            'display:flex;flex-direction:column;gap:5px;padding:8px;' +
            'max-height:calc(100vh - 130px);overflow-y:auto;scrollbar-width:none;';

        // Camera slot (populated by initWebcam)
        var camSection = document.createElement('div');
        camSection.id = 'lg-cam-section';
        toolbar.appendChild(camSection);

        // Summary card
        var summaryDiv = document.createElement('div');
        summaryDiv.style.cssText =
            'background:rgba(255,255,255,0.97);border-radius:10px;' +
            'box-shadow:0 2px 12px rgba(0,0,0,0.12);padding:10px 12px;';
        if (!el('lg-risk-blink-style')) {
            var lgRbs = document.createElement('style');
            lgRbs.id = 'lg-risk-blink-style';
            lgRbs.textContent = '@keyframes lg-risk-blink{0%,100%{opacity:1;}50%{opacity:.35;}}' +
                '.lg-risk-blink{animation:lg-risk-blink 1s infinite;}';
            document.head.appendChild(lgRbs);
        }
        summaryDiv.innerHTML =
            '<div id="lg-risk-badge" class="lg-risk-blink" style="background:#dc3545;color:#fff;padding:4px 8px;' +
            'border-radius:20px;font-size:11px;font-weight:700;margin-bottom:8px;text-align:center;">' +
            '🛡 Risk: 0 (low)</div>' +
            '<div id="lg-sum-answered" style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px;">' +
            '<span style="color:#6c757d;">Answered</span><span style="font-weight:700;color:#28a745;">0</span></div>' +
            '<div id="lg-sum-remaining" style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px;">' +
            '<span style="color:#6c757d;">Remaining</span><span style="font-weight:700;color:#495057;">0</span></div>' +
            '<div id="lg-sum-flagged" style="display:flex;justify-content:space-between;font-size:12px;">' +
            '<span style="color:#6c757d;">Flagged</span><span style="font-weight:700;color:#fd7e14;">0</span></div>';
        toolbar.appendChild(summaryDiv);

        // Tool buttons column
        var toolsDiv = document.createElement('div');
        toolsDiv.style.cssText = 'display:flex;flex-direction:column;gap:4px;';

        function tbBtn(icon, label, clickFn, bg) {
            var b = document.createElement('button');
            b.style.cssText =
                'display:flex;align-items:center;gap:8px;width:100%;' +
                'background:' + (bg || 'rgba(255,255,255,0.97)') + ';' +
                'color:' + (bg ? '#fff' : '#495057') + ';' +
                'border:1px solid rgba(0,0,0,0.1);border-radius:8px;padding:8px 10px;' +
                'cursor:pointer;font-size:12px;font-weight:700;text-align:left;' +
                'box-shadow:0 1px 4px rgba(0,0,0,0.08);transition:background .15s;';
            b.innerHTML = '<span style="font-size:16px;line-height:1;">' + icon + '</span><span>' + label + '</span>';
            b.addEventListener('click', clickFn);
            return b;
        }

        if (cfg.enablechat) {
            toolsDiv.appendChild(tbBtn('💬', 'Chat', function() { window.lgToggleChat(); }, '#0d6efd'));
        }
        if (cfg.enablebreaks) {
            toolsDiv.appendChild(tbBtn('☕', 'Break', function() { window.lgRequestBreak(); }, '#fd7e14'));
        }
        toolsDiv.appendChild(tbBtn('🔢', 'Calc',  function() { window.lgToggleCalc(); }));
        toolsDiv.appendChild(tbBtn('📝', 'Notes', function() { window.lgTogglePad(); }));
        if (cfg.enablesos) {
            toolsDiv.appendChild(tbBtn('🆘', 'SOS',   function() { window.lgDifficulty(); }, '#dc3545'));
        }

        // Zoom row
        var zoomRow = document.createElement('div');
        zoomRow.style.cssText = 'display:flex;gap:4px;align-items:center;';
        function zBtn(lbl, fn) {
            var b = document.createElement('button');
            b.type = 'button';
            b.style.cssText =
                'flex:1;background:rgba(255,255,255,0.97);border:1px solid rgba(0,0,0,0.1);' +
                'border-radius:8px;padding:7px;cursor:pointer;font-size:13px;font-weight:700;' +
                'box-shadow:0 1px 4px rgba(0,0,0,0.08);';
            b.textContent = lbl;
            b.addEventListener('click', fn);
            return b;
        }
        var zoomBadge = document.createElement('span');
        zoomBadge.id = 'lg-zoom-badge';
        zoomBadge.textContent = '100%';
        zoomBadge.style.cssText =
            'flex:0 0 36px;text-align:center;font-size:11px;font-weight:700;color:#495057;';
        zoomRow.appendChild(zBtn('A−', function() { changeTextZoom(-10); }));
        zoomRow.appendChild(zoomBadge);
        zoomRow.appendChild(zBtn('A+', function() { changeTextZoom(10); }));
        toolsDiv.appendChild(zoomRow);

        toolbar.appendChild(toolsDiv);
        document.body.appendChild(toolbar);

        spa.style.display = 'flex';

        // ── Global handlers ──────────────────────────────────────────────
        window.lgToggleCalc   = toggleCalculator;
        window.lgTogglePad    = toggleScratchPad;
        window.lgDifficulty   = showTechnicalDifficultyModal;
        window.lgZoom         = changeTextZoom;
        window.lgZoomOut      = function() { changeTextZoom(-10); };
        window.lgZoomIn       = function() { changeTextZoom(10); };
        window.lgToggleFlag   = function() { toggleFlag(state.currentslot); };
        window.lgRequestBreak = requestBreak;

        // Flagged items panel toggle
        var flaggedOpen = false;
        window.lgToggleFlagged = function() {
            flaggedOpen = !flaggedOpen;
            var fp = el('lg-flagged-panel');
            if (fp) { fp.style.maxHeight = flaggedOpen ? '200px' : '0'; }
        };

        // Navigator strip toggle
        var navOpen = false;
        window.lgToggleNav = function() {
            navOpen = !navOpen;
            var strip   = el('lg-nav-strip');
            var navBtn  = el('lg-nav-toggle-btn');
            if (strip) {
                // 400px outer window — inner panel scrolls if more questions need it.
                strip.style.maxHeight = navOpen ? '400px' : '0';
                strip.style.overflow  = navOpen ? 'hidden' : 'hidden'; // panel scrolls internally
            }
            if (navBtn) { navBtn.innerHTML = '📋 Navigator ' + (navOpen ? '▼' : '▲'); }
        };

        el('lg-prev-btn').addEventListener('click', function() {
            if (state.currentslot > 1) { submitCurrentAnswer(false, state.currentslot - 1); }
        });
        el('lg-next-btn').addEventListener('click', function() { submitCurrentAnswer(false, 0); });
        el('lg-submit-btn').addEventListener('click', function() {
            var unanswered = state.navigation.filter(function(n) { return !n.answered; }).length;
            var msg = unanswered > 0
                ? unanswered + ' question' + (unanswered > 1 ? 's are' : ' is') + ' still unanswered. Submit anyway?'
                : 'Submit your assessment? You cannot change answers after submission.';
            showConfirmModal(msg, function() { submitCurrentAnswer(true); });
        });
    }

    function showResultScreen(data) {
        // Restore body scroll for the result page.
        document.body.style.overflow = '';
        var spa = el('lg-spa-container');
        if (!spa) { return; }
        var gradeHtml = '';

        if (data.resultsheld) {
            gradeHtml = '<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:12px;' +
                'padding:24px 48px;margin-bottom:24px;max-width:480px;">' +
                '<div style="font-size:32px;margin-bottom:8px;">⏳</div>' +
                '<div style="font-size:16px;font-weight:600;">Results Pending</div>' +
                '<div style="font-size:14px;color:#6c757d;margin-top:8px;">Your results will be released by your instructor. ' +
                'You will receive a notification when they are available.</div></div>';
        } else {
            var pct      = data.maxgrade > 0 ? Math.round((data.grade / data.maxgrade) * 100) : 0;
            var pm       = parseFloat(cfg.passmark || 0);
            var passed   = pm > 0 ? pct >= pm : null;
            var colour   = pm > 0
                ? (passed ? '#28a745' : '#dc3545')
                : (pct >= 70 ? '#28a745' : (pct >= 50 ? '#fd7e14' : '#dc3545'));
            var pfBadge  = pm > 0
                ? '<div style="font-size:20px;font-weight:700;margin-top:8px;letter-spacing:1px;">' +
                  (passed ? '✅ PASS' : '❌ FAIL') + '</div>'
                : '';
            gradeHtml = '<div style="background:' + colour + ';color:#fff;border-radius:12px;' +
                'padding:24px 48px;margin-bottom:24px;">' +
                '<div style="font-size:48px;font-weight:700;">' + pct + '%</div>' +
                '<div style="font-size:16px;">' + data.grade.toFixed(1) + ' / ' + data.maxgrade + ' marks</div>' +
                pfBadge + '</div>';
        }

        spa.innerHTML = '<div style="display:flex;flex-direction:column;align-items:center;' +
            'justify-content:center;height:100vh;font-family:system-ui,sans-serif;text-align:center;padding:40px;">' +
            '<div style="font-size:64px;margin-bottom:16px;">✅</div>' +
            '<h2 style="margin-bottom:8px;">Assessment Submitted</h2>' +
            '<p style="color:#6c757d;margin-bottom:24px;">Your attempt has been recorded.</p>' +
            gradeHtml +
            '<a href="' + cfg.wwwroot + '/course/view.php?id=' + cfg.courseid + '" ' +
            'style="background:#6c757d;color:#fff;padding:12px 24px;border-radius:6px;' +
            'text-decoration:none;font-size:15px;">← Back to Course</a></div>';
    }

    var pauseOverlay = null;
    function showPausedScreen() {
        if (pauseOverlay) { return; }
        pauseOverlay = document.createElement('div');
        pauseOverlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.85);' +
            'display:flex;flex-direction:column;align-items:center;justify-content:center;' +
            'z-index:999990;color:#fff;font-family:system-ui,sans-serif;text-align:center;padding:40px;';
        pauseOverlay.innerHTML =
            '<div style="font-size:64px;margin-bottom:16px;">⏸</div>' +
            '<h2 style="color:#fd7e14;margin-bottom:10px;">Assessment Paused</h2>' +
            '<p style="color:#ccc;max-width:440px;">Your invigilator has temporarily paused this assessment.<br>' +
            'Please wait. The assessment will resume automatically.</p>' +
            '<div style="margin-top:24px;font-size:13px;color:#6c757d;">Checking every 15 seconds…</div>';
        document.body.appendChild(pauseOverlay);
    }

    function hidePausedScreen() {
        if (pauseOverlay) {
            document.body.removeChild(pauseOverlay);
            pauseOverlay = null;
            showToast('▶ Assessment resumed by invigilator.', 'success', 4000);
        }
    }

    function showSuspendedScreen() {
        // Stop ALL proctoring event generation immediately.
        // Intervals check state.proctoringActive before firing so setting it false
        // prevents face/noise/object events from logging while suspended — stops risk
        // score accumulation and prevents the reinstatement-loop bug.
        state.proctoringActive = false;
        state.suspended        = true;
        state.suspendedAt      = Math.floor(Date.now() / 1000); // record when clock stopped
        clearTimers();
        // Remove the full-viewport "Screen Sharing Stopped" overlay if present —
        // it's appended to document.body (outside #lg-spa-container) and would
        // otherwise sit on top of (and hide) the suspended screen / resume button.
        var ssOverlay = document.getElementById('lg-ss-paused');
        if (ssOverlay && ssOverlay.parentNode) { ssOverlay.parentNode.removeChild(ssOverlay); }
        state.screenSharePaused = false; // Suspension takes over — clear the screen-share pause.
        // Hide the "Fullscreen Required" blocker too — it's also appended to
        // document.body with a very high z-index and would otherwise cover
        // the suspended screen / resume button.
        hideFsBlocker();
        var spa = el('lg-spa-container');
        if (!spa) { return; }

        // Self-paused: the student chose to pause via the check-in dialog, not a proctor
        // or violation-triggered suspension. No proctor action or polling is needed — they
        // can resume themselves any time by redoing the security precheck.
        if (state.selfPaused) {
            spa.innerHTML =
                '<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;' +
                'min-height:100vh;font-family:system-ui,sans-serif;text-align:center;padding:40px;background:#f8f9fa;">' +
                '<div style="background:#fff;border-radius:16px;padding:40px 48px;max-width:520px;' +
                'box-shadow:0 4px 32px rgba(253,126,20,0.15);border-top:6px solid #fd7e14;">' +
                '<div style="font-size:72px;margin-bottom:20px;">⏸</div>' +
                '<h2 style="color:#fd7e14;margin:0 0 12px;font-size:24px;">Assessment Paused</h2>' +
                '<p style="color:#6c757d;margin:0 0 8px;font-size:15px;line-height:1.6;">' +
                'You paused your own assessment. Your question and remaining time are saved exactly as they were.</p>' +
                '<p style="color:#6c757d;margin:0 0 28px;font-size:14px;">' +
                'Resume whenever you are ready — you will need to complete a quick security check again first.</p>' +
                '<button id="lg-selfresume-btn" style="background:#28a745;color:#fff;border:none;border-radius:10px;' +
                'padding:14px 32px;font-size:15px;font-weight:700;cursor:pointer;' +
                'box-shadow:0 4px 16px rgba(40,167,69,0.4);">▶ Redo Security Check &amp; Resume</button>' +
                '</div></div>';
            setTimeout(function() {
                var btn = document.getElementById('lg-selfresume-btn');
                if (btn) {
                    btn.addEventListener('click', function() {
                        state.reinstatementMode = true;
                        if (cfg.proctoring && !cfg.skip_precheck) {
                            runPreExamWizard();
                        } else {
                            beginResumedAssessment();
                        }
                    });
                }
            }, 50);
            return;
        }

        // A confirmed suspension (proctor clicked "Confirm Suspension" on the Suspended
        // tab) is a final decision, not a pending review — the "checking for
        // reinstatement…" framing falsely implies this might resolve on its own.
        var susTitle  = state.confirmedSuspension ? 'Suspension Confirmed' : 'Attempt Suspended';
        var susBody1  = state.confirmedSuspension
            ? 'Your suspension has been confirmed due to a violation of exam rules.'
            : 'Your exam has been suspended by the proctor due to integrity violations.';
        var susStatus = state.confirmedSuspension
            ? '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#dc3545;' +
              'margin-right:8px;"></span>This is a final decision. Contact your proctor if you believe this was made in error.'
            : '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#ffc107;' +
              'margin-right:8px;animation:lg-pulse 1.5s infinite;"></span>' +
              'Checking for reinstatement… This page checks automatically every 10 seconds.';
        var susStatusBg = state.confirmedSuspension
            ? 'background:#f8d7da;border:1px solid #dc3545;color:#721c24;'
            : 'background:#fff3cd;border:1px solid #ffc107;color:#856404;';

        spa.innerHTML =
            '<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;' +
            'min-height:100vh;font-family:system-ui,sans-serif;text-align:center;padding:40px;background:#f8f9fa;">' +
            '<div style="background:#fff;border-radius:16px;padding:40px 48px;max-width:520px;' +
            'box-shadow:0 4px 32px rgba(220,53,69,0.15);border-top:6px solid #dc3545;">' +
            '<div style="font-size:72px;margin-bottom:20px;">⛔</div>' +
            '<h2 style="color:#dc3545;margin:0 0 12px;font-size:24px;">' + susTitle + '</h2>' +
            '<p style="color:#6c757d;margin:0 0 8px;font-size:15px;line-height:1.6;">' + susBody1 + '</p>' +
            '<p style="color:#6c757d;margin:0 0 28px;font-size:14px;">Please contact your proctor to discuss next steps.</p>' +
            '<div id="lg-sus-status" style="' + susStatusBg + 'border-radius:10px;' +
            'padding:14px 18px;margin-bottom:20px;font-size:13px;">' +
            susStatus +
            '</div>' +
            '<div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">' +
            '<button id="lg-sus-check" ' +
            'style="background:#0f6cbf;color:#fff;border:none;border-radius:8px;padding:11px 22px;' +
            'font-size:14px;font-weight:700;cursor:pointer;">🔄 Check Now</button>' +
            '<a href="' + cfg.wwwroot + '/course/view.php?id=' + (cfg.courseid || '') + '" ' +
            'style="background:#fff;color:#6c757d;border:2px solid #dee2e6;border-radius:8px;' +
            'padding:11px 22px;font-size:14px;font-weight:700;text-decoration:none;">← Back to Course</a>' +
            '</div></div></div>';

        // Shared check function — used by both the auto-poll and the "Check Now" button.
        function doSuspendedCheck() {
            if (!state.attemptid) { return; }
            callWS('mod_learnguard_get_attempt_state', {attemptid: state.attemptid})
                .then(function(data) {
                    if (data.state === 'inprogress' && !data.suspended) {
                        clearInterval(susCheckTimer);
                        var delta = (cfg.reinstate_threshold_delta > 0) ? cfg.reinstate_threshold_delta : 20;
                        var newThreshold = data.risk_score + delta;
                        state.reinstateSuspendThreshold = newThreshold;
                        state.riskScore = data.risk_score;
                        state.riskLevel = data.risk_level;
                        // CRITICAL: set lgReinstateSuspendedAt here so that when
                        // beginResumedAssessment() is called after the wizard completes,
                        // the timesuspended>0 guard is skipped (avoids the loop).
                        // Without this, the guard fires again because lgReinstateSuspendedAt
                        // was only set in the timesuspended branch, not in the
                        // data.suspended=true branch that runs on page reload while suspended.
                        if (data.timesuspended > 0 && lgReinstateSuspendedAt === 0) {
                            lgReinstateSuspendedAt = data.timesuspended;
                        }
                        showReinstatementMessage(data.risk_score, newThreshold);
                    } else if (!!data.is_flagged !== state.confirmedSuspension) {
                        // Still suspended, but the proctor just confirmed it (or un-confirmed
                        // it) while this screen was already open — update the status box in
                        // place instead of waiting for a full page reload.
                        state.confirmedSuspension = !!data.is_flagged;
                        var statusEl = el('lg-sus-status');
                        if (statusEl) {
                            if (state.confirmedSuspension) {
                                statusEl.style.cssText = 'background:#f8d7da;border:1px solid #dc3545;' +
                                    'color:#721c24;border-radius:10px;padding:14px 18px;margin-bottom:20px;font-size:13px;';
                                statusEl.innerHTML = '<span style="display:inline-block;width:10px;height:10px;' +
                                    'border-radius:50%;background:#dc3545;margin-right:8px;"></span>' +
                                    'This is a final decision. Contact your proctor if you believe this was made in error.';
                            } else {
                                statusEl.style.cssText = 'background:#fff3cd;border:1px solid #ffc107;' +
                                    'color:#856404;border-radius:10px;padding:14px 18px;margin-bottom:20px;font-size:13px;';
                                statusEl.innerHTML = '<span style="display:inline-block;width:10px;height:10px;' +
                                    'border-radius:50%;background:#ffc107;margin-right:8px;animation:lg-pulse 1.5s infinite;"></span>' +
                                    'Checking for reinstatement… This page checks automatically every 10 seconds.';
                            }
                        }
                    }
                }).catch(function() {});
        }

        // Wire "Check Now" button to an immediate check (no page reload).
        setTimeout(function() {
            var chk = document.getElementById('lg-sus-check');
            if (chk) { chk.addEventListener('click', doSuspendedCheck); }
        }, 50);

        // Auto-poll every 10 seconds.
        var susCheckTimer = setInterval(doSuspendedCheck, 10000);
    }

    /* =======================================================================
     * CONTINUE & REVIEW — a proctor reopened an already-finished attempt via
     * Assessment Manager, granting extra time to revise answers and resubmit.
     * Not a suspension — no proctor polling needed, the button acts immediately.
     * ====================================================================== */
    function showReviewReopenedScreen() {
        state.proctoringActive = false;
        clearTimers();
        var ssOverlay = document.getElementById('lg-ss-paused');
        if (ssOverlay && ssOverlay.parentNode) { ssOverlay.parentNode.removeChild(ssOverlay); }
        hideFsBlocker();
        var spa = el('lg-spa-container');
        if (!spa) { return; }

        spa.innerHTML =
            '<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;' +
            'min-height:100vh;font-family:system-ui,sans-serif;text-align:center;padding:40px;background:#f8f9fa;">' +
            '<div style="background:#fff;border-radius:16px;padding:40px 48px;max-width:520px;' +
            'box-shadow:0 4px 32px rgba(15,108,191,0.15);border-top:6px solid #0f6cbf;">' +
            '<div style="font-size:72px;margin-bottom:20px;">📝</div>' +
            '<h2 style="color:#0f6cbf;margin:0 0 12px;font-size:24px;">Attempt Reopened for Review</h2>' +
            '<p style="color:#6c757d;margin:0 0 8px;font-size:15px;line-height:1.6;">' +
            'Your proctor has reopened this assessment and granted you extra time to review your ' +
            'answers and resubmit.</p>' +
            '<p style="color:#6c757d;margin:0 0 20px;font-size:14px;">' +
            'You will need to complete a quick security check first. Your timer starts once you begin.</p>' +
            '<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:8px;' +
            'padding:10px 14px;margin:0 0 12px;font-size:13px;color:#856404;">' +
            '⚠️ Your new score will <strong>replace your previously recorded score</strong> once you ' +
            'resubmit — the new result overrides the previous one, even if it is lower.</div>' +
            '<div style="background:#f8d7da;border:1px solid #f5c2c7;border-radius:8px;' +
            'padding:10px 14px;margin:0 0 24px;font-size:13px;color:#842029;">' +
            '🔒 You can only review and resubmit <strong>once</strong> — make sure you are fully ' +
            'satisfied with your answers before submitting again. No further reviews will be granted.</div>' +
            '<button id="lg-review-start-btn" style="background:#0f6cbf;color:#fff;border:none;border-radius:10px;' +
            'padding:14px 32px;font-size:15px;font-weight:700;cursor:pointer;' +
            'box-shadow:0 4px 16px rgba(15,108,191,0.4);">▶ Redo Security Check &amp; Continue</button>' +
            '</div></div>';
        setTimeout(function() {
            var btn = document.getElementById('lg-review-start-btn');
            if (btn) {
                btn.addEventListener('click', function() {
                    state.reinstatementMode = true;
                    state.reviewMode = true;
                    if (cfg.proctoring && !cfg.skip_precheck) {
                        runPreExamWizard();
                    } else {
                        beginResumedAssessment();
                    }
                });
            }
        }, 50);
    }

    /* =======================================================================
     * REINSTATEMENT MESSAGE
     * Updates the #lg-sus-status box inside the existing "Attempt Suspended"
     * card to show the "Suspension Lifted" message and precheck button.
     * Matches the visual layout shown in the pre-existing working build.
     * ====================================================================== */
    function showReinstatementMessage(score, newThreshold) {
        var statusEl = el('lg-sus-status');
        if (!statusEl) { return; }
        // Hide "Check Now" — reinstatement already confirmed, no need to check again.
        var chkBtn = document.getElementById('lg-sus-check');
        if (chkBtn) { chkBtn.style.display = 'none'; }
        var delta = newThreshold - score;
        statusEl.style.background   = '#d4edda';
        statusEl.style.borderColor  = '#28a745';
        statusEl.style.color        = '#155724';
        var needsPrecheck = cfg.proctoring && !cfg.skip_precheck;
        statusEl.innerHTML =
            '✅ <strong>Your suspension has been lifted!</strong><br>' +
            (needsPrecheck
                ? '<p style="margin:8px 0 10px;font-size:13px;">You must complete the security precheck again before resuming your exam.</p>'
                : '<p style="margin:8px 0 10px;font-size:13px;">You can resume your exam now.</p>') +
            '<div style="background:#f8d7da;border:1px solid #dc3545;border-radius:8px;' +
            'padding:10px 14px;margin:0 0 12px;font-size:13px;color:#721c24;font-weight:700;">' +
            '⚠️ Warning: if your risk score rises to <strong>' + newThreshold + ' points</strong> ' +
            '(your current score of ' + score + ' + ' + delta + '), ' +
            'you will be <strong>finally suspended</strong> with no further reinstatement.</div>' +
            '<button id="lg-resume-btn" style="background:#28a745;color:#fff;border:none;border-radius:10px;' +
            'padding:14px 32px;font-size:15px;font-weight:700;cursor:pointer;' +
            'box-shadow:0 4px 16px rgba(40,167,69,0.4);">' +
            (needsPrecheck ? '▶ Redo Security Precheck &amp; Resume Exam' : '▶ Resume Exam') +
            '</button>';
        setTimeout(function() {
            var btn = document.getElementById('lg-resume-btn');
            if (btn) {
                btn.addEventListener('click', function() {
                    state.reinstatementMode = true;
                    if (needsPrecheck) {
                        runPreExamWizard();
                    } else {
                        state.suspended = false;
                        beginResumedAssessment();
                    }
                });
            }
        }, 100);
    }

    /* =======================================================================
     * CLEANUP
     * ====================================================================== */
    function clearTimers() {
        if (state.snapshotTimer)  { clearInterval(state.snapshotTimer); }
        if (state.pollTimer)      { clearInterval(state.pollTimer); }
        if (state.autosaveTimer)  { clearInterval(state.autosaveTimer); }
        if (state.countdownTimer) { clearInterval(state.countdownTimer); }
        if (state.scheduleTimer)  { clearInterval(state.scheduleTimer); }
    }

    /* =======================================================================
     * PUBLIC INIT — entry point called by view.php
     * ====================================================================== */
    return {
        init: function(config) {
            cfg = config || {};

            // Reconcile client/server clocks once up-front for the schedule countdowns.
            state.serverOffset = (parseInt(cfg.servertime, 10) || Math.floor(Date.now() / 1000)) -
                Math.floor(Date.now() / 1000);

            hideLoadingScreen();

            // CRITICAL: Anyone with manage capability is a teacher/admin.
            // Show teacher view, DO NOT activate any proctoring. Full stop.
            if (cfg.canManage) {
                var teacherView = el('lg-teacher-view');
                if (teacherView) { teacherView.style.display = 'block'; }
                return;
            }

            // Student flow — disable external chat/help widgets immediately on page load.
            disableExternalChatWidgets();

            var spa = el('lg-spa-container');
            if (spa) { spa.style.display = 'block'; }

            if (cfg.attemptid && cfg.attemptid > 0) {
                // Resuming an existing attempt — skip wizard, re-enter proctoring.
                beginResumedAssessment();
            } else {
                // New attempt — show instructions. Wizard runs on "Begin" click.
                showInstructionsScreen();
            }
        },
    };
});
