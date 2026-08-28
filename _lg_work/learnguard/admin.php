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
 * Central administration / proctor management backend for mod_learnguard.
 *
 * This is the single hub where human proctors and teachers manage:
 *  - Live session monitoring
 *  - Attempt review and suspension
 *  - Question management
 *  - Proctor assignment
 *  - Assessment settings overview
 *  - Event audit trail
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id     = required_param('id', PARAM_INT); // Course module ID.
$action = optional_param('action', 'overview', PARAM_ALPHA);

$cm       = get_coursemodule_from_id('learnguard', $id, 0, false, MUST_EXIST);
$course   = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$instance = $DB->get_record('learnguard', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/learnguard:manage', $context);

$PAGE->set_url('/mod/learnguard/admin.php', ['id' => $id]);
$PAGE->set_context($context);
$PAGE->set_title('Admin — ' . format_string($instance->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->css('/mod/learnguard/styles.css');

// ---- POST action handling ----
if (data_submitted() && confirm_sesskey()) {

    // Approve a break request.
    if ($action === 'approvebreak') {
        $attemptid = required_param('attemptid', PARAM_INT);
        require_capability('mod/learnguard:liveproctor', $context);
        $attempt  = $DB->get_record('learnguard_attempts', ['id' => $attemptid, 'learnguard' => $instance->id], '*', MUST_EXIST);
        $now      = time();
        $duration = (int)($attempt->break_duration ?: (int)(get_config('learnguard', 'break_min_minutes') ?: 5));
        $deadline = $now + ($duration * 60);
        $DB->set_field('learnguard_attempts', 'break_approved',  $now,      ['id' => $attemptid, 'learnguard' => $instance->id]);
        $DB->set_field('learnguard_attempts', 'break_deadline',  $deadline, ['id' => $attemptid, 'learnguard' => $instance->id]);
        learnguard_socket_emit('proctor:break_approved', ['attemptid' => $attemptid], $instance->id, $attemptid);
        $returnurl = optional_param('returnurl', '', PARAM_LOCALURL);
        redirect($returnurl !== '' ? new moodle_url($returnurl) : new moodle_url('/mod/learnguard/proctordashboard.php', ['id' => $id]),
            'Break approved (' . $duration . ' min).', null, \core\output\notification::NOTIFY_SUCCESS);
    }

    // Deny a break request.
    if ($action === 'denybreak') {
        $attemptid = required_param('attemptid', PARAM_INT);
        require_capability('mod/learnguard:liveproctor', $context);
        $DB->set_field('learnguard_attempts', 'break_requested', 0, ['id' => $attemptid, 'learnguard' => $instance->id]);
        $DB->set_field('learnguard_attempts', 'break_approved',  0, ['id' => $attemptid, 'learnguard' => $instance->id]);
        $DB->set_field('learnguard_attempts', 'break_deadline',  0, ['id' => $attemptid, 'learnguard' => $instance->id]);
        $returnurl = optional_param('returnurl', '', PARAM_LOCALURL);
        redirect($returnurl !== '' ? new moodle_url($returnurl) : new moodle_url('/mod/learnguard/proctordashboard.php', ['id' => $id]),
            'Break request denied.', null, \core\output\notification::NOTIFY_WARNING);
    }

    // Grant extra time to a student.
    if ($action === 'granttime') {
        $attemptid = required_param('attemptid', PARAM_INT);
        $extramins = optional_param('extramins', 0, PARAM_INT);
        require_capability('mod/learnguard:manage', $context);
        $attempt = $DB->get_record('learnguard_attempts', ['id' => $attemptid, 'learnguard' => $instance->id], '*', MUST_EXIST);
        $extraSecs = max(0, $extramins) * 60;
        $DB->set_field('learnguard_attempts', 'extra_time',
            (int)($attempt->extra_time ?? 0) + $extraSecs, ['id' => $attemptid]);
        redirect(new moodle_url('/mod/learnguard/admin.php', ['id' => $id, 'tab' => 'attempts']),
            'Extra time granted: ' . $extramins . ' minute(s) added to ' . fullname($DB->get_record('user', ['id' => $attempt->userid])) . '\'s attempt.',
            null, \core\output\notification::NOTIFY_SUCCESS);
    }

    // Force-submit an attempt.
    if ($action === 'forcesubmit') {
        $attemptid = required_param('attemptid', PARAM_INT);
        require_capability('mod/learnguard:manage', $context);
        $attempt = $DB->get_record('learnguard_attempts', ['id' => $attemptid, 'learnguard' => $instance->id, 'state' => 'inprogress'], '*', MUST_EXIST);
        require_once($CFG->libdir . '/gradelib.php');
        $grade = \mod_learnguard\attempt_manager::finish($attempt, $instance);
        redirect(new moodle_url('/mod/learnguard/admin.php', ['id' => $id, 'tab' => 'attempts']),
            'Attempt force-submitted. Grade: ' . round($grade, 2) . '/' . $instance->grade,
            null, \core\output\notification::NOTIFY_SUCCESS);
    }

    // Suspend an attempt.
    if ($action === 'suspend') {
        $attemptid = required_param('attemptid', PARAM_INT);
        require_capability('mod/learnguard:suspendattempt', $context);
        $attempt = $DB->get_record('learnguard_attempts', ['id' => $attemptid, 'learnguard' => $instance->id], '*', MUST_EXIST);
        $DB->set_field('learnguard_attempts', 'state', 'suspended', ['id' => $attemptid]);
        $DB->set_field('learnguard_attempts', 'risk_score_at_suspend', $attempt->risk_score, ['id' => $attemptid]);
        redirect(new moodle_url('/mod/learnguard/admin.php', ['id' => $id]),
            'Attempt #' . $attempt->id . ' suspended.', null, \core\output\notification::NOTIFY_SUCCESS);
    }

    // Reinstate an attempt.
    if ($action === 'reinstate') {
        $attemptid = required_param('attemptid', PARAM_INT);
        require_capability('mod/learnguard:suspendattempt', $context);
        $DB->set_field('learnguard_attempts', 'state', 'inprogress', ['id' => $attemptid]);
        redirect(new moodle_url('/mod/learnguard/admin.php', ['id' => $id, 'tab' => 'attempts']),
            'Attempt reinstated. Student can continue.', null, \core\output\notification::NOTIFY_SUCCESS);
    }

    // Delete an attempt — wipes ALL attempts for that student so they can retry.
    // Deleting just one attempt is not enough when maxattempts > 0: the remaining
    // finished/suspended attempts still count against the limit. The admin intent
    // when clicking "Delete" is always "let this student start fresh."
    if ($action === 'deleteattempt') {
        $attemptid = required_param('attemptid', PARAM_INT);
        require_capability('mod/learnguard:manage', $context);

        // Identify the student from the specified attempt (security: verify it belongs here).
        $triggerattempt = $DB->get_record('learnguard_attempts',
            ['id' => $attemptid, 'learnguard' => $instance->id], '*', MUST_EXIST);
        $studentid = $triggerattempt->userid;

        // Find every attempt this student has for this assessment.
        $allattempts = $DB->get_records('learnguard_attempts', [
            'learnguard' => $instance->id,
            'userid'     => $studentid,
        ]);

        foreach ($allattempts as $a) {
            $DB->delete_records('learnguard_responses',         ['attemptid' => $a->id]);
            $DB->delete_records('learnguard_attempt_questions', ['attemptid' => $a->id]);
            $DB->delete_records('learnguard_events',            ['attemptid' => $a->id]);
            $DB->delete_records('learnguard_snapshots',         ['attemptid' => $a->id]);
            $DB->delete_records('learnguard_attempts',          ['id'        => $a->id]);
        }

        // Clear the gradebook so any previous grade disappears.
        require_once($CFG->libdir . '/gradelib.php');
        grade_update('mod/learnguard', $instance->course, 'mod', 'learnguard',
            $instance->id, 0, [(object)['userid' => $studentid, 'rawgrade' => null]]);

        redirect(new moodle_url('/mod/learnguard/admin.php', ['id' => $id, 'tab' => 'attempts']),
            'All attempts for this student have been deleted. They can now attempt again.',
            null, \core\output\notification::NOTIFY_SUCCESS);
    }

    // Assign a proctor.
    if ($action === 'addproctor') {
        $usersearch = required_param('usersearch', PARAM_RAW);
        $usersearch = trim($usersearch);
        $proctor = null;
        if (is_numeric($usersearch)) {
            $proctor = $DB->get_record('user', ['id' => (int)$usersearch, 'deleted' => 0]);
        }
        if (!$proctor) {
            $proctor = $DB->get_record('user', ['email' => $usersearch, 'deleted' => 0]);
        }
        if (!$proctor) {
            $proctor = $DB->get_record('user', ['username' => $usersearch, 'deleted' => 0]);
        }
        if ($proctor) {
            if (!$DB->record_exists('learnguard_proctors', ['learnguard' => $instance->id, 'userid' => $proctor->id])) {
                $DB->insert_record('learnguard_proctors', (object)[
                    'learnguard'   => $instance->id,
                    'userid'       => $proctor->id,
                    'role'         => 'proctor',
                    'timemodified' => time(),
                ]);
                if (get_config('learnguard', 'notify_proctors_enabled')) {
                    learnguard_send_email($proctor,
                        '✅ LearnGuard: You have been assigned as a proctor',
                        "You have been assigned as a proctor for the assessment \"" . format_string($instance->name) . "\"\n" .
                        "in the course \"" . format_string($course->fullname) . "\".\n\n" .
                        "You can review live sessions and attempts from the LearnGuard Proctor Dashboard.\n\n" .
                        "LearnGuard — Secure Assessment Platform"
                    );
                }
                redirect(new moodle_url('/mod/learnguard/admin.php', ['id' => $id, 'tab' => 'proctors']),
                    'Proctor ' . fullname($proctor) . ' assigned.', null, \core\output\notification::NOTIFY_SUCCESS);
            } else {
                redirect(new moodle_url('/mod/learnguard/admin.php', ['id' => $id, 'tab' => 'proctors']),
                    'User is already a proctor.', null, \core\output\notification::NOTIFY_WARNING);
            }
        } else {
            redirect(new moodle_url('/mod/learnguard/admin.php', ['id' => $id, 'tab' => 'proctors']),
                'User not found: ' . s($usersearch), null, \core\output\notification::NOTIFY_ERROR);
        }
    }

    // Emergency pause — also notify via Socket.IO for instant delivery.
    if ($action === 'pause') {
        require_capability('mod/learnguard:manage', $context);
        set_config('paused_' . $instance->id, '1', 'learnguard');
        learnguard_socket_emit('assessment:paused', ['learnguardid' => $instance->id], $instance->id);
        redirect(new moodle_url('/mod/learnguard/admin.php', ['id' => $id]),
            '⏸ Assessment paused. All active students notified instantly.',
            null, \core\output\notification::NOTIFY_WARNING);
    }
    if ($action === 'unpause') {
        require_capability('mod/learnguard:manage', $context);
        unset_config('paused_' . $instance->id, 'learnguard');
        learnguard_socket_emit('assessment:resumed', ['learnguardid' => $instance->id], $instance->id);
        redirect(new moodle_url('/mod/learnguard/admin.php', ['id' => $id]),
            '▶ Assessment resumed. Students notified.',
            null, \core\output\notification::NOTIFY_SUCCESS);
    }

    // Release results to learners.
    if ($action === 'releaseresults') {
        require_capability('mod/learnguard:manage', $context);

        // Mark results as released.
        $DB->set_field('learnguard', 'results_released', time(), ['id' => $instance->id]);
        $instance->results_released = time();

        $notifytype = optional_param('notify_type', 'both', PARAM_ALPHA);
        $notified = 0;

        if ($notifytype !== 'none') {
            // Get all finished attempts for this assessment.
            $attempts = $DB->get_records_sql(
                "SELECT a.*, u.id AS uid, u.firstname, u.lastname, u.email, u.username,
                        u.mailformat, u.auth, u.confirmed, u.deleted, u.suspended
                   FROM {learnguard_attempts} a
                   JOIN {user} u ON u.id = a.userid
                  WHERE a.learnguard = :lgid AND a.state = 'finished'
               GROUP BY a.userid, a.id, u.id, u.firstname, u.lastname, u.email,
                        u.username, u.mailformat, u.auth, u.confirmed, u.deleted, u.suspended",
                ['lgid' => $instance->id]
            );

            require_once($CFG->libdir . '/moodlelib.php');

            $from      = core_user::get_support_user();
            $viewurl   = new moodle_url('/mod/learnguard/view.php', ['id' => $cm->id]);
            $maxgrade  = (float)$instance->grade;

            foreach ($attempts as $a) {
                $student = (object)[
                    'id'         => $a->uid,
                    'firstname'  => $a->firstname,
                    'lastname'   => $a->lastname,
                    'email'      => $a->email,
                    'username'   => $a->username,
                    'mailformat' => $a->mailformat,
                    'auth'       => $a->auth,
                    'confirmed'  => $a->confirmed,
                    'deleted'    => $a->deleted,
                    'suspended'  => $a->suspended,
                ];

                $pct   = $maxgrade > 0 ? round(($a->sumgrades / $maxgrade) * 100, 1) : 0;
                $grade = round($a->sumgrades ?? 0, 1);

                $subject = get_string('results_released_subject', 'learnguard');
                $body    = get_string('results_released_body', 'learnguard', (object)[
                    'firstname'      => $a->firstname,
                    'assessmentname' => format_string($instance->name),
                    'grade'          => $grade . ' (' . $pct . '%)',
                    'maxgrade'       => $maxgrade,
                    'url'            => $viewurl->out(false),
                ]);

                // Email.
                if (in_array($notifytype, ['email', 'both'])) {
                    email_to_user($student, $from, $subject, $body);
                }

                // In-app Moodle message.
                if (in_array($notifytype, ['inapp', 'both'])) {
                    $message                      = new \core\message\message();
                    $message->component           = 'moodle';
                    $message->name                = 'instantmessage';
                    $message->userfrom            = $from;
                    $message->userto              = $student;
                    $message->subject             = $subject;
                    $message->fullmessage         = $body;
                    $message->fullmessageformat   = FORMAT_PLAIN;
                    $message->fullmessagehtml     = nl2br(htmlspecialchars($body));
                    $message->smallmessage        = get_string('results_released_subject', 'learnguard');
                    $message->notification        = 1;
                    $message->contexturl          = $viewurl->out(false);
                    $message->contexturlname      = format_string($instance->name);
                    message_send($message);
                }

                $notified++;
            }
        }

        redirect(
            new moodle_url('/mod/learnguard/admin.php', ['id' => $id]),
            get_string('results_released_ok', 'learnguard', $notified),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    // Remove a proctor.
    if ($action === 'removeproctor') {
        $proctorid = required_param('proctorid', PARAM_INT);
        $DB->delete_records('learnguard_proctors', ['id' => $proctorid, 'learnguard' => $instance->id]);
        redirect(new moodle_url('/mod/learnguard/admin.php', ['id' => $id, 'tab' => 'proctors']),
            'Proctor removed.', null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

// Active tab.
$tab = optional_param('tab', 'overview', PARAM_ALPHA);

// "Settings" is not a tab of its own — it goes straight to Moodle's native
// activity settings form (which already includes this activity's "LearnGuard
// Settings" section), mirroring LearnShield (whose quizaccess_learnshield
// config lives inline in the quiz settings form).
if ($tab === 'settings') {
    redirect(new moodle_url('/course/modedit.php', ['update' => $cm->id, 'return' => 1]));
}

echo $OUTPUT->header();
$brand = learnguard_brand();
// Activity subsection tab strip.
echo learnguard_activity_tabs($id, in_array($tab, ['settings','brand']) ? $tab : 'report');
echo learnguard_nav_bar($id, 'Admin Backend', true,
    (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false));
echo learnguard_banner(format_string($instance->name), 'Proctor & Administration Backend');

// ---- Tab navigation ----
$tabs = [
    'overview'  => '📊 Overview',
    'attempts'  => '📋 Attempts',
    'live'      => '🔴 Live Monitor',
    'proctors'  => '👤 Proctors',
    'graders'   => '✍️ Graders',
    'questions' => '❓ Questions',
    'events'    => '⚠️ Events',
];
echo html_writer::start_div('d-flex gap-2 flex-wrap mb-4',
    ['style' => 'border-bottom:2px solid #dee2e6;padding-bottom:0;']);
foreach ($tabs as $key => $label) {
    $isactive = ($tab === $key);
    echo html_writer::link(
        new moodle_url('/mod/learnguard/admin.php', ['id' => $id, 'tab' => $key]),
        $label,
        [
            'style' => 'padding:8px 16px;text-decoration:none;font-size:14px;font-weight:600;' .
                'border-radius:4px 4px 0 0;' .
                ($isactive
                    ? 'background:' . $brand->primary . ';color:#fff;'
                    : 'color:#495057;background:#f8f9fa;border:1px solid #dee2e6;'),
        ]
    );
}
echo html_writer::end_div();

// ---- Overview tab ----
if ($tab === 'overview') {
    $totalattempts = $DB->count_records('learnguard_attempts', ['learnguard' => $instance->id]);
    $finished      = $DB->count_records('learnguard_attempts', ['learnguard' => $instance->id, 'state' => 'finished']);
    $inprogress    = $DB->count_records('learnguard_attempts', ['learnguard' => $instance->id, 'state' => 'inprogress']);
    $suspended     = $DB->count_records('learnguard_attempts', ['learnguard' => $instance->id, 'state' => 'suspended']);
    $highrisk      = $DB->count_records('learnguard_attempts', ['learnguard' => $instance->id, 'risk_level' => 'high']);
    $totalquestions = $DB->count_records('learnguard_slots', ['learnguard' => $instance->id]);

    // Stats cards.
    echo html_writer::start_div('d-flex gap-3 flex-wrap mb-4');
    foreach ([
        ['Total Attempts',   $totalattempts, $brand->primary],
        ['Completed',        $finished,      '#28a745'],
        ['In Progress',      $inprogress,    '#fd7e14'],
        ['Suspended',        $suspended,     '#dc3545'],
        ['High Risk',        $highrisk,      '#6f42c1'],
        ['Questions',        $totalquestions,'#20c997'],
    ] as [$label, $count, $colour]) {
        echo html_writer::start_div('', ['style' =>
            'background:' . $colour . ';color:#fff;border-radius:8px;' .
            'padding:16px 24px;min-width:110px;text-align:center;']);
        echo html_writer::tag('div', $count, ['style' => 'font-size:28px;font-weight:700;']);
        echo html_writer::tag('div', $label, ['style' => 'font-size:12px;opacity:0.85;']);
        echo html_writer::end_div();
    }
    echo html_writer::end_div();

    // Quick actions.
    echo html_writer::tag('h4', 'Quick Actions', ['class' => 'mb-3']);
    echo html_writer::start_div('d-flex gap-2 flex-wrap mb-4');
    $quicklinks = [
        [new moodle_url('/mod/learnguard/proctordashboard.php', ['id' => $id]),
            '🔴 Live Dashboard', 'btn-danger'],
        [new moodle_url('/mod/learnguard/proctorview.php'),
            '👤 Proctor View', 'btn-outline-secondary'],
        [new moodle_url('/mod/learnguard/report.php', ['id' => $id]),
            '📊 Full Report', 'btn-primary'],
        [new moodle_url('/mod/learnguard/editquestions.php', ['id' => $id]),
            '📝 Manage Questions', 'btn-secondary'],
        [new moodle_url('/course/modedit.php', ['update' => $cm->id]),
            '⚙️ Edit Settings', 'btn-outline-secondary'],
        [new moodle_url('/mod/learnguard/export_scores.php',
                ['id' => $id, 'format' => 'csv', 'sesskey' => sesskey()]),
            '⬇ Export CSV', 'btn-outline-secondary'],
        [new moodle_url('/mod/learnguard/export_scores.php',
                ['id' => $id, 'format' => 'tsv', 'sesskey' => sesskey()]),
            '📊 Export Excel', 'btn-outline-success'],
        [new moodle_url('/mod/learnguard/export_scores.php',
                ['id' => $id, 'format' => 'json', 'sesskey' => sesskey()]),
            '{ } JSON', 'btn-outline-secondary'],
    ];
    foreach ($quicklinks as [$url, $label, $cls]) {
        echo html_writer::link($url, $label, ['class' => 'btn btn-sm ' . $cls]);
    }
    echo html_writer::end_div();

    // ---- Emergency Pause ----
    $isPaused = (bool)get_config('learnguard', 'paused_' . $instance->id);
    echo html_writer::tag('h4', '🚨 Emergency Controls', ['class' => 'mt-4 mb-2']);
    echo html_writer::start_div('d-flex gap-2 align-items-center mb-3');
    if ($isPaused) {
        echo html_writer::div(
            '⏸ Assessment is currently <strong>PAUSED</strong>. All active students see a pause screen.',
            'alert alert-warning d-inline-block mb-0 me-2'
        );
        echo html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:inline;']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',      'value' => $id]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'unpause']);
        echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => '▶ Resume Assessment', 'class' => 'btn btn-success']);
        echo html_writer::end_tag('form');
    } else {
        echo html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:inline;']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',      'value' => $id]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'pause']);
        echo html_writer::empty_tag('input', [
            'type' => 'submit', 'value' => '⏸ Emergency Pause ALL Students',
            'class' => 'btn btn-danger',
            'onclick' => 'return confirm("Pause this assessment for ALL currently active students?");',
        ]);
        echo html_writer::end_tag('form');
    }
    echo html_writer::end_div();

    // ---- Release Results panel (only when results are held) ----
    if (isset($instance->show_results) && $instance->show_results == 0) {
        echo html_writer::tag('h4', '📬 Release Results', ['class' => 'mt-4 mb-2']);

        if ($instance->results_released > 0) {
            echo html_writer::div(
                '✅ Results were released on ' . userdate($instance->results_released) . '.',
                'alert alert-success'
            );
        } else {
            echo html_writer::div(
                '⏳ Results are currently <strong>held</strong>. Learners see only a confirmation screen ' .
                'after submitting. Use the form below to release results and optionally notify learners.',
                'alert alert-warning'
            );

            $finishedattempts = $DB->count_records('learnguard_attempts',
                ['learnguard' => $instance->id, 'state' => 'finished']);

            echo html_writer::start_tag('form', ['method' => 'post', 'style' => 'max-width:520px;margin-bottom:24px;']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',       'value' => $id]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',  'value' => sesskey()]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',   'value' => 'releaseresults']);

            echo html_writer::start_div('mb-3');
            echo html_writer::tag('label', 'Notification method', [
                'for' => 'notify_type', 'style' => 'font-weight:600;font-size:14px;display:block;margin-bottom:4px;',
            ]);
            echo html_writer::select(
                [
                    'both'  => get_string('notify_both',  'learnguard'),
                    'email' => get_string('notify_email',  'learnguard'),
                    'inapp' => get_string('notify_inapp',  'learnguard'),
                    'none'  => get_string('notify_none',   'learnguard'),
                ],
                'notify_type', 'both', false, ['id' => 'notify_type', 'class' => 'form-control']
            );
            echo html_writer::end_div();

            echo html_writer::tag('p',
                $finishedattempts . ' completed attempt(s) will have results released.',
                ['style' => 'color:#6c757d;font-size:14px;']);

            echo html_writer::empty_tag('input', [
                'type' => 'submit', 'value' => '📬 Release Results Now',
                'class' => 'btn btn-success',
                'onclick' => 'return confirm(' . json_encode(get_string('release_results_confirm', 'learnguard')) . ');',
            ]);
            echo html_writer::end_tag('form');
        }
    }

    // Assessment settings summary.
    echo html_writer::tag('h4', '⚙️ Current Settings', ['class' => 'mb-2']);
    echo html_writer::start_tag('table', ['class' => 'generaltable', 'style' => 'max-width:600px;']);
    $settingsrows = [
        ['Time Limit',         $instance->timelimit ? gmdate('H:i:s', $instance->timelimit) : 'No limit'],
        ['Max Attempts',       $instance->attempts ?: 'Unlimited'],
        ['Max Grade',          $instance->grade],
        ['Proctoring',         $instance->proctoring ? '✅ Enabled' : '❌ Disabled'],
        ['Fullscreen',         $instance->proctor_fullscreen ? '✅' : '❌'],
        ['Webcam',             $instance->proctor_webcam ? '✅' : '❌'],
        ['Tab Block',          $instance->proctor_tabdetect ? '✅' : '❌'],
        ['Key Block',          $instance->proctor_keyblock ? '✅' : '❌'],
        ['Mobile Blocked',     $instance->block_mobile ? '✅' : '❌'],
        ['Auto-suspend Score', $instance->autosuspend_score ?: 'Disabled'],
        ['Face Method',        $instance->proctor_fcmethod],
    ];
    foreach ($settingsrows as [$label, $value]) {
        echo html_writer::start_tag('tr');
        echo html_writer::tag('td', html_writer::tag('strong', $label), ['style' => 'padding:6px 12px;color:#6c757d;width:180px;']);
        echo html_writer::tag('td', $value, ['style' => 'padding:6px 12px;']);
        echo html_writer::end_tag('tr');
    }
    echo html_writer::end_tag('table');
}

