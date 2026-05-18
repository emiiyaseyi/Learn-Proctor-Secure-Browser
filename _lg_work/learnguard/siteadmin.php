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
 * proctor assignments, media gallery, and navigation to each course-level
 * admin page.
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

// -----------------------------------------------------------------------
// POST ACTIONS (proctors)
// -----------------------------------------------------------------------
if (data_submitted() && confirm_sesskey()) {

    $postaction = optional_param('postaction', '', PARAM_ALPHA);

    // Add a global proctor.
    if ($postaction === 'addglobalproctor') {
        $search = trim(required_param('usersearch', PARAM_RAW));
        $user = $DB->get_record('user', ['email' => $search, 'deleted' => 0])
              ?: $DB->get_record('user', ['username' => $search, 'deleted' => 0]);
        if (!$user && is_numeric($search)) {
            $user = $DB->get_record('user', ['id' => (int)$search, 'deleted' => 0]);
        }
        if ($user) {
            if (!$DB->record_exists_select('learnguard_proctors', 'userid = :uid AND is_global = 1', ['uid' => $user->id])) {
                $DB->insert_record('learnguard_proctors', (object)[
                    'learnguard'   => 0,
                    'userid'       => $user->id,
                    'role'         => 'global_proctor',
                    'is_global'    => 1,
                    'timemodified' => time(),
                ]);
            }
            redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'proctors']),
                fullname($user) . ' added as a Global Proctor.', null, \core\output\notification::NOTIFY_SUCCESS);
        } else {
            redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'proctors']),
                'User not found: ' . s($search), null, \core\output\notification::NOTIFY_ERROR);
        }
    }

    // Remove a global proctor.
    if ($postaction === 'removeglobalproctor') {
        $proctorid = required_param('proctorid', PARAM_INT);
        $DB->delete_records('learnguard_proctors', ['id' => $proctorid, 'is_global' => 1]);
        redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'proctors']),
            'Global proctor removed.', null, \core\output\notification::NOTIFY_SUCCESS);
    }

    // Add a per-assessment proctor from site admin.
    if ($postaction === 'addassessmentproctor') {
        $lgid   = required_param('lgid', PARAM_INT);
        $search = trim(required_param('usersearch', PARAM_RAW));
        $instance = $DB->get_record('learnguard', ['id' => $lgid], '*', MUST_EXIST);
        $user = $DB->get_record('user', ['email' => $search, 'deleted' => 0])
              ?: $DB->get_record('user', ['username' => $search, 'deleted' => 0]);
        if (!$user && is_numeric($search)) {
            $user = $DB->get_record('user', ['id' => (int)$search, 'deleted' => 0]);
        }
        if ($user) {
            if (!$DB->record_exists('learnguard_proctors', ['learnguard' => $lgid, 'userid' => $user->id])) {
                $DB->insert_record('learnguard_proctors', (object)[
                    'learnguard'   => $lgid,
                    'userid'       => $user->id,
                    'role'         => 'proctor',
                    'is_global'    => 0,
                    'timemodified' => time(),
                ]);
            }
            redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'proctors', 'lgid' => $lgid]),
                fullname($user) . ' assigned as proctor for ' . format_string($instance->name) . '.',
                null, \core\output\notification::NOTIFY_SUCCESS);
        } else {
            redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'proctors', 'lgid' => $lgid]),
                'User not found: ' . s($search), null, \core\output\notification::NOTIFY_ERROR);
        }
    }

    // Remove a per-assessment proctor from site admin.
    if ($postaction === 'removeassessmentproctor') {
        $proctorid = required_param('proctorid', PARAM_INT);
        $lgid      = required_param('lgid', PARAM_INT);
        $DB->delete_records('learnguard_proctors', ['id' => $proctorid, 'learnguard' => $lgid, 'is_global' => 0]);
        redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'proctors', 'lgid' => $lgid]),
            'Proctor removed.', null, \core\output\notification::NOTIFY_SUCCESS);
    }

    // Branding save.
    if ($postaction === '' && $tab === 'branding') {
        $newprimary = optional_param('brand_primary', '#0f6cbf', PARAM_TEXT);
        $newaccent  = optional_param('brand_accent',  '#28a745', PARAM_TEXT);
        $newname    = optional_param('brand_name',    '',        PARAM_TEXT);
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $newprimary)) { set_config('brand_primary', $newprimary, 'learnguard'); }
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $newaccent))  { set_config('brand_accent',  $newaccent,  'learnguard'); }
        set_config('brand_name', clean_param($newname, PARAM_TEXT), 'learnguard');
        purge_all_caches();
        redirect(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'branding']),
            'Branding saved. Theme cache purged.', null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

echo $OUTPUT->header();

$brand = learnguard_brand();

// ---- Site-wide stats ----
$totalassessments = $DB->count_records('learnguard');
$totalattempts    = $DB->count_records('learnguard_attempts');
$inprogress       = $DB->count_records('learnguard_attempts', ['state' => 'inprogress']);
$finished         = $DB->count_records('learnguard_attempts', ['state' => 'finished']);
$suspended        = $DB->count_records('learnguard_attempts', ['state' => 'suspended']);
$highrisk         = $DB->count_records('learnguard_attempts', ['risk_level' => 'high']);

echo learnguard_banner('LearnGuard — Site Administration', 'Site-wide overview of all LearnGuard assessments', '🌐');

// ---- Tabs ----
$tabs = [
    'overview'    => '📊 Overview',
    'assessments' => '📋 All Assessments',
    'highrisk'    => '⚠️ High Risk',
    'proctors'    => '👤 Proctors',
    'media'       => '🖼 Media Gallery',
    'questions'   => '🏦 Question Bank',
    'branding'    => '🎨 Branding',
];

// Quick link buttons top-right.
echo html_writer::link(new moodle_url('/mod/learnguard/overview.php'), '🗺 Overview',
    ['class' => 'btn btn-outline-secondary btn-sm', 'style' => 'float:right;margin-left:6px;margin-bottom:8px;']);
