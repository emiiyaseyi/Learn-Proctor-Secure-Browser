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
$dateFrom     = optional_param('date_from', '', PARAM_TEXT);
$dateTo       = optional_param('date_to',   '', PARAM_TEXT);
// Completed-date range filter: only attempts whose timefinish falls in this window
// are included anywhere below — same $cellMap feeds both the on-screen table AND the
// CSV export, so applying the filter once here keeps both in lockstep automatically.
$dateFromTs = ($dateFrom !== '' && strtotime($dateFrom) !== false) ? strtotime($dateFrom . ' 00:00:00') : 0;
$dateToTs   = ($dateTo   !== '' && strtotime($dateTo)   !== false) ? strtotime($dateTo   . ' 23:59:59') : 0;

$validSorts = ['name_asc', 'name_desc', 'total_desc', 'total_asc', 'avg_desc', 'avg_asc', 'recent_desc', 'recent_asc'];
if (!in_array($sortKey, $validSorts)) { $sortKey = 'name_asc'; }

// Void an attempt's score directly from the matrix (same effect as grader.php's
// "Void" action — sets state='voided' so it stops counting toward totals/averages
// and the student can be granted a reattempt; history is preserved, not deleted).
if (data_submitted() && confirm_sesskey() && optional_param('smaction', '', PARAM_ALPHA) === 'voidattempt') {
    $vaid     = required_param('vaid', PARAM_INT);
    $vattempt = $DB->get_record('learnguard_attempts', ['id' => $vaid], '*', IGNORE_MISSING);
    if ($vattempt && in_array($vattempt->state, ['finished', 'suspended'])) {
        $DB->set_field('learnguard_attempts', 'state', 'voided', ['id' => $vaid]);
        \core\notification::success('Attempt score voided — it no longer counts toward totals.');
    }
    redirect(new moodle_url('/mod/learnguard/scorematrix.php', array_filter([
        'filter_course' => $filterCourse, 'sort' => $sortKey,
        'date_from' => $dateFrom, 'date_to' => $dateTo,
    ])));
}

// Bulk void — tick multiple score cells across the matrix, then Void Selected. Same
// effect as the single per-cell void above (state='voided', kept visible with a
// strikethrough), just for many attempts at once — e.g. cleaning up a burst of
// duplicate attempts caused by a since-fixed retry bug, without clicking one by one.
if (data_submitted() && confirm_sesskey() && optional_param('smaction', '', PARAM_ALPHA) === 'bulkvoid') {
    $bulkIds = optional_param_array('bulk_attid', [], PARAM_INT);
    $voided = 0;
    foreach ($bulkIds as $baid) {
        $battempt = $DB->get_record('learnguard_attempts', ['id' => (int)$baid], '*', IGNORE_MISSING);
        if ($battempt && in_array($battempt->state, ['finished', 'suspended'])) {
            $DB->set_field('learnguard_attempts', 'state', 'voided', ['id' => $battempt->id]);
            $voided++;
        }
    }
    redirect(new moodle_url('/mod/learnguard/scorematrix.php', array_filter([
        'filter_course' => $filterCourse, 'sort' => $sortKey,
        'date_from' => $dateFrom, 'date_to' => $dateTo,
    ])), $voided . ' attempt(s) voided — they no longer count toward totals.', null,
        \core\output\notification::NOTIFY_SUCCESS);
}

// Bulk delete — PERMANENTLY removes the selected attempts (and every row that
// references them) rather than just voiding them, so they disappear from this and
// every other report entirely. Unlike void, this cannot be undone.
if (data_submitted() && confirm_sesskey() && optional_param('smaction', '', PARAM_ALPHA) === 'bulkdelete') {
    $bulkIds = optional_param_array('bulk_attid', [], PARAM_INT);
    $deleted = 0;
    foreach ($bulkIds as $baid) {
        $baid = (int)$baid;
        if ($baid && $DB->record_exists('learnguard_attempts', ['id' => $baid])) {
            learnguard_delete_single_attempt($baid);
            $deleted++;
        }
    }
    redirect(new moodle_url('/mod/learnguard/scorematrix.php', array_filter([
        'filter_course' => $filterCourse, 'sort' => $sortKey,
        'date_from' => $dateFrom, 'date_to' => $dateTo,
    ])), $deleted . ' attempt(s) permanently deleted.', null,
        \core\output\notification::NOTIFY_SUCCESS);
}

