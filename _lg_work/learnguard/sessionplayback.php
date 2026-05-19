<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Session playback for mod_learnguard.
 *
 * Replays a student's exam session as a timeline:
 *  - Webcam snapshots displayed chronologically with timestamps
 *  - Proctoring events overlaid on the timeline
 *  - Click any point to see the snapshot + events at that moment
 *
 * URL: /mod/learnguard/sessionplayback.php?id={cmid}&attempt={attemptid}
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id        = required_param('id',      PARAM_INT);
$attemptid = required_param('attempt', PARAM_INT);

$cm       = get_coursemodule_from_id('learnguard', $id, 0, false, MUST_EXIST);
$course   = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$instance = $DB->get_record('learnguard', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/learnguard:viewreports', $context);

$attempt = $DB->get_record('learnguard_attempts', ['id' => $attemptid, 'learnguard' => $instance->id], '*', MUST_EXIST);
$student = $DB->get_record('user', ['id' => $attempt->userid], '*', MUST_EXIST);

$PAGE->set_url('/mod/learnguard/sessionplayback.php', ['id' => $id, 'attempt' => $attemptid]);
$PAGE->set_context($context);
$PAGE->set_title('Session Playback — ' . fullname($student));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('incourse');
$PAGE->requires->css('/mod/learnguard/styles.css');

// Fetch ALL snapshots chronologically (all types).
$snapshots = $DB->get_records_sql(
    "SELECT id, snapshot_url, snapshot_type, timemodified
       FROM {learnguard_snapshots}
      WHERE attemptid = :aid
      ORDER BY timemodified ASC",
    ['aid' => $attemptid]
);

// Fetch all events.
$events = $DB->get_records_sql(
    "SELECT id, event_type, severity, score_impact, timemodified, event_data
       FROM {learnguard_events}
      WHERE attemptid = :aid
      ORDER BY timemodified ASC",
    ['aid' => $attemptid]
);

$timestart  = $attempt->timestart;
$timefinish = $attempt->timefinish ?: time();
$duration   = $timefinish - $timestart;

echo $OUTPUT->header();
$brand = learnguard_brand();

echo learnguard_banner('Session Playback', fullname($student) . ' — ' . format_string($instance->name), '▶');

echo html_writer::link(
    new moodle_url('/mod/learnguard/attemptdetail.php', ['id' => $id, 'attempt' => $attemptid]),
    '← Back to Attempt Detail', ['class' => 'btn btn-outline-secondary btn-sm mb-3']
);

// Session info row.
$sevCol = ['low' => '#28a745', 'medium' => '#fd7e14', 'high' => '#dc3545'];
echo html_writer::start_div('d-flex gap-3 flex-wrap mb-3');
foreach ([
    ['Student',    fullname($student), '#495057'],
    ['Duration',   gmdate('H:i:s', $duration), $brand->primary],
    ['Snapshots',  count($snapshots), '#0d6efd'],
    ['Events',     count($events), '#6f42c1'],
    ['Risk Score', $attempt->risk_score . ' (' . $attempt->risk_level . ')', $sevCol[$attempt->risk_level] ?? '#6c757d'],
] as [$label, $value, $colour]) {
    echo '<div style="background:#f8f9fa;border-radius:8px;padding:10px 16px;text-align:center;min-width:100px;">';
    echo '<div style="font-size:18px;font-weight:700;color:' . $colour . ';">' . $value . '</div>';
    echo '<div style="font-size:11px;color:#6c757d;">' . $label . '</div>';
    echo '</div>';
}
echo html_writer::end_div();

if (empty($snapshots)) {
    echo html_writer::div('No webcam snapshots were recorded for this session.', 'alert alert-info');
    echo $OUTPUT->footer();
    exit;
}

// Build event map by time for quick lookup.
$eventsByTime = [];
foreach ($events as $e) {
    $relTime = $e->timemodified - $timestart;
    $bucket  = max(0, (int)floor($relTime / 30)) * 30; // 30-second buckets.
    if (!isset($eventsByTime[$bucket])) { $eventsByTime[$bucket] = []; }
    $eventsByTime[$bucket][] = $e;
}

?>
<div style="font-family:'Segoe UI',system-ui,sans-serif;">

<!-- Playback viewer -->
<div style="display:grid;grid-template-columns:1fr 280px;gap:16px;margin-bottom:20px;">

  <!-- Main snapshot display -->
  <div>
    <div style="background:#111;border-radius:10px;overflow:hidden;position:relative;">
      <img id="lg-pb-img" src="data:image/jpeg;base64,<?php echo reset($snapshots)->snapshot_url; ?>"
           style="width:100%;max-height:500px;object-fit:contain;display:block;" alt="Session snapshot">
      <div id="lg-pb-overlay" style="position:absolute;bottom:0;left:0;right:0;background:rgba(0,0,0,0.6);
           color:#fff;padding:8px 14px;font-size:13px;">
        <div id="lg-pb-time" style="font-weight:700;"></div>
        <div id="lg-pb-events" style="font-size:11px;opacity:0.9;margin-top:2px;"></div>
      </div>
    </div>
    <!-- Progress bar / scrubber -->
    <div style="margin-top:10px;">
      <input type="range" id="lg-pb-scrubber" min="0"
             max="<?php echo count($snapshots) - 1; ?>" value="0" step="1"
             style="width:100%;cursor:pointer;">
    </div>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:6px;gap:10px;">
      <button id="lg-pb-prev" class="btn btn-sm btn-outline-secondary">⏮ Prev</button>
      <button id="lg-pb-play" class="btn btn-sm btn-primary">▶ Play</button>
      <button id="lg-pb-next" class="btn btn-sm btn-outline-secondary">Next ⏭</button>
      <select id="lg-pb-speed" class="form-control form-control-sm" style="max-width:120px;">
        <option value="2000">0.5× (2s)</option>
        <option value="1000" selected>1× (1s)</option>
        <option value="500">2× (0.5s)</option>
        <option value="200">5× (0.2s)</option>
      </select>
    </div>
  </div>

  <!-- Event list -->
  <div style="border:1px solid #dee2e6;border-radius:8px;overflow:hidden;">
    <div style="background:<?php echo $brand->primary; ?>;color:#fff;padding:10px 14px;font-weight:700;font-size:13px;">
      🔔 Events (<?php echo count($events); ?>)
    </div>
    <div style="height:460px;overflow-y:auto;">
      <?php foreach ($events as $e):
          $rel = $e->timemodified - $timestart;
          $sc  = $sevCol[$e->severity] ?? '#6c757d';
      ?>
      <div style="padding:7px 10px;border-bottom:1px solid #f0f0f0;border-left:3px solid <?php echo $sc; ?>;font-size:12px;cursor:pointer;"
           onclick="jumpToTime(<?php echo (int)($e->timemodified - $timestart); ?>)">
        <strong><?php echo s(str_replace('_', ' ', $e->event_type)); ?></strong><br>
        <span style="color:#6c757d;"><?php echo gmdate('H:i:s', max(0, $rel)); ?></span>
        <?php if ($e->score_impact > 0): ?>
        <span style="color:#dc3545;font-weight:700;">+<?php echo $e->score_impact; ?></span>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
      <?php if (empty($events)): ?>
      <p style="padding:14px;color:#6c757d;font-size:13px;">No events recorded.</p>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Thumbnail filmstrip -->
<div style="display:flex;gap:6px;overflow-x:auto;padding:8px;background:#f8f9fa;border-radius:8px;margin-bottom:16px;">
  <?php $i = 0; foreach ($snapshots as $sn):
      $rel = $sn->timemodified - $timestart;
  ?>
  <div onclick="goTo(<?php echo $i; ?>)" title="<?php echo gmdate('H:i:s', max(0, $rel)); ?>"
       style="flex-shrink:0;cursor:pointer;border:2px solid transparent;border-radius:4px;overflow:hidden;transition:border-color .15s;"
       class="lg-pb-thumb" id="lg-thumb-<?php echo $i; ?>">
    <img src="data:image/jpeg;base64,<?php echo $sn->snapshot_url; ?>"
         style="width:80px;height:60px;object-fit:cover;display:block;" alt="">
    <div style="font-size:9px;text-align:center;color:#6c757d;padding:2px;"><?php echo gmdate('i:s', max(0, $rel)); ?></div>
  </div>
  <?php $i++; endforeach; ?>
</div>

</div><!-- font-family wrapper -->

<script>
(function(){
var snaps = <?php
    $snapData = [];
    $i = 0;
    foreach ($snapshots as $sn) {
        $snapData[] = [
            'src'  => 'data:image/jpeg;base64,' . $sn->snapshot_url,
            'time' => (int)($sn->timemodified - $timestart),
            'ts'   => userdate($sn->timemodified, '%H:%M:%S'),
        ];
        $i++;
    }
    echo json_encode($snapData);
?>;

var evtMap = <?php echo json_encode($eventsByTime); ?>;
var current = 0;
var playing = false;
var playTimer = null;

var img     = document.getElementById('lg-pb-img');
var scrub   = document.getElementById('lg-pb-scrubber');
var timeEl  = document.getElementById('lg-pb-time');
var evtsEl  = document.getElementById('lg-pb-events');
var playBtn = document.getElementById('lg-pb-play');

function goTo(idx) {
    if (idx < 0 || idx >= snaps.length) return;
    current = idx;
    var s = snaps[idx];
    img.src = s.src;
    if (timeEl) timeEl.textContent = s.ts + '  (+' + s.time + 's)';
    scrub.value = idx;

    // Highlight events near this time.
    var bucket = Math.floor(s.time / 30) * 30;
    var nearby = evtMap[bucket] || [];
    if (evtsEl) {
        evtsEl.textContent = nearby.map(function(e) {
            return e.event_type.replace(/_/g,' ');
        }).join(' · ') || '';
    }

    // Highlight filmstrip thumb.
    document.querySelectorAll('.lg-pb-thumb').forEach(function(t, i) {
        t.style.borderColor = i === idx ? '#0f6cbf' : 'transparent';
    });

    // Scroll filmstrip.
    var thumb = document.getElementById('lg-thumb-' + idx);
    if (thumb) thumb.scrollIntoView({block:'nearest', inline:'center', behavior:'smooth'});
}

function jumpToTime(relSeconds) {
    var closest = 0; var minDiff = Infinity;
    snaps.forEach(function(s, i) {
        var d = Math.abs(s.time - relSeconds);
        if (d < minDiff) { minDiff = d; closest = i; }
    });
    goTo(closest);
}
window.jumpToTime = jumpToTime;
window.goTo = goTo;

function togglePlay() {
    if (playing) {
        playing = false; clearInterval(playTimer); playBtn.textContent = '▶ Play';
    } else {
        playing = true; playBtn.textContent = '⏸ Pause';
        var speed = parseInt(document.getElementById('lg-pb-speed').value, 10);
        playTimer = setInterval(function() {
            if (current < snaps.length - 1) { goTo(current + 1); }
            else { playing = false; clearInterval(playTimer); playBtn.textContent = '▶ Play'; }
        }, speed);
    }
}

document.getElementById('lg-pb-play').addEventListener('click', togglePlay);
document.getElementById('lg-pb-prev').addEventListener('click', function(){ goTo(current - 1); });
document.getElementById('lg-pb-next').addEventListener('click', function(){ goTo(current + 1); });
scrub.addEventListener('input', function(){ goTo(parseInt(this.value, 10)); });

goTo(0);
})();
</script>

<?php echo $OUTPUT->footer();
