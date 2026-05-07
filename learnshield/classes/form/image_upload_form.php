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
 * User image upload form for Learn Shield V1.
 *
 * @package    quizaccess_learnshield
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link       https://www.linkedin.com/in/michaeladeniran/
 */

namespace quizaccess_learnshield\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form for uploading a base/reference image for a user.
 */
class image_upload_form extends \moodleform {

    protected function definition() {
        $mform  = $this->_form;
        $userid = $this->_customdata['userid'] ?? 0;

        $mform->addElement('hidden', 'id', $userid);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('header', 'fileuploadheader',
            get_string('upload_image_heading', 'quizaccess_learnshield'));

        $mform->addElement('html',
            \html_writer::tag('p', get_string('upload_image_info', 'quizaccess_learnshield'))
        );

        $mform->addElement('filepicker', 'userimage',
            get_string('image', 'quizaccess_learnshield'),
            null,
            ['maxbytes' => 5 * 1024 * 1024, 'accepted_types' => ['image']]
        );
        $mform->addRule('userimage', null, 'required', null, 'client');

        $this->add_action_buttons(true, get_string('upload_image', 'quizaccess_learnshield'));
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (empty($data['userimage'])) {
            $errors['userimage'] = get_string('provide_image', 'quizaccess_learnshield');
        }
        return $errors;
    }
}
