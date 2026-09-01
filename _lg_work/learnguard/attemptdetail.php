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
 * Attempt detail / review page for mod_learnguard.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

/**
 * Convert raw JSON event_data into a clean, human-readable string.
 * Handles all event types used by LearnGuard proctoring.
 */
function learnguard_format_event_data(string $eventType, string $raw): string {
    if (!$raw || $raw === '{}' || $raw === '') { return '—'; }
    $d = @json_decode($raw, true);
    if (!is_array($d)) { return htmlspecialchars($raw); }

    switch ($eventType) {
        case 'multiple_persons_detected':
        case 'multiple_persons':
            return isset($d['count']) ? (int)$d['count'] . ' persons in frame' : '—';

        case 'audio_detected':
        case 'audio_level':
            return isset($d['level']) ? 'Level ' . (int)$d['level'] : '—';

        case 'audio_clip_started':
        case 'audio_clip':
            $parts = [];
            if (isset($d['noise_level'])) { $parts[] = 'Noise ' . (int)$d['noise_level']; }
            if (isset($d['duration']))    { $parts[] = (int)$d['duration'] . 's clip'; }
            return $parts ? implode(' · ', $parts) : '—';

        case 'background_change':
            return isset($d['mean_diff']) ? 'Δ pixel ' . (int)$d['mean_diff'] : '—';

        case 'key_violation':
            $key = $d['key'] ?? '';
            $labels = ['meta' => 'Windows key', 'printscreen' => 'Print Screen',
                       'escape' => 'Escape', 'tab' => 'Tab'];
            $label = $labels[strtolower($key)] ?? strtoupper($key);
            return 'Key: ' . htmlspecialchars($label);

        case 'object_detected_cell_phone':
        case 'object_cell_phone':
            return isset($d['confidence']) ? 'Phone · ' . (int)$d['confidence'] . '% confidence' : 'Phone detected';

        case 'object_detected_book':
            return isset($d['confidence']) ? 'Book · ' . (int)$d['confidence'] . '% confidence' : 'Book detected';

        case 'face_missing':
        case 'face_not_detected':
            return 'No face in frame';

        case 'face_mismatch':
        case 'identity_mismatch':
            return isset($d['distance']) ? 'Distance ' . round((float)$d['distance'], 3) : 'Mismatch detected';

        case 'face_mismatch_approval_pending':
            return 'Awaiting invigilator approval';

        case 'camera_covered':
            return isset($d['brightness']) ? 'Brightness ' . (int)$d['brightness'] : 'Camera blocked';

        case 'tab_switch':
        case 'tab_hidden':
            return isset($d['count']) ? 'Switch #' . (int)$d['count'] : 'Tab switched';

        case 'fullscreen_exit':
            return 'Exited fullscreen';

        case 'screen_share_stopped':
            return 'Screen sharing stopped';

        case 'auto_suspended':
            return isset($d['reason']) ? htmlspecialchars($d['reason']) : 'Risk threshold exceeded';

        case 'clipboard_copy':
        case 'clipboard_paste':
        case 'clipboard_cut':
            $action = str_replace('clipboard_', '', $eventType);
            return 'Clipboard ' . $action;

        case 'vpn_detected':
            return isset($d['ip']) ? 'IP: ' . htmlspecialchars($d['ip']) : 'VPN/proxy detected';

        case 'no_blink_2min':
            return 'No blink for 2+ min';

        case 'audio_clip_saved':
            return isset($d['noise_level']) ? 'Noise ' . (int)$d['noise_level'] : 'Audio clip saved';

        case 'self_paused':
            return 'Student paused via check-in dialog';

        case 'checkin_prompted':
            $cnt = isset($d['count']) ? (int)$d['count'] : 5;
            return 'Check-in dialog shown (after ' . $cnt . ' violations)';

        case 'checkin_continued':
            return 'Student chose: Continue Assessment';

        case 'checkin_paused':
            return 'Student chose: Pause Assessment';

        case 'checkin_dismissed':
            return 'Student chose: Don\'t Show Again';

        default:
            // Generic: turn keys into readable "Key: Value" pairs.
            $out = [];
            foreach ($d as $k => $v) {
                if (is_scalar($v)) {
                    $out[] = ucwords(str_replace('_', ' ', $k)) . ': ' . htmlspecialchars((string)$v);
                }
            }
            return $out ? implode(' · ', $out) : '—';
    }
}

/**
 * Render a small inline SVG sparkline showing cumulative risk score over time.
 * $points is an array of [unix_timestamp, cumulative_score] pairs, ordered by time.
 */
function learnguard_risk_sparkline(array $points, int $width = 320, int $height = 50): string {
    if (count($points) < 2) {
        return '';
    }
    $t0    = $points[0][0];
    $tspan = max(1, end($points)[0] - $t0);
    $maxy  = max(100, max(array_column($points, 1)));
    $pad   = 4;

    $coords = [];
    foreach ($points as [$t, $score]) {
        $x = $pad + (($t - $t0) / $tspan) * ($width - 2 * $pad);
        $y = $height - $pad - (max(0, $score) / $maxy) * ($height - 2 * $pad);
        $coords[] = round($x, 1) . ',' . round($y, 1);
    }

    $y30 = $height - $pad - (30 / $maxy) * ($height - 2 * $pad);
    $y60 = $height - $pad - (60 / $maxy) * ($height - 2 * $pad);

    $svg  = '<svg width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '" style="display:block;overflow:visible;">';
    $svg .= '<line x1="0" y1="' . round($y60, 1) . '" x2="' . $width . '" y2="' . round($y60, 1) . '" stroke="#dc3545" stroke-width="1" stroke-dasharray="3,2" opacity="0.5"/>';
    $svg .= '<line x1="0" y1="' . round($y30, 1) . '" x2="' . $width . '" y2="' . round($y30, 1) . '" stroke="#fd7e14" stroke-width="1" stroke-dasharray="3,2" opacity="0.5"/>';
    $svg .= '<polyline points="' . implode(' ', $coords) . '" fill="none" stroke="#0f6cbf" stroke-width="2"/>';
    $svg .= '</svg>';
    return $svg;
}

$id        = required_param('id',      PARAM_INT); // Course module ID.
$attemptid = required_param('attempt', PARAM_INT);