// ---- Attempts tab ----
if ($tab === 'attempts') {
    $attempts = $DB->get_records_sql(
        "SELECT a.*, u.firstname, u.lastname, u.email
           FROM {learnguard_attempts} a
           JOIN {user} u ON u.id = a.userid
          WHERE a.learnguard = :lgid
       ORDER BY a.timestart DESC
          LIMIT 100",
        ['lgid' => $instance->id]
    );

    $riskcolours = ['low' => '#28a745', 'medium' => '#fd7e14', 'high' => '#dc3545'];

    echo html_writer::tag('h4', 'All Attempts (' . count($attempts) . ')', ['class' => 'mb-3']);

    if (empty($attempts)) {
        echo html_writer::div('No attempts yet.', 'alert alert-info');
    } else {
        echo html_writer::start_tag('table', ['class' => 'generaltable lg-attempts-table w-100']);
        echo html_writer::start_tag('thead');
        echo html_writer::start_tag('tr');
        foreach (['Student', '#', 'State', 'Grade', 'Risk', 'Started', 'Actions'] as $h) {
            echo html_writer::tag('th', $h, ['style' => 'padding:8px 10px;']);
        }
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');

        foreach ($attempts as $a) {
            $rc = $riskcolours[$a->risk_level] ?? '#6c757d';
            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', fullname($a));
            echo html_writer::tag('td', '#' . $a->attempt);
            echo html_writer::tag('td', ucfirst($a->state));
            echo html_writer::tag('td', isset($a->sumgrades) ? round($a->sumgrades, 1) . ' / ' . $instance->grade : '—');
            echo html_writer::tag('td', html_writer::tag('span', $a->risk_score, [
                'class' => 'lg-badge-risk',
                'style' => 'background:' . $rc . ';',
            ]));
            echo html_writer::tag('td', userdate($a->timestart, '%d/%m/%y %H:%M'));

            echo html_writer::start_tag('td');
            echo html_writer::link(
                new moodle_url('/mod/learnguard/attemptdetail.php', ['id' => $id, 'attempt' => $a->id]),
                '🔍',
                ['class' => 'btn btn-sm btn-outline-primary', 'title' => 'Review']
            );

            if ($a->state === 'inprogress' && has_capability('mod/learnguard:suspendattempt', $context)) {
                echo ' ';
                echo html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:inline;']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',        'value' => $id]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',   'value' => sesskey()]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',    'value' => 'suspend']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'attemptid', 'value' => $a->id]);
                echo html_writer::empty_tag('input', [
                    'type' => 'submit', 'value' => '⛔',
                    'class' => 'btn btn-sm btn-outline-danger',
                    'title' => 'Suspend',
                    'onclick' => 'return confirm("Suspend this attempt?");',
                ]);
                echo html_writer::end_tag('form');
            }
            if ($a->state === 'suspended' && has_capability('mod/learnguard:suspendattempt', $context)) {
                // Prominent amber "Unsuspend" banner + button so admin can't miss it.
                echo '<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;' .
                     'padding:4px 10px;margin-top:4px;display:inline-flex;align-items:center;gap:8px;">' .
                     '<span style="font-size:12px;color:#856404;font-weight:600;">⛔ Suspended</span>';
                echo html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:inline;']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',        'value' => $id]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',   'value' => sesskey()]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',    'value' => 'reinstate']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'attemptid', 'value' => $a->id]);
                echo html_writer::empty_tag('input', [
                    'type'    => 'submit',
                    'value'   => '▶ Unsuspend',
                    'class'   => 'btn btn-sm btn-warning',
                    'title'   => 'Remove suspension and allow student to continue',
                    'onclick' => 'return confirm("Unsuspend this attempt and allow the student to continue?");',
                ]);
                echo html_writer::end_tag('form');
                echo '</div>';
            }
            // Extended time grant — for inprogress attempts with a time limit.
            if ($a->state === 'inprogress' && $instance->timelimit > 0
                && has_capability('mod/learnguard:manage', $context)) {
                echo ' ';
                $extraTimeMins = isset($a->extra_time) ? round($a->extra_time / 60) : 0;
                echo '<form method="post" style="display:inline;margin-top:4px;">';
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',        'value' => $id]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',   'value' => sesskey()]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',    'value' => 'granttime']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'attemptid', 'value' => $a->id]);
                echo '<div style="display:inline-flex;align-items:center;gap:4px;">';
                echo html_writer::empty_tag('input', [
                    'type' => 'number', 'name' => 'extramins',
                    'value' => $extraTimeMins, 'min' => '0', 'max' => '120', 'step' => '5',
                    'style' => 'width:60px;padding:3px 6px;border:1px solid #ccc;border-radius:4px;font-size:12px;',
                    'title' => 'Extra minutes to grant',
                ]);
                echo '<span style="font-size:11px;color:#6c757d;">min</span>';
                echo html_writer::empty_tag('input', [
                    'type' => 'submit', 'value' => '⏱ +Time',
                    'class' => 'btn btn-sm btn-outline-info',
                    'title' => 'Grant extra time to this student',
                ]);
                echo '</div></form>';
            }

            // Force submit — for inprogress attempts.
            if ($a->state === 'inprogress' && has_capability('mod/learnguard:manage', $context)) {
                echo ' ';
                echo html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:inline;']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',        'value' => $id]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',   'value' => sesskey()]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',    'value' => 'forcesubmit']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'attemptid', 'value' => $a->id]);
                echo html_writer::empty_tag('input', [
                    'type'    => 'submit',
                    'value'   => '📤 Force Submit',
                    'class'   => 'btn btn-sm btn-outline-danger',
                    'title'   => 'Force-submit this attempt and calculate grade now',
                    'onclick' => 'return confirm("Force-submit this attempt? The student\'s current answers will be graded immediately.");',
                ]);
                echo html_writer::end_tag('form');
            }

            // Delete attempt button — available to managers for all attempts.
            if (has_capability('mod/learnguard:manage', $context)) {
                echo ' ';
                echo html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:inline;']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',        'value' => $id]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',   'value' => sesskey()]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',    'value' => 'deleteattempt']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'attemptid', 'value' => $a->id]);
                echo html_writer::empty_tag('input', [
                    'type' => 'submit', 'value' => '🗑 Delete',
                    'class' => 'btn btn-sm btn-outline-danger',
                    'onclick' => 'return confirm("Delete this attempt permanently? The student will be able to re-attempt. This cannot be undone.");',
                ]);
                echo html_writer::end_tag('form');
            }
            echo html_writer::end_tag('td');
            echo html_writer::end_tag('tr');
        }

        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');
    }
}

