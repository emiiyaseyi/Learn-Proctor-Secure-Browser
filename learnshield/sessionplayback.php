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
 * Session playback — replay full event timeline as a video-like scrubber.
 *
 * @package    quizaccess_learnshield
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link       https://www.linkedin.com/in/michaeladeniran/
 */

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

use quizaccess_learnshield\event_tracker;

$courseid  = required_param('courseid', PARAM_INT);
$cmid      = required_param('cmid',     PARAM_INT);
$userid    = required_param('userid',   PARAM_INT);
$sessionid = optional_param('sessionid', 0, PARAM_INT);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$cm     = get_coursemodule_from_id('quiz', $cmid, $courseid, false, MUST_EXIST);
$user   = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('quizaccess/learnshield:viewreport', $context);

// Find session.
if (!$sessionid) {
    $session = $DB->get_record('quizaccess_learnshield_sessions',
        ['courseid' => $courseid, 'quizid' => $cmid, 'userid' => $userid]);
    $sessionid = $session ? $session->id : 0;
}

// Fetch all events.
$events = $sessionid ? event_tracker::get_session_events($sessionid) : [];

// Fetch snapshots for the timeline.
$snaplogs = $DB->get_records('quizaccess_learnshield_logs',
    ['courseid' => $courseid, 'quizid' => $cmid, 'userid' => $userid],
    'timemodified ASC');
$snapshots = array_filter((array)$snaplogs, fn($l) => !empty($l->webcampicture));

// Build unified timeline: merge events and snapshots, sorted by time.
$timeline = [];
foreach ($events as $ev) {
    $timeline[] = [
        'type'    => 'event',
        'time'    => $ev->timemodified,
        'event'   => $ev->event_type,
        'severity'=> $ev->severity,
        'score'   => $ev->score_impact,
        'data'    => $ev->event_data,
    ];
}
foreach ($snapshots as $snap) {
    $timeline[] = [
        'type'   => 'snapshot',
        'time'   => $snap->timemodified,
        'url'    => $snap->webcampicture,
        'score'  => 0,
    ];
}
usort($timeline, fn($a, $b) => $a['time'] <=> $b['time']);

$starttime = $timeline ? $timeline[0]['time'] : time();
$endtime   = $timeline ? end($timeline)['time'] : time();
$duration  = max($endtime - $starttime, 1);

$PAGE->set_url(new moodle_url('/mod/quiz/accessrule/learnshield/sessionplayback.php', [
    'courseid' => $courseid, 'cmid' => $cmid, 'userid' => $userid,
]));
$PAGE->set_context($context);
$PAGE->set_heading('Session Playback — ' . fullname($user));
$PAGE->set_title('Session Playback');
$PAGE->requires->css('/mod/quiz/accessrule/learnshield/styles.css');

echo $OUTPUT->header();

echo html_writer::start_div('d-flex align-items-center gap-3 mb-3 flex-wrap');
echo html_writer::link(
    new moodle_url('/mod/quiz/accessrule/learnshield/attemptdetail.php',
        ['courseid' => $courseid, 'quizid' => $cmid, 'userid' => $userid]),
    '← Back to Attempt Report', ['class' => 'btn btn-outline-secondary btn-sm']
);
echo html_writer::tag('h3', 'Session Playback: ' . s(fullname($user)), ['class' => 'mb-0']);
echo html_writer::end_div();

if (empty($timeline)) {
    echo $OUTPUT->notification('No events recorded for this session.', 'info');
    echo $OUTPUT->footer();
    exit;
}

