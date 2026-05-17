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
 * Backend — All proctored quiz attempts for a course.
 * Lists every quiz attempt that was monitored by Learn Shield,
 * with risk scores, violation counts, and a link to the per-attempt report.
 *
 * @package    quizaccess_learnshield
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link       https://www.linkedin.com/in/michaeladeniran/
 */

require_once(__DIR__ . '/../../../../config.php');

use quizaccess_learnshield\suspicion_scorer;

$courseid = required_param('courseid', PARAM_INT);
$cmid     = optional_param('cmid',     0,  PARAM_INT);
$search   = optional_param('search',   '', PARAM_TEXT);
$risk     = optional_param('risk',     '', PARAM_ALPHA);
$page     = optional_param('page',     0,  PARAM_INT);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

if ($cmid) {
    $cm      = get_coursemodule_from_id('quiz', $cmid, $courseid, false, MUST_EXIST);
    require_login($course, false, $cm);
    $context = context_module::instance($cm->id);
} else {
    require_login($course, true);
    $context = context_course::instance($courseid);
}

require_capability('quizaccess/learnshield:viewreport', $context);

$perpage = 30;

// -----------------------------------------------------------------------
// Build the SQL query.
// -----------------------------------------------------------------------
$where  = 'e.courseid = :courseid';
$params = ['courseid' => $courseid];

if ($cmid) {
    $where  .= ' AND e.quizid = :quizid';
    $params['quizid'] = $cmid;
}

if ($risk) {
    $where .= ' AND s.risk_level = :risk';
    $params['risk'] = $risk;
}

if ($search) {
    $where .= ' AND (' . $DB->sql_like('u.firstname', ':sfn', false) .
              ' OR '   . $DB->sql_like('u.lastname',  ':sln', false) .
              ' OR '   . $DB->sql_like('u.email',     ':sem', false) . ')';
    $params['sfn'] = '%' . $search . '%';
    $params['sln'] = '%' . $search . '%';
    $params['sem'] = '%' . $search . '%';
}

// Summary subquery: per (courseid, quizid, userid) — latest status and counts.
$sql = "SELECT
            e.userid,
            e.quizid,
            e.courseid,
            u.firstname,
            u.lastname,
            u.email,
            c.fullname   AS coursename,
            q.name       AS quizname,
            MAX(e.timemodified)       AS last_snapshot_time,
            COUNT(e.id)               AS snapshot_count,
            COALESCE(s.risk_score, 0) AS risk_score,
            COALESCE(s.risk_level, 'low') AS risk_level,
            COALESCE(s.tab_switches,  0) AS tab_switches,
            COALESCE(s.fullscreen_exits, 0) AS fullscreen_exits,
            COALESCE(s.face_missing_count, 0) AS face_missing,
            COALESCE(s.is_flagged, 0) AS is_flagged,
            s.id AS sessionid
        FROM {quizaccess_learnshield_logs} e
        INNER JOIN {user}           u  ON u.id  = e.userid
        INNER JOIN {course}         c  ON c.id  = e.courseid
        INNER JOIN {course_modules} cm ON cm.id = e.quizid
        INNER JOIN {quiz}           q  ON q.id  = cm.instance
        LEFT JOIN  {quizaccess_learnshield_sessions} s
               ON  s.userid    = e.userid
               AND s.quizid    = e.quizid
               AND s.courseid  = e.courseid
        WHERE {$where}
        GROUP BY e.userid, e.quizid, e.courseid,
                 u.firstname, u.lastname, u.email,
                 c.fullname, q.name,
                 s.risk_score, s.risk_level, s.tab_switches,
                 s.fullscreen_exits, s.face_missing_count, s.is_flagged, s.id
        ORDER BY COALESCE(s.risk_score, 0) DESC, last_snapshot_time DESC";

$countsql = "SELECT COUNT(*) FROM (
                SELECT e.userid, e.quizid
                FROM {quizaccess_learnshield_logs} e
                INNER JOIN {user}           u  ON u.id  = e.userid
                LEFT JOIN  {quizaccess_learnshield_sessions} s
                       ON  s.userid   = e.userid
                       AND s.quizid   = e.quizid
                       AND s.courseid = e.courseid
                WHERE {$where}
                GROUP BY e.userid, e.quizid
             ) subq";

$total   = $DB->count_records_sql($countsql, $params);
$records = $DB->get_records_sql($sql, $params, $page * $perpage, $perpage);

// -----------------------------------------------------------------------
// Page setup.
// -----------------------------------------------------------------------
$PAGE->set_url(new moodle_url('/mod/quiz/accessrule/learnshield/attempts.php', [
    'courseid' => $courseid, 'cmid' => $cmid,
]));
$PAGE->set_context($context);
$PAGE->set_heading($course->fullname);
$PAGE->set_title('Learn Shield — All Attempts');
$PAGE->requires->css('/mod/quiz/accessrule/learnshield/styles.css');

echo $OUTPUT->header();
echo $OUTPUT->heading('Learn Shield — All Proctored Attempts');
echo html_writer::tag('p', 'Complete list of all quiz attempts monitored by Learn Shield for this course.', ['class' => 'text-muted']);

// -----------------------------------------------------------------------
// Filter bar.
// -----------------------------------------------------------------------
$filterurl = new moodle_url('/mod/quiz/accessrule/learnshield/attempts.php', ['courseid' => $courseid, 'cmid' => $cmid]);

echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'd-flex flex-wrap gap-2 mb-4 align-items-center']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'cmid',     'value' => $cmid]);

