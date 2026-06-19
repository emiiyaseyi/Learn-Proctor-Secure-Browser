<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Site-admin bulk scheduling — assign per-learner exam windows across
 * all courses and LearnGuard activities from one central page.
 *
 * URL: /mod/learnguard/bulkschedule.php
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
$syscontext = context_system::instance();
require_capability('moodle/site:config', $syscontext);

$PAGE->set_url('/mod/learnguard/bulkschedule.php');
$PAGE->set_context($syscontext);
$PAGE->set_title('Bulk Schedule — LearnGuard');
$PAGE->set_heading('LearnGuard — Bulk Scheduling');
$PAGE->set_pagelayout('admin');
$PAGE->requires->css('/mod/learnguard/styles.css');

/** Parse "YYYY-MM-DD" + optional "HH:MM" into a unix timestamp. */
function lgsched_parse_dt(string $date, string $time): int {
    $date = trim($date);
    if ($date === '') { return 0; }
    $dp = explode('-', $date);
    if (count($dp) !== 3) { return 0; }
    [$y, $mo, $d] = array_map('intval', $dp);
    $h = 0; $mi = 0;
    $t = trim($time);
    if ($t !== '') {
        $tp = explode(':', $t);
        $h = (int)($tp[0] ?? 0); $mi = (int)($tp[1] ?? 0);
    }
    return make_timestamp($y, $mo, $d, $h, $mi);
}

// ── Load all courses and their LearnGuard activities ─────────────────────────
$allActivities = $DB->get_records_sql(
    "SELECT cm.id AS cmid, cm.instance AS lgid, cm.course AS courseid,
            c.shortname AS courseshort, c.fullname AS coursefull, lg.name AS actname
       FROM {course_modules} cm
       JOIN {modules} m ON m.id = cm.module AND m.name = 'learnguard'
       JOIN {learnguard} lg ON lg.id = cm.instance
       JOIN {course} c ON c.id = cm.course
      WHERE cm.visible = 1 AND c.id <> 1
      ORDER BY c.fullname ASC, lg.name ASC"
);

// Group by course.
$courseGroups = [];
foreach ($allActivities as $act) {
    $cid = (int)$act->courseid;
    if (!isset($courseGroups[$cid])) {
        $courseGroups[$cid] = [
            'fullname'  => $act->coursefull,
            'shortname' => $act->courseshort,
            'cmids'     => [],
            'activities'=> [],
        ];
    }
    $courseGroups[$cid]['activities'][] = $act;
    $courseGroups[$cid]['cmids'][] = (int)$act->cmid;
}

// ── Load all users enrolled in any of these courses ──────────────────────────
$allUsers = [];
if (!empty($courseGroups)) {
    $courseIds = array_keys($courseGroups);
    [$cidSql, $cidP] = $DB->get_in_or_equal($courseIds, SQL_PARAMS_NAMED, 'c');
    $allUsers = $DB->get_records_sql(
        "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.username
           FROM {user} u
           JOIN {user_enrolments} ue ON ue.userid = u.id AND ue.status = 0
           JOIN {enrol} e ON e.id = ue.enrolid AND e.status = 0
          WHERE e.courseid $cidSql AND u.deleted = 0 AND u.suspended = 0
          ORDER BY u.lastname ASC, u.firstname ASC",
        $cidP
    );
}

// Map cmid → lgid (instance id) for schedule creation.
$cmidToLgid = [];
foreach ($allActivities as $act) { $cmidToLgid[(int)$act->cmid] = (int)$act->lgid; }

// Map cmid → courseid for enrolment checks.
$cmidToCourse = [];
foreach ($allActivities as $act) { $cmidToCourse[(int)$act->cmid] = (int)$act->courseid; }

// ── Handle POST ───────────────────────────────────────────────────────────────
$messages = []; $errors = [];

