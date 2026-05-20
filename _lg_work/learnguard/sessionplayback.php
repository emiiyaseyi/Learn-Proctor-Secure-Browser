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
    echo html_writer::div('No snapshots were recorded for this session.', 'alert alert-info');
    echo $OUTPUT->footer();
    exit;
}

// Build snapshot index array — IDs only; images loaded via snapshot.php (no inline base64).
$snapIndex = [];
foreach ($snapshots as $sn) {
    $snapIndex[] = [
        'id'   => (int)$sn->id,
        'type' => $sn->snapshot_type,
        'time' => (int)($sn->timemodified - $timestart),
        'ts'   => userdate($sn->timemodified, '%H:%M:%S'),
    ];
}

// Build event map keyed by 30-second buckets.
$eventsByTime = [];
foreach ($events as $e) {
    $relTime = max(0, (int)($e->timemodified - $timestart));
    $bucket  = (int)floor($relTime / 30) * 30;
    $eventsByTime[$bucket][] = [
        'type'   => str_replace('_', ' ', $e->event_type),
        'sev'    => $e->severity,
        'score'  => (int)$e->score_impact,
        'rel'    => $relTime,
        'ts'     => userdate($e->timemodified, '%H:%M:%S'),
    ];
}
$sevCol = ['low' => '#28a745', 'medium' => '#fd7e14', 'high' => '#dc3545'];

// Type label map for filmstrip badges.
$typeIcon = ['periodic'=>'📷','photo_id'=>'🪪','360_video'=>'🎥','screen_capture'=>'🖥',
             'random_screenshot'=>'🎲','object_cell_phone'=>'📱','background_change'=>'🖼',
             'multiple_persons'=>'👥','face'=>'👤'];