// Quiz dropdown.
$quizzes = $DB->get_records_sql(
    "SELECT cm.id, q.name FROM {course_modules} cm
      INNER JOIN {quiz} q ON q.id = cm.instance
      WHERE cm.course = :cid AND cm.module = (SELECT id FROM {modules} WHERE name = 'quiz')",
    ['cid' => $courseid]
);
$quizoptions = [0 => '— All Quizzes —'];
foreach ($quizzes as $q) { $quizoptions[$q->id] = $q->name; }
echo html_writer::select($quizoptions, 'cmid', $cmid, false, ['class' => 'form-control mr-2', 'style' => 'max-width:220px;']);

echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'search', 'value' => s($search),
    'placeholder' => 'Search name or email…',
    'class' => 'form-control mr-2', 'style' => 'max-width:220px;',
]);

$riskopts = ['' => 'All risks', 'low' => 'Low', 'medium' => 'Medium', 'high' => 'High'];
echo html_writer::select($riskopts, 'risk', $risk, false, ['class' => 'form-control mr-2', 'style' => 'max-width:130px;']);

echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Filter', 'class' => 'btn btn-primary']);

if ($search || $risk || $cmid) {
    echo html_writer::link($filterurl, 'Clear', ['class' => 'btn btn-secondary ml-1']);
}
echo html_writer::end_tag('form');

// -----------------------------------------------------------------------
// Summary stats bar.
// -----------------------------------------------------------------------
$totalattempts  = $DB->count_records_select('quizaccess_learnshield_logs', 'courseid = :c', ['c' => $courseid]);
$flaggedsessions = $DB->count_records_select('quizaccess_learnshield_sessions',
    'courseid = :c AND is_flagged = 1', ['c' => $courseid]);
$highrisk = $DB->count_records_select('quizaccess_learnshield_sessions',
    "courseid = :c AND risk_level = 'high'", ['c' => $courseid]);

echo html_writer::start_div('learnshield-stats-bar mb-4');
foreach ([
    ['Total Snapshots',     $totalattempts],
    ['Flagged Sessions',    $flaggedsessions],
    ['High Risk Sessions',  $highrisk],
    ['Showing',             $total . ' attempts'],
] as [$label, $val]) {
    echo html_writer::start_div('learnshield-stat-card');
    echo html_writer::div($val,   'stat-number');
    echo html_writer::div($label, 'stat-label');
    echo html_writer::end_div();
}
echo html_writer::end_div();

// -----------------------------------------------------------------------
// Attempts table.
// -----------------------------------------------------------------------
if (empty($records)) {
    echo $OUTPUT->notification('No proctored attempts found for the selected filters.', 'info');
} else {
    echo html_writer::start_tag('div', ['class' => 'table-responsive']);
    echo html_writer::start_tag('table', ['class' => 'table table-sm table-bordered table-hover']);
    echo html_writer::start_tag('thead', ['class' => 'thead-light']);
    echo html_writer::tag('tr',
        html_writer::tag('th', 'Student') .
        html_writer::tag('th', 'Quiz') .
        html_writer::tag('th', 'Snapshots') .
        html_writer::tag('th', 'Risk Score') .
        html_writer::tag('th', 'Tab Switches') .
        html_writer::tag('th', 'FS Exits') .
        html_writer::tag('th', 'Face Missing') .
        html_writer::tag('th', 'Flagged') .
        html_writer::tag('th', 'Last Activity') .
        html_writer::tag('th', 'Actions')
    );
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    foreach ($records as $row) {
        $riskclass  = suspicion_scorer::risk_class($row->risk_level);
        $flagged    = $row->is_flagged ? '<span class="badge badge-warning">Flagged</span>' : '';

        $reporturl  = new moodle_url('/mod/quiz/accessrule/learnshield/attemptdetail.php', [
            'courseid' => $row->courseid,
            'quizid'   => $row->quizid,
            'userid'   => $row->userid,
        ]);
        $auditurl   = new moodle_url('/mod/quiz/accessrule/learnshield/auditlog.php', [
            'courseid' => $row->courseid,
            'cmid'     => $row->quizid,
            'sessionid'=> $row->sessionid ?: 0,
        ]);

        echo html_writer::start_tag('tr');
        echo html_writer::tag('td',
            html_writer::tag('strong', s(fullname($row))) .
            html_writer::empty_tag('br') .
            html_writer::tag('small', s($row->email), ['class' => 'text-muted'])
        );
        echo html_writer::tag('td', s($row->quizname));
        echo html_writer::tag('td', (int)$row->snapshot_count);
        echo html_writer::tag('td',
            html_writer::tag('span',
                (int)$row->risk_score . ' pts — ' . ucfirst($row->risk_level),
                ['class' => "learnshield-risk-badge {$riskclass}"]
            )
        );
        echo html_writer::tag('td', (int)$row->tab_switches);
        echo html_writer::tag('td', (int)$row->fullscreen_exits);
        echo html_writer::tag('td', (int)$row->face_missing);
        echo html_writer::tag('td', $flagged ?: '—');
        echo html_writer::tag('td',
            $row->last_snapshot_time
                ? userdate($row->last_snapshot_time, get_string('strftimedatetimeshort', 'core_langconfig'))
                : '—'
        );
        echo html_writer::tag('td',
            html_writer::link($reporturl, 'View Report', ['class' => 'btn btn-sm btn-primary mr-1']) .
            html_writer::link($auditurl,  'Audit Log',   ['class' => 'btn btn-sm btn-secondary'])
        );
        echo html_writer::end_tag('tr');
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
    echo html_writer::end_tag('div');

    // Pagination.
    echo $OUTPUT->paging_bar($total, $page, $perpage,
        new moodle_url('/mod/quiz/accessrule/learnshield/attempts.php', [
            'courseid' => $courseid, 'cmid' => $cmid, 'search' => $search, 'risk' => $risk,
        ])
    );
}

echo $OUTPUT->footer();
