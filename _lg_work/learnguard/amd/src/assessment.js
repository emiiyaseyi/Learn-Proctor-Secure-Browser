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
        proctoringActive: false,
        serverOffset:     0,
    };

    function el(id) { return document.getElementById(id); }

    // Current time in seconds, corrected for any drift between the client
    // clock and the server clock (so a wrong client clock can't be used to
    // dodge a scheduled exam window).
    function nowTs() {
        return Math.floor(Date.now() / 1000) + state.serverOffset;
    }

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
        // This listener is registered once and enforces blocking whenever proctoring is active.
        // The five user-requested keys (Windows/Meta, Alt combos, Tab, PrintScreen, Esc) are
        // ALWAYS blocked — no toggle needed. Additional keys respect cfg.enablekeyblock.
        var criticalKeys         = ['Escape', 'Meta', 'OS'];
        var secondaryBlocked     = ['F1', 'F5', 'F11', 'F12'];
        var secondaryBlockedCtrl = ['a','c','v','x','u','s','p','j','i','k'];

        function blockKey(e) {
            if (!state.proctoringActive) { return; }
            var k = e.key;

            // 1. Windows key (Meta / OS)
            if (k === 'Meta' || k === 'OS') {
                e.preventDefault(); e.stopPropagation();
                showToast('🔒 Windows key blocked during exam.', 'warning', 2000);
                logProctoringEvent('key_violation', {key: 'meta'}, 'medium', 5);
                return;
            }
            // 2. Escape
            if (k === 'Escape') {
                e.preventDefault(); e.stopPropagation();
                return;
            }
            // 3. Tab (all forms — alone or with Alt/Shift)
            if (k === 'Tab') {
                e.preventDefault(); e.stopPropagation();
                return;
            }
            // 4. Alt key combinations (Alt+Tab, Alt+F4, Alt+Esc switch windows / close browser)
            // Exception: Alt+C (calculator) and Alt+N (notepad) are allowed exam shortcuts.
            if (e.altKey) {
                if (k.toLowerCase() === 'c' || k.toLowerCase() === 'n' ||
                    k === '=' || k === '+' || k === '-') { return; }
                e.preventDefault(); e.stopPropagation();
                logProctoringEvent('key_violation', {key: 'alt+' + k}, 'medium', 5);
                return;
            }
            // 5. PrintScreen
            if (k === 'PrintScreen' || k === 'PrtScr' || k === 'Snapshot') {
                e.preventDefault(); e.stopPropagation();
                logProctoringEvent('key_violation', {key: 'printscreen'}, 'medium', 10);
                return;
            }

            // Secondary blocking — only when enablekeyblock is configured ON
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
            if (state.proctoringActive && e.key === 'Alt') { e.preventDefault(); }
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

        var wasOffline = false;
        function update() {
            if (!navigator.onLine) {
                badge.innerHTML = '❌ <span style="font-size:11px;">Offline</span>';
                badge.style.color = '#ff6b6b';
                if (!wasOffline) {
                    wasOffline = true;
                    showToast('⚠️ Internet connection lost! Your progress is auto-saved.', 'danger', 6000);
                    logProctoringEvent('connection_lost', {}, 'medium', 0);
                }
                return;
            }
            wasOffline = false;
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
            var inInput = ['input', 'textarea', 'select'].indexOf(tag) >= 0;

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
        navigator.mediaDevices.getUserMedia({audio: true, video: false})
            .then(function(stream) {
                var AC = window.AudioContext || window.webkitAudioContext;
                if (!AC) { return; }
                var ctx = new AC(); var analyser = ctx.createAnalyser();
                analyser.fftSize = 512;
                ctx.createMediaStreamSource(stream).connect(analyser);
                var lastLog = 0;
                var THRESHOLD = 8; // low = picks up any sound (strict)

                setInterval(function() {
                    if (!state.proctoringActive) { return; }
                    var data = new Uint8Array(analyser.frequencyBinCount);
                    analyser.getByteFrequencyData(data);
                    var level = Math.round(data.reduce(function(a, b) { return a + b; }, 0) / data.length);

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
            faceapi.detectAllFaces(video, new faceapi.TinyFaceDetectorOptions()).withFaceLandmarks(true).then(function(dets) {
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
            faceapi.detectAllFaces(video, new faceapi.TinyFaceDetectorOptions()).withFaceLandmarks(true).then(function(dets) {
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

        // Heartbeat — keeps session visible on live proctor dashboard.
        setInterval(function() {
            if (!state.proctoringActive || state.finished) { return; }
            logProctoringEvent('session_heartbeat', {}, 'info', 0);
        }, 60000);

        // Periodic screen capture — uses the screen stream from wizard screen-share step.
        initPeriodicScreenCapture();
    }

    function initPeriodicScreenCapture() {
        // Use screen stream from wizard (stored in state.screenStream).
        if (!state.screenStream) { return; }
        // Capture a frame every 15 seconds.
        setInterval(function() {
            if (!state.proctoringActive || !state.screenStream || state.finished) { return; }
            captureScreenSnapshot('screen_capture');
        }, 15000);
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

                var THRESHOLD    = 8;    // Strict — picks up any background sound.
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
                logProctoringEvent('object_detected_' + cls.replace(' ','_'),
                    {class: cls, confidence: Math.round(score * 100)}, viol.severity, viol.score);
                showToast('⚠️ ' + viol.msg, 'warning', 6000);
                captureAndUploadSnapshot('object_' + cls.replace(' ','_'));
            }
        }
        if (personCount > 1) {
            logProctoringEvent('multiple_persons_detected', {count: personCount}, 'high', 25);
            showToast('🚫 Multiple people detected in frame!', 'danger', 8000);
            captureAndUploadSnapshot('multiple_persons');
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
                    logProctoringEvent('object_detected_' + cls.replace(' ','_'),
                        {class: cls, confidence: Math.round(pred.score * 100)}, viol.severity, viol.score);
                    showToast('⚠️ ' + viol.msg, 'warning', 6000);
                    captureAndUploadSnapshot('object_' + cls.replace(' ','_'));
                }
            });
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
                logProctoringEvent('webcam_denied', {error: err.message || 'denied'}, 'high', 20);
                showToast('⚠️ Webcam access denied — this has been logged.', 'danger');
            });
    }

    function startSnapshotTimer() {
        var interval = cfg.snapinterval || 15000; // Default 15 seconds.
        state.snapshotTimer = setInterval(function() {
            captureAndUploadSnapshot('periodic');
        }, interval);
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
        if (!state.webcamStream || !state.attemptid) { return; }
        var video = el('lg-webcam-video');
        if (!video || video.readyState < 2) { return; }
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
            logProctoringEvent('camera_covered', {brightness: Math.round(avgBrightness)}, 'high', 20);
            showToast('⚠️ Camera appears covered or blocked!', 'danger', 6000);
        }

        uploadSnapshot(canvas, type);
    }

    /* =======================================================================
     * RISK SCORE DISPLAY
     * ====================================================================== */
    var lgHalfWarnShown = false;

    function updateRiskDisplay() {
        var badge = el('lg-risk-badge');
        if (!badge) { return; }
        var colours = {low: '#28a745', medium: '#fd7e14', high: '#dc3545'};
        badge.style.background = colours[state.riskLevel] || '#6c757d';
        badge.textContent = '🛡 Risk: ' + state.riskScore + ' (' + state.riskLevel + ')';

        // 50% threshold warning — fires once.
        var threshold = (cfg.autosuspend_score > 0) ? cfg.autosuspend_score : 80;
        var half = Math.floor(threshold * 0.5);
        if (!lgHalfWarnShown && state.riskScore >= half && state.proctoringActive && !state.finished) {
            lgHalfWarnShown = true;
            lgShowHalfWarning(state.riskScore, threshold);
        }
    }

    function lgShowHalfWarning(current, threshold) {
        var existing = document.getElementById('lg-threshold-warning-modal');
        if (existing) { existing.remove(); }
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
            'I Understand — Continue Exam</button></div>';
        document.body.appendChild(modal);
        document.getElementById('lg-threshold-dismiss').addEventListener('click', function() {
            modal.remove();
        });
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
        showToast('⏱ Time is up! Submitting your assessment…', 'warning');
        finishAttempt();
    }

    // Live-attempt countdown to the learner's scheduled "timeclose" (if any).
    // This is independent of the exam time-limit timer (#lg-timer) — it
    // represents the per-learner scheduling window set via schedule.php.
    function startScheduleCountdown() {
        var badge = el('lg-schedule-timer');
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

    var flaggedLog = [];   // local list of flagged items shown to candidate

    function logProctoringEvent(eventtype, data, severity, score) {
        if (!state.attemptid) { return; }
        var meta    = eventMeta[eventtype] || {};
        var sev     = severity || meta.severity || 'low';
        var impact  = (score !== undefined) ? score : (meta.score || 0);

        // Add to local flagged log so candidate can see what's been flagged.
        if (sev !== 'low' || impact > 0) {
            var readableLabels = {
                'tab_switch':          'Tab / window switch detected',
                'fullscreen_exit':     'Fullscreen exited',
                'key_violation':       'Blocked key pressed: ' + (data && data.key ? data.key : ''),
                'clipboard_copy':      'Copy attempt blocked',
                'clipboard_paste':     'Paste attempt blocked',
                'phone_detected':      'Mobile phone detected',
                'object_detected':     'Object detected on webcam',
                'multiple_persons_detected': 'Multiple people in frame',
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
        }

        callWS('mod_learnguard_log_event', {
            attemptid: state.attemptid, eventtype: eventtype,
            eventdata: JSON.stringify(data || {}), severity: sev, scoreimpact: impact,
        }).then(function(resp) {
            state.riskScore = resp.risk_score;
            state.riskLevel = resp.risk_level;
            updateRiskDisplay();
            if (cfg.autosuspend_score > 0 && state.riskScore >= cfg.autosuspend_score && !state.suspended) {
                state.suspended = true;
                // Write suspension to DB so the admin suspended page shows this learner.
                callWS('mod_learnguard_auto_suspend', {
                    attemptid: state.attemptid,
                    reason: 'Risk score ' + state.riskScore + ' reached auto-suspend threshold of ' + cfg.autosuspend_score,
                }).catch(function() {});
                showSuspendedScreen();
            }
        }).catch(function() {});
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
        status.textContent = '';
        next.disabled      = false;
        next.textContent   = (idx === total - 1) ? '🚀 Start Exam Now' : 'Continue →';
        next.style.background = bp;
        back.style.display = (idx > 0) ? 'inline-block' : 'none';

        switch (step.id) {
            case 'welcome':     body.innerHTML = wiz_welcome(); break;
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
        var brand = cfg.brandname || 'LearnGuard';
        var inst  = cfg.instructions
            ? '<div style="background:#f0f4ff;border-left:4px solid ' + (cfg.brandprimary || '#0f6cbf') +
              ';padding:12px 16px;border-radius:4px;margin:14px 0;">' +
              '<strong>📋 Exam Instructions</strong><br>' + cfg.instructions + '</div>' : '';
        return '<div style="text-align:center;margin-bottom:16px;">' +
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
            '</div>';
    }

    function lgWizBadge(icon, label) {
        return '<span style="background:#fff;border:1px solid #dee2e6;border-radius:20px;padding:4px 10px;' +
            'font-size:12px;font-weight:600;color:#495057;white-space:nowrap;">' + icon + ' ' + label + '</span>';
    }

    function wiz_closetabs_html() {
        return '<p style="margin-bottom:16px;font-size:15px;">Before the exam begins, you must <strong>close all other browser tabs, windows, and applications</strong> running on your computer.</p>' +
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
            '<input type="checkbox" id="lg-closetabs-chk" style="width:20px;height:20px;margin-top:2px;flex-shrink:0;accent-color:#28a745;">' +
            '<span style="font-size:14px;font-weight:600;color:#155724;">' +
            'I confirm I have closed all other tabs, applications and messaging software. ' +
            'My phone is put away and notifications are silenced. I am ready to begin the precheck.' +
            '</span></label></div>';
    }

    function wiz_closetabs() {
        var chk     = document.getElementById('lg-closetabs-chk');
        var nextBtn = el('lg-wiz-next-btn');
        if (nextBtn) { nextBtn.disabled = true; }
        if (chk) {
            chk.addEventListener('change', function() {
                if (nextBtn) { nextBtn.disabled = !this.checked; }
            });
        }
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
                if (statusEl) { statusEl.innerHTML = wiz_chkResult(false,
                    '<strong>Microphone access denied.</strong> Audio monitoring will be disabled.<br>' +
                    'This will be noted by the proctor.'); }
                // Mic not strictly mandatory (some exams don't require it) — allow continue with warning
                el('lg-wiz-next-btn').disabled = false;
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
            '<p style="font-size:12px;color:#6c757d;margin:0 0 10px;">Upload a clear, recent passport-style photo showing your face and any ID text visible.</p>' +
            '<div style="display:flex;gap:10px;align-items:flex-start;">' +
            '<div style="flex:1;">' +
            '<input type="file" id="lg-face-passport-input" accept="image/*" style="width:100%;font-size:13px;padding:4px;border:1px solid #ccc;border-radius:4px;">' +
            '</div>' +
            '<img id="lg-face-passport-preview" src="" alt="" style="display:none;width:80px;height:80px;object-fit:cover;border-radius:6px;border:2px solid #28a745;">' +
            '</div>' +
            '<div id="lg-passport-status" style="font-size:12px;color:#6c757d;margin-top:8px;">⚠️ Photo not yet uploaded — this is required to continue.</div>' +
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

            // Fallback after 3 failed attempts — requires invigilator approval
            '<div id="lg-face-confirm-wrap" style="display:none;margin-top:14px;border:2px solid #fd7e14;border-radius:8px;padding:14px;background:#fff8f0;">' +
            '<p style="font-size:13px;font-weight:700;color:#dc3545;margin:0 0 8px;">⚠️ Face match below required threshold after 3 attempts.</p>' +
            '<p style="font-size:13px;color:#495057;margin:0 0 12px;">Tick the box below to request invigilator approval. You cannot start the exam until the invigilator reviews your photo and approves.</p>' +
            '<label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;margin-bottom:10px;">' +
            '<input type="checkbox" id="lg-face-confirm-chk" style="width:18px;height:18px;margin-top:2px;flex-shrink:0;">' +
            '<span style="font-size:13px;">I have tried my best to match my face. I consent to the invigilator reviewing my photo and approving my access.</span>' +
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

        // MANDATORY: Next is disabled until passport is uploaded
        if (nextBtn) { nextBtn.disabled = true; }

        // Checkbox → in AUTO mode: proceed immediately. In MANUAL mode: request invigilator approval.
        if (confirmChk) {
            confirmChk.addEventListener('change', function() {
                if (!this.checked) { return; }

                // AUTO mode: no invigilator needed — just enable next.
                if (cfg.faceapprovalmode === 'auto' || cfg.faceapprovalmode !== 'manual') {
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
                                            '<br>Please contact your instructor.';
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
            if (confirmWrap) { confirmWrap.style.display = 'block'; }
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
                        showFallback('⚠️ Auto face detection unavailable. Tick the confirmation box below to request invigilator review.');
                    }
                };
                reader.readAsDataURL(file);
            });
        }

        // ── Load face-api models ──────────────────────────────────────────
        if (typeof faceapi !== 'undefined' && cfg.modelurl && !faceApiReady) {
            Promise.all([
                faceapi.nets.tinyFaceDetector.loadFromUri(cfg.modelurl),
                faceapi.nets.faceLandmark68TinyNet.loadFromUri(cfg.modelurl),
                faceapi.nets.faceRecognitionNet.loadFromUri(cfg.modelurl),
            ]).then(function() {
                faceApiReady = true;
                if (statusEl) { statusEl.textContent = '✓ Face detection ready. Upload your passport photo above.'; }
            }).catch(function() {
                showFallback('⚠️ Auto face detection unavailable. Upload your photo and tick the checkbox below.');
            });
        } else if (faceApiReady) {
            if (statusEl) { statusEl.textContent = '✓ Face detection ready. Upload your passport photo above.'; }
        } else {
            showFallback('⚠️ Face detection could not load. Upload your photo and tick the checkbox to continue.');
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
                            matchFill.style.background = pct >= 70 ? '#28a745' : pct >= 50 ? '#fd7e14' : '#dc3545';
                        }

                        if (pct >= 70) {
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
                                matchMsg.innerHTML = '<strong style="color:#dc3545;">' + pct + '% — need 70%+</strong>. ' +
                                    (remaining > 0 ? remaining + ' attempt' + (remaining > 1 ? 's' : '') + ' remaining.' : '');
                            }
                            if (faceMatchAttempts >= 3) {
                                clearInterval(matchIv);
                                if (matchMsg) {
                                    matchMsg.innerHTML = '<strong style="color:#dc3545;">⚠️ Could not reach 70% after 3 attempts.</strong><br>' +
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
                    if (surf === 'browser' || surf === 'window') {
                        stream.getTracks().forEach(function(t) { t.stop(); });
                        if (box) { box.style.background = '#fff3cd'; }
                        if (msg) { msg.innerHTML = '⚠️ You shared a <strong>browser tab or window</strong>. Please share your <strong>Entire Screen</strong> instead.'; }
                        if (btn) { btn.disabled = false; btn.textContent = '🖥 Try Again'; }
                        return;
                    }

                    state.screenStream = stream;

                    // Monitor for stop — if student stops sharing during exam, pause it
                    track.addEventListener('ended', function() {
                        if (state.proctoringActive && !state.finished) {
                            logProctoringEvent('screen_share_stopped', {}, 'high', 10);
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
        var overlay = document.createElement('div');
        overlay.id = 'lg-ss-paused';
        overlay.style.cssText = 'position:fixed;inset:0;z-index:99998;background:rgba(220,53,69,0.96);' +
            'display:flex;align-items:center;justify-content:center;flex-direction:column;' +
            'font-family:"Segoe UI",system-ui,sans-serif;color:#fff;padding:20px;text-align:center;';
        overlay.innerHTML =
            '<div style="font-size:64px;margin-bottom:16px;">🖥</div>' +
            '<h2 style="margin:0 0 12px;font-size:22px;font-weight:800;">Screen Sharing Stopped</h2>' +
            '<p style="margin:0 0 24px;font-size:15px;opacity:.9;max-width:440px;">' +
            'Your screen sharing has stopped. Resume it to continue your assessment.<br>' +
            '<strong>This has been flagged and your proctor has been notified.</strong></p>' +
            '<button id="lg-ss-resume-btn" ' +
            'style="background:#fff;color:#dc3545;border:none;border-radius:10px;' +
            'padding:14px 32px;font-size:15px;font-weight:700;cursor:pointer;box-shadow:0 4px 16px rgba(0,0,0,0.3);">' +
            '🖥 Resume Screen Sharing</button>';
        document.body.appendChild(overlay);

        document.getElementById('lg-ss-resume-btn').addEventListener('click', function() {
            navigator.mediaDevices.getDisplayMedia({video: true, audio: false})
                .then(function(newStream) {
                    state.screenStream = newStream;
                    var track = newStream.getVideoTracks()[0];
                    track.addEventListener('ended', function() {
                        if (state.proctoringActive && !state.finished) {
                            logProctoringEvent('screen_share_stopped', {}, 'high', 10);
                            showScreenSharePausedOverlay();
                        }
                    });
                    var old = document.getElementById('lg-ss-paused');
                    if (old) { old.parentNode.removeChild(old); }
                    showToast('✅ Screen sharing resumed.', 'success', 3000);
                })
                .catch(function() {
                    showToast('Could not start screen sharing. Please try again.', 'danger', 5000);
                });
        });
    }

    function captureScreenSnapshot(type) {
        if (!state.screenStream || !state.attemptid || state.attemptid < 1) { return; }
        var track = state.screenStream.getVideoTracks()[0];
        if (!track || track.readyState !== 'live') { return; }
        var imgCapture;
        if (typeof ImageCapture !== 'undefined') {
            imgCapture = new ImageCapture(track);
            imgCapture.grabFrame().then(function(bitmap) {
                var c = document.createElement('canvas');
                c.width = bitmap.width; c.height = bitmap.height;
                c.getContext('2d').drawImage(bitmap, 0, 0);
                uploadSnapshot(c, type || 'screen_capture');
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
        vid.srcObject = state.screenStream;
        vid.muted = true;
        // Wait for metadata so videoWidth/videoHeight are populated before drawing
        vid.addEventListener('loadedmetadata', function() {
            vid.play().then(function() {
                // Give one frame time to render
                requestAnimationFrame(function() {
                    var w = vid.videoWidth  || 1920;
                    var h = vid.videoHeight || 1080;
                    var c = document.createElement('canvas');
                    c.width = Math.min(w, 1920); c.height = Math.min(h, 1080);
                    c.getContext('2d').drawImage(vid, 0, 0, c.width, c.height);
                    uploadSnapshot(c, type || 'screen_capture');
                    vid.pause(); vid.srcObject = null;
                });
            }).catch(function() {});
        });
        vid.load();
    }

    function uploadSnapshot(canvas, type) {
        callWS('mod_learnguard_send_snapshot', {
            attemptid: state.attemptid,
            imagedata: canvas.toDataURL('image/jpeg', 0.7),
            event_type: type,
        }).catch(function() {});
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
            '<p style="color:#555;margin-bottom:6px;">Show your desk, walls, and surroundings to confirm you are alone in the room.</p>' +
            '<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:8px 14px;font-size:13px;margin-bottom:12px;">' +
            'ℹ️ Max 30 seconds. Retake as many times as needed. When satisfied, click <strong>Upload Video</strong> — your proctor will review the recording. Continue is only enabled after a successful upload.</div>' +
            '<div style="text-align:center;">' +
            '<video id="lg-env-preview" autoplay playsinline muted style="width:100%;max-width:360px;border-radius:8px;border:2px solid #dee2e6;background:#111;"></video>' +
            '<video id="lg-env-playback" controls style="display:none;width:100%;max-width:360px;border-radius:8px;margin-top:8px;"></video>' +
            '</div>' +
            '<div id="lg-env-timer" style="text-align:center;font-size:36px;font-weight:bold;color:#dc3545;margin:10px 0;display:none;">30</div>' +
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
                if (uploadBtn) { uploadBtn.style.display = 'inline-block'; }
                if (statusEl) {
                    statusEl.innerHTML = '<span style="color:#856404;font-size:13px;">' +
                        '✓ Recording complete (' + (30 - timeLeft) + 's). Review the video above, then click ' +
                        '<strong style="color:#28a745;">⬆ Upload Video</strong> to submit it to your proctor.</span>';
                }
                // Continue stays disabled until Upload is clicked.
                if (nextBtn) { nextBtn.disabled = true; }
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
                    callWS('mod_learnguard_upload_360', {
                        attemptid: state.attemptid,
                        videodata: b64,
                        duration:  30 - timeLeft,
                    }).then(function(data) {
                        if (uploadBtn) { uploadBtn.style.display = 'none'; }
                        if (statusEl) {
                            statusEl.innerHTML = wiz_chkResult(true,
                                '✅ Environment recording uploaded successfully! Your proctor can now view it. Click <strong>Continue</strong> to proceed.' +
                                (data && data.size ? ' (' + Math.round(data.size / 1024) + ' KB)' : ''));
                        }
                        if (nextBtn) { nextBtn.disabled = false; }
                    }).catch(function() {
                        uploadBtn.disabled = false;
                        uploadBtn.textContent = '↺ Retry Upload';
                        if (statusEl) {
                            statusEl.innerHTML = wiz_chkResult(false,
                                '⚠️ Upload failed. Please try again or click <strong>Continue</strong> to proceed without uploading.');
                        }
                        if (nextBtn) { nextBtn.disabled = false; }
                    });
                } else {
                    uploadBtn.disabled = false;
                    if (nextBtn) { nextBtn.disabled = false; }
                }
            };
            reader.readAsDataURL(blob);
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
                startScheduleCountdown();
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
                startScheduleCountdown();
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
                'Please contact your instructor if you need further assistance.</p>' +
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
                    '<span style="font-size:13px;color:#0c5460;">Opens in <span id="lg-open-countdown-clock">--:--:--</span></span>' +
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
                    '<strong>⏳ Available until ' + availCloseDate + '</strong> ' +
                    '<span style="font-size:13px;color:#856404;">(closes in <span id="lg-close-countdown-clock">--:--:--</span>)</span>' +
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
            '<div style="font-weight:800;font-size:17px;letter-spacing:-0.3px;min-width:160px;">🛡 ' + bn + '</div>' +
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
            '<div id="lg-timer" style="font-size:20px;font-weight:900;font-variant-numeric:tabular-nums;' +
            'background:rgba(0,0,0,0.2);padding:5px 14px;border-radius:20px;letter-spacing:2px;">--:--</div>' +
            '</div></div>' +

            // ══ COMPLETION BAR ═════════════════════════════════════════════
            '<div style="height:3px;background:#e9ecef;flex-shrink:0;">' +
            '<div id="lg-completion-bar" style="height:100%;background:' + bac + ';' +
            'width:0%;transition:width .5s;"></div></div>' +

            // ══ QUESTION AREA (full-width, vertically centred) ═════════════
            '<div id="lg-question-scroll" role="main" aria-label="Exam question" style="flex:1;overflow-y:auto;display:flex;' +
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
            '<div id="lg-nav-panel" style="display:flex;flex-wrap:wrap;gap:5px;"></div>' +
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
            'display:flex;flex-direction:column;gap:5px;padding:8px;';

        // Camera slot (populated by initWebcam)
        var camSection = document.createElement('div');
        camSection.id = 'lg-cam-section';
        toolbar.appendChild(camSection);

        // Summary card
        var summaryDiv = document.createElement('div');
        summaryDiv.style.cssText =
            'background:rgba(255,255,255,0.97);border-radius:10px;' +
            'box-shadow:0 2px 12px rgba(0,0,0,0.12);padding:10px 12px;';
        summaryDiv.innerHTML =
            '<div id="lg-risk-badge" style="background:#28a745;color:#fff;padding:4px 8px;' +
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
        toolsDiv.appendChild(tbBtn('🆘', 'SOS',   function() { window.lgDifficulty(); }, '#dc3545'));

        // Zoom row
        var zoomRow = document.createElement('div');
        zoomRow.style.cssText = 'display:flex;gap:4px;';
        function zBtn(lbl, fn) {
            var b = document.createElement('button');
            b.style.cssText =
                'flex:1;background:rgba(255,255,255,0.97);border:1px solid rgba(0,0,0,0.1);' +
                'border-radius:8px;padding:7px;cursor:pointer;font-size:13px;font-weight:700;' +
                'box-shadow:0 1px 4px rgba(0,0,0,0.08);';
            b.textContent = lbl;
            b.addEventListener('click', fn);
            return b;
        }
        zoomRow.appendChild(zBtn('A−', function() { changeTextZoom(-10); }));
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
            if (strip)  { strip.style.maxHeight = navOpen ? '170px' : '0'; }
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

        spa.innerHTML =
            '<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;' +
            'min-height:100vh;font-family:system-ui,sans-serif;text-align:center;padding:40px;background:#f8f9fa;">' +
            '<div style="background:#fff;border-radius:16px;padding:40px 48px;max-width:520px;' +
            'box-shadow:0 4px 32px rgba(220,53,69,0.15);border-top:6px solid #dc3545;">' +
            '<div style="font-size:72px;margin-bottom:20px;">⛔</div>' +
            '<h2 style="color:#dc3545;margin:0 0 12px;font-size:24px;">Attempt Suspended</h2>' +
            '<p style="color:#6c757d;margin:0 0 8px;font-size:15px;line-height:1.6;">Your exam has been suspended by the proctor due to integrity violations.</p>' +
            '<p style="color:#6c757d;margin:0 0 28px;font-size:14px;">Please contact your instructor to discuss next steps.</p>' +
            '<div id="lg-sus-status" style="background:#fff3cd;border:1px solid #ffc107;border-radius:10px;' +
            'padding:14px 18px;margin-bottom:20px;font-size:13px;color:#856404;">' +
            '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#ffc107;' +
            'margin-right:8px;animation:lg-pulse 1.5s infinite;"></span>' +
            'Checking for reinstatement… This page checks automatically every 20 seconds.' +
            '</div>' +
            '<div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">' +
            '<button onclick="location.reload()" ' +
            'style="background:#0f6cbf;color:#fff;border:none;border-radius:8px;padding:11px 22px;' +
            'font-size:14px;font-weight:700;cursor:pointer;">🔄 Check Now</button>' +
            '<a href="' + cfg.wwwroot + '/course/view.php?id=' + (cfg.courseid || '') + '" ' +
            'style="background:#fff;color:#6c757d;border:2px solid #dee2e6;border-radius:8px;' +
            'padding:11px 22px;font-size:14px;font-weight:700;text-decoration:none;">← Back to Course</a>' +
            '</div></div></div>';

        // Poll server every 20 seconds — if proctor reinstates, reload automatically.
        var susCheckTimer = setInterval(function() {
            if (!state.attemptid) { return; }
            callWS('mod_learnguard_get_attempt_state', {attemptid: state.attemptid})
                .then(function(data) {
                    if (data.state === 'inprogress' && !data.suspended) {
                        clearInterval(susCheckTimer);
                        var statusEl = el('lg-sus-status');
                        if (statusEl) {
                            statusEl.style.background = '#d4edda';
                            statusEl.style.borderColor = '#28a745';
                            statusEl.style.color = '#155724';
                            statusEl.innerHTML = '✅ <strong>Your suspension has been lifted!</strong><br>' +
                                '<p style="margin:8px 0 12px;font-size:13px;">Click the button below to return to fullscreen and resume your exam.</p>' +
                                '<button id="lg-resume-btn" style="background:#28a745;color:#fff;border:none;border-radius:10px;' +
                                'padding:14px 32px;font-size:15px;font-weight:700;cursor:pointer;box-shadow:0 4px 16px rgba(40,167,69,0.4);">' +
                                '▶ Return to Fullscreen &amp; Resume Exam</button>';
                            // Clicking the button provides the user gesture needed for requestFullscreen().
                            setTimeout(function() {
                                var btn = document.getElementById('lg-resume-btn');
                                if (btn) {
                                    btn.addEventListener('click', function() {
                                        var docEl = document.documentElement;
                                        var req   = docEl.requestFullscreen || docEl.webkitRequestFullscreen || docEl.mozRequestFullScreen;
                                        if (req) {
                                            req.call(docEl)
                                                .then(function() { location.reload(); })
                                                .catch(function() { location.reload(); });
                                        } else {
                                            location.reload();
                                        }
                                    });
                                }
                            }, 100);
                        }
                    }
                }).catch(function() {});
        }, 20000);
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