$cm       = get_coursemodule_from_id('learnguard', $id, 0, false, MUST_EXIST);
$course   = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$instance = $DB->get_record('learnguard', ['id' => $cm->instance], '*', MUST_EXIST);
$attempt  = $DB->get_record('learnguard_attempts', ['id' => $attemptid, 'learnguard' => $instance->id], '*', MUST_EXIST);
$student  = $DB->get_record('user', ['id' => $attempt->userid], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/learnguard:viewreports', $context);

$PAGE->set_url('/mod/learnguard/attemptdetail.php', ['id' => $id, 'attempt' => $attemptid]);
$PAGE->set_context($context);
$PAGE->set_title('Attempt Review — ' . fullname($student));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->css('/mod/learnguard/styles.css');

// ---- Snapshot management actions ----
if (data_submitted() && confirm_sesskey()) {
    $postaction = optional_param('action', '', PARAM_ALPHANUMEXT);

    // Tell a still-active, not-suspended student their flagged items were reviewed and
    // cleared — sent via the existing chat system, which the exam client already
    // surfaces as BOTH a live toast (if the chat panel isn't open) and a chat panel
    // entry on its next 15s poll, so one message covers both without any client change.
    // Only makes sense while they're actually mid-exam and not already suspended —
    // a finished/voided/suspended attempt has no live screen to receive it on.
    if (!function_exists('learnguard_notify_dismiss_reviewed')) {
        function learnguard_notify_dismiss_reviewed($attemptid, $learnguardid) {
            global $DB, $USER;
            $DB->insert_record('learnguard_messages', (object)[
                'learnguard'  => $learnguardid,
                'attemptid'   => $attemptid,
                'senderid'    => $USER->id,
                'sendertype'  => 'proctor',
                'message'     => '✅ Your proctor has reviewed your flagged items and cleared them — you may '
                    . 'continue your assessment. To avoid further flags: stay within camera view, remain in a '
                    . 'quiet environment, keep your face visible at all times, and avoid switching tabs or windows.',
                'timecreated' => time(),
                'isseen'      => 0,
            ]);
        }
    }

    // Proctor: override (dismiss) a flagged event and deduct its score.
    if ($postaction === 'override_event' && has_capability('mod/learnguard:liveproctor', $context)) {
        $evid = required_param('eventid', PARAM_INT);
        $ev   = $DB->get_record('learnguard_events', ['id' => $evid, 'attemptid' => $attemptid], '*', MUST_EXIST);
        if (empty($ev->overridden)) {
            $DB->set_field('learnguard_events', 'overridden', 1, ['id' => $ev->id]);
            $newScore = max(0, (int)$attempt->risk_score - (int)$ev->score_impact);
            $newLevel = $newScore >= 60 ? 'high' : ($newScore >= 30 ? 'medium' : 'low');
            $DB->set_field('learnguard_attempts', 'risk_score',    $newScore, ['id' => $attemptid]);
            $DB->set_field('learnguard_attempts', 'risk_level',    $newLevel, ['id' => $attemptid]);
            $DB->set_field('learnguard_attempts', 'timemodified',  time(),    ['id' => $attemptid]);
            if ($attempt->state === 'inprogress') {
                learnguard_notify_dismiss_reviewed($attemptid, $instance->id);
            }
        }
        redirect(new moodle_url('/mod/learnguard/attemptdetail.php', ['id' => $id, 'attempt' => $attemptid]),
            'Event dismissed. Risk score adjusted.', null, \core\output\notification::NOTIFY_SUCCESS);
    }

    // Proctor: bulk dismiss multiple flagged events at once.
    if ($postaction === 'bulk_dismiss_events' && has_capability('mod/learnguard:liveproctor', $context)) {
        $rawIds  = optional_param_array('eventids', [], PARAM_INT);
        $evIds   = array_filter(array_map('intval', $rawIds));
        $deducted = 0;
        foreach ($evIds as $evid) {
            $ev = $DB->get_record('learnguard_events', ['id' => $evid, 'attemptid' => $attemptid]);
            if ($ev && empty($ev->overridden)) {
                $DB->set_field('learnguard_events', 'overridden', 1, ['id' => $ev->id]);
                $deducted += (int)$ev->score_impact;
            }
        }
        if ($deducted > 0) {
            $attempt  = $DB->get_record('learnguard_attempts', ['id' => $attemptid], '*', MUST_EXIST);
            $newScore = max(0, (int)$attempt->risk_score - $deducted);
            $newLevel = $newScore >= 60 ? 'high' : ($newScore >= 30 ? 'medium' : 'low');
            $DB->set_field('learnguard_attempts', 'risk_score',   $newScore, ['id' => $attemptid]);
            $DB->set_field('learnguard_attempts', 'risk_level',   $newLevel, ['id' => $attemptid]);
            $DB->set_field('learnguard_attempts', 'timemodified', time(),    ['id' => $attemptid]);
            if ($attempt->state === 'inprogress') {
                learnguard_notify_dismiss_reviewed($attemptid, $instance->id);
            }
        }
        redirect(new moodle_url('/mod/learnguard/attemptdetail.php', ['id' => $id, 'attempt' => $attemptid]),
            count($evIds) . ' event(s) dismissed. Risk score adjusted.', null, \core\output\notification::NOTIFY_SUCCESS);
    }

    // Proctor: toggle violation scoring on/off for this attempt.
    if ($postaction === 'togglevio' && has_capability('mod/learnguard:liveproctor', $context)) {
        $newVal = empty($attempt->violations_suspended) ? 1 : 0;
        $DB->set_field('learnguard_attempts', 'violations_suspended', $newVal, ['id' => $attemptid]);
        redirect(new moodle_url('/mod/learnguard/attemptdetail.php', ['id' => $id, 'attempt' => $attemptid]),
            $newVal ? '⏸ Violation scoring paused for this learner.' : '▶ Violation scoring resumed.',
            null, \core\output\notification::NOTIFY_SUCCESS);
    }

    if ($postaction === 'delete360' && has_capability('mod/learnguard:manage', $context)) {
        $DB->delete_records_select('learnguard_snapshots',
            "attemptid = :aid AND snapshot_type = 'environment_recording'", ['aid' => $attemptid]);
        redirect(new moodle_url('/mod/learnguard/attemptdetail.php', ['id' => $id, 'attempt' => $attemptid]),
            '360° recordings deleted.', null, \core\output\notification::NOTIFY_SUCCESS);
    }
    if ($postaction === 'deleteaudioclip' && has_capability('mod/learnguard:manage', $context)) {
        $clipid = required_param('clipid', PARAM_INT);
        $DB->delete_records('learnguard_audio_clips', ['id' => $clipid, 'attemptid' => $attemptid]);
        redirect(new moodle_url('/mod/learnguard/attemptdetail.php', ['id' => $id, 'attempt' => $attemptid]),
            'Audio clip deleted.', null, \core\output\notification::NOTIFY_SUCCESS);
    }
    if ($postaction === 'deleteallaudios' && has_capability('mod/learnguard:manage', $context)) {
        $DB->delete_records('learnguard_audio_clips', ['attemptid' => $attemptid]);
        redirect(new moodle_url('/mod/learnguard/attemptdetail.php', ['id' => $id, 'attempt' => $attemptid]),
            'All audio clips deleted.', null, \core\output\notification::NOTIFY_SUCCESS);
    }
    if ($postaction === 'deletesnaps' && has_capability('mod/learnguard:manage', $context)) {
        $DB->delete_records_select('learnguard_snapshots',
            "attemptid = :aid AND snapshot_type != 'environment_recording'", ['aid' => $attemptid]);
        redirect(new moodle_url('/mod/learnguard/attemptdetail.php', ['id' => $id, 'attempt' => $attemptid]),
            'Webcam snapshots deleted.', null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

echo $OUTPUT->header();
echo learnguard_nav_bar($id, 'Attempt Detail', true,
    (new moodle_url('/mod/learnguard/report.php', ['id' => $id]))->out(false));

// Quick action buttons.
echo html_writer::start_div('d-flex gap-2 flex-wrap mb-3');
echo html_writer::link(
    new moodle_url('/mod/learnguard/sessionplayback.php', ['id' => $id, 'attempt' => $attemptid]),
    '▶ Session Playback', ['class' => 'btn btn-sm btn-primary']
);
echo html_writer::link(
    new moodle_url('/mod/learnguard/report_pdf.php', ['id' => $id, 'attempt' => $attemptid]),
    '📄 Download PDF Report', ['class' => 'btn btn-sm btn-outline-secondary']
);
echo html_writer::link(
    new moodle_url('/mod/learnguard/incidents.php', ['id' => $id, 'attempt' => $attemptid]),
    '⚠️ Incidents', ['class' => 'btn btn-sm btn-outline-warning']
);
if ($attempt->state === 'finished' && has_capability('mod/learnguard:grade', $context)) {
    echo html_writer::link(
        new moodle_url('/mod/learnguard/gradeessays.php', ['id' => $id, 'attempt' => $attemptid]),
        '📝 Grade Essays', ['class' => 'btn btn-sm btn-outline-success']
    );
}
// Pause / resume violation scoring (proctor action for in-progress attempts).
if ($attempt->state === 'inprogress' && has_capability('mod/learnguard:liveproctor', $context)) {
    $vioSus = !empty($attempt->violations_suspended);
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => '', 'style' => 'display:inline-block;']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'togglevio']);
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'value' => $vioSus ? '▶ Resume Violations' : '⏸ Pause Violations',
        'class' => 'btn btn-sm ' . ($vioSus ? 'btn-success' : 'btn-warning'),
        'title' => $vioSus ? 'Resume violation scoring for this learner' : 'Pause all violation scoring for this learner',
        'onclick' => 'return confirm("' . ($vioSus ? 'Resume violation scoring?' : 'Pause violation scoring? New violations will still be logged but will not add to the risk score.') . '");',
    ]);
    echo html_writer::end_tag('form');
}
echo html_writer::end_div();

