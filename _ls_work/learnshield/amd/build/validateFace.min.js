// Learn Shield V1 — Face validation (admin side)
//
// @package    quizaccess_learnshield
// @author     Michael Adeniran <michaeladeniransnr@gmail.com>
// @copyright  2025 Michael Adeniran
// @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

define(['jquery', 'core/ajax', 'core/notification'], function($, Ajax, Notification) {
    return {
        init(props, modelurl) {
            const btn = document.getElementById('fcvalidate');
            if (!btn) { return; }

            btn.addEventListener('click', async function(e) {
                e.preventDefault();
                const spinner = document.getElementById('loading_spinner');
                if (spinner) { spinner.style.display = 'inline-block'; }
                btn.disabled = true;

                const video = document.getElementById('ls-preflight-video');
                const canvas = document.getElementById('ls-preflight-canvas');
                if (!video || !canvas) {
                    btn.disabled = false;
                    if (spinner) { spinner.style.display = 'none'; }
                    return;
                }

                canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
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
                            if (faces.length > 0) { faceImage = faces[0].toDataURL(); faceFound = 1; }
                        }
                    } catch (err) {}
                }

                Ajax.call([{
                    methodname: 'quizaccess_learnshield_validate_face',
                    args: {
                        courseid: props.courseid || 0,
                        quizid:   props.cmid || 0,
                        webcamdata: imagedata,
                        faceimage: faceImage,
                        facefound: faceFound,
                    },
                }])[0]
                .done(function(res) {
                    const resultEl = document.getElementById('face_validation_result');
                    if (res.validated) {
                        if (resultEl) { resultEl.textContent = '✓ Identity Verified'; }
                        const formActivate = document.getElementById('form_activate');
                        if (formActivate) { formActivate.style.visibility = 'visible'; }
                    } else {
                        if (resultEl) { resultEl.textContent = '✗ Face not matched. Please retry.'; }
                        btn.disabled = false;
                    }
                })
                .fail(function() { btn.disabled = false; })
                .always(function() {
                    if (spinner) { spinner.style.display = 'none'; }
                });
            });
        },
    };
});
