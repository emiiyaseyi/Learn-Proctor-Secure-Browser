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
 * Live proctor dashboard for Learn Shield V1.
 *
 * @package    quizaccess_learnshield
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link       https://www.linkedin.com/in/michaeladeniran/
 */

require_once(__DIR__ . '/../../../../config.php');

use quizaccess_learnshield\session_manager;
use quizaccess_learnshield\suspicion_scorer;
use quizaccess_learnshield\link_generator;

$courseid   = optional_param('courseid', 0,   PARAM_INT);
$cmid       = optional_param('cmid',    0,   PARAM_INT);
$gridsize   = optional_param('grid',    9,   PARAM_INT);   // tiles per page
$riskfilter = optional_param('risk',    '',  PARAM_ALPHA);
$gridpage   = optional_param('gpage',   0,   PARAM_INT);   // pagination page

if ($cmid && $courseid) {
    $course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    $cm      = get_coursemodule_from_id('quiz', $cmid, $courseid, false, MUST_EXIST);
    require_login($course, false, $cm);
    $context = context_module::instance($cm->id);
} elseif ($courseid) {
    $course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    require_login($course, true);
    $context = context_course::instance($courseid);
} else {
    require_login();
    $context = context_system::instance();
}

require_capability('quizaccess/learnshield:liveproctor', $context);

$PAGE->set_url(new moodle_url('/mod/quiz/accessrule/learnshield/proctordashboard.php', [
    'courseid' => $courseid, 'cmid' => $cmid,
]));
$PAGE->set_context($context);
$PAGE->set_heading(get_string('live_dashboard', 'quizaccess_learnshield'));
$PAGE->set_title(get_string('live_dashboard',   'quizaccess_learnshield'));
$PAGE->requires->css('/mod/quiz/accessrule/learnshield/styles.css');
$PAGE->requires->js_call_amd('quizaccess_learnshield/livemonitor', 'init', [[
    'courseid'   => $courseid,
    'cmid'       => $cmid,
    'gridsize'   => $gridsize,
    'riskfilter' => $riskfilter,
    'refreshurl' => (new moodle_url('/mod/quiz/accessrule/learnshield/proctordashboard.php',
        ['courseid' => $courseid, 'cmid' => $cmid]))->out(false),
]]);

echo $OUTPUT->header();
require_once(__DIR__ . '/navhelper.php');
ls_nav_bar($courseid, $cmid, 'dashboard');

// Header row with Face Approvals quick-link and Proctor View link.
$pendingFace = 0;
try {
    if ($cmid) {
        $pendingFace = (int)$DB->count_records('quizaccess_learnshield_face_approvals',
            ['cmid' => $cmid, 'status' => 'pending']);
    }
} catch (\Exception $e) {}

echo html_writer::start_div('d-flex align-items-center justify-content-between flex-wrap mb-2 gap-2');
echo html_writer::tag('h2', get_string('live_dashboard', 'quizaccess_learnshield'), ['style' => 'margin:0;font-size:20px;']);
echo html_writer::start_div('d-flex gap-2 flex-wrap');
if ($pendingFace > 0) {
    echo html_writer::link(
        new moodle_url('/mod/quiz/accessrule/learnshield/face_approvals.php', $cmid ? ['cmid' => $cmid] : []),
        '👤 Face Approvals (' . $pendingFace . ')',
        ['class' => 'btn btn-warning btn-sm', 'style' => 'font-weight:700;']
    );
}
echo html_writer::link(
    new moodle_url('/mod/quiz/accessrule/learnshield/proctorview.php'),
    '🛡 Proctor View',
    ['class' => 'btn btn-outline-primary btn-sm']
);
echo html_writer::link(
    new moodle_url('/mod/quiz/accessrule/learnshield/proctordashboard.php',
        ['courseid' => $courseid, 'cmid' => $cmid]),
    '🔄 Refresh',
    ['class' => 'btn btn-outline-secondary btn-sm']
);
echo html_writer::end_div();
echo html_writer::end_div();

// SSE alert panel — populated by JS.
echo '<div id="ls-sse-alerts" style="margin-bottom:12px;"></div>';

// Stats bar.
$activesessions  = session_manager::get_active_sessions($courseid, $cmid);
$flaggedcount    = count(array_filter($activesessions, fn($s) => $s->is_flagged));
$violationcount  = $DB->count_records_select('quizaccess_learnshield_violations',
    'courseid = :c', ['c' => $courseid]);

echo html_writer::start_div('learnshield-stats-bar');
foreach ([
    [count($activesessions), get_string('active_learners',  'quizaccess_learnshield')],
    [$flaggedcount,           get_string('flagged_sessions', 'quizaccess_learnshield')],
    [$violationcount,         get_string('total_violations', 'quizaccess_learnshield')],
] as [$num, $label]) {
    echo html_writer::start_div('learnshield-stat-card');
    echo html_writer::div($num,   'stat-number');
    echo html_writer::div($label, 'stat-label');
    echo html_writer::end_div();
}
echo html_writer::end_div();

