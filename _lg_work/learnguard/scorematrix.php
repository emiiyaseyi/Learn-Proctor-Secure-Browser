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
 * One row per student, one column per LearnGuard assessment (optionally
 * filtered to a single course), with Total and Average columns. Cells are
 * blank until the student has a finished/graded attempt for that assessment.
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
$download     = optional_param('download', '', PARAM_ALPHA);

// ── Columns: LearnGuard assessments (optionally filtered to one course) ────
$params = [];
$coursesql = '';
if ($filterCourse > 0) {
    $coursesql = ' AND c.id = :courseid';
    $params['courseid'] = $filterCourse;
}
$assessments = $DB->get_records_sql(
    "SELECT lg.id, lg.name, lg.grade AS maxgrade, c.id AS courseid, c.fullname AS coursename, cm.id AS cmid
       FROM {learnguard} lg
       JOIN {course} c ON c.id = lg.course
       JOIN {modules} mdl ON mdl.name = 'learnguard'
       JOIN {course_modules} cm ON cm.instance = lg.id AND cm.module = mdl.id
      WHERE 1=1$coursesql
   ORDER BY c.fullname, lg.name", $params
);

// ── Cells: best-known score = most recently finished attempt per (assessment, user) ──
$cellMap  = [];
$students = [];
if (!empty($assessments)) {
    [$insql, $inparams] = $DB->get_in_or_equal(array_keys($assessments), SQL_PARAMS_NAMED, 'lg');

    // Use get_recordset_sql (not get_records_sql) because the first column
    // (a.learnguard) is not unique per row — multiple students per assessment
    // would collapse to a single entry with get_records_sql.
    // CASE WHEN now only checks essay slots the student was actually assigned
    // (via learnguard_attempt_questions) so slots from the pool that the
    // student never received don't hold the score hostage as NULL.
    $cellRS = $DB->get_recordset_sql(
        "SELECT a.learnguard, a.userid,
                CASE WHEN EXISTS (
                    SELECT 1
                      FROM {learnguard_slots} es
                      JOIN {learnguard_qbank} eq ON eq.id = es.questionid AND eq.qtype = 'essay'
                      JOIN {learnguard_attempt_questions} aq ON aq.slotid = es.id AND aq.attemptid = a.id
                     WHERE es.learnguard = a.learnguard
                       AND NOT EXISTS (
                           SELECT 1 FROM {learnguard_essay_grades} eg
                            WHERE eg.attemptid = a.id AND eg.slotid = es.id
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
        $cellMap[$row->userid][$row->learnguard] = $row->sumgrades !== null ? (float)$row->sumgrades : null;
    }
    $cellRS->close();

    $students = $DB->get_records_sql(
        "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.username
           FROM {learnguard_attempts} a
           JOIN {user} u ON u.id = a.userid
          WHERE a.learnguard $insql
       ORDER BY u.lastname, u.firstname", $inparams
    );
}

// ── CSV export (current filter applied) ─────────────────────────────────────
if ($download === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="learnguard_score_matrix_' . date('Ymd_His') . '.csv"');
    echo "\xEF\xBB\xBF";
    $fh = fopen('php://output', 'w');
    $header = ['First Name', 'Last Name', 'Email'];
    foreach ($assessments as $a) {
        $header[] = format_string($a->name) . ' (' . format_string($a->coursename) . ', /' . (float)$a->maxgrade . ')';
    }
    $header[] = 'Total';
    $header[] = 'Average';
    fputcsv($fh, $header);

    foreach ($students as $stu) {
        $row = [$stu->firstname, $stu->lastname, $stu->email];
        $sum = 0.0; $count = 0;
        foreach ($assessments as $a) {
            $score = $cellMap[$stu->id][$a->id] ?? null;
            $row[] = $score !== null ? number_format($score, 2, '.', '') : '';
            if ($score !== null) { $sum += $score; $count++; }
        }
        $row[] = $count > 0 ? number_format($sum, 2, '.', '') : '';
        $row[] = $count > 0 ? number_format($sum / $count, 2, '.', '') : '';
        fputcsv($fh, $row);
    }
    fclose($fh);
    exit;
}

// ── Page render ──────────────────────────────────────────────────────────────
$PAGE->set_url('/mod/learnguard/scorematrix.php', $filterCourse ? ['filter_course' => $filterCourse] : []);
$PAGE->set_context(context_system::instance());
$PAGE->set_title('LearnGuard — Score Matrix');
$PAGE->set_heading('LearnGuard — Score Matrix');
$PAGE->requires->css('/mod/learnguard/styles.css');

echo $OUTPUT->header();
echo learnguard_nav_bar(0, '🏆 Score Matrix');
$brand = learnguard_brand();
echo learnguard_banner('Score Matrix', 'Cross-course view of every student\'s score in each LearnGuard assessment.', '🏆');

// Course filter.
$allCourses = $DB->get_records_sql("SELECT DISTINCT c.id, c.fullname FROM {course} c
    JOIN {learnguard} lg ON lg.course = c.id ORDER BY c.fullname");

?>
<form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:20px;background:#f8f9fa;padding:14px 16px;border-radius:8px;">
  <div style="display:flex;flex-direction:column;gap:4px;">
    <label style="font-size:12px;font-weight:700;">Course</label>
    <select name="filter_course" class="form-control" style="max-width:280px;" onchange="this.form.submit()">
      <option value="0">— All Courses —</option>
      <?php foreach ($allCourses as $c): ?>
      <option value="<?php echo $c->id; ?>" <?php echo ($filterCourse == $c->id ? 'selected' : ''); ?>><?php echo s(format_string($c->fullname)); ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="btn btn-primary btn-sm">🔍 Filter</button>
  <?php if ($filterCourse): ?>
  <a href="<?php echo new moodle_url('/mod/learnguard/scorematrix.php'); ?>" class="btn btn-outline-secondary btn-sm">Clear</a>
  <?php endif; ?>
  <div style="margin-left:auto;">
    <?php
    $exportParams = $filterCourse ? ['filter_course' => $filterCourse] : [];
    echo html_writer::link(new moodle_url('/mod/learnguard/scorematrix.php', $exportParams + ['download' => 'csv']),
        '⬇ CSV', ['class' => 'btn btn-outline-secondary btn-sm']);
    ?>
  </div>
</form>

<?php
if (empty($assessments)) {
    echo html_writer::div('No LearnGuard assessments found' . ($filterCourse ? ' in this course.' : ' on this site.'), 'alert alert-info');
} else if (empty($students)) {
    echo html_writer::div('No attempts found for these assessments yet.', 'alert alert-info');
} else {
    echo '<div style="overflow-x:auto;">';
    echo html_writer::start_tag('table', ['class' => 'generaltable w-100', 'style' => 'font-size:13px;']);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr', ['style' => 'background:#f8f9fa;']);
    echo html_writer::tag('th', 'Student', ['style' => 'padding:8px 10px;white-space:nowrap;']);
    foreach ($assessments as $a) {
        $label = html_writer::link(
            new moodle_url('/mod/learnguard/scores.php', ['id' => $a->cmid]),
            format_string($a->name), ['style' => 'color:inherit;']
        ) . '<br><small class="text-muted">' . s(format_string($a->coursename)) . ' (/' . (float)$a->maxgrade . ')</small>';
        echo html_writer::tag('th', $label, ['style' => 'padding:8px 10px;white-space:nowrap;']);
    }
    echo html_writer::tag('th', 'Total', ['style' => 'padding:8px 10px;white-space:nowrap;']);
    echo html_writer::tag('th', 'Average', ['style' => 'padding:8px 10px;white-space:nowrap;']);
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    foreach ($students as $stu) {
        echo html_writer::start_tag('tr');
        echo html_writer::tag('td',
            '<strong>' . s(fullname($stu)) . '</strong><br><small class="text-muted">' . s($stu->email) . '</small>',
            ['style' => 'padding:8px 10px;white-space:nowrap;']);
        $sum = 0.0; $count = 0;
        foreach ($assessments as $a) {
            $score = $cellMap[$stu->id][$a->id] ?? null;
            if ($score !== null) { $sum += $score; $count++; }
            echo html_writer::tag('td', $score !== null ? number_format($score, 2) : '—',
                ['style' => 'padding:8px 10px;text-align:center;']);
        }
        echo html_writer::tag('td', $count > 0 ? number_format($sum, 2) : '—',
            ['style' => 'padding:8px 10px;text-align:center;font-weight:700;']);
        echo html_writer::tag('td', $count > 0 ? number_format($sum / $count, 2) : '—',
            ['style' => 'padding:8px 10px;text-align:center;font-weight:700;']);
        echo html_writer::end_tag('tr');
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
    echo '</div>';
    echo html_writer::tag('p', 'Blank cells mean the student has no finished attempt for that assessment yet, or the attempt has ungraded essay questions.',
        ['class' => 'text-muted mt-2', 'style' => 'font-size:12px;']);
}

echo $OUTPUT->footer();
