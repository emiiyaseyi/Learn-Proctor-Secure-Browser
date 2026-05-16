// Learn Shield — Pre-exam Multi-Step Wizard
// Steps: Welcome → Browser → Internet → Camera → Audio → Face → Environment → Privacy → Fullscreen → Ready
//
// @package    quizaccess_learnshield
// @author     Michael Adeniran <michaeladeniransnr@gmail.com>
// @copyright  2025 Michael Adeniran
// @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

/* eslint-disable no-console */
define(['jquery', 'core/ajax', 'core/notification'],
    function($, Ajax, Notification) {

        let cfg           = {};
        let currentStep   = 0;
        let cameraStream  = null;
        let audioCtx      = null;
        let audioAnalyser = null;
        let faceApiLoaded = false;

        const PRIVACY_POLICY = `LEARN SHIELD — PRIVACY & DATA PROTECTION NOTICE

This exam is proctored by Learn Shield. By proceeding, you agree to the following:

DATA COLLECTED
• Webcam snapshots taken at regular intervals during your exam
• A 360° environment recording before the exam starts
• Audio activity levels (volume only — not recorded as audio file)
• Browser events: tab switches, keyboard shortcuts, fullscreen exits
• Session metadata: IP address, browser type, operating system
• Face detection results and integrity risk score

PURPOSE
All data is collected solely to maintain academic integrity and verify your identity.

STORAGE
All data is stored within your institution's Moodle server. No data is shared with third parties unless your institution has configured an external storage provider (Google Drive or OneDrive).

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
        // Step definitions
        // -----------------------------------------------------------------------
        function getSteps() {
            return [
                { id: 'welcome',     icon: '🎓', title: 'Welcome to Your Proctored Exam' },
                { id: 'browser',     icon: '🌐', title: 'Browser Compatibility' },
                { id: 'internet',    icon: '📡', title: 'Internet Connection' },
                { id: 'camera',      icon: '📷', title: 'Camera / Webcam' },
                { id: 'audio',       icon: '🎤', title: 'Microphone (Audio)' },
                { id: 'face',        icon: '👤', title: 'Face Detection & Liveness' },
                { id: 'environment', icon: '🔭', title: 'Environment Recording (360°)' },
                { id: 'privacy',     icon: '🔒', title: 'Privacy & Data Protection' },
                { id: 'fullscreen',  icon: '⛶', title: 'Fullscreen Mode' },
                { id: 'ready',       icon: '✅', title: 'Ready to Begin' },
            ];
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
                <div id="ls-wizard-box" style="background:#fff;border-radius:12px;width:min(640px,96vw);
                     max-height:88vh;overflow:hidden;display:flex;flex-direction:column;
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
                        <div id="ls-wiz-status" style="font-size:13px;color:#6c757d"></div>
                        <button id="ls-wiz-next" class="btn btn-primary" style="min-width:120px">Continue →</button>
                    </div>
                </div>`;
            document.body.appendChild(overlay);
            document.getElementById('ls-wiz-next').addEventListener('click', handleNext);
        }

        // -----------------------------------------------------------------------
        // Render
        // -----------------------------------------------------------------------
        function renderStep(index) {
            const steps = getSteps();
            const step  = steps[index];
            const total = steps.length;

            document.getElementById('ls-wiz-icon').textContent      = step.icon;
            document.getElementById('ls-wiz-title').textContent     = step.title;
            document.getElementById('ls-wiz-stepcount').textContent = `Step ${index + 1} of ${total}`;
            document.getElementById('ls-wiz-progress-bar').style.width =
                Math.round(((index + 1) / total) * 100) + '%';

            const body   = document.getElementById('ls-wiz-body');
            const next   = document.getElementById('ls-wiz-next');
            const status = document.getElementById('ls-wiz-status');
            body.innerHTML   = '';
            status.textContent = '';
            next.disabled    = false;
            next.textContent = index === steps.length - 1 ? '🚀 Start Exam Now' : 'Continue →';

            switch (step.id) {
                case 'welcome':     body.innerHTML = renderWelcome(); break;
                case 'browser':     body.innerHTML = renderAutoCheck('browser'); runBrowserCheck(); break;
                case 'internet':    body.innerHTML = renderAutoCheck('internet'); runInternetCheck(); break;
                case 'camera':      body.innerHTML = renderCameraStep(); next.disabled = true; runCameraCheck(); break;
                case 'audio':       body.innerHTML = renderAudioStep();  next.disabled = true; runAudioCheck(); break;
                case 'face':        body.innerHTML = renderFaceStep();   next.disabled = true; runFaceCheck(); break;
                case 'environment': body.innerHTML = renderEnvironmentStep(); next.disabled = true; runEnvironmentRecording(); break;
                case 'privacy':     body.innerHTML = renderPrivacyStep(); next.disabled = true; hookPrivacyAccept(); break;
                case 'fullscreen':  body.innerHTML = renderFullscreenStep(); next.disabled = true; setTimeout(hookFsBtn, 100); break;
                case 'ready':       body.innerHTML = renderReadyStep(); break;
            }
        }

        // -----------------------------------------------------------------------
        // Step content
        // -----------------------------------------------------------------------
        function renderWelcome() {
            const inst = cfg.instructions
                ? `<div style="background:#f0f4ff;border-left:4px solid #0f6cbf;padding:12px 16px;border-radius:4px;margin:14px 0;">
                       <strong>📋 Exam Instructions</strong><br><span style="white-space:pre-line">${escHtml(cfg.instructions)}</span>
                   </div>` : '';
            return `<h4>Before you begin, we will verify your system is ready.</h4>${inst}
                <p style="margin-top:12px">Steps to complete:</p>
                <ul style="line-height:2;color:#555">
                    <li>🌐 Browser compatibility</li><li>📡 Internet connection</li>
                    <li>📷 Camera / webcam</li><li>🎤 Microphone</li>
                    <li>👤 Face detection & liveness</li><li>🔭 360° environment recording</li>
                    <li>🔒 Privacy policy acceptance</li><li>⛶ Fullscreen mode</li>
                </ul>
                <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:12px 16px;margin-top:12px;font-size:13px;">
                    <strong>⚠️ During the exam the following are enforced:</strong>
                    fullscreen mode • webcam monitoring • tab switching detection •
                    keyboard shortcut blocking • clipboard monitoring • audio activity monitoring
                </div>`;
        }

        function renderAutoCheck(id) {
            return `<div id="ls-check-result-${id}" style="min-height:60px;padding:8px 0">Checking…</div>`;
        }

        function renderCameraStep() {
            return `<p>We need webcam access to monitor you during the exam.</p>
                <div style="text-align:center;margin:12px 0;">
                    <video id="ls-wiz-video" autoplay playsinline muted
                           style="width:100%;max-width:320px;border-radius:8px;border:2px solid #dee2e6;background:#111;"></video>
                </div>
                <div id="ls-camera-status" style="font-size:14px;color:#6c757d;text-align:center">Requesting camera access…</div>`;
        }

        function renderAudioStep() {
            return `<p>Microphone access is required to monitor audio during the exam.
                    <strong>Audio is not recorded</strong> — only volume levels are checked.</p>
                <ul style="color:#555;font-size:13px;margin-bottom:14px">
                    <li>Stay quiet for 3 seconds (background noise check)</li>
                    <li>Then speak briefly to confirm your mic works</li>
                    <li>Excessive background noise will block access</li>
                </ul>
                <div style="display:flex;align-items:center;gap:10px;margin:14px 0;">
                    <span style="font-size:20px">🎤</span>
                    <div style="flex:1;background:#e9ecef;border-radius:8px;height:22px;overflow:hidden;">
                        <div id="ls-audio-meter" style="height:100%;background:#28a745;width:0%;transition:width .15s;border-radius:8px;"></div>
                    </div>
                    <span id="ls-audio-label" style="font-size:12px;min-width:80px">Checking…</span>
                </div>
                <div id="ls-audio-prompt" style="display:none;background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:10px 14px;font-size:14px;margin-bottom:8px;"></div>
                <div id="ls-audio-status" style="font-size:14px;color:#6c757d">Requesting microphone access…</div>`;
        }

        function renderFaceStep() {
            return `<p>Please position yourself so your face is clearly visible and centred in the camera.</p>
                <div style="background:#f8f9fa;border:1px solid #dee2e6;border-radius:6px;padding:10px 14px;font-size:13px;margin-bottom:12px;">
                    ✓ Good lighting &nbsp;|&nbsp; ✓ Face centred &nbsp;|&nbsp; ✓ One person only &nbsp;|&nbsp; ✓ No face coverings
                </div>
                <div style="text-align:center;margin:10px 0;">
                    <video id="ls-wiz-video2" autoplay playsinline muted
                           style="width:100%;max-width:280px;border-radius:8px;border:2px solid #dee2e6;background:#111;"></video>
                </div>
                <div id="ls-face-status" style="text-align:center;font-size:14px;color:#6c757d;margin-top:8px">Scanning…</div>
                <div id="ls-liveness-prompt" style="display:none;margin-top:10px;text-align:center;background:#fff3cd;padding:10px;border-radius:8px;"></div>
                <!-- Manual confirmation checkbox — always required even if models not loaded -->
                <div id="ls-face-confirm-wrap" style="display:none;margin-top:14px;border-top:1px solid #eee;padding-top:12px;">
                    <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;">
                        <input type="checkbox" id="ls-face-confirm-chk" style="width:18px;height:18px;margin-top:2px;flex-shrink:0;">
                        <span style="font-size:13px;">I confirm my face is clearly visible, I am alone, and there are no face coverings.</span>
                    </label>
                </div>`;
        }

        function renderEnvironmentStep() {
            return `
                <h4 style="margin-bottom:8px">360° Environment Recording</h4>
                <p style="color:#555;margin-bottom:6px">Show your desk, walls, and surroundings. Confirm you are alone in the room.</p>
                <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:8px 14px;font-size:13px;margin-bottom:12px;">
                    ℹ️ Max 30 seconds. Retake as many times as needed — only your final submission is saved (auto-deleted after 7 days).
                </div>
                <div style="text-align:center;">
                    <video id="ls-env-preview" autoplay playsinline muted
                           style="width:100%;max-width:360px;border-radius:8px;border:2px solid #dee2e6;background:#111;"></video>
                    <video id="ls-env-playback" controls style="display:none;width:100%;max-width:360px;border-radius:8px;margin-top:8px;"></video>
                </div>
                <div id="ls-env-timer" style="text-align:center;font-size:36px;font-weight:bold;color:#dc3545;margin:10px 0;display:none;">30</div>
                <div style="text-align:center;margin-top:10px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
                    <button id="ls-env-record-btn" class="btn btn-danger btn-lg">🔴 Start Recording</button>
                    <button id="ls-env-stop-btn"   class="btn btn-secondary btn-lg" style="display:none">⏹ Stop</button>
                    <button id="ls-env-retake-btn" class="btn btn-outline-warning"  style="display:none">↺ Retake</button>
                </div>
                <div id="ls-env-status" style="text-align:center;margin-top:10px;font-size:13px;color:#6c757d">
                    Press "Start Recording" then slowly pan your camera around the room.
                </div>`;
        }

        function renderPrivacyStep() {
            return `<p>Please read the privacy notice below. You must scroll to the bottom to accept.</p>
                <div id="ls-privacy-scroll" onscroll="window.__lsPrivacyScroll(this)"
                     style="height:200px;overflow-y:auto;border:1px solid #dee2e6;border-radius:6px;
                            padding:14px;font-size:13px;line-height:1.6;background:#f8f9fa;white-space:pre-wrap;">${escHtml(PRIVACY_POLICY)}</div>
                <div style="margin-top:12px;display:flex;align-items:center;gap:10px;">
                    <input type="checkbox" id="ls-privacy-agree" disabled style="width:18px;height:18px;cursor:pointer;"/>
                    <label for="ls-privacy-agree" style="cursor:pointer;font-weight:500;">
                        I have read and accept the Privacy &amp; Data Protection notice
                    </label>
                </div>
                <p id="ls-privacy-hint" style="font-size:12px;color:#6c757d;margin-top:6px;">↓ Scroll to the bottom to enable acceptance</p>`;
        }

        function renderFullscreenStep() {
            return `<div style="text-align:center;">
                    <div style="font-size:64px;margin-bottom:14px">⛶</div>
                    <h4>Fullscreen Mode Required</h4>
                    <p style="color:#555;">This exam must be taken in fullscreen. The system will monitor fullscreen state throughout.</p>
                    <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:10px 14px;text-align:left;font-size:13px;margin:14px 0">
                        <strong>During the exam:</strong>
                        <ul style="margin:6px 0 0;padding-left:18px;line-height:1.8">
                            <li>Switching tabs is detected and logged</li>
                            <li>Exiting fullscreen is detected and logged immediately</li>
                            <li>You will be prompted to return to fullscreen if you exit</li>
                        </ul>
                    </div>
                    <button id="ls-enter-fs-btn" class="btn btn-success btn-lg" style="padding:14px 36px;">
                        ⛶ Enter Fullscreen Mode
                    </button>
                    <div id="ls-fs-status" style="margin-top:10px;font-size:14px;color:#6c757d">Click above to enter fullscreen</div>
                </div>`;
        }

        function renderReadyStep() {
            return `<div style="text-align:center;">
                    <div style="font-size:64px;margin-bottom:14px">✅</div>
                    <h4>You're All Set!</h4>
                    <p style="color:#555;">All checks have passed. Your exam environment is ready.</p>
                    <div style="background:#d4edda;border:1px solid #c3e6cb;border-radius:8px;padding:14px;text-align:left;margin:14px 0">
                        <p style="margin:0;font-weight:600;color:#155724">✓ System verified and secure</p>
                        <p style="margin:4px 0 0;color:#155724;font-size:13px">Camera • Audio • Fullscreen • Privacy Accepted</p>
                    </div>
                    <p style="color:#dc3545;font-weight:600;margin-top:10px">
                        Once you click "Start Exam Now", the timer begins and all monitoring activates.
                    </p>
                </div>`;
        }

        // -----------------------------------------------------------------------
        // Check logic
        // -----------------------------------------------------------------------
        function runBrowserCheck() {
            const el = document.getElementById('ls-check-result-browser');
            const issues = [];
            if (!navigator.mediaDevices?.getUserMedia) { issues.push('Camera API not supported'); }
            if (!window.AudioContext && !window.webkitAudioContext) { issues.push('Audio API not supported'); }
            if (typeof fetch === 'undefined') { issues.push('Browser too old'); }
            el.innerHTML = issues.length
                ? checkResult(false, 'Issues: ' + issues.join(', ') + '. You may still continue.')
                : checkResult(true, `Compatible (${getBrowserName()})`);
            setStepDone();
        }

        function runInternetCheck() {
            const el = document.getElementById('ls-check-result-internet');
            if (!navigator.onLine) {
                el.innerHTML = checkResult(false, 'No internet connection detected.');
                setStepDone(); return;
            }
            const start = Date.now();
            fetch(window.location.origin + '/favicon.ico?t=' + start, {cache: 'no-store'})
                .then(() => {
                    el.innerHTML = checkResult(true, `Connected — ${Date.now() - start}ms response time`);
                })
                .catch(() => {
                    el.innerHTML = checkResult(true, 'Connected (online)');
                })
                .finally(() => setStepDone());
        }

        function runCameraCheck() {
            const statusEl = document.getElementById('ls-camera-status');
            navigator.mediaDevices.getUserMedia({video: {width: 320, height: 240}})
                .then(function(stream) {
                    cameraStream = stream;
                    const video = document.getElementById('ls-wiz-video');
                    if (video) { video.srcObject = stream; video.play(); }
                    statusEl.innerHTML = checkResult(true, 'Camera is working ✓');
                    document.getElementById('ls-wiz-next').disabled = false;
                })
                .catch(function(e) {
                    statusEl.innerHTML = checkResult(false, 'Camera access denied: ' + e.message + '<br>Please allow camera access and refresh.');
                    document.getElementById('ls-wiz-next').disabled = false;
                });
        }

        function runAudioCheck() {
            const statusEl  = document.getElementById('ls-audio-status');
            const promptEl  = document.getElementById('ls-audio-prompt');
            const meter     = document.getElementById('ls-audio-meter');
            const label     = document.getElementById('ls-audio-label');

            navigator.mediaDevices.getUserMedia({audio: true})
                .then(function(stream) {
                    const AC       = window.AudioContext || window.webkitAudioContext;
                    audioCtx       = new AC();
                    audioAnalyser  = audioCtx.createAnalyser();
                    audioAnalyser.fftSize = 512;
                    audioCtx.createMediaStreamSource(stream).connect(audioAnalyser);

                    let bgReadings = [], bgDone = false, spoken = false;
                    statusEl.textContent = '🔇 Stay quiet for 3 seconds (background noise check)…';

                    const interval = setInterval(function() {
                        const data  = new Uint8Array(audioAnalyser.frequencyBinCount);
                        audioAnalyser.getByteFrequencyData(data);
                        const level = Math.round(data.reduce((a, b) => a + b, 0) / data.length);
                        const pct   = Math.min(level * 4, 100);

                        if (meter) { meter.style.width = pct + '%'; meter.style.background = level > 20 ? '#dc3545' : '#28a745'; }
                        if (label) { label.textContent = level > 20 ? '🔊 Loud' : (level > 5 ? '🔉' : '🔇 Quiet'); }

                        if (!bgDone) {
                            bgReadings.push(level);
                            if (bgReadings.length >= 10) {
                                bgDone = true;
                                const avg = bgReadings.reduce((a, b) => a + b) / bgReadings.length;
                                if (avg > 22) {
                                    clearInterval(interval);
                                    statusEl.innerHTML = checkResult(false,
                                        '⚠️ Loud background noise detected (level: ' + Math.round(avg) + ').<br>' +
                                        'Please move to a quieter location. Continuous background noise is not permitted.');
                                    const retryBtn = document.createElement('button');
                                    retryBtn.className = 'btn btn-warning mt-2';
                                    retryBtn.textContent = '↺ Retry Audio Check';
                                    retryBtn.onclick = function() { retryBtn.remove(); runAudioCheck(); };
                                    statusEl.after(retryBtn);
                                    return;
                                }
                                if (promptEl) { promptEl.style.display = 'block'; promptEl.textContent = '✓ Quiet environment. Now say "I am ready to begin" into your microphone.'; }
                                statusEl.textContent = '🎤 Please speak now to confirm your microphone works…';
                            }
                        } else if (!spoken && level > 14) {
                            spoken = true;
                            clearInterval(interval);
                            statusEl.innerHTML = checkResult(true, '✓ Microphone working and background is quiet.');
                            if (promptEl) { promptEl.style.display = 'none'; }
                            document.getElementById('ls-wiz-next').disabled = false;
                        }
                    }, 300);

                    setTimeout(function() {
                        if (!spoken) {
                            clearInterval(interval);
                            const avg = bgReadings.reduce((a, b) => a + b, 0) / (bgReadings.length || 1);
                            if (avg <= 22) {
                                statusEl.innerHTML = checkResult(true, 'Microphone connected (no speech detected — ensure your mic works).');
                                document.getElementById('ls-wiz-next').disabled = false;
                            }
                        }
                    }, 15000);
                })
                .catch(function() {
                    statusEl.innerHTML = checkResult(false, 'Microphone access denied or unavailable.');
                    document.getElementById('ls-wiz-next').disabled = false;
                });
        }

        async function runFaceCheck() {
            const statusEl    = document.getElementById('ls-face-status');
            const livenessEl  = document.getElementById('ls-liveness-prompt');
            const confirmWrap = document.getElementById('ls-face-confirm-wrap');
            const confirmChk  = document.getElementById('ls-face-confirm-chk');

            // Show the confirm checkbox — ALWAYS required.
            if (confirmWrap) {
                confirmWrap.style.display = 'block';
                if (confirmChk) {
                    confirmChk.addEventListener('change', function() {
                        document.getElementById('ls-wiz-next').disabled = !this.checked;
                    });
                }
            }

            // Attach camera.
            const video2 = document.getElementById('ls-wiz-video2');
            if (video2 && cameraStream) {
                video2.srcObject = cameraStream;
                video2.play();
            } else if (video2) {
                try {
                    cameraStream = await navigator.mediaDevices.getUserMedia({video: true});
                    video2.srcObject = cameraStream;
                    video2.play();
                } catch (e) {
                    statusEl.innerHTML = checkResult(false, 'Camera not available. Tick the checkbox below to confirm your face is visible.');
                    return;
                }
            }

            // Load face-api models.
            if (cfg.modelurl && !faceApiLoaded) {
                try {
                    // eslint-disable-next-line no-undef
                    await faceapi.nets.ssdMobilenetv1.loadFromUri(cfg.modelurl);
                    // eslint-disable-next-line no-undef
                    await faceapi.nets.faceLandmark68TinyNet.loadFromUri(cfg.modelurl);
                    faceApiLoaded = true;
                } catch (e) { console.warn('face-api load failed', e); }
            }

            if (!faceApiLoaded) {
                // No model available — rely entirely on manual confirmation checkbox.
                statusEl.innerHTML = '<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:10px 14px;font-size:13px;">' +
                    '⚠️ Automated face detection is unavailable.<br>' +
                    'Ensure your face is <strong>clearly visible</strong>, centred, and well-lit.<br>' +
                    'Tick the confirmation checkbox below to proceed.</div>';
                return;
            }

            statusEl.textContent = '🔍 Scanning for your face…';
            let attempts = 0, faceConfirmed = false, blinkDetected = false, prevEar = null, blocked = false;

            const interval = setInterval(async function() {
                if (blocked) { return; }
                attempts++;
                if (!video2 || video2.readyState < 2) { return; }

                try {
                    // eslint-disable-next-line no-undef
                    const dets = await faceapi.detectAllFaces(video2).withFaceLandmarks(true);

                    // Multiple faces — block.
                    if (dets.length > 1) {
                        blocked = true;
                        clearInterval(interval);
                        statusEl.innerHTML = checkResult(false,
                            '🚫 ' + dets.length + ' faces detected. Only the exam candidate may be present.<br>' +
                            'Ensure you are alone, then retry.');
                        if (confirmChk) { confirmChk.checked = false; confirmChk.disabled = true; }
                        document.getElementById('ls-wiz-next').disabled = true;
                        addRetryBtn(statusEl, function() { blocked = false; faceConfirmed = false; blinkDetected = false; prevEar = null; attempts = 0; runFaceCheck(); });
                        return;
                    }

                    // No face — warn after 20 attempts.
                    if (dets.length === 0) {
                        if (attempts > 20 && !faceConfirmed) {
                            statusEl.innerHTML = checkResult(false,
                                '⚠️ No face detected after 10 seconds.<br>' +
                                'Check your lighting and camera position. Tick the checkbox below if your face IS visible.');
                        }
                        return;
                    }

                    // Face found.
                    if (!faceConfirmed) {
                        faceConfirmed = true;
                        statusEl.innerHTML = checkResult(true, '✓ Face detected — one person confirmed in frame.');
                        if (livenessEl) { livenessEl.style.display = 'block'; livenessEl.textContent = '👁 Liveness check: please blink your eyes slowly once.'; }
                    }

                    // Blink detection (EAR method).
                    const lm  = dets[0].landmarks;
                    const ear = function(pts) {
                        const h = Math.abs(pts[1].y - pts[5].y) + Math.abs(pts[2].y - pts[4].y);
                        const w = Math.abs(pts[0].x - pts[3].x);
                        return w > 0 ? h / (2 * w) : 1;
                    };
                    const avgEar = (ear(lm.getLeftEye()) + ear(lm.getRightEye())) / 2;
                    if (prevEar !== null && prevEar > 0.2 && avgEar < 0.2) { blinkDetected = true; }
                    prevEar = avgEar;

                    if (blinkDetected) {
                        clearInterval(interval);
                        if (livenessEl) { livenessEl.style.background = '#d4edda'; livenessEl.innerHTML = '✅ <strong>Liveness confirmed</strong> — blink detected. Real person verified.'; }
                        // Auto-tick the confirmation checkbox after blink confirmed.
                        if (confirmChk) { confirmChk.checked = true; confirmChk.disabled = false; document.getElementById('ls-wiz-next').disabled = false; }
                    }
                } catch (e) { /* skip frame */ }
            }, 500);

            // Auto-allow after 20 s.
            setTimeout(function() {
                if (!blocked) {
                    clearInterval(interval);
                    if (!faceConfirmed) {
                        statusEl.innerHTML = checkResult(false, 'Face not confirmed. Tick the checkbox below if your face IS visible.');
                    } else if (!blinkDetected) {
                        if (livenessEl) { livenessEl.innerHTML = '⚠️ Liveness inconclusive — tick the checkbox below to confirm.'; }
                    }
                }
            }, 20000);
        }

        function addRetryBtn(el, cb) {
            const btn = document.createElement('button');
            btn.className = 'btn btn-warning mt-2'; btn.textContent = '↺ Retry';
            btn.onclick = function() { btn.remove(); cb(); };
            el.after(btn);
        }

        function runEnvironmentRecording() {
            const preview   = document.getElementById('ls-env-preview');
            const playback  = document.getElementById('ls-env-playback');
            const recordBtn = document.getElementById('ls-env-record-btn');
            const stopBtn   = document.getElementById('ls-env-stop-btn');
            const retakeBtn = document.getElementById('ls-env-retake-btn');
            const timerEl   = document.getElementById('ls-env-timer');
            const statusEl  = document.getElementById('ls-env-status');
            const nextBtn   = document.getElementById('ls-wiz-next');

            let recorder = null, chunks = [], blob = null, timerIv = null, timeLeft = 30;

            const startPreview = function() {
                if (cameraStream) { preview.srcObject = cameraStream; preview.play(); }
                else {
                    navigator.mediaDevices.getUserMedia({video: true, audio: true})
                        .then(s => { preview.srcObject = s; preview.play(); })
                        .catch(() => { statusEl.textContent = 'Camera unavailable.'; });
                }
            };
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
                recorder.ondataavailable = e => { if (e.data.size > 0) chunks.push(e.data); };
                recorder.onstop = function() {
                    clearInterval(timerIv);
                    timerEl.style.display = 'none';
                    blob = new Blob(chunks, {type: mime});
                    playback.src = URL.createObjectURL(blob);
                    playback.style.display = 'block';
                    preview.style.display  = 'none';
                    stopBtn.style.display  = 'none'; recordBtn.style.display = 'none';
                    retakeBtn.style.display = 'inline-block';
                    statusEl.innerHTML = checkResult(true, '✓ Recording complete (' + (30 - timeLeft) + 's). Review above, then click Continue.');
                    nextBtn.disabled = false;
                };
                recorder.start(1000);
                recordBtn.style.display = 'none'; stopBtn.style.display = 'inline-block';
                timeLeft = 30; timerEl.style.display = 'block'; timerEl.textContent = timeLeft;
                statusEl.textContent = '🔴 Recording… pan your camera to show your full environment.';
                timerIv = setInterval(function() { timerEl.textContent = --timeLeft; if (timeLeft <= 0) recorder.stop(); }, 1000);
            }

            stopBtn.addEventListener('click', function() { if (recorder && recorder.state !== 'inactive') recorder.stop(); });

            retakeBtn.addEventListener('click', function() {
                blob = null; chunks = [];
                playback.style.display = 'none'; preview.style.display = 'block';
                recordBtn.style.display = 'inline-block'; retakeBtn.style.display = 'none';
                timerEl.style.display = 'none'; nextBtn.disabled = true;
                statusEl.textContent = 'Press "Start Recording" when ready.';
                startPreview();
            });

            // Override continue to upload.
            const uploadHandler = function(e) {
                if (!blob) { return; }
                e.stopImmediatePropagation();
                nextBtn.disabled = true; nextBtn.textContent = 'Uploading…';
                const reader = new FileReader();
                reader.onloadend = function() {
                    Ajax.call([{
                        methodname: 'quizaccess_learnshield_send_camshot',
                        args: {
                            courseid: cfg.courseid || 0, screenshotid: 0, quizid: cfg.cmid || 0,
                            webcampicture: reader.result, imagetype: 2,
                            parenttype: 'environment_recording', faceimage: '', facefound: 0,
                        },
                    }])[0].always(function() {
                        nextBtn.textContent = 'Continue →'; nextBtn.disabled = false;
                        nextBtn.removeEventListener('click', uploadHandler);
                        handleNext();
                    });
                };
                reader.readAsDataURL(blob);
            };
            nextBtn.addEventListener('click', uploadHandler);
        }

        function hookPrivacyAccept() {
            window.__lsPrivacyScroll = function(el) {
                if (el.scrollTop + el.clientHeight >= el.scrollHeight - 10) {
                    const chk = document.getElementById('ls-privacy-agree');
                    if (chk) { chk.disabled = false; }
                    const hint = document.getElementById('ls-privacy-hint');
                    if (hint) { hint.style.display = 'none'; }
                }
            };
            const chk = document.getElementById('ls-privacy-agree');
            if (chk) {
                chk.addEventListener('change', function() {
                    document.getElementById('ls-wiz-next').disabled = !this.checked;
                });
            }
        }

        function hookFsBtn() {
            const btn      = document.getElementById('ls-enter-fs-btn');
            const statusEl = document.getElementById('ls-fs-status');
            if (!btn) { return; }
            btn.addEventListener('click', function() {
                const el  = document.documentElement;
                const req = el.requestFullscreen || el.webkitRequestFullscreen || el.mozRequestFullScreen;
                if (!req) { statusEl.innerHTML = checkResult(false, 'Fullscreen not supported.'); document.getElementById('ls-wiz-next').disabled = false; return; }
                req.call(el)
                    .then(function() { btn.style.display = 'none'; statusEl.innerHTML = checkResult(true, '✓ Fullscreen active.'); document.getElementById('ls-wiz-next').disabled = false; })
                    .catch(function(e) { statusEl.innerHTML = checkResult(false, 'Fullscreen blocked: ' + e.message + '. Try again.'); });
            });
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

        function completeWizard() {
            const checkbox = document.getElementById('id_proctoring');
            if (checkbox) { checkbox.checked = true; checkbox.dispatchEvent(new Event('change')); }
            const submit = document.getElementById('id_submitbutton');
            if (submit) {
                const overlay = document.getElementById('ls-wizard-overlay');
                if (overlay) { overlay.remove(); }
                submit.disabled = false;
                submit.click();
            }
        }

        // -----------------------------------------------------------------------
        // Helpers
        // -----------------------------------------------------------------------
        function setStepDone() { document.getElementById('ls-wiz-next').disabled = false; }

        function checkResult(pass, msg) {
            const [bg, border, color, icon] = pass
                ? ['#d4edda', '#c3e6cb', '#155724', '✅']
                : ['#fff3cd', '#ffc107', '#856404', '⚠️'];
            return `<div style="background:${bg};border:1px solid ${border};border-radius:6px;padding:10px 14px;color:${color};font-size:14px;margin-top:8px;">${icon} ${msg}</div>`;
        }

        function getBrowserName() {
            const ua = navigator.userAgent;
            if (ua.includes('Firefox'))  return 'Firefox';
            if (ua.includes('Edg'))      return 'Edge';
            if (ua.includes('Chrome'))   return 'Chrome';
            if (ua.includes('Safari'))   return 'Safari';
            return 'Browser';
        }

        function escHtml(s) {
            return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        // -----------------------------------------------------------------------
        // Public API
        // -----------------------------------------------------------------------
        return {
            launch(config) {
                cfg = config || {};
                currentStep = 0;
                $('#id_proctoring').closest('.fitem').hide();
                $('#id_submitbutton').hide();
                buildOverlay();
                renderStep(0);
            },
        };
    });
