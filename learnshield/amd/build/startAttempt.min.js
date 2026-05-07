// Learn Shield V1 — Start-attempt preflight module
// Handles the webcam consent modal and optional face validation before quiz start.
//
// @package    quizaccess_learnshield
// @author     Michael Adeniran <michaeladeniransnr@gmail.com>
// @copyright  2025 Michael Adeniran
// @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

define(['jquery', 'core/ajax', 'core/notification', 'core/str'],
    function($, Ajax, Notification, Str) {

        let isCameraAllowed = false;

        $('#id_submitbutton').prop('disabled', true);

        $(function() {
            $('#id_submitbutton').prop('disabled', true);
            $('#id_proctoring').on('change', function() {
                if (this.checked && isCameraAllowed) {
                    $('#id_submitbutton').prop('disabled', false);
                } else {
                    $('#id_submitbutton').prop('disabled', true);
                }
            });
        });

        return {
            async setup(props, modelurl) {
                const width = props.imagewidth || 230;
                let height  = 0;
                let streaming = false;

                const video   = document.getElementById('ls-preflight-video');
                const canvas  = document.getElementById('ls-preflight-canvas');
                const cropimg = document.getElementById('ls-preflight-crop');

                if (!video) { return; }

                // Load face-api models if needed.
                if (modelurl) {
                    try {
                        // eslint-disable-next-line no-undef
                        await faceapi.nets.ssdMobilenetv1.loadFromUri(modelurl);
                    } catch (e) {
                        // Silently continue.
                    }
                }

                navigator.mediaDevices.getUserMedia({video: true, audio: false})
                    .then(function(stream) {
                        video.srcObject = stream;
                        video.play();
                        isCameraAllowed = true;
                    })
                    .catch(function() {
                        Notification.addNotification({
                            message: 'Please allow camera access to proceed.',
                            type: 'error',
                        });
                    });

                video.addEventListener('canplay', function() {
                    if (!streaming) {
                        height = video.videoHeight / (video.videoWidth / width);
                        if (isNaN(height)) { height = width / (4 / 3); }
                        video.setAttribute('width',  width);
                        video.setAttribute('height', height);
                        if (canvas) {
                            canvas.setAttribute('width',  width);
                            canvas.setAttribute('height', height);
                        }
                        streaming = true;
                    }
                });

                // Face validation button.
                const fcbtn = document.getElementById('fcvalidate');
                if (fcbtn) {
                    fcbtn.addEventListener('click', async function(e) {
                        e.preventDefault();
                        document.getElementById('loading_spinner').style.display = 'inline-block';
                        fcbtn.disabled = true;

                        if (canvas && width && height) {
                            canvas.width  = width;
                            canvas.height = height;
                            canvas.getContext('2d').drawImage(video, 0, 0, width, height);
                            const imagedata = canvas.toDataURL('image/png');

                            let faceImage = '';
                            let faceFound = 0;

                            if (modelurl) {
                                try {
                                    // eslint-disable-next-line no-undef
                                    const detections = await faceapi.detectAllFaces(canvas);
                                    if (detections.length > 0) {
                                        const box = detections[0].box;
                                        // eslint-disable-next-line no-undef
                                        const faces = await faceapi.extractFaces(canvas, [new faceapi.Rect(box.x, box.y, box.width, box.height)]);
                                        if (faces.length > 0) {
                                            faceImage = faces[0].toDataURL();
                                            faceFound = 1;
                                        }
                                    }
                                } catch (err) {
                                    // Continue without face detection.
                                }
                            }

                            Ajax.call([{
                                methodname: 'quizaccess_learnshield_validate_face',
                                args: {
                                    courseid:   props.courseid,
                                    quizid:     props.cmid,
                                    webcamdata: imagedata,
                                    faceimage:  faceImage,
                                    facefound:  faceFound,
                                },
                            }])[0]
                            .done(function(res) {
                                const resultEl = document.getElementById('face_validation_result');
                                if (res.validated) {
                                    if (resultEl) { resultEl.textContent = '✓ Validated'; }
                                    document.getElementById('form_activate').style.visibility = 'visible';
                                } else {
                                    if (resultEl) { resultEl.textContent = '✗ Not matched — please retry'; }
                                    fcbtn.disabled = false;
                                }
                            })
                            .fail(function() {
                                fcbtn.disabled = false;
                            })
                            .always(function() {
                                document.getElementById('loading_spinner').style.display = 'none';
                            });
                        }
                    });
                }
            },
        };
    });
