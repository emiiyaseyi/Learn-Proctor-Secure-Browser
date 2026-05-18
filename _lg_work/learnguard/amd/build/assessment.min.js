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
        lastMessageTime:  0,
        proctoringActive: false,
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
                logProctoringEvent('multi_monitor', {screens: window.screen.width}, 'medium', 15);
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
    function initConnectionMonitor() {
        var badge = el('lg-connection');
        if (!badge) { return; }

        function update() {
            if (!navigator.onLine) {
                badge.textContent = '❌ Offline';
                badge.style.color = '#dc3545';
                showToast('⚠️ Internet connection lost! Your progress is auto-saved.', 'danger', 6000);
                logProctoringEvent('connection_lost', {}, 'medium', 0);
                return;
            }
            var conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
            if (conn) {
                var type = conn.effectiveType || conn.type || '?';
                var icons = {'4g': '📶', '3g': '📶', '2g': '📡', 'slow-2g': '⚠️', '?': '📶'};
                badge.textContent = (icons[type] || '📶') + ' ' + type.toUpperCase();
                badge.style.color = (type === 'slow-2g' || type === '2g') ? '#fd7e14' : 'rgba(255,255,255,0.9)';
            } else {
                badge.textContent = '📶';
                badge.style.color = 'rgba(255,255,255,0.9)';
            }
        }

        update();
        window.addEventListener('online',  function() { update(); });
        window.addEventListener('offline', function() { update(); });
        if (navigator.connection) {
            navigator.connection.addEventListener('change', update);
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
        var c = document.getElementById('lg-calc');
        if (c) { calcVisible = !calcVisible; c.style.display = calcVisible ? 'block' : 'none'; return; }

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
            '<span onclick="document.getElementById(\'lg-calc\').style.display=\'none\';window.lgCalcVis=false;" ' +
            'style="cursor:pointer;font-size:16px;line-height:1;">×</span></div>' +
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
        var p = document.getElementById('lg-scratchpad');
        if (p) { padVisible = !padVisible; p.style.display = padVisible ? 'block' : 'none'; return; }
        padVisible = true;
        var pad = document.createElement('div');
        pad.id  = 'lg-scratchpad';
        pad.style.cssText = 'position:fixed;left:20px;bottom:80px;z-index:99990;' +
            'background:#fff;border:1px solid #dee2e6;border-radius:12px;' +
            'box-shadow:0 8px 32px rgba(0,0,0,0.2);width:280px;font-family:system-ui,sans-serif;';
        pad.innerHTML =
            '<div id="lg-pad-handle" style="background:#fd7e14;color:#fff;padding:8px 12px;' +
            'border-radius:12px 12px 0 0;display:flex;justify-content:space-between;cursor:grab;">' +
            '<span style="font-weight:700;font-size:13px;">📝 Scratch Pad</span>' +
            '<span onclick="document.getElementById(\'lg-scratchpad\').style.display=\'none\';window.lgPadVis=false;" ' +
            'style="cursor:pointer;font-size:16px;">×</span></div>' +
            '<textarea id="lg-pad-txt" style="width:100%;height:200px;border:none;padding:12px;' +
            'font-size:14px;line-height:1.5;resize:none;border-radius:0 0 12px 12px;' +
            'font-family:system-ui,sans-serif;" placeholder="Write notes here…" ' +
            'spellcheck="false">' +
            (sessionStorage.getItem('lg_scratchpad') || '') +
            '</textarea>';
        document.body.appendChild(pad);
        makeDraggable(document.getElementById('lg-pad-handle'), pad);
        document.getElementById('lg-pad-txt').addEventListener('input', function() {
            sessionStorage.setItem('lg_scratchpad', this.value);
        });
    }

    /* =======================================================================
     * TEXT ZOOM — increase/decrease question font size
     * ====================================================================== */
    var textZoomLevel = 100;
    function changeTextZoom(delta) {
        textZoomLevel = Math.max(80, Math.min(160, textZoomLevel + delta));
        var qscroll = el('lg-question-scroll');
        if (qscroll) { qscroll.style.fontSize = textZoomLevel + '%'; }
        var badge = el('lg-zoom-badge');
        if (badge) { badge.textContent = textZoomLevel + '%'; }
    }

    /* =======================================================================
     * KEYBOARD NAVIGATION during assessment
     * Arrow Left/Right = navigate questions
     * Alt+C = calculator, Alt+N = notepad
     * ====================================================================== */
    function initKeyboardNav() {
        document.addEventListener('keydown', function(e) {
            if (!state.proctoringActive && !state.attemptid) { return; }
            var tag = document.activeElement ? document.activeElement.tagName.toLowerCase() : '';
            // Don't intercept when typing in input/textarea.
            var inInput = ['input', 'textarea', 'select'].indexOf(tag) >= 0;

            if (e.altKey && e.key.toLowerCase() === 'c') { e.preventDefault(); toggleCalculator(); return; }
            if (e.altKey && e.key.toLowerCase() === 'n') { e.preventDefault(); toggleScratchPad(); return; }
            if (e.altKey && (e.key === '=' || e.key === '+')) { e.preventDefault(); changeTextZoom(10); return; }
            if (e.altKey && e.key === '-') { e.preventDefault(); changeTextZoom(-10); return; }

            if (!inInput) {
                if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
                    e.preventDefault();
                    if (state.currentslot < state.totalquestions) { submitCurrentAnswer(false); }
                }
                if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (state.currentslot > 1) { submitCurrentAnswer(false); }
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
        navigator.mediaDevices.getUserMedia({audio: true, video: false})
            .then(function(stream) {
                var AC = window.AudioContext || window.webkitAudioContext;
                if (!AC) { return; }
                var ctx = new AC(); var analyser = ctx.createAnalyser();
                analyser.fftSize = 512;
                ctx.createMediaStreamSource(stream).connect(analyser);
                var lastLog = 0;
                setInterval(function() {
                    if (!state.proctoringActive) { return; }
                    var data = new Uint8Array(analyser.frequencyBinCount);
                    analyser.getByteFrequencyData(data);
                    var level = data.reduce(function(a, b) { return a + b; }, 0) / data.length;
                    var now = Date.now();
                    if (level > 15 && now - lastLog > 30000) {
                        lastLog = now;
                        logProctoringEvent('audio_detected', {level: Math.round(level)}, 'low', 0);
                    }
                }, 1500);
            })
            .catch(function() { /* Mic unavailable — skip silently */ });
    }

    /* =======================================================================
     * EYE TRACKING & LIVENESS MONITORING (requires face-api)
     * ====================================================================== */
    function initFaceMonitoring() {
        if (typeof faceapi === 'undefined' || !cfg.modelurl) { return; }
        var video = el('lg-webcam-video');
        if (!video) { return; }

        // Liveness (blink detection).
        var lastBlink = Date.now();
        var prevEar   = null;
        var warned    = false;

        // Eye tracking (gaze).
        var lastGazeLog = 0;

        setInterval(function() {
            if (!state.proctoringActive || !video || video.readyState < 2) { return; }
            faceapi.detectAllFaces(video).withFaceLandmarks(true).then(function(dets) {
                if (!dets.length) {
                    logProctoringEvent('face_missing', {}, 'high', 20);
                    return;
                }
                var lm  = dets[0].landmarks;
                var ear = function(pts) {
                    var h = Math.abs(pts[1].y - pts[5].y) + Math.abs(pts[2].y - pts[4].y);
                    var w = Math.abs(pts[0].x - pts[3].x);
                    return w > 0 ? h / (2 * w) : 1;
                };
                var avgEar = (ear(lm.getLeftEye()) + ear(lm.getRightEye())) / 2;

                // Blink detection.
                if (prevEar !== null && prevEar > 0.2 && avgEar < 0.2) {
                    lastBlink = Date.now();
                    warned = false;
                }
                prevEar = avgEar;
                if (!warned && (Date.now() - lastBlink) > 120000) {
                    warned = true;
                    logProctoringEvent('no_blink_2min', {}, 'medium', 10);
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
                // Log IP and timezone mismatch.
                if (d.timezone) {
                    var localTz = Intl.DateTimeFormat().resolvedOptions().timeZone;
                    if (localTz && d.timezone && localTz !== d.timezone) {
                        logProctoringEvent('timezone_mismatch', {
                            server: d.timezone, browser: localTz
                        }, 'medium', 10);
                    }
                }
            })
            .catch(function() { /* API unreachable — skip silently */ });
    }

    // ---- Screenshot blocking: show black overlay on PrintScreen ----
    function initScreenshotBlocking() {
        document.addEventListener('keydown', function(e) {
            if (!state.proctoringActive) { return; }
            if (e.key === 'PrintScreen' || e.key === 'PrtScr' || e.key === 'Snapshot') {
                e.preventDefault();
                logProctoringEvent('screenshot_attempt', {key: e.key}, 'medium', 10);
                // Flash a black overlay — ruins any screenshot.
                var overlay = document.createElement('div');
                overlay.style.cssText = 'position:fixed;inset:0;background:#000;z-index:9999999;';
                document.body.appendChild(overlay);
                setTimeout(function() {
                    if (overlay.parentNode) { overlay.parentNode.removeChild(overlay); }
                }, 300);
                showToast('⚠️ Screenshot attempt blocked and logged.', 'warning');
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
                logProctoringEvent('screen_share_attempt', {}, 'high', 25);
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
                logProctoringEvent('unusual_display', {ratio: ratio.toFixed(2)}, 'medium', 10);
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
        var video = el('lg-webcam-video');
        if (!video) { return; }
        var downCount = 0;
        setInterval(function() {
            if (!state.proctoringActive || !video || video.readyState < 2) { return; }
            faceapi.detectAllFaces(video).withFaceLandmarks(true).then(function(dets) {
                if (!dets.length) { return; }
                var lm  = dets[0].landmarks;
                var nose  = lm.getNose();
                var leftEye  = lm.getLeftEye();
                var rightEye = lm.getRightEye();
                // If nose tip is below eye level significantly, head is tilted down.
                var eyeMidY = (leftEye[0].y + rightEye[0].y) / 2;
                var noseTipY = nose[6].y;
                if (noseTipY - eyeMidY > 30) {
                    downCount++;
                    if (downCount >= 3) {
                        downCount = 0;
                        logProctoringEvent('head_down', {}, 'medium', 5);
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
    function initProctoring() {
        state.proctoringActive = true;
        state.multiMonitorLogged = false;
        initFullscreen();
        initTabDetection();
        initKeyBlock();
        initClipboard();
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
    }

    /* =======================================================================
     * AUDIO CLIP RECORDING
     * Records 5-10 seconds of audio when background noise exceeds threshold.
     * Runs entirely in browser — no AI server needed.
     * ====================================================================== */
    var audioClipRecorder  = null;
    var audioClipRecording = false;
    var audioClipLastTime  = 0;
    var audioClipCooldown  = 120000;  // 2 min cooldown between clips.

    function initAudioClipRecording() {
        if (!cfg.enableaudio) { return; }
        navigator.mediaDevices.getUserMedia({audio: true, video: false})
            .then(function(stream) {
                var AC = window.AudioContext || window.webkitAudioContext;
                if (!AC) { return; }
                var ctx      = new AC();
                var analyser = ctx.createAnalyser();
                analyser.fftSize = 512;
                ctx.createMediaStreamSource(stream).connect(analyser);

                var THRESHOLD    = 30;   // RMS level — tune in admin (admin-configurable later).
                var DURATION_MS  = 8000; // 8 seconds recording.
                var highCount    = 0;
                var TRIGGER_TICKS = 6;   // ~1.8s at 300ms interval before recording starts.

                setInterval(function() {
                    if (!state.proctoringActive || audioClipRecording) { return; }
                    var data  = new Uint8Array(analyser.frequencyBinCount);
                    analyser.getByteFrequencyData(data);
                    var level = Math.round(data.reduce(function(a,b){return a+b;},0) / data.length);

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

    function startAudioClipRecording(stream, noiseLevel, durationMs) {
        if (audioClipRecording || !state.attemptid) { return; }
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
        logProctoringEvent('audio_clip_started', {noise_level: noiseLevel}, 'medium', 5);

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
    var prevFrameData  = null;  // For background change detection.

    function initObjectDetection() {
        if (typeof cocoSsd === 'undefined') {
            // TF.js / COCO-SSD not loaded — skip silently.
            return;
        }
        if (tfModelLoading) { return; }
        tfModelLoading = true;

        // Load model in background after 30s (don't slow down quiz start).
        setTimeout(function() {
            cocoSsd.load().then(function(model) {
                tfModel = model;
                showToast('🔍 AI object detection active', 'info', 3000);
                // Run detection every 30 seconds.
                setInterval(function() {
                    if (!state.proctoringActive || !tfModel) { return; }
                    var video = el('lg-webcam-video');
                    if (!video || video.readyState < 2) { return; }
                    runObjectDetection(video);
                }, 30000);
                // First scan after 10s.
                setTimeout(function() {
                    var video = el('lg-webcam-video');
                    if (video && video.readyState >= 2) { runObjectDetection(video); }
                }, 10000);
            }).catch(function() { /* Model failed to load — skip silently */ });
        }, 30000);
    }

    var OBJECT_VIOLATIONS = {
        'cell phone': {severity: 'high',   score: 25, msg: 'Mobile phone detected in exam area.'},
        'book':       {severity: 'medium', score: 10, msg: 'Book/printed material detected.'},
        'laptop':     {severity: 'high',   score: 20, msg: 'Additional laptop/screen detected.'},
        'remote':     {severity: 'low',    score: 5,  msg: 'Remote control detected.'},
        'headphones': {severity: 'medium', score: 15, msg: 'Earphones/headphones detected.'},
    };

    function runObjectDetection(video) {
        if (!tfModel) { return; }
        tfModel.detect(video).then(function(predictions) {
            var personCount = 0;
            predictions.forEach(function(pred) {
                if (pred.score < 0.5) { return; }
                var cls = pred.class.toLowerCase();

                if (cls === 'person') {
                    personCount++;
                    return;
                }
                var viol = OBJECT_VIOLATIONS[cls];
                if (viol) {
                    logProctoringEvent('object_detected_' + cls.replace(' ','_'),
                        {class: cls, confidence: Math.round(pred.score * 100)},
                        viol.severity, viol.score);
                    showToast('⚠️ ' + viol.msg, 'warning', 6000);
                    // Also take a snapshot to capture the violation.
                    captureAndUploadSnapshot('object_' + cls.replace(' ','_'));
                }
            });

            // 2nd person detection.
            if (personCount > 1) {
                logProctoringEvent('multiple_persons_detected', {count: personCount}, 'high', 25);
                showToast('🚫 Multiple people detected in frame!', 'danger', 8000);
                captureAndUploadSnapshot('multiple_persons');
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
                    logProctoringEvent('background_change', {mean_diff: Math.round(meanDiff)}, 'medium', 10);
                    showToast('⚠️ Sudden background change detected.', 'warning', 5000);
                    captureAndUploadSnapshot('background_change');
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
        faceapi.detectSingleFace(videoElement)
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

            faceapi.detectSingleFace(video)
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
                            {distance: dist.toFixed(3), threshold: 0.6}, 'high', 30);
                        showToast('🚨 Identity mismatch detected!', 'danger', 10000);
                        captureAndUploadSnapshot('identity_mismatch');
                    }
                }).catch(function() {});
        }, 300000); // Every 5 minutes.
    }

    /* =======================================================================
     * WEBCAM
     * ====================================================================== */
    function initWebcam() {
        if (!cfg.enablewebcam) { return; }

        // Create a floating draggable webcam box (like LearnShield).
        if (!document.getElementById('lg-webcam-box')) {
            var bp = cfg.brandprimary || '#0f6cbf';
            var box = document.createElement('div');
            box.id = 'lg-webcam-box';
            box.style.cssText = 'position:fixed;bottom:80px;right:16px;z-index:9990;' +
                'width:180px;border-radius:10px;overflow:hidden;' +
                'box-shadow:0 4px 20px rgba(0,0,0,0.4);background:#111;';
            box.innerHTML =
                '<div id="lg-cam-handle" style="background:' + bp + ';color:#fff;' +
                'padding:4px 10px;font-size:11px;font-weight:700;cursor:grab;' +
                'display:flex;justify-content:space-between;align-items:center;">' +
                '<span>🔒 ' + (cfg.brandname || 'LearnGuard') + '</span>' +
                '<span style="opacity:.7;font-size:10px;">drag</span>' +
                '</div>' +
                '<video id="lg-webcam-video" autoplay muted playsinline ' +
                'style="width:100%;display:block;background:#111;"></video>';
            document.body.appendChild(box);
            makeDraggable(document.getElementById('lg-cam-handle'), box);
        }

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
        var timerEl = el('lg-timer');
        if (!timerEl) { return; }
        timerEl.style.cssText += ';display:block;';

        function pad(n) { return n < 10 ? '0' + n : String(n); }

        state.countdownTimer = setInterval(function() {
            var elapsed   = Math.floor(Date.now() / 1000) - state.timestart;

            if (state.timelimit > 0) {
                // ── COUNTDOWN MODE ──────────────────────────────────
                var remaining = state.timelimit - elapsed;
                if (remaining <= 0) {
                    clearInterval(state.countdownTimer);
                    timerEl.textContent = '⏱ 00:00';
                    timerEl.style.background = 'rgba(220,53,69,0.6)';
                    timerEl.style.animation  = 'lg-spin 0.5s linear infinite';
                    autoFinishAttempt();
                    return;
                }
                var h = Math.floor(remaining / 3600);
                var m = Math.floor((remaining % 3600) / 60);
                var s = remaining % 60;
                // Show H:MM:SS if > 1 hour, MM:SS otherwise.
                timerEl.textContent = (h > 0 ? h + ':' + pad(m) : pad(m)) + ':' + pad(s);

                // Colour changes: 10 min → orange, 5 min → red, <1 min → pulsing.
                if (remaining <= 60) {
                    timerEl.style.background = 'rgba(220,53,69,0.5)';
                    timerEl.style.fontWeight = '900';
                } else if (remaining <= 300) {
                    timerEl.style.background = 'rgba(253,126,20,0.4)';
                    timerEl.style.fontWeight = '800';
                }
                // Urgency labels.
                if (remaining === 300) { showToast('⏱ 5 minutes remaining!', 'warning', 6000); }
                if (remaining === 60)  { showToast('⏱ 1 minute remaining!', 'danger',  8000); }
            } else {
                // ── ELAPSED MODE (no limit) ──────────────────────────
                var h2 = Math.floor(elapsed / 3600);
                var m2 = Math.floor((elapsed % 3600) / 60);
                var s2 = elapsed % 60;
                timerEl.textContent = '⏱ ' + (h2 > 0 ? h2 + ':' + pad(m2) : pad(m2)) + ':' + pad(s2);
            }
        }, 1000);
        // Trigger immediately so timer shows at once.
        timerEl.textContent = state.timelimit > 0
            ? pad(Math.floor(state.timelimit / 60)) + ':' + pad(state.timelimit % 60)
            : '00:00';
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
                if (data.paused && !state.paused) {
                    state.paused = true;
                    showPausedScreen();
                } else if (!data.paused && state.paused) {
                    state.paused = false;
                    hidePausedScreen();
                }
                if (data.suspended && !state.suspended) {
                    state.suspended = true; showSuspendedScreen();
                }
                state.riskScore = data.risk_score;
                state.riskLevel = data.risk_level;
                updateRiskDisplay();

                // Phase 3: break approval notification.
                if (data.break_approved && data.break_approved > 0 && !breakOverlay) {
                    showBreakOverlay();
                }

                // Phase 3: extra time granted — update timer limit.
                if (data.extra_time && data.extra_time > 0 && state.timelimit > 0) {
                    var newLimit = parseInt(data.extra_time, 10);
                    if (newLimit !== state.timelimit) {
                        state.timelimit = newLimit;
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
        var bp = cfg.brandprimary || '#0f6cbf';

        state.navigation.forEach(function(item) {
            var btn = document.createElement('button');
            var isCurrent  = item.slot === state.currentslot;
            var isFlagged  = !!state.flags[item.slot];
            var isAnswered = !!item.answered;

            btn.textContent = isFlagged ? '🚩' : item.slot;
            btn.style.cssText =
                'width:100%;aspect-ratio:1/1;border-radius:6px;cursor:pointer;font-size:12px;' +
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
                // Re-trigger MathJax rendering for LaTeX in newly loaded question.
                if (window.MathJax) {
                    try {
                        if (MathJax.typesetPromise) { MathJax.typesetPromise([container]); }
                        else if (MathJax.Hub) { MathJax.Hub.Queue(['Typeset', MathJax.Hub, container]); }
                    } catch (e) {}
                }
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
    function submitCurrentAnswer(andFinish, targetSlot) {
        callWS('mod_learnguard_submit_answer', {
            attemptid: state.attemptid, slot: state.currentslot,
            responsedata: collectCurrentFormData(),
        }).then(function(data) {
            // Use server-confirmed answered flag; fall back to checking local form data
            // if WS cache is stale and data.answered is not yet available.
            var wasAnswered = (data.answered !== undefined && data.answered !== null)
                ? !!data.answered
                : (collectCurrentFormData().length > 0);
            updateNavState(state.currentslot, wasAnswered);
            if (andFinish || data.finished) { finishAttempt(); }
            else {
                var goTo = (targetSlot > 0) ? targetSlot : data.nextslot;
                if (goTo > 0) { state.currentslot = goTo; loadQuestion(goTo); }
            }
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
        overlay.style.cssText = 'position:fixed;inset:0;z-index:9999999;' +
            'background:rgba(10,10,30,.97);display:flex;align-items:center;' +
            'justify-content:center;font-family:system-ui,sans-serif;';
        var bp = cfg.brandprimary || '#0f6cbf';
        overlay.innerHTML =
            '<div id="lg-wiz-card" style="background:#fff;border-radius:12px;' +
            'width:min(640px,96vw);max-height:88vh;overflow:hidden;display:flex;' +
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

    var WIZARD_STEPS = [
        {id: 'welcome',     icon: '🎓', title: 'Welcome to Your Proctored Exam'},
        {id: 'browser',     icon: '🌐', title: 'Browser Compatibility'},
        {id: 'internet',    icon: '📡', title: 'Internet Connection'},
        {id: 'camera',      icon: '📷', title: 'Camera / Webcam'},
        {id: 'audio',       icon: '🎤', title: 'Microphone (Audio)'},
        {id: 'photoid',     icon: '🪪', title: 'Identity Verification (Photo ID)'},
        {id: 'face',        icon: '👤', title: 'Face Detection & Liveness'},
        {id: 'keystroke',   icon: '⌨️', title: 'Typing Pattern Baseline'},
        {id: 'monitor',     icon: '🖥', title: 'Single Screen Required'},
        {id: 'environment', icon: '🔭', title: 'Environment Recording (360°)'},
        {id: 'privacy',     icon: '🔒', title: 'Privacy & Data Protection'},
        {id: 'fullscreen',  icon: '⛶', title: 'Fullscreen Mode'},
        {id: 'ready',       icon: '✅', title: 'Ready to Begin'},
    ];

    function renderWizardStep(idx) {
        var step  = WIZARD_STEPS[idx];
        var total = WIZARD_STEPS.length;
        var bp    = cfg.brandprimary || '#0f6cbf';

        el('lg-wiz-icon').textContent     = step.icon;
        el('lg-wiz-title').textContent    = step.title;
        el('lg-wiz-stepcount').textContent = 'Step ' + (idx + 1) + ' of ' + total;
        el('lg-wiz-progress').style.width  = Math.round(((idx + 1) / total) * 100) + '%';

        var body   = el('lg-wiz-body');
        var next   = el('lg-wiz-next-btn');
        var back   = el('lg-wiz-back-btn');
        var status = el('lg-wiz-status');
        body.innerHTML     = '';
        status.textContent = '';
        next.disabled      = false;
        next.textContent   = (idx === total - 1) ? '🚀 Start Exam Now' : 'Continue →';
        next.style.background = bp;
        back.style.display = (idx > 0) ? 'inline-block' : 'none';

        switch (step.id) {
            case 'welcome':     body.innerHTML = wiz_welcome(); break;
            case 'browser':     body.innerHTML = wiz_autocheck('browser'); wiz_browser(); break;
            case 'internet':    body.innerHTML = wiz_autocheck('internet'); wiz_internet(); break;
            case 'camera':      body.innerHTML = wiz_camera_html(); next.disabled = true; wiz_camera(); break;
            case 'audio':       body.innerHTML = wiz_audio_html(); next.disabled = true; wiz_audio(); break;
            case 'photoid':     body.innerHTML = wiz_photoid_html(); next.disabled = true; wiz_photoid(); break;
            case 'face':        body.innerHTML = wiz_face_html(); next.disabled = true; wiz_face(); break;
            case 'keystroke':   body.innerHTML = wiz_keystroke_html(); next.disabled = true; wiz_keystroke(); break;
            case 'monitor':     body.innerHTML = wiz_monitor_html(); break;
            case 'environment': body.innerHTML = wiz_env_html(); next.disabled = true; wiz_env(); break;
            case 'privacy':     body.innerHTML = wiz_privacy_html(); next.disabled = true; wiz_privacy_hooks(); break;
            case 'fullscreen':  body.innerHTML = wiz_fs_html(); next.disabled = true; setTimeout(wiz_fs_hooks, 80); break;
            case 'ready':       body.innerHTML = wiz_ready_html(); break;
        }

        next.onclick = function() {
            if (step.id === 'ready') { wiz_complete(); return; }
            wizardStep++; renderWizardStep(wizardStep);
        };
        back.onclick = function() { wizardStep--; renderWizardStep(wizardStep); };
    }

    // ---- Step content ----
    function wiz_welcome() {
        var inst = cfg.instructions
            ? '<div style="background:#f0f4ff;border-left:4px solid ' + (cfg.brandprimary || '#0f6cbf') +
              ';padding:12px 16px;border-radius:4px;margin:14px 0;"><strong>📋 Exam Instructions</strong><br>' +
              cfg.instructions + '</div>' : '';
        return '<h4>Before you begin, we will verify your system is ready.</h4>' + inst +
            '<p style="margin-top:12px">Steps to complete:</p>' +
            '<ul style="line-height:2.2;color:#555;padding-left:20px;">' +
            '<li>🌐 Browser compatibility</li><li>📡 Internet connection speed</li>' +
            '<li>📷 Camera / webcam</li>' +
            (cfg.enableaudio ? '<li>🎤 Microphone</li>' : '') +
            '<li>👤 Face detection &amp; liveness check</li>' +
            (cfg.enableenvrecord ? '<li>🔭 360° environment recording</li>' : '') +
            '<li>🔒 Privacy policy acceptance</li>' +
            (cfg.enablefullscreen ? '<li>⛶ Fullscreen mode</li>' : '') +
            '</ul>' +
            '<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;' +
            'padding:12px 16px;margin-top:12px;font-size:13px;">' +
            '<strong>⚠️ During the exam:</strong> fullscreen • webcam • tab detection • ' +
            'keyboard shortcut blocking • clipboard monitoring</div>';
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
                if (vid) { vid.srcObject = stream; vid.play(); }
                if (statusEl) { statusEl.innerHTML = wiz_chkResult(true, 'Camera is working ✓'); }
                el('lg-wiz-next-btn').disabled = false;
            })
            .catch(function(e) {
                if (statusEl) { statusEl.innerHTML = wiz_chkResult(false,
                    'Camera access denied: ' + e.message + '<br>Please allow camera access and refresh.'); }
                el('lg-wiz-next-btn').disabled = false;
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

        navigator.mediaDevices.getUserMedia({audio: true})
            .then(function(stream) {
                var AC = window.AudioContext || window.webkitAudioContext;
                if (!AC) { if (statusEl) { statusEl.innerHTML = wiz_chkResult(true, 'Mic available (AudioContext not supported for meter).'); } el('lg-wiz-next-btn').disabled = false; return; }
                var ctx = new AC(); var analyser = ctx.createAnalyser();
                analyser.fftSize = 512;
                ctx.createMediaStreamSource(stream).connect(analyser);
                var bgReadings = [], bgDone = false, spoken = false;
                if (statusEl) { statusEl.textContent = '🔇 Stay quiet for 3 seconds…'; }
                var iv = setInterval(function() {
                    var data = new Uint8Array(analyser.frequencyBinCount);
                    analyser.getByteFrequencyData(data);
                    var level = Math.round(data.reduce(function(a,b){return a+b;}, 0) / data.length);
                    var pct   = Math.min(level * 4, 100);
                    if (meter) { meter.style.width = pct + '%'; meter.style.background = level > 20 ? '#dc3545' : '#28a745'; }
                    if (lbl)   { lbl.textContent = level > 20 ? '🔊 Loud' : level > 5 ? '🔉' : '🔇 Quiet'; }

                    if (!bgDone) {
                        bgReadings.push(level);
                        if (bgReadings.length >= 10) {
                            bgDone = true;
                            var avg = bgReadings.reduce(function(a,b){return a+b;}) / bgReadings.length;
                            if (avg > 22) {
                                clearInterval(iv);
                                if (statusEl) { statusEl.innerHTML = wiz_chkResult(false, '⚠️ Loud background noise detected. Please move to a quieter location.'); }
                                el('lg-wiz-next-btn').disabled = false;
                                return;
                            }
                            if (promptEl) { promptEl.style.display = 'block'; promptEl.textContent = '✓ Quiet. Now speak briefly to confirm your mic works…'; }
                            if (statusEl) { statusEl.textContent = '🎤 Please speak now…'; }
                        }
                    } else if (!spoken && level > 14) {
                        spoken = true; clearInterval(iv);
                        if (statusEl) { statusEl.innerHTML = wiz_chkResult(true, '✓ Microphone working. Background is quiet.'); }
                        if (promptEl) { promptEl.style.display = 'none'; }
                        el('lg-wiz-next-btn').disabled = false;
                    }
                }, 300);
                setTimeout(function() {
                    if (!spoken) {
                        clearInterval(iv);
                        if (statusEl) { statusEl.innerHTML = wiz_chkResult(true, 'Microphone connected (no speech detected — ensure your mic works).'); }
                        el('lg-wiz-next-btn').disabled = false;
                    }
                }, 15000);
            })
            .catch(function() {
                if (statusEl) { statusEl.innerHTML = wiz_chkResult(false, 'Microphone access denied or unavailable.'); }
                el('lg-wiz-next-btn').disabled = false;
            });
    }

    function wiz_face_html() {
        return '<p>Position yourself so your face is clearly visible and centred in the camera.</p>' +
            '<div style="background:#f8f9fa;border:1px solid #dee2e6;border-radius:6px;' +
            'padding:10px 14px;font-size:13px;margin-bottom:12px;">' +
            '✓ Good lighting &nbsp;|&nbsp; ✓ Face centred &nbsp;|&nbsp; ✓ One person only &nbsp;|&nbsp; ✓ No face coverings' +
            '</div>' +
            '<div style="text-align:center;margin:10px 0;">' +
            '<video id="lg-wiz-vid2" autoplay playsinline muted ' +
            'style="width:100%;max-width:280px;border-radius:8px;border:2px solid #dee2e6;background:#111;"></video>' +
            '</div>' +
            '<div id="lg-face-status" style="text-align:center;font-size:14px;color:#6c757d;margin-top:8px;">Scanning…</div>' +
            '<div id="lg-liveness-prompt" style="display:none;margin-top:10px;text-align:center;' +
            'background:#fff3cd;padding:10px;border-radius:8px;"></div>' +
            '<div id="lg-face-confirm-wrap" style="display:none;margin-top:14px;border-top:1px solid #eee;padding-top:12px;">' +
            '<label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;">' +
            '<input type="checkbox" id="lg-face-confirm-chk" style="width:18px;height:18px;margin-top:2px;flex-shrink:0;">' +
            '<span style="font-size:13px;">I confirm my face is clearly visible, I am alone, and there are no face coverings.</span>' +
            '</label></div>';
    }

    function wiz_face() {
        var statusEl = el('lg-face-status');
        var livenessEl = el('lg-liveness-prompt');
        var confirmWrap = el('lg-face-confirm-wrap');
        var confirmChk  = el('lg-face-confirm-chk');
        var nextBtn     = el('lg-wiz-next-btn');

        // Always show the manual confirmation checkbox.
        if (confirmWrap) { confirmWrap.style.display = 'block'; }
        if (confirmChk) {
            confirmChk.addEventListener('change', function() {
                if (nextBtn) { nextBtn.disabled = !this.checked; }
            });
        }

        // Attach camera.
        var vid2 = el('lg-wiz-vid2');
        if (vid2 && cameraStream) { vid2.srcObject = cameraStream; vid2.play(); }
        else if (vid2) {
            navigator.mediaDevices.getUserMedia({video: true}).then(function(s) {
                cameraStream = s; vid2.srcObject = s; vid2.play();
            }).catch(function() {
                if (statusEl) { statusEl.innerHTML = wiz_chkResult(false, 'Camera not available. Tick the checkbox below.'); }
            });
        }

        // Try face-api if available.
        if (typeof faceapi !== 'undefined' && cfg.modelurl && !faceApiReady) {
            Promise.all([
                faceapi.nets.ssdMobilenetv1.loadFromUri(cfg.modelurl),
                faceapi.nets.faceLandmark68TinyNet.loadFromUri(cfg.modelurl),
            ]).then(function() {
                faceApiReady = true;
                wiz_faceDetect(vid2, statusEl, livenessEl, confirmChk, nextBtn);
            }).catch(function() {
                if (statusEl) { statusEl.innerHTML = wiz_chkResult(false,
                    '⚠️ Auto face detection unavailable. Please tick the checkbox below.'); }
            });
        } else if (faceApiReady && vid2) {
            wiz_faceDetect(vid2, statusEl, livenessEl, confirmChk, nextBtn);
        } else {
            if (statusEl) { statusEl.innerHTML = '<div style="background:#fff3cd;border:1px solid #ffc107;' +
                'border-radius:6px;padding:10px 14px;font-size:13px;">' +
                '⚠️ Automated face detection is unavailable.<br>' +
                'Ensure your face is <strong>clearly visible</strong>, centred, and well-lit.<br>' +
                'Tick the confirmation checkbox below to proceed.</div>'; }
        }
    }

    function wiz_faceDetect(vid2, statusEl, livenessEl, confirmChk, nextBtn) {
        var faceConfirmed = false, blinkDetected = false, prevEar = null, blocked = false;
        if (statusEl) { statusEl.textContent = '🔍 Scanning for your face…'; }
        var iv = setInterval(function() {
            if (blocked || !vid2 || vid2.readyState < 2) { return; }
            faceapi.detectAllFaces(vid2).withFaceLandmarks(true).then(function(dets) {
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
                    if (confirmChk) { confirmChk.checked = true; confirmChk.disabled = false; }
                    if (nextBtn) { nextBtn.disabled = false; }
                    // Store face descriptor for identity matching during exam.
                    if (vid2) { storeFaceDescriptor(vid2); }
                }
            }).catch(function() {});
        }, 500);
        setTimeout(function() {
            if (!blocked) {
                clearInterval(iv);
                if (!faceConfirmed && statusEl) { statusEl.innerHTML = wiz_chkResult(false, 'Face not confirmed. Tick checkbox if your face IS visible.'); }
            }
        }, 20000);
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
        return '<p>Hold your <strong>government-issued photo ID</strong> clearly in front of your camera. ' +
            'The invigilator will review this to verify your identity.</p>' +
            '<div style="background:#f8f9fa;border:1px solid #dee2e6;border-radius:6px;' +
            'padding:10px 14px;font-size:13px;margin-bottom:12px;">' +
            '✓ Hold ID next to your face &nbsp;|&nbsp; ✓ Make sure text is readable &nbsp;|&nbsp; ✓ Good lighting' +
            '</div>' +
            '<div style="text-align:center;margin:10px 0;">' +
            '<video id="lg-wiz-id-vid" autoplay playsinline muted ' +
            'style="width:100%;max-width:340px;border-radius:8px;border:2px solid #dee2e6;background:#111;"></video>' +
            '</div>' +
            '<div style="text-align:center;margin-top:10px;">' +
            '<button id="lg-capture-id-btn" style="background:#0f6cbf;color:#fff;border:none;border-radius:8px;' +
            'padding:10px 24px;font-size:15px;cursor:pointer;">📸 Capture ID Photo</button>' +
            '</div>' +
            '<canvas id="lg-id-canvas" style="display:none;"></canvas>' +
            '<div id="lg-id-status" style="text-align:center;font-size:14px;color:#6c757d;margin-top:10px;">' +
            'Position your ID and face clearly in the frame, then click Capture.</div>';
    }

    function wiz_photoid() {
        var vid = el('lg-wiz-id-vid');
        var statusEl = el('lg-id-status');
        var captureBtn = el('lg-capture-id-btn');
        var nextBtn = el('lg-wiz-next-btn');

        // Attach camera.
        if (vid && cameraStream) { vid.srcObject = cameraStream; vid.play(); }
        else if (vid) {
            navigator.mediaDevices.getUserMedia({video: true}).then(function(s) {
                cameraStream = s; vid.srcObject = s; vid.play();
            }).catch(function() {
                if (statusEl) { statusEl.innerHTML = wiz_chkResult(false, 'Camera not available.'); }
                if (nextBtn) { nextBtn.disabled = false; }
            });
        }

        if (captureBtn) {
            captureBtn.addEventListener('click', function() {
                var canvas = el('lg-id-canvas');
                if (!canvas || !vid) { return; }
                canvas.width = vid.videoWidth || 640;
                canvas.height = vid.videoHeight || 480;
                canvas.getContext('2d').drawImage(vid, 0, 0);
                var dataUrl = canvas.toDataURL('image/jpeg', 0.8);
                // Upload as a special snapshot.
                if (state.attemptid) {
                    callWS('mod_learnguard_send_snapshot', {
                        attemptid: state.attemptid,
                        imagedata: dataUrl,
                        event_type: 'photo_id',
                    }).catch(function() {});
                }
                if (statusEl) { statusEl.innerHTML = wiz_chkResult(true, '✓ ID photo captured and saved for invigilator review.'); }
                if (nextBtn) { nextBtn.disabled = false; }
                captureBtn.textContent = '↺ Retake';
            });
        } else {
            // If button not found, allow continue anyway.
            if (nextBtn) { nextBtn.disabled = false; }
        }
    }

    function wiz_keystroke_html() {
        return '<p>Type the sentence below to establish your typing pattern baseline. ' +
            'This helps verify your identity during the exam.</p>' +
            '<div style="background:#f0f4ff;border:1px solid #0f6cbf;border-radius:6px;' +
            'padding:10px 14px;font-size:15px;font-weight:600;margin-bottom:14px;text-align:center;">' +
            '"The quick brown fox jumps over the lazy dog"' +
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
                        // Log baseline once.
                        if (state.attemptid && keystrokeBaseline.length > 5) {
                            logProctoringEvent('keystroke_baseline', {
                                samples: keystrokeBaseline.length,
                                avg_interval: Math.round(keystrokeBaseline.reduce(function(a,b){return a+b.interval;},0) / keystrokeBaseline.length),
                            }, 'low', 0);
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
            '<p style="color:#555;margin-bottom:6px;">Show your desk, walls, and surroundings to confirm you are alone.</p>' +
            '<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:8px 14px;font-size:13px;margin-bottom:12px;">' +
            'ℹ️ Max 30 seconds. Retake as many times as needed.</div>' +
            '<div style="text-align:center;">' +
            '<video id="lg-env-preview" autoplay playsinline muted style="width:100%;max-width:360px;border-radius:8px;border:2px solid #dee2e6;background:#111;"></video>' +
            '<video id="lg-env-playback" controls style="display:none;width:100%;max-width:360px;border-radius:8px;margin-top:8px;"></video>' +
            '</div>' +
            '<div id="lg-env-timer" style="text-align:center;font-size:36px;font-weight:bold;color:#dc3545;margin:10px 0;display:none;">30</div>' +
            '<div style="text-align:center;margin-top:10px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">' +
            '<button id="lg-env-rec-btn" style="background:#dc3545;color:#fff;border:none;border-radius:8px;padding:12px 24px;font-size:16px;cursor:pointer;">🔴 Start Recording</button>' +
            '<button id="lg-env-stop-btn" style="display:none;background:#6c757d;color:#fff;border:none;border-radius:8px;padding:12px 24px;cursor:pointer;">⏹ Stop</button>' +
            '<button id="lg-env-retake-btn" style="display:none;background:#fd7e14;color:#fff;border:none;border-radius:8px;padding:10px 20px;cursor:pointer;">↺ Retake</button>' +
            '</div>' +
            '<div id="lg-env-status" style="text-align:center;margin-top:10px;font-size:13px;color:#6c757d;">' +
            'Press "Start Recording" then slowly pan your camera around the room.</div>';
    }

    function wiz_env() {
        var preview   = el('lg-env-preview');
        var playback  = el('lg-env-playback');
        var recBtn    = el('lg-env-rec-btn');
        var stopBtn   = el('lg-env-stop-btn');
        var retakeBtn = el('lg-env-retake-btn');
        var timerEl   = el('lg-env-timer');
        var statusEl  = el('lg-env-status');
        var nextBtn   = el('lg-wiz-next-btn');

        var recorder = null, chunks = [], timerIv = null, timeLeft = 30;

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
                recorder = new MediaRecorder(stream, {mimeType: mime, videoBitsPerSecond: 400000});
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
                if (statusEl) { statusEl.innerHTML = wiz_chkResult(true, '✓ Recording complete. Uploading to server…'); }

                // Upload recording to server so proctors can review it.
                var reader = new FileReader();
                reader.onloadend = function() {
                    var b64 = reader.result;
                    if (state.attemptid) {
                        callWS('mod_learnguard_upload_360', {
                            attemptid: state.attemptid,
                            videodata: b64,
                            duration:  30 - timeLeft,
                        }).then(function(data) {
                            if (statusEl && data.status === 'ok') {
                                statusEl.innerHTML = wiz_chkResult(true,
                                    '✓ Recording uploaded (' + Math.round(data.size / 1024) + ' KB). Review above, then click Continue.');
                            }
                        }).catch(function() {
                            // Upload failed — still allow continuing.
                            if (statusEl) {
                                statusEl.innerHTML = wiz_chkResult(true,
                                    '✓ Recording complete (upload failed — proctor may not see it). Click Continue.');
                            }
                        });
                    }
                };
                reader.readAsDataURL(blob);

                if (nextBtn) { nextBtn.disabled = false; }
            };
            recorder.start(1000);
            if (recBtn) { recBtn.style.display = 'none'; }
            if (stopBtn) { stopBtn.style.display = 'inline-block'; }
            timeLeft = 30;
            if (timerEl) { timerEl.style.display = 'block'; timerEl.textContent = '30'; }
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
            if (timerEl) { timerEl.style.display = 'none'; }
            if (nextBtn) { nextBtn.disabled = true; }
            if (statusEl) { statusEl.textContent = 'Press "Start Recording" when ready.'; }
        }); }
    }

    function wiz_privacy_html() {
        return '<p>Please read the privacy notice. You must scroll to the bottom to accept.</p>' +
            '<div id="lg-privacy-scroll" style="height:200px;overflow-y:auto;border:1px solid #dee2e6;' +
            'border-radius:6px;padding:14px;font-size:13px;line-height:1.6;background:#f8f9fa;white-space:pre-wrap;">' +
            PRIVACY_POLICY + '</div>' +
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
        var nextBtn  = el('lg-wiz-next-btn');
        if (scrollEl) {
            scrollEl.addEventListener('scroll', function() {
                if (this.scrollTop + this.clientHeight >= this.scrollHeight - 10) {
                    if (chk) { chk.disabled = false; }
                    if (hint) { hint.style.display = 'none'; }
                }
            });
        }
        if (chk) {
            chk.addEventListener('change', function() {
                if (nextBtn) { nextBtn.disabled = !this.checked; }
            });
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

    function wiz_ready_html() {
        return '<div style="text-align:center;">' +
            '<div style="font-size:64px;margin-bottom:14px;">✅</div>' +
            '<h4>You\'re All Set!</h4>' +
            '<p style="color:#555;">All checks have passed. Your exam environment is ready.</p>' +
            '<div style="background:#d4edda;border:1px solid #c3e6cb;border-radius:8px;padding:14px;text-align:left;margin:14px 0;">' +
            '<p style="margin:0;font-weight:600;color:#155724;">✓ System verified and secure</p>' +
            '<p style="margin:4px 0 0;color:#155724;font-size:13px;">Camera • Audio • Face detected • Fullscreen ready • Privacy accepted</p>' +
            '</div>' +
            '<p style="color:#dc3545;font-weight:600;margin-top:10px;">' +
            'Once you click "Start Exam Now", the timer begins and all monitoring activates.</p>' +
            '</div>';
    }

    function wiz_complete() {
        var overlay = document.getElementById('lg-wizard-overlay');
        if (overlay) { document.body.removeChild(overlay); }
        launchAssessment();
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
                initChatWidget();
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
                initChatWidget();
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

    // Inject site-wide LearnGuard heading styles once.
    function injectGlobalStyles() {
        if (document.getElementById('lg-global-styles')) { return; }
        var s = document.createElement('style');
        s.id = 'lg-global-styles';
        s.textContent =
            '#learnguard-app h1, #learnguard-app h2, #learnguard-app h3, #learnguard-app h4 { text-align:center; }' +
            '#lg-wizard-overlay h4, #lg-wizard-overlay h5 { text-align:center; }' +
            '#lg-wizard-overlay .lg-wiz-body h4 { margin-bottom:12px; }' +
            '.lg-question-card { transition:box-shadow .2s; }' +
            '.lg-question-card:hover { box-shadow:0 4px 20px rgba(0,0,0,0.12); }' +
            '.lg-mc-answers label { transition:border-color .15s, background .15s; }' +
            '.lg-mc-answers label:hover { border-color:#0f6cbf !important; background:#f0f7ff !important; }';
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
        var qInfo = randomize && numq > 0
            ? 'You will receive <strong>' + numq + ' random question' + (numq !== 1 ? 's' : '') +
              '</strong> chosen from a pool of ' + pool + '.'
            : 'This assessment has <strong>' + pool + ' question' + (pool !== 1 ? 's' : '') + '</strong>.';

        var tInfo = timelimit > 0
            ? formatSeconds(timelimit) + ' total'
            : 'No time limit';

        var infoCards =
            '<div style="display:flex;gap:16px;flex-wrap:wrap;justify-content:center;margin-bottom:24px;">' +
            infoCard('❓', pool > 0 ? actualQ + ' Question' + (actualQ !== 1 ? 's' : '') : '—', 'to attempt', bp) +
            // Only show the time card when a real time limit is configured.
            (timelimit > 0 ? infoCard('⏱', formatSeconds(timelimit), 'time allowed', bp) : '') +
            // "Randomised" card — no subtitle needed.
            (randomize ? infoCard('🔀', 'Randomised', '', bp) : '') +
            (cfg.proctoring ? infoCard('🛡', 'Proctored', 'monitoring active', '#dc3545') : '') +
            '</div>';

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
                'Please contact your instructor if you need further assistance.</p>' +
                '</div>';
        } else {
            bottomHtml =
                '<div style="text-align:center;margin-top:8px;padding-bottom:32px;">' +
                '<button id="lg-start-btn" style="background:' + bp + ';color:#fff;border:none;' +
                'border-radius:10px;padding:18px 56px;font-size:19px;font-weight:700;cursor:pointer;' +
                'box-shadow:0 4px 16px rgba(0,0,0,0.2);letter-spacing:0.3px;">' +
                '▶ Begin Assessment</button>' +
                '</div>';
        }

        // ── Availability notice (shown when window not yet open or already closed) ──
        var availNotice = '';
        var nowTs = Math.floor(Date.now() / 1000);
        if (!cfg.canManage) {
            if (cfg.timeopen > 0 && nowTs < cfg.timeopen) {
                var openDate = new Date(cfg.timeopen * 1000).toLocaleString();
                availNotice = '<div style="background:#e8f4fd;border:1px solid #bee5eb;border-radius:8px;' +
                    'padding:14px 18px;margin-bottom:20px;text-align:center;">' +
                    '<strong>🕐 This assessment opens on:</strong><br>' +
                    '<span style="font-size:16px;font-weight:700;color:#0c5460;">' + openDate + '</span></div>';
            } else if (cfg.timeclose > 0 && nowTs > cfg.timeclose) {
                var closeDate = new Date(cfg.timeclose * 1000).toLocaleString();
                availNotice = '<div style="background:#f8d7da;border:1px solid #f5c6cb;border-radius:8px;' +
                    'padding:14px 18px;margin-bottom:20px;text-align:center;">' +
                    '<strong>🔒 This assessment closed on:</strong><br>' +
                    '<span style="font-size:16px;font-weight:700;color:#721c24;">' + closeDate + '</span></div>';
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
            (instructions
                ? '<div style="background:#f8f9fa;border-radius:8px;padding:20px;margin-bottom:20px;font-size:15px;line-height:1.6;">' +
                  '<strong style="display:block;margin-bottom:8px;text-align:center;">📋 Instructions</strong>' +
                  instructions + '</div>'
                : '') +
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
                    } else if (cfg.proctoring) {
                        runPreExamWizard();
                    } else {
                        launchAssessment();
                    }
                });
            }
        }
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
                        if (cfg.proctoring) { runPreExamWizard(); } else { launchAssessment(); }
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

    function showBreakOverlay() {
        if (breakOverlay) { return; }
        breakOverlay = document.createElement('div');
        breakOverlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.88);' +
            'display:flex;flex-direction:column;align-items:center;justify-content:center;' +
            'z-index:999992;color:#fff;font-family:system-ui,sans-serif;text-align:center;padding:40px;';
        breakOverlay.innerHTML =
            '<div style="font-size:64px;margin-bottom:16px;">☕</div>' +
            '<h2 style="color:#fd7e14;margin-bottom:10px;">Break Approved</h2>' +
            '<p style="color:#ccc;max-width:440px;">Your invigilator has approved your break.<br>' +
            'When you are ready to continue, click the button below.</p>' +
            '<button id="lg-end-break-btn" style="margin-top:24px;background:#28a745;color:#fff;' +
            'border:none;border-radius:10px;padding:14px 36px;font-size:18px;cursor:pointer;font-weight:700;">' +
            '▶ Resume Assessment</button>';
        document.body.appendChild(breakOverlay);
        document.getElementById('lg-end-break-btn').addEventListener('click', function() {
            callWS('mod_learnguard_end_break', {attemptid: state.attemptid})
                .then(function() { hideBreakOverlay(); })
                .catch(function() { hideBreakOverlay(); });
        });
    }

    function hideBreakOverlay() {
        if (breakOverlay) { document.body.removeChild(breakOverlay); breakOverlay = null; }
    }

    function requestBreak() {
        if (!state.attemptid) { return; }
        callWS('mod_learnguard_request_break', {attemptid: state.attemptid})
            .then(function(data) {
                if (data.status === 'requested') {
                    showToast('☕ Break requested — waiting for proctor approval.', 'info', 6000);
                } else if (data.status === 'already_approved') {
                    showBreakOverlay();
                }
            })
            .catch(function() {});
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
     * REDESIGNED Assessment shell — premium exam UX
     *  • Two-column: question (left, ~70%) + navigator panel (right, 260px)
     *  • Top bar: brand | progress + progress bar | timer
     *  • Bottom bar: Previous | Flag for Review | Save & Next / Submit All
     *  • Navigator: 5-col grid, colour-coded + summary stats + tools
     * -------------------------------------------------------------------- */
    function showAssessmentShell() {
        var spa = el('lg-spa-container');
        if (!spa) { return; }

        var bp  = cfg.brandprimary || '#0f6cbf';
        var bac = cfg.brandaccent  || '#28a745';
        var bn  = cfg.brandname    || 'LearnGuard';

        spa.style.cssText = 'position:fixed;inset:0;z-index:100;display:flex;' +
            'flex-direction:column;font-family:"Segoe UI",system-ui,sans-serif;background:#f0f2f5;';
        document.body.style.overflow = 'hidden';

        spa.innerHTML =
            // ══ TOP BAR ════════════════════════════════════════════════════
            '<div style="background:' + bp + ';color:#fff;display:flex;align-items:center;' +
            'padding:0 20px;height:54px;flex-shrink:0;box-shadow:0 2px 10px rgba(0,0,0,0.25);">' +
            // Brand
            '<div style="font-weight:800;font-size:17px;letter-spacing:-0.3px;min-width:160px;">' +
            '🛡 ' + bn + '</div>' +
            // Centre: progress text + bar
            '<div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;">' +
            '<div id="lg-progress" style="font-size:14px;font-weight:700;opacity:0.95;"></div>' +
            '<div style="width:200px;height:5px;background:rgba(255,255,255,0.25);border-radius:3px;overflow:hidden;">' +
            '<div id="lg-progress-bar" style="height:100%;background:rgba(255,255,255,0.9);width:0%;' +
            'transition:width .5s;border-radius:3px;"></div></div>' +
            '</div>' +
            // Right: timer
            '<div style="display:flex;align-items:center;gap:10px;min-width:160px;justify-content:flex-end;">' +
            '<div id="lg-connection" style="font-size:15px;opacity:0.8;">📶</div>' +
            '<div id="lg-timer" style="font-size:20px;font-weight:900;' +
            'font-variant-numeric:tabular-nums;background:rgba(0,0,0,0.2);' +
            'padding:5px 14px;border-radius:20px;letter-spacing:2px;">--:--</div>' +
            '</div></div>' +

            // Completion progress line (answered %).
            '<div style="height:3px;background:#e9ecef;flex-shrink:0;">' +
            '<div id="lg-completion-bar" style="height:100%;background:' + bac + ';' +
            'width:0%;transition:width .5s;"></div></div>' +

            // ══ MAIN BODY ══════════════════════════════════════════════════
            '<div style="display:flex;flex:1;min-height:0;overflow:hidden;">' +

            // ── Question column ───────────────────────────────────────────
            '<div style="flex:1;min-width:0;display:flex;flex-direction:column;">' +

            // Scrollable question area — natural top-aligned flow, no artificial vertical centering.
            '<div style="flex:1;overflow-y:auto;padding:16px 20px 100px;" id="lg-question-scroll">' +
            '<div id="lg-question-container" style="max-width:820px;margin:0 auto;">' +
            '</div></div>' +

            // ── Bottom navigation bar ─────────────────────────────────────
            '<div style="flex-shrink:0;height:76px;border-top:1px solid #dee2e6;background:#fff;' +
            'display:flex;align-items:center;padding:0 36px;gap:10px;' +
            'box-shadow:0 -3px 12px rgba(0,0,0,0.07);">' +

            // Left zone: Previous button
            '<button id="lg-prev-btn" ' +
            'style="display:flex;align-items:center;gap:6px;background:#fff;color:#495057;' +
            'border:2px solid #dee2e6;border-radius:10px;padding:12px 20px;cursor:pointer;' +
            'font-size:15px;font-weight:600;min-width:130px;justify-content:center;' +
            'transition:all .15s;">' +
            '← Previous</button>' +

            // Centre zone: Flag for review
            '<button id="lg-flag-btn" ' +
            'style="flex:1;display:flex;align-items:center;gap:6px;background:#fff;color:#6c757d;' +
            'border:2px solid #dee2e6;border-radius:10px;padding:12px 16px;cursor:pointer;' +
            'font-size:14px;font-weight:600;justify-content:center;transition:all .15s;" ' +
            'onclick="window.lgToggleFlag()">' +
            '🚩 Flag for Review</button>' +

            // Right zone: Save & Next / Submit All
            '<div style="display:flex;gap:8px;min-width:160px;justify-content:flex-end;">' +
            '<button id="lg-next-btn" ' +
            'style="background:' + bp + ';color:#fff;border:none;border-radius:10px;' +
            'padding:13px 26px;cursor:pointer;font-size:15px;font-weight:700;' +
            'box-shadow:0 3px 10px rgba(0,0,0,0.15);transition:all .15s;">' +
            'Save & Next →</button>' +
            '<button id="lg-submit-btn" ' +
            'style="display:none;background:#28a745;color:#fff;border:none;border-radius:10px;' +
            'padding:13px 22px;cursor:pointer;font-size:15px;font-weight:700;' +
            'box-shadow:0 3px 10px rgba(0,0,0,0.15);">' +
            '✔ Submit All</button>' +
            '</div></div></div>' + // end question column

            // ── Right panel (260px) ───────────────────────────────────────
            '<div style="width:260px;flex-shrink:0;border-left:1px solid #dee2e6;' +
            'display:flex;flex-direction:column;background:#fff;overflow:hidden;">' +

            // Navigator header
            '<div style="padding:12px 16px 0;flex-shrink:0;">' +
            '<div style="font-size:11px;font-weight:700;color:#6c757d;letter-spacing:1px;' +
            'text-transform:uppercase;margin-bottom:8px;">Question Navigator</div>' +
            '</div>' +

            // Navigator grid — scrollable
            '<div style="flex:1;overflow-y:auto;padding:0 16px;">' +
            '<div id="lg-nav-panel" style="display:grid;grid-template-columns:repeat(5,1fr);' +
            'gap:5px;margin-bottom:16px;"></div>' +

            // Legend
            '<div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px;">' +
            '<div style="display:flex;align-items:center;gap:5px;font-size:11px;color:#6c757d;">' +
            '<div style="width:10px;height:10px;border-radius:2px;background:#28a745;"></div>Answered</div>' +
            '<div style="display:flex;align-items:center;gap:5px;font-size:11px;color:#6c757d;">' +
            '<div style="width:10px;height:10px;border-radius:2px;background:#e9ecef;border:1px solid #dee2e6;"></div>Not yet</div>' +
            '<div style="display:flex;align-items:center;gap:5px;font-size:11px;color:#6c757d;">' +
            '<span style="font-size:10px;">🚩</span>Flagged</div>' +
            '</div>' +

            // Summary stats
            '<div style="border-top:1px solid #e9ecef;padding-top:12px;margin-bottom:8px;">' +
            '<div style="font-size:11px;font-weight:700;color:#6c757d;letter-spacing:1px;' +
            'text-transform:uppercase;margin-bottom:8px;">Summary</div>' +
            '<div style="background:#f8f9fa;border-radius:8px;padding:12px;">' +
            '<div id="lg-sum-answered" style="display:flex;justify-content:space-between;' +
            'font-size:13px;margin-bottom:6px;">' +
            '<span style="color:#6c757d;">Answered</span>' +
            '<span style="font-weight:700;color:#28a745;">0</span></div>' +
            '<div id="lg-sum-remaining" style="display:flex;justify-content:space-between;' +
            'font-size:13px;margin-bottom:6px;">' +
            '<span style="color:#6c757d;">Remaining</span>' +
            '<span style="font-weight:700;color:#495057;">0</span></div>' +
            '<div id="lg-sum-flagged" style="display:flex;justify-content:space-between;' +
            'font-size:13px;">' +
            '<span style="color:#6c757d;">Flagged</span>' +
            '<span style="font-weight:700;color:#fd7e14;">0</span></div>' +
            '</div></div>' +
            '</div>' + // end scrollable nav area

            // Panel footer: risk badge + tools
            '<div style="border-top:1px solid #e9ecef;padding:10px 14px;flex-shrink:0;">' +
            '<div id="lg-risk-badge" style="background:#28a745;color:#fff;padding:5px 10px;' +
            'border-radius:20px;font-size:12px;font-weight:700;margin-bottom:8px;text-align:center;">' +
            '🛡 Risk: 0 (low)</div>' +
            // Tool grid: 5 base tools + conditional chat + break
            '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:3px;margin-bottom:3px;">' +
            toolBtn('🔢', 'lgToggleCalc', 'Calculator (Alt+C)') +
            toolBtn('📝', 'lgTogglePad',  'Notes (Alt+N)') +
            toolBtn('🆘', 'lgDifficulty', 'Report issue') +
            '</div>' +
            '<div style="display:grid;grid-template-columns:1fr 1fr;gap:3px;margin-bottom:3px;">' +
            toolBtn('A−', 'lgZoomOut', 'Smaller text') +
            toolBtn('A+', 'lgZoomIn',  'Larger text') +
            '</div>' +
            // Phase 3: chat + break (shown only when enabled)
            (cfg.enablechat
                ? '<button id="lg-chat-toggle-btn" onclick="window.lgToggleChat()" ' +
                  'style="width:100%;background:#0d6efd;color:#fff;border:none;border-radius:6px;' +
                  'padding:7px;font-size:12px;font-weight:700;cursor:pointer;margin-bottom:3px;">💬 Chat</button>'
                : '') +
            (cfg.enablebreaks
                ? '<button onclick="window.lgRequestBreak()" ' +
                  'style="width:100%;background:#fd7e14;color:#fff;border:none;border-radius:6px;' +
                  'padding:7px;font-size:12px;font-weight:700;cursor:pointer;">☕ Request Break</button>'
                : '') +
            '</div></div>' +

            '</div>' + // end right panel
            '</div>'; // end main body

        spa.style.display = 'flex';

        // Wire global handlers.
        window.lgToggleCalc  = toggleCalculator;
        window.lgTogglePad   = toggleScratchPad;
        window.lgDifficulty  = showTechnicalDifficultyModal;
        window.lgZoom        = changeTextZoom;
        window.lgZoomOut     = function() { changeTextZoom(-10); };
        window.lgZoomIn      = function() { changeTextZoom(10); };
        window.lgToggleFlag  = function() { toggleFlag(state.currentslot); };
        window.lgRequestBreak = requestBreak;

        el('lg-prev-btn').addEventListener('click', function() {
            if (state.currentslot > 1) { submitCurrentAnswer(false, state.currentslot - 1); }
        });
        el('lg-next-btn').addEventListener('click', function() { submitCurrentAnswer(false, 0); });
        el('lg-submit-btn').addEventListener('click', function() {
            var unanswered = state.navigation.filter(function(n) { return !n.answered; }).length;
            var msg = unanswered > 0
                ? unanswered + ' question' + (unanswered > 1 ? 's are' : ' is') + ' still unanswered. Submit anyway?'
                : 'Submit your assessment? You cannot change answers after submission.';
            showConfirmModal(msg, function() { submitCurrentAnswer(true); }
            );
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