// ── Assessments ───────────────────────────────────────────────────────────────
$params    = [];
$coursesql = '';
if ($filterCourse > 0) {
    $coursesql          = ' AND c.id = :courseid';
    $params['courseid'] = $filterCourse;
}
// cm.deletioninprogress: Moodle's course_delete_module() marks this flag immediately
// when a module is deleted from the course UI, but the actual row deletion (and this
// plugin's own learnguard_delete_instance() cascade) only completes when core's adhoc
// deletion task next runs via cron — which can be minutes to hours later. Without this
// filter, a "deleted" assessment kept showing here (and inflating the Average
// denominator) for as long as it sat waiting to be purged, even though it had already
// disappeared from the course itself.
$assessments = $DB->get_records_sql(
    "SELECT lg.id, lg.name, lg.grade AS maxgrade, c.id AS courseid, c.fullname AS coursename, cm.id AS cmid
       FROM {learnguard} lg
       JOIN {course} c ON c.id = lg.course
       JOIN {modules} mdl ON mdl.name = 'learnguard'
       JOIN {course_modules} cm ON cm.instance = lg.id AND cm.module = mdl.id
      WHERE cm.deletioninprogress = 0$coursesql
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

// Denominator for the Average column: total max possible across EVERY assessment
// shown in the matrix (same for every row, since the columns are the same for every
// student), not just the ones a given student has a score for. Otherwise a student
// who only completed 1 of 2 assessments shows the same average as one who completed
// both — e.g. 100/100 on one assessment reads as "100.00" instead of the true 50%
// (100 gotten out of 200 possible across both assessments).
$totalMaxPossible = array_sum(array_column($assessments, 'maxgrade'));

// ── Cells ─────────────────────────────────────────────────────────────────────
$cellMap  = [];
$students = [];
$suspendStatus = [];
if (!empty($assessments)) {
    [$insql, $inparams] = $DB->get_in_or_equal(array_keys($assessments), SQL_PARAMS_NAMED, 'lg');

    // Completed-date range, applied only when set — an in-progress attempt (timefinish=0)
    // naturally falls outside any real range and is excluded, which is the correct
    // behaviour: a date filter means "scores completed in this window."
    $dateWhere = '';
    if ($dateFromTs > 0) { $dateWhere .= ' AND a.timefinish >= :datefromts'; $inparams['datefromts'] = $dateFromTs; }
    if ($dateToTs   > 0) { $dateWhere .= ' AND a.timefinish <= :datets';    $inparams['datets']      = $dateToTs; }

    // Fetch ALL finished+voided attempts ordered by id ASC (not attempt column — that field
    // can repeat when admin voids an attempt and grants a reattempt, resetting it to 1).
    // Voided attempts are included so that attempt numbering is preserved (#1 voided, #2 finished)
    // and historical data is visible. Voided cells render with a strikethrough indicator.
    $rawCells = [];
    $cellRS = $DB->get_recordset_sql(
        "SELECT a.learnguard, a.userid, a.id, a.timefinish, a.state,
                CASE WHEN a.state = 'voided' THEN a.sumgrades
                     WHEN EXISTS (
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
          WHERE a.state IN ('finished', 'voided', 'inprogress') AND a.learnguard $insql$dateWhere
          ORDER BY a.userid, a.learnguard, a.id ASC",
        $inparams
    );
    foreach ($cellRS as $row) {
        $rawCells[$row->userid][$row->learnguard][] = [
            'id'          => (int)$row->id,
            'sumgrades'   => $row->sumgrades !== null ? (float)$row->sumgrades : null,
            'timefinish'  => (int)$row->timefinish,
            'voided'      => ($row->state === 'voided'),
            'inprogress'  => ($row->state === 'inprogress'),
        ];
    }
    $cellRS->close();

    // Re-key as 1-based sequential attempt numbers in id order so duplicate attempt
    // numbers in the DB (caused by the void+reattempt flow) don't collapse into one cell.
    $cellMap = [];
    foreach ($rawCells as $uid => $lgMap) {
        foreach ($lgMap as $lgid => $attempts) {
            foreach ($attempts as $idx => $att) {
                $cellMap[$uid][$lgid][$idx + 1] = $att;
            }
        }
    }

    $students = $DB->get_records_sql(
        "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.username
           FROM {learnguard_attempts} a
           JOIN {user} u ON u.id = a.userid
          WHERE a.learnguard $insql$dateWhere
       ORDER BY u.lastname, u.firstname",
        $inparams
    );

    // Suspension status per student — 'confirmed' (is_flagged=1) or 'pending' (still
    // awaiting a decision), used to colour their name in the table and CSV below.
    // Deliberately NOT date-filtered — a currently suspended attempt has no completion
    // date (timefinish=0) to filter on, and this reflects current status, not "did they
    // complete something in this window."
    [$susInsql, $susInparams] = $DB->get_in_or_equal(array_keys($assessments), SQL_PARAMS_NAMED, 'sus');
    $susRows = $DB->get_records_sql(
        "SELECT userid, MAX(CASE WHEN is_flagged = 1 THEN 2 ELSE 1 END) AS lvl
           FROM {learnguard_attempts}
          WHERE learnguard $susInsql AND state = 'suspended'
       GROUP BY userid",
        $susInparams
    );
    $suspendStatus = [];
    foreach ($susRows as $r) {
        $suspendStatus[(int)$r->userid] = ((int)$r->lvl === 2) ? 'confirmed' : 'pending';
    }
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
        // Same fix as the displayed Average column: percentage of total possible across
        // ALL assessments, not just the ones this student has a score for.
        'avg'    => ($cnt > 0 && $totalMaxPossible > 0) ? ($sum / $totalMaxPossible) * 100 : -1,
        'latest' => $latest,
    ];
}

// Max attempt number per student (for per-attempt row output).
$maxAttemptsPerStudent = [];
foreach ($cellMap as $uid => $lgMap) {
    $mx = 0;
    foreach ($lgMap as $lgid => $attMap) {
        foreach ($attMap as $attNum => $att) {
            if ($attNum > $mx) { $mx = $attNum; }
        }
    }
    $maxAttemptsPerStudent[$uid] = $mx;
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
    $hdr = ['First Name', 'Last Name', 'Email', 'Attempt #'];
    foreach ($byCourse as $cid => $courseData) {
        foreach ($courseData['assessments'] as $a) {
            $hdr[] = format_string($a->name) . ' [' . format_string($a->coursename) . '] Score (/' . (float)$a->maxgrade . ')';
        }
        $hdr[] = format_string($courseData['name']) . ' — Last Completed';
    }
    $hdr[] = 'Total Score';
    $hdr[] = 'Average (% of total possible)';
    $hdr[] = 'Overall Last Completed';
    $hdr[] = 'Suspension Status';
    fputcsv($fh, $hdr);

    foreach ($students as $stu) {
        $maxAtt = $maxAttemptsPerStudent[$stu->id] ?? 0;
        $susCsvLabel = ($suspendStatus[$stu->id] ?? '') === 'confirmed' ? 'Confirmed Suspended'
                     : (($suspendStatus[$stu->id] ?? '') === 'pending' ? 'Suspended — Pending Review' : '');

        // Same display-numbering as the on-screen table: voided rows keep their
        // existing id-order position, active rows get their own independent 1,2,3…
        // count among just themselves.
        $activeCounter = 0;
        $csvDisplayNumMap = [];
        for ($an = 1; $an <= max(1, $maxAtt); $an++) {
            $rowVoided = false;
            foreach (($cellMap[$stu->id] ?? []) as $lgid => $lgAtts) {
                if (!empty($lgAtts[$an]['voided'])) { $rowVoided = true; break; }
            }
            if ($rowVoided) {
                $csvDisplayNumMap[$an] = $an;
            } else {
                $activeCounter++;
                $csvDisplayNumMap[$an] = $activeCounter;
            }
        }

        for ($attNum = 1; $attNum <= max(1, $maxAtt); $attNum++) {
            $row = [$stu->firstname, $stu->lastname, $stu->email, '#' . $csvDisplayNumMap[$attNum]];
            $rowSum = 0.0; $rowCount = 0; $rowLatest = 0;
            foreach ($byCourse as $cid => $courseData) {
                $courseLatest = 0;
                foreach ($courseData['assessments'] as $a) {
                    $att = $cellMap[$stu->id][$a->id][$attNum] ?? null;
                    if ($att === null) {
                        $row[] = '';
                    } elseif ($att['sumgrades'] !== null) {
                        $row[] = number_format($att['sumgrades'], 2, '.', '');
                        $rowSum += $att['sumgrades']; $rowCount++;
                        if ($att['timefinish'] > $courseLatest) { $courseLatest = $att['timefinish']; }
                        if ($att['timefinish'] > $rowLatest)   { $rowLatest    = $att['timefinish']; }
                    } else {
                        $row[] = '⏳';
                        if ($att['timefinish'] > $courseLatest) { $courseLatest = $att['timefinish']; }
                        if ($att['timefinish'] > $rowLatest)   { $rowLatest    = $att['timefinish']; }
                    }
                }
                $row[] = $courseLatest ? userdate($courseLatest, '%d %b %Y %H:%M') : '';
            }
            $row[] = $rowCount > 0 ? number_format($rowSum, 2, '.', '') : '';
            $row[] = ($rowCount > 0 && $totalMaxPossible > 0)
                ? number_format(($rowSum / $totalMaxPossible) * 100, 2, '.', '') . '%' : '';
            $row[] = $rowLatest ? userdate($rowLatest, '%d %b %Y %H:%M') : '';
            $row[] = $susCsvLabel;
            fputcsv($fh, $row);
        }
    }
    fclose($fh);
    exit;
}

// ── Page setup ────────────────────────────────────────────────────────────────
$PAGE->set_url('/mod/learnguard/scorematrix.php', array_filter([
    'filter_course' => $filterCourse ?: null,
    'sort'          => $sortKey !== 'name_asc' ? $sortKey : null,
    'date_from'     => $dateFrom ?: null,
    'date_to'       => $dateTo ?: null,
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
$exportParams = array_filter([
    'filter_course' => $filterCourse ?: null, 'sort' => $sortKey,
    'date_from' => $dateFrom ?: null, 'date_to' => $dateTo ?: null,
]);

// Sort URL helper. Preserves the current course/date filters so clicking a column
// header to re-sort doesn't silently drop them back to defaults.
function sm_sort_url(string $key, int $filterCourse, string $dateFrom = '', string $dateTo = ''): string {
    $p = ['sort' => $key];
    if ($filterCourse > 0) { $p['filter_course'] = $filterCourse; }
    if ($dateFrom !== '')  { $p['date_from'] = $dateFrom; }
    if ($dateTo   !== '')  { $p['date_to']   = $dateTo; }
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
  <div>
    <label style="font-size:11px;font-weight:700;display:block;margin-bottom:3px;">Completed From</label>
    <input type="date" name="date_from" value="<?php echo s($dateFrom); ?>"
        class="form-control form-control-sm" style="max-width:160px;">
  </div>
  <div>
    <label style="font-size:11px;font-weight:700;display:block;margin-bottom:3px;">Completed To</label>
    <input type="date" name="date_to" value="<?php echo s($dateTo); ?>"
        class="form-control form-control-sm" style="max-width:160px;">
  </div>
  <div style="align-self:flex-end;display:flex;gap:6px;">
    <button type="submit" class="btn btn-primary btn-sm">🔍 Apply</button>
    <?php if ($filterCourse || $sortKey !== 'name_asc' || $dateFrom || $dateTo): ?>
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
        . 'Each attempt is shown on its own row tagged with its attempt number (#1, #2 …). '
        . '<strong>Last Completed</strong> = completion date for that attempt. '
        . '<strong>Total</strong> = sum of scores gotten across assessments for that same attempt row. '
        . '<strong>Average</strong> = Total ÷ total possible across ALL assessments shown (not just the ones attempted) — '
        . 'so a student who only completed some assessments is not scored as if the rest didn\'t exist. '
        . '🗑 next to a score voids it (excludes it from totals; the attempt stays visible but voided). '
        . '⏳ = essays still awaiting grading. '
        . '<span style="color:#adb5bd;">—</span> = no attempt at that number yet. '
        . '<span style="color:#dc3545;">Red name</span> = confirmed suspended. '
        . '<span style="color:#ffc107;">Yellow name</span> = suspended, awaiting review.'
        . ($dateFrom || $dateTo
            ? ' <strong>Date filter active:</strong> only attempts completed '
              . ($dateFrom ? 'from ' . s($dateFrom) . ' ' : '') . ($dateTo ? 'to ' . s($dateTo) . ' ' : '')
              . 'are shown, totalled, and exported — students with no attempt in this window are omitted entirely.'
            : '')
        . '</p>';

    // ── Sort URL helpers ──────────────────────────────────────────────────
    $nameSortNext   = ($sortKey === 'name_asc')    ? 'name_desc'   : 'name_asc';
    $totalSortNext  = ($sortKey === 'total_desc')  ? 'total_asc'   : 'total_desc';
    $avgSortNext    = ($sortKey === 'avg_desc')    ? 'avg_asc'     : 'avg_desc';
    $recentSortNext = ($sortKey === 'recent_desc') ? 'recent_asc'  : 'recent_desc';

    // ── Bulk actions toolbar — tick score cells above (checkbox appears on every
    // finished/voided/essay-pending cell, not on in-progress ones), then Void or
    // Delete Selected. Void keeps the row visible (struck through, excluded from
    // totals); Delete permanently removes the attempt and everything tied to it, so
    // it disappears from this and every other report — e.g. cleaning up a burst of
    // duplicate attempts (a retry bug, a bulk-reattempt run more than once, etc.)
    // down to the one that should have existed. ──────────────────────────────────
    echo '<div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;flex-wrap:wrap;">';
    echo '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="smToggleAllChk(true)">☑ Select All</button>';
    echo '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="smToggleAllChk(false)">☐ Clear</button>';
    echo '<button type="button" class="btn btn-sm btn-warning" onclick="smBulkAction(\'bulkvoid\')">🗑 Void Selected</button>';
    echo '<button type="button" class="btn btn-sm btn-danger" onclick="smBulkAction(\'bulkdelete\')">❌ Delete Selected (permanent)</button>';
    echo '<span id="sm-bulk-count" style="font-size:12px;color:#6c757d;"></span>';
    echo '</div>';
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => '', 'id' => 'sm-bulk-form']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'smaction', 'id' => 'sm-bulk-action', 'value' => '']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'filter_course', 'value' => $filterCourse]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sort',          'value' => $sortKey]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'date_from',     'value' => $dateFrom]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'date_to',       'value' => $dateTo]);
    echo '<div id="sm-bulk-targets"></div>';
    echo html_writer::end_tag('form');

    echo '<div style="overflow-x:auto;">';
    echo '<table class="generaltable" style="white-space:nowrap;font-size:13px;">';
    echo '<thead>';

    // ── Row 1: Student (rowspan=2) | Course groups | Summary (rowspan=2) ──
    echo '<tr>';
    echo '<th rowspan="2" style="vertical-align:bottom;padding:6px 10px;">';
    echo html_writer::link(sm_sort_url($nameSortNext, $filterCourse, $dateFrom, $dateTo),
        'Student' . sm_arrow('name', $sortKey),
        ['style' => 'color:inherit;text-decoration:none;font-weight:700;']);
    echo '</th>';
    echo '<th rowspan="2" style="vertical-align:bottom;text-align:center;padding:6px 8px;color:#6c757d;">Att</th>';

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
    echo html_writer::link(sm_sort_url($totalSortNext, $filterCourse, $dateFrom, $dateTo),
        'Total' . sm_arrow('total', $sortKey),
        ['style' => 'color:inherit;text-decoration:none;font-weight:700;']);
    echo '</th>';
    echo '<th rowspan="2" style="vertical-align:bottom;text-align:center;padding:6px 8px;">';
    echo html_writer::link(sm_sort_url($avgSortNext, $filterCourse, $dateFrom, $dateTo),
        'Average' . sm_arrow('avg', $sortKey),
        ['style' => 'color:inherit;text-decoration:none;font-weight:700;']);
    echo '</th>';
    echo '<th rowspan="2" style="vertical-align:bottom;text-align:center;padding:6px 8px;">';
    echo html_writer::link(sm_sort_url($recentSortNext, $filterCourse, $dateFrom, $dateTo),
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

    // ── Data rows — one row per student per attempt ───────────────────────
    echo '<tbody>';
    $prevUid = null;
    foreach ($students as $stu) {
        $maxAtt = $maxAttemptsPerStudent[$stu->id] ?? 0;
        if ($maxAtt < 1) { continue; }

        // Display numbering for the Att badge only — the underlying row/column
        // alignment below is untouched. A voided row keeps showing whatever number
        // it already had (its id-order position); active (non-voided) rows get
        // their own independent 1,2,3… count among just themselves, so a student's
        // only real remaining attempt reads as #1 even if an earlier one was voided.
        $activeCounter = 0;
        $displayNumMap = [];
        for ($an = 1; $an <= $maxAtt; $an++) {
            $rowVoided = false;
            foreach (($cellMap[$stu->id] ?? []) as $lgid => $lgAtts) {
                if (!empty($lgAtts[$an]['voided'])) { $rowVoided = true; break; }
            }
            if ($rowVoided) {
                $displayNumMap[$an] = $an;
            } else {
                $activeCounter++;
                $displayNumMap[$an] = $activeCounter;
            }
        }

        for ($attNum = 1; $attNum <= $maxAtt; $attNum++) {
            $isFirst   = ($attNum === 1);
            $sepStyle  = (!$isFirst || $prevUid !== null) && $isFirst
                       ? 'border-top:2px solid #dee2e6;' : '';

            echo '<tr>';

            // Student name cell — full on first attempt row, muted repeat on subsequent.
            // Coloured by suspension status: red = confirmed, yellow = suspended but not
            // yet reviewed. Applied to both the bold first row and the muted repeats so
            // the signal is visible no matter which of a student's rows you're looking at.
            $susNameColour = ($suspendStatus[$stu->id] ?? '') === 'confirmed' ? '#dc3545'
                            : (($suspendStatus[$stu->id] ?? '') === 'pending' ? '#ffc107' : '');
            echo '<td style="padding:6px 10px;white-space:nowrap;' . $sepStyle . '">';
            if ($isFirst) {
                echo '<strong' . ($susNameColour ? ' style="color:' . $susNameColour . ';"' : '') . '>'
                   . s(fullname($stu)) . '</strong><br>';
                echo '<small class="text-muted">' . s($stu->email) . '</small>';
            } else {
                echo '<span style="color:' . ($susNameColour ?: '#adb5bd') . ';font-size:11px;">'
                   . s(fullname($stu)) . '<br>' . s($stu->email) . '</span>';
            }
            echo '</td>';

            // Attempt badge — grey + strikethrough for voided rows.
            $attData    = $cellMap[$stu->id] ?? [];
            $isVoidedRow = false;
            foreach ($attData as $lgid => $lgAtts) {
                if (!empty($lgAtts[$attNum]['voided'])) { $isVoidedRow = true; break; }
            }
            $badgeBg  = $isVoidedRow ? '#adb5bd' : '#0f6cbf';
            $badgeTxt = $isVoidedRow ? 'text-decoration:line-through;' : '';
            echo '<td style="text-align:center;padding:6px 8px;' . $sepStyle . '">';
            echo '<span style="background:' . $badgeBg . ';color:#fff;padding:2px 8px;'
               . 'border-radius:10px;font-size:12px;font-weight:700;' . $badgeTxt . '">#' . $displayNumMap[$attNum] . '</span>';
            echo '</td>';

            // Per-course data.
            $rowSum = 0.0; $rowCount = 0; $rowLatest = 0;
            foreach ($byCourse as $cid => $courseData) {
                $courseLatest = 0;

                foreach ($courseData['assessments'] as $a) {
                    $att = $cellMap[$stu->id][$a->id][$attNum] ?? null;
                    $reportUrl = new moodle_url('/mod/learnguard/grader_user_report.php',
                        ['id' => $a->cmid, 'userid' => $stu->id]);

                    echo '<td style="text-align:center;padding:4px 8px;'
                       . 'border-left:1px solid #f0f0f0;vertical-align:middle;' . $sepStyle . '">';
                    if ($att === null) {
                        echo '<span class="text-muted">—</span>';
                    } elseif (!empty($att['inprogress'])) {
                        // In-progress attempt: show partial score from answered questions.
                        if ($att['sumgrades'] !== null) {
                            echo '<span style="color:#fd7e14;font-size:11px;" title="In progress (partial score)">'
                               . number_format($att['sumgrades'], 2) . '</span>'
                               . '<br><span style="font-size:10px;color:#fd7e14;">in progress</span>';
                        } else {
                            echo '<span style="color:#fd7e14;font-size:11px;" title="In progress — no answers yet">🖊</span>';
                        }
                        // Do not count in-progress towards row average (not final grade).
                    } elseif (!empty($att['voided'])) {
                        // Voided attempt: show score struck-through; still counted in totals.
                        echo '<input type="checkbox" class="sm-bulk-chk" data-attid="' . (int)$att['id']
                           . '" style="margin-right:4px;vertical-align:middle;">';
                        $vScore = $att['sumgrades'] !== null
                            ? '<span style="text-decoration:line-through;color:#adb5bd;font-size:11px;">'
                              . number_format($att['sumgrades'], 2) . '</span>'
                            : '<span style="color:#adb5bd;font-size:11px;">—</span>';
                        echo $vScore . '<br><span style="font-size:10px;color:#adb5bd;">voided</span>';
                        if ($att['sumgrades'] !== null) { $rowSum += $att['sumgrades']; $rowCount++; }
                        if ($att['timefinish'] > $courseLatest) { $courseLatest = $att['timefinish']; }
                        if ($att['timefinish'] > $rowLatest)   { $rowLatest    = $att['timefinish']; }
                    } elseif ($att['sumgrades'] !== null) {
                        echo '<input type="checkbox" class="sm-bulk-chk" data-attid="' . (int)$att['id']
                           . '" style="margin-right:4px;vertical-align:middle;">';
                        echo '<a href="' . $reportUrl . '" style="color:#0f6cbf;text-decoration:none;">'
                           . '<strong>' . number_format($att['sumgrades'], 2) . '</strong></a>';
                        // Void this attempt's score directly from the matrix — same effect as
                        // grader.php's Void action (state -> voided; history kept, not deleted).
                        echo '<form method="post" style="display:inline;" onsubmit="return confirm(' .
                             '\'Void this score? It will no longer count toward totals/averages. ' .
                             'The attempt stays visible as voided and the student can be granted a reattempt.\');">';
                        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',  'value' => sesskey()]);
                        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'smaction', 'value' => 'voidattempt']);
                        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'vaid',     'value' => $att['id']]);
                        // Preserve the current filter/sort/date-range so voiding doesn't
                        // silently reset the view back to defaults on redirect.
                        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'filter_course', 'value' => $filterCourse]);
                        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sort',          'value' => $sortKey]);
                        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'date_from',     'value' => $dateFrom]);
                        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'date_to',       'value' => $dateTo]);
                        echo '<button type="submit" title="Void this score" ' .
                             'style="background:none;border:none;color:#dc3545;font-size:11px;cursor:pointer;padding:0;margin-left:4px;">🗑</button>';
                        echo '</form>';
                        $rowSum += $att['sumgrades']; $rowCount++;
                        if ($att['timefinish'] > $courseLatest) { $courseLatest = $att['timefinish']; }
                        if ($att['timefinish'] > $rowLatest)   { $rowLatest    = $att['timefinish']; }
                    } else {
                        echo '<input type="checkbox" class="sm-bulk-chk" data-attid="' . (int)$att['id']
                           . '" style="margin-right:4px;vertical-align:middle;">';
                        echo '<span class="text-warning" title="Essays pending grading">⏳</span>';
                        if ($att['timefinish'] > $courseLatest) { $courseLatest = $att['timefinish']; }
                        if ($att['timefinish'] > $rowLatest)   { $rowLatest    = $att['timefinish']; }
                    }
                    echo '</td>';
                }

                // Last Completed cell (one per course, for this attempt row).
                echo '<td style="text-align:center;padding:6px 8px;border-left:1px solid #f0f0f0;'
                    . 'border-right:2px solid #ced4da;' . $sepStyle . '">';
                if ($courseLatest > 0) {
                    echo '<span style="font-size:11px;">' . userdate($courseLatest, '%d %b %Y') . '</span><br>';
                    echo '<small class="text-muted">' . userdate($courseLatest, '%H:%M') . '</small>';
                } else {
                    echo '<span class="text-muted">—</span>';
                }
                echo '</td>';
            }

            // Total for this attempt row (struck-through for voided rows).
            $totalStyle = $isVoidedRow ? 'text-decoration:line-through;color:#adb5bd;' : '';
            echo '<td style="text-align:center;padding:6px 8px;border-left:2px solid #ced4da;' . $sepStyle . '">';
            echo $rowCount > 0
                ? '<span style="' . $totalStyle . '"><strong>' . number_format($rowSum, 2) . '</strong></span>'
                : '<span class="text-muted">—</span>';
            echo '</td>';

            // Average for this attempt row (struck-through for voided rows). Percentage of
            // the total possible across ALL assessments in the matrix — not just the ones
            // this student has a score for — so a partially-completed row correctly reads
            // lower than a fully-completed one instead of matching it.
            echo '<td style="text-align:center;padding:6px 8px;' . $sepStyle . '">';
            echo ($rowCount > 0 && $totalMaxPossible > 0)
                ? '<span style="' . $totalStyle . '"><strong>' . number_format(($rowSum / $totalMaxPossible) * 100, 2) . '%</strong></span>'
                : '<span class="text-muted">—</span>';
            echo '</td>';

            // Overall Last Completed for this attempt row.
            echo '<td style="text-align:center;padding:6px 8px;white-space:nowrap;' . $sepStyle . '">';
            if ($rowLatest > 0) {
                echo '<span style="font-size:11px;">' . userdate($rowLatest, '%d %b %Y') . '</span><br>';
                echo '<small class="text-muted">' . userdate($rowLatest, '%H:%M') . '</small>';
            } else {
                echo '<span class="text-muted">—</span>';
            }
            echo '</td>';

            echo '</tr>';
        }
        $prevUid = $stu->id;
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
    ?>