// Integrity score badge.
$riskcolours = ['low' => '#28a745', 'medium' => '#fd7e14', 'high' => '#dc3545'];
$rc = $riskcolours[$attempt->risk_level] ?? '#6c757d';

echo html_writer::start_div('d-flex align-items-start gap-3 mb-4');

// Student info.
echo html_writer::start_div('flex-grow-1');
echo html_writer::tag('h2', fullname($student), ['style' => 'margin-bottom:4px;']);
echo html_writer::tag('p', $student->email . ' &mdash; Attempt #' . $attempt->attempt, ['style' => 'color:#6c757d;margin:0;']);
echo html_writer::tag('p', 'State: <strong>' . ucfirst($attempt->state) . '</strong> &nbsp;|&nbsp; ' .
    'Started: <strong>' . userdate($attempt->timestart) . '</strong>' .
    (!empty($attempt->timefinish) ? ' &nbsp;|&nbsp; Finished: <strong>' . userdate($attempt->timefinish) . '</strong>' : ''),
    ['style' => 'color:#6c757d;margin-top:4px;']
);
echo html_writer::end_div();

// Risk badge.
echo html_writer::start_div('text-center', ['style' => 'flex-shrink:0;']);
echo html_writer::tag('div', $attempt->risk_score, [
    'class' => 'lg-grade-badge',
    'style' => 'background:' . $rc . ';margin:0 auto 4px;font-size:20px;width:72px;height:72px;',
]);
echo html_writer::tag('div', 'Risk Score', ['style' => 'font-size:12px;color:#6c757d;font-weight:600;']);
echo html_writer::tag('div', strtoupper($attempt->risk_level), [
    'style' => 'font-size:11px;color:' . $rc . ';font-weight:700;letter-spacing:1px;',
]);
echo html_writer::end_div();

// Grade badge.
if (!empty($attempt->sumgrades)) {
    $pct = $instance->grade > 0 ? round(($attempt->sumgrades / $instance->grade) * 100) : 0;
    $gc  = $pct >= 70 ? '#28a745' : ($pct >= 50 ? '#fd7e14' : '#dc3545');
    echo html_writer::start_div('text-center', ['style' => 'flex-shrink:0;']);
    echo html_writer::tag('div', $pct . '%', [
        'class' => 'lg-grade-badge',
        'style' => 'background:' . $gc . ';margin:0 auto 4px;width:72px;height:72px;',
    ]);
    echo html_writer::tag('div', 'Grade', ['style' => 'font-size:12px;color:#6c757d;font-weight:600;']);
    echo html_writer::tag('div', round($attempt->sumgrades, 1) . ' / ' . $instance->grade, [
        'style' => 'font-size:11px;color:#6c757d;',
    ]);
    echo html_writer::end_div();
}

echo html_writer::end_div(); // d-flex

// ---- 360° Environment Recordings — dedicated management section ----
$env_recordings = $DB->get_records_select(
    'learnguard_snapshots',
    "attemptid = :aid AND snapshot_type = 'environment_recording'",
    ['aid' => $attemptid],
    'timemodified ASC'
);

