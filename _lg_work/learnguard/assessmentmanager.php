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
 * Assessment Manager — site-wide view of all assessed activities.
 *
 * Admins/proctors can:
 *  - Filter by course → select an assessment
 *  - See all scheduled users with attempt counts
 *  - Edit per-user: time limit override, extra attempts, window end date
 *  - Grant bulk reattempt (void a finished attempt so the student may redo it)
 *
 * URL: /mod/learnguard/assessmentmanager.php
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$courseid  = optional_param('courseid',  0, PARAM_INT);
$lgid      = optional_param('lgid',      0, PARAM_INT);
$action    = optional_param('action',    '', PARAM_ALPHAEXT);

$PAGE->set_url('/mod/learnguard/assessmentmanager.php',
    ['courseid' => $courseid, 'lgid' => $lgid]);
$PAGE->set_context(context_system::instance());
$PAGE->set_title('LearnGuard — Assessment Manager');
$PAGE->set_heading('LearnGuard — Assessment Manager');
$PAGE->set_pagelayout('admin');
$PAGE->requires->css('/mod/learnguard/styles.css');

// -----------------------------------------------------------------------
// POST ACTIONS
// -----------------------------------------------------------------------
if (data_submitted() && confirm_sesskey()) {

    // ── Save per-user schedule overrides ───────────────────────────────
    if ($action === 'save_user_overrides' && $lgid) {
        $lg = $DB->get_record('learnguard', ['id' => $lgid], '*', MUST_EXIST);
        $userids        = required_param_array('uid',              PARAM_INT);
        $timeclose_vals = optional_param_array('timeclose_ts',     [], PARAM_INT);
        $extra_atts     = optional_param_array('extra_attempts',   [], PARAM_INT);
        $timelimits     = optional_param_array('timelimit_secs',   [], PARAM_INT);

        foreach ($userids as $idx => $uid) {
            $uid = (int)$uid;
            if (!$uid) { continue; }

            $tc  = isset($timeclose_vals[$idx]) ? (int)$timeclose_vals[$idx] : 0;
            $ea  = isset($extra_atts[$idx])     ? max(0, (int)$extra_atts[$idx]) : 0;
            $tl  = isset($timelimits[$idx])     ? max(0, (int)$timelimits[$idx]) : 0;

            $existing = $DB->get_record('learnguard_schedules',
                ['learnguard' => $lgid, 'userid' => $uid]);

            if ($existing) {
                $existing->timeclose         = $tc;
                $existing->extra_attempts    = $ea;
                $existing->timelimit_override = $tl;
                $existing->timemodified      = time();
                $DB->update_record('learnguard_schedules', $existing);
            } else {
                // Insert a new schedule row; timeopen = activity default.
                $rec = new stdClass();
                $rec->learnguard         = $lgid;
                $rec->userid             = $uid;
                $rec->timeopen           = (int)$lg->timeopen;
                $rec->timeclose          = $tc;
                $rec->extra_attempts     = $ea;
                $rec->timelimit_override = $tl;
                $rec->createdby          = $USER->id;
                $rec->timecreated        = time();
                $rec->timemodified       = time();
                $DB->insert_record('learnguard_schedules', $rec);
            }
        }

        redirect(new moodle_url('/mod/learnguard/assessmentmanager.php',
            ['courseid' => $courseid, 'lgid' => $lgid]),
            'User overrides saved.', null, \core\output\notification::NOTIFY_SUCCESS);
    }

    // ── Bulk reattempt: void the latest finished attempt ───────────────
    if ($action === 'bulk_reattempt' && $lgid) {
        $reattempt_uids = required_param_array('reattempt_uid', PARAM_INT);
        foreach ($reattempt_uids as $uid) {
            $uid = (int)$uid;
            if (!$uid) { continue; }
            // Void the most recent finished attempt for this user+activity.
            $latest = $DB->get_record_sql(
                "SELECT * FROM {learnguard_attempts}
                  WHERE learnguard = :lgid AND userid = :uid AND state = 'finished'
               ORDER BY timefinish DESC LIMIT 1",
                ['lgid' => $lgid, 'uid' => $uid]
            );
            if ($latest) {
                $DB->set_field('learnguard_attempts', 'state', 'voided', ['id' => $latest->id]);
            }
        }
        redirect(new moodle_url('/mod/learnguard/assessmentmanager.php',
            ['courseid' => $courseid, 'lgid' => $lgid]),
            'Reattempt granted: latest finished attempt(s) voided.', null,
            \core\output\notification::NOTIFY_SUCCESS);
    }
}

