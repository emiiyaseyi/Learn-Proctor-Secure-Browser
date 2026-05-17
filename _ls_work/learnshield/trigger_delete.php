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
 * Trigger bulk deletion of all proctoring records — Learn Shield V1.
 *
 * @package    quizaccess_learnshield
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link       https://www.linkedin.com/in/michaeladeniran/
 */

require_once(__DIR__ . '/../../../../config.php');

require_login();
require_sesskey();

$context = context_system::instance();
require_capability('moodle/site:config', $context);

// Mark all logs for deletion.
$DB->set_field('quizaccess_learnshield_logs', 'deletionprogress', 1, []);

$settingsurl = new moodle_url('/admin/settings.php', ['section' => 'modsettingsquizcat']);
redirect($settingsurl, get_string('settings:deleteallsuccess', 'quizaccess_learnshield'),
    null, \core\output\notification::NOTIFY_SUCCESS);