if (!empty($env_recordings)) {
    echo html_writer::start_div('', ['style' =>
        'background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:16px 20px;margin-bottom:20px;']);
    echo html_writer::start_div('d-flex align-items-center justify-content-between mb-2');
    echo html_writer::tag('h4', '🔭 360° Environment Recordings (' . count($env_recordings) . ')',
        ['style' => 'margin:0;font-size:16px;']);

    // Bulk delete 360° recordings button.
    if (has_capability('mod/learnguard:manage', $context)) {
        echo html_writer::start_tag('form', ['method' => 'post']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',        'value' => $id]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',   'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'attempt',   'value' => $attemptid]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',    'value' => 'delete360']);
        echo html_writer::empty_tag('input', [
            'type' => 'submit', 'value' => '🗑 Delete All 360° Recordings',
            'class' => 'btn btn-sm btn-outline-danger',
            'onclick' => 'return confirm("Delete all 360° recordings for this attempt?");',
        ]);
        echo html_writer::end_tag('form');
    }
    echo html_writer::end_div();

    echo html_writer::tag('p',
        'These recordings were made during the pre-exam environment scan. ' .
        'Review them to verify the student\'s exam environment.',
        ['style' => 'color:#6c757d;font-size:13px;margin-bottom:10px;']);

    echo html_writer::start_div('d-flex flex-wrap gap-2');
    $reci = 0;
    foreach ($env_recordings as $rec) {
        $reci++;
        $src   = (new moodle_url('/mod/learnguard/snapshot.php', ['id' => $rec->id, 'sesskey' => sesskey()]))->out(false);
        $label = 'Environment scan #' . $reci . ' — ' . userdate($rec->timemodified);
        echo html_writer::start_div('', ['style' => 'text-align:center;']);
        $isVid360 = ($rec->snapshot_type === '360_video');
        if ($isVid360) {
            echo '<video src="' . $src . '" controls style="width:200px;height:150px;object-fit:cover;border-radius:6px;border:2px solid #ffc107;cursor:pointer;" title="' . s($label) . '"></video>';
        } else {
            echo '<img src="' . $src . '" onclick="lgLightbox(\'' . $src . '\',\'' . addslashes($label) . '\')" ' .
                 'style="width:200px;height:150px;object-fit:cover;border-radius:6px;border:2px solid #ffc107;cursor:zoom-in;" ' .
                 'title="' . s($label) . '">';
        }
        echo html_writer::tag('div', 'Scan #' . $reci, ['style' => 'font-size:11px;color:#6c757d;margin-top:3px;']);
        echo html_writer::end_div();
    }
    echo html_writer::end_div();
    echo html_writer::end_div();
}

