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
 * Session manager for Learn Shield V1.
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
 * Manages proctoring session lifecycle.
 */
class session_manager {

    const STATUS_ACTIVE     = 'active';
    const STATUS_SUSPENDED  = 'suspended';
    const STATUS_COMPLETED  = 'completed';
    const STATUS_TERMINATED = 'terminated';

    /**
     * Find or create the session record for an attempt.
     *
     * @param int    $courseid
     * @param int    $quizid
     * @param int    $userid
     * @param int    $attemptid
     * @param string $browserinfo
     * @param string $ipaddress
     * @return int Session ID.
     */
    public static function get_or_create(
        int $courseid,
        int $quizid,
        int $userid,
        int $attemptid,
        string $browserinfo = '',
        string $ipaddress = ''
    ): int {
        global $DB;

        $existing = $DB->get_record('quizaccess_learnshield_sessions', [
            'courseid'  => $courseid,
            'quizid'    => $quizid,
            'userid'    => $userid,
            'attemptid' => $attemptid,
        ]);

        if ($existing) {
            return $existing->id;
        }

        return $DB->insert_record('quizaccess_learnshield_sessions', (object)[
            'courseid'             => $courseid,
            'quizid'               => $quizid,
            'userid'               => $userid,
            'attemptid'            => $attemptid,
            'status'               => self::STATUS_ACTIVE,
            'risk_score'           => 0,
            'risk_level'           => 'low',
            'start_time'           => time(),
            'ip_address'           => $ipaddress ?: getremoteaddr(),
            'browser_info'         => $browserinfo ?: ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'consent_given'        => 1,
            'fullscreen_exits'     => 0,
            'tab_switches'         => 0,
            'clipboard_events'     => 0,
            'keyboard_violations'  => 0,
            'face_missing_count'   => 0,
            'is_flagged'           => 0,
            'timemodified'         => time(),
        ]);
    }

    /**
     * Mark a session as completed.
     *
     * @param int $sessionid
     */
    public static function complete(int $sessionid): void {
        global $DB;
        $DB->update_record('quizaccess_learnshield_sessions', (object)[
            'id'           => $sessionid,
            'status'       => self::STATUS_COMPLETED,
            'end_time'     => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Suspend a session (proctor action).
     *
     * @param int $sessionid
     * @param int $proctorid
     */
    public static function suspend(int $sessionid, int $proctorid): void {
        global $DB;

        $session = $DB->get_record('quizaccess_learnshield_sessions', ['id' => $sessionid], '*', MUST_EXIST);

        $DB->update_record('quizaccess_learnshield_sessions', (object)[
            'id'           => $sessionid,
            'status'       => self::STATUS_SUSPENDED,
            'timemodified' => time(),
        ]);

        event_tracker::log($sessionid, $session->courseid, $session->quizid, $session->userid,
            $session->attemptid, event_tracker::TYPE_SUSPENSION,
            json_encode(['proctor_id' => $proctorid]), event_tracker::SEVERITY_DANGER, 0
        );
    }

    /**
     * Return all active sessions, optionally filtered.
     *
     * @param int $courseid 0 = all courses.
     * @param int $quizid   0 = all quizzes.
     * @return array
     */
    public static function get_active_sessions(int $courseid = 0, int $quizid = 0): array {
        global $DB;

        // Match the 10-minute staleness window used by the live-monitor poll
        // (get_live_sessions) so the initial page-load counts/tiles agree with
        // the first JS refresh instead of a stale session inflating them.
        $where  = "s.status = 'active' AND s.timemodified >= :activesince";
        $params = ['activesince' => time() - 600];

        if ($courseid) {
            $where .= ' AND s.courseid = :courseid';
            $params['courseid'] = $courseid;
        }
        if ($quizid) {
            $where .= ' AND s.quizid = :quizid';
            $params['quizid'] = $quizid;
        }

        $sql = "SELECT s.*, u.firstname, u.lastname, u.email
                  FROM {quizaccess_learnshield_sessions} s
                 INNER JOIN {user} u ON u.id = s.userid
                 WHERE {$where}
                 ORDER BY s.risk_score DESC";

        return array_values($DB->get_records_sql($sql, $params));
    }

    /**
     * Return a single session by ID with user info.
     *
     * @param int $sessionid
     * @return object|false
     */
    public static function get_session(int $sessionid) {
        global $DB;

        $sql = "SELECT s.*, u.firstname, u.lastname, u.email,
                       c.fullname as coursename, q.name as quizname
                  FROM {quizaccess_learnshield_sessions} s
                 INNER JOIN {user}           u  ON u.id  = s.userid
                 INNER JOIN {course}         c  ON c.id  = s.courseid
                 INNER JOIN {course_modules} cm ON cm.id = s.quizid
                 INNER JOIN {quiz}           q  ON q.id  = cm.instance
                 WHERE s.id = :sessionid";

        return $DB->get_record_sql($sql, ['sessionid' => $sessionid]);
    }
}
