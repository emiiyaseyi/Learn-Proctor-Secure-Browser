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
 * Permanently delete a single attempt and every row that references it — the
 * attempt-scoped subset of learnguard_delete_instance()'s cascade, for tools (Score
 * Matrix's bulk delete) that need to remove one specific attempt rather than a whole
 * activity. Unlike voiding (state='voided', kept visible for history), this removes
 * the row entirely, so it also disappears from any report built from learnguard_attempts.
 *
 * @param int $attemptid Attempt ID.
 * @return void
 */
function learnguard_delete_single_attempt(int $attemptid): void {
    global $DB;
    $attempt = $DB->get_record('learnguard_attempts', ['id' => $attemptid], '*', IGNORE_MISSING);
    if (!$attempt) {
        return;
    }
    if ($attempt->uniqueid) {
        question_engine::delete_questions_usage_by_activity($attempt->uniqueid);
    }
    $DB->delete_records('learnguard_snapshots',         ['attemptid' => $attemptid]);
    $DB->delete_records('learnguard_events',            ['attemptid' => $attemptid]);
    $DB->delete_records('learnguard_attempt_questions', ['attemptid' => $attemptid]);
    $DB->delete_records('learnguard_responses',         ['attemptid' => $attemptid]);
    $DB->delete_records('learnguard_response_history',  ['attemptid' => $attemptid]);
    $DB->delete_records('learnguard_essay_grades',      ['attemptid' => $attemptid]);
    $DB->delete_records('learnguard_audio_clips',       ['attemptid' => $attemptid]);
    $DB->delete_records('learnguard_attempt_sections',  ['attemptid' => $attemptid]);
    $DB->delete_records('learnguard_messages',          ['attemptid' => $attemptid]);
    $DB->delete_records('learnguard_attempts',          ['id' => $attemptid]);
}

/**
 * Void every attempt under one assessment — the whole-assessment equivalent of the
 * existing per-attempt "Void" action (state='voided', history kept and still visible,
 * fully reversible). Only finished/suspended attempts are touched, same as the
 * per-attempt and bulk void actions on scorematrix.php — a currently in-progress
 * attempt (a live exam session) is deliberately left alone.
 *
 * @param int $lgid learnguard instance id
 * @return int number of attempts voided
 */
function learnguard_void_assessment_attempts(int $lgid): int {
    global $DB;
    $count = $DB->count_records_select('learnguard_attempts',
        "learnguard = :lgid AND state IN ('finished', 'suspended')", ['lgid' => $lgid]);
    if ($count > 0) {
        $DB->execute(
            "UPDATE {learnguard_attempts} SET state = 'voided'
              WHERE learnguard = :lgid AND state IN ('finished', 'suspended')",
            ['lgid' => $lgid]
        );
    }
    return $count;
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

    // Attempt-scoped tables — cascade via every attempt on this activity. This list had
    // fallen behind the schema: attempt_questions, responses, response_history,
    // essay_grades, audio_clips, and attempt_sections were never cleaned up, left as
    // orphaned rows after every previous activity deletion.
    $attempts = $DB->get_records('learnguard_attempts', ['learnguard' => $id]);
    $attemptIds = array_keys($attempts);
    foreach ($attempts as $attempt) {
        if ($attempt->uniqueid) {
            question_engine::delete_questions_usage_by_activity($attempt->uniqueid);
        }
    }
    if (!empty($attemptIds)) {
        list($insql, $inparams) = $DB->get_in_or_equal($attemptIds, SQL_PARAMS_NAMED);
        $DB->delete_records_select('learnguard_snapshots',         "attemptid $insql", $inparams);
        $DB->delete_records_select('learnguard_events',            "attemptid $insql", $inparams);
        $DB->delete_records_select('learnguard_attempt_questions', "attemptid $insql", $inparams);
        $DB->delete_records_select('learnguard_responses',         "attemptid $insql", $inparams);
        $DB->delete_records_select('learnguard_response_history',  "attemptid $insql", $inparams);
        $DB->delete_records_select('learnguard_essay_grades',      "attemptid $insql", $inparams);
        $DB->delete_records_select('learnguard_audio_clips',       "attemptid $insql", $inparams);
        $DB->delete_records_select('learnguard_attempt_sections',  "attemptid $insql", $inparams);
    }
    $DB->delete_records('learnguard_attempts', ['learnguard' => $id]);

    // Precheck-time tables — keyed by course module id, not the activity id.
    $cm = get_coursemodule_from_instance('learnguard', $id, 0, false, IGNORE_MISSING);
    if ($cm) {
        $approvalIds = $DB->get_fieldset_select('learnguard_face_approvals', 'id', 'cmid = :cmid', ['cmid' => $cm->id]);
        if (!empty($approvalIds)) {
            list($ainsql, $ainparams) = $DB->get_in_or_equal($approvalIds, SQL_PARAMS_NAMED);
            $DB->delete_records_select('learnguard_fa_messages', "approvalid $ainsql", $ainparams);
        }
        $DB->delete_records('learnguard_face_approvals',    ['cmid' => $cm->id]);
        $DB->delete_records('learnguard_precheck_sessions', ['cmid' => $cm->id]);
    }

    // Activity-scoped tables. Note: learnguard_qbank/_qbank_cats/_qbank_answers/
    // _ce_prompts and learnguard_learner_cohorts are deliberately NOT deleted here —
    // questions are a shared bank that other activities' slots may still reference,
    // and cohorts are a per-course resource independent of any one activity.
    $DB->delete_records('learnguard_slots',              ['learnguard' => $id]);
    $DB->delete_records('learnguard_pools',               ['learnguard' => $id]);
    $DB->delete_records('learnguard_proctors',            ['learnguard' => $id]);
    $DB->delete_records('learnguard_graders',             ['learnguard' => $id]);
    $DB->delete_records('learnguard_incidents',           ['learnguard' => $id]);
    $DB->delete_records('learnguard_messages',            ['learnguard' => $id]);
    $DB->delete_records('learnguard_schedules',           ['learnguard' => $id]);
    $DB->delete_records('learnguard_brand_overrides',     ['learnguard' => $id]);
    $DB->delete_records('learnguard_grading_config',      ['learnguard' => $id]);
    $DB->delete_records('learnguard_grader_assignments',  ['learnguard' => $id]);

    // Remove grade item.
    learnguard_grade_item_delete($instance);

    $DB->delete_records('learnguard', ['id' => $id]);

    return true;
}

