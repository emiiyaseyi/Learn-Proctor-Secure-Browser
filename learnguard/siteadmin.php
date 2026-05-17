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
 * Site-level administration for mod_learnguard.
 *
 * Accessible only to Moodle site administrators. Shows ALL LearnGuard
 * assessments across the entire site — statistics, high-risk flags,
 * proctor assignments, and navigation to each course-level admin page.
 *
 * URL: /mod/learnguard/siteadmin.php
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

// SITE ADMIN ONLY.
require_login();
require_capability('moodle/site:config', context_system::instance());

$tab = optional_param('tab', 'overview', PARAM_ALPHA);

$PAGE->set_url('/mod/learnguard/siteadmin.php', ['tab' => $tab]);
$PAGE->set_context(context_system::instance());
$PAGE->set_title('LearnGuard — Site Administration');
$PAGE->set_heading('LearnGuard — Site Administration');
$PAGE->set_pagelayout('admin');
$PAGE->requires->css('/mod/learnguard/styles.css');

echo $OUTPUT->header();

// ---- Site-wide stats ----
$totalassessments = $DB->count_records('learnguard');
$totalattempts    = $DB->count_records('learnguard_attempts');
$inprogress       = $DB->count_records('learnguard_attempts', ['state' => 'inprogress']);
$finished         = $DB->count_records('learnguard_attempts', ['state' => 'finished']);
$suspended        = $DB->count_records('learnguard_attempts', ['state' => 'suspended']);
$highrisk         = $DB->count_records('learnguard_attempts', ['risk_level' => 'high']);

// ---- Header ----
echo html_writer::start_div('', ['style' =>
    'background:linear-gradient(135deg,#0f6cbf,#0a4d8c);color:#fff;border-radius:8px;' .
    'padding:20px 24px;margin-bottom:20px;']);
echo html_writer::tag('h2', '🛡 LearnGuard — Site Administration', ['style' => 'margin:0 0 4px;font-size:22px;']);
echo html_writer::tag('p', 'Site-wide overview of all LearnGuard assessments',
    ['style' => 'margin:0;opacity:0.8;font-size:14px;']);
echo html_writer::end_div();

// ---- Tabs ----
$tabs = [
    'overview'     => '📊 Overview',
    'assessments'  => '📋 All Assessments',
    'highrisk'     => '⚠️ High Risk',
    'proctors'     => '👤 Proctor Assignments',
];
echo html_writer::start_div('d-flex gap-2 flex-wrap mb-4',
    ['style' => 'border-bottom:2px solid #dee2e6;padding-bottom:0;']);
foreach ($tabs as $key => $label) {
    $active = ($tab === $key);
    echo html_writer::link(
        new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => $key]),
        $label,
        ['style' => 'padding:8px 16px;text-decoration:none;font-size:14px;font-weight:600;border-radius:4px 4px 0 0;' .
            ($active ? 'background:#0f6cbf;color:#fff;' : 'color:#495057;background:#f8f9fa;border:1px solid #dee2e6;')]
    );
}
echo html_writer::end_div();

