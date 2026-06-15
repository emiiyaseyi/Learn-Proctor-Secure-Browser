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
 * Central Learn Shield administration — all courses and quizzes.
 *
 * @package    quizaccess_learnshield
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link       https://www.linkedin.com/in/michaeladeniran/
 */

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once(__DIR__ . '/lib.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

// -----------------------------------------------------------------------
// Handle POST actions (all state-changing actions use POST for CSRF safety)
// -----------------------------------------------------------------------
$action = optional_param('action', '', PARAM_ALPHA);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action && confirm_sesskey()) {

    $quizid   = required_param('quizid',   PARAM_INT);
    $cmid     = optional_param('cmid',     0, PARAM_INT);
    $courseid = optional_param('courseid', 0, PARAM_INT);

    $adminurl = new moodle_url('/mod/quiz/accessrule/learnshield/admin.php');

    if ($action === 'enableproctoring') {
        if (!$DB->record_exists('quizaccess_learnshield', ['quizid' => $quizid])) {
            $DB->insert_record('quizaccess_learnshield', (object)[
                'quizid' => $quizid, 'proctoringrequired' => 1,
            ]);
        }
        redirect($adminurl, 'Proctoring enabled.', null, \core\output\notification::NOTIFY_SUCCESS);
    }

    if ($action === 'disableproctoring') {
        $DB->delete_records('quizaccess_learnshield', ['quizid' => $quizid]);
        redirect($adminurl, 'Proctoring disabled.', null, \core\output\notification::NOTIFY_WARNING);
    }

    if ($action === 'addproctor') {
        // Support search by user ID, username, or email.
        $userident = optional_param('userident', '', PARAM_TEXT);
        $role      = optional_param('role', 'proctor', PARAM_ALPHANUMEXT);

        $targetuser = null;
        if (is_numeric($userident)) {
            $targetuser = $DB->get_record('user', ['id' => (int)$userident, 'deleted' => 0]);
        } else {
            $targetuser = $DB->get_record_select('user',
                "(" . $DB->sql_like('username', ':un', false) . " OR " .
                      $DB->sql_like('email',    ':em', false) . ") AND deleted = 0",
                ['un' => $userident, 'em' => $userident]
            );
        }

        if (!$targetuser) {
            redirect($adminurl, 'User not found: ' . s($userident), null, \core\output\notification::NOTIFY_ERROR);
        }

        if (!$DB->record_exists('quizaccess_learnshield_proctor_assign', [
            'quizid' => $cmid ?: $quizid, 'user_id' => $targetuser->id,
        ])) {
            $DB->insert_record('quizaccess_learnshield_proctor_assign', (object)[
                'courseid' => $courseid, 'quizid' => $cmid ?: $quizid,
                'user_id'  => $targetuser->id, 'role' => $role, 'timemodified' => time(),
            ]);
            \quizaccess_learnshield\email_notifier::notify_proctor_assigned(
                $targetuser, $cmid ?: $quizid, $courseid, $role);
        }
        redirect($adminurl, fullname($targetuser) . ' assigned as ' . $role . '.', null, \core\output\notification::NOTIFY_SUCCESS);
    }

    if ($action === 'removeproctor') {
        $assignid = required_param('assignid', PARAM_INT);
        $DB->delete_records('quizaccess_learnshield_proctor_assign', ['id' => $assignid]);
        redirect($adminurl, 'Proctor removed.', null, \core\output\notification::NOTIFY_SUCCESS);
    }

    if ($action === 'emergencypause') {
        set_config('emergency_pause_active', 1, 'quizaccess_learnshield');
        redirect($adminurl, '🚨 Emergency Pause ACTIVE — all assessments blocked.', null, \core\output\notification::NOTIFY_ERROR);
    }

    if ($action === 'emergencyresume') {
        set_config('emergency_pause_active', 0, 'quizaccess_learnshield');
        redirect($adminurl, '✅ Emergency Pause lifted — assessments resumed.', null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

// -----------------------------------------------------------------------
// GET params (read-only: filter by course, search user)
// -----------------------------------------------------------------------
$filtercourseid = optional_param('filtercourseid', 0, PARAM_INT);
$searchuser     = optional_param('searchuser',     '', PARAM_TEXT);

// -----------------------------------------------------------------------
// Page setup
// -----------------------------------------------------------------------
$PAGE->set_url(new moodle_url('/mod/quiz/accessrule/learnshield/admin.php'));
$PAGE->set_context($context);
$PAGE->set_heading('Learn Shield — Central Administration');
$PAGE->set_title('Learn Shield — Central Administration');
$PAGE->requires->css('/mod/quiz/accessrule/learnshield/styles.css');

echo $OUTPUT->header();

// -----------------------------------------------------------------------
// Top navigation
// -----------------------------------------------------------------------
echo html_writer::start_div('d-flex gap-2 mb-3 flex-wrap align-items-center');
// Link directly to the plugin settings page (correct section key).
echo html_writer::link(
    new moodle_url('/admin/settings.php', ['section' => 'quizaccess_learnshield']),
    '⚙ Global Settings', ['class' => 'btn btn-secondary btn-sm']
);
echo html_writer::link(
    new moodle_url('/mod/quiz/accessrule/learnshield/userslist.php'),
    '📸 User Images', ['class' => 'btn btn-secondary btn-sm']
);
echo html_writer::link(
    new moodle_url('/mod/quiz/accessrule/learnshield/scorematrix.php'),
    '🏆 Score Matrix', ['class' => 'btn btn-secondary btn-sm']
);
echo html_writer::end_div();

echo $OUTPUT->heading('Learn Shield — Central Administration');
echo html_writer::tag('p', 'Manage all proctored quizzes, assign proctors, and view site-wide statistics.', ['class' => 'text-muted']);

// -----------------------------------------------------------------------
// Emergency pause banner
// -----------------------------------------------------------------------
$emergencyActive = (bool)(int)(get_config('quizaccess_learnshield', 'emergency_pause_active') ?: 0);
$posturl = (new moodle_url('/mod/quiz/accessrule/learnshield/admin.php'))->out(false);

if ($emergencyActive) {
    echo html_writer::div(
        html_writer::tag('strong', '🚨 EMERGENCY PAUSE ACTIVE') . ' — All proctored assessments blocked. ' .
        html_writer::start_tag('form', ['method' => 'post', 'action' => $posturl, 'class' => 'd-inline']) .
        html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'emergencyresume']) .
        html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
        html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'quizid',  'value' => 0]) .
        html_writer::tag('button', 'Resume All →', ['type' => 'submit', 'class' => 'btn btn-success btn-sm ml-2']) .
        html_writer::end_tag('form'),
        'alert alert-danger mb-3'
    );
} else {
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $posturl, 'class' => 'mb-3']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'emergencypause']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'quizid',  'value' => 0]);
    echo html_writer::tag('button', '🚨 Emergency Pause All Assessments', ['type' => 'submit', 'class' => 'btn btn-outline-danger btn-sm']);
    echo html_writer::end_tag('form');
}

