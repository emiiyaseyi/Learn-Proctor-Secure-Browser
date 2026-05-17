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

    // Suspend an attempt.
    if ($action === 'suspend') {
        $attemptid = required_param('attemptid', PARAM_INT);
        require_capability('mod/learnguard:suspendattempt', $context);
        $attempt = $DB->get_record('learnguard_attempts', ['id' => $attemptid, 'learnguard' => $instance->id], '*', MUST_EXIST);
        $DB->set_field('learnguard_attempts', 'state', 'suspended', ['id' => $attemptid]);
        redirect(new moodle_url('/mod/learnguard/admin.php', ['id' => $id]),
            'Attempt #' . $attempt->id . ' suspended.', null, \core\output\notification::NOTIFY_SUCCESS);
    }

    // Reinstate an attempt.
    if ($action === 'reinstate') {
        $attemptid = required_param('attemptid', PARAM_INT);
        require_capability('mod/learnguard:suspendattempt', $context);
        $DB->set_field('learnguard_attempts', 'state', 'inprogress', ['id' => $attemptid]);
        redirect(new moodle_url('/mod/learnguard/admin.php', ['id' => $id]),
            'Attempt reinstated.', null, \core\output\notification::NOTIFY_SUCCESS);
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

echo $OUTPUT->header();

// ---- Top navigation bar ----
echo html_writer::start_div('', ['style' =>
    'background:linear-gradient(135deg,#0f6cbf,#0a4d8c);color:#fff;border-radius:8px;padding:20px 24px;margin-bottom:20px;']);
echo html_writer::tag('h2', '🛡 LearnGuard — ' . format_string($instance->name),
    ['style' => 'margin:0 0 4px;font-size:20px;']);
echo html_writer::tag('p', 'Proctor & Administration Backend',
    ['style' => 'margin:0;opacity:0.8;font-size:14px;']);
echo html_writer::end_div();

// ---- Tab navigation ----
$tabs = [
    'overview'  => '📊 Overview',
    'attempts'  => '📋 Attempts',
    'live'      => '🔴 Live Monitor',
    'proctors'  => '👤 Proctors',
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
                    ? 'background:#0f6cbf;color:#fff;'
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
        ['Total Attempts',   $totalattempts, '#0f6cbf'],
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
        [new moodle_url('/mod/learnguard/report.php', ['id' => $id]),
            '📊 Full Report', 'btn-primary'],
        [new moodle_url('/mod/learnguard/editquestions.php', ['id' => $id]),
            '📝 Manage Questions', 'btn-secondary'],
        [new moodle_url('/course/modedit.php', ['update' => $cm->id]),
            '⚙️ Edit Settings', 'btn-outline-secondary'],
        [new moodle_url('/mod/learnguard/report.php', ['id' => $id, 'download' => 'csv']),
            '⬇ Export CSV', 'btn-outline-secondary'],
    ];
    foreach ($quicklinks as [$url, $label, $cls]) {
        echo html_writer::link($url, $label, ['class' => 'btn btn-sm ' . $cls]);
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
                echo ' ';
                echo html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:inline;']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',        'value' => $id]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',   'value' => sesskey()]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',    'value' => 'reinstate']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'attemptid', 'value' => $a->id]);
                echo html_writer::empty_tag('input', [
                    'type' => 'submit', 'value' => '✅ Reinstate',
                    'class' => 'btn btn-sm btn-outline-success',
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

    // Add proctor form.
    echo html_writer::tag('h5', 'Assign a New Proctor', ['class' => 'mt-4 mb-2']);
    echo html_writer::tag('p',
        'Enter the user\'s Moodle username, email address, or user ID. The user must already have a Moodle account.',
        ['style' => 'color:#6c757d;font-size:14px;']);
    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'd-flex gap-2 align-items-end', 'style' => 'max-width:500px;']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',      'value' => $id]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'addproctor']);
    echo html_writer::start_div('flex-grow-1');
    echo html_writer::tag('label', 'Username / Email / User ID', ['for' => 'usersearch', 'style' => 'font-weight:600;font-size:14px;']);
    echo html_writer::empty_tag('input', [
        'type' => 'text', 'name' => 'usersearch', 'id' => 'usersearch',
        'class' => 'form-control', 'placeholder' => 'john.smith  or  john@uni.edu  or  42',
        'required' => 'required',
        'style' => 'margin-top:4px;',
    ]);
    echo html_writer::end_div();
    echo html_writer::empty_tag('input', [
        'type' => 'submit', 'value' => '+ Assign Proctor',
        'class' => 'btn btn-primary',
    ]);
    echo html_writer::end_tag('form');
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

echo $OUTPUT->footer();
