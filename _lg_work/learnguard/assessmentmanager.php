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
 * Workflow:
 *  1. Select a course — enrolled users and activity summary appear immediately.
 *  2. Choose "Apply to": All activities in the course  OR  a specific activity.
 *  3. Use the Bulk Override bar to fill values for all / selected users at once,
 *     OR edit per-user rows individually.
 *  4. Save — writes learnguard_schedules for each target activity + user.
 *  5. Bulk Reattempt (available when a specific activity is selected).
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

// courseid   = the course being managed.
// apply_lgid = 0 → apply to ALL activities in course;  > 0 → just that activity.
$courseid   = optional_param('courseid',   0, PARAM_INT);
$apply_lgid = optional_param('apply_lgid', 0, PARAM_INT);
$action     = optional_param('action',    '', PARAM_ALPHAEXT);

$PAGE->set_url('/mod/learnguard/assessmentmanager.php',
    ['courseid' => $courseid, 'apply_lgid' => $apply_lgid]);
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
    if ($action === 'save_user_overrides' && $courseid) {
        $userids        = required_param_array('uid',            PARAM_INT);
        $timeclose_vals = optional_param_array('timeclose_ts',   [], PARAM_INT);
        $extra_atts     = optional_param_array('extra_attempts', [], PARAM_INT);
        $timelimits     = optional_param_array('timelimit_secs', [], PARAM_INT);
        $skip_prechks   = optional_param_array('skip_precheck_uids', [], PARAM_INT);
        $cohort_names   = optional_param_array('cohort_name',   [], PARAM_TEXT);

        // Collect per-category violation exclusion UID lists.
        $excl_cats = ['camera', 'face', 'audio', 'screen', 'objects', 'tab', 'keyboard'];
        $excl_uids = [];
        foreach ($excl_cats as $cat) {
            $excl_uids[$cat] = optional_param_array('excl_' . $cat . '_uids', [], PARAM_INT);
        }

        // Determine which activity IDs to write.
        if ($apply_lgid > 0) {
            $targetLgids = [$apply_lgid];
        } else {
            $targetLgids = array_keys((array)$DB->get_records_sql(
                "SELECT lg.id FROM {learnguard} lg WHERE lg.course = :cid ORDER BY lg.name ASC",
                ['cid' => $courseid]
            ));
        }

        foreach ($targetLgids as $lgid) {
            $lgRec = $DB->get_record('learnguard', ['id' => (int)$lgid]);
            if (!$lgRec) { continue; }

            foreach ($userids as $idx => $uid) {
                $uid = (int)$uid;
                if (!$uid) { continue; }

                $tc = isset($timeclose_vals[$idx]) ? (int)$timeclose_vals[$idx] : 0;
                $ea = isset($extra_atts[$idx])     ? max(0, (int)$extra_atts[$idx]) : 0;
                $tl = isset($timelimits[$idx])     ? max(0, (int)$timelimits[$idx]) : 0;
                $sp = in_array($uid, $skip_prechks) ? 1 : 0;
                $cn = clean_param(trim($cohort_names[$idx] ?? ''), PARAM_TEXT);
                if (mb_strlen($cn) > 100) { $cn = mb_substr($cn, 0, 100); }

                // Build comma-separated violation exclusion list for this user.
                $excl_list = [];
                foreach ($excl_cats as $cat) {
                    if (in_array($uid, $excl_uids[$cat] ?? [])) {
                        $excl_list[] = $cat;
                    }
                }
                $ev = implode(',', $excl_list);

                $existing = $DB->get_record('learnguard_schedules',
                    ['learnguard' => (int)$lgid, 'userid' => $uid]);

                if ($existing) {
                    $existing->timeclose          = $tc;
                    $existing->extra_attempts     = $ea;
                    $existing->timelimit_override = $tl;
                    $existing->skip_precheck      = $sp;
                    $existing->exclude_violations = $ev;
                    $existing->cohort_name        = $cn;
                    $existing->timemodified       = time();
                    $DB->update_record('learnguard_schedules', $existing);
                } else {
                    $rec = new stdClass();
                    $rec->learnguard          = (int)$lgid;
                    $rec->userid              = $uid;
                    $rec->timeopen            = (int)$lgRec->timeopen;
                    $rec->timeclose           = $tc;
                    $rec->extra_attempts      = $ea;
                    $rec->timelimit_override  = $tl;
                    $rec->skip_precheck       = $sp;
                    $rec->exclude_violations  = $ev;
                    $rec->cohort_name         = $cn;
                    $rec->createdby           = $USER->id;
                    $rec->timecreated         = time();
                    $rec->timemodified        = time();
                    $DB->insert_record('learnguard_schedules', $rec);
                }
                // Option B: upsert learnguard_learner_cohorts (course-level cohort label).
                if ($cn !== '') {
                    $cohortRec = $DB->get_record('learnguard_learner_cohorts',
                        ['userid' => $uid, 'courseid' => $courseid]);
                    if ($cohortRec) {
                        $DB->set_field('learnguard_learner_cohorts', 'cohort_name', $cn, ['id' => $cohortRec->id]);
                    } else {
                        $DB->insert_record('learnguard_learner_cohorts', (object)[
                            'userid'      => $uid,
                            'courseid'    => $courseid,
                            'cohort_name' => $cn,
                            'timecreated' => time(),
                        ]);
                    }
                }
            }
        }

        $targetLabel = ($apply_lgid > 0) ? '1 assessment' : count($targetLgids) . ' assessment(s) in this course';
        redirect(
            new moodle_url('/mod/learnguard/assessmentmanager.php',
                ['courseid' => $courseid, 'apply_lgid' => $apply_lgid]),
            'Overrides saved for ' . $targetLabel . '.', null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    // ── Bulk reattempt — applies to the selected activity, or every activity in the
    // course when no specific activity is filtered (same "apply to all" rule the
    // per-user overrides above already use). ──────────────────────────────
    if ($action === 'bulk_reattempt' && $courseid) {
        $reattempt_uids = required_param_array('reattempt_uid', PARAM_INT);
        $bulkTargetLgids = ($apply_lgid > 0) ? [$apply_lgid] : array_keys((array)$DB->get_records_sql(
            "SELECT lg.id FROM {learnguard} lg WHERE lg.course = :cid ORDER BY lg.name ASC",
            ['cid' => $courseid]
        ));
        foreach ($bulkTargetLgids as $lgid) {
            $lgid  = (int)$lgid;
            $lgRec = $DB->get_record('learnguard', ['id' => $lgid]);
            if (!$lgRec) { continue; }
            foreach ($reattempt_uids as $uid) {
                $uid = (int)$uid;
                if (!$uid) { continue; }
                $existing = $DB->get_record('learnguard_schedules',
                    ['learnguard' => $lgid, 'userid' => $uid]);
                if ($existing) {
                    $DB->set_field('learnguard_schedules', 'extra_attempts',
                        (int)$existing->extra_attempts + 1, ['id' => $existing->id]);
                    $DB->set_field('learnguard_schedules', 'timemodified', time(), ['id' => $existing->id]);
                } else {
                    $rec = new stdClass();
                    $rec->learnguard         = $lgid;
                    $rec->userid             = $uid;
                    $rec->timeopen           = (int)$lgRec->timeopen;
                    $rec->timeclose          = 0;
                    $rec->extra_attempts     = 1;
                    $rec->timelimit_override = 0;
                    $rec->skip_precheck      = 0;
                    $rec->exclude_violations = '';
                    $rec->createdby          = $USER->id;
                    $rec->timecreated        = time();
                    $rec->timemodified       = time();
                    $DB->insert_record('learnguard_schedules', $rec);
                }
            }
        }
        redirect(
            new moodle_url('/mod/learnguard/assessmentmanager.php',
                ['courseid' => $courseid, 'apply_lgid' => $apply_lgid]),
            'Reattempt granted across ' . count($bulkTargetLgids) . ' assessment(s): users can now take one additional attempt.', null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    // ── Continue & Review: reopen each user's LATEST attempt — finished OR still
    // in-progress (e.g. abandoned/stuck, never submitted) — with a fresh review window,
    // instead of granting a brand new attempt. A student with no attempt row at all for
    // this activity is still skipped, since there is nothing to reopen. State goes back
    // to (or stays) 'inprogress' so the learner's existing answers/responses are still
    // there to review, complete, and change; reopened_for_review=1 forces the
    // precheck-redo gate and the review_autosuspend_score threshold on the client/server.
    // timestart is deliberately NOT set here — it's set by the learner's own
    // start_review() call once they've actually completed the precheck, so the granted
    // minutes count from when they begin, not from whenever this was clicked.
    // Applies to the selected activity, or every activity in the course when no
    // specific activity is filtered. ─────────────────────────────────────────
    if ($action === 'continue_review' && $courseid) {
        $review_uids = required_param_array('review_uid', PARAM_INT);
        $reviewMins  = optional_param('review_minutes', 15, PARAM_INT);
        $reviewMins  = max(1, min(180, $reviewMins));
        $reviewTargetLgids = ($apply_lgid > 0) ? [$apply_lgid] : array_keys((array)$DB->get_records_sql(
            "SELECT lg.id FROM {learnguard} lg WHERE lg.course = :cid ORDER BY lg.name ASC",
            ['cid' => $courseid]
        ));
        $reopenedCount = 0;
        $alreadyReviewedCount = 0;
        foreach ($reviewTargetLgids as $lgid) {
            $lgid = (int)$lgid;
            foreach ($review_uids as $uid) {
                $uid = (int)$uid;
                if (!$uid) { continue; }
                $latestRows = $DB->get_records_select('learnguard_attempts',
                    "learnguard = :lgid AND userid = :uid AND state IN ('finished', 'inprogress')",
                    ['lgid' => $lgid, 'uid' => $uid], 'timestart DESC', '*', 0, 1);
                $latestAttempt = $latestRows ? reset($latestRows) : null;
                if (!$latestAttempt) { continue; }
                // A learner only ever gets ONE Continue & Review cycle per attempt.
                // reopened_for_review is set the first time review is granted and is never
                // cleared — including after the learner resubmits — so its presence here
                // reliably means this exact attempt already had its one review (whether
                // still pending or already resubmitted). Skip rather than reopen again.
                if (!empty($latestAttempt->reopened_for_review)) {
                    $alreadyReviewedCount++;
                    continue;
                }
                // Snapshot the current score (if any) BEFORE it can be overwritten on
                // resubmit, so scores.php can show whether the review raised or lowered it.
                if ($latestAttempt->sumgrades !== null) {
                    $DB->set_field('learnguard_attempts', 'prev_sumgrades', $latestAttempt->sumgrades, ['id' => $latestAttempt->id]);
                }
                $DB->set_field('learnguard_attempts', 'state', 'inprogress', ['id' => $latestAttempt->id]);
                $DB->set_field('learnguard_attempts', 'reopened_for_review', 1, ['id' => $latestAttempt->id]);
                $DB->set_field('learnguard_attempts', 'extra_time', $reviewMins * 60, ['id' => $latestAttempt->id]);
                $reopenedCount++;
            }
        }
        $reviewMsg = $reopenedCount . ' attempt(s) reopened for review across ' . count($reviewTargetLgids) . ' assessment(s) — '
            . $reviewMins . ' minute(s) each, starting when the learner begins.';
        if ($alreadyReviewedCount > 0) {
            $reviewMsg .= ' ' . $alreadyReviewedCount . ' student(s) were skipped — each learner only gets one review per attempt, and they already used theirs.';
        }
        redirect(
            new moodle_url('/mod/learnguard/assessmentmanager.php',
                ['courseid' => $courseid, 'apply_lgid' => $apply_lgid]),
            $reviewMsg, null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    // ── Rename cohort batch ────────────────────────────────────────────────
    if ($action === 'rename_cohort' && $courseid) {
        $old_cn = mb_substr(clean_param(required_param('old_cohort', PARAM_TEXT), PARAM_TEXT), 0, 100);
        $new_cn = mb_substr(clean_param(required_param('new_cohort', PARAM_TEXT), PARAM_TEXT), 0, 100);
        if ($old_cn !== '' && $new_cn !== '' && $old_cn !== $new_cn) {
            $DB->execute(
                "UPDATE {learnguard_learner_cohorts} SET cohort_name = :newcn
                  WHERE courseid = :cid AND cohort_name = :oldcn",
                ['newcn' => $new_cn, 'cid' => $courseid, 'oldcn' => $old_cn]
            );
            $lgids = array_keys((array)$DB->get_records_sql(
                "SELECT lg.id FROM {learnguard} lg WHERE lg.course = :cid", ['cid' => $courseid]
            ));
            if (!empty($lgids)) {
                [$lgSql, $lgP] = $DB->get_in_or_equal($lgids, SQL_PARAMS_NAMED, 'rcl');
                $DB->execute(
                    "UPDATE {learnguard_schedules} SET cohort_name = :newcn
                      WHERE learnguard $lgSql AND cohort_name = :oldcn",
                    array_merge(['newcn' => $new_cn, 'oldcn' => $old_cn], $lgP)
                );
            }
        }
        redirect(
            new moodle_url('/mod/learnguard/assessmentmanager.php',
                ['courseid' => $courseid, 'apply_lgid' => $apply_lgid]),
            'Cohort batch renamed from "' . s($old_cn) . '" to "' . s($new_cn) . '".', null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

// -----------------------------------------------------------------------
// DATA
// -----------------------------------------------------------------------

// All courses with at least one LearnGuard activity.
$courses = $DB->get_records_sql(
    "SELECT DISTINCT c.id, c.fullname, c.shortname
       FROM {course} c
       JOIN {learnguard} lg ON lg.course = c.id
   ORDER BY c.fullname ASC"
);

// Activities in the selected course.
$activities = [];
if ($courseid) {
    $actRows = $DB->get_records_sql(
        "SELECT lg.id, lg.name, lg.timeopen, lg.timeclose, lg.timelimit,
                lg.attempts AS maxattempts, lg.grade, cm.id AS cmid
           FROM {learnguard} lg
           JOIN {course_modules} cm ON cm.instance = lg.id
              AND cm.module = (SELECT id FROM {modules} WHERE name = 'learnguard')
          WHERE lg.course = :cid
       ORDER BY lg.name ASC",
        ['cid' => $courseid]
    );
    $activities = $actRows; // keyed by lg.id
}

// Specific activity record (when apply_lgid > 0).
$selectedLg = ($apply_lgid > 0 && isset($activities[$apply_lgid])) ? $activities[$apply_lgid] : null;
$selectedCm = null;
if ($selectedLg) {
    $selectedCm = get_coursemodule_from_instance('learnguard', $apply_lgid, $courseid, false, MUST_EXIST);
}

// Users: course-level enrolment.
$users         = [];
$scheds        = [];
$attCounts     = [];
$cohortMap     = []; // [userid] => cohort_name (from learnguard_learner_cohorts)
$distinctCohorts = []; // distinct cohort names in this course (for filter/rename UI)
$enrolledCount = []; // keyed by lgid → enrolled student count

if ($courseid) {
    $courseCtx = context_course::instance($courseid);
    $users = get_enrolled_users($courseCtx, '', 0,
        'u.id,u.firstname,u.lastname,u.email,u.username');

    // Schedule overrides: load from the specific activity (if selected) or first activity.
    $schedLgid = ($apply_lgid > 0) ? $apply_lgid : (int)(array_key_first($activities) ?? 0);
    if ($schedLgid) {
        $schedRows = $DB->get_records('learnguard_schedules', ['learnguard' => $schedLgid]);
        foreach ($schedRows as $s) { $scheds[(int)$s->userid] = $s; }
    }

    // Attempt counts (specific activity only).
    if ($apply_lgid > 0) {
        $attRows = $DB->get_records_sql(
            "SELECT userid,
                    COUNT(*) AS total_attempts,
                    SUM(CASE WHEN state='finished'  THEN 1 ELSE 0 END) AS finished,
                    SUM(CASE WHEN state='inprogress' THEN 1 ELSE 0 END) AS inprogress,
                    SUM(CASE WHEN state='voided'    THEN 1 ELSE 0 END) AS voided,
                    MAX(sumgrades) AS best_grade
               FROM {learnguard_attempts}
              WHERE learnguard = :lgid
           GROUP BY userid",
            ['lgid' => $apply_lgid]
        );
        foreach ($attRows as $r) { $attCounts[(int)$r->userid] = $r; }
    }

    // Load course-level cohort labels (Option B table).
    if (!empty($users)) {
        $uids = array_keys($users);
        [$uidSqlC, $uidPC] = $DB->get_in_or_equal($uids, SQL_PARAMS_NAMED, 'lcu');
        $cohortRows = $DB->get_records_sql(
            "SELECT userid, cohort_name FROM {learnguard_learner_cohorts}
              WHERE courseid = :cid AND userid $uidSqlC",
            array_merge(['cid' => $courseid], $uidPC)
        );
        foreach ($cohortRows as $cr) { $cohortMap[(int)$cr->userid] = $cr->cohort_name; }

        $distinctCohorts = array_unique(array_filter(array_values($cohortMap)));
        sort($distinctCohorts);
    }

    // Also fetch enrollment timestamps for auto-suggest.
    $enrollTs = [];
    if (!empty($users)) {
        $uids = array_keys($users);
        [$uidSqlE, $uidPE] = $DB->get_in_or_equal($uids, SQL_PARAMS_NAMED, 'eu');
        $enrolRows = $DB->get_records_sql(
            "SELECT ue.userid, MIN(ue.timestart) AS enroltime
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :cid AND e.status = 0
              WHERE ue.userid $uidSqlE
           GROUP BY ue.userid",
            array_merge(['cid' => $courseid], $uidPE)
        );
        foreach ($enrolRows as $er) { $enrollTs[(int)$er->userid] = (int)$er->enroltime; }
    }

    // Enrolled count per activity for the summary table.
    foreach ($activities as $a) {
        try {
            $actCtx = context_module::instance((int)$a->cmid);
            $enrolledCount[(int)$a->id] = count(get_enrolled_users($actCtx, 'mod/learnguard:attempt', 0, 'u.id'));
        } catch (Exception $e) {
            $enrolledCount[(int)$a->id] = 0;
        }
    }
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
.lgam-section     { background:#fff; border:1px solid #dee2e6; border-radius:8px; margin-bottom:20px; }
.lgam-sec-head    { background:#f8f9fa; border-bottom:1px solid #dee2e6; padding:10px 16px;
                    font-weight:700; border-radius:8px 8px 0 0;
                    display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; }
.lgam-table       { width:100%; border-collapse:collapse; font-size:13px; }
.lgam-table th    { background:#f1f3f5; padding:8px 12px; text-align:left;
                    border-bottom:2px solid #dee2e6; white-space:nowrap; }
.lgam-table td    { padding:7px 12px; border-bottom:1px solid #f0f0f0; vertical-align:middle; }
.lgam-table tr:hover td { background:#f8f9fa; }
.lgam-table tr.lgam-bulk-row td { background:#fff8e1; border-bottom:2px solid #ffc107; }
.lgam-inp         { width:72px; padding:3px 6px; border:1px solid #ced4da;
                    border-radius:4px; font-size:12px; text-align:center; }
.lgam-inp.wide    { width:148px; }
.lgam-sel         { padding:6px 10px; border:1px solid #ced4da; border-radius:6px;
                    min-width:220px; font-size:13px; }
.lgam-hint        { font-size:11px; color:#6c757d; white-space:nowrap; }
.badge-att        { font-size:11px; padding:2px 7px; border-radius:8px; font-weight:600; display:inline-block; }
.badge-fin        { background:#d4edda; color:#155724; }
.badge-prog       { background:#cce5ff; color:#004085; }
.badge-void       { background:#f8d7da; color:#721c24; }
.lgam-act-sum th  { background:#e9ecef; }
.lgam-def-badge   { background:#e9ecef; border-radius:6px; padding:1px 7px;
                    font-size:11px; color:#495057; white-space:nowrap; }
.lgam-sort-btn    { background:none; border:none; cursor:pointer; font-size:11px;
                    color:#6c757d; padding:0 3px; vertical-align:middle; }
.lgam-sort-btn:hover { color:#0f6cbf; }
.lgam-filter-bar  { padding:8px 16px; border-bottom:1px solid #dee2e6;
                    display:flex; gap:10px; align-items:center; flex-wrap:wrap; background:#fafafa; }
.lgam-rename-bar  { padding:8px 16px; border-bottom:1px solid #c3e6cb;
                    display:flex; gap:8px; align-items:center; flex-wrap:wrap;
                    background:#f0fff4; }
</style>

<div class="lgam-wrap">
<h2 style="margin-bottom:6px;">📋 Assessment Manager</h2>
<p style="color:#6c757d;margin-bottom:16px;">
  Select a course to manage all enrolled users. Optionally target a specific assessment — or leave
  on <em>All activities</em> to apply overrides across every assessment in the course at once.
</p>

<!-- ── Filter bar ──────────────────────────────────────────────────── -->
<div class="lgam-filters">

  <select class="lgam-sel" id="lgam-course-sel" onchange="lgamNavCourse(this.value)">
    <option value="">— Select a course —</option>
    <?php foreach ($courses as $c): ?>
    <option value="<?php echo (int)$c->id; ?>" <?php if ($c->id == $courseid) echo 'selected'; ?>>
      <?php echo s($c->shortname . ' — ' . $c->fullname); ?>
    </option>
    <?php endforeach; ?>
  </select>

  <?php if ($courseid && !empty($activities)): ?>
  <select class="lgam-sel" id="lgam-act-sel" onchange="lgamNavActivity(this.value)">
    <option value="0">— All activities in course —</option>
    <?php foreach ($activities as $a): ?>
    <option value="<?php echo (int)$a->id; ?>" <?php if ($a->id == $apply_lgid) echo 'selected'; ?>>
      <?php echo s($a->name); ?>
    </option>
    <?php endforeach; ?>
  </select>
  <?php elseif ($courseid): ?>
  <span style="color:#6c757d;font-size:13px;">No LearnGuard assessments in this course.</span>
  <?php endif; ?>

</div>

<?php if (!$courseid): ?>
<div class="alert alert-secondary">Select a course above to begin.</div>
<?php elseif (empty($activities)): ?>
<div class="alert alert-info">No LearnGuard assessments found in this course.</div>
<?php else: ?>

<!-- ── Activity summary (always shown when course selected) ─────────── -->
<div class="lgam-section">
  <div class="lgam-sec-head">
    <span>📊 Assessments in This Course</span>
    <?php if ($apply_lgid > 0 && $selectedCm): ?>
    <a href="<?php echo new moodle_url('/mod/learnguard/admin.php', ['id' => $selectedCm->id]); ?>"
       class="btn btn-sm btn-outline-secondary" target="_blank">Open Activity Admin ↗</a>
    <?php endif; ?>
  </div>
  <div style="overflow-x:auto;">
  <table class="lgam-table lgam-act-sum">
    <thead>
      <tr>
        <th>Assessment</th>
        <th>Time Limit</th>
        <th>Max Attempts</th>
        <th>Opens</th>
        <th>Due / Closes</th>
        <th>Enrolled</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($activities as $a):
        $isSelected = ($a->id == $apply_lgid);
        $rowStyle   = $isSelected ? 'background:#e8f4fd;font-weight:600;' : '';
    ?>
    <tr style="<?php echo $rowStyle; ?>">
      <td>
        <?php if ($isSelected): ?><span style="color:#0f6cbf;">▶ </span><?php endif; ?>
        <?php echo s($a->name); ?>
      </td>
      <td><?php echo $a->timelimit ? round($a->timelimit / 60) . ' min' : 'None'; ?></td>
      <td><?php echo ($a->maxattempts ?? 0) ? (int)$a->maxattempts : 'Unlimited'; ?></td>
      <td><?php echo $a->timeopen  ? userdate((int)$a->timeopen,  get_string('strftimedatetime')) : '—'; ?></td>
      <td><?php echo $a->timeclose ? userdate((int)$a->timeclose, get_string('strftimedatetime')) : '—'; ?></td>
      <td><?php echo $enrolledCount[(int)$a->id] ?? '—'; ?></td>
      <td>
        <?php if (!$isSelected): ?>
        <button type="button" class="btn btn-xs btn-outline-primary"
                style="font-size:11px;padding:2px 8px;"
                onclick="lgamNavActivity('<?php echo (int)$a->id; ?>')">
          Filter →
        </button>
        <?php else: ?>
        <button type="button" class="btn btn-xs btn-outline-secondary"
                style="font-size:11px;padding:2px 8px;"
                onclick="lgamNavActivity(0)">
          Show all
        </button>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<!-- ── User overrides form ──────────────────────────────────────────── -->
<form method="post"
      action="<?php echo new moodle_url('/mod/learnguard/assessmentmanager.php',
          ['courseid' => $courseid, 'apply_lgid' => $apply_lgid]); ?>"
      id="lgam-override-form">
  <?php echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]); ?>
  <?php echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'action', 'value'=>'save_user_overrides']); ?>

<div class="lgam-section">
  <div class="lgam-sec-head">
    <span>
      👥 Per-User Overrides — <?php echo count($users); ?> student(s)
      <?php if ($apply_lgid > 0 && $selectedLg): ?>
        &mdash; <em><?php echo s($selectedLg->name); ?></em>
      <?php else: ?>
        &mdash; <em>All <?php echo count($activities); ?> assessment(s) in course</em>
      <?php endif; ?>
    </span>
    <button type="submit" class="btn btn-sm btn-primary">💾 Save All Overrides</button>
  </div>

  <!-- Bulk-fill bar ─────────────────────────────────────────────────── -->
  <div style="padding:10px 16px;background:#fffdf0;border-bottom:1px solid #ffc107;
              display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
    <strong style="font-size:13px;align-self:center;">⚡ Bulk fill:</strong>

    <label style="font-size:12px;display:flex;flex-direction:column;gap:2px;">
      Window Close<br>
      <input type="datetime-local" id="bulk-dt" class="lgam-inp wide"
             style="width:150px;" title="Leave blank to clear override">
    </label>

    <label style="font-size:12px;display:flex;flex-direction:column;gap:2px;">
      Extra Attempts<br>
      <input type="number" id="bulk-ea" class="lgam-inp" min="0" max="99" placeholder="0">
    </label>

    <label style="font-size:12px;display:flex;flex-direction:column;gap:2px;">
      Time Limit (min)<br>
      <input type="number" id="bulk-tl" class="lgam-inp" min="0" max="9999" placeholder="0">
    </label>

    <label style="font-size:12px;display:flex;flex-direction:column;gap:2px;">
      Cohort / Batch<br>
      <input type="text" id="bulk-cn" class="lgam-inp" style="width:130px;" maxlength="100"
             placeholder="e.g. Batch Jan-2026">
    </label>

    <div style="display:flex;flex-direction:column;gap:4px;">
      <button type="button" class="btn btn-sm btn-warning" onclick="lgamApplyBulk('all')">
        Apply to All Users
      </button>
      <button type="button" class="btn btn-sm btn-outline-warning" onclick="lgamApplyBulk('selected')">
        Apply to Ticked Only
      </button>
    </div>
    <small style="color:#6c757d;align-self:center;">
      Fills the form rows below — still need to click <strong>Save All Overrides</strong>.
    </small>
  </div>

  <?php if ($courseid && !empty($distinctCohorts)): ?>
  <!-- Rename cohort batch ───────────────────────────────────────────────── -->
  <div class="lgam-rename-bar">
    <strong style="font-size:12px;color:#155724;white-space:nowrap;">🔁 Rename Cohort Batch:</strong>
    <select id="lgam-rename-old" class="lgam-sel" style="min-width:0;width:160px;font-size:12px;padding:4px 8px;">
      <option value="">From cohort…</option>
      <?php foreach ($distinctCohorts as $dc): ?>
      <option value="<?php echo htmlspecialchars($dc, ENT_QUOTES); ?>"><?php echo htmlspecialchars($dc); ?></option>
      <?php endforeach; ?>
    </select>
    <span style="font-size:14px;color:#495057;">→</span>
    <input type="text" id="lgam-rename-new" class="lgam-inp" style="width:150px;"
           placeholder="New cohort name" maxlength="100">
    <button type="button" class="btn btn-sm btn-success" onclick="lgamRenameCohort()">
      🔁 Rename All
    </button>
    <small class="lgam-hint">Renames every learner in this course with the selected cohort label.</small>
  </div>
  <?php endif; ?>

  <!-- Filter / search bar ──────────────────────────────────────────────── -->
  <div class="lgam-filter-bar">
    <span style="font-size:12px;font-weight:600;color:#495057;white-space:nowrap;">🔍 Filter:</span>
    <input type="text" id="lgam-filter-name" class="lgam-inp" style="width:190px;"
           placeholder="Student name or email…" oninput="lgamFilterTable()">
    <?php if (!empty($distinctCohorts)): ?>
    <select id="lgam-filter-cohort" class="lgam-sel"
            style="min-width:0;width:160px;font-size:12px;padding:4px 8px;" onchange="lgamFilterTable()">
      <option value="">All cohorts</option>
      <?php foreach ($distinctCohorts as $dc): ?>
      <option value="<?php echo strtolower(htmlspecialchars($dc, ENT_QUOTES)); ?>">
        <?php echo htmlspecialchars($dc); ?>
      </option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="lgamClearFilters()">✕ Clear</button>
    <small class="lgam-hint" id="lgam-filter-count" style="color:#6c757d;"></small>
  </div>

  <div style="overflow-x:auto;">
  <table class="lgam-table">
    <thead>
      <tr>
        <th style="width:36px;">
          <input type="checkbox" id="lgam-chk-all" onclick="lgamToggleAll(this)"
                 title="Select all users">
        </th>
        <th>
          Student
          <button type="button" class="lgam-sort-btn" data-sortcol="name"
                  onclick="lgamSortTable('name')" title="Sort by name">↕</button>
        </th>
        <?php if ($apply_lgid > 0): ?>
        <th>Attempts</th>
        <th>Best Score</th>
        <?php endif; ?>
        <th>
          Window Close Override
          <button type="button" class="lgam-sort-btn" data-sortcol="timeclose"
                  onclick="lgamSortTable('timeclose')" title="Sort by close date">↕</button>
          <br><span class="lgam-hint">blank = activity default</span>
          <?php if ($apply_lgid > 0 && $selectedLg && $selectedLg->timeclose): ?>
          <br><span class="lgam-def-badge">Default: <?php echo userdate((int)$selectedLg->timeclose, '%d %b %Y %H:%M'); ?></span>
          <?php endif; ?>
        </th>
        <th>
          Extra Attempts
          <br><span class="lgam-hint">added to max</span>
          <?php if ($apply_lgid > 0 && $selectedLg): ?>
          <br><span class="lgam-def-badge">Max: <?php echo ($selectedLg->maxattempts ?? 0) ? (int)$selectedLg->maxattempts : '∞'; ?></span>
          <?php endif; ?>
        </th>
        <th>
          Time Limit Override (min)
          <br><span class="lgam-hint">0 = activity default</span>
          <?php if ($apply_lgid > 0 && $selectedLg): ?>
          <br><span class="lgam-def-badge">Default: <?php echo $selectedLg->timelimit ? round($selectedLg->timelimit/60).'min' : 'None'; ?></span>
          <?php endif; ?>
        </th>
        <th>
          Skip Precheck
          <br><span class="lgam-hint">bypass wizard</span>
        </th>
        <th>
          Exclude Violations
          <br><span class="lgam-hint">no score for category</span>
        </th>
        <th>
          Cohort / Batch
          <button type="button" class="lgam-sort-btn" data-sortcol="cohort"
                  onclick="lgamSortTable('cohort')" title="Sort by cohort">↕</button>
          <br><span class="lgam-hint">group label</span>
        </th>
        <?php if ($apply_lgid > 0): ?>
        <th>Actions</th>
        <?php endif; ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($users as $u):
        $sched  = $scheds[(int)$u->id] ?? null;
        $atts   = $attCounts[(int)$u->id] ?? null;
        $fin    = $atts ? (int)$atts->finished    : 0;
        $prog   = $atts ? (int)$atts->inprogress  : 0;
        $voided = $atts ? (int)$atts->voided       : 0;
        $total  = $atts ? (int)$atts->total_attempts : 0;

        $best = '—';
        if ($atts && $atts->best_grade !== null && $selectedLg && (float)$selectedLg->grade > 0) {
            $best = round((float)$atts->best_grade / (float)$selectedLg->grade * 100, 1) . '%';
        }

        $tcVal     = $sched ? (int)$sched->timeclose          : 0;
        $eaVal     = $sched ? (int)$sched->extra_attempts     : 0;
        $tlVal     = $sched ? (int)$sched->timelimit_override : 0;
        $spVal     = $sched ? (int)($sched->skip_precheck ?? 0) : 0;
        $evVal     = $sched ? (string)($sched->exclude_violations ?? '') : '';
        $evList    = $evVal !== '' ? array_filter(array_map('trim', explode(',', $evVal))) : [];
        $tlMins    = $tlVal > 0 ? (int)round($tlVal / 60) : 0;
        $tcDate    = $tcVal > 0 ? date('Y-m-d\TH:i', $tcVal) : '';
        // Cohort: prefer course-level (Option B) over per-schedule (Option A).
        $cnVal     = $cohortMap[(int)$u->id] ?? ($sched ? (string)($sched->cohort_name ?? '') : '');
        $cnEnrolTs = $enrollTs[(int)$u->id] ?? 0;
        $cnSuggest = $cnEnrolTs > 0 ? 'Batch ' . date('M-Y', $cnEnrolTs) : '';
    ?>
    <tr data-uid="<?php echo (int)$u->id; ?>"
        data-name="<?php echo strtolower(htmlspecialchars(fullname($u) . ' ' . $u->email, ENT_QUOTES)); ?>"
        data-timeclose="<?php echo $tcVal; ?>"
        data-cohort="<?php echo strtolower(htmlspecialchars($cnVal, ENT_QUOTES)); ?>">
      <td>
        <?php echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'uid[]','value'=>(int)$u->id]); ?>
        <input type="checkbox" class="lgam-chk" value="<?php echo (int)$u->id; ?>">
      </td>
      <td>
        <strong><?php echo fullname($u); ?></strong><br>
        <small style="color:#6c757d;"><?php echo s($u->email); ?></small>
      </td>
      <?php if ($apply_lgid > 0): ?>
      <td>
        <?php if ($total): ?>
          <span class="badge-att badge-fin"><?php echo $fin; ?> done</span>
          <?php if ($prog):   ?><span class="badge-att badge-prog"><?php echo $prog;   ?> active</span><?php endif; ?>
          <?php if ($voided): ?><span class="badge-att badge-void"><?php echo $voided; ?> voided</span><?php endif; ?>
        <?php else: ?>
          <span style="color:#aaa;">Not started</span>
        <?php endif; ?>
      </td>
      <td><?php echo $best; ?></td>
      <?php endif; ?>
      <td>
        <input type="datetime-local" name="timeclose_dt[]" class="lgam-inp wide lgam-dt row-dt"
               value="<?php echo $tcDate; ?>">
        <input type="hidden" name="timeclose_ts[]" class="lgam-ts" value="<?php echo $tcVal; ?>">
        <?php if ($tcVal > 0): ?>
        <br><span class="lgam-hint">→ <?php echo userdate($tcVal, '%d %b %Y %H:%M'); ?></span>
        <?php endif; ?>
      </td>
      <td>
        <input type="number" name="extra_attempts[]" class="lgam-inp row-ea"
               value="<?php echo $eaVal; ?>" min="0" max="99">
      </td>
      <td>
        <input type="number" name="timelimit_secs[]" class="lgam-inp row-tl" data-type="minutes"
               value="<?php echo $tlMins; ?>" min="0" max="9999"
               title="Minutes (0 = activity default)">
      </td>
      <td style="text-align:center;">
        <input type="checkbox" name="skip_precheck_uids[]" value="<?php echo (int)$u->id; ?>"
               title="Bypass the pre-exam wizard for this user"
               <?php echo $spVal ? 'checked' : ''; ?>>
      </td>
      <td style="padding:4px 6px;">
        <?php
        $excl_labels = [
            'camera'   => ['📷', 'Camera',   'Webcam denied / unavailable / covered'],
            'face'     => ['👤', 'Face',     'Face missing / multiple faces / identity mismatch / head pose / blink'],
            'audio'    => ['🔊', 'Audio',    'Noise detection / audio clips'],
            'screen'   => ['🖥', 'Screen',   'Screen-share stopped or denied'],
            'objects'  => ['📦', 'Objects',  'Phone / book / laptop / keyboard detected + multiple persons'],
            'tab'      => ['🔀', 'Tab',      'Tab switch / fullscreen exit / page unload / multiple monitors'],
            'keyboard' => ['⌨', 'Keys',     'Key violations / clipboard copy-paste / print-screen'],
        ];
        echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:2px 8px;min-width:130px;">';
        foreach ($excl_labels as $cat => [$icon, $label, $tip]) {
            $checked = in_array($cat, $evList) ? 'checked' : '';
            echo '<label title="' . s($tip) . '" style="display:flex;align-items:center;gap:3px;'
               . 'font-size:11px;cursor:pointer;white-space:nowrap;">'
               . '<input type="checkbox" name="excl_' . $cat . '_uids[]"'
               . ' value="' . (int)$u->id . '" ' . $checked . '>'
               . ' ' . $icon . ' ' . $label . '</label>';
        }
        echo '</div>';
        ?>
      </td>
      <td style="min-width:150px;">
        <?php if ($cnVal !== ''): ?>
        <span style="background:#e8f0fe;color:#1a56db;border-radius:4px;padding:2px 8px;
                     font-size:11px;font-weight:600;display:inline-block;margin-bottom:3px;">
          <?php echo htmlspecialchars($cnVal); ?>
        </span><br>
        <?php endif; ?>
        <input type="text" name="cohort_name[]" class="lgam-inp" style="width:130px;"
               value="<?php echo htmlspecialchars($cnVal); ?>"
               placeholder="<?php echo htmlspecialchars($cnSuggest ?: 'Cohort name…'); ?>"
               maxlength="100"
               title="Cohort / batch label for this learner">
        <?php if ($cnSuggest && $cnVal === ''): ?>
        <br><span class="lgam-hint" style="cursor:pointer;color:#0f6cbf;"
                  onclick="this.previousElementSibling.value=<?php echo json_encode($cnSuggest); ?>"
                  title="Click to use suggested cohort">💡 <?php echo htmlspecialchars($cnSuggest); ?></span>
        <?php endif; ?>
      </td>
      <?php if ($apply_lgid > 0 && $selectedCm): ?>
      <td>
        <a href="<?php echo new moodle_url('/mod/learnguard/grader_user_report.php',
            ['id' => $selectedCm->id, 'userid' => $u->id]); ?>"
           class="btn btn-xs btn-outline-secondary" style="font-size:11px;padding:2px 6px;"
           target="_blank">📄 Attempts</a>
      </td>
      <?php elseif ($apply_lgid > 0): ?>
      <td>&mdash;</td>
      <?php endif; ?>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <div style="padding:10px 16px;border-top:1px solid #dee2e6;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
    <button type="submit" class="btn btn-primary">💾 Save All Overrides</button>
    <small style="color:#6c757d;">
      <strong>Window Close:</strong> personal deadline (overrides activity default).&nbsp;
      <strong>Extra Attempts:</strong> added to the activity maximum per user.&nbsp;
      <strong>Time Limit:</strong> personal limit in minutes (0 = use activity default).&nbsp;
      <strong>Skip Precheck:</strong> user goes straight to questions, bypassing the pre-exam wizard.&nbsp;
      <strong>Exclude Violations:</strong> ticked categories are not logged or scored for that user (e.g. exclude Camera if they have a hardware issue).
      <?php if ($apply_lgid === 0): ?>
      &nbsp;— Applies to <strong>all <?php echo count($activities); ?> assessment(s)</strong> in this course.
      <?php endif; ?>
    </small>
  </div>
</div>
</form>

<?php if ($courseid): ?>
<!-- ── Bulk Reattempt ────────────────────────────────────────────────── -->
<div class="lgam-section">
  <div class="lgam-sec-head">🔄 Bulk Reattempt</div>
  <div style="padding:12px 16px;">
    <p style="color:#6c757d;font-size:13px;margin-bottom:10px;">
      Tick users in the table above, then click <strong>Grant Reattempt</strong> to allow
      each selected user one additional attempt. Their existing attempts are kept intact as
      Attempt 1, 2, etc. — nothing is voided. To void a specific attempt, use the
      grader or scores page directly.
      <?php if ($apply_lgid === 0): ?>
      <strong>Applies to all <?php echo count($activities); ?> assessment(s)</strong> in this course —
      use the Filter button on a specific assessment above to target just one.
      <?php else: ?>
      Applies to <strong><?php echo s(!empty($selectedLg) ? $selectedLg->name : 'this assessment'); ?></strong> only.
      <?php endif; ?>
    </p>
    <form method="post" action="<?php echo new moodle_url('/mod/learnguard/assessmentmanager.php',
        ['courseid' => $courseid, 'apply_lgid' => $apply_lgid]); ?>" id="lgam-reattempt-form">
      <?php echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]); ?>
      <?php echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'action', 'value'=>'bulk_reattempt']); ?>
      <div id="lgam-reattempt-targets"></div>
      <button type="button" class="btn btn-warning" onclick="lgamSubmitReattempt()">
        🔄 Grant Reattempt for Ticked Users
      </button>
    </form>
  </div>
</div>

<!-- ── Continue & Review (extra time on the EXISTING attempt) ─────────── -->
<div class="lgam-section">
  <div class="lgam-sec-head">⏱ Continue &amp; Review (Extra Time)</div>
  <div style="padding:12px 16px;">
    <p style="color:#6c757d;font-size:13px;margin-bottom:10px;">
      Tick users in the table above, set a review time, then click <strong>Grant Continue &amp;
      Review</strong>. Unlike Bulk Reattempt (a brand new attempt), this reopens each user's
      <strong>latest attempt — finished, or still in-progress/abandoned</strong> — so they can go
      back in, review their answers, complete or change them, and resubmit — nothing is voided.
      They must redo the security precheck before continuing, and the timer starts once they
      actually begin, not when you click this button. Auto-suspend during the review uses the
      <strong>Continue &amp; Review Suspension Threshold</strong> site setting instead of the
      activity's normal threshold.
      <?php if ($apply_lgid === 0): ?>
      <strong>Applies to all <?php echo count($activities); ?> assessment(s)</strong> in this course —
      users with no attempt at all on a given assessment are skipped for it.
      <?php else: ?>
      Applies to <strong><?php echo s(!empty($selectedLg) ? $selectedLg->name : 'this assessment'); ?></strong> only.
      <?php endif; ?>
    </p>
    <form method="post" action="<?php echo new moodle_url('/mod/learnguard/assessmentmanager.php',
        ['courseid' => $courseid, 'apply_lgid' => $apply_lgid]); ?>" id="lgam-review-form">
      <?php echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]); ?>
      <?php echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'action', 'value'=>'continue_review']); ?>
      <div id="lgam-review-targets"></div>
      <label style="font-size:13px;font-weight:600;margin-right:8px;">Review time (minutes):
        <input type="number" name="review_minutes" id="lgam-review-minutes" value="15" min="1" max="180"
            style="width:70px;margin-left:4px;padding:4px 6px;border:1px solid #ced4da;border-radius:4px;">
      </label>
      <button type="button" class="btn btn-info" style="color:#fff;" onclick="lgamSubmitReview()">
        ⏱ Grant Continue &amp; Review for Ticked Users
      </button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php endif; // courseid + activities ?>

</div><!-- .lgam-wrap -->

<script>
var lgamBaseUrl   = '<?php echo (new moodle_url('/mod/learnguard/assessmentmanager.php'))->out(false); ?>';
var lgamCourseId  = <?php echo (int)$courseid; ?>;
var lgamSesskey   = '<?php echo sesskey(); ?>';
var lgamApplyLgid = <?php echo (int)$apply_lgid; ?>;

function lgamNavCourse(courseId) {
    if (!courseId) {
        window.location.href = lgamBaseUrl;
    } else {
        window.location.href = lgamBaseUrl + '?courseid=' + encodeURIComponent(courseId) + '&apply_lgid=0';
    }
}

function lgamNavActivity(lgid) {
    window.location.href = lgamBaseUrl + '?courseid=' + encodeURIComponent(lgamCourseId)
        + '&apply_lgid=' + encodeURIComponent(lgid || 0);
}

function lgamToggleAll(master) {
    document.querySelectorAll('.lgam-chk').forEach(function(c) { c.checked = master.checked; });
}

// Bulk-fill handler: populate all (or ticked) user rows from the bulk bar.
function lgamApplyBulk(mode) {
    var dtVal  = document.getElementById('bulk-dt').value;
    var eaVal  = document.getElementById('bulk-ea').value;
    var tlVal  = document.getElementById('bulk-tl').value;
    var cnEl   = document.getElementById('bulk-cn');
    var cnVal  = cnEl ? cnEl.value.trim() : '';

    // Determine which rows to update.
    var rows = document.querySelectorAll('#lgam-override-form tbody tr');
    rows.forEach(function(row) {
        var chk = row.querySelector('.lgam-chk');
        if (mode === 'selected' && (!chk || !chk.checked)) { return; }

        var dtInp = row.querySelector('.row-dt');
        var tsInp = dtInp ? dtInp.nextElementSibling : null;
        var eaInp = row.querySelector('.row-ea');
        var tlInp = row.querySelector('.row-tl');
        var cnInp = row.querySelector('input[name="cohort_name[]"]');

        if (dtInp !== null) {
            dtInp.value = dtVal;
            if (tsInp && tsInp.type === 'hidden') {
                tsInp.value = dtVal ? Math.floor(new Date(dtVal).getTime() / 1000) : 0;
            }
        }
        if (eaInp !== null && eaVal !== '') { eaInp.value = eaVal; }
        if (tlInp !== null && tlVal !== '') { tlInp.value = tlVal; }
        if (cnInp !== null && cnVal !== '') { cnInp.value = cnVal; }
    });

    var count = (mode === 'all')
        ? rows.length
        : document.querySelectorAll('.lgam-chk:checked').length;
    if (count === 0) { alert('No users are ticked. Tick users first.'); return; }
    // Visual feedback.
    var btn = event.target;
    var orig = btn.textContent;
    btn.textContent = '✓ Applied to ' + count + ' user(s)';
    setTimeout(function() { btn.textContent = orig; }, 2000);
}

// Keep hidden timestamp fields in sync with datetime-local inputs.
document.querySelectorAll('.lgam-dt').forEach(function(inp) {
    inp.addEventListener('change', function() {
        var ts = inp.value ? Math.floor(new Date(inp.value).getTime() / 1000) : 0;
        var hidden = inp.nextElementSibling;
        if (hidden && hidden.type === 'hidden') { hidden.value = ts; }
    });
});

// On submit: convert minute inputs to seconds + sync any un-fired datetime inputs.
var overrideForm = document.getElementById('lgam-override-form');
if (overrideForm) {
    overrideForm.addEventListener('submit', function() {
        overrideForm.querySelectorAll('input[data-type="minutes"]').forEach(function(inp) {
            inp.value = Math.max(0, parseInt(inp.value, 10) || 0) * 60;
        });
        overrideForm.querySelectorAll('.lgam-dt').forEach(function(inp) {
            var ts = inp.value ? Math.floor(new Date(inp.value).getTime() / 1000) : 0;
            var hidden = inp.nextElementSibling;
            if (hidden && hidden.type === 'hidden') { hidden.value = ts; }
        });
    });
}

// ── Sort & filter for the overrides table ─────────────────────────────────
var lgamSortState = {col: '', asc: true};
function lgamSortTable(col) {
    var tbody = document.querySelector('#lgam-override-form tbody');
    if (!tbody) { return; }
    var asc = (lgamSortState.col !== col) || !lgamSortState.asc;
    lgamSortState = {col: col, asc: asc};
    var rows = Array.from(tbody.querySelectorAll('tr[data-uid]'));
    rows.sort(function(a, b) {
        var av = a.dataset[col] || '';
        var bv = b.dataset[col] || '';
        if (col === 'timeclose') {
            av = parseInt(av, 10) || 0;
            bv = parseInt(bv, 10) || 0;
            return asc ? av - bv : bv - av;
        }
        return asc ? av.localeCompare(bv) : bv.localeCompare(av);
    });
    rows.forEach(function(r) { tbody.appendChild(r); });
    document.querySelectorAll('.lgam-sort-btn').forEach(function(b) { b.textContent = '↕'; });
    var btn = document.querySelector('.lgam-sort-btn[data-sortcol="' + col + '"]');
    if (btn) { btn.textContent = asc ? '↑' : '↓'; }
}

function lgamFilterTable() {
    var nameQ   = ((document.getElementById('lgam-filter-name')   || {}).value || '').toLowerCase().trim();
    var cohortQ = ((document.getElementById('lgam-filter-cohort') || {}).value || '');
    var shown = 0;
    document.querySelectorAll('#lgam-override-form tbody tr[data-uid]').forEach(function(tr) {
        var nOk = !nameQ   || (tr.dataset.name   || '').indexOf(nameQ) >= 0;
        var cOk = !cohortQ || (tr.dataset.cohort || '') === cohortQ;
        var vis = nOk && cOk;
        tr.style.display = vis ? '' : 'none';
        if (vis) { shown++; }
    });
    var cnt = document.getElementById('lgam-filter-count');
    if (cnt) { cnt.textContent = (nameQ || cohortQ) ? shown + ' student(s) shown' : ''; }
}

function lgamRenameCohort() {
    var oldSel = document.getElementById('lgam-rename-old');
    var newInp = document.getElementById('lgam-rename-new');
    if (!oldSel || !newInp) { return; }
    var oldVal = oldSel.value.trim();
    var newVal = newInp.value.trim();
    if (!oldVal) { alert('Please select a cohort to rename.'); return; }
    if (!newVal) { alert('Please enter a new cohort name.'); return; }
    if (oldVal === newVal) { alert('Old and new cohort names are the same.'); return; }
    if (!confirm('Rename all learners with cohort "' + oldVal + '" to "' + newVal + '"?')) { return; }
    var f = document.createElement('form');
    f.method = 'post';
    f.action = lgamBaseUrl + '?courseid=' + encodeURIComponent(lgamCourseId)
               + '&apply_lgid=' + encodeURIComponent(lgamApplyLgid);
    var fields = {sesskey: lgamSesskey, action: 'rename_cohort',
                  old_cohort: oldVal, new_cohort: newVal};
    Object.keys(fields).forEach(function(k) {
        var inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = k; inp.value = fields[k];
        f.appendChild(inp);
    });
    document.body.appendChild(f);
    f.submit();
}

function lgamClearFilters() {
    var n = document.getElementById('lgam-filter-name');
    var c = document.getElementById('lgam-filter-cohort');
    if (n) { n.value = ''; }
    if (c) { c.value = ''; }
    lgamFilterTable();
}

function lgamSubmitReattempt() {
    var form    = document.getElementById('lgam-reattempt-form');
    var targets = document.getElementById('lgam-reattempt-targets');
    targets.innerHTML = '';
    var checked = document.querySelectorAll('.lgam-chk:checked');
    if (!checked.length) {
        alert('Please tick at least one student first.');
        return;
    }
    checked.forEach(function(c) {
        var inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = 'reattempt_uid[]'; inp.value = c.value;
        targets.appendChild(inp);
    });
    var reScope = lgamApplyLgid > 0 ? 'this assessment' : 'ALL assessments in this course';
    if (confirm('Grant one additional attempt for ' + checked.length + ' student(s) on ' + reScope +
            '?\nTheir existing attempts are kept intact — nothing is voided.')) {
        form.submit();
    }
}

function lgamSubmitReview() {
    var form    = document.getElementById('lgam-review-form');
    var targets = document.getElementById('lgam-review-targets');
    targets.innerHTML = '';
    var checked = document.querySelectorAll('.lgam-chk:checked');
    if (!checked.length) {
        alert('Please tick at least one student first.');
        return;
    }
    var mins = parseInt(document.getElementById('lgam-review-minutes').value, 10) || 15;
    checked.forEach(function(c) {
        var inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = 'review_uid[]'; inp.value = c.value;
        targets.appendChild(inp);
    });
    var rvScope = lgamApplyLgid > 0 ? 'this assessment' : 'ALL assessments in this course';
    if (confirm('Reopen the latest attempt (finished or still in-progress/abandoned) for ' + checked.length +
            ' student(s) on ' + rvScope + ' with ' + mins + ' minute(s) to review and resubmit?\n' +
            'Students with no attempt at all on a given assessment are skipped for it.')) {
        form.submit();
    }
}
</script>

<?php echo $OUTPUT->footer(); ?>
