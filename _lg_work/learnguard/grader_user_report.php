<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * LearnGuard — Grader User Report
 * Shows all attempts for one student with full question + answer detail.
 * Each attempt is collapsible (accordion). MCQ questions show all options.
 * Includes a browser print button for PDF export.
 *
 * URL: /mod/learnguard/grader_user_report.php?id=CMID&userid=USERID
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/question_engine.php');

$id     = required_param('id',     PARAM_INT);
$userid = required_param('userid', PARAM_INT);

$cm     = get_coursemodule_from_id('learnguard', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$lg     = $DB->get_record('learnguard', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context   = context_module::instance($cm->id);
$isManager = has_capability('mod/learnguard:manage', $context);

// Only managers and assigned graders may view this report.
$myGrader = $DB->get_record('learnguard_graders', ['learnguard' => $cm->instance, 'userid' => $USER->id]);
if (!$myGrader) {
    try {
        $myGrader = $DB->get_record_sql(
            "SELECT * FROM {learnguard_graders} WHERE userid = :uid AND is_global = 1 ORDER BY id LIMIT 1",
            ['uid' => $USER->id]
        ) ?: null;
    } catch (Exception $e) { $myGrader = null; }
}
if (!$isManager && !$myGrader) {
    throw new moodle_exception('accessdenied', 'error', '', 'You are not assigned as a grader for this assessment.');
}

$anonymous = !empty($myGrader->anonymous) && !$isManager;

// Load the student.
$student = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);

// Load all attempts (finished + voided) ordered chronologically.
$attempts = array_values($DB->get_records('learnguard_attempts',
    ['learnguard' => $cm->instance, 'userid' => $userid],
    'attempt ASC',
    'id, attempt, state, timestart, timefinish, sumgrades, reopened_for_review, review_granted_time'
));

// A pending reattempt/review grant that the student hasn't acted on yet: schedules a
// placeholder card so the grant is visible here instead of only in Assessment Manager.
// completedCount mirrors classes/attempt_manager.php::start_or_resume_locked() exactly
// (finished/suspended, or reopened_for_review — an attempt reopened for review still
// counts as "used" even while its state is back to inprogress).
$pendingGrant = null;
if (!empty($attempts)) {
    $schedule    = $DB->get_record('learnguard_schedules', ['learnguard' => $cm->instance, 'userid' => $userid]);
    $extraAtt    = $schedule ? (int)$schedule->extra_attempts : 0;
    $baseMax     = (int)($lg->attempts ?? 0);
    $hasInProgress = false;
    $completedCount = 0;
    $highestAttNum  = 0;
    foreach ($attempts as $a) {
        if ($a->state === 'inprogress') { $hasInProgress = true; }
        if (in_array($a->state, ['finished', 'suspended']) || !empty($a->reopened_for_review)) {
            $completedCount++;
        }
        $highestAttNum = max($highestAttNum, (int)$a->attempt);
    }
    if ($extraAtt > 0 && $baseMax > 0 && !$hasInProgress) {
        $effectiveMax = $baseMax + $extraAtt;
        if ($completedCount < $effectiveMax) {
            $pendingGrant = ['nextattemptnum' => $highestAttNum + 1];
        }
    }
}

// Load check-in events for all this student's attempts in this activity.
$checkInEvents = [];
if (!empty($attempts)) {
    $attemptIds = array_column($attempts, 'id');
    [$aidSql, $aidP] = $DB->get_in_or_equal(array_map('intval', $attemptIds), SQL_PARAMS_NAMED, 'caid');
    $ciRows = $DB->get_records_sql(
        "SELECT e.id, e.attemptid, e.event_type, e.event_data, e.timemodified
           FROM {learnguard_events} e
          WHERE e.userid = :uid AND e.attemptid $aidSql
            AND e.event_type IN ('checkin_prompted','checkin_continued','checkin_paused','checkin_dismissed','self_paused')
          ORDER BY e.timemodified ASC",
        array_merge(['uid' => $userid], $aidP)
    );
    foreach ($ciRows as $r) {
        $checkInEvents[] = $r;
    }
}

if (empty($attempts)) {
    throw new moodle_exception('invalidattemptid', 'error', '', 'No attempts found for this user.');
}

// Pre-load all MCQ/multicheck answer options for this activity's questions
// (batch query to avoid N+1 inside the per-question loop).
$activityQids = $DB->get_fieldset_sql(
    "SELECT DISTINCT q.id FROM {learnguard_slots} s JOIN {learnguard_qbank} q ON q.id = s.questionid
      WHERE s.learnguard = :lgid AND q.qtype IN ('multichoice','multicheck')",
    ['lgid' => $cm->instance]
);
$allOptions = []; // keyed by questionid → array of answer objects
if (!empty($activityQids)) {
    [$qidSql, $qidP] = $DB->get_in_or_equal(array_map('intval', $activityQids), SQL_PARAMS_NAMED, 'qid');
    $optRows = $DB->get_records_sql(
        "SELECT id, questionid, answertext, fraction, sortorder
           FROM {learnguard_qbank_answers}
          WHERE questionid $qidSql
          ORDER BY questionid ASC, sortorder ASC",
        $qidP
    );
    foreach ($optRows as $opt) {
        $allOptions[(int)$opt->questionid][] = $opt;
    }
}

// Decodes one response's raw JSON into the same {rawAnswer, answer, selectedIds,
// options} shape the per-question display already uses — shared by the CURRENT answer
// and, for a reviewed attempt, the PRE-REVIEW ("before") answer, so both render through
// identical logic and can never silently drift apart.
function lgur_extract_answer_display(?string $rawjson, int $slotnum, string $qtype, int $qid, array $allOptions): array {
    $decoded   = $rawjson ? (json_decode($rawjson, true) ?? []) : [];
    $rawAnswer = $decoded["q{$slotnum}_answer"] ?? '';

    $selectedIds = [];
    $optionsList = [];
    if (in_array($qtype, ['multichoice', 'multicheck'])) {
        if (is_numeric($rawAnswer)) {
            $selectedIds[] = (int)$rawAnswer;
        } elseif (is_string($rawAnswer) && strpos($rawAnswer, ',') !== false) {
            foreach (explode(',', $rawAnswer) as $sid2) {
                if (is_numeric(trim($sid2))) { $selectedIds[] = (int)trim($sid2); }
            }
        }
        $optionsList = $allOptions[$qid] ?? [];
    }

    $answerDisplay = '';
    if (in_array($qtype, ['multichoice', 'multicheck'])) {
        $answerDisplay = ''; // rendered via options list instead.
    } elseif ($qtype === 'shortanswer') {
        $answerDisplay = s($rawAnswer);
    } elseif ($qtype === 'essay') {
        // render_essay_answer_html() handles both an OLD plain-text answer (escaped,
        // newline-to-<br>) and a NEW rich-text HTML answer from the essay formatting
        // toolbar — a bare format_text() alone does not escape, so a legacy answer
        // containing a stray "<" could still misrender.
        $answerDisplay = \mod_learnguard\question_engine::render_essay_answer_html((string)$rawAnswer);
    } elseif ($qtype === 'choiceessay') {
        // $rawAnswer here is the whole {promptid,text,shown} JSON blob, not just the
        // written text — previously fell into the plain s() branch below and showed the
        // raw JSON verbatim. Extract just the written response.
        $ceData = is_string($rawAnswer) ? (json_decode($rawAnswer, true) ?? []) : [];
        $answerDisplay = \mod_learnguard\question_engine::render_essay_answer_html((string)($ceData['text'] ?? ''));
    } else {
        $answerDisplay = s($rawAnswer);
    }

    return [
        'rawAnswer'   => $rawAnswer,
        'answer'      => $answerDisplay,
        'selectedIds' => $selectedIds,
        'options'     => $optionsList,
    ];
}

// For each attempt, load the assigned questions + responses.
$attemptData = [];
foreach ($attempts as $att) {
    $aid = (int)$att->id;

    // Pre-review answers, only relevant for an attempt that was reopened for review.
    $reviewChanges = !empty($att->reopened_for_review)
        ? \mod_learnguard\attempt_manager::get_review_changes($aid, (int)$att->review_granted_time)
        : [];

    $aqRows = $DB->get_records('learnguard_attempt_questions', ['attemptid' => $aid], 'slotnum ASC');
    if (!empty($aqRows)) {
        $slotIds = array_column(array_values($aqRows), 'slotid');
    } else {
        $respRows = $DB->get_records('learnguard_responses', ['attemptid' => $aid], '', 'slotid');
        $slotIds  = array_column(array_values($respRows), 'slotid');
    }

    $questions = [];
    if (!empty($slotIds)) {
        [$sidSql, $sidP] = $DB->get_in_or_equal(array_map('intval', $slotIds), SQL_PARAMS_NAMED, 'sid');
        $slotRows = $DB->get_records_sql(
            "SELECT s.id AS slotid, s.slot, q.id AS qid, q.qtype, q.questiontext, s.maxmark
               FROM {learnguard_slots} s
               LEFT JOIN {learnguard_qbank} q ON q.id = s.questionid
              WHERE s.id $sidSql
              ORDER BY s.slot",
            $sidP
        );
        foreach ($slotRows as $slotid => $slot) {
            $resp    = $DB->get_record('learnguard_responses', ['attemptid' => $aid, 'slotid' => $slotid]);
            $aqRow   = null;
            foreach ($aqRows as $aq) { if ((int)$aq->slotid === (int)$slotid) { $aqRow = $aq; break; } }
            $slotnum = $aqRow ? (int)$aqRow->slotnum : (int)$slot->slot;

            $qtype = $slot->qtype ?? 'essay';
            $qid   = (int)($slot->qid ?? 0);

            $cur = lgur_extract_answer_display(
                $resp ? $resp->responsedata : null, $slotnum, $qtype, $qid, $allOptions
            );

            // If this slot's answer changed during a Continue & Review, extract the
            // pre-review ("before") answer through the exact same logic, ready for the
            // colour-coded before/after block in the template.
            $reviewBefore = null;
            if (isset($reviewChanges[(int)$slotid])) {
                $rc = $reviewChanges[(int)$slotid];
                $reviewBefore = lgur_extract_answer_display(
                    $rc['beforeraw'], $slotnum, $qtype, $qid, $allOptions
                );
                $reviewBefore['fraction'] = $rc['beforefraction'];
                $reviewBefore['state']    = $rc['beforestate'];
            }

            // Manual grade and stored fraction for this slot.
            $grade    = $DB->get_record('learnguard_essay_grades',
                ['attemptid' => $aid, 'slotid' => $slotid], 'grade, graderid');
            $fraction = $resp ? (float)$resp->fraction : null;
            $state    = $resp ? $resp->state : null;

            $questions[] = [
                'slot'        => $slot,
                'slotnum'     => $slotnum,
                'qtype'       => $qtype,
                'rawAnswer'   => $cur['rawAnswer'],
                'answer'      => $cur['answer'],
                'selectedIds' => $cur['selectedIds'],
                'options'     => $cur['options'],
                'state'       => $state,
                'fraction'    => $fraction,
                'grade'       => $grade ? (float)$grade->grade : null,
                'graderid'    => $grade ? (int)$grade->graderid : null,
                'reviewBefore'=> $reviewBefore,
            ];
        }
    }

    $attemptData[] = ['attempt' => $att, 'questions' => $questions];
}

$displayName = $anonymous
    ? 'Candidate #' . (int)$attempts[0]->id
    : fullname($student);

$PAGE->set_url('/mod/learnguard/grader_user_report.php', ['id' => $id, 'userid' => $userid]);
$PAGE->set_context($context);
$PAGE->set_title($displayName . ' — All Attempts');
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->css('/mod/learnguard/styles.css');

echo $OUTPUT->header();
?>
<style>
/* ── layout ── */
.lgur-wrap     { max-width:960px; margin:0 auto; font-family:system-ui,sans-serif; }
.lgur-header   { background:#0f6cbf; color:#fff; padding:16px 24px; border-radius:10px;
                 margin-bottom:20px; display:flex; align-items:center;
                 justify-content:space-between; flex-wrap:wrap; gap:10px; }
.lgur-header h2 { margin:0; font-size:20px; font-weight:700; }

/* ── accordion ── */
.lgur-acc-item  { border:1px solid #dee2e6; border-radius:10px; margin-bottom:14px;
                  overflow:hidden; }
.lgur-acc-hdr   { display:flex; align-items:center; gap:14px; flex-wrap:wrap;
                  padding:14px 20px; cursor:pointer; background:#f8f9fa;
                  user-select:none; transition:background .15s; }
.lgur-acc-hdr:hover { background:#e9ecef; }
.lgur-acc-hdr.open  { background:#e8f4fd; border-bottom:1px solid #b8d8f8; border-radius:10px 10px 0 0; }
.lgur-acc-arrow { font-size:12px; color:#6c757d; margin-left:auto; flex-shrink:0; }
.lgur-acc-body  { display:none; padding:20px; background:#fff; }
.lgur-acc-body.open { display:block; }

/* ── attempt header bits ── */
.lgur-att-num   { background:#0f6cbf; color:#fff; border-radius:6px; padding:4px 14px;
                  font-weight:700; font-size:15px; flex-shrink:0; }
.lgur-att-num.voided { background:#6c757d; }
.lgur-meta      { font-size:12px; color:#6c757d; }
.lgur-score     { font-weight:700; font-size:15px; white-space:nowrap; }

/* ── question card ── */
.lgur-q         { border:1px solid #e9ecef; border-radius:8px; padding:14px 16px;
                  margin-bottom:12px; background:#fafafa; }
.lgur-q-hdr     { font-weight:700; font-size:13px; color:#0f6cbf; margin-bottom:8px; }
.lgur-qtext     { font-size:14px; margin-bottom:10px; color:#212529; }
.lgur-ans       { background:#fff; border:1px solid #dee2e6; border-radius:6px;
                  padding:10px 14px; font-size:14px; min-height:36px; }
.lgur-grade     { margin-top:8px; font-size:12px; color:#6c757d; }

/* ── MCQ option list ── */
.lgur-opts      { list-style:none; margin:0; padding:0; }
.lgur-opt       { display:flex; align-items:center; gap:10px; padding:7px 10px;
                  border-radius:6px; margin-bottom:5px; font-size:14px;
                  border:1px solid #e9ecef; background:#fff; }
.lgur-opt.selected.correct   { background:#d4edda; border-color:#c3e6cb; }
.lgur-opt.selected.incorrect { background:#f8d7da; border-color:#f5c6cb; }
.lgur-opt.unselected.correct { background:#fff3cd; border-color:#ffe082; }
.lgur-opt-bullet { width:22px; height:22px; border-radius:50%; border:2px solid #adb5bd;
                   display:flex; align-items:center; justify-content:center;
                   font-size:11px; font-weight:700; flex-shrink:0; }
.lgur-opt.selected.correct   .lgur-opt-bullet { background:#28a745; border-color:#28a745; color:#fff; }
.lgur-opt.selected.incorrect .lgur-opt-bullet { background:#dc3545; border-color:#dc3545; color:#fff; }
.lgur-opt.unselected.correct .lgur-opt-bullet { background:#ffc107; border-color:#e0a800; color:#212529; }
.lgur-opt-tag   { font-size:11px; font-weight:700; margin-left:auto; padding:2px 8px;
                  border-radius:10px; white-space:nowrap; }
.lgur-opt.selected.correct   .lgur-opt-tag { background:#28a745; color:#fff; }
.lgur-opt.selected.incorrect .lgur-opt-tag { background:#dc3545; color:#fff; }
.lgur-opt.unselected.correct .lgur-opt-tag { background:#ffc107; color:#212529; }

/* ── badges ── */
.lgur-badge     { display:inline-block; border-radius:12px; padding:2px 10px;
                  font-size:11px; font-weight:700; }
.lgur-badge.graded  { background:#d4edda; color:#155724; }
.lgur-badge.pending { background:#fff3cd; color:#856404; }
.lgur-badge.blank   { background:#e2e3e5; color:#383d41; }
.lgur-badge.voided  { background:#f8d7da; color:#721c24; }
.lgur-badge.auto    { background:#e8f4fd; color:#0a4d8c; }
.lgur-badge.review  { background:#e0d4f7; color:#4a2f7c; }

/* ── granted-but-not-yet-started attempt placeholder ── */
.lgur-att-num.pending-grant { background:#adb5bd; }
.lgur-placeholder-body { padding:18px 20px; color:#6c757d; font-size:13px; background:#fff; }

/* ── review before/after diff ── */
.lgur-diff        { margin-top:10px; border:1px dashed #b39ddb; border-radius:8px;
                     padding:10px 14px; background:#f6f2fc; }
.lgur-diff-label   { font-size:11px; font-weight:700; color:#4a2f7c; margin-bottom:6px;
                      text-transform:uppercase; letter-spacing:.3px; }
.lgur-diff-before   { background:#fff3e0; border:1px solid #ffcc80; border-radius:6px;
                       padding:8px 12px; font-size:13px; margin-bottom:6px; }
.lgur-diff-before .lgur-opt.selected { background:#ffe0b2; border-color:#ffb74d; }
.lgur-diff-tag      { font-size:10px; font-weight:700; color:#e65100; text-transform:uppercase;
                       margin-bottom:4px; display:block; }
.lgur-diff-after-tag{ font-size:10px; font-weight:700; color:#2e7d32; text-transform:uppercase;
                       margin-bottom:4px; display:block; margin-top:8px; }

/* ── score breakdown ── */
.lgur-breakdown { font-size:12px; color:#495057; margin-top:14px; padding:10px 14px;
                  background:#f0f4ff; border:1px solid #cfe2ff; border-radius:6px; }
.lgur-breakdown table { width:100%; border-collapse:collapse; margin-top:6px; }
.lgur-breakdown th, .lgur-breakdown td { padding:4px 8px; text-align:left;
  border-bottom:1px solid #dee2e6; font-size:11px; }
.lgur-breakdown th { font-weight:700; color:#0a4d8c; }

@media print {
    .lgur-noprint { display:none !important; }
    .lgur-header  { background:#0f6cbf !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .lgur-acc-item { page-break-inside:avoid; border:1px solid #ccc !important; }
    .lgur-acc-body { display:block !important; }
    .lgur-acc-hdr  { background:#e8f4fd !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    /* Per-attempt print: when body has lgur-print-one class, hide non-target items */
    body.lgur-print-one .lgur-acc-item:not(.lgur-printing) { display:none !important; }
}
</style>

<div class="lgur-wrap">

<div class="lgur-header">
  <div>
    <h2>📄 All Attempts Report — <?php echo s($displayName); ?></h2>
    <?php if (!$anonymous): ?>
    <div style="font-size:13px;opacity:.85;"><?php echo s($student->email); ?></div>
    <?php endif; ?>
  </div>
  <div class="lgur-noprint" style="display:flex;gap:8px;flex-wrap:wrap;">
    <button onclick="lgurExpandAll()" class="btn btn-light btn-sm">⬇ Expand All</button>
    <button onclick="lgurCollapseAll()" class="btn btn-light btn-sm">⬆ Collapse All</button>
    <button onclick="window.print()" class="btn btn-light btn-sm">🖨️ Print / Save as PDF</button>
    <a href="<?php echo new moodle_url('/mod/learnguard/grader.php', ['id' => $id]); ?>"
       class="btn btn-outline-light btn-sm">← Back to Grader</a>
  </div>
</div>

<div style="background:#e8f4fd;border:1px solid #b8d8f8;border-radius:8px;padding:10px 16px;margin-bottom:16px;font-size:13px;">
  <strong>Activity:</strong> <?php echo s(format_string($lg->name)); ?>
  &nbsp;|&nbsp;<strong>Course:</strong> <?php echo s(format_string($course->fullname)); ?>
  &nbsp;|&nbsp;<strong>Attempts:</strong> <?php echo count($attempts); ?>
  &nbsp;|&nbsp;<strong>Max Grade:</strong> <?php echo number_format((float)$lg->grade, 2); ?>
</div>

<?php foreach ($attemptData as $idx => $adata):
    $att      = $adata['attempt'];
    $qs       = $adata['questions'];
    $isVoided = $att->state === 'voided';
    $started  = $att->timestart  ? userdate((int)$att->timestart,  get_string('strftimedatetimeshort')) : '—';
    $finished = $att->timefinish ? userdate((int)$att->timefinish, get_string('strftimedatetimeshort')) : '—';
    $sumG     = $att->sumgrades !== null
        ? number_format((float)$att->sumgrades, 2) . ' / ' . number_format((float)$lg->grade, 2)
        : '— (pending)';
    $accId    = 'lgur-acc-' . $idx;
    // Expand the most recent (last) attempt by default.
    $openByDefault = ($idx === count($attemptData) - 1);
?>
<div class="lgur-acc-item">

  <!-- Accordion header -->
  <div class="lgur-acc-hdr <?php echo $openByDefault ? 'open' : ''; ?>"
       onclick="lgurToggle('<?php echo $accId; ?>')" id="<?php echo $accId; ?>-hdr">
    <span class="lgur-att-num <?php echo $isVoided ? 'voided' : ''; ?>">
      Attempt <?php echo (int)$att->attempt; ?><?php echo $isVoided ? ' (VOID)' : ''; ?>
    </span>
    <?php if (!empty($att->reopened_for_review)): ?>
    <span class="lgur-badge review" title="<?php
        echo $att->review_granted_time
            ? 'Continue & Review granted ' . s(userdate((int)$att->review_granted_time, get_string('strftimedatetimeshort')))
            : 'Continue & Review granted before this date was tracked'; ?>">
      🔄 Reviewed
    </span>
    <?php endif; ?>
    <div class="lgur-meta">
      <div>Started: <strong><?php echo $started; ?></strong></div>
      <div>Finished: <strong><?php echo $finished; ?></strong></div>
    </div>
    <div class="lgur-score">
      <?php echo $isVoided
          ? '<span class="lgur-badge voided">Voided</span>'
          : s($sumG); ?>
    </div>
    <button class="btn btn-sm btn-outline-secondary lgur-noprint"
            style="font-size:11px;padding:2px 8px;margin-left:4px;"
            onclick="event.stopPropagation();lgurPrintAttempt('<?php echo $accId; ?>')"
            title="Print only this attempt">🖨️ Print</button>
    <span class="lgur-acc-arrow" id="<?php echo $accId; ?>-arrow">
      <?php echo $openByDefault ? '▲' : '▼'; ?>
    </span>
  </div>

  <!-- Accordion body -->
  <div class="lgur-acc-body <?php echo $openByDefault ? 'open' : ''; ?>" id="<?php echo $accId; ?>">

    <?php if (empty($qs)): ?>
      <p style="color:#6c757d;font-size:13px;">No question assignment records for this attempt.</p>
    <?php else: ?>

    <?php
    // Build score breakdown data for manager view.
    $breakdownRows = [];
    $totalRaw = 0;
    $totalMax = 0;
    foreach ($qs as $qd) {
        $mm  = (float)($qd['slot']->maxmark ?? 1);
        $fr  = $qd['fraction'];
        $contrib = ($fr !== null) ? ($fr * $mm) : null;
        $breakdownRows[] = [
            'slot'    => $qd['slotnum'],
            'qtype'   => $qd['qtype'],
            'maxmark' => $mm,
            'fraction'=> $fr,
            'contrib' => $contrib,
        ];
        $totalMax += $mm;
        if ($contrib !== null) { $totalRaw += $contrib; }
    }
    ?>

    <?php foreach ($qs as $qi => $qd):
        $slot  = $qd['slot'];
        $qtype = $qd['qtype'];
        $state = $qd['state'];
        if ($state === 'graded')       { $badgeCls = 'graded'; $badgeLbl = '✓ Graded'; }
        elseif (in_array($qtype, ['multichoice','multicheck','truefalse']) && $state === 'complete')
                                        { $badgeCls = 'auto';   $badgeLbl = '⚡ Auto-graded'; }
        elseif ($state === 'needsgrading') { $badgeCls = 'pending'; $badgeLbl = '⏳ Pending'; }
        // state check first, not just rawAnswer==='' — for choiceessay, rawAnswer is the
        // whole {promptid,text,shown} JSON blob, never a literal empty string even when
        // the student wrote nothing, so that check alone would mislabel a blank choiceessay
        // answer as "Pending" instead of "Blank".
        elseif ($state === 'noresponse' || $qd['rawAnswer'] === '') { $badgeCls = 'blank'; $badgeLbl = 'Blank'; }
        else                            { $badgeCls = 'pending'; $badgeLbl = '⏳ Pending'; }
    ?>
    <div class="lgur-q">
      <div class="lgur-q-hdr">
        Q<?php echo ($qi + 1); ?>
        &nbsp;<span class="lgur-badge" style="background:#e8f4fd;color:#0a4d8c;font-size:10px;vertical-align:middle;">
          <?php echo htmlspecialchars(ucfirst($qtype)); ?>
        </span>
        &nbsp;/ <?php echo number_format((float)($slot->maxmark ?? 1), 2); ?> mark(s)
        &nbsp;<span class="lgur-badge <?php echo $badgeCls; ?>"><?php echo $badgeLbl; ?></span>
        <?php if ($qd['fraction'] !== null): ?>
        &nbsp;<span style="font-size:11px;color:#495057;font-weight:400;">
          (raw: <?php echo number_format($qd['fraction'] * (float)($slot->maxmark ?? 1), 3); ?> / <?php echo number_format((float)($slot->maxmark ?? 1), 2); ?>)
        </span>
        <?php endif; ?>
      </div>
      <div class="lgur-qtext">
        <?php echo format_text($slot->questiontext ?? '<em>(Question text unavailable)</em>', FORMAT_HTML); ?>
      </div>

      <?php if (in_array($qtype, ['multichoice', 'multicheck']) && !empty($qd['options'])): ?>
      <!-- All answer options with selection/correctness indicators -->
      <div style="font-size:12px;color:#495057;font-weight:600;margin-bottom:6px;">Answer Options:</div>
      <ul class="lgur-opts">
        <?php foreach ($qd['options'] as $optIdx => $opt):
            $isSelected = in_array((int)$opt->id, $qd['selectedIds']);
            $isCorrect  = $opt->fraction >= 1.0;
            $isPartial  = $opt->fraction > 0 && $opt->fraction < 1.0;
            $optCls     = '';
            $tag        = '';
            if ($isSelected && $isCorrect) {
                $optCls = 'selected correct';
                $tag    = '✓ Selected · Correct';
            } elseif ($isSelected && !$isCorrect) {
                $optCls = 'selected incorrect';
                $tag    = '✗ Selected · Incorrect';
            } elseif (!$isSelected && $isCorrect) {
                $optCls = 'unselected correct';
                $tag    = '★ Correct answer';
            } elseif (!$isSelected && $isPartial) {
                $optCls = 'unselected correct';
                $tag    = '◑ Partial credit (' . number_format($opt->fraction * 100) . '%)';
            }
            $letter = chr(65 + $optIdx); // A, B, C, D …
        ?>
        <li class="lgur-opt <?php echo $optCls; ?>">
          <span class="lgur-opt-bullet"><?php echo $letter; ?></span>
          <span><?php echo format_text($opt->answertext, FORMAT_HTML); ?></span>
          <?php if ($tag): ?><span class="lgur-opt-tag"><?php echo $tag; ?></span><?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php if (empty($qd['selectedIds']) && $qd['rawAnswer'] === ''): ?>
      <p style="color:#999;font-size:13px;margin-top:6px;"><em>No option selected.</em></p>
      <?php endif; ?>

      <?php elseif (in_array($qtype, ['multichoice', 'multicheck'])): ?>
      <!-- MCQ but no options found in DB -->
      <div class="lgur-ans">
        <?php echo ($qd['rawAnswer'] !== '' ? s($qd['rawAnswer']) : '<em style="color:#999;">No response submitted.</em>'); ?>
      </div>

      <?php else: ?>
      <!-- Essay / Short Answer / other -->
      <div style="font-size:12px;color:#495057;font-weight:600;margin-bottom:4px;">Student Answer:</div>
      <div class="lgur-ans">
        <?php echo ($qd['answer'] !== '' ? $qd['answer'] : '<em style="color:#999;">No response submitted.</em>'); ?>
      </div>
      <?php endif; ?>

      <?php if ($qd['reviewBefore'] !== null): $rb = $qd['reviewBefore']; ?>
      <!-- Continue & Review changed this answer — before (amber) vs. current (above) -->
      <div class="lgur-diff">
        <div class="lgur-diff-label">🔄 Changed during Continue &amp; Review</div>
        <span class="lgur-diff-tag">Previous answer</span>
        <div class="lgur-diff-before">
          <?php if (in_array($qtype, ['multichoice', 'multicheck'])): ?>
            <?php if (!empty($rb['selectedIds']) && !empty($rb['options'])):
                $prevLabels = [];
                foreach ($rb['options'] as $optIdx2 => $opt2) {
                    if (in_array((int)$opt2->id, $rb['selectedIds'])) {
                        $prevLabels[] = chr(65 + $optIdx2) . '. ' . format_text($opt2->answertext, FORMAT_HTML);
                    }
                }
                echo $prevLabels ? implode('<br>', $prevLabels) : '<em style="color:#999;">No option selected.</em>';
            ?>
            <?php else: ?>
              <em style="color:#999;">No option selected.</em>
            <?php endif; ?>
          <?php else: ?>
            <?php echo ($rb['answer'] !== '' ? $rb['answer'] : ($rb['rawAnswer'] !== '' ? s($rb['rawAnswer']) : '<em style="color:#999;">No response submitted.</em>')); ?>
          <?php endif; ?>
          <?php if ($rb['fraction'] !== null): ?>
          <div style="font-size:11px;color:#a15c00;margin-top:4px;">
            Previous score: <?php echo number_format($rb['fraction'] * (float)($slot->maxmark ?? 1), 3); ?> / <?php echo number_format((float)($slot->maxmark ?? 1), 2); ?>
          </div>
          <?php endif; ?>
        </div>
        <span class="lgur-diff-after-tag">Current answer (above)</span>
      </div>
      <?php endif; ?>

      <?php if ($qd['grade'] !== null): ?>
      <div class="lgur-grade">
        ✏️ Manual Grade: <strong><?php echo number_format($qd['grade'], 2); ?> / <?php echo number_format((float)($slot->maxmark ?? 1), 2); ?></strong>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <?php if ($isManager && !empty($breakdownRows)): ?>
    <!-- Score breakdown (manager only) — helps diagnose score discrepancies -->
    <div class="lgur-breakdown">
      <strong>📊 Score Breakdown</strong> (stored fractions from DB)
      <table>
        <tr><th>Slot</th><th>Type</th><th>Max Mark</th><th>Stored Fraction</th><th>Contribution</th></tr>
        <?php foreach ($breakdownRows as $br): ?>
        <tr>
          <td><?php echo (int)$br['slot']; ?></td>
          <td><?php echo htmlspecialchars($br['qtype']); ?></td>
          <td><?php echo number_format($br['maxmark'], 4); ?></td>
          <td><?php echo $br['fraction'] !== null ? number_format($br['fraction'], 6) : '<em>null</em>'; ?></td>
          <td><?php echo $br['contrib'] !== null ? number_format($br['contrib'], 4) : '<em>—</em>'; ?></td>
        </tr>
        <?php endforeach; ?>
        <tr style="font-weight:700;border-top:2px solid #adb5bd;">
          <td colspan="2">Total</td>
          <td><?php echo number_format($totalMax, 4); ?></td>
          <td>—</td>
          <td><?php echo number_format($totalRaw, 4); ?></td>
        </tr>
        <tr style="font-weight:700;color:#0a4d8c;">
          <td colspan="4">Scaled score (totalraw/totalmax × <?php echo number_format((float)$lg->grade, 2); ?>)</td>
          <td><?php echo $totalMax > 0 ? number_format(($totalRaw / $totalMax) * (float)$lg->grade, 5) : '—'; ?></td>
        </tr>
      </table>
    </div>
    <?php endif; ?>

    <?php endif; ?>
  </div><!-- /lgur-acc-body -->

</div><!-- /lgur-acc-item -->
<?php endforeach; ?>

<?php if ($pendingGrant): ?>
<!-- Extra attempt granted (Assessment Manager) but not yet started by the student. -->
<div class="lgur-acc-item">
  <div class="lgur-acc-hdr" style="cursor:default;">
    <span class="lgur-att-num pending-grant">
      Attempt <?php echo (int)$pendingGrant['nextattemptnum']; ?>
    </span>
    <span class="lgur-badge pending">⏳ Granted — not started</span>
  </div>
  <div class="lgur-placeholder-body">
    An additional attempt was granted for this student on this assessment, but they have
    not started it yet. This card will fill in with question detail once they begin.
  </div>
</div>
<?php endif; ?>

</div><!-- /lgur-wrap -->

<?php if (!empty($checkInEvents)): ?>
<div style="background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,0.08);padding:24px 28px;margin:24px 0;">
    <h4 style="margin:0 0 16px;font-size:16px;color:#343a40;">🔔 Check-in Pop-up History</h4>
    <p style="color:#6c757d;font-size:13px;margin:0 0 14px;">
        These entries show each time the check-in dialog was shown to the student, and the choice they made.
    </p>
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <thead>
            <tr style="background:#f8f9fa;">
                <th style="padding:8px 12px;text-align:left;border-bottom:2px solid #dee2e6;">Time</th>
                <th style="padding:8px 12px;text-align:left;border-bottom:2px solid #dee2e6;">Attempt</th>
                <th style="padding:8px 12px;text-align:left;border-bottom:2px solid #dee2e6;">Event</th>
                <th style="padding:8px 12px;text-align:left;border-bottom:2px solid #dee2e6;">Detail</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $attemptNumMap = [];
        foreach ($attempts as $att) { $attemptNumMap[(int)$att->id] = (int)$att->attempt; }
        $ciLabels = [
            'checkin_prompted'  => ['🔔 Dialog Shown',      '#fd7e14'],
            'checkin_continued' => ['▶ Continued',          '#28a745'],
            'checkin_paused'    => ['⏸ Paused Assessment',  '#dc3545'],
            'checkin_dismissed' => ['🚫 Don\'t Show Again', '#6c757d'],
            'self_paused'       => ['⏸ Self-Paused',        '#dc3545'],
        ];
        foreach ($checkInEvents as $ciev):
            $attNum = $attemptNumMap[(int)$ciev->attemptid] ?? '?';
            $label  = $ciLabels[$ciev->event_type] ?? [$ciev->event_type, '#6c757d'];
            $d      = @json_decode($ciev->event_data, true) ?: [];
            $detail = '';
            if ($ciev->event_type === 'checkin_prompted' && isset($d['count'])) {
                $detail = 'After ' . (int)$d['count'] . ' violations';
            }
        ?>
        <tr style="border-bottom:1px solid #f0f0f0;">
            <td style="padding:8px 12px;color:#495057;"><?php echo userdate($ciev->timemodified, '%d %b %Y %H:%M:%S'); ?></td>
            <td style="padding:8px 12px;">Attempt <?php echo (int)$attNum; ?></td>
            <td style="padding:8px 12px;">
                <span style="background:<?php echo s($label[1]); ?>;color:#fff;border-radius:12px;padding:2px 10px;font-size:12px;font-weight:700;white-space:nowrap;">
                    <?php echo s($label[0]); ?>
                </span>
            </td>
            <td style="padding:8px 12px;color:#6c757d;font-size:12px;"><?php echo s($detail); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php else: ?>
<div style="background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,0.08);padding:20px 28px;margin:24px 0;color:#6c757d;font-size:13px;">
    🔔 No check-in pop-up events recorded for this student.
</div>
<?php endif; ?>

<script>
function lgurToggle(id) {
    var body  = document.getElementById(id);
    var hdr   = document.getElementById(id + '-hdr');
    var arrow = document.getElementById(id + '-arrow');
    if (!body) { return; }
    var opening = !body.classList.contains('open');
    body.classList.toggle('open', opening);
    if (hdr)   { hdr.classList.toggle('open', opening); }
    if (arrow) { arrow.textContent = opening ? '▲' : '▼'; }
}
function lgurExpandAll() {
    document.querySelectorAll('.lgur-acc-body').forEach(function(b) {
        b.classList.add('open');
        var hdr = document.getElementById(b.id + '-hdr');
        if (hdr) { hdr.classList.add('open'); }
        var arrow = document.getElementById(b.id + '-arrow');
        if (arrow) { arrow.textContent = '▲'; }
    });
}
function lgurCollapseAll() {
    document.querySelectorAll('.lgur-acc-body').forEach(function(b) {
        b.classList.remove('open');
        var hdr = document.getElementById(b.id + '-hdr');
        if (hdr) { hdr.classList.remove('open'); }
        var arrow = document.getElementById(b.id + '-arrow');
        if (arrow) { arrow.textContent = '▼'; }
    });
}
function lgurPrintAttempt(accId) {
    // Expand the target attempt if collapsed.
    var body = document.getElementById(accId);
    if (body && !body.classList.contains('open')) { lgurToggle(accId); }
    // Mark all acc-items as hidden except the one we want, then print, then restore.
    document.querySelectorAll('.lgur-acc-item').forEach(function(item) {
        item.classList.remove('lgur-printing');
    });
    if (body) {
        var item = body.closest ? body.closest('.lgur-acc-item') : body.parentElement;
        if (item) { item.classList.add('lgur-printing'); }
    }
    document.body.classList.add('lgur-print-one');
    window.print();
    document.body.classList.remove('lgur-print-one');
    document.querySelectorAll('.lgur-acc-item').forEach(function(item) {
        item.classList.remove('lgur-printing');
    });
}
</script>

<?php echo $OUTPUT->footer();
