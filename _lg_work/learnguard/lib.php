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
 * Required Moodle callbacks for mod_learnguard.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link       https://www.linkedin.com/in/michaeladeniran/
 */

defined('MOODLE_INTERNAL') || die();

// -----------------------------------------------------------------------
// Feature support
// -----------------------------------------------------------------------

/**
 * Declare which Moodle module features this plugin supports.
 *
 * @param string $feature FEATURE_xx constant
 * @return bool|null
 */
function learnguard_supports(string $feature): ?bool {
    switch ($feature) {
        case FEATURE_GROUPS:                  return true;
        case FEATURE_GROUPINGS:               return true;
        case FEATURE_MOD_INTRO:               return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS: return true;
        case FEATURE_COMPLETION_HAS_RULES:    return true;
        case FEATURE_GRADE_HAS_GRADE:         return true;
        case FEATURE_GRADE_OUTCOMES:          return true;
        case FEATURE_BACKUP_MOODLE2:          return true;
        case FEATURE_SHOW_DESCRIPTION:        return true;
        case FEATURE_MOD_PURPOSE:             return MOD_PURPOSE_ASSESSMENT;
        default:                              return null;
    }
}

// -----------------------------------------------------------------------
// Instance management
// -----------------------------------------------------------------------

/**
 * Called when a new LearnGuard activity is created.
 *
 * @param stdClass $data Data from the module form.
 * @param mod_learnguard_mod_form|null $mform
 * @return int  New instance ID.
 */
function learnguard_add_instance(stdClass $data, $mform = null): int {
    global $DB;

    $data->timecreated  = time();
    $data->timemodified = time();

    // Ensure boolean flags are saved correctly.
    $data->proctoring         = (int)($data->proctoring         ?? 1);
    $data->proctor_fullscreen = (int)($data->proctor_fullscreen ?? 1);
    $data->proctor_webcam     = (int)($data->proctor_webcam     ?? 1);
    $data->proctor_audio      = (int)($data->proctor_audio      ?? 1);
    $data->proctor_tabdetect  = (int)($data->proctor_tabdetect  ?? 1);
    $data->proctor_keyblock   = (int)($data->proctor_keyblock   ?? 1);
    $data->proctor_clipboard  = (int)($data->proctor_clipboard  ?? 1);
    $data->proctor_env_record = (int)($data->proctor_env_record ?? 1);
    $data->block_mobile       = (int)($data->block_mobile       ?? 1);

    $id = $DB->insert_record('learnguard', $data);
    $data->id = $id;

    learnguard_grade_item_update($data);

    // Save grade to pass if provided.
    if (isset($data->gradepass)) {
        learnguard_grade_item_update($data, null, (float)$data->gradepass);
    }

    return $id;
}

/**
 * Called when an existing LearnGuard activity is updated.
 *
 * @param stdClass $data
 * @param mod_learnguard_mod_form|null $mform
 * @return bool
 */
function learnguard_update_instance(stdClass $data, $mform = null): bool {
    global $DB;

    $data->id           = $data->instance;
    $data->timemodified = time();

    $DB->update_record('learnguard', $data);

    $gradepass = isset($data->gradepass) ? (float)$data->gradepass : null;
    learnguard_grade_item_update($data, null, $gradepass);

    return true;
}

/**
 * Called when a LearnGuard activity is deleted.
 *
 * @param int $id Instance ID.
 * @return bool
 */
function learnguard_delete_instance(int $id): bool {
    global $DB;

    if (!$instance = $DB->get_record('learnguard', ['id' => $id])) {
        return false;
    }

    // Delete all attempt data.
    $attempts = $DB->get_records('learnguard_attempts', ['learnguard' => $id]);
    foreach ($attempts as $attempt) {
        // Delete the question usage (quba).
        if ($attempt->uniqueid) {
            question_engine::delete_questions_usage_by_activity($attempt->uniqueid);
        }
        $DB->delete_records('learnguard_snapshots', ['attemptid' => $attempt->id]);
        $DB->delete_records('learnguard_events',    ['attemptid' => $attempt->id]);
    }
    $DB->delete_records('learnguard_attempts', ['learnguard' => $id]);
    $DB->delete_records('learnguard_slots',    ['learnguard' => $id]);
    $DB->delete_records('learnguard_proctors', ['learnguard' => $id]);

    // Remove grade item.
    learnguard_grade_item_delete($instance);

    $DB->delete_records('learnguard', ['id' => $id]);

    return true;
}

