<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * LearnGuard Essay Grading Interface — edwiser-style layout.
 *
 * Supports first & second graders with optional blind/anonymous marking.
 * URL: /mod/learnguard/grader.php?id=CMID[&attemptid=N[&q=N]]
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id        = required_param('id',        PARAM_INT);
$attemptid = optional_param('attemptid', 0,  PARAM_INT);
$qnum      = optional_param('q',         1,  PARAM_INT); // 1-based question index in grading view
$action    = optional_param('action',    '', PARAM_ALPHA);
$tab       = optional_param('tab',       'ungraded', PARAM_ALPHA);

$cm      = get_coursemodule_from_id('learnguard', $id, 0, false, MUST_EXIST);
$course  = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$lg      = $DB->get_record('learnguard', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);

$isManager = has_capability('mod/learnguard:manage', $context);
$myGrader  = $DB->get_record('learnguard_graders', ['learnguard' => $cm->instance, 'userid' => $USER->id]);
$isGrader  = $isManager || !empty($myGrader);

if (!$isGrader) {
    throw new moodle_exception('accessdenied', 'error', '', 'You are not assigned as a grader for this assessment.');
}

$anonymous  = !empty($myGrader->anonymous) && !$isManager;
$gradeLayer = $myGrader ? (int)$myGrader->gradelayer : 1;

$PAGE->set_url('/mod/learnguard/grader.php', ['id' => $id, 'attemptid' => $attemptid, 'q' => $qnum]);
$PAGE->set_context($context);
$PAGE->set_title(format_string($lg->name) . ' — Grader');
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->css('/mod/learnguard/styles.css');

$brand = learnguard_brand();

// ── Load essay slots ─────────────────────────────────────────────────────────
$essaySlots = $DB->get_records_sql(
    "SELECT s.id AS slotid, s.slot, q.questiontext, q.markingguidance, s.maxmark
       FROM {learnguard_slots} s
       JOIN {learnguard_qbank} q ON q.id = s.questionid
      WHERE s.learnguard = :lgid AND q.qtype = 'essay'
      ORDER BY s.slot",
    ['lgid' => $cm->instance]
);
$essaySlots  = array_values($essaySlots); // re-index 0-based
$totalSlots  = count($essaySlots);

// ── Handle: void attempt (grant reattempt) ───────────────────────────────────
if ($action === 'void_attempt' && $isManager && confirm_sesskey()) {
    $vaid = required_param('vaid', PARAM_INT);
    $vattempt = $DB->get_record('learnguard_attempts', ['id' => $vaid, 'learnguard' => $cm->instance]);
    if ($vattempt && in_array($vattempt->state, ['finished', 'suspended'])) {
        $DB->set_field('learnguard_attempts', 'state', 'voided', ['id' => $vaid]);
    }
    redirect(new moodle_url('/mod/learnguard/grader.php', ['id' => $id]),
        '✅ Attempt voided — student can now retake the assessment.', null,
        \core\output\notification::NOTIFY_SUCCESS);
}

// ── Handle: grade single slot ────────────────────────────────────────────────
if ($action === 'grade_slot' && $attemptid && confirm_sesskey()) {
    $gradeslotid = required_param('slotid', PARAM_INT);
    $gradeVal    = required_param('grade',  PARAM_FLOAT);
    $feedback    = optional_param('feedback', '', PARAM_RAW);

    $existing = $DB->get_record('learnguard_essay_grades', [
        'attemptid' => $attemptid, 'slotid' => $gradeslotid, 'graderid' => $USER->id,
    ]);
    if ($existing) {
        $DB->update_record('learnguard_essay_grades', (object)[
            'id' => $existing->id, 'grade' => $gradeVal, 'feedback' => $feedback, 'timegraded' => time(),
        ]);
    } else {
        $DB->insert_record('learnguard_essay_grades', (object)[
            'attemptid' => $attemptid, 'slotid' => $gradeslotid, 'graderid' => $USER->id,
            'grade' => $gradeVal, 'feedback' => $feedback, 'timegraded' => time(),
        ]);
    }
    // Feed fraction into response record.
    $slotrow  = $DB->get_record('learnguard_slots', ['id' => $gradeslotid]);
    $maxmark  = $slotrow ? (float)$slotrow->maxmark : 0.0;
    $fraction = ($maxmark > 0) ? min(1.0, max(0.0, $gradeVal / $maxmark)) : 0.0;
    $DB->set_field('learnguard_responses', 'fraction', $fraction,
        ['attemptid' => $attemptid, 'slotid' => $gradeslotid]);
    $DB->set_field('learnguard_responses', 'state', 'complete',
        ['attemptid' => $attemptid, 'slotid' => $gradeslotid]);
    $DB->set_field('learnguard_responses', 'timemodified', time(),
        ['attemptid' => $attemptid, 'slotid' => $gradeslotid]);

    // Recalculate total grade.
    $attempt = $DB->get_record('learnguard_attempts', ['id' => $attemptid]);
    if ($attempt && $attempt->state === 'finished') {
        require_once($CFG->libdir . '/gradelib.php');
        $newgrade = \mod_learnguard\question_engine::calculate_total_grade($attemptid, $lg);
        $DB->set_field('learnguard_attempts', 'sumgrades', $newgrade, ['id' => $attemptid]);
        grade_update('mod/learnguard', $lg->course, 'mod', 'learnguard',
            $lg->id, 0, [(object)['userid' => $attempt->userid, 'rawgrade' => $newgrade]]);
    }
    // Stay on same question or advance to next.
    $nextQ = ($qnum < $totalSlots) ? $qnum + 1 : $qnum;
    redirect(new moodle_url('/mod/learnguard/grader.php',
        ['id' => $id, 'attemptid' => $attemptid, 'q' => $nextQ]),
        '✅ Grade saved.', null, \core\output\notification::NOTIFY_SUCCESS);
}

