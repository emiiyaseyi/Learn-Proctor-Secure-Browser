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
        finished:         false,
        suspended:        false,
        riskScore:        0,
        riskLevel:        'low',
        webcamStream:     null,
        snapshotTimer:    null,
        pollTimer:        null,
        autosaveTimer:    null,
        countdownTimer:   null,
        lastMessageTime:  0,
        proctoringActive: false,   // true only after wizard completes
    };

    function el(id) { return document.getElementById(id); }

    function callWS(method, args) {
        return new Promise(function(resolve, reject) {
            Ajax.call([{methodname: method, args: args, done: resolve,
                fail: function(err) { reject(err); }}]);
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

    function enterFS() {
        var e = document.documentElement;
        var fn = e.requestFullscreen || e.webkitRequestFullscreen || e.mozRequestFullScreen;
        if (fn) { fn.call(e).catch(function() { showFsBlocker(); }); }
    }

    function showFsBlocker() {
        if (!cfg.enablefullscreen || state.finished || !state.proctoringActive) { return; }
        if (fsBlocker) { fsBlocker.style.display = 'flex'; return; }

        fsBlocker = document.createElement('div');
        fsBlocker.id = 'lg-fs-blocker';
        fsBlocker.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.95);' +
            'display:flex;flex-direction:column;align-items:center;justify-content:center;' +
            'z-index:999999;color:#fff;font-family:system-ui,sans-serif;text-align:center;padding:40px;';

        fsBlocker.innerHTML =
            '<div style="font-size:64px;margin-bottom:20px;">🛡️</div>' +
            '<h2 style="margin-bottom:12px;">Fullscreen Required</h2>' +
            '<p style="color:#ccc;margin-bottom:24px;max-width:400px;">This proctored assessment ' +
            'must be taken in fullscreen mode.</p>' +
            '<button id="lg-fs-return-btn" style="background:#0f6cbf;color:#fff;border:none;' +
            'border-radius:6px;padding:14px 32px;font-size:18px;cursor:pointer;">↗ Return to Fullscreen</button>';

        document.body.appendChild(fsBlocker);
        el('lg-fs-return-btn').addEventListener('click', function() { enterFS(); });
    }

    function hideFsBlocker() {
        if (fsBlocker) { fsBlocker.style.display = 'none'; }
    }

    function initFullscreen() {
        if (!cfg.enablefullscreen) { return; }
        enterFS();
        document.addEventListener('fullscreenchange', function() {
            if (!fsIsActive() && !state.finished && state.proctoringActive) {
                showFsBlocker();
                logProctoringEvent('fullscreen_exit', {}, 'medium', 15);
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

    function initTabDetection() {
        if (!cfg.enabletabdetect) { return; }
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                logProctoringEvent('tab_switch', {}, 'medium', 10);
            } else {
                showTabBlocker();
            }
        });
        window.addEventListener('blur', function() {
            if (!state.finished && state.proctoringActive) {
                logProctoringEvent('tab_switch', {trigger: 'blur'}, 'medium', 10);
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
        if (!cfg.enablekeyblock) { return; }
        var blocked = ['F1', 'F5', 'F11', 'F12'];
        var blockedCtrl = ['a','c','v','x','u','s','p','j','i','k'];
        var blockedAlt  = ['Tab', 'F4'];

        document.addEventListener('keydown', function(e) {
            if (!state.proctoringActive) { return; }
            if (blocked.indexOf(e.key) !== -1) {
                e.preventDefault(); e.stopPropagation();
                logProctoringEvent('key_violation', {key: e.key}, 'low', 0); return;
            }
            if ((e.ctrlKey || e.metaKey) && blockedCtrl.indexOf(e.key.toLowerCase()) !== -1) {
                e.preventDefault(); e.stopPropagation();
                logProctoringEvent('key_violation', {key: 'ctrl+' + e.key}, 'low', 0); return;
            }
            if (e.altKey && blockedAlt.indexOf(e.key) !== -1) {
                e.preventDefault(); e.stopPropagation();
                logProctoringEvent('key_violation', {key: 'alt+' + e.key}, 'low', 0);
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
                if (state.proctoringActive) { logProctoringEvent('clipboard_' + evt, {}, 'low', 5); }
            });
        });
    }

    /* =======================================================================
     * INITIATE ALL PROCTORING — called once, after wizard completes
     * ====================================================================== */
    function initProctoring() {
        state.proctoringActive = true;
        initFullscreen();
        initTabDetection();
        initKeyBlock();
        initClipboard();
    }

    /* =======================================================================
     * WEBCAM
     * ====================================================================== */
    function initWebcam() {
        if (!cfg.enablewebcam) { return; }
        navigator.mediaDevices.getUserMedia({video: true, audio: false})
            .then(function(stream) {
                state.webcamStream = stream;
                var camEl = el('lg-webcam-video');
                if (camEl) { camEl.srcObject = stream; camEl.play().catch(function() {}); }
                startSnapshotTimer();
            })
            .catch(function(err) {
                logProctoringEvent('webcam_denied', {error: err.message || 'denied'}, 'high', 20);
                showToast('⚠️ Webcam access denied — this has been logged.', 'danger');
            });
    }

    function startSnapshotTimer() {
        var interval = cfg.snapinterval || 30000;
        state.snapshotTimer = setInterval(function() {
            captureAndUploadSnapshot('periodic');
        }, interval);
    }

    function captureAndUploadSnapshot(type) {
        if (!state.webcamStream || !state.attemptid) { return; }
        var video = el('lg-webcam-video');
        if (!video) { return; }
        var canvas = document.createElement('canvas');
        canvas.width = 320; canvas.height = 240;
        canvas.getContext('2d').drawImage(video, 0, 0, 320, 240);
        callWS('mod_learnguard_send_snapshot', {
            attemptid: state.attemptid,
            imagedata: canvas.toDataURL('image/jpeg', 0.7),
            event_type: type,
        }).catch(function() {});
    }

    /* =======================================================================
     * RISK SCORE DISPLAY
     * ====================================================================== */
    function updateRiskDisplay() {
        var badge = el('lg-risk-badge');
        if (!badge) { return; }
        var colours = {low: '#28a745', medium: '#fd7e14', high: '#dc3545'};
        badge.style.background = colours[state.riskLevel] || '#6c757d';
        badge.textContent = '🛡 Risk: ' + state.riskScore + ' (' + state.riskLevel + ')';
    }

    /* =======================================================================
     * TIMER
     * ====================================================================== */
    function initTimer() {
        if (!state.timelimit) { return; }
        var timerEl = el('lg-timer');
        if (!timerEl) { return; }
        timerEl.style.display = 'block';
        state.countdownTimer = setInterval(function() {
            var remaining = state.timelimit - (Math.floor(Date.now() / 1000) - state.timestart);
            if (remaining <= 0) {
                clearInterval(state.countdownTimer);
                timerEl.textContent = '⏱ 0:00';
                timerEl.style.color = '#dc3545';
                autoFinishAttempt();
                return;
            }
            timerEl.textContent = '⏱ ' + Math.floor(remaining / 60) + ':' +
                ((remaining % 60) < 10 ? '0' : '') + (remaining % 60);
            if (remaining <= 300) { timerEl.style.color = '#fd7e14'; }
            if (remaining <= 60)  { timerEl.style.color = '#dc3545'; }
        }, 1000);
    }

    function autoFinishAttempt() {
        if (state.finished) { return; }
        showToast('⏱ Time is up! Submitting your assessment…', 'warning');
        finishAttempt();
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
                if (data.suspended && !state.suspended) {
                    state.suspended = true; showSuspendedScreen();
                }
                state.riskScore = data.risk_score;
                state.riskLevel = data.risk_level;
                updateRiskDisplay();
            }).catch(function() {});
        }, 15000);
    }

    function startAutosave() {
        state.autosaveTimer = setInterval(function() {
            if (state.finished || !state.attemptid) { return; }
            var formData = collectCurrentFormData();
            if (!formData.length) { return; }
            callWS('mod_learnguard_autosave', {
                attemptid: state.attemptid,
                slot: state.currentslot,
                responsedata: formData,
            }).then(function() {
                showToast('💾 Answer saved automatically', 'info', 2000);
            }).catch(function() {});
        }, 30000);
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
        face_missing:    {severity: 'high',   score: 20},
        face_multiple:   {severity: 'high',   score: 25},
        clipboard_copy:  {severity: 'low',    score: 5},
        clipboard_paste: {severity: 'low',    score: 5},
        clipboard_cut:   {severity: 'low',    score: 5},
        key_violation:   {severity: 'low',    score: 0},
        webcam_denied:   {severity: 'high',   score: 20},
    };

    function logProctoringEvent(eventtype, data, severity, score) {
        if (!state.attemptid) { return; }
        var meta    = eventMeta[eventtype] || {};
        var sev     = severity || meta.severity || 'low';
        var impact  = (score !== undefined) ? score : (meta.score || 0);
        callWS('mod_learnguard_log_event', {
            attemptid: state.attemptid, eventtype: eventtype,
            eventdata: JSON.stringify(data || {}), severity: sev, scoreimpact: impact,
        }).then(function(resp) {
            state.riskScore = resp.risk_score;
            state.riskLevel = resp.risk_level;
            updateRiskDisplay();
            if (cfg.autosuspend_score > 0 && state.riskScore >= cfg.autosuspend_score && !state.suspended) {
                state.suspended = true; showSuspendedScreen();
            }
        }).catch(function() {});
    }

    /* =======================================================================
     * TOAST
     * ====================================================================== */
    var toastContainer = null;
    function showToast(message, type, duration) {
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.style.cssText = 'position:fixed;top:16px;right:16px;z-index:9998;' +
                'display:flex;flex-direction:column;gap:8px;max-width:360px;';
            document.body.appendChild(toastContainer);
        }
        var colours = {info: '#0d6efd', success: '#198754', warning: '#fd7e14', danger: '#dc3545'};
        var toast = document.createElement('div');
        toast.style.cssText = 'background:' + (colours[type] || colours.info) +
            ';color:#fff;padding:10px 16px;border-radius:6px;font-size:14px;' +
            'font-family:system-ui,sans-serif;box-shadow:0 4px 12px rgba(0,0,0,0.3);transition:opacity 0.3s;';
        toast.textContent = message;
        toastContainer.appendChild(toast);
        var delay = typeof duration === 'number' ? duration : 4000;
        setTimeout(function() {
            toast.style.opacity = '0';
            setTimeout(function() { if (toast.parentNode) { toast.parentNode.removeChild(toast); } }, 300);
        }, delay);
    }

    /* =======================================================================
     * NAVIGATION PANEL
     * ====================================================================== */
    function renderNavigation() {
        var nav = el('lg-nav-panel');
        if (!nav) { return; }
        nav.innerHTML = '';
        var colours = {
            correct: '#28a745', partiallycorrect: '#fd7e14', incorrect: '#dc3545',
            complete: '#0d6efd', todo: '#dee2e6', invalid: '#adb5bd',
        };
        state.navigation.forEach(function(item) {
            var btn = document.createElement('button');
            btn.textContent = item.slot;
            btn.style.cssText = 'width:36px;height:36px;border-radius:4px;cursor:pointer;font-size:13px;' +
                'font-weight:600;border:2px solid ' + (item.slot === state.currentslot ? '#0f6cbf' : '#ccc') +
                ';background:' + (colours[item.state] || '#f8f9fa') +
                ';color:' + (item.answered ? '#fff' : '#333') + ';';
            btn.title = 'Question ' + item.slot;
            btn.addEventListener('click', function() { navigateToSlot(item.slot); });
            nav.appendChild(btn);
        });
    }

    function updateNavState(slot, answered) {
        state.navigation.forEach(function(n) {
            if (n.slot === slot) { n.answered = answered; n.state = answered ? 'complete' : 'todo'; }
        });
        renderNavigation();
    }

    function navigateToSlot(slot) {
        if (slot < 1 || slot > state.totalquestions || slot === state.currentslot) { return; }
        state.currentslot = slot; loadQuestion(slot);
    }

    /* =======================================================================
     * QUESTION LOADING
     * ====================================================================== */
    function loadQuestion(slot) {
        var container = el('lg-question-container');
        var progress  = el('lg-progress');
        if (!container) { return; }
        container.innerHTML = '<div style="text-align:center;padding:40px;color:#6c757d;">' +
            '<div class="lg-spinner"></div><p>Loading question…</p></div>';
        if (progress) { progress.textContent = 'Question ' + slot + ' of ' + state.totalquestions; }

        callWS('mod_learnguard_get_question', {attemptid: state.attemptid, slot: slot, review: false})
            .then(function(data) {
                container.innerHTML = data.html;
                state.currentslot = data.slot;
                container.querySelectorAll('script').forEach(function(old) {
                    var s = document.createElement('script');
                    if (old.src) { s.src = old.src; } else { s.textContent = old.textContent; }
                    old.parentNode.replaceChild(s, old);
                });
                renderNavigation();
                updateNavigationButtons(slot, data.totalquestions);
            })
            .catch(function(err) {
                container.innerHTML = '<div class="alert alert-danger">Failed to load question.<br><small>' +
                    (err.message || '') + '</small></div>';
            });
    }

    function updateNavigationButtons(slot, total) {
        var prevBtn   = el('lg-prev-btn');
        var nextBtn   = el('lg-next-btn');
        var submitBtn = el('lg-submit-btn');
        if (prevBtn)   { prevBtn.disabled = (slot <= 1); prevBtn.style.opacity = slot <= 1 ? '0.4' : '1'; }
        if (nextBtn)   { nextBtn.style.display   = (slot < total)  ? 'inline-block' : 'none'; }
        if (submitBtn) { submitBtn.style.display  = (slot >= total) ? 'inline-block' : 'none'; }
    }

    /* =======================================================================
     * ANSWER SUBMISSION
     * ====================================================================== */
    function submitCurrentAnswer(andFinish) {
        callWS('mod_learnguard_submit_answer', {
            attemptid: state.attemptid, slot: state.currentslot,
            responsedata: collectCurrentFormData(),
        }).then(function(data) {
            updateNavState(state.currentslot, true);
            if (andFinish || data.finished) { finishAttempt(); }
            else if (data.nextslot > 0) { state.currentslot = data.nextslot; loadQuestion(data.nextslot); }
        }).catch(function(err) {
            showToast('Error saving answer: ' + (err.message || 'Please try again.'), 'danger');
        });
    }

    function finishAttempt() {
        if (state.finished) { return; }
        callWS('mod_learnguard_finish_attempt', {attemptid: state.attemptid})
            .then(function(data) {
                state.finished = true; clearTimers(); showResultScreen(data);
            })
            .catch(function(err) {
                showToast('Failed to submit: ' + (err.message || 'Please try again.'), 'danger');
            });
    }

    /* =======================================================================
     * PRE-EXAM WIZARD (mirrors LearnShield pre-exam check flow)
     *
     * Steps (shown only when proctoring is enabled):
     *  0. Overview / consent
     *  1. Browser & system check
     *  2. Camera test (if webcam enabled)
     *  3. Microphone test (if audio enabled)
     *  4. Fullscreen launch
     * ====================================================================== */
    var wizardStep = 0;
    var wizardSteps = [];
    var cameraStream = null;

    function buildWizardSteps() {
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

    function showWizardStep(idx) {
        var step = wizardSteps[idx];
        var spa  = el('lg-spa-container');
        if (!spa || !step) { return; }

        var isLast  = (idx === wizardSteps.length - 1);
        var isFirst = (idx === 0);
        var colour  = step.btnColour || '#0f6cbf';

        spa.innerHTML =
            '<div style="max-width:600px;margin:0 auto;padding:40px 24px;font-family:system-ui,sans-serif;">' +
            // Progress dots.
            '<div style="display:flex;justify-content:center;gap:6px;margin-bottom:28px;">' +
            wizardSteps.map(function(s, i) {
                return '<div style="width:10px;height:10px;border-radius:50%;background:' +
                    (i < idx ? '#28a745' : i === idx ? '#0f6cbf' : '#dee2e6') + ';"></div>';
            }).join('') +
            '</div>' +
            '<div style="text-align:center;margin-bottom:20px;">' +
            '<div style="font-size:56px;margin-bottom:8px;">' + step.icon + '</div>' +
            '<h2 style="margin-bottom:4px;">' + step.title + '</h2>' +
            '<div style="font-size:12px;color:#6c757d;">Step ' + (idx + 1) + ' of ' + wizardSteps.length + '</div>' +
            '</div>' +
            '<div id="lg-wiz-body" style="margin-bottom:24px;">' + step.body() + '</div>' +
            '<div style="display:flex;justify-content:' + (isFirst ? 'flex-end' : 'space-between') + ';gap:12px;">' +
            (!isFirst
                ? '<button id="lg-wiz-back-btn" style="background:#6c757d;color:#fff;border:none;' +
                  'border-radius:6px;padding:10px 20px;cursor:pointer;">← Back</button>'
                : '') +
            '<button id="lg-wiz-next-btn" ' +
            (step.btnDisabled ? 'disabled ' : '') +
            'style="background:' + colour + ';color:#fff;border:none;border-radius:6px;' +
            'padding:12px 28px;font-size:16px;cursor:pointer;' +
            (step.btnDisabled ? 'opacity:0.5;' : '') + '">' +
            step.btnLabel + '</button>' +
            '</div></div>';

        spa.style.display = 'block';

        // Call onShow hook.
        if (step.onShow) { step.onShow(); }

        // Back button.
        var backBtn = el('lg-wiz-back-btn');
        if (backBtn) {
            backBtn.addEventListener('click', function() {
                wizardStep--;
                showWizardStep(wizardStep);
            });
        }

        // Next button.
        var nextBtn = el('lg-wiz-next-btn');
        if (nextBtn) {
            nextBtn.addEventListener('click', function() {
                if (step.onNext) { step.onNext(); }
                if (!isLast) {
                    wizardStep++;
                    showWizardStep(wizardStep);
                }
            });
        }
    }

    function runPreExamWizard() {
        wizardStep  = 0;
        wizardSteps = buildWizardSteps();
        showWizardStep(0);
    }

    /* =======================================================================
     * LAUNCH ASSESSMENT (after wizard completes or when proctoring disabled)
     * ====================================================================== */
    function launchAssessment() {
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

        callWS('mod_learnguard_start_attempt', {cmid: cfg.cmid})
            .then(function(data) {
                state.attemptid      = data.attemptid;
                state.currentslot    = data.currentslot;
                state.totalquestions = data.totalquestions;
                state.timelimit      = data.timelimit;
                state.timestart      = data.timestart;
                state.navigation     = data.navigation;

                showAssessmentShell();
                initTimer();
                initWebcam();
                startAutosave();
                startPolling();
                loadQuestion(state.currentslot);
                renderNavigation();
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
    function beginResumedAssessment() {
        state.attemptid = cfg.attemptid;

        // Init proctoring immediately for resumed attempts.
        if (cfg.proctoring) { initProctoring(); }

        showAssessmentShell();

        callWS('mod_learnguard_get_attempt_state', {attemptid: state.attemptid})
            .then(function(data) {
                state.currentslot    = data.currentslot;
                state.navigation     = data.navigation;
                state.totalquestions = data.navigation.length;
                state.riskScore      = data.risk_score;
                state.riskLevel      = data.risk_level;

                if (data.suspended) { showSuspendedScreen(); return; }

                initTimer();
                initWebcam();
                startAutosave();
                startPolling();
                loadQuestion(state.currentslot);
                renderNavigation();
                updateRiskDisplay();
                if (cfg.enablefullscreen) { enterFS(); }
            });
    }

    /* =======================================================================
     * SCREENS
     * ====================================================================== */
    function hideLoadingScreen() {
        var loading = el('lg-loading');
        if (loading) { loading.style.display = 'none'; }
    }

    function showInstructionsScreen() {
        var spa = el('lg-spa-container');
        if (!spa) { return; }
        var instructions = cfg.instructions || '';

        spa.innerHTML =
            '<div style="max-width:700px;margin:0 auto;padding:40px 24px;font-family:system-ui,sans-serif;">' +
            '<h2 style="margin-bottom:8px;">📋 ' + (cfg.assessmentname || 'Assessment Instructions') + '</h2>' +
            (instructions
                ? '<div style="background:#f8f9fa;border-radius:8px;padding:20px;margin-bottom:24px;">' +
                  instructions + '</div>'
                : '<p style="color:#6c757d;margin-bottom:24px;">No special instructions have been provided.</p>') +
            (cfg.proctoring
                ? '<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:8px;' +
                  'padding:16px;margin-bottom:24px;">' +
                  '<strong>⚠️ This assessment is proctored by Learn Guard.</strong><br>' +
                  'A system check will run before the assessment starts.</div>'
                : '') +
            '<button id="lg-start-btn" style="background:#0f6cbf;color:#fff;border:none;' +
            'border-radius:6px;padding:14px 36px;font-size:18px;cursor:pointer;">▶ Begin Assessment</button>' +
            '</div>';

        spa.style.display = 'block';

        el('lg-start-btn').addEventListener('click', function() {
            // If proctoring is on, run the pre-exam wizard first.
            // If not, launch directly.
            if (cfg.proctoring) {
                runPreExamWizard();
            } else {
                launchAssessment();
            }
        });
    }

    function showAssessmentShell() {
        var spa = el('lg-spa-container');
        if (!spa) { return; }

        spa.innerHTML =
            '<div style="display:flex;flex-direction:column;height:100vh;font-family:system-ui,sans-serif;">' +
            // Top bar.
            '<div style="display:flex;align-items:center;justify-content:space-between;' +
            'background:#0f6cbf;color:#fff;padding:10px 16px;flex-shrink:0;">' +
            '<div style="font-weight:700;font-size:16px;">🛡 LearnGuard Assessment</div>' +
            '<div style="display:flex;gap:12px;align-items:center;">' +
            '<div id="lg-progress" style="font-size:14px;"></div>' +
            '<div id="lg-timer" style="display:none;font-size:14px;font-weight:700;"></div>' +
            '<div id="lg-risk-badge" style="background:#28a745;color:#fff;padding:4px 10px;' +
            'border-radius:12px;font-size:12px;font-weight:600;">🛡 Risk: 0 (low)</div>' +
            '</div></div>' +
            // Content + sidebar.
            '<div style="display:flex;flex:1;overflow:hidden;">' +
            '<div style="flex:1;overflow-y:auto;padding:24px;">' +
            '<div id="lg-question-container"></div>' +
            '<div style="display:flex;gap:12px;margin-top:24px;justify-content:space-between;">' +
            '<button id="lg-prev-btn" style="background:#6c757d;color:#fff;border:none;' +
            'border-radius:6px;padding:10px 20px;cursor:pointer;font-size:14px;">← Previous</button>' +
            '<div style="display:flex;gap:8px;">' +
            '<button id="lg-next-btn" style="background:#0f6cbf;color:#fff;border:none;' +
            'border-radius:6px;padding:10px 24px;cursor:pointer;font-size:14px;">Save & Next →</button>' +
            '<button id="lg-submit-btn" style="display:none;background:#28a745;color:#fff;border:none;' +
            'border-radius:6px;padding:10px 24px;cursor:pointer;font-size:14px;">✔ Submit Assessment</button>' +
            '</div></div></div>' +
            // Sidebar.
            '<div style="width:200px;border-left:1px solid #dee2e6;padding:16px;overflow-y:auto;' +
            'background:#f8f9fa;flex-shrink:0;">' +
            (cfg.enablewebcam
                ? '<video id="lg-webcam-video" autoplay muted playsinline ' +
                  'style="width:100%;border-radius:6px;margin-bottom:12px;background:#000;"></video>'
                : '') +
            '<div style="font-size:12px;font-weight:600;color:#6c757d;margin-bottom:8px;">QUESTIONS</div>' +
            '<div id="lg-nav-panel" style="display:flex;flex-wrap:wrap;gap:4px;"></div>' +
            '</div></div></div>';

        spa.style.display = 'block';

        el('lg-prev-btn').addEventListener('click', function() {
            if (state.currentslot > 1) { submitCurrentAnswer(false); }
        });
        el('lg-next-btn').addEventListener('click', function() { submitCurrentAnswer(false); });
        el('lg-submit-btn').addEventListener('click', function() {
            if (confirm('Are you sure you want to submit your assessment? You cannot change your answers after submission.')) {
                submitCurrentAnswer(true);
            }
        });
    }

    function showResultScreen(data) {
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
            var pct    = data.maxgrade > 0 ? Math.round((data.grade / data.maxgrade) * 100) : 0;
            var colour = pct >= 70 ? '#28a745' : (pct >= 50 ? '#fd7e14' : '#dc3545');
            gradeHtml = '<div style="background:' + colour + ';color:#fff;border-radius:12px;' +
                'padding:24px 48px;margin-bottom:24px;">' +
                '<div style="font-size:48px;font-weight:700;">' + pct + '%</div>' +
                '<div style="font-size:16px;">' + data.grade.toFixed(1) + ' / ' + data.maxgrade + ' marks</div></div>';
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

    function showSuspendedScreen() {
        clearTimers();
        var spa = el('lg-spa-container');
        if (!spa) { return; }
        spa.innerHTML = '<div style="display:flex;flex-direction:column;align-items:center;' +
            'justify-content:center;height:100vh;font-family:system-ui,sans-serif;text-align:center;padding:40px;">' +
            '<div style="font-size:64px;margin-bottom:16px;">⛔</div>' +
            '<h2 style="color:#dc3545;margin-bottom:8px;">Attempt Suspended</h2>' +
            '<p style="color:#6c757d;margin-bottom:24px;">Your attempt has been suspended due to integrity violations.<br>' +
            'Please contact your instructor.</p>' +
            '<a href="' + cfg.wwwroot + '/course/view.php?id=' + cfg.courseid + '" ' +
            'style="background:#6c757d;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;">' +
            '← Back to Course</a></div>';
    }

    /* =======================================================================
     * CLEANUP
     * ====================================================================== */
    function clearTimers() {
        if (state.snapshotTimer)  { clearInterval(state.snapshotTimer); }
        if (state.pollTimer)      { clearInterval(state.pollTimer); }
        if (state.autosaveTimer)  { clearInterval(state.autosaveTimer); }
        if (state.countdownTimer) { clearInterval(state.countdownTimer); }
    }

    /* =======================================================================
     * PUBLIC INIT — entry point called by view.php
     * ====================================================================== */
    return {
        init: function(config) {
            cfg = config || {};

            hideLoadingScreen();

            // CRITICAL: Anyone with manage capability is a teacher/admin.
            // Show teacher view, DO NOT activate any proctoring. Full stop.
            if (cfg.canManage) {
                var teacherView = el('lg-teacher-view');
                if (teacherView) { teacherView.style.display = 'block'; }
                return;
            }

            // Student flow.
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