?>
<style>
.pb-wrap        { font-family:"Segoe UI",system-ui,sans-serif; }
.pb-main        { background:#111; border-radius:12px; overflow:hidden; position:relative; }
.pb-main img    { width:100%; max-height:520px; object-fit:contain; display:block; min-height:200px; background:#111; }
.pb-overlay     { position:absolute; bottom:0; left:0; right:0; background:rgba(0,0,0,.65); color:#fff; padding:10px 14px; }
.pb-scrubber    { width:100%; height:6px; cursor:pointer; accent-color:<?php echo $brand->primary; ?>; margin-top:10px; }
.pb-controls    { display:flex; align-items:center; gap:8px; margin-top:8px; flex-wrap:wrap; }
.pb-btn         { border:none; border-radius:6px; padding:7px 14px; cursor:pointer; font-size:13px; font-weight:700; }
.pb-btn-primary { background:<?php echo $brand->primary; ?>; color:#fff; }
.pb-btn-sec     { background:#f8f9fa; color:#495057; border:1px solid #dee2e6; }
.pb-filmstrip   { display:flex; gap:5px; overflow-x:auto; padding:8px; background:#1a1a2e; border-radius:8px; scrollbar-width:thin; }
.pb-thumb       { flex-shrink:0; cursor:pointer; border:2px solid transparent; border-radius:5px; overflow:hidden; transition:border-color .15s; text-align:center; }
.pb-thumb img   { width:88px; height:66px; object-fit:cover; display:block; background:#111; }
.pb-thumb.active { border-color:<?php echo $brand->primary; ?>; }
.pb-thumb.has-event { border-color:#dc3545; }
.pb-evt-list    { height:520px; overflow-y:auto; }
.pb-evt         { padding:7px 10px; border-bottom:1px solid #f0f0f0; font-size:12px; cursor:pointer; transition:background .1s; }
.pb-evt:hover   { background:#f8f9fa; }
.pb-evt.active  { background:#e8f0fe; }
</style>

<div class="pb-wrap">

<!-- ── Info row ───────────────────────────────────────────── -->
<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px;">
<?php foreach ([
    ['📽 Snapshots', count($snapshots), $brand->primary],
    ['⏱ Duration',   gmdate('H:i:s', max(1, $duration)), '#495057'],
    ['🔔 Events',    count($events), '#6f42c1'],
    ['⚠️ Risk',      ($attempt->risk_score ?? 0) . ' (' . ($attempt->risk_level ?? 'low') . ')',
     $sevCol[$attempt->risk_level ?? 'low'] ?? '#6c757d'],
] as [$lbl, $val, $col]):  ?>
<div style="background:#f8f9fa;border-radius:8px;padding:10px 16px;text-align:center;min-width:110px;border-top:3px solid <?php echo $col; ?>;">
  <div style="font-size:17px;font-weight:800;color:<?php echo $col; ?>;"><?php echo $val; ?></div>
  <div style="font-size:11px;color:#6c757d;"><?php echo $lbl; ?></div>
</div>
<?php endforeach; ?>

<div style="margin-left:auto;display:flex;align-items:center;gap:8px;">
  <span id="pb-pos" style="font-size:12px;color:#6c757d;min-width:80px;">Frame 1 / <?php echo count($snapIndex); ?></span>
  <select id="pb-type-filter" class="form-control form-control-sm" style="max-width:180px;" onchange="pbFilter(this.value)">
    <option value="">All types</option>
    <?php foreach (['periodic'=>'Webcam','screen_capture'=>'Screen','photo_id'=>'Photo ID','360_video'=>'360°','random_screenshot'=>'Random'] as $tv=>$tl): ?>
    <option value="<?php echo $tv; ?>"><?php echo $tl; ?></option>
    <?php endforeach; ?>
  </select>
</div>
</div>

<!-- ── Main playback grid ──────────────────────────────────── -->
<div style="display:grid;grid-template-columns:1fr 280px;gap:16px;margin-bottom:12px;">

  <div>
    <div class="pb-main">
      <img id="pb-img" src="" alt="Session snapshot" loading="lazy">
      <div class="pb-overlay">
        <div style="display:flex;justify-content:space-between;align-items:center;">
          <span id="pb-time" style="font-weight:700;font-size:14px;"></span>
          <span id="pb-type-badge" style="font-size:11px;opacity:.8;"></span>
        </div>
        <div id="pb-evt-overlay" style="font-size:11px;margin-top:3px;opacity:.9;"></div>
      </div>
    </div>
    <input type="range" id="pb-scrubber" class="pb-scrubber" min="0" max="0" value="0">
    <div class="pb-controls">
      <button class="pb-btn pb-btn-sec" onclick="pbGo(0)">⏮</button>
      <button class="pb-btn pb-btn-sec" onclick="pbStep(-1)">◀</button>
      <button class="pb-btn pb-btn-primary" id="pb-play-btn" onclick="pbTogglePlay()">▶ Play</button>
      <button class="pb-btn pb-btn-sec" onclick="pbStep(1)">▶</button>
      <button class="pb-btn pb-btn-sec" onclick="pbGo(pbSnaps.length-1)">⏭</button>
      <select id="pb-speed" class="form-control form-control-sm" style="max-width:130px;">
        <option value="3000">Slow (3s)</option>
        <option value="1500" selected>Normal (1.5s)</option>
        <option value="750">Fast (0.75s)</option>
        <option value="250">Very fast</option>
      </select>
      <span style="flex:1;"></span>
      <a href="<?php echo (new moodle_url('/mod/learnguard/attemptdetail.php',['id'=>$id,'attempt'=>$attemptid]))->out(false); ?>"
         class="pb-btn pb-btn-sec" style="text-decoration:none;">← Detail</a>
    </div>
  </div>

  <!-- Event list -->
  <div style="border:1px solid #dee2e6;border-radius:8px;overflow:hidden;">
    <div style="background:<?php echo $brand->primary; ?>;color:#fff;padding:10px 14px;font-weight:700;font-size:13px;">
      🔔 Events (<?php echo count($events); ?>)
    </div>
    <div class="pb-evt-list" id="pb-evt-list">
      <?php if (empty($events)): ?>
      <p style="padding:14px;color:#6c757d;font-size:13px;">No events recorded.</p>
      <?php else: foreach ($events as $eid => $e):
          $rel = max(0,(int)($e->timemodified - $timestart));
          $sc  = $sevCol[$e->severity] ?? '#6c757d';
      ?>
      <div class="pb-evt" data-rel="<?php echo $rel; ?>"
           style="border-left:3px solid <?php echo $sc; ?>;"
           onclick="pbJumpTo(<?php echo $rel; ?>)">
        <div style="font-weight:700;font-size:12px;"><?php echo s(str_replace('_',' ',$e->event_type)); ?>
          <?php if ($e->score_impact > 0): ?>
          <span style="color:#dc3545;margin-left:4px;">+<?php echo $e->score_impact; ?></span>
          <?php endif; ?>
        </div>
        <div style="color:#6c757d;font-size:11px;"><?php echo gmdate('H:i:s',$rel); ?></div>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>

<!-- ── Filmstrip ───────────────────────────────────────────── -->
<div class="pb-filmstrip" id="pb-filmstrip">
<?php $fi = 0; foreach ($snapIndex as $sn):
    $hasEvt = isset($eventsByTime[(int)floor($sn['time']/30)*30]);
    $icon   = $typeIcon[$sn['type']] ?? '📷';
    $src    = (new moodle_url('/mod/learnguard/snapshot.php',['id'=>$sn['id'],'sesskey'=>sesskey()]))->out(false);
?>
<div class="pb-thumb<?php echo $hasEvt ? ' has-event' : ''; ?>" id="pb-th-<?php echo $fi; ?>"
     onclick="pbGo(<?php echo $fi; ?>)" title="<?php echo $icon . ' ' . gmdate('i:s',max(0,$sn['time'])); ?>">
  <img src="<?php echo $src; ?>" loading="lazy" alt="" style="width:88px;height:66px;object-fit:cover;background:#111;">
  <div style="font-size:9px;color:#aaa;padding:2px;text-align:center;">
    <?php echo $icon . ' ' . gmdate('i:s',max(0,$sn['time'])); ?>
  </div>
</div>
<?php $fi++; endforeach; ?>
</div>

</div><!-- /pb-wrap -->

<script>
(function(){
'use strict';
var wwwroot = <?php echo json_encode($CFG->wwwroot); ?>;
var sk      = <?php echo json_encode(sesskey()); ?>;

// Full snapshot index (IDs only — images load via snapshot.php on demand).
var ALL_SNAPS = <?php echo json_encode($snapIndex); ?>;
var evtMap    = <?php echo json_encode($eventsByTime); ?>;
var primary   = <?php echo json_encode($brand->primary); ?>;

var pbSnaps   = ALL_SNAPS.slice(); // current filtered view
var current   = 0;
var playing   = false;
var playTimer = null;

var imgEl    = document.getElementById('pb-img');
var scrubEl  = document.getElementById('pb-scrubber');
var timeEl   = document.getElementById('pb-time');
var typeEl   = document.getElementById('pb-type-badge');
var evtOvEl  = document.getElementById('pb-evt-overlay');
var playBtn  = document.getElementById('pb-play-btn');
var posEl    = document.getElementById('pb-pos');

function snapUrl(id) {
    return wwwroot + '/mod/learnguard/snapshot.php?id=' + id + '&sesskey=' + sk;
}

function pbGo(idx) {
    if (!pbSnaps.length || idx < 0 || idx >= pbSnaps.length) return;
    current = idx;
    var s = pbSnaps[idx];

    // Load image via snapshot.php — no inline base64 needed.
    imgEl.src = snapUrl(s.id);
    if (timeEl) timeEl.textContent = s.ts + '  (t+' + s.time + 's)';
    if (typeEl) typeEl.textContent = s.type.replace(/_/g,' ');
    if (scrubEl) scrubEl.value = idx;
    if (posEl)   posEl.textContent = 'Frame ' + (idx+1) + ' / ' + pbSnaps.length;

    // Show events near this snapshot.
    var bucket = Math.floor(s.time / 30) * 30;
    var near   = evtMap[bucket] || [];
    if (evtOvEl) evtOvEl.textContent = near.map(function(e) { return e.type; }).join(' · ');

    // Highlight filmstrip thumb.
    document.querySelectorAll('.pb-thumb').forEach(function(t, i) {
        t.classList.toggle('active', pbSnaps[i] && pbSnaps[i].id === s.id);
    });
    var th = document.getElementById('pb-th-' + idx);
    if (th) th.scrollIntoView({block:'nearest', inline:'center', behavior:'smooth'});

    // Highlight matching event in event list.
    document.querySelectorAll('.pb-evt').forEach(function(evEl) {
        var rel = parseInt(evEl.dataset.rel, 10);
        evEl.classList.toggle('active', Math.abs(rel - s.time) <= 30);
    });
}
window.pbGo = pbGo;

function pbStep(dir) { pbGo(current + dir); }
window.pbStep = pbStep;

function pbJumpTo(relSeconds) {
    var closest = 0, minDiff = Infinity;
    pbSnaps.forEach(function(s, i) {
        var d = Math.abs(s.time - relSeconds);
        if (d < minDiff) { minDiff = d; closest = i; }
    });
    pbGo(closest);
}
window.pbJumpTo = pbJumpTo;

function pbTogglePlay() {
    if (playing) {
        playing = false; clearInterval(playTimer); playBtn.textContent = '▶ Play';
    } else {
        playing = true; playBtn.textContent = '⏸ Pause';
        var speed = parseInt(document.getElementById('pb-speed').value, 10);
        playTimer = setInterval(function() {
            if (current < pbSnaps.length - 1) { pbGo(current + 1); }
            else { playing = false; clearInterval(playTimer); playBtn.textContent = '▶ Play'; }
        }, speed);
    }
}
window.pbTogglePlay = pbTogglePlay;

// Type filter.
function pbFilter(type) {
    if (playing) { pbTogglePlay(); }
    pbSnaps = type ? ALL_SNAPS.filter(function(s){ return s.type === type; }) : ALL_SNAPS.slice();
    if (scrubEl) { scrubEl.max = Math.max(0, pbSnaps.length - 1); }
    pbGo(0);
}
window.pbFilter = pbFilter;

// Keyboard shortcuts.
document.addEventListener('keydown', function(e) {
    if (e.key === 'ArrowRight' || e.key === 'ArrowDown') { e.preventDefault(); pbStep(1); }
    if (e.key === 'ArrowLeft'  || e.key === 'ArrowUp')   { e.preventDefault(); pbStep(-1); }
    if (e.key === ' ') { e.preventDefault(); pbTogglePlay(); }
});

scrubEl.addEventListener('input', function() { pbGo(parseInt(this.value, 10)); });
if (scrubEl) scrubEl.max = Math.max(0, pbSnaps.length - 1);

// Init.
pbGo(0);
})();
</script>

<?php echo $OUTPUT->footer();