// ── Grading distribution ─────────────────────────────────────────────────────
$gconfig = null;
try { $gconfig = $DB->get_record('learnguard_grading_config', ['learnguard' => $cm->instance]); }
catch (Exception $e) { /* table not yet created */ }
$gradingMode = $gconfig->gradingmode ?? 'all';
$distributed = ($gradingMode !== 'all' && $gradeLayer == 1 && !$isManager);

// ── Load all finished attempts ───────────────────────────────────────────────
$sql = "SELECT a.id, a.userid, a.attempt, a.state, a.timestart, a.timefinish, a.sumgrades,
               a.risk_score, a.risk_level,
               u.firstname, u.lastname, u.email,
               COALESCE(myeg.my_graded, 0) AS my_graded
          FROM {learnguard_attempts} a
          JOIN {user} u ON u.id = a.userid
          LEFT JOIN (SELECT attemptid, COUNT(DISTINCT slotid) AS my_graded
                       FROM {learnguard_essay_grades}
                      WHERE graderid = :gid
                   GROUP BY attemptid) myeg ON myeg.attemptid = a.id";
$sqlparams = ['lgid' => $cm->instance, 'gid' => $USER->id];
if ($distributed) {
    $sql .= " JOIN {learnguard_grader_assignments} ga
                ON ga.attemptid = a.id AND ga.learnguard = :lgid2 AND ga.graderid = :myid";
    $sqlparams['lgid2'] = $cm->instance;
    $sqlparams['myid']  = $USER->id;
}
$sql .= " WHERE a.learnguard = :lgid AND a.state = 'finished' ORDER BY a.timefinish DESC";
$attempts = $DB->get_records_sql($sql, $sqlparams);

// Partition into ungraded / graded lists.
$ungradedAttempts = [];
$gradedAttempts   = [];
foreach ($attempts as $att) {
    if ((int)$att->my_graded >= $totalSlots && $totalSlots > 0) {
        $gradedAttempts[$att->id] = $att;
    } else {
        $ungradedAttempts[$att->id] = $att;
    }
}

// Distribution check: grader can't open an attempt not assigned to them.
if ($attemptid && $distributed) {
    $assignedToMe = $DB->record_exists('learnguard_grader_assignments',
        ['learnguard' => $cm->instance, 'attemptid' => $attemptid, 'graderid' => $USER->id]);
    if (!$assignedToMe) {
        redirect(new moodle_url('/mod/learnguard/grader.php', ['id' => $id]),
            '⚠️ This attempt is not assigned to you for grading.', null,
            \core\output\notification::NOTIFY_WARNING);
    }
}

// ── Grading-view data ────────────────────────────────────────────────────────
$curAttempt  = null;
$curStudent  = null;
$responses   = [];
$gradesBySlot = [];
$currentSlot = null;

if ($attemptid && !empty($essaySlots)) {
    $curAttempt = $DB->get_record('learnguard_attempts', ['id' => $attemptid], '*', MUST_EXIST);
    $curStudent = $DB->get_record('user', ['id' => $curAttempt->userid], '*', MUST_EXIST);
    $responses  = $DB->get_records('learnguard_responses',
        ['attemptid' => $attemptid], 'slotid ASC', 'slotid, responsedata, fraction');

    $allGrades = $DB->get_records_sql(
        "SELECT eg.*, u.firstname, u.lastname, gr.gradelayer, gr.anonymous
           FROM {learnguard_essay_grades} eg
           JOIN {user} u ON u.id = eg.graderid
           LEFT JOIN {learnguard_graders} gr ON gr.userid = eg.graderid AND gr.learnguard = :lgid
          WHERE eg.attemptid = :aid",
        ['lgid' => $cm->instance, 'aid' => $attemptid]
    );
    foreach ($allGrades as $g) { $gradesBySlot[$g->slotid][] = $g; }

    // Clamp q param.
    $qnum       = max(1, min($qnum, $totalSlots));
    $currentSlot = $essaySlots[$qnum - 1];
}

