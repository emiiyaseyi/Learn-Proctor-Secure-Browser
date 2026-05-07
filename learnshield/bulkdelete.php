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
 * Bulk delete proctoring data for a quiz — Learn Shield V1.
 *
 * @package    quizaccess_learnshield
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link       https://www.linkedin.com/in/michaeladeniran/
 */

require_once(__DIR__ . '/../../../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$cmid     = required_param('cmid',     PARAM_INT);

require_login();
require_sesskey();

$cm      = get_coursemodule_from_id('quiz', $cmid, $courseid, false, MUST_EXIST);
$context = context_module::instance($cm->id);
require_capability('quizaccess/learnshield:deletecamshots', $context);

// Mark records for deletion.
$DB->set_field('quizaccess_learnshield_logs', 'deletionprogress', 1, [
    'courseid' => $courseid,
    'quizid'   => $cmid,
]);

$summaryurl = new moodle_url('/mod/quiz/accessrule/learnshield/proctoringsummary.php', ['courseid' => $courseid]);
redirect($summaryurl, get_string('settings:deleteallsuccess', 'quizaccess_learnshield'),
    null, \core\output\notification::NOTIFY_SUCCESS);
