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