$action = optional_param('action', '', PARAM_ALPHANUMEXT);
if ($action === 'schedule' && data_submitted() && confirm_sesskey()) {
    $selCmids  = optional_param_array('sel_cmids',  [], PARAM_INT);
    $selUids   = optional_param_array('sel_uids',   [], PARAM_INT);
    $datestart = optional_param('datestart', '', PARAM_TEXT);
    $timestart = optional_param('timestart', '', PARAM_TEXT);
    $dateend   = optional_param('dateend',   '', PARAM_TEXT);
    $timeend   = optional_param('timeend',   '', PARAM_TEXT);

    $timeopen  = lgsched_parse_dt($datestart, $timestart);
    $timeclose = lgsched_parse_dt($dateend,   $timeend);

    if (empty($selCmids)) {
        $errors[] = 'No activities selected.';
    } else if (empty($selUids)) {
        $errors[] = 'No learners selected.';
    } else if (!$timeopen && !$timeclose) {
        $errors[] = 'Please provide an opening date/time, a closing date/time, or both.';
    } else {
        $now = time(); $count = 0; $skipped = 0;
        // Pre-load enrolment sets per course for fast lookup.
        $courseEnrolSets = [];
        foreach ($selCmids as $cmid) {
            $cmid = (int)$cmid;
            $cid  = $cmidToCourse[$cmid] ?? 0;
            if (!$cid || !isset($cmidToLgid[$cmid])) { continue; }
            if (!isset($courseEnrolSets[$cid])) {
                $ctx  = context_course::instance($cid);
                $eusers = get_enrolled_users($ctx, '', 0, 'u.id', '', 0, 0, true);
                $courseEnrolSets[$cid] = array_keys($eusers);
            }
        }

        foreach ($selCmids as $cmid) {
            $cmid  = (int)$cmid;
            $lgid  = $cmidToLgid[$cmid] ?? 0;
            $cid   = $cmidToCourse[$cmid] ?? 0;
            if (!$lgid || !$cid) { continue; }
            $enrolled = $courseEnrolSets[$cid] ?? [];
            $enrolledSet = array_flip($enrolled);

            foreach ($selUids as $uid) {
                $uid = (int)$uid;
                if (!$uid) { continue; }
                if (!isset($enrolledSet[$uid])) { $skipped++; continue; }
                $existing = $DB->get_record('learnguard_schedules', ['learnguard' => $lgid, 'userid' => $uid]);
                if ($existing) {
                    $DB->update_record('learnguard_schedules', (object)[
                        'id'           => $existing->id,
                        'timeopen'     => $timeopen,
                        'timeclose'    => $timeclose,
                        'timemodified' => $now,
                    ]);
                } else {
                    $DB->insert_record('learnguard_schedules', (object)[
                        'learnguard'   => $lgid,
                        'userid'       => $uid,
                        'timeopen'     => $timeopen,
                        'timeclose'    => $timeclose,
                        'createdby'    => $USER->id,
                        'timecreated'  => $now,
                        'timemodified' => $now,
                    ]);
                }
                $count++;
            }
        }
        $actCount = count($selCmids); $lrnCount = count($selUids);
        $messages[] = "$count schedule(s) saved across $actCount activity/activities for $lrnCount learner(s)."
                    . ($skipped ? " $skipped skipped (learner not enrolled in that activity)." : '');
    }
}

// Single schedule remove.
if ($action === 'removeschedule' && data_submitted() && confirm_sesskey()) {
    $sid = required_param('sid', PARAM_INT);
    $DB->delete_records('learnguard_schedules', ['id' => $sid]);
    $messages[] = 'Schedule removed.';
}

// Bulk remove.
if ($action === 'bulkremove' && data_submitted() && confirm_sesskey()) {
    $sids = optional_param_array('sel_sched', [], PARAM_INT);
    if (empty($sids)) {
        $errors[] = 'No schedules selected.';
    } else {
        [$dSql, $dP] = $DB->get_in_or_equal($sids);
        $DB->delete_records_select('learnguard_schedules', "id $dSql", $dP);
        $messages[] = count($sids) . ' schedule(s) deleted.';
    }
}