// ── Per-attempt slot-status map (for list view badges) ───────────────────────
// Fetch all responses + grades for this assessment in one batch.
$slotIds = array_column($essaySlots, 'slotid');
$attemptList = array_merge(array_keys($ungradedAttempts), array_keys($gradedAttempts));
$slotStatusMap = []; // [attemptid][slotid] => 'rg'|'graded'|'na'
if (!empty($attemptList) && !empty($slotIds)) {
    list($aidSql, $aidParams) = $DB->get_in_or_equal($attemptList, SQL_PARAMS_NAMED, 'aid');
    list($sidSql, $sidParams) = $DB->get_in_or_equal($slotIds,     SQL_PARAMS_NAMED, 'sid');

    $respRows = $DB->get_records_sql(
        "SELECT attemptid, slotid, responsedata FROM {learnguard_responses}
          WHERE attemptid $aidSql AND slotid $sidSql",
        array_merge($aidParams, $sidParams)
    );
    $gradeRows = $DB->get_records_sql(
        "SELECT attemptid, slotid FROM {learnguard_essay_grades}
          WHERE graderid = :uid AND attemptid $aidSql AND slotid $sidSql",
        array_merge(['uid' => $USER->id], $aidParams, $sidParams)
    );
    $gradedSet = [];
    foreach ($gradeRows as $gr) { $gradedSet[$gr->attemptid . '_' . $gr->slotid] = true; }
    $respSet   = [];
    foreach ($respRows as $rr) {
        $decoded = json_decode($rr->responsedata, true) ?? [];
        $hasAnswer = false;
        foreach ($decoded as $v) { if (trim((string)$v) !== '') { $hasAnswer = true; break; } }
        $respSet[$rr->attemptid . '_' . $rr->slotid] = $hasAnswer;
    }

    foreach ($attemptList as $aid) {
        foreach ($slotIds as $sid) {
            $key = $aid . '_' . $sid;
            if (!empty($gradedSet[$key])) {
                $slotStatusMap[$aid][$sid] = 'graded';
            } elseif (!empty($respSet[$key])) {
                $slotStatusMap[$aid][$sid] = 'rg';
            } else {
                $slotStatusMap[$aid][$sid] = 'na';
            }
        }
    }
}

$rcolours = ['low' => '#28a745', 'medium' => '#fd7e14', 'high' => '#dc3545'];

echo $OUTPUT->header();
echo learnguard_activity_tabs($id, 'grader');
?>
<!DOCTYPE html>
<style>
/* ── edwiser-style grader ── */
.lgr-wrap   { font-family:"Segoe UI",system-ui,sans-serif; max-width:1200px; margin:0 auto; padding:0 0 40px; }

/* Header */
.lgr-head   { background:<?php echo $brand->primary; ?>; color:<?php echo $brand->textcolor; ?>;
              padding:12px 20px; display:flex; align-items:center; justify-content:space-between;
              flex-wrap:wrap; gap:8px; }
.lgr-head h2 { margin:0; font-size:18px; font-weight:700; }
.lgr-head .lgr-sub { font-size:12px; opacity:.8; }

/* Stats bar */
.lgr-stats  { background:#fff; border:1px solid #dee2e6; border-radius:0 0 0 0;
              padding:10px 20px; font-size:13px; color:#495057;
              display:flex; gap:20px; flex-wrap:wrap; align-items:center; }
.lgr-stats span { display:flex; align-items:center; gap:5px; }