// -----------------------------------------------------------------------
// Site-wide stats
// -----------------------------------------------------------------------
try {
    $stats = (object)[
        'proctored_quizzes' => $DB->count_records('quizaccess_learnshield'),
        'total_students'    => $DB->count_records_sql('SELECT COUNT(DISTINCT userid) FROM {quizaccess_learnshield_logs}'),
        'total_snapshots'   => $DB->count_records('quizaccess_learnshield_logs'),
        'high_risk'         => $DB->count_records('quizaccess_learnshield_sessions', ['risk_level' => 'high']),
        'flagged'           => $DB->count_records('quizaccess_learnshield_sessions', ['is_flagged' => 1]),
        'active_now'        => $DB->count_records('quizaccess_learnshield_sessions', ['status' => 'active']),
    ];
} catch (Exception $e) {
    $stats = (object)['proctored_quizzes'=>0,'total_students'=>0,'total_snapshots'=>0,'high_risk'=>0,'flagged'=>0,'active_now'=>0];
}

echo html_writer::start_div('learnshield-stats-bar mb-4');
foreach ([
    ['Proctored Quizzes', $stats->proctored_quizzes],
    ['Students Monitored', $stats->total_students],
    ['Active Now', $stats->active_now, 'color:#28a745'],
    ['High Risk', $stats->high_risk,   'color:#dc3545'],
    ['Flagged',   $stats->flagged,     'color:#fd7e14'],
] as $s) {
    echo html_writer::start_div('learnshield-stat-card');
    $style = isset($s[2]) ? ' style="' . $s[2] . '"' : '';
    echo "<div class=\"stat-number\"{$style}>{$s[1]}</div>";
    echo html_writer::div($s[0], 'stat-label');
    echo html_writer::end_div();
}
echo html_writer::end_div();

