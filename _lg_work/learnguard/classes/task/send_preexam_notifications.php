<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Scheduled task: send pre-exam notification emails to students AND proctors.
 *
 * Runs hourly via Moodle's task scheduler.
 *
 * Email delivery: uses Moodle's configured outgoing mail (SMTP or sendmail).
 * Configure at: Site administration → Server → Email → Outgoing mail configuration.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_learnguard\task;

defined('MOODLE_INTERNAL') || die();

class send_preexam_notifications extends \core\task\scheduled_task {

    public function get_name(): string {
        return 'LearnGuard: Send pre-exam notification emails';
    }

    public function execute(): void {
        global $DB, $CFG;
        require_once($CFG->libdir . '/moodlelib.php');
        require_once($CFG->dirroot . '/mod/learnguard/lib.php');

        $enabled = get_config('learnguard', 'notify_email_enabled');
        if (!$enabled) {
            mtrace('  LearnGuard notifications disabled — skipping.');
            return;
        }

        $now = time();

        $assessments = $DB->get_records_sql(
            "SELECT lg.*, cm.id AS cmid, c.id AS courseid, c.fullname AS coursefull
               FROM {learnguard} lg
               JOIN {course} c ON c.id = lg.course
               JOIN {modules} md ON md.name = 'learnguard'
               JOIN {course_modules} cm ON cm.instance = lg.id AND cm.module = md.id
              WHERE lg.timeopen > :now
                AND lg.timeopen <= :window",
            ['now' => $now, 'window' => $now + 3700]
        );

        foreach ($assessments as $lg) {
            $notifyHours = (int)($lg->notification_hours ?? 0);
            if ($notifyHours <= 0) {
                // Use global default if per-assessment not set
                $notifyHours = (int)(get_config('learnguard', 'notify_hours_before') ?? 24);
            }

            $opensIn      = $lg->timeopen - $now;
            $targetWindow = $notifyHours * 3600;
            if (abs($opensIn - $targetWindow) > 1800) { continue; }

            $context  = \context_module::instance($lg->cmid);
            $students = get_enrolled_users($context, 'mod/learnguard:attempt');
            $proctors = learnguard_get_all_proctors($lg->id, $lg->cmid);

            $viewUrl   = (new \moodle_url('/mod/learnguard/view.php',  ['id' => $lg->cmid]))->out(false);
            $adminUrl  = (new \moodle_url('/mod/learnguard/admin.php', ['id' => $lg->cmid]))->out(false);
            $timelimit = $lg->timelimit > 0 ? round($lg->timelimit / 60) . ' minutes' : 'No limit';
            $openDate  = userdate($lg->timeopen, '%A, %d %B %Y at %H:%M');

            // ── Student notification ──────────────────────────────────────
            $studentSubjectTpl = get_config('learnguard', 'notify_email_subject')
                ?: 'Reminder: Your exam "{assessmentname}" opens soon';
            $studentBodyTpl    = get_config('learnguard', 'notify_email_body')
                ?: "Dear {firstname},\n\nYour exam \"{assessmentname}\" in {coursename} opens on {timeopen}.\n\nTime allowed: {timelimit}\n\nPlease ensure your webcam and internet connection are ready.\n\nLearnGuard";

            foreach ($students as $student) {
                if ($DB->record_exists('learnguard_attempts', ['learnguard' => $lg->id, 'userid' => $student->id])) {
                    continue;
                }
                $subject = learnguard_fill_email_template($studentSubjectTpl, [
                    '{assessmentname}' => format_string($lg->name),
                    '{coursename}'     => format_string($lg->coursefull),
                    '{firstname}'      => $student->firstname,
                    '{lastname}'       => $student->lastname,
                    '{timeopen}'       => $openDate,
                    '{timelimit}'      => $timelimit,
                ]);
                $body = learnguard_fill_email_template($studentBodyTpl, [
                    '{assessmentname}' => format_string($lg->name),
                    '{coursename}'     => format_string($lg->coursefull),
                    '{firstname}'      => $student->firstname,
                    '{lastname}'       => $student->lastname,
                    '{timeopen}'       => $openDate,
                    '{timelimit}'      => $timelimit,
                ]);
                $body .= "\n\n" . $viewUrl;
                learnguard_send_email($student, $subject, $body);
                mtrace('  Student notified: ' . fullname($student));
            }

            // ── Proctor notification ──────────────────────────────────────
            $proctorSubject = 'Upcoming exam: ' . format_string($lg->name) . ' (opens ' . $openDate . ')';
            $proctorBody =
                'This is an automated reminder from LearnGuard.' . "\n\n" .
                'The following proctored assessment is opening soon:' . "\n\n" .
                'Assessment : ' . format_string($lg->name)      . "\n" .
                'Course     : ' . format_string($lg->coursefull) . "\n" .
                'Opens      : ' . $openDate                      . "\n" .
                'Students   : ' . count($students)               . " enrolled\n" .
                'Time limit : ' . $timelimit                     . "\n\n" .
                'Admin panel: ' . $adminUrl . "\n" .
                'Live dashboard will be available once students start the exam.' . "\n\n" .
                'LearnGuard — Secure Assessment Platform';

            foreach ($proctors as $proctor) {
                learnguard_send_email($proctor, $proctorSubject, $proctorBody);
                mtrace('  Proctor notified: ' . fullname($proctor));
            }
        }
    }
}