/* Tabs */
.lgr-tabs   { display:flex; gap:0; border-bottom:2px solid #dee2e6; margin:0; padding:0 20px;
              background:#fff; }
.lgr-tab    { padding:10px 20px; font-size:14px; font-weight:600; cursor:pointer; border:none;
              background:none; color:#6c757d; border-bottom:3px solid transparent; margin-bottom:-2px; }
.lgr-tab.active { color:#0f6cbf; border-bottom-color:#0f6cbf; }

/* Filters row */
.lgr-filters { display:flex; align-items:center; justify-content:space-between;
               padding:10px 20px; background:#fff; border-bottom:1px solid #f0f0f0;
               flex-wrap:wrap; gap:8px; }
.lgr-sort   { display:flex; align-items:center; gap:8px; font-size:13px; color:#495057; }

/* Badge legend */
.lgr-legend { display:flex; gap:12px; align-items:center; font-size:12px; color:#495057; }
.lgr-leg-rg  { background:#0f6cbf; color:#fff; padding:2px 8px; border-radius:4px; font-weight:700; }
.lgr-leg-na  { background:#dc3545; color:#fff; padding:2px 8px; border-radius:4px; font-weight:700; }
.lgr-leg-ok  { background:#28a745; color:#fff; padding:2px 8px; border-radius:4px; font-weight:700; }

/* Attempt table */
.lgr-table  { width:100%; border-collapse:collapse; font-size:13px; }
.lgr-table th { background:#f8f9fa; border-bottom:2px solid #dee2e6; padding:10px 12px;
                font-weight:700; color:#495057; white-space:nowrap; }
.lgr-table td { border-bottom:1px solid #f0f0f0; padding:10px 12px; vertical-align:middle; }
.lgr-table tr:hover td { background:#f8fbff; }
.lgr-avatar { width:36px; height:36px; border-radius:50%; background:#0f6cbf;
              color:#fff; font-weight:700; font-size:14px; display:flex;
              align-items:center; justify-content:center; flex-shrink:0; }
.lgr-name   { font-weight:600; color:#212529; }
.lgr-email  { font-size:11px; color:#6c757d; }
.lgr-qbadge { display:inline-block; padding:2px 7px; border-radius:4px; font-size:11px;
              font-weight:700; color:#fff; margin:1px; cursor:pointer; text-decoration:none; }
.lgr-qbadge.rg { background:#0f6cbf; }
.lgr-qbadge.na { background:#dc3545; }
.lgr-qbadge.ok { background:#28a745; }

/* Grading view */
.lgr-qnav   { background:#212529; color:#fff; padding:10px 16px;
              display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.lgr-qnav-btn { background:#495057; color:#fff; border:none; border-radius:5px;
                padding:6px 12px; font-size:13px; font-weight:700; cursor:pointer; }
.lgr-qnav-btn.active { background:#0f6cbf; }
.lgr-qnav-btn:hover  { background:#636c72; }
.lgr-qnav-btn.active:hover { background:#0a52a2; }
.lgr-qstatus { background:#fff3cd; border-bottom:1px solid #ffc107; padding:8px 20px;
               font-size:12px; color:#856404; display:flex; gap:20px; flex-wrap:wrap; }
.lgr-qstatus span { font-weight:700; }

.lgr-split  { display:grid; grid-template-columns:1fr 360px; min-height:calc(100vh - 200px); }
.lgr-left   { border-right:1px solid #dee2e6; overflow-y:auto; }
.lgr-right  { background:#f8f9fa; overflow-y:auto; }

.lgr-section { padding:16px 20px; border-bottom:1px solid #f0f0f0; }
.lgr-section h4 { font-size:14px; font-weight:700; color:#212529; margin:0 0 10px; }
.lgr-qtext  { font-size:15px; line-height:1.8; color:#212529; }
.lgr-resp   { background:#fff; border:1px solid #dee2e6; border-radius:6px;
              padding:14px; font-size:14px; line-height:1.9; color:#495057;
              min-height:100px; white-space:pre-wrap; }
.lgr-guide  { background:#fff8e1; border:1px solid #ffe082; border-radius:6px;
              padding:12px 14px; font-size:13px; color:#6d5300; }

/* Right panel */
.lgr-right-head { background:#fff; border-bottom:2px solid #dee2e6; padding:14px 16px;
                  font-size:15px; font-weight:700; color:#212529; }
.lgr-rp-body    { padding:16px; }
.lgr-mark-row   { display:flex; align-items:center; gap:10px; margin-top:16px; flex-wrap:wrap; }
.lgr-mark-in    { width:90px; border:2px solid #dee2e6; border-radius:6px;
                  padding:8px 12px; font-size:18px; font-weight:700; text-align:center; }
.lgr-mark-in:focus { border-color:#0f6cbf; outline:none; }
.lgr-submit-btn { background:#0f6cbf; color:#fff; border:none; border-radius:6px;
                  padding:10px 22px; font-size:15px; font-weight:700; cursor:pointer; }
.lgr-submit-btn:hover { background:#0a52a2; }
.lgr-other-grade { background:#e8f4fd; border-radius:6px; padding:8px 12px; font-size:12px; color:#0a4d8c; margin-top:8px; }
.lgr-no-resp { color:#adb5bd; font-style:italic; }

@media(max-width:900px){.lgr-split{grid-template-columns:1fr;}}
</style>

<div class="lgr-wrap">

<!-- ── Header ─────────────────────────────────────────────────────────────── -->
<div class="lgr-head">
  <div>
    <div class="lgr-sub"><?php echo format_string($course->fullname); ?></div>
    <h2><?php echo format_string($lg->name); ?></h2>
  </div>
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <?php if ($attemptid): ?>
    <a href="<?php echo new moodle_url('/mod/learnguard/grader.php', ['id' => $id]); ?>"
       style="background:rgba(255,255,255,.2);color:<?php echo $brand->textcolor; ?>;
              border:1px solid rgba(255,255,255,.4);border-radius:6px;
              padding:7px 14px;font-size:13px;font-weight:700;text-decoration:none;">
      ← Go Back
    </a>
    <?php else: ?>
    <a href="<?php echo new moodle_url('/mod/learnguard/admin.php', ['id' => $id, 'tab' => 'graders']); ?>"
       style="background:rgba(255,255,255,.2);color:<?php echo $brand->textcolor; ?>;
              border:1px solid rgba(255,255,255,.4);border-radius:6px;
              padding:7px 14px;font-size:13px;font-weight:700;text-decoration:none;">
      ⚙️ Manage Graders
    </a>
    <a href="<?php echo new moodle_url('/mod/learnguard/proctorview.php', ['id' => $id]); ?>"
       style="background:rgba(255,255,255,.2);color:<?php echo $brand->textcolor; ?>;
              border:1px solid rgba(255,255,255,.4);border-radius:6px;
              padding:7px 14px;font-size:13px;font-weight:700;text-decoration:none;">
      ← Back
    </a>
    <?php endif; ?>
  </div>
</div>

<?php if (empty($essaySlots)): ?>
<div class="alert alert-info" style="margin:20px;">
  No essay questions found for this assessment. Add essay-type questions to use this grading panel.
</div>

<?php elseif ($attemptid && $curAttempt): ?>
<!-- ═══════════════════════════════════════════════════════════════════════════
  GRADING VIEW
═══════════════════════════════════════════════════════════════════════════ -->

<?php
  // Build slot-status for THIS attempt's questions.
  $resp = $responses[$currentSlot->slotid] ?? null;
  $decoded = ($resp && $resp->responsedata) ? (json_decode($resp->responsedata, true) ?? []) : [];
  $answer  = $decoded["q{$currentSlot->slot}_answer"] ?? null;

  $myGrade = null;
  foreach (($gradesBySlot[$currentSlot->slotid] ?? []) as $g) {
    if ($g->graderid == $USER->id) { $myGrade = $g; break; }
  }
  $otherGrades = array_filter($gradesBySlot[$currentSlot->slotid] ?? [], fn($g) => $g->graderid != $USER->id);

  $hasResp   = ($answer !== null && trim($answer) !== '');
  $qStatus   = $hasResp ? ($myGrade ? 'graded' : 'rg') : 'na';
  $statusBg  = ['rg' => '#fff3cd', 'graded' => '#d4edda', 'na' => '#f8d7da'][$qStatus] ?? '#f8f9fa';
  $statusTxt = ['rg' => 'Requires Grading', 'graded' => 'Graded', 'na' => 'Not Answered'][$qStatus] ?? '—';
  $displayName = $anonymous ? 'Candidate #' . $curAttempt->id : fullname($curStudent);
  $maxScore    = (float)($currentSlot->maxmark ?: 1);
  $rc = $rcolours[$curAttempt->risk_level ?? 'low'] ?? '#6c757d';

  // Per-slot statuses for nav bar.
  $navStatuses = [];
  foreach ($essaySlots as $ns) {
    $nr  = $responses[$ns->slotid] ?? null;
    $nd  = ($nr && $nr->responsedata) ? (json_decode($nr->responsedata, true) ?? []) : [];
    $na  = $nd["q{$ns->slot}_answer"] ?? null;
    $hasR = ($na !== null && trim($na) !== '');
    $hasG = false;
    foreach (($gradesBySlot[$ns->slotid] ?? []) as $g) {
      if ($g->graderid == $USER->id) { $hasG = true; break; }
    }
    $navStatuses[] = $hasR ? ($hasG ? 'graded' : 'rg') : 'na';
  }
?>

<!-- Question navigation bar -->
<div class="lgr-qnav">
  <button onclick="history.back()" class="lgr-qnav-btn" title="Previous">&lt;</button>
  <?php foreach ($essaySlots as $ni => $ns):
    $ns_stat = $navStatuses[$ni];
    $ns_bg   = ['rg' => '#0f6cbf', 'graded' => '#28a745', 'na' => '#dc3545'][$ns_stat] ?? '#495057';
    $isActive = ($ni + 1 === $qnum) ? 'style="background:' . $ns_bg . ';outline:3px solid #fff;"' : 'style="background:' . $ns_bg . ';"';
  ?>
  <a href="<?php echo new moodle_url('/mod/learnguard/grader.php', ['id' => $id, 'attemptid' => $attemptid, 'q' => $ni + 1]); ?>"
     class="lgr-qnav-btn" <?php echo $isActive; ?>>Q<?php echo $ni + 1; ?></a>
  <?php endforeach; ?>
  <button onclick="window.location.href='<?php echo new moodle_url('/mod/learnguard/grader.php', ['id' => $id, 'attemptid' => $attemptid, 'q' => min($qnum + 1, $totalSlots)]); ?>'" class="lgr-qnav-btn">&gt;</button>

  <div style="flex:1;"></div>
  <?php if (!$anonymous): ?>
  <a href="<?php echo new moodle_url('/mod/learnguard/attemptdetail.php', ['id' => $id, 'attempt' => $attemptid]); ?>"
     style="background:rgba(255,255,255,.15);color:#fff;text-decoration:none;padding:6px 14px;border-radius:5px;font-size:13px;font-weight:600;">
    View Response History
  </a>
  <?php endif; ?>
  <a href="<?php echo new moodle_url('/mod/learnguard/grader.php', ['id' => $id]); ?>"
     style="background:rgba(255,255,255,.1);color:#fff;text-decoration:none;padding:6px 10px;border-radius:5px;font-size:13px;margin-left:6px;">✕</a>
</div>

<!-- Status bar -->
<div class="lgr-qstatus" style="background:<?php echo $statusBg; ?>;">
  <span style="background:#6c757d;color:#fff;padding:2px 9px;border-radius:4px;font-size:11px;"><?php echo $statusTxt; ?></span>
  <span>Question Number: <span><?php echo $qnum; ?></span></span>
  <span>Question Type: <span>Essay</span></span>
  <span>Status: <span><?php echo $hasResp ? 'Complete' : 'Not answered'; ?></span></span>
  <span>Marks: <span><?php echo $myGrade ? $myGrade->grade . '/' . $maxScore : 'RG'; ?></span></span>
  <span style="margin-left:auto;font-weight:700;">
    <?php echo $displayName; ?>
    <span class="lgr-qbadge rg" style="font-size:11px;background:<?php echo $rc; ?>;">
      Risk: <?php echo $curAttempt->risk_score ?? 0; ?>
    </span>
  </span>
</div>

<!-- Split pane -->
<div class="lgr-split">

  <!-- LEFT: Question + Response + Marking Guide -->
  <div class="lgr-left">
    <div class="lgr-section">
      <h4>Question</h4>
      <div class="lgr-qtext"><?php echo format_text($currentSlot->questiontext, FORMAT_HTML); ?></div>
    </div>

    <div class="lgr-section">
      <h4>Student Response</h4>
      <?php if ($hasResp): ?>
      <div class="lgr-resp"><?php echo nl2br(s($answer)); ?></div>
      <?php else: ?>
      <div class="lgr-resp"><span class="lgr-no-resp">No response submitted for this question.</span></div>
      <?php endif; ?>
    </div>

    <?php if (!empty($currentSlot->markingguidance)): ?>
    <div class="lgr-section">
      <h4>📋 Marking Guide / Rubric</h4>
      <div class="lgr-guide"><?php echo format_text($currentSlot->markingguidance, FORMAT_HTML); ?></div>
    </div>
    <?php endif; ?>

    <?php if ($isManager && !empty($otherGrades)): ?>
    <div class="lgr-section">
      <h4>Other Graders</h4>
      <?php foreach ($otherGrades as $og):
        $gName = !empty($og->anonymous) ? 'Grader (anonymous)' : fullname((object)['firstname'=>$og->firstname,'lastname'=>$og->lastname]);
        $gLayer = $og->gradelayer ?: 1;
      ?>
      <div class="lgr-other-grade">
        <strong>Layer <?php echo $gLayer; ?> — <?php echo $gName; ?>:</strong>
        <?php echo $og->grade; ?>/<?php echo $maxScore; ?> pts
        <?php if ($og->feedback): ?>
        <br><em style="color:#6c757d;"><?php echo s($og->feedback); ?></em>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- RIGHT: Comment and Grade panel -->
  <div class="lgr-right">
    <div class="lgr-right-head">Comment and Grade</div>
    <div class="lgr-rp-body">
      <?php if ($hasResp): ?>
      <form method="post" action="<?php echo new moodle_url('/mod/learnguard/grader.php'); ?>">
        <input type="hidden" name="id"        value="<?php echo $id; ?>">
        <input type="hidden" name="attemptid" value="<?php echo $attemptid; ?>">
        <input type="hidden" name="q"         value="<?php echo $qnum; ?>">
        <input type="hidden" name="action"    value="grade_slot">
        <input type="hidden" name="slotid"    value="<?php echo $currentSlot->slotid; ?>">
        <input type="hidden" name="sesskey"   value="<?php echo sesskey(); ?>">

        <label style="font-size:13px;font-weight:700;display:block;margin-bottom:6px;">Comment (optional)</label>
        <textarea name="feedback" rows="6"
          style="width:100%;border:1px solid #dee2e6;border-radius:6px;padding:10px 12px;
                 font-size:13px;resize:vertical;box-sizing:border-box;background:#fff;"
          placeholder="Enter feedback for the student…"><?php echo $myGrade ? s($myGrade->feedback) : ''; ?></textarea>

        <div class="lgr-mark-row">
          <span style="font-size:14px;font-weight:700;color:#495057;">Mark</span>
          <input type="number" name="grade" class="lgr-mark-in"
                 min="0" max="<?php echo $maxScore; ?>" step="0.5"
                 value="<?php echo $myGrade ? $myGrade->grade : ''; ?>"
                 placeholder="0" required>
          <span style="font-size:14px;color:#6c757d;">out of <strong><?php echo $maxScore; ?></strong></span>
        </div>

        <!-- Quick percentage shortcuts -->
        <div style="display:flex;gap:5px;margin-top:10px;flex-wrap:wrap;">
          <?php for ($pct = 0; $pct <= 100; $pct += 25):
            $val = round($maxScore * $pct / 100, 1); ?>
          <button type="button"
            onclick="this.closest('form').querySelector('[name=grade]').value='<?php echo $val; ?>'"
            style="padding:3px 10px;border:1px solid #dee2e6;border-radius:10px;cursor:pointer;font-size:12px;background:#f8f9fa;color:#495057;">
            <?php echo $pct; ?>%
          </button>
          <?php endfor; ?>
        </div>

        <div style="margin-top:16px;">
          <button type="submit" class="lgr-submit-btn" style="width:100%;">
            ✅ Submit Grade & Next →
          </button>
        </div>

        <?php if ($myGrade): ?>
        <div style="background:#d4edda;border-radius:6px;padding:8px 12px;font-size:12px;color:#155724;margin-top:10px;">
          ✅ You graded this: <strong><?php echo $myGrade->grade; ?></strong>/<?php echo $maxScore; ?> pts
        </div>
        <?php endif; ?>
      </form>
      <?php else: ?>
      <div style="padding:20px;text-align:center;color:#6c757d;font-size:14px;">
        <div style="font-size:32px;margin-bottom:10px;">⬜</div>
        No response for this question — nothing to grade.
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ═══════════════════════════════════════════════════════════════════════════
  LIST VIEW
═══════════════════════════════════════════════════════════════════════════ -->

<!-- Stats bar -->
<div class="lgr-stats">
  <span>📊 Attempts: <strong><?php echo count($attempts); ?></strong></span>
  <span>🏅 Grading method: <strong>Essay / Manual</strong></span>
  <?php if ($anonymous): ?><span>👤 <strong>Anonymous marking ON</strong></span><?php endif; ?>
  <?php if ($distributed): ?>
  <span>📦 Distribution: <strong><?php echo ucfirst($gradingMode); ?></strong></span>
  <?php endif; ?>
  <?php if ($isManager): ?>
  <span style="margin-left:auto;">
    <span style="background:#6c757d;color:#fff;border-radius:10px;padding:2px 10px;font-size:12px;">Layer <?php echo $gradeLayer; ?> Grader</span>
  </span>
  <?php endif; ?>
</div>

<!-- Tabs -->
<div class="lgr-tabs">
  <a href="<?php echo new moodle_url('/mod/learnguard/grader.php', ['id' => $id, 'tab' => 'ungraded']); ?>"
     class="lgr-tab <?php echo ($tab !== 'graded') ? 'active' : ''; ?>">
    Not Graded Users (<?php echo count($ungradedAttempts); ?>)
  </a>
  <a href="<?php echo new moodle_url('/mod/learnguard/grader.php', ['id' => $id, 'tab' => 'graded']); ?>"
     class="lgr-tab <?php echo ($tab === 'graded') ? 'active' : ''; ?>">
    Graded Users (<?php echo count($gradedAttempts); ?>)
  </a>
</div>

<!-- Filter row -->
<div class="lgr-filters">
  <div style="display:flex;align-items:center;gap:12px;">
    <label style="display:flex;align-items:center;gap:6px;font-size:13px;font-weight:600;cursor:pointer;">
      <input type="checkbox" id="lgr-select-all" onchange="document.querySelectorAll('.lgr-row-chk').forEach(c=>c.checked=this.checked)">
      Select All
    </label>
  </div>
  <div class="lgr-legend">
    <span><span class="lgr-leg-rg">RG</span> Require Grading</span>
    <span><span class="lgr-leg-na">NA</span> Not Answered</span>
    <span><span class="lgr-leg-ok">✓</span> Graded</span>
  </div>
</div>

<?php
  $currentList = ($tab === 'graded') ? $gradedAttempts : $ungradedAttempts;
?>
<?php if (empty($currentList)): ?>
<div style="padding:40px 20px;text-align:center;color:#6c757d;font-size:15px;">
  <?php if ($tab === 'graded'): ?>
  No graded users yet.
  <?php else: ?>
  All users have been graded — great work!
  <?php endif; ?>
</div>
<?php else: ?>
<table class="lgr-table">
  <thead>
    <tr>
      <th style="width:30px;"><input type="checkbox" id="lgr-head-chk" onchange="document.querySelectorAll('.lgr-row-chk').forEach(c=>c.checked=this.checked)"></th>
      <th>Candidate</th>
      <th>GRADE</th>
      <th>ATTEMPTS</th>
      <th>STARTED</th>
      <th>COMPLETED</th>
      <th>TIME TAKEN</th>
      <?php foreach ($essaySlots as $ni => $ns): ?>
      <th style="text-align:center;">Q<?php echo $ni + 1; ?></th>
      <?php endforeach; ?>
      <th>Action</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($currentList as $att):
    $displayName = $anonymous ? 'Candidate #' . $att->id : fullname($att);
    $initials    = strtoupper(substr($att->firstname ?? '?', 0, 1) . substr($att->lastname ?? '?', 0, 1));
    $rc   = $rcolours[$att->risk_level ?? 'low'] ?? '#6c757d';
    $sc   = ($totalSlots > 0 && $att->sumgrades !== null)
          ? round($att->sumgrades, 1) . '/' . array_sum(array_column($essaySlots, 'maxmark'))
          : '--/--';
    $timeTaken = ($att->timestart && $att->timefinish)
        ? human_readable_secs(max(0, $att->timefinish - $att->timestart)) : '—';
  ?>
  <tr>
    <td><input type="checkbox" class="lgr-row-chk" value="<?php echo $att->id; ?>"></td>
    <td>
      <div style="display:flex;align-items:center;gap:10px;">
        <div class="lgr-avatar"><?php echo $initials; ?></div>
        <div>
          <div class="lgr-name"><?php echo $displayName; ?></div>
          <?php if (!$anonymous): ?>
          <div class="lgr-email"><?php echo s($att->email); ?></div>
          <?php endif; ?>
        </div>
      </div>
    </td>
    <td><strong><?php echo $sc; ?></strong></td>
    <td><strong><?php echo $att->attempt; ?></strong></td>
    <td><small><?php echo $att->timestart ? userdate($att->timestart, '%d %b %Y %H:%M') : '—'; ?></small></td>
    <td><small><?php echo $att->timefinish ? userdate($att->timefinish, '%d %b %Y %H:%M') : '—'; ?></small></td>
    <td><small><?php echo $timeTaken; ?></small></td>
    <?php foreach ($essaySlots as $ni => $ns):
      $slotStat = $slotStatusMap[$att->id][$ns->slotid] ?? 'na';
      $cls  = ['rg' => 'rg', 'graded' => 'ok', 'na' => 'na'][$slotStat] ?? 'na';
      $lbl  = ['rg' => 'RG', 'graded' => '✓',  'na' => 'NA'][$slotStat] ?? 'NA';
      $href = new moodle_url('/mod/learnguard/grader.php', ['id' => $id, 'attemptid' => $att->id, 'q' => $ni + 1]);
    ?>
    <td style="text-align:center;">
      <a href="<?php echo $href; ?>" class="lgr-qbadge <?php echo $cls; ?>"><?php echo $lbl; ?></a>
    </td>
    <?php endforeach; ?>
    <td>
      <div style="display:flex;gap:6px;flex-wrap:wrap;">
        <a href="<?php echo new moodle_url('/mod/learnguard/grader.php', ['id' => $id, 'attemptid' => $att->id, 'q' => 1]); ?>"
           class="btn btn-sm <?php echo ($att->my_graded >= $totalSlots) ? 'btn-outline-success' : 'btn-primary'; ?>" style="white-space:nowrap;">
          <?php echo ($att->my_graded >= $totalSlots && $totalSlots > 0) ? '✏️ Review' : '✏️ Grade'; ?>
        </a>
        <?php if ($isManager): ?>
        <form method="post" style="display:inline;" onsubmit="return confirm('Void this attempt and allow the student to retake? The grading data will be preserved.');">
          <input type="hidden" name="action"  value="void_attempt">
          <input type="hidden" name="vaid"    value="<?php echo $att->id; ?>">
          <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
          <input type="hidden" name="id"      value="<?php echo $id; ?>">
          <button type="submit" class="btn btn-sm btn-outline-warning" title="Void attempt — student can retake" style="white-space:nowrap;">
            🔄 Reattempt
          </button>
        </form>
        <?php endif; ?>
      </div>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
<?php endif; ?>

</div><!-- /lgr-wrap -->

<?php echo $OUTPUT->footer();
