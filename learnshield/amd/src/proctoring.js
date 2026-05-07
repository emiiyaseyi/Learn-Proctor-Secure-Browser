// Learn Shield V1 — Main proctoring module
// Runs inside the quiz attempt: webcam snapshots, fullscreen enforcement,
// tab-switch detection, keyboard blocking, clipboard monitoring.
//
// @package    quizaccess_learnshield
// @author     Michael Adeniran <michaeladeniransnr@gmail.com>
// @copyright  2025 Michael Adeniran
// @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

/* eslint-disable no-console */
define(['jquery', 'core/ajax', 'core/notification', 'core/str'],
    function($, Ajax, Notification, Str) {

        let isCameraAllowed = false;
        let sessionScore    = 0;
        let sessionId       = 0;
        let props           = {};

        const STRINGS = {};

        async function loadStrings() {
            const keys = [
                {key: 'facenotfoundoncam',            component: 'quizaccess_learnshield'},
                {key: 'wrong_during_taking_image',     component: 'quizaccess_learnshield'},
                {key: 'wrong_during_taking_screenshot',component: 'quizaccess_learnshield'},
                {key: 'enable_web_camera_before_submitting', component: 'quizaccess_learnshield'},
                {key: 'videonotavailable',             component: 'quizaccess_learnshield'},
                {key: 'fullscreen_exit_warning',       component: 'quizaccess_learnshield'},
                {key: 'tab_switch_warning',            component: 'quizaccess_learnshield'},
                {key: 'keyboard_violation_warning',    component: 'quizaccess_learnshield'},
                {key: 'clipboard_copy_warning',        component: 'quizaccess_learnshield'},
                {key: 'fullscreen_return_btn',         component: 'quizaccess_learnshield'},
            ];
            try {
                const vals = await Str.get_strings(keys);
                keys.forEach((k, i) => { STRINGS[k.key] = vals[i]; });
            } catch (e) {
                Notification.exception(e);
            }
        }

        // -----------------------------------------------------------------------
        // Violation logging (AJAX)
        // -----------------------------------------------------------------------

        function logEvent(eventType, eventData, severity) {
            Ajax.call([{
                methodname: 'quizaccess_learnshield_log_event',
                args: {
                    sessionid:  sessionId,
                    courseid:   props.courseid,
                    quizid:     props.quizid || props.cmid,
                    attemptid:  props.attemptid || 0,
                    event_type: eventType,
                    event_data: JSON.stringify(eventData || {}),
                    severity:   severity || 'info',
                },
            }])[0]
            .done(function(res) {
                if (res.scoreimpact > 0) {
                    sessionScore += res.scoreimpact;
                    updateScoreDisplay();
                }
            })
            .fail(console.error);
        }

        function updateScoreDisplay() {
            const el = document.getElementById('ls-risk-score');
            if (el) {
                el.textContent = sessionScore;
                const bar = document.getElementById('ls-risk-bar');
                if (bar) {
                    const pct = Math.min(sessionScore, 100);
                    bar.style.width = pct + '%';
                    bar.className = 'learnshield-score-bar ' +
                        (sessionScore >= 60 ? 'high' : sessionScore >= 30 ? 'medium' : 'low');
                }
            }
        }

        // -----------------------------------------------------------------------
        // Fullscreen enforcement
        // -----------------------------------------------------------------------

        function requestFullscreen() {
            const el = document.documentElement;
            if (el.requestFullscreen)            { el.requestFullscreen(); }
            else if (el.webkitRequestFullscreen) { el.webkitRequestFullscreen(); }
            else if (el.mozRequestFullScreen)    { el.mozRequestFullScreen(); }
        }

        function isFullscreen() {
            return !!(document.fullscreenElement ||
                      document.webkitFullscreenElement ||
                      document.mozFullScreenElement);
        }

        function showFullscreenWarning() {
            let overlay = document.getElementById('ls-fs-warning');
            if (!overlay) {
                overlay = document.createElement('div');
                overlay.id        = 'ls-fs-warning';
                overlay.className = 'learnshield-fs-warning';
                overlay.innerHTML = `
                    <h2>⚠️ ${STRINGS.fullscreen_exit_warning || 'Fullscreen mode required!'}</h2>
                    <p>${STRINGS.fullscreen_exit_warning || 'Please return to fullscreen mode immediately.'}</p>
                    <button class="btn btn-light btn-lg" id="ls-fs-return">
                        ${STRINGS.fullscreen_return_btn || 'Return to Fullscreen'}
                    </button>`;
                document.body.appendChild(overlay);
                document.getElementById('ls-fs-return').addEventListener('click', function() {
                    requestFullscreen();
                });
            }
            overlay.style.display = 'flex';
        }

        function hideFullscreenWarning() {
            const overlay = document.getElementById('ls-fs-warning');
            if (overlay) { overlay.style.display = 'none'; }
        }

        function setupFullscreenMonitoring() {
            if (!props.enablefullscreen) { return; }

            requestFullscreen();

            const onChange = function() {
                if (!isFullscreen()) {
                    showFullscreenWarning();
                    logEvent('fullscreen_exit', {count: 1}, 'warning');
                    showNotification(STRINGS.fullscreen_exit_warning, 'error');
                } else {
                    hideFullscreenWarning();
                }
            };

            document.addEventListener('fullscreenchange',       onChange);
            document.addEventListener('webkitfullscreenchange', onChange);
            document.addEventListener('mozfullscreenchange',    onChange);
        }

        // -----------------------------------------------------------------------
        // Tab switch detection
        // -----------------------------------------------------------------------

        function setupTabDetection() {
            if (!props.enabletabdetect) { return; }

            document.addEventListener('visibilitychange', function() {
                if (document.hidden) {
                    logEvent('tab_switch', {}, 'warning');
                    showNotification(STRINGS.tab_switch_warning, 'warning');
                }
            });

            window.addEventListener('blur', function() {
                logEvent('tab_switch', {type: 'blur'}, 'warning');
            });
        }

        // -----------------------------------------------------------------------
        // Keyboard shortcut blocking
        // -----------------------------------------------------------------------

        const BLOCKED_KEYS = [
            // Ctrl+C, V, U, P, S, A
            {ctrl: true, keys: ['c','v','u','p','s','a']},
        ];
        const BLOCKED_SINGLES = ['F12', 'PrintScreen'];

        function setupKeyboardBlocking() {
            if (!props.enablekeyblock) { return; }

            document.addEventListener('keydown', function(e) {
                const key = e.key;

                if (BLOCKED_SINGLES.includes(key)) {
                    e.preventDefault();
                    logEvent('keyboard_violation', {key}, 'warning');
                    showNotification(STRINGS.keyboard_violation_warning, 'warning');
                    return;
                }

                if (e.ctrlKey || e.metaKey) {
                    const lk = key.toLowerCase();
                    if (['c','v','u','p','s','a'].includes(lk)) {
                        e.preventDefault();
                        logEvent('keyboard_violation', {key: 'Ctrl+' + key.toUpperCase()}, 'warning');
                        showNotification(STRINGS.keyboard_violation_warning, 'warning');
                    }
                }
            });
        }

        // -----------------------------------------------------------------------
        // Clipboard monitoring
        // -----------------------------------------------------------------------

        function setupClipboardMonitoring() {
            if (!props.enableclipboard) { return; }

            document.addEventListener('copy', function() {
                logEvent('clipboard_copy', {}, 'warning');
                showNotification(STRINGS.clipboard_copy_warning, 'warning');
            });

            document.addEventListener('paste', function() {
                logEvent('clipboard_paste', {}, 'warning');
                showNotification(STRINGS.clipboard_copy_warning, 'warning');
            });
        }

        // -----------------------------------------------------------------------
        // Notification helper
        // -----------------------------------------------------------------------

        const showNotification = (message, type) => {
            Notification.addNotification({message: message || '', type: type || 'info'});
        };

        // -----------------------------------------------------------------------
        // Webcam capture (setup_attempt_page initialisation)
        // -----------------------------------------------------------------------

        return {

            async setup(propsArg, modelurl) {
                props = propsArg || {};

                await loadStrings();

                // Skip on review/summary pages.
                if (document.getElementById('page-mod-quiz-summary') ||
                    document.getElementById('page-mod-quiz-review')) {
                    return false;
                }

                const width = props.image_width || 230;
                let height  = 0;
                let streaming = false;

                // Inject risk score widget.
                const scoreHtml = `
                    <div id="ls-score-widget" style="position:fixed;bottom:90px;right:20px;
                         background:#fff;border:1px solid #dee2e6;border-radius:8px;
                         padding:8px 14px;z-index:9998;font-size:13px;min-width:140px;">
                        <div style="font-weight:600;margin-bottom:4px;">Integrity Score</div>
                        <div class="learnshield-score-bar-wrap">
                            <div id="ls-risk-bar" class="learnshield-score-bar low" style="width:0%"></div>
                        </div>
                        <div style="text-align:right;font-size:11px;color:#6c757d;">
                            <span id="ls-risk-score">0</span> pts
                        </div>
                    </div>`;
                $('body').append(scoreHtml);

                // Webcam box.
                $('body').append(`
                    <div class="learnshield-fixed-webcam-box">
                        <video id="ls-video">${STRINGS.videonotavailable || 'Video unavailable'}</video>
                        <img id="ls-cropimg" src="" alt="" style="display:none;"/>
                        <canvas id="ls-canvas" style="display:none;"></canvas>
                        <div class="learnshield-webcam-label">Learn Shield — Monitoring</div>
                    </div>`);

                const video  = document.getElementById('ls-video');
                const canvas = document.getElementById('ls-canvas');
                const cropimg = document.getElementById('ls-cropimg');

                // Draggable webcam box.
                const box = document.querySelector('.learnshield-fixed-webcam-box');
                let dragging = false;
                let ox = 0, oy = 0;
                box.addEventListener('mousedown', e => { dragging = true; ox = e.clientX - box.offsetLeft; oy = e.clientY - box.offsetTop; });
                document.addEventListener('mousemove', e => { if (dragging) { box.style.left = (e.clientX - ox) + 'px'; box.style.top  = (e.clientY - oy) + 'px'; box.style.right = 'auto'; box.style.bottom = 'auto'; } });
                document.addEventListener('mouseup', () => { dragging = false; });

                // Face API models.
                if (modelurl) {
                    try {
                        // eslint-disable-next-line no-undef
                        await faceapi.nets.ssdMobilenetv1.loadFromUri(modelurl);
                    } catch (e) {
                        console.warn('face-api model load failed:', e);
                    }
                }

                const takePicture = async() => {
                    if (!width || !height) { return; }

                    canvas.width  = width;
                    canvas.height = height;
                    canvas.getContext('2d').drawImage(video, 0, 0, width, height);
                    const imagedata = canvas.toDataURL('image/png');

                    let faceFound = 0;
                    let faceImage = '';

                    if (modelurl) {
                        try {
                            // eslint-disable-next-line no-undef
                            const detections = await faceapi.detectAllFaces(canvas);
                            if (detections.length > 0) {
                                const box2 = detections[0].box;
                                // eslint-disable-next-line no-undef
                                const faces = await faceapi.extractFaces(canvas, [new faceapi.Rect(box2.x, box2.y, box2.width, box2.height)]);
                                if (faces.length > 0) {
                                    faceImage = faces[0].toDataURL();
                                    cropimg.src = faceImage;
                                    faceFound = 1;
                                } else {
                                    logEvent('face_missing', {}, 'warning');
                                }
                            } else {
                                logEvent('face_missing', {}, 'warning');
                                showNotification(STRINGS.facenotfoundoncam, 'warning');
                            }
                        } catch (e) {
                            console.warn('Face detection error:', e);
                        }
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
                    }])[0]
                    .done(function(res) {
                        if (res.warnings && res.warnings.length > 0) {
                            showNotification(STRINGS.wrong_during_taking_image, 'error');
                        }
                    })
                    .fail(console.error);
                };

                navigator.mediaDevices.getUserMedia({video: true, audio: false})
                    .then(function(stream) {
                        video.srcObject = stream;
                        video.play();
                        isCameraAllowed = true;
                    })
                    .catch(function() {
                        $('.mod_quiz-next-nav').prop('disabled', true);
                        $('.submitbtns').html(`<p class="text-danger">${STRINGS.enable_web_camera_before_submitting}</p>`);
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

                // Security controls.
                setupFullscreenMonitoring();
                setupTabDetection();
                setupKeyboardBlocking();
                setupClipboardMonitoring();

                // Start capturing.
                const firstDelay = 3000;
                const interval   = props.camshotdelay || 30000;
                setTimeout(takePicture, firstDelay);
                setInterval(takePicture, interval);

                // Poll for chat messages every 5 seconds.
                if (sessionId) {
                    setInterval(function() {
                        Ajax.call([{
                            methodname: 'quizaccess_learnshield_get_messages',
                            args: {sessionid: sessionId, since: Math.floor(Date.now() / 1000) - 10},
                        }])[0].done(function(res) {
                            (res.messages || []).forEach(function(msg) {
                                if (msg.message_type === 'warning') {
                                    showNotification('⚠️ ' + msg.message, 'error');
                                } else {
                                    showNotification('💬 ' + msg.sender_name + ': ' + msg.message, 'info');
                                }
                            });
                        });
                    }, 5000);
                }

                return true;
            },

            // Invoked on the quiz VIEW page (before starting attempt).
            async init(propsArg) {
                props = propsArg || {};
                const width = props.image_width || 230;
                let height  = 0;
                let streaming = false;

                await loadStrings();

                $('body').append(`
                    <div class="learnshield-fixed-webcam-box">
                        <video id="ls-video">${STRINGS.videonotavailable || 'Video unavailable'}</video>
                        <canvas id="ls-canvas" style="display:none;"></canvas>
                        <div class="learnshield-webcam-label">Learn Shield</div>
                    </div>`);

                const video = document.getElementById('ls-video');

                navigator.mediaDevices.getUserMedia({video: true, audio: false})
                    .then(function(stream) {
                        video.srcObject = stream;
                        video.play();
                        isCameraAllowed = true;
                        Notification.addNotification({
                            message: propsArg.cameraallow,
                            type: 'success',
                        });
                    })
                    .catch(function() {
                        Notification.addNotification({
                            message: propsArg.allowcamerawarning,
                            type: 'warning',
                        });
                    });

                video.addEventListener('canplay', function() {
                    if (!streaming) {
                        height = video.videoHeight / (video.videoWidth / width);
                        if (isNaN(height)) { height = width / (4 / 3); }
                        video.setAttribute('width',  width);
                        video.setAttribute('height', height);
                        streaming = true;
                    }
                });
            },
        };
    });