// -----------------------------------------------------------------------
// Branding helpers
// -----------------------------------------------------------------------

// ==========================================================================
// EMAIL HELPERS
// ==========================================================================

/**
 * Send a LearnGuard notification email using Moodle's outgoing mail (SMTP).
 * Configure SMTP at: Site admin → Server → Email → Outgoing mail configuration.
 *
 * @param stdClass $toUser   Moodle user object (must have firstname, lastname, email).
 * @param string   $subject  Plain-text subject line.
 * @param string   $body     Plain-text email body.
 */
function learnguard_send_email(stdClass $toUser, string $subject, string $body): bool {
    global $CFG;
    require_once($CFG->libdir . '/moodlelib.php');

    // Use a configured "from" address or fall back to the Moodle support user.
    $fromEmail = get_config('learnguard', 'notify_from_email');
    $fromName  = get_config('learnguard', 'notify_from_name') ?: 'LearnGuard';

    if ($fromEmail) {
        $from = (object)[
            'email'     => $fromEmail,
            'firstname' => $fromName,
            'lastname'  => '',
            'maildisplay' => 1,
        ];
    } else {
        $from = \core_user::get_support_user();
    }

    // Build HTML version of the plain-text body.
    $htmlBody = '<pre style="font-family:sans-serif;font-size:14px;line-height:1.6;">' .
                nl2br(htmlspecialchars($body)) . '</pre>';

    return email_to_user($toUser, $from, $subject, $body, $htmlBody);
}

/**
 * Fill an email template with key → value replacements.
 *
 * @param string $template   Template string with {placeholders}.
 * @param array  $values     Map of placeholder → value.
 * @return string
 */
function learnguard_fill_email_template(string $template, array $values): string {
    return str_replace(array_keys($values), array_values($values), $template);
}

/**
 * Get all proctors assigned to a LearnGuard assessment (per-assessment + global + course-level).
 * Returns an array of user objects.
 *
 * @param int $lgid  learnguard instance id
 * @param int $cmid  course module id
 * @return array of stdClass user objects
 */
function learnguard_get_all_proctors(int $lgid, int $cmid): array {
    global $DB;

    $instance = $DB->get_record('learnguard', ['id' => $lgid], '*', IGNORE_MISSING);
    $courseid = $instance ? (int)$instance->course : 0;

    // Global proctors + per-assessment proctors + course-level proctors
    $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email, u.username,
                   u.mailformat, u.auth, u.confirmed, u.deleted, u.suspended
              FROM {learnguard_proctors} p
              JOIN {user} u ON u.id = p.userid AND u.deleted = 0 AND u.suspended = 0
             WHERE p.is_global = 1
                OR p.learnguard = :lgid
                OR (p.courseid = :courseid AND p.courseid > 0 AND p.learnguard = 0 AND p.is_global = 0)";

    return array_values($DB->get_records_sql($sql, ['lgid' => $lgid, 'courseid' => $courseid]));
}

/**
 * Whether a user is allowed to proctor a given LearnGuard activity — site admin, global
 * proctor, or assigned per-course/per-activity via learnguard_proctors. Used by site-wide
 * pages (proctorview.php) and their AJAX endpoints, where a Moodle per-module capability
 * check would wrongly reject a global proctor who isn't enrolled in every course.
 *
 * @param int $userid
 * @param int $learnguardid
 * @return bool
 */
function learnguard_is_proctor_for(int $userid, int $learnguardid): bool {
    if (has_capability('moodle/site:config', \context_system::instance())) {
        return true;
    }
    $proctors = learnguard_get_all_proctors($learnguardid, 0);
    foreach ($proctors as $p) {
        if ((int)$p->id === $userid) { return true; }
    }
    return false;
}

/**
 * Force-end any approved breaks whose deadline has already passed.
 *
 * The exam timer normally auto-calls end_break from the student's own browser when
 * its local countdown reaches zero (amd/src/assessment.js showBreakOverlay()). If that
 * tab is backgrounded/throttled or the call never lands, break_end is never set and the
 * attempt shows as "on break" indefinitely on proctor dashboards. Called defensively
 * wherever break state is read so it self-heals without needing the student's tab.
 *
 * @param int[] $learnguardids restrict to these activity IDs; empty = every activity
 */