<script>
function smUpdateBulkCount() {
    var n = document.querySelectorAll('.sm-bulk-chk:checked').length;
    var el = document.getElementById('sm-bulk-count');
    if (el) { el.textContent = n ? (n + ' selected') : ''; }
}
document.addEventListener('change', function(e) {
    if (e.target && e.target.classList && e.target.classList.contains('sm-bulk-chk')) {
        smUpdateBulkCount();
    }
});
function smToggleAllChk(state) {
    document.querySelectorAll('.sm-bulk-chk').forEach(function(c) { c.checked = state; });
    smUpdateBulkCount();
}
function smBulkAction(action) {
    var checked = document.querySelectorAll('.sm-bulk-chk:checked');
    if (!checked.length) { alert('Tick at least one score cell first.'); return; }
    var msg = action === 'bulkdelete'
        ? 'PERMANENTLY delete ' + checked.length + ' attempt(s)? This removes them (and all their data — ' +
          'responses, evidence, everything) completely and cannot be undone. Voided attempts stay visible for ' +
          'history; deleted ones do not.'
        : 'Void ' + checked.length + ' attempt(s)? They will no longer count toward totals/averages, but stay ' +
          'visible (struck through) and the students can be granted a reattempt.';
    if (!confirm(msg)) { return; }
    var targets = document.getElementById('sm-bulk-targets');
    targets.innerHTML = '';
    checked.forEach(function(c) {
        var inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = 'bulk_attid[]'; inp.value = c.getAttribute('data-attid');
        targets.appendChild(inp);
    });
    document.getElementById('sm-bulk-action').value = action;
    document.getElementById('sm-bulk-form').submit();
}
</script>
    <?php
}

echo $OUTPUT->footer();
