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
 * Site-wide cross-course score matrix for mod_learnguard.
 *
 * Columns grouped by course. For each course: one score column per assessment,
 * then one shared "Attempt #" column and one "Last Completed" column for that course.
 * Sorting by Name / Total / Average / Most Recent via clickable headers or dropdown.
 *
 * URL: /mod/learnguard/scorematrix.php
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
$context = context_system::instance();

// Score Matrix access is its own independent grant (learnguard_score_viewers) — separate
// from being a proctor or grader — plus full site admins always get in. A global grant
// (is_global=1) sees every course; a course-scoped grant only sees the course(s) it's
// assigned to, enforced below via $allowedCourseIds (null = unrestricted).
$isSiteAdmin = has_capability('moodle/site:config', $context);
$allowedCourseIds = null;
if (!$isSiteAdmin) {
    $isGlobalViewer = $DB->record_exists_select(
        'learnguard_score_viewers', 'userid = :uid AND is_global = 1', ['uid' => $USER->id]);
    if (!$isGlobalViewer) {
        $courseGrants = $DB->get_records_select(
            'learnguard_score_viewers', 'userid = :uid AND is_global = 0', ['uid' => $USER->id]);
        $allowedCourseIds = array_values(array_unique(array_map(
            fn($r) => (int)$r->courseid, $courseGrants)));
        if (empty($allowedCourseIds)) {
            throw new moodle_exception('accessdenied', 'error', '', 'You do not have permission to view the Score Matrix.');
        }
    }
}

$filterCourse = optional_param('filter_course', 0, PARAM_INT);
$sortKey      = optional_param('sort', 'name_asc', PARAM_ALPHANUMEXT);
$download     = optional_param('download', '', PARAM_ALPHA);
$dateFrom     = optional_param('date_from', '', PARAM_TEXT);
$dateTo       = optional_param('date_to',   '', PARAM_TEXT);
// Completed-date range filter: only attempts whose timefinish falls in this window
// are included anywhere below — same $cellMap feeds both the on-screen table AND the
// CSV export, so applying the filter once here keeps both in lockstep automatically.
$dateFromTs = ($dateFrom !== '' && strtotime($dateFrom) !== false) ? strtotime($dateFrom . ' 00:00:00') : 0;
$dateToTs   = ($dateTo   !== '' && strtotime($dateTo)   !== false) ? strtotime($dateTo   . ' 23:59:59') : 0;

$validSorts = ['name_asc', 'name_desc', 'total_desc', 'total_asc', 'avg_desc', 'avg_asc', 'recent_desc', 'recent_asc'];
if (!in_array($sortKey, $validSorts)) { $sortKey = 'name_asc'; }

// Void an attempt's score directly from the matrix (same effect as grader.php's
// "Void" action — sets state='voided' so it stops counting toward totals/averages
// and the student can be granted a reattempt; history is preserved, not deleted).
// Restricted to full site admins even though the page itself is now also reachable by a
// scoped/global Score Matrix viewer — that grant is meant for viewing only, not for
// mutating or deleting attempt data outside whatever it was actually intended for.
if ($isSiteAdmin && data_submitted() && confirm_sesskey() && optional_param('smaction', '', PARAM_ALPHA) === 'voidattempt') {
    $vaid     = required_param('vaid', PARAM_INT);
    $vattempt = $DB->get_record('learnguard_attempts', ['id' => $vaid], '*', IGNORE_MISSING);
    if ($vattempt && in_array($vattempt->state, ['finished', 'suspended'])) {
        $DB->set_field('learnguard_attempts', 'state', 'voided', ['id' => $vaid]);
        \core\notification::success('Attempt score voided — it no longer counts toward totals.');
    }
    redirect(new moodle_url('/mod/learnguard/scorematrix.php', array_filter([
        'filter_course' => $filterCourse, 'sort' => $sortKey,
        'date_from' => $dateFrom, 'date_to' => $dateTo,
    ])));
}

// Bulk void — tick multiple score cells across the matrix, then Void Selected. Same
// effect as the single per-cell void above (state='voided', kept visible with a
// strikethrough), just for many attempts at once — e.g. cleaning up a burst of
// duplicate attempts caused by a since-fixed retry bug, without clicking one by one.
if ($isSiteAdmin && data_submitted() && confirm_sesskey() && optional_param('smaction', '', PARAM_ALPHA) === 'bulkvoid') {
    $bulkIds = optional_param_array('bulk_attid', [], PARAM_INT);
    $voided = 0;
    foreach ($bulkIds as $baid) {
        $battempt = $DB->get_record('learnguard_attempts', ['id' => (int)$baid], '*', IGNORE_MISSING);
        if ($battempt && in_array($battempt->state, ['finished', 'suspended'])) {
            $DB->set_field('learnguard_attempts', 'state', 'voided', ['id' => $battempt->id]);
            $voided++;
        }
    }
    redirect(new moodle_url('/mod/learnguard/scorematrix.php', array_filter([
        'filter_course' => $filterCourse, 'sort' => $sortKey,
        'date_from' => $dateFrom, 'date_to' => $dateTo,
    ])), $voided . ' attempt(s) voided — they no longer count toward totals.', null,
        \core\output\notification::NOTIFY_SUCCESS);
}

// Bulk delete — PERMANENTLY removes the selected attempts (and every row that
// references them) rather than just voiding them, so they disappear from this and
// every other report entirely. Unlike void, this cannot be undone.
if ($isSiteAdmin && data_submitted() && confirm_sesskey() && optional_param('smaction', '', PARAM_ALPHA) === 'bulkdelete') {
    $bulkIds = optional_param_array('bulk_attid', [], PARAM_INT);
    $deleted = 0;
    foreach ($bulkIds as $baid) {
        $baid = (int)$baid;
        if ($baid && $DB->record_exists('learnguard_attempts', ['id' => $baid])) {
            learnguard_delete_single_attempt($baid);
            $deleted++;
        }
    }
    redirect(new moodle_url('/mod/learnguard/scorematrix.php', array_filter([
        'filter_course' => $filterCourse, 'sort' => $sortKey,
        'date_from' => $dateFrom, 'date_to' => $dateTo,
    ])), $deleted . ' attempt(s) permanently deleted.', null,
        \core\output\notification::NOTIFY_SUCCESS);
}

// Manually run the "finish expired attempts" sweep right now instead of waiting on the
// scheduled task (every 5 minutes via cron) — same trigger as Assessment Manager's, added
// here too since a blank/"review pending" cell in the matrix is exactly the symptom this
// fixes. Site-wide, same as the scheduled task itself; site-admin only, matching the
// other mutating actions on this page.
if ($isSiteAdmin && data_submitted() && confirm_sesskey() && optional_param('smaction', '', PARAM_ALPHA) === 'finishexpired') {
    $finishedNow = learnguard_finish_expired_attempts();
    redirect(new moodle_url('/mod/learnguard/scorematrix.php', array_filter([
        'filter_course' => $filterCourse, 'sort' => $sortKey,
        'date_from' => $dateFrom, 'date_to' => $dateTo,
    ])), $finishedNow > 0
            ? $finishedNow . ' overdue attempt(s) force-finished — refresh to see updated scores.'
            : 'No overdue attempts found — nothing needed finishing.',
        null, \core\output\notification::NOTIFY_SUCCESS);
}

// ── Single "N/A" cell click → remind this one student they haven't attempted yet ──
if ($isSiteAdmin && data_submitted() && confirm_sesskey() && optional_param('smaction', '', PARAM_ALPHA) === 'sendreminder') {
    $rUserId  = optional_param('ruserid', 0, PARAM_INT);
    $rLgId    = optional_param('rlgid', 0, PARAM_INT);
    $mailSent = ($rUserId && $rLgId) ? learnguard_notify_student_attempt_reminder($rLgId, $rUserId) : false;
    redirect(new moodle_url('/mod/learnguard/scorematrix.php', array_filter([
        'filter_course' => $filterCourse, 'sort' => $sortKey,
        'date_from' => $dateFrom, 'date_to' => $dateTo,
    ])), $mailSent
            ? 'Reminder email sent.'
            : 'Reminder email failed or is disabled — check Mail History for details.',
        null, $mailSent ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING);
}