// -----------------------------------------------------------------------
// User search widget (top, so proctor IDs are available before the tables)
// -----------------------------------------------------------------------
echo html_writer::start_div('card mb-4');
echo html_writer::div('🔍 Find User for Proctor Assignment', 'card-header font-weight-bold');
echo html_writer::start_div('card-body');
echo html_writer::tag('p', 'Search by name, email, or username. Use the User ID shown below in the assignment form.',
    ['class' => 'text-muted small mb-2']);

$searchform = new moodle_url('/mod/quiz/accessrule/learnshield/admin.php');
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $searchform->out(false), 'class' => 'd-flex gap-2 flex-wrap align-items-center mb-3']);
echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'searchuser', 'value' => s($searchuser),
    'placeholder' => 'Name, email, or username…', 'class' => 'form-control', 'style' => 'max-width:280px;']);
echo html_writer::tag('button', 'Search', ['type' => 'submit', 'class' => 'btn btn-secondary']);
if ($filtercourseid) {
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'filtercourseid', 'value' => $filtercourseid]);
}
echo html_writer::end_tag('form');

if (!empty($searchuser)) {
    $like = '%' . $searchuser . '%';
    try {
        $foundusers = $DB->get_records_sql(
            "SELECT id, firstname, lastname, email, username FROM {user}
              WHERE (" . $DB->sql_like('firstname', ':fn', false) .
                  " OR " . $DB->sql_like('lastname',  ':ln', false) .
                  " OR " . $DB->sql_like('email',     ':em', false) .
                  " OR " . $DB->sql_like('username',  ':un', false) .
              ") AND deleted = 0 LIMIT 20",
            ['fn' => $like, 'ln' => $like, 'em' => $like, 'un' => $like]
        );
        if ($foundusers) {
            echo html_writer::start_tag('table', ['class' => 'table table-sm table-bordered']);
            echo html_writer::tag('tr',
                html_writer::tag('th', 'User ID') . html_writer::tag('th', 'Name') .
                html_writer::tag('th', 'Email') . html_writer::tag('th', 'Username'),
                ['class' => 'thead-light']
            );
            foreach ($foundusers as $u) {
                echo html_writer::tag('tr',
                    html_writer::tag('td', html_writer::tag('code', $u->id, ['title' => 'Copy this ID to the assignment form'])) .
                    html_writer::tag('td', s(fullname($u))) .
                    html_writer::tag('td', s($u->email)) .
                    html_writer::tag('td', s($u->username))
                );
            }
            echo html_writer::end_tag('table');
        } else {
            echo $OUTPUT->notification('No users found for "' . s($searchuser) . '"', 'info');
        }
    } catch (Exception $e) { /* Skip */ }
}
echo html_writer::end_div();
echo html_writer::end_div();

// -----------------------------------------------------------------------
// Course selector / filter (dropdown)
// -----------------------------------------------------------------------
try {
    $modulerow = $DB->get_record('modules', ['name' => 'quiz']);
    $moduleid  = $modulerow ? $modulerow->id : 0;

    $allcourses = $DB->get_records_sql(
        "SELECT DISTINCT c.id, c.fullname, c.shortname
           FROM {course} c
           INNER JOIN {course_modules} cm ON cm.course = c.id
           WHERE cm.module = :mid AND c.id <> :site
           ORDER BY c.fullname ASC",
        ['mid' => $moduleid, 'site' => SITEID]
    );
} catch (Exception $e) {
    $allcourses = []; $moduleid = 0;
}

echo html_writer::tag('h4', 'Courses &amp; Quizzes', ['class' => 'mb-3']);

// Course filter dropdown + search.
$courseopts = [0 => '— Select a course —'];
foreach ($allcourses as $c) { $courseopts[$c->id] = s($c->fullname) . ' (' . s($c->shortname) . ')'; }

$filterurl = new moodle_url('/mod/quiz/accessrule/learnshield/admin.php', ['searchuser' => $searchuser]);
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $filterurl->out(false), 'class' => 'd-flex gap-2 mb-3 flex-wrap align-items-center']);
echo html_writer::select($courseopts, 'filtercourseid', $filtercourseid, false,
    ['class' => 'form-control', 'style' => 'max-width:360px;', 'id' => 'ls-course-filter']);