// -----------------------------------------------------------------------
// Branding helpers
// -----------------------------------------------------------------------

/**
 * Return the configured brand colours and name as a stdClass.
 * Always returns valid hex strings, never null.
 */
function learnguard_brand(): stdClass {
    static $brand = null;
    if ($brand !== null) { return $brand; }
    $primary = get_config('learnguard', 'brand_primary') ?: '#0f6cbf';
    $brand   = (object)[
        'primary'   => $primary,
        'accent'    => get_config('learnguard', 'brand_accent')    ?: '#28a745',
        'name'      => get_config('learnguard', 'brand_name')      ?: 'LearnGuard',
        'textcolor' => get_config('learnguard', 'brand_textcolor') ?: '#ffffff',
        'dark'      => learnguard_darken($primary, 30),
    ];
    return $brand;
}

/**
 * Return a compact navigation/breadcrumb bar for LearnGuard pages.
 *
 * @param int    $cmid     Course-module ID (0 = site-level page)
 * @param string $current  Label for the current page (shown as last breadcrumb)
 * @param bool   $back     Whether to show a ← Back button as the first item
 * @param string $backurl  Override the back URL (defaults to assessment view)
 * @return string HTML
 */
function learnguard_nav_bar(int $cmid = 0, string $current = '', bool $back = true, string $backurl = ''): string {
    $b = learnguard_brand();
    $style = 'background:#f8f9fa;border:1px solid #dee2e6;border-radius:6px;' .
             'padding:6px 14px;margin-bottom:14px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;font-size:13px;';
    $sep   = html_writer::tag('span', '›', ['style' => 'color:#adb5bd;margin:0 2px;']);
    $ls    = 'color:' . $b->primary . ';text-decoration:none;font-weight:600;';
    $html  = html_writer::start_div('', ['style' => $style]);

    if ($back) {
        $burl = $backurl ?: ($cmid > 0
            ? (new moodle_url('/mod/learnguard/view.php', ['id' => $cmid]))->out(false)
            : (new moodle_url('/mod/learnguard/siteadmin.php'))->out(false));
        $html .= html_writer::link($burl, '← Back', ['class' => 'btn btn-sm btn-outline-secondary', 'style' => 'padding:2px 10px;margin-right:6px;']);
    }

    if ($cmid > 0) {
        $html .= html_writer::link(
            new moodle_url('/mod/learnguard/view.php', ['id' => $cmid]),
            '🏠 Assessment', ['style' => $ls]
        );
        $html .= $sep;
        $html .= html_writer::link(
            new moodle_url('/mod/learnguard/admin.php', ['id' => $cmid]),
            '🛡 Admin', ['style' => $ls]
        );
    } else {
        $html .= html_writer::link(
            new moodle_url('/mod/learnguard/siteadmin.php'),
            '🌐 LearnGuard Site Admin', ['style' => $ls]
        );
    }

    if ($current) {
        $html .= $sep;
        $html .= html_writer::tag('span', $current, ['style' => 'color:#495057;font-weight:600;']);
    }

    $html .= html_writer::end_div();
    return $html;
}

/**
 * Check if a user is an assigned proctor for a LearnGuard activity.
 *
 * @param int $learnguardid  learnguard.id
 * @param int $userid        defaults to $USER->id
 * @return bool
 */
function learnguard_is_proctor(int $learnguardid, int $userid = 0): bool {
    global $DB, $USER;
    if (!$userid) { $userid = $USER->id; }
    // Per-assessment assignment.
    if ($DB->record_exists('learnguard_proctors', ['learnguard' => $learnguardid, 'userid' => $userid])) {
        return true;
    }
    // Global proctor (is_global = 1 means they proctor ALL assessments site-wide).
    return $DB->record_exists_select(
        'learnguard_proctors',
        'userid = :uid AND is_global = 1',
        ['uid' => $userid]
    );
}

/**
 * Darken a hex color by a given amount (0-255).
 */
