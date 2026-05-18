<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Scheduled task: send pre-exam notification emails.
 *
 * Runs hourly. For each LearnGuard assessment with notification_hours > 0,
 * sends an email to enrolled students when the assessment opens in
 * exactly notification_hours hours (give or take 1 hour).
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

        $now = time();

        // Find assessments opening in the next notification window.
        $assessments = $DB->get_records_sql(
            "SELECT lg.*, cm.id AS cmid, c.id AS courseid, c.fullname AS coursefull
               FROM {learnguard} lg
               JOIN {course} c ON c.id = lg.course
               JOIN {modules} md ON md.name = 'learnguard'
               JOIN {course_modules} cm ON cm.instance = lg.id AND cm.module = md.id
              WHERE lg.notification_hours > 0
                AND lg.timeopen > :now
                AND lg.timeopen <= :window",
            ['now' => $now, 'window' => $now + 3700]  // +1 hour + 100s tolerance
        );

        foreach ($assessments as $lg) {
            $opensIn = $lg->timeopen - $now;
            $targetWindow = $lg->notification_hours * 3600;

            // Only notify if we're within the target window (±30 min).
            if (abs($opensIn - $targetWindow) > 1800) { continue; }

            // Get enrolled students.
            $context  = \context_module::instance($lg->cmid);
            $students = get_enrolled_users($context, 'mod/learnguard:attempt');

            $from    = \core_user::get_support_user();
            $viewUrl = new \moodle_url('/mod/learnguard/view.php', ['id' => $lg->cmid]);

            foreach ($students as $student) {
                // Don't notify if student already has an attempt.
                if ($DB->record_exists('learnguard_attempts', ['learnguard' => $lg->id, 'userid' => $student->id])) {
                    continue;
                }

                $subject = 'Reminder: ' . format_string($lg->name) . ' opens in ' . $lg->notification_hours . ' hour(s)';
                $body    =
                    'Dear ' . fullname($student) . ",\n\n" .
                    'This is a reminder that your assessment will open soon.\n\n' .
                    'Assessment: ' . format_string($lg->name) . "\n" .
                    'Course:     ' . format_string($lg->coursefull) . "\n" .
                    'Opens:      ' . userdate($lg->timeopen, '%A, %d %B %Y at %H:%M') . "\n\n" .
                    ($lg->timelimit > 0 ? 'Time limit: ' . round($lg->timelimit / 60) . " minutes\n\n" : '') .
                    'Click here to access the assessment:' . "\n" . $viewUrl->out(false) . "\n\n" .
                    'Ensure your webcam, microphone, and internet connection are ready.' . "\n\n" .
                    'LearnGuard — Secure Assessment Platform';

                email_to_user($student, $from, $subject, $body);
                mtrace('  Notified: ' . fullname($student) . ' for ' . format_string($lg->name));
            }
        }
    }
}