echo html_writer::tag('button', 'Show Quizzes', ['type' => 'submit', 'class' => 'btn btn-primary']);
if ($filtercourseid) {
    echo html_writer::link(
        new moodle_url('/mod/quiz/accessrule/learnshield/admin.php', ['searchuser' => $searchuser]),
        'Clear', ['class' => 'btn btn-outline-secondary']
    );
}
echo html_writer::end_tag('form');

// -----------------------------------------------------------------------
// Quizzes for selected course
// -----------------------------------------------------------------------
if ($filtercourseid && isset($allcourses[$filtercourseid])) {
    $course = $allcourses[$filtercourseid];

    try {
        $quizzes = $DB->get_records_sql(
            "SELECT cm.id AS cmid, q.id AS quizid, q.name AS quizname,
                    ls.id AS ls_id,
                    (SELECT COUNT(DISTINCT userid) FROM {quizaccess_learnshield_logs} WHERE quizid = cm.id) AS students,
                    (SELECT COUNT(id) FROM {quizaccess_learnshield_sessions} WHERE quizid = cm.id AND risk_level = 'high') AS high_risk,
                    (SELECT COUNT(id) FROM {quizaccess_learnshield_sessions} WHERE quizid = cm.id AND status = 'active') AS active
               FROM {course_modules} cm
               INNER JOIN {quiz} q ON q.id = cm.instance
               LEFT JOIN {quizaccess_learnshield} ls ON ls.quizid = cm.id
              WHERE cm.course = :courseid AND cm.module = :moduleid
              ORDER BY q.name ASC",
            ['courseid' => $filtercourseid, 'moduleid' => $moduleid]
        );
    } catch (Exception $e) { $quizzes = []; }

    echo html_writer::start_div('card mb-4');
    echo html_writer::start_div('card-header bg-light d-flex justify-content-between align-items-center');
    echo html_writer::tag('h5', s($course->fullname) . ' (' . s($course->shortname) . ')', ['class' => 'mb-0']);
    echo html_writer::tag('small', count($quizzes) . ' quizzes', ['class' => 'text-muted']);
    echo html_writer::end_div();

    if (empty($quizzes)) {
        echo html_writer::div(html_writer::tag('p', 'No quizzes found in this course.', ['class' => 'text-muted mb-0']), 'card-body');
    } else {
        echo html_writer::start_div('card-body p-0');
        foreach ($quizzes as $quiz) {
            $proctored = !empty($quiz->ls_id);

            // Load assigned proctors.
            $proctors = [];
            try {
                foreach ($DB->get_records('quizaccess_learnshield_proctor_assign', ['quizid' => $quiz->cmid]) as $p) {
                    $pu = $DB->get_record('user', ['id' => $p->user_id], 'id,firstname,lastname');
                    if ($pu) {
                        $removeform = html_writer::start_tag('form', ['method' => 'post', 'action' => $posturl, 'class' => 'd-inline']) .
                            html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',   'value' => 'removeproctor']) .
                            html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',  'value' => sesskey()]) .
                            html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'quizid',   'value' => $quiz->cmid]) .
                            html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'assignid', 'value' => $p->id]) .
                            html_writer::tag('button', '✕', ['type' => 'submit', 'class' => 'btn btn-link btn-sm text-danger p-0', 'title' => 'Remove']) .
                            html_writer::end_tag('form');
                        $proctors[] = s(fullname($pu)) . ' <small class="text-muted">(' . s($p->role) . ')</small> ' . $removeform;
                    }
                }
            } catch (Exception $e) { /* Skip */ }

            $statusbadge = $proctored
                ? html_writer::tag('span', '✓ Proctored', ['class' => 'badge badge-success'])
                : html_writer::tag('span', 'Not Proctored', ['class' => 'badge badge-secondary']);

            echo html_writer::start_div('border-bottom p-3');
            echo html_writer::start_div('d-flex flex-wrap gap-2 align-items-start');

            // Quiz name + status.
            echo html_writer::start_div();
            echo html_writer::tag('strong', s($quiz->quizname));
            echo html_writer::tag('br');
            echo $statusbadge;
            if ($quiz->students > 0) {
                echo ' ' . html_writer::tag('small', $quiz->students . ' students | ' . ($quiz->active ?: 0) . ' active | ' . ($quiz->high_risk ?: 0) . ' high risk', ['class' => 'text-muted']);
            }
            if (!empty($proctors)) {
                echo html_writer::tag('div', 'Proctors: ' . implode(', ', $proctors), ['class' => 'small mt-1']);
            }
            echo html_writer::end_div();

            // Action buttons.
            echo html_writer::start_div('ml-auto d-flex flex-wrap gap-1 align-items-center');

            if ($proctored) {
                $atturl  = new moodle_url('/mod/quiz/accessrule/learnshield/attempts.php',      ['courseid' => $filtercourseid, 'cmid' => $quiz->cmid]);
                $repurl  = new moodle_url('/mod/quiz/accessrule/learnshield/report.php',         ['courseid' => $filtercourseid, 'cmid' => $quiz->cmid]);
                $liveurl = new moodle_url('/mod/quiz/accessrule/learnshield/proctordashboard.php',['courseid' => $filtercourseid, 'cmid' => $quiz->cmid]);
                $pdfurl  = new moodle_url('/mod/quiz/accessrule/learnshield/pdf_report.php',     ['courseid' => $filtercourseid, 'cmid' => $quiz->cmid]);
                $audurl  = new moodle_url('/mod/quiz/accessrule/learnshield/auditlog.php',       ['courseid' => $filtercourseid, 'cmid' => $quiz->cmid]);

                echo html_writer::link($atturl,  'Attempts', ['class' => 'btn btn-primary btn-sm']);
                echo html_writer::link($repurl,  'Report',   ['class' => 'btn btn-secondary btn-sm']);
                echo html_writer::link($liveurl, 'Live',     ['class' => 'btn btn-success btn-sm']);
                echo html_writer::link($audurl,  'Audit',    ['class' => 'btn btn-info btn-sm']);
                echo html_writer::link($pdfurl,  'PDF',      ['class' => 'btn btn-outline-dark btn-sm', 'target' => '_blank']);

                // Assign proctor form.
                echo html_writer::start_tag('form', ['method' => 'post', 'action' => $posturl, 'class' => 'd-flex gap-1 align-items-center']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',   'value' => 'addproctor']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',  'value' => sesskey()]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'cmid',     'value' => $quiz->cmid]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $filtercourseid]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'quizid',   'value' => $quiz->quizid]);
                echo html_writer::empty_tag('input', [
                    'type' => 'text', 'name' => 'userident', 'placeholder' => 'Name/email/ID',
                    'class' => 'form-control form-control-sm', 'style' => 'width:140px;',
                    'title' => 'Enter user ID, email, or name to assign as proctor',
                ]);
                echo html_writer::select(
                    ['proctor' => 'Proctor', 'supervisor' => 'Supervisor', 'reviewer' => 'Reviewer'],
                    'role', 'proctor', false,
                    ['class' => 'form-control form-control-sm', 'style' => 'width:110px;']
                );
                echo html_writer::tag('button', '+ Assign', ['type' => 'submit', 'class' => 'btn btn-warning btn-sm']);
                echo html_writer::end_tag('form');

                // Disable proctoring.
                echo html_writer::start_tag('form', ['method' => 'post', 'action' => $posturl, 'class' => 'd-inline']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'disableproctoring']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'quizid',  'value' => $quiz->quizid]);
                echo html_writer::tag('button', 'Disable', ['type' => 'submit', 'class' => 'btn btn-outline-danger btn-sm',
                    'onclick' => 'return confirm("Disable proctoring for this quiz?")']);
                echo html_writer::end_tag('form');

            } else {
                // Enable proctoring (POST form).
                echo html_writer::start_tag('form', ['method' => 'post', 'action' => $posturl]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'enableproctoring']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'quizid',  'value' => $quiz->quizid]);
                echo html_writer::tag('button', '+ Enable Proctoring', ['type' => 'submit', 'class' => 'btn btn-outline-success btn-sm']);
                echo html_writer::end_tag('form');
            }

            echo html_writer::end_div(); // actions
            echo html_writer::end_div(); // flex row
            echo html_writer::end_div(); // border-bottom p-3
        }
    }

    echo html_writer::end_div(); // .card
    if (isset($quiz)) { echo html_writer::end_div(); } // .card-body (if quizzes exist)

} else {
    echo $OUTPUT->notification(
        empty($allcourses)
            ? 'No courses with quizzes found.'
            : 'Select a course above to view and manage its quizzes.',
        'info'
    );
}

echo $OUTPUT->footer();
