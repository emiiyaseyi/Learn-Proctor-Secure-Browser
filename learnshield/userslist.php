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
 * Users list (base image management) for Learn Shield V1.
 *
 * @package    quizaccess_learnshield
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link       https://www.linkedin.com/in/michaeladeniran/
 */

require_once(__DIR__ . '/../../../../config.php');

require_login();

$context = context_system::instance();
require_capability('quizaccess/learnshield:uploaduserimages', $context);

$PAGE->set_url(new moodle_url('/mod/quiz/accessrule/learnshield/userslist.php'));
$PAGE->set_context($context);
$PAGE->set_heading(get_string('users_list', 'quizaccess_learnshield'));
$PAGE->set_title(get_string('users_list',   'quizaccess_learnshield'));
$PAGE->requires->css('/mod/quiz/accessrule/learnshield/styles.css');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('users_list', 'quizaccess_learnshield'));
echo html_writer::tag('p', get_string('users_list_info_description', 'quizaccess_learnshield'));

// Fetch all users who need a base image.
$sql = "SELECT u.id, u.firstname, u.lastname, u.email,
               ui.id as image_record_id
          FROM {user} u
     LEFT JOIN {quizaccess_learnshield_user_images} ui ON ui.user_id = u.id
         WHERE u.deleted = 0 AND u.suspended = 0 AND u.id <> :guestid
         ORDER BY u.lastname ASC, u.firstname ASC";
$users = $DB->get_records_sql($sql, ['guestid' => guest_user()->id]);

echo html_writer::start_tag('table', ['class' => 'table table-sm table-bordered']);
echo html_writer::start_tag('thead');
echo html_writer::start_tag('tr');
foreach (['Name', 'Email', 'Image Status', 'Action'] as $h) {
    echo html_writer::tag('th', $h);
}
echo html_writer::end_tag('tr');
echo html_writer::end_tag('thead');
echo html_writer::start_tag('tbody');

foreach ($users as $user) {
    $hasimage = !empty($user->image_record_id);
    $imgurl   = quizaccess_learnshield_get_image_url($user->id);
    $uploadurl = new moodle_url('/mod/quiz/accessrule/learnshield/upload_image.php', ['id' => $user->id]);
    $deleteurl = new moodle_url('/mod/quiz/accessrule/learnshield/delete_user_image.php', [
        'id' => $user->id, 'sesskey' => sesskey(),
    ]);

    echo html_writer::start_tag('tr');
    echo html_writer::tag('td', s(fullname($user)));
    echo html_writer::tag('td', s($user->email));
    echo html_writer::tag('td',
        $hasimage
            ? html_writer::tag('span', 'Uploaded', ['class' => 'badge badge-success'])
            : html_writer::tag('span', 'Missing',  ['class' => 'badge badge-danger'])
    );
    echo html_writer::start_tag('td');
    echo html_writer::link($uploadurl,
        $hasimage ? 'Update Image' : get_string('upload_image', 'quizaccess_learnshield'),
        ['class' => 'btn btn-sm btn-primary mr-1']
    );
    if ($hasimage) {
        echo html_writer::link($deleteurl, get_string('delete', 'quizaccess_learnshield'),
            ['class' => 'btn btn-sm btn-danger']);
    }
    echo html_writer::end_tag('td');
    echo html_writer::end_tag('tr');
}

echo html_writer::end_tag('tbody');
echo html_writer::end_tag('table');

echo $OUTPUT->footer();