// Bulk edit (update open/close times for selected schedules).
if ($action === 'bulkedit' && data_submitted() && confirm_sesskey()) {
    $sids      = optional_param_array('sel_sched', [], PARAM_INT);
    $datestart = optional_param('edit_datestart', '', PARAM_TEXT);
    $timestart = optional_param('edit_timestart', '', PARAM_TEXT);
    $dateend   = optional_param('edit_dateend',   '', PARAM_TEXT);
    $timeend   = optional_param('edit_timeend',   '', PARAM_TEXT);
    $timeopen  = lgsched_parse_dt($datestart, $timestart);
    $timeclose = lgsched_parse_dt($dateend,   $timeend);
    if (empty($sids)) {
        $errors[] = 'No schedules selected.';
    } else if (!$timeopen && !$timeclose) {
        $errors[] = 'Please provide an opening date/time, a closing date/time, or both.';
    } else {
        $now = time();
        foreach ($sids as $sid) {
            $sid = (int)$sid;
            if ($sid && $DB->record_exists('learnguard_schedules', ['id' => $sid])) {
                $DB->update_record('learnguard_schedules', (object)[
                    'id'           => $sid,
                    'timeopen'     => $timeopen,
                    'timeclose'    => $timeclose,
                    'timemodified' => $now,
                ]);
            }
        }
        $messages[] = count($sids) . ' schedule(s) updated.';
    }
}

// ── Load all current schedules (for the management section) ──────────────────
$allSchedules = $DB->get_records_sql(
    "SELECT sch.id, sch.learnguard AS lgid, sch.userid, sch.timeopen, sch.timeclose,
            u.firstname, u.lastname, u.email, u.username,
            lg.name AS actname,
            c.fullname AS coursefull, c.shortname AS courseshort,
            cm.id AS cmid
       FROM {learnguard_schedules} sch
       JOIN {user}          u  ON u.id   = sch.userid
       JOIN {learnguard}    lg ON lg.id  = sch.learnguard
       JOIN {course_modules} cm ON cm.instance = sch.learnguard
       JOIN {modules}       mo ON mo.id  = cm.module AND mo.name = 'learnguard'
       JOIN {course}        c  ON c.id   = cm.course
      WHERE u.deleted = 0
      ORDER BY c.fullname ASC, lg.name ASC, u.lastname ASC, u.firstname ASC"
);

// ── Page render ───────────────────────────────────────────────────────────────
$brand = learnguard_brand();
echo $OUTPUT->header();
?>
<style>
.lgbs-wrap { max-width:1200px; margin:0 auto; font-family:system-ui,sans-serif; }
.lgbs-header { background:<?php echo htmlspecialchars($brand->primary); ?>;
               color:#fff; padding:16px 24px; border-radius:10px; margin-bottom:20px;
               display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; }
.lgbs-header h2 { margin:0; font-size:20px; font-weight:700; }
.lgbs-header small { font-size:12px; opacity:.85; display:block; }
.lgbs-card { background:#fff; border:1px solid #dee2e6; border-radius:10px;
             padding:20px; margin-bottom:20px; box-shadow:0 2px 8px rgba(0,0,0,.06); }
