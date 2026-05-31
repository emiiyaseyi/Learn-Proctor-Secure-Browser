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
            var precheckKey = 'ls_precheck_done_' + cmid;

            // ── startattempt.php: precheck already done — auto-submit to start quiz ──────
            // When precheck completes on view.php, the form submits to startattempt.php.
            // Our JS runs there too (is_preflight_check_required returns true for both).
            // If the flag is set, tick all checkboxes and click Start attempt automatically.
            if (sessionStorage.getItem(precheckKey)) {
                sessionStorage.removeItem(precheckKey); // Clear flag — one-shot use.

                // Tick all required preflight checkboxes (honesty check, proctoring, etc.)
                document.querySelectorAll('input[type="checkbox"]').forEach(function(c) {
                    if (!c.checked) { c.checked = true; c.dispatchEvent(new Event('change')); }
                });

                // Brief loading overlay so learner sees "starting exam".
                var overlay = document.createElement('div');
                overlay.style.cssText = 'position:fixed;inset:0;z-index:9999999;' +
                    'background:rgba(15,108,191,0.97);display:flex;flex-direction:column;' +
                    'align-items:center;justify-content:center;color:#fff;font-family:system-ui,sans-serif;';
                overlay.innerHTML = '<div style="font-size:52px;margin-bottom:16px;">🚀</div>' +
                    '<h2 style="font-size:20px;margin-bottom:8px;">All checks passed!</h2>' +
                    '<p style="font-size:14px;opacity:.85;">Starting your exam now…</p>' +
                    '<div style="margin-top:20px;width:40px;height:40px;border:4px solid rgba(255,255,255,.3);' +
                    'border-top-color:#fff;border-radius:50%;animation:ls-spin 0.8s linear infinite;"></div>';
                document.body.appendChild(overlay);

                // Auto-click the Start attempt button after a short delay.
                setTimeout(function() {
                    var btn = document.getElementById('id_submitbutton') ||
                              document.querySelector('[type="submit"]');
                    if (btn) { btn.disabled = false; btn.click(); }
                }, 400);

                return; // Don't attach interceptor on this page.
            }

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