// ---- Webcam Snapshots + Screen Captures (separated by type) ----
$snapshots = $DB->get_records_select(
    'learnguard_snapshots',
    "attemptid = :aid AND snapshot_type NOT IN ('environment_recording','360_video')",
    ['aid' => $attemptid],
    'timemodified ASC'
);
// Separate webcam from screen captures. is_screen (added after the bug below was
// found) is authoritative when set — snapshot_type is a reason/evidence tag ('periodic',
// 'violation_face_missing', etc.), NOT an image-source indicator: when the webcam was
// unavailable, a periodic/violation capture silently fell back to a screen frame but
// kept its original reason tag, so a straight snapshot_type==='screen_capture' check
// miscategorised those screen frames as webcam snapshots. Rows from before is_screen
// existed default to 0, so the string check is kept as a fallback for those.
$isScreenSnap = fn($s) => !empty($s->is_screen) || $s->snapshot_type === 'screen_capture';
$webcamSnaps = array_filter($snapshots, fn($s) => !$isScreenSnap($s));
$screenSnaps = array_filter($snapshots, $isScreenSnap);
$snapcount = count($snapshots);
if (!empty($snapshots)) {
    echo html_writer::start_div('d-flex justify-content-between align-items-center mb-2');
    echo html_writer::tag('h4',
        '📷 Webcam Snapshots (' . count($webcamSnaps) . ') · 🖥 Screen Captures (' . count($screenSnaps) . ')',
        ['style' => 'margin:0;font-size:16px;']);
    if (has_capability('mod/learnguard:suspendattempt', $context) && $attempt->state === 'suspended') {
        echo html_writer::tag('small',
            'Tick images here to attach as evidence when confirming this suspension below.',
            ['class' => 'text-muted']);
    }

    // Delete all snapshots button.
    if (has_capability('mod/learnguard:manage', $context)) {
        echo html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:inline;']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',      'value' => $id]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'attempt', 'value' => $attemptid]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'deletesnaps']);
        echo html_writer::empty_tag('input', [
            'type' => 'submit', 'value' => '🗑 Delete All Snapshots',
            'class' => 'btn btn-sm btn-outline-danger',
            'onclick' => 'return confirm("Delete all webcam snapshots for this attempt?");',
        ]);
        echo html_writer::end_tag('form');
    }
    echo html_writer::end_div();

    // Lightbox overlay.
    echo html_writer::tag('div', '',
        ['id' => 'lg-lightbox', 'onclick' => 'this.style.display="none"',
         'style' => 'display:none;position:fixed;inset:0;z-index:999999;background:rgba(0,0,0,0.92);' .
                    'align-items:center;justify-content:center;cursor:zoom-out;']);
    ?>
    <?php
    // Hidden form for single-event dismiss (can't nest inside bulk form).
    echo html_writer::start_tag('form', [
        'method' => 'post', 'action' => '', 'id' => 'single-dismiss-form', 'style' => 'display:none;',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',  'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',   'value' => 'override_event']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'eventid',  'id'    => 'single-dismiss-eventid', 'value' => '']);
    echo html_writer::end_tag('form');
    ?>
    <script>
    function lgSingleDismiss(evid, impact) {
        if (!confirm('Dismiss this event and deduct +' + impact + ' from the risk score?')) { return; }
        document.getElementById('single-dismiss-eventid').value = evid;
        document.getElementById('single-dismiss-form').submit();
    }
    function lgToggleAllDismiss(cb) {
        document.querySelectorAll('.bulk-dismiss-cb').forEach(function(el) {
            el.checked = cb.checked;
        });
    }
    function lgLightbox(src, label) {
        var lb = document.getElementById('lg-lightbox');
        lb.innerHTML = '<div style="text-align:center;"><img src="' + src + '" style="max-width:90vw;max-height:85vh;border-radius:8px;box-shadow:0 4px 32px rgba(0,0,0,0.8);">' +
            '<div style="color:#fff;margin-top:10px;font-size:14px;">' + label + '</div>' +
            '<div style="color:#aaa;font-size:12px;margin-top:4px;">Click anywhere to close</div></div>';
        lb.style.display = 'flex';
    }
    </script>
    <?php

    // Helper to render a snapshot grid.
    $renderSnapGrid = function(array $items, string $lightboxGroup) {
        if (empty($items)) { return; }
        echo html_writer::start_div('', ['style' =>
            'display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px;margin-bottom:24px;']);
        $i = 0;
        foreach ($items as $snap) {
            $i++;
            $src   = (new moodle_url('/mod/learnguard/snapshot.php',
                ['id' => $snap->id, 'sesskey' => sesskey()]))->out(false);
            $label = userdate($snap->timemodified) . ' — ' . ucfirst(str_replace('_', ' ', $snap->snapshot_type));
            echo html_writer::start_div('', ['style' => 'text-align:center;position:relative;']);
            echo '<input type="checkbox" class="sm-evidence-chk" data-snapid="' . (int)$snap->id . '" ' .
                 'style="position:absolute;top:4px;left:4px;width:18px;height:18px;z-index:2;cursor:pointer;">';
            echo '<img src="' . $src . '" onclick="lgLightbox(\'' . $src . '\',\'' . addslashes($label) . '\')" ' .
                 'loading="lazy" ' .
                 'style="width:100%;height:120px;object-fit:cover;border-radius:6px;border:1px solid #dee2e6;cursor:zoom-in;" ' .
                 'title="' . s($label) . '">';
            echo html_writer::tag('div', '#' . $i . ' · ' . userdate($snap->timemodified, '%H:%M:%S'),
                ['style' => 'font-size:11px;color:#6c757d;margin-top:3px;']);
            echo html_writer::end_div();
        }
        echo html_writer::end_div();
    };

    // Webcam section.
    if (!empty($webcamSnaps)) {
        echo html_writer::tag('h5', '📷 Webcam Snapshots (' . count($webcamSnaps) . ')',
            ['style' => 'font-size:14px;font-weight:700;color:#495057;margin:12px 0 8px;']);
        $renderSnapGrid($webcamSnaps, 'webcam-' . $attemptid);
    } else {
        echo html_writer::tag('p',
            '📷 No webcam snapshots recorded. Captured every 15s during active proctored attempts.',
            ['class' => 'text-muted small mb-2']);
    }

    // Screen captures section.
    echo html_writer::tag('h5', '🖥 Screen Captures (' . count($screenSnaps) . ')',
        ['style' => 'font-size:14px;font-weight:700;color:#495057;margin:12px 0 8px;']);
    if (!empty($screenSnaps)) {
        $renderSnapGrid($screenSnaps, 'screen-' . $attemptid);
    } else {
        echo html_writer::tag('p',
            '🖥 No screen captures recorded. Captured every 15s when screen sharing is active.',
            ['class' => 'text-muted small mb-2']);
    }
}

// Proctoring events.
$events = $DB->get_records('learnguard_events', ['attemptid' => $attemptid], 'timemodified ASC');

// Violation evidence: snapshots captured automatically when a scored violation fired.
// Stored as a flat list; correlated per-event by type AND timestamp (±15 s) below.
$violationSnaps = array_values($DB->get_records_sql(
    "SELECT s.id, s.snapshot_type, s.timemodified
       FROM {learnguard_snapshots} s
      WHERE s.attemptid = :aid AND " . $DB->sql_like('s.snapshot_type', ':prefix') . "
   ORDER BY s.timemodified ASC",
    ['aid' => $attemptid, 'prefix' => $DB->sql_like_escape('violation_') . '%']
));

// Audio clips, for matching into the Evidence column below (same ±15s proximity pattern).
$evidenceAudioClips = [];
if ($DB->get_manager()->table_exists('learnguard_audio_clips')) {
    $evidenceAudioClips = array_values($DB->get_records(
        'learnguard_audio_clips', ['attemptid' => $attemptid], 'timecreated ASC',
        'id, timecreated, audiodata, duration, noise_level'
    ));
}

// ---- Risk Score Trend sparkline ----
$sparkpoints = [[(int)$attempt->timestart, 0]];
$cum = 0;
foreach ($events as $ev) {
    if ($ev->score_impact > 0) {
        $cum += (int)$ev->score_impact;
        $sparkpoints[] = [(int)$ev->timemodified, $cum];
    }
}
$endtime = !empty($attempt->timefinish) ? (int)$attempt->timefinish : time();
if ($endtime <= $sparkpoints[count($sparkpoints) - 1][0]) {
    $endtime = $sparkpoints[count($sparkpoints) - 1][0] + 1;
}
$sparkpoints[] = [$endtime, (int)$attempt->risk_score];

$sparkline = learnguard_risk_sparkline($sparkpoints);
if ($sparkline) {
    echo html_writer::start_div('', ['style' =>
        'background:#fff;border:1px solid #dee2e6;border-radius:8px;padding:12px 16px;margin-bottom:20px;']);
    echo html_writer::tag('div', '📈 Risk Score Trend', ['style' => 'font-size:13px;font-weight:700;color:#495057;margin-bottom:6px;']);
    echo $sparkline;
    echo html_writer::tag('div',
        '<span style="color:#fd7e14;">┄┄┄</span> Medium threshold (30) &nbsp; ' .
        '<span style="color:#dc3545;">┄┄┄</span> High threshold (60)',
        ['style' => 'font-size:11px;color:#6c757d;margin-top:4px;']);
    echo html_writer::end_div();
}

if (!empty($events)) {
    $canOverride = has_capability('mod/learnguard:liveproctor', $context);
    echo html_writer::tag('h4', '⚠️ Proctoring Events');

    // Bulk-dismiss form wraps the entire table so checkboxes submit together.
    if ($canOverride) {
        echo html_writer::start_tag('form', [
            'method' => 'post', 'action' => '', 'id' => 'bulk-dismiss-form',
        ]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'bulk_dismiss_events']);
        echo '<div style="margin-bottom:8px;display:flex;gap:8px;align-items:center;">';
        echo '<label style="font-size:13px;font-weight:600;cursor:pointer;">'
           . '<input type="checkbox" id="bulk-dismiss-all" onchange="lgToggleAllDismiss(this)" style="margin-right:4px;">'
           . ' Select All Dismissible</label>';
        echo html_writer::empty_tag('input', [
            'type' => 'submit', 'value' => '✕ Dismiss Selected',
            'class' => 'btn btn-sm btn-outline-danger',
            'onclick' => 'return document.querySelectorAll(".bulk-dismiss-cb:checked").length > 0 || (alert("Select at least one event to dismiss."), false);',
        ]);
        echo '</div>';
    }

    echo html_writer::start_tag('table', ['class' => 'generaltable w-100 mb-4']);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    if ($canOverride) { echo html_writer::tag('th', '', ['style' => 'width:32px;padding:8px 12px;']); }
    $hdrs = ['Time', 'Event', 'Severity', 'Score Impact', 'Detail', 'Evidence'];
    if ($canOverride) { $hdrs[] = 'Action'; }
    foreach ($hdrs as $h) {
        echo html_writer::tag('th', $h, ['style' => 'padding:8px 12px;font-size:13px;']);
    }
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    $sevcols = ['low' => '#28a745', 'medium' => '#fd7e14', 'high' => '#dc3545'];
    foreach ($events as $ev) {
        $sevcol  = $sevcols[$ev->severity] ?? '#6c757d';
        $detail  = learnguard_format_event_data($ev->event_type, $ev->event_data ?? '');
        $isDismissible = $canOverride && empty($ev->overridden) && (int)$ev->score_impact > 0;
        echo html_writer::start_tag('tr', !empty($ev->overridden) ? ['style' => 'opacity:.55;'] : []);
        if ($canOverride) {
            echo html_writer::tag('td', $isDismissible
                ? html_writer::empty_tag('input', [
                    'type' => 'checkbox', 'name' => 'eventids[]',
                    'value' => $ev->id, 'class' => 'bulk-dismiss-cb',
                  ])
                : '',
                ['style' => 'text-align:center;']);
        }
        echo html_writer::tag('td', userdate($ev->timemodified, '%H:%M:%S'),
            ['style' => 'white-space:nowrap;font-size:13px;color:#495057;']);
        echo html_writer::tag('td',
            html_writer::tag('strong', ucwords(str_replace('_', ' ', $ev->event_type)),
                ['style' => 'font-size:13px;']),
            ['style' => 'max-width:200px;']);
        echo html_writer::tag('td', html_writer::tag('span', ucfirst($ev->severity), [
            'style' => 'color:' . $sevcol . ';font-weight:700;font-size:13px;',
        ]));
        echo html_writer::tag('td',
            $ev->score_impact > 0
                ? html_writer::tag('span', '+' . $ev->score_impact, ['style' => 'color:#dc3545;font-weight:700;'])
                : html_writer::tag('span', '0', ['style' => 'color:#6c757d;']),
            ['style' => 'text-align:center;']);
        echo html_writer::tag('td',
            html_writer::tag('span', $detail, ['style' => 'font-size:12px;color:#495057;']),
            ['style' => 'max-width:220px;']);

        // Evidence column — match snapshots by type, then narrow to the closest capture
        // burst in time. A flat ±15s window alone can pull in evidence from a DIFFERENT
        // occurrence of the same recurring event type (e.g. Face Missing / Fullscreen Exit
        // firing more than once within 15s of each other) — showing the wrong photo next
        // to this row. Anchor on the single closest snapshot, then only include other
        // same-type snapshots within a few seconds of THAT one (the webcam+screen pair
        // from one capture burst share nearly the same timestamp).
        $evSnapPrefix = 'violation_' . $ev->event_type;
        $evTime       = (int)$ev->timemodified;
        $evSnapCandidates = array_values(array_filter($violationSnaps, function($vs) use ($evSnapPrefix, $evTime) {
            return $vs->snapshot_type === $evSnapPrefix && abs($vs->timemodified - $evTime) <= 15;
        }));
        $evSnaps = [];
        if (!empty($evSnapCandidates)) {
            usort($evSnapCandidates, function($a, $b) use ($evTime) {
                return abs($a->timemodified - $evTime) <=> abs($b->timemodified - $evTime);
            });
            $anchorTime = $evSnapCandidates[0]->timemodified;
            foreach ($evSnapCandidates as $vs) {
                if (abs($vs->timemodified - $anchorTime) <= 3) {
                    $evSnaps[] = $vs;
                }
            }
        }
        // Audio clips are matched to the triggering event by proximity too (recording
        // starts within a second or two of the audio_clip_started event being logged).
        $evAudio = [];
        if (in_array($ev->event_type, ['audio_clip_started', 'audio_clip'], true)) {
            $evAudio = array_filter($evidenceAudioClips, function($ac) use ($evTime) {
                return abs((int)$ac->timecreated - $evTime) <= 15;
            });
        }

        $evEvidHtml = '';
        if (!empty($evSnaps) || !empty($evAudio)) {
            $evEvidHtml = '<div style="display:flex;gap:4px;flex-wrap:wrap;align-items:center;">';
            foreach ($evSnaps as $vs) {
                $thumbUrl = (new moodle_url('/mod/learnguard/snapshot.php',
                    ['id' => $vs->id, 'sesskey' => sesskey()]))->out(false);
                $label    = userdate($vs->timemodified, '%H:%M:%S');
                $evEvidHtml .= '<a href="' . $thumbUrl . '" target="_blank" title="Evidence: ' . s($label) . '"' .
                    ' onclick="lgLightbox(\'' . s($thumbUrl) . '\',\'Evidence @ ' . s($label) . '\');return false;">' .
                    '<img src="' . $thumbUrl . '" style="width:52px;height:40px;object-fit:cover;' .
                    'border-radius:4px;border:1px solid #dee2e6;" alt="evidence"></a>';
            }
            foreach ($evAudio as $ac) {
                $evEvidHtml .= '<audio controls preload="none" style="height:28px;width:150px;" title="Audio evidence">' .
                    '<source src="data:audio/webm;base64,' . $ac->audiodata . '" type="audio/webm">' .
                    '<source src="data:audio/ogg;base64,' . $ac->audiodata . '" type="audio/ogg">' .
                    '</audio>';
            }
            $evEvidHtml .= '</div>';
        } else {
            $evEvidHtml = '<span style="color:#ccc;font-size:11px;">—</span>';
        }
        echo html_writer::tag('td', $evEvidHtml, ['style' => 'min-width:100px;']);

        if ($canOverride) {
            if (!empty($ev->overridden)) {
                echo html_writer::tag('td',
                    html_writer::tag('span', '✓ Dismissed', ['style' => 'color:#28a745;font-size:11px;font-weight:700;']));
            } elseif ((int)$ev->score_impact > 0) {
                echo html_writer::start_tag('td');
                echo html_writer::tag('button', '✕ Dismiss', [
                    'type'    => 'button',
                    'class'   => 'btn btn-xs btn-outline-secondary',
                    'style'   => 'font-size:11px;padding:2px 7px;',
                    'title'   => 'Remove this event and deduct its score from the risk total',
                    'onclick' => 'lgSingleDismiss(' . (int)$ev->id . ',' . (int)$ev->score_impact . ')',
                ]);
                echo html_writer::end_tag('td');
            } else {
                echo html_writer::tag('td', '—', ['style' => 'color:#6c757d;font-size:12px;']);
            }
        }
        echo html_writer::end_tag('tr');
    }
    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
    if ($canOverride) {
        echo html_writer::end_tag('form');
    }
} else {
    echo html_writer::div('No proctoring events recorded.', 'alert alert-success mb-4');
}

