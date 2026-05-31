// Learn Shield — Start-attempt interceptor
//
// Flow:
//   1. Learner clicks "Attempt quiz" → Moodle shows time-limit / preflight modal (normal behaviour)
//   2. Learner reads the modal, then clicks "Start attempt"
//   3. THIS intercepts that click and launches the precheck wizard instead
//   4. After all 15 precheck steps pass → attempt starts automatically
//
// @package    quizaccess_learnshield
// @author     Michael Adeniran <michaeladeniransnr@gmail.com>
// @copyright  2025 Michael Adeniran
// @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

define(['jquery', 'quizaccess_learnshield/preexamwizard'], function($, Wizard) {

    // Load a script asynchronously (idempotent — won't load twice).
    function loadScript(src) {
        if (!src || document.querySelector('script[src="' + src + '"]')) { return; }
        var s = document.createElement('script');
        s.src = src;
        s.async = true;
        document.head.appendChild(s);
    }

    return {
        setup: function(props, modelurl) {

            // Hide the proctoring checkbox from the learner — the wizard handles consent.
            // It must remain in the DOM so `completeWizard()` can tick and submit it.
            var pcheck = document.getElementById('id_proctoring');
            if (pcheck) {
                var fitem = pcheck.closest('.fitem');
                if (fitem) { fitem.style.display = 'none'; }
            }

            // Pre-load face-api.js in the background while the learner reads the
            // time-limit modal — so it's ready by the time the face-check step runs.
            if (props.faceapiurl) {
                setTimeout(function() { loadScript(props.faceapiurl); }, 500);
            }

            // ── Intercept the "Start attempt" button in the modal ─────────────────
            // In Moodle 4.x the preflight form (containing id_submitbutton) is
            // rendered in the DOM on page load, then revealed inside a JS modal.
            // We attach a one-shot click handler to intercept the "Start attempt"
            // click and launch the precheck wizard instead.

            function attachInterceptor() {
                var btn = document.getElementById('id_submitbutton');
                if (!btn) { return false; }

                btn.addEventListener('click', function handler(e) {
                    // Only intercept if the wizard hasn't already completed.
                    if (sessionStorage.getItem('ls_precheck_done_' + (props.cmid || 0))) {
                        // Precheck already done this session — let the click through.
                        return;
                    }

                    e.preventDefault();
                    e.stopImmediatePropagation();

                    // Remove handler so it doesn't fire again on the post-wizard submit.
                    btn.removeEventListener('click', handler);

                    // Launch the precheck wizard.
                    Wizard.launch({
                        modelurl:         modelurl || null,
                        faceapiurl:       props.faceapiurl  || null,
                        onnxurl:          props.onnxurl     || null,
                        cmid:             props.cmid        || 0,
                        courseid:         props.courseid    || 0,
                        quizid:           props.quizid      || 0,
                        instructions:     props.instructions || '',
                        enablefullscreen: props.enablefullscreen !== 0,
                        faceapprovalmode: props.faceapprovalmode || 'auto',
                    });
                });

                return true;
            }

            // Try immediately (form may already be in DOM).
            if (!attachInterceptor()) {
                // Not in DOM yet — poll until the modal injects the form.
                var attempts = 0;
                var poll = setInterval(function() {
                    if (attachInterceptor() || ++attempts > 40) {
                        clearInterval(poll);
                    }
                }, 250);
            }
        },
    };
});