// ── Bulk "Send to All" — AJAX endpoint, called by the client in batches of 5 (see the
// JS below) so a course with hundreds of not-yet-attempted students doesn't fire
// hundreds of emails in one request under the same server-load conditions today's other
// fixes were specifically written around. Returns JSON, not a redirect.
if ($isSiteAdmin && data_submitted() && confirm_sesskey() && optional_param('smaction', '', PARAM_ALPHA) === 'sendreminderbatch') {
    $pairs = optional_param_array('pairs', [], PARAM_RAW);
    $sentCount = 0;
    foreach ($pairs as $pair) {
        $parts = explode(':', $pair, 2);
        $pu = isset($parts[0]) ? (int)$parts[0] : 0;
        $pl = isset($parts[1]) ? (int)$parts[1] : 0;
        if ($pu > 0 && $pl > 0 && learnguard_notify_student_attempt_reminder($pl, $pu)) {
            $sentCount++;
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['sent' => $sentCount, 'total' => count($pairs)]);
    die;
}

// ── Score cell "Grant Continue & Review" — reopens this SPECIFIC attempt (already
// identified by the cell the admin clicked, unlike assessmentmanager.php's bulk
// grant which has to look up each user's latest attempt) with the site default
// review-minutes allowance and no absolute deadline override; the email states the
// outer assessment window as the hard ceiling either way. Same one-review-per-attempt
// rule as assessmentmanager.php — no re-grant override here, since this is a quick
// single-click action, not the deliberate bulk-override workflow.
if ($isSiteAdmin && data_submitted() && confirm_sesskey() && optional_param('smaction', '', PARAM_ALPHA) === 'grantreview') {
    $grAttemptId = optional_param('grattemptid', 0, PARAM_INT);
    $grAttempt   = $DB->get_record('learnguard_attempts', ['id' => $grAttemptId], '*', IGNORE_MISSING);
    $redirectUrl = new moodle_url('/mod/learnguard/scorematrix.php', array_filter([
        'filter_course' => $filterCourse, 'sort' => $sortKey,
        'date_from' => $dateFrom, 'date_to' => $dateTo,
    ]));
    if (!$grAttempt || !in_array($grAttempt->state, ['finished', 'inprogress'], true)) {
        redirect($redirectUrl, 'Could not grant review for that attempt.', null, \core\output\notification::NOTIFY_WARNING);
    }
    if (!empty($grAttempt->reopened_for_review)) {
        redirect($redirectUrl,
            'This attempt already had its one Continue & Review. Use Assessment Manager\'s ' .
            '"Allow re-granting" option if this one genuinely needs a second review.',
            null, \core\output\notification::NOTIFY_WARNING);
    }
    if ($grAttempt->sumgrades !== null) {
        $DB->set_field('learnguard_attempts', 'prev_sumgrades', $grAttempt->sumgrades, ['id' => $grAttempt->id]);
    }
    $DB->set_field('learnguard_attempts', 'state', 'inprogress', ['id' => $grAttempt->id]);
    $DB->set_field('learnguard_attempts', 'reopened_for_review', 1, ['id' => $grAttempt->id]);
    $DB->set_field('learnguard_attempts', 'extra_time', 15 * 60, ['id' => $grAttempt->id]);
    $DB->set_field('learnguard_attempts', 'review_granted_time', time(), ['id' => $grAttempt->id]);
    // Same reasoning as assessmentmanager.php's Continue & Review grant: make sure the
    // student's course enrolment isn't suspended, or a review grant here is pointless.
    // No absolute deadline is set on this quick single-click grant, so only the
    // suspended-status fix applies (no date to extend expiry to).
    $grLgCourse = $DB->get_field('learnguard', 'course', ['id' => $grAttempt->learnguard]);
    if ($grLgCourse) {
        learnguard_ensure_course_access((int)$grAttempt->userid, (int)$grLgCourse, 0);
    }
    $mailSent = learnguard_notify_student_review_granted($grAttempt->id);
    redirect($redirectUrl, 'Continue & Review granted' .
        ($mailSent ? ' and the student has been emailed.' : ' — but the notification email failed or is disabled; check Mail History.'),
        null, \core\output\notification::NOTIFY_SUCCESS);
}

// ── Assessments ───────────────────────────────────────────────────────────────
$params    = [];
$coursesql = '';
// A course-scoped Score Matrix grant restricts everything to its allowed course(s),
// regardless of what filter_course is requested — silently fall back to "all of my
// allowed courses" rather than error, since this is just the normal filter dropdown
// being reused by a more restricted viewer.
if ($allowedCourseIds !== null && $filterCourse > 0 && !in_array($filterCourse, $allowedCourseIds, true)) {
    $filterCourse = 0;
}
if ($filterCourse > 0) {
    $coursesql          = ' AND c.id = :courseid';
    $params['courseid'] = $filterCourse;
} elseif ($allowedCourseIds !== null) {
    [$courseInSql, $courseInParams] = $DB->get_in_or_equal($allowedCourseIds, SQL_PARAMS_NAMED, 'ac');
    $coursesql = " AND c.id $courseInSql";
    $params    = array_merge($params, $courseInParams);
}
// cm.deletioninprogress: Moodle's course_delete_module() marks this flag immediately
// when a module is deleted from the course UI, but the actual row deletion (and this
// plugin's own learnguard_delete_instance() cascade) only completes when core's adhoc
// deletion task next runs via cron — which can be minutes to hours later. Without this
// filter, a "deleted" assessment kept showing here (and inflating the Average
// denominator) for as long as it sat waiting to be purged, even though it had already
// disappeared from the course itself.
$assessments = $DB->get_records_sql(
    "SELECT lg.id, lg.name, lg.grade AS maxgrade, c.id AS courseid, c.fullname AS coursename, cm.id AS cmid
       FROM {learnguard} lg
       JOIN {course} c ON c.id = lg.course
       JOIN {modules} mdl ON mdl.name = 'learnguard'
       JOIN {course_modules} cm ON cm.instance = lg.id AND cm.module = mdl.id
      WHERE cm.deletioninprogress = 0$coursesql
   ORDER BY c.fullname, lg.name",
    $params
);

// Group by course: each course group has N score columns + 1 Last Completed.
$byCourse = [];
foreach ($assessments as $a) {
    if (!isset($byCourse[$a->courseid])) {
        $byCourse[$a->courseid] = ['name' => $a->coursename, 'assessments' => []];
    }
    $byCourse[$a->courseid]['assessments'][] = $a;
}

// Denominator for the Average column: total max possible across EVERY assessment
// shown in the matrix (same for every row, since the columns are the same for every
// student), not just the ones a given student has a score for. Otherwise a student
// who only completed 1 of 2 assessments shows the same average as one who completed
// both — e.g. 100/100 on one assessment reads as "100.00" instead of the true 50%
// (100 gotten out of 200 possible across both assessments).
$totalMaxPossible = array_sum(array_column($assessments, 'maxgrade'));

// ── Cells ─────────────────────────────────────────────────────────────────────
$cellMap  = [];
$students = [];
$suspendStatus = [];
if (!empty($assessments)) {
    [$insql, $inparams] = $DB->get_in_or_equal(array_keys($assessments), SQL_PARAMS_NAMED, 'lg');

    // Completed-date range, applied only when set — an in-progress attempt (timefinish=0)
    // naturally falls outside any real range and is excluded, which is the correct
    // behaviour: a date filter means "scores completed in this window."
    $dateWhere = '';
    if ($dateFromTs > 0) { $dateWhere .= ' AND a.timefinish >= :datefromts'; $inparams['datefromts'] = $dateFromTs; }
    if ($dateToTs   > 0) { $dateWhere .= ' AND a.timefinish <= :datets';    $inparams['datets']      = $dateToTs; }

    // Fetch ALL finished+voided attempts ordered by id ASC (not attempt column — that field
    // can repeat when admin voids an attempt and grants a reattempt, resetting it to 1).
    // Voided attempts are included so that attempt numbering is preserved (#1 voided, #2 finished)
    // and historical data is visible. Voided cells render with a strikethrough indicator.
    $rawCells = [];
    $cellRS = $DB->get_recordset_sql(
        "SELECT a.learnguard, a.userid, a.id, a.timefinish, a.state,
                a.reopened_for_review, a.prev_sumgrades,
                CASE WHEN a.state = 'voided' THEN a.sumgrades
                     WHEN EXISTS (
                    SELECT 1
                      FROM {learnguard_slots} es
                      JOIN {learnguard_qbank} eq ON eq.id = es.questionid AND eq.qtype IN ('essay', 'choiceessay')
                     WHERE es.learnguard = a.learnguard
                       AND (
                           EXISTS (SELECT 1 FROM {learnguard_attempt_questions} aq
                                    WHERE aq.slotid = es.id AND aq.attemptid = a.id)
                           OR EXISTS (SELECT 1 FROM {learnguard_responses} r
                                       WHERE r.slotid = es.id AND r.attemptid = a.id)
                           OR NOT EXISTS (SELECT 1 FROM {learnguard_attempt_questions} aq2
                                           WHERE aq2.attemptid = a.id)
                       )
                       AND NOT EXISTS (
                           SELECT 1 FROM {learnguard_essay_grades} eg
                            WHERE eg.slotid = es.id AND eg.attemptid = a.id
                       )
                ) THEN NULL ELSE a.sumgrades END AS sumgrades
           FROM {learnguard_attempts} a
          WHERE a.state IN ('finished', 'voided', 'inprogress', 'suspended') AND a.learnguard $insql$dateWhere
          ORDER BY a.userid, a.learnguard, a.id ASC",
        $inparams
    );
    foreach ($cellRS as $row) {
        // A Continue & Review attempt sits in state='inprogress' from the moment review is
        // GRANTED, before the learner has touched anything — showing that as a bare "in
        // progress" cell made a real, previously-scored student look ungraded for the whole
        // review window. While pending (not yet resubmitted), show the pre-review snapshot
        // (prev_sumgrades) with a "review pending" indicator instead of hiding the score.
        $pendingReview = ($row->state === 'inprogress' && !empty($row->reopened_for_review) && $row->prev_sumgrades !== null);
        $rawCells[$row->userid][$row->learnguard][] = [
            'id'            => (int)$row->id,
            'sumgrades'     => $pendingReview ? (float)$row->prev_sumgrades
                                : ($row->sumgrades !== null ? (float)$row->sumgrades : null),
            'prev_sumgrades' => $row->prev_sumgrades !== null ? (float)$row->prev_sumgrades : null,
            'timefinish'    => (int)$row->timefinish,
            'voided'        => ($row->state === 'voided'),
            'inprogress'    => ($row->state === 'inprogress') && !$pendingReview,
            'reviewpending' => $pendingReview,
            'suspended'     => ($row->state === 'suspended'),
        ];
    }
    $cellRS->close();

    // Re-key as 1-based sequential attempt numbers in id order so duplicate attempt
    // numbers in the DB (caused by the void+reattempt flow) don't collapse into one cell.
    $cellMap = [];
    foreach ($rawCells as $uid => $lgMap) {
        foreach ($lgMap as $lgid => $attempts) {
            foreach ($attempts as $idx => $att) {
                $cellMap[$uid][$lgid][$idx + 1] = $att;
            }
        }
    }

    $students = $DB->get_records_sql(
        "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.username
           FROM {learnguard_attempts} a
           JOIN {user} u ON u.id = a.userid
          WHERE a.learnguard $insql$dateWhere
       ORDER BY u.lastname, u.firstname",
        $inparams
    );

    // Suspension status per student — 'confirmed' (is_flagged=1) or 'pending' (still
    // awaiting a decision), used to colour their name in the table and CSV below.
    // Deliberately NOT date-filtered — a currently suspended attempt has no completion
    // date (timefinish=0) to filter on, and this reflects current status, not "did they
    // complete something in this window."
    [$susInsql, $susInparams] = $DB->get_in_or_equal(array_keys($assessments), SQL_PARAMS_NAMED, 'sus');
    $susRows = $DB->get_records_sql(
        "SELECT userid, MAX(CASE WHEN is_flagged = 1 THEN 2 ELSE 1 END) AS lvl
           FROM {learnguard_attempts}
          WHERE learnguard $susInsql AND state = 'suspended'
       GROUP BY userid",
        $susInparams
    );
    $suspendStatus = [];
    foreach ($susRows as $r) {
        $suspendStatus[(int)$r->userid] = ((int)$r->lvl === 2) ? 'confirmed' : 'pending';
    }
}

// ── Per-student aggregates ────────────────────────────────────────────────────
$studentMeta = [];
foreach ($students as $stu) {
    $sum = 0.0; $cnt = 0; $latest = 0;
    foreach ($assessments as $a) {
        $attempts = $cellMap[$stu->id][$a->id] ?? [];
        // For Total/Average, use the most recent attempt with a non-null score.
        $latestScore = null; $latestTf = 0;
        foreach ($attempts as $attNum => $att) {
            if ($att['sumgrades'] !== null) { $latestScore = $att['sumgrades']; }
            if ($att['timefinish'] > $latestTf) { $latestTf = $att['timefinish']; }
        }
        if ($latestScore !== null) { $sum += $latestScore; $cnt++; }
        if ($latestTf > $latest) { $latest = $latestTf; }
    }
    $studentMeta[$stu->id] = [
        'total'  => $sum,
        'count'  => $cnt,
        // Same fix as the displayed Average column: percentage of total possible across
        // ALL assessments, not just the ones this student has a score for.
        'avg'    => ($cnt > 0 && $totalMaxPossible > 0) ? ($sum / $totalMaxPossible) * 100 : -1,
        'latest' => $latest,
    ];
}

// Max attempt number per student (for per-attempt row output).
$maxAttemptsPerStudent = [];
foreach ($cellMap as $uid => $lgMap) {
    $mx = 0;
    foreach ($lgMap as $lgid => $attMap) {
        foreach ($attMap as $attNum => $att) {
            if ($attNum > $mx) { $mx = $attNum; }
        }
    }
    $maxAttemptsPerStudent[$uid] = $mx;
}

// ── Sort ──────────────────────────────────────────────────────────────────────
if ($sortKey !== 'name_asc') {
    uasort($students, function($a, $b) use ($sortKey, $studentMeta) {
        $ma = $studentMeta[$a->id];
        $mb = $studentMeta[$b->id];
        switch ($sortKey) {
            case 'name_desc':   return strnatcasecmp($b->lastname . $b->firstname, $a->lastname . $a->firstname);
            case 'total_desc':  return $mb['total']  <=> $ma['total'];
            case 'total_asc':   return $ma['total']  <=> $mb['total'];
            case 'avg_desc':    return $mb['avg']    <=> $ma['avg'];
            case 'avg_asc':     return $ma['avg']    <=> $mb['avg'];
            case 'recent_desc': return $mb['latest'] <=> $ma['latest'];
            case 'recent_asc':  return $ma['latest'] <=> $mb['latest'];
        }
        return strnatcasecmp($a->lastname . $a->firstname, $b->lastname . $b->firstname);
    });
}

// ── CSV export ────────────────────────────────────────────────────────────────
if ($download === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="learnguard_score_matrix_' . date('Ymd_His') . '.csv"');
    echo "\xEF\xBB\xBF";
    $fh = fopen('php://output', 'w');
    $hdr = ['First Name', 'Last Name', 'Email', 'Attempt #'];
    foreach ($byCourse as $cid => $courseData) {
        foreach ($courseData['assessments'] as $a) {
            $hdr[] = format_string($a->name) . ' [' . format_string($a->coursename) . '] Score (/' . (float)$a->maxgrade . ')';
        }
        $hdr[] = format_string($courseData['name']) . ' — Last Completed';
    }
    $hdr[] = 'Total Score';
    $hdr[] = 'Average (% of total possible)';
    $hdr[] = 'Overall Last Completed';
    $hdr[] = 'Suspension Status';
    fputcsv($fh, $hdr);

    foreach ($students as $stu) {
        $maxAtt = $maxAttemptsPerStudent[$stu->id] ?? 0;
        $susCsvLabel = ($suspendStatus[$stu->id] ?? '') === 'confirmed' ? 'Confirmed Suspended'
                     : (($suspendStatus[$stu->id] ?? '') === 'pending' ? 'Suspended — Pending Review' : '');

        // Same display-numbering as the on-screen table: voided rows keep their
        // existing id-order position, active rows get their own independent 1,2,3…
        // count among just themselves.
        $activeCounter = 0;
        $csvDisplayNumMap = [];
        for ($an = 1; $an <= max(1, $maxAtt); $an++) {
            $rowVoided = false;
            foreach (($cellMap[$stu->id] ?? []) as $lgid => $lgAtts) {
                if (!empty($lgAtts[$an]['voided'])) { $rowVoided = true; break; }
            }
            if ($rowVoided) {
                $csvDisplayNumMap[$an] = $an;
            } else {
                $activeCounter++;
                $csvDisplayNumMap[$an] = $activeCounter;
            }
        }

        for ($attNum = 1; $attNum <= max(1, $maxAtt); $attNum++) {
            $row = [$stu->firstname, $stu->lastname, $stu->email, '#' . $csvDisplayNumMap[$attNum]];
            $rowSum = 0.0; $rowCount = 0; $rowLatest = 0;
            foreach ($byCourse as $cid => $courseData) {
                $courseLatest = 0;
                foreach ($courseData['assessments'] as $a) {
                    $att = $cellMap[$stu->id][$a->id][$attNum] ?? null;
                    if ($att === null) {
                        $row[] = '';
                    } elseif (!empty($att['suspended'])) {
                        // Not counted toward totals — no final score until a proctor
                        // reinstates or confirms the suspension (see the on-screen render).
                        $row[] = 'SUSPENDED';
                        if ($att['timefinish'] > $courseLatest) { $courseLatest = $att['timefinish']; }
                        if ($att['timefinish'] > $rowLatest)   { $rowLatest    = $att['timefinish']; }
                    } elseif ($att['sumgrades'] !== null) {
                        $row[] = number_format($att['sumgrades'], 2, '.', '');
                        $rowSum += $att['sumgrades']; $rowCount++;
                        if ($att['timefinish'] > $courseLatest) { $courseLatest = $att['timefinish']; }
                        if ($att['timefinish'] > $rowLatest)   { $rowLatest    = $att['timefinish']; }
                    } else {
                        $row[] = '⏳';
                        if ($att['timefinish'] > $courseLatest) { $courseLatest = $att['timefinish']; }
                        if ($att['timefinish'] > $rowLatest)   { $rowLatest    = $att['timefinish']; }
                    }
                }
                $row[] = $courseLatest ? userdate($courseLatest, '%d %b %Y %H:%M') : '';
            }
            $row[] = $rowCount > 0 ? number_format($rowSum, 2, '.', '') : '';
            $row[] = ($rowCount > 0 && $totalMaxPossible > 0)
                ? number_format(($rowSum / $totalMaxPossible) * 100, 2, '.', '') . '%' : '';
            $row[] = $rowLatest ? userdate($rowLatest, '%d %b %Y %H:%M') : '';
            $row[] = $susCsvLabel;
            fputcsv($fh, $row);
        }
    }
    fclose($fh);
    exit;
}

// ── Page setup ────────────────────────────────────────────────────────────────
$PAGE->set_url('/mod/learnguard/scorematrix.php', array_filter([
    'filter_course' => $filterCourse ?: null,
    'sort'          => $sortKey !== 'name_asc' ? $sortKey : null,
    'date_from'     => $dateFrom ?: null,
    'date_to'       => $dateTo ?: null,
]));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('LearnGuard — Score Matrix');
$PAGE->set_heading('LearnGuard — Score Matrix');
$PAGE->requires->css('/mod/learnguard/styles.css');

echo $OUTPUT->header();
echo learnguard_nav_bar(0, '🏆 Score Matrix');
echo learnguard_banner('Score Matrix',
    'Cross-course view of every student\'s score across all LearnGuard assessments.', '🏆');

$allCoursesSql    = "SELECT DISTINCT c.id, c.fullname FROM {course} c
    JOIN {learnguard} lg ON lg.course = c.id";
$allCoursesParams = [];
if ($allowedCourseIds !== null) {
    [$acSql, $allCoursesParams] = $DB->get_in_or_equal($allowedCourseIds, SQL_PARAMS_NAMED, 'ac2');
    $allCoursesSql .= " WHERE c.id $acSql";
}
$allCoursesSql .= " ORDER BY c.fullname";
$allCourses = $DB->get_records_sql($allCoursesSql, $allCoursesParams);
$exportParams = array_filter([
    'filter_course' => $filterCourse ?: null, 'sort' => $sortKey,
    'date_from' => $dateFrom ?: null, 'date_to' => $dateTo ?: null,
]);

// Sort URL helper. Preserves the current course/date filters so clicking a column
// header to re-sort doesn't silently drop them back to defaults.
function sm_sort_url(string $key, int $filterCourse, string $dateFrom = '', string $dateTo = ''): string {
    $p = ['sort' => $key];
    if ($filterCourse > 0) { $p['filter_course'] = $filterCourse; }
    if ($dateFrom !== '')  { $p['date_from'] = $dateFrom; }
    if ($dateTo   !== '')  { $p['date_to']   = $dateTo; }
    return (new moodle_url('/mod/learnguard/scorematrix.php', $p))->out(false);
}
// Arrow indicator helper.
function sm_arrow(string $col, string $sortKey): string {
    if (strpos($sortKey, $col) !== 0) { return ' <span style="color:#adb5bd;font-size:10px;">⇅</span>'; }
    return strpos($sortKey, '_desc') !== false
        ? ' <span style="color:#0f6cbf;">▼</span>'
        : ' <span style="color:#0f6cbf;">▲</span>';
}
?>
<!-- ── Filter / Sort bar ────────────────────────────────────────────────── -->
<form method="get" class="mb-3" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;
      background:#f8f9fa;padding:12px 14px;border-radius:6px;border:1px solid #dee2e6;">
  <div>
    <label style="font-size:11px;font-weight:700;display:block;margin-bottom:3px;">Filter by Course</label>
    <select name="filter_course" class="form-control form-control-sm" style="max-width:250px;" onchange="this.form.submit()">
      <option value="0">— All Courses —</option>
      <?php foreach ($allCourses as $c): ?>
      <option value="<?php echo $c->id; ?>" <?php echo ($filterCourse == $c->id ? 'selected' : ''); ?>>
        <?php echo s(format_string($c->fullname)); ?>
      </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label style="font-size:11px;font-weight:700;display:block;margin-bottom:3px;">Sort By</label>
    <select name="sort" class="form-control form-control-sm" style="max-width:230px;" onchange="this.form.submit()">
      <option value="name_asc"    <?php echo $sortKey === 'name_asc'    ? 'selected' : ''; ?>>Name — A → Z</option>
      <option value="name_desc"   <?php echo $sortKey === 'name_desc'   ? 'selected' : ''; ?>>Name — Z → A</option>
      <option value="total_desc"  <?php echo $sortKey === 'total_desc'  ? 'selected' : ''; ?>>Total Score — Highest first</option>
      <option value="total_asc"   <?php echo $sortKey === 'total_asc'   ? 'selected' : ''; ?>>Total Score — Lowest first</option>
      <option value="avg_desc"    <?php echo $sortKey === 'avg_desc'    ? 'selected' : ''; ?>>Average — Highest first</option>
      <option value="avg_asc"     <?php echo $sortKey === 'avg_asc'     ? 'selected' : ''; ?>>Average — Lowest first</option>
      <option value="recent_desc" <?php echo $sortKey === 'recent_desc' ? 'selected' : ''; ?>>Most Recently Completed</option>
      <option value="recent_asc"  <?php echo $sortKey === 'recent_asc'  ? 'selected' : ''; ?>>Oldest First</option>
    </select>
  </div>
  <div>
    <label style="font-size:11px;font-weight:700;display:block;margin-bottom:3px;">Completed From</label>
    <input type="date" name="date_from" value="<?php echo s($dateFrom); ?>"
        class="form-control form-control-sm" style="max-width:160px;">
  </div>
  <div>
    <label style="font-size:11px;font-weight:700;display:block;margin-bottom:3px;">Completed To</label>
    <input type="date" name="date_to" value="<?php echo s($dateTo); ?>"
        class="form-control form-control-sm" style="max-width:160px;">
  </div>
  <div style="align-self:flex-end;display:flex;gap:6px;">
    <button type="submit" class="btn btn-primary btn-sm">🔍 Apply</button>
    <?php if ($filterCourse || $sortKey !== 'name_asc' || $dateFrom || $dateTo): ?>
    <a href="<?php echo (new moodle_url('/mod/learnguard/scorematrix.php'))->out(false); ?>"
       class="btn btn-outline-secondary btn-sm">✕ Reset</a>
    <?php endif; ?>
  </div>
  <div style="margin-left:auto;align-self:flex-end;">
    <?php echo html_writer::link(
        new moodle_url('/mod/learnguard/scorematrix.php', $exportParams + ['download' => 'csv']),
        '⬇ Export CSV', ['class' => 'btn btn-outline-secondary btn-sm']
    ); ?>
  </div>
</form>

<?php
if (empty($assessments)) {
    echo html_writer::div('No LearnGuard assessments found' . ($filterCourse ? ' in this course.' : ' on this site.'), 'alert alert-info');
} elseif (empty($students)) {
    echo html_writer::div('No attempts found for these assessments yet.', 'alert alert-info');
} else {

    // ── Legend ────────────────────────────────────────────────────────────
    echo '<p class="text-muted" style="font-size:11px;margin-bottom:8px;">'
        . 'Each attempt is shown on its own row tagged with its attempt number (#1, #2 …). '
        . '<strong>Last Completed</strong> = completion date for that attempt. '
        . '<strong>Total</strong> = sum of scores gotten across assessments for that same attempt row. '
        . '<strong>Average</strong> = Total ÷ total possible across ALL assessments shown (not just the ones attempted) — '
        . 'so a student who only completed some assessments is not scored as if the rest didn\'t exist. '
        . '🗑 next to a score voids it (excludes it from totals; the attempt stays visible but voided). '
        . '⏳ = essays still awaiting grading. '
        . '🔄 review pending = the learner has a Continue &amp; Review session open — the score shown is their prior ' .
          'submission and will update once they resubmit (each learner only gets one review per attempt). '
        . '<span style="color:#28a745;">▲</span>/<span style="color:#dc3545;">▼</span> = score rose/fell after a review resubmission. '
        . '🚫 suspended = awaiting a proctor decision (reinstate or confirm) — click through to review it; not counted toward totals until resolved. '
        . '<span style="color:#adb5bd;">N/A</span> = no attempt at that number yet. '
        . '<span style="color:#dc3545;">Red name</span> = confirmed suspended. '
        . '<span style="color:#ffc107;">Yellow name</span> = suspended, awaiting review.'
        . ($dateFrom || $dateTo
            ? ' <strong>Date filter active:</strong> only attempts completed '
              . ($dateFrom ? 'from ' . s($dateFrom) . ' ' : '') . ($dateTo ? 'to ' . s($dateTo) . ' ' : '')
              . 'are shown, totalled, and exported — students with no attempt in this window are omitted entirely.'
            : '')
        . '</p>';

    if ($isSiteAdmin) {
        echo '<form method="post" style="margin-bottom:14px;">';
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',  'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'smaction', 'value' => 'finishexpired']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'filter_course', 'value' => $filterCourse]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sort',          'value' => $sortKey]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'date_from',     'value' => $dateFrom]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'date_to',       'value' => $dateTo]);
        echo '<button type="submit" class="btn btn-sm btn-outline-warning" onclick="return confirm(' .
             '\'Force-finish every overdue attempt site-wide right now (window closed, time limit run out, ' .
             'or a Continue &amp; Review deadline passed)? This is normally done automatically every 5 minutes.\');">' .
             '🔄 Finish Overdue Attempts Now</button> ';
        echo '<small class="text-muted">Fixes a blank/"review pending" cell that\'s stuck past its window or review deadline.</small>';
        echo '</form>';
    }

    // ── Sort URL helpers ──────────────────────────────────────────────────
    $nameSortNext   = ($sortKey === 'name_asc')    ? 'name_desc'   : 'name_asc';
    $totalSortNext  = ($sortKey === 'total_desc')  ? 'total_asc'   : 'total_desc';
    $avgSortNext    = ($sortKey === 'avg_desc')    ? 'avg_asc'     : 'avg_desc';
    $recentSortNext = ($sortKey === 'recent_desc') ? 'recent_asc'  : 'recent_desc';

    // ── Bulk actions toolbar — tick score cells above (checkbox appears on every
    // finished/voided/essay-pending cell, not on in-progress ones), then Void or
    // Delete Selected. Void keeps the row visible (struck through, excluded from
    // totals); Delete permanently removes the attempt and everything tied to it, so
    // it disappears from this and every other report — e.g. cleaning up a burst of
    // duplicate attempts (a retry bug, a bulk-reattempt run more than once, etc.)
    // down to the one that should have existed. Site-admin only — a Score Matrix
    // viewer grant (global or course-scoped) is for viewing, not mutating/deleting
    // attempt data. ──────────────────────────────────────────────────────────────
    if ($isSiteAdmin) {
    echo '<div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;flex-wrap:wrap;">';
    echo '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="smToggleAllChk(true)">☑ Select All</button>';
    echo '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="smToggleAllChk(false)">☐ Clear</button>';
    echo '<button type="button" class="btn btn-sm btn-warning" onclick="smBulkAction(\'bulkvoid\')">🗑 Void Selected</button>';
    echo '<button type="button" class="btn btn-sm btn-danger" onclick="smBulkAction(\'bulkdelete\')">❌ Delete Selected (permanent)</button>';
    echo '<span id="sm-bulk-count" style="font-size:12px;color:#6c757d;"></span>';
    echo '<div style="border-left:1px solid #dee2e6;height:20px;margin:0 4px;"></div>';
    echo '<button type="button" class="btn btn-sm btn-outline-primary" onclick="smSendAllReminders()">' .
         '📧 Send Reminders to All (N/A)</button>';
    echo '<span id="sm-reminder-status" style="font-size:12px;color:#6c757d;"></span>';
    echo '</div>';
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => '', 'id' => 'sm-bulk-form']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'smaction', 'id' => 'sm-bulk-action', 'value' => '']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'filter_course', 'value' => $filterCourse]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sort',          'value' => $sortKey]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'date_from',     'value' => $dateFrom]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'date_to',       'value' => $dateTo]);
    echo '<div id="sm-bulk-targets"></div>';
    echo html_writer::end_tag('form');
    // Single N/A-click reminder form.
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => '', 'id' => 'sm-reminder-form', 'style' => 'display:none;']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'smaction', 'value' => 'sendreminder']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'ruserid', 'id' => 'sm-reminder-userid', 'value' => '']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'rlgid',   'id' => 'sm-reminder-lgid',   'value' => '']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'filter_course', 'value' => $filterCourse]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sort',          'value' => $sortKey]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'date_from',     'value' => $dateFrom]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'date_to',       'value' => $dateTo]);
    echo html_writer::end_tag('form');
    // Single score-cell "Grant Continue & Review" form.
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => '', 'id' => 'sm-grant-form', 'style' => 'display:none;']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'smaction', 'value' => 'grantreview']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'grattemptid', 'id' => 'sm-grant-attid', 'value' => '']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'filter_course', 'value' => $filterCourse]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sort',          'value' => $sortKey]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'date_from',     'value' => $dateFrom]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'date_to',       'value' => $dateTo]);
    echo html_writer::end_tag('form');
    }

    echo '<div style="overflow-x:auto;">';
    echo '<table class="generaltable" style="white-space:nowrap;font-size:13px;">';
    echo '<thead>';

    // ── Row 1: Student (rowspan=2) | Course groups | Summary (rowspan=2) ──
    echo '<tr>';
    echo '<th rowspan="2" style="vertical-align:bottom;padding:6px 10px;">';
    echo html_writer::link(sm_sort_url($nameSortNext, $filterCourse, $dateFrom, $dateTo),
        'Student' . sm_arrow('name', $sortKey),
        ['style' => 'color:inherit;text-decoration:none;font-weight:700;']);
    echo '</th>';
    echo '<th rowspan="2" style="vertical-align:bottom;text-align:center;padding:6px 8px;color:#6c757d;">Att</th>';

    foreach ($byCourse as $cid => $courseData) {
        // colspan = N assessments + 1 Last Completed.
        $span = count($courseData['assessments']) + 1;
        echo '<th colspan="' . $span . '" style="text-align:center;padding:5px 8px;'
            . 'background:#e9ecef;border-left:2px solid #ced4da;">';
        echo '📚 ' . s(format_string($courseData['name']));
        echo '</th>';
    }

    // Summary columns (rowspan=2).
    echo '<th rowspan="2" style="vertical-align:bottom;text-align:center;padding:6px 8px;border-left:2px solid #ced4da;">';
    echo html_writer::link(sm_sort_url($totalSortNext, $filterCourse, $dateFrom, $dateTo),
        'Total' . sm_arrow('total', $sortKey),
        ['style' => 'color:inherit;text-decoration:none;font-weight:700;']);
    echo '</th>';
    echo '<th rowspan="2" style="vertical-align:bottom;text-align:center;padding:6px 8px;">';
    echo html_writer::link(sm_sort_url($avgSortNext, $filterCourse, $dateFrom, $dateTo),
        'Average' . sm_arrow('avg', $sortKey),
        ['style' => 'color:inherit;text-decoration:none;font-weight:700;']);
    echo '</th>';
    echo '<th rowspan="2" style="vertical-align:bottom;text-align:center;padding:6px 8px;">';
    echo html_writer::link(sm_sort_url($recentSortNext, $filterCourse, $dateFrom, $dateTo),
        'Overall Last Completed' . sm_arrow('recent', $sortKey),
        ['style' => 'color:inherit;text-decoration:none;font-weight:700;']);
    echo '</th>';
    echo '</tr>';

    // ── Row 2: Per-assessment score headers + Att# + Last Completed per course ──
    echo '<tr>';
    foreach ($byCourse as $cid => $courseData) {
        foreach ($courseData['assessments'] as $a) {
            echo '<th style="text-align:center;padding:5px 8px;border-left:1px solid #dee2e6;'
                . 'font-weight:600;font-size:12px;">';
            echo html_writer::link(
                new moodle_url('/mod/learnguard/scores.php', ['id' => $a->cmid]),
                s(format_string($a->name)),
                ['style' => 'color:inherit;', 'title' => 'View scores']
            );
            echo '<br><small style="font-weight:400;color:#6c757d;">/' . (float)$a->maxgrade . '</small>';
            echo '</th>';
        }
        // No separate Att# column — attempt numbers are shown inline within each score cell.
        // One Last Completed per course.
        echo '<th style="text-align:center;padding:5px 8px;border-left:1px solid #dee2e6;'
            . 'border-right:2px solid #ced4da;color:#6c757d;font-weight:400;font-size:12px;">'
            . 'Last Completed</th>';
    }
    echo '</tr>';
    echo '</thead>';

    // ── Data rows — one row per student per attempt ───────────────────────
    echo '<tbody>';
    $prevUid = null;
    foreach ($students as $stu) {
        $maxAtt = $maxAttemptsPerStudent[$stu->id] ?? 0;
        if ($maxAtt < 1) { continue; }

        // Display numbering for the Att badge only — the underlying row/column
        // alignment below is untouched. A voided row keeps showing whatever number
        // it already had (its id-order position); active (non-voided) rows get
        // their own independent 1,2,3… count among just themselves, so a student's
        // only real remaining attempt reads as #1 even if an earlier one was voided.
        $activeCounter = 0;
        $displayNumMap = [];
        for ($an = 1; $an <= $maxAtt; $an++) {
            $rowVoided = false;
            foreach (($cellMap[$stu->id] ?? []) as $lgid => $lgAtts) {
                if (!empty($lgAtts[$an]['voided'])) { $rowVoided = true; break; }
            }
            if ($rowVoided) {
                $displayNumMap[$an] = $an;
            } else {
                $activeCounter++;
                $displayNumMap[$an] = $activeCounter;
            }
        }

        for ($attNum = 1; $attNum <= $maxAtt; $attNum++) {
            $isFirst   = ($attNum === 1);
            $sepStyle  = (!$isFirst || $prevUid !== null) && $isFirst
                       ? 'border-top:2px solid #dee2e6;' : '';

            echo '<tr>';

            // Student name cell — full on first attempt row, muted repeat on subsequent.
            // Coloured by suspension status: red = confirmed, yellow = suspended but not
            // yet reviewed. Applied to both the bold first row and the muted repeats so
            // the signal is visible no matter which of a student's rows you're looking at.
            $susNameColour = ($suspendStatus[$stu->id] ?? '') === 'confirmed' ? '#dc3545'
                            : (($suspendStatus[$stu->id] ?? '') === 'pending' ? '#ffc107' : '');
            echo '<td style="padding:6px 10px;white-space:nowrap;' . $sepStyle . '">';
            if ($isFirst) {
                echo '<strong' . ($susNameColour ? ' style="color:' . $susNameColour . ';"' : '') . '>'
                   . s(fullname($stu)) . '</strong><br>';
                echo '<small class="text-muted">' . s($stu->email) . '</small>';
            } else {
                echo '<span style="color:' . ($susNameColour ?: '#adb5bd') . ';font-size:11px;">'
                   . s(fullname($stu)) . '<br>' . s($stu->email) . '</span>';
            }
            echo '</td>';

            // Attempt badge — grey + strikethrough for voided rows.
            $attData    = $cellMap[$stu->id] ?? [];
            $isVoidedRow = false;
            foreach ($attData as $lgid => $lgAtts) {
                if (!empty($lgAtts[$attNum]['voided'])) { $isVoidedRow = true; break; }
            }
            $badgeBg  = $isVoidedRow ? '#adb5bd' : '#0f6cbf';
            $badgeTxt = $isVoidedRow ? 'text-decoration:line-through;' : '';
            echo '<td style="text-align:center;padding:6px 8px;' . $sepStyle . '">';
            echo '<span style="background:' . $badgeBg . ';color:#fff;padding:2px 8px;'
               . 'border-radius:10px;font-size:12px;font-weight:700;' . $badgeTxt . '">#' . $displayNumMap[$attNum] . '</span>';
            echo '</td>';

            // Per-course data.
            $rowSum = 0.0; $rowCount = 0; $rowLatest = 0;
            foreach ($byCourse as $cid => $courseData) {
                $courseLatest = 0;

                foreach ($courseData['assessments'] as $a) {
                    $att = $cellMap[$stu->id][$a->id][$attNum] ?? null;
                    $reportUrl = new moodle_url('/mod/learnguard/grader_user_report.php',
                        ['id' => $a->cmid, 'userid' => $stu->id]);

                    echo '<td style="text-align:center;padding:4px 8px;'
                       . 'border-left:1px solid #f0f0f0;vertical-align:middle;' . $sepStyle . '">';
                    if ($att === null) {
                        // No attempt at all for this student on this assessment — doesn't
                        // affect totals/averages (this cell was never counted toward
                        // $rowSum/$rowCount either way) and the CSV export keeps this cell
                        // blank, not "N/A" (see the CSV loop below, unaffected by this).
                        // Clickable only on the first attempt row (attNum 1) — a student's
                        // "no attempt yet" is a per-assessment fact, not per-attempt-number,
                        // so showing a reminder action on every repeated N/A row would be
                        // redundant/confusing. Site-admin only, same as every other mutating
                        // action on this page.
                        if ($isSiteAdmin && $isFirst) {
                            echo '<button type="button" class="sm-na-btn" data-userid="' . (int)$stu->id .
                                '" data-lgid="' . (int)$a->id . '" ' .
                                'style="background:none;border:none;padding:0;color:#adb5bd;font-size:10px;' .
                                'text-decoration:underline dotted;cursor:pointer;" ' .
                                'title="Click to send this student a reminder that this assessment is yet to be attempted">N/A</button>';
                        } else {
                            echo '<span class="text-muted" style="font-size:10px;" title="No attempt on this assessment">N/A</span>';
                        }
                    } elseif (!empty($att['suspended'])) {
                        // Suspended attempts are deliberately never auto-resolved (unlike an
                        // overdue window/time-limit) — whether to reinstate or confirm the
                        // suspension is a proctor judgment call, not something to silently
                        // decide here. Surfacing it clearly (instead of the old behaviour —
                        // simply excluded from the query, indistinguishable from "no
                        // attempt at all") is what actually needed fixing: a suspended
                        // attempt sitting unresolved for days looked identical to a student
                        // who never attempted anything.
                        echo '<a href="' . $reportUrl . '" style="color:#dc3545;text-decoration:none;font-size:11px;" ' .
                             'title="Suspended — needs a proctor decision (reinstate or confirm) before this can show a final score.">' .
                             '🚫 suspended</a>';
                        if ($att['timefinish'] > $courseLatest) { $courseLatest = $att['timefinish']; }
                        if ($att['timefinish'] > $rowLatest)   { $rowLatest    = $att['timefinish']; }
                        // Deliberately not counted toward totals — no final score yet.
                    } elseif (!empty($att['inprogress'])) {
                        // In-progress attempt: show partial score from answered questions.
                        if ($att['sumgrades'] !== null) {
                            echo '<span style="color:#fd7e14;font-size:11px;" title="In progress (partial score)">'
                               . number_format($att['sumgrades'], 2) . '</span>'
                               . '<br><span style="font-size:10px;color:#fd7e14;">in progress</span>';
                        } else {
                            echo '<span style="color:#fd7e14;font-size:11px;" title="In progress — no answers yet">🖊</span>';
                        }
                        // Do not count in-progress towards row average (not final grade).
                    } elseif (!empty($att['voided'])) {
                        // Voided attempt: show score struck-through; still counted in totals.
                        if ($isSiteAdmin) {
                            echo '<input type="checkbox" class="sm-bulk-chk" data-attid="' . (int)$att['id']
                               . '" style="margin-right:4px;vertical-align:middle;">';
                        }
                        $vScore = $att['sumgrades'] !== null
                            ? '<span style="text-decoration:line-through;color:#adb5bd;font-size:11px;">'
                              . number_format($att['sumgrades'], 2) . '</span>'
                            : '<span style="color:#adb5bd;font-size:11px;">—</span>';
                        echo $vScore . '<br><span style="font-size:10px;color:#adb5bd;">voided</span>';
                        if ($att['sumgrades'] !== null) { $rowSum += $att['sumgrades']; $rowCount++; }
                        if ($att['timefinish'] > $courseLatest) { $courseLatest = $att['timefinish']; }
                        if ($att['timefinish'] > $rowLatest)   { $rowLatest    = $att['timefinish']; }
                    } elseif ($att['sumgrades'] !== null && !empty($att['reviewpending'])) {
                        // Continue & Review granted but not yet resubmitted — show the
                        // pre-review score (kept visible instead of hidden behind "in
                        // progress") with a badge, not a live/void-able score: state is
                        // still 'inprogress' so voiding here would silently no-op anyway.
                        echo '<a href="' . $reportUrl . '" style="color:#0f6cbf;text-decoration:none;">'
                           . '<strong>' . number_format($att['sumgrades'], 2) . '</strong></a>'
                           . '<br><span style="font-size:10px;color:#0f6cbf;" ' .
                           'title="Learner has a Continue & Review session open — this is their prior score, shown until they resubmit.">🔄 review pending</span>';
                        // Prior score still counts toward totals — nothing about it changed yet.
                        $rowSum += $att['sumgrades']; $rowCount++;
                        if ($att['timefinish'] > $courseLatest) { $courseLatest = $att['timefinish']; }
                        if ($att['timefinish'] > $rowLatest)   { $rowLatest    = $att['timefinish']; }
                    } elseif ($att['sumgrades'] !== null) {
                        if ($isSiteAdmin) {
                            echo '<input type="checkbox" class="sm-bulk-chk" data-attid="' . (int)$att['id']
                               . '" style="margin-right:4px;vertical-align:middle;">';
                        }
                        if ($isSiteAdmin) {
                            // Score itself opens a tiny menu (View Report / Grant Continue &
                            // Review) instead of navigating straight to the report — same
                            // pattern for every scored cell, not just 0 or otherwise "notable"
                            // scores, since a proctor may want to grant a review from any of
                            // them.
                            echo '<span class="sm-score-wrap" style="position:relative;display:inline-block;">';
                            echo '<button type="button" class="sm-score-btn" onclick="smToggleScoreMenu(this)" ' .
                                 'data-reporturl="' . s($reportUrl->out(false)) . '" data-attid="' . (int)$att['id'] . '" ' .
                                 'style="background:none;border:none;padding:0;color:#0f6cbf;cursor:pointer;">' .
                                 '<strong>' . number_format($att['sumgrades'], 2) . '</strong></button>';
                            echo '<div class="sm-score-menu" style="display:none;position:absolute;top:100%;left:0;' .
                                 'z-index:50;background:#fff;border:1px solid #dee2e6;border-radius:6px;' .
                                 'box-shadow:0 4px 12px rgba(0,0,0,.15);white-space:nowrap;font-weight:400;">' .
                                 '<a class="sm-score-menu-item" href="' . $reportUrl . '" ' .
                                 'style="display:block;padding:6px 12px;color:#212529;text-decoration:none;font-size:12px;">📄 View Report</a>' .
                                 '<a class="sm-score-menu-item" href="#" onclick="smGrantReview(' . (int)$att['id'] . ');return false;" ' .
                                 'style="display:block;padding:6px 12px;color:#0f6cbf;text-decoration:none;font-size:12px;border-top:1px solid #f0f0f0;">🔄 Grant Continue &amp; Review</a>' .
                                 '</div>';
                            echo '</span>';
                        } else {
                            echo '<a href="' . $reportUrl . '" style="color:#0f6cbf;text-decoration:none;">'
                               . '<strong>' . number_format($att['sumgrades'], 2) . '</strong></a>';
                        }
                        // Score changed after a review resubmission — green up / red down,
                        // comparing the new score against the pre-review snapshot.
                        if ($att['prev_sumgrades'] !== null) {
                            if ($att['sumgrades'] > $att['prev_sumgrades']) {
                                echo ' <span title="Up from ' . number_format($att['prev_sumgrades'], 2) . '" style="color:#28a745;font-weight:700;">▲</span>';
                            } elseif ($att['sumgrades'] < $att['prev_sumgrades']) {
                                echo ' <span title="Down from ' . number_format($att['prev_sumgrades'], 2) . '" style="color:#dc3545;font-weight:700;">▼</span>';
                            }
                        }
                        // Void this attempt's score directly from the matrix — same effect as
                        // grader.php's Void action (state -> voided; history kept, not deleted).
                        // Site-admin only — see the bulk-toolbar comment above.
                        if ($isSiteAdmin) {
                        echo '<form method="post" style="display:inline;" onsubmit="return confirm(' .
                             '\'Void this score? It will no longer count toward totals/averages. ' .
                             'The attempt stays visible as voided and the student can be granted a reattempt.\');">';
                        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',  'value' => sesskey()]);
                        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'smaction', 'value' => 'voidattempt']);
                        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'vaid',     'value' => $att['id']]);
                        // Preserve the current filter/sort/date-range so voiding doesn't
                        // silently reset the view back to defaults on redirect.
                        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'filter_course', 'value' => $filterCourse]);
                        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sort',          'value' => $sortKey]);
                        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'date_from',     'value' => $dateFrom]);
                        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'date_to',       'value' => $dateTo]);
                        echo '<button type="submit" title="Void this score" ' .
                             'style="background:none;border:none;color:#dc3545;font-size:11px;cursor:pointer;padding:0;margin-left:4px;">🗑</button>';
                        echo '</form>';
                        }
                        $rowSum += $att['sumgrades']; $rowCount++;
                        if ($att['timefinish'] > $courseLatest) { $courseLatest = $att['timefinish']; }
                        if ($att['timefinish'] > $rowLatest)   { $rowLatest    = $att['timefinish']; }
                    } else {
                        if ($isSiteAdmin) {
                            echo '<input type="checkbox" class="sm-bulk-chk" data-attid="' . (int)$att['id']
                               . '" style="margin-right:4px;vertical-align:middle;">';
                        }
                        echo '<span class="text-warning" title="Essays pending grading">⏳</span>';
                        if ($att['timefinish'] > $courseLatest) { $courseLatest = $att['timefinish']; }
                        if ($att['timefinish'] > $rowLatest)   { $rowLatest    = $att['timefinish']; }
                    }
                    echo '</td>';
                }

                // Last Completed cell (one per course, for this attempt row).
                echo '<td style="text-align:center;padding:6px 8px;border-left:1px solid #f0f0f0;'
                    . 'border-right:2px solid #ced4da;' . $sepStyle . '">';
                if ($courseLatest > 0) {
                    echo '<span style="font-size:11px;">' . userdate($courseLatest, '%d %b %Y') . '</span><br>';
                    echo '<small class="text-muted">' . userdate($courseLatest, '%H:%M') . '</small>';
                } else {
                    echo '<span class="text-muted">—</span>';
                }
                echo '</td>';
            }

            // Total for this attempt row (struck-through for voided rows).
            $totalStyle = $isVoidedRow ? 'text-decoration:line-through;color:#adb5bd;' : '';
            echo '<td style="text-align:center;padding:6px 8px;border-left:2px solid #ced4da;' . $sepStyle . '">';
            echo $rowCount > 0
                ? '<span style="' . $totalStyle . '"><strong>' . number_format($rowSum, 2) . '</strong></span>'
                : '<span class="text-muted">—</span>';
            echo '</td>';

            // Average for this attempt row (struck-through for voided rows). Percentage of
            // the total possible across ALL assessments in the matrix — not just the ones
            // this student has a score for — so a partially-completed row correctly reads
            // lower than a fully-completed one instead of matching it.
            echo '<td style="text-align:center;padding:6px 8px;' . $sepStyle . '">';
            echo ($rowCount > 0 && $totalMaxPossible > 0)
                ? '<span style="' . $totalStyle . '"><strong>' . number_format(($rowSum / $totalMaxPossible) * 100, 2) . '%</strong></span>'
                : '<span class="text-muted">—</span>';
            echo '</td>';

            // Overall Last Completed for this attempt row.
            echo '<td style="text-align:center;padding:6px 8px;white-space:nowrap;' . $sepStyle . '">';
            if ($rowLatest > 0) {
                echo '<span style="font-size:11px;">' . userdate($rowLatest, '%d %b %Y') . '</span><br>';
                echo '<small class="text-muted">' . userdate($rowLatest, '%H:%M') . '</small>';
            } else {
                echo '<span class="text-muted">—</span>';
            }
            echo '</td>';

            echo '</tr>';
        }
        $prevUid = $stu->id;
    }
    echo '</tbody>';
    echo '</table>';
    echo '</div>';

    $sortLabels = [
        'name_asc' => 'Name (A→Z)', 'name_desc' => 'Name (Z→A)',
        'total_desc' => 'Total ↓', 'total_asc' => 'Total ↑',
        'avg_desc' => 'Average ↓', 'avg_asc' => 'Average ↑',
        'recent_desc' => 'Most Recent', 'recent_asc' => 'Oldest First',
    ];
    echo '<p class="text-muted mt-2" style="font-size:11px;">'
        . count($students) . ' student(s) &nbsp;·&nbsp; '
        . count($assessments) . ' assessment(s) across ' . count($byCourse) . ' course(s)'
        . ' &nbsp;·&nbsp; Sorted by: <strong>' . s($sortLabels[$sortKey] ?? $sortKey) . '</strong></p>';
    ?>