// ---- Session Timeline ----
$allevents = $DB->get_records('learnguard_events', ['attemptid' => $attemptid], 'timemodified ASC');
if (!empty($allevents)) {
    echo html_writer::tag('h4', '📅 Session Timeline');
    $sevcols   = ['low' => '#28a745', 'medium' => '#fd7e14', 'high' => '#dc3545'];
    $starttime = (int)$attempt->timestart;

    echo html_writer::start_div('', ['style' => 'position:relative;margin-left:24px;margin-bottom:24px;']);
    // Vertical line.
    echo html_writer::tag('div', '', ['style' =>
        'position:absolute;left:0;top:0;bottom:0;width:2px;background:#dee2e6;']);

    foreach ($allevents as $ev) {
        $secsFromStart = (int)$ev->timemodified - $starttime;
        $timeLabel     = ($secsFromStart >= 0 ? '+' : '') . gmdate('H:i:s', abs($secsFromStart));
        $sev     = $ev->severity ?? 'low';
        $col     = $sevcols[$sev] ?? '#6c757d';

        echo html_writer::start_div('', ['style' =>
            'position:relative;padding:8px 0 8px 28px;']);
        // Dot.
        echo html_writer::tag('div', '', ['style' =>
            'position:absolute;left:-6px;top:14px;width:14px;height:14px;border-radius:50%;' .
            'background:' . $col . ';border:2px solid #fff;']);
        // Content.
        echo html_writer::start_div('', ['style' =>
            'background:#f8f9fa;border-left:3px solid ' . $col . ';border-radius:0 6px 6px 0;' .
            'padding:8px 12px;display:flex;gap:12px;align-items:flex-start;']);
        echo html_writer::tag('div',
            userdate($ev->timemodified, '%H:%M:%S') . ' <small style="color:#6c757d;">(' . $timeLabel . ')</small>',
            ['style' => 'white-space:nowrap;font-size:12px;flex-shrink:0;']);
        echo html_writer::start_div('', ['style' => 'flex:1;']);
        echo html_writer::tag('div',
            html_writer::tag('span', strtoupper($sev), [
                'style' => 'background:' . $col . ';color:#fff;padding:1px 6px;border-radius:3px;' .
                           'font-size:10px;font-weight:700;margin-right:6px;',
            ]) . ' ' . str_replace('_', ' ', ucfirst($ev->event_type)),
            ['style' => 'font-weight:600;font-size:13px;']);
        $evDetail = learnguard_format_event_data($ev->event_type, $ev->event_data ?? '');
        if ($evDetail && $evDetail !== '—') {
            echo html_writer::tag('div',
                html_writer::tag('span', $evDetail, ['style' => 'font-size:11px;color:#6c757d;']),
                ['style' => 'margin-top:2px;']);
        }
        echo html_writer::end_div();
        echo html_writer::end_div();
        echo html_writer::end_div();
    }
    echo html_writer::end_div();
}

