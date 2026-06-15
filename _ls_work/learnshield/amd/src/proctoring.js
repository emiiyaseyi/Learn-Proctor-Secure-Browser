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
        // Toast messages for each violation type.
        var LS_VIOLATION_TOASTS = {
            'fullscreen_exit':        ['⚠️ Fullscreen exited — return to fullscreen to continue.', 'warning'],
            'tab_switch':             ['⚠️ Tab/window switch detected and logged.', 'warning'],
            'keyboard_violation':     ['🔒 Restricted shortcut blocked and logged.', 'warning'],
            'clipboard_copy':         ['⚠️ Copy action blocked and logged.', 'warning'],
            'clipboard_paste':        ['⚠️ Paste action blocked and logged.', 'warning'],
            'face_missing':           ['⚠️ Face not visible on webcam — please face the camera.', 'danger'],
            'multiple_monitors':      ['⚠️ Multiple monitors detected — this has been logged.', 'warning'],
            'object_detected_cell_phone': ['🚨 Mobile phone detected in your exam area!', 'danger'],
            'object_detected_book':   ['⚠️ Book/material detected in frame — remove it.', 'warning'],
            'object_detected_laptop': ['🚨 Additional laptop detected in frame!', 'danger'],
            'multiple_persons_detected': ['🚨 Multiple people detected in frame!', 'danger'],
            'background_change':      ['⚠️ Sudden background change detected.', 'warning'],
            'audio_clip_started':     ['🎤 Loud noise detected — audio clip saved.', 'warning'],
            'face_identity_mismatch': ['🚨 Identity mismatch detected — invigilator notified.', 'danger'],
            'camera_covered':         ['⚠️ Camera appears covered or blocked!', 'danger'],
            'no_blink_detected':      ['⚠️ No blink detected in 2 minutes — are you still there?', 'warning'],
            'network_offline':        ['⚠️ Internet connection lost! Reconnect to continue.', 'danger'],
            'screenshot_attempt':     ['⚠️ Screenshot attempt blocked and logged.', 'warning'],
            'devtools_open':          ['⚠️ Developer tools detected — this has been logged.', 'danger'],
        };

        // Friendly labels for the learner-facing flagged-issues panel
        // (no point values shown there beyond what updateFlaggedPanel renders).
        var LS_FLAG_LABELS = {
            'tab_switch':                 'Tab / window switch detected',
            'tab_return':                 'Returned from another tab/window',
            'window_blur':                'Window lost focus',
            'fullscreen_exit':            'Fullscreen exited',
            'keyboard_violation':         'Blocked key pressed',
            'clipboard_copy':             'Copy attempt blocked',
            'clipboard_paste':            'Paste attempt blocked',
            'face_missing':               'Face not visible on webcam',
            'multiple_monitors':          'Multiple monitors detected',
            'object_detected_cell_phone': 'Mobile phone detected',
            'object_detected_book':       'Book/material detected',
            'object_detected_laptop':     'Additional laptop detected',
            'multiple_persons_detected':  'Multiple people in frame',
            'multiple_faces_detected':    'Multiple faces detected on webcam',
            'background_change':          'Background change detected',
            'audio_clip_started':         'Loud noise detected — audio clip recorded',
            'face_identity_mismatch':     'Face identity mismatch',
            'camera_covered':             'Camera appears covered',
            'no_blink_detected':          'No blink detected for 2 minutes',
            'network_offline':            'Internet connection lost',
            'screenshot_attempt':         'Screenshot attempt blocked',
            'devtools_open':              'Developer tools opened',
        };

        // Local list of flagged items shown to the candidate during the attempt.
        var flaggedLog = [];

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
                // Add to learner-visible flagged-issues panel (skip routine
                // 'info' telemetry that carries no score impact).
                if (severity !== 'info' || res.scoreimpact > 0) {
                    flaggedLog.push({
                        time:     new Date().toLocaleTimeString(),
                        label:    LS_FLAG_LABELS[type] || type.replace(/_/g, ' '),
                        severity: severity || 'info',
                        score:    res.scoreimpact || 0,
                    });
                    updateFlaggedPanel();
                }
                // Show toast to learner for notable violations.
                var toastInfo = LS_VIOLATION_TOASTS[type];
                if (toastInfo && (severity === 'warning' || severity === 'high' || severity === 'medium')) {
                    showToast(toastInfo[0], toastInfo[1], 5000);
                }
            })
            .fail(function(e) { console.warn('LS log failed', e); });
        }

        // -----------------------------------------------------------------------
        // Flagged-items panel (learner-visible list of proctoring flags, mirrors
        // mod_learnguard's #lg-flagged-panel for parity).
        // -----------------------------------------------------------------------
        function injectFlaggedPanel() {
            if (document.getElementById('ls-flagged-panel')) { return; }
            $('body').append(`
                <button id="ls-flagged-toggle" onclick="window.lsToggleFlagged()"
                    style="position:fixed;bottom:20px;left:20px;z-index:9998;
                    background:#fff3cd;color:#856404;border:2px solid #ffc107;
                    border-radius:10px;padding:8px 14px;cursor:pointer;font-size:13px;
                    font-weight:600;box-shadow:0 2px 8px rgba(0,0,0,.15);">
                    ⚠️ Flags
                    <span id="ls-flagged-count" style="display:none;position:absolute;top:-8px;right:-8px;
                        background:#dc3545;color:#fff;border-radius:50%;width:18px;height:18px;font-size:10px;
                        font-weight:700;line-height:18px;text-align:center;">0</span>
                </button>
                <div id="ls-flagged-panel" style="position:fixed;bottom:64px;left:20px;z-index:9998;
                    max-height:0;overflow:hidden;width:320px;transition:max-height .3s ease;
                    border:2px solid #ffc107;border-radius:10px;background:#fffbf0;
                    box-shadow:0 4px 16px rgba(0,0,0,.2);">
                    <div style="padding:10px 14px;">
                        <div style="font-size:12px;font-weight:700;color:#856404;margin-bottom:6px;">
                            ⚠️ Proctoring flags recorded this session — your proctor can see all of these:
                        </div>
                        <div id="ls-flagged-list" style="max-height:160px;overflow-y:auto;"></div>
                    </div>
                </div>`);

            let flaggedOpen = false;
            window.lsToggleFlagged = function() {
                flaggedOpen = !flaggedOpen;
                const fp = document.getElementById('ls-flagged-panel');
                if (fp) { fp.style.maxHeight = flaggedOpen ? '220px' : '0'; }
            };
        }

        function updateFlaggedPanel() {
            const panel = document.getElementById('ls-flagged-panel');
            if (!panel) { return; }
            const badge = document.getElementById('ls-flagged-count');
            if (badge) { badge.textContent = flaggedLog.length; badge.style.display = 'inline-block'; }
            const list = document.getElementById('ls-flagged-list');
            if (!list) { return; }
            list.innerHTML = '';
            flaggedLog.slice(-10).reverse().forEach(function(item) {
                const col = (item.severity === 'high' || item.severity === 'danger') ? '#dc3545'
                    : (item.severity === 'medium' || item.severity === 'warning') ? '#fd7e14' : '#6c757d';
                const row = document.createElement('div');
                row.style.cssText = 'display:flex;gap:8px;align-items:flex-start;padding:6px 0;border-bottom:1px solid #f0f0f0;';
                row.innerHTML = '<span style="font-size:10px;color:#6c757d;white-space:nowrap;">' + item.time + '</span>' +
                    '<span style="flex:1;font-size:12px;color:#212529;">' + item.label + '</span>' +
                    (item.score > 0 ? '<span style="font-size:11px;font-weight:700;color:' + col + ';">+' + item.score + 'pts</span>' : '');
                list.appendChild(row);
            });
        }

        // Track 50% warning state so it only fires once.
        var lsHalfWarningShown = false;

        function updateScoreWidget() {
            const el  = document.getElementById('ls-risk-score');
            const bar = document.getElementById('ls-risk-bar');
            if (el)  { el.textContent = sessionScore; }
            if (bar) {
                const pct  = Math.min(sessionScore, 100);
                bar.style.width  = pct + '%';
                const level = sessionScore >= 60 ? 'high' : sessionScore >= 30 ? 'medium' : 'low';
                // "Low" risk shown red (not green) and the bar blinks at all
                // levels — a constant visible-monitoring reminder rather than
                // a "you're safe" green signal. Historical reports keep the
                // green .learnshield-score-bar.low colour unchanged.
                bar.className = 'learnshield-score-bar learnshield-risk-blink ' + level;
                bar.style.background = (level === 'low') ? '#dc3545' : '';
            }
            // Show 50% threshold warning once.
            const suspendThreshold = propsG.scoreconfig && propsG.scoreconfig.high_threshold
                ? propsG.scoreconfig.high_threshold : 80;
            const halfThreshold = Math.floor(suspendThreshold * 0.5);
            if (!lsHalfWarningShown && sessionScore >= halfThreshold) {
                lsHalfWarningShown = true;
                showHalfThresholdWarning(sessionScore, suspendThreshold);
            }
            checkAutoSuspend(sessionScore);
        }

        function showHalfThresholdWarning(current, threshold) {
            var existing = document.getElementById('ls-threshold-warning-modal');
            if (existing) { existing.remove(); }

            // Use parseInt to ensure values are safe integers (prevents XSS via crafted score values).
            var safeScore = parseInt(current, 10) || 0;
            var safeThold = parseInt(threshold, 10) || 80;

            var modal = document.createElement('div');
            modal.id = 'ls-threshold-warning-modal';
            modal.style.cssText = 'position:fixed;inset:0;z-index:2147483646;background:rgba(0,0,0,0.85);' +
                'display:flex;align-items:center;justify-content:center;font-family:system-ui,sans-serif;';
            modal.innerHTML =
                '<div style="background:#fff;border-radius:14px;padding:32px 36px;max-width:480px;width:92%;' +
                'text-align:center;box-shadow:0 16px 48px rgba(0,0,0,0.5);border-top:6px solid #fd7e14;">' +
                '<div style="font-size:52px;margin-bottom:12px;">⚠️</div>' +
                '<h2 style="font-size:20px;font-weight:800;color:#dc3545;margin-bottom:10px;">Integrity Warning</h2>' +
                '<p style="font-size:15px;color:#495057;margin-bottom:8px;">' +
                'Your integrity risk score is now <strong style="color:#fd7e14;">' + safeScore + ' points</strong>. ' +
                'You have passed the <strong>halfway mark</strong> toward the suspension threshold of <strong>' + safeThold + ' points</strong>.' +
                '</p>' +
                '<p style="font-size:14px;color:#6c757d;margin-bottom:20px;">' +
                'If your score reaches <strong>' + safeThold + ' points</strong>, your exam will be ' +
                '<strong style="color:#dc3545;">automatically and permanently suspended</strong>. ' +
                'This action cannot be reversed.' +
                '</p>' +
                '<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:12px 16px;' +
                'text-align:left;font-size:13px;margin-bottom:20px;">' +
                '<strong>To avoid suspension:</strong><br>' +
                '• Stay in fullscreen mode<br>' +
                '• Keep your face visible in the camera<br>' +
                '• Do not switch tabs or applications<br>' +
                '• Stay quiet and alone in the room' +
                '</div>' +
                '<button id="ls-threshold-dismiss" style="background:#0f6cbf;color:#fff;border:none;' +
                'border-radius:8px;padding:12px 32px;font-size:15px;font-weight:700;cursor:pointer;width:100%;">' +
                'I Understand — Continue Exam' +
                '</button></div>';
            document.body.appendChild(modal);

            document.getElementById('ls-threshold-dismiss').addEventListener('click', function() {
                modal.remove();
            });
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
                    showSuspendedOverlay();
                }
            });
        }

        // -----------------------------------------------------------------------
        // Suspended overlay — shown when the session is suspended (auto or by a
        // proctor). Polls every 20s for reinstatement, then offers a resume
        // button. Mirrors mod_learnguard's showSuspendedScreen().
        // -----------------------------------------------------------------------
        const SUSPENDED_OVERLAY_ID = 'ls-suspended-overlay';

        function showSuspendedOverlay() {
            if (document.getElementById(SUSPENDED_OVERLAY_ID)) { return; }

            const overlay = document.createElement('div');
            overlay.id = SUSPENDED_OVERLAY_ID;
            overlay.style.cssText = [
                'position:fixed', 'inset:0', 'z-index:2147483647',
                'background:#dc3545', 'color:#fff',
                'display:flex', 'flex-direction:column',
                'align-items:center', 'justify-content:center',
                'text-align:center', 'padding:40px',
                'font-family:system-ui,sans-serif',
            ].join(';');
            const courseurl = ((typeof M !== 'undefined' && M.cfg && M.cfg.wwwroot) ? M.cfg.wwwroot : '') +
                '/course/view.php?id=' + (propsG.courseid || 0);
            overlay.innerHTML =
                '<div style="font-size:72px;margin-bottom:20px;">⚠️</div>' +
                '<h2 style="font-size:26px;margin-bottom:12px;">Exam Suspended</h2>' +
                '<p style="font-size:16px;opacity:.85;max-width:480px;margin-bottom:20px;">' +
                'Your exam has been suspended due to excessive integrity violations. ' +
                'Please contact your proctor or institution.</p>' +
                '<div id="ls-sus-status" style="background:rgba(255,255,255,.15);border-radius:10px;' +
                'padding:14px 18px;margin-bottom:20px;font-size:13px;max-width:480px;">' +
                '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#ffc107;' +
                'margin-right:8px;animation:learnshield-pulse 1.5s infinite;"></span>' +
                'Checking for reinstatement… This page checks automatically every 20 seconds.' +
                '</div>' +
                '<a href="' + courseurl + '" ' +
                'style="background:#fff;color:#dc3545;border-radius:8px;text-decoration:none;' +
                'padding:11px 22px;font-size:14px;font-weight:700;">← Back to Course</a>';
            document.body.appendChild(overlay);

            // Poll every 20 seconds — if a proctor reinstates the session, offer a resume button.
            var susCheckTimer = setInterval(function() {
                if (!sessionId) { return; }
                Ajax.call([{
                    methodname: 'quizaccess_learnshield_get_session_status',
                    args: {sessionid: sessionId},
                }])[0].done(function(res) {
                    if (!res || res.suspended) { return; }
                    clearInterval(susCheckTimer);
                    var statusEl = document.getElementById('ls-sus-status');
                    if (!statusEl) { return; }
                    statusEl.style.background = 'rgba(40,167,69,.25)';
                    statusEl.innerHTML = '✅ <strong>Your suspension has been lifted!</strong>' +
                        '<p style="margin:8px 0 12px;font-size:13px;">Click below to return to your exam.</p>' +
                        '<div style="background:rgba(255,255,255,.2);border:1px solid #fff;border-radius:8px;' +
                        'padding:10px 14px;margin:0 0 14px;font-size:13px;font-weight:700;">' +
                        '⚠️ Warning: any further violation will result in final suspension.</div>' +
                        '<button id="ls-resume-btn" style="background:#28a745;color:#fff;border:none;border-radius:10px;' +
                        'padding:14px 32px;font-size:15px;font-weight:700;cursor:pointer;">▶ Resume Exam</button>';
                    var btn = document.getElementById('ls-resume-btn');
                    if (btn) {
                        btn.addEventListener('click', function() { location.reload(); });
                    }
                }).fail(function() {});
            }, 20000);
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
        // Toast notifications (shown to learner for violations)
        // -----------------------------------------------------------------------
        var lsToastContainer = null;
        function showToast(message, type, duration) {
            if (!lsToastContainer) {
                lsToastContainer = document.createElement('div');
                lsToastContainer.style.cssText = 'position:fixed;top:16px;left:50%;transform:translateX(-50%);' +
                    'z-index:2147483646;display:flex;flex-direction:column;gap:8px;max-width:480px;width:90%;pointer-events:none;';
                document.body.appendChild(lsToastContainer);
            }
            var colours = {info:'#0d6efd', success:'#198754', warning:'#fd7e14', danger:'#dc3545', error:'#dc3545'};
            var toast = document.createElement('div');
            toast.style.cssText = 'background:' + (colours[type] || colours.info) +
                ';color:#fff;padding:12px 18px;border-radius:8px;font-size:14px;' +
                'font-family:system-ui,sans-serif;box-shadow:0 4px 16px rgba(0,0,0,.4);' +
                'transition:opacity .3s;pointer-events:auto;text-align:center;font-weight:600;';
            toast.textContent = message;
            lsToastContainer.appendChild(toast);
            setTimeout(function() {
                toast.style.opacity = '0';
                setTimeout(function() { if (toast.parentNode) { toast.parentNode.removeChild(toast); } }, 300);
            }, typeof duration === 'number' ? duration : 4000);
        }

        // -----------------------------------------------------------------------
        // Disable ALL external chat/help widgets — nuclear approach
        // -----------------------------------------------------------------------
        function injectLmsChatDisabler() {
            if (document.getElementById('ls-hide-external-chat')) { return; }

            var chatSels =
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
                '.block_online_users,.block_messages,' +
                '[id*="tawk"],[class*="tawk"],' +
                '[id*="livechat"],[class*="livechat"],' +
                '[id*="chat-widget"],[class*="chat-widget"],[id*="chat_widget"],' +
                '[id*="freshdesk"],[class*="freshdesk"],' +
                '[id*="zendesk"],[class*="zendesk"]';
            var hideProps = '{display:none!important;visibility:hidden!important;' +
                'pointer-events:none!important;z-index:-99999!important;' +
                'width:0!important;height:0!important;max-width:0!important;' +
                'max-height:0!important;opacity:0!important;overflow:hidden!important;}';

            // 1. Nuclear CSS — also targets :fullscreen context so widget can't escape fullscreen stacking.
            var fsSelectors = chatSels.split(',').map(function(s) { return ':fullscreen ' + s.trim(); }).join(',') + ',' +
                chatSels.split(',').map(function(s) { return ':-webkit-full-screen ' + s.trim(); }).join(',') + ',' +
                chatSels.split(',').map(function(s) { return ':-moz-full-screen ' + s.trim(); }).join(',');
            const s = document.createElement('style');
            s.id = 'ls-hide-external-chat';
            s.textContent = chatSels + hideProps + fsSelectors + hideProps;
            document.head.appendChild(s);

            // 2. Override chat APIs.
            window.Tawk_API = window.Tawk_API || {};
            window.Tawk_API.onLoad = function() { try { window.Tawk_API.hideWidget(); } catch(e) {} };
            try { if (window.Tawk_API.hideWidget) { window.Tawk_API.hideWidget(); } } catch(e) {}
            try { if (window.Intercom) { window.Intercom('hide'); } } catch(e) {}
            try { if (window.$crisp) { window.$crisp.push(['do','chat:hide']); } } catch(e) {}
            try { if (window.drift && window.drift.hide) { window.drift.hide(); } } catch(e) {}

            // 3. Interval brute-force.
            const chatSel = '#tawkchat-container,#tawkchat-minified-box,#tawk-bubble,' +
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

            // 4. MutationObserver — removes newly injected nodes.
            const chatPat = /tawk|livechat|chat.widget|intercom|crisp|drift|tidio|freshdesk|zendesk/i;
            const obs = new MutationObserver(function(mutations) {
                mutations.forEach(function(m) {
                    m.addedNodes.forEach(function(node) {
                        if (node.nodeType !== 1) { return; }
                        const id  = node.id    || '';
                        const cls = typeof node.className === 'string' ? node.className : '';
                        const src = node.src   || (node.getAttribute ? node.getAttribute('src') || '' : '');
                        if (chatPat.test(id) || chatPat.test(cls) || chatPat.test(src)) {
                            node.style.setProperty('display',    'none',   'important');
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

        // Flag set in beforeunload so fullscreen-exit caused by quiz navigation is not logged.
        var lsQuizNavigating = false;
        window.addEventListener('beforeunload', function() { lsQuizNavigating = true; });

        // Flag set while a file input dialog is open — file dialogs exit fullscreen
        // in some browsers. Do not flag this as a violation.
        var lsFileDialogOpen = false;
        document.addEventListener('focus', function() {
            // When focus returns to the window, clear the file dialog flag.
            setTimeout(function() { lsFileDialogOpen = false; }, 200);
        }, true);
        document.addEventListener('click', function(e) {
            if (e.target && e.target.type === 'file') {
                lsFileDialogOpen = true;
                // Re-enter fullscreen automatically after dialog closes (focus returns).
                setTimeout(function() {
                    if (!fsIsActive() && sessionStorage.getItem(LS_FS_KEY)) {
                        enterFS().catch(function() {});
                    }
                    lsFileDialogOpen = false;
                }, 3000);
            }
        }, true);

        function setupFullscreen(cfg) {
            if (!cfg.enablefullscreen) { return; }

            sessionStorage.setItem(LS_FS_KEY, '1');
            showFsBlocker();
            enterFS().then(hideFsBlocker).catch(function() {});

            const onFsChange = function() {
                if (!fsIsActive()) {
                    showFsBlocker();
                    // Do not log if caused by page navigation or file dialog.
                    if (!lsQuizNavigating && !lsFileDialogOpen) {
                        logEvent('fullscreen_exit', {}, 'warning');
                        showToast('⚠️ Fullscreen exited — this has been logged. Click "Return to Fullscreen" to continue.', 'warning', 5000);
                    }
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

            // Flag set when the learner clicks Moodle's own in-quiz navigation
            // (Previous/Next page, Finish attempt, question navigation panel).
            // These cause a full page reload/visibility change but are normal
            // exam navigation, not abandonment — must not be logged as a
            // violation or trigger the "leave page?" prompt below.
            let lsInternalNav = false;
            document.addEventListener('click', function(e) {
                if (e.target && e.target.closest &&
                    e.target.closest('#mod_quiz_navblock, .submitbtns, .mod_quiz-next-nav, .mod_quiz-prev-nav')) {
                    lsInternalNav = true;
                }
            }, true);

            document.addEventListener('visibilitychange', function() {
                if (document.hidden) {
                    if (lsInternalNav) { return; }
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
                if (lsInternalNav) { return; }
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
                        <div id="ls-risk-bar" class="learnshield-score-bar learnshield-risk-blink low" style="width:0%;background:#dc3545"></div>
                    </div>
                    <div style="text-align:right;font-size:11px;color:#6c757d;margin-top:2px;">
                        <span id="ls-risk-score">0</span> pts
                    </div>
                </div>`);
        }

        // -----------------------------------------------------------------------
        // Per-learner schedule countdown ("Window closes in")
        // -----------------------------------------------------------------------
        // Purely informational — Moodle's own quiz timer already enforces the
        // {quiz_overrides} timeclose set via schedule.php. This badge mirrors the
        // "Window closes in" indicator shown in mod_learnguard during a live attempt.
        function injectScheduleCountdown(props) {
            if (!props.timeclose || props.timeclose <= 0) { return; }
            if (document.getElementById('ls-schedule-countdown')) { return; }

            $('body').append(`
                <div id="ls-schedule-countdown" title="Your scheduled window closes at this time"
                     style="position:fixed;top:10px;left:50%;transform:translateX(-50%);
                     background:rgba(0,0,0,0.7);color:#fff;padding:6px 16px;border-radius:20px;
                     font-size:12px;font-weight:700;font-variant-numeric:tabular-nums;
                     z-index:9999;white-space:nowrap;">
                    ⏳ Window closes in: --:--:--
                </div>`);

            const serverOffset = (props.servertime || Math.floor(Date.now() / 1000)) - Math.floor(Date.now() / 1000);
            const nowTs = () => Math.floor(Date.now() / 1000) + serverOffset;

            function pad(n) { return n < 10 ? '0' + n : String(n); }
            function fmt(s) {
                if (s < 0) { s = 0; }
                const d = Math.floor(s / 86400);
                const h = Math.floor((s % 86400) / 3600);
                const m = Math.floor((s % 3600) / 60);
                const sec = s % 60;
                const hms = pad(h) + ':' + pad(m) + ':' + pad(sec);
                return d > 0 ? (d + 'd ' + hms) : hms;
            }

            const badge = document.getElementById('ls-schedule-countdown');
            let warned5min = false;
            let warned1min = false;

            setInterval(function() {
                const remaining = props.timeclose - nowTs();
                if (remaining <= 0) {
                    badge.textContent  = '⏳ Window closes in: 00:00:00';
                    badge.style.background = 'rgba(220,53,69,0.85)';
                    return;
                }
                badge.textContent = '⏳ Window closes in: ' + fmt(remaining);
                if (remaining <= 60) {
                    badge.style.background = 'rgba(220,53,69,0.85)';
                } else if (remaining <= 300) {
                    badge.style.background = 'rgba(253,126,20,0.8)';
                } else {
                    badge.style.background = 'rgba(0,0,0,0.7)';
                }
                if (!warned5min && remaining <= 300 && remaining > 60) {
                    warned5min = true;
                    Notification.addNotification({
                        message: '⏱ Your scheduled window closes in 5 minutes!',
                        type: 'warning',
                    });
                }
                if (!warned1min && remaining <= 60) {
                    warned1min = true;
                    Notification.addNotification({
                        message: '⏱ Your scheduled window closes in 1 minute!',
                        type: 'error',
                    });
                }
            }, 1000);
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
                            // eslint-disable-next-line no-undef
                    const det = await faceapi.detectAllFaces(video, new faceapi.TinyFaceDetectorOptions()).withFaceLandmarks(true);
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
            let lastMultiFaceLog = 0;

            // Dedicated fast face-presence check (every 1s) — independent of
            // the 5s blink/gaze/multi-face loop below. Detects "no face"
            // within ~1s for a responsive learner-facing flag, but the
            // score/flag itself is debounced to once per 30s so a continued
            // absence doesn't rack up hundreds of points (and trigger
            // near-instant auto-suspend).
            let lastFaceMissingLog = 0;
            setInterval(async function() {
                if (!video) { return; }
                // Camera blocked/denied/disconnected — no stream to analyse at
                // all. Treat this the same as "no face in frame" so it keeps
                // getting flagged every 30s instead of going silently unmonitored.
                if (video.readyState < 2) {
                    const nowCb = Date.now();
                    if (nowCb - lastFaceMissingLog > 30000) {
                        lastFaceMissingLog = nowCb;
                        logEvent('face_missing', {reason: 'camera_unavailable'}, 'warning');
                        showToast('⚠️ ' + (STRINGS.facenotfoundoncam || 'Face not visible — please face the camera.'), 'warning', 6000);
                    }
                    return;
                }
                try {
                    // eslint-disable-next-line no-undef
                    const det = await faceapi.detectAllFaces(video, new faceapi.TinyFaceDetectorOptions());
                    if (det.length) { return; }
                    const nowFm = Date.now();
                    if (nowFm - lastFaceMissingLog > 30000) {
                        lastFaceMissingLog = nowFm;
                        logEvent('face_missing', {}, 'warning');
                        showToast('⚠️ ' + (STRINGS.facenotfoundoncam || 'Face not visible — please face the camera.'), 'warning', 6000);
                    }
                } catch (e) { /* Skip */ }
            }, 1000);

            setInterval(async function() {
                if (!video || video.readyState < 2) { return; }
                try {
                    // eslint-disable-next-line no-undef
                    // eslint-disable-next-line no-undef
                    const det = await faceapi.detectAllFaces(video, new faceapi.TinyFaceDetectorOptions()).withFaceLandmarks(true);
                    if (!det.length) { return; }
                    if (det.length > 1) {
                        const nowMf = Date.now();
                        if (nowMf - lastMultiFaceLog > 30000) {
                            lastMultiFaceLog = nowMf;
                            logEvent('multiple_faces_detected', {count: det.length}, 'high');
                            showToast('🚫 Multiple faces detected on webcam!', 'danger', 8000);
                        }
                    }
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
                    var THRESHOLD = 8; var highCount = 0; var TRIGGER = 4;

                    setInterval(function() {
                        if (lsAudioClipRecording) { return; }
                        var data = new Uint8Array(analyser.frequencyBinCount);
                        analyser.getByteFrequencyData(data);
                        var level = Math.round(data.reduce(function(a, b) { return a + b; }, 0) / data.length);
                        if (level > THRESHOLD) {
                            highCount++;
                            // 10s cooldown — just prevents overlap with the 8s
                            // recording itself; every sustained noise crossing
                            // gets its own scored event.
                            if (highCount >= TRIGGER && Date.now() - lsAudioClipLastTime > 10000) {
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
        // Object detection — YOLOv5n via ONNX Runtime Web (bundled, open-source)
        // Falls back to COCO-SSD if ONNX Runtime not available.
        // -----------------------------------------------------------------------

        // COCO class names index → human-readable (subset we care about).
        var LS_YOLO_CLASSES = [
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

        var LS_YOLO_VIOLATIONS = {
            'cell phone': {sev: 'high',   sc: 25, event: 'object_detected_cell_phone'},
            'book':       {sev: 'medium', sc: 10, event: 'object_detected_book'},
            'laptop':     {sev: 'high',   sc: 20, event: 'object_detected_laptop'},
            'keyboard':   {sev: 'medium', sc: 10, event: 'object_detected_keyboard'},
            'remote':     {sev: 'low',    sc: 5,  event: 'object_detected_remote'},
        };

        var lsOrtSession = null;
        var lsTfModel    = null;

        function lsInitObjectDetection(props, videoEl) {
            // Try ONNX YOLOv5n first (bundled, open-source, no CDN needed).
            var ortUrl = propsG.onnxurl || null;
            if (typeof ort !== 'undefined' && ortUrl) {
                setTimeout(function() {
                    ort.InferenceSession.create(ortUrl + '/yolov5n.onnx', {
                        executionProviders: ['wasm'],
                        graphOptimizationLevel: 'all',
                    }).then(function(session) {
                        lsOrtSession = session;
                        setInterval(function() { lsRunYoloDetection(videoEl); }, 25000);
                    }).catch(function(e) {
                        console.warn('LS ONNX failed, falling back to COCO-SSD', e);
                        lsLoadCocoSsd(videoEl);
                    });
                }, 8000);
            } else {
                // Fallback: COCO-SSD from CDN.
                lsLoadCocoSsd(videoEl);
            }
        }

        // Use 416x416 instead of 640x640 — 2.4x faster inference, still accurate enough.
        var LS_YOLO_SIZE = 416;

        function lsRunYoloDetection(videoEl) {
            if (!lsOrtSession || !videoEl || videoEl.readyState < 2) { return; }
            var sz = LS_YOLO_SIZE;
            var canvas = document.createElement('canvas');
            canvas.width = sz; canvas.height = sz;
            canvas.getContext('2d').drawImage(videoEl, 0, 0, sz, sz);
            var data = canvas.getContext('2d').getImageData(0, 0, sz, sz).data;
            var inputTensor = new Float32Array(3 * sz * sz);
            for (var i = 0; i < sz * sz; i++) {
                inputTensor[i]           = data[i * 4]     / 255.0;
                inputTensor[i + sz * sz]     = data[i * 4 + 1] / 255.0;
                inputTensor[i + 2 * sz * sz] = data[i * 4 + 2] / 255.0;
            }
            var tensor = new ort.Tensor('float32', inputTensor, [1, 3, sz, sz]);
            lsOrtSession.run({images: tensor}).then(function(output) {
                var preds = output[Object.keys(output)[0]].data;
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
                    var cls = (LS_YOLO_CLASSES[classId] || '').toLowerCase();
                    if (cls === 'person') { personCount++; continue; }
                    var viol = LS_YOLO_VIOLATIONS[cls];
                    if (viol) {
                        logEvent(viol.event, {class: cls, confidence: Math.round(score * 100)}, viol.sev);
                        showToast('⚠️ ' + cls.charAt(0).toUpperCase() + cls.slice(1) + ' detected in exam area!',
                            viol.sev === 'high' ? 'danger' : 'warning', 6000);
                    }
                }
                if (personCount > 1) {
                    logEvent('multiple_persons_detected', {count: personCount}, 'high');
                    showToast('🚨 Multiple people detected in frame!', 'danger', 8000);
                }
            }).catch(function() {});
        }

        function lsLoadCocoSsd(videoEl) {
            if (typeof cocoSsd === 'undefined') { return; }
            setTimeout(function() {
                cocoSsd.load().then(function(model) {
                    lsTfModel = model;
                    setInterval(function() {
                        if (!lsTfModel || !videoEl || videoEl.readyState < 2) { return; }
                        lsTfModel.detect(videoEl).then(function(preds) {
                            var personCount = 0;
                            preds.forEach(function(pred) {
                                if (pred.score < 0.35) { return; }
                                var cls = pred.class.toLowerCase();
                                if (cls === 'person') { personCount++; return; }
                                var viol = LS_YOLO_VIOLATIONS[cls];
                                if (viol) {
                                    logEvent(viol.event, {class: cls, confidence: Math.round(pred.score * 100)}, viol.sev);
                                    showToast('⚠️ ' + cls + ' detected!', viol.sev === 'high' ? 'danger' : 'warning', 6000);
                                }
                            });
                            if (personCount > 1) {
                                logEvent('multiple_persons_detected', {count: personCount}, 'high');
                                showToast('🚨 Multiple people detected!', 'danger', 8000);
                            }
                        }).catch(function() {});
                    }, 25000);
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
        // Screen capture — captures screen frames every 15s from the shared stream
        // -----------------------------------------------------------------------
        var lsScreenStream = null;

        function lsInitScreenCapture(props) {
            // Request screen share (user already did this in precheck, but stream is gone after page reload).
            navigator.mediaDevices.getDisplayMedia({video: {displaySurface: 'monitor'}, audio: false})
                .then(function(stream) {
                    lsScreenStream = stream;
                    var track = stream.getVideoTracks()[0];
                    // Stop capture if learner stops sharing.
                    track.addEventListener('ended', function() {
                        lsScreenStream = null;
                        logEvent('screen_share_stopped', {}, 'warning');
                    });

                    // Capture a frame immediately, then every 15s.
                    lsCaptureScreen(props);
                    setInterval(function() { lsCaptureScreen(props); }, 15000);
                })
                .catch(function() {
                    // Screen capture optional — exam can continue without it.
                    console.warn('LS: screen capture not available');
                });
        }

        function lsCaptureScreen(props) {
            if (!lsScreenStream || !lsScreenStream.active) { return; }
            var track = lsScreenStream.getVideoTracks()[0];
            if (!track || track.readyState !== 'live') { return; }

            var vid = document.createElement('video');
            vid.srcObject = lsScreenStream;
            vid.muted = true;
            vid.addEventListener('loadedmetadata', function() {
                vid.play().then(function() {
                    requestAnimationFrame(function() {
                        var w = Math.min(vid.videoWidth  || 1920, 1280);
                        var h = Math.min(vid.videoHeight || 720,  720);
                        var c = document.createElement('canvas');
                        c.width = w; c.height = h;
                        c.getContext('2d').drawImage(vid, 0, 0, w, h);
                        var imagedata = c.toDataURL('image/jpeg', 0.6);
                        vid.pause(); vid.srcObject = null;
                        Ajax.call([{
                            methodname: 'quizaccess_learnshield_send_camshot',
                            args: {
                                courseid:      props.courseid,
                                screenshotid:  sessionId || 0,
                                quizid:        props.quizid || props.cmid,
                                webcampicture: imagedata,
                                imagetype:     4,       // Screen capture type.
                                parenttype:    'screen_capture',
                                faceimage:     '',
                                facefound:     0,
                            },
                        }]);
                    });
                }).catch(function() {});
            });
            vid.load();
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
                // eslint-disable-next-line no-undef
            faceapi.detectSingleFace(videoEl, new faceapi.TinyFaceDetectorOptions()).withFaceLandmarks(true).withFaceDescriptor()
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

                // Load face-api and ONNX runtime dynamically (non-blocking, no head injection).
                function loadScriptAsync(src) {
                    return new Promise(function(resolve) {
                        if (!src) { resolve(); return; }
                        if (document.querySelector('script[src="' + src + '"]')) { resolve(); return; }
                        var s = document.createElement('script');
                        s.src = src; s.async = true;
                        s.onload = resolve; s.onerror = resolve;
                        document.head.appendChild(s);
                    });
                }
                if (props.faceapiurl) { await loadScriptAsync(props.faceapiurl); }
                // Awaited (not fire-and-forget) so `ort` is defined by the time
                // lsInitObjectDetection() runs its synchronous `typeof ort` check below.
                if (props.onnxurl)    { await loadScriptAsync(props.onnxurl + '/ort.min.js'); }

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
                injectScheduleCountdown(props);
                injectFlaggedPanel();

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

                // Load face-api models (TinyFaceDetector = fast + small; faceRecognition for identity matching).
                if (modelurl) {
                    try {
                        // eslint-disable-next-line no-undef
                        await Promise.all([
                            faceapi.nets.tinyFaceDetector.loadFromUri(modelurl),
                            faceapi.nets.faceLandmark68TinyNet.loadFromUri(modelurl),
                            faceapi.nets.faceRecognitionNet.loadFromUri(modelurl),
                        ]);
                    } catch (e) { console.warn('face-api load failed', e); }
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
                            const det = // eslint-disable-next-line no-undef
                        await faceapi.detectAllFaces(canvas, new faceapi.TinyFaceDetectorOptions());
                            if (det.length > 0) {
                                const b = det[0].box;
                                // eslint-disable-next-line no-undef
                                const faces = await faceapi.extractFaces(canvas,
                                    // eslint-disable-next-line no-undef
                                    [new faceapi.Rect(b.x, b.y, b.width, b.height)]);
                                if (faces.length > 0) { faceImage = faces[0].toDataURL(); faceFound = 1; }
                            }
                            // face_missing is now flagged by the dedicated 1s
                            // face-presence check in startLivenessMonitoring()
                            // (debounced to 30s) — not duplicated here.
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

                // Snapshot schedule — every 15 seconds.
                setTimeout(takePicture, 3000);
                setInterval(takePicture, props.camshotdelay || 15000);

                // Heartbeat — keeps session "live" on proctor dashboard every 60s.
                setInterval(function() {
                    logEvent('session_heartbeat', {}, 'info');
                }, 60000);

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