// ---- Live Monitor tab ----
if ($tab === 'live') {
    echo html_writer::tag('p',
        'Live proctor dashboard shows students currently taking the assessment (active in the last 2 minutes).');
    echo html_writer::link(
        new moodle_url('/mod/learnguard/proctordashboard.php', ['id' => $id]),
        '🔴 Open Full Live Dashboard →',
        ['class' => 'btn btn-danger mb-3']
    );

    // Inline live preview.
    echo html_writer::start_div('', ['id' => 'lg-admin-live',
        'style' => 'display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px;']);
    echo html_writer::end_div();

    echo html_writer::tag('p', 'Auto-refreshes every 15 seconds.',
        ['style' => 'color:#6c757d;font-size:13px;margin-top:8px;']);
    ?>
    <script>
    (function() {
        var cmid = <?php echo (int)$cm->id; ?>;
        var wwwroot = <?php echo json_encode($CFG->wwwroot); ?>;
        var sesskey = <?php echo json_encode(sesskey()); ?>;

        function fetchLive() {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', wwwroot + '/lib/ajax/service.php?sesskey=' + sesskey, true);
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.onload = function() {
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp && resp[0] && resp[0].data) {
                        renderLive(resp[0].data.sessions || []);
                    }
                } catch (e) {}
            };
            xhr.send(JSON.stringify([{
                index: 0,
                methodname: 'mod_learnguard_get_live_sessions',
                args: {cmid: cmid},
            }]));
        }

        function renderLive(sessions) {
            var grid = document.getElementById('lg-admin-live');
            if (!grid) { return; }
            if (!sessions.length) {
                grid.innerHTML = '<p style="color:#6c757d;grid-column:1/-1;">No active sessions right now.</p>';
                return;
            }
            var rc = {low:'#28a745',medium:'#fd7e14',high:'#dc3545'};
            grid.innerHTML = '';
            sessions.forEach(function(s) {
                var card = document.createElement('div');
                card.style.cssText = 'border:2px solid '+(rc[s.risk_level]||'#dee2e6')+';border-radius:8px;padding:12px;background:#fff;';
                card.innerHTML = '<strong>'+esc(s.fullname)+'</strong><br>' +
                    '<span style="font-size:12px;color:#6c757d;">Q '+s.currentpage+' | Risk: '+s.risk_score+'</span><br>' +
                    '<a href="'+wwwroot+'/mod/learnguard/attemptdetail.php?id='+cmid+'&attempt='+s.attemptid+'" style="font-size:12px;">View →</a>';
                grid.appendChild(card);
            });
        }

        function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
        fetchLive();
        setInterval(fetchLive, 15000);
    })();
    </script>
    <?php
}

