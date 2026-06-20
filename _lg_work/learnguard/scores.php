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
 * All Scores page for a single mod_learnguard assessment.
 *
 * Lists every attempt's score, sortable by column and filterable by state,
 * risk level, and student name/email — with CSV, Excel and PDF export of
 * the currently filtered/sorted view.
 *
 * URL: /mod/learnguard/scores.php?id={cmid}
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT);

$cm       = get_coursemodule_from_id('learnguard', $id, 0, false, MUST_EXIST);
$course   = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$instance = $DB->get_record('learnguard', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/learnguard:viewreports', $context);

// ── AJAX handler (manager-only recalculate) ───────────────────────────────────
$lgajax = optional_param('lgajax', 0, PARAM_INT);
if ($lgajax) {
    require_sesskey();
    header('Content-Type: application/json');
    $ajaxAction = optional_param('action', '', PARAM_ALPHANUMEXT);
    if ($ajaxAction === 'recalcall') {
        require_capability('mod/learnguard:manage', $context);
        require_once($CFG->libdir . '/gradelib.php');
        require_once(__DIR__ . '/classes/question_engine.php');
        $finishedAttempts = $DB->get_records('learnguard_attempts',
            ['learnguard' => $instance->id, 'state' => 'finished']);
        $count = 0;
        foreach ($finishedAttempts as $att) {
            if (\mod_learnguard\question_engine::has_ungraded_essays((int)$att->id, $instance->id)) {
                $DB->set_field('learnguard_attempts', 'sumgrades', null, ['id' => $att->id]);
                continue;
            }
            $newgrade = \mod_learnguard\question_engine::calculate_total_grade((int)$att->id, $instance);
            $DB->set_field('learnguard_attempts', 'sumgrades', $newgrade, ['id' => $att->id]);
            grade_update('mod/learnguard', $instance->course, 'mod', 'learnguard', $instance->id, 0,
                [(object)['userid' => $att->userid, 'rawgrade' => $newgrade]]);
            $count++;
        }
        echo json_encode(['ok' => true, 'count' => $count]);
    } else if ($ajaxAction === 'reset_essay_grades') {
        require_capability('mod/learnguard:manage', $context);
        require_once($CFG->libdir . '/gradelib.php');
        $essaySlotIds = $DB->get_fieldset_sql(
            "SELECT s.id FROM {learnguard_slots} s
               JOIN {learnguard_qbank} q ON q.id = s.questionid AND q.qtype = 'essay'
              WHERE s.learnguard = :lgid",
            ['lgid' => $instance->id]
        );
        if (!empty($essaySlotIds)) {
            $attemptIds = $DB->get_fieldset_sql(
                "SELECT id FROM {learnguard_attempts} WHERE learnguard = :lgid",
                ['lgid' => $instance->id]
            );
            if (!empty($attemptIds)) {
                [$slotSql, $slotParams] = $DB->get_in_or_equal($essaySlotIds, SQL_PARAMS_NAMED, 'sl');
                [$attSql, $attParams]   = $DB->get_in_or_equal($attemptIds, SQL_PARAMS_NAMED, 'at');
                $DB->delete_records_select('learnguard_essay_grades',
                    "slotid $slotSql AND attemptid $attSql",
                    array_merge($slotParams, $attParams)
                );
                $DB->execute(
                    "UPDATE {learnguard_responses} SET state = 'needsgrading', fraction = 0
                      WHERE slotid $slotSql AND attemptid $attSql",
                    array_merge($slotParams, $attParams)
                );
            }
        }
        $DB->set_field_select('learnguard_attempts', 'sumgrades', null,
            "learnguard = :lgid AND state = 'finished'", ['lgid' => $instance->id]
        );
        $userIds = $DB->get_fieldset_sql(
            "SELECT DISTINCT userid FROM {learnguard_attempts} WHERE learnguard = :lgid",
            ['lgid' => $instance->id]
        );
        foreach ($userIds as $uid) {
            grade_update('mod/learnguard', $instance->course, 'mod', 'learnguard', $instance->id, 0,
                [(object)['userid' => $uid, 'rawgrade' => null]]);
        }
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    }
    exit;
}

// ── Attempt management actions ───────────────────────────────────────────────
$action = optional_param('action', '', PARAM_ALPHA);
if ($action && data_submitted() && confirm_sesskey()) {
    require_capability('mod/learnguard:manage', $context);
    $attemptid = required_param('attemptid', PARAM_INT);
    $attempt   = $DB->get_record('learnguard_attempts',
        ['id' => $attemptid, 'learnguard' => $instance->id], '*', MUST_EXIST);

    if ($action === 'deleteattempt') {
        // Hard delete the attempt and all associated data so the student can restart clean.
        $DB->delete_records('learnguard_events',            ['attemptid' => $attemptid]);
        $DB->delete_records('learnguard_snapshots',         ['attemptid' => $attemptid]);
        $DB->delete_records('learnguard_responses',         ['attemptid' => $attemptid]);
        $DB->delete_records('learnguard_attempt_questions', ['attemptid' => $attemptid]);
        $DB->delete_records('learnguard_attempts',          ['id' => $attemptid]);
        redirect(
            new moodle_url('/mod/learnguard/scores.php', ['id' => $id]),
            'Attempt deleted. The student may now start a fresh attempt.',
            null, \core\output\notification::NOTIFY_SUCCESS
        );
    }

    if ($action === 'grantretry') {
        // Mark as abandoned — keeps all history but allows a new attempt to be started.
        // 'abandoned' is excluded from the max-attempts count (state IN ('finished','suspended')).
        if (in_array($attempt->state, ['finished', 'suspended'], true)) {
            $DB->set_field('learnguard_attempts', 'state', 'abandoned', ['id' => $attemptid]);
            redirect(
                new moodle_url('/mod/learnguard/scores.php', ['id' => $id]),
                'Re-attempt granted. Previous attempt preserved as Abandoned. The student may now start a new attempt.',
                null, \core\output\notification::NOTIFY_SUCCESS
            );
        }
        redirect(new moodle_url('/mod/learnguard/scores.php', ['id' => $id]));
    }
}

// ── Auto-recalculate all finished scores on page load ─────────────────────────
// Only updates attempts where all essays are graded; clears stale partial scores otherwise.
if (has_capability('mod/learnguard:manage', $context)) {
    require_once($CFG->libdir . '/gradelib.php');
    require_once(__DIR__ . '/classes/question_engine.php');
    $finishedAtts = $DB->get_records('learnguard_attempts',
        ['learnguard' => $instance->id, 'state' => 'finished'], '', 'id,userid');
    foreach ($finishedAtts as $fatt) {
        if (\mod_learnguard\question_engine::has_ungraded_essays((int)$fatt->id, $instance->id)) {
            $DB->set_field('learnguard_attempts', 'sumgrades', null, ['id' => $fatt->id]);
            continue;
        }
        $newg = \mod_learnguard\question_engine::calculate_total_grade((int)$fatt->id, $instance);
        $DB->set_field('learnguard_attempts', 'sumgrades', $newg, ['id' => $fatt->id]);
        grade_update('mod/learnguard', $instance->course, 'mod', 'learnguard', $instance->id, 0,
            [(object)['userid' => $fatt->userid, 'rawgrade' => $newg]]);
    }
}

/**
 * Compute [score, percent, duration_minutes] for a scores row.
 *
 * @param stdClass $a    Row from the scores query (sumgrades, timestart, timefinish).
 * @param float    $max  The assessment's maximum grade.
 * @return array{0:?float,1:?float,2:string}
 */
function learnguard_scores_compute(stdClass $a, float $max): array {
    $score = $a->sumgrades !== null ? (float)$a->sumgrades : null;
    $pct   = ($score !== null && $max > 0) ? round(($score / $max) * 100, 1) : null;
    $duration = '';
    if (!empty($a->timefinish) && $a->timefinish > $a->timestart) {
        $secs = (int)$a->timefinish - (int)$a->timestart;
        if ($secs < 60) {
            $duration = $secs . 's';
        } else {
            $mins = (int)floor($secs / 60);
            $rem  = $secs % 60;
            $duration = $mins . ' min' . ($rem > 0 ? ' ' . $rem . 's' : '');
        }
    }
    return [$score, $pct, $duration];
}

// ── Filters & sorting ───────────────────────────────────────────────────
$filterState = optional_param('filter_state', '', PARAM_ALPHA);
$filterRisk  = optional_param('filter_risk',  '', PARAM_ALPHA);
$search      = optional_param('search',       '', PARAM_TEXT);
$sort        = optional_param('sort',  'timestart', PARAM_ALPHA);
$dirParam    = optional_param('dir',   'desc',      PARAM_ALPHA);
$download    = optional_param('download', '', PARAM_ALPHA);

$sortMap = [
    'name'       => 'u.lastname, u.firstname',
    'attempt'    => 'a.attempt',
    'state'      => 'a.state',
    'score'      => 'sumgrades',
    'risk'       => 'a.risk_score',
    'timestart'  => 'a.timestart',
    'timefinish' => 'a.timefinish',
];
$sortcol = $sortMap[$sort] ?? $sortMap['timestart'];
$dirParam = ($dirParam === 'asc') ? 'asc' : 'desc';
$sqldir   = ($dirParam === 'asc') ? 'ASC' : 'DESC';

$where  = 'a.learnguard = :lgid';
$params = ['lgid' => $instance->id];

if (in_array($filterState, ['inprogress', 'finished', 'suspended', 'abandoned'], true)) {
    $where .= ' AND a.state = :fstate';
    $params['fstate'] = $filterState;
}
if (in_array($filterRisk, ['low', 'medium', 'high'], true)) {
    $where .= ' AND a.risk_level = :frisk';
    $params['frisk'] = $filterRisk;
}
if ($search !== '') {
    $where .= ' AND (' . $DB->sql_like('u.firstname', ':s1', false) . ' OR '
                       . $DB->sql_like('u.lastname',  ':s2', false) . ' OR '
                       . $DB->sql_like('u.email',     ':s3', false) . ')';
    $like = '%' . $DB->sql_like_escape($search) . '%';
    $params['s1'] = $params['s2'] = $params['s3'] = $like;
}

$sql = "SELECT a.id, a.userid, a.attempt, a.state,
               CASE WHEN EXISTS (
                   SELECT 1
                     FROM {learnguard_slots} es
                     JOIN {learnguard_qbank} eq ON eq.id = es.questionid AND eq.qtype = 'essay'
                     LEFT JOIN {learnguard_responses} er ON er.slotid = es.id AND er.attemptid = a.id
                    WHERE es.learnguard = a.learnguard
                      AND (
                          EXISTS (SELECT 1 FROM {learnguard_attempt_questions} aq
                                   WHERE aq.slotid = es.id AND aq.attemptid = a.id)
                          OR EXISTS (SELECT 1 FROM {learnguard_responses} r
                                      WHERE r.slotid = es.id AND r.attemptid = a.id)
                          OR NOT EXISTS (SELECT 1 FROM {learnguard_attempt_questions} aq2
                                          WHERE aq2.attemptid = a.id)
                      )
                      AND (er.id IS NULL OR er.state != 'graded')
               ) THEN NULL ELSE a.sumgrades END AS sumgrades,
               a.timestart, a.timefinish,
               a.risk_score, a.risk_level,
               u.firstname, u.lastname, u.email, u.idnumber, u.username
          FROM {learnguard_attempts} a
          JOIN {user} u ON u.id = a.userid
         WHERE $where
      ORDER BY $sortcol $sqldir";

$attempts = $DB->get_records_sql($sql, $params);
$maxgrade = (float)$instance->grade;

// Batch-fetch raw essay grade totals (sum of grader-given marks per attempt).
$rawEssayTotals = [];
if (!empty($attempts)) {
    $aidList = array_column(array_values($attempts), 'id');
    if (!empty($aidList)) {
        [$aidSql2, $aidP2] = $DB->get_in_or_equal($aidList, SQL_PARAMS_NAMED, 'sc');
        $rawRows = $DB->get_records_sql(
            "SELECT attemptid, SUM(grade) AS raw_total FROM {learnguard_essay_grades}
              WHERE attemptid $aidSql2 GROUP BY attemptid",
            $aidP2
        );
        foreach ($rawRows as $r) { $rawEssayTotals[(int)$r->attemptid] = (float)$r->raw_total; }
    }
}

// ── Exports (current filter/sort applied) ──────────────────────────────────
if ($download === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . clean_filename($instance->name) . '_scores.csv"');
    echo "\xEF\xBB\xBF";
    $fh = fopen('php://output', 'w');
    fputcsv($fh, ['First Name', 'Last Name', 'Email', 'ID Number', 'Username', 'Attempt #', 'State',
        'Score', 'Max Score', 'Percent', 'Risk Score', 'Risk Level', 'Started', 'Finished', 'Duration (min)']);
    foreach ($attempts as $a) {
        [$score, $pct, $duration] = learnguard_scores_compute($a, $maxgrade);
        fputcsv($fh, [
            $a->firstname, $a->lastname, $a->email, $a->idnumber, $a->username,
            (int)$a->attempt, $a->state,
            $score !== null ? number_format($score, 2, '.', '') : '',
            number_format($maxgrade, 2, '.', ''),
            $pct !== null ? $pct . '%' : '',
            (int)$a->risk_score, $a->risk_level,
            $a->timestart  ? userdate($a->timestart, '%Y-%m-%d %H:%M:%S')  : '',
            $a->timefinish ? userdate($a->timefinish, '%Y-%m-%d %H:%M:%S') : '',
            $duration,
        ]);
    }
    fclose($fh);
    exit;
}