/**
 * Push an event to the Socket.IO server so all connected clients in a room
 * receive it instantly (milliseconds) instead of waiting for the 15-second poll.
 *
 * Falls back silently if the socket server is not configured or unreachable.
 *
 * @param string $event        Socket.IO event name (e.g. 'assessment:paused').
 * @param array  $data         Payload to send with the event.
 * @param int    $learnguardid Assessment ID — used to derive the room.
 * @param int    $attemptid    Optional: send to a specific student's room.
 */
function learnguard_socket_emit(string $event, array $data = [], int $learnguardid = 0, int $attemptid = 0): void {
    $socketUrl = get_config('learnguard', 'ai_socket_url');
    $secret    = get_config('learnguard', 'ai_gateway_key') ?: '';
    if (empty($socketUrl)) { return; }

    $room = $attemptid > 0 ? "attempt:{$attemptid}" : "assessment:{$learnguardid}";
    $payload = json_encode(['room' => $room, 'event' => $event, 'data' => $data]);

    $curl = new \curl();
    $curl->setHeader(['Content-Type: application/json', 'X-API-Key: ' . $secret]);
    $curl->post(rtrim($socketUrl, '/') . '/emit', $payload);
    // Errors are silently ignored — socket is an enhancement, not a requirement.
}

/**
 * Call the AI Gateway and return decoded JSON or null on failure.
 *
 * @param string $endpoint  e.g. '/api/v1/face/detect'
 * @param array  $data      Request body (JSON-encoded).
 * @return array|null
 */
function learnguard_ai_call(string $endpoint, array $data): ?array {
    $gatewayUrl = get_config('learnguard', 'ai_gateway_url');
    $apiKey     = get_config('learnguard', 'ai_gateway_key') ?: '';
    if (empty($gatewayUrl)) { return null; }

    $curl = new \curl();
    $curl->setHeader(['Content-Type: application/json', 'X-API-Key: ' . $apiKey]);
    $resp = $curl->post(rtrim($gatewayUrl, '/') . $endpoint, json_encode($data));
    if (!$resp) { return null; }
    $decoded = json_decode($resp, true);
    return is_array($decoded) ? $decoded : null;
}

function learnguard_darken(string $hex, int $amount = 30): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6) { return '#0a4d8c'; }
    [$r, $g, $b] = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    $r = max(0, $r - $amount);
    $g = max(0, $g - $amount);
    $b = max(0, $b - $amount);
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}

/**
 * Return a styled banner div using the configured brand color.
 *
 * @param string $title   Main heading
 * @param string $sub     Sub-heading / description (optional)
 * @param string $icon    Emoji icon (optional)
 * @return string HTML
 */
function learnguard_banner(string $title, string $sub = '', string $icon = '🛡'): string {
    $b  = learnguard_brand();
    $tc = $b->textcolor;
    return html_writer::start_div('lg-banner', ['style' =>
        'background:linear-gradient(135deg,' . $b->primary . ',' . $b->dark . ');' .
        'color:' . $tc . ';border-radius:8px;padding:20px 24px;margin-bottom:20px;text-align:center;']) .
        html_writer::tag('h2', $icon . ' ' . $title, ['style' => 'margin:0 0 4px;font-size:20px;text-align:center;color:' . $tc . ';']) .
        ($sub ? html_writer::tag('p', $sub, ['style' => 'margin:0;opacity:0.85;font-size:14px;text-align:center;color:' . $tc . ';']) : '') .
        html_writer::end_div();
}

// -----------------------------------------------------------------------
// Availability
// -----------------------------------------------------------------------

/**
 * Check if an assessment is currently within its availability window.
 *
 * @param stdClass $instance  learnguard row
 * @return bool
 */
function learnguard_is_available(stdClass $instance): bool {
    $now = time();
    if (!empty($instance->timeopen) && $now < (int)$instance->timeopen) {
        return false;
    }
    if (!empty($instance->timeclose) && $now > (int)$instance->timeclose) {
        return false;
    }
    return true;
}

/**
 * Human-readable availability status string.
 *
 * @param stdClass $instance
 * @return string
 */
function learnguard_availability_message(stdClass $instance): string {
    $now = time();
    if (!empty($instance->timeopen) && $now < (int)$instance->timeopen) {
        return get_string('assessmentnotopen', 'learnguard', userdate($instance->timeopen));
    }
    if (!empty($instance->timeclose) && $now > (int)$instance->timeclose) {
        return get_string('assessmentclosed', 'learnguard', userdate($instance->timeclose));
    }
    return '';
}

