<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * LearnGuard Essay Grading Interface.
 *
 * Supports first & second graders with optional blind/anonymous marking.
 * URL: /mod/learnguard/grader.php?id=CMID
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id         = required_param('id',        PARAM_INT);
$attemptid  = optional_param('attemptid', 0,    PARAM_INT);
$action     = optional_param('action',    '',   PARAM_ALPHA);

$cm      = get_coursemodule_from_id('learnguard', $id, 0, false, MUST_EXIST);
$course  = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$lg      = $DB->get_record('learnguard', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);

// Capability check: must be assigned as a grader OR be a manager.
$isManager = has_capability('mod/learnguard:manage', $context);
$myGrader  = $DB->get_record('learnguard_graders', ['learnguard' => $cm->instance, 'userid' => $USER->id]);
$isGrader  = $isManager || !empty($myGrader);

if (!$isGrader) {
    throw new moodle_exception('accessdenied', 'error', '', 'You are not assigned as a grader for this assessment.');
}

$anonymous = !empty($myGrader->anonymous) && !$isManager; // Managers always see names.
$gradeLayer = $myGrader ? (int)$myGrader->gradelayer : 1;

$PAGE->set_url('/mod/learnguard/grader.php', ['id' => $id, 'attemptid' => $attemptid]);
$PAGE->set_context($context);
$PAGE->set_title(format_string($lg->name) . ' — Grader');
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->css('/mod/learnguard/styles.css');

$brand = learnguard_brand();

// Handle grade submission — one score/feedback pair per essay slot, keyed by slotid.
if ($action === 'grade' && $attemptid && confirm_sesskey()) {
    $grades    = optional_param_array('grade',    [], PARAM_FLOAT);
    $feedbacks = optional_param_array('feedback', [], PARAM_RAW);

    foreach ($grades as $gradeslotid => $gradeVal) {
        $gradeslotid = (int)$gradeslotid;
        $feedback    = $feedbacks[$gradeslotid] ?? '';

        $existing = $DB->get_record('learnguard_essay_grades', [
            'attemptid' => $attemptid,
            'slotid'    => $gradeslotid,
            'graderid'  => $USER->id,
        ]);
        if ($existing) {
            $DB->update_record('learnguard_essay_grades', (object)[
                'id'        => $existing->id,
                'grade'     => $gradeVal,
                'feedback'  => $feedback,
                'timegraded'=> time(),
            ]);
        } else {
            $DB->insert_record('learnguard_essay_grades', (object)[
                'attemptid' => $attemptid,
                'slotid'    => $gradeslotid,
                'graderid'  => $USER->id,
                'grade'     => $gradeVal,
                'feedback'  => $feedback,
                'timegraded'=> time(),
            ]);
        }

        // Feed this grade into the response's fraction so it counts towards the
        // attempt's total — whichever grader saves last becomes the official grade.
        $slotrow  = $DB->get_record('learnguard_slots', ['id' => $gradeslotid]);
        $maxmark  = $slotrow ? (float)$slotrow->maxmark : 0.0;
        $fraction = ($maxmark > 0) ? min(1.0, max(0.0, $gradeVal / $maxmark)) : 0.0;
        $DB->set_field('learnguard_responses', 'fraction', $fraction,
            ['attemptid' => $attemptid, 'slotid' => $gradeslotid]);
        $DB->set_field('learnguard_responses', 'state', 'complete',
            ['attemptid' => $attemptid, 'slotid' => $gradeslotid]);
        $DB->set_field('learnguard_responses', 'timemodified', time(),
            ['attemptid' => $attemptid, 'slotid' => $gradeslotid]);
    }

    // Recalculate and store the attempt's overall grade.
    $attempt = $DB->get_record('learnguard_attempts', ['id' => $attemptid]);
    if ($attempt && $attempt->state === 'finished') {
        require_once($CFG->libdir . '/gradelib.php');
        $newgrade = \mod_learnguard\question_engine::calculate_total_grade($attemptid, $lg);
        $DB->set_field('learnguard_attempts', 'sumgrades', $newgrade, ['id' => $attemptid]);
        grade_update('mod/learnguard', $lg->course, 'mod', 'learnguard',
            $lg->id, 0, [(object)['userid' => $attempt->userid, 'rawgrade' => $newgrade]]);
    }

    redirect(new moodle_url('/mod/learnguard/grader.php', ['id' => $id, 'attemptid' => $attemptid]),
        '✅ Grade saved.', null, \core\output\notification::NOTIFY_SUCCESS);
}