<script>
function smUpdateBulkCount() {
    var n = document.querySelectorAll('.sm-bulk-chk:checked').length;
    var el = document.getElementById('sm-bulk-count');
    if (el) { el.textContent = n ? (n + ' selected') : ''; }
}
document.addEventListener('change', function(e) {
    if (e.target && e.target.classList && e.target.classList.contains('sm-bulk-chk')) {
        smUpdateBulkCount();
    }
});
function smToggleAllChk(state) {
    document.querySelectorAll('.sm-bulk-chk').forEach(function(c) { c.checked = state; });
    smUpdateBulkCount();
}
function smBulkAction(action) {
    var checked = document.querySelectorAll('.sm-bulk-chk:checked');
    if (!checked.length) { alert('Tick at least one score cell first.'); return; }
    var msg = action === 'bulkdelete'
        ? 'PERMANENTLY delete ' + checked.length + ' attempt(s)? This removes them (and all their data — ' +
          'responses, evidence, everything) completely and cannot be undone. Voided attempts stay visible for ' +
          'history; deleted ones do not.'
        : 'Void ' + checked.length + ' attempt(s)? They will no longer count toward totals/averages, but stay ' +
          'visible (struck through) and the students can be granted a reattempt.';
    if (!confirm(msg)) { return; }
    var targets = document.getElementById('sm-bulk-targets');
    targets.innerHTML = '';
    checked.forEach(function(c) {
        var inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = 'bulk_attid[]'; inp.value = c.getAttribute('data-attid');
        targets.appendChild(inp);
    });
    document.getElementById('sm-bulk-action').value = action;
    document.getElementById('sm-bulk-form').submit();
}

