// Learn Shield V1 — Pre-exam system check module
//
// @package    quizaccess_learnshield
// @author     Michael Adeniran <michaeladeniransnr@gmail.com>
// @copyright  2025 Michael Adeniran
// @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

define(['jquery', 'core/notification'], function($, Notification) {

    const CHECKS = ['webcam', 'browser', 'internet', 'permissions', 'fullscreen'];

    function setStatus(checkId, status) {
        const el = document.getElementById('ls-check-' + checkId);
        if (!el) { return; }
        el.className = 'learnshield-check-' + status;
        el.textContent = status === 'pass' ? '✓ Pass' : status === 'fail' ? '✗ Fail' : '…';
    }

    async function runChecks() {
        // Browser check.
        const ua = navigator.userAgent;
        const isModern = /Chrome\/[6-9]\d|Firefox\/[7-9]\d|Safari\/\d+/.test(ua);
        setStatus('browser', isModern ? 'pass' : 'fail');

        // Internet check.
        setStatus('internet', navigator.onLine ? 'pass' : 'fail');

        // Fullscreen support check.
        const fsSupported = !!(document.documentElement.requestFullscreen ||
                               document.documentElement.webkitRequestFullscreen);
        setStatus('fullscreen', fsSupported ? 'pass' : 'fail');

        // Webcam + permissions.
        try {
            const stream = await navigator.mediaDevices.getUserMedia({video: true});
            setStatus('webcam',      'pass');
            setStatus('permissions', 'pass');
            stream.getTracks().forEach(t => t.stop());
        } catch (e) {
            setStatus('webcam',      'fail');
            setStatus('permissions', 'fail');
        }

        // Summary.
        const fails = CHECKS.filter(id => {
            const el = document.getElementById('ls-check-' + id);
            return el && el.className.includes('fail');
        });

        const summaryEl = document.getElementById('ls-precheck-summary');
        if (summaryEl) {
            if (fails.length === 0) {
                summaryEl.className = 'alert alert-success mt-3';
                summaryEl.textContent = 'All checks passed. You may proceed.';
                const startBtn = document.getElementById('ls-start-exam-btn');
                if (startBtn) { startBtn.disabled = false; }
            } else {
                summaryEl.className = 'alert alert-danger mt-3';
                summaryEl.textContent = 'One or more checks failed: ' + fails.join(', ') + '. Please resolve issues before starting.';
            }
        }
    }

    return {
        init(cfg) {
            CHECKS.forEach(id => setStatus(id, 'pending'));
            runChecks();
        },
    };
});
