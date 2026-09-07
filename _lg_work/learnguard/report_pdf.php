<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * PDF report for a LearnGuard attempt.
 *
 * Generates a printable HTML report (opens print dialog) containing:
 *  - Student details and attempt summary
 *  - Score and grade
 *  - Risk assessment summary
 *  - Key proctoring events
 *  - Per-question results
 *
 * URL: /mod/learnguard/report_pdf.php?id={cmid}&attempt={attemptid}
 *
 * This page outputs a clean print-ready HTML page and auto-triggers the browser
 * print dialog. Users can print or save as PDF from the browser.
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
$brand   = learnguard_brand();

// Fetch data.
$events = $DB->get_records_sql(
    "SELECT event_type, severity, score_impact, timemodified FROM {learnguard_events}
      WHERE attemptid = :aid AND severity IN ('high','medium') ORDER BY timemodified ASC LIMIT 50",
    ['aid' => $attemptid]
);

$aqs = $DB->get_records_sql(
    "SELECT aq.slotnum, q.name, q.qtype, r.fraction, r.state, s.maxmark
       FROM {learnguard_attempt_questions} aq
       JOIN {learnguard_qbank} q    ON q.id = aq.questionid
       JOIN {learnguard_slots} s    ON s.id = aq.slotid
  LEFT JOIN {learnguard_responses} r ON r.attemptid = aq.attemptid AND r.slotid = aq.slotid
      WHERE aq.attemptid = :aid ORDER BY aq.slotnum",
    ['aid' => $attemptid]
);

$maxgrade = (float)$instance->grade;
$score    = (float)$attempt->sumgrades;
$pct      = $maxgrade > 0 ? round(($score / $maxgrade) * 100, 1) : 0;
$duration = $attempt->timefinish ? ($attempt->timefinish - $attempt->timestart) : 0;