// -----------------------------------------------------------------------
// DATA
// -----------------------------------------------------------------------

// All distinct courses that have at least one LearnGuard activity.
$courses = $DB->get_records_sql(
    "SELECT DISTINCT c.id, c.fullname, c.shortname
       FROM {course} c
       JOIN {learnguard} lg ON lg.course = c.id
   ORDER BY c.fullname ASC"
);

// Activities in the selected course.
$activities = [];
if ($courseid) {
    $activities = $DB->get_records_sql(
        "SELECT lg.id, lg.name, lg.timeopen, lg.timeclose, lg.timelimit,
                lg.attempts AS maxattempts, lg.grade, cm.id AS cmid
           FROM {learnguard} lg
           JOIN {course_modules} cm ON cm.instance = lg.id
              AND cm.module = (SELECT id FROM {modules} WHERE name = 'learnguard')
          WHERE lg.course = :cid
       ORDER BY lg.name ASC",
        ['cid' => $courseid]
    );
}

// Selected activity details + user list.
$lg      = null;
$cm      = null;
$users   = [];
$scheds  = [];
$attCounts = [];

if ($lgid) {
    $lg = $DB->get_record('learnguard', ['id' => $lgid], '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('learnguard', $lgid, $lg->course, false, MUST_EXIST);

    // All enrolled users who can attempt.
    $ctx     = context_module::instance($cm->id);
    $enrolled = get_enrolled_users($ctx, 'mod/learnguard:attempt', 0, 'u.id,u.firstname,u.lastname,u.email,u.username');

    // Schedules indexed by userid.
    $schedRows = $DB->get_records('learnguard_schedules', ['learnguard' => $lgid]);
    foreach ($schedRows as $s) { $scheds[$s->userid] = $s; }

    // Attempt counts per user.
    $attRows = $DB->get_records_sql(
        "SELECT userid,
                COUNT(*) AS total_attempts,
                SUM(CASE WHEN state='finished' THEN 1 ELSE 0 END) AS finished,
                SUM(CASE WHEN state='inprogress' THEN 1 ELSE 0 END) AS inprogress,
                SUM(CASE WHEN state='voided' THEN 1 ELSE 0 END) AS voided,
                MAX(sumgrades) AS best_grade
           FROM {learnguard_attempts}
          WHERE learnguard = :lgid
       GROUP BY userid",
        ['lgid' => $lgid]
    );
    foreach ($attRows as $r) { $attCounts[$r->userid] = $r; }

    $users = $enrolled;
}

// -----------------------------------------------------------------------
// OUTPUT
// -----------------------------------------------------------------------
echo $OUTPUT->header();
echo learnguard_nav_bar(0, 'Assessment Manager');
?>
<style>
.lgam-wrap        { padding:16px 20px; font-family:"Segoe UI",system-ui,sans-serif; }
.lgam-filters     { display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:16px; }
.lgam-filters select,
.lgam-filters .btn { font-size:13px; }
.lgam-section     { background:#fff; border:1px solid #dee2e6; border-radius:8px; margin-bottom:20px; }
.lgam-sec-head    { background:#f8f9fa; border-bottom:1px solid #dee2e6; padding:10px 16px;
                    font-weight:700; border-radius:8px 8px 0 0; display:flex; justify-content:space-between; align-items:center; }
.lgam-table       { width:100%; border-collapse:collapse; font-size:13px; }
.lgam-table th    { background:#f1f3f5; padding:8px 12px; text-align:left; border-bottom:2px solid #dee2e6; white-space:nowrap; }
.lgam-table td    { padding:7px 12px; border-bottom:1px solid #f0f0f0; vertical-align:middle; }
.lgam-table tr:hover td { background:#f8f9fa; }
.lgam-inp         { width:72px; padding:3px 6px; border:1px solid #ced4da; border-radius:4px; font-size:12px; text-align:center; }
.lgam-inp.wide    { width:130px; }
.badge-att        { font-size:11px; padding:2px 7px; border-radius:8px; font-weight:600; }
.badge-fin        { background:#d4edda; color:#155724; }
.badge-prog       { background:#cce5ff; color:#004085; }
.badge-void       { background:#f8d7da; color:#721c24; }
</style>

<div class="lgam-wrap">
<h2 style="margin-bottom:12px;">📋 Assessment Manager</h2>
<p style="color:#6c757d;margin-bottom:16px;">
  Select a course and assessment to view scheduled users, attempt counts, and per-user overrides.
</p>

<!-- ── Filter bar ──────────────────────────────────────────────────── -->
<form method="get" action="<?php echo new moodle_url('/mod/learnguard/assessmentmanager.php'); ?>">
<div class="lgam-filters">
  <select name="courseid" id="lgam-course-sel" onchange="this.form.lgid.value='';this.form.submit();"
          style="padding:6px 10px;border:1px solid #ced4da;border-radius:6px;min-width:220px;">
    <option value="">— Select a course —</option>
    <?php foreach ($courses as $c): ?>
    <option value="<?php echo $c->id; ?>" <?php if ($c->id == $courseid) echo 'selected'; ?>>
      <?php echo s($c->shortname . ' — ' . $c->fullname); ?>
    </option>
    <?php endforeach; ?>
  </select>

  <?php if ($courseid && !empty($activities)): ?>
  <select name="lgid" id="lgam-act-sel" onchange="this.form.submit();"
          style="padding:6px 10px;border:1px solid #ced4da;border-radius:6px;min-width:220px;">
    <option value="">— Select an assessment —</option>
    <?php foreach ($activities as $a): ?>
    <option value="<?php echo $a->id; ?>" <?php if ($a->id == $lgid) echo 'selected'; ?>>
      <?php echo s($a->name); ?>
    </option>
    <?php endforeach; ?>
  </select>
  <?php endif; ?>
  <input type="hidden" name="lgid" value="<?php echo $lgid; ?>">
</div>
</form>

<?php if ($lg && $cm): ?>

<!-- ── Activity info ────────────────────────────────────────────────── -->
<div class="lgam-section">
  <div class="lgam-sec-head">
    <span>ℹ️ <?php echo s($lg->name); ?></span>
    <a href="<?php echo new moodle_url('/mod/learnguard/admin.php', ['id' => $cm->id]); ?>"
       class="btn btn-sm btn-outline-secondary" target="_blank">Open Activity Admin</a>
  </div>
  <div style="padding:10px 16px;display:flex;gap:24px;flex-wrap:wrap;font-size:13px;">
    <span><strong>Max Attempts:</strong> <?php echo $lg->maxattempts ?: 'Unlimited'; ?></span>
    <span><strong>Time Limit:</strong>
      <?php echo $lg->timelimit ? round($lg->timelimit / 60) . ' min' : 'None'; ?></span>
    <span><strong>Window Opens:</strong>
      <?php echo $lg->timeopen  ? userdate((int)$lg->timeopen)  : '—'; ?></span>
    <span><strong>Window Closes:</strong>
      <?php echo $lg->timeclose ? userdate((int)$lg->timeclose) : '—'; ?></span>
    <span><strong>Grade:</strong> <?php echo (float)$lg->grade; ?></span>
    <span><strong>Enrolled Students:</strong> <?php echo count($users); ?></span>
  </div>
</div>

<!-- ── User overrides table ─────────────────────────────────────────── -->
<form method="post" action="<?php echo new moodle_url('/mod/learnguard/assessmentmanager.php',
    ['courseid' => $courseid, 'lgid' => $lgid]); ?>">
<?php echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]); ?>
<?php echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'save_user_overrides']); ?>

<div class="lgam-section">
  <div class="lgam-sec-head">
    <span>👥 Enrolled Users & Per-User Overrides (<?php echo count($users); ?>)</span>
    <div style="display:flex;gap:8px;">
      <button type="submit" class="btn btn-sm btn-primary">💾 Save Overrides</button>
    </div>
  </div>
  <div style="overflow-x:auto;">
  <table class="lgam-table">
    <thead>
      <tr>
        <th><input type="checkbox" id="lgam-chk-all" onclick="lgamToggleAll(this)"> All</th>
        <th>Student</th>
        <th>Attempts</th>
        <th>Best Score</th>
        <th>Window Close Override<br><small style="font-weight:400;">(blank = activity default)</small></th>
        <th>Extra Attempts<br><small style="font-weight:400;">(added to max)</small></th>
        <th>Time Limit Override (min)<br><small style="font-weight:400;">(0 = activity default)</small></th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php $idx = 0; foreach ($users as $u): ?>
    <?php
      $sched   = $scheds[$u->id] ?? null;
      $atts    = $attCounts[$u->id] ?? null;
      $fin     = $atts ? (int)$atts->finished   : 0;
      $prog    = $atts ? (int)$atts->inprogress  : 0;
      $voided  = $atts ? (int)$atts->voided      : 0;
      $total   = $atts ? (int)$atts->total_attempts : 0;
      $best    = ($atts && $atts->best_grade !== null)
                  ? round((float)$atts->best_grade / (float)$lg->grade * 100, 1) . '%'
                  : '—';

      $tcVal   = $sched ? (int)$sched->timeclose          : 0;
      $eaVal   = $sched ? (int)$sched->extra_attempts     : 0;
      $tlVal   = $sched ? (int)$sched->timelimit_override : 0;
      $tlMins  = $tlVal > 0 ? (int)round($tlVal / 60) : 0;

      // Date-time for the window close input.
      $tcDate  = $tcVal > 0 ? date('Y-m-d\TH:i', $tcVal) : '';
    ?>
    <tr>
      <td><input type="checkbox" name="reattempt_uid[]" value="<?php echo $u->id; ?>" class="lgam-chk"></td>
      <td>
        <strong><?php echo fullname($u); ?></strong><br>
        <small style="color:#6c757d;"><?php echo s($u->email); ?></small>
      </td>
      <td>
        <?php if ($total): ?>
          <span class="badge-att badge-fin"><?php echo $fin; ?> done</span>
          <?php if ($prog):  ?><span class="badge-att badge-prog"><?php echo $prog; ?> active</span><?php endif; ?>
          <?php if ($voided): ?><span class="badge-att badge-void"><?php echo $voided; ?> voided</span><?php endif; ?>
        <?php else: ?>
          <span style="color:#aaa;">Not started</span>
        <?php endif; ?>
      </td>
      <td><?php echo $best; ?></td>
      <td>
        <?php echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'uid[]', 'value' => $u->id]); ?>
        <input type="datetime-local" name="timeclose_ts[]" class="lgam-inp wide"
               value="<?php echo $tcDate; ?>"
               onchange="lgamDateToTs(this)">
        <input type="hidden" name="timeclose_ts[]" class="lgam-ts" value="<?php echo $tcVal; ?>">
      </td>
      <td>
        <input type="number" name="extra_attempts[]" class="lgam-inp"
               value="<?php echo $eaVal; ?>" min="0" max="99">
      </td>
      <td>
        <input type="number" name="timelimit_secs[]" class="lgam-inp" data-type="minutes"
               value="<?php echo $tlMins; ?>" min="0" max="9999"
               title="Minutes (0 = use activity default)">
      </td>
      <td>
        <a href="<?php echo new moodle_url('/mod/learnguard/grader_user_report.php',
            ['id' => $cm->id, 'userid' => $u->id]); ?>"
           class="btn btn-xs btn-outline-secondary" style="font-size:11px;padding:2px 6px;"
           target="_blank">📄 Attempts</a>
      </td>
    </tr>
    <?php $idx++; endforeach; ?>
    </tbody>
  </table>
  </div>
  <div style="padding:10px 16px;border-top:1px solid #dee2e6;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
    <button type="submit" class="btn btn-primary">💾 Save Overrides</button>
    <small style="color:#6c757d;">
      <strong>Extra Attempts:</strong> added to the activity's max attempts per user.
      <strong>Time Limit Override:</strong> sets a personal time limit (0 = use activity default).
      <strong>Window Close:</strong> sets a personal deadline for this user.
    </small>
  </div>
</div>
</form>

<!-- ── Bulk Reattempt ────────────────────────────────────────────────── -->
<div class="lgam-section">
  <div class="lgam-sec-head">🔄 Bulk Reattempt</div>
  <div style="padding:12px 16px;">
    <p style="color:#6c757d;font-size:13px;margin-bottom:10px;">
      Select users above (checkboxes) then click Grant Reattempt to void their latest finished
      attempt, allowing them to sit the assessment again. This does not delete their old attempt —
      it is marked as voided and remains visible in grader history.
    </p>
    <form method="post" action="<?php echo new moodle_url('/mod/learnguard/assessmentmanager.php',
        ['courseid' => $courseid, 'lgid' => $lgid]); ?>" id="lgam-reattempt-form">
      <?php echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]); ?>
      <?php echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'bulk_reattempt']); ?>
      <div id="lgam-reattempt-targets"></div>
      <button type="button" class="btn btn-warning" onclick="lgamSubmitReattempt()">
        🔄 Grant Reattempt for Selected Users
      </button>
    </form>
  </div>