// =====================================================================
// TAB: Overview
// =====================================================================
if ($tab === 'overview') {

    // Stat cards.
    echo html_writer::start_div('d-flex gap-3 flex-wrap mb-4');
    foreach ([
        ['Assessments',  $totalassessments, '#0f6cbf'],
        ['Total Attempts',$totalattempts,   '#495057'],
        ['In Progress',  $inprogress,       '#fd7e14'],
        ['Completed',    $finished,         '#28a745'],
        ['Suspended',    $suspended,        '#dc3545'],
        ['High Risk',    $highrisk,         '#6f42c1'],
    ] as [$label, $count, $colour]) {
        echo html_writer::start_div('', ['style' =>
            'background:' . $colour . ';color:#fff;border-radius:8px;padding:16px 24px;' .
            'min-width:120px;text-align:center;']);
        echo html_writer::tag('div', $count, ['style' => 'font-size:28px;font-weight:700;']);
        echo html_writer::tag('div', $label, ['style' => 'font-size:12px;opacity:0.85;']);
        echo html_writer::end_div();
    }
    echo html_writer::end_div();

    // Recently active assessments.
    echo html_writer::tag('h4', '🔴 Currently In-Progress Attempts (all courses)', ['class' => 'mb-3 mt-2']);
    $active = $DB->get_records_sql(
        "SELECT a.id, a.userid, a.risk_score, a.risk_level, a.timestart,
                u.firstname, u.lastname, u.email,
                lg.name AS assessmentname, c.fullname AS coursename,
                cm.id AS cmid
           FROM {learnguard_attempts} a
           JOIN {user}       u   ON u.id   = a.userid
           JOIN {learnguard} lg  ON lg.id  = a.learnguard
           JOIN {course}     c   ON c.id   = lg.course
           JOIN {course_modules} cm ON cm.instance = lg.id AND cm.module = (
               SELECT id FROM {modules} WHERE name = 'learnguard')
          WHERE a.state = 'inprogress'
       ORDER BY a.risk_score DESC, a.timestart DESC
          LIMIT 40",
        []
    );

    if (empty($active)) {
        echo html_writer::div('No active attempts right now.', 'alert alert-info');
    } else {
        $riskcolours = ['low' => '#28a745', 'medium' => '#fd7e14', 'high' => '#dc3545'];
        echo html_writer::start_tag('table', ['class' => 'generaltable w-100']);
        echo html_writer::start_tag('thead');
        echo html_writer::start_tag('tr');
        foreach (['Student', 'Assessment', 'Course', 'Risk', 'Started', 'Actions'] as $h) {
            echo html_writer::tag('th', $h, ['style' => 'padding:8px 10px;']);
        }
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');
        foreach ($active as $a) {
            $rc = $riskcolours[$a->risk_level] ?? '#6c757d';
            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', fullname($a));
            echo html_writer::tag('td', format_string($a->assessmentname));
            echo html_writer::tag('td', format_string($a->coursename));
            echo html_writer::tag('td', html_writer::tag('span', $a->risk_score . ' ' . strtoupper($a->risk_level), [
                'class' => 'lg-badge-risk', 'style' => 'background:' . $rc . ';',
            ]));
            echo html_writer::tag('td', userdate($a->timestart, '%H:%M'));
            echo html_writer::start_tag('td');
            echo html_writer::link(
                new moodle_url('/mod/learnguard/attemptdetail.php', ['id' => $a->cmid, 'attempt' => $a->id]),
                '🔍 Review',
                ['class' => 'btn btn-sm btn-outline-primary']
            );
            echo ' ';
            echo html_writer::link(
                new moodle_url('/mod/learnguard/admin.php', ['id' => $a->cmid]),
                'Admin →',
                ['class' => 'btn btn-sm btn-outline-secondary']
            );
            echo html_writer::end_tag('td');
            echo html_writer::end_tag('tr');
        }
        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');
    }
}