// ---- Proctors tab ----
if ($tab === 'proctors') {
    $proctors = $DB->get_records_sql(
        "SELECT p.id AS pid, p.role, p.timemodified, u.id AS userid,
                u.firstname, u.lastname, u.email
           FROM {learnguard_proctors} p
           JOIN {user} u ON u.id = p.userid
          WHERE p.learnguard = :lgid
       ORDER BY u.lastname, u.firstname",
        ['lgid' => $instance->id]
    );

    echo html_writer::tag('h4', 'Assigned Proctors (' . count($proctors) . ')', ['class' => 'mb-3']);

    if (empty($proctors)) {
        echo html_writer::div('No proctors assigned yet.', 'alert alert-info');
    } else {
        echo html_writer::start_tag('table', ['class' => 'generaltable', 'style' => 'max-width:700px;']);
        echo html_writer::start_tag('thead');
        echo html_writer::start_tag('tr');
        foreach (['Name', 'Email', 'Role', 'Assigned', 'Remove'] as $h) {
            echo html_writer::tag('th', $h, ['style' => 'padding:8px 12px;']);
        }
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');
        foreach ($proctors as $p) {
            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', html_writer::link(
                new moodle_url('/user/view.php', ['id' => $p->userid, 'course' => $course->id]),
                fullname($p)
            ));
            echo html_writer::tag('td', $p->email);
            echo html_writer::tag('td', ucfirst($p->role));
            echo html_writer::tag('td', userdate($p->timemodified, '%d/%m/%Y'));
            echo html_writer::start_tag('td');
            echo html_writer::start_tag('form', ['method' => 'post']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',         'value' => $id]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',    'value' => sesskey()]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',     'value' => 'removeproctor']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'proctorid',  'value' => $p->pid]);
            echo html_writer::empty_tag('input', [
                'type' => 'submit', 'value' => '✕ Remove',
                'class' => 'btn btn-sm btn-outline-danger',
                'onclick' => 'return confirm("Remove this proctor?");',
            ]);
            echo html_writer::end_tag('form');
            echo html_writer::end_tag('td');
            echo html_writer::end_tag('tr');
        }
        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');
    }

    // Add proctor form with live user search.
    echo html_writer::tag('h5', 'Assign Proctors', ['class' => 'mt-4 mb-2']);
    echo html_writer::tag('p',
        'Search by name, username, or email. Select one or more users to assign as proctors.',
        ['style' => 'color:#6c757d;font-size:14px;']);

    // Live search results div.
    echo html_writer::start_div('', ['style' => 'max-width:520px;margin-bottom:16px;']);
    echo html_writer::empty_tag('input', [
        'type' => 'text', 'id' => 'lg-proctor-search',
        'class' => 'form-control', 'placeholder' => '🔍 Search by name, username or email…',
        'autocomplete' => 'off',
        'style' => 'margin-bottom:6px;',
    ]);
    echo html_writer::tag('div', '', [
        'id' => 'lg-proctor-results',
        'style' => 'max-height:240px;overflow-y:auto;border:1px solid #dee2e6;border-radius:6px;display:none;background:#fff;',
    ]);
    echo html_writer::end_div();

    // Hidden form for actual submission.
    echo html_writer::start_tag('form', ['method' => 'post', 'id' => 'lg-addproctor-form', 'style' => 'max-width:520px;']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',      'value' => $id]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'addproctor']);
    echo html_writer::start_div('flex-grow-1');
    echo html_writer::tag('label', 'Selected User (Username / Email / ID)', ['for' => 'usersearch', 'style' => 'font-weight:600;font-size:14px;']);
    echo html_writer::empty_tag('input', [
        'type' => 'text', 'name' => 'usersearch', 'id' => 'usersearch',
        'class' => 'form-control', 'placeholder' => 'Click a search result above to fill this in',
        'required' => 'required',
        'style' => 'margin-top:4px;',
    ]);
    echo html_writer::end_div();
    echo html_writer::empty_tag('input', [
        'type' => 'submit', 'value' => '+ Assign Proctor',
        'class' => 'btn btn-primary',
    ]);
    echo html_writer::end_tag('form');
    ?>
    <script>
    (function(){
        var timer = null;
        var search = document.getElementById('lg-proctor-search');
        var results = document.getElementById('lg-proctor-results');
        var target = document.getElementById('usersearch');
        if (!search || !results || !target) { return; }

        search.addEventListener('input', function() {
            clearTimeout(timer);
            var q = this.value.trim();
            if (q.length < 2) { results.style.display = 'none'; return; }
            timer = setTimeout(function() { fetchUsers(q); }, 300);
        });

        function fetchUsers(q) {
            var url = <?php echo json_encode($CFG->wwwroot); ?> + '/user/selector/search.php?sesskey=' + <?php echo json_encode(sesskey()); ?> +
                '&search=' + encodeURIComponent(q) + '&context=' + <?php echo json_encode($context->id); ?>;
            var xhr = new XMLHttpRequest();
            xhr.open('GET', url, true);
            xhr.onload = function() {
                try {
                    var data = JSON.parse(xhr.responseText);
                    renderResults(data.users || []);
                } catch(e) {
                    // Fallback: show message
                    results.innerHTML = '<div style="padding:10px;color:#6c757d;font-size:13px;">Type a full name, username or email and press Enter to fill the field directly.</div>';
                    results.style.display = 'block';
                }
            };
            xhr.onerror = function() {
                results.style.display = 'none';
            };
            xhr.send();
        }

        function renderResults(users) {
            if (!users.length) {
                results.innerHTML = '<div style="padding:10px;color:#6c757d;font-size:13px;">No users found.</div>';
                results.style.display = 'block';
                return;
            }
            results.innerHTML = '';
            results.style.display = 'block';
            users.slice(0, 15).forEach(function(u) {
                var row = document.createElement('div');
                row.style.cssText = 'padding:8px 12px;cursor:pointer;border-bottom:1px solid #f0f0f0;display:flex;align-items:center;gap:8px;';
                row.innerHTML = '<span style="font-weight:600;">' + esc(u.fullname) + '</span>' +
                    '<span style="font-size:12px;color:#6c757d;">' + esc(u.username || u.email || '') + '</span>';
                row.addEventListener('mouseenter', function(){ this.style.background='#f0f7ff'; });
                row.addEventListener('mouseleave', function(){ this.style.background=''; });
                row.addEventListener('click', function(){
                    target.value = u.username || u.email || String(u.id);
                    search.value = u.fullname + ' (' + (u.username || u.email || '') + ')';
                    results.style.display = 'none';
                });
                results.appendChild(row);
            });
        }

        function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

        // Close on outside click
        document.addEventListener('click', function(e){
            if (!results.contains(e.target) && e.target !== search) {
                results.style.display = 'none';
            }
        });

        // Allow direct typing into the field too.
        search.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                if (this.value.trim()) {
                    target.value = this.value.trim();
                    results.style.display = 'none';
                }
            }
        });
    })();
    </script>
    <?php
}

