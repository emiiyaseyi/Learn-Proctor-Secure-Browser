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
 * Email notification handler for Learn Shield.
 *
 * @package    quizaccess_learnshield
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link       https://www.linkedin.com/in/michaeladeniran/
 */

namespace quizaccess_learnshield;

defined('MOODLE_INTERNAL') || die();

/**
 * Sends email notifications for Learn Shield events using Moodle's messaging API.
 */
class email_notifier {

    const EVENT_HIGH_RISK       = 'high_risk_reached';
    const EVENT_SESSION_FLAGGED = 'session_flagged';
    const EVENT_SUSPENDED       = 'session_suspended';
    const EVENT_EXAM_STARTED    = 'exam_started';
    const EVENT_EXAM_SUBMITTED  = 'exam_submitted';
    const EVENT_ENVIRONMENT_REC = 'environment_recording';

    /**
     * Send a notification that a session has reached high risk.
     *
     * @param object $session
     * @param int    $score
     */
    public static function notify_high_risk(object $session, int $score): void {
        if (!self::is_enabled('notify_high_risk')) {
            return;
        }
        global $DB;

        $student = $DB->get_record('user', ['id' => $session->userid]);
        $quiz    = self::get_quiz_name($session->quizid);
        $course  = $DB->get_record('course', ['id' => $session->courseid], 'fullname');

        $subject = 'Learn Shield: High Risk Alert — ' . fullname($student);
        $body    = "A high integrity risk score has been reached during a proctored exam.\n\n" .
                   "Student: " . fullname($student) . " <" . $student->email . ">\n" .
                   "Quiz:    {$quiz}\n" .
                   "Course:  {$course->fullname}\n" .
                   "Score:   {$score} points (High Risk)\n" .
                   "Time:    " . userdate(time()) . "\n\n" .
                   "Please review this session in Learn Shield.";

        self::send_to_teachers_and_proctors($session->quizid, $session->courseid, $subject, $body);
    }

    /**
     * Send a notification that a session was manually flagged.
     *
     * @param object $session
     * @param object $proctor  The user who flagged it.
     */
    public static function notify_flagged(object $session, object $proctor): void {
        if (!self::is_enabled('notify_flagged')) {
            return;
        }
        global $DB;

        $student = $DB->get_record('user', ['id' => $session->userid]);
        $quiz    = self::get_quiz_name($session->quizid);

        $subject = 'Learn Shield: Session Flagged — ' . fullname($student);
        $body    = "A student session has been manually flagged by a proctor.\n\n" .
                   "Student: " . fullname($student) . "\n" .
                   "Flagged by: " . fullname($proctor) . "\n" .
                   "Quiz: {$quiz}\n" .
                   "Time: " . userdate(time());

        self::send_to_teachers_and_proctors($session->quizid, $session->courseid, $subject, $body);
    }

    /**
     * Send a notification that a learner was auto-suspended.
     *
     * @param object $session
     * @param int    $score
     * @param string $reason
     */
    public static function notify_suspended(object $session, int $score, string $reason = ''): void {
        if (!self::is_enabled('notify_suspended')) {
            return;
        }
        global $DB;

        $student = $DB->get_record('user', ['id' => $session->userid]);
        $quiz    = self::get_quiz_name($session->quizid);
        $course  = $DB->get_record('course', ['id' => $session->courseid], 'fullname');

        $subject = 'Learn Shield: Learner Auto-Suspended — ' . fullname($student);
        $body    = "A learner has been automatically suspended from a proctored exam.\n\n" .
                   "Student: " . fullname($student) . " <" . $student->email . ">\n" .
                   "Quiz:    {$quiz}\n" .
                   "Course:  {$course->fullname}\n" .
                   "Score:   {$score} points\n" .
                   "Reason:  " . ($reason ?: 'Exceeded violation threshold') . "\n" .
                   "Time:    " . userdate(time()) . "\n\n" .
                   "Please review this session.";

        self::send_to_teachers_and_proctors($session->quizid, $session->courseid, $subject, $body);

        // Also notify the learner.
        if (self::is_enabled('notify_learner_suspended')) {
            self::send_to_user($student,
                'Learn Shield: Your exam session has been suspended',
                "Your exam session has been suspended due to integrity violations.\n\n" .
                "Quiz: {$quiz}\n" .
                "Please contact your instructor."
            );
        }
    }

