<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Live proctor dashboard for mod_learnguard.
 *
 * Polls the web service every 10 seconds and shows a live grid of active sessions.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT); // Course module ID.

$cm       = get_coursemodule_from_id('learnguard', $id, 0, false, MUST_EXIST);
$course   = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$instance = $DB->get_record('learnguard', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/learnguard:liveproctor', $context);

$PAGE->set_url('/mod/learnguard/proctordashboard.php', ['id' => $id]);
$PAGE->set_context($context);
$PAGE->set_title('Live Proctor — ' . format_string($instance->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->css('/mod/learnguard/styles.css');

echo $OUTPUT->header();
echo learnguard_nav_bar($id, 'Live Dashboard');

echo html_writer::start_div('', ['style' => 'font-family:system-ui,sans-serif;']);

// Topbar.
echo html_writer::start_div('d-flex align-items-center justify-content-between mb-3');
echo html_writer::tag('h2', '🔴 Live Proctor Dashboard — ' . format_string($instance->name),
    ['style' => 'margin:0;font-size:20px;']);
echo html_writer::start_div('d-flex gap-2 align-items-center');
echo html_writer::tag('span', '', ['id' => 'lg-live-dot',
    'style' => 'display:inline-block;width:10px;height:10px;border-radius:50%;background:#28a745;']);
echo html_writer::tag('span', 'Live', ['style' => 'font-size:14px;color:#28a745;font-weight:600;']);
echo html_writer::tag('span', '0 active', ['id' => 'lg-active-count', 'style' => 'font-size:14px;color:#6c757d;']);
echo html_writer::end_div();
echo html_writer::end_div();

// Grid.
echo html_writer::tag('div', '', ['id' => 'lg-live-grid',
    'style' => 'display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;']);

echo html_writer::end_div(); // font-family wrapper

// JavaScript poller.
?>
<script>
(function() {
    var cmid = <?php echo (int)$cm->id; ?>;
    var wwwroot = <?php echo json_encode($CFG->wwwroot); ?>;
    var sesskey = <?php echo json_encode(sesskey()); ?>;

    function fetchSessions() {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', wwwroot + '/lib/ajax/service.php?sesskey=' + sesskey, true);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.onload = function() {
            try {
                var resp = JSON.parse(xhr.responseText);
                if (resp && resp[0] && resp[0].data) {
                    renderGrid(resp[0].data.sessions || []);
                }
            } catch (e) {}
        };
        xhr.send(JSON.stringify([{
            index: 0,
            methodname: 'mod_learnguard_get_live_sessions',
            args: {cmid: cmid},
        }]));
    }

    var riskColours = {low: '#28a745', medium: '#fd7e14', high: '#dc3545'};

    function renderGrid(sessions) {
        var grid = document.getElementById('lg-live-grid');
        var count = document.getElementById('lg-active-count');
        if (!grid) { return; }

        if (count) { count.textContent = sessions.length + ' active'; }

        if (sessions.length === 0) {
            grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:60px;color:#6c757d;">' +
                '<div style="font-size:48px;margin-bottom:12px;">👁</div>' +
                '<p>No active sessions at the moment.</p></div>';
            return;
        }

        grid.innerHTML = '';
        sessions.forEach(function(s) {
            var rc = riskColours[s.risk_level] || '#6c757d';
            var lastSeen = Math.floor(Date.now() / 1000) - s.lastseen;
            var lastSeenStr = lastSeen < 60 ? lastSeen + 's ago' : Math.floor(lastSeen / 60) + 'm ago';

            var card = document.createElement('div');
            card.className = 'lg-session-card risk-' + s.risk_level;
            card.innerHTML =
                '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">' +
                '<div>' +
                '<span class="lg-active-dot"></span>' +
                '<strong style="font-size:14px;">' + escHtml(s.fullname) + '</strong>' +
                '</div>' +
                '<span style="background:' + rc + ';color:#fff;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;">' +
                s.risk_score + ' ' + s.risk_level.toUpperCase() +
                '</span>' +
                '</div>' +
                '<div style="font-size:12px;color:#6c757d;">' +
                '<div>Q ' + s.currentpage + ' &nbsp;|&nbsp; Attempt #' + s.attempt + '</div>' +
                '<div>Last seen: ' + lastSeenStr + '</div>' +
                '</div>' +
                '<div style="margin-top:10px;">' +
                '<a href="' + wwwroot + '/mod/learnguard/attemptdetail.php?id=' + cmid + '&attempt=' + s.attemptid + '" ' +
                'target="_blank" style="font-size:12px;color:#0f6cbf;">🔍 View Detail →</a>' +
                '</div>';

            grid.appendChild(card);
        });
    }

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // Poll every 10 seconds.
    fetchSessions();
    setInterval(fetchSessions, 10000);
})();
</script>
<?php
echo $OUTPUT->footer();