// Suspend/reinstate actions (for authorised users).
if (has_capability('mod/learnguard:suspendattempt', $context) && $attempt->state === 'inprogress') {
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => 'attemptdetail.php', 'class' => 'mb-3']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $id]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'attempt', 'value' => $attemptid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'suspend']);
    echo html_writer::empty_tag('input', [
        'type'    => 'submit',
        'value'   => '⛔ Suspend This Attempt',
        'class'   => 'btn btn-danger',
        'onclick' => 'return confirm("Are you sure you want to suspend this attempt?");',
    ]);
    echo html_writer::end_tag('form');
}

// Confirm Suspension (with reason + evidence, emailed to the student) — shown once the
// attempt is already suspended, as the final review step. Separate from the plain
// "Confirm Suspension" bulk action on proctorview.php's Suspended tab (which just sets
// is_flagged=1 with no student-facing message); this one also notifies the student with
// the proctor's stated reason and any evidence images ticked in the section above.
if (has_capability('mod/learnguard:suspendattempt', $context) && $attempt->state === 'suspended') {
    echo '<button type="button" class="btn btn-danger mb-3" onclick="document.getElementById(\'sm-confirm-modal\').style.display=\'flex\';">'
       . '🚫 Confirm Suspension (Notify Student)</button>';
    if (has_capability('moodle/site:config', context_system::instance())) {
        echo ' <a href="' . (new moodle_url('/mod/learnguard/suspensionnotices.php'))->out(false) .
             '" class="btn btn-outline-secondary btn-sm mb-3">📋 View Suspension Notices Log</a>';
    }

    echo '<div id="sm-confirm-modal" style="display:none;position:fixed;inset:0;z-index:99999;' .
         'background:rgba(0,0,0,0.6);align-items:center;justify-content:center;font-family:inherit;">' .
         '<div style="background:#fff;border-radius:10px;padding:24px;max-width:480px;width:92%;box-shadow:0 8px 32px rgba(0,0,0,0.4);">' .
         '<h4 style="margin-top:0;">🚫 Confirm Suspension</h4>' .
         '<p class="text-muted" style="font-size:13px;">This finalises the suspension and emails the student with your ' .
         'reason and any evidence images/audio clips ticked in the Webcam Snapshots / Screen Captures / Audio Clips ' .
         'sections above.</p>' .
         '<form method="post" action="attemptdetail.php" id="sm-confirm-form">' .
         html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $id]) .
         html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'attempt', 'value' => $attemptid]) .
         html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
         html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'confirmsuspendnotify']) .
         '<div id="sm-confirm-snapids"></div>' .
         '<div id="sm-confirm-audioids"></div>' .
         '<label style="font-weight:600;font-size:13px;display:block;margin-bottom:6px;">Reason (sent to student):</label>' .
         '<textarea name="reason" rows="4" required style="width:100%;padding:8px;border:1px solid #ced4da;' .
         'border-radius:6px;font-size:14px;margin-bottom:14px;" placeholder="e.g. Repeated fullscreen exits and a second person visible in the webcam feed."></textarea>' .
         '<div id="sm-confirm-evcount" style="font-size:12px;color:#6c757d;margin-bottom:14px;">No evidence selected.</div>' .
         '<div style="display:flex;gap:10px;">' .
         '<button type="button" class="btn btn-secondary" style="flex:1;" onclick="document.getElementById(\'sm-confirm-modal\').style.display=\'none\';">Cancel</button>' .
         '<button type="submit" class="btn btn-danger" style="flex:1;">Confirm &amp; Send Email</button>' .
         '</div></form></div></div>';

    echo '<script>
    document.addEventListener("DOMContentLoaded", function() {
        var modal = document.getElementById("sm-confirm-modal");
        if (!modal) { return; }
        var evEl = document.getElementById("sm-confirm-evcount");
        function refreshEv() {
            var nImg = document.querySelectorAll(".sm-evidence-chk:checked").length;
            var nAud = document.querySelectorAll(".sm-audio-chk:checked").length;
            if (!nImg && !nAud) {
                evEl.textContent = "No evidence images or audio clips selected — email will be sent with the reason only.";
                return;
            }
            var parts = [];
            if (nImg) { parts.push(nImg + " evidence image(s)"); }
            if (nAud) { parts.push(nAud + " audio clip(s)"); }
            evEl.textContent = parts.join(" and ") + " will be attached.";
        }
        document.addEventListener("change", function(e) {
            if (e.target && e.target.classList &&
                (e.target.classList.contains("sm-evidence-chk") || e.target.classList.contains("sm-audio-chk"))) {
                refreshEv();
            }
        });
        document.getElementById("sm-confirm-form").addEventListener("submit", function() {
            var snapTargets = document.getElementById("sm-confirm-snapids");
            snapTargets.innerHTML = "";
            document.querySelectorAll(".sm-evidence-chk:checked").forEach(function(c) {
                var inp = document.createElement("input");
                inp.type = "hidden"; inp.name = "snapids[]"; inp.value = c.getAttribute("data-snapid");
                snapTargets.appendChild(inp);
            });
            var audioTargets = document.getElementById("sm-confirm-audioids");
            audioTargets.innerHTML = "";
            document.querySelectorAll(".sm-audio-chk:checked").forEach(function(c) {
                var inp = document.createElement("input");
                inp.type = "hidden"; inp.name = "audioids[]"; inp.value = c.getAttribute("data-clipid");
                audioTargets.appendChild(inp);
            });
        });
        refreshEv();
    });
    </script>';
}