// =====================================================================
// TAB: All Assessments
// =====================================================================
if ($tab === 'assessments') {
    echo html_writer::tag('h4', 'All LearnGuard Assessments (' . $totalassessments . ')', ['class' => 'mb-3']);

    $assessments = $DB->get_records_sql(
        "SELECT lg.id, lg.name, lg.proctoring, lg.timelimit, lg.grade, lg.show_results,
                lg.results_released, lg.timecreated,
                c.id AS courseid, c.fullname AS coursename,
                cm.id AS cmid,
                (SELECT COUNT(*) FROM {learnguard_attempts} a WHERE a.learnguard = lg.id) AS totalattempts,
                (SELECT COUNT(*) FROM {learnguard_attempts} a WHERE a.learnguard = lg.id AND a.state = 'inprogress') AS active,
                (SELECT COUNT(*) FROM {learnguard_attempts} a WHERE a.learnguard = lg.id AND a.risk_level = 'high') AS highrisk
           FROM {learnguard} lg
           JOIN {course} c ON c.id = lg.course
           JOIN {course_modules} cm ON cm.instance = lg.id AND cm.module = (
               SELECT id FROM {modules} WHERE name = 'learnguard')
       ORDER BY c.fullname, lg.name",
        []
    );

    if (empty($assessments)) {
        echo html_writer::div('No LearnGuard assessments found on this site.', 'alert alert-info');
    } else {
        echo html_writer::start_tag('table', ['class' => 'generaltable w-100']);
        echo html_writer::start_tag('thead');
        echo html_writer::start_tag('tr');
        foreach (['Assessment', 'Course', 'Proctoring', 'Grade', 'Attempts', 'Active', 'High Risk', 'Results', 'Actions'] as $h) {
            echo html_writer::tag('th', $h, ['style' => 'padding:8px 10px;white-space:nowrap;']);
        }
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');

        foreach ($assessments as $a) {
            $resultStatus = !isset($a->show_results) || $a->show_results == 1
                ? '✅ Immediate'
                : ($a->results_released > 0
                    ? '📬 Released ' . userdate($a->results_released, '%d/%m/%y')
                    : '⏳ Held');

            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', html_writer::link(
                new moodle_url('/mod/learnguard/view.php', ['id' => $a->cmid]),
                format_string($a->name),
                ['target' => '_blank']
            ));
            echo html_writer::tag('td', html_writer::link(
                new moodle_url('/course/view.php', ['id' => $a->courseid]),
                format_string($a->coursename)
            ));
            echo html_writer::tag('td', $a->proctoring ? '🛡 On' : 'Off');
            echo html_writer::tag('td', (int)$a->grade);
            echo html_writer::tag('td', (int)$a->totalattempts, ['style' => 'text-align:center;']);
            echo html_writer::tag('td',
                $a->active > 0
                    ? html_writer::tag('span', $a->active . ' 🔴', ['style' => 'color:#dc3545;font-weight:700;'])
                    : '0',
                ['style' => 'text-align:center;']
            );
            echo html_writer::tag('td',
                $a->highrisk > 0
                    ? html_writer::tag('span', $a->highrisk, ['style' => 'color:#dc3545;font-weight:700;'])
                    : '0',
                ['style' => 'text-align:center;']
            );
            echo html_writer::tag('td', $resultStatus, ['style' => 'white-space:nowrap;font-size:13px;']);
            echo html_writer::start_tag('td');
            echo html_writer::link(
                new moodle_url('/mod/learnguard/admin.php', ['id' => $a->cmid]),
                '⚙️ Admin',
                ['class' => 'btn btn-sm btn-outline-primary']
            );
            echo ' ';
            echo html_writer::link(
                new moodle_url('/mod/learnguard/proctordashboard.php', ['id' => $a->cmid]),
                '🔴 Live',
                ['class' => 'btn btn-sm btn-outline-danger']
            );
            echo html_writer::end_tag('td');
            echo html_writer::end_tag('tr');
        }
        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');
    }

    // Note about creating new assessments.
    echo html_writer::start_div('alert alert-info mt-3');
    echo html_writer::tag('strong', 'Creating new assessments: ');
    echo 'Go to the course where you want to add a LearnGuard activity, click "Add an activity or resource", ' .
         'and select "LearnGuard Assessment". ';
    echo html_writer::link(
        new moodle_url('/course/index.php'),
        'Browse Courses →',
        ['class' => 'btn btn-sm btn-primary']
    );
    echo html_writer::end_div();
}

