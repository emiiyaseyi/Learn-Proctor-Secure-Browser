// Learn Shield V1 — Validate admin uploaded image (face detection preview)
//
// @package    quizaccess_learnshield
// @author     Michael Adeniran <michaeladeniransnr@gmail.com>
// @copyright  2025 Michael Adeniran
// @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

define(['jquery', 'core/notification'], function($, Notification) {
    return {
        init(props, modelurl) {
            const btn = document.getElementById('ls-validate-upload-btn');
            if (!btn || !modelurl) { return; }

            btn.addEventListener('click', async function() {
                const img = document.getElementById('ls-uploaded-preview');
                if (!img) { return; }

                btn.disabled = true;
                btn.textContent = 'Checking…';

                try {
                    // eslint-disable-next-line no-undef
                    await faceapi.nets.ssdMobilenetv1.loadFromUri(modelurl);
                    // eslint-disable-next-line no-undef
                    const detections = await faceapi.detectAllFaces(img);
                    if (detections.length > 0) {
                        Notification.addNotification({message: '✓ Face detected in uploaded image.', type: 'success'});
                    } else {
                        Notification.addNotification({message: '✗ No face detected. Please upload a clearer image.', type: 'error'});
                    }
                } catch (e) {
                    Notification.addNotification({message: 'Face detection unavailable.', type: 'info'});
                } finally {
                    btn.disabled = false;
                    btn.textContent = 'Validate Face';
                }
            });
        },
    };
});