// Pass timeline to JS as JSON.
$timelineJson = json_encode($timeline);
$PAGE->requires->js_amd_inline("
require(['jquery'], function(\$) {
    const timeline    = {$timelineJson};
    const startTime   = {$starttime};
    const duration    = {$duration};
    let currentIndex  = 0;
    let playing       = false;
    let playInterval  = null;
    let playSpeed     = 1;

    function updateFrame(idx) {
        if (idx < 0 || idx >= timeline.length) { return; }
        const item = timeline[idx];
        const rel  = item.time - startTime;
        const pct  = Math.min((rel / duration) * 100, 100);

        // Update scrubber.
        document.getElementById('ls-scrubber').value = idx;
        document.getElementById('ls-time-display').textContent = formatTime(rel);
        document.getElementById('ls-progress-bar').style.width = pct + '%';

        // Update snapshot panel.
        if (item.type === 'snapshot') {
            document.getElementById('ls-snapshot-panel').innerHTML =
                '<img src=\"' + item.url + '\" style=\"width:100%;border-radius:6px;\" alt=\"snapshot\">';
        }

        // Update event panel.
        const eventPanel = document.getElementById('ls-event-panel');
        const color = item.severity === 'danger' ? '#dc3545' : item.severity === 'warning' ? '#fd7e14' : '#6c757d';
        eventPanel.innerHTML =
            '<div style=\"font-size:13px;\"><span style=\"color:' + color + ';font-weight:600;\">' +
            (item.event || item.type) + '</span>' +
            (item.score > 0 ? ' <span class=\"badge badge-danger\">+' + item.score + ' pts</span>' : '') +
            '<br><small class=\"text-muted\">' + new Date(item.time * 1000).toLocaleTimeString() + '</small></div>';

        // Highlight active timeline row.
        document.querySelectorAll('.ls-tl-row').forEach(function(r) { r.classList.remove('table-active'); });
        const activeRow = document.getElementById('ls-tl-' + idx);
        if (activeRow) { activeRow.classList.add('table-active'); activeRow.scrollIntoView({block:'nearest'}); }

        currentIndex = idx;
    }

    function formatTime(seconds) {
        const m = Math.floor(seconds / 60);
        const s = seconds % 60;
        return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
    }

    function play() {
        playing = true;
        document.getElementById('ls-play-btn').textContent = '⏸';
        playInterval = setInterval(function() {
            if (currentIndex >= timeline.length - 1) { pause(); return; }
            updateFrame(currentIndex + 1);
        }, 1000 / playSpeed);
    }

    function pause() {
        playing = false;
        clearInterval(playInterval);
        document.getElementById('ls-play-btn').textContent = '▶';
    }

    // Controls.
    document.getElementById('ls-play-btn').addEventListener('click', function() {
        if (playing) { pause(); } else { play(); }
    });

    document.getElementById('ls-prev-btn').addEventListener('click', function() { pause(); updateFrame(currentIndex - 1); });
    document.getElementById('ls-next-btn').addEventListener('click', function() { pause(); updateFrame(currentIndex + 1); });

    document.getElementById('ls-scrubber').addEventListener('input', function() {
        pause(); updateFrame(parseInt(this.value, 10));
    });

    document.getElementById('ls-speed-btn').addEventListener('click', function() {
        playSpeed = playSpeed === 1 ? 2 : playSpeed === 2 ? 5 : 1;
        this.textContent = playSpeed + 'x';
        if (playing) { pause(); play(); }
    });

    // Click on timeline row.
    document.querySelectorAll('.ls-tl-row').forEach(function(r) {
        r.addEventListener('click', function() { pause(); updateFrame(parseInt(r.dataset.idx, 10)); });
    });

    // Initialize at frame 0.
    document.getElementById('ls-scrubber').max = timeline.length - 1;
    updateFrame(0);
});
");

// -----------------------------------------------------------------------
// Playback UI
// -----------------------------------------------------------------------
?>
<div class="row">
    <div class="col-md-5">
        <!-- Snapshot viewer -->
        <div class="card mb-3">
            <div class="card-header font-weight-bold">📷 Webcam</div>
            <div class="card-body p-2" id="ls-snapshot-panel" style="min-height:220px;background:#111;border-radius:4px;display:flex;align-items:center;justify-content:center;color:#aaa;">
                No snapshot
            </div>
        </div>
        <!-- Current event -->
        <div class="card mb-3">
            <div class="card-header font-weight-bold">📋 Current Event</div>
            <div class="card-body" id="ls-event-panel" style="min-height:60px;">—</div>
        </div>
    </div>
    <div class="col-md-7">
        <!-- Playback controls -->
        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex gap-2 align-items-center mb-2">
                    <button id="ls-prev-btn" class="btn btn-outline-secondary btn-sm">⏮</button>
                    <button id="ls-play-btn" class="btn btn-primary btn-sm" style="min-width:48px;">▶</button>
                    <button id="ls-next-btn" class="btn btn-outline-secondary btn-sm">⏭</button>
                    <button id="ls-speed-btn" class="btn btn-outline-secondary btn-sm" title="Playback speed">1x</button>
                    <span id="ls-time-display" class="text-muted small ml-2">00:00</span>
                    <span class="text-muted small ml-auto"><?php echo count($timeline); ?> events · <?php echo gmdate('H:i:s', $duration); ?></span>
                </div>
                <!-- Progress bar -->
                <div style="background:#e9ecef;border-radius:6px;height:8px;overflow:hidden;">
                    <div id="ls-progress-bar" class="learnshield-score-bar low" style="width:0%;height:100%;transition:width .3s;"></div>
                </div>
                <!-- Scrubber -->
                <input type="range" id="ls-scrubber" min="0" max="<?php echo count($timeline) - 1; ?>" value="0"
                       class="form-control-range mt-2" style="width:100%;">
            </div>
        </div>
        <!-- Timeline events list -->
        <div class="card">
            <div class="card-header font-weight-bold">📅 Event Timeline</div>
            <div class="card-body p-0" style="max-height:340px;overflow-y:auto;">
                <table class="table table-sm table-bordered mb-0 learnshield-audit-table">
                    <thead class="thead-light">
                        <tr><th>Time</th><th>Event</th><th>Score</th></tr>
                    </thead>
                    <tbody>
                        <?php
                        foreach ($timeline as $i => $item) {
                            $rel  = $item['time'] - $starttime;
                            $ev   = $item['event'] ?? ($item['type'] === 'snapshot' ? '📷 snapshot' : $item['type']);
                            $sev  = $item['severity'] ?? 'info';
                            $cls  = match($sev) { 'danger' => 'table-danger', 'warning' => 'table-warning', default => '' };
                            ?>
                            <tr class="ls-tl-row <?php echo $cls; ?>" data-idx="<?php echo $i; ?>" id="ls-tl-<?php echo $i; ?>" style="cursor:pointer;">
                                <td><?php echo gmdate('i:s', $rel); ?></td>
                                <td><span class="event-type"><?php echo s(str_replace('_', ' ', $ev)); ?></span></td>
                                <td><?php echo $item['score'] > 0 ? '+' . $item['score'] : '—'; ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php
echo $OUTPUT->footer();