// ── Score cell menu (View Report / Grant Continue & Review) ──────────────────
document.addEventListener('click', function(e) {
    // Close any open score menu when clicking outside one.
    document.querySelectorAll('.sm-score-menu').forEach(function(m) {
        if (m.style.display === 'block' && !m.parentElement.contains(e.target)) {
            m.style.display = 'none';
        }
    });
});
function smToggleScoreMenu(btn) {
    var menu = btn.parentElement.querySelector('.sm-score-menu');
    if (!menu) { return; }
    var isOpen = menu.style.display === 'block';
    document.querySelectorAll('.sm-score-menu').forEach(function(m) { m.style.display = 'none'; });
    menu.style.display = isOpen ? 'none' : 'block';
}
function smGrantReview(attemptid) {
    if (!confirm('Grant Continue & Review for this attempt? The student will be emailed and told the review must be completed within the assessment window.')) { return; }
    document.getElementById('sm-grant-attid').value = attemptid;
    document.getElementById('sm-grant-form').submit();
}

// ── N/A cell → single reminder ────────────────────────────────────────────────
document.addEventListener('click', function(e) {
    if (e.target && e.target.classList && e.target.classList.contains('sm-na-btn')) {
        if (!confirm('Send this student a reminder that this assessment is yet to be attempted?')) { return; }
        document.getElementById('sm-reminder-userid').value = e.target.getAttribute('data-userid');
        document.getElementById('sm-reminder-lgid').value   = e.target.getAttribute('data-lgid');
        document.getElementById('sm-reminder-form').submit();
    }
});

