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
 * Incident manager for Learn Shield V1.
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
 * CRUD operations for incident case records.
 */
class incident_manager {

    const STATUS_PENDING        = 'pending';
    const STATUS_INVESTIGATING  = 'investigating';
    const STATUS_RESOLVED       = 'resolved';
    const STATUS_CONFIRMED      = 'violation_confirmed';
    const STATUS_CLEARED        = 'cleared';

    const SEVERITY_LOW    = 'low';
    const SEVERITY_MEDIUM = 'medium';
    const SEVERITY_HIGH   = 'high';

    /**
     * Automatically create a high-risk incident when the suspicion score crosses the high threshold.
     *
     * @param object $session  Session record.
     * @param int    $score    Current risk score.
     * @return int  New incident ID.
     */
    public static function auto_create_incident(object $session, int $score): int {
        return self::create_incident(
            $session->id,
            $session->courseid,
            $session->quizid,
            $session->userid,
            $session->attemptid,
            "High Risk Score: {$score}",
            "Automatically generated: session reached high risk score of {$score}.",
            self::SEVERITY_HIGH
        );
    }

    /**
     * Create a new incident record.
     *
     * @param int    $sessionid
     * @param int    $courseid
     * @param int    $quizid
     * @param int    $userid
     * @param int    $attemptid
     * @param string $title
     * @param string $description
     * @param string $severity
     * @param int    $createdby   0 = auto-generated (e.g. by suspicion_scorer).
     * @return int  New incident ID.
     */
    public static function create_incident(
        int $sessionid,
        int $courseid,
        int $quizid,
        int $userid,
        int $attemptid,
        string $title,
        string $description = '',
        string $severity = self::SEVERITY_MEDIUM,
        int $createdby = 0
    ): int {
        global $DB;

        return $DB->insert_record('quizaccess_learnshield_incidents', (object)[
            'sessionid'        => $sessionid,
            'courseid'         => $courseid,
            'quizid'           => $quizid,
            'userid'           => $userid,
            'title'            => $title,
            'description'      => $description,
            'severity'         => $severity,
            'status'           => self::STATUS_PENDING,
            'createdby'        => $createdby,
            'timemodified'     => time(),
        ]);
    }

    /**
     * Resolve an incident.
     *
     * @param int    $incidentid
     * @param int    $resolverid
     * @param string $status    One of the STATUS_* constants.
     * @param string $notes
     */
    public static function resolve(int $incidentid, int $resolverid, string $status, string $notes = ''): void {
        global $DB;

        $DB->update_record('quizaccess_learnshield_incidents', (object)[
            'id'               => $incidentid,
            'status'           => $status,
            'resolved_by'      => $resolverid,
            'resolution_notes' => $notes,
            'timemodified'     => time(),
        ]);
    }

    /**
     * Retrieve all incidents for a course/quiz.
     *
     * @param int    $courseid
     * @param int    $quizid   0 = all quizzes in course.
     * @param string $status   '' = all statuses.
     * @return array
     */
    public static function get_incidents(int $courseid, int $quizid = 0, string $status = ''): array {
        global $DB;

        $where  = 'i.courseid = :courseid';
        $params = ['courseid' => $courseid];

        if ($quizid) {
            $where .= ' AND i.quizid = :quizid';
            $params['quizid'] = $quizid;
        }
        if ($status !== '') {
            $where .= ' AND i.status = :status';
            $params['status'] = $status;
        }

        $sql = "SELECT i.*, u.firstname, u.lastname, u.email,
                       c.firstname AS creator_firstname, c.lastname AS creator_lastname
                  FROM {quizaccess_learnshield_incidents} i
                 INNER JOIN {user} u ON u.id = i.userid
                 LEFT JOIN {user} c  ON c.id = i.createdby
                 WHERE {$where}
                 ORDER BY i.timemodified DESC";

        return array_values($DB->get_records_sql($sql, $params));
    }
}
