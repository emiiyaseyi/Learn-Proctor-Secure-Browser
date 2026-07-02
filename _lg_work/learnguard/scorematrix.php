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
 * One row per student; columns grouped by course then assessment.
 * Each assessment shows three sub-columns: Score | Att # | Completed.
 * Sorting by Name / Total / Average / Most Recent via clickable headers.
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

// ── Assessments — optionally filtered to one course ──────────────────────────
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

// Group assessments by course for header rendering.
$byCourse = []; // [courseid => ['name'=>..., 'assessments'=>[...]]]
foreach ($assessments as $a) {
    if (!isset($byCourse[$a->courseid])) {
        $byCourse[$a->courseid] = ['name' => $a->coursename, 'assessments' => []];
    }
    $byCourse[$a->courseid]['assessments'][] = $a;
}

// ── Cells — one entry per (user, assessment) ─────────────────────────────────
$cellMap  = [];
$students = [];
if (!empty($assessments)) {
    [$insql, $inparams] = $DB->get_in_or_equal(array_keys($assessments), SQL_PARAMS_NAMED, 'lg');

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
           JOIN (SELECT learnguard, userid, MAX(timefinish) AS maxtf
                   FROM {learnguard_attempts}
                  WHERE state = 'finished' AND learnguard $insql
               GROUP BY learnguard, userid) latest
             ON latest.learnguard = a.learnguard AND latest.userid = a.userid AND latest.maxtf = a.timefinish
          WHERE a.state = 'finished'",
        $inparams
    );
    foreach ($cellRS as $row) {
        $cellMap[$row->userid][$row->learnguard] = [
            'sumgrades'  => $row->sumgrades !== null ? (float)$row->sumgrades : null,
            'attempt'    => (int)$row->attempt,
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

// ── Per-student aggregates for sorting ───────────────────────────────────────
$studentMeta = [];
foreach ($students as $stu) {
    $sum = 0.0; $cnt = 0; $latest = 0;
    foreach ($assessments as $a) {
        $cell = $cellMap[$stu->id][$a->id] ?? null;
        if ($cell && $cell['sumgrades'] !== null) {
            $sum += $cell['sumgrades'];
            $cnt++;
        }
        if ($cell && $cell['timefinish'] > $latest) {
            $latest = $cell['timefinish'];
        }
    }
    $studentMeta[$stu->id] = [
        'total'  => $sum,
        'count'  => $cnt,
        'avg'    => $cnt > 0 ? $sum / $cnt : -1,
        'latest' => $latest,
    ];
}

// ── Sort students ─────────────────────────────────────────────────────────────
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
    $header = ['First Name', 'Last Name', 'Email'];
    foreach ($byCourse as $cid => $courseData) {
        foreach ($courseData['assessments'] as $a) {
            $aLabel = format_string($a->name) . ' [' . format_string($a->coursename) . '] (/' . (float)$a->maxgrade . ')';
            $header[] = $aLabel . ' — Score';
            $header[] = $aLabel . ' — Attempt #';
        }
        $header[] = format_string($courseData['name']) . ' — Last Completed';
    }
    $header[] = 'Total Score';
    $header[] = 'Average Score';
    $header[] = 'Overall Last Completed';
    fputcsv($fh, $header);

    foreach ($students as $stu) {
        $row = [$stu->firstname, $stu->lastname, $stu->email];
        $sum = 0.0; $count = 0;
        foreach ($byCourse as $cid => $courseData) {
            $courseLatest = 0;
            foreach ($courseData['assessments'] as $a) {
                $cell  = $cellMap[$stu->id][$a->id] ?? null;
                $score = $cell ? $cell['sumgrades'] : null;
                $attNo = $cell ? $cell['attempt']   : null;
                $tf    = $cell ? $cell['timefinish'] : 0;
                $row[] = $score !== null ? number_format($score, 2, '.', '') : '';
                $row[] = $attNo          ? $attNo                            : '';
                if ($score !== null) { $sum += $score; $count++; }
                if ($tf > $courseLatest) { $courseLatest = $tf; }
            }
            $row[] = $courseLatest ? userdate($courseLatest, '%d %b %Y %H:%M') : '';
        }
        $meta  = $studentMeta[$stu->id];
        $row[] = $count > 0      ? number_format($sum, 2, '.', '')            : '';
        $row[] = $count > 0      ? number_format($sum / $count, 2, '.', '')   : '';
        $row[] = $meta['latest'] ? userdate($meta['latest'], '%d %b %Y %H:%M') : '';
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
    'Cross-course view — one row per student, one group of columns per assessment (Score | Attempt # | Completed).', '🏆');

$allCourses  = $DB->get_records_sql("SELECT DISTINCT c.id, c.fullname FROM {course} c
    JOIN {learnguard} lg ON lg.course = c.id ORDER BY c.fullname");
$exportParams = array_filter(['filter_course' => $filterCourse ?: null, 'sort' => $sortKey]);

// ── Helper: build a sort URL ──────────────────────────────────────────────────
function sm_sort_url(string $key, int $filterCourse): string {
    $p = ['sort' => $key];
    if ($filterCourse > 0) { $p['filter_course'] = $filterCourse; }
    return (new moodle_url('/mod/learnguard/scorematrix.php', $p))->out(false);
}

// Arrow indicators.
function sm_arrow(string $col, string $sortKey): string {
    $up   = ['name_asc', 'total_asc', 'avg_asc', 'recent_asc'];
    $down = ['name_desc', 'total_desc', 'avg_desc', 'recent_desc'];
    if (strpos($sortKey, $col) === 0) {
        return in_array($sortKey, $down) ? ' <span style="color:#0f6cbf;">▼</span>' : ' <span style="color:#0f6cbf;">▲</span>';
    }
    return ' <span style="color:#ced4da;font-size:10px;">⇅</span>';
}

?>
<!-- ── Filter / Sort bar ── -->
<form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:18px;
      background:#f8f9fa;padding:14px 16px;border-radius:8px;border:1px solid #dee2e6;">
  <div style="display:flex;flex-direction:column;gap:4px;">
    <label style="font-size:11px;font-weight:700;text-transform:uppercase;color:#495057;">Filter by Course</label>
    <select name="filter_course" class="form-control form-control-sm" style="max-width:260px;" onchange="this.form.submit()">
      <option value="0">— All Courses —</option>
      <?php foreach ($allCourses as $c): ?>
      <option value="<?php echo $c->id; ?>" <?php echo ($filterCourse == $c->id ? 'selected' : ''); ?>>
        <?php echo s(format_string($c->fullname)); ?>
      </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div style="display:flex;flex-direction:column;gap:4px;">
    <label style="font-size:11px;font-weight:700;text-transform:uppercase;color:#495057;">Sort By</label>
    <select name="sort" class="form-control form-control-sm" style="max-width:240px;" onchange="this.form.submit()">
      <option value="name_asc"    <?php echo $sortKey === 'name_asc'    ? 'selected' : ''; ?>>Name — A → Z</option>
      <option value="name_desc"   <?php echo $sortKey === 'name_desc'   ? 'selected' : ''; ?>>Name — Z → A</option>
      <option value="total_desc"  <?php echo $sortKey === 'total_desc'  ? 'selected' : ''; ?>>Total Score — Highest first</option>
      <option value="total_asc"   <?php echo $sortKey === 'total_asc'   ? 'selected' : ''; ?>>Total Score — Lowest first</option>
      <option value="avg_desc"    <?php echo $sortKey === 'avg_desc'    ? 'selected' : ''; ?>>Average Score — Highest first</option>
      <option value="avg_asc"     <?php echo $sortKey === 'avg_asc'     ? 'selected' : ''; ?>>Average Score — Lowest first</option>
      <option value="recent_desc" <?php echo $sortKey === 'recent_desc' ? 'selected' : ''; ?>>Most Recently Completed</option>
      <option value="recent_asc"  <?php echo $sortKey === 'recent_asc'  ? 'selected' : ''; ?>>Oldest Completion First</option>
    </select>
  </div>
  <button type="submit" class="btn btn-primary btn-sm" style="align-self:flex-end;">🔍 Apply</button>
  <?php if ($filterCourse || $sortKey !== 'name_asc'): ?>
  <a href="<?php echo (new moodle_url('/mod/learnguard/scorematrix.php'))->out(false); ?>"
     class="btn btn-outline-secondary btn-sm" style="align-self:flex-end;">✕ Reset</a>
  <?php endif; ?>
  <div style="margin-left:auto;align-self:flex-end;">
    <?php echo html_writer::link(
        new moodle_url('/mod/learnguard/scorematrix.php', $exportParams + ['download' => 'csv']),
        '⬇ Export CSV', ['class' => 'btn btn-outline-secondary btn-sm']
    ); ?>
  </div>
</form>

<?php
if (empty($assessments)) {
    echo html_writer::div('No LearnGuard assessments found' . ($filterCourse ? ' in this course.' : ' on this site.'),
        'alert alert-info');
} elseif (empty($students)) {
    echo html_writer::div('No attempts found for these assessments yet.', 'alert alert-info');
} else {

    // ── Table legend ────────────────────────────────────────────────────────
    echo '<div style="font-size:11px;color:#6c757d;margin-bottom:10px;display:flex;gap:16px;flex-wrap:wrap;">';
    echo '<span><strong>Score</strong> = latest finished attempt score &nbsp;·&nbsp;</span>';
    echo '<span><strong>Att #</strong> = which attempt number (1, 2, 3…). '
        . '<span style="background:#fff3cd;color:#856404;padding:1px 4px;border-radius:3px;">2+</span> highlighted in amber</span>';
    echo '<span>&nbsp;·&nbsp; <strong style="color:#ffc107;">Last Completed</strong> = most recent completion date across all assessments in that course</span>';
    echo '<span>&nbsp;·&nbsp; <span style="color:#adb5bd;">—</span> = no attempt &nbsp;·&nbsp; ⏳ = essays pending grading</span>';
    echo '</div>';

    echo '<div style="overflow-x:auto;">';
    echo '<table class="generaltable" id="lgsm-table" '
        . 'style="font-size:12px;border-collapse:collapse;min-width:100%;">';

    // ── Header row 1: Student + Course groups + summary columns ──────────────
    echo '<thead>';
    echo '<tr style="background:#343a40;color:#fff;">';

    // Student header (rowspan=2).
    $nameSortNext = ($sortKey === 'name_asc') ? 'name_desc' : 'name_asc';
    echo '<th rowspan="2" style="padding:8px 12px;vertical-align:middle;white-space:nowrap;'
        . 'border-right:2px solid #495057;min-width:160px;">';
    echo html_writer::link(sm_sort_url($nameSortNext, $filterCourse), 'Student' . sm_arrow('name', $sortKey),
        ['style' => 'color:#fff;font-weight:700;text-decoration:none;', 'title' => 'Click to sort by name']);
    echo '</th>';

    // Course-group headers: each assessment has 2 sub-cols (Score + Att#), plus 1 "Last Completed" col per course.
    foreach ($byCourse as $cid => $courseData) {
        $colSpan = count($courseData['assessments']) * 2 + 1; // 2 per assessment + 1 date col
        echo '<th colspan="' . $colSpan . '" style="padding:6px 10px;text-align:center;'
            . 'background:#495057;border-right:2px solid #6c757d;border-left:1px solid #6c757d;font-size:11px;">';
        echo '📚 ' . s(format_string($courseData['name']));
        echo '</th>';
    }

    // Summary columns (rowspan=2).
    $totalSortNext  = ($sortKey === 'total_desc')  ? 'total_asc'  : 'total_desc';
    $avgSortNext    = ($sortKey === 'avg_desc')    ? 'avg_asc'    : 'avg_desc';
    $recentSortNext = ($sortKey === 'recent_desc') ? 'recent_asc' : 'recent_desc';

    foreach ([
        ['total',  $totalSortNext,  'Total'],
        ['avg',    $avgSortNext,    'Average'],
        ['recent', $recentSortNext, 'Overall Last Completed'],
    ] as [$col, $nextSort, $label]) {
        echo '<th rowspan="2" style="padding:8px 10px;text-align:center;vertical-align:middle;'
            . 'white-space:nowrap;background:#212529;border-left:2px solid #6c757d;">';
        echo html_writer::link(sm_sort_url($nextSort, $filterCourse), $label . sm_arrow($col, $sortKey),
            ['style' => 'color:#fff;font-weight:700;text-decoration:none;font-size:11px;',
             'title' => 'Click to sort by ' . strtolower($label)]);
        echo '</th>';
    }
    echo '</tr>';

    // ── Header row 2: per-assessment sub-columns (Score | Att #) + 1 date per course ──
    echo '<tr style="background:#495057;color:#dee2e6;">';
    foreach ($byCourse as $cid => $courseData) {
        foreach ($courseData['assessments'] as $a) {
            $aLink = html_writer::link(
                new moodle_url('/mod/learnguard/scores.php', ['id' => $a->cmid]),
                s(format_string($a->name)),
                ['style' => 'color:#fff;font-size:11px;font-weight:700;', 'title' => 'View scores for this assessment']
            );
            $maxGrade = '/' . (float)$a->maxgrade;

            // Score sub-column.
            echo '<th style="padding:6px 8px;text-align:center;border-left:1px solid #6c757d;'
                . 'white-space:nowrap;min-width:80px;">';
            echo $aLink . '<br><small style="font-weight:400;color:#adb5bd;">' . $maxGrade . '</small>';
            echo '</th>';

            // Att # sub-column.
            echo '<th style="padding:6px 8px;text-align:center;white-space:nowrap;min-width:44px;'
                . 'color:#ced4da;font-weight:400;">';
            echo '<abbr title="Attempt number">Att #</abbr>';
            echo '</th>';
        }

        // One "Last Completed" column per course — shows most recent timefinish across all course assessments.
        echo '<th style="padding:6px 8px;text-align:center;white-space:nowrap;min-width:110px;'
            . 'color:#ffc107;font-weight:600;border-left:1px solid #6c757d;border-right:2px solid #6c757d;">';
        echo 'Last Completed<br><small style="font-weight:400;color:#adb5bd;font-size:9px;">in this course</small>';
        echo '</th>';
    }
    echo '</tr>';
    echo '</thead>';

    // ── Data rows ─────────────────────────────────────────────────────────────
    echo '<tbody>';
    $rowEven = false;
    foreach ($students as $stu) {
        $meta   = $studentMeta[$stu->id];
        $rowBg  = $rowEven ? 'background:#f8f9fa;' : '';
        $rowEven = !$rowEven;

        echo '<tr style="border-bottom:1px solid #e9ecef;' . $rowBg . '">';

        // Student cell.
        echo '<td style="padding:8px 12px;white-space:nowrap;border-right:2px solid #dee2e6;vertical-align:middle;">';
        echo '<strong style="font-size:13px;">' . s(fullname($stu)) . '</strong><br>';
        echo '<small style="color:#6c757d;">' . s($stu->email) . '</small>';
        echo '</td>';

        // Data cells grouped by course.
        foreach ($byCourse as $cid => $courseData) {
            // Find this course's most recent timefinish for this student.
            $courseLatest = 0;
            foreach ($courseData['assessments'] as $ca) {
                $ccell = $cellMap[$stu->id][$ca->id] ?? null;
                if ($ccell && $ccell['timefinish'] > $courseLatest) {
                    $courseLatest = $ccell['timefinish'];
                }
            }

            foreach ($courseData['assessments'] as $a) {
                $cell      = $cellMap[$stu->id][$a->id] ?? null;
                $score     = $cell ? $cell['sumgrades'] : null;
                $attemptNo = $cell ? $cell['attempt']   : null;

                if ($score !== null) {
                    // Score cell.
                    $reportUrl = new moodle_url('/mod/learnguard/grader_user_report.php',
                        ['id' => $a->cmid, 'userid' => $stu->id]);
                    echo '<td style="padding:7px 8px;text-align:center;vertical-align:middle;border-left:1px solid #e9ecef;">';
                    echo html_writer::link($reportUrl, '<strong>' . number_format($score, 2) . '</strong>',
                        ['style' => 'color:#0f6cbf;', 'title' => 'Click to view attempt detail']);
                    echo '</td>';

                    // Attempt # cell.
                    $attStyle = $attemptNo > 1 ? 'background:#fff3cd;color:#856404;font-weight:700;padding:1px 5px;border-radius:3px;'
                                               : 'color:#495057;';
                    echo '<td style="padding:7px 8px;text-align:center;vertical-align:middle;">';
                    echo '<span style="' . $attStyle . '">' . (int)$attemptNo . '</span>';
                    echo '</td>';
                } elseif ($cell) {
                    // Attempt exists but essays pending (score null).
                    echo '<td style="padding:7px 8px;text-align:center;border-left:1px solid #e9ecef;color:#856404;">⏳</td>';
                    echo '<td style="padding:7px 8px;text-align:center;color:#856404;">' . (int)($cell['attempt'] ?? 0) . '</td>';
                } else {
                    // No attempt at all.
                    echo '<td style="padding:7px 8px;text-align:center;border-left:1px solid #e9ecef;color:#dee2e6;">—</td>';
                    echo '<td style="padding:7px 8px;text-align:center;color:#dee2e6;">—</td>';
                }
            }

            // One "Last Completed" cell per course — most recent timefinish across all course assessments.
            echo '<td style="padding:7px 10px;text-align:center;vertical-align:middle;white-space:nowrap;'
                . 'border-left:1px solid #e9ecef;border-right:2px solid #dee2e6;">';
            if ($courseLatest > 0) {
                echo '<span style="font-size:11px;color:#495057;font-weight:600;">'
                    . userdate($courseLatest, '%d %b %Y') . '</span><br>';
                echo '<small style="color:#6c757d;">' . userdate($courseLatest, '%H:%M') . '</small>';
            } else {
                echo '<span style="color:#dee2e6;">—</span>';
            }
            echo '</td>';
        }

        // Summary: Total.
        echo '<td style="padding:7px 10px;text-align:center;vertical-align:middle;border-left:2px solid #dee2e6;'
            . 'background:' . ($rowEven ? '#f0f4f8' : '#e8eef4') . ';">';
        echo $meta['count'] > 0
            ? '<strong style="color:#198754;">' . number_format($meta['total'], 2) . '</strong>'
            : '<span style="color:#dee2e6;">—</span>';
        echo '</td>';

        // Summary: Average.
        echo '<td style="padding:7px 10px;text-align:center;vertical-align:middle;'
            . 'background:' . ($rowEven ? '#f0f4f8' : '#e8eef4') . ';">';
        echo $meta['count'] > 0
            ? '<strong>' . number_format($meta['avg'], 2) . '</strong>'
            : '<span style="color:#dee2e6;">—</span>';
        echo '</td>';

        // Summary: Last Completed.
        echo '<td style="padding:7px 10px;text-align:center;vertical-align:middle;white-space:nowrap;'
            . 'background:' . ($rowEven ? '#f0f4f8' : '#e8eef4') . ';">';
        if ($meta['latest'] > 0) {
            echo '<span style="font-size:11px;">' . userdate($meta['latest'], '%d %b %Y') . '</span><br>';
            echo '<small style="color:#6c757d;">' . userdate($meta['latest'], '%H:%M') . '</small>';
        } else {
            echo '<span style="color:#dee2e6;">—</span>';
        }
        echo '</td>';

        echo '</tr>';
    }
    echo '</tbody>';
    echo '</table>';
    echo '</div>';

    echo html_writer::tag('p',
        count($students) . ' student(s) &nbsp;·&nbsp; '
        . count($assessments) . ' assessment(s) across ' . count($byCourse) . ' course(s)'
        . ' &nbsp;·&nbsp; Sorted by: <strong>'
        . s(['name_asc' => 'Name (A→Z)', 'name_desc' => 'Name (Z→A)',
             'total_desc' => 'Total ↓', 'total_asc' => 'Total ↑',
             'avg_desc' => 'Average ↓', 'avg_asc' => 'Average ↑',
             'recent_desc' => 'Most Recent', 'recent_asc' => 'Oldest First'][$sortKey] ?? $sortKey)
        . '</strong>',
        ['class' => 'text-muted mt-2', 'style' => 'font-size:11px;']);
}

echo $OUTPUT->footer();