.lgbs-card h3 { margin:0 0 14px; font-size:16px; font-weight:700; color:#212529; }
.lgbs-course-hdr { background:#e8f4fd; border:1px solid #b8d8f8; border-radius:6px;
                   padding:8px 14px; margin:0 0 8px; display:flex;
                   align-items:center; gap:10px; }
.lgbs-course-name { font-weight:700; color:#0f6cbf; font-size:14px; }
.lgbs-act-list { display:flex; flex-wrap:wrap; gap:6px; padding:0 0 10px 20px; }
.lgbs-act-lbl { display:flex; align-items:center; gap:5px; background:#f8f9fa;
                border:1px solid #dee2e6; border-radius:6px; padding:5px 10px;
                cursor:pointer; font-size:13px; }
.lgbs-act-lbl:hover { background:#e8f4fd; border-color:#b8d8f8; }
.lgbs-search-box { width:100%; max-width:360px; padding:7px 12px; border:1px solid #dee2e6;
                   border-radius:6px; font-size:13px; margin-bottom:10px; }
.lgbs-user-tbl { width:100%; border-collapse:collapse; font-size:13px; }
.lgbs-user-tbl th { background:#f8f9fa; padding:8px 12px; text-align:left;
                    position:sticky; top:0; border-bottom:2px solid #dee2e6; z-index:1; }
.lgbs-user-tbl td { padding:7px 12px; border-bottom:1px solid #f0f0f0; }
.lgbs-user-tbl tr:hover td { background:#f8f9fa; }
.lgbs-user-tbl tr.hidden { display:none; }
.lgbs-dt-row { display:flex; gap:20px; flex-wrap:wrap; align-items:flex-end; margin-bottom:14px; }
.lgbs-dt-grp label { font-weight:700; display:block; margin-bottom:4px; font-size:13px; }
.lgbs-dt-grp .dt-pair { display:flex; gap:6px; }
.lgbs-submit { background:<?php echo htmlspecialchars($brand->primary); ?>; color:#fff;
               border:none; border-radius:6px; padding:11px 24px;
               font-size:15px; font-weight:700; cursor:pointer; }
.lgbs-submit:hover { filter:brightness(.9); }
.lgbs-back { background:#fff; color:#495057; border:1px solid #dee2e6;
             border-radius:6px; padding:9px 18px; font-size:13px;
             font-weight:600; text-decoration:none; }
.lgbs-sched-tbl { width:100%; border-collapse:collapse; font-size:13px; }
.lgbs-sched-tbl th { background:#f8f9fa; padding:8px 12px; text-align:left;
                     border-bottom:2px solid #dee2e6; position:sticky; top:0; z-index:1; }
.lgbs-sched-tbl td { padding:7px 12px; border-bottom:1px solid #f0f0f0; vertical-align:middle; }
.lgbs-sched-tbl tr:hover td { background:#f8f9fa; }
.lgbs-sched-tbl tr.hidden { display:none; }
.lgbs-bulk-bar { display:flex; align-items:center; gap:8px; flex-wrap:wrap;
                 background:#f0f4ff; border:1px solid #b8d0f8; border-radius:8px;
                 padding:10px 14px; margin-bottom:12px; }
.lgbs-bulk-bar .bulkbar-label { font-weight:700; font-size:13px; color:#0f6cbf; }
.lgbs-status-open   { background:#d4edda; color:#155724; border-radius:4px; padding:2px 7px; font-size:11px; font-weight:700; }
.lgbs-status-closed { background:#f8d7da; color:#721c24; border-radius:4px; padding:2px 7px; font-size:11px; font-weight:700; }
.lgbs-status-future { background:#fff3cd; color:#856404; border-radius:4px; padding:2px 7px; font-size:11px; font-weight:700; }
.lgbs-status-anytime{ background:#e2e3e5; color:#383d41; border-radius:4px; padding:2px 7px; font-size:11px; font-weight:700; }
</style>

<div class="lgbs-wrap">

<div class="lgbs-header">
  <div>
    <h2>📅 Bulk Exam Scheduling</h2>
    <small>Assign personal open/close windows to learners across any LearnGuard activities site-wide.</small>
  </div>
  <a href="<?php echo new moodle_url('/mod/learnguard/siteadmin.php'); ?>" class="lgbs-back">← Site Admin</a>
</div>

<?php foreach ($messages as $m): ?>
  <div class="alert alert-success">✅ <?php echo s($m); ?></div>
<?php endforeach; ?>
<?php if ($errors): ?>
  <div class="alert alert-danger">⚠️ <?php echo implode('<br>⚠️ ', array_map('s', $errors)); ?></div>
<?php endif; ?>

<?php if (empty($courseGroups)): ?>
  <div class="alert alert-info">No LearnGuard activities found on this site yet.</div>
<?php else: ?>

<form method="post" action="">
<input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
<input type="hidden" name="action" value="schedule">

<!-- ── Step 1: Select Activities ──────────────────────────────────────────── -->
<div class="lgbs-card">
  <h3>① Select Activities</h3>
  <div style="margin-bottom:10px;display:flex;gap:8px;flex-wrap:wrap;">
    <button type="button" class="btn btn-sm btn-outline-primary"
            onclick="document.querySelectorAll('.lgbs-act-cb').forEach(function(c){c.checked=true;})">
      ☑ Select All Activities
    </button>
    <button type="button" class="btn btn-sm btn-outline-secondary"
            onclick="document.querySelectorAll('.lgbs-act-cb').forEach(function(c){c.checked=false;})">
      ☐ Clear All
    </button>
  </div>

  <?php foreach ($courseGroups as $cid => $cg): ?>
  <div class="lgbs-course-hdr">
    <input type="checkbox" class="lgbs-course-cb" data-course="<?php echo $cid; ?>"
           onchange="lgbsToggleCourse(<?php echo $cid; ?>,this.checked)"
           title="Select all activities in this course">
    <span class="lgbs-course-name"><?php echo htmlspecialchars(format_string($cg['fullname'])); ?></span>
    <span style="font-size:12px;color:#6c757d;"><?php echo count($cg['activities']); ?> activity/activities</span>
  </div>
  <div class="lgbs-act-list">
    <?php foreach ($cg['activities'] as $act): ?>
    <label class="lgbs-act-lbl">
      <input type="checkbox" class="lgbs-act-cb lgbs-course-act-<?php echo $cid; ?>"
             name="sel_cmids[]" value="<?php echo (int)$act->cmid; ?>">
      <?php echo htmlspecialchars(format_string($act->actname)); ?>
    </label>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>
</div>

<!-- ── Step 2: Select Learners ────────────────────────────────────────────── -->
<div class="lgbs-card">
  <h3>② Select Learners</h3>
  <p style="color:#6c757d;font-size:13px;margin:0 0 12px;">
    Showing all learners enrolled in any course with a LearnGuard activity.
    Learners not enrolled in a selected activity are silently skipped when saving.
  </p>

  <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:10px;">
    <input type="text" id="lgbs-search" class="lgbs-search-box" placeholder="🔍 Search by name, username or email…"
           oninput="lgbsFilter(this.value)">
    <button type="button" class="btn btn-sm btn-outline-primary"
            onclick="document.querySelectorAll('#lgbs-user-tbody tr:not(.hidden) .lgbs-ucb').forEach(function(c){c.checked=true;})">
      ☑ Select All Visible
    </button>
    <button type="button" class="btn btn-sm btn-outline-secondary"
            onclick="document.querySelectorAll('.lgbs-ucb').forEach(function(c){c.checked=false;})">
      ☐ Clear All
    </button>
  </div>

  <div style="max-height:380px;overflow-y:auto;border:1px solid #e9ecef;border-radius:6px;">
    <table class="lgbs-user-tbl">
      <thead>
        <tr>
          <th style="width:36px;"><input type="checkbox" id="lgbs-sel-all"
               onclick="document.querySelectorAll('.lgbs-ucb').forEach(function(c){c.checked=document.getElementById('lgbs-sel-all').checked;})"></th>
          <th>Name</th>
          <th>Username</th>
          <th>Email</th>
        </tr>
      </thead>
      <tbody id="lgbs-user-tbody">
      <?php if (empty($allUsers)): ?>
        <tr><td colspan="4" style="padding:14px;text-align:center;color:#6c757d;">No enrolled users found.</td></tr>
      <?php else: foreach ($allUsers as $u): ?>
        <tr data-name="<?php echo htmlspecialchars(strtolower(fullname($u))); ?>"
            data-uname="<?php echo htmlspecialchars(strtolower($u->username)); ?>"
            data-email="<?php echo htmlspecialchars(strtolower($u->email)); ?>">
          <td><input type="checkbox" class="lgbs-ucb" name="sel_uids[]" value="<?php echo (int)$u->id; ?>"></td>
          <td><strong><?php echo htmlspecialchars(fullname($u)); ?></strong></td>
          <td><?php echo htmlspecialchars($u->username); ?></td>
          <td><?php echo htmlspecialchars($u->email); ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <p style="font-size:12px;color:#6c757d;margin:6px 0 0;"><?php echo count($allUsers); ?> user(s) across all LearnGuard courses.</p>
</div>

<!-- ── Step 3: Date / Time Window ─────────────────────────────────────────── -->
<div class="lgbs-card">
  <h3>③ Set Exam Window</h3>
  <p style="color:#6c757d;font-size:13px;margin:0 0 14px;">
    Leave "Opens" blank for no opening restriction; leave "Closes" blank for no deadline.
    At least one must be set. The window is applied to all selected learner × activity pairs.
  </p>

  <div class="lgbs-dt-row">
    <div class="lgbs-dt-grp">
      <label>📅 Opens</label>
      <div class="dt-pair">
        <input type="date" name="datestart" class="form-control">
        <input type="time" name="timestart" class="form-control" value="00:00">
      </div>
    </div>
    <div class="lgbs-dt-grp">
      <label>🔒 Closes</label>
      <div class="dt-pair">
        <input type="date" name="dateend" class="form-control">
        <input type="time" name="timeend" class="form-control" value="23:59">
      </div>
    </div>
  </div>

  <button type="submit" class="lgbs-submit"
          onclick="return lgbsConfirmSubmit()">
    📅 Apply Schedule to Selected Learners &amp; Activities
  </button>
</div>

</form>
<?php endif; ?>

<!-- ── ④ All Current Schedules ──────────────────────────────────────────────── -->
<div class="lgbs-card" style="margin-top:28px;">
  <h3>④ All Current Schedules</h3>

  <?php if (empty($allSchedules)): ?>
    <p style="color:#6c757d;font-size:13px;">No schedules have been created yet.</p>
  <?php else: ?>

  <!-- Bulk-edit date pickers (hidden until schedules are selected) -->
  <form method="post" action="" id="lgbs-sched-bulk-form">
    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
    <input type="hidden" name="action" id="lgbs-sched-action" value="">

    <div class="lgbs-bulk-bar">
      <span class="bulkbar-label">Bulk actions:</span>

      <input type="text" id="lgbs-sched-search" class="lgbs-search-box"
             placeholder="🔍 Filter schedules…" oninput="lgbsSchedFilter(this.value)"
             style="margin:0;max-width:260px;">

      <button type="button" class="btn btn-sm btn-outline-primary"
              onclick="lgbsSchedSelectAllVisible()">☑ Select All Visible</button>
      <button type="button" class="btn btn-sm btn-outline-secondary"
              onclick="document.querySelectorAll('.lgbs-scb').forEach(function(c){c.checked=false;})">☐ Clear</button>

      <span style="border-left:1px solid #b8d0f8;height:22px;"></span>

      <span style="font-size:13px;font-weight:600;">Update Opens/Closes:</span>
      <input type="date" name="edit_datestart" class="form-control form-control-sm" style="width:140px;">
      <input type="time" name="edit_timestart" class="form-control form-control-sm" style="width:110px;" value="00:00">
      <span style="font-size:12px;color:#6c757d;">→ Closes:</span>
      <input type="date" name="edit_dateend" class="form-control form-control-sm" style="width:140px;">
      <input type="time" name="edit_timeend" class="form-control form-control-sm" style="width:110px;" value="23:59">

      <button type="button" class="btn btn-sm btn-warning"
              onclick="lgbsSchedBulkAction('bulkedit')">✏️ Update Selected</button>
      <button type="button" class="btn btn-sm btn-danger"
              onclick="lgbsSchedBulkAction('bulkremove')">🗑 Delete Selected</button>
    </div>

    <div style="max-height:480px;overflow-y:auto;border:1px solid #e9ecef;border-radius:6px;">
      <table class="lgbs-sched-tbl">
        <thead>
          <tr>
            <th style="width:36px;">
              <input type="checkbox" id="lgbs-scb-all"
                     onclick="document.querySelectorAll('.lgbs-scb').forEach(function(c){c.checked=document.getElementById('lgbs-scb-all').checked;})">
            </th>
            <th>Course</th>
            <th>Activity</th>
            <th>Learner</th>
            <th>Opens</th>
            <th>Closes</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody id="lgbs-sched-tbody">
        <?php
        $now = time();
        foreach ($allSchedules as $sch):
            $to = (int)$sch->timeopen;
            $tc = (int)$sch->timeclose;
            if ($to <= 0 && $tc <= 0) {
                $statusLabel = 'Anytime'; $statusCls = 'lgbs-status-anytime';
            } else if ($to > 0 && $now < $to) {
                $statusLabel = 'Future';  $statusCls = 'lgbs-status-future';
            } else if ($tc > 0 && $now > $tc) {
                $statusLabel = 'Closed';  $statusCls = 'lgbs-status-closed';
            } else {
                $statusLabel = 'Open';    $statusCls = 'lgbs-status-open';
            }
            $openStr  = $to > 0 ? userdate($to, get_string('strftimedatetimeshort')) : '—';
            $closeStr = $tc > 0 ? userdate($tc, get_string('strftimedatetimeshort')) : '—';
            $searchText = strtolower(
                format_string($sch->coursefull) . ' ' .
                format_string($sch->actname) . ' ' .
                fullname($sch) . ' ' .
                $sch->username . ' ' . $sch->email
            );
        ?>
          <tr data-search="<?php echo htmlspecialchars($searchText); ?>">
            <td><input type="checkbox" class="lgbs-scb" name="sel_sched[]" value="<?php echo (int)$sch->id; ?>"></td>
            <td><?php echo htmlspecialchars(format_string($sch->courseshort)); ?></td>
            <td><?php echo htmlspecialchars(format_string($sch->actname)); ?></td>
            <td>
              <strong><?php echo htmlspecialchars(fullname($sch)); ?></strong><br>
              <small style="color:#6c757d;"><?php echo htmlspecialchars($sch->username); ?></small>
            </td>
            <td style="white-space:nowrap;"><?php echo $openStr; ?></td>
            <td style="white-space:nowrap;"><?php echo $closeStr; ?></td>
            <td><span class="<?php echo $statusCls; ?>"><?php echo $statusLabel; ?></span></td>
            <td>
              <form method="post" action="" style="display:inline;">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <input type="hidden" name="action" value="removeschedule">
                <input type="hidden" name="sid" value="<?php echo (int)$sch->id; ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger"
                        onclick="return confirm('Delete this schedule?')">🗑</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div><!-- /scroll -->

    <p style="font-size:12px;color:#6c757d;margin:6px 0 0;"><?php echo count($allSchedules); ?> schedule(s) total.</p>
  </form>

  <?php endif; ?>
</div>

</div><!-- /lgbs-wrap -->

<script>
function lgbsToggleCourse(cid, checked) {
    document.querySelectorAll('.lgbs-course-act-' + cid).forEach(function(cb){ cb.checked = checked; });
}

function lgbsFilter(q) {
    q = q.toLowerCase().trim();
    document.querySelectorAll('#lgbs-user-tbody tr[data-name]').forEach(function(tr) {
        var show = !q
            || tr.dataset.name.indexOf(q) >= 0
            || tr.dataset.uname.indexOf(q) >= 0
            || tr.dataset.email.indexOf(q) >= 0;
        tr.classList.toggle('hidden', !show);
    });
}

function lgbsConfirmSubmit() {
    var acts = document.querySelectorAll('.lgbs-act-cb:checked').length;
    var usrs = document.querySelectorAll('.lgbs-ucb:checked').length;
    if (acts === 0) { alert('Please select at least one activity.'); return false; }
    if (usrs === 0) { alert('Please select at least one learner.'); return false; }
    var datestart = document.querySelector('[name="datestart"]').value;
    var dateend   = document.querySelector('[name="dateend"]').value;
    if (!datestart && !dateend) { alert('Please set an opening date, a closing date, or both.'); return false; }
    return confirm(
        'Apply schedule to ' + usrs + ' learner(s) across ' + acts + ' activity/activities?\n\n' +
        'Existing schedules for these learner/activity pairs will be updated.'
    );
}

function lgbsSchedFilter(q) {
    q = q.toLowerCase().trim();
    document.querySelectorAll('#lgbs-sched-tbody tr[data-search]').forEach(function(tr) {
        tr.classList.toggle('hidden', q !== '' && tr.dataset.search.indexOf(q) < 0);
    });
}

function lgbsSchedSelectAllVisible() {
    document.querySelectorAll('#lgbs-sched-tbody tr:not(.hidden) .lgbs-scb').forEach(function(c){ c.checked = true; });
}

function lgbsSchedBulkAction(action) {
    var sel = document.querySelectorAll('.lgbs-scb:checked').length;
    if (sel === 0) { alert('Please select at least one schedule.'); return; }
    if (action === 'bulkremove') {
        if (!confirm('Delete ' + sel + ' selected schedule(s)? This cannot be undone.')) { return; }
    } else if (action === 'bulkedit') {
        var ds = document.querySelector('[name="edit_datestart"]').value;
        var de = document.querySelector('[name="edit_dateend"]').value;
        if (!ds && !de) { alert('Please enter an opening date, a closing date, or both for the update.'); return; }
        if (!confirm('Update opens/closes for ' + sel + ' selected schedule(s)?')) { return; }
    }
    document.getElementById('lgbs-sched-action').value = action;
    document.getElementById('lgbs-sched-bulk-form').submit();
}
</script>

<?php echo $OUTPUT->footer(); ?>