// ---- Graders tab — assign essay graders, anonymous option, second-layer review ----
if ($tab === 'graders') {

    // R4: CSV export of grader performance.
    if (optional_param('graderexport', 0, PARAM_INT) && confirm_sesskey()) {
        $expGraders = $DB->get_records_sql(
            "SELECT g.id AS gid, g.userid, g.anonymous, g.gradelayer,
                    COALESCE(g.deadline,0) AS deadline,
                    u.firstname, u.lastname, u.email
               FROM {learnguard_graders} g
               JOIN {user} u ON u.id = g.userid
              WHERE g.learnguard = ?
           ORDER BY g.gradelayer, u.lastname", [$instance->id]);
        $totalFin = $DB->count_records('learnguard_attempts',
            ['learnguard' => $instance->id, 'state' => 'finished']);
        $gradedMap = [];
        try {
            $rows = $DB->get_records_sql(
                "SELECT eg.graderid, COUNT(DISTINCT eg.attemptid) AS graded
                   FROM {learnguard_essay_grades} eg
                   JOIN {learnguard_attempts} a ON a.id = eg.attemptid AND a.learnguard = ?
                  GROUP BY eg.graderid", [$instance->id]);
            foreach ($rows as $r) { $gradedMap[$r->graderid] = (int)$r->graded; }
        } catch (Exception $e) {}
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="grader_performance_' . date('Ymd') . '.csv"');
        echo "\xEF\xBB\xBF";
        $fh = fopen('php://output', 'w');
        fputcsv($fh, ['Grader', 'Email', 'Layer', 'Anonymous', 'Deadline', 'Total Attempts', 'Graded', 'Left']);
        foreach ($expGraders as $g) {
            $graded = $gradedMap[$g->userid] ?? 0;
            fputcsv($fh, [
                fullname($g), $g->email,
                $g->gradelayer == 2 ? '2nd Review' : '1st Grader',
                $g->anonymous ? 'Yes' : 'No',
                $g->deadline > 0 ? userdate($g->deadline, '%d/%m/%Y') : '',
                $totalFin, $graded, max(0, $totalFin - $graded),
            ]);
        }
        fclose($fh);
        exit;
    }

    // Add grader.
    if (data_submitted() && confirm_sesskey()) {
        $gaction = optional_param('gaction', '', PARAM_ALPHA);
        if ($gaction === 'addgrader') {
            $search   = trim(required_param('usersearch', PARAM_RAW));
            $anon     = optional_param('anonymous', 0, PARAM_INT);
            $layer    = optional_param('gradelayer', 1, PARAM_INT);
            $dlraw    = optional_param('grader_deadline', '', PARAM_RAW);
            $deadline = ($dlraw !== '') ? strtotime($dlraw) : 0;
            $guser    = $DB->get_record('user', ['email' => $search, 'deleted' => 0])
                     ?: $DB->get_record('user', ['username' => $search, 'deleted' => 0]);
            if (!$guser && is_numeric($search)) { $guser = $DB->get_record('user', ['id' => (int)$search, 'deleted' => 0]); }
            if ($guser) {
                try {
                    if (!$DB->record_exists('learnguard_graders', ['learnguard' => $instance->id, 'userid' => $guser->id])) {
                        $DB->insert_record('learnguard_graders', (object)[
                            'learnguard'   => $instance->id,
                            'userid'       => $guser->id,
                            'anonymous'    => (int)$anon,
                            'gradelayer'   => min(2, max(1, (int)$layer)),
                            'deadline'     => (int)$deadline,
                            'timecreated'  => time(),
                        ]);
                        if (get_config('learnguard', 'notify_proctors_enabled')) {
                            $graderUrl = (new moodle_url('/mod/learnguard/grader.php', ['id' => $id]))->out(false);
                            $dlStr = $deadline > 0 ? userdate($deadline, '%d %B %Y') : 'No deadline set';
                            learnguard_send_email($guser,
                                '✅ LearnGuard: You have been assigned as a grader',
                                "You have been assigned as a Layer " . min(2, max(1, (int)$layer)) .
                                " grader for the assessment \"" . format_string($instance->name) . "\"\n" .
                                "in the course \"" . format_string($course->fullname) . "\".\n\n" .
                                "Grading deadline: " . $dlStr . "\n\n" .
                                "Open your grading queue here: " . $graderUrl . "\n\n" .
                                "LearnGuard — Secure Assessment Platform"
                            );
                        }
                    }
                } catch (Exception $e) { /* table not yet created — ignore */ }
                redirect(new moodle_url('/mod/learnguard/admin.php', ['id' => $id, 'tab' => 'graders']),
                    fullname($guser) . ' added as grader.', null, \core\output\notification::NOTIFY_SUCCESS);
            } else {
                redirect(new moodle_url('/mod/learnguard/admin.php', ['id' => $id, 'tab' => 'graders']),
                    'User not found: ' . s($search), null, \core\output\notification::NOTIFY_ERROR);
            }
        }
        if ($gaction === 'addgrader_multi') {
            // Assign a grader to this assessment PLUS any additional selected assessments in the same course.
            $search   = trim(required_param('usersearch', PARAM_RAW));
            $anon     = optional_param('anonymous', 0, PARAM_INT);
            $layer    = optional_param('gradelayer', 1, PARAM_INT);
            $dlraw    = optional_param('grader_deadline', '', PARAM_RAW);
            $deadline = ($dlraw !== '') ? strtotime($dlraw) : 0;
            $guser    = $DB->get_record('user', ['email' => $search, 'deleted' => 0])
                     ?: $DB->get_record('user', ['username' => $search, 'deleted' => 0]);
            if (!$guser && is_numeric($search)) { $guser = $DB->get_record('user', ['id' => (int)$search, 'deleted' => 0]); }

            // Build the list of learnguard instance IDs to assign to.
            // Always include current; add any extras the user ticked.
            $extraLgids = optional_param_array('extra_lgids', [], PARAM_INT);
            $allLgIds   = array_unique(array_merge([$instance->id], $extraLgids));

            if ($guser) {
                // Auto-enroll the grader using Moodle's manual enrollment plugin.
                // enrol_try_internal_enrol() creates a proper {user_enrolments} record which
                // satisfies require_login()'s is_enrolled() check on Moodle 4.5+ / 5.x.
                // It also creates the manual enrollment instance for the course if needed.
                require_once($CFG->libdir . '/enrollib.php');
                $lgCourseCtx = context_course::instance($course->id);
                $lgRoleId    = (int)($DB->get_field('role', 'id', ['shortname' => 'teacher'])
                               ?: $DB->get_field('role', 'id', ['shortname' => 'editingteacher'])
                               ?: 0);
                $lgDidEnroll = false;
                if (!is_enrolled($lgCourseCtx, $guser->id)) {
                    $lgDidEnroll = (bool) enrol_try_internal_enrol($course->id, $guser->id, $lgRoleId ?: null);
                }

                $addedTo = [];
                foreach ($allLgIds as $lgid) {
                    $lgid = (int)$lgid;
                    // Verify the submitter has manage capability on this activity.
                    $targetCm = get_coursemodule_from_instance('learnguard', $lgid, $course->id);
                    if (!$targetCm) { continue; }
                    $targetCtx = context_module::instance($targetCm->id);
                    if (!has_capability('mod/learnguard:manage', $targetCtx)) { continue; }

                    try {
                        if (!$DB->record_exists('learnguard_graders', ['learnguard' => $lgid, 'userid' => $guser->id])) {
                            $DB->insert_record('learnguard_graders', (object)[
                                'learnguard'   => $lgid,
                                'userid'       => $guser->id,
                                'anonymous'    => (int)$anon,
                                'gradelayer'   => min(2, max(1, (int)$layer)),
                                'deadline'     => $deadline,
                                'timecreated'  => time(),
                                'lg_enrolled'  => $lgDidEnroll ? 1 : 0,
                            ]);
                            $targetLg = $DB->get_record('learnguard', ['id' => $lgid]);
                            $addedTo[] = $targetLg ? format_string($targetLg->name) : 'ID ' . $lgid;
                            if (get_config('learnguard', 'notify_proctors_enabled') && $lgid === (int)$instance->id) {
                                $graderUrl = (new moodle_url('/mod/learnguard/grader.php', ['id' => $targetCm->id]))->out(false);
                                $dlStr = $deadline > 0 ? userdate($deadline, '%d %B %Y') : 'No deadline set';
                                learnguard_send_email($guser,
                                    '✅ LearnGuard: You have been assigned as a grader',
                                    "You have been assigned as a Layer " . min(2, max(1, (int)$layer)) .
                                    " grader for the assessment \"" . format_string($instance->name) . "\"\n" .
                                    "in the course \"" . format_string($course->fullname) . "\".\n\n" .
                                    "Grading deadline: " . $dlStr . "\n\n" .
                                    "Open your grading queue here: " . $graderUrl . "\n\n" .
                                    "LearnGuard — Secure Assessment Platform"
                                );
                            }
                        }
                    } catch (Exception $e) { }
                }
                $msg = empty($addedTo) ? fullname($guser) . ' already assigned to all selected assessments.'
                                       : fullname($guser) . ' assigned to: ' . implode(', ', $addedTo) . '.';
                redirect(new moodle_url('/mod/learnguard/admin.php', ['id' => $id, 'tab' => 'graders']),
                    $msg, null, \core\output\notification::NOTIFY_SUCCESS);
            } else {
                redirect(new moodle_url('/mod/learnguard/admin.php', ['id' => $id, 'tab' => 'graders']),
                    'User not found: ' . s($search), null, \core\output\notification::NOTIFY_ERROR);
            }
        }
        if ($gaction === 'removegrader') {
            $graderid  = required_param('graderid', PARAM_INT);
            $graderRec = $DB->get_record('learnguard_graders', ['id' => $graderid, 'learnguard' => $instance->id]);
            try { $DB->delete_records('learnguard_graders', ['id' => $graderid, 'learnguard' => $instance->id]); } catch (Exception $e) {}

            // If LearnGuard auto-enrolled this grader and they have no remaining
            // assignments in this course, revoke their course enrollment.
            if ($graderRec && !empty($graderRec->lg_enrolled)) {
                $otherCount = $DB->count_records_sql(
                    "SELECT COUNT(*) FROM {learnguard_graders} g
                       JOIN {learnguard} lg ON lg.id = g.learnguard
                      WHERE g.userid = :uid AND lg.course = :cid",
                    ['uid' => $graderRec->userid, 'cid' => $course->id]
                );
                if ($otherCount == 0) {
                    require_once($CFG->libdir . '/enrollib.php');
                    $lgManualPlugin = enrol_get_plugin('manual');
                    $lgManualInst   = $lgManualPlugin
                        ? $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'])
                        : null;
                    if ($lgManualPlugin && $lgManualInst) {
                        $lgManualPlugin->unenrol_user($lgManualInst, $graderRec->userid);
                    }
                }
            }
            redirect(new moodle_url('/mod/learnguard/admin.php', ['id' => $id, 'tab' => 'graders']),
                'Grader removed and course access revoked.', null, \core\output\notification::NOTIFY_SUCCESS);
        }
        // R5: Toggle anonymous flag on an existing grader.
        if ($gaction === 'toggleanon') {
            $graderid = required_param('graderid', PARAM_INT);
            try {
                $grec = $DB->get_record('learnguard_graders', ['id' => $graderid, 'learnguard' => $instance->id]);
                if ($grec) {
                    $DB->set_field('learnguard_graders', 'anonymous', $grec->anonymous ? 0 : 1, ['id' => $graderid]);
                }
            } catch (Exception $e) {}
            redirect(new moodle_url('/mod/learnguard/admin.php', ['id' => $id, 'tab' => 'graders']),
                'Anonymous setting updated.', null, \core\output\notification::NOTIFY_SUCCESS);
        }
        // R5: Bulk set anonymous for all graders on this assessment.
        if ($gaction === 'bulkanon') {
            $anonval = optional_param('anonval', 0, PARAM_INT);
            try {
                $DB->set_field('learnguard_graders', 'anonymous', $anonval, ['learnguard' => $instance->id]);
            } catch (Exception $e) {}
            redirect(new moodle_url('/mod/learnguard/admin.php', ['id' => $id, 'tab' => 'graders']),
                'Bulk anonymous setting updated.', null, \core\output\notification::NOTIFY_SUCCESS);
        }
        // R4: Send manual reminder to a specific grader.
        if ($gaction === 'sendreminder') {
            $graderid = required_param('graderid', PARAM_INT);
            try {
                $grec  = $DB->get_record('learnguard_graders', ['id' => $graderid, 'learnguard' => $instance->id]);
                $guser = $grec ? $DB->get_record('user', ['id' => $grec->userid]) : null;
                if ($guser) {
                    $graderUrl  = (new moodle_url('/mod/learnguard/grader.php', ['id' => $id]))->out(false);
                    $dlStr = ($grec->deadline > 0) ? userdate($grec->deadline, '%d %B %Y') : 'No deadline set';
                    learnguard_send_email($guser, '⏰ Reminder: Essays awaiting grading — LearnGuard',
                        "This is a reminder that you have essay responses awaiting your grade for:\n\n" .
                        "Assessment: " . format_string($instance->name) . "\n" .
                        "Course    : " . format_string($course->fullname) . "\n" .
                        "Deadline  : " . $dlStr . "\n\n" .
                        "Open your grading queue: " . $graderUrl . "\n\n" .
                        "LearnGuard — Secure Assessment Platform"
                    );
                }
            } catch (Exception $e) {}
            redirect(new moodle_url('/mod/learnguard/admin.php', ['id' => $id, 'tab' => 'graders']),
                'Reminder sent.', null, \core\output\notification::NOTIFY_SUCCESS);
        }
        if ($gaction === 'setgradingmode') {
            $gmode = optional_param('gradingmode', 'all', PARAM_ALPHA);
            $gcap  = max(0, optional_param('gradingcap', 0, PARAM_INT));
            if (!in_array($gmode, ['all', 'split', 'random', 'manual'], true)) { $gmode = 'all'; }

            try {
                $existing = $DB->get_record('learnguard_grading_config', ['learnguard' => $instance->id]);
                if ($existing) {
                    $DB->update_record('learnguard_grading_config', (object)[
                        'id' => $existing->id, 'gradingmode' => $gmode, 'gradingcap' => $gcap, 'timemodified' => time(),
                    ]);
                } else {
                    $DB->insert_record('learnguard_grading_config', (object)[
                        'learnguard' => $instance->id, 'gradingmode' => $gmode, 'gradingcap' => $gcap, 'timemodified' => time(),
                    ]);
                }
                if ($gmode === 'split' || $gmode === 'random') {
                    learnguard_distribute_grading($instance->id, $gmode, $gcap);
                }
            } catch (Exception $e) { /* tables not yet created — ignore */ }

            redirect(new moodle_url('/mod/learnguard/admin.php', ['id' => $id, 'tab' => 'graders']),
                'Grading distribution updated.', null, \core\output\notification::NOTIFY_SUCCESS);
        }
        if ($gaction === 'manualassign') {
            $manualattemptid = required_param('manualattemptid', PARAM_INT);
            $manualgraderid  = optional_param('manualgraderid', 0, PARAM_INT);
            try {
                $existing = $DB->get_record('learnguard_grader_assignments',
                    ['learnguard' => $instance->id, 'attemptid' => $manualattemptid]);
                if ($manualgraderid > 0) {
                    if ($existing) {
                        $DB->update_record('learnguard_grader_assignments', (object)[
                            'id' => $existing->id, 'graderid' => $manualgraderid, 'timecreated' => time(),
                        ]);
                    } else {
                        $DB->insert_record('learnguard_grader_assignments', (object)[
                            'learnguard' => $instance->id, 'attemptid' => $manualattemptid,
                            'graderid' => $manualgraderid, 'timecreated' => time(),
                        ]);
                    }
                } else if ($existing) {
                    $DB->delete_records('learnguard_grader_assignments', ['id' => $existing->id]);
                }
            } catch (Exception $e) { /* tables not yet created — ignore */ }

            redirect(new moodle_url('/mod/learnguard/admin.php', ['id' => $id, 'tab' => 'graders']),
                'Assignment updated.', null, \core\output\notification::NOTIFY_SUCCESS);
        }
    }

    // Check essay questions exist.
    $essayCount = $DB->count_records_sql(
        "SELECT COUNT(*) FROM {learnguard_slots} s
           JOIN {learnguard_qbank} q ON q.id = s.questionid
          WHERE s.learnguard = ? AND q.qtype = 'essay'", [$instance->id]
    );

    echo html_writer::tag('h4', '✍️ Essay Graders', ['class' => 'mb-1']);

    if ($essayCount === 0) {
        echo html_writer::div('This assessment has no essay questions. Grader assignment is only needed for essays.', 'alert alert-info');
    }

    $graders = [];
    try {
        $graders = $DB->get_records_sql(
            "SELECT g.id AS gid, g.userid, g.anonymous, g.gradelayer,
                    COALESCE(g.deadline, 0) AS deadline, g.timecreated,
                    u.firstname, u.lastname, u.email
               FROM {learnguard_graders} g
               JOIN {user} u ON u.id = g.userid
              WHERE g.learnguard = ?
           ORDER BY g.gradelayer, u.lastname", [$instance->id]
        );
    } catch (Exception $e) {
        echo $OUTPUT->notification('⚠️ Graders table not yet created. Run Moodle upgrade first.', 'warning');
    }

    if (empty($graders)) {
        echo html_writer::div('No graders assigned. Without a grader assigned, the logged-in assessor grades essays manually via the "Grade Essays" button.', 'alert alert-warning');
    } else {
        // R4: Grader performance stats — total finished, graded per grader.
        $totalFinishedAttempts = $DB->count_records('learnguard_attempts',
            ['learnguard' => $instance->id, 'state' => 'finished']);
        $gradedByGrader = [];
        try {
            $rows = $DB->get_records_sql(
                "SELECT eg.graderid, COUNT(DISTINCT eg.attemptid) AS graded
                   FROM {learnguard_essay_grades} eg
                   JOIN {learnguard_attempts} a ON a.id = eg.attemptid AND a.learnguard = ?
                  GROUP BY eg.graderid", [$instance->id]
            );
            foreach ($rows as $r) { $gradedByGrader[$r->graderid] = (int)$r->graded; }
        } catch (Exception $e) {}

        // R4: CSV export for grader performance.
        $exportUrl = new moodle_url('/mod/learnguard/admin.php', ['id' => $id, 'tab' => 'graders', 'graderexport' => 1, 'sesskey' => sesskey()]);
        echo html_writer::start_div('d-flex gap-2 align-items-center mb-2 flex-wrap');
        echo html_writer::link(new moodle_url('/mod/learnguard/gradeessays.php', ['id' => $id]),
            '✍️ Grade Essays Now', ['class' => 'btn btn-primary btn-sm']);
        echo html_writer::link($exportUrl, '⬇ Export Grader Performance', ['class' => 'btn btn-outline-secondary btn-sm']);
        // R5: Bulk anonymous buttons.
        echo '<form method="post" style="display:inline-flex;gap:4px;">' .
             html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
             html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'gaction', 'value' => 'bulkanon']) .
             html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'anonval', 'value' => '1']) .
             '<button type="submit" class="btn btn-outline-secondary btn-sm">🙈 All Anonymous</button></form>';
        echo '<form method="post" style="display:inline-flex;gap:4px;">' .
             html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
             html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'gaction', 'value' => 'bulkanon']) .
             html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'anonval', 'value' => '0']) .
             '<button type="submit" class="btn btn-outline-secondary btn-sm">👁 All Visible</button></form>';
        echo html_writer::end_div();

        echo html_writer::start_tag('table', ['class' => 'generaltable w-100 mb-3', 'style' => 'font-size:13px;']);
        echo html_writer::start_tag('tr');
        foreach (['Grader', 'Layer', 'Anonymous', 'Deadline', 'Assigned', 'Graded', 'Left', 'Actions'] as $h) {
            echo html_writer::tag('th', $h, ['style' => 'padding:8px 10px;white-space:nowrap;']);
        }
        echo html_writer::end_tag('tr');
        foreach ($graders as $g) {
            $layerLabel  = $g->gradelayer == 2 ? '2nd Review' : '1st Grader';
            $layerColour = $g->gradelayer == 2 ? '#6f42c1' : '#0f6cbf';
            $graded      = $gradedByGrader[$g->userid] ?? 0;
            $left        = max(0, $totalFinishedAttempts - $graded);
            $dlStr       = ($g->deadline > 0) ? userdate($g->deadline, '%d/%m/%Y') : '—';
            $dlOverdue   = ($g->deadline > 0 && $g->deadline < time());
            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', html_writer::tag('strong', fullname($g)) .
                '<br><small class="text-muted">' . s($g->email) . '</small>');
            echo html_writer::tag('td', html_writer::tag('span', $layerLabel,
                ['style' => 'background:' . $layerColour . ';color:#fff;padding:2px 8px;border-radius:12px;font-size:11px;']));
            echo html_writer::tag('td', $g->anonymous ? '✅ Yes' : '❌ No');
            echo html_writer::tag('td', html_writer::tag('span', $dlStr,
                ['style' => $dlOverdue ? 'color:#dc3545;font-weight:700;' : '']));
            echo html_writer::tag('td', $totalFinishedAttempts, ['style' => 'text-align:center;']);
            echo html_writer::tag('td', $graded,  ['style' => 'text-align:center;color:#28a745;font-weight:700;']);
            echo html_writer::tag('td', $left,    ['style' => 'text-align:center;' . ($left > 0 ? 'color:#dc3545;' : '')]);
            // Action buttons.
            echo html_writer::start_tag('td');
            echo '<div style="display:flex;flex-wrap:wrap;gap:4px;">';
            // Toggle anonymous.
            echo '<form method="post" style="margin:0;">' .
                 html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',  'value' => sesskey()]) .
                 html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'gaction',  'value' => 'toggleanon']) .
                 html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'graderid', 'value' => $g->gid]) .
                 '<button type="submit" class="btn btn-sm btn-outline-secondary" title="Toggle anonymous">' .
                 ($g->anonymous ? '👁 Show' : '🙈 Hide') . '</button></form>';
            // Send reminder (R4).
            if ($left > 0 && get_config('learnguard', 'notify_proctors_enabled')) {
                echo '<form method="post" style="margin:0;">' .
                     html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',  'value' => sesskey()]) .
                     html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'gaction',  'value' => 'sendreminder']) .
                     html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'graderid', 'value' => $g->gid]) .
                     '<button type="submit" class="btn btn-sm btn-outline-warning" title="Send reminder email">📧 Remind</button></form>';
            }
            // Remove.
            echo '<form method="post" style="margin:0;">' .
                 html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',  'value' => sesskey()]) .
                 html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'gaction',  'value' => 'removegrader']) .
                 html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'graderid', 'value' => $g->gid]) .
                 '<button type="submit" class="btn btn-sm btn-outline-danger">× Remove</button></form>';
            echo '</div>';
            echo html_writer::end_tag('td');
            echo html_writer::end_tag('tr');
        }
        echo html_writer::end_tag('table');
    }

    // Query other LearnGuard assessments in the same course for the multi-assign picker.
    $otherLgCms = [];
    try {
        $otherLgRows = $DB->get_records_sql(
            "SELECT lg.id AS lgid, lg.name, cm.id AS cmid
               FROM {learnguard} lg
               JOIN {course_modules} cm ON cm.instance = lg.id AND cm.module = (
                   SELECT id FROM {modules} WHERE name = 'learnguard'
               ) AND cm.deletioninprogress = 0
              WHERE lg.course = :courseid AND lg.id != :currid
           ORDER BY lg.name",
            ['courseid' => $course->id, 'currid' => $instance->id]
        );
        foreach ($otherLgRows as $r) {
            // Only include activities the current user can manage.
            $tmpCtx = context_module::instance((int)$r->cmid);
            if (has_capability('mod/learnguard:manage', $tmpCtx)) {
                $otherLgCms[] = $r;
            }
        }
    } catch (Exception $e) {}

    // Add grader form.
    echo html_writer::tag('h5', 'Assign a Grader', ['class' => 'mt-3 mb-2']);
    echo html_writer::start_tag('form', ['method' => 'post']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'gaction', 'value' => 'addgrader_multi']);
    echo '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">';
    echo '<div style="display:flex;flex-direction:column;gap:4px;">' .
         '<label style="font-size:12px;font-weight:600;">Email / Username / ID</label>' .
         html_writer::empty_tag('input', ['type' => 'text', 'name' => 'usersearch',
             'class' => 'form-control', 'style' => 'max-width:260px;', 'required' => 'required',
             'placeholder' => 'Email, username or user ID']) . '</div>';
    echo '<div style="display:flex;flex-direction:column;gap:4px;">' .
         '<label style="font-size:12px;font-weight:600;">Grade Layer</label>' .
         '<select name="gradelayer" class="form-control" style="max-width:150px;">' .
         '<option value="1">1st Grader</option>' .
         '<option value="2">2nd Review (second layer)</option>' .
         '</select></div>';
    echo '<div style="display:flex;flex-direction:column;gap:4px;">' .
         '<label style="font-size:12px;font-weight:600;">Grading Deadline <small class="text-muted">(optional)</small></label>' .
         html_writer::empty_tag('input', ['type' => 'date', 'name' => 'grader_deadline',
             'class' => 'form-control', 'style' => 'max-width:180px;']) . '</div>';
    echo '<div style="display:flex;gap:6px;align-items:center;padding-bottom:4px;">' .
         html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'anonymous', 'value' => '1', 'id' => 'lg-anon']) .
         '<label for="lg-anon" style="font-size:13px;margin-bottom:0;">Anonymous (hide student name from grader)</label>' .
         '</div>';
    echo '</div>'; // close flex row

    // Multi-assessment picker.
    echo '<div style="margin-top:14px;border:1px solid #dee2e6;border-radius:8px;padding:14px 18px;background:#f8f9fa;">';
    echo '<div style="font-size:12px;font-weight:700;text-transform:uppercase;color:#6c757d;margin-bottom:10px;letter-spacing:.5px;">📋 Also assign to these assessments in the same course</div>';
    // Current assessment — always included, shown disabled.
    echo '<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">';
    echo html_writer::empty_tag('input', ['type' => 'checkbox', 'checked' => 'checked', 'disabled' => 'disabled']);
    echo '<span style="font-size:13px;font-weight:600;">' . s(format_string($instance->name)) . '</span>';
    echo '<span style="font-size:11px;color:#6c757d;margin-left:4px;">(current assessment)</span>';
    echo '</div>';
    if (!empty($otherLgCms)) {
        foreach ($otherLgCms as $other) {
            $cbId = 'lg-extra-' . (int)$other->lgid;
            echo '<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">';
            echo html_writer::empty_tag('input', [
                'type'  => 'checkbox',
                'name'  => 'extra_lgids[]',
                'value' => (int)$other->lgid,
                'id'    => $cbId,
            ]);
            echo '<label for="' . $cbId . '" style="font-size:13px;margin-bottom:0;cursor:pointer;">' .
                 s(format_string($other->name)) . '</label>';
            echo '</div>';
        }
    } else {
        echo '<p style="font-size:12px;color:#6c757d;margin:4px 0 0;">No other LearnGuard assessments found in this course.</p>';
    }
    echo '</div>';

    echo '<div style="margin-top:12px;">';
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => '+ Assign Grader', 'class' => 'btn btn-primary']);
    echo '</div>';
    echo html_writer::end_tag('form');

    echo html_writer::start_div('alert alert-info mt-3');
    echo html_writer::tag('strong', 'How it works: ');
    echo 'Layer 1 graders see essays first. Layer 2 graders review and can override Layer 1 grades. ';
    echo 'With Anonymous enabled, the grader sees only "Student A, B, C..." instead of real names.';
    echo html_writer::end_div();

    // ---- Grading distribution — split finished attempts across Layer-1 graders ----
    if ($essayCount > 0) {
        $layer1graders = array_values(array_filter($graders, fn($g) => (int)$g->gradelayer === 1));

        $gconfig = null;
        try { $gconfig = $DB->get_record('learnguard_grading_config', ['learnguard' => $instance->id]); }
        catch (Exception $e) { /* table not yet created — ignore */ }
        $curMode = $gconfig->gradingmode ?? 'all';
        $curCap  = $gconfig->gradingcap ?? 0;

        echo html_writer::tag('h5', '📊 Grading Distribution', ['class' => 'mt-4 mb-2']);

        if (count($layer1graders) < 2) {
            echo html_writer::div(
                'Assign at least 2 "1st Grader" (Layer 1) graders above to split grading work between them. '
                . 'With 0 or 1 Layer-1 grader, "All Graders See All" applies regardless of this setting.',
                'alert alert-secondary mb-2'
            );
        }

        echo html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'gaction', 'value' => 'setgradingmode']);

        $modes = [
            'all'    => 'All Graders See All (default)',
            'split'  => 'Even Split — divide finished attempts evenly between Layer-1 graders',
            'random' => 'Cap + Randomise — give each Layer-1 grader a random sample, up to a cap',
            'manual' => 'Manual — admin assigns each attempt to a specific grader',
        ];
        echo '<div style="display:flex;flex-direction:column;gap:4px;">' .
             '<label style="font-size:12px;font-weight:600;">Mode</label>' .
             '<select name="gradingmode" class="form-control" style="max-width:420px;">';
        foreach ($modes as $val => $label) {
            $sel = ($curMode === $val) ? ' selected="selected"' : '';
            echo '<option value="' . s($val) . '"' . $sel . '>' . s($label) . '</option>';
        }
        echo '</select></div>';

        echo '<div style="display:flex;flex-direction:column;gap:4px;">' .
             '<label style="font-size:12px;font-weight:600;">Cap per grader (Cap + Randomise)</label>' .
             html_writer::empty_tag('input', ['type' => 'number', 'name' => 'gradingcap', 'min' => '0',
                 'value' => (string)$curCap, 'class' => 'form-control', 'style' => 'max-width:120px;',
                 'placeholder' => '0 = even share']) . '</div>';

        echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => '💾 Save & Distribute', 'class' => 'btn btn-primary']);
        echo html_writer::end_tag('form');

        // Current distribution summary.
        if ($curMode !== 'all') {
            $assigned = [];
            try {
                $assigned = $DB->get_records_sql(
                    "SELECT ga.graderid, COUNT(*) AS cnt
                       FROM {learnguard_grader_assignments} ga
                      WHERE ga.learnguard = ?
                   GROUP BY ga.graderid", [$instance->id]
                );
            } catch (Exception $e) { /* table not yet created — ignore */ }

            $totalFinished = $DB->count_records('learnguard_attempts',
                ['learnguard' => $instance->id, 'state' => 'finished']);
            $totalAssigned = array_sum(array_map(fn($r) => (int)$r->cnt, $assigned));

            echo html_writer::start_div('alert alert-info mt-2');
            echo html_writer::tag('strong', 'Current distribution: ');
            if (empty($layer1graders)) {
                echo 'No Layer-1 graders assigned yet — nothing to distribute.';
            } else if (empty($assigned) && $curMode !== 'manual') {
                echo 'Not yet distributed — click "Save & Distribute" to assign attempts.';
            } else {
                $parts = [];
                foreach ($layer1graders as $g) {
                    $cnt = (int)($assigned[$g->userid]->cnt ?? 0);
                    $parts[] = fullname($g) . ': ' . $cnt;
                }
                echo implode(', ', $parts);
                if ($totalAssigned < $totalFinished) {
                    echo '. ' . ($totalFinished - $totalAssigned) . ' finished attempt(s) are unassigned — '
                        . 'these remain visible to managers and Layer-2 reviewers via "Grade Essays Now".';
                }
            }
            echo html_writer::end_div();
        }

        // Manual assignment table — one row per finished attempt.
        if ($curMode === 'manual' && !empty($layer1graders)) {
            $finishedAttempts = $DB->get_records_sql(
                "SELECT a.id, a.userid, a.timefinish, u.firstname, u.lastname, u.email
                   FROM {learnguard_attempts} a
                   JOIN {user} u ON u.id = a.userid
                  WHERE a.learnguard = ? AND a.state = 'finished'
               ORDER BY a.timefinish DESC", [$instance->id]
            );

            $curAssignments = [];
            try {
                foreach ($DB->get_records('learnguard_grader_assignments', ['learnguard' => $instance->id]) as $row) {
                    $curAssignments[$row->attemptid] = $row->graderid;
                }
            } catch (Exception $e) { /* table not yet created — ignore */ }

            if (empty($finishedAttempts)) {
                echo html_writer::div('No finished attempts yet to assign.', 'alert alert-secondary mt-2');
            } else {
                echo html_writer::start_tag('table', ['class' => 'generaltable w-100 mt-2']);
                echo html_writer::start_tag('tr');
                foreach (['Candidate', 'Submitted', 'Assigned Grader', ''] as $h) {
                    echo html_writer::tag('th', $h, ['style' => 'padding:6px 10px;']);
                }
                echo html_writer::end_tag('tr');
                foreach ($finishedAttempts as $att) {
                    echo html_writer::start_tag('tr');
                    echo html_writer::tag('td', html_writer::tag('strong', fullname($att)) .
                        '<br><small style="color:#6c757d;">' . s($att->email) . '</small>');
                    echo html_writer::tag('td', userdate($att->timefinish, '%d/%m/%y %H:%M'));
                    echo html_writer::start_tag('td', ['colspan' => 2]);
                    echo html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:flex;gap:6px;align-items:center;']);
                    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
                    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'gaction', 'value' => 'manualassign']);
                    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'manualattemptid', 'value' => $att->id]);
                    echo '<select name="manualgraderid" class="form-control" style="max-width:220px;">';
                    echo '<option value="0">— Unassigned —</option>';
                    foreach ($layer1graders as $g) {
                        $sel = (isset($curAssignments[$att->id]) && (int)$curAssignments[$att->id] === (int)$g->userid)
                            ? ' selected="selected"' : '';
                        echo '<option value="' . (int)$g->userid . '"' . $sel . '>' . s(fullname($g)) . '</option>';
                    }
                    echo '</select>';
                    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Save', 'class' => 'btn btn-sm btn-outline-primary']);
                    echo html_writer::end_tag('form');
                    echo html_writer::end_tag('td');
                    echo html_writer::end_tag('tr');
                }
                echo html_writer::end_tag('table');
            }
        }
    }
}

