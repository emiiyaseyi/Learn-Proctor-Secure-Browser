// Learn Shield — Pre-exam Multi-Step Wizard
// Steps: Close Tabs → Welcome → Browser → Internet → Camera → Audio → Face → Environment → Privacy → Fullscreen → Ready
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
        // Step definitions — matches LearnGuard wizard exactly
        // -----------------------------------------------------------------------
        function getSteps() {
            return [
                { id: 'closetabs',   icon: '🗂️', title: 'Close All Other Tabs & Apps' },
                { id: 'welcome',     icon: '🎓', title: 'Welcome to Your Proctored Exam' },
                { id: 'browser',     icon: '🌐', title: 'Browser Compatibility' },
                { id: 'internet',    icon: '📡', title: 'Internet Connection' },
                { id: 'camera',      icon: '📷', title: 'Camera / Webcam' },
                { id: 'audio',       icon: '🎤', title: 'Microphone (Audio)' },
                { id: 'photoid',     icon: '🪪',  title: 'Identity Verification (Photo ID)' },
                { id: 'face',        icon: '👤', title: 'Face Detection & Identity' },
                { id: 'screenshare', icon: '🖥',  title: 'Screen Sharing (Mandatory)' },
                { id: 'monitor',     icon: '🖥',  title: 'Single Screen Required' },
                { id: 'environment', icon: '🔭', title: 'Environment Recording (360°)' },
                { id: 'privacy',     icon: '🔒', title: 'Privacy & Data Protection' },
                { id: 'fullscreen',  icon: '⛶',  title: 'Fullscreen Mode' },
                { id: 'ready',       icon: '✅', title: 'Ready to Begin' },
            ];
        }

        // -----------------------------------------------------------------------
        // Report wizard step progress to server
        // -----------------------------------------------------------------------
        function reportPrecheck(stepId, stepNumber, totalSteps) {
            if (!cfg.cmid) { return; }
            Ajax.call([{
                methodname: 'quizaccess_learnshield_report_precheck',
                args: {
                    cmid:       cfg.cmid || 0,
                    step:       stepId,
                    stepnumber: stepNumber,
                    totalsteps: totalSteps,
                },
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
                case 'closetabs':   body.innerHTML = renderCloseTabsStep(); next.disabled = true; hookCloseTabsConfirm(); break;
                case 'welcome':     body.innerHTML = renderWelcome(); next.disabled = true; hookWelcomeAck(); break;
                case 'browser':     body.innerHTML = renderAutoCheck('browser'); runBrowserCheck(); break;
                case 'internet':    body.innerHTML = renderAutoCheck('internet'); runInternetCheck(); break;
                case 'camera':      body.innerHTML = renderCameraStep(); next.disabled = true; runCameraCheck(); break;
                case 'audio':       body.innerHTML = renderAudioStep();  next.disabled = true; runAudioCheck(); break;
                case 'photoid':     body.innerHTML = renderPhotoIdStep(); next.disabled = true; hookPhotoId(); break;
                case 'face':        body.innerHTML = renderFaceStep();   next.disabled = true; runFaceCheck(); break;
                case 'screenshare': body.innerHTML = renderScreenShareStep(); next.disabled = true; runScreenShare(); break;
                case 'monitor':     body.innerHTML = renderMonitorStep(); runMonitorCheck(); break;
                case 'environment': body.innerHTML = renderEnvironmentStep(); next.disabled = true; runEnvironmentRecording(); break;
                case 'privacy':     body.innerHTML = renderPrivacyStep(); next.disabled = true; hookPrivacyAccept(); break;
                case 'fullscreen':  body.innerHTML = renderFullscreenStep(); next.disabled = true; setTimeout(hookFsBtn, 100); break;
                case 'ready':       body.innerHTML = renderReadyStep(); break;
            }
        }

        // -----------------------------------------------------------------------
        // Step content
        // -----------------------------------------------------------------------
        function renderCloseTabsStep() {
            return `<div style="text-align:center;padding:10px 0;">
                <div style="font-size:64px;margin-bottom:14px">🗂️</div>
                <h4>Close All Other Tabs & Applications</h4>
                <p style="color:#555;margin-bottom:14px;">
                    Before starting your exam, close all other browser tabs, windows, and applications.
                    This prevents access to external resources and ensures a distraction-free environment.
                </p>
                <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:12px 16px;text-align:left;font-size:13px;margin-bottom:16px;">
                    <strong>Please close before continuing:</strong>
                    <ul style="margin:6px 0 0;padding-left:18px;line-height:2;">
                        <li>All other browser tabs and windows</li>
                        <li>Email clients and messaging apps</li>
                        <li>Reference documents and notes</li>
                        <li>Any screen recording or streaming software</li>
                    </ul>
                </div>
                <div style="display:flex;align-items:center;gap:10px;justify-content:center;margin-top:12px;padding:12px;background:#f8f9fa;border-radius:8px;">
                    <input type="checkbox" id="ls-closetabs-confirm" style="width:18px;height:18px;cursor:pointer;flex-shrink:0;">
                    <label for="ls-closetabs-confirm" style="font-size:14px;cursor:pointer;text-align:left;">
                        I confirm I have closed all other tabs and applications
                    </label>
                </div>
            </div>`;
        }

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
                    environment is suitable, and set up monitoring. This takes <strong>2–5 minutes</strong>.
                    Each step must pass before you can proceed to the next.
                </p>
            </div>
            <div style="background:#d4edda;border-radius:8px;padding:14px 16px;margin-bottom:14px;">
                <strong style="font-size:14px;color:#155724;">✅ What You Need Before Starting</strong>
                <ul style="margin:8px 0 0;padding-left:18px;font-size:13px;color:#155724;line-height:2;">
                    <li>📷 A working <strong>webcam</strong> (built-in or external)</li>
                    <li>🎤 A working <strong>microphone</strong></li>
                    <li>🪪 Your <strong>government-issued photo ID</strong> (passport, national ID, or driver's licence)</li>
                    <li>🌐 A stable <strong>internet connection</strong></li>
                    <li>🤫 A <strong>quiet, private room</strong> — no other people visible</li>
                    <li>💡 <strong>Good lighting</strong> — face must be clearly visible</li>
                    <li>💻 A <strong>desktop or laptop</strong> — mobile phones not allowed</li>
                </ul>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px;">
                ${['🗂 Close Tabs','🌐 Browser','📡 Internet','📷 Camera','🎤 Microphone','🪪 Photo ID','👤 Face Check','🖥 Screen Share','🔭 360° Record','🔒 Privacy','⛶ Fullscreen','✅ Start Exam'].map(
                    b => `<span style="background:#fff;border:1px solid #dee2e6;border-radius:20px;padding:4px 10px;font-size:12px;font-weight:600;color:#495057;">${b}</span>`
                ).join('')}
            </div>
            <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:12px 16px;font-size:13px;margin-bottom:14px;">
                <strong>⚠️ During the exam these are continuously active:</strong><br>
                <span style="color:#856404;">Fullscreen • Webcam snapshots • Tab-switch detection • Key-blocking •
                Clipboard monitoring • Audio monitoring • Object detection (phone/book) • Face identity matching • Screen sharing</span>
            </div>
            <div style="background:#f0f0ff;border:2px solid #0f6cbf;border-radius:8px;padding:14px 16px;">
                <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;">
                    <input type="checkbox" id="ls-welcome-ack" style="width:18px;height:18px;margin-top:2px;flex-shrink:0;accent-color:#0f6cbf;">
                    <span style="font-size:13px;font-weight:600;color:#0f6cbf;">
                        I understand the pre-check requirements. I have my Photo ID ready, my webcam and microphone are available,
                        and I am in a quiet, well-lit, private room. I consent to proctoring data being collected.
                    </span>
                </label>
            </div>`;
        }

        function renderAutoCheck(id) {
            return `<div id="ls-check-result-${id}" style="min-height:60px;padding:8px 0">Checking…</div>`;
        }

        function renderCameraStep() {
            return `<p>We need webcam access to monitor you during the exam. <strong>Camera is mandatory.</strong></p>
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
            return `<p>Position yourself so your face is clearly visible and centred in the camera.</p>
                <div style="background:#f8f9fa;border:1px solid #dee2e6;border-radius:6px;padding:10px 14px;font-size:13px;margin-bottom:12px;">
                    ✓ Good lighting &nbsp;|&nbsp; ✓ Face centred &nbsp;|&nbsp; ✓ One person only &nbsp;|&nbsp; ✓ No face coverings
                </div>
                <div style="text-align:center;margin:10px 0;">
                    <video id="ls-wiz-video2" autoplay playsinline muted
                           style="width:100%;max-width:280px;border-radius:8px;border:2px solid #dee2e6;background:#111;"></video>
                </div>
                <div id="ls-face-status" style="text-align:center;font-size:14px;color:#6c757d;margin-top:8px">Scanning…</div>
                <div id="ls-liveness-prompt" style="display:none;margin-top:10px;text-align:center;background:#fff3cd;padding:10px;border-radius:8px;"></div>
                <!-- Invigilator approval panel (hidden until needed) -->
                <div id="ls-face-approval-panel" style="display:none;margin-top:14px;border:1px solid #dee2e6;border-radius:8px;overflow:hidden;">
                    <div style="background:#0f6cbf;color:#fff;padding:10px 14px;font-size:13px;font-weight:700;">
                        👤 Invigilator Face Review
                    </div>
                    <div style="padding:14px;">
                        <div id="ls-approval-status-msg" style="font-size:13px;color:#6c757d;margin-bottom:10px;">
                            ⏳ Waiting for invigilator to review your face…
                        </div>
                        <div id="ls-fa-chat-msgs" style="max-height:120px;overflow-y:auto;display:flex;flex-direction:column;gap:4px;margin-bottom:8px;"></div>
                        <div style="display:flex;gap:6px;">
                            <input type="text" id="ls-fa-chat-inp" placeholder="Message to invigilator…" maxlength="200"
                                   style="flex:1;border:1px solid #dee2e6;border-radius:6px;padding:6px 10px;font-size:12px;"
                                   onkeydown="if(event.key==='Enter'){window.__lsFaSendMsg();}">
                            <button onclick="window.__lsFaSendMsg()"
                                style="background:#0f6cbf;color:#fff;border:none;border-radius:6px;padding:6px 12px;font-size:12px;cursor:pointer;">
                                Send
                            </button>
                        </div>
                    </div>
                </div>
                <!-- Manual confirmation checkbox -->
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

        function renderPhotoIdStep() {
            return `<p style="margin-bottom:12px;">Upload a clear photo of your <strong>government-issued ID</strong> (passport, national ID card, or driver's licence).</p>
                <div style="border:2px solid #0f6cbf;border-radius:8px;padding:14px;background:#f0f7ff;margin-bottom:12px;">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                        <strong style="font-size:13px;">Upload Photo ID</strong>
                        <span style="background:#dc3545;color:#fff;font-size:10px;font-weight:700;padding:2px 7px;border-radius:10px;">REQUIRED</span>
                    </div>
                    <p style="font-size:12px;color:#6c757d;margin:0 0 10px;">A clear, unobstructed image of your ID — name and photo must be visible.</p>
                    <div style="display:flex;gap:10px;align-items:flex-start;">
                        <div style="flex:1;">
                            <input type="file" id="ls-photoid-input" accept="image/*"
                                   style="width:100%;font-size:13px;padding:4px;border:1px solid #ccc;border-radius:4px;">
                        </div>
                        <img id="ls-photoid-preview" src="" alt="" style="display:none;width:80px;height:80px;object-fit:cover;border-radius:6px;border:2px solid #28a745;">
                    </div>
                    <div id="ls-photoid-status" style="font-size:12px;color:#6c757d;margin-top:8px;">⚠️ Photo ID not yet uploaded — required to continue.</div>
                </div>
                <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:10px 14px;font-size:12px;">
                    <strong>Tips for a good ID photo:</strong><br>
                    • Ensure the text on the ID is readable<br>
                    • Take the photo in good lighting — no glare<br>
                    • The full ID card/passport must be in the frame
                </div>`;
        }

        function renderScreenShareStep() {
            return `<div style="text-align:center;padding:10px 0;">
                <div style="font-size:56px;margin-bottom:12px;">🖥</div>
                <h4>Screen Sharing — Required</h4>
                <p style="color:#555;margin-bottom:6px;">
                    You must share your <strong>entire screen</strong> so the proctor can monitor your desktop during the exam.
                    Sharing a window or browser tab is <strong>not accepted</strong>.
                </p>
                <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:10px 14px;text-align:left;font-size:13px;margin:14px 0;">
                    <strong>When prompted:</strong>
                    <ul style="margin:6px 0 0;padding-left:18px;line-height:1.8;">
                        <li>Select the <strong>"Entire Screen"</strong> tab (not "Window" or "Tab")</li>
                        <li>Click <strong>Share</strong> to confirm</li>
                        <li>You cannot proceed until entire-screen sharing is active</li>
                    </ul>
                </div>
                <button id="ls-screenshare-btn" class="btn btn-primary btn-lg" style="padding:14px 36px;">
                    🖥 Start Screen Sharing
                </button>
                <div id="ls-screenshare-status" style="margin-top:10px;font-size:14px;color:#6c757d;">
                    Click above to begin screen sharing.
                </div>
            </div>`;
        }

        function renderMonitorStep() {
            return `<div style="text-align:center;padding:10px 0;">
                <div style="font-size:56px;margin-bottom:12px;">🖥</div>
                <h4>Single Screen Required</h4>
                <p style="color:#555;margin-bottom:6px;">
                    Only one monitor is permitted during this exam. External displays must be disconnected.
                </p>
                <div id="ls-monitor-result" style="min-height:60px;padding:8px 0;">Checking…</div>
                <div style="background:#f8f9fa;border-radius:6px;padding:10px 14px;font-size:13px;text-align:left;margin-top:10px;">
                    <strong>If you have an external monitor:</strong>
                    <ul style="margin:6px 0 0;padding-left:18px;line-height:1.8;">
                        <li>Disconnect it now</li>
                        <li>Close any software that mirrors your display</li>
                    </ul>
                </div>
            </div>`;
        }

        // -----------------------------------------------------------------------
        // Check logic
        // -----------------------------------------------------------------------
        function hookCloseTabsConfirm() {
            const chk  = document.getElementById('ls-closetabs-confirm');
            const next = document.getElementById('ls-wiz-next');
            if (!chk) { return; }
            chk.addEventListener('change', function() { next.disabled = !this.checked; });
        }

        function hookWelcomeAck() {
            const chk  = document.getElementById('ls-welcome-ack');
            const next = document.getElementById('ls-wiz-next');
            if (!chk) { return; }
            chk.addEventListener('change', function() { next.disabled = !this.checked; });
        }

        function hookPhotoId() {
            const input    = document.getElementById('ls-photoid-input');
            const preview  = document.getElementById('ls-photoid-preview');
            const statusEl = document.getElementById('ls-photoid-status');
            const next     = document.getElementById('ls-wiz-next');
            if (!input) { return; }
            input.addEventListener('change', function() {
                const file = this.files[0];
                if (!file) { return; }
                const reader = new FileReader();
                reader.onloadend = function() {
                    if (preview) { preview.src = reader.result; preview.style.display = 'block'; }
                    // Upload passport photo via WS (same send_camshot endpoint, type=photo_id)
                    Ajax.call([{
                        methodname: 'quizaccess_learnshield_send_camshot',
                        args: {
                            courseid: cfg.courseid || 0, screenshotid: 0,
                            quizid: cfg.cmid || 0,
                            webcampicture: reader.result, imagetype: 3,
                            parenttype: 'photo_id', faceimage: '', facefound: 0,
                        },
                    }])[0].done(function() {
                        if (statusEl) { statusEl.innerHTML = checkResult(true, '✅ Photo ID uploaded successfully.'); }
                        if (next) { next.disabled = false; }
                    }).fail(function() {
                        if (statusEl) { statusEl.innerHTML = checkResult(false, '⚠️ Upload failed — please retry or continue.'); }
                        if (next) { next.disabled = false; } // Allow continue even on fail
                    });
                    if (statusEl) { statusEl.textContent = 'Uploading…'; }
                };
                reader.readAsDataURL(file);
            });
        }

        var lsScreenStream = null;

        function runScreenShare() {
            const btn      = document.getElementById('ls-screenshare-btn');
            const statusEl = document.getElementById('ls-screenshare-status');
            const next     = document.getElementById('ls-wiz-next');
            if (!btn) { return; }

            btn.addEventListener('click', function() {
                btn.disabled = true;
                statusEl.textContent = 'Waiting for screen sharing permission…';

                navigator.mediaDevices.getDisplayMedia({video: {cursor: 'always'}, audio: false})
                    .then(function(stream) {
                        // Verify entire screen was shared (displaySurface === 'monitor').
                        const track = stream.getVideoTracks()[0];
                        const settings = track && track.getSettings ? track.getSettings() : {};
                        const isEntireScreen = !settings.displaySurface || settings.displaySurface === 'monitor';

                        if (!isEntireScreen) {
                            stream.getTracks().forEach(function(t) { t.stop(); });
                            statusEl.innerHTML = checkResult(false,
                                '🚫 You must share your <strong>entire screen</strong>, not a window or tab.<br>' +
                                'Click "Start Screen Sharing" again and select the "Entire Screen" option.');
                            btn.disabled = false;
                            next.disabled = true;
                            return;
                        }

                        lsScreenStream = stream;
                        btn.style.display = 'none';
                        statusEl.innerHTML = checkResult(true, '✅ Entire screen is being shared. Screen sharing active.');
                        next.disabled = false;

                        // If learner stops sharing, block next.
                        track.addEventListener('ended', function() {
                            statusEl.innerHTML = checkResult(false,
                                '⚠️ Screen sharing stopped. You must re-share your screen to continue.');
                            btn.style.display = 'inline-block';
                            btn.disabled = false;
                            next.disabled = true;
                        });
                    })
                    .catch(function(e) {
                        statusEl.innerHTML = checkResult(false,
                            '🚫 Screen sharing is required for this exam.<br>' +
                            'Reason: ' + e.message + '<br>Please try again.');
                        btn.disabled = false;
                        next.disabled = true;
                    });
            });
        }

        function runMonitorCheck() {
            const resultEl = document.getElementById('ls-monitor-result');
            const extended = ('isExtended' in window.screen) && window.screen.isExtended;
            const heuristic = window.screen.width > 2560;
            if (extended || heuristic) {
                if (resultEl) { resultEl.innerHTML = checkResult(false,
                    '⚠️ Multiple monitors detected (screen width: ' + window.screen.width + 'px).<br>' +
                    'Disconnect your external display and click Continue.'); }
            } else {
                if (resultEl) { resultEl.innerHTML = checkResult(true,
                    '✅ Single screen confirmed (' + window.screen.width + '×' + window.screen.height + ').'); }
            }
            // Allow continue regardless (proctor will review if flagged).
            setStepDone();
        }

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
                    statusEl.innerHTML = checkResult(true, '✓ Camera is working');
                    document.getElementById('ls-wiz-next').disabled = false;
                })
                .catch(function(e) {
                    statusEl.innerHTML = checkResult(false,
                        '🚫 Camera access denied: ' + e.message + '<br>' +
                        '<strong>Camera is required to take this exam.</strong> Allow camera access in your browser settings and retry.');
                    // Camera is MANDATORY — keep next button disabled, add retry.
                    const btn = document.createElement('button');
                    btn.className = 'btn btn-warning mt-2';
                    btn.textContent = '↺ Retry Camera Access';
                    btn.onclick = function() {
                        btn.remove();
                        statusEl.textContent = 'Requesting camera access…';
                        runCameraCheck();
                    };
                    statusEl.after(btn);
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

                        if (meter) { meter.style.width = pct + '%'; meter.style.background = level > 8 ? '#dc3545' : '#28a745'; }
                        if (label) { label.textContent = level > 8 ? '🔊 Noise' : (level > 3 ? '🔉' : '🔇 Quiet'); }

                        if (!bgDone) {
                            bgReadings.push(level);
                            if (bgReadings.length >= 10) {
                                bgDone = true;
                                const avg = bgReadings.reduce((a, b) => a + b) / bgReadings.length;
                                if (avg > 10) { // Strict: any noticeable background noise fails
                                    clearInterval(interval);
                                    statusEl.innerHTML = checkResult(false,
                                        '⚠️ Background noise detected (level: ' + Math.round(avg) + ').<br>' +
                                        'Please move to a quieter location. Even low background noise is flagged during the exam.');
                                    const retryBtn = document.createElement('button');
                                    retryBtn.className = 'btn btn-warning mt-2';
                                    retryBtn.textContent = '↺ Retry Audio Check';
                                    retryBtn.onclick = function() { retryBtn.remove(); runAudioCheck(); };
                                    statusEl.after(retryBtn);
                                    return;
                                }
                                if (promptEl) { promptEl.style.display = 'block'; promptEl.textContent = '✓ Quiet environment confirmed. Now say "I am ready to begin" into your microphone.'; }
                                statusEl.textContent = '🎤 Please speak now to confirm your microphone works…';
                            }
                        } else if (!spoken && level > 8) {
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
                    await faceapi.nets.tinyFaceDetector.loadFromUri(cfg.modelurl);
                    // eslint-disable-next-line no-undef
                    await faceapi.nets.faceLandmark68TinyNet.loadFromUri(cfg.modelurl);
                    // eslint-disable-next-line no-undef
                    await faceapi.nets.faceRecognitionNet.loadFromUri(cfg.modelurl);
                    faceApiLoaded = true;
                } catch (e) { console.warn('face-api load failed', e); }
            }

            if (!faceApiLoaded) {
                statusEl.innerHTML = '<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:10px 14px;font-size:13px;">' +
                    '⚠️ Automated face detection is unavailable.<br>' +
                    'Ensure your face is <strong>clearly visible</strong>, centred, and well-lit.<br>' +
                    'Tick the confirmation checkbox below to proceed.</div>';
                return;
            }

            statusEl.textContent = '🔍 Scanning for your face…';
            let attempts = 0, faceConfirmed = false, blinkDetected = false, prevEar = null, blocked = false;
            let retryCount = 0;

            const interval = setInterval(async function() {
                if (blocked) { return; }
                attempts++;
                if (!video2 || video2.readyState < 2) { return; }

                try {
                    // eslint-disable-next-line no-undef
                    // eslint-disable-next-line no-undef
                    const dets = await faceapi.detectAllFaces(video2, new faceapi.TinyFaceDetectorOptions()).withFaceLandmarks(true);

                    // Multiple faces — block.
                    if (dets.length > 1) {
                        blocked = true;
                        clearInterval(interval);
                        statusEl.innerHTML = checkResult(false,
                            '🚫 ' + dets.length + ' faces detected. Only the exam candidate may be present.<br>' +
                            'Ensure you are alone, then retry.');
                        if (confirmChk) { confirmChk.checked = false; confirmChk.disabled = true; }
                        document.getElementById('ls-wiz-next').disabled = true;
                        retryCount++;
                        addRetryBtn(statusEl, function() {
                            blocked = false; faceConfirmed = false; blinkDetected = false; prevEar = null; attempts = 0;
                            if (retryCount >= 3 && cfg.faceapprovalmode === 'manual') {
                                showFaceApprovalRequest(video2);
                            } else {
                                runFaceCheck();
                            }
                        });
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
                        if (livenessEl) { livenessEl.style.background = '#d4edda'; livenessEl.innerHTML = '✅ <strong>Liveness confirmed</strong> — blink detected.'; }
                        // Store face descriptor for identity matching during the exam.
                        if (typeof faceapi !== 'undefined') {
                            // eslint-disable-next-line no-undef
                            // eslint-disable-next-line no-undef
                    faceapi.detectSingleFace(video2, new faceapi.TinyFaceDetectorOptions()).withFaceLandmarks(true).withFaceDescriptor()
                                .then(function(det) {
                                    if (det && det.descriptor) {
                                        try {
                                            sessionStorage.setItem('ls_face_desc',
                                                JSON.stringify(Array.from(det.descriptor)));
                                        } catch (e) {}
                                    }
                                }).catch(function() {});
                        }
                        if (confirmChk) { confirmChk.checked = true; confirmChk.disabled = false; document.getElementById('ls-wiz-next').disabled = false; }
                    }
                } catch (e) { /* skip frame */ }
            }, 500);

            // After 20 s: allow manual checkbox; offer approval only in manual mode.
            setTimeout(function() {
                if (!blocked) {
                    clearInterval(interval);
                    if (!faceConfirmed) {
                        retryCount++;
                        statusEl.innerHTML = checkResult(false, 'Face not confirmed. Tick the checkbox below if your face IS visible.');
                        if (retryCount >= 3 && cfg.faceapprovalmode === 'manual') {
                            showFaceApprovalRequest(video2);
                        }
                    } else if (!blinkDetected) {
                        if (livenessEl) { livenessEl.innerHTML = '⚠️ Liveness inconclusive — tick the checkbox below to confirm.'; }
                    }
                }
            }, 20000);
        }

        // -----------------------------------------------------------------------
        // Face approval — request invigilator review
        // -----------------------------------------------------------------------
        var faceApprovalId   = 0;
        var faceApprovalPoll = null;

        function showFaceApprovalRequest(video2) {
            const panel = document.getElementById('ls-face-approval-panel');
            if (!panel || panel.style.display !== 'none') { return; }

            // Capture snapshot for invigilator to review.
            let passportData = '';
            try {
                const c = document.createElement('canvas');
                c.width = 320; c.height = 240;
                c.getContext('2d').drawImage(video2, 0, 0, 320, 240);
                passportData = c.toDataURL('image/jpeg', 0.8);
            } catch (e) {}

            panel.style.display = 'block';

            Ajax.call([{
                methodname: 'quizaccess_learnshield_request_face_approval',
                args: {
                    cmid:         cfg.cmid || 0,
                    matchpct:     0,
                    passportdata: passportData,
                },
            }])[0].done(function(res) {
                if (res && res.approvalid) {
                    faceApprovalId = res.approvalid;
                    pollFaceApproval();
                }
            }).fail(function() {
                document.getElementById('ls-approval-status-msg').textContent =
                    '⚠️ Could not connect to server. Use the checkbox below to proceed manually.';
            });

            // Set up chat send function.
            window.__lsFaSendMsg = function() {
                const inp = document.getElementById('ls-fa-chat-inp');
                if (!inp || !inp.value.trim() || !faceApprovalId) { return; }
                const msg = inp.value.trim();
                inp.value = '';
                Ajax.call([{
                    methodname: 'quizaccess_learnshield_face_approval_student_message',
                    args: {approvalid: faceApprovalId, message: msg},
                }])[0].done(function() {
                    appendFaMsg(msg, 'student');
                });
            };
        }

        function pollFaceApproval() {
            if (faceApprovalPoll) { clearInterval(faceApprovalPoll); }
            const statusEl = document.getElementById('ls-approval-status-msg');
            let lastMsgTs  = 0;

            faceApprovalPoll = setInterval(function() {
                if (!faceApprovalId) { return; }
                Ajax.call([{
                    methodname: 'quizaccess_learnshield_check_face_approval',
                    args: {approvalid: faceApprovalId, since: lastMsgTs},
                }])[0].done(function(res) {
                    if (!res) { return; }

                    // Append any new chat messages.
                    (res.messages || []).forEach(function(m) {
                        if (m.timecreated > lastMsgTs) {
                            lastMsgTs = m.timecreated;
                            appendFaMsg(m.message, m.sendertype);
                        }
                    });

                    if (res.status === 'approved') {
                        clearInterval(faceApprovalPoll);
                        if (statusEl) { statusEl.innerHTML = '<span style="color:#28a745;font-weight:700;">✅ Approved by invigilator — you may proceed.</span>'; }
                        const confirmChk = document.getElementById('ls-face-confirm-chk');
                        if (confirmChk) { confirmChk.checked = true; }
                        document.getElementById('ls-wiz-next').disabled = false;
                    } else if (res.status === 'denied') {
                        clearInterval(faceApprovalPoll);
                        const reason = res.reviewnote ? ' Reason: ' + res.reviewnote : '';
                        if (statusEl) { statusEl.innerHTML = '<span style="color:#dc3545;font-weight:700;">❌ Denied by invigilator.' + reason + '</span>'; }
                        document.getElementById('ls-wiz-next').disabled = true;
                    } else if (statusEl) {
                        statusEl.textContent = '⏳ Waiting for invigilator to review your face…';
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
            div.innerHTML = '<div style="font-size:10px;opacity:.7;margin-bottom:2px;">' +
                (isProctor ? 'Invigilator' : 'You') + '</div>' + escHtml(message);
            box.appendChild(div);
            box.scrollTop = box.scrollHeight;
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

            const uploadHandler = function(e) {
                if (!blob) { return; }
                e.stopImmediatePropagation();
                nextBtn.disabled = true; nextBtn.textContent = '⏳ Uploading…';
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
                        // Show success banner to learner.
                        statusEl.innerHTML = checkResult(true, '✅ Environment recording uploaded successfully! Proctors can now view your recording.');
                        const banner = document.createElement('div');
                        banner.style.cssText = 'position:fixed;top:24px;left:50%;transform:translateX(-50%);' +
                            'background:#28a745;color:#fff;padding:14px 28px;border-radius:8px;z-index:9999999;' +
                            'font-size:15px;font-weight:700;box-shadow:0 4px 16px rgba(0,0,0,.3);text-align:center;';
                        banner.textContent = '✅ Environment recording uploaded successfully!';
                        document.body.appendChild(banner);
                        setTimeout(function() { if (banner.parentNode) { banner.parentNode.removeChild(banner); } }, 4000);
                        nextBtn.textContent = 'Continue →'; nextBtn.disabled = false;
                        nextBtn.removeEventListener('click', uploadHandler);
                        handleNext();
                    }).fail(function() {
                        statusEl.innerHTML = checkResult(false, '⚠️ Upload failed. You may continue — the recording will be retried automatically.');
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

            // Report step to server (async, non-blocking).
            reportPrecheck(step.id, currentStep + 1, steps.length);

            if (step.id === 'ready') { completeWizard(); return; }
            currentStep++;
            renderStep(currentStep);
        }

        function completeWizard() {
            // Stop face approval polling if running.
            if (faceApprovalPoll) { clearInterval(faceApprovalPoll); }

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
            return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        // -----------------------------------------------------------------------
        // Public API
        // -----------------------------------------------------------------------
        return {
            launch(config) {
                cfg = config || {};
                currentStep = 0;
                faceApprovalId = 0;
                faceApprovalPoll = null;
                $('#id_proctoring').closest('.fitem').hide();
                $('#id_submitbutton').hide();
                buildOverlay();
                renderStep(0);
            },
        };
    });