// ── "Send Reminders to All (N/A)" — batches of 5 to avoid overloading the
// server, same reasoning as every other batched action added today. ──────────
function smSendAllReminders() {
    var pairs = [];
    document.querySelectorAll('.sm-na-btn').forEach(function(btn) {
        pairs.push(btn.getAttribute('data-userid') + ':' + btn.getAttribute('data-lgid'));
    });
    if (!pairs.length) { alert('No "N/A" cells on this page — nothing to remind.'); return; }
    if (!confirm('Send a reminder email to all ' + pairs.length + ' student(s) currently showing "N/A" on this page?')) { return; }

    var statusEl = document.getElementById('sm-reminder-status');
    var batchSize = 5;
    var sentTotal = 0;
    var i = 0;
    function nextBatch() {
        if (i >= pairs.length) {
            statusEl.textContent = 'Done — ' + sentTotal + ' of ' + pairs.length + ' reminder(s) sent. See Mail History for details.';
            return;
        }
        var batch = pairs.slice(i, i + batchSize);
        i += batchSize;
        statusEl.textContent = 'Sending ' + Math.min(i, pairs.length) + ' of ' + pairs.length + '…';
        var body = new URLSearchParams();
        body.set('sesskey', document.getElementById('sm-bulk-form').sesskey.value);
        body.set('smaction', 'sendreminderbatch');
        batch.forEach(function(p) { body.append('pairs[]', p); });
        fetch(window.location.pathname, {method: 'POST', body: body})
            .then(function(r) { return r.json(); })
            .then(function(data) { sentTotal += (data && data.sent) || 0; nextBatch(); })
            .catch(function() { nextBatch(); }); // one failed batch shouldn't stop the rest
    }
    nextBatch();
}
</script>
    <?php
}

echo $OUTPUT->footer();