$sevCol = ['low' => '#28a745', 'medium' => '#fd7e14', 'high' => '#dc3545'];
$rc     = $sevCol[$attempt->risk_level] ?? '#6c757d';

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Attempt Report — <?php echo s(fullname($student)); ?></title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: "Segoe UI", Arial, sans-serif; font-size: 12px; color: #212529; background: #fff; padding: 0; }
.page { max-width: 800px; margin: 0 auto; padding: 24px; }
.header { background: <?php echo $brand->primary; ?>; color: #fff; padding: 20px 24px; border-radius: 6px 6px 0 0; margin-bottom: 0; }
.header h1 { font-size: 18px; margin-bottom: 4px; }
.header p  { font-size: 12px; opacity: 0.85; margin: 0; }
.section { border: 1px solid #dee2e6; border-radius: 6px; margin-bottom: 16px; overflow: hidden; }
.section-head { background: #f8f9fa; border-bottom: 1px solid #dee2e6; padding: 8px 14px; font-weight: 700; font-size: 13px; }
.section-body { padding: 12px 14px; }
table { width: 100%; border-collapse: collapse; }
th { background: #f8f9fa; padding: 6px 10px; text-align: left; font-size: 11px; border-bottom: 1px solid #dee2e6; }
td { padding: 6px 10px; border-bottom: 1px solid #f5f5f5; font-size: 12px; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 700; color: #fff; }
.score-circle { width: 80px; height: 80px; border-radius: 50%; background: <?php echo $pct >= 70 ? '#28a745' : ($pct >= 50 ? '#fd7e14' : '#dc3545'); ?>;
  color: #fff; display: flex; align-items: center; justify-content: center; flex-direction: column; margin: 0 auto; }
.info-row { display: flex; gap: 16px; flex-wrap: wrap; }
.info-item { flex: 1; min-width: 120px; background: #f8f9fa; border-radius: 6px; padding: 10px 12px; text-align: center; }
.info-val { font-size: 18px; font-weight: 700; }
footer { text-align: center; color: #adb5bd; font-size: 10px; margin-top: 20px; padding-top: 10px; border-top: 1px solid #dee2e6; }
@media print { body { padding: 0; } .no-print { display: none; } }
</style>
</head>
<body>
<div class="page">

<div class="no-print" style="background:#fff3cd;padding:10px 16px;border-radius:6px;margin-bottom:16px;font-size:13px;">
  📄 <strong>Print or Save as PDF:</strong> Use your browser's Print function (Ctrl+P / Cmd+P), then select "Save as PDF".
  <button onclick="window.print()" style="margin-left:12px;background:#0f6cbf;color:#fff;border:none;border-radius:4px;padding:4px 12px;cursor:pointer;">🖨 Print Now</button>
</div>

<!-- Header -->
<div class="header">
  <h1>🛡 <?php echo htmlspecialchars(get_config('learnguard','brand_name') ?: 'LearnGuard'); ?> — Attempt Report</h1>
  <p><?php echo s(format_string($instance->name)); ?> &nbsp;|&nbsp; <?php echo s(format_string($course->fullname)); ?></p>
  <p>Generated: <?php echo userdate(time()); ?></p>
</div>

<!-- Student + Score -->
<div style="display:flex;gap:16px;border:1px solid #dee2e6;border-top:none;padding:16px;border-radius:0 0 6px 6px;margin-bottom:16px;">
  <div style="flex:1;">
    <strong style="font-size:15px;"><?php echo s(fullname($student)); ?></strong><br>
    <span style="color:#6c757d;"><?php echo s($student->email); ?></span><br><br>
    <table style="width:auto;">
      <tr><td style="padding:2px 20px 2px 0;color:#6c757d;">Attempt</td><td><strong>#<?php echo $attempt->attempt; ?></strong></td></tr>
      <tr><td style="padding:2px 20px 2px 0;color:#6c757d;">State</td><td><strong><?php echo ucfirst($attempt->state); ?></strong></td></tr>
      <tr><td style="padding:2px 20px 2px 0;color:#6c757d;">Started</td><td><?php echo $attempt->timestart ? userdate($attempt->timestart) : '—'; ?></td></tr>
      <?php if ($attempt->timefinish): ?>
      <tr><td style="padding:2px 20px 2px 0;color:#6c757d;">Finished</td><td><?php echo userdate($attempt->timefinish); ?></td></tr>
      <tr><td style="padding:2px 20px 2px 0;color:#6c757d;">Duration</td><td><?php echo gmdate('H:i:s', $duration); ?></td></tr>
      <?php endif; ?>
      <tr><td style="padding:2px 20px 2px 0;color:#6c757d;">IP Address</td><td><?php echo s($attempt->ip_address); ?></td></tr>
    </table>
  </div>
  <div style="text-align:center;min-width:120px;">
    <div class="score-circle">
      <div style="font-size:22px;font-weight:900;"><?php echo $pct; ?>%</div>
      <div style="font-size:10px;opacity:.8;"><?php echo round($score,1); ?>/<?php echo $maxgrade; ?></div>
    </div>
    <div style="margin-top:8px;font-size:11px;color:#6c757d;">Final Score</div>
    <div style="margin-top:6px;">
      <span class="badge" style="background:<?php echo $rc; ?>;"><?php echo $attempt->risk_score; ?> Risk</span>
    </div>
  </div>
</div>

<!-- Proctoring Events -->
<?php if (!empty($events)): ?>
<div class="section">
  <div class="section-head">⚠️ Notable Proctoring Events (<?php echo count($events); ?>)</div>
  <div class="section-body" style="padding:0;">
    <table>
      <tr><th>Event</th><th>Severity</th><th>Score Impact</th><th>Time</th></tr>
      <?php foreach ($events as $e):
          $sc = $sevCol[$e->severity] ?? '#6c757d';
      ?>
      <tr>
        <td><?php echo s(str_replace('_', ' ', ucfirst($e->event_type))); ?></td>
        <td><span class="badge" style="background:<?php echo $sc; ?>;"><?php echo strtoupper($e->severity); ?></span></td>
        <td><?php echo $e->score_impact > 0 ? '+' . $e->score_impact : '0'; ?></td>
        <td><?php echo userdate($e->timemodified, '%H:%M:%S'); ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Question Results -->
<div class="section">
  <div class="section-head">📝 Question Results</div>
  <div class="section-body" style="padding:0;">
    <table>
      <tr><th>#</th><th>Question</th><th>Type</th><th>Max</th><th>Score</th><th>%</th><th>Status</th></tr>
      <?php foreach ($aqs as $aq):
          $mark    = (float)$aq->maxmark;
          $earned  = $aq->fraction !== null ? round((float)$aq->fraction * $mark, 2) : 0;
          $qpct    = $mark > 0 ? round($earned / $mark * 100) : 0;
          $stateCol = in_array($aq->state, ['complete','needsgrading','graded']) ? '#28a745' : '#dc3545';
      ?>
      <tr>
        <td><?php echo $aq->slotnum; ?></td>
        <td style="max-width:200px;"><?php echo s(format_string($aq->name)); ?></td>
        <td><?php echo s(ucfirst($aq->qtype)); ?></td>
        <td style="text-align:center;"><?php echo $mark; ?></td>
        <td style="text-align:center;font-weight:700;"><?php echo $earned; ?></td>
        <td style="text-align:center;"><?php echo $qpct; ?>%</td>
        <td><span class="badge" style="background:<?php echo $stateCol; ?>;"><?php echo s(ucfirst($aq->state ?? 'todo')); ?></span></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>

<footer>
  LearnGuard Assessment Platform &nbsp;|&nbsp; Developed by Michael Adeniran &nbsp;|&nbsp; <?php echo date('Y'); ?>
</footer>
</div>
<script>
// Auto-open print dialog after 1 second.
setTimeout(function() { /* window.print(); */ }, 1000);
</script>
</body>
</html>
<?php
exit; // No Moodle footer — this is a standalone print page.