function learnguard_expire_breaks(array $learnguardids = []): void {
    global $DB;
    $now = time();
    if (empty($learnguardids)) {
        $DB->execute(
            "UPDATE {learnguard_attempts} SET break_end = :now
              WHERE break_approved > 0 AND break_end = 0 AND break_deadline > 0 AND break_deadline <= :now2",
            ['now' => $now, 'now2' => $now]
        );
        return;
    }
    list($insql, $inparams) = $DB->get_in_or_equal($learnguardids, SQL_PARAMS_NAMED);
    $inparams['now']  = $now;
    $inparams['now2'] = $now;
    $DB->execute(
        "UPDATE {learnguard_attempts} SET break_end = :now
          WHERE learnguard $insql AND break_approved > 0 AND break_end = 0 AND break_deadline > 0 AND break_deadline <= :now2",
        $inparams
    );
}

/**
 * Notify all assigned proctors about an event on an attempt.
 *
 * @param int    $attemptid   attempt ID
 * @param string $eventType   short event label
 * @param string $detail      extra context for the email body
 */
function learnguard_notify_proctors_event(int $attemptid, string $eventType, string $detail = ''): void {
    global $DB;

    $attempt  = $DB->get_record('learnguard_attempts', ['id' => $attemptid], '*', IGNORE_MISSING);
    if (!$attempt) { return; }

    $cm       = get_coursemodule_from_instance('learnguard', $attempt->learnguard, 0, false, IGNORE_MISSING);
    if (!$cm)  { return; }

    $student  = $DB->get_record('user',       ['id' => $attempt->userid],      '*', IGNORE_MISSING);
    $instance = $DB->get_record('learnguard', ['id' => $attempt->learnguard],  '*', IGNORE_MISSING);
    if (!$student || !$instance) { return; }

    $proctors   = learnguard_get_all_proctors($attempt->learnguard, $cm->id);
    if (empty($proctors)) { return; }

    $detailUrl  = (new moodle_url('/mod/learnguard/attemptdetail.php',
                    ['id' => $cm->id, 'attempt' => $attempt->id]))->out(false);
    $dashUrl    = (new moodle_url('/mod/learnguard/proctordashboard.php',
                    ['id' => $cm->id]))->out(false);

    $subject = '⚠️ LearnGuard Alert: ' . str_replace('_', ' ', strtoupper($eventType)) .
               ' — ' . fullname($student);

    $body = 'LearnGuard Proctoring Alert' . "\n" .
            str_repeat('=', 40) . "\n\n" .
            'Event     : ' . str_replace('_', ' ', strtoupper($eventType)) . "\n" .
            'Student   : ' . fullname($student) . ' <' . $student->email . '>' . "\n" .
            'Assessment: ' . format_string($instance->name) . "\n" .
            'Time      : ' . userdate(time(), '%d/%m/%Y %H:%M:%S') . "\n" .
            ($detail ? 'Detail    : ' . $detail . "\n" : '') . "\n" .
            'Attempt detail: ' . $detailUrl . "\n" .
            'Live dashboard: ' . $dashUrl . "\n\n" .
            'LearnGuard — Secure Assessment Platform';

    foreach ($proctors as $proctor) {
        learnguard_send_email($proctor, $subject, $body);
    }
}

/**
 * Email all global proctors a one-day-ahead warning that a batch of evidence images
 * and/or audio clips is about to be permanently deleted by the daily GDPR retention
 * cleanup task, so they have a chance to download anything they still need first.
 * Gated by the 'learnguard/media_deletion_reminder_enabled' setting (default on).
 *
 * @param int $snapCount  number of evidence images due for deletion tomorrow
 * @param int $audioCount number of audio clips due for deletion tomorrow
 * @param int $retainDays the retention period (days) that is about to be crossed
 */
function learnguard_notify_media_deletion_reminder(int $snapCount, int $audioCount, int $retainDays): void {
    global $DB;

    $globalProctors = $DB->get_records('learnguard_proctors', ['is_global' => 1]);
    if (empty($globalProctors)) { return; }

    $storageUrl = (new moodle_url('/mod/learnguard/storage.php'))->out(false);

    $items = [];
    if ($snapCount > 0)  { $items[] = "$snapCount evidence image(s)"; }
    if ($audioCount > 0) { $items[] = "$audioCount audio clip(s)"; }

    $subject = '⚠️ LearnGuard: evidence media being deleted tomorrow';
    $body = 'LearnGuard Data Retention Notice' . "\n" .
            str_repeat('=', 40) . "\n\n" .
            'In approximately 24 hours, the following proctoring evidence will reach the ' .
            $retainDays . '-day retention limit and be PERMANENTLY deleted to free up storage:' . "\n\n" .
            '  - ' . implode("\n  - ", $items) . "\n\n" .
            'If you need to keep any of this evidence, please review and download it before ' .
            'then from the Storage Management page:' . "\n" .
            $storageUrl . "\n\n" .
            'This deletion cannot be undone once it runs.' . "\n\n" .
            'LearnGuard — Secure Assessment Platform';

    $notified = [];
    foreach ($globalProctors as $assignment) {
        if (isset($notified[$assignment->userid])) { continue; }
        $notified[$assignment->userid] = true;
        $user = $DB->get_record('user', ['id' => $assignment->userid, 'deleted' => 0, 'suspended' => 0], '*', IGNORE_MISSING);
        if ($user) {
            learnguard_send_email($user, $subject, $body);
        }
    }
}

