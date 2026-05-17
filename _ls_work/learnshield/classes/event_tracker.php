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
 * Event tracker for Learn Shield V1.
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
 * Writes immutable event records to the audit trail table.
 */
class event_tracker {

    const TYPE_WEBCAM_SNAPSHOT    = 'webcam_snapshot';
    const TYPE_TAB_SWITCH         = 'tab_switch';
    const TYPE_FULLSCREEN_EXIT    = 'fullscreen_exit';
    const TYPE_KEYBOARD_VIOLATION = 'keyboard_violation';
    const TYPE_CLIPBOARD_COPY     = 'clipboard_copy';
    const TYPE_CLIPBOARD_PASTE    = 'clipboard_paste';
    const TYPE_FACE_MISSING       = 'face_missing';
    const TYPE_PROCTOR_FLAG       = 'proctor_flag';
    const TYPE_WARNING_SENT       = 'warning_sent';
    const TYPE_SUSPENSION         = 'suspension';
    const TYPE_CHAT               = 'chat';
    const TYPE_CONSENT            = 'consent';
    const TYPE_SESSION_START      = 'session_start';
    const TYPE_SESSION_END        = 'session_end';
    const TYPE_FACE_MATCH_PASS    = 'face_match_pass';
    const TYPE_FACE_MATCH_FAIL    = 'face_match_fail';

    const SEVERITY_INFO    = 'info';
    const SEVERITY_WARNING = 'warning';
    const SEVERITY_DANGER  = 'danger';

    /**
     * Insert a single event record (immutable — never updated).
     *
     * @param int    $sessionid
     * @param int    $courseid
     * @param int    $quizid
     * @param int    $userid
     * @param int    $attemptid
     * @param string $eventtype
     * @param string $eventdata   JSON-encoded extra data.
     * @param string $severity
     * @param int    $scoreimpact
     * @return int   New event record ID.
     */
    public static function log(
        int $sessionid,
        int $courseid,
        int $quizid,
        int $userid,
        int $attemptid,
        string $eventtype,
        string $eventdata = '{}',
        string $severity = self::SEVERITY_INFO,
        int $scoreimpact = 0
    ): int {
        global $DB;

        return $DB->insert_record('quizaccess_learnshield_events', (object)[
            'sessionid'    => $sessionid,
            'courseid'     => $courseid,
            'quizid'       => $quizid,
            'userid'       => $userid,
            'attemptid'    => $attemptid,
            'event_type'   => $eventtype,
            'event_data'   => $eventdata,
            'severity'     => $severity,
            'score_impact' => $scoreimpact,
            'timemodified' => time(),
        ]);
    }

    /**
     * Return all events for a session, ordered oldest first.
     *
     * @param int $sessionid
     * @return array
     */
    public static function get_session_events(int $sessionid): array {
        global $DB;
        return array_values($DB->get_records(
            'quizaccess_learnshield_events',
            ['sessionid' => $sessionid],
            'timemodified ASC'
        ));
    }

    /**
     * Return events for a specific user/quiz, newest first, limited.
     *
     * @param int $userid
     * @param int $quizid
     * @param int $limit
     * @return array
     */
    public static function get_recent_events(int $userid, int $quizid, int $limit = 50): array {
        global $DB;
        return array_values($DB->get_records(
            'quizaccess_learnshield_events',
            ['userid' => $userid, 'quizid' => $quizid],
            'timemodified DESC',
            '*', 0, $limit
        ));
    }

    /**
     * Count events of a given type for a session.
     *
     * @param int    $sessionid
     * @param string $eventtype
     * @return int
     */
    public static function count_events(int $sessionid, string $eventtype): int {
        global $DB;
        return $DB->count_records('quizaccess_learnshield_events', [
            'sessionid'  => $sessionid,
            'event_type' => $eventtype,
        ]);
    }

    /**
     * Determine the severity label for an event type.
     *
     * @param string $eventtype
     * @return string
     */
    public static function severity_for_type(string $eventtype): string {
        $danger  = [self::TYPE_SUSPENSION, self::TYPE_FACE_MATCH_FAIL];
        $warning = [
            self::TYPE_TAB_SWITCH, self::TYPE_FULLSCREEN_EXIT,
            self::TYPE_KEYBOARD_VIOLATION, self::TYPE_CLIPBOARD_COPY,
            self::TYPE_CLIPBOARD_PASTE, self::TYPE_FACE_MISSING,
            self::TYPE_PROCTOR_FLAG, self::TYPE_WARNING_SENT,
        ];

        if (in_array($eventtype, $danger,  true)) {
            return self::SEVERITY_DANGER;
        }
        if (in_array($eventtype, $warning, true)) {
            return self::SEVERITY_WARNING;
        }
        return self::SEVERITY_INFO;
    }
}
