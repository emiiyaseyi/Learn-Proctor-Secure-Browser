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
 * Activity creation/editing form for mod_learnguard.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link       https://www.linkedin.com/in/michaeladeniran/
 */

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/course/moodleform_mod.php');

/**
 * Module form for LearnGuard.
 */
class mod_learnguard_mod_form extends moodleform_mod {

    public function definition() {
        $mform = $this->_form;

        // ---- General ----
        $mform->addElement('header', 'general', get_string('general', 'form'));

        // Assessment name / title — shown on the course page for learners.
        $mform->addElement('text', 'name', get_string('assessmentname', 'learnguard'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        // Description / intro.
        $this->standard_intro_elements();

        // ---- Exam instructions ----
        $mform->addElement('editor', 'instructions_editor',
            get_string('instructions', 'learnguard'),
            ['rows' => 6], ['maxfiles' => 0, 'maxbytes' => 0, 'trusttext' => false]);
        $mform->setType('instructions_editor', PARAM_RAW);

        // ---- Timer ----
        $mform->addElement('header', 'timing', get_string('timing', 'quiz'));
        $mform->addElement('duration', 'timelimit',
            get_string('timelimit', 'learnguard'),
            ['optional' => true, 'defaultunit' => 60]);
        $mform->setDefault('timelimit', 0);

        // ---- Attempts ----
        $mform->addElement('header', 'attemptsheader', get_string('attemptsallowed', 'quiz'));

        $attemptsoptions = [0 => get_string('unlimited')];
        for ($i = 1; $i <= 10; $i++) {
            $attemptsoptions[$i] = $i;
        }
        $mform->addElement('select', 'attempts', get_string('maxattempts', 'learnguard'), $attemptsoptions);
        $mform->setDefault('attempts', 1);

        // ---- Question layout ----
        $mform->addElement('header', 'layoutheader', get_string('layout', 'quiz'));

        $perpageopts = [];
        for ($i = 1; $i <= 50; $i++) {
            $perpageopts[$i] = $i;
        }
        $mform->addElement('select', 'questionsperpage',
            get_string('questionsperpage', 'learnguard'), $perpageopts);
        $mform->setDefault('questionsperpage', 1);

        $mform->addElement('advcheckbox', 'shufflequestions', get_string('shufflequestions', 'learnguard'));
        $mform->addElement('advcheckbox', 'shuffleanswers',   get_string('shuffleanswers',   'learnguard'));
        $mform->setDefault('shuffleanswers', 1);

        // ---- Grading ----
        $mform->addElement('header', 'gradeheader', get_string('grading', 'learnguard'));

        $mform->addElement('text', 'grade', get_string('maximumgrade', 'learnguard'), ['size' => 6]);
        $mform->setType('grade', PARAM_INT);
        $mform->setDefault('grade', 100);
        $mform->addHelpButton('grade', 'maximumgrade', 'learnguard');

        $mform->addElement('text', 'gradepass', get_string('gradepass', 'grades'), ['size' => 6]);
        $mform->setType('gradepass', PARAM_FLOAT);
        $mform->setDefault('gradepass', 0);
        $mform->addHelpButton('gradepass', 'gradepass', 'grades');

        $mform->addElement('select', 'grademethod',
            get_string('grademethod', 'learnguard'),
            [
                1 => get_string('grademethod_highest', 'learnguard'),
                2 => get_string('grademethod_average',  'learnguard'),
                3 => get_string('grademethod_first',    'learnguard'),
                4 => get_string('grademethod_last',     'learnguard'),
            ]
        );
        $mform->setDefault('grademethod', 1);

        // ---- Proctoring ----
        $mform->addElement('header', 'proctoring_header',
            get_string('proctoring_heading', 'learnguard'));

        $mform->addElement('advcheckbox', 'proctoring', get_string('proctoring', 'learnguard'));
        $mform->setDefault('proctoring', 1);

        // Per-feature proctoring options.
        $proctoringsettings = [
            'proctor_fullscreen' => get_string('proctor_fullscreen', 'learnguard'),
            'proctor_webcam'     => get_string('proctor_webcam',     'learnguard'),
            'proctor_audio'      => get_string('proctor_audio',      'learnguard'),
            'proctor_tabdetect'  => get_string('proctor_tabblock',   'learnguard'),
            'proctor_keyblock'   => get_string('proctor_keyblock',   'learnguard'),
            'proctor_clipboard'  => get_string('proctor_clipboard',  'learnguard'),
            'proctor_env_record' => get_string('proctor_env_record', 'learnguard'),
            'block_mobile'       => get_string('block_mobile',       'learnguard'),
        ];
        foreach ($proctoringsettings as $name => $label) {
            $mform->addElement('advcheckbox', $name, $label);
            $mform->setDefault($name, 1);
            $mform->hideIf($name, 'proctoring', 'eq', 0);
        }

        $mform->addElement('text', 'proctor_snapinterval',
            get_string('proctor_snapinterval', 'learnguard'), ['size' => 5]);
        $mform->setType('proctor_snapinterval', PARAM_INT);
        $mform->setDefault('proctor_snapinterval', 30);
        $mform->hideIf('proctor_snapinterval', 'proctoring', 'eq', 0);

        $mform->addElement('select', 'proctor_fcmethod',
            get_string('proctor_fcmethod', 'learnguard'),
            [
                'None'  => get_string('fcmethod_none',  'learnguard'),
                'Local' => get_string('fcmethod_local', 'learnguard'),
                'BS'    => get_string('fcmethod_bs',    'learnguard'),
            ]
        );
        $mform->setDefault('proctor_fcmethod', 'None');
        $mform->hideIf('proctor_fcmethod', 'proctoring', 'eq', 0);

        $mform->addElement('text', 'autosuspend_score',
            get_string('autosuspend_score', 'learnguard'), ['size' => 5]);
        $mform->setType('autosuspend_score', PARAM_INT);
        $mform->setDefault('autosuspend_score', 80);
        $mform->addHelpButton('autosuspend_score', 'autosuspend_score', 'learnguard');
        $mform->hideIf('autosuspend_score', 'proctoring', 'eq', 0);

        // Standard module elements (availability, groups, completion, etc.)
        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    public function data_preprocessing(&$defaultvalues) {
        parent::data_preprocessing($defaultvalues);

        // Restore the intro editor.
        if (isset($defaultvalues['instructions'])) {
            $defaultvalues['instructions_editor'] = [
                'text'   => $defaultvalues['instructions'],
                'format' => $defaultvalues['instructionsformat'] ?? FORMAT_HTML,
            ];
        }

        // Load grade to pass from gradebook.
        if (!empty($defaultvalues['id'])) {
            require_once($CFG->libdir . '/gradelib.php');
            $gi = grade_item::fetch([
                'itemtype'   => 'mod',
                'itemmodule' => 'learnguard',
                'iteminstance' => $defaultvalues['id'],
            ]);
            if ($gi) {
                $defaultvalues['gradepass'] = $gi->gradepass ?? 0;
            }
        }
    }

    public function get_data() {
        $data = parent::get_data();
        if ($data && isset($data->instructions_editor)) {
            $data->instructions       = $data->instructions_editor['text'];
            $data->instructionsformat = $data->instructions_editor['format'];
        }
        return $data;
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if (!empty($data['grade']) && $data['grade'] < 0) {
            $errors['grade'] = get_string('error:gradenegative', 'learnguard');
        }
        if (!empty($data['gradepass']) && !empty($data['grade']) &&
                $data['gradepass'] > $data['grade']) {
            $errors['gradepass'] = get_string('gradepassgreaterthangrade', 'grades');
        }

        return $errors;
    }
}