echo html_writer::link(new moodle_url('/mod/learnguard/siteadmin_ai.php'), '🤖 AI Services',
    ['class' => 'btn btn-outline-primary btn-sm', 'style' => 'float:right;margin-bottom:8px;']);

echo html_writer::start_div('d-flex gap-2 flex-wrap mb-4',
    ['style' => 'border-bottom:2px solid #dee2e6;padding-bottom:0;']);
foreach ($tabs as $key => $label) {
    $active = ($tab === $key);
    echo html_writer::link(
        new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => $key]),
        $label,
        ['style' => 'padding:8px 16px;text-decoration:none;font-size:14px;font-weight:600;border-radius:4px 4px 0 0;' .
            ($active ? 'background:' . $brand->primary . ';color:#fff;' : 'color:#495057;background:#f8f9fa;border:1px solid #dee2e6;')]
    );
}
echo html_writer::end_div();

// =====================================================================
// TAB: Overview
// =====================================================================
if ($tab === 'overview') {

    echo html_writer::start_div('d-flex gap-3 flex-wrap mb-4');
    foreach ([
        ['Assessments',   $totalassessments, $brand->primary],
        ['Total Attempts', $totalattempts,   '#495057'],
        ['In Progress',   $inprogress,       '#fd7e14'],
        ['Completed',     $finished,         '#28a745'],
        ['Suspended',     $suspended,        '#dc3545'],
        ['High Risk',     $highrisk,         '#6f42c1'],
    ] as [$label, $count, $colour]) {
        echo html_writer::start_div('', ['style' =>
            'background:' . $colour . ';color:#fff;border-radius:8px;padding:16px 24px;' .
            'min-width:120px;text-align:center;']);
        echo html_writer::tag('div', $count, ['style' => 'font-size:28px;font-weight:700;']);
        echo html_writer::tag('div', $label, ['style' => 'font-size:12px;opacity:0.85;']);
        echo html_writer::end_div();
    }
    echo html_writer::end_div();

    echo html_writer::tag('h4', '🔴 Currently In-Progress Attempts (all courses)', ['class' => 'mb-3 mt-2']);
    $active = $DB->get_records_sql(
        "SELECT a.id, a.userid, a.risk_score, a.risk_level, a.timestart,
                u.firstname, u.lastname, u.email,
                lg.name AS assessmentname, c.fullname AS coursename, cm.id AS cmid
           FROM {learnguard_attempts} a
           JOIN {user}       u   ON u.id   = a.userid
           JOIN {learnguard} lg  ON lg.id  = a.learnguard
           JOIN {course}     c   ON c.id   = lg.course
           JOIN {modules} mdl ON mdl.name = 'learnguard'
           JOIN {course_modules} cm ON cm.instance = lg.id AND cm.module = mdl.id
          WHERE a.state = 'inprogress'
       ORDER BY a.risk_score DESC, a.timestart DESC
          LIMIT 40", []
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
                '🔍 Review', ['class' => 'btn btn-sm btn-outline-primary']
            );
            echo ' ';
            echo html_writer::link(
                new moodle_url('/mod/learnguard/admin.php', ['id' => $a->cmid]),
                'Admin →', ['class' => 'btn btn-sm btn-outline-secondary']
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
        "SELECT lg.id, lg.name, lg.proctoring, lg.timelimit, lg.grade, lg.timecreated,
                COALESCE(lg.show_results, 1) AS show_results,
                COALESCE(lg.results_released, 0) AS results_released,
                c.id AS courseid, c.fullname AS coursename, cm.id AS cmid,
                (SELECT COUNT(*) FROM {learnguard_attempts} a WHERE a.learnguard = lg.id) AS totalattempts,
                (SELECT COUNT(*) FROM {learnguard_attempts} a WHERE a.learnguard = lg.id AND a.state = 'inprogress') AS active,
                (SELECT COUNT(*) FROM {learnguard_attempts} a WHERE a.learnguard = lg.id AND a.risk_level = 'high') AS highrisk
           FROM {learnguard} lg
           JOIN {course} c ON c.id = lg.course
           JOIN {modules} mdl ON mdl.name = 'learnguard'
           JOIN {course_modules} cm ON cm.instance = lg.id AND cm.module = mdl.id
       ORDER BY c.fullname, lg.name", []
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
                : ($a->results_released > 0 ? '📬 Released ' . userdate($a->results_released, '%d/%m/%y') : '⏳ Held');
            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', html_writer::link(
                new moodle_url('/mod/learnguard/view.php', ['id' => $a->cmid]), format_string($a->name), ['target' => '_blank']
            ));
            echo html_writer::tag('td', html_writer::link(
                new moodle_url('/course/view.php', ['id' => $a->courseid]), format_string($a->coursename)
            ));
            echo html_writer::tag('td', $a->proctoring ? '🛡 On' : 'Off');
            echo html_writer::tag('td', (int)$a->grade);
            echo html_writer::tag('td', (int)$a->totalattempts, ['style' => 'text-align:center;']);
            echo html_writer::tag('td',
                $a->active > 0 ? html_writer::tag('span', $a->active . ' 🔴', ['style' => 'color:#dc3545;font-weight:700;']) : '0',
                ['style' => 'text-align:center;']
            );
            echo html_writer::tag('td',
                $a->highrisk > 0 ? html_writer::tag('span', $a->highrisk, ['style' => 'color:#dc3545;font-weight:700;']) : '0',
                ['style' => 'text-align:center;']
            );
            echo html_writer::tag('td', $resultStatus, ['style' => 'white-space:nowrap;font-size:13px;']);
            echo html_writer::start_tag('td');
            echo html_writer::link(new moodle_url('/mod/learnguard/admin.php', ['id' => $a->cmid]), '⚙️ Admin',
                ['class' => 'btn btn-sm btn-outline-primary']);
            echo ' ';
            echo html_writer::link(new moodle_url('/mod/learnguard/proctordashboard.php', ['id' => $a->cmid]), '🔴 Live',
                ['class' => 'btn btn-sm btn-outline-danger']);
            echo html_writer::end_tag('td');
            echo html_writer::end_tag('tr');
        }
        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');
    }

    echo html_writer::start_div('alert alert-info mt-3');
    echo html_writer::tag('strong', 'Creating new assessments: ');
    echo 'Go to the course, click "Add an activity or resource", and select "LearnGuard Assessment". ';
    echo html_writer::link(new moodle_url('/course/index.php'), 'Browse Courses →', ['class' => 'btn btn-sm btn-primary']);
    echo ' ';
    echo html_writer::link(new moodle_url('/mod/learnguard/demo_setup.php'), '🎓 Create Demo Course',
        ['class' => 'btn btn-sm btn-outline-success']);
    echo html_writer::end_div();
}

