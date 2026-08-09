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
// Also build a per-user course list so the learner table can be filtered by
// which activities/courses are selected in Step 1.
$allUsers      = [];
$userCourseIds = []; // [userid] => [courseid, ...]
if (!empty($courseGroups)) {
    $courseIds = array_keys($courseGroups);
    [$cidSql, $cidP] = $DB->get_in_or_equal($courseIds, SQL_PARAMS_NAMED, 'c');
    $userCourseRows = $DB->get_records_sql(
        "SELECT u.id, u.firstname, u.lastname, u.email, u.username, e.courseid
           FROM {user} u
           JOIN {user_enrolments} ue ON ue.userid = u.id AND ue.status = 0
           JOIN {enrol} e ON e.id = ue.enrolid AND e.status = 0
          WHERE e.courseid $cidSql AND u.deleted = 0 AND u.suspended = 0
          ORDER BY u.lastname ASC, u.firstname ASC",
        $cidP
    );
    foreach ($userCourseRows as $r) {
        $uid = (int)$r->id;
        $allUsers[$uid] = $r; // deduplicate; last record wins (all rows same user data)
        $userCourseIds[$uid][] = (int)$r->courseid;
    }
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

// Bulk cohort assign (set cohort_name on selected schedule rows + upsert learnguard_learner_cohorts).
if ($action === 'bulkcohort' && data_submitted() && confirm_sesskey()) {
    $sids        = optional_param_array('sel_sched', [], PARAM_INT);
    $cohortName  = optional_param('cohort_name_bulk', '', PARAM_TEXT);
    $cohortName  = clean_param(trim($cohortName), PARAM_TEXT);
    if (mb_strlen($cohortName) > 100) { $cohortName = mb_substr($cohortName, 0, 100); }
    if (empty($sids)) {
        $errors[] = 'No schedules selected.';
    } else {
        $now = time();
        foreach ($sids as $sid) {
            $sid = (int)$sid;
            $sch = $DB->get_record('learnguard_schedules', ['id' => $sid]);
            if (!$sch) { continue; }
            // Option A: update cohort_name on schedule row.
            $DB->set_field('learnguard_schedules', 'cohort_name', $cohortName, ['id' => $sid]);
            // Option B: upsert learnguard_learner_cohorts using courseid from learnguard.
            $courseId = (int)$DB->get_field('learnguard', 'course', ['id' => $sch->learnguard]);
            if ($courseId) {
                $existing = $DB->get_record('learnguard_learner_cohorts',
                    ['userid' => (int)$sch->userid, 'courseid' => $courseId]);
                if ($existing) {
                    $DB->set_field('learnguard_learner_cohorts', 'cohort_name', $cohortName,
                        ['id' => $existing->id]);
                } else {
                    $DB->insert_record('learnguard_learner_cohorts', (object)[
                        'userid'      => (int)$sch->userid,
                        'courseid'    => $courseId,
                        'cohort_name' => $cohortName,
                        'timecreated' => $now,
                    ]);
                }
            }
        }
        $messages[] = count($sids) . ' learner(s) assigned to cohort "' . s($cohortName) . '".';
    }
}

// Per-row cohort save.
if ($action === 'savecohort' && data_submitted() && confirm_sesskey()) {
    $sid        = required_param('sid', PARAM_INT);
    $cohortName = optional_param('cohort_name_row', '', PARAM_TEXT);
    $cohortName = clean_param(trim($cohortName), PARAM_TEXT);
    if (mb_strlen($cohortName) > 100) { $cohortName = mb_substr($cohortName, 0, 100); }
    $sch = $DB->get_record('learnguard_schedules', ['id' => $sid]);
    if ($sch) {
        $DB->set_field('learnguard_schedules', 'cohort_name', $cohortName, ['id' => $sid]);
        $courseId = (int)$DB->get_field('learnguard', 'course', ['id' => $sch->learnguard]);
        if ($courseId) {
            $existing = $DB->get_record('learnguard_learner_cohorts',
                ['userid' => (int)$sch->userid, 'courseid' => $courseId]);
            if ($existing) {
                $DB->set_field('learnguard_learner_cohorts', 'cohort_name', $cohortName,
                    ['id' => $existing->id]);
            } else {
                $DB->insert_record('learnguard_learner_cohorts', (object)[
                    'userid'      => (int)$sch->userid,
                    'courseid'    => $courseId,
                    'cohort_name' => $cohortName,
                    'timecreated' => time(),
                ]);
            }
        }
        $messages[] = 'Cohort updated.';
    }
}

// ── Load all current schedules (for the management section) ──────────────────
$allSchedules = $DB->get_records_sql(
    "SELECT sch.id, sch.learnguard AS lgid, sch.userid, sch.timeopen, sch.timeclose,
            sch.cohort_name AS sched_cohort,
            u.firstname, u.lastname, u.email, u.username,
            lg.name AS actname,
            c.fullname AS coursefull, c.shortname AS courseshort,
            c.id AS courseid,
            cm.id AS cmid,
            COALESCE(lc.cohort_name, sch.cohort_name, '') AS cohort_name,
            MIN(ue.timestart) AS enrol_timestart
       FROM {learnguard_schedules} sch
       JOIN {user}           u  ON u.id         = sch.userid
       JOIN {learnguard}     lg ON lg.id        = sch.learnguard
       JOIN {course_modules} cm ON cm.instance  = sch.learnguard
       JOIN {modules}        mo ON mo.id        = cm.module AND mo.name = 'learnguard'
       JOIN {course}         c  ON c.id         = cm.course
       LEFT JOIN {learnguard_learner_cohorts} lc ON lc.userid = sch.userid AND lc.courseid = c.id
       LEFT JOIN {enrol}          e  ON e.courseid   = c.id AND e.status = 0
       LEFT JOIN {user_enrolments} ue ON ue.enrolid  = e.id AND ue.userid = sch.userid
      WHERE u.deleted = 0
      GROUP BY sch.id, sch.learnguard, sch.userid, sch.timeopen, sch.timeclose,
               sch.cohort_name, u.firstname, u.lastname, u.email, u.username,
               lg.name, c.fullname, c.shortname, c.id, cm.id, lc.cohort_name
      ORDER BY c.fullname ASC, lg.name ASC, u.lastname ASC, u.firstname ASC"
);

// Collect distinct cohort names from all schedules for the filter dropdown.
$distinctBsCohorts = [];
foreach ($allSchedules as $_bssch) {
    $cv = (string)($_bssch->cohort_name ?? '');
    if ($cv !== '' && !in_array($cv, $distinctBsCohorts, true)) {
        $distinctBsCohorts[] = $cv;
    }
}
sort($distinctBsCohorts);

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
.lgbs-cohort-badge  { background:#e8f0fe; color:#1a56db; border-radius:4px; padding:2px 8px;
                      font-size:11px; font-weight:600; display:inline-block; }
.lgbs-cohort-inp    { width:120px; padding:3px 6px; border:1px solid #ced4da; border-radius:4px;
                      font-size:12px; }
.lgbs-sort-btn      { background:none; border:none; cursor:pointer; font-size:11px;
                      color:#6c757d; padding:0 3px; vertical-align:middle; }
.lgbs-sort-btn:hover { color:#0f6cbf; }
.lgbs-sched-filter-bar { display:flex; gap:8px; align-items:center; flex-wrap:wrap;
                          margin-bottom:8px; padding:6px 0; }
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
            onclick="document.querySelectorAll('.lgbs-act-cb').forEach(function(c){c.checked=true;});lgbsApplyUserFilter();">
      ☑ Select All Activities
    </button>
    <button type="button" class="btn btn-sm btn-outline-secondary"
            onclick="document.querySelectorAll('.lgbs-act-cb').forEach(function(c){c.checked=false;});lgbsApplyUserFilter();">
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
             name="sel_cmids[]" value="<?php echo (int)$act->cmid; ?>"
             onchange="lgbsApplyUserFilter()">
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
    Only learners enrolled in the courses whose activities you selected in Step 1 are shown.
    Learners not enrolled in a specific selected activity are silently skipped when saving.
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
            data-email="<?php echo htmlspecialchars(strtolower($u->email)); ?>"
            data-courses="<?php echo htmlspecialchars(implode(',', $userCourseIds[(int)$u->id] ?? [])); ?>"
            class="hidden">
          <td><input type="checkbox" class="lgbs-ucb" name="sel_uids[]" value="<?php echo (int)$u->id; ?>"></td>
          <td><strong><?php echo htmlspecialchars(fullname($u)); ?></strong></td>
          <td><?php echo htmlspecialchars($u->username); ?></td>
          <td><?php echo htmlspecialchars($u->email); ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <p style="font-size:12px;color:#6c757d;margin:6px 0 0;" id="lgbs-user-count"><?php echo count($allUsers); ?> user(s) total. Select activities above to filter.</p>
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

      <span style="border-left:1px solid #b8d0f8;height:22px;margin:0 4px;"></span>
      <span style="font-size:13px;font-weight:600;">🏷 Cohort:</span>
      <input type="text" name="cohort_name_bulk" id="lgbs-cohort-bulk-inp"
             class="form-control form-control-sm lgbs-cohort-inp"
             placeholder="e.g. Batch Jan-2026" maxlength="100">
      <button type="button" class="btn btn-sm btn-outline-info"
              onclick="lgbsCohortSuggest()" title="Auto-suggest from enrollment date">💡 Suggest</button>
      <button type="button" class="btn btn-sm btn-info"
              onclick="lgbsSchedBulkAction('bulkcohort')">🏷 Assign Cohort</button>
    </div>

    <!-- Column filter bar ──────────────────────────────────────────────── -->
    <div class="lgbs-sched-filter-bar">
      <span style="font-size:12px;font-weight:600;color:#495057;">Filter:</span>
      <select id="lgbs-filter-status" onchange="lgbsSchedFilterUpdate()"
              class="form-control form-control-sm" style="width:120px;" title="Filter by status">
        <option value="">All statuses</option>
        <option value="open">Open</option>
        <option value="closed">Closed</option>
        <option value="future">Future</option>
        <option value="anytime">Anytime</option>
      </select>
      <?php if (!empty($distinctBsCohorts)): ?>
      <select id="lgbs-filter-cohort" onchange="lgbsSchedFilterUpdate()"
              class="form-control form-control-sm" style="width:160px;" title="Filter by cohort">
        <option value="">All cohorts</option>
        <?php foreach ($distinctBsCohorts as $dbc): ?>
        <option value="<?php echo strtolower(htmlspecialchars($dbc, ENT_QUOTES)); ?>">
          <?php echo htmlspecialchars($dbc); ?>
        </option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="lgbsClearSchedFilters()">✕ Clear filters</button>
      <small id="lgbs-sched-filter-count" style="color:#6c757d;font-size:12px;"></small>
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
            <th>
              Opens
              <button type="button" class="lgbs-sort-btn" data-sortcol="opens"
                      onclick="lgbsSchedSort('opens')" title="Sort by open date">↕</button>
            </th>
            <th>
              Closes
              <button type="button" class="lgbs-sort-btn" data-sortcol="closes"
                      onclick="lgbsSchedSort('closes')" title="Sort by close date">↕</button>
            </th>
            <th>
              Status
              <button type="button" class="lgbs-sort-btn" data-sortcol="status"
                      onclick="lgbsSchedSort('status')" title="Sort by status">↕</button>
            </th>
            <th>
              Cohort / Batch
              <button type="button" class="lgbs-sort-btn" data-sortcol="cohort"
                      onclick="lgbsSchedSort('cohort')" title="Sort by cohort">↕</button>
            </th>
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
            $openStr    = $to > 0 ? userdate($to, get_string('strftimedatetimeshort')) : '&mdash;';
            $closeStr   = $tc > 0 ? userdate($tc, get_string('strftimedatetimeshort')) : '&mdash;';
            $cohortVal  = (string)($sch->cohort_name ?? '');
            $enrolTs    = (int)($sch->enrol_timestart ?? 0);
            $suggestVal = $enrolTs > 0 ? 'Batch ' . date('M-Y', $enrolTs) : '';
            $searchText = strtolower(
                format_string($sch->coursefull) . ' ' .
                format_string($sch->actname) . ' ' .
                fullname($sch) . ' ' .
                $sch->username . ' ' . $sch->email . ' ' . $cohortVal
            );
        ?>
          <tr data-search="<?php echo htmlspecialchars($searchText); ?>"
              data-enrolts="<?php echo $enrolTs; ?>"
              data-suggest="<?php echo htmlspecialchars($suggestVal); ?>"
              data-opens="<?php echo $to; ?>"
              data-closes="<?php echo $tc; ?>"
              data-status="<?php echo strtolower($statusLabel); ?>"
              data-cohort="<?php echo strtolower(htmlspecialchars($cohortVal, ENT_QUOTES)); ?>">
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
            <td style="min-width:170px;">
              <?php if ($cohortVal !== ''): ?>
              <span class="lgbs-cohort-badge"><?php echo htmlspecialchars($cohortVal); ?></span><br>
              <?php endif; ?>
              <form method="post" action="" style="display:inline-flex;gap:4px;margin-top:3px;align-items:center;">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <input type="hidden" name="action" value="savecohort">
                <input type="hidden" name="sid" value="<?php echo (int)$sch->id; ?>">
                <input type="text" name="cohort_name_row" class="lgbs-cohort-inp"
                       value="<?php echo htmlspecialchars($cohortVal); ?>"
                       placeholder="<?php echo htmlspecialchars($suggestVal ?: 'Cohort name…'); ?>"
                       maxlength="100">
                <button type="submit" class="btn btn-xs btn-outline-info"
                        style="padding:2px 6px;font-size:11px;" title="Save cohort">💾</button>
              </form>
            </td>
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
    lgbsApplyUserFilter();
}

// Gather which course IDs have at least one checked activity.
function lgbsSelectedCourseIds() {
    var courses = {};
    document.querySelectorAll('.lgbs-act-cb:checked').forEach(function(cb) {
        // Each activity checkbox has classes like "lgbs-act-cb lgbs-course-act-{cid}".
        cb.className.split(' ').forEach(function(cls) {
            var m = cls.match(/^lgbs-course-act-(\d+)$/);
            if (m) { courses[m[1]] = true; }
        });
    });
    return courses;
}

function lgbsApplyUserFilter() {
    var q = (document.getElementById('lgbs-search') ? document.getElementById('lgbs-search').value : '').toLowerCase().trim();
    var selectedCourses = lgbsSelectedCourseIds();
    var hasSelection = Object.keys(selectedCourses).length > 0;
    var visible = 0;
    document.querySelectorAll('#lgbs-user-tbody tr[data-name]').forEach(function(tr) {
        var textMatch = !q
            || tr.dataset.name.indexOf(q) >= 0
            || tr.dataset.uname.indexOf(q) >= 0
            || tr.dataset.email.indexOf(q) >= 0;
        var courseMatch = !hasSelection || (tr.dataset.courses || '').split(',').some(function(cid) {
            return cid && selectedCourses[cid];
        });
        var show = textMatch && courseMatch;
        tr.classList.toggle('hidden', !show);
        if (show) { visible++; }
    });
    var cntEl = document.getElementById('lgbs-user-count');
    if (cntEl) { cntEl.textContent = visible + ' learner(s) match the current filter.'; }
}

function lgbsFilter(q) {
    lgbsApplyUserFilter();
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

function lgbsSchedFilterUpdate() {
    var q      = ((document.getElementById('lgbs-sched-search')  || {}).value || '').toLowerCase().trim();
    var status = ((document.getElementById('lgbs-filter-status') || {}).value || '');
    var cohort = ((document.getElementById('lgbs-filter-cohort') || {}).value || '');
    var shown  = 0;
    document.querySelectorAll('#lgbs-sched-tbody tr[data-search]').forEach(function(tr) {
        var textOk   = !q      || tr.dataset.search.indexOf(q) >= 0;
        var statusOk = !status || (tr.dataset.status || '') === status;
        var cohortOk = !cohort || (tr.dataset.cohort || '') === cohort;
        var vis = textOk && statusOk && cohortOk;
        tr.classList.toggle('hidden', !vis);
        if (vis) { shown++; }
    });
    var cnt = document.getElementById('lgbs-sched-filter-count');
    if (cnt) { cnt.textContent = (q || status || cohort) ? shown + ' shown' : ''; }
}

function lgbsSchedFilter(q) { lgbsSchedFilterUpdate(); }

function lgbsClearSchedFilters() {
    var s = document.getElementById('lgbs-filter-status');
    var c = document.getElementById('lgbs-filter-cohort');
    var t = document.getElementById('lgbs-sched-search');
    if (s) { s.value = ''; }
    if (c) { c.value = ''; }
    if (t) { t.value = ''; }
    lgbsSchedFilterUpdate();
}

var lgbsSchedSortState = {col: '', asc: true};
function lgbsSchedSort(col) {
    var tbody = document.getElementById('lgbs-sched-tbody');
    if (!tbody) { return; }
    var asc = (lgbsSchedSortState.col !== col) || !lgbsSchedSortState.asc;
    lgbsSchedSortState = {col: col, asc: asc};
    var rows = Array.from(tbody.querySelectorAll('tr[data-search]'));
    rows.sort(function(a, b) {
        var av = a.dataset[col] || '';
        var bv = b.dataset[col] || '';
        if (col === 'opens' || col === 'closes') {
            av = parseInt(av, 10) || 0;
            bv = parseInt(bv, 10) || 0;
            return asc ? av - bv : bv - av;
        }
        return asc ? av.localeCompare(bv) : bv.localeCompare(av);
    });
    rows.forEach(function(r) { tbody.appendChild(r); });
    document.querySelectorAll('.lgbs-sort-btn').forEach(function(b) { b.textContent = '↕'; });
    var btn = document.querySelector('.lgbs-sort-btn[data-sortcol="' + col + '"]');
    if (btn) { btn.textContent = asc ? '↑' : '↓'; }
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
    } else if (action === 'bulkcohort') {
        var cn = document.getElementById('lgbs-cohort-bulk-inp').value.trim();
        if (!cn) { alert('Please enter a cohort name first.'); return; }
        if (!confirm('Assign cohort "' + cn + '" to ' + sel + ' selected learner(s)?')) { return; }
    }
    document.getElementById('lgbs-sched-action').value = action;
    document.getElementById('lgbs-sched-bulk-form').submit();
}

function lgbsCohortSuggest() {
    // Find the earliest enrollment suggestion from checked rows; fall back to all visible rows.
    var rows = document.querySelectorAll('#lgbs-sched-tbody .lgbs-scb:checked');
    if (!rows.length) { rows = document.querySelectorAll('#lgbs-sched-tbody tr:not(.hidden)'); }
    var earliest = 0;
    rows.forEach(function(el) {
        var tr  = el.closest ? el.closest('tr') : el.parentElement;
        var ts  = parseInt((tr ? tr.dataset.enrolts : 0) || 0, 10);
        var sug = tr ? tr.dataset.suggest : '';
        if (ts > 0 && (earliest === 0 || ts < earliest)) { earliest = ts; }
    });
    var inp = document.getElementById('lgbs-cohort-bulk-inp');
    if (earliest > 0) {
        var d = new Date(earliest * 1000);
        var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        inp.value = 'Batch ' + months[d.getMonth()] + '-' + d.getFullYear();
    } else {
        var now = new Date();
        var months2 = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        inp.value = 'Batch ' + months2[now.getMonth()] + '-' + now.getFullYear();
    }
    inp.focus();
}
</script>

<?php echo $OUTPUT->footer(); ?>