/**
 * Bundle selected snapshot/audio-clip evidence into a ZIP for an email attachment.
 * Shared by learnguard_notify_student_reinstated() and
 * learnguard_notify_student_suspension_confirmed() so both notice types attach
 * evidence the exact same way. email_to_user() requires the attachment path to be
 * RELATIVE to $CFG->dataroot; make_request_directory() creates a dir under
 * $CFG->tempdir (which lives under dataroot on a standard install) that Moodle
 * auto-cleans at the end of this request.
 *
 * @param int   $attemptid attempt ID (evidence is scoped to this attempt only)
 * @param array $snapIds   learnguard_snapshots IDs to attach (may be empty)
 * @param array $audioIds  learnguard_audio_clips IDs to attach (may be empty)
 * @return array [string $attachRel, string $attachName, int $snapCount, int $audioCount]
 */
function learnguard_build_evidence_zip(int $attemptid, array $snapIds, array $audioIds): array {
    global $DB, $CFG;

    $attachRel  = '';
    $attachName = '';
    $snapCount  = 0;
    $audioCount = 0;
    if ((empty($snapIds) && empty($audioIds)) || !class_exists('ZipArchive')) {
        return [$attachRel, $attachName, $snapCount, $audioCount];
    }

    $zipPath = make_request_directory() . '/evidence.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
        return [$attachRel, $attachName, $snapCount, $audioCount];
    }

    $i = 0;
    if (!empty($snapIds)) {
        [$insql, $inparams] = $DB->get_in_or_equal($snapIds, SQL_PARAMS_NAMED);
        $inparams['aid'] = $attemptid;
        $snaps = $DB->get_records_select('learnguard_snapshots', "id $insql AND attemptid = :aid", $inparams);
        foreach ($snaps as $snap) {
            $binary = base64_decode($snap->snapshot_url, true);
            if ($binary === false || strlen($binary) < 10) { continue; }
            $i++;
            $ext = ($snap->snapshot_type === '360_video') ? 'webm' : 'jpg';
            $zip->addFromString('evidence_' . $i . '_' . $snap->snapshot_type . '.' . $ext, $binary);
            $snapCount++;
        }
    }
    if (!empty($audioIds) && $DB->get_manager()->table_exists('learnguard_audio_clips')) {
        [$insql, $inparams] = $DB->get_in_or_equal($audioIds, SQL_PARAMS_NAMED);
        $inparams['aid'] = $attemptid;
        $clips = $DB->get_records_select('learnguard_audio_clips', "id $insql AND attemptid = :aid", $inparams);
        foreach ($clips as $clip) {
            $binary = base64_decode($clip->audiodata, true);
            if ($binary === false || strlen($binary) < 10) { continue; }
            $i++;
            $zip->addFromString('evidence_audio_' . $i . '.webm', $binary);
            $audioCount++;
        }
    }
    $zip->close();
    if ($i > 0 && file_exists($zipPath) && strpos(realpath($zipPath), $CFG->dataroot) === 0) {
        $attachRel  = ltrim(str_replace($CFG->dataroot, '', realpath($zipPath)), '/\\');
        $attachName = 'evidence.zip';
    }
    return [$attachRel, $attachName, $snapCount, $audioCount];
}

/**
 * Email the student directly when a proctor reinstates their suspended attempt.
 * Gated by the 'notify_reinstate_enabled' admin setting (default on). Two call shapes:
 * the plain reinstate buttons (report.php, proctorview.php, admin.php) call this with
 * just $attemptid — a generic reinstated message goes out, no evidence attached; the
 * richer Reinstate & Notify flow on report.php passes a proctor-written $message plus
 * any ticked snapshot/audio evidence, mirroring the Confirm Suspension flow. Every call
 * (including a skipped one, when the setting is off) is logged to
 * learnguard_suspension_notices (noticetype='reinstate') so proctors/admins can review
 * what was sent later, via suspensionnotices.php.
 *
 * @param int    $attemptid attempt ID
 * @param string $message   optional proctor-written message, sent to the student verbatim
 * @param array  $snapIds   learnguard_snapshots IDs to attach as evidence (may be empty)
 * @param array  $audioIds  learnguard_audio_clips IDs to attach as evidence (may be empty)
 * @return bool whether the email was sent successfully
 */
