// Learn Shield V1 — Start-attempt preflight loader
// Shows a "Begin System Check" landing screen.
// Wizard ONLY launches when the student explicitly clicks the button.
//
// @package    quizaccess_learnshield
// @author     Michael Adeniran <michaeladeniransnr@gmail.com>
// @copyright  2025 Michael Adeniran
// @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

define(['jquery', 'quizaccess_learnshield/preexamwizard'], function($, Wizard) {
    return {
        setup(props, modelurl) {
            // Hide and disable Moodle's default submit button — wizard completes it.
            const submitBtn = document.getElementById('id_submitbutton');
            if (submitBtn) { submitBtn.disabled = true; submitBtn.style.display = 'none'; }

            // Also hide the agreement checkbox (wizard handles consent).
            const checkRow = document.querySelector('#id_proctoring')?.closest('.fitem');
            if (checkRow) { checkRow.style.display = 'none'; }

            // Inject the landing card with a single "Begin" button.
            const placeholder = document.getElementById('ls-preflight-placeholder') || document.body;
            const card = document.createElement('div');
            card.id = 'ls-launch-card';
            card.style.cssText = 'max-width:540px;margin:20px auto;text-align:center;' +
                'border:2px solid #0f6cbf;border-radius:12px;padding:36px 28px;background:#fff;';
            card.innerHTML = `
                <div style="font-size:56px;margin-bottom:16px;">🔒</div>
                <h4 style="margin-bottom:8px;">This is a Proctored Exam</h4>
                <p style="color:#555;margin-bottom:4px;">
                    Before you can start, a <strong>system readiness check</strong> is required.
                </p>
                <p style="color:#555;margin-bottom:24px;font-size:14px;">
                    You will need: <strong>webcam</strong>, <strong>microphone</strong>,
                    and <strong>fullscreen mode</strong>.
                </p>
                <button id="ls-begin-check-btn" class="btn btn-success btn-lg"
                        style="font-size:18px;padding:14px 36px;border-radius:8px;">
                    ▶ Begin System Check
                </button>
                <p style="margin-top:16px;font-size:12px;color:#aaa;">
                    The check takes approximately 2 minutes.
                </p>`;
            placeholder.appendChild(card);

            document.getElementById('ls-begin-check-btn').addEventListener('click', function() {
                card.remove();
                Wizard.launch({
                    modelurl:         modelurl || null,
                    cmid:             props.cmid || 0,
                    courseid:         props.courseid || 0,
                    instructions:     props.instructions || '',
                    enablefullscreen: props.enablefullscreen !== 0,
                    faceapprovalmode: props.faceapprovalmode || 'auto',
                });
            });
        },
    };
});