if ($download === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . clean_filename($instance->name) . '_scores.xls"');
    echo '<?xml version="1.0"?><Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" ';
    echo 'xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">';
    echo '<Worksheet ss:Name="Scores"><Table>';
    $headers = ['Name', 'Email', 'Attempt', 'State', 'Score', 'Max', 'Percent', 'Risk Score', 'Risk Level', 'Started', 'Finished', 'Duration (min)'];
    echo '<Row>';
    foreach ($headers as $h) {
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars($h) . '</Data></Cell>';
    }
    echo '</Row>';
    foreach ($attempts as $a) {
        [$score, $pct, $duration] = learnguard_scores_compute($a, $maxgrade);
        $row = [
            fullname($a), $a->email, (int)$a->attempt, ucfirst($a->state),
            $score !== null ? $score : '', $maxgrade, $pct !== null ? $pct : '',
            (int)$a->risk_score, ucfirst($a->risk_level),
            $a->timestart  ? userdate($a->timestart)  : '',
            $a->timefinish ? userdate($a->timefinish) : '',
            $duration,
        ];
        echo '<Row>';
        foreach ($row as $val) {
            $type = is_numeric($val) ? 'Number' : 'String';
            echo '<Cell><Data ss:Type="' . $type . '">' . htmlspecialchars((string)$val) . '</Data></Cell>';
        }
        echo '</Row>';
    }
    echo '</Table></Worksheet></Workbook>';
    exit;
}

