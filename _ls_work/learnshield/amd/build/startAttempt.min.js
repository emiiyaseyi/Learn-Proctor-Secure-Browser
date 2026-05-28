// Learn Shield V1 — Start-attempt preflight loader
// Shows a "Begin System Check" landing screen.
// Wizard ONLY launches when the student explicitly clicks the button.
// Scripts (face-api, ort) are loaded dynamically here — never in <head>.
//
// @package    quizaccess_learnshield
// @author     Michael Adeniran <michaeladeniransnr@gmail.com>
// @copyright  2025 Michael Adeniran
// @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

define(['jquery', 'quizaccess_learnshield/preexamwizard'], function($, Wizard) {

    // Dynamically inject a script tag (async, non-blocking).
    function loadScript(src, onload) {
        if (!src) { if (onload) { onload(); } return; }
        // Don't load the same script twice.
        if (document.querySelector('script[src="' + src + '"]')) { if (onload) { onload(); } return; }
        var s = document.createElement('script');
        s.src = src;
        s.async = true;
        if (onload) { s.onload = onload; }
        document.head.appendChild(s);
    }

    return {
        setup(props, modelurl) {
            // Hide Moodle's default submit button — wizard completes it.
            var submitBtn = document.getElementById('id_submitbutton');
            if (submitBtn) { submitBtn.disabled = true; submitBtn.style.display = 'none'; }

            // Hide the agreement checkbox — wizard handles consent.
            var checkRow = document.querySelector('#id_proctoring');
            if (checkRow) {
                var fitem = checkRow.closest('.fitem');
                if (fitem) { fitem.style.display = 'none'; }
            }

            // Inject the landing card.
            var placeholder = document.getElementById('ls-preflight-placeholder') || document.body;
            var card = document.createElement('div');
            card.id = 'ls-launch-card';
            card.style.cssText = 'max-width:560px;margin:20px auto;text-align:center;' +
                'border:2px solid #0f6cbf;border-radius:12px;padding:36px 28px;background:#fff;' +
                'box-shadow:0 4px 20px rgba(0,0,0,0.1);';
            card.innerHTML =
                '<div style="font-size:56px;margin-bottom:16px;">🔒</div>' +
                '<h4 style="margin-bottom:8px;font-size:20px;">This is a Proctored Exam</h4>' +
                '<p style="color:#555;margin-bottom:4px;">Before you can start, a <strong>system readiness check</strong> is required.</p>' +
                '<p style="color:#555;margin-bottom:8px;font-size:14px;">' +
                    'You will need: <strong>webcam</strong>, <strong>microphone</strong>, ' +
                    '<strong>photo ID</strong>, and <strong>fullscreen mode</strong>.' +
                '</p>' +
                '<p style="color:#888;font-size:13px;margin-bottom:24px;">The check takes approximately <strong>3–5 minutes</strong> and has 15 steps.</p>' +
                '<button id="ls-begin-check-btn" class="btn btn-success btn-lg" ' +
                    'style="font-size:18px;padding:14px 36px;border-radius:8px;">▶ Begin System Check</button>' +
                '<p style="margin-top:16px;font-size:12px;color:#aaa;">Once started, you must complete all checks to access the exam.</p>';
            placeholder.appendChild(card);

            document.getElementById('ls-begin-check-btn').addEventListener('click', function() {
                card.remove();

                // Launch wizard — scripts load async while wizard renders step 1.
                Wizard.launch({
                    modelurl:         modelurl || null,
                    faceapiurl:       props.faceapiurl || null,
                    onnxurl:          props.onnxurl || null,
                    cmid:             props.cmid || 0,
                    courseid:         props.courseid || 0,
                    instructions:     props.instructions || '',
                    enablefullscreen: props.enablefullscreen !== 0,
                    faceapprovalmode: props.faceapprovalmode || 'auto',
                });
            });

            // Pre-load face-api in background while student reads the landing card
            // so it's ready by the time they reach the face check step.
            if (props.faceapiurl) {
                setTimeout(function() { loadScript(props.faceapiurl); }, 2000);
            }
        },
    };
});