// =====================================================================
// TAB: High Risk
// =====================================================================
if ($tab === 'highrisk') {
    echo html_writer::tag('h4', '⚠️ All High-Risk & Suspicious Attempts (site-wide)', ['class' => 'mb-3']);

    $highriskAttempts = $DB->get_records_sql(
        "SELECT a.id, a.userid, a.attempt, a.state, a.risk_score, a.risk_level,
                a.timestart, a.timefinish,
                u.firstname, u.lastname, u.email,
                lg.name AS assessmentname, c.fullname AS coursename, cm.id AS cmid
           FROM {learnguard_attempts} a
           JOIN {user}           u   ON u.id = a.userid
           JOIN {learnguard}     lg  ON lg.id = a.learnguard
           JOIN {course}         c   ON c.id = lg.course
           JOIN {course_modules} cm  ON cm.instance = lg.id AND cm.module = (
               SELECT id FROM {modules} WHERE name = 'learnguard')
          WHERE a.risk_level IN ('high', 'medium')
       ORDER BY a.risk_score DESC
          LIMIT 200", []
    );

    if (empty($highriskAttempts)) {
        echo html_writer::div('No medium or high risk attempts found.', 'alert alert-success');
    } else {
        $riskcolours = ['low' => '#28a745', 'medium' => '#fd7e14', 'high' => '#dc3545'];
        echo html_writer::start_tag('table', ['class' => 'generaltable w-100']);
        echo html_writer::start_tag('thead');
        echo html_writer::start_tag('tr');
        foreach (['Student', 'Assessment', 'Course', 'State', 'Risk Score', 'Level', 'Started', 'Actions'] as $h) {
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
            echo html_writer::tag('td', html_writer::tag('span', strtoupper($a->risk_level),
                ['class' => 'lg-badge-risk', 'style' => 'background:' . $rc . ';']));
            echo html_writer::tag('td', userdate($a->timestart, '%d/%m/%y %H:%M'));
            echo html_writer::start_tag('td');
            echo html_writer::link(
                new moodle_url('/mod/learnguard/attemptdetail.php', ['id' => $a->cmid, 'attempt' => $a->id]),
                '🔍 Review', ['class' => 'btn btn-sm btn-outline-danger']
            );
            echo html_writer::end_tag('td');
            echo html_writer::end_tag('tr');
        }
        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');
    }
}