// Fetch all attempts with essay questions.
// A slot is an essay question linked to this assessment.
$attempts = $DB->get_records_sql(
    "SELECT a.id, a.userid, a.state, a.timestart, a.timefinish, a.risk_score, a.risk_level,
            u.firstname, u.lastname, u.email,
            (SELECT COUNT(*) FROM {learnguard_essay_grades} eg WHERE eg.attemptid = a.id AND eg.graderid = :gid) AS my_graded
       FROM {learnguard_attempts} a
       JOIN {user} u ON u.id = a.userid
      WHERE a.learnguard = :lgid AND a.state = 'finished'
   ORDER BY a.timefinish DESC",
    ['lgid' => $cm->instance, 'gid' => $USER->id]
);

// Load essay slots for this assessment.
$essaySlots = $DB->get_records_sql(
    "SELECT s.id AS slotid, s.slot, q.questiontext, s.maxmark
       FROM {learnguard_slots} s
       JOIN {learnguard_qbank} q ON q.id = s.questionid
      WHERE s.learnguard = :lgid AND q.qtype = 'essay'
      ORDER BY s.slot",
    ['lgid' => $cm->instance]
);

echo $OUTPUT->header();
echo learnguard_activity_tabs($id, 'grader');
?>

<style>
.gr-wrap      { font-family:"Segoe UI",system-ui,sans-serif;max-width:1100px;margin:0 auto;padding:0 16px 40px; }
.gr-header    { background:<?php echo $brand->primary; ?>;color:<?php echo $brand->textcolor; ?>;padding:16px 24px;border-radius:10px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px; }
.gr-card      { background:#fff;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.08);margin-bottom:14px;overflow:hidden; }
.gr-card-head { padding:12px 16px;background:#f8f9fa;border-bottom:1px solid #dee2e6;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px; }
.gr-badge     { padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;color:#fff; }
.gr-q-block   { padding:16px;border-bottom:1px solid #f5f5f5; }
.gr-q-text    { font-weight:600;color:#212529;margin-bottom:8px;font-size:15px; }
.gr-answer    { background:#f8f9fa;border-radius:6px;padding:12px;font-size:14px;line-height:1.7;color:#495057;min-height:60px;white-space:pre-wrap;margin-bottom:12px; }
.gr-score-row { display:flex;align-items:center;gap:12px;flex-wrap:wrap; }
.gr-star-row  { display:flex;gap:4px;align-items:center; }
.gr-star      { width:36px;height:36px;cursor:pointer;border:2px solid #dee2e6;border-radius:6px;font-size:18px;background:#fff;transition:.15s;display:flex;align-items:center;justify-content:center; }
.gr-star.active { background:#fd7e14;border-color:#fd7e14;color:#fff; }
.gr-grader-note { background:#d4edda;border-radius:6px;padding:8px 12px;font-size:12px;color:#155724;margin-top:6px; }
.gr-second-grade{ background:#f0f4ff;border-radius:6px;padding:8px 12px;font-size:12px;color:#0a4d8c;margin-top:6px; }
</style>

<div class="gr-wrap">

<div class="gr-header">
  <div>
    <div style="font-size:18px;font-weight:800;">✏️ Essay Grading Panel</div>
    <div style="font-size:13px;opacity:.85;"><?php echo format_string($lg->name); ?></div>
  </div>
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <?php if ($anonymous): ?>
    <span style="background:rgba(255,255,255,.2);padding:4px 12px;border-radius:10px;font-size:12px;">👤 Anonymous marking ON</span>
    <?php endif; ?>
    <span style="background:rgba(255,255,255,.2);padding:4px 12px;border-radius:10px;font-size:12px;">
      🏷 Layer <?php echo $gradeLayer; ?> Grader
    </span>
    <a href="<?php echo new moodle_url('/mod/learnguard/proctorview.php'); ?>" class="btn btn-sm" style="background:rgba(255,255,255,.2);color:#fff;border:1px solid rgba(255,255,255,.4);">← Back</a>
  </div>
</div>

<?php if (empty($essaySlots)): ?>
<div class="alert alert-info">No essay questions found for this assessment. Add essay-type questions to use this grading panel.</div>
<?php elseif ($attemptid): ?>

<?php
// Single attempt grading view.
$attempt = $DB->get_record('learnguard_attempts', ['id' => $attemptid], '*', MUST_EXIST);
$student = $DB->get_record('user', ['id' => $attempt->userid], '*', MUST_EXIST);
$displayName = $anonymous ? 'Candidate #' . $attempt->id : fullname($student);

// Load all responses for this attempt.
$responses = $DB->get_records('learnguard_responses',
    ['attemptid' => $attemptid], 'slotid ASC', 'slotid, responsedata, fraction');

// Load previous grades by other graders.
$allGrades = $DB->get_records_sql(
    "SELECT eg.*, u.firstname, u.lastname, gr.gradelayer, gr.anonymous
       FROM {learnguard_essay_grades} eg
       JOIN {user} u ON u.id = eg.graderid
       LEFT JOIN {learnguard_graders} gr ON gr.userid = eg.graderid AND gr.learnguard = :lgid
      WHERE eg.attemptid = :aid",
    ['lgid' => $cm->instance, 'aid' => $attemptid]
);

// Organise by slotid.
$gradesBySlot = [];
foreach ($allGrades as $g) {
    $gradesBySlot[$g->slotid][] = $g;
}

$rcolours = ['low' => '#28a745', 'medium' => '#fd7e14', 'high' => '#dc3545'];
$rc = $rcolours[$attempt->risk_level ?? 'low'] ?? '#6c757d';
?>

<div class="gr-card" style="margin-bottom:20px;">
  <div class="gr-card-head">
    <div>
      <strong style="font-size:16px;"><?php echo $displayName; ?></strong>
      <?php if (!$anonymous): ?>
      <div style="font-size:12px;color:#6c757d;"><?php echo s($student->email); ?></div>
      <?php endif; ?>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <span class="gr-badge" style="background:<?php echo $rc; ?>;">
        Risk: <?php echo $attempt->risk_score ?? 0; ?> <?php echo strtoupper($attempt->risk_level ?? 'low'); ?>
      </span>
      <span style="font-size:12px;color:#6c757d;">
        Submitted: <?php echo $attempt->timefinish ? userdate($attempt->timefinish, '%d/%m/%y %H:%M') : 'In progress'; ?>
      </span>
      <?php if (!$anonymous): ?>
      <a href="<?php echo new moodle_url('/mod/learnguard/attemptdetail.php', ['id' => $id, 'attempt' => $attemptid]); ?>"
         class="btn btn-sm btn-outline-secondary">🔍 Full Report</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<form method="post" action="<?php echo new moodle_url('/mod/learnguard/grader.php', ['id' => $id, 'attemptid' => $attemptid, 'action' => 'grade', 'sesskey' => sesskey()]); ?>">
<?php foreach ($essaySlots as $slot):
    $resp = $responses[$slot->slotid] ?? null;
    $answer = null;
    if ($resp) {
        $decoded = json_decode($resp->responsedata, true) ?? [];
        $answer  = $decoded["q{$slot->slot}_answer"] ?? null;
    }
    $myGrade = null;
    foreach (($gradesBySlot[$slot->slotid] ?? []) as $g) {
        if ($g->graderid == $USER->id) { $myGrade = $g; break; }
    }
    $otherGrades = array_filter($gradesBySlot[$slot->slotid] ?? [], fn($g) => $g->graderid != $USER->id);
    $maxScore    = (float)($slot->maxmark ?: 10);
?>
<div class="gr-card">
  <div class="gr-card-head">
    <div style="font-weight:700;font-size:13px;">Question <?php echo (int)$slot->slot; ?></div>
    <div style="font-size:12px;color:#6c757d;">Max: <?php echo $maxScore; ?> pts</div>
  </div>
  <div class="gr-q-block">
    <div class="gr-q-text"><?php echo format_text($slot->questiontext, FORMAT_HTML); ?></div>
    <div class="gr-answer"><?php echo ($answer !== null && $answer !== '') ? nl2br(s($answer)) : '<em style="color:#adb5bd;">No response submitted</em>'; ?></div>

    <!-- My grade form -->
    <div class="gr-score-row">
      <div>
        <label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px;">Your Score (0–<?php echo $maxScore; ?>)</label>
        <input type="number" name="grade[<?php echo $slot->slotid; ?>]" id="gr-score-<?php echo $slot->slotid; ?>"
               min="0" max="<?php echo $maxScore; ?>" step="0.5"
               value="<?php echo $myGrade ? $myGrade->grade : ''; ?>"
               style="width:80px;border:2px solid #dee2e6;border-radius:6px;padding:6px 10px;font-size:16px;font-weight:700;text-align:center;"
               required>
        <!-- Quick score buttons -->
        <div style="display:flex;gap:4px;margin-top:6px;flex-wrap:wrap;">
          <?php for ($pct = 0; $pct <= 100; $pct += 25):
              $val = round($maxScore * $pct / 100, 1); ?>
          <button type="button" onclick="document.getElementById('gr-score-<?php echo $slot->slotid; ?>').value='<?php echo $val; ?>'"
            style="padding:3px 10px;border:1px solid #dee2e6;border-radius:10px;cursor:pointer;font-size:12px;background:#f8f9fa;color:#495057;">
            <?php echo $pct; ?>%
          </button>
          <?php endfor; ?>
        </div>
      </div>
    </div>

    <div style="margin-top:10px;">
      <label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px;">Feedback (optional)</label>
      <textarea name="feedback[<?php echo $slot->slotid; ?>]" rows="3"
        style="width:100%;border:1px solid #dee2e6;border-radius:6px;padding:8px 12px;font-size:13px;resize:vertical;box-sizing:border-box;"
        placeholder="Feedback shown to student after results are released…"><?php echo $myGrade ? s($myGrade->feedback) : ''; ?></textarea>
    </div>

    <?php if ($myGrade): ?>
    <div class="gr-grader-note">✅ You graded this question: <strong><?php echo $myGrade->grade; ?></strong>/<?php echo $maxScore; ?> pts</div>
    <?php endif; ?>

    <!-- Other graders' grades (Layer 2 / admin only) -->
    <?php if ($isManager && !empty($otherGrades)): ?>
    <?php foreach ($otherGrades as $og):
        $graderName = !empty($og->anonymous) ? 'Grader (anonymous)' : fullname((object)['firstname'=>$og->firstname,'lastname'=>$og->lastname]);
        $layer = $og->gradelayer ?: 1;
    ?>
    <div class="gr-second-grade">
      <strong>Layer <?php echo $layer; ?> — <?php echo $graderName; ?>:</strong>
      <?php echo $og->grade; ?>/<?php echo $maxScore; ?> pts
      <?php if ($og->feedback): ?>
      <br><em style="color:#6c757d;"><?php echo s($og->feedback); ?></em>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>

<div style="text-align:right;margin-top:20px;">
  <a href="<?php echo new moodle_url('/mod/learnguard/grader.php', ['id' => $id]); ?>"
     class="btn btn-outline-secondary">← Back to List</a>
  &nbsp;
  <button type="submit" class="btn btn-primary btn-lg" style="min-width:160px;">
    ✅ Save All Grades
  </button>
</div>
</form>

<?php else: ?>

<!-- Attempt list view -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:8px;">
  <h3 style="margin:0;font-size:17px;">
    Finished Attempts
    <span style="background:#0f6cbf;color:#fff;border-radius:10px;padding:2px 10px;font-size:13px;margin-left:8px;"><?php echo count($attempts); ?></span>
  </h3>
  <div style="display:flex;gap:8px;">
    <a href="<?php echo new moodle_url('/mod/learnguard/admin.php', ['id' => $id, 'tab' => 'graders']); ?>"
       class="btn btn-sm btn-outline-secondary">⚙️ Manage Graders</a>
  </div>
</div>

<?php if (empty($attempts)): ?>
<div class="alert alert-info">No finished attempts found. Students must complete the exam before grading becomes available.</div>
<?php else: ?>
<div class="gr-card">
<table class="generaltable w-100" style="font-size:13px;margin:0;">
  <thead>
    <tr>
      <th style="padding:10px 14px;">Candidate</th>
      <th style="padding:10px 14px;">Submitted</th>
      <th style="padding:10px 14px;">Risk</th>
      <th style="padding:10px 14px;">My Progress</th>
      <th style="padding:10px 14px;">Action</th>
    </tr>
  </thead>
  <tbody>
  <?php $rcolours = ['low' => '#28a745', 'medium' => '#fd7e14', 'high' => '#dc3545'];
  foreach ($attempts as $att):
      $displayName = $anonymous ? 'Candidate #' . $att->id : fullname($att);
      $rc = $rcolours[$att->risk_level ?? 'low'] ?? '#6c757d';
      $slotCount   = count($essaySlots);
      $gradedCount = (int)$att->my_graded;
      $allDone     = $gradedCount >= $slotCount;
  ?>
  <tr>
    <td style="padding:10px 14px;">
      <strong><?php echo $displayName; ?></strong>
      <?php if (!$anonymous): ?>
      <div style="font-size:11px;color:#6c757d;"><?php echo s($att->email); ?></div>
      <?php endif; ?>
    </td>
    <td style="padding:10px 14px;">
      <small><?php echo $att->timefinish ? userdate($att->timefinish, '%d/%m/%y %H:%M') : '—'; ?></small>
    </td>
    <td style="padding:10px 14px;">
      <span class="gr-badge" style="background:<?php echo $rc; ?>;">
        <?php echo ($att->risk_score ?? 0); ?>
      </span>
    </td>
    <td style="padding:10px 14px;">
      <div style="display:flex;align-items:center;gap:8px;">
        <div style="background:#e9ecef;border-radius:6px;height:8px;width:80px;overflow:hidden;">
          <div style="height:100%;background:<?php echo $allDone ? '#28a745' : '#0f6cbf'; ?>;width:<?php echo $slotCount > 0 ? round($gradedCount/$slotCount*100) : 0; ?>%;border-radius:6px;"></div>
        </div>
        <span style="font-size:12px;color:#6c757d;"><?php echo $gradedCount; ?>/<?php echo $slotCount; ?></span>
        <?php if ($allDone): ?><span style="font-size:11px;color:#28a745;font-weight:700;">✓ Done</span><?php endif; ?>
      </div>
    </td>
    <td style="padding:10px 14px;">
      <a href="<?php echo new moodle_url('/mod/learnguard/grader.php', ['id' => $id, 'attemptid' => $att->id]); ?>"
         class="btn btn-sm <?php echo $allDone ? 'btn-outline-success' : 'btn-primary'; ?>">
        <?php echo $allDone ? '✏️ Review' : '✏️ Grade'; ?>
      </a>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
<?php endif; ?>

</div><!-- /gr-wrap -->

<?php echo $OUTPUT->footer();
