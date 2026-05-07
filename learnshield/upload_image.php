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
 * Upload reference image for a user — Learn Shield V1.
 *
 * @package    quizaccess_learnshield
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link       https://www.linkedin.com/in/michaeladeniran/
 */

require_once(__DIR__ . '/../../../../config.php');

use quizaccess_learnshield\form\image_upload_form;

$userid = required_param('id', PARAM_INT);

require_login();

$context = context_system::instance();
require_capability('quizaccess/learnshield:uploaduserimages', $context);

$user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);

$PAGE->set_url(new moodle_url('/mod/quiz/accessrule/learnshield/upload_image.php', ['id' => $userid]));
$PAGE->set_context($context);
$PAGE->set_heading(get_string('upload_image_heading', 'quizaccess_learnshield'));
$PAGE->set_title(get_string('upload_image_title', 'quizaccess_learnshield'));
$PAGE->requires->css('/mod/quiz/accessrule/learnshield/styles.css');

$mform = new image_upload_form(null, ['userid' => $userid]);

$returnurl = new moodle_url('/mod/quiz/accessrule/learnshield/userslist.php');

if ($mform->is_cancelled()) {
    redirect($returnurl);
} else if ($formdata = $mform->get_data()) {
    // Remove existing reference image.
    $existingfile = quizaccess_learnshield_get_image_file($userid);
    if ($existingfile) {
        $existingfile->delete();
    }

    // Save new image.
    $draftitemid = $formdata->userimage;
    $context     = context_system::instance();

    file_save_draft_area_files($draftitemid, $context->id, 'quizaccess_learnshield', 'user_photo', $userid, [
        'subdirs'  => 0,
        'maxfiles' => 1,
    ]);

    // Update DB record.
    $existing = $DB->get_record('quizaccess_learnshield_user_images', ['user_id' => $userid]);
    if ($existing) {
        $existing->photo_draft_id = $draftitemid;
        $DB->update_record('quizaccess_learnshield_user_images', $existing);
    } else {
        $DB->insert_record('quizaccess_learnshield_user_images', (object)[
            'user_id'       => $userid,
            'photo_draft_id'=> $draftitemid,
        ]);
    }

    redirect($returnurl, get_string('image_updated', 'quizaccess_learnshield'),
        null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('upload_image_heading', 'quizaccess_learnshield') . ': ' . fullname($user));
echo html_writer::tag('p', get_string('upload_image_info', 'quizaccess_learnshield'));
$mform->display();
echo $OUTPUT->footer();