// =====================================================================
// TAB: Proctors — Global + Per-Assessment
// =====================================================================
if ($tab === 'proctors') {

    $selectedLgid = optional_param('lgid', 0, PARAM_INT);

    // ── Section 1: Global Proctors ────────────────────────────────────
    echo html_writer::start_div('', ['style' =>
        'border:2px solid #6f42c1;border-radius:10px;padding:20px;margin-bottom:28px;background:#fdf8ff;']);
    echo html_writer::tag('h4', '🌐 Global Proctors', ['style' => 'color:#6f42c1;margin-bottom:6px;']);
    echo html_writer::tag('p',
        'Global proctors can view and monitor <strong>every</strong> LearnGuard assessment on this site ' .
        'without needing per-assessment assignment. Assign sparingly — typically senior invigilators only.',
        ['style' => 'color:#6c757d;font-size:13px;margin-bottom:16px;']);

    $globalProctors = $DB->get_records_sql(
        "SELECT p.id AS pid, p.timemodified, u.id AS userid, u.firstname, u.lastname, u.email
           FROM {learnguard_proctors} p
           JOIN {user} u ON u.id = p.userid
          WHERE p.is_global = 1
       ORDER BY u.lastname, u.firstname", []
    );

    if (empty($globalProctors)) {
        echo html_writer::div('No global proctors assigned yet.', 'alert alert-info');
    } else {
        echo html_writer::start_tag('table', ['class' => 'generaltable w-100', 'style' => 'margin-bottom:16px;']);
        echo html_writer::start_tag('tr');
        foreach (['Proctor', 'Email', 'Assigned', 'Remove'] as $h) {
            echo html_writer::tag('th', $h, ['style' => 'padding:8px 10px;']);
        }
        echo html_writer::end_tag('tr');
        foreach ($globalProctors as $p) {
            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', html_writer::tag('strong', fullname($p)));
            echo html_writer::tag('td', $p->email);
            echo html_writer::tag('td', userdate($p->timemodified, '%d/%m/%Y'));
            echo html_writer::start_tag('td');
            echo html_writer::start_tag('form', ['method' => 'post']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',     'value' => sesskey()]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'postaction',  'value' => 'removeglobalproctor']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'proctorid',   'value' => $p->pid]);
            echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => '× Remove',
                'class' => 'btn btn-sm btn-outline-danger',
                'onclick' => 'return confirm("Remove ' . s(fullname($p)) . ' as global proctor?")']);
            echo html_writer::end_tag('form');
            echo html_writer::end_tag('td');
            echo html_writer::end_tag('tr');
        }
        echo html_writer::end_tag('table');
    }

    // Add global proctor form.
    echo html_writer::tag('h6', 'Add a Global Proctor', ['style' => 'margin-bottom:8px;']);
    echo html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:flex;gap:8px;flex-wrap:wrap;align-items:center;']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',    'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'postaction', 'value' => 'addglobalproctor']);
    echo html_writer::empty_tag('input', [
        'type' => 'text', 'name' => 'usersearch', 'class' => 'form-control', 'style' => 'max-width:280px;',
        'placeholder' => 'Email, username or user ID', 'required' => 'required',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => '+ Add Global Proctor',
        'class' => 'btn btn-primary', 'style' => 'background:#6f42c1;border-color:#6f42c1;']);
    echo html_writer::end_tag('form');
    echo html_writer::end_div();

    // ── Section 2: Per-Assessment Proctors ───────────────────────────
    echo html_writer::tag('h4', '📋 Per-Assessment Proctor Assignment', ['class' => 'mb-2']);
    echo html_writer::tag('p', 'Select an assessment below to view or change its proctor list.',
        ['style' => 'color:#6c757d;font-size:13px;margin-bottom:14px;']);

    // Assessment picker form.
    $allAssessments = $DB->get_records_sql(
        "SELECT lg.id, lg.name, c.fullname AS coursename
           FROM {learnguard} lg
           JOIN {course} c ON c.id = lg.course
       ORDER BY c.fullname, lg.name", []
    );

    echo html_writer::start_tag('form', ['method' => 'get', 'style' => 'display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:20px;']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab', 'value' => 'proctors']);
    echo html_writer::start_tag('select', ['name' => 'lgid', 'class' => 'form-control', 'style' => 'max-width:420px;',
        'onchange' => 'this.form.submit()']);
    echo html_writer::tag('option', '— Choose an assessment —', ['value' => '0']);
    foreach ($allAssessments as $a) {
        echo html_writer::tag('option', '[' . format_string($a->coursename) . '] ' . format_string($a->name),
            ['value' => $a->id, 'selected' => ($selectedLgid === (int)$a->id) ? 'selected' : null]);
    }
    echo html_writer::end_tag('select');
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'View', 'class' => 'btn btn-outline-secondary']);
    echo html_writer::end_tag('form');

    if ($selectedLgid > 0) {
        $selInstance = $DB->get_record('learnguard', ['id' => $selectedLgid]);
        $selCm = get_coursemodule_from_instance('learnguard', $selectedLgid, 0, false, MUST_EXIST);

        echo html_writer::start_div('', ['style' =>
            'border:1px solid #dee2e6;border-radius:8px;padding:20px;background:#fff;']);
        echo html_writer::tag('h5', format_string($selInstance->name),
            ['style' => 'border-left:4px solid ' . $brand->primary . ';padding-left:10px;margin-bottom:14px;']);

        $asmtProctors = $DB->get_records_sql(
            "SELECT p.id AS pid, p.timemodified, u.id AS userid, u.firstname, u.lastname, u.email
               FROM {learnguard_proctors} p
               JOIN {user} u ON u.id = p.userid
              WHERE p.learnguard = :lgid AND p.is_global = 0
           ORDER BY u.lastname, u.firstname",
            ['lgid' => $selectedLgid]
        );

        if (empty($asmtProctors)) {
            echo html_writer::div('No proctors assigned to this assessment yet.', 'alert alert-info');
        } else {
            echo html_writer::start_tag('table', ['class' => 'generaltable w-100', 'style' => 'margin-bottom:14px;']);
            echo html_writer::start_tag('tr');
            foreach (['Proctor', 'Email', 'Assigned', 'Actions'] as $h) {
                echo html_writer::tag('th', $h, ['style' => 'padding:8px 10px;']);
            }
            echo html_writer::end_tag('tr');
            foreach ($asmtProctors as $p) {
                echo html_writer::start_tag('tr');
                echo html_writer::tag('td', fullname($p));
                echo html_writer::tag('td', $p->email);
                echo html_writer::tag('td', userdate($p->timemodified, '%d/%m/%Y'));
                echo html_writer::start_tag('td');
                echo html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:inline;']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',    'value' => sesskey()]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'postaction', 'value' => 'removeassessmentproctor']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'proctorid',  'value' => $p->pid]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'lgid',       'value' => $selectedLgid]);
                echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => '× Remove',
                    'class' => 'btn btn-sm btn-outline-danger',
                    'onclick' => 'return confirm("Remove ' . s(fullname($p)) . ' from this assessment?")']);
                echo html_writer::end_tag('form');
                echo ' ';
                echo html_writer::link(
                    new moodle_url('/mod/learnguard/proctordashboard.php', ['id' => $selCm->id]),
                    '🔴 Dashboard', ['class' => 'btn btn-sm btn-outline-primary']
                );
                echo html_writer::end_tag('td');
                echo html_writer::end_tag('tr');
            }
            echo html_writer::end_tag('table');
        }

        // Add proctor form for selected assessment.
        echo html_writer::tag('h6', 'Add Proctor to this Assessment', ['style' => 'margin-bottom:8px;']);
        echo html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:flex;gap:8px;flex-wrap:wrap;align-items:center;']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',    'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'postaction', 'value' => 'addassessmentproctor']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'lgid',       'value' => $selectedLgid]);
        echo html_writer::empty_tag('input', [
            'type' => 'text', 'name' => 'usersearch', 'class' => 'form-control', 'style' => 'max-width:280px;',
            'placeholder' => 'Email, username or user ID', 'required' => 'required',
        ]);
        echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => '+ Assign Proctor', 'class' => 'btn btn-primary']);
        echo html_writer::end_tag('form');
        echo html_writer::end_div();
    }

    // Global proctors also cover this assessment — info note.
    if (count($globalProctors) > 0) {
        echo html_writer::start_div('alert alert-info mt-3');
        echo html_writer::tag('strong', '🌐 Note: ');
        echo count($globalProctors) . ' Global Proctor' . (count($globalProctors) > 1 ? 's are' : ' is') .
             ' active — they already cover ALL assessments automatically.';
        echo html_writer::end_div();
    }
}