// Controls bar.
$gridurl = new moodle_url('/mod/quiz/accessrule/learnshield/proctordashboard.php',
    ['courseid' => $courseid, 'cmid' => $cmid]);
echo html_writer::start_div('d-flex align-items-center gap-2 mb-3 flex-wrap');

// Grid size buttons — 4, 9, 16, 20, 40, 60.
$gridsizes = [4 => '4', 9 => '9', 16 => '16', 20 => '20', 40 => '40', 60 => '60'];
foreach ($gridsizes as $size => $label) {
    $active = ($gridsize == $size) ? 'btn-primary' : 'btn-outline-secondary';
    echo html_writer::link(
        new moodle_url($gridurl, ['grid' => $size, 'risk' => $riskfilter]),
        $label . ' per page',
        ['class' => "btn btn-sm {$active}"]
    );
}

echo html_writer::tag('span', '|', ['class' => 'text-muted mx-1']);

// Risk filter.
foreach (['' => 'All Risk', 'low' => 'Low', 'medium' => 'Medium', 'high' => 'High'] as $val => $label) {
    $active = ($riskfilter === $val) ? 'btn-warning' : 'btn-outline-secondary';
    echo html_writer::link(
        new moodle_url($gridurl, ['grid' => $gridsize, 'risk' => $val, 'gpage' => 0]),
        $label,
        ['class' => "btn btn-sm {$active}"]
    );
}

echo html_writer::end_div();

// Alert panel (populated by JS).
echo html_writer::div('', 'learnshield-alert-panel mb-3', ['id' => 'ls-alert-panel']);

// Apply risk filter and pagination to sessions.
if ($riskfilter) {
    $activesessions = array_filter($activesessions, fn($s) => $s->risk_level === $riskfilter);
}
$totalSessions = count($activesessions);
$totalPages    = $gridsize > 0 ? (int)ceil($totalSessions / $gridsize) : 1;
$gridpage      = max(0, min($gridpage, $totalPages - 1));
$pagedSessions = array_slice(array_values($activesessions), $gridpage * $gridsize, $gridsize);

// Determine grid CSS columns.
$cols = $gridsize >= 40 ? 8 : ($gridsize >= 20 ? 5 : ($gridsize >= 16 ? 4 : ($gridsize >= 9 ? 3 : 2)));
$gridcss = "display:grid;grid-template-columns:repeat({$cols},1fr);gap:12px;";

echo html_writer::start_div('', ['id' => 'ls-session-grid', 'style' => $gridcss]);

if (empty($activesessions)) {
    echo html_writer::div(
        html_writer::tag('p', get_string('no_active_sessions', 'quizaccess_learnshield'), ['class' => 'text-muted']),
        'col-12 text-center p-5'
    );
} else {
    foreach ($pagedSessions as $session) {

        $riskclass   = suspicion_scorer::risk_class($session->risk_level);
        $tileclass   = 'learnshield-tile risk-' . $session->risk_level . ($session->is_flagged ? ' flagged' : '');
        $snapshoturl = $session->latest_snapshot ?? '';

        echo html_writer::start_div($tileclass, [
            'data-sessionid' => $session->id,
            'data-userid'    => $session->userid,
        ]);

        // Alert dot if high risk or flagged.
        if ($session->risk_level === 'high' || $session->is_flagged) {
            echo html_writer::div(
                html_writer::span('', 'learnshield-tile-alert-dot'),
                'learnshield-tile-alerts'
            );
        }

        // Risk badge.
        echo html_writer::div(
            html_writer::span(ucfirst($session->risk_level), "learnshield-risk-badge {$riskclass}"),
            'learnshield-tile-risk'
        );

        // Snapshot.
        if ($snapshoturl) {
            echo html_writer::empty_tag('img', [
                'src'   => $snapshoturl,
                'class' => 'learnshield-tile-snapshot',
                'alt'   => fullname($session),
            ]);
        } else {
            echo html_writer::div(
                html_writer::tag('i', '', ['class' => 'fa fa-user fa-3x text-muted']),
                'learnshield-tile-snapshot d-flex align-items-center justify-content-center'
            );
        }

        // Info bar.
        echo html_writer::start_div('learnshield-tile-info');
        echo html_writer::div(s(fullname($session)), 'learnshield-tile-name');
        echo html_writer::div(
            'Score: ' . $session->risk_score . ' | Tabs: ' . $session->tab_switches,
            'learnshield-tile-meta'
        );
        echo html_writer::end_div();

        echo html_writer::end_div(); // .learnshield-tile
    }
}

echo html_writer::end_div(); // #ls-session-grid

