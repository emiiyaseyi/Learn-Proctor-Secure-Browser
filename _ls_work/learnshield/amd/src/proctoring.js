// Learn Shield V1 — Main proctoring module (active during quiz attempt)
//
// setup() → runs on the quiz attempt page.
//   - Enforces fullscreen immediately (blocking overlay until entered).
//   - Detects tab switching, keyboard shortcuts, clipboard, right-click.
//   - Takes webcam snapshots at configured intervals.
//   - Monitors audio, eye position, liveness, and multiple monitors.
//
// init() → no-op on the quiz VIEW page (before attempt starts).
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
            if (el.requestFullscreen)            return el.requestFullscreen();
            if (el.webkitRequestFullscreen)      return el.webkitRequestFullscreen();
            if (el.mozRequestFullScreen)         return el.mozRequestFullScreen();
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

            // Show blocker immediately on page load.
            showFsBlocker();

            // Try auto-enter (works in Chrome after recent same-origin fullscreen).
            enterFS().then(hideFsBlocker).catch(function() {
                // Blocker stays — student must click the "Return to Fullscreen" button.
            });

            // fullscreenchange fires AFTER the fullscreen state changes (~100-300ms).
            const onFsChange = function() {
                if (!fsIsActive()) {
                    showFsBlocker();                  // Appears immediately.
                    logEvent('fullscreen_exit', {}, 'warning');
                } else {
                    hideFsBlocker();
                }
            };
            document.addEventListener('fullscreenchange',       onFsChange);
            document.addEventListener('webkitfullscreenchange', onFsChange);
            document.addEventListener('mozfullscreenchange',    onFsChange);

            // visibilitychange fires BEFORE fullscreenchange — use it for < 1 s response.
            // When the user switches away, the tab becomes hidden AND fullscreen exits.
            // Show the blocker immediately on visibility change, not waiting for fs event.
            document.addEventListener('visibilitychange', function() {
                if (document.hidden && !fsIsActive()) {
                    // Pre-emptively show blocker — they've already left.
                }
                if (!document.hidden && !fsIsActive()) {
                    // User returned to tab but is no longer in fullscreen.
                    showFsBlocker(); // < 1 second: fires as soon as they switch back.
                }
            });

            // Intercept Alt+F4 / window close attempts (partial — can't fully block).
            window.addEventListener('blur', function() {
                if (!fsIsActive()) {
                    // Slight delay to let fullscreenchange fire first.
                    setTimeout(function() {
                        if (!fsIsActive()) { showFsBlocker(); }
                    }, 50); // 50 ms — well under 1 second.
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
                        url:        window.location.href, // Same-origin info only.
                    }, 'warning');
                    Notification.addNotification({
                        message: STRINGS.tab_switch_warning || '⚠️ Leaving the exam tab has been logged.',
                        type: 'warning',
                    });
                } else {
                    // Returned to exam tab — log how long they were away.
                    if (awayStart > 0) {
                        const seconds = Math.round((Date.now() - awayStart) / 1000);
                        logEvent('tab_return', {
                            away_seconds: seconds,
                            count:        awayCount,
                        }, 'warning');
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

            // Warn on attempt to navigate away.
            window.addEventListener('beforeunload', function(e) {
                logEvent('page_unload', {url: window.location.href}, 'warning');
                // Some browsers show the message, others don't.
                e.preventDefault();
                e.returnValue = 'Leaving this page will be logged. Are you sure?';
                return e.returnValue;
            });
        }

        // -----------------------------------------------------------------------
        // Keyboard — block harmful shortcuts; allow ALL typing keys
        // (students must be able to type answers, press space, etc.)
        // -----------------------------------------------------------------------
        function setupKeyboard(cfg) {
            if (!cfg.enablekeyblock) { return; }

            document.addEventListener('keydown', function(e) {
                const k   = e.key;
                const ctrl = e.ctrlKey || e.metaKey;
                const alt  = e.altKey;

                // Block F12.
                if (k === 'F12') { e.preventDefault(); log('F12'); return; }

                // Block PrintScreen.
                if (k === 'PrintScreen') { e.preventDefault(); log('PrintScreen'); return; }

                // Block Ctrl/Cmd + dangerous combos (but NOT Ctrl+A for text selection in answers).
                if (ctrl && !alt) {
                    const blocked = {
                        'c': 'Ctrl+C', 'v': 'Ctrl+V',
                        'u': 'Ctrl+U', 'p': 'Ctrl+P',
                        's': 'Ctrl+S',
                    };
                    const lower = k.toLowerCase();
                    if (blocked[lower]) {
                        e.preventDefault();
                        log(blocked[lower]);
                        return;
                    }
                }

                // Block Alt+Tab (does not always work, OS-level, but log it).
                if (alt && k === 'Tab') {
                    e.preventDefault();
                    logEvent('tab_switch', {reason: 'alt_tab'}, 'warning');
                }

                // Do NOT block Space, Enter, Arrow keys, letters, numbers —
                // students need these to answer quiz questions.
            }, true); // Capture phase so quiz cannot swallow the event.

            function log(key) {
                logEvent('keyboard_violation', {key}, 'warning');
                Notification.addNotification({
                    message: (STRINGS.keyboard_violation_warning || '⚠️ Restricted shortcut blocked.') +
                             ' (' + key + ')',
                    type: 'warning',
                });
            }
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
            } catch (e) { /* Not supported in all browsers */ }
        }

        // -----------------------------------------------------------------------
        // Voice / audio monitoring
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
                        const now   = Date.now();
                        if (level > 15 && now - lastLog > 30000) {
                            lastLog = now;
                            logEvent('audio_detected', {level: Math.round(level)}, 'info');
                        }
                    }, 1500);
                })
                .catch(function() { /* Mic unavailable — skip silently */ });
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
                        } catch (e) { /* Silently skip */ }
                    }, 3000);
                }).catch(function() { /* Landmark model unavailable */ });
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
            // Apply to all current inputs.
            document.querySelectorAll('input[type="text"],input[type="search"],textarea,[contenteditable="true"]')
                .forEach(applyToEl);
            // Watch for dynamically added inputs (quiz pagination renders new questions).
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

                // Network Information API (Chrome).
                if ('connection' in navigator) {
                    const c = navigator.connection;
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
                    // Fallback: measure response time.
                    const start = Date.now();
                    fetch(window.location.origin + '/favicon.ico?t=' + start, {cache: 'no-store'})
                        .then(function() {
                            const ms = Date.now() - start;
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
            setInterval(updateConnection, 15000); // Check every 15 s.

            window.addEventListener('online',  function() { updateConnection(); });
            window.addEventListener('offline', function() { updateConnection(); });

            if ('connection' in navigator) {
                navigator.connection.addEventListener('change', updateConnection);
            }
        }

        // -----------------------------------------------------------------------
        // Reconnection recovery
        // -----------------------------------------------------------------------
        function setupReconnectionRecovery(props) {
            // Persist attempt state to sessionStorage for crash recovery.
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
        // Adaptive low-bandwidth mode
        // -----------------------------------------------------------------------
        function adaptCamshotQuality(canvas, width) {
            // Monitor connection quality and reduce canvas resolution if weak.
            let quality = 1.0; // 1.0 = full quality, 0.5 = half.
            if ('connection' in navigator) {
                const c = navigator.connection;
                if (c.effectiveType === 'slow-2g') { quality = 0.3; }
                else if (c.effectiveType === '2g') { quality = 0.5; }
                else if (c.effectiveType === '3g') { quality = 0.75; }
            }
            return quality;
        }

        // -----------------------------------------------------------------------
        // Emergency pause polling (check server every 30 s during exam)
        // -----------------------------------------------------------------------
        function startEmergencyPausePolling() {
            setInterval(function() {
                Ajax.call([{
                    methodname: 'quizaccess_learnshield_get_alerts',
                    args: {courseid: propsG.courseid || 0, since: 0},
                }])[0].done(function(res) {
                    // Check if there's a system-level emergency event.
                    // The server-side prevent_access() handles this before quiz loads,
                    // but we also poll to catch in-flight pauses.
                });
            }, 30000);
        }

        // -----------------------------------------------------------------------
        // Mobile device detection (client-side reinforcement)
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
                return true; // Blocked.
            }
            return false;
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

                // Block mobile devices at the client level (server also blocks via prevent_access()).
                if (checkMobileBlock()) { return false; }

                await loadStrings();

                // Quiz finished — clear fullscreen requirement flag.
                if (document.getElementById('page-mod-quiz-summary') ||
                    document.getElementById('page-mod-quiz-review')) {
                    sessionStorage.removeItem(LS_FS_KEY);
                    return false;
                }

                const width = props.image_width || 230;
                let height  = 0;
                let streaming = false;

                injectScoreWidget();

                // Webcam box.
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
                const cropimg = document.getElementById('ls-cropimg');

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
                    const imagedata = canvas.toDataURL('image/png');

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

                // Security controls.
                disableDictionary();
                disableContextMenu();

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
             * init() — quiz VIEW page only. No camera, no monitoring. Pure no-op.
             */
            async init() {
                return true;
            },
        };
    });