// =====================================================================
// TAB: Media Gallery
// =====================================================================
if ($tab === 'media') {

    echo html_writer::tag('h4', '🖼 Media Gallery — All Snapshots & Recordings', ['class' => 'mb-1']);
    echo html_writer::tag('p',
        'Webcam snapshots and Photo ID captures stored across all assessments.',
        ['style' => 'color:#6c757d;font-size:13px;margin-bottom:16px;']);

    // Total counts.
    $totalSnaps = $DB->count_records('learnguard_snapshots');
    echo html_writer::start_div('d-flex gap-3 flex-wrap mb-4');
    foreach ([
        ['Total Snapshots', $totalSnaps, $brand->primary],
        ['Webcam', $DB->count_records('learnguard_snapshots', ['snapshot_type' => 'periodic']), '#0d6efd'],
        ['Photo ID', $DB->count_records('learnguard_snapshots', ['snapshot_type' => 'photo_id']), '#6f42c1'],
    ] as [$label, $count, $colour]) {
        echo html_writer::start_div('', ['style' =>
            'background:' . $colour . ';color:#fff;border-radius:8px;padding:12px 20px;min-width:110px;text-align:center;']);
        echo html_writer::tag('div', $count, ['style' => 'font-size:24px;font-weight:700;']);
        echo html_writer::tag('div', $label, ['style' => 'font-size:11px;opacity:0.85;']);
        echo html_writer::end_div();
    }
    echo html_writer::end_div();

    // ── Filter form ───────────────────────────────────────────────────
    $filterLgid    = optional_param('filter_lgid',    0,  PARAM_INT);
    $filterStudent = optional_param('filter_student', '', PARAM_TEXT);
    $filterType    = optional_param('filter_type',    '', PARAM_ALPHA);
    $mediaPage     = optional_param('mediapage',      0,  PARAM_INT);
    $perPage       = 48;

    $allAssessments2 = $DB->get_records_sql(
        "SELECT lg.id, lg.name, c.fullname AS coursename
           FROM {learnguard} lg JOIN {course} c ON c.id = lg.course
       ORDER BY c.fullname, lg.name", []
    );

    echo html_writer::start_tag('form', [
        'method' => 'get',
        'style'  => 'background:#f8f9fa;border:1px solid #dee2e6;border-radius:8px;padding:16px;margin-bottom:20px;',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab', 'value' => 'media']);
    echo html_writer::start_div('d-flex gap-3 flex-wrap align-items-end');

    // Assessment filter.
    echo html_writer::start_div('');
    echo html_writer::tag('label', 'Assessment', ['style' => 'font-weight:600;display:block;font-size:13px;margin-bottom:4px;']);
    echo html_writer::start_tag('select', ['name' => 'filter_lgid', 'class' => 'form-control', 'style' => 'max-width:260px;']);
    echo html_writer::tag('option', '— All assessments —', ['value' => '0']);
    foreach ($allAssessments2 as $a) {
        echo html_writer::tag('option',
            '[' . format_string($a->coursename) . '] ' . format_string($a->name),
            ['value' => $a->id, 'selected' => ($filterLgid === (int)$a->id) ? 'selected' : null]);
    }
    echo html_writer::end_tag('select');
    echo html_writer::end_div();

    // Student filter.
    echo html_writer::start_div('');
    echo html_writer::tag('label', 'Student (name/email)', ['style' => 'font-weight:600;display:block;font-size:13px;margin-bottom:4px;']);
    echo html_writer::empty_tag('input', [
        'type' => 'text', 'name' => 'filter_student', 'class' => 'form-control', 'style' => 'max-width:220px;',
        'value' => s($filterStudent), 'placeholder' => 'Search name or email…',
    ]);
    echo html_writer::end_div();

    // Type filter.
    echo html_writer::start_div('');
    echo html_writer::tag('label', 'Type', ['style' => 'font-weight:600;display:block;font-size:13px;margin-bottom:4px;']);
    echo html_writer::start_tag('select', ['name' => 'filter_type', 'class' => 'form-control', 'style' => 'max-width:160px;']);
    $typeOptions = ['' => 'All types', 'periodic' => 'Webcam (periodic)', 'photo_id' => 'Photo ID', 'face' => 'Face check'];
    foreach ($typeOptions as $val => $lbl) {
        echo html_writer::tag('option', $lbl, ['value' => $val, 'selected' => ($filterType === $val) ? 'selected' : null]);
    }
    echo html_writer::end_tag('select');
    echo html_writer::end_div();

    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => '🔍 Filter', 'class' => 'btn btn-primary', 'style' => 'align-self:flex-end;']);
    if ($filterLgid || $filterStudent || $filterType) {
        echo ' ';
        echo html_writer::link(new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'media']),
            'Clear', ['class' => 'btn btn-outline-secondary', 'style' => 'align-self:flex-end;']);
    }
    echo html_writer::end_div();
    echo html_writer::end_tag('form');

    // ── Build WHERE clause ────────────────────────────────────────────
    $where  = '1=1';
    $params = [];

    if ($filterLgid > 0) {
        $where   .= ' AND a.learnguard = :filter_lgid';
        $params['filter_lgid'] = $filterLgid;
    }
    if ($filterType !== '') {
        $where   .= ' AND s.snapshot_type = :filter_type';
        $params['filter_type'] = $filterType;
    }
    if ($filterStudent !== '') {
        $like = $DB->sql_like_escape($filterStudent);
        $where .= ' AND (' .
            $DB->sql_like('u.firstname', ':fsn', false) . ' OR ' .
            $DB->sql_like('u.lastname',  ':lsn', false) . ' OR ' .
            $DB->sql_like('u.email',     ':esn', false) . ')';
        $params['fsn'] = '%' . $like . '%';
        $params['lsn'] = '%' . $like . '%';
        $params['esn'] = '%' . $like . '%';
    }

    $baseSql = "FROM {learnguard_snapshots} s
                JOIN {learnguard_attempts} a  ON a.id = s.attemptid
                JOIN {user}                u  ON u.id = s.userid
                JOIN {learnguard}          lg ON lg.id = a.learnguard
                JOIN {course}              c  ON c.id  = lg.course
               WHERE $where";

    $countSql   = "SELECT COUNT(*) $baseSql";
    $totalCount = $DB->count_records_sql($countSql, $params);
    $totalPages = $totalCount > 0 ? ceil($totalCount / $perPage) : 1;
    $mediaPage  = max(0, min($mediaPage, $totalPages - 1));
    $offset     = $mediaPage * $perPage;

    // Single query — joins {modules}+{course_modules} to get cmid for the "View attempt" links.
    $snapshots2 = $DB->get_records_sql(
        "SELECT s.id, s.snapshot_url, s.snapshot_type, s.facefound, s.timemodified,
                u.firstname, u.lastname, u.email, u.id AS userid,
                lg.name AS assessmentname, c.fullname AS coursename, a.id AS attemptid,
                cm.id AS cmid
           FROM {learnguard_snapshots} s
           JOIN {learnguard_attempts} a  ON a.id = s.attemptid
           JOIN {user}                u  ON u.id = s.userid
           JOIN {learnguard}          lg ON lg.id = a.learnguard
           JOIN {course}              c  ON c.id  = lg.course
           JOIN {modules}             md ON md.name = 'learnguard'
           JOIN {course_modules}      cm ON cm.instance = lg.id AND cm.module = md.id
          WHERE $where
       ORDER BY s.timemodified DESC",
        $params, $offset, $perPage
    );

    echo html_writer::tag('p',
        'Showing ' . ($totalCount === 0 ? '0' : ($offset + 1)) . '–' .
        min($offset + $perPage, $totalCount) . ' of ' . $totalCount . ' snapshots.',
        ['style' => 'font-size:13px;color:#6c757d;margin-bottom:12px;']
    );

    $typeLabels  = ['periodic' => ['Webcam', '#0d6efd'], 'photo_id' => ['Photo ID', '#6f42c1'], 'face' => ['Face', '#fd7e14']];

    // ── Thumbnail grid ────────────────────────────────────────────────
    if (empty($snapshots2)) {
        echo html_writer::div('No snapshots match the current filter.', 'alert alert-info');
    } else {
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;margin-bottom:20px;">';
        foreach ($snapshots2 as $s) {
            $imgSrc  = 'data:image/jpeg;base64,' . $s->snapshot_url;
            $tinfo   = $typeLabels[$s->snapshot_type] ?? [$s->snapshot_type, '#6c757d'];
            $student = htmlspecialchars(fullname($s), ENT_QUOTES);
            $asmt    = htmlspecialchars(format_string($s->assessmentname), ENT_QUOTES);
            $ts      = userdate($s->timemodified, '%d/%m/%y %H:%M');
            $detailUrl = (new moodle_url('/mod/learnguard/attemptdetail.php',
                ['id' => $s->cmid, 'attempt' => $s->attemptid]))->out(false);
            echo '<div style="border:1px solid #dee2e6;border-radius:8px;overflow:hidden;background:#fff;' .
                 'box-shadow:0 2px 6px rgba(0,0,0,0.07);">';
            // Thumbnail with lightbox.
            echo '<div onclick="lgMediaLightbox(this)" data-src="' . htmlspecialchars($imgSrc, ENT_QUOTES) . '" ' .
                 'data-student="' . $student . '" data-asmt="' . $asmt . '" data-ts="' . $ts . '" ' .
                 'style="cursor:zoom-in;height:130px;overflow:hidden;background:#111;">';
            echo '<img src="' . htmlspecialchars($imgSrc, ENT_QUOTES) . '" ' .
                 'style="width:100%;height:130px;object-fit:cover;" loading="lazy" alt="Snapshot"/>';
            echo '</div>';
            // Caption.
            echo '<div style="padding:8px 10px;">';
            echo '<div style="background:' . $tinfo[1] . ';color:#fff;display:inline-block;padding:2px 8px;' .
                 'border-radius:10px;font-size:10px;font-weight:700;margin-bottom:5px;">' . $tinfo[0] . '</div>';
            echo '<div style="font-size:12px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' .
                 $student . '</div>';
            echo '<div style="font-size:11px;color:#6c757d;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' .
                 format_string($s->assessmentname) . '</div>';
            echo '<div style="font-size:11px;color:#adb5bd;">' . $ts . '</div>';
            echo '<a href="' . $detailUrl . '" style="font-size:11px;color:' . $brand->primary . ';">View attempt →</a>';
            echo '</div></div>';
        }
        echo '</div>';

        // ── Pagination ────────────────────────────────────────────────
        if ($totalPages > 1) {
            echo '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px;">';
            for ($p = 0; $p < $totalPages; $p++) {
                $active = ($p === $mediaPage);
                $pageUrl = new moodle_url('/mod/learnguard/siteadmin.php', [
                    'tab' => 'media', 'mediapage' => $p,
                    'filter_lgid' => $filterLgid, 'filter_student' => $filterStudent, 'filter_type' => $filterType,
                ]);
                echo html_writer::link($pageUrl, ($p + 1), [
                    'style' => 'padding:6px 12px;border-radius:4px;text-decoration:none;font-size:13px;' .
                        ($active ? 'background:' . $brand->primary . ';color:#fff;' : 'background:#f8f9fa;border:1px solid #dee2e6;color:#333;'),
                ]);
            }
            echo '</div>';
        }
    }

    // ── Lightbox script ───────────────────────────────────────────────
    ?>
    <div id="lg-media-lightbox" onclick="this.style.display='none'"
         style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.92);z-index:999999;
                align-items:center;justify-content:center;flex-direction:column;font-family:system-ui,sans-serif;">
        <div style="position:relative;max-width:90vw;max-height:80vh;">
            <img id="lg-lb-img" src="" style="max-width:90vw;max-height:80vh;border-radius:4px;object-fit:contain;" alt=""/>
        </div>
        <div id="lg-lb-caption" style="color:#fff;margin-top:10px;text-align:center;font-size:14px;"></div>
        <div style="color:#aaa;font-size:12px;margin-top:4px;">Click anywhere to close</div>
    </div>
    <script>
    function lgMediaLightbox(el) {
        var lb = document.getElementById('lg-media-lightbox');
        document.getElementById('lg-lb-img').src = el.dataset.src;
        document.getElementById('lg-lb-caption').textContent =
            el.dataset.student + ' — ' + el.dataset.asmt + ' — ' + el.dataset.ts;
        lb.style.display = 'flex';
    }
    </script>
    <?php
}

