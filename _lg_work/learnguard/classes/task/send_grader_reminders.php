<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Scheduled task: send daily grading deadline reminder emails.
 *
 * Runs daily at 08:00. For every grader assignment that has a deadline
 * set and still has ungraded essays, sends a reminder with the count of
 * remaining essays, the grading URL, and the deadline.
 *
 * Gated by the "Send Proctor Notifications" (notify_proctors_enabled) setting.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_learnguard\task;

defined('MOODLE_INTERNAL') || die();

class send_grader_reminders extends \core\task\scheduled_task {

    public function get_name(): string {
        return 'LearnGuard: Send grading deadline reminder emails';
    }

    public function execute(): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/learnguard/lib.php');

        if (!get_config('learnguard', 'notify_proctors_enabled')) {
            mtrace('  LearnGuard proctor notifications disabled — skipping grader reminders.');
            return;
        }

        // Find all grader assignments with a deadline that still have ungraded essays.
        $graders = $DB->get_records_sql(
            "SELECT g.id, g.learnguard, g.userid, g.deadline, g.gradelayer,
                    lg.name AS assessmentname, lg.course AS courseid,
                    cm.id AS cmid
               FROM {learnguard_graders} g
               JOIN {learnguard} lg ON lg.id = g.learnguard
               JOIN {course_modules} cm ON cm.instance = lg.id
               JOIN {modules} mdl ON mdl.id = cm.module AND mdl.name = 'learnguard'
              WHERE g.deadline > 0", []
        );

        $now = time();
        $sent = 0;

        foreach ($graders as $g) {
            // Skip if deadline already passed.
            if ($g->deadline < $now) {
                continue;
            }

            // Count ungraded finished essays for this grader.
            $ungraded = $DB->count_records_sql(
                "SELECT COUNT(a.id)
                   FROM {learnguard_attempts} a
                  WHERE a.learnguard = :lgid
                    AND a.state = 'finished'
                    AND NOT EXISTS (
                        SELECT 1 FROM {learnguard_essay_grades} eg
                         WHERE eg.attemptid = a.id AND eg.graderid = :uid
                    )",
                ['lgid' => $g->learnguard, 'uid' => $g->userid]
            );

            if ($ungraded <= 0) {
                continue;
            }

            $user = $DB->get_record('user', ['id' => $g->userid, 'deleted' => 0]);
            if (!$user) {
                continue;
            }

            $gradersUrl = (new \moodle_url('/mod/learnguard/grader.php', ['id' => $g->cmid]))->out(false);
            $ddlStr     = userdate($g->deadline, '%d %B %Y');
            $daysLeft   = ceil(($g->deadline - $now) / 86400);

            learnguard_send_email(
                $user,
                'Grading reminder: ' . $ungraded . ' essay' . ($ungraded > 1 ? 's' : '') . ' awaiting review — ' . $g->assessmentname,
                'Hi ' . fullname($user) . ',<br><br>' .
                'This is a reminder that you have <strong>' . $ungraded . ' essay' . ($ungraded > 1 ? 's' : '') . '</strong> ' .
                'still awaiting your review for the assessment: <strong>' . s($g->assessmentname) . '</strong>.<br><br>' .
                'Grading deadline: <strong>' . $ddlStr . '</strong> (' . $daysLeft . ' day' . ($daysLeft > 1 ? 's' : '') . ' remaining)<br><br>' .
                'Visit your grading page: <a href="' . $gradersUrl . '">' . $gradersUrl . '</a><br><br>' .
                'If you have already completed your grading, please disregard this message.'
            );

            mtrace('  Sent grading reminder to ' . fullname($user) . ' — ' . $ungraded . ' ungraded for ' . $g->assessmentname);
            $sent++;
        }

        mtrace('  LearnGuard grader reminders: ' . $sent . ' email(s) sent.');
    }
}