function learnguard_notify_student_reinstated(
    int $attemptid, string $message = '', array $snapIds = [], array $audioIds = []
): bool {
    global $DB, $USER;

    $attempt  = $DB->get_record('learnguard_attempts', ['id' => $attemptid], '*', IGNORE_MISSING);
    if (!$attempt) { return false; }
    $cm       = get_coursemodule_from_instance('learnguard', $attempt->learnguard, 0, false, IGNORE_MISSING);
    if (!$cm)  { return false; }
    $student  = $DB->get_record('user',       ['id' => $attempt->userid],     '*', IGNORE_MISSING);
    $instance = $DB->get_record('learnguard', ['id' => $attempt->learnguard], '*', IGNORE_MISSING);
    if (!$student || !$instance) { return false; }

    $enabledCfg = get_config('learnguard', 'notify_reinstate_enabled');
    $enabled    = ($enabledCfg === false || $enabledCfg === '') ? true : (bool)$enabledCfg;
    if (!$enabled) {
        $DB->insert_record('learnguard_suspension_notices', (object)[
            'learnguard'  => $attempt->learnguard,
            'attemptid'   => $attemptid,
            'userid'      => $attempt->userid,
            'proctorid'   => $USER->id,
            'noticetype'  => 'reinstate',
            'reason'      => $message !== '' ? $message : '(reinstatement email disabled site-wide)',
            'snapcount'   => 0,
            'audiocount'  => 0,
            'mail_status' => 'skipped',
            'mail_error'  => 'Reinstatement email disabled via LearnGuard settings.',
            'timecreated' => time(),
        ]);
        return false;
    }

    $examUrl = (new moodle_url('/mod/learnguard/view.php', ['id' => $cm->id]))->out(false);
    [$attachRel, $attachName, $snapCount, $audioCount] = learnguard_build_evidence_zip($attemptid, $snapIds, $audioIds);
    $hasEvidence = ($snapCount + $audioCount) > 0;

    $subject = '✅ Your assessment attempt has been reinstated — ' . format_string($instance->name);
    $body    = 'Hello ' . fullname($student) . ',' . "\n\n" .
        'Your suspended attempt for "' . format_string($instance->name) . '" has been reviewed and reinstated ' .
        'by your proctor. You may return to the assessment and continue from where you left off.' . "\n\n" .
        'You will be asked to complete a short security check again before continuing.' . "\n\n" .
        ($message !== '' ? 'Message from your proctor:' . "\n" . $message . "\n\n" : '') .
        ($hasEvidence ? 'Supporting evidence from your session is attached to this email.' . "\n\n" : '') .
        'Return to the assessment: ' . $examUrl . "\n\n" .
        'LearnGuard — Secure Assessment Platform';

    $fromEmail = get_config('learnguard', 'notify_from_email');
    $fromName  = get_config('learnguard', 'notify_from_name') ?: 'LearnGuard';
    $from      = $fromEmail
        ? (object)['email' => $fromEmail, 'firstname' => $fromName, 'lastname' => '', 'maildisplay' => 1]
        : \core_user::get_support_user();
    $htmlBody  = '<pre style="font-family:sans-serif;font-size:14px;line-height:1.6;">' .
        nl2br(htmlspecialchars($body)) . '</pre>';

    $mailError = null;
    try {
        $sent = email_to_user($student, $from, $subject, $body, $htmlBody, $attachRel, $attachName);
    } catch (\Throwable $e) {
        $sent      = false;
        $mailError = $e->getMessage();
    }

    $DB->insert_record('learnguard_suspension_notices', (object)[
        'learnguard'  => $attempt->learnguard,
        'attemptid'   => $attemptid,
        'userid'      => $attempt->userid,
        'proctorid'   => $USER->id,
        'noticetype'  => 'reinstate',
        'reason'      => $message !== '' ? $message : '(no message — standard reinstatement notice)',
        'snapcount'   => $snapCount,
        'audiocount'  => $audioCount,
        'mail_status' => $sent ? 'sent' : 'failed',
        'mail_error'  => $mailError,
        'timecreated' => time(),
    ]);

    return (bool)$sent;
}

/**
 * Email the student when a proctor confirms their suspension via attemptdetail.php's
 * "Confirm Suspension (Notify Student)" modal — includes the proctor's typed reason and,
 * if any snapshot/audio IDs were ticked in the Webcam Snapshots / Screen Captures / Audio
 * Clips sections, a ZIP of that evidence attached to the email. Gated by the
 * 'notify_suspension_confirmed_enabled' admin setting (default on). Every call
 * (including a skipped one) is logged to learnguard_suspension_notices
 * (noticetype='suspend') so proctors/admins can review what was sent later, via
 * suspensionnotices.php.
 *
 * @param int    $attemptid attempt ID
 * @param string $reason    proctor-entered reason, sent to the student verbatim
 * @param array  $snapIds   learnguard_snapshots IDs to attach as evidence (may be empty)
 * @param array  $audioIds  learnguard_audio_clips IDs to attach as evidence (may be empty)
 * @return bool whether the email was sent successfully
 */