// =====================================================================
// TAB: High Risk
// =====================================================================
if ($tab === 'highrisk') {
    echo html_writer::tag('h4', '⚠️ All High-Risk & Suspicious Attempts (site-wide)', ['class' => 'mb-3']);

    $highriskAttempts = $DB->get_records_sql(
        "SELECT a.id, a.userid, a.attempt, a.state, a.risk_score, a.risk_level,
                a.timestart, a.timefinish, a.tab_switches, a.fs_exits, a.face_missing,
                u.firstname, u.lastname, u.email,
                lg.name AS assessmentname,
                c.fullname AS coursename,
                cm.id AS cmid
           FROM {learnguard_attempts} a
           JOIN {user}           u   ON u.id = a.userid
           JOIN {learnguard}     lg  ON lg.id = a.learnguard
           JOIN {course}         c   ON c.id = lg.course
           JOIN {course_modules} cm  ON cm.instance = lg.id AND cm.module = (
               SELECT id FROM {modules} WHERE name = 'learnguard')
          WHERE a.risk_level IN ('high', 'medium')
       ORDER BY a.risk_score DESC
          LIMIT 200",
        []
    );

    if (empty($highriskAttempts)) {
        echo html_writer::div('No medium or high risk attempts found.', 'alert alert-success');
    } else {
        $riskcolours = ['low' => '#28a745', 'medium' => '#fd7e14', 'high' => '#dc3545'];
        echo html_writer::start_tag('table', ['class' => 'generaltable w-100']);
        echo html_writer::start_tag('thead');
        echo html_writer::start_tag('tr');
        foreach (['Student', 'Assessment', 'Course', 'State', 'Risk Score', 'Risk Level', 'Started', 'Actions'] as $h) {
            echo html_writer::tag('th', $h, ['style' => 'padding:8px 10px;']);
        }
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');

        foreach ($highriskAttempts as $a) {
            $rc = $riskcolours[$a->risk_level] ?? '#6c757d';
            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', fullname($a));
            echo html_writer::tag('td', format_string($a->assessmentname));
            echo html_writer::tag('td', format_string($a->coursename));
            echo html_writer::tag('td', ucfirst($a->state));
            echo html_writer::tag('td', html_writer::tag('strong', $a->risk_score));
            echo html_writer::tag('td', html_writer::tag('span', strtoupper($a->risk_level), [
                'class' => 'lg-badge-risk', 'style' => 'background:' . $rc . ';',
            ]));
            echo html_writer::tag('td', userdate($a->timestart, '%d/%m/%y %H:%M'));
            echo html_writer::start_tag('td');
            echo html_writer::link(
                new moodle_url('/mod/learnguard/attemptdetail.php', ['id' => $a->cmid, 'attempt' => $a->id]),
                '🔍 Review',
                ['class' => 'btn btn-sm btn-outline-danger']
            );
            echo html_writer::end_tag('td');
            echo html_writer::end_tag('tr');
        }
        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');
    }
}

// =====================================================================
// TAB: Proctor Assignments (site-wide)
// =====================================================================
if ($tab === 'proctors') {
    echo html_writer::tag('h4', '👤 All Proctor Assignments (site-wide)', ['class' => 'mb-3']);

    $assignments = $DB->get_records_sql(
        "SELECT p.id AS pid, p.role, p.timemodified,
                u.id AS userid, u.firstname, u.lastname, u.email,
                lg.name AS assessmentname,
                c.fullname AS coursename,
                cm.id AS cmid
           FROM {learnguard_proctors} p
           JOIN {user}           u   ON u.id = p.userid
           JOIN {learnguard}     lg  ON lg.id = p.learnguard
           JOIN {course}         c   ON c.id = lg.course
           JOIN {course_modules} cm  ON cm.instance = lg.id AND cm.module = (
               SELECT id FROM {modules} WHERE name = 'learnguard')
       ORDER BY c.fullname, lg.name, u.lastname",
        []
    );

    if (empty($assignments)) {
        echo html_writer::div('No proctor assignments found.', 'alert alert-info');
    } else {
        echo html_writer::start_tag('table', ['class' => 'generaltable w-100']);
        echo html_writer::start_tag('thead');
        echo html_writer::start_tag('tr');
        foreach (['Proctor', 'Email', 'Assessment', 'Course', 'Role', 'Assigned', 'Manage'] as $h) {
            echo html_writer::tag('th', $h, ['style' => 'padding:8px 10px;']);
        }
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');
        foreach ($assignments as $p) {
            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', fullname($p));
            echo html_writer::tag('td', $p->email);
            echo html_writer::tag('td', format_string($p->assessmentname));
            echo html_writer::tag('td', format_string($p->coursename));
            echo html_writer::tag('td', ucfirst($p->role));
            echo html_writer::tag('td', userdate($p->timemodified, '%d/%m/%Y'));
            echo html_writer::tag('td', html_writer::link(
                new moodle_url('/mod/learnguard/admin.php', ['id' => $p->cmid, 'tab' => 'proctors']),
                'Manage →',
                ['class' => 'btn btn-sm btn-outline-secondary']
            ));
            echo html_writer::end_tag('tr');
        }
        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');
    }

    echo html_writer::tag('p',
        'To assign new proctors, go to the specific assessment\'s Admin Backend → Proctors tab.',
        ['style' => 'color:#6c757d;font-size:13px;margin-top:12px;']
    );
}

echo $OUTPUT->footer();