// =====================================================================
// TAB: Question Bank (site-wide)
// =====================================================================
if ($tab === 'questions') {
    $totalquestions = $DB->count_records('learnguard_qbank');
    $totalcats      = $DB->count_records('learnguard_qbank_cats');

    echo learnguard_banner('LearnGuard Question Bank', 'All questions across all assessments on this site.', '🏦');

    echo html_writer::start_div('d-flex gap-2 mb-3');
    echo html_writer::link(new moodle_url('/mod/learnguard/questionbank.php'), '+ Browse / Manage All Questions', ['class' => 'btn btn-primary']);
    echo html_writer::link(new moodle_url('/mod/learnguard/questionimport.php'), '📥 Import Questions', ['class' => 'btn btn-outline-secondary']);
    echo html_writer::end_div();

    echo html_writer::start_div('d-flex gap-3 flex-wrap mb-4');
    foreach ([['Total Questions', $totalquestions, $brand->primary], ['Categories', $totalcats, '#20c997']] as [$label, $count, $colour]) {
        echo html_writer::start_div('', ['style' =>
            'background:' . $colour . ';color:#fff;border-radius:8px;padding:16px 24px;min-width:120px;text-align:center;']);
        echo html_writer::tag('div', $count, ['style' => 'font-size:28px;font-weight:700;']);
        echo html_writer::tag('div', $label, ['style' => 'font-size:12px;opacity:0.85;']);
        echo html_writer::end_div();
    }
    echo html_writer::end_div();

    $breakdown = $DB->get_records_sql('SELECT qtype, COUNT(*) AS cnt FROM {learnguard_qbank} GROUP BY qtype ORDER BY cnt DESC');
    if ($breakdown) {
        $colours = ['multichoice' => '#0f6cbf', 'multicheck' => '#6f42c1', 'truefalse' => '#20c997',
                    'shortanswer' => '#fd7e14', 'essay' => '#795548', 'matching' => '#17a2b8'];
        echo html_writer::tag('h5', 'Question Types', ['class' => 'mb-2']);
        echo html_writer::start_div('d-flex gap-2 flex-wrap mb-4');
        foreach ($breakdown as $row) {
            $col = $colours[$row->qtype] ?? '#6c757d';
            echo html_writer::tag('div', html_writer::tag('strong', $row->cnt) . ' ' . ucfirst($row->qtype),
                ['style' => 'background:' . $col . ';color:#fff;padding:6px 14px;border-radius:20px;font-size:13px;']);
        }
        echo html_writer::end_div();
    }

    $recent = $DB->get_records_sql(
        "SELECT q.id, q.name, q.qtype, c.name AS catname, q.timemodified
           FROM {learnguard_qbank} q LEFT JOIN {learnguard_qbank_cats} c ON c.id = q.categoryid
          ORDER BY q.timemodified DESC LIMIT 20"
    );
    if ($recent) {
        $typecolors = ['multichoice' => '#0f6cbf', 'multicheck' => '#6f42c1', 'truefalse' => '#20c997',
                       'shortanswer' => '#fd7e14', 'essay' => '#795548', 'matching' => '#17a2b8'];
        echo html_writer::tag('h5', 'Recently Modified (last 20)', ['class' => 'mb-2']);
        echo html_writer::start_tag('table', ['class' => 'generaltable w-100']);
        echo html_writer::start_tag('tr');
        foreach (['Question', 'Type', 'Category', 'Modified'] as $h) {
            echo html_writer::tag('th', $h, ['style' => 'padding:6px 10px;']);
        }
        echo html_writer::end_tag('tr');
        foreach ($recent as $q) {
            $col = $typecolors[$q->qtype] ?? '#6c757d';
            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', format_string($q->name), ['style' => 'font-weight:600;max-width:320px;']);
            echo html_writer::tag('td', html_writer::tag('span', ucfirst($q->qtype),
                ['style' => 'background:' . $col . ';color:#fff;padding:2px 7px;border-radius:10px;font-size:11px;font-weight:700;']));
            echo html_writer::tag('td', format_string($q->catname ?? 'General'));
            echo html_writer::tag('td', userdate($q->timemodified, '%d/%m/%y'));
            echo html_writer::end_tag('tr');
        }
        echo html_writer::end_tag('table');
    }
}

