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
require_capability('moodle/site:config', context_system::instance());

$filterCourse = optional_param('filter_course', 0, PARAM_INT);
$sortKey      = optional_param('sort', 'name_asc', PARAM_ALPHANUMEXT);
$download     = optional_param('download', '', PARAM_ALPHA);

$validSorts = ['name_asc', 'name_desc', 'total_desc', 'total_asc', 'avg_desc', 'avg_asc', 'recent_desc', 'recent_asc'];
if (!in_array($sortKey, $validSorts)) { $sortKey = 'name_asc'; }

// ── Assessments ───────────────────────────────────────────────────────────────
$params    = [];
$coursesql = '';
if ($filterCourse > 0) {
    $coursesql          = ' AND c.id = :courseid';
    $params['courseid'] = $filterCourse;
}
$assessments = $DB->get_records_sql(
    "SELECT lg.id, lg.name, lg.grade AS maxgrade, c.id AS courseid, c.fullname AS coursename, cm.id AS cmid
       FROM {learnguard} lg
       JOIN {course} c ON c.id = lg.course
       JOIN {modules} mdl ON mdl.name = 'learnguard'
       JOIN {course_modules} cm ON cm.instance = lg.id AND cm.module = mdl.id
      WHERE 1=1$coursesql
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

// ── Cells ─────────────────────────────────────────────────────────────────────
$cellMap  = [];
$students = [];
if (!empty($assessments)) {
    [$insql, $inparams] = $DB->get_in_or_equal(array_keys($assessments), SQL_PARAMS_NAMED, 'lg');

    // Fetch ALL finished attempts so the matrix shows every attempt score, not just the latest.
    $cellRS = $DB->get_recordset_sql(
        "SELECT a.learnguard, a.userid, a.attempt, a.timefinish,
                CASE WHEN EXISTS (
                    SELECT 1
                      FROM {learnguard_slots} es
                      JOIN {learnguard_qbank} eq ON eq.id = es.questionid AND eq.qtype = 'essay'
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
          WHERE a.state = 'finished' AND a.learnguard $insql
          ORDER BY a.userid, a.learnguard, a.attempt ASC",
        $inparams
    );
    foreach ($cellRS as $row) {
        // Array of all attempts per user per assessment, keyed by attempt number.
        $cellMap[$row->userid][$row->learnguard][(int)$row->attempt] = [
            'sumgrades'  => $row->sumgrades !== null ? (float)$row->sumgrades : null,
            'timefinish' => (int)$row->timefinish,
        ];
    }
    $cellRS->close();

    $students = $DB->get_records_sql(
        "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.username
           FROM {learnguard_attempts} a
           JOIN {user} u ON u.id = a.userid
          WHERE a.learnguard $insql
       ORDER BY u.lastname, u.firstname",
        $inparams
    );
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
        'avg'    => $cnt > 0 ? $sum / $cnt : -1,
        'latest' => $latest,
    ];
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
    $hdr = ['First Name', 'Last Name', 'Email'];
    foreach ($byCourse as $cid => $courseData) {
        foreach ($courseData['assessments'] as $a) {
            $hdr[] = format_string($a->name) . ' [' . format_string($a->coursename) . '] Score (/' . (float)$a->maxgrade . ')';
        }
        $hdr[] = format_string($courseData['name']) . ' — Last Completed';
    }
    $hdr[] = 'Total Score';
    $hdr[] = 'Average Score';
    $hdr[] = 'Overall Last Completed';
    fputcsv($fh, $hdr);

    foreach ($students as $stu) {
        $row = [$stu->firstname, $stu->lastname, $stu->email];
        $sum = 0.0; $count = 0;
        foreach ($byCourse as $cid => $courseData) {
            $courseLatest = 0;
            foreach ($courseData['assessments'] as $a) {
                $attempts = $cellMap[$stu->id][$a->id] ?? [];
                $latestScore = null; $parts = [];
                foreach ($attempts as $attNum => $att) {
                    $s = $att['sumgrades'];
                    $parts[] = 'Att' . $attNum . ':' . ($s !== null ? number_format($s, 2, '.', '') : '⏳');
                    if ($s !== null) { $latestScore = $s; }
                    if ($att['timefinish'] > $courseLatest) { $courseLatest = $att['timefinish']; }
                }
                $row[] = !empty($parts) ? implode(' | ', $parts) : '';
                if ($latestScore !== null) { $sum += $latestScore; $count++; }
            }
            $row[] = $courseLatest ? userdate($courseLatest, '%d %b %Y %H:%M') : '';
        }
        $meta  = $studentMeta[$stu->id];
        $row[] = $count > 0      ? number_format($sum, 2, '.', '')                      : '';
        $row[] = $count > 0      ? number_format($sum / $count, 2, '.', '')             : '';
        $row[] = $meta['latest'] ? userdate($meta['latest'], '%d %b %Y %H:%M')          : '';
        fputcsv($fh, $row);
    }
    fclose($fh);
    exit;
}

// ── Page setup ────────────────────────────────────────────────────────────────
$PAGE->set_url('/mod/learnguard/scorematrix.php', array_filter([
    'filter_course' => $filterCourse ?: null,
    'sort'          => $sortKey !== 'name_asc' ? $sortKey : null,
]));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('LearnGuard — Score Matrix');
$PAGE->set_heading('LearnGuard — Score Matrix');
$PAGE->requires->css('/mod/learnguard/styles.css');

echo $OUTPUT->header();
echo learnguard_nav_bar(0, '🏆 Score Matrix');
echo learnguard_banner('Score Matrix',
    'Cross-course view of every student\'s score across all LearnGuard assessments.', '🏆');

$allCourses   = $DB->get_records_sql("SELECT DISTINCT c.id, c.fullname FROM {course} c
    JOIN {learnguard} lg ON lg.course = c.id ORDER BY c.fullname");
$exportParams = array_filter(['filter_course' => $filterCourse ?: null, 'sort' => $sortKey]);

// Sort URL helper.
function sm_sort_url(string $key, int $filterCourse): string {
    $p = ['sort' => $key];
    if ($filterCourse > 0) { $p['filter_course'] = $filterCourse; }
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
  <div style="align-self:flex-end;display:flex;gap:6px;">
    <button type="submit" class="btn btn-primary btn-sm">🔍 Apply</button>
    <?php if ($filterCourse || $sortKey !== 'name_asc'): ?>
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
        . 'All finished attempt scores are shown per cell — ① = attempt 1, ② = attempt 2, etc. '
        . '<strong>Last Completed</strong> = most recent completion date for any assessment in that course. '
        . 'Total &amp; Average use the most recent attempt score per assessment. '
        . '⏳ = essays still awaiting grading. '
        . '<span style="color:#adb5bd;">—</span> = no attempt yet.'
        . '</p>';

    // ── Sort URL helpers ──────────────────────────────────────────────────
    $nameSortNext   = ($sortKey === 'name_asc')    ? 'name_desc'   : 'name_asc';
    $totalSortNext  = ($sortKey === 'total_desc')  ? 'total_asc'   : 'total_desc';
    $avgSortNext    = ($sortKey === 'avg_desc')    ? 'avg_asc'     : 'avg_desc';
    $recentSortNext = ($sortKey === 'recent_desc') ? 'recent_asc'  : 'recent_desc';

    echo '<div style="overflow-x:auto;">';
    echo '<table class="generaltable" style="white-space:nowrap;font-size:13px;">';
    echo '<thead>';

    // ── Row 1: Student (rowspan=2) | Course groups | Summary (rowspan=2) ──
    echo '<tr>';
    echo '<th rowspan="2" style="vertical-align:bottom;padding:6px 10px;">';
    echo html_writer::link(sm_sort_url($nameSortNext, $filterCourse),
        'Student' . sm_arrow('name', $sortKey),
        ['style' => 'color:inherit;text-decoration:none;font-weight:700;']);
    echo '</th>';

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
    echo html_writer::link(sm_sort_url($totalSortNext, $filterCourse),
        'Total' . sm_arrow('total', $sortKey),
        ['style' => 'color:inherit;text-decoration:none;font-weight:700;']);
    echo '</th>';
    echo '<th rowspan="2" style="vertical-align:bottom;text-align:center;padding:6px 8px;">';
    echo html_writer::link(sm_sort_url($avgSortNext, $filterCourse),
        'Average' . sm_arrow('avg', $sortKey),
        ['style' => 'color:inherit;text-decoration:none;font-weight:700;']);
    echo '</th>';
    echo '<th rowspan="2" style="vertical-align:bottom;text-align:center;padding:6px 8px;">';
    echo html_writer::link(sm_sort_url($recentSortNext, $filterCourse),
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

    // ── Data rows ─────────────────────────────────────────────────────────
    echo '<tbody>';
    foreach ($students as $stu) {
        $meta = $studentMeta[$stu->id];
        echo '<tr>';

        // Student name.
        echo '<td style="padding:6px 10px;white-space:nowrap;">';
        echo '<strong>' . s(fullname($stu)) . '</strong><br>';
        echo '<small class="text-muted">' . s($stu->email) . '</small>';
        echo '</td>';

        // Per-course data.
        foreach ($byCourse as $cid => $courseData) {
            $courseLatest = 0;

            foreach ($courseData['assessments'] as $a) {
                $attempts = $cellMap[$stu->id][$a->id] ?? [];
                $reportUrl = new moodle_url('/mod/learnguard/grader_user_report.php',
                    ['id' => $a->cmid, 'userid' => $stu->id]);

                foreach ($attempts as $att) {
                    if ($att['timefinish'] > $courseLatest) { $courseLatest = $att['timefinish']; }
                }

                echo '<td style="text-align:center;padding:4px 8px;border-left:1px solid #f0f0f0;vertical-align:middle;">';
                if (empty($attempts)) {
                    echo '<span class="text-muted">—</span>';
                } else {
                    // Stack all attempts: ① 5.00 / ② 6.00 etc.
                    $circledNums = ['①','②','③','④','⑤','⑥','⑦','⑧','⑨','⑩'];
                    $lines = [];
                    foreach ($attempts as $attNum => $att) {
                        $badge = $circledNums[min($attNum - 1, 9)] ?? '#' . $attNum;
                        if ($att['sumgrades'] !== null) {
                            $lines[] = '<a href="' . $reportUrl . '" style="color:#0f6cbf;text-decoration:none;white-space:nowrap;">'
                                     . '<span style="font-size:11px;color:#6c757d;margin-right:2px;">' . $badge . '</span>'
                                     . '<strong>' . number_format($att['sumgrades'], 2) . '</strong></a>';
                        } else {
                            $lines[] = '<span style="white-space:nowrap;">'
                                     . '<span style="font-size:11px;color:#6c757d;margin-right:2px;">' . $badge . '</span>'
                                     . '<span class="text-warning" title="Essays pending grading">⏳</span></span>';
                        }
                    }
                    echo implode('<br>', $lines);
                }
                echo '</td>';
            }

            // Last Completed cell (one per course).
            echo '<td style="text-align:center;padding:6px 8px;border-left:1px solid #f0f0f0;'
                . 'border-right:2px solid #ced4da;">';
            if ($courseLatest > 0) {
                echo '<span style="font-size:11px;">' . userdate($courseLatest, '%d %b %Y') . '</span><br>';
                echo '<small class="text-muted">' . userdate($courseLatest, '%H:%M') . '</small>';
            } else {
                echo '<span class="text-muted">—</span>';
            }
            echo '</td>';
        }

        // Total.
        echo '<td style="text-align:center;padding:6px 8px;border-left:2px solid #ced4da;">';
        echo $meta['count'] > 0
            ? '<strong>' . number_format($meta['total'], 2) . '</strong>'
            : '<span class="text-muted">—</span>';
        echo '</td>';

        // Average.
        echo '<td style="text-align:center;padding:6px 8px;">';
        echo $meta['count'] > 0
            ? '<strong>' . number_format($meta['avg'], 2) . '</strong>'
            : '<span class="text-muted">—</span>';
        echo '</td>';

        // Overall Last Completed.
        echo '<td style="text-align:center;padding:6px 8px;white-space:nowrap;">';
        if ($meta['latest'] > 0) {
            echo '<span style="font-size:11px;">' . userdate($meta['latest'], '%d %b %Y') . '</span><br>';
            echo '<small class="text-muted">' . userdate($meta['latest'], '%H:%M') . '</small>';
        } else {
            echo '<span class="text-muted">—</span>';
        }
        echo '</td>';

        echo '</tr>';
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
}

echo $OUTPUT->footer();
