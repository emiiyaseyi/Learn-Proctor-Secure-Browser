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
        $duration = round(((int)$a->timefinish - (int)$a->timestart) / 60, 1);
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
    'score'      => 'a.sumgrades',
    'risk'       => 'a.risk_score',
    'timestart'  => 'a.timestart',
    'timefinish' => 'a.timefinish',
];
$sortcol = $sortMap[$sort] ?? $sortMap['timestart'];
$dirParam = ($dirParam === 'asc') ? 'asc' : 'desc';
$sqldir   = ($dirParam === 'asc') ? 'ASC' : 'DESC';

$where  = 'a.learnguard = :lgid';
$params = ['lgid' => $instance->id];

if (in_array($filterState, ['inprogress', 'finished', 'suspended'], true)) {
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

$sql = "SELECT a.id, a.userid, a.attempt, a.state, a.sumgrades, a.timestart, a.timefinish,
               a.risk_score, a.risk_level,
               u.firstname, u.lastname, u.email, u.idnumber, u.username
          FROM {learnguard_attempts} a
          JOIN {user} u ON u.id = a.userid
         WHERE $where
      ORDER BY $sortcol $sqldir";

$attempts = $DB->get_records_sql($sql, $params);
$maxgrade = (float)$instance->grade;

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

  <div style="margin-left:auto;display:flex;gap:8px;">
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
        echo html_writer::tag('td',
            ($score !== null ? number_format($score, 1) : '—') . ' / ' . number_format($maxgrade, 1) .
            ($pct !== null ? ' <span style="font-weight:700;color:' .
                ($pct >= 70 ? '#28a745' : ($pct >= 50 ? '#fd7e14' : '#dc3545')) . ';">(' . $pct . '%)</span>' : ''),
            ['style' => 'padding:8px 10px;text-align:center;']);
        echo html_writer::tag('td',
            '<span style="background:' . $rc . ';color:#fff;padding:2px 7px;border-radius:10px;font-size:11px;font-weight:700;">' .
            (int)$a->risk_score . ' ' . ucfirst($a->risk_level) . '</span>',
            ['style' => 'padding:8px 10px;text-align:center;']);
        echo html_writer::tag('td', $a->timestart  ? userdate($a->timestart, '%d/%m/%y %H:%M')  : '—', ['style' => 'padding:8px 10px;']);
        echo html_writer::tag('td', $a->timefinish ? userdate($a->timefinish, '%d/%m/%y %H:%M') : '—', ['style' => 'padding:8px 10px;']);
        echo html_writer::tag('td', $duration !== '' ? $duration . ' min' : '—', ['style' => 'padding:8px 10px;']);
        echo html_writer::start_tag('td', ['style' => 'padding:8px 10px;white-space:nowrap;']);
        echo html_writer::link(
            new moodle_url('/mod/learnguard/attemptdetail.php', ['id' => $id, 'attempt' => $a->id]),
            '🔍 Review', ['class' => 'btn btn-sm btn-outline-primary']
        );
        echo html_writer::end_tag('td');
        echo html_writer::end_tag('tr');
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
    echo '</div>';
    echo html_writer::tag('p', count($attempts) . ' attempt(s) shown.', ['class' => 'text-muted mt-2', 'style' => 'font-size:12px;']);
}

echo $OUTPUT->footer();
