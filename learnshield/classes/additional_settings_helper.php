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
 * Helper class for report queries in Learn Shield V1.
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
 * Provides dynamic SQL helpers for report filtering and data retrieval.
 */
class additional_settings_helper {

    /**
     * Search proctoring logs by username, email, course name, or quiz name.
     *
     * @param string $username
     * @param string $email
     * @param string $coursename
     * @param string $quizname
     * @return \moodle_recordset|array
     */
    public function search(string $username, string $email, string $coursename, string $quizname) {
        global $DB;

        $params          = [];
        $whereclausearray1 = [];
        $whereclausearray2 = [];

        foreach ([
            $this->usernamequerypart($username),
            $this->emailquerypart($email, $username),
            $this->coursenamequerypart($coursename, $username),
            $this->quiznamequerypart($quizname, $username),
        ] as $part) {
            $params          = array_merge($params, $part['params']);
            $whereclausearray1 = array_merge($whereclausearray1, $part['whereclausearray1']);
            $whereclausearray2 = array_merge($whereclausearray2, $part['whereclausearray2']);
        }

        $totalclausecount = count($whereclausearray1) + count($whereclausearray2);
        if ($totalclausecount === 0) {
            return [];
        }

        $andjoin1    = implode(' AND ', $whereclausearray1);
        $whereclause = count($whereclausearray2) > 0
            ? "({$andjoin1}) OR (" . implode(' AND ', $whereclausearray2) . ')'
            : "({$andjoin1})";

        $sql = "SELECT e.id as reportid, e.userid as studentid, e.webcampicture,
                       e.status, e.quizid, e.courseid, e.timemodified,
                       u.firstname, u.lastname, u.email,
                       c.fullname as coursename, q.name as quizname
                  FROM {quizaccess_learnshield_logs} e
                 INNER JOIN {user}           u  ON u.id  = e.userid
                 INNER JOIN {course}         c  ON c.id  = e.courseid
                 INNER JOIN {course_modules} cm ON cm.id = e.quizid
                 INNER JOIN {quiz}           q  ON q.id  = cm.instance
                 WHERE {$whereclause}";

        return $DB->get_recordset_sql($sql, $params);
    }

    /** @return array */
    public function usernamequerypart(string $username): array {
        global $DB;
        $params = $wc1 = $wc2 = [];
        if ($username !== '') {
            $split  = explode(' ', $username);
            $wc1[]  = '(' . $DB->sql_like('u.firstname', ':firstnamelike', false) . ')';
            $wc2[]  = '(' . $DB->sql_like('u.lastname',  ':lastnamelike',  false) . ')';
            $params['firstnamelike'] = count($split) > 1 ? $split[0] : $username;
            $params['lastnamelike']  = count($split) > 1 ? $split[1] : $username;
        }
        return ['params' => $params, 'whereclausearray1' => $wc1, 'whereclausearray2' => $wc2];
    }

    /** @return array */
    public function emailquerypart(string $email, string $username): array {
        global $DB;
        $params = $wc1 = $wc2 = [];
        if ($email !== '') {
            $wc1[] = '(' . $DB->sql_like('u.email', ':emaillike1', false) . ')';
            $params['emaillike1'] = $email;
            if ($username !== '') {
                $wc2[] = '(' . $DB->sql_like('u.email', ':emaillike2', false) . ')';
                $params['emaillike2'] = $email;
            }
        }
        return ['params' => $params, 'whereclausearray1' => $wc1, 'whereclausearray2' => $wc2];
    }

    /** @return array */
    public function coursenamequerypart(string $coursename, string $username): array {
        global $DB;
        $params = $wc1 = $wc2 = [];
        if ($coursename !== '') {
            $wc1[] = '(' . $DB->sql_like('c.fullname', ':coursenamelike1', false) . ')';
            $params['coursenamelike1'] = $coursename;
            if ($username !== '') {
                $wc2[] = '(' . $DB->sql_like('c.fullname', ':coursenamelike2', false) . ')';
                $params['coursenamelike2'] = $coursename;
            }
        }
        return ['params' => $params, 'whereclausearray1' => $wc1, 'whereclausearray2' => $wc2];
    }

    /** @return array */
    public function quiznamequerypart(string $quizname, string $username): array {
        global $DB;
        $params = $wc1 = $wc2 = [];
        if ($quizname !== '') {
            $wc1[] = '(' . $DB->sql_like('q.name', ':quiznamelike1', false) . ')';
            $params['quiznamelike1'] = "%{$quizname}%";
            if ($username !== '') {
                $wc2[] = '(' . $DB->sql_like('q.name', ':quiznamelike2', false) . ')';
                $params['quiznamelike2'] = "%{$quizname}%";
            }
        }
        return ['params' => $params, 'whereclausearray1' => $wc1, 'whereclausearray2' => $wc2];
    }

    /**
     * @param int $courseid
     * @return \moodle_recordset
     */
    public function searchbycourseid(int $courseid) {
        global $DB;
        return $DB->get_recordset('quizaccess_learnshield_logs', ['courseid' => $courseid]);
    }

    /**
     * @param int $quizid
     * @return \moodle_recordset
     */
    public function searchbyquizid(int $quizid) {
        global $DB;
        return $DB->get_recordset('quizaccess_learnshield_logs', ['quizid' => $quizid]);
    }

    /**
     * Return all proctoring log data with student, course, and quiz details.
     *
     * @return \moodle_recordset
     */
    public function getalldata() {
        global $DB;
        $sql = "SELECT e.id as reportid, e.userid as studentid, e.webcampicture, e.status,
                       e.quizid, e.courseid, e.timemodified, u.firstname, u.lastname, u.email,
                       c.fullname as coursename, q.name as quizname
                  FROM {quizaccess_learnshield_logs} e
                 INNER JOIN {user}           u  ON u.id  = e.userid
                 INNER JOIN {course}         c  ON c.id  = e.courseid
                 INNER JOIN {course_modules} cm ON cm.id = e.quizid
                 INNER JOIN {quiz}           q  ON q.id  = cm.instance";
        return $DB->get_recordset_sql($sql);
    }

    /**
     * Delete proctoring log records and their associated files.
     *
     * @param string $deleteidstring Comma-separated log IDs.
     */
    public function deletelogs(string $deleteidstring): void {
        global $DB;
        $deleteids = explode(',', $deleteidstring);
        if (empty($deleteids)) {
            return;
        }

        $logs = $DB->get_records_list('quizaccess_learnshield_logs', 'id', $deleteids);
        foreach ($logs as $row) {
            $patharray = explode('/', $row->webcampicture);
            $filename  = end($patharray);

            $DB->delete_records('quizaccess_learnshield_fm_warnings', ['reportid' => $row->id]);
            $DB->delete_records('quizaccess_learnshield_logs',        ['id'       => $row->id]);

            $usersfiles = $DB->get_records_select('files',
                'component = :component AND filearea = :filearea AND filename = :filename',
                ['component' => 'quizaccess_learnshield', 'filearea' => 'picture', 'filename' => $filename]
            );
            foreach ($usersfiles as $filerow) {
                $this->deletefile($filerow);
            }
        }
    }

    /**
     * Delete a single file from Moodle file storage.
     *
     * @param object $filerow
     */
    public function deletefile(object $filerow): void {
        $fs   = get_file_storage();
        $file = $fs->get_file(
            $filerow->contextid, 'quizaccess_learnshield', 'picture',
            $filerow->itemid, '/', $filerow->filename
        );
        if ($file) {
            $file->delete();
        }
    }
}
