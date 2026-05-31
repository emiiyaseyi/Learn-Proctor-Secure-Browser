// Learn Shield — Pre-exam Multi-Step Wizard (15 steps, matches LearnGuard)
// Steps: Welcome → CloseTabs → Browser → Internet → Camera → Audio →
//        PhotoID → Face → ScreenShare → Keystroke → Monitor →
//        Environment360 → Privacy → Fullscreen → Ready
//
// @package    quizaccess_learnshield
// @author     Michael Adeniran <michaeladeniransnr@gmail.com>
// @copyright  2025 Michael Adeniran
// @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

/* eslint-disable no-console */
define(['jquery', 'core/ajax', 'core/notification'],
    function($, Ajax, Notification) {

        let cfg             = {};
        let currentStep     = 0;
        let cameraStream    = null;
        let screenStream    = null;
        // Track precheck notes/flags to show in the summary step.
        const precheckNotes = [];
        let audioCtx        = null;
        let audioAnalyser   = null;
        let faceApiLoaded   = false;
        let passportDescriptor = null;
        let keystrokeBaseline  = [];
        let faceApprovalId  = 0;
        let faceApprovalPoll = null;

        const PRIVACY_POLICY = `LEARN SHIELD — PRIVACY & DATA PROTECTION NOTICE

This exam is proctored by Learn Shield. By proceeding, you agree to the following:

DATA COLLECTED
• Webcam snapshots taken at regular intervals during your exam
• A 360° environment recording before the exam starts
• Your photo ID captured during identity verification
• Audio activity levels (volume only — not recorded as audio file)
• Browser events: tab switches, keyboard shortcuts, fullscreen exits
• Session metadata: IP address, browser type, operating system
• Face detection results and integrity risk score
• Typing pattern baseline (keystroke timing only, not content)

PURPOSE
All data is collected solely to maintain academic integrity and verify your identity.

STORAGE
All data is stored within your institution's Moodle server. No data is shared with third parties unless your institution has configured an external storage provider.

RETENTION
• Webcam snapshots: 14 days
• Environment recordings: 7 days
• Event logs: 180 days
• Flagged evidence: 90 days

YOUR RIGHTS
Contact your institution's administrator to request deletion of your proctoring data.

CONSENT
Proceeding constitutes your explicit consent to the above data processing.`;

        // -----------------------------------------------------------------------
        // Step definitions — 15 steps matching LearnGuard
        // -----------------------------------------------------------------------
        function getSteps() {
            return [
                { id: 'welcome',     icon: '🎓', title: 'Welcome to Your Proctored Exam' },
                { id: 'closetabs',   icon: '🗂️', title: 'Close All Other Apps & Tabs' },
                { id: 'browser',     icon: '🌐', title: 'Browser Compatibility' },
                { id: 'internet',    icon: '📡', title: 'Internet Connection' },
                { id: 'camera',      icon: '📷', title: 'Camera / Webcam' },
                { id: 'audio',       icon: '🎤', title: 'Microphone (Audio)' },
                { id: 'photoid',     icon: '🪪', title: 'Identity Verification (Photo ID)' },
                { id: 'face',        icon: '👤', title: 'Face Detection & Liveness' },
                { id: 'screenshare', icon: '🖥',  title: 'Screen Sharing (Monitoring)' },
                { id: 'keystroke',   icon: '⌨️', title: 'Typing Pattern Baseline' },
                { id: 'monitor',     icon: '🖥',  title: 'Single Screen Required' },
                { id: 'environment', icon: '🔭', title: 'Environment Recording (360°)' },
                { id: 'summary',     icon: '📋', title: 'Precheck Summary' },
                { id: 'privacy',     icon: '🔒', title: 'Privacy & Data Protection' },
                { id: 'fullscreen',  icon: '⛶',  title: 'Fullscreen Mode' },
                { id: 'ready',       icon: '✅', title: 'Ready to Begin' },
            ];
        }

        // -----------------------------------------------------------------------
        // Report wizard step progress to server (proctor sees this in real-time)
        // -----------------------------------------------------------------------
        function reportPrecheck(stepId, stepNumber, totalSteps) {
            if (!cfg.cmid) { return; }
            Ajax.call([{
                methodname: 'quizaccess_learnshield_report_precheck',
                args: { cmid: cfg.cmid || 0, step: stepId, stepnumber: stepNumber, totalsteps: totalSteps },
            }]);
        }

        // -----------------------------------------------------------------------
        // Overlay DOM
        // -----------------------------------------------------------------------
        function buildOverlay() {
            if (document.getElementById('ls-wizard-overlay')) { return; }
            const overlay = document.createElement('div');
            overlay.id    = 'ls-wizard-overlay';
            overlay.style.cssText = 'position:fixed;inset:0;z-index:999999;background:rgba(15,15,30,.97);' +
                'display:flex;align-items:center;justify-content:center;font-family:system-ui,sans-serif;';
            overlay.innerHTML = `
                <div id="ls-wizard-box" style="background:#fff;border-radius:12px;width:min(660px,96vw);
                     max-height:90vh;overflow:hidden;display:flex;flex-direction:column;
                     box-shadow:0 8px 40px rgba(0,0,0,.6);">
                    <div style="background:#0f6cbf;color:#fff;padding:18px 24px;
                                display:flex;align-items:center;gap:12px;flex-shrink:0;">
                        <div style="font-size:28px" id="ls-wiz-icon">🔒</div>
                        <div>
                            <div style="font-size:18px;font-weight:700" id="ls-wiz-title">Learn Shield Setup</div>
                            <div style="font-size:12px;opacity:.85" id="ls-wiz-subtitle">Secure exam preparation</div>
                        </div>
                        <div style="margin-left:auto;font-size:12px;opacity:.8" id="ls-wiz-stepcount"></div>
                    </div>
                    <div style="height:4px;background:#cde">
                        <div id="ls-wiz-progress-bar" style="height:100%;background:#0f6cbf;transition:width .4s;width:0%"></div>
                    </div>
                    <div id="ls-wiz-body" style="flex:1;overflow-y:auto;padding:24px"></div>
                    <div style="padding:14px 24px;border-top:1px solid #eee;display:flex;
                                justify-content:space-between;align-items:center;flex-shrink:0;">
                        <button id="ls-wiz-back" class="btn btn-outline-secondary btn-sm" style="display:none;">← Back</button>
                        <div id="ls-wiz-status" style="font-size:13px;color:#6c757d;flex:1;padding:0 10px;"></div>
                        <button id="ls-wiz-next" class="btn btn-primary" style="min-width:130px">Continue →</button>
                    </div>
                </div>`;
            document.body.appendChild(overlay);
            document.getElementById('ls-wiz-next').addEventListener('click', handleNext);
            document.getElementById('ls-wiz-back').addEventListener('click', handleBack);
        }

        // -----------------------------------------------------------------------
        // Render
        // -----------------------------------------------------------------------
        function renderStep(index) {
            const steps  = getSteps();
            const step   = steps[index];
            const total  = steps.length;

            document.getElementById('ls-wiz-icon').textContent      = step.icon;
            document.getElementById('ls-wiz-title').textContent     = step.title;
            document.getElementById('ls-wiz-stepcount').textContent = `Step ${index + 1} of ${total}`;
            document.getElementById('ls-wiz-progress-bar').style.width =
                Math.round(((index + 1) / total) * 100) + '%';

            const body   = document.getElementById('ls-wiz-body');
            const next   = document.getElementById('ls-wiz-next');
            const back   = document.getElementById('ls-wiz-back');
            const status = document.getElementById('ls-wiz-status');
            body.innerHTML    = '';
            status.textContent = '';
            next.disabled     = false;
            next.textContent  = index === steps.length - 1 ? '🚀 Start Exam Now' : 'Continue →';
            back.style.display = index > 0 ? 'inline-block' : 'none';

            switch (step.id) {
                case 'welcome':     body.innerHTML = renderWelcome(); break;
                case 'closetabs':   body.innerHTML = renderCloseTabsStep(); next.disabled = true; hookCloseTabsConfirm(); break;
                case 'browser':     body.innerHTML = renderAutoCheck('browser'); runBrowserCheck(); break;
                case 'internet':    body.innerHTML = renderAutoCheck('internet'); runInternetCheck(); break;
                case 'camera':      body.innerHTML = renderCameraStep(); next.disabled = true; runCameraCheck(); break;
                case 'audio':       body.innerHTML = renderAudioStep(); next.disabled = true; runAudioCheck(); break;
                case 'photoid':     body.innerHTML = renderPhotoidStep(); next.disabled = true; runPhotoidCheck(); break;
                case 'face':        body.innerHTML = renderFaceStep(); next.disabled = true; runFaceCheck(); break;
                case 'screenshare': body.innerHTML = renderScreenshareStep(); next.disabled = true; runScreenshareCheck(); break;
                case 'keystroke':   body.innerHTML = renderKeystrokeStep(); next.disabled = true; runKeystrokeCheck(); break;
                case 'monitor':     body.innerHTML = renderMonitorStep(); break;
                case 'environment': body.innerHTML = renderEnvironmentStep(); next.disabled = true; runEnvironmentRecording(); break;
                case 'summary':     body.innerHTML = renderSummaryStep(); break;
                case 'privacy':     body.innerHTML = renderPrivacyStep(); next.disabled = true; hookPrivacyAccept(); break;
                case 'fullscreen':  body.innerHTML = renderFullscreenStep(); next.disabled = true; setTimeout(hookFsBtn, 100); break;
                case 'ready':       body.innerHTML = renderReadyStep(); break;
            }

            // Report to server on every step (proctor sees real-time progress).
            reportPrecheck(step.id, index + 1, total);
        }

        // -----------------------------------------------------------------------
        // Helper: result box
        // -----------------------------------------------------------------------
        function checkResult(pass, msg) {
            const [bg, border, color, icon] = pass
                ? ['#d4edda', '#c3e6cb', '#155724', '✅']
                : ['#fff3cd', '#ffc107', '#856404', '⚠️'];
            return `<div style="background:${bg};border:1px solid ${border};border-radius:6px;padding:10px 14px;color:${color};font-size:14px;margin-top:8px;">${icon} ${msg}</div>`;
        }

        function lsBadge(icon, label) {
            return `<span style="background:#fff;border:1px solid #dee2e6;border-radius:20px;padding:4px 10px;font-size:12px;font-weight:600;color:#495057;white-space:nowrap;">${icon} ${label}</span>`;
        }

        function escHtml(s) {
            return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        function getBrowserName() {
            const ua = navigator.userAgent;
            if (ua.includes('Firefox')) return 'Firefox';
            if (ua.includes('Edg'))     return 'Edge';
            if (ua.includes('Chrome'))  return 'Chrome';
            if (ua.includes('Safari'))  return 'Safari';
            return 'Browser';
        }

        function setStepDone() { document.getElementById('ls-wiz-next').disabled = false; }

        // -----------------------------------------------------------------------
        // STEP: Welcome
        // -----------------------------------------------------------------------
        function renderWelcome() {
            const inst = cfg.instructions
                ? `<div style="background:#f0f4ff;border-left:4px solid #0f6cbf;padding:12px 16px;border-radius:4px;margin:14px 0;">
                       <strong>📋 Exam Instructions</strong><br><span style="white-space:pre-line">${escHtml(cfg.instructions)}</span>
                   </div>` : '';
            return `
            <div style="text-align:center;margin-bottom:16px;">
                <div style="font-size:52px;margin-bottom:8px;">🛡</div>
                <h3 style="margin:0 0 4px;font-size:18px;">Welcome to Your Proctored Exam</h3>
                <p style="color:#6c757d;font-size:13px;margin:0;">Powered by Learn Shield — AI-assisted integrity verification</p>
            </div>
            ${inst}
            <div style="background:#f0f4ff;border-radius:8px;padding:14px 16px;margin-bottom:14px;">
                <strong style="font-size:14px;color:#0f6cbf;">ℹ️ What is the Pre-Check?</strong>
                <p style="margin:6px 0 0;font-size:13px;color:#495057;line-height:1.6;">
                    Before your exam starts, we run automated checks to verify your identity, confirm your
                    environment is suitable, and set up monitoring. This takes <strong>3–7 minutes</strong>.
                    Each step must pass before you can proceed to the next.
                </p>
            </div>
            <div style="background:#d4edda;border-radius:8px;padding:14px 16px;margin-bottom:14px;">
                <strong style="font-size:14px;color:#155724;">✅ What You Need Before Starting</strong>
                <ul style="margin:8px 0 0;padding-left:18px;font-size:13px;color:#155724;line-height:2;">
                    <li>📷 A working <strong>webcam</strong> (built-in or external)</li>
                    <li>🎤 A working <strong>microphone</strong></li>
                    <li>🌐 A stable <strong>internet connection</strong></li>
                    <li>🪪 A valid <strong>photo ID</strong> — national ID, passport, driver's licence, or official employee/staff ID card</li>
                    <li>🤫 A <strong>quiet, private room</strong> — no other people visible</li>
                    <li>💡 <strong>Good lighting</strong> — face must be clearly visible</li>
                    <li>💻 A <strong>desktop or laptop</strong> — mobile phones not allowed</li>
                </ul>
            </div>
            <strong style="font-size:13px;color:#6c757d;text-transform:uppercase;letter-spacing:.5px;">Steps you will complete:</strong>
            <div style="display:flex;flex-wrap:wrap;gap:6px;margin:8px 0 14px;">
                ${lsBadge('🗂','Close Tabs')}
                ${lsBadge('🌐','Browser')}
                ${lsBadge('📡','Internet')}
                ${lsBadge('📷','Camera')}
                ${lsBadge('🎤','Microphone')}
                ${lsBadge('🪪','Photo ID')}
                ${lsBadge('👤','Face Check')}
                ${lsBadge('🖥','Screen Share')}
                ${lsBadge('⌨️','Typing')}
                ${lsBadge('🖥','Monitor')}
                ${lsBadge('🔭','360° Record')}
                ${lsBadge('🔒','Privacy')}
                ${lsBadge('⛶','Fullscreen')}
                ${lsBadge('✅','Begin Exam')}
            </div>
            <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:12px 16px;font-size:13px;">
                <strong>⚠️ During the exam these are continuously active:</strong><br>
                <span style="color:#856404;">Fullscreen enforcement • Webcam snapshots every 15s • Tab-switch detection •
                Key-blocking • Clipboard monitoring • Audio monitoring • Object detection (phone/book) •
                Face identity matching • Screen sharing</span>
            </div>`;
        }

        // -----------------------------------------------------------------------
        // STEP: Close Tabs
        // -----------------------------------------------------------------------
        function renderCloseTabsStep() {
            return `<p style="margin-bottom:16px;font-size:15px;">Before the exam begins, <strong>close all other browser tabs, windows, and applications</strong> on your computer.</p>
                <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:20px;">
                    <div style="display:flex;align-items:flex-start;gap:12px;padding:14px 16px;background:#fff3cd;border:1px solid #ffc107;border-radius:10px;">
                        <span style="font-size:24px;flex-shrink:0;">🗂</span>
                        <div><strong>Close all other browser tabs</strong>
                        <p style="margin:4px 0 0;font-size:13px;color:#6c757d;">Only this exam tab should be open. Use Ctrl+W (Windows) or Cmd+W (Mac).</p></div>
                    </div>
                    <div style="display:flex;align-items:flex-start;gap:12px;padding:14px 16px;background:#fff3cd;border:1px solid #ffc107;border-radius:10px;">
                        <span style="font-size:24px;flex-shrink:0;">💻</span>
                        <div><strong>Close all other applications</strong>
                        <p style="margin:4px 0 0;font-size:13px;color:#6c757d;">Close messaging apps, email clients, music players, document editors.</p></div>
                    </div>
                    <div style="display:flex;align-items:flex-start;gap:12px;padding:14px 16px;background:#fff3cd;border:1px solid #ffc107;border-radius:10px;">
                        <span style="font-size:24px;flex-shrink:0;">📱</span>
                        <div><strong>Put your phone away</strong>
                        <p style="margin:4px 0 0;font-size:13px;color:#6c757d;">Place your phone face-down or out of reach. Using it during the exam is a violation.</p></div>
                    </div>
                    <div style="display:flex;align-items:flex-start;gap:12px;padding:14px 16px;background:#fff3cd;border:1px solid #ffc107;border-radius:10px;">
                        <span style="font-size:24px;flex-shrink:0;">🔕</span>
                        <div><strong>Disable notifications</strong>
                        <p style="margin:4px 0 0;font-size:13px;color:#6c757d;">Turn on Do Not Disturb to prevent pop-ups or sounds during the exam.</p></div>
                    </div>
                </div>
                <div style="background:#d4edda;border:2px solid #28a745;border-radius:10px;padding:16px 18px;">
                    <label style="display:flex;align-items:flex-start;gap:12px;cursor:pointer;">
                        <input type="checkbox" id="ls-closetabs-confirm" style="width:20px;height:20px;margin-top:2px;flex-shrink:0;accent-color:#28a745;">
                        <span style="font-size:14px;font-weight:600;color:#155724;">
                            I confirm I have closed all other tabs, applications and messaging software.
                            My phone is put away and notifications are silenced. I am ready to begin the precheck.
                        </span>
                    </label>
                </div>`;
        }

        function hookCloseTabsConfirm() {
            const chk  = document.getElementById('ls-closetabs-confirm');
            const next = document.getElementById('ls-wiz-next');
            if (!chk) { return; }
            chk.addEventListener('change', function() { next.disabled = !this.checked; });
        }

        // -----------------------------------------------------------------------
        // STEP: Auto checks (browser, internet)
        // -----------------------------------------------------------------------
        function renderAutoCheck(id) {
            return `<div id="ls-check-result-${id}" style="min-height:60px;padding:8px 0">Checking…</div>`;
        }

        function runBrowserCheck() {
            const el = document.getElementById('ls-check-result-browser');
            const issues = [];
            if (!navigator.mediaDevices?.getUserMedia) { issues.push('Camera API not supported'); }
            if (!window.AudioContext && !window.webkitAudioContext) { issues.push('Audio API not supported'); }
            if (typeof fetch === 'undefined') { issues.push('Browser too old — use Chrome, Firefox, or Edge'); }
            if (el) { el.innerHTML = issues.length
                ? checkResult(false, 'Issues: ' + issues.join('. ') + ' You may still continue.')
                : checkResult(true, `Compatible (${getBrowserName()})`); }
            setStepDone();
        }

        function runInternetCheck() {
            const el = document.getElementById('ls-check-result-internet');
            if (!navigator.onLine) { if (el) { el.innerHTML = checkResult(false, 'No internet connection detected!'); } setStepDone(); return; }
            const t0 = Date.now();
            fetch(window.location.origin + '/favicon.ico?t=' + t0, {cache: 'no-store'})
                .then(() => {
                    const ms   = Date.now() - t0;
                    const qual = ms < 200 ? 'Excellent' : ms < 500 ? 'Good' : ms < 1000 ? 'Moderate' : 'Slow';
                    if (el) { el.innerHTML = checkResult(ms < 1500, `${qual} connection (${ms} ms)`); }
                })
                .catch(() => { if (el) { el.innerHTML = checkResult(true, 'Connected (latency unmeasured)'); } })
                .finally(() => setStepDone());
        }

        // -----------------------------------------------------------------------
        // STEP: Camera
        // -----------------------------------------------------------------------
        function renderCameraStep() {
            return `<p>We need webcam access to monitor you during the exam. <strong>Camera is mandatory.</strong></p>
                <div style="text-align:center;margin:12px 0;">
                    <video id="ls-wiz-video" autoplay playsinline muted
                           style="width:100%;max-width:320px;border-radius:8px;border:2px solid #dee2e6;background:#111;"></video>
                </div>
                <div id="ls-camera-status" style="font-size:14px;color:#6c757d;text-align:center;">Requesting camera access…</div>`;
        }

        function runCameraCheck() {
            const statusEl = document.getElementById('ls-camera-status');
            navigator.mediaDevices.getUserMedia({video: {width: 320, height: 240}})
                .then(function(stream) {
                    cameraStream = stream;
                    const video = document.getElementById('ls-wiz-video');
                    if (video) { video.srcObject = stream; video.play(); }
                    if (statusEl) { statusEl.innerHTML = checkResult(true, '✓ Camera is working'); }
                    document.getElementById('ls-wiz-next').disabled = false;
                })
                .catch(function(e) {
                    if (statusEl) { statusEl.innerHTML = checkResult(false,
                        '<strong>Camera access is required for this exam.</strong><br>' +
                        'Please allow camera access in your browser settings, then click Retry.'); }
                    const btn = document.createElement('button');
                    btn.className = 'btn btn-warning mt-2';
                    btn.textContent = '↺ Retry Camera Access';
                    btn.onclick = function() { btn.remove(); if (statusEl) { statusEl.textContent = 'Requesting camera access…'; } runCameraCheck(); };
                    if (statusEl) { statusEl.after(btn); }
                });
        }

        // -----------------------------------------------------------------------
        // STEP: Audio
        // -----------------------------------------------------------------------
        function renderAudioStep() {
            return `<p>Microphone access is required to monitor audio during the exam.
                    <strong>Audio is not recorded</strong> — only volume levels are checked.</p>
                <ul style="color:#555;font-size:13px;margin-bottom:14px;line-height:2;">
                    <li>Stay quiet for 3 seconds (background noise check)</li>
                    <li>Then speak briefly to confirm your mic works</li>
                    <li>Excessive background noise will block access</li>
                </ul>
                <div style="display:flex;align-items:center;gap:10px;margin:14px 0;">
                    <span style="font-size:20px;">🎤</span>
                    <div style="flex:1;background:#e9ecef;border-radius:8px;height:22px;overflow:hidden;">
                        <div id="ls-audio-meter" style="height:100%;background:#28a745;width:0%;transition:width .15s;border-radius:8px;"></div>
                    </div>
                    <span id="ls-audio-label" style="font-size:12px;min-width:80px;">Checking…</span>
                </div>
                <div id="ls-audio-prompt" style="display:none;background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:10px 14px;font-size:14px;margin-bottom:8px;"></div>
                <div id="ls-audio-status" style="font-size:14px;color:#6c757d;">Requesting microphone access…</div>`;
        }

        function runAudioCheck() {
            const statusEl = document.getElementById('ls-audio-status');
            const promptEl = document.getElementById('ls-audio-prompt');
            const meter    = document.getElementById('ls-audio-meter');
            const label    = document.getElementById('ls-audio-label');

            navigator.mediaDevices.getUserMedia({audio: true})
                .then(function(stream) {
                    const AC  = window.AudioContext || window.webkitAudioContext;
                    audioCtx  = new AC();
                    audioAnalyser = audioCtx.createAnalyser();
                    audioAnalyser.fftSize = 512;
                    audioCtx.createMediaStreamSource(stream).connect(audioAnalyser);

                    let bgReadings = [], bgDone = false, spoken = false;
                    if (statusEl) { statusEl.textContent = '🔇 Stay quiet for 3 seconds (background noise check)…'; }

                    const interval = setInterval(function() {
                        const data  = new Uint8Array(audioAnalyser.frequencyBinCount);
                        audioAnalyser.getByteFrequencyData(data);
                        const level = Math.round(data.reduce((a, b) => a + b, 0) / data.length);
                        const pct   = Math.min(level * 4, 100);
                        if (meter) { meter.style.width = pct + '%'; meter.style.background = level > 8 ? '#dc3545' : '#28a745'; }
                        if (label) { label.textContent = level > 8 ? '🔊 Noise' : (level > 3 ? '🔉' : '🔇 Quiet'); }

                        if (!bgDone) {
                            bgReadings.push(level);
                            if (bgReadings.length >= 10) {
                                bgDone = true;
                                const avg = bgReadings.reduce((a, b) => a + b) / bgReadings.length;
                                if (avg > 10) {
                                    clearInterval(interval);
                                    if (statusEl) { statusEl.innerHTML = checkResult(false,
                                        '⚠️ Background noise detected (level: ' + Math.round(avg) + ').<br>' +
                                        'Please move to a quieter location.'); }
                                    const retryBtn = document.createElement('button');
                                    retryBtn.className = 'btn btn-warning mt-2';
                                    retryBtn.textContent = '↺ Retry Audio Check';
                                    retryBtn.onclick = function() { retryBtn.remove(); runAudioCheck(); };
                                    if (statusEl) { statusEl.after(retryBtn); }
                                    return;
                                }
                                if (promptEl) { promptEl.style.display = 'block'; promptEl.textContent = '✓ Quiet confirmed. Now say "I am ready to begin" into your microphone.'; }
                                if (statusEl) { statusEl.textContent = '🎤 Please speak now…'; }
                            }
                        } else if (!spoken && level > 8) {
                            spoken = true;
                            clearInterval(interval);
                            if (statusEl) { statusEl.innerHTML = checkResult(true, '✓ Microphone working and background is quiet.'); }
                            if (promptEl) { promptEl.style.display = 'none'; }
                            document.getElementById('ls-wiz-next').disabled = false;
                        }
                    }, 300);

                    setTimeout(function() {
                        if (!spoken) {
                            clearInterval(interval);
                            const avg = bgReadings.reduce((a, b) => a + b, 0) / (bgReadings.length || 1);
                            if (avg <= 10) {
                                if (statusEl) { statusEl.innerHTML = checkResult(true, 'Microphone connected (no speech detected — ensure your mic works).'); }
                                document.getElementById('ls-wiz-next').disabled = false;
                            }
                        }
                    }, 15000);
                })
                .catch(function() {
                    if (statusEl) { statusEl.innerHTML = checkResult(false, 'Microphone access denied or unavailable. This will be noted by the proctor.'); }
                    document.getElementById('ls-wiz-next').disabled = false;
                });
        }

        // -----------------------------------------------------------------------
        // STEP: Photo ID (hold physical ID to camera + capture)
        // -----------------------------------------------------------------------
        function renderPhotoidStep() {
            return `<p>Hold a <strong>valid photo ID</strong> clearly in front of your camera so your proctor can verify your identity.</p>
                <div style="background:#e8f4f8;border:1px solid #bee5eb;border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:12px;">
                    <strong>Accepted ID types:</strong>
                    <ul style="margin:6px 0 0;padding-left:18px;line-height:2.0;">
                        <li>🪪 <strong>National ID card</strong></li>
                        <li>📘 <strong>International passport</strong></li>
                        <li>🚗 <strong>Driver's licence</strong></li>
                        <li>🏢 <strong>Official employee / staff ID card</strong> <em style="color:#6c757d;font-size:12px;">(for staff/employees only)</em></li>
                    </ul>
                </div>
                <div style="background:#f8f9fa;border:1px solid #dee2e6;border-radius:6px;
                    padding:8px 14px;font-size:13px;margin-bottom:12px;text-align:center;">
                    ✓ Hold ID next to your face &nbsp;|&nbsp; ✓ Name &amp; photo must be readable &nbsp;|&nbsp; ✓ Good lighting
                </div>
                <div id="ls-id-live-wrap" style="text-align:center;margin:10px 0;">
                    <video id="ls-wiz-id-vid" autoplay playsinline muted
                        style="width:100%;max-width:340px;border-radius:8px;border:2px solid #dee2e6;background:#111;display:block;margin:0 auto;"></video>
                </div>
                <div id="ls-id-preview-wrap" style="display:none;text-align:center;margin:10px 0;">
                    <img id="ls-id-preview" src="" alt="Captured ID"
                        style="width:100%;max-width:340px;border-radius:8px;border:3px solid #28a745;"/>
                </div>
                <div style="text-align:center;margin-top:12px;display:flex;justify-content:center;gap:10px;">
                    <button id="ls-capture-id-btn" style="background:#0f6cbf;color:#fff;border:none;border-radius:8px;
                        padding:10px 24px;font-size:15px;cursor:pointer;font-weight:700;">📸 Capture Photo</button>
                    <button id="ls-retake-id-btn" style="display:none;background:#fd7e14;color:#fff;border:none;border-radius:8px;
                        padding:10px 24px;font-size:15px;cursor:pointer;font-weight:700;">↺ Retake</button>
                </div>
                <canvas id="ls-id-canvas" style="display:none;"></canvas>
                <div id="ls-id-status" style="text-align:center;font-size:13px;color:#6c757d;margin-top:10px;">
                    Position your ID and face clearly, then click Capture.</div>`;
        }

        function runPhotoidCheck() {
            const vid        = document.getElementById('ls-wiz-id-vid');
            const statusEl   = document.getElementById('ls-id-status');
            const captureBtn = document.getElementById('ls-capture-id-btn');
            const retakeBtn  = document.getElementById('ls-retake-id-btn');
            const preview    = document.getElementById('ls-id-preview');
            const liveWrap   = document.getElementById('ls-id-live-wrap');
            const prevWrap   = document.getElementById('ls-id-preview-wrap');
            const nextBtn    = document.getElementById('ls-wiz-next');

            function startCamera() {
                if (vid && cameraStream) { vid.srcObject = cameraStream; vid.play().catch(function(){}); }
                else if (vid) {
                    navigator.mediaDevices.getUserMedia({video: true}).then(function(s) {
                        cameraStream = s; vid.srcObject = s; vid.play().catch(function(){});
                    }).catch(function() {
                        if (statusEl) { statusEl.innerHTML = checkResult(false, 'Camera not available.'); }
                        if (nextBtn) { nextBtn.disabled = false; }
                    });
                }
            }
            startCamera();

            function doCapture() {
                const canvas = document.getElementById('ls-id-canvas');
                if (!canvas || !vid) { return; }
                const w = vid.videoWidth || 640; const h = vid.videoHeight || 480;
                if (w < 2 || h < 2) { if (statusEl) { statusEl.textContent = 'Camera not ready — please wait and try again.'; } return; }
                canvas.width = w; canvas.height = h;
                canvas.getContext('2d').drawImage(vid, 0, 0);
                const dataUrl = canvas.toDataURL('image/jpeg', 0.85);

                if (preview) { preview.src = dataUrl; }
                if (liveWrap) { liveWrap.style.display = 'none'; }
                if (prevWrap) { prevWrap.style.display = 'block'; }
                if (captureBtn) { captureBtn.style.display = 'none'; }
                if (retakeBtn)  { retakeBtn.style.display = 'inline-block'; }
                if (statusEl) { statusEl.innerHTML = checkResult(true, 'ID photo captured — click Next to continue or Retake to try again.'); }
                if (nextBtn) { nextBtn.disabled = false; }

                // Upload to server (admin/proctor can see it in gallery).
                Ajax.call([{
                    methodname: 'quizaccess_learnshield_send_camshot',
                    args: {
                        courseid: cfg.courseid || 0, screenshotid: 0, quizid: cfg.cmid || 0,
                        webcampicture: dataUrl, imagetype: 3,
                        parenttype: 'photo_id', faceimage: '', facefound: 0,
                    },
                }])[0].done(function() {
                    Notification.addNotification({message: '✅ Photo ID uploaded successfully.', type: 'success'});
                }).fail(function() {
                    Notification.addNotification({message: '⚠️ Photo ID upload failed — will retry.', type: 'warning'});
                });
            }

            function doRetake() {
                if (liveWrap) { liveWrap.style.display = 'block'; }
                if (prevWrap) { prevWrap.style.display = 'none'; }
                if (captureBtn) { captureBtn.style.display = 'inline-block'; }
                if (retakeBtn)  { retakeBtn.style.display = 'none'; }
                if (statusEl) { statusEl.textContent = 'Position your ID and face clearly, then click Capture.'; }
                if (nextBtn) { nextBtn.disabled = true; }
                startCamera();
            }

            if (captureBtn) { captureBtn.addEventListener('click', doCapture); }
            if (retakeBtn)  { retakeBtn.addEventListener('click', doRetake); }
        }

        // -----------------------------------------------------------------------
        // STEP: Face (passport file upload + live match + liveness)
        // -----------------------------------------------------------------------
        function renderFaceStep() {
            return `<p style="margin-bottom:10px;">Your identity will be verified by matching your passport/ID photo to your live camera feed.</p>

                <div style="border:2px solid #0f6cbf;border-radius:8px;padding:14px;margin-bottom:14px;background:#f0f7ff;">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                        <strong style="font-size:13px;">Step 1 — Upload Passport / ID Photo</strong>
                        <span style="background:#dc3545;color:#fff;font-size:10px;font-weight:700;padding:2px 7px;border-radius:10px;">REQUIRED</span>
                    </div>
                    <p style="font-size:12px;color:#6c757d;margin:0 0 10px;">Upload a clear, recent passport-style photo showing your face clearly.</p>
                    <div style="display:flex;gap:10px;align-items:flex-start;">
                        <div style="flex:1;">
                            <input type="file" id="ls-face-passport-input" accept="image/*"
                                style="width:100%;font-size:13px;padding:4px;border:1px solid #ccc;border-radius:4px;">
                        </div>
                        <img id="ls-face-passport-preview" src="" alt=""
                            style="display:none;width:80px;height:80px;object-fit:cover;border-radius:6px;border:2px solid #28a745;">
                    </div>
                    <div id="ls-passport-status" style="font-size:12px;color:#6c757d;margin-top:8px;">⚠️ Photo not yet uploaded — this is required to continue.</div>
                </div>

                <div id="ls-face-step2" style="border:1px solid #dee2e6;border-radius:8px;padding:14px;background:#f8f9fa;opacity:.5;pointer-events:none;">
                    <strong style="font-size:13px;">Step 2 — Live Face Match</strong>
                    <p style="font-size:12px;color:#6c757d;margin:6px 0 10px;">Look directly at your camera. Good lighting, no glasses or face covering.</p>
                    <div style="display:flex;gap:12px;justify-content:center;align-items:flex-start;flex-wrap:wrap;">
                        <div style="text-align:center;">
                            <video id="ls-wiz-video2" autoplay playsinline muted
                                style="width:180px;border-radius:8px;border:2px solid #dee2e6;background:#111;display:block;"></video>
                            <small style="color:#6c757d;font-size:11px;">Live camera</small>
                        </div>
                        <div style="text-align:center;">
                            <img id="ls-passport-thumb" src="" alt=""
                                style="display:none;width:180px;height:135px;object-fit:cover;border-radius:8px;border:2px solid #0f6cbf;">
                            <small style="color:#6c757d;font-size:11px;">Uploaded passport</small>
                        </div>
                    </div>
                    <div id="ls-face-match-bar" style="margin-top:12px;display:none;">
                        <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
                            <span style="font-size:13px;font-weight:700;">Face Match Score</span>
                            <span id="ls-face-match-pct" style="font-size:20px;font-weight:900;">—</span>
                        </div>
                        <div style="background:#dee2e6;border-radius:10px;height:14px;overflow:hidden;">
                            <div id="ls-face-match-fill" style="height:100%;width:0%;background:#28a745;border-radius:10px;transition:width .5s;"></div>
                        </div>
                        <div id="ls-face-match-msg" style="font-size:12px;margin-top:6px;text-align:center;"></div>
                    </div>
                    <div id="ls-face-status" style="text-align:center;font-size:13px;color:#6c757d;margin-top:8px;"></div>
                    <div id="ls-liveness-prompt" style="display:none;margin-top:10px;text-align:center;background:#fff3cd;padding:10px;border-radius:8px;"></div>
                </div>

                <!-- Manual confirmation / invigilator approval panel -->
                <div id="ls-face-confirm-wrap" style="display:none;margin-top:14px;border:2px solid #fd7e14;border-radius:8px;padding:14px;background:#fff8f0;">
                    <p style="font-size:13px;font-weight:700;color:#dc3545;margin:0 0 8px;">⚠️ Face match inconclusive after 3 attempts.</p>
                    <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;margin-bottom:10px;">
                        <input type="checkbox" id="ls-face-confirm-chk" style="width:18px;height:18px;margin-top:2px;flex-shrink:0;">
                        <span style="font-size:13px;">I confirm my face is clearly visible. I consent to the invigilator reviewing my photo.</span>
                    </label>
                    <div id="ls-face-approval-panel" style="display:none;">
                        <div style="background:#0f6cbf;color:#fff;padding:10px 14px;font-size:13px;font-weight:700;border-radius:6px 6px 0 0;">
                            👤 Invigilator Face Review
                        </div>
                        <div style="padding:14px;border:1px solid #dee2e6;border-top:none;border-radius:0 0 6px 6px;">
                            <div id="ls-approval-status-msg" style="font-size:13px;color:#6c757d;margin-bottom:10px;">
                                ⏳ Waiting for invigilator to review your face…
                            </div>
                            <div id="ls-fa-chat-msgs" style="max-height:120px;overflow-y:auto;display:flex;flex-direction:column;gap:4px;margin-bottom:8px;"></div>
                            <div style="display:flex;gap:6px;">
                                <input type="text" id="ls-fa-chat-inp" placeholder="Message to invigilator…" maxlength="200"
                                    style="flex:1;border:1px solid #dee2e6;border-radius:6px;padding:6px 10px;font-size:12px;">
                                <button id="ls-fa-send-btn"
                                    style="background:#0f6cbf;color:#fff;border:none;border-radius:6px;padding:6px 12px;font-size:12px;cursor:pointer;">
                                    Send
                                </button>
                            </div>
                        </div>
                    </div>
                </div>`;
        }

        async function runFaceCheck() {
            const statusEl    = document.getElementById('ls-face-status');
            const livenessEl  = document.getElementById('ls-liveness-prompt');
            const confirmWrap = document.getElementById('ls-face-confirm-wrap');
            const confirmChk  = document.getElementById('ls-face-confirm-chk');
            const passInput   = document.getElementById('ls-face-passport-input');
            const passPreview = document.getElementById('ls-face-passport-preview');
            const passThumb   = document.getElementById('ls-passport-thumb');
            const passStat    = document.getElementById('ls-passport-status');
            const matchBar    = document.getElementById('ls-face-match-bar');
            const matchPct    = document.getElementById('ls-face-match-pct');
            const matchFill   = document.getElementById('ls-face-match-fill');
            const matchMsg    = document.getElementById('ls-face-match-msg');
            const step2       = document.getElementById('ls-face-step2');
            const video2      = document.getElementById('ls-wiz-video2');
            const nextBtn     = document.getElementById('ls-wiz-next');

            if (nextBtn) { nextBtn.disabled = true; }

            // Wait for face-api.js script to finish loading (it was pre-loaded asynchronously).
            if (cfg.modelurl && typeof faceapi === 'undefined') {
                if (statusEl) { statusEl.textContent = '⏳ Initialising identity verification system…'; }
                let waited = 0;
                while (typeof faceapi === 'undefined' && waited < 12000) {
                    await new Promise(function(r) { setTimeout(r, 400); });
                    waited += 400;
                }
            }

            // Load face-api models once (cached after first load — no re-download on revisit).
            if (cfg.modelurl && !faceApiLoaded) {
                if (statusEl) { statusEl.textContent = '⏳ Loading identity verification models (first time only — ~5s)…'; }
                try {
                    // eslint-disable-next-line no-undef
                    await Promise.all([
                        faceapi.nets.tinyFaceDetector.loadFromUri(cfg.modelurl),
                        faceapi.nets.faceLandmark68TinyNet.loadFromUri(cfg.modelurl),
                        faceapi.nets.faceRecognitionNet.loadFromUri(cfg.modelurl),
                    ]);
                    faceApiLoaded = true;
                } catch (e) {
                    console.warn('face-api load failed — will fall back to manual review', e);
                }
            }

            function unlockStep2() {
                if (step2) { step2.style.opacity = '1'; step2.style.pointerEvents = ''; }
                if (video2 && cameraStream) { video2.srcObject = cameraStream; video2.play().catch(function(){}); }
                else if (video2) {
                    navigator.mediaDevices.getUserMedia({video: true}).then(function(s) {
                        cameraStream = s; video2.srcObject = s; video2.play().catch(function(){});
                    }).catch(function() {});
                }
            }

            let faceMatchAttempts = 0;

            function showFallback(reason) {
                if (confirmWrap) { confirmWrap.style.display = 'block'; }
                if (statusEl) { statusEl.innerHTML = `<div style="font-size:13px;color:#856404;">${reason || '⚠️ Could not complete automatic face match.'}</div>`; }
                // Add note to precheck summary.
                if (!precheckNotes.find(n => n.id === 'face')) {
                    precheckNotes.push({id: 'face', icon: '👤',
                        text: 'Face identity could not be automatically verified. Your proctor will review your photo ID to confirm your identity before the exam begins.'});
                }
            }

            function startLiveMatch() {
                if (!passportDescriptor || !faceApiLoaded) { return; }
                if (matchBar) { matchBar.style.display = 'block'; }
                if (statusEl) { statusEl.textContent = '🔍 Matching your face to the passport photo…'; }
                faceMatchAttempts = 0;

                const matchIv = setInterval(async function() {
                    if (!video2 || video2.readyState < 2) { return; }
                    try {
                        // eslint-disable-next-line no-undef
                        const det = await faceapi.detectSingleFace(video2, new faceapi.TinyFaceDetectorOptions())
                            .withFaceLandmarks(true).withFaceDescriptor();
                        if (!det) { if (statusEl) { statusEl.textContent = '🔍 No face detected — move into camera frame.'; } return; }
                        // eslint-disable-next-line no-undef
                        const dist = faceapi.euclideanDistance(passportDescriptor, det.descriptor);
                        const pct  = Math.max(0, Math.min(100, Math.round((1 - dist) * 100)));
                        if (matchPct)  { matchPct.textContent = pct + '%'; }
                        if (matchFill) { matchFill.style.width = pct + '%'; matchFill.style.background = pct >= 70 ? '#28a745' : pct >= 50 ? '#fd7e14' : '#dc3545'; }

                        if (pct >= 70) {
                            clearInterval(matchIv);
                            if (matchMsg) { matchMsg.innerHTML = `<strong style="color:#28a745;">✅ Identity verified — ${pct}% match.</strong>`; }
                            if (statusEl) { statusEl.innerHTML = checkResult(true, 'Identity verified. Completing liveness check…'); }
                            // Store descriptor for in-exam matching.
                            try { sessionStorage.setItem('ls_face_desc', JSON.stringify(Array.from(det.descriptor))); } catch(e) {}
                            runLivenessCheck(video2, livenessEl, confirmChk, nextBtn);
                        } else {
                            faceMatchAttempts++;
                            const remaining = 3 - faceMatchAttempts;
                            if (matchMsg) { matchMsg.innerHTML = `<strong style="color:#dc3545;">${pct}% — need 70%+</strong>. ${remaining > 0 ? remaining + ' attempt(s) remaining.' : ''}`; }
                            if (faceMatchAttempts >= 3) {
                                clearInterval(matchIv);
                                showFallback('⚠️ Could not automatically verify your identity. Tick below to request invigilator approval.');
                            }
                        }
                    } catch(e) { /* skip frame */ }
                }, 1000);
            }

            // Passport file upload handler.
            if (passInput) {
                passInput.addEventListener('change', function() {
                    const file = this.files[0];
                    if (!file) { return; }
                    const reader = new FileReader();
                    reader.onload = function(ev) {
                        const dataUrl = ev.target.result;
                        if (passPreview) { passPreview.src = dataUrl; passPreview.style.display = 'block'; }
                        if (passThumb)   { passThumb.src   = dataUrl; passThumb.style.display   = 'block'; }
                        if (passStat) { passStat.textContent = '🔍 Processing uploaded photo…'; }
                        unlockStep2();

                        // Upload to server for admin/proctor view.
                        Ajax.call([{
                            methodname: 'quizaccess_learnshield_send_camshot',
                            args: {
                                courseid: cfg.courseid || 0, screenshotid: 0, quizid: cfg.cmid || 0,
                                webcampicture: dataUrl, imagetype: 3,
                                parenttype: 'photo_id', faceimage: '', facefound: 0,
                            },
                        }])[0].done(function() {
                            Notification.addNotification({message: '✅ Passport photo uploaded to server.', type: 'success'});
                        });

                        if (faceApiLoaded && typeof faceapi !== 'undefined') {
                            const img = new Image();
                            img.onload = async function() {
                                try {
                                    // eslint-disable-next-line no-undef
                                    const det = await faceapi.detectSingleFace(img, new faceapi.TinyFaceDetectorOptions())
                                        .withFaceLandmarks(true).withFaceDescriptor();
                                    if (!det) {
                                        if (passStat) { passStat.innerHTML = checkResult(false, '❌ No face detected in uploaded photo. Please use a clear, well-lit passport photo.'); }
                                        return;
                                    }
                                    passportDescriptor = det.descriptor;
                                    if (passStat) { passStat.innerHTML = checkResult(true, '✓ Face extracted from passport. Now look at your camera for Step 2.'); }
                                    startLiveMatch();
                                } catch(e) {
                                    if (passStat) { passStat.innerHTML = checkResult(false, '⚠️ Could not process photo. Try a clearer image.'); }
                                }
                            };
                            img.src = dataUrl;
                        } else {
                            if (passStat) { passStat.innerHTML = checkResult(true, '✓ Photo uploaded — will be reviewed by invigilator.'); }
                            showFallback('Your photo has been uploaded and will be reviewed by your invigilator. Tick the confirmation box below to proceed.');
                        }
                    };
                    reader.readAsDataURL(file);
                });
            }

            if (!faceApiLoaded) {
                if (statusEl) { statusEl.innerHTML =
                    '<div style="background:#d4edda;border:1px solid #c3e6cb;border-radius:6px;padding:10px 14px;font-size:13px;color:#155724;">' +
                    '✓ Identity verification ready. Please upload your photo ID above — ' +
                    'your invigilator will review it to confirm your identity.</div>'; }
            } else {
                if (statusEl) { statusEl.textContent = '✓ Identity verification system ready. Upload your photo ID above to begin.'; }
            }

            // Confirmation checkbox.
            if (confirmChk) {
                confirmChk.addEventListener('change', function() {
                    if (!this.checked) { return; }
                    if (cfg.faceapprovalmode === 'auto' || cfg.faceapprovalmode !== 'manual') {
                        if (nextBtn) { nextBtn.disabled = false; }
                        return;
                    }
                    showFaceApprovalRequest(video2);
                });
            }
        }

        function runLivenessCheck(video2, livenessEl, confirmChk, nextBtn) {
            if (!faceApiLoaded || typeof faceapi === 'undefined') {
                if (confirmChk) { confirmChk.checked = true; }
                if (nextBtn) { nextBtn.disabled = false; }
                return;
            }
            if (livenessEl) { livenessEl.style.display = 'block'; livenessEl.textContent = '👁 Liveness check: please blink your eyes slowly once.'; }
            let prevEar = null;
            const iv = setInterval(async function() {
                if (!video2 || video2.readyState < 2) { return; }
                try {
                    // eslint-disable-next-line no-undef
                    const det = await faceapi.detectAllFaces(video2, new faceapi.TinyFaceDetectorOptions()).withFaceLandmarks(true);
                    if (!det.length) { return; }
                    const lm  = det[0].landmarks;
                    const ear = function(pts) {
                        const h = Math.abs(pts[1].y - pts[5].y) + Math.abs(pts[2].y - pts[4].y);
                        const w = Math.abs(pts[0].x - pts[3].x);
                        return w > 0 ? h / (2 * w) : 1;
                    };
                    const avgEar = (ear(lm.getLeftEye()) + ear(lm.getRightEye())) / 2;
                    if (prevEar !== null && prevEar > 0.2 && avgEar < 0.2) {
                        clearInterval(iv);
                        if (livenessEl) { livenessEl.style.background = '#d4edda'; livenessEl.innerHTML = '✅ <strong>Liveness confirmed</strong> — blink detected.'; }
                        if (confirmChk) { confirmChk.checked = true; confirmChk.disabled = false; }
                        if (nextBtn) { nextBtn.disabled = false; }
                    }
                    prevEar = avgEar;
                } catch(e) { /* skip */ }
            }, 500);
            setTimeout(function() {
                clearInterval(iv);
                if (livenessEl && livenessEl.textContent.includes('blink')) {
                    livenessEl.innerHTML = '⚠️ Liveness inconclusive — tick the checkbox below to confirm.';
                }
                if (confirmChk) { confirmChk.disabled = false; }
            }, 20000);
        }

        // Face approval request (manual mode).
        function showFaceApprovalRequest(video2) {
            const panel     = document.getElementById('ls-face-approval-panel');
            const statusMsg = document.getElementById('ls-approval-status-msg');
            if (!panel) { return; }
            panel.style.display = 'block';

            let passportData = '';
            try {
                const c = document.createElement('canvas');
                c.width = 320; c.height = 240;
                c.getContext('2d').drawImage(video2, 0, 0, 320, 240);
                passportData = c.toDataURL('image/jpeg', 0.8);
            } catch(e) {}

            Ajax.call([{
                methodname: 'quizaccess_learnshield_request_face_approval',
                args: { cmid: cfg.cmid || 0, matchpct: 0, passportdata: passportData },
            }])[0].done(function(res) {
                if (res && res.approvalid) { faceApprovalId = res.approvalid; pollFaceApproval(); }
            }).fail(function() {
                if (statusMsg) { statusMsg.textContent = '⚠️ Could not connect to server. Use the checkbox above to proceed manually.'; }
            });

            // Send button.
            const sendBtn = document.getElementById('ls-fa-send-btn');
            if (sendBtn) {
                sendBtn.addEventListener('click', lsFaSendMsg);
                const inp = document.getElementById('ls-fa-chat-inp');
                if (inp) { inp.addEventListener('keydown', function(e) { if (e.key === 'Enter') { lsFaSendMsg(); } }); }
            }
        }

        function lsFaSendMsg() {
            const inp = document.getElementById('ls-fa-chat-inp');
            if (!inp || !inp.value.trim() || !faceApprovalId) { return; }
            const msg = inp.value.trim();
            inp.value = '';
            Ajax.call([{
                methodname: 'quizaccess_learnshield_face_approval_student_message',
                args: { approvalid: faceApprovalId, message: msg },
            }])[0].done(function() { appendFaMsg(msg, 'student'); });
        }

        function pollFaceApproval() {
            if (faceApprovalPoll) { clearInterval(faceApprovalPoll); }
            const statusEl = document.getElementById('ls-approval-status-msg');
            let lastMsgTs = 0;
            faceApprovalPoll = setInterval(function() {
                if (!faceApprovalId) { return; }
                Ajax.call([{
                    methodname: 'quizaccess_learnshield_check_face_approval',
                    args: { approvalid: faceApprovalId, since: lastMsgTs },
                }])[0].done(function(res) {
                    if (!res) { return; }
                    (res.messages || []).forEach(function(m) {
                        if (m.timecreated > lastMsgTs) { lastMsgTs = m.timecreated; appendFaMsg(m.message, m.sendertype); }
                    });
                    if (res.status === 'approved') {
                        clearInterval(faceApprovalPoll);
                        if (statusEl) { statusEl.innerHTML = '<span style="color:#28a745;font-weight:700;">✅ Approved — you may proceed.</span>'; }
                        const chk = document.getElementById('ls-face-confirm-chk');
                        if (chk) { chk.checked = true; }
                        document.getElementById('ls-wiz-next').disabled = false;
                    } else if (res.status === 'denied') {
                        clearInterval(faceApprovalPoll);
                        if (statusEl) { statusEl.innerHTML = `<span style="color:#dc3545;font-weight:700;">❌ Denied.${res.reviewnote ? ' Reason: ' + res.reviewnote : ''}</span>`; }
                    }
                });
            }, 3000);
        }

        function appendFaMsg(message, sendertype) {
            const box = document.getElementById('ls-fa-chat-msgs');
            if (!box) { return; }
            const isProctor = sendertype === 'proctor';
            const div = document.createElement('div');
            div.style.cssText = 'padding:5px 8px;border-radius:6px;font-size:12px;max-width:90%;' +
                (isProctor ? 'align-self:flex-start;background:#0f6cbf;color:#fff;' : 'align-self:flex-end;background:#e9ecef;color:#212529;');
            div.innerHTML = `<div style="font-size:10px;opacity:.7;">${isProctor ? 'Invigilator' : 'You'}</div>${escHtml(message)}`;
            box.appendChild(div);
            box.scrollTop = box.scrollHeight;
        }

        // -----------------------------------------------------------------------
        // STEP: Screen Share (mandatory full screen)
        // -----------------------------------------------------------------------
        function renderScreenshareStep() {
            return `<p style="margin-bottom:12px;">This assessment requires <strong>screen sharing</strong> so the proctor can verify
                you are only working in the exam window. Your screen will be captured periodically.</p>
                <div id="ls-ss-status" style="background:#f8f9fa;border-radius:8px;padding:16px;text-align:center;margin-bottom:14px;">
                    <div style="font-size:36px;margin-bottom:8px;">🖥</div>
                    <div id="ls-ss-msg" style="font-size:14px;color:#6c757d;">Click the button below to share your screen.</div>
                </div>
                <div style="text-align:center;margin-bottom:12px;">
                    <button id="ls-ss-btn" style="background:#0f6cbf;color:#fff;border:none;border-radius:8px;
                        padding:12px 28px;font-size:15px;font-weight:700;cursor:pointer;">🖥 Share My Screen</button>
                </div>
                <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:10px 14px;font-size:13px;margin-top:8px;">
                    ⚠️ <strong>Screen sharing is mandatory for this proctored exam.</strong>
                    You must share your <strong>entire screen</strong> to proceed. Sharing only a tab or window is not accepted.
                </div>`;
        }

        function runScreenshareCheck() {
            const btn    = document.getElementById('ls-ss-btn');
            const msg    = document.getElementById('ls-ss-msg');
            const box    = document.getElementById('ls-ss-status');
            const nextBtn = document.getElementById('ls-wiz-next');

            if (!btn) { return; }
            btn.addEventListener('click', function() {
                btn.disabled = true; btn.textContent = 'Requesting…';
                if (msg) { msg.textContent = 'Select your ENTIRE SCREEN (not a window or tab)…'; }

                const constraints = {video: {displaySurface: 'monitor'}, audio: false};
                navigator.mediaDevices.getDisplayMedia(constraints)
                    .then(function(stream) {
                        const track = stream.getVideoTracks()[0];
                        const surf  = (track && track.getSettings) ? (track.getSettings().displaySurface || '') : '';
                        if (surf === 'browser' || surf === 'window') {
                            stream.getTracks().forEach(function(t) { t.stop(); });
                            if (box) { box.style.background = '#fff3cd'; }
                            if (msg) { msg.innerHTML = '⚠️ You shared a <strong>browser tab or window</strong>. Please share your <strong>Entire Screen</strong> instead.'; }
                            btn.disabled = false; btn.textContent = '🖥 Try Again';
                            return;
                        }
                        screenStream = stream;
                        track.addEventListener('ended', function() {
                            Notification.addNotification({message: '⚠️ Screen sharing stopped! Please resume to continue.', type: 'error'});
                        });
                        if (box) {
                            box.style.background = '#d4edda';
                            box.innerHTML = '<div style="font-size:36px;margin-bottom:8px;">✅</div>' +
                                '<div style="font-size:14px;color:#155724;font-weight:700;">Full-screen sharing active!</div>' +
                                '<div style="font-size:12px;color:#155724;margin-top:4px;">Your entire screen will be monitored.</div>';
                        }
                        btn.style.display = 'none';
                        if (nextBtn) { nextBtn.disabled = false; }
                    })
                    .catch(function() {
                        if (box) { box.style.background = '#f8d7da'; }
                        if (msg) { msg.innerHTML = '🚫 <strong>Screen sharing was declined.</strong><br>' +
                            'Screen sharing is <strong>mandatory</strong>. Click "Try Again" and select your <strong>Entire Screen</strong>.'; }
                        btn.disabled = false; btn.textContent = '🖥 Try Again';
                    });
            });
        }

        // -----------------------------------------------------------------------
        // STEP: Keystroke (typing pattern baseline)
        // -----------------------------------------------------------------------
        function renderKeystrokeStep() {
            return `<p>Type the sentence below to establish your typing pattern baseline.
                This helps verify your identity during the exam.</p>
                <div style="background:#f0f4ff;border:1px solid #0f6cbf;border-radius:6px;
                    padding:10px 14px;font-size:15px;font-weight:600;margin-bottom:14px;text-align:center;">
                    The quick brown fox jumps over the lazy dog
                </div>
                <textarea id="ls-keystroke-txt" rows="3" style="width:100%;padding:10px;border:2px solid #ccc;
                    border-radius:6px;font-size:15px;" placeholder="Type the sentence above…"
                    spellcheck="false"></textarea>
                <div id="ls-ks-status" style="font-size:13px;color:#6c757d;margin-top:8px;">Type the full sentence to continue.</div>`;
        }

        function runKeystrokeCheck() {
            const txt     = document.getElementById('ls-keystroke-txt');
            const statusEl = document.getElementById('ls-ks-status');
            const nextBtn = document.getElementById('ls-wiz-next');
            const TARGET  = 'The quick brown fox jumps over the lazy dog';
            let lastTime  = 0;
            keystrokeBaseline = [];

            if (!txt) { if (nextBtn) { nextBtn.disabled = false; } return; }

            // Disable paste — must type manually.
            txt.addEventListener('paste', function(e) { e.preventDefault(); if (statusEl) { statusEl.textContent = '⚠️ Paste is disabled. Please type manually.'; } });
            txt.addEventListener('contextmenu', function(e) { e.preventDefault(); });
            txt.addEventListener('drop', function(e) { e.preventDefault(); });

            txt.addEventListener('keydown', function(e) {
                const now = Date.now();
                if (lastTime > 0) { keystrokeBaseline.push({key: e.key, interval: now - lastTime}); }
                lastTime = now;
            });

            txt.addEventListener('input', function() {
                const typed = this.value.trim().toLowerCase();
                const exact = typed === TARGET.toLowerCase();
                if (exact) {
                    if (statusEl) { statusEl.innerHTML = '<span style="color:#28a745;font-weight:600;">✓ Pattern recorded. You may continue.</span>'; }
                    txt.style.borderColor = '#28a745';
                    if (nextBtn) { nextBtn.disabled = false; }
                    // Log baseline to server.
                    if (cfg.cmid && keystrokeBaseline.length > 5) {
                        const avgInterval = Math.round(keystrokeBaseline.reduce(function(a,b){return a+b.interval;},0) / keystrokeBaseline.length);
                        Ajax.call([{
                            methodname: 'quizaccess_learnshield_report_precheck',
                            args: { cmid: cfg.cmid, step: 'keystroke_baseline', stepnumber: 0, totalsteps: 0 },
                        }]);
                        console.log('Keystroke baseline recorded: avg interval=' + avgInterval + 'ms, samples=' + keystrokeBaseline.length);
                    }
                } else {
                    txt.style.borderColor = '#ccc';
                    if (nextBtn) { nextBtn.disabled = true; }
                    const pct = Math.min(100, Math.round((this.value.length / TARGET.length) * 100));
                    if (statusEl) { statusEl.textContent = `Typed ${pct}% — keep going…`; }
                }
            });
        }

        // -----------------------------------------------------------------------
        // STEP: Monitor (single screen check)
        // -----------------------------------------------------------------------
        function renderMonitorStep() {
            const extended = ('screen' in window && window.screen.isExtended);
            const wide     = (window.screen.width > 2560);
            const detected = extended || wide;
            return `<p>Only one screen is permitted during this assessment.</p>
                <div style="background:${detected ? '#f8d7da' : '#d4edda'};border-radius:8px;
                    padding:16px;margin-bottom:16px;text-align:center;">
                    <div style="font-size:40px;margin-bottom:8px;">${detected ? '⚠️' : '✅'}</div>
                    <strong>${detected
                        ? 'Multiple monitors detected! Please disconnect extra screens before continuing.'
                        : 'Single screen confirmed — you are good to proceed.'}</strong>
                </div>
                <p style="font-size:13px;color:#6c757d;">If you have a single screen but see a warning,
                try disconnecting any external displays and returning to this step.</p>`;
        }

        // -----------------------------------------------------------------------
        // STEP: Environment 360°
        // -----------------------------------------------------------------------
        function renderEnvironmentStep() {
            return `
                <h4 style="margin-bottom:8px;">360° Environment Recording</h4>
                <p style="color:#555;margin-bottom:6px;">Show your desk, walls, and surroundings. Confirm you are alone in the room.</p>
                <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:8px 14px;font-size:13px;margin-bottom:12px;">
                    ℹ️ Max 30 seconds. Retake as many times as needed.
                    When satisfied, click <strong>Upload Video</strong> — your proctor will review the recording.
                    Continue is only enabled after a successful upload.
                </div>
                <div style="text-align:center;">
                    <video id="ls-env-preview" autoplay playsinline muted
                        style="width:100%;max-width:360px;border-radius:8px;border:2px solid #dee2e6;background:#111;"></video>
                    <video id="ls-env-playback" controls style="display:none;width:100%;max-width:360px;border-radius:8px;margin-top:8px;"></video>
                </div>
                <div id="ls-env-timer" style="text-align:center;font-size:36px;font-weight:bold;color:#dc3545;margin:10px 0;display:none;">30</div>
                <div style="text-align:center;margin-top:10px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
                    <button id="ls-env-record-btn" class="btn btn-danger btn-lg">🔴 Start Recording</button>
                    <button id="ls-env-stop-btn" class="btn btn-secondary btn-lg" style="display:none;">⏹ Stop</button>
                    <button id="ls-env-retake-btn" class="btn btn-outline-warning" style="display:none;">↺ Retake</button>
                    <button id="ls-env-upload-btn" class="btn btn-success btn-lg" style="display:none;">⬆ Upload Video</button>
                </div>
                <div id="ls-env-status" style="text-align:center;margin-top:10px;font-size:13px;color:#6c757d;">
                    Press "Start Recording" then slowly pan your camera around the room.
                </div>`;
        }

        function runEnvironmentRecording() {
            const preview   = document.getElementById('ls-env-preview');
            const playback  = document.getElementById('ls-env-playback');
            const recordBtn = document.getElementById('ls-env-record-btn');
            const stopBtn   = document.getElementById('ls-env-stop-btn');
            const retakeBtn = document.getElementById('ls-env-retake-btn');
            const uploadBtn = document.getElementById('ls-env-upload-btn');
            const timerEl   = document.getElementById('ls-env-timer');
            const statusEl  = document.getElementById('ls-env-status');
            const nextBtn   = document.getElementById('ls-wiz-next');

            // Continue is DISABLED until upload is confirmed.
            if (nextBtn) { nextBtn.disabled = true; }

            let recorder = null, chunks = [], blob = null, timerIv = null, timeLeft = 30;

            function startPreview() {
                if (cameraStream) { preview.srcObject = cameraStream; preview.play(); }
                else {
                    navigator.mediaDevices.getUserMedia({video: true, audio: true})
                        .then(s => { preview.srcObject = s; preview.play(); })
                        .catch(() => { if (statusEl) { statusEl.textContent = 'Camera unavailable.'; } });
                }
            }
            startPreview();

            recordBtn.addEventListener('click', function() {
                navigator.mediaDevices.getUserMedia({video: true, audio: true})
                    .then(function(stream) { startRec(stream); })
                    .catch(function() { startRec(preview.srcObject); });
            });

            function startRec(stream) {
                chunks = [];
                const mime = MediaRecorder.isTypeSupported('video/webm;codecs=vp8') ? 'video/webm;codecs=vp8' : 'video/webm';
                recorder = new MediaRecorder(stream, {mimeType: mime, videoBitsPerSecond: 400000});
                recorder.ondataavailable = e => { if (e.data.size > 0) { chunks.push(e.data); } };
                recorder.onstop = function() {
                    clearInterval(timerIv);
                    timerEl.style.display = 'none';
                    blob = new Blob(chunks, {type: mime});
                    playback.src = URL.createObjectURL(blob);
                    playback.style.display = 'block';
                    preview.style.display  = 'none';
                    stopBtn.style.display  = 'none';
                    recordBtn.style.display = 'none';
                    retakeBtn.style.display = 'inline-block';
                    // Show Upload button — Continue stays disabled until upload is done.
                    if (uploadBtn) { uploadBtn.style.display = 'inline-block'; }
                    if (statusEl) { statusEl.innerHTML = `<span style="color:#856404;font-size:13px;">
                        ✓ Recording complete (${30 - timeLeft}s). Review the video above, then click
                        <strong style="color:#28a745;">⬆ Upload Video</strong> to submit it to your proctor.
                    </span>`; }
                    if (nextBtn) { nextBtn.disabled = true; } // Stays disabled until upload.
                };
                recorder.start(1000);
                recordBtn.style.display = 'none'; stopBtn.style.display = 'inline-block';
                if (uploadBtn) { uploadBtn.style.display = 'none'; }
                timeLeft = 30; timerEl.style.display = 'block'; timerEl.textContent = timeLeft;
                if (statusEl) { statusEl.textContent = '🔴 Recording… pan your camera to show your full environment.'; }
                timerIv = setInterval(function() { timerEl.textContent = --timeLeft; if (timeLeft <= 0) { recorder.stop(); } }, 1000);
            }

            stopBtn.addEventListener('click', function() {
                if (recorder && recorder.state !== 'inactive') { recorder.stop(); }
            });

            retakeBtn.addEventListener('click', function() {
                blob = null; chunks = [];
                playback.style.display = 'none'; preview.style.display = 'block';
                recordBtn.style.display = 'inline-block';
                retakeBtn.style.display = 'none';
                if (uploadBtn) { uploadBtn.style.display = 'none'; }
                timerEl.style.display = 'none';
                if (nextBtn) { nextBtn.disabled = true; }
                if (statusEl) { statusEl.textContent = 'Press "Start Recording" when ready.'; }
                startPreview();
            });

            // Upload button: upload video, show confirmation, then enable Continue.
            if (uploadBtn) {
                uploadBtn.addEventListener('click', function() {
                    if (!blob) { return; }
                    uploadBtn.disabled = true;
                    uploadBtn.textContent = '⏳ Uploading…';
                    if (statusEl) { statusEl.innerHTML = '<span style="color:#0f6cbf;font-size:13px;">⏳ Uploading your recording to the server…</span>'; }

                    const reader = new FileReader();
                    reader.onloadend = function() {
                        Ajax.call([{
                            methodname: 'quizaccess_learnshield_send_camshot',
                            args: {
                                courseid: cfg.courseid || 0, screenshotid: 0, quizid: cfg.cmid || 0,
                                webcampicture: reader.result, imagetype: 2,
                                parenttype: 'environment_recording', faceimage: '', facefound: 0,
                            },
                        }])[0].done(function() {
                            // SUCCESS — show confirmation, hide upload button, enable Continue.
                            if (uploadBtn) { uploadBtn.style.display = 'none'; }
                            if (statusEl) {
                                statusEl.innerHTML = checkResult(true,
                                    '✅ 360° recording uploaded successfully! Your proctor can now view and review it. Click <strong>Continue</strong> to proceed.');
                            }
                            if (nextBtn) { nextBtn.disabled = false; }
                        }).fail(function() {
                            // FAIL — let them retry or continue anyway.
                            uploadBtn.disabled = false;
                            uploadBtn.textContent = '↺ Retry Upload';
                            if (statusEl) {
                                statusEl.innerHTML = checkResult(false,
                                    '⚠️ Upload failed. Please try again or click <strong>Continue</strong> to proceed without uploading.');
                            }
                            if (nextBtn) { nextBtn.disabled = false; } // Allow continue on fail.
                        });
                    };
                    reader.readAsDataURL(blob);
                });
            }
        }

        // -----------------------------------------------------------------------
        // STEP: Precheck Summary (shows notes/flags from precheck steps)
        // -----------------------------------------------------------------------
        function renderSummaryStep() {
            const hasNotes = precheckNotes.length > 0;
            const notesList = hasNotes
                ? precheckNotes.map(n =>
                    `<li style="padding:6px 0;border-bottom:1px solid #f0f0f0;display:flex;align-items:flex-start;gap:8px;">
                        <span style="font-size:16px;flex-shrink:0;">${n.icon}</span>
                        <span style="font-size:13px;color:#495057;">${n.text}</span>
                    </li>`).join('')
                : '';
            return `<div style="text-align:center;margin-bottom:20px;">
                    <div style="font-size:48px;margin-bottom:10px;">📋</div>
                    <h3 style="font-size:18px;margin:0 0 6px;">Precheck Complete</h3>
                    <p style="font-size:13px;color:#6c757d;">
                        All required checks have been completed. Review any notes below before continuing.
                    </p>
                </div>
                ${hasNotes ? `
                <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:14px 16px;margin-bottom:16px;">
                    <strong style="font-size:14px;color:#856404;">⚠️ Notes from your precheck:</strong>
                    <ul style="margin:10px 0 0;padding:0;list-style:none;">
                        ${notesList}
                    </ul>
                    <p style="font-size:13px;color:#856404;margin-top:12px;margin-bottom:0;">
                        <strong>Important:</strong> Once your exam starts, any violations will count towards your
                        integrity score and may result in automatic suspension of your assessment.
                        Please review the items above and ensure you comply during the exam.
                    </p>
                </div>` : `
                <div style="background:#d4edda;border:1px solid #c3e6cb;border-radius:8px;padding:14px 16px;margin-bottom:16px;text-align:center;">
                    <strong style="font-size:14px;color:#155724;">✅ No violations were flagged during your precheck.</strong>
                    <p style="font-size:13px;color:#155724;margin:6px 0 0;">
                        Your precheck was completed cleanly. Ensure you maintain compliance during the exam.
                    </p>
                </div>`}
                <div style="background:#f0f4ff;border-radius:8px;padding:12px 16px;font-size:13px;color:#495057;">
                    <strong>Reminder — during the exam:</strong>
                    <ul style="margin:6px 0 0;padding-left:18px;line-height:2;">
                        <li>Stay in fullscreen at all times</li>
                        <li>Keep your face clearly visible in the camera</li>
                        <li>Do not switch tabs, windows or applications</li>
                        <li>Stay quiet and alone in the room</li>
                    </ul>
                </div>`;
        }

        // -----------------------------------------------------------------------
        // STEP: Privacy
        // -----------------------------------------------------------------------
        function renderPrivacyStep() {
            return `<p style="margin-bottom:10px;">
                    Read the privacy notice fully — scroll to the bottom, then tick the box to confirm you accept.
                </p>
                <div id="ls-privacy-scroll"
                    style="height:220px;overflow-y:auto;border:2px solid #dee2e6;border-radius:6px;
                    padding:14px;font-size:13px;line-height:1.6;background:#f8f9fa;white-space:pre-wrap;">${escHtml(PRIVACY_POLICY)}</div>
                <p id="ls-privacy-hint" style="font-size:12px;color:#856404;background:#fff3cd;border:1px solid #ffc107;
                    border-radius:6px;padding:8px 12px;margin-top:10px;">
                    ↓ Scroll to the bottom of the notice above to unlock the acceptance checkbox.
                </p>
                <div id="ls-privacy-accept-wrap" style="display:none;margin-top:12px;background:#d4edda;
                    border:2px solid #28a745;border-radius:8px;padding:14px 16px;">
                    <label style="display:flex;align-items:flex-start;gap:12px;cursor:pointer;">
                        <input type="checkbox" id="ls-privacy-agree"
                            style="width:20px;height:20px;margin-top:2px;flex-shrink:0;accent-color:#28a745;cursor:pointer;"/>
                        <span style="font-size:14px;font-weight:600;color:#155724;">
                            I have read and I fully accept the Privacy &amp; Data Protection notice above.
                            I understand my data will be collected and processed for exam integrity purposes.
                        </span>
                    </label>
                    <p id="ls-privacy-chk-hint" style="font-size:12px;color:#e74c3c;margin:8px 0 0;display:none;font-weight:600;">
                        ⚠️ You must tick the checkbox above to continue.
                    </p>
                </div>`;
        }

        function hookPrivacyAccept() {
            var scroll  = document.getElementById('ls-privacy-scroll');
            var hint    = document.getElementById('ls-privacy-hint');
            var wrap    = document.getElementById('ls-privacy-accept-wrap');
            var chk     = document.getElementById('ls-privacy-agree');
            var chkHint = document.getElementById('ls-privacy-chk-hint');
            var nextBtn = document.getElementById('ls-wiz-next');

            var scrolledToBottom = false;

            // HARD-DISABLE — no timer, no auto-unlock, no shortcuts.
            // Continue is only enabled when: (1) scrolled to bottom AND (2) checkbox checked.
            function setNextState() {
                if (!nextBtn) { return; }
                nextBtn.disabled = !(scrolledToBottom && chk && chk.checked);
            }

            // Ensure disabled from the start.
            setNextState();

            function unlockCheckbox() {
                if (scrolledToBottom) { return; }
                scrolledToBottom = true;
                if (hint) { hint.style.display = 'none'; }
                if (wrap) { wrap.style.display = 'block'; }
                if (scroll) { scroll.style.borderColor = '#28a745'; }
                setNextState();
            }

            if (scroll) {
                scroll.addEventListener('scroll', function() {
                    if (this.scrollTop + this.clientHeight >= this.scrollHeight - 15) {
                        unlockCheckbox();
                    }
                });
                // If text is genuinely shorter than the box height, unlock immediately.
                // Use setTimeout to let the DOM fully render before measuring heights.
                setTimeout(function() {
                    var el = document.getElementById('ls-privacy-scroll');
                    if (el && el.scrollHeight <= el.clientHeight) { unlockCheckbox(); }
                }, 150);
            }

            if (chk) {
                chk.addEventListener('change', function() {
                    if (chkHint) { chkHint.style.display = this.checked ? 'none' : 'block'; }
                    setNextState();
                });
            }
        }

        // -----------------------------------------------------------------------
        // STEP: Fullscreen
        // -----------------------------------------------------------------------
        function renderFullscreenStep() {
            return `<div style="text-align:center;">
                    <div style="font-size:64px;margin-bottom:14px;">⛶</div>
                    <h4>Fullscreen Mode Required</h4>
                    <p style="color:#555;">This exam must be taken in fullscreen. The system will monitor fullscreen state throughout.</p>
                    <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:10px 14px;text-align:left;font-size:13px;margin:14px 0;">
                        <strong>During the exam:</strong>
                        <ul style="margin:6px 0 0;padding-left:18px;line-height:1.8;">
                            <li>Switching tabs is detected and logged</li>
                            <li>Exiting fullscreen is detected and logged immediately</li>
                            <li>You will be prompted to return to fullscreen if you exit</li>
                        </ul>
                    </div>
                    <button id="ls-enter-fs-btn" class="btn btn-success btn-lg" style="padding:14px 36px;">
                        ⛶ Enter Fullscreen Mode
                    </button>
                    <div id="ls-fs-status" style="margin-top:10px;font-size:14px;color:#6c757d;">Click above to enter fullscreen</div>
                </div>`;
        }

        function hookFsBtn() {
            const btn      = document.getElementById('ls-enter-fs-btn');
            const statusEl = document.getElementById('ls-fs-status');
            if (!btn) { return; }
            btn.addEventListener('click', function() {
                const el  = document.documentElement;
                const req = el.requestFullscreen || el.webkitRequestFullscreen || el.mozRequestFullScreen;
                if (!req) { if (statusEl) { statusEl.innerHTML = checkResult(false, 'Fullscreen not supported.'); } document.getElementById('ls-wiz-next').disabled = false; return; }
                req.call(el)
                    .then(function() {
                        btn.style.display = 'none';
                        if (statusEl) { statusEl.innerHTML = checkResult(true, '✓ Fullscreen active.'); }
                        document.getElementById('ls-wiz-next').disabled = false;
                    })
                    .catch(function(e) {
                        if (statusEl) { statusEl.innerHTML = checkResult(false, 'Fullscreen blocked: ' + e.message + '. Try again.'); }
                    });
            });
        }

        // -----------------------------------------------------------------------
        // STEP: Ready
        // -----------------------------------------------------------------------
        function renderReadyStep() {
            return `<div style="text-align:center;">
                    <div style="font-size:64px;margin-bottom:14px;">✅</div>
                    <h4>You're All Set!</h4>
                    <p style="color:#555;">All checks have passed. Your exam environment is ready.</p>
                    <div style="background:#d4edda;border:1px solid #c3e6cb;border-radius:8px;padding:14px;text-align:left;margin:14px 0;">
                        <p style="margin:0;font-weight:600;color:#155724;">✓ System verified and secure</p>
                        <p style="margin:4px 0 0;color:#155724;font-size:13px;">
                            Camera • Audio • Photo ID • Face Verified • Screen Sharing Active •
                            Typing Baseline • Fullscreen • Privacy Accepted
                        </p>
                    </div>
                    <p style="color:#dc3545;font-weight:600;margin-top:10px;">
                        Once you click "Start Exam Now", the timer begins and all monitoring activates.
                    </p>
                </div>`;
        }

        // -----------------------------------------------------------------------
        // Navigation
        // -----------------------------------------------------------------------
        function handleNext() {
            const steps = getSteps();
            const step  = steps[currentStep];
            if (step.id === 'ready') { completeWizard(); return; }
            currentStep++;
            renderStep(currentStep);
        }

        function handleBack() {
            if (currentStep > 0) { currentStep--; renderStep(currentStep); }
        }

        function completeWizard() {
            if (faceApprovalPoll) { clearInterval(faceApprovalPoll); }

            const overlay = document.getElementById('ls-wizard-overlay');
            if (overlay) { overlay.remove(); }

            // Show "starting" overlay.
            const loading = document.createElement('div');
            loading.id = 'ls-starting-overlay';
            loading.style.cssText = 'position:fixed;inset:0;z-index:9999999;background:rgba(15,108,191,0.97);' +
                'display:flex;flex-direction:column;align-items:center;justify-content:center;' +
                'color:#fff;font-family:system-ui,sans-serif;';
            loading.innerHTML = '<div style="font-size:52px;margin-bottom:16px;">🚀</div>' +
                '<h2 style="font-size:20px;margin-bottom:8px;">All checks passed!</h2>' +
                '<p style="font-size:14px;opacity:.85;">Starting your exam now…</p>' +
                '<div style="margin-top:20px;width:40px;height:40px;border:4px solid rgba(255,255,255,.3);' +
                'border-top-color:#fff;border-radius:50%;animation:ls-spin 0.8s linear infinite;"></div>';
            document.body.appendChild(loading);

            const quizid = cfg.quizid || 0;
            const cmid   = cfg.cmid   || 0;

            // Mark precheck as done so startAttempt.js interceptor lets the next click through.
            try { sessionStorage.setItem('ls_precheck_done_' + cmid, '1'); } catch(e) {}

            // Build preflightdata: proctoring=1 plus ALL other form fields.
            // Checkboxes are always set to '1' — learner agreed to everything via precheck.
            var preflightdata = [{name: 'proctoring', value: '1'}];
            var form = document.querySelector('form');
            if (form) {
                form.querySelectorAll('input, select, textarea').forEach(function(f) {
                    if (!f.name || f.name === 'proctoring' || f.name === 'sesskey' || f.name === 'submitbutton') { return; }
                    // Always pass checkboxes as '1' (learner agreed via precheck wizard).
                    var val = (f.type === 'checkbox') ? '1' : (f.value || '');
                    if (val) { preflightdata.push({name: f.name, value: val}); }
                });
            }

            function fallbackSubmit() {
                loading.remove();
                var chk = document.getElementById('id_proctoring');
                if (chk) { chk.checked = true; chk.dispatchEvent(new Event('change')); }
                // Tick all required checkboxes before submitting.
                if (form) {
                    form.querySelectorAll('input[type="checkbox"]').forEach(function(c) {
                        if (!c.checked) { c.checked = true; c.dispatchEvent(new Event('change')); }
                    });
                }
                var submit = document.getElementById('id_submitbutton') ||
                             document.querySelector('form [type="submit"]');
                if (submit) { submit.disabled = false; submit.click(); }
            }

            if (quizid && cmid) {
                Ajax.call([{
                    methodname: 'mod_quiz_start_attempt',
                    args: { quizid: quizid, preflightdata: preflightdata, forcenew: false },
                }])[0].done(function(res) {
                    if (res && res.attempt && res.attempt.id) {
                        window.location.href = M.cfg.wwwroot +
                            '/mod/quiz/attempt.php?attempt=' + res.attempt.id + '&cmid=' + cmid;
                    } else {
                        fallbackSubmit();
                    }
                }).fail(function() { fallbackSubmit(); });
            } else {
                fallbackSubmit();
            }
        }

        // -----------------------------------------------------------------------
        // Dynamic script loader (non-blocking, idempotent)
        // -----------------------------------------------------------------------
        function loadScript(src) {
            return new Promise(function(resolve) {
                if (!src) { resolve(); return; }
                if (document.querySelector('script[src="' + src + '"]')) { resolve(); return; }
                var s = document.createElement('script');
                s.src = src;
                s.async = true;
                s.onload  = resolve;
                s.onerror = resolve; // Resolve even on error — fallback handles it.
                document.head.appendChild(s);
            });
        }

        // -----------------------------------------------------------------------
        // Public API
        // -----------------------------------------------------------------------
        return {
            launch(config) {
                cfg              = config || {};
                currentStep      = 0;
                faceApprovalId   = 0;
                faceApprovalPoll = null;
                passportDescriptor = null;
                keystrokeBaseline  = [];
                precheckNotes.length = 0; // Clear notes from any previous attempt.
                screenStream     = null;
                faceApiLoaded    = false;
                $('#id_proctoring').closest('.fitem').hide();
                $('#id_submitbutton').hide();
                buildOverlay();
                renderStep(0);

                // Pre-load face-api in background so it's ready for the face step.
                if (cfg.faceapiurl) {
                    loadScript(cfg.faceapiurl);
                }
            },
        };
    });