// ---- Questions tab ----
if ($tab === 'questions') {
    $totalq = $DB->count_records('learnguard_slots', ['learnguard' => $instance->id]);
    echo html_writer::tag('p', $totalq . ' question(s) currently in this assessment.');
    echo html_writer::link(
        new moodle_url('/mod/learnguard/editquestions.php', ['id' => $id]),
        '📝 Open Question Manager →',
        ['class' => 'btn btn-primary mb-3']
    );
    echo html_writer::link(
        new moodle_url('/question/edit.php', ['courseid' => $course->id]),
        '🏦 Open Question Bank →',
        ['class' => 'btn btn-outline-secondary mb-3', 'target' => '_blank']
    );

    // Show current question list.
    $slots = $DB->get_records_sql(
        "SELECT s.slot, q.name AS qname, q.qtype, s.maxmark
           FROM {learnguard_slots} s
           JOIN {question} q ON q.id = s.questionid
          WHERE s.learnguard = :lgid
       ORDER BY s.slot ASC",
        ['lgid' => $instance->id]
    );

    if (!empty($slots)) {
        echo html_writer::start_tag('table', ['class' => 'generaltable', 'style' => 'max-width:600px;']);
        foreach ($slots as $s) {
            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', '#' . $s->slot, ['style' => 'color:#6c757d;font-weight:700;width:40px;']);
            echo html_writer::tag('td', format_string($s->qname));
            echo html_writer::tag('td', ucfirst($s->qtype), ['style' => 'color:#6c757d;font-size:13px;']);
            echo html_writer::tag('td', $s->maxmark . ' pts', ['style' => 'text-align:right;color:#6c757d;']);
            echo html_writer::end_tag('tr');
        }
        echo html_writer::end_tag('table');
    }
}