// Pagination controls.
if ($totalPages > 1) {
    echo html_writer::start_div('d-flex gap-2 mt-3 align-items-center flex-wrap');
    echo html_writer::tag('span', "Page " . ($gridpage + 1) . " of {$totalPages} ({$totalSessions} total)", ['class' => 'text-muted small mr-2']);
    for ($p = 0; $p < $totalPages; $p++) {
        $active = ($p === $gridpage) ? 'btn-primary' : 'btn-outline-secondary';
        echo html_writer::link(
            new moodle_url($gridurl, ['grid' => $gridsize, 'risk' => $riskfilter, 'gpage' => $p]),
            $p + 1,
            ['class' => "btn btn-sm {$active}"]
        );
    }
    if ($gridpage < $totalPages - 1) {
        echo html_writer::link(
            new moodle_url($gridurl, ['grid' => $gridsize, 'risk' => $riskfilter, 'gpage' => $gridpage + 1]),
            'Next →', ['class' => 'btn btn-sm btn-outline-secondary']
        );
    }
    echo html_writer::end_div();
}

// Chat panel placeholder (populated when a tile is clicked).
echo html_writer::div('', 'mt-4', ['id' => 'ls-chat-container']);

// SSE real-time alert notifications.
$sseUrl = (new moodle_url('/mod/quiz/accessrule/learnshield/proctor_sse.php', [
    'courseid' => $courseid,
    'sesskey'  => sesskey(),
]))->out(false);
?>
<style>
.ls-sse-alert { padding:10px 14px; border-radius:8px; margin-bottom:8px; font-size:13px; display:flex; align-items:center; gap:10px; animation:lsSlidein .3s ease; }
.ls-sse-alert.warning { background:#fff3cd; border-left:4px solid #ffc107; color:#856404; }
.ls-sse-alert.danger  { background:#f8d7da; border-left:4px solid #dc3545; color:#721c24; }
@keyframes lsSlidein { from{opacity:0;transform:translateY(-6px)} to{opacity:1;transform:none} }
</style>
<script>
(function(){
var sseUrl  = <?php echo json_encode($sseUrl); ?>;
var alertEl = document.getElementById('ls-sse-alerts');
var lastTs  = Math.floor(Date.now()/1000) - 30;
var STALE   = 8000; // 8 s with no SSE heartbeat → fall back to XHR
var sseAlive = false;
var sseTimer = null;

function showAlert(ev) {
    if (!alertEl) { return; }
    var cls = ev.severity === 'danger' ? 'danger' : 'warning';
    var div = document.createElement('div');
    div.className = 'ls-sse-alert ' + cls;
    div.innerHTML = '<strong>' + (ev.fullname || 'Student') + ':</strong> ' +
        ev.event_type.replace(/_/g,' ') + ' (' + new Date(ev.ts*1000).toLocaleTimeString() + ')';
    alertEl.insertBefore(div, alertEl.firstChild);
    // Keep max 5 alerts visible.
    while (alertEl.children.length > 5) { alertEl.removeChild(alertEl.lastChild); }
    // Auto-dismiss after 30 s.
    setTimeout(function(){ if(div.parentNode) div.parentNode.removeChild(div); }, 30000);
}

function processAlerts(data) {
    (data.alerts || []).forEach(function(a) {
        if (a.ts > lastTs) { lastTs = a.ts; showAlert(a); }
    });
    if (data.since) { lastTs = Math.max(lastTs, data.since); }
}

// XHR fallback polling.
function xhrPoll() {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', <?php echo json_encode((new moodle_url('/mod/quiz/accessrule/learnshield/proctor_sse.php',
        ['courseid' => $courseid, 'sesskey' => sesskey()]))->out(false)); ?> + '&since=' + lastTs, true);
    xhr.onload = function() {
        try {
            // Parse SSE data lines manually for XHR fallback.
            var lines = xhr.responseText.split('\n');
            lines.forEach(function(line) {
                if (line.startsWith('data:')) {
                    try { processAlerts(JSON.parse(line.slice(5))); } catch(e){}
                }
            });
        } catch(e) {}
    };
    xhr.send();
}

// Try SSE first.
if (typeof EventSource !== 'undefined') {
    var es = new EventSource(sseUrl);
    es.addEventListener('alerts', function(e) {
        sseAlive = true;
        if (sseTimer) { clearTimeout(sseTimer); }
        sseTimer = setTimeout(function() { sseAlive = false; }, STALE);
        try { processAlerts(JSON.parse(e.data)); } catch(ex){}
    });
    es.addEventListener('ping', function() {
        sseAlive = true;
        if (sseTimer) { clearTimeout(sseTimer); }
        sseTimer = setTimeout(function() { sseAlive = false; }, STALE);
    });
    es.onerror = function() { sseAlive = false; };
    // SSE liveness check: if no ping in 8 s, also poll via XHR.
    sseTimer = setTimeout(function() { sseAlive = false; }, STALE);
}

// Always run XHR backup every 5 s in case SSE drops.
setInterval(function() {
    if (!sseAlive) { xhrPoll(); }
}, 5000);
})();
</script>
<?php
echo $OUTPUT->footer();
