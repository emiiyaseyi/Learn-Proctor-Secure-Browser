// Learn Shield — Start-attempt interceptor
//
// Flow:
//   1. Learner clicks "Attempt quiz" → Moodle shows time-limit / preflight modal (normal behaviour)
//   2. Learner reads the modal, then clicks "Start attempt"
//   3. THIS intercepts that click, DESTROYS Moodle's modal (removes its focus trap),
//      then launches the precheck wizard in its place
//   4. After all precheck steps pass → attempt starts automatically
//
// WHY we destroy the modal:
//   Moodle 4.x modals use a focus trap that captures ALL keyboard events and keeps
//   them inside the modal. If we just show our wizard overlay on top, the focus trap
//   continues running and prevents any typing in our wizard's textareas.
//   Solution: remove the modal and its backdrop before launching our wizard.
//
// @package    quizaccess_learnshield
// @author     Michael Adeniran <michaeladeniransnr@gmail.com>
// @copyright  2025 Michael Adeniran
// @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

define(['jquery', 'quizaccess_learnshield/preexamwizard'], function($, Wizard) {

    function loadScript(src) {
        if (!src || document.querySelector('script[src="' + src + '"]')) { return; }
        var s = document.createElement('script');
        s.src = src;
        s.async = true;
        document.head.appendChild(s);
    }

    // Destroy Moodle's modal so its focus trap cannot block keyboard input in our wizard.
    function destroyMoodleModal() {
        // Remove all modal backdrops.
        document.querySelectorAll('.modal-backdrop').forEach(function(el) { el.remove(); });

        // Hide / remove all visible modals.
        document.querySelectorAll('.modal.show, .modal.fade.show, [role="dialog"]').forEach(function(el) {
            el.style.display = 'none';
            el.classList.remove('show');
        });

        // Remove aria-modal and inert from body (Bootstrap/Moodle focus-trap artefacts).
        document.body.removeAttribute('aria-hidden');
        document.body.style.overflow = '';
        document.body.style.paddingRight = '';
        document.documentElement.style.overflow = '';

        // Release any focus-trap library instances (Moodle 4.3+ uses focus-trap.js).
        try {
            // Some Moodle themes store the trap instance; try common patterns.
            if (window.focusTrap && typeof window.focusTrap.deactivate === 'function') {
                window.focusTrap.deactivate();
            }
        } catch(e) {}
    }

    return {
        setup: function(props, modelurl) {
            var cmid = props.cmid || 0;

            // ── view.php: attach interceptor to "Start attempt" button in modal ──────────

            // Hide the proctoring checkbox — wizard handles consent; element must stay in DOM.
            var pcheck = document.getElementById('id_proctoring');
            if (pcheck) {
                var fitem = pcheck.closest('.fitem');
                if (fitem) { fitem.style.display = 'none'; }
            }

            // Pre-load face-api.js in background while learner reads the modal.
            if (props.faceapiurl) {
                setTimeout(function() { loadScript(props.faceapiurl); }, 500);
            }

            function attachInterceptor() {
                var btn = document.getElementById('id_submitbutton');
                if (!btn) { return false; }

                btn.addEventListener('click', function handler(e) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    btn.removeEventListener('click', handler);

                    // CRITICAL: destroy Moodle's modal focus trap before launching wizard.
                    destroyMoodleModal();

                    setTimeout(function() {
                        Wizard.launch({
                            modelurl:         modelurl || null,
                            faceapiurl:       props.faceapiurl  || null,
                            onnxurl:          props.onnxurl     || null,
                            cmid:             cmid,
                            courseid:         props.courseid    || 0,
                            quizid:           props.quizid      || 0,
                            instructions:     props.instructions || '',
                            enablefullscreen: props.enablefullscreen !== 0,
                            faceapprovalmode: props.faceapprovalmode || 'auto',
                        });
                    }, 50);
                });

                return true;
            }

            if (!attachInterceptor()) {
                var attempts = 0;
                var poll = setInterval(function() {
                    if (attachInterceptor() || ++attempts > 40) { clearInterval(poll); }
                }, 250);
            }
        },
    };
});
