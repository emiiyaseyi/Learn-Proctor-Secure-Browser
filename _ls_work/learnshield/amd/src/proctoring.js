// Learn Shield — Main proctoring module (active during quiz attempt)
//
// setup() → runs on the quiz attempt page.
//   - Enforces fullscreen immediately (blocking overlay until entered).
//   - Detects tab switching, keyboard shortcuts, clipboard, right-click.
//   - Takes webcam snapshots at configured intervals.
//   - Monitors audio (with live noise meter), eye position, liveness.
//   - Disables LMS chat widgets during the exam.
//   - Auto-suspends if risk score exceeds threshold.
//
// @package    quizaccess_learnshield
// @author     Michael Adeniran <michaeladeniransnr@gmail.com>
// @copyright  2025 Michael Adeniran
// @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

/* eslint-disable no-console */
define(['jquery', 'core/ajax', 'core/notification', 'core/str'],
    function($, Ajax, Notification, Str) {

        let sessionScore = 0;
        let sessionId    = 0;
        let propsG       = {};
        const STRINGS    = {};

        async function loadStrings() {
            const keys = [
                {key: 'facenotfoundoncam',                component: 'quizaccess_learnshield'},
                {key: 'wrong_during_taking_image',         component: 'quizaccess_learnshield'},
                {key: 'enable_web_camera_before_submitting', component: 'quizaccess_learnshield'},
                {key: 'videonotavailable',                 component: 'quizaccess_learnshield'},
                {key: 'tab_switch_warning',                component: 'quizaccess_learnshield'},
                {key: 'keyboard_violation_warning',        component: 'quizaccess_learnshield'},
                {key: 'clipboard_copy_warning',            component: 'quizaccess_learnshield'},
            ];
            try {
                const vals = await Str.get_strings(keys);
                keys.forEach((k, i) => { STRINGS[k.key] = vals[i]; });
            } catch (e) { console.warn('LS strings failed', e); }
        }

        // -----------------------------------------------------------------------
        // AJAX event logger
        // -----------------------------------------------------------------------
        function logEvent(type, data, severity) {
            Ajax.call([{
                methodname: 'quizaccess_learnshield_log_event',
                args: {
                    sessionid:  sessionId,
                    courseid:   propsG.courseid  || 0,
                    quizid:     propsG.quizid    || propsG.cmid || 0,
                    attemptid:  propsG.attemptid || 0,
                    event_type: type,
                    event_data: JSON.stringify(data || {}),
                    severity:   severity || 'info',
                },
            }])[0]
            .done(function(res) {
                if (res.scoreimpact > 0) {
                    sessionScore += res.scoreimpact;
                    updateScoreWidget();
                }
            })
            .fail(function(e) { console.warn('LS log failed', e); });
        }

        function updateScoreWidget() {
            const el  = document.getElementById('ls-risk-score');
            const bar = document.getElementById('ls-risk-bar');
            if (el)  { el.textContent = sessionScore; }
            if (bar) {
                const pct  = Math.min(sessionScore, 100);
                bar.style.width  = pct + '%';
                bar.className    = 'learnshield-score-bar ' +
                    (sessionScore >= 60 ? 'high' : sessionScore >= 30 ? 'medium' : 'low');
            }
            checkAutoSuspend(sessionScore);
        }

        // -----------------------------------------------------------------------
        // Auto-suspend when risk score is critically high
        // -----------------------------------------------------------------------
        let autoSuspendTriggered = false;
        function checkAutoSuspend(score) {
            if (autoSuspendTriggered || score < 80) { return; }
            autoSuspendTriggered = true;
            Ajax.call([{
                methodname: 'quizaccess_learnshield_auto_suspend',
                args: {
                    sessionid: sessionId,
                    courseid:  propsG.courseid || 0,
                    quizid:    propsG.quizid   || 0,
                    reason:    'high_risk_score',
                },
            }])[0].done(function(res) {
                if (res && res.suspended) {
                    document.body.innerHTML =
                        '<div style="position:fixed;inset:0;background:#dc3545;color:#fff;' +
                        'display:flex;align-items:center;justify-content:center;flex-direction:column;' +
                        'text-align:center;font-family:system-ui,sans-serif;padding:40px;z-index:2147483647;">' +
                        '<div style="font-size:72px;margin-bottom:20px;">⚠️</div>' +
                        '<h2 style="font-size:26px;margin-bottom:12px;">Exam Suspended</h2>' +
                        '<p style="font-size:16px;opacity:.85;max-width:480px;">' +
                        'Your exam has been suspended due to excessive integrity violations. ' +
                        'Please contact your proctor or institution.</p></div>';
                }
            });
        }

        // -----------------------------------------------------------------------
        // Noise meter widget
        // -----------------------------------------------------------------------
        function injectNoiseMeter() {
            if (document.getElementById('ls-noise-meter-widget')) { return; }
            const w = document.createElement('div');
            w.id = 'ls-noise-meter-widget';
            w.style.cssText = 'position:fixed;bottom:230px;right:20px;z-index:9997;' +
                'background:#fff;border:1px solid #dee2e6;border-radius:8px;padding:6px 12px;' +
                'font-size:11px;min-width:130px;box-shadow:0 2px 8px rgba(0,0,0,.1);';
            w.innerHTML = '<div style="font-weight:600;margin-bottom:2px;">Noise Level</div>' +
                '<div style="display:flex;align-items:center;gap:6px;">' +
                '<div style="flex:1;background:#e9ecef;border-radius:4px;height:8px;overflow:hidden;">' +
                '<div id="ls-noise-bar" style="height:100%;background:#28a745;width:0%;' +
                'transition:width .15s;border-radius:4px;"></div></div>' +
                '<span id="ls-noise-label" style="font-size:10px;min-width:28px;color:#6c757d;">--</span></div>';
            document.body.appendChild(w);
        }

        function updateNoiseMeter(level) {
            const bar   = document.getElementById('ls-noise-bar');
            const label = document.getElementById('ls-noise-label');
            if (!bar) { return; }
            const pct = Math.min(level * 3.5, 100);
            bar.style.width      = pct + '%';
            bar.style.background = level > 30 ? '#dc3545' : level > 15 ? '#fd7e14' : '#28a745';
            if (label) { label.textContent = Math.round(level); }
        }

        // -----------------------------------------------------------------------
        // Disable LMS chat/help widgets during exam
        // -----------------------------------------------------------------------
        function injectLmsChatDisabler() {
            const selectors = [
                '.block_online_users', '.block_messages',
                '[class*="chat-widget"]', '[class*="helpdesk"]',
                '.crisp-client', '#intercom-container',
                '#hubspot-messages-iframe-container',
                '.freshdesk-widget', '.zendesk-launcher',
                '[id*="chat-button"]', '[class*="live-chat"]',
            ];
            const hide = function() {
                selectors.forEach(function(sel) {
                    document.querySelectorAll(sel).forEach(function(el) {
                        if (!el.closest('#ls-wizard-overlay') && !el.closest('#ls-webcam-box')) {
                            el.style.setProperty('display', 'none', 'important');
                        }
                    });
                });
            };
            hide();
            const obs = new MutationObserver(hide);
            obs.observe(document.body, {childList: true, subtree: true});
        }

        // -----------------------------------------------------------------------
        // Fullscreen — BLOCKING overlay approach
        // -----------------------------------------------------------------------
        const FS_OVERLAY_ID = 'ls-fs-mandatory';

        function fsIsActive() {
            return !!(document.fullscreenElement || document.webkitFullscreenElement || document.mozFullScreenElement);
        }

        function enterFS() {
            const el = document.documentElement;
            if (el.requestFullscreen)        return el.requestFullscreen();
            if (el.webkitRequestFullscreen)  return el.webkitRequestFullscreen();
            if (el.mozRequestFullScreen)     return el.mozRequestFullScreen();
            return Promise.reject(new Error('Fullscreen not supported'));
        }

        function showFsBlocker() {
            let blocker = document.getElementById(FS_OVERLAY_ID);
            if (blocker) { blocker.style.display = 'flex'; return; }

            blocker = document.createElement('div');
            blocker.id = FS_OVERLAY_ID;
            blocker.style.cssText = [
                'position:fixed', 'inset:0', 'z-index:2147483647',
                'background:rgba(0,10,40,.98)', 'color:#fff',
                'display:flex', 'flex-direction:column',
                'align-items:center', 'justify-content:center',
                'text-align:center', 'padding:40px',
                'font-family:system-ui,sans-serif',
            ].join(';');
            blocker.innerHTML = `
                <div style="font-size:72px;margin-bottom:20px;">🔒</div>
                <h2 style="font-size:26px;margin-bottom:12px;">Fullscreen Mode Required</h2>
                <p style="font-size:16px;opacity:.85;max-width:480px;margin-bottom:28px;">
                    You have exited fullscreen mode. You must return to fullscreen to continue your exam.
                    This exit has been logged.
                </p>
                <button id="ls-fs-re-enter" style="
                    background:#28a745;color:#fff;border:none;
                    padding:16px 40px;font-size:18px;border-radius:10px;cursor:pointer;">
                    ↩ Return to Fullscreen
                </button>
                <p style="margin-top:18px;font-size:13px;opacity:.6;">
                    If you cannot enter fullscreen, contact your proctor.
                </p>`;
            document.body.appendChild(blocker);

            document.getElementById('ls-fs-re-enter').addEventListener('click', function() {
                enterFS()
                    .then(function() { hideFsBlocker(); })
                    .catch(function() {
                        document.querySelector('#' + FS_OVERLAY_ID + ' p').textContent =
                            'Fullscreen was blocked. Please click again or press F11.';
                    });
            });
        }

        function hideFsBlocker() {
            const b = document.getElementById(FS_OVERLAY_ID);
            if (b) { b.style.display = 'none'; }
        }

        const LS_FS_KEY = 'learnshield_fs_required';

        function setupFullscreen(cfg) {
            if (!cfg.enablefullscreen) { return; }

            sessionStorage.setItem(LS_FS_KEY, '1');
            showFsBlocker();
            enterFS().then(hideFsBlocker).catch(function() {});

            const onFsChange = function() {
                if (!fsIsActive()) {
                    showFsBlocker();
                    logEvent('fullscreen_exit', {}, 'warning');
                } else {
                    hideFsBlocker();
                }
            };
            document.addEventListener('fullscreenchange',       onFsChange);
            document.addEventListener('webkitfullscreenchange', onFsChange);
            document.addEventListener('mozfullscreenchange',    onFsChange);

            document.addEventListener('visibilitychange', function() {
                if (!document.hidden && !fsIsActive()) { showFsBlocker(); }
            });

            window.addEventListener('blur', function() {
                if (!fsIsActive()) {
                    setTimeout(function() { if (!fsIsActive()) { showFsBlocker(); } }, 50);
                }
            });

            // Block F11.
            document.addEventListener('keydown', function(e) {
                if (e.key === 'F11') {
                    e.preventDefault();
                    if (!fsIsActive()) { enterFS().catch(function() {}); }
                }
            }, true);
        }

        // -----------------------------------------------------------------------
        // Tab / window switching
        // -----------------------------------------------------------------------
        function setupTabDetection(cfg) {
            if (!cfg.enabletabdetect) { return; }

            let awayStart = 0;
            let awayCount = 0;

            document.addEventListener('visibilitychange', function() {
                if (document.hidden) {
                    awayStart = Date.now();
                    awayCount++;
                    logEvent('tab_switch', {
                        reason:     'tab_hidden',
                        count:      awayCount,
                        page_title: document.title,
                        url:        window.location.href,
                    }, 'warning');
                    Notification.addNotification({
                        message: STRINGS.tab_switch_warning || '⚠️ Leaving the exam tab has been logged.',
                        type: 'warning',
                    });
                } else {
                    if (awayStart > 0) {
                        const seconds = Math.round((Date.now() - awayStart) / 1000);
                        logEvent('tab_return', {away_seconds: seconds, count: awayCount}, 'warning');
                        awayStart = 0;
                        if (seconds > 5) {
                            Notification.addNotification({
                                message: `⚠️ You were away for ${seconds} seconds. This has been logged.`,
                                type: 'warning',
                            });
                        }
                    }
                }
            });

            window.addEventListener('blur', function() {
                logEvent('window_blur', {reason: 'window_lost_focus'}, 'warning');
            });

            window.addEventListener('beforeunload', function(e) {
                logEvent('page_unload', {url: window.location.href}, 'warning');
                e.preventDefault();
                e.returnValue = 'Leaving this page will be logged. Are you sure?';
                return e.returnValue;
            });
        }

        // -----------------------------------------------------------------------
        // Keyboard — always block dangerous OS shortcuts; optionally block more
        // -----------------------------------------------------------------------
        function setupKeyboard(cfg) {
            function log(key) {
                logEvent('keyboard_violation', {key}, 'warning');
                Notification.addNotification({
                    message: (STRINGS.keyboard_violation_warning || '⚠️ Restricted shortcut blocked.') +
                             ' (' + key + ')',
                    type: 'warning',
                });
            }

            document.addEventListener('keydown', function(e) {
                const k   = e.key;
                const ctrl = e.ctrlKey || e.metaKey;
                const alt  = e.altKey;

                // ALWAYS block: Windows/Meta key (prevents OS shortcuts).
                if (k === 'Meta' || k === 'OS' || (e.metaKey && k !== 'Meta' && k !== 'OS')) {
                    e.preventDefault();
                    log('Windows/Meta');
                    return;
                }

                // ALWAYS block: Escape (would exit fullscreen or reveal browser UI).
                if (k === 'Escape') {
                    e.preventDefault();
                    return;
                }

                // ALWAYS block: All Alt combos except Alt+C/N (exam navigation).
                if (alt && !ctrl && k !== 'c' && k !== 'C' && k !== 'n' && k !== 'N') {
                    e.preventDefault();
                    logEvent('keyboard_violation', {key: 'Alt+' + k}, 'warning');
                    return;
                }

                // ALWAYS block: Ctrl+Tab / Alt+Tab (OS-level tab switch).
                if (k === 'Tab' && (ctrl || alt)) {
                    e.preventDefault();
                    logEvent('tab_switch', {reason: 'keyboard_tab'}, 'warning');
                    return;
                }

                if (!cfg.enablekeyblock) { return; }

                // Block F12.
                if (k === 'F12') { e.preventDefault(); log('F12'); return; }

                // Block PrintScreen.
                if (k === 'PrintScreen') { e.preventDefault(); log('PrintScreen'); return; }

                // Block Ctrl/Cmd + dangerous combos.
                if (ctrl && !alt) {
                    const blocked = {
                        'c': 'Ctrl+C', 'v': 'Ctrl+V',
                        'u': 'Ctrl+U', 'p': 'Ctrl+P',
                        's': 'Ctrl+S',
                    };
                    const lower = k.toLowerCase();
                    if (blocked[lower]) { e.preventDefault(); log(blocked[lower]); return; }
                }
            }, true);
        }

        // -----------------------------------------------------------------------
        // Clipboard
        // -----------------------------------------------------------------------
        function setupClipboard(cfg) {
            if (!cfg.enableclipboard) { return; }
            document.addEventListener('copy',  function() { logEvent('clipboard_copy',  {}, 'warning'); });
            document.addEventListener('paste', function() { logEvent('clipboard_paste', {}, 'warning'); });
            document.addEventListener('cut',   function() { logEvent('clipboard_copy',  {reason: 'cut'}, 'warning'); });
        }

        // -----------------------------------------------------------------------
        // Right-click
        // -----------------------------------------------------------------------
        function disableContextMenu() {
            document.addEventListener('contextmenu', function(e) { e.preventDefault(); });
        }

        // -----------------------------------------------------------------------
        // Risk score widget
        // -----------------------------------------------------------------------
        function injectScoreWidget() {
            if (document.getElementById('ls-score-widget')) { return; }
            $('body').append(`
                <div id="ls-score-widget" style="position:fixed;bottom:90px;right:20px;
                     background:#fff;border:1px solid #dee2e6;border-radius:8px;
                     padding:8px 14px;z-index:9998;font-size:12px;min-width:130px;
                     box-shadow:0 2px 8px rgba(0,0,0,.15);">
                    <div style="font-weight:600;margin-bottom:4px;">Integrity Score</div>
                    <div class="learnshield-score-bar-wrap">
                        <div id="ls-risk-bar" class="learnshield-score-bar low" style="width:0%"></div>
                    </div>
                    <div style="text-align:right;font-size:11px;color:#6c757d;margin-top:2px;">
                        <span id="ls-risk-score">0</span> pts
                    </div>
                </div>`);
        }

        // -----------------------------------------------------------------------
        // Multiple monitor detection
        // -----------------------------------------------------------------------
        function detectMultipleMonitors() {
            if ('isExtended' in window.screen && window.screen.isExtended) {
                logEvent('multiple_monitors', {isExtended: true}, 'warning');
                Notification.addNotification({
                    message: '⚠️ Multiple monitors detected. External screens are not permitted.',
                    type: 'warning',
                });
            }
            try {
                window.screen.addEventListener('change', function() {
                    if (window.screen.isExtended) {
                        logEvent('multiple_monitors', {reason: 'screen_change'}, 'warning');
                    }
                });
            } catch (e) { /* Not supported */ }
        }

        // -----------------------------------------------------------------------
        // Voice / audio monitoring (feeds noise meter)
        // -----------------------------------------------------------------------
        function startVoiceMonitoring() {
            navigator.mediaDevices.getUserMedia({audio: true, video: false})
                .then(function(stream) {
                    const AC       = window.AudioContext || window.webkitAudioContext;
                    if (!AC) { return; }
                    const ctx      = new AC();
                    const analyser = ctx.createAnalyser();
                    analyser.fftSize = 512;
                    ctx.createMediaStreamSource(stream).connect(analyser);

                    let lastLog = 0;
                    setInterval(function() {
                        const data = new Uint8Array(analyser.frequencyBinCount);
                        analyser.getByteFrequencyData(data);
                        const level = data.reduce((a, b) => a + b, 0) / data.length;
                        updateNoiseMeter(level);
                        const now = Date.now();
                        if (level > 15 && now - lastLog > 30000) {
                            lastLog = now;
                            logEvent('audio_detected', {level: Math.round(level)}, 'info');
                        }
                    }, 1500);
                })
                .catch(function() { /* Mic unavailable */ });
        }

        // -----------------------------------------------------------------------
        // Eye-tracking (gaze direction estimation)
        // -----------------------------------------------------------------------
        function startEyeTracking(video, modelurl) {
            if (!modelurl) { return; }
            try {
                // eslint-disable-next-line no-undef
                faceapi.nets.faceLandmark68TinyNet.loadFromUri(modelurl).then(function() {
                    let lastGazeLog = 0;
                    setInterval(async function() {
                        if (!video || video.readyState < 2) { return; }
                        try {
                            // eslint-disable-next-line no-undef
                            const det = await faceapi.detectAllFaces(video).withFaceLandmarks(true);
                            if (!det.length) { return; }
                            const lm    = det[0].landmarks;
                            const le    = lm.getLeftEye();
                            const re    = lm.getRightEye();
                            const gazeL = Math.abs(le[0].x + le[3].x - 2 * le[1].x);
                            const gazeR = Math.abs(re[0].x + re[3].x - 2 * re[1].x);
                            const ew    = Math.abs(le[0].x - le[3].x);
                            if (ew > 0 && (gazeL + gazeR) / 2 > ew * 0.3) {
                                const now = Date.now();
                                if (now - lastGazeLog > 15000) {
                                    lastGazeLog = now;
                                    logEvent('gaze_away', {}, 'info');
                                }
                            }
                        } catch (e) { /* Skip */ }
                    }, 3000);
                }).catch(function() {});
            } catch (e) { /* faceapi not loaded */ }
        }

        // -----------------------------------------------------------------------
        // Liveness monitoring (blink detection)
        // -----------------------------------------------------------------------
        function startLivenessMonitoring(video, modelurl) {
            if (!modelurl) { return; }
            let lastBlink = Date.now();
            let prevEar   = null;
            let warned    = false;

            setInterval(async function() {
                if (!video || video.readyState < 2) { return; }
                try {
                    // eslint-disable-next-line no-undef
                    const det = await faceapi.detectAllFaces(video).withFaceLandmarks(true);
                    if (!det.length) { return; }
                    const eyeAR = function(pts) {
                        const h = Math.abs(pts[1].y - pts[5].y) + Math.abs(pts[2].y - pts[4].y);
                        const w = Math.abs(pts[0].x - pts[3].x);
                        return w > 0 ? h / (2 * w) : 1;
                    };
                    const lm  = det[0].landmarks;
                    const ear = (eyeAR(lm.getLeftEye()) + eyeAR(lm.getRightEye())) / 2;
                    if (prevEar !== null && prevEar > 0.2 && ear < 0.2) {
                        lastBlink = Date.now();
                        warned = false;
                    }
                    prevEar = ear;
                    if (!warned && (Date.now() - lastBlink) > 120000) {
                        warned = true;
                        logEvent('no_blink_detected', {minutes: 2}, 'warning');
                    }
                } catch (e) { /* Skip */ }
            }, 5000);
        }

        // -----------------------------------------------------------------------
        // Disable spell-check / dictionary during exam
        // -----------------------------------------------------------------------
        function disableDictionary() {
            const applyToEl = function(el) {
                el.setAttribute('spellcheck',    'false');
                el.setAttribute('autocomplete',  'off');
                el.setAttribute('autocorrect',   'off');
                el.setAttribute('autocapitalize','off');
            };
            document.querySelectorAll('input[type="text"],input[type="search"],textarea,[contenteditable="true"]')
                .forEach(applyToEl);
            const obs = new MutationObserver(function(mutations) {
                mutations.forEach(function(m) {
                    m.addedNodes.forEach(function(n) {
                        if (n.nodeType === 1) {
                            if (n.matches('input,textarea,[contenteditable]')) { applyToEl(n); }
                            n.querySelectorAll('input[type="text"],textarea,[contenteditable="true"]').forEach(applyToEl);
                        }
                    });
                });
            });
            obs.observe(document.body, {childList: true, subtree: true});
        }

        // -----------------------------------------------------------------------
        // Internet connection strength indicator
        // -----------------------------------------------------------------------
        function injectConnectionWidget() {
            const widget = document.createElement('div');
            widget.id = 'ls-conn-widget';
            widget.style.cssText = 'position:fixed;bottom:160px;right:20px;z-index:9997;' +
                'background:#fff;border:1px solid #dee2e6;border-radius:8px;padding:6px 12px;' +
                'font-size:11px;min-width:130px;box-shadow:0 2px 8px rgba(0,0,0,.1);';
            widget.innerHTML = '<div style="font-weight:600;margin-bottom:2px;">Connection</div>' +
                '<div id="ls-conn-status" style="color:#28a745">● Checking…</div>' +
                '<div id="ls-conn-type" style="color:#6c757d;font-size:10px;"></div>';
            document.body.appendChild(widget);

            function updateConnection() {
                const statusEl = document.getElementById('ls-conn-status');
                const typeEl   = document.getElementById('ls-conn-type');
                if (!statusEl) { return; }

                if (!navigator.onLine) {
                    statusEl.textContent = '● Offline!';
                    statusEl.style.color = '#dc3545';
                    logEvent('network_offline', {}, 'warning');
                    Notification.addNotification({message: '⚠️ Internet connection lost!', type: 'error'});
                    return;
                }

                if ('connection' in navigator) {
                    const c    = navigator.connection;
                    const type = c.effectiveType || 'unknown';
                    const dl   = c.downlink || 0;
                    const rtt  = c.rtt || 0;
                    const qual = type === '4g' ? 'Strong' : type === '3g' ? 'Good' : type === '2g' ? 'Weak' : 'Checking';
                    const col  = type === '4g' ? '#28a745' : type === '3g' ? '#fd7e14' : '#dc3545';
                    statusEl.textContent = '● ' + qual;
                    statusEl.style.color  = col;
                    typeEl.textContent    = dl > 0 ? `${dl} Mbps | ${rtt}ms` : type.toUpperCase();
                    if (type === '2g' || type === 'slow-2g') {
                        Notification.addNotification({
                            message: '⚠️ Weak internet connection detected. Snapshot quality may be reduced.',
                            type: 'warning',
                        });
                    }
                } else {
                    const start = Date.now();
                    fetch(window.location.origin + '/favicon.ico?t=' + start, {cache: 'no-store'})
                        .then(function() {
                            const ms   = Date.now() - start;
                            const qual = ms < 200 ? 'Strong' : ms < 600 ? 'Good' : 'Weak';
                            const col  = ms < 200 ? '#28a745' : ms < 600 ? '#fd7e14' : '#dc3545';
                            statusEl.textContent = '● ' + qual;
                            statusEl.style.color  = col;
                            typeEl.textContent    = ms + 'ms';
                        })
                        .catch(function() {
                            statusEl.textContent = '● Offline!';
                            statusEl.style.color = '#dc3545';
                        });
                }
            }

            updateConnection();
            setInterval(updateConnection, 15000);
            window.addEventListener('online',  function() { updateConnection(); });
            window.addEventListener('offline', function() { updateConnection(); });
            if ('connection' in navigator) { navigator.connection.addEventListener('change', updateConnection); }
        }

        // -----------------------------------------------------------------------
        // Reconnection recovery
        // -----------------------------------------------------------------------
        function setupReconnectionRecovery(props) {
            sessionStorage.setItem('ls_attempt_active', JSON.stringify({
                courseid:  props.courseid,
                quizid:    props.quizid,
                attemptid: props.attemptid,
                sessionid: props.sessionid,
                timestamp: Date.now(),
            }));

            window.addEventListener('online', function() {
                logEvent('network_reconnected', {}, 'info');
                Notification.addNotification({message: '✅ Internet connection restored.', type: 'success'});
            });

            window.addEventListener('offline', function() {
                logEvent('network_offline', {}, 'warning');
                Notification.addNotification({
                    message: '⚠️ Internet connection lost! Your progress is saved. Reconnect to continue.',
                    type: 'error',
                });
            });
        }

        // -----------------------------------------------------------------------
        // Mobile device detection
        // -----------------------------------------------------------------------
        function checkMobileBlock() {
            const isMobile = /Mobi|Android|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent)
                || ('ontouchstart' in window && screen.width < 1024);
            if (isMobile) {
                document.body.innerHTML = '';
                const blocker = document.createElement('div');
                blocker.style.cssText = 'position:fixed;inset:0;background:#dc3545;color:#fff;display:flex;' +
                    'flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:30px;' +
                    'font-family:system-ui,sans-serif;z-index:2147483647;';
                blocker.innerHTML = '<div style="font-size:56px;margin-bottom:16px;">📵</div>' +
                    '<h2>Mobile Device Not Permitted</h2>' +
                    '<p>This proctored exam must be taken on a desktop or laptop computer.<br>' +
                    'Mobile phones and tablets are not allowed.</p>' +
                    '<p style="margin-top:16px;opacity:.8;font-size:13px;">Please contact your institution if you need assistance.</p>';
                document.body.appendChild(blocker);
                return true;
            }
            return false;
        }

        // -----------------------------------------------------------------------
        // Audio clip recording (triggered by sustained high noise)
        // -----------------------------------------------------------------------
        var lsAudioClipRecording = false;
        var lsAudioClipLastTime  = 0;

        function lsInitAudioClipRecording(props) {
            if (!props.enableaudio) { return; }
            navigator.mediaDevices.getUserMedia({audio: true, video: false})
                .then(function(stream) {
                    var AC = window.AudioContext || window.webkitAudioContext;
                    if (!AC) { return; }
                    var ctx = new AC(); var analyser = ctx.createAnalyser();
                    analyser.fftSize = 512;
                    ctx.createMediaStreamSource(stream).connect(analyser);
                    var THRESHOLD = 30; var highCount = 0; var TRIGGER = 6;

                    setInterval(function() {
                        if (lsAudioClipRecording) { return; }
                        var data = new Uint8Array(analyser.frequencyBinCount);
                        analyser.getByteFrequencyData(data);
                        var level = Math.round(data.reduce(function(a, b) { return a + b; }, 0) / data.length);
                        if (level > THRESHOLD) {
                            highCount++;
                            if (highCount >= TRIGGER && Date.now() - lsAudioClipLastTime > 120000) {
                                lsStartAudioClipRecording(stream, level, props);
                                highCount = 0;
                            }
                        } else { highCount = Math.max(0, highCount - 1); }
                    }, 300);
                }).catch(function() {});
        }

        function lsStartAudioClipRecording(stream, noiseLevel, props) {
            if (lsAudioClipRecording) { return; }
            lsAudioClipRecording = true; lsAudioClipLastTime = Date.now();
            var chunks = []; var mime = 'audio/webm';
            try {
                var recorder = new MediaRecorder(stream, {mimeType: mime, audioBitsPerSecond: 32000});
                recorder.ondataavailable = function(e) { if (e.data.size > 0) chunks.push(e.data); };
                recorder.onstop = function() {
                    lsAudioClipRecording = false;
                    var blob = new Blob(chunks, {type: mime});
                    var reader = new FileReader();
                    reader.onloadend = function() {
                        Ajax.call([{
                            methodname: 'quizaccess_learnshield_upload_audio_clip',
                            args: {
                                sessionid:   sessionId,
                                audiodata:   reader.result,
                                duration:    8,
                                noise_level: noiseLevel,
                            },
                        }]);
                    };
                    reader.readAsDataURL(blob);
                };
                recorder.start(1000);
                logEvent('audio_clip_started', {noise_level: noiseLevel}, 'medium');
                setTimeout(function() {
                    if (recorder.state !== 'inactive') { recorder.stop(); }
                }, 8000);
            } catch (e) { lsAudioClipRecording = false; }
        }

        // -----------------------------------------------------------------------
        // Object detection — TensorFlow.js COCO-SSD
        // -----------------------------------------------------------------------
        var lsTfModel = null;

        function lsInitObjectDetection(props, videoEl) {
            if (typeof cocoSsd === 'undefined') { return; }
            setTimeout(function() {
                cocoSsd.load().then(function(model) {
                    lsTfModel = model;
                    setInterval(function() {
                        if (!lsTfModel || !videoEl || videoEl.readyState < 2) { return; }
                        lsTfModel.detect(videoEl).then(function(preds) {
                            var personCount = 0;
                            preds.forEach(function(pred) {
                                if (pred.score < 0.35) { return; } // Lowered threshold for better detection.
                                var cls = pred.class.toLowerCase();
                                if (cls === 'person') { personCount++; return; }
                                var vmap = {
                                    'cell phone': {sev: 'high',   sc: 25},
                                    'book':       {sev: 'medium', sc: 10},
                                    'laptop':     {sev: 'high',   sc: 20},
                                };
                                if (vmap[cls]) {
                                    logEvent('object_detected_' + cls.replace(' ', '_'),
                                        {class: cls, confidence: Math.round(pred.score * 100)},
                                        vmap[cls].sev);
                                }
                            });
                            if (personCount > 1) {
                                logEvent('multiple_persons_detected', {count: personCount}, 'high');
                            }
                        }).catch(function() {});
                    }, 30000);
                }).catch(function() {});
            }, 30000);
        }

        // -----------------------------------------------------------------------
        // Background change detection
        // -----------------------------------------------------------------------
        function lsInitBackgroundChangeDetection(videoEl) {
            var canvas = document.createElement('canvas');
            canvas.width = 64; canvas.height = 48;
            var ctx = canvas.getContext('2d'); var lastPx = null;
            setInterval(function() {
                if (!videoEl || videoEl.readyState < 2) { return; }
                ctx.drawImage(videoEl, 0, 0, 64, 48);
                var data = ctx.getImageData(0, 0, 64, 48).data;
                if (lastPx) {
                    var diff = 0;
                    for (var i = 0; i < data.length; i += 4) {
                        diff += Math.abs(data[i] - lastPx[i]) +
                                Math.abs(data[i + 1] - lastPx[i + 1]) +
                                Math.abs(data[i + 2] - lastPx[i + 2]);
                    }
                    if (diff / (data.length / 4 * 3) > 35) {
                        logEvent('background_change', {}, 'medium');
                    }
                }
                lastPx = new Uint8ClampedArray(data);
            }, 15000);
        }

        // -----------------------------------------------------------------------
        // Face identity matching (compares live feed to wizard-captured descriptor)
        // -----------------------------------------------------------------------
        var lsStoredDescriptor = null;

        function lsInitFaceIdentityMatching(videoEl, modelurl) {
            if (typeof faceapi === 'undefined') { return; }
            try {
                var s = sessionStorage.getItem('ls_face_desc');
                if (s) { lsStoredDescriptor = JSON.parse(s); }
            } catch (e) {}

            setInterval(function() {
                if (!lsStoredDescriptor || !videoEl || videoEl.readyState < 2) { return; }
                // eslint-disable-next-line no-undef
                faceapi.detectSingleFace(videoEl).withFaceLandmarks(true).withFaceDescriptor()
                    .then(function(det) {
                        if (!det || !det.descriptor) { return; }
                        // eslint-disable-next-line no-undef
                        var dist = faceapi.euclideanDistance(
                            new Float32Array(lsStoredDescriptor), det.descriptor);
                        if (dist > 0.6) {
                            logEvent('face_identity_mismatch', {distance: dist.toFixed(3)}, 'high');
                        }
                    }).catch(function() {});
            }, 300000);
        }

        // -----------------------------------------------------------------------
        // Public API
        // -----------------------------------------------------------------------
        return {

            /**
             * setup() — active quiz attempt: webcam + all monitoring.
             */
            async setup(props, modelurl) {
                propsG    = props || {};
                sessionId = props.sessionid || 0;
                const cfg = {
                    enablefullscreen: props.enablefullscreen,
                    enabletabdetect:  props.enabletabdetect,
                    enablekeyblock:   props.enablekeyblock,
                    enableclipboard:  props.enableclipboard,
                };

                if (checkMobileBlock()) { return false; }

                await loadStrings();

                if (document.getElementById('page-mod-quiz-summary') ||
                    document.getElementById('page-mod-quiz-review')) {
                    sessionStorage.removeItem(LS_FS_KEY);
                    return false;
                }

                const width = props.image_width || 230;
                let height  = 0;
                let streaming = false;

                injectScoreWidget();
                injectNoiseMeter();
                injectLmsChatDisabler();

                // Webcam box (fixed top-right, draggable).
                $('body').append(`
                    <div class="learnshield-fixed-webcam-box" id="ls-webcam-box">
                        <video id="ls-video" autoplay playsinline>
                            ${STRINGS.videonotavailable || 'Video unavailable'}
                        </video>
                        <img id="ls-cropimg" src="" alt="" style="display:none;"/>
                        <canvas id="ls-canvas" style="display:none;"></canvas>
                        <div class="learnshield-webcam-label">🔒 Learn Shield</div>
                    </div>`);

                // Draggable webcam box.
                const box = document.getElementById('ls-webcam-box');
                let drag = false, ox = 0, oy = 0;
                box.addEventListener('mousedown', e => { drag = true; ox = e.clientX - box.offsetLeft; oy = e.clientY - box.offsetTop; });
                document.addEventListener('mousemove', e => {
                    if (!drag) { return; }
                    box.style.left   = (e.clientX - ox) + 'px';
                    box.style.top    = (e.clientY - oy) + 'px';
                    box.style.right  = 'auto';
                    box.style.bottom = 'auto';
                });
                document.addEventListener('mouseup', () => { drag = false; });

                const video   = document.getElementById('ls-video');
                const canvas  = document.getElementById('ls-canvas');

                // Load face-api models.
                if (modelurl) {
                    try {
                        // eslint-disable-next-line no-undef
                        await faceapi.nets.ssdMobilenetv1.loadFromUri(modelurl);
                    } catch (e) { console.warn('face-api load failed'); }
                }

                // Snapshot function.
                const takePicture = async() => {
                    if (!width || !height) { return; }
                    canvas.width  = width;
                    canvas.height = height;
                    canvas.getContext('2d').drawImage(video, 0, 0, width, height);
                    const imagedata = canvas.toDataURL('image/jpeg', 0.8);

                    let faceFound = 0, faceImage = '';
                    if (modelurl) {
                        try {
                            // eslint-disable-next-line no-undef
                            const det = await faceapi.detectAllFaces(canvas);
                            if (det.length > 0) {
                                const b = det[0].box;
                                // eslint-disable-next-line no-undef
                                const faces = await faceapi.extractFaces(canvas,
                                    // eslint-disable-next-line no-undef
                                    [new faceapi.Rect(b.x, b.y, b.width, b.height)]);
                                if (faces.length > 0) { faceImage = faces[0].toDataURL(); faceFound = 1; }
                            } else {
                                logEvent('face_missing', {}, 'warning');
                                Notification.addNotification({
                                    message: STRINGS.facenotfoundoncam || '⚠️ Face not visible.',
                                    type: 'warning',
                                });
                            }
                        } catch (e) { console.warn('face detect error', e); }
                    }

                    Ajax.call([{
                        methodname: 'quizaccess_learnshield_send_camshot',
                        args: {
                            courseid:      props.courseid,
                            screenshotid:  props.id,
                            quizid:        props.quizid || props.cmid,
                            webcampicture: imagedata,
                            imagetype:     1,
                            parenttype:    'camshot_image',
                            faceimage:     faceImage,
                            facefound:     faceFound,
                        },
                    }])[0].fail(function(e) { console.warn('camshot failed', e); });
                };

                // Start camera.
                navigator.mediaDevices.getUserMedia({video: true, audio: false})
                    .then(function(stream) {
                        video.srcObject = stream;
                        video.play();
                    })
                    .catch(function() {
                        $('.mod_quiz-next-nav').prop('disabled', true);
                        $('.submitbtns').html(
                            `<p class="text-danger font-weight-bold">
                            ${STRINGS.enable_web_camera_before_submitting || 'Please enable webcam.'}
                            </p>`
                        );
                    });

                video.addEventListener('canplay', function() {
                    if (!streaming) {
                        height = video.videoHeight / (video.videoWidth / width);
                        if (isNaN(height)) { height = width / (4 / 3); }
                        video.setAttribute('width',  width);
                        video.setAttribute('height', height);
                        canvas.setAttribute('width',  width);
                        canvas.setAttribute('height', height);
                        streaming = true;
                    }
                });

                // Start all security controls.
                setupFullscreen(cfg);
                setupTabDetection(cfg);
                setupKeyboard(cfg);
                setupClipboard(cfg);
                disableContextMenu();
                disableDictionary();

                // Connection + recovery.
                injectConnectionWidget();
                setupReconnectionRecovery(props);

                // Additional monitoring.
                detectMultipleMonitors();
                startVoiceMonitoring();
                if (modelurl) {
                    startEyeTracking(video, modelurl);
                    startLivenessMonitoring(video, modelurl);
                }

                // Snapshot schedule.
                setTimeout(takePicture, 3000);
                setInterval(takePicture, props.camshotdelay || 30000);

                // AI detection.
                lsInitAudioClipRecording(props);
                lsInitObjectDetection(props, video);
                lsInitBackgroundChangeDetection(video);
                if (modelurl) { lsInitFaceIdentityMatching(video, modelurl); }

                // Poll proctor chat messages.
                if (sessionId) {
                    let lastMsgTs = 0;
                    setInterval(function() {
                        Ajax.call([{
                            methodname: 'quizaccess_learnshield_get_messages',
                            args: {sessionid: sessionId, since: lastMsgTs},
                        }])[0].done(function(res) {
                            (res.messages || []).forEach(function(msg) {
                                if (msg.timemodified <= lastMsgTs) { return; }
                                lastMsgTs = msg.timemodified;
                                Notification.addNotification({
                                    message: (msg.message_type === 'warning' ? '📢 Proctor: ' : '💬 ') + msg.message,
                                    type: msg.message_type === 'warning' ? 'error' : 'info',
                                });
                            });
                        });
                    }, 5000);
                }

                return true;
            },

            /**
             * init() — quiz VIEW page only. No-op.
             */
            async init() {
                return true;
            },
        };
    });