if ($download === 'pdf') {
    $brand   = learnguard_brand();
    $applied = [];
    if ($filterState !== '') { $applied[] = 'State: ' . ucfirst($filterState); }
    if ($filterRisk  !== '') { $applied[] = 'Risk: ' . ucfirst($filterRisk); }
    if ($search       !== '') { $applied[] = 'Search: "' . $search . '"'; }

    header('Content-Type: text/html; charset=UTF-8');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Scores — <?php echo s(format_string($instance->name)); ?></title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: "Segoe UI", Arial, sans-serif; font-size: 12px; color: #212529; background: #fff; padding: 0; }
.page { max-width: 1000px; margin: 0 auto; padding: 24px; }
.header { background: <?php echo $brand->primary; ?>; color: #fff; padding: 20px 24px; border-radius: 6px; margin-bottom: 16px; }
.header h1 { font-size: 18px; margin-bottom: 4px; }
.header p  { font-size: 12px; opacity: 0.85; margin: 0; }
table { width: 100%; border-collapse: collapse; }
th { background: #f8f9fa; padding: 6px 10px; text-align: left; font-size: 11px; border-bottom: 1px solid #dee2e6; }
td { padding: 6px 10px; border-bottom: 1px solid #f5f5f5; font-size: 12px; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 700; color: #fff; }
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

<div class="header">
  <h1>🏆 <?php echo htmlspecialchars($brand->name); ?> — All Scores</h1>
  <p><?php echo s(format_string($instance->name)); ?> &nbsp;|&nbsp; <?php echo s(format_string($course->fullname)); ?></p>
  <p>Generated: <?php echo userdate(time()); ?><?php echo $applied ? ' &nbsp;|&nbsp; Filters: ' . s(implode(', ', $applied)) : ''; ?></p>
</div>

<table>
  <tr>
    <th>Student</th><th>Email</th><th>Attempt</th><th>State</th>
    <th>Score</th><th>%</th><th>Risk</th><th>Started</th><th>Finished</th><th>Duration</th>
  </tr>
  <?php
  $rcolours = ['low' => '#28a745', 'medium' => '#fd7e14', 'high' => '#dc3545'];
  foreach ($attempts as $a):
      [$score, $pct, $duration] = learnguard_scores_compute($a, $maxgrade);
      $rc = $rcolours[$a->risk_level] ?? '#6c757d';
  ?>
  <tr>
    <td><?php echo s(fullname($a)); ?></td>
    <td><?php echo s($a->email); ?></td>
    <td>#<?php echo (int)$a->attempt; ?></td>
    <td><?php echo s(ucfirst($a->state)); ?></td>
    <td><?php echo $score !== null ? number_format($score, 1) : '—'; ?> / <?php echo number_format($maxgrade, 1); ?></td>
    <td><?php echo $pct !== null ? $pct . '%' : '—'; ?></td>
    <td><span class="badge" style="background:<?php echo $rc; ?>;"><?php echo (int)$a->risk_score; ?> <?php echo s(ucfirst($a->risk_level)); ?></span></td>
    <td><?php echo $a->timestart  ? userdate($a->timestart, '%d/%m/%y %H:%M')  : '—'; ?></td>
    <td><?php echo $a->timefinish ? userdate($a->timefinish, '%d/%m/%y %H:%M') : '—'; ?></td>
    <td><?php echo $duration !== '' ? $duration . ' min' : '—'; ?></td>
  </tr>
  <?php endforeach; ?>
</table>

<footer>
  LearnGuard Assessment Platform &nbsp;|&nbsp; Developed by Michael Adeniran &nbsp;|&nbsp; <?php echo date('Y'); ?>
</footer>
</div>
</body>
</html>
    <?php
    exit;
}

// ── Page render ─────────────────────────────────────────────────────────────
$PAGE->set_url('/mod/learnguard/scores.php', ['id' => $id]);
$PAGE->set_context($context);
$PAGE->set_title(format_string($instance->name) . ' — All Scores');
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->css('/mod/learnguard/styles.css');

echo $OUTPUT->header();
echo learnguard_activity_tabs($id, 'scores');
$brand = learnguard_brand();
echo learnguard_banner('All Scores', format_string($instance->name), '🏆');

// Summary cards (whole assessment, independent of current filters).
$totalAttempts   = $DB->count_records('learnguard_attempts', ['learnguard' => $instance->id]);
$finishedCount   = $DB->count_records('learnguard_attempts', ['learnguard' => $instance->id, 'state' => 'finished']);
$inprogressCount = $DB->count_records('learnguard_attempts', ['learnguard' => $instance->id, 'state' => 'inprogress']);
$suspendedCount  = $DB->count_records('learnguard_attempts', ['learnguard' => $instance->id, 'state' => 'suspended']);
$avgRow = $DB->get_record_sql(
    "SELECT AVG(sumgrades) AS avgscore FROM {learnguard_attempts}
      WHERE learnguard = :lgid AND state = 'finished' AND sumgrades IS NOT NULL",
    ['lgid' => $instance->id]
);
$avgPct = ($maxgrade > 0 && $avgRow && $avgRow->avgscore !== null)
    ? round(((float)$avgRow->avgscore / $maxgrade) * 100, 1) : null;

echo html_writer::start_div('d-flex gap-3 flex-wrap mb-4');
foreach ([
    ['Total Attempts', $totalAttempts, $brand->primary],
    ['Finished',       $finishedCount, '#28a745'],
    ['In Progress',    $inprogressCount, '#fd7e14'],
    ['Suspended',      $suspendedCount, '#dc3545'],
    ['Average Score',  $avgPct !== null ? $avgPct . '%' : '—', '#6c757d'],
] as [$label, $val, $colour]) {
    echo html_writer::start_div('', ['style' =>
        'background:' . $colour . ';color:#fff;border-radius:8px;padding:16px 24px;min-width:120px;text-align:center;']);
    echo html_writer::tag('div', $val, ['style' => 'font-size:28px;font-weight:700;']);
    echo html_writer::tag('div', $label, ['style' => 'font-size:13px;opacity:0.85;']);
    echo html_writer::end_div();
}
echo html_writer::end_div();

// ── Filter form ──────────────────────────────────────────────────────────
?>
<form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px;background:#f8f9fa;padding:14px 16px;border-radius:8px;">
  <input type="hidden" name="id" value="<?php echo $id; ?>">
  <input type="hidden" name="sort" value="<?php echo s($sort); ?>">
  <input type="hidden" name="dir" value="<?php echo s($dirParam); ?>">
  <div style="display:flex;flex-direction:column;gap:4px;">
    <label style="font-size:12px;font-weight:700;">State</label>
    <select name="filter_state" class="form-control" style="max-width:160px;" onchange="this.form.submit()">
      <option value="">— All —</option>
      <option value="inprogress" <?php echo $filterState === 'inprogress' ? 'selected' : ''; ?>>In Progress</option>
      <option value="finished"   <?php echo $filterState === 'finished'   ? 'selected' : ''; ?>>Finished</option>
      <option value="suspended"  <?php echo $filterState === 'suspended'  ? 'selected' : ''; ?>>Suspended</option>
      <option value="abandoned"  <?php echo $filterState === 'abandoned'  ? 'selected' : ''; ?>>Abandoned (Re-attempt)</option>
    </select>
  </div>
  <div style="display:flex;flex-direction:column;gap:4px;">
    <label style="font-size:12px;font-weight:700;">Risk Level</label>
    <select name="filter_risk" class="form-control" style="max-width:140px;" onchange="this.form.submit()">
      <option value="">— All —</option>
      <option value="low"    <?php echo $filterRisk === 'low'    ? 'selected' : ''; ?>>Low</option>
      <option value="medium" <?php echo $filterRisk === 'medium' ? 'selected' : ''; ?>>Medium</option>
      <option value="high"   <?php echo $filterRisk === 'high'   ? 'selected' : ''; ?>>High</option>
    </select>
  </div>
  <div style="display:flex;flex-direction:column;gap:4px;">
    <label style="font-size:12px;font-weight:700;">Search (name / email)</label>
    <input type="text" name="search" value="<?php echo s($search); ?>" class="form-control" style="max-width:220px;" placeholder="e.g. Jane Doe">
  </div>
  <button type="submit" class="btn btn-primary btn-sm">🔍 Filter</button>
  <?php if ($filterState !== '' || $filterRisk !== '' || $search !== ''): ?>
  <a href="<?php echo new moodle_url('/mod/learnguard/scores.php', ['id' => $id, 'sort' => $sort, 'dir' => $dirParam]); ?>" class="btn btn-outline-secondary btn-sm">Clear</a>
  <?php endif; ?>

  <div style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
    <?php if (has_capability('mod/learnguard:manage', $context)): ?>
    <button type="button" id="lg-recalc-btn" class="btn btn-outline-warning btn-sm"
            onclick="lgRecalcAll()"
            title="Recompute scores for all finished attempts using the current grading formula">
      🔄 Recalculate All Scores
    </button>
    <button type="button" id="lg-reset-essays-btn" class="btn btn-outline-danger btn-sm"
            onclick="lgResetEssayGrades()"
            title="Delete ALL essay grades and reset all attempt scores to pending so grading can start fresh">
      🗑 Reset Essay Grades
    </button>
    <?php endif; ?>
    <?php
    $exportParams = ['id' => $id, 'sort' => $sort, 'dir' => $dirParam,
        'filter_state' => $filterState, 'filter_risk' => $filterRisk, 'search' => $search];
    echo html_writer::link(new moodle_url('/mod/learnguard/scores.php', $exportParams + ['download' => 'csv']),
        '⬇ CSV', ['class' => 'btn btn-outline-secondary btn-sm']);
    echo html_writer::link(new moodle_url('/mod/learnguard/scores.php', $exportParams + ['download' => 'excel']),
        '📊 Excel', ['class' => 'btn btn-outline-success btn-sm']);
    echo html_writer::link(new moodle_url('/mod/learnguard/scores.php', $exportParams + ['download' => 'pdf']),
        '🖨 PDF', ['class' => 'btn btn-outline-dark btn-sm', 'target' => '_blank']);
    ?>
  </div>
</form>

<?php
// ── Sortable results table ───────────────────────────────────────────────
if (empty($attempts)) {
    echo html_writer::div('No attempts match the current filters.', 'alert alert-info');
} else {
    /**
     * Build a sort-toggle link for a column header.
     */
    $sortLink = function(string $col, string $label) use ($id, $sort, $dirParam, $filterState, $filterRisk, $search) {
        $newdir = ($sort === $col && $dirParam === 'asc') ? 'desc' : 'asc';
        $arrow  = ($sort === $col) ? ($dirParam === 'asc' ? ' ▲' : ' ▼') : '';
        $url = new moodle_url('/mod/learnguard/scores.php', [
            'id' => $id, 'sort' => $col, 'dir' => $newdir,
            'filter_state' => $filterState, 'filter_risk' => $filterRisk, 'search' => $search,
        ]);
        return html_writer::link($url, $label . $arrow, ['style' => 'color:inherit;text-decoration:none;']);
    };

    $rcolours = ['low' => '#28a745', 'medium' => '#fd7e14', 'high' => '#dc3545'];

    echo '<div style="overflow-x:auto;">';
    echo html_writer::start_tag('table', ['class' => 'generaltable w-100', 'style' => 'font-size:13px;']);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr', ['style' => 'background:#f8f9fa;']);
    foreach ([
        ['name',       'Student'],
        ['attempt',    'Attempt'],
        ['state',      'State'],
        ['score',      'Score'],
        ['risk',       'Risk'],
        ['timestart',  'Started'],
        ['timefinish', 'Finished'],
    ] as [$col, $label]) {
        echo html_writer::tag('th', $sortLink($col, $label), ['style' => 'padding:8px 10px;']);
    }
    echo html_writer::tag('th', 'Duration', ['style' => 'padding:8px 10px;']);
    echo html_writer::tag('th', 'Actions', ['style' => 'padding:8px 10px;']);
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    foreach ($attempts as $a) {
        [$score, $pct, $duration] = learnguard_scores_compute($a, $maxgrade);
        $rc = $rcolours[$a->risk_level] ?? '#6c757d';

        echo html_writer::start_tag('tr');
        echo html_writer::tag('td',
            '<strong>' . s(fullname($a)) . '</strong><br><small class="text-muted">' . s($a->email) . '</small>',
            ['style' => 'padding:8px 10px;']);
        echo html_writer::tag('td', '#' . (int)$a->attempt, ['style' => 'padding:8px 10px;text-align:center;']);
        echo html_writer::tag('td', ucfirst($a->state), ['style' => 'padding:8px 10px;']);
        $rawEssay = $rawEssayTotals[(int)$a->id] ?? null;
        $rawSuffix = ($score !== null && $rawEssay !== null)
            ? ' <span style="color:#6c757d;font-size:11px;">(' . number_format($rawEssay, 0) . ')</span>'
            : '';
        echo html_writer::tag('td',
            ($score !== null ? number_format($score, 2) : '—') . ' / ' . number_format($maxgrade, 2) . $rawSuffix .
            ($pct !== null ? '<br><span style="font-weight:700;color:' .
                ($pct >= 70 ? '#28a745' : ($pct >= 50 ? '#fd7e14' : '#dc3545')) . ';font-size:11px;">' . $pct . '%</span>' : ''),
            ['style' => 'padding:8px 10px;text-align:center;']);
        echo html_writer::tag('td',
            '<span style="background:' . $rc . ';color:#fff;padding:1px 6px;border-radius:10px;font-size:11px;font-weight:700;display:inline-block;">' . (int)$a->risk_score . '</span>' .
            ' <span style="color:' . $rc . ';font-size:11px;font-weight:600;">' . ucfirst($a->risk_level) . '</span>',
            ['style' => 'padding:8px 10px;text-align:center;']);
        echo html_writer::tag('td', $a->timestart  ? userdate($a->timestart, '%d/%m/%y %H:%M')  : '—', ['style' => 'padding:8px 10px;']);
        echo html_writer::tag('td', $a->timefinish ? userdate($a->timefinish, '%d/%m/%y %H:%M') : '—', ['style' => 'padding:8px 10px;']);
        echo html_writer::tag('td', $duration !== '' ? $duration . ' min' : '—', ['style' => 'padding:8px 10px;']);
        echo html_writer::start_tag('td', ['style' => 'padding:8px 10px;']);
        echo '<div style="display:flex;flex-wrap:wrap;gap:4px;align-items:center;">';
        echo html_writer::link(
            new moodle_url('/mod/learnguard/attemptdetail.php', ['id' => $id, 'attempt' => $a->id]),
            '🔍 Review', ['class' => 'btn btn-sm btn-outline-primary']
        );
        // Allow re-attempt: marks finished/suspended attempt as 'abandoned' so student can start fresh (history kept).
        if (in_array($a->state, ['finished', 'suspended'], true)) {
            echo '<form method="post" style="display:contents;">' .
                '<input type="hidden" name="sesskey" value="' . sesskey() . '">' .
                '<input type="hidden" name="action" value="grantretry">' .
                '<input type="hidden" name="attemptid" value="' . (int)$a->id . '">' .
                '<input type="hidden" name="id" value="' . $id . '">' .
                '<button type="submit" class="btn btn-sm btn-outline-warning" ' .
                'onclick="return confirm(\'Allow this student to start a new attempt?\\nThe current attempt will be kept as Abandoned for your records.\')">' .
                '🔄 Re-attempt</button></form>';
        }
        // Delete attempt: permanently removes attempt + all data.
        echo '<form method="post" style="display:contents;">' .
            '<input type="hidden" name="sesskey" value="' . sesskey() . '">' .
            '<input type="hidden" name="action" value="deleteattempt">' .
            '<input type="hidden" name="attemptid" value="' . (int)$a->id . '">' .
            '<input type="hidden" name="id" value="' . $id . '">' .
            '<button type="submit" class="btn btn-sm btn-outline-danger" ' .
            'onclick="return confirm(\'Permanently delete this attempt and ALL its data (events, snapshots, responses)?\\nThis cannot be undone. The student will need to restart from scratch.\')">' .
            '🗑 Delete</button></form>';
        echo '</div>';
        echo html_writer::end_tag('td');
        echo html_writer::end_tag('tr');
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
    echo '</div>';
    echo html_writer::tag('p', count($attempts) . ' attempt(s) shown.', ['class' => 'text-muted mt-2', 'style' => 'font-size:12px;']);
}

if (has_capability('mod/learnguard:manage', $context)) {
    $scoresAjaxUrl = (new moodle_url('/mod/learnguard/scores.php', ['id' => $id]))->out(false);
    $scoresSesskey = sesskey();
    echo '<script>
function lgRecalcAll() {
    var btn = document.getElementById("lg-recalc-btn");
    if (!btn || !confirm("Recalculate scores for all finished attempts using the current formula?\\n\\nThis updates both the activity score and the Moodle gradebook.")) { return; }
    btn.disabled = true; btn.textContent = "⏳ Recalculating…";
    fetch("' . $scoresAjaxUrl . '", {
        method: "POST",
        headers: {"Content-Type": "application/x-www-form-urlencoded"},
        body: "lgajax=1&action=recalcall&sesskey=' . $scoresSesskey . '"
    }).then(function(r){ return r.json(); }).then(function(d) {
        if (d.ok) {
            btn.textContent = "✅ Done (" + d.count + " updated)";
            btn.classList.remove("btn-outline-warning");
            btn.classList.add("btn-outline-success");
            setTimeout(function(){ location.reload(); }, 1500);
        } else {
            btn.textContent = "❌ Error"; btn.disabled = false;
        }
    }).catch(function(){ btn.textContent = "❌ Error"; btn.disabled = false; });
}
function lgResetEssayGrades() {
    var btn = document.getElementById("lg-reset-essays-btn");
    if (!btn || !confirm("⚠ WARNING: This will delete ALL essay grades for this activity and reset all attempt scores to pending.\\n\\nStudents must be re-graded from scratch. This cannot be undone.\\n\\nProceed?")) { return; }
    btn.disabled = true; btn.textContent = "⏳ Resetting…";
    fetch("' . $scoresAjaxUrl . '", {
        method: "POST",
        headers: {"Content-Type": "application/x-www-form-urlencoded"},
        body: "lgajax=1&action=reset_essay_grades&sesskey=' . $scoresSesskey . '"
    }).then(function(r){ return r.json(); }).then(function(d) {
        if (d.ok) {
            btn.textContent = "✅ Reset complete";
            btn.classList.remove("btn-outline-danger");
            btn.classList.add("btn-outline-success");
            setTimeout(function(){ location.reload(); }, 1500);
        } else {
            btn.textContent = "❌ Error"; btn.disabled = false;
        }
    }).catch(function(){ btn.textContent = "❌ Error"; btn.disabled = false; });
}
</script>';
}

echo $OUTPUT->footer();