// ---- Events tab ----
if ($tab === 'events') {
    $events = $DB->get_records_sql(
        "SELECT e.*, u.firstname, u.lastname, a.attempt
           FROM {learnguard_events} e
           JOIN {learnguard_attempts} a ON a.id = e.attemptid
           JOIN {user} u ON u.id = e.userid
          WHERE a.learnguard = :lgid
       ORDER BY e.timemodified DESC
          LIMIT 200",
        ['lgid' => $instance->id]
    );

    echo html_writer::tag('h4', 'Recent Proctoring Events (last 200)', ['class' => 'mb-3']);

    if (empty($events)) {
        echo html_writer::div('No proctoring events recorded.', 'alert alert-success');
    } else {
        $sevcols = ['low' => '#28a745', 'medium' => '#fd7e14', 'high' => '#dc3545'];
        echo html_writer::start_tag('table', ['class' => 'generaltable w-100']);
        echo html_writer::start_tag('thead');
        echo html_writer::start_tag('tr');
        foreach (['Time', 'Student', 'Attempt', 'Event', 'Severity', '+Score'] as $h) {
            echo html_writer::tag('th', $h, ['style' => 'padding:8px 10px;']);
        }
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');
        foreach ($events as $ev) {
            $sc = $sevcols[$ev->severity] ?? '#6c757d';
            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', userdate($ev->timemodified, '%d/%m %H:%M:%S'), ['style' => 'white-space:nowrap;font-size:13px;']);
            echo html_writer::tag('td', fullname($ev));
            echo html_writer::tag('td', '#' . $ev->attempt);
            echo html_writer::tag('td', str_replace('_', ' ', ucfirst($ev->event_type)));
            echo html_writer::tag('td', html_writer::tag('span', ucfirst($ev->severity), [
                'style' => 'color:' . $sc . ';font-weight:700;font-size:13px;',
            ]));
            echo html_writer::tag('td', $ev->score_impact > 0 ? '+' . $ev->score_impact : '0');
            echo html_writer::end_tag('tr');
        }
        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');
    }
}

