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
 * Link generator helper for Learn Shield V1.
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
 * Generates URLs for Learn Shield report and dashboard pages.
 */
class link_generator {

    /**
     * Build the URL for the proctoring report page.
     *
     * @param int  $courseid
     * @param int  $cmid
     * @param bool $download  Whether to request a downloadable format.
     * @param bool $https     Whether to force HTTPS.
     * @return \moodle_url
     */
    public static function get_link(int $courseid, int $cmid, bool $download = false, bool $https = false): \moodle_url {
        $params = [
            'courseid' => $courseid,
            'cmid'     => $cmid,
        ];
        if ($download) {
            $params['download'] = 1;
        }
        return new \moodle_url('/mod/quiz/accessrule/learnshield/report.php', $params);
    }

    /**
     * Build the URL for the live proctor dashboard.
     *
     * @param int $courseid
     * @param int $cmid
     * @return \moodle_url
     */
    public static function get_dashboard_link(int $courseid, int $cmid): \moodle_url {
        return new \moodle_url('/mod/quiz/accessrule/learnshield/proctordashboard.php', [
            'courseid' => $courseid,
            'cmid'     => $cmid,
        ]);
    }

    /**
     * Build the URL for the incident review page.
     *
     * @param int $courseid
     * @param int $cmid
     * @return \moodle_url
     */
    public static function get_incident_link(int $courseid, int $cmid): \moodle_url {
        return new \moodle_url('/mod/quiz/accessrule/learnshield/incidentreview.php', [
            'courseid' => $courseid,
            'cmid'     => $cmid,
        ]);
    }

    /**
     * Build the URL for the audit log viewer.
     *
     * @param int $courseid
     * @param int $cmid
     * @return \moodle_url
     */
    public static function get_auditlog_link(int $courseid, int $cmid): \moodle_url {
        return new \moodle_url('/mod/quiz/accessrule/learnshield/auditlog.php', [
            'courseid' => $courseid,
            'cmid'     => $cmid,
        ]);
    }

    /**
     * Build the URL for the course-level all-attempts backend.
     *
     * @param int $courseid
     * @param int $cmid     Optional — filter to a specific quiz.
     * @return \moodle_url
     */
    public static function get_attempts_link(int $courseid, int $cmid = 0): \moodle_url {
        return new \moodle_url('/mod/quiz/accessrule/learnshield/attempts.php', [
            'courseid' => $courseid,
            'cmid'     => $cmid,
        ]);
    }

    /**
     * Build the URL for the per-attempt detail report.
     *
     * @param int $courseid
     * @param int $quizid   course_module id
     * @param int $userid
     * @return \moodle_url
     */
    public static function get_attempt_detail_link(int $courseid, int $quizid, int $userid): \moodle_url {
        return new \moodle_url('/mod/quiz/accessrule/learnshield/attemptdetail.php', [
            'courseid' => $courseid,
            'quizid'   => $quizid,
            'userid'   => $userid,
        ]);
    }
}
