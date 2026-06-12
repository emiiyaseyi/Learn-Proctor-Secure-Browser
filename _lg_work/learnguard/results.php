<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Site-wide results page for mod_learnguard.
 *
 * Shows all assessment scores across all courses, with filtering,
 * state breakdown, export and grader assignment.
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

$PAGE->set_url('/mod/learnguard/results.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('LearnGuard — All Results');
$PAGE->set_heading('LearnGuard — Assessment Results');
$PAGE->requires->css('/mod/learnguard/styles.css');

// ── Filters ───────────────────────────────────────────────────────────────
$filterCourse  = optional_param('filter_course',  0,          PARAM_INT);
$filterLgid    = optional_param('filter_lgid',    0,          PARAM_INT);
$filterState   = optional_param('filter_state',   '',         PARAM_ALPHA);
$page          = optional_param('page',           0,          PARAM_INT);
$perPage       = 50;

// ── Export? ───────────────────────────────────────────────────────────────
$export = optional_param('export', '', PARAM_ALPHA);

// ── Build query ───────────────────────────────────────────────────────────
$where  = '1=1';
$params = [];
if ($filterCourse > 0) {
    $where  .= ' AND c.id = :courseid';
    $params['courseid'] = $filterCourse;
}
if ($filterLgid > 0) {
    $where  .= ' AND lg.id = :lgid';
    $params['lgid'] = $filterLgid;
}
if ($filterState !== '') {
    $where  .= ' AND a.state = :state';
    $params['state'] = $filterState;
}

$sql = "SELECT a.id, a.userid, a.attempt, a.state, a.timestart, a.timefinish,
               a.sumgrades, a.risk_score, a.risk_level,
               u.firstname, u.lastname, u.email, u.idnumber,
               lg.name AS assessmentname, lg.id AS lgid, lg.grade AS maxgrade,
               c.fullname AS coursename, c.id AS courseid,
               cm.id AS cmid
          FROM {learnguard_attempts} a
          JOIN {user}           u   ON u.id = a.userid
          JOIN {learnguard}     lg  ON lg.id = a.learnguard
          JOIN {course}         c   ON c.id = lg.course
          JOIN {course_modules} cm  ON cm.instance = lg.id AND cm.module = (
               SELECT id FROM {modules} WHERE name = 'learnguard')
         WHERE $where
      ORDER BY a.timestart DESC";

// ── Export ────────────────────────────────────────────────────────────────
if ($export === 'csv' && confirm_sesskey()) {
    $rows = $DB->get_records_sql($sql, $params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="learnguard_results_' . date('Ymd_His') . '.csv"');
    echo "\xEF\xBB\xBF";
    echo '"First Name","Last Name","Email","ID Number","Course","Assessment","Attempt","State","Score","Max","Percent","Risk","Started","Finished"' . "\n";
    foreach ($rows as $r) {
        $score = $r->sumgrades !== null ? (float)$r->sumgrades : null;
        $pct   = ($score !== null && $r->maxgrade > 0) ? round(($score / $r->maxgrade) * 100, 1) : null;
        echo implode(',', array_map(function($v) { return '"' . str_replace('"','""',(string)$v) . '"'; }, [
            $r->firstname, $r->lastname, $r->email, $r->idnumber,
            format_string($r->coursename), format_string($r->assessmentname),
            $r->attempt, $r->state,
            $score !== null ? number_format($score, 2, '.', '') : '',
            number_format((float)$r->maxgrade, 2, '.', ''),
            $pct !== null ? $pct . '%' : '',
            $r->risk_score,
            $r->timestart  ? date('Y-m-d H:i', (int)$r->timestart)  : '',
            $r->timefinish ? date('Y-m-d H:i', (int)$r->timefinish) : '',
        ])) . "\n";
    }
    exit;
}

// ── State counts ──────────────────────────────────────────────────────────
$stateCounts = $DB->get_records_sql(
    "SELECT a.state, COUNT(*) AS cnt FROM {learnguard_attempts} a
      JOIN {learnguard} lg ON lg.id = a.learnguard
      JOIN {course} c ON c.id = lg.course
     WHERE $where GROUP BY a.state",
    $params
);
$counts = ['inprogress' => 0, 'finished' => 0, 'suspended' => 0, 'notstarted' => 0];
foreach ($stateCounts as $sc) { $counts[$sc->state] = (int)$sc->cnt; }

$total  = $DB->count_records_sql("SELECT COUNT(*) FROM {learnguard_attempts} a
    JOIN {learnguard} lg ON lg.id = a.learnguard JOIN {course} c ON c.id = lg.course WHERE $where", $params);

// Paginate
$attempts = $DB->get_records_sql($sql . " LIMIT $perPage OFFSET " . ($page * $perPage), $params);

// All courses for filter
$allCourses = $DB->get_records_sql("SELECT DISTINCT c.id, c.fullname FROM {course} c
    JOIN {learnguard} lg ON lg.course = c.id ORDER BY c.fullname");
// All assessments for filter
$allAssessments = $DB->get_records_sql("SELECT lg.id, lg.name, c.fullname AS coursename FROM {learnguard} lg
    JOIN {course} c ON c.id = lg.course ORDER BY c.fullname, lg.name");

echo $OUTPUT->header();
echo learnguard_nav_bar(0, 'All Results');
$brand = learnguard_brand();
echo learnguard_banner('All Assessment Results', 'Scores, attempt states, exports and grader assignment across all courses.', '📊');
?>

<div style="font-family:'Segoe UI',system-ui,sans-serif;padding:0 4px;">

<!-- State summary pills -->
<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px;">
<?php
$stateConfig = [
    ''           => ['All Attempts',    $total,                 '#6c757d'],
    'inprogress' => ['🟢 In Progress',  $counts['inprogress'],  '#28a745'],
    'finished'   => ['✅ Finished',     $counts['finished'],    '#0f6cbf'],
    'suspended'  => ['🚫 Suspended',    $counts['suspended'],   '#dc3545'],
    'notstarted' => ['⏳ Not Started',  $counts['notstarted'],  '#fd7e14'],
];
foreach ($stateConfig as $stateVal => [$label, $cnt, $col]) {
    $active = ($filterState === $stateVal);
    $url = new moodle_url('/mod/learnguard/results.php',
        ['filter_course' => $filterCourse, 'filter_lgid' => $filterLgid, 'filter_state' => $stateVal]);
    echo html_writer::link($url, $label . ' (' . $cnt . ')', ['style' =>
        'padding:8px 16px;border-radius:20px;text-decoration:none;font-size:13px;font-weight:700;' .
        ($active ? 'background:' . $col . ';color:#fff;' : 'background:#f8f9fa;color:' . $col . ';border:2px solid ' . $col . ';')
    ]);
}
?>
</div>

<!-- Filters + export -->
<form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:20px;background:#f8f9fa;padding:14px 16px;border-radius:8px;">
  <div style="display:flex;flex-direction:column;gap:4px;">
    <label style="font-size:12px;font-weight:700;">Course</label>
    <select name="filter_course" class="form-control" style="max-width:220px;" onchange="this.form.submit()">
      <option value="0">— All Courses —</option>
      <?php foreach ($allCourses as $c): ?>
      <option value="<?php echo $c->id; ?>" <?php echo ($filterCourse == $c->id ? 'selected' : ''); ?>><?php echo s(format_string($c->fullname)); ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div style="display:flex;flex-direction:column;gap:4px;">
    <label style="font-size:12px;font-weight:700;">Assessment</label>
    <select name="filter_lgid" class="form-control" style="max-width:260px;" onchange="this.form.submit()">
      <option value="0">— All Assessments —</option>
      <?php foreach ($allAssessments as $a): ?>
      <option value="<?php echo $a->id; ?>" <?php echo ($filterLgid == $a->id ? 'selected' : ''); ?>>[<?php echo s(format_string($a->coursename)); ?>] <?php echo s(format_string($a->name)); ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <input type="hidden" name="filter_state" value="<?php echo s($filterState); ?>">
  <button type="submit" class="btn btn-primary btn-sm">🔍 Filter</button>
  <?php if ($filterCourse || $filterLgid || $filterState): ?>
  <a href="<?php echo new moodle_url('/mod/learnguard/results.php'); ?>" class="btn btn-outline-secondary btn-sm">Clear</a>
  <?php endif; ?>
  <a href="<?php echo new moodle_url('/mod/learnguard/results.php', [
    'filter_course' => $filterCourse, 'filter_lgid' => $filterLgid, 'filter_state' => $filterState,
    'export' => 'csv', 'sesskey' => sesskey()]); ?>" class="btn btn-outline-success btn-sm ms-auto">⬇ Export CSV</a>
</form>

<!-- Results table -->
<?php if (empty($attempts)): ?>
<div class="alert alert-info">No attempts match the current filters.</div>
<?php else: ?>
<div style="overflow-x:auto;">
<table class="generaltable w-100" style="font-size:13px;">
  <thead>
    <tr style="background:#f8f9fa;">
      <th style="padding:8px 10px;">Student</th>
      <th style="padding:8px 10px;">Course</th>
      <th style="padding:8px 10px;">Assessment</th>
      <th style="padding:8px 10px;">Attempt</th>
      <th style="padding:8px 10px;">State</th>
      <th style="padding:8px 10px;">Score</th>
      <th style="padding:8px 10px;">%</th>
      <th style="padding:8px 10px;">Risk</th>
      <th style="padding:8px 10px;">Started</th>
      <th style="padding:8px 10px;">Actions</th>
    </tr>
  </thead>
  <tbody>
  <?php
  $stateLabels = ['inprogress' => '🟢 In Progress', 'finished' => '✅ Finished', 'suspended' => '🚫 Suspended', 'notstarted' => '⏳ Not Started'];
  $rcolours    = ['low' => '#28a745', 'medium' => '#fd7e14', 'high' => '#dc3545'];
  foreach ($attempts as $att):
      $score = $att->sumgrades !== null ? (float)$att->sumgrades : null;
      $pct   = ($score !== null && $att->maxgrade > 0) ? round(($score / $att->maxgrade) * 100, 1) : null;
      $rc    = $rcolours[$att->risk_level ?? 'low'] ?? '#6c757d';
  ?>
  <tr>
    <td style="padding:8px 10px;">
      <strong><?php echo fullname($att); ?></strong><br>
      <small class="text-muted"><?php echo s($att->email); ?></small>
    </td>
    <td style="padding:8px 10px;"><small><?php echo format_string($att->coursename); ?></small></td>
    <td style="padding:8px 10px;"><?php echo format_string($att->assessmentname); ?></td>
    <td style="padding:8px 10px;text-align:center;"><?php echo (int)$att->attempt; ?></td>
    <td style="padding:8px 10px;"><?php echo $stateLabels[$att->state] ?? ucfirst($att->state); ?></td>
    <td style="padding:8px 10px;text-align:center;font-weight:700;">
      <?php echo $score !== null ? number_format($score, 1) : '—'; ?> / <?php echo number_format((float)$att->maxgrade, 1); ?>
    </td>
    <td style="padding:8px 10px;text-align:center;">
      <?php if ($pct !== null): ?>
      <span style="font-weight:700;color:<?php echo $pct >= 70 ? '#28a745' : ($pct >= 50 ? '#fd7e14' : '#dc3545'); ?>;">
        <?php echo $pct; ?>%
      </span>
      <?php else: ?>
      <span style="color:#adb5bd;">—</span>
      <?php endif; ?>
    </td>
    <td style="padding:8px 10px;text-align:center;">
      <span style="background:<?php echo $rc; ?>;color:#fff;padding:2px 7px;border-radius:10px;font-size:11px;font-weight:700;">
        <?php echo ($att->risk_score ?? 0); ?>
      </span>
    </td>
    <td style="padding:8px 10px;"><small><?php echo $att->timestart ? userdate((int)$att->timestart, '%d/%m/%y %H:%M') : '—'; ?></small></td>
    <td style="padding:8px 10px;white-space:nowrap;">
      <?php echo html_writer::link(
          new moodle_url('/mod/learnguard/attemptdetail.php', ['id' => $att->cmid, 'attempt' => $att->id]),
          '🔍', ['class' => 'btn btn-sm btn-outline-primary me-1', 'title' => 'View detail']
      ); ?>
      <?php echo html_writer::link(
          new moodle_url('/mod/learnguard/admin.php', ['id' => $att->cmid, 'tab' => 'attempts']),
          '⚙️', ['class' => 'btn btn-sm btn-outline-secondary me-1', 'title' => 'Assessment admin']
      ); ?>
      <?php if ($att->state === 'inprogress'): ?>
      <?php echo html_writer::link(
          new moodle_url('/mod/learnguard/proctordashboard.php', ['id' => $att->cmid]),
          '🔴', ['class' => 'btn btn-sm btn-danger', 'title' => 'Live dashboard']
      ); ?>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<!-- Pagination -->
<?php
$totalPages = ceil($total / $perPage);
if ($totalPages > 1):
    echo '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:12px;">';
    for ($p = 0; $p < $totalPages; $p++) {
        $active = ($p === $page);
        $pageUrl = new moodle_url('/mod/learnguard/results.php', [
            'filter_course' => $filterCourse, 'filter_lgid' => $filterLgid,
            'filter_state' => $filterState, 'page' => $p,
        ]);
        echo html_writer::link($pageUrl, ($p + 1), ['style' =>
            'padding:6px 12px;border-radius:4px;text-decoration:none;font-size:13px;' .
            ($active ? 'background:' . $brand->primary . ';color:#fff;' : 'background:#f8f9fa;border:1px solid #dee2e6;color:#333;')
        ]);
    }
    echo '</div>';
endif;
?>

<?php endif; ?>

</div>

<?php echo $OUTPUT->footer();