</div>

<?php elseif ($courseid && empty($activities)): ?>
<div class="alert alert-info">No LearnGuard assessments found in the selected course.</div>
<?php elseif (!$courseid): ?>
<div class="alert alert-secondary">Select a course above to begin.</div>
<?php endif; ?>

</div><!-- .lgam-wrap -->

<script>
function lgamToggleAll(master) {
    document.querySelectorAll('.lgam-chk').forEach(function(c) { c.checked = master.checked; });
}

function lgamDateToTs(input) {
    // Convert datetime-local value to unix timestamp stored in adjacent hidden field.
    var val = input.value;
    var ts  = val ? Math.floor(new Date(val).getTime() / 1000) : 0;
    // The hidden input follows immediately after in the DOM.
    var hidden = input.nextElementSibling;
    if (hidden && hidden.type === 'hidden') { hidden.value = ts; }
}

function lgamSubmitReattempt() {
    var form    = document.getElementById('lgam-reattempt-form');
    var targets = document.getElementById('lgam-reattempt-targets');
    targets.innerHTML = '';
    var checked = document.querySelectorAll('.lgam-chk:checked');
    if (!checked.length) {
        alert('Please select at least one student to grant reattempt.');
        return;
    }
    checked.forEach(function(c) {
        var inp = document.createElement('input');
        inp.type  = 'hidden';
        inp.name  = 'reattempt_uid[]';
        inp.value = c.value;
        targets.appendChild(inp);
    });
    if (confirm('Void the latest finished attempt for ' + checked.length + ' student(s)?\nThey will be able to retake the assessment.')) {
        form.submit();
    }
}

// Convert any pre-filled date inputs on load (for display consistency).
document.querySelectorAll('input[type="datetime-local"]').forEach(function(inp) {
    inp.addEventListener('change', function() { lgamDateToTs(inp); });
});

// Fix: timelimit_secs inputs store minutes in UI but must submit seconds.
document.querySelectorAll('form').forEach(function(form) {
    form.addEventListener('submit', function() {
        form.querySelectorAll('input[data-type="minutes"]').forEach(function(inp) {
            inp.value = Math.max(0, parseInt(inp.value, 10) || 0) * 60;
        });
    });
});
</script>

<?php echo $OUTPUT->footer(); ?>