// ---- Brand tab — per-activity branding override ----
if ($tab === 'brand') {

    if (data_submitted() && confirm_sesskey()) {
        $baction = optional_param('baction', '', PARAM_ALPHA);
        if ($baction === 'savebrand') {
            $bname    = trim(optional_param('brand_name', '', PARAM_TEXT));
            $bprimary = trim(optional_param('brand_primary', '', PARAM_RAW));
            $baccent  = trim(optional_param('brand_accent', '', PARAM_RAW));
            $btext    = trim(optional_param('brand_textcolor', '', PARAM_RAW));
            $hexre = '/^#[0-9a-fA-F]{3,8}$/';
            if ($bprimary !== '' && !preg_match($hexre, $bprimary)) { $bprimary = ''; }
            if ($baccent  !== '' && !preg_match($hexre, $baccent))  { $baccent  = ''; }
            if ($btext    !== '' && !preg_match($hexre, $btext))    { $btext    = ''; }

            try {
                $existing = $DB->get_record('learnguard_brand_overrides', ['learnguard' => $instance->id]);
                $rec = (object)[
                    'learnguard'      => $instance->id,
                    'brand_name'      => $bname    !== '' ? $bname    : null,
                    'brand_primary'   => $bprimary !== '' ? $bprimary : null,
                    'brand_accent'    => $baccent  !== '' ? $baccent  : null,
                    'brand_textcolor' => $btext    !== '' ? $btext    : null,
                    'timemodified'    => time(),
                ];
                if ($existing) {
                    $rec->id = $existing->id;
                    $DB->update_record('learnguard_brand_overrides', $rec);
                } else {
                    $DB->insert_record('learnguard_brand_overrides', $rec);
                }
                redirect(new moodle_url('/mod/learnguard/admin.php', ['id' => $id, 'tab' => 'brand']),
                    'Branding saved.', null, \core\output\notification::NOTIFY_SUCCESS);
            } catch (Exception $e) {
                redirect(new moodle_url('/mod/learnguard/admin.php', ['id' => $id, 'tab' => 'brand']),
                    '⚠️ Could not save — branding table not yet created. Run the Moodle upgrade first.',
                    null, \core\output\notification::NOTIFY_ERROR);
            }
        }
        if ($baction === 'resetbrand') {
            try { $DB->delete_records('learnguard_brand_overrides', ['learnguard' => $instance->id]); } catch (Exception $e) {}
            redirect(new moodle_url('/mod/learnguard/admin.php', ['id' => $id, 'tab' => 'brand']),
                'Branding reset to global defaults.', null, \core\output\notification::NOTIFY_SUCCESS);
        }
    }

    $override = null;
    try {
        $override = $DB->get_record('learnguard_brand_overrides', ['learnguard' => $instance->id]) ?: null;
    } catch (Exception $e) {
        echo $OUTPUT->notification('⚠️ Branding table not yet created. Run Moodle upgrade first.', 'warning');
    }

    $valname    = $override->brand_name      ?? '';
    $valprimary = $override->brand_primary   ?? $brand->primary;
    $valaccent  = $override->brand_accent    ?? $brand->accent;
    $valtext    = $override->brand_textcolor ?? $brand->textcolor;

    echo html_writer::tag('h4', '🎨 Per-Activity Branding', ['class' => 'mb-1']);
    echo html_writer::div(
        'Override LearnGuard\'s global branding (Site Administration → Plugins → LearnGuard → ' .
        'Branding &amp; Appearance) for <strong>' . format_string($instance->name) . '</strong> only — ' .
        'the rest of the site keeps the global branding shown below. Leave the Brand Name blank to keep ' .
        'the global name. The colour pickers are pre-filled with the global colours; change any of them ' .
        'to override just that colour for this assessment.',
        'alert alert-info mb-3'
    );

    // Current global defaults (reference).
    echo html_writer::start_div('d-flex gap-3 flex-wrap mb-3');
    foreach ([
        ['Global Name',    $brand->name,      null],
        ['Global Primary', $brand->primary,   $brand->primary],
        ['Global Accent',  $brand->accent,    $brand->accent],
        ['Global Banner Text', $brand->textcolor, $brand->textcolor],
    ] as [$label, $val, $swatch]) {
        echo html_writer::start_div('', ['style' =>
            'background:#fff;border:1px solid #dee2e6;border-radius:8px;padding:10px 14px;min-width:140px;']);
        echo html_writer::div($label, '', ['style' => 'font-size:11px;color:#6c757d;margin-bottom:4px;']);
        if ($swatch) {
            echo html_writer::tag('span', '', ['style' =>
                'display:inline-block;width:18px;height:18px;border-radius:4px;vertical-align:middle;' .
                'margin-right:6px;border:1px solid #dee2e6;background:' . s($swatch) . ';']);
        }
        echo html_writer::tag('strong', s($val));
        echo html_writer::end_div();
    }
    echo html_writer::end_div();

    // Override form.
    echo html_writer::start_tag('form', ['method' => 'post',
        'style' => 'display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end;max-width:760px;']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'baction', 'value' => 'savebrand']);

    echo '<div style="display:flex;flex-direction:column;gap:4px;flex:1 1 220px;">' .
         '<label style="font-size:12px;font-weight:600;">Assessment Brand Name</label>' .
         html_writer::empty_tag('input', ['type' => 'text', 'name' => 'brand_name',
             'class' => 'form-control', 'value' => s($valname),
             'placeholder' => $brand->name]) . '</div>';

    foreach ([
        ['brand_primary',   'Primary Colour',       $valprimary],
        ['brand_accent',    'Accent Colour',        $valaccent],
        ['brand_textcolor', 'Banner / Top-bar Text Colour', $valtext],
    ] as [$fname, $flabel, $fval]) {
        echo '<div style="display:flex;flex-direction:column;gap:4px;">' .
             '<label style="font-size:12px;font-weight:600;">' . $flabel . '</label>' .
             html_writer::empty_tag('input', ['type' => 'color', 'name' => $fname,
                 'value' => $fval, 'style' => 'width:60px;height:38px;padding:2px;']) .
             '</div>';
    }

    echo '<div>' . html_writer::empty_tag('input', ['type' => 'submit', 'value' => '💾 Save Branding',
        'class' => 'btn btn-primary']) . '</div>';
    echo html_writer::end_tag('form');

    if ($override) {
        echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'mt-3']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'baction', 'value' => 'resetbrand']);
        echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => '↺ Reset to Global Defaults',
            'class' => 'btn btn-outline-secondary btn-sm']);
        echo html_writer::end_tag('form');
    }
}

echo $OUTPUT->footer();