    /**
     * Send a notification that a user has been assigned as a proctor for a quiz.
     *
     * @param object $proctor  The newly-assigned user.
     * @param int    $cmid     course_modules id of the quiz (0 if unknown).
     * @param int    $courseid
     * @param string $role
     */
    public static function notify_proctor_assigned(object $proctor, int $cmid, int $courseid, string $role): void {
        if (!self::is_enabled('notify_proctor_assigned')) {
            return;
        }
        global $DB;

        $quiz   = self::get_quiz_name($cmid);
        $course = $DB->get_record('course', ['id' => $courseid], 'fullname', IGNORE_MISSING);

        $subject = 'Learn Shield: You have been assigned as ' . $role;
        $body    = "You have been assigned as a {$role} for a Learn Shield proctored quiz.\n\n" .
                   "Quiz:   {$quiz}\n" .
                   "Course: " . ($course->fullname ?? 'Unknown course') . "\n" .
                   "Time:   " . userdate(time()) . "\n\n" .
                   "You can review proctored sessions from the Learn Shield Proctor View.";

        self::send_to_user($proctor, $subject, $body);
        self::send_inapp([$proctor], $subject, $body);
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    private static function is_enabled(string $setting): bool {
        $val = get_config('quizaccess_learnshield', $setting);
        return $val !== false && (bool)(int)$val;
    }

    private static function get_quiz_name(int $cmid): string {
        global $DB;
        try {
            $cm   = $DB->get_record('course_modules', ['id' => $cmid]);
            $quiz = $DB->get_record('quiz', ['id' => $cm->instance], 'name');
            return $quiz->name ?? 'Unknown Quiz';
        } catch (\Exception $e) {
            return 'Unknown Quiz';
        }
    }

    /**
     * Send an email to all teachers and assigned proctors for a quiz.
     */
    private static function send_to_teachers_and_proctors(int $cmid, int $courseid, string $subject, string $body): void {
        global $DB;

        $recipients = [];

        // Get assigned proctors.
        $proctors = $DB->get_records('quizaccess_learnshield_proctor_assign', ['quizid' => $cmid]);
        foreach ($proctors as $p) {
            $u = $DB->get_record('user', ['id' => $p->user_id]);
            if ($u) { $recipients[$u->id] = $u; }
        }

        // Get course teachers.
        $context = \context_course::instance($courseid);
        $teacherids = get_users_by_capability($context, 'quizaccess/learnshield:viewreport', 'u.id');
        foreach ($teacherids as $tid) {
            if (!isset($recipients[$tid])) {
                $u = $DB->get_record('user', ['id' => $tid]);
                if ($u) { $recipients[$u->id] = $u; }
            }
        }

        foreach ($recipients as $recipient) {
            self::send_to_user($recipient, $subject, $body);
            self::send_inapp([$recipient], $subject, $body);
        }
    }

    /**
     * Send a plain-text email to a user via Moodle's email API.
     */
    private static function send_to_user(\stdClass $user, string $subject, string $body): void {
        try {
            $from = \core_user::get_noreply_user();
            email_to_user($user, $from, $subject, $body, format_text($body, FORMAT_PLAIN));
        } catch (\Exception $e) {
            debugging('Learn Shield email: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Send an in-app Moodle notification (message API).
     * Admin can control email vs in-app vs both via settings.
     *
     * @param array  $tousers  Array of \stdClass user objects.
     * @param string $subject
     * @param string $body
     * @param string $url      Optional contextual link.
     */
    public static function send_inapp(array $tousers, string $subject, string $body, string $url = ''): void {
        if (!self::is_enabled('notify_inapp')) { return; }
        foreach ($tousers as $touser) {
            try {
                $msg                    = new \core\message\message();
                $msg->component         = 'quizaccess_learnshield';
                $msg->name              = 'proctoring_alert';
                $msg->userfrom          = \core_user::get_noreply_user();
                $msg->userto            = $touser;
                $msg->subject           = $subject;
                $msg->fullmessage       = $body;
                $msg->fullmessageformat = FORMAT_PLAIN;
                $msg->fullmessagehtml   = nl2br(htmlspecialchars($body));
                $msg->smallmessage      = shorten_text(strip_tags($body), 100);
                $msg->notification      = 1;
                if ($url) { $msg->contexturl = $url; $msg->contexturlname = 'View in Learn Shield'; }
                message_send($msg);
            } catch (\Exception $e) {
                debugging('Learn Shield inapp: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }
}