function learnguard_notify_student_suspension_confirmed(
    int $attemptid, string $reason, array $snapIds = [], array $audioIds = []
): bool {
    global $DB, $USER;

    $attempt  = $DB->get_record('learnguard_attempts', ['id' => $attemptid], '*', IGNORE_MISSING);
    if (!$attempt) { return false; }
    $cm       = get_coursemodule_from_instance('learnguard', $attempt->learnguard, 0, false, IGNORE_MISSING);
    if (!$cm)  { return false; }
    $student  = $DB->get_record('user',       ['id' => $attempt->userid],     '*', IGNORE_MISSING);
    $instance = $DB->get_record('learnguard', ['id' => $attempt->learnguard], '*', IGNORE_MISSING);
    if (!$student || !$instance) { return false; }

    $enabledCfg = get_config('learnguard', 'notify_suspension_confirmed_enabled');
    $enabled    = ($enabledCfg === false || $enabledCfg === '') ? true : (bool)$enabledCfg;
    if (!$enabled) {
        $DB->insert_record('learnguard_suspension_notices', (object)[
            'learnguard'  => $attempt->learnguard,
            'attemptid'   => $attemptid,
            'userid'      => $attempt->userid,
            'proctorid'   => $USER->id,
            'noticetype'  => 'suspend',
            'reason'      => $reason,
            'snapcount'   => 0,
            'audiocount'  => 0,
            'mail_status' => 'skipped',
            'mail_error'  => 'Confirmed-suspension email disabled via LearnGuard settings.',
            'timecreated' => time(),
        ]);
        return false;
    }

    [$attachRel, $attachName, $snapCount, $audioCount] = learnguard_build_evidence_zip($attemptid, $snapIds, $audioIds);
    $hasEvidence = ($snapCount + $audioCount) > 0;

    $subject = '🚫 Your assessment attempt has been suspended — ' . format_string($instance->name);
    $body    = 'Hello ' . fullname($student) . ',' . "\n\n" .
        'Your attempt for "' . format_string($instance->name) . '" has been reviewed and your suspension has ' .
        'been confirmed due to a violation of exam rules.' . "\n\n" .
        'Reason:' . "\n" . $reason . "\n\n" .
        ($hasEvidence ? 'Supporting evidence is attached to this email.' . "\n\n" : '') .
        'If you believe this decision was made in error, please contact your instructor.' . "\n\n" .
        'LearnGuard — Secure Assessment Platform';

    $fromEmail = get_config('learnguard', 'notify_from_email');
    $fromName  = get_config('learnguard', 'notify_from_name') ?: 'LearnGuard';
    $from      = $fromEmail
        ? (object)['email' => $fromEmail, 'firstname' => $fromName, 'lastname' => '', 'maildisplay' => 1]
        : \core_user::get_support_user();
    $htmlBody  = '<pre style="font-family:sans-serif;font-size:14px;line-height:1.6;">' .
        nl2br(htmlspecialchars($body)) . '</pre>';

    $mailError = null;
    try {
        $sent = email_to_user($student, $from, $subject, $body, $htmlBody, $attachRel, $attachName);
    } catch (\Throwable $e) {
        $sent      = false;
        $mailError = $e->getMessage();
    }

    $DB->insert_record('learnguard_suspension_notices', (object)[
        'learnguard'  => $attempt->learnguard,
        'attemptid'   => $attemptid,
        'userid'      => $attempt->userid,
        'proctorid'   => $USER->id,
        'noticetype'  => 'suspend',
        'reason'      => $reason,
        'snapcount'   => $snapCount,
        'audiocount'  => $audioCount,
        'mail_status' => $sent ? 'sent' : 'failed',
        'mail_error'  => $mailError,
        'timecreated' => time(),
    ]);

    return (bool)$sent;
}

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
 * Render the full-width subsection tab strip for LearnGuard per-assessment pages.
 * Shows: Overview | Proctor | Grader | Report | Settings | Brand
 *
 * @param int    $cmid     Course-module ID
 * @param string $active   Current tab key (overview|proctor|grader|report|settings|brand)
 * @return string HTML
 */