// =====================================================================
// TAB: Branding
// =====================================================================
if ($tab === 'branding') {
    $brandprimary = get_config('learnguard', 'brand_primary') ?: '#0f6cbf';
    $brandaccent  = get_config('learnguard', 'brand_accent')  ?: '#28a745';
    $brandname    = get_config('learnguard', 'brand_name')    ?: '';

    echo html_writer::tag('h4', '🎨 Branding & Appearance', ['class' => 'mb-1']);
    echo html_writer::tag('p',
        'These settings are applied site-wide across all LearnGuard assessments.',
        ['style' => 'color:#6c757d;margin-bottom:24px;']);

    echo html_writer::start_div('', ['style' =>
        'border:1px solid #dee2e6;border-radius:8px;overflow:hidden;margin-bottom:24px;max-width:700px;']);
    echo html_writer::start_div('', ['id' => 'lg-brand-preview-bar', 'style' =>
        'background:' . $brandprimary . ';color:#fff;padding:12px 20px;' .
        'display:flex;align-items:center;justify-content:space-between;']);
    echo html_writer::tag('span', '🛡 ' . (trim($brandname) ?: 'LearnGuard') . ' Assessment',
        ['id' => 'lg-brand-preview-name', 'style' => 'font-weight:700;font-size:16px;']);
    echo html_writer::start_div('', ['style' => 'display:flex;gap:12px;align-items:center;']);
    echo html_writer::tag('span', 'Q 1 of 20', ['style' => 'font-size:14px;']);
    echo html_writer::tag('span', '⏱ 44:58',   ['style' => 'font-size:14px;font-weight:700;']);
    echo html_writer::tag('span', '🛡 Risk: 0 (low)', ['id' => 'lg-brand-preview-badge', 'style' =>
        'background:' . $brandaccent . ';color:#fff;padding:4px 10px;border-radius:12px;font-size:12px;font-weight:600;']);
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::start_div('', ['style' => 'padding:20px;background:#f8f9fa;']);
    echo html_writer::tag('p', '📋 Your question content will appear here.', ['style' => 'color:#6c757d;']);
    echo html_writer::start_div('', ['style' => 'display:flex;gap:8px;margin-top:8px;']);
    echo html_writer::tag('span', '← Previous',
        ['style' => 'background:#6c757d;color:#fff;padding:8px 16px;border-radius:6px;font-size:13px;']);
    echo html_writer::tag('span', 'Save & Next →',
        ['id' => 'lg-brand-preview-btn', 'style' => 'background:' . $brandprimary . ';color:#fff;padding:8px 16px;border-radius:6px;font-size:13px;']);
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::start_tag('form', ['method' => 'post', 'style' => 'max-width:500px;']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',    'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'postaction', 'value' => '']);

    echo html_writer::start_div('mb-3');
    echo html_writer::tag('label', 'Plugin display name',
        ['for' => 'brand_name', 'style' => 'font-weight:600;display:block;margin-bottom:4px;']);
    echo html_writer::empty_tag('input', [
        'type' => 'text', 'id' => 'brand_name', 'name' => 'brand_name', 'value' => $brandname,
        'class' => 'form-control', 'placeholder' => 'e.g. My University Proctor',
        'oninput' => 'document.getElementById("lg-brand-preview-name").textContent = "🛡 " + (this.value || "LearnGuard") + " Assessment";',
    ]);
    echo html_writer::end_div();

    foreach ([
        ['brand_primary', 'Primary colour (banners, buttons)', $brandprimary,
            'lg-brand-preview-bar', 'lg-brand-preview-btn'],
        ['brand_accent', 'Accent colour (success badges)', $brandaccent, 'lg-brand-preview-badge', ''],
    ] as [$name, $label, $value, $previewEl, $previewEl2]) {
        $updateJs = 'document.getElementById("' . $previewEl . '").style.background=this.value;' .
            ($previewEl2 ? 'document.getElementById("' . $previewEl2 . '").style.background=this.value;' : '');
        echo html_writer::start_div('mb-3');
        echo html_writer::tag('label', $label, ['for' => $name . '_picker', 'style' => 'font-weight:600;display:block;margin-bottom:4px;']);
        echo html_writer::start_div('', ['style' => 'display:flex;gap:8px;align-items:center;']);
        echo html_writer::empty_tag('input', [
            'type' => 'color', 'id' => $name . '_picker', 'value' => $value,
            'style' => 'width:48px;height:40px;border:1px solid #ccc;border-radius:4px;cursor:pointer;padding:2px;',
            'oninput' => 'document.getElementById("' . $name . '").value=this.value;' . $updateJs,
        ]);
        echo html_writer::empty_tag('input', [
            'type' => 'text', 'id' => $name, 'name' => $name, 'value' => $value,
            'class' => 'form-control', 'style' => 'max-width:120px;font-family:monospace;',
            'pattern' => '#[0-9a-fA-F]{6}',
            'oninput' => 'if(/^#[0-9a-fA-F]{6}$/.test(this.value)){document.getElementById("' . $name . '_picker").value=this.value;' . $updateJs . '}',
        ]);
        echo html_writer::end_div();
        echo html_writer::end_div();
    }

    $presets = [
        ['🔵 Default Blue', '#0f6cbf', '#28a745'], ['🟣 Royal Purple', '#6f42c1', '#fd7e14'],
        ['🔴 University Red', '#dc3545', '#0f6cbf'], ['⚫ Midnight Dark', '#212529', '#17a2b8'],
        ['🟤 Warm Brown', '#795548', '#ff9800'], ['🟢 Forest Green', '#2e7d32', '#1565c0'],
    ];
    echo html_writer::tag('h6', 'Quick Presets', ['class' => 'mb-2 mt-3']);
    echo html_writer::start_div('d-flex gap-2 flex-wrap mb-3');
    foreach ($presets as $preset) {
        echo html_writer::tag('button', $preset[0], [
            'type' => 'button', 'class' => 'btn btn-sm btn-outline-secondary',
            'onclick' => 'applyPreset("' . $preset[1] . '","' . $preset[2] . '")',
            'style' => 'border-left:4px solid ' . $preset[1] . ';',
        ]);
    }
    echo html_writer::end_div();

    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => '💾 Save Branding', 'class' => 'btn btn-primary']);
    echo html_writer::end_tag('form');
    ?>
    <script>
    function applyPreset(primary, accent) {
        document.getElementById('brand_primary').value = primary;
        document.getElementById('brand_primary_picker').value = primary;
        document.getElementById('brand_accent').value = accent;
        document.getElementById('brand_accent_picker').value = accent;
        document.getElementById('lg-brand-preview-bar').style.background = primary;
        document.getElementById('lg-brand-preview-btn').style.background = primary;
        document.getElementById('lg-brand-preview-badge').style.background = accent;
    }
    </script>
    <?php
}

echo $OUTPUT->footer();
