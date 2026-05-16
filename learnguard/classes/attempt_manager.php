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
 * Attempt management for mod_learnguard.
 *
 * Handles creating, continuing, and finishing assessment attempts.
 * Integrates with Moodle's Question Engine (question_usage_by_activity).
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link       https://www.linkedin.com/in/michaeladeniran/
 */

namespace mod_learnguard;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/engine/lib.php');

/**
 * Manages LearnGuard attempt lifecycle using Moodle Question Engine.
 */
class attempt_manager {

    /**
     * Start a new attempt, or return the current in-progress attempt.
     *
     * @param stdClass $instance   learnguard row
     * @param stdClass $cm         course_modules row
     * @param stdClass $user
     * @return stdClass            learnguard_attempts row
     */
    public static function start_or_resume(\stdClass $instance, \stdClass $cm, \stdClass $user): \stdClass {
        global $DB;

        // Check for existing in-progress attempt.
        $attempt = $DB->get_record('learnguard_attempts', [
            'learnguard' => $instance->id,
            'userid'     => $user->id,
            'state'      => 'inprogress',
        ]);

        if ($attempt) {
            return $attempt;
        }

        // Count previous attempts.
        $attemptno = $DB->count_records('learnguard_attempts', [
            'learnguard' => $instance->id,
            'userid'     => $user->id,
        ]) + 1;

        // Check max attempts.
        if ($instance->attempts > 0 && $attemptno > $instance->attempts) {
            throw new \moodle_exception('error:nopermission', 'learnguard');
        }

        // Fetch question slots.
        $slots = $DB->get_records('learnguard_slots', ['learnguard' => $instance->id], 'slot ASC');
        if (empty($slots)) {
            throw new \moodle_exception('error:noquestions', 'learnguard');
        }

        // Create a Question Usage By Activity (quba).
        $context = \context_module::instance($cm->id);
        $quba    = \question_engine::make_questions_usage_by_activity('mod_learnguard', $context);
        $quba->set_preferred_behaviour('deferredfeedback');

        // Add each question to the quba.
        foreach ($slots as $slot) {
            $question = \question_bank::load_question($slot->questionid);
            $quba->add_question($question, $slot->maxmark);
        }

        // Start all questions.
        $quba->start_all_questions();
        \question_engine::save_questions_usage_by_activity($quba);

        // Create the attempt record.
        $attempt = (object)[
            'learnguard'   => $instance->id,
            'userid'       => $user->id,
            'attempt'      => $attemptno,
            'uniqueid'     => $quba->get_id(),
            'currentpage'  => 0,
            'state'        => 'inprogress',
            'timestart'    => time(),
            'timemodified' => time(),
            'ip_address'   => getremoteaddr(),
            'browser_info' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            'risk_score'   => 0,
            'risk_level'   => 'low',
        ];
        $attempt->id = $DB->insert_record('learnguard_attempts', $attempt);

        return $attempt;
    }

    /**
     * Get the rendered HTML for a question slot.
     *
     * @param stdClass $attempt
     * @param int      $slot    1-based slot number.
     * @param bool     $review  True = show feedback/answers.
     * @return array   ['html' => string, 'questionnumber' => int, 'totalquestions' => int, 'isfinished' => bool]
     */
    public static function get_question_html(\stdClass $attempt, int $slot, bool $review = false): array {
        global $PAGE;

        $quba = \question_engine::load_questions_usage_by_activity($attempt->uniqueid);
        $slots = $quba->get_slots();
        $total = count($slots);

        if ($slot < 1 || $slot > $total) {
            throw new \moodle_exception('error:noattempt', 'learnguard');
        }

        $options = new \question_display_options();
        $options->marks          = $review ? \question_display_options::MARK_AND_MAX : \question_display_options::HIDDEN;
        $options->feedback       = $review ? \question_display_options::VISIBLE : \question_display_options::HIDDEN;
        $options->correctness    = $review ? \question_display_options::VISIBLE : \question_display_options::HIDDEN;
        $options->numpartscorrect = $review ? \question_display_options::VISIBLE : \question_display_options::HIDDEN;
        $options->readonly       = $review;

        // Render the question HTML.
        ob_start();
        echo $quba->render_question($slot, $options, $slot);
        $html = ob_get_clean();

        return [
            'html'           => $html,
            'slot'           => $slot,
            'questionnumber' => $slot,
            'totalquestions' => $total,
            'isfinished'     => ($attempt->state === 'finished'),
            'maxmark'        => $quba->get_question_max_mark($slot),
        ];
    }