function learnguard_activity_tabs(int $cmid, string $active = 'overview'): string {
    global $DB, $USER;
    $b = learnguard_brand();

    // Permissions.
    $context   = context_module::instance($cmid);
    $canManage = has_capability('mod/learnguard:manage', $context);
    $canProctor = $canManage || has_capability('mod/learnguard:liveproctor', $context);
    $isGrader  = $canManage || $DB->record_exists_sql(
        "SELECT id FROM {learnguard_graders} WHERE userid = :uid AND learnguard IN (
             SELECT instance FROM {course_modules} WHERE id = :cmid
         )", ['uid' => $USER->id, 'cmid' => $cmid]
    );

    $tabs = [];
    $tabs['overview'] = ['🏠 Overview',  '/mod/learnguard/view.php',        ['id' => $cmid], true];
    if ($canProctor) {
        $tabs['proctor']  = ['🛡 Proctor',  '/mod/learnguard/proctorview.php', [],              true];
        $tabs['report']   = ['📊 Reports',  '/mod/learnguard/admin.php',       ['id' => $cmid, 'tab' => 'attempts'], true];
        $tabs['scores']   = ['🏆 Scores',   '/mod/learnguard/scores.php',      ['id' => $cmid], true];
    }
    if ($isGrader) {
        $tabs['grader']   = ['✏️ Grader',   '/mod/learnguard/grader.php',      ['id' => $cmid], true];
    }
    if ($canManage) {
        $tabs['schedule'] = ['📅 Schedule', '/mod/learnguard/schedule.php',     ['id' => $cmid], true];
        $tabs['settings'] = ['⚙️ Settings', '/mod/learnguard/admin.php',       ['id' => $cmid, 'tab' => 'settings'], true];
        $tabs['brand']    = ['🎨 Brand',    '/mod/learnguard/admin.php',       ['id' => $cmid, 'tab' => 'brand'],    true];
    }

    $stripStyle = 'display:flex;background:#fff;border-bottom:2px solid #dee2e6;' .
        'overflow-x:auto;scrollbar-width:none;margin-bottom:16px;';
    $html = html_writer::start_div('', ['style' => $stripStyle, 'class' => 'lg-activity-tabs']);

    foreach ($tabs as $key => [$label, $path, $params, $show]) {
        if (!$show) { continue; }
        $isActive = ($key === $active);
        $url      = new moodle_url($path, $params);
        $tabStyle = 'display:flex;align-items:center;gap:5px;padding:0 18px;height:44px;font-size:13px;' .
            'font-weight:' . ($isActive ? '700' : '600') . ';' .
            'color:' . ($isActive ? $b->primary : '#6c757d') . ';' .
            'text-decoration:none;white-space:nowrap;flex-shrink:0;' .
            'border-bottom:3px solid ' . ($isActive ? $b->primary : 'transparent') . ';' .
            'transition:all .15s;';
        $html .= html_writer::link($url, $label, ['style' => $tabStyle]);
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

/**
 * Fetch a learner's personal schedule for an assessment, if one was assigned.
 *
 * @param int $learnguardid learnguard.id
 * @param int $userid
 * @return stdClass|null
 */
function learnguard_get_schedule(int $learnguardid, int $userid): ?stdClass {
    global $DB;
    $record = $DB->get_record('learnguard_schedules', ['learnguard' => $learnguardid, 'userid' => $userid]);
    return $record ?: null;
}

/**
 * Resolve the effective open/close window for a specific learner: a personal
 * schedule (learnguard_schedules) overrides the instance-level timeopen/timeclose.
 *
 * @param stdClass $instance learnguard row
 * @param int      $userid
 * @return array{0:int,1:int,2:bool} [timeopen, timeclose, hasschedule]
 */
function learnguard_effective_window(stdClass $instance, int $userid): array {
    $schedule = learnguard_get_schedule((int)$instance->id, $userid);
    if ($schedule) {
        return [(int)$schedule->timeopen, (int)$schedule->timeclose, true];
    }
    return [(int)($instance->timeopen ?? 0), (int)($instance->timeclose ?? 0), false];
}

/**
 * Check if an assessment is currently within its availability window for a
 * specific learner, taking any personal schedule into account.
 *
 * @param stdClass $instance learnguard row
 * @param int      $userid
 * @return bool
 */
function learnguard_is_available_for_user(stdClass $instance, int $userid): bool {
    [$timeopen, $timeclose] = learnguard_effective_window($instance, $userid);
    $now = time();
    if ($timeopen && $now < $timeopen) {
        return false;
    }
    if ($timeclose && $now > $timeclose) {
        return false;
    }
    return true;
}

/**
 * The effective time limit (seconds) for a specific attempt — mirrors exactly what the
 * client computes for its own countdown (initTimer() in assessment.js), so this can be
 * used as a server-side backstop that agrees with what the learner sees on screen:
 *  - Continue & Review attempts: the granted review budget (extra_time) IS the whole
 *    limit for this session, not added on top of the activity's normal time limit.
 *  - Normal attempts: the activity's timelimit, or a per-user timelimit_override from
 *    learnguard_schedules when set, plus any extra_time granted (proctor bonus minutes /
 *    break time-back).
 * Returns 0 for "no time limit" (untimed) — callers must treat 0 as never-expires.
 *
 * @param stdClass $instance learnguard row
 * @param stdClass $attempt  learnguard_attempts row
 * @return int seconds, or 0 if untimed
 */
function learnguard_effective_timelimit_seconds(stdClass $instance, stdClass $attempt): int {
    if (!empty($attempt->reopened_for_review)) {
        return (int)($attempt->extra_time ?? 0);
    }
    $base = (int)$instance->timelimit;
    $schedule = learnguard_get_schedule((int)$instance->id, (int)$attempt->userid);
    if ($schedule && !empty($schedule->timelimit_override)) {
        $base = (int)$schedule->timelimit_override;
    }
    if ($base <= 0) {
        return 0;
    }
    return $base + (int)($attempt->extra_time ?? 0);
}

/**
 * Whether an in-progress attempt's OWN time limit has been exceeded — independent of
 * learnguard_is_available_for_user()'s personal/activity WINDOW check (the exam being
 * open at all vs. this specific attempt having run out of allotted minutes). Always
 * false for an untimed activity/session.
 *
 * Approved-break time is excluded from the elapsed calculation: unlike a suspension
 * (extend_attempt_time() shifts timestart forward once resolved), end_break() never
 * shifts timestart — the client's on-screen timer only LOOKS frozen during a break via
 * a local trick that doesn't survive a reload. Without this, a student legitimately on
 * (or just back from) an approved break could be force-finished for time that was never
 * really theirs to lose.
 *
 * @param stdClass $instance learnguard row
 * @param stdClass $attempt  learnguard_attempts row
 * @return bool
 */
function learnguard_attempt_timelimit_expired(stdClass $instance, stdClass $attempt): bool {
    // An explicit absolute deadline set on a Continue & Review grant (review_deadline) is
    // checked independently of the minutes-based extra_time limit below — whichever is
    // reached first ends the review. Unlike extra_time, this doesn't depend on timestart
    // ever advancing, so a review that's granted but never actually started (the student
    // never redoes the precheck, so start_review() never runs and timestart stays at
    // whatever it was on the ORIGINAL attempt) still gets a hard, predictable cutoff
    // instead of drifting on indefinitely.
    if (!empty($attempt->reopened_for_review) && !empty($attempt->review_deadline)
            && time() > (int)$attempt->review_deadline) {
        return true;
    }
    $now = time();

    // Absolute safety net, independent of every calculation above: no attempt should
    // EVER be able to sit 'inprogress' indefinitely regardless of which specific edge
    // case (a misconfigured limit, an unresolved break, extra_time somehow left at 0 on
    // a reopened_for_review attempt, etc.) might otherwise let it slip through every
    // check above forever. No legitimate exam/review session — breaks included — should
    // ever genuinely need more than 48 hours.
    if ($now - (int)$attempt->timestart > 48 * HOURSECS) {
        return true;
    }

    $limit = learnguard_effective_timelimit_seconds($instance, $attempt);
    if ($limit <= 0) {
        return false;
    }
    // Never expire while an approved break is still GENUINELY active — i.e. its own
    // deadline hasn't passed yet. end_break() normally sets break_end when the student
    // clicks "end break", but a student who closes the tab during (or right after) an
    // approved break never sends that call, leaving break_approved set with break_end
    // still empty permanently. Blocking expiry forever in that case — as this used to —
    // left an abandoned break-taker stuck 'inprogress' no matter how many days passed.
    // Once the break's own deadline has passed, treat it as effectively over at that
    // deadline instead, and keep evaluating the time limit normally from there.
    if (!empty($attempt->break_approved) && empty($attempt->break_end)) {
        if (empty($attempt->break_deadline) || $now < (int)$attempt->break_deadline) {
            return false; // still genuinely on break (or no deadline recorded to fall back on).
        }
    }
    $effectiveBreakEnd = !empty($attempt->break_end) ? (int)$attempt->break_end
        : (!empty($attempt->break_approved) ? (int)$attempt->break_deadline : 0);
    $breakSeconds = (!empty($attempt->break_approved) && $effectiveBreakEnd > (int)$attempt->break_approved)
                    ? ($effectiveBreakEnd - (int)$attempt->break_approved) : 0;
    $elapsed = $now - (int)$attempt->timestart - $breakSeconds;
    return $elapsed > $limit;
}

/**
 * Force-finish every 'inprogress' attempt site-wide whose personal/activity window has
 * closed or whose own time limit (including a Continue & Review review_deadline) has run
 * out. Shared by the auto_finish_expired_attempts scheduled task (runs every 5 minutes)
 * and a manual "run now" trigger for admins who don't want to wait on cron, or whose
 * site's cron isn't confirmed to be running.
 *
 * @return int number of attempts finished
 */
function learnguard_finish_expired_attempts(): int {
    global $DB;

    $attempts = $DB->get_records('learnguard_attempts', ['state' => 'inprogress']);
    if (empty($attempts)) {
        return 0;
    }

    $lgids = array_unique(array_map(fn($a) => (int)$a->learnguard, $attempts));
    [$insql, $inparams] = $DB->get_in_or_equal($lgids, SQL_PARAMS_NAMED);
    $instances = $DB->get_records_select('learnguard', "id $insql", $inparams);

    $finished = 0;
    foreach ($attempts as $attempt) {
        $instance = $instances[$attempt->learnguard] ?? null;
        if (!$instance) {
            continue; // orphaned attempt (activity deleted without cascade) — leave alone.
        }

        $windowClosed  = empty($attempt->reopened_for_review)
                         && !learnguard_is_available_for_user($instance, (int)$attempt->userid);
        $timelimitOver = learnguard_attempt_timelimit_expired($instance, $attempt);
        if (!$windowClosed && !$timelimitOver) {
            continue;
        }

        \mod_learnguard\attempt_manager::finish($attempt, $instance);
        $finished++;
    }

    return $finished;
}

/**
 * Human-readable availability status string for a specific learner, taking
 * any personal schedule into account.
 *
 * @param stdClass $instance learnguard row
 * @param int      $userid
 * @return string
 */
function learnguard_availability_message_for_user(stdClass $instance, int $userid): string {
    [$timeopen, $timeclose] = learnguard_effective_window($instance, $userid);
    $now = time();
    if ($timeopen && $now < $timeopen) {
        return get_string('assessmentnotopen', 'learnguard', userdate($timeopen));
    }
    if ($timeclose && $now > $timeclose) {
        return get_string('assessmentclosed', 'learnguard', userdate($timeclose));
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

/**
 * Distribute finished attempts with essay questions among Layer-1 graders.
 *
 * Replaces all existing learnguard_grader_assignments rows for this activity
 * with a fresh distribution. Layer-2 graders and managers always see every
 * finished attempt regardless of this table (see grader.php) — distribution
 * only affects which attempts each Layer-1 grader sees in their queue.
 *
 * @param int    $learnguardid Activity instance ID.
 * @param string $mode         'split' (even round-robin) or 'random' (capped random sample per grader).
 * @param int    $cap          Max attempts per grader for 'random' mode (0 = even share of the pool).
 */
function learnguard_distribute_grading(int $learnguardid, string $mode, int $cap): void {
    global $DB;

    $graders = array_values($DB->get_records('learnguard_graders',
        ['learnguard' => $learnguardid, 'gradelayer' => 1], 'id ASC'));
    $attempts = array_values($DB->get_records('learnguard_attempts',
        ['learnguard' => $learnguardid, 'state' => 'finished'], 'id ASC', 'id'));

    $DB->delete_records('learnguard_grader_assignments', ['learnguard' => $learnguardid]);

    if (empty($graders) || empty($attempts)) {
        return;
    }

    shuffle($attempts);
    $now = time();

    if ($mode === 'split') {
        $numgraders = count($graders);
        foreach ($attempts as $i => $attempt) {
            $grader = $graders[$i % $numgraders];
            $DB->insert_record('learnguard_grader_assignments', (object)[
                'learnguard'  => $learnguardid,
                'attemptid'   => $attempt->id,
                'graderid'    => $grader->userid,
                'timecreated' => $now,
            ]);
        }
        return;
    }

    // 'random' — each grader gets up to $cap random attempts (no overlap).
    // A cap of 0 falls back to an even share so every grader gets work.
    $effectivecap = $cap > 0 ? $cap : (int)ceil(count($attempts) / count($graders));
    $pool = $attempts;
    foreach ($graders as $grader) {
        if (empty($pool)) {
            break;
        }
        $take = min($effectivecap, count($pool));
        for ($i = 0; $i < $take; $i++) {
            $attempt = array_shift($pool);
            $DB->insert_record('learnguard_grader_assignments', (object)[
                'learnguard'  => $learnguardid,
                'attemptid'   => $attempt->id,
                'graderid'    => $grader->userid,
                'timecreated' => $now,
            ]);
        }
    }
}