// -----------------------------------------------------------------------
// Gradebook integration
// -----------------------------------------------------------------------

/**
 * Create or update the grade item in the gradebook.
 *
 * @param stdClass $instance
 * @param mixed    $grades
 */
function learnguard_grade_item_update(stdClass $instance, $grades = null, ?float $gradepass = null): void {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $params = [
        'itemname'  => $instance->name,
        'gradetype' => GRADE_TYPE_VALUE,
        'grademax'  => (float)($instance->grade ?? 100),
        'grademin'  => 0,
    ];

    if ($gradepass !== null) {
        $params['gradepass'] = $gradepass;
    }

    if ($grades === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }

    grade_update('mod/learnguard', $instance->course, 'mod', 'learnguard',
        $instance->id, 0, $grades, $params);
}

/**
 * Delete the grade item from the gradebook.
 *
 * @param stdClass $instance
 */
function learnguard_grade_item_delete(stdClass $instance): void {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');
    grade_update('mod/learnguard', $instance->course, 'mod', 'learnguard',
        $instance->id, 0, null, ['deleted' => 1]);
}

/**
 * Update grades in the gradebook.
 *
 * @param stdClass $instance
 * @param int      $userid  0 = all users.
 * @param bool     $nullifnone
 */
function learnguard_update_grades(stdClass $instance, int $userid = 0, bool $nullifnone = true): void {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    // TODO: retrieve the best grade for each user and update.
    // For now, refresh the grade item.
    learnguard_grade_item_update($instance);
}

// -----------------------------------------------------------------------
// Completion
// -----------------------------------------------------------------------

/**
 * Check custom completion rules for a user.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param int      $userid
 * @param bool     $type
 * @return bool
 */
function learnguard_get_completion_state($course, $cm, $userid, $type): bool {
    global $DB;
    $instance = $DB->get_record('learnguard', ['id' => $cm->instance], '*', MUST_EXIST);
    $finished = $DB->record_exists('learnguard_attempts', [
        'learnguard' => $instance->id, 'userid' => $userid, 'state' => 'finished',
    ]);
    return $type == COMPLETION_AND ? $finished : ($finished ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE);
}

// -----------------------------------------------------------------------
// File serving
// -----------------------------------------------------------------------

/**
 * Serve plugin files.
 */
function learnguard_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []): void {
    if ($context->contextlevel != CONTEXT_MODULE) { send_file_not_found(); }
    require_login($course, true, $cm);

    $itemid   = (int)array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    $fs   = get_file_storage();
    $file = $fs->get_file($context->id, 'mod_learnguard', $filearea, $itemid, $filepath, $filename);
    if (!$file) { send_file_not_found(); }

    send_stored_file($file, 0, 0, $forcedownload, $options);
}

// -----------------------------------------------------------------------
// Reset (used by course reset)
// -----------------------------------------------------------------------

/**
 * Return items that can be reset.
 *
 * @param stdClass $mform
 */
function learnguard_reset_course_form_definition(stdClass $mform): void {
    $mform->addElement('checkbox', 'reset_learnguard_attempts', get_string('allAttempts', 'learnguard'));
}

/**
 * Reset the module data.
 *
 * @param stdClass $data
 * @return array
 */
function learnguard_reset_userdata(stdClass $data): array {
    global $DB;
    $results = [];

    if (empty($data->reset_learnguard_attempts)) {
        return $results;
    }

    $instances = $DB->get_records('learnguard', ['course' => $data->courseid]);
    foreach ($instances as $instance) {
        $attempts = $DB->get_records('learnguard_attempts', ['learnguard' => $instance->id]);
        foreach ($attempts as $attempt) {
            if ($attempt->uniqueid) {
                question_engine::delete_questions_usage_by_activity($attempt->uniqueid);
            }
            $DB->delete_records('learnguard_snapshots', ['attemptid' => $attempt->id]);
            $DB->delete_records('learnguard_events',    ['attemptid' => $attempt->id]);
        }
        $DB->delete_records('learnguard_attempts', ['learnguard' => $instance->id]);
    }

    $results[] = [
        'component' => get_string('modulename', 'learnguard'),
        'item'      => get_string('allAttempts', 'learnguard'),
        'error'     => false,
    ];

    return $results;
}