    /**
     * Process an answer for a slot and save it.
     *
     * @param stdClass $attempt
     * @param int      $slot         1-based slot.
     * @param array    $responsedata Key-value pairs of form field names → values.
     * @return int     Next slot number (or 0 if this was the last question).
     */
    public static function process_answer(\stdClass $attempt, int $slot, array $responsedata): int {
        global $DB;

        $quba = \question_engine::load_questions_usage_by_activity($attempt->uniqueid);

        // Process the submitted response.
        $quba->process_action($slot, $responsedata, time());
        \question_engine::save_questions_usage_by_activity($quba);

        // Update current page.
        $DB->set_field('learnguard_attempts', 'currentpage', $slot, ['id' => $attempt->id]);
        $DB->set_field('learnguard_attempts', 'timemodified', time(), ['id' => $attempt->id]);

        $total = count($quba->get_slots());
        return ($slot < $total) ? $slot + 1 : 0;
    }

    /**
     * Autosave an answer without advancing.
     *
     * @param stdClass $attempt
     * @param int      $slot
     * @param array    $responsedata
     */
    public static function autosave(\stdClass $attempt, int $slot, array $responsedata): void {
        global $DB;

        $quba = \question_engine::load_questions_usage_by_activity($attempt->uniqueid);
        $quba->process_autosave($slot, $responsedata, time());
        \question_engine::save_questions_usage_by_activity($quba);

        $DB->set_field('learnguard_attempts', 'timemodified', time(), ['id' => $attempt->id]);
    }

    /**
     * Finish an attempt: grade all questions and update records.
     *
     * @param stdClass $attempt
     * @param stdClass $instance
     * @return float Final grade.
     */
    public static function finish(\stdClass $attempt, \stdClass $instance): float {
        global $DB, $CFG;

        $quba = \question_engine::load_questions_usage_by_activity($attempt->uniqueid);

        // Finish all questions that have not been finished yet.
        $quba->finish_all_questions(time());
        \question_engine::save_questions_usage_by_activity($quba);

        // Calculate raw score.
        $rawgrade = $quba->get_total_mark();

        // Update attempt record.
        $DB->update_record('learnguard_attempts', (object)[
            'id'           => $attempt->id,
            'state'        => 'finished',
            'timefinish'   => time(),
            'timemodified' => time(),
            'sumgrades'    => $rawgrade,
        ]);

        // Update gradebook.
        $instance = $DB->get_record('learnguard', ['id' => $attempt->learnguard]);
        $maxraw   = array_sum(array_map(fn($s) => $quba->get_question_max_mark($s), $quba->get_slots()));
        $grade    = $maxraw > 0 ? ($rawgrade / $maxraw) * $instance->grade : 0;

        require_once($CFG->libdir . '/gradelib.php');
        $grades = [(object)['userid' => $attempt->userid, 'rawgrade' => $grade]];
        grade_update('mod/learnguard', $instance->course, 'mod', 'learnguard',
            $instance->id, 0, $grades);

        return $grade;
    }

    /**
     * Get a summary of all slots for navigation panel.
     *
     * @param stdClass $attempt
     * @return array   Array of [slot, state, maxmark]
     */
    public static function get_navigation_summary(\stdClass $attempt): array {
        $quba    = \question_engine::load_questions_usage_by_activity($attempt->uniqueid);
        $summary = [];
        foreach ($quba->get_slots() as $slot) {
            $state = $quba->get_question_state($slot);
            $summary[] = [
                'slot'     => $slot,
                'state'    => $state->get_state_class(false),
                'maxmark'  => $quba->get_question_max_mark($slot),
                'answered' => $state->is_finished() || $state->is_graded(),
            ];
        }
        return $summary;
    }

    /**
     * Log a proctoring event for an attempt.
     *
     * @param int    $attemptid
     * @param int    $userid
     * @param string $eventtype
     * @param string $eventdata  JSON
     * @param string $severity
     * @param int    $scoreimpact
     */
    public static function log_event(
        int $attemptid, int $userid, string $eventtype,
        string $eventdata, string $severity, int $scoreimpact
    ): void {
        global $DB;

        $DB->insert_record('learnguard_events', (object)[
            'attemptid'    => $attemptid,
            'userid'       => $userid,
            'event_type'   => $eventtype,
            'event_data'   => $eventdata,
            'severity'     => $severity,
            'score_impact' => $scoreimpact,
            'timemodified' => time(),
        ]);

        // Update risk score on the attempt.
        if ($scoreimpact > 0) {
            $attempt = $DB->get_record('learnguard_attempts', ['id' => $attemptid]);
            if ($attempt) {
                $newscore = $attempt->risk_score + $scoreimpact;
                $level    = $newscore >= 60 ? 'high' : ($newscore >= 30 ? 'medium' : 'low');
                $DB->update_record('learnguard_attempts', (object)[
                    'id'           => $attemptid,
                    'risk_score'   => $newscore,
                    'risk_level'   => $level,
                    'timemodified' => time(),
                ]);
            }
        }
    }
}
