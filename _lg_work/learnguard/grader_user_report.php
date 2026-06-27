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
    'id, attempt, state, timestart, timefinish, sumgrades'
));

if (empty($attempts)) {
    throw new moodle_exception('invalidattemptid', 'error', '', 'No attempts found for this user.');
}

// For each attempt, load the assigned questions + responses.
$attemptData = [];
foreach ($attempts as $att) {
    $aid = (int)$att->id;

    // Get assigned slot IDs (AQ-based or all response rows as fallback).
    $aqRows = $DB->get_records('learnguard_attempt_questions', ['attemptid' => $aid], 'slotnum ASC');
    if (!empty($aqRows)) {
        $slotIds = array_column(array_values($aqRows), 'slotid');
    } else {
        $respRows = $DB->get_records('learnguard_responses', ['attemptid' => $aid], '', 'slotid');
        $slotIds  = array_column(array_values($respRows), 'slotid');
    }

    // Load slot + question data.
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
            $decoded = ($resp && $resp->responsedata) ? (json_decode($resp->responsedata, true) ?? []) : [];
            $aqRow   = null;
            foreach ($aqRows as $aq) { if ((int)$aq->slotid === (int)$slotid) { $aqRow = $aq; break; } }
            $slotnum = $aqRow ? (int)$aqRow->slotnum : (int)$slot->slot;
            $answer  = $decoded["q{$slotnum}_answer"] ?? '';

            // Decode MCQ answer ID to text.
            $answerDisplay = '';
            $qtype = $slot->qtype ?? 'essay';
            if (in_array($qtype, ['multichoice', 'multicheck']) && is_numeric($answer)) {
                $ans = $DB->get_record('learnguard_qbank_answers', ['id' => (int)$answer], 'answertext, fraction');
                if ($ans) {
                    $answerDisplay = format_text($ans->answertext, FORMAT_HTML) .
                        ($ans->fraction >= 1.0 ? ' <span style="color:#28a745;">✓ Correct</span>' : ' <span style="color:#dc3545;">✗ Incorrect</span>');
                } else {
                    $answerDisplay = '<em>(answer ID ' . (int)$answer . ' not found)</em>';
                }
            } elseif ($qtype === 'shortanswer') {
                $answerDisplay = s($answer);
            } elseif ($qtype === 'essay') {
                $answerDisplay = format_text($answer, FORMAT_HTML);
            } else {
                $answerDisplay = s($answer);
            }

            // Manual grade for this slot.
            $grade = $DB->get_record('learnguard_essay_grades',
                ['attemptid' => $aid, 'slotid' => $slotid], 'grade, graderid');

            $questions[] = [
                'slot'          => $slot,
                'slotnum'       => $slotnum,
                'answer'        => $answerDisplay,
                'state'         => $resp ? $resp->state : null,
                'grade'         => $grade ? (float)$grade->grade : null,
                'graderid'      => $grade ? (int)$grade->graderid : null,
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
.lgur-wrap     { max-width:960px; margin:0 auto; font-family:system-ui,sans-serif; }
.lgur-header   { background:#0f6cbf; color:#fff; padding:16px 24px; border-radius:10px;
                 margin-bottom:20px; display:flex; align-items:center;
                 justify-content:space-between; flex-wrap:wrap; gap:10px; }
.lgur-header h2 { margin:0; font-size:20px; font-weight:700; }
.lgur-card     { background:#fff; border:1px solid #dee2e6; border-radius:10px;
                 padding:20px; margin-bottom:20px; page-break-inside:avoid; }
.lgur-att-hdr  { display:flex; align-items:center; gap:14px; flex-wrap:wrap;
                 margin-bottom:14px; padding-bottom:10px; border-bottom:2px solid #f0f0f0; }
.lgur-att-num  { background:#0f6cbf; color:#fff; border-radius:6px; padding:4px 14px;
                 font-weight:700; font-size:15px; }
.lgur-att-num.voided { background:#6c757d; }
.lgur-meta     { font-size:12px; color:#6c757d; }
.lgur-score    { margin-left:auto; font-weight:700; font-size:16px; }
.lgur-q        { border:1px solid #e9ecef; border-radius:8px; padding:14px 16px;
                 margin-bottom:12px; background:#fafafa; }
.lgur-q-hdr    { font-weight:700; font-size:13px; color:#0f6cbf; margin-bottom:8px; }
.lgur-qtext    { font-size:14px; margin-bottom:10px; color:#212529; }
.lgur-ans      { background:#fff; border:1px solid #dee2e6; border-radius:6px;
                 padding:10px 14px; font-size:14px; min-height:36px; }
.lgur-grade    { margin-top:8px; font-size:12px; color:#6c757d; }
.lgur-badge    { display:inline-block; border-radius:12px; padding:2px 10px;
                 font-size:11px; font-weight:700; }
.lgur-badge.graded  { background:#d4edda; color:#155724; }
.lgur-badge.pending { background:#fff3cd; color:#856404; }
.lgur-badge.blank   { background:#e2e3e5; color:#383d41; }
.lgur-badge.voided  { background:#f8d7da; color:#721c24; }
@media print {
    .lgur-noprint { display:none !important; }
    .lgur-header  { background:#0f6cbf !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .lgur-card    { page-break-inside:avoid; border:1px solid #ccc !important; }
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
    $att   = $adata['attempt'];
    $qs    = $adata['questions'];
    $isVoided = $att->state === 'voided';
    $started  = $att->timestart  ? userdate((int)$att->timestart,  get_string('strftimedatetimeshort')) : '—';
    $finished = $att->timefinish ? userdate((int)$att->timefinish, get_string('strftimedatetimeshort')) : '—';
    $sumG     = $att->sumgrades !== null ? number_format((float)$att->sumgrades, 2) . ' / ' . number_format((float)$lg->grade, 2) : '— (pending)';
?>
<div class="lgur-card">
  <div class="lgur-att-hdr">
    <span class="lgur-att-num <?php echo $isVoided ? 'voided' : ''; ?>">
      Attempt <?php echo (int)$att->attempt; ?><?php echo $isVoided ? ' (VOIDED)' : ''; ?>
    </span>
    <div class="lgur-meta">
      <div>Started: <strong><?php echo $started; ?></strong></div>
      <div>Finished: <strong><?php echo $finished; ?></strong></div>
    </div>
    <div class="lgur-score"><?php echo $isVoided ? '<span class="lgur-badge voided">Voided</span>' : s($sumG); ?></div>
  </div>

  <?php if (empty($qs)): ?>
    <p style="color:#6c757d;font-size:13px;">No question assignment records for this attempt.</p>
  <?php else: ?>
  <?php foreach ($qs as $qi => $qd):
      $slot  = $qd['slot'];
      $qtype = $slot->qtype ?? 'essay';
      $state = $qd['state'];
      if ($state === 'graded') { $badgeCls = 'graded'; $badgeLbl = '✓ Graded'; }
      elseif ($state === 'needsgrading') { $badgeCls = 'pending'; $badgeLbl = '⏳ Pending'; }
      elseif ($qd['answer'] === '') { $badgeCls = 'blank'; $badgeLbl = 'Blank'; }
      else { $badgeCls = 'pending'; $badgeLbl = '⏳ Pending'; }
  ?>
  <div class="lgur-q">
    <div class="lgur-q-hdr">
      Q<?php echo ($qi + 1); ?>
      &nbsp;<span class="lgur-badge" style="background:#e8f4fd;color:#0a4d8c;font-size:10px;vertical-align:middle;">
        <?php echo htmlspecialchars(ucfirst($qtype)); ?>
      </span>
      &nbsp;/ <?php echo number_format((float)($slot->maxmark ?? 1), 2); ?> mark(s)
      &nbsp;<span class="lgur-badge <?php echo $badgeCls; ?>"><?php echo $badgeLbl; ?></span>
    </div>
    <div class="lgur-qtext">
      <?php echo format_text($slot->questiontext ?? '<em>(Question text unavailable)</em>', FORMAT_HTML); ?>
    </div>
    <div style="font-size:12px;color:#495057;font-weight:600;margin-bottom:4px;">Student Answer:</div>
    <div class="lgur-ans">
      <?php echo ($qd['answer'] !== '' ? $qd['answer'] : '<em style="color:#999;">No response submitted.</em>'); ?>
    </div>
    <?php if ($qd['grade'] !== null): ?>
    <div class="lgur-grade">
      ✏️ Grade: <strong><?php echo number_format($qd['grade'], 2); ?> / <?php echo number_format((float)($slot->maxmark ?? 1), 2); ?></strong>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>
<?php endforeach; ?>

</div><!-- /lgur-wrap -->

<?php echo $OUTPUT->footer();
