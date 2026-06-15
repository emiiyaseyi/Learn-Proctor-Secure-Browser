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
 * Site-wide cross-course score matrix for quizzes proctored by Learn Shield.
 *
 * One row per student, one column per Learn Shield-proctored quiz (optionally
 * filtered to a single course), with Total and Average columns. Cells are
 * blank until the student has a grade recorded for that quiz.
 *
 * URL: /mod/quiz/accessrule/learnshield/scorematrix.php
 *
 * @package    quizaccess_learnshield
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$filtercourseid = optional_param('filtercourseid', 0, PARAM_INT);
$download       = optional_param('download', '', PARAM_ALPHA);

// ── Columns: quizzes with Learn Shield enabled (optionally filtered to one course) ──
$params = [];
$coursesql = '';
if ($filtercourseid > 0) {
    $coursesql = ' AND c.id = :courseid';
    $params['courseid'] = $filtercourseid;
}
$quizzes = $DB->get_records_sql(
    "SELECT q.id AS quizid, q.name, q.grade AS maxgrade, c.id AS courseid, c.fullname AS coursename, cm.id AS cmid
       FROM {quiz} q
       JOIN {course} c ON c.id = q.course
       JOIN {modules} md ON md.name = 'quiz'
       JOIN {course_modules} cm ON cm.instance = q.id AND cm.module = md.id
       JOIN {quizaccess_learnshield} ls ON ls.quizid = cm.id
      WHERE 1=1$coursesql
   ORDER BY c.fullname, q.name", $params
);

// ── Cells: each student's recorded grade for each quiz (blank if ungraded) ──
$cellMap  = [];
$students = [];
if (!empty($quizzes)) {
    [$insql, $inparams] = $DB->get_in_or_equal(array_keys($quizzes), SQL_PARAMS_NAMED, 'q');

    $cellRows = $DB->get_records_sql(
        "SELECT qg.quiz, qg.userid, qg.grade
           FROM {quiz_grades} qg
          WHERE qg.quiz $insql", $inparams
    );
    foreach ($cellRows as $row) {
        $cellMap[$row->userid][$row->quiz] = $row->grade !== null ? (float)$row->grade : null;
    }

    $students = $DB->get_records_sql(
        "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.username
           FROM {quiz_grades} qg
           JOIN {user} u ON u.id = qg.userid
          WHERE qg.quiz $insql
       ORDER BY u.lastname, u.firstname", $inparams
    );
}

// ── CSV export (current filter applied) ─────────────────────────────────────
if ($download === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="learnshield_score_matrix_' . date('Ymd_His') . '.csv"');
    echo "\xEF\xBB\xBF";
    $fh = fopen('php://output', 'w');
    $header = ['First Name', 'Last Name', 'Email'];
    foreach ($quizzes as $q) {
        $header[] = format_string($q->name) . ' (' . format_string($q->coursename) . ', /' . (float)$q->maxgrade . ')';
    }
    $header[] = 'Total';
    $header[] = 'Average';
    fputcsv($fh, $header);

    foreach ($students as $stu) {
        $row = [$stu->firstname, $stu->lastname, $stu->email];
        $sum = 0.0; $count = 0;
        foreach ($quizzes as $q) {
            $score = $cellMap[$stu->id][$q->quizid] ?? null;
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
$PAGE->set_url('/mod/quiz/accessrule/learnshield/scorematrix.php', $filtercourseid ? ['filtercourseid' => $filtercourseid] : []);
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Learn Shield — Score Matrix');
$PAGE->set_heading('Learn Shield — Score Matrix');
$PAGE->requires->css('/mod/quiz/accessrule/learnshield/styles.css');

echo $OUTPUT->header();
require_once(__DIR__ . '/navhelper.php');
ls_nav_bar(0, 0, 'scorematrix');

echo $OUTPUT->heading('🏆 Score Matrix');
echo html_writer::tag('p', 'Cross-course view of every student\'s grade in each Learn Shield-proctored quiz.', ['class' => 'text-muted']);

// Course filter.
$allcourses = $DB->get_records_sql(
    "SELECT DISTINCT c.id, c.fullname
       FROM {course} c
       JOIN {course_modules} cm ON cm.course = c.id
       JOIN {modules} md ON md.id = cm.module AND md.name = 'quiz'
       JOIN {quizaccess_learnshield} ls ON ls.quizid = cm.id
   ORDER BY c.fullname"
);

?>
<form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:20px;background:#f8f9fa;padding:14px 16px;border-radius:8px;">
  <div style="display:flex;flex-direction:column;gap:4px;">
    <label style="font-size:12px;font-weight:700;">Course</label>
    <select name="filtercourseid" class="form-control" style="max-width:280px;" onchange="this.form.submit()">
      <option value="0">— All Courses —</option>
      <?php foreach ($allcourses as $c): ?>
      <option value="<?php echo $c->id; ?>" <?php echo ($filtercourseid == $c->id ? 'selected' : ''); ?>><?php echo s(format_string($c->fullname)); ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="btn btn-primary btn-sm">🔍 Filter</button>
  <?php if ($filtercourseid): ?>
  <a href="<?php echo new moodle_url('/mod/quiz/accessrule/learnshield/scorematrix.php'); ?>" class="btn btn-outline-secondary btn-sm">Clear</a>
  <?php endif; ?>
  <div style="margin-left:auto;">
    <?php
    $exportParams = $filtercourseid ? ['filtercourseid' => $filtercourseid] : [];
    echo html_writer::link(new moodle_url('/mod/quiz/accessrule/learnshield/scorematrix.php', $exportParams + ['download' => 'csv']),
        '⬇ CSV', ['class' => 'btn btn-outline-secondary btn-sm']);
    ?>
  </div>
</form>

<?php
if (empty($quizzes)) {
    echo html_writer::div('No Learn Shield-proctored quizzes found' . ($filtercourseid ? ' in this course.' : ' on this site.'), 'alert alert-info');
} else if (empty($students)) {
    echo html_writer::div('No grades recorded for these quizzes yet.', 'alert alert-info');
} else {
    echo '<div style="overflow-x:auto;">';
    echo html_writer::start_tag('table', ['class' => 'generaltable w-100', 'style' => 'font-size:13px;']);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr', ['style' => 'background:#f8f9fa;']);
    echo html_writer::tag('th', 'Student', ['style' => 'padding:8px 10px;white-space:nowrap;']);
    foreach ($quizzes as $q) {
        $label = html_writer::link(
            new moodle_url('/mod/quiz/report.php', ['id' => $q->cmid, 'mode' => 'overview']),
            format_string($q->name), ['style' => 'color:inherit;']
        ) . '<br><small class="text-muted">' . s(format_string($q->coursename)) . ' (/' . (float)$q->maxgrade . ')</small>';
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
        foreach ($quizzes as $q) {
            $score = $cellMap[$stu->id][$q->quizid] ?? null;
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
    echo html_writer::tag('p', 'Blank cells mean the student has no recorded grade for that quiz yet.',
        ['class' => 'text-muted mt-2', 'style' => 'font-size:12px;']);
}

echo $OUTPUT->footer();