// Process suspend action.
if (
    data_submitted() &&
    confirm_sesskey() &&
    optional_param('action', '', PARAM_ALPHA) === 'suspend' &&
    has_capability('mod/learnguard:suspendattempt', $context)
) {
    $DB->set_field('learnguard_attempts', 'state', 'suspended', ['id' => $attemptid]);
    $DB->set_field('learnguard_attempts', 'risk_score_at_suspend', $attempt->risk_score, ['id' => $attemptid]);
    $DB->set_field('learnguard_attempts', 'time_last_suspended', time(), ['id' => $attemptid]);
    redirect(new moodle_url('/mod/learnguard/attemptdetail.php', ['id' => $id, 'attempt' => $attemptid]),
        'Attempt suspended.', null, \core\output\notification::NOTIFY_SUCCESS);
}

// Process confirm-suspension-with-notification action.
if (
    data_submitted() &&
    confirm_sesskey() &&
    optional_param('action', '', PARAM_ALPHA) === 'confirmsuspendnotify' &&
    has_capability('mod/learnguard:suspendattempt', $context)
) {
    $reason   = trim(required_param('reason', PARAM_TEXT));
    $snapIds  = optional_param_array('snapids', [], PARAM_INT);
    $audioIds = optional_param_array('audioids', [], PARAM_INT);
    $DB->set_field('learnguard_attempts', 'is_flagged', 1, ['id' => $attemptid]);
    $mailSent = learnguard_notify_student_suspension_confirmed($attemptid, $reason, $snapIds, $audioIds);
    redirect(new moodle_url('/mod/learnguard/attemptdetail.php', ['id' => $id, 'attempt' => $attemptid]),
        $mailSent
            ? 'Suspension confirmed and student notified by email.'
            : 'Suspension confirmed, but the notification email failed to send — see the Suspension Notices log for details.',
        null,
        $mailSent ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING);
}

// ── Audio Clips Section ─────────────────────────────────────────────────────
$audioclips = [];
if ($DB->get_manager()->table_exists('learnguard_audio_clips')) {
    $audioclips = $DB->get_records('learnguard_audio_clips', ['attemptid' => $attemptid], 'timecreated ASC');
}

if (!empty($audioclips) || true) { // Always show section so proctors know it exists.
    echo html_writer::start_div('', ['style' =>
        'border:2px solid #6f42c1;border-radius:10px;margin-bottom:20px;overflow:hidden;']);
    echo html_writer::start_div('', ['style' =>
        'background:#6f42c1;color:#fff;padding:12px 18px;' .
        'display:flex;justify-content:space-between;align-items:center;']);
    echo html_writer::tag('strong', '🎤 Audio Clips — Noise Triggered (' . count($audioclips) . ')');

    if (!empty($audioclips) && has_capability('mod/learnguard:manage', $context)) {
        echo html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:inline;']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'deleteallaudios']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',      'value' => $id]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'attempt', 'value' => $attemptid]);
        echo html_writer::empty_tag('input', [
            'type' => 'submit', 'value' => '🗑 Delete All Audio Clips',
            'class' => 'btn btn-sm btn-outline-light',
            'onclick' => 'return confirm("Delete all audio clips for this attempt?");',
        ]);
        echo html_writer::end_tag('form');
    }
    echo html_writer::end_div();

    if (!empty($audioclips) && has_capability('mod/learnguard:suspendattempt', $context) && $attempt->state === 'suspended') {
        echo html_writer::tag('small',
            'Tick clips here to attach as evidence when confirming this suspension below.',
            ['style' => 'display:block;padding:8px 18px 0;color:#6c757d;']);
    }

    if (empty($audioclips)) {
        echo html_writer::tag('p',
            '🔇 No audio clips recorded — either noise never exceeded the threshold, or audio monitoring was disabled.',
            ['style' => 'padding:14px 18px;margin:0;color:#6c757d;font-size:13px;']);
    } else {
        echo html_writer::start_div('', ['style' => 'padding:16px;display:grid;' .
            'grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;']);
        foreach ($audioclips as $clip) {
            $ts    = userdate($clip->timecreated, '%d/%m/%y %H:%M:%S');
            $since = $clip->timecreated - $attempt->timestart;
            $rel   = gmdate('H:i:s', max(0, $since)) . ' into exam';
            $level = (int)$clip->noise_level;
            $levColour = $level > 60 ? '#dc3545' : ($level > 40 ? '#fd7e14' : '#28a745');

            echo html_writer::start_div('', ['style' =>
                'border:1px solid #dee2e6;border-radius:8px;background:#fff;' .
                'padding:12px;box-shadow:0 2px 6px rgba(0,0,0,0.07);position:relative;']);

            echo html_writer::start_div('', ['style' =>
                'display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;']);
            echo '<label style="display:flex;align-items:center;gap:6px;cursor:pointer;">' .
                 '<input type="checkbox" class="sm-audio-chk" data-clipid="' . (int)$clip->id . '" ' .
                 'style="width:16px;height:16px;cursor:pointer;">' .
                 html_writer::tag('span', $ts, ['style' => 'font-size:12px;font-weight:700;']) .
                 '</label>';
            echo html_writer::tag('span',
                '🔊 Level: ' . $level,
                ['style' => 'font-size:11px;background:' . $levColour . ';color:#fff;' .
                    'padding:2px 8px;border-radius:10px;font-weight:700;']);
            echo html_writer::end_div();

            echo html_writer::tag('p', '⏱ ' . $rel . ' · ⏱ ' . $clip->duration . 's recording',
                ['style' => 'font-size:11px;color:#6c757d;margin:0 0 8px;']);

            // HTML5 audio player.
            echo '<audio controls style="width:100%;margin-bottom:8px;">';
            echo '<source src="data:audio/webm;base64,' . $clip->audiodata . '" type="audio/webm">';
            echo '<source src="data:audio/ogg;base64,' . $clip->audiodata . '" type="audio/ogg">';
            echo 'Your browser does not support the audio element.';
            echo '</audio>';

            // Individual delete.
            if (has_capability('mod/learnguard:manage', $context)) {
                echo html_writer::start_tag('form', ['method' => 'post', 'style' => 'text-align:right;']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'deleteaudioclip']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',      'value' => $id]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'attempt', 'value' => $attemptid]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'clipid',  'value' => $clip->id]);
                echo html_writer::empty_tag('input', [
                    'type' => 'submit', 'value' => '🗑 Delete',
                    'class' => 'btn btn-sm btn-outline-danger',
                    'onclick' => 'return confirm("Delete this audio clip?");',
                ]);
                echo html_writer::end_tag('form');
            }
            echo html_writer::end_div();
        }
        echo html_writer::end_div();
    }
    echo html_writer::end_div();
}

echo $OUTPUT->footer();
