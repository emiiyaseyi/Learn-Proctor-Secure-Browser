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

        // ---- Availability window ----
        $mform->addElement('header', 'availabilityheader', get_string('availability'));

        $mform->addElement('date_time_selector', 'timeopen',
            get_string('assessmentopen', 'learnguard'), ['optional' => true]);
        $mform->setDefault('timeopen', 0);
        $mform->addHelpButton('timeopen', 'assessmentopen', 'learnguard');

        $mform->addElement('date_time_selector', 'timeclose',
            get_string('assessmentclose', 'learnguard'), ['optional' => true]);
        $mform->setDefault('timeclose', 0);
        $mform->addHelpButton('timeclose', 'assessmentclose', 'learnguard');

        // ---- Exam Access PIN ----
        $mform->addElement('header', 'pinheader', get_string('accesspin', 'learnguard'));
        $mform->addElement('text', 'access_pin', get_string('accesspin', 'learnguard'),
            ['size' => 20, 'autocomplete' => 'off']);
        $mform->setType('access_pin', PARAM_TEXT);
        $mform->setDefault('access_pin', '');
        $mform->addHelpButton('access_pin', 'accesspin', 'learnguard');
        $mform->addElement('static', 'access_pin_info', '',
            '<span style="font-size:12px;color:#6c757d;">Leave blank to disable. Students must enter this PIN before starting the exam.</span>');

        // ---- Pre-exam notification ----
        $mform->addElement('header', 'notifheader', 'Pre-exam Email Notification');
        $notifOptions = [0 => 'Disabled', 1 => '1 hour before', 2 => '2 hours before',
                         6 => '6 hours before', 12 => '12 hours before', 24 => '24 hours before', 48 => '48 hours before'];
        $mform->addElement('select', 'notification_hours', 'Notify students before opening', $notifOptions);
        $mform->setDefault('notification_hours', 0);
        $mform->addElement('static', 'notif_info', '',
            '<span style="font-size:12px;color:#6c757d;">Sends an email reminder to enrolled students before the assessment opens. Requires Moodle cron to be running.</span>');

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

        // ---- Question randomization ----
        $mform->addElement('header', 'randomizeheader', get_string('randomize_heading', 'learnguard'));

        $mform->addElement('advcheckbox', 'randomize',
            get_string('randomize', 'learnguard'));
        $mform->setDefault('randomize', 0);
        $mform->addHelpButton('randomize', 'randomize', 'learnguard');

        $mform->addElement('text', 'num_questions',
            get_string('num_questions', 'learnguard'), ['size' => 5]);
        $mform->setType('num_questions', PARAM_INT);
        $mform->setDefault('num_questions', 0);
        $mform->addHelpButton('num_questions', 'num_questions', 'learnguard');
        $mform->hideIf('num_questions', 'randomize', 'eq', 0);

        // ---- Pathway / role-based question selection (Choice Essay only) ----
        // A student picks a "pathway" (a Grading Unit, e.g. a role/track) before their
        // exam clock starts, and the system randomly draws N Choice Essay questions from
        // that unit plus M from the admin-selected "other units" pool. Deliberately its
        // own mode, separate from the randomize/pool-rules settings above — this only
        // ever applies to Choice Essay questions and needs a student decision first.
        $mform->addElement('header', 'pathwayheader', 'Pathway / Role-Based Selection (Choice Essay only)');

        $mform->addElement('advcheckbox', 'pathway_enabled',
            'Enable pathway/role-based question selection');
        $mform->setDefault('pathway_enabled', 0);
        $mform->addElement('static', 'pathway_enabled_desc', '',
            '<span style="font-size:12px;color:#6c757d;">Before the exam clock starts, the ' .
            'student picks one of the units below as their "pathway" — the system then ' .
            'randomly draws Choice Essay questions from that unit and from the selected ' .
            '"other units" pool below. Requires Choice Essay questions already tagged with ' .
            'Grading Units.</span>');
        $mform->hideIf('pathway_enabled_desc', 'pathway_enabled', 'eq', 0);

        $mform->addElement('text', 'pathway_own_count',
            'Questions to draw from the chosen unit', ['size' => 5]);
        $mform->setType('pathway_own_count', PARAM_INT);
        $mform->setDefault('pathway_own_count', 0);
        $mform->hideIf('pathway_own_count', 'pathway_enabled', 'eq', 0);

        $mform->addElement('text', 'pathway_other_count',
            'Questions to draw from the "other units" pool, combined', ['size' => 5]);
        $mform->setType('pathway_other_count', PARAM_INT);
        $mform->setDefault('pathway_other_count', 0);
        $mform->hideIf('pathway_other_count', 'pathway_enabled', 'eq', 0);

        global $DB;
        $allGradingUnitsForForm = $DB->get_records('learnguard_grading_units', null, 'sortorder ASC, name ASC');
        $unitChoices = [];
        foreach ($allGradingUnitsForForm as $u) { $unitChoices[$u->id] = format_string($u->name); }
        if (empty($unitChoices)) {
            $mform->addElement('static', 'pathway_no_units', '',
                '<em>No Grading Units exist yet — create some on Site Administration → LearnGuard → ' .
                'Grading Units first, then come back here to configure pathways.</em>');
            $mform->hideIf('pathway_no_units', 'pathway_enabled', 'eq', 0);
        } else {
            $pathwaySelect = $mform->addElement('select', 'pathway_other_units',
                'Which units count as the "other units" pool?', $unitChoices);
            $pathwaySelect->setMultiple(true);
            $mform->setType('pathway_other_units', PARAM_INT);
            $mform->hideIf('pathway_other_units', 'pathway_enabled', 'eq', 0);
        }

        // ---- Choice Essay Grading Rubric (activity-wide, shared by every Choice Essay
        // question in this assessment) ----
        $mform->addElement('header', 'rubricheader', 'Choice Essay Grading Rubric');

        $mform->addElement('advcheckbox', 'rubric_enabled',
            'Enable a scoring rubric for Choice Essay grading');
        $mform->setDefault('rubric_enabled', 0);
        $mform->addElement('static', 'rubric_enabled_desc', '',
            '<span style="font-size:12px;color:#6c757d;">Define your own criteria below — name, ' .
            'weight (%), and what the grader should look for; weights should add up to 100. ' .
            'Once enabled, every Choice Essay question in this assessment is graded against ' .
            'these criteria instead of a single mark box. Does not affect plain Essay questions.</span>');
        $mform->hideIf('rubric_enabled_desc', 'rubric_enabled', 'eq', 0);

        $mform->addElement('advcheckbox', 'rubric_show_learner',
            'Show the rubric to students before/while answering');
        $mform->setDefault('rubric_show_learner', 1);
        $mform->hideIf('rubric_show_learner', 'rubric_enabled', 'eq', 0);

        $mform->addElement('advcheckbox', 'rubric_show_total_grader',
            'Let the grader see the running total while entering scores');
        $mform->setDefault('rubric_show_total_grader', 0);
        $mform->addElement('static', 'rubric_show_total_grader_desc', '',
            '<span style="font-size:12px;color:#6c757d;">Off by default — the grader sees each ' .
            'criterion\'s score box and the weight, but not a computed total, unless enabled here.</span>');
        $mform->hideIf('rubric_show_total_grader', 'rubric_enabled', 'eq', 0);
        $mform->hideIf('rubric_show_total_grader_desc', 'rubric_enabled', 'eq', 0);

        $rubricrepeatarray = [
            $mform->createElement('text', 'rubric_name', 'Criterion name', ['size' => 28]),
            $mform->createElement('text', 'rubric_weight', 'Weight %', ['size' => 4]),
            $mform->createElement('textarea', 'rubric_focus', 'Assessment focus (what the grader should look for)',
                ['rows' => 2, 'cols' => 40]),
        ];
        $rubricrepeatoptions = [
            'rubric_name'   => ['type' => PARAM_TEXT],
            'rubric_weight' => ['type' => PARAM_INT],
            'rubric_focus'  => ['type' => PARAM_TEXT],
        ];
        $existingCriteriaCount = 0;
        if (!empty($this->_instance)) {
            global $DB;
            $existingCriteriaCount = $DB->count_records('learnguard_rubric_criteria', ['learnguard' => $this->_instance]);
        }
        $this->repeat_elements($rubricrepeatarray, max(3, $existingCriteriaCount + 1), $rubricrepeatoptions,
            'rubric_criteria_repeats', 'rubric_criteria_add', 1, 'Add another criterion', true,
            'rubric_criteria_delete');

        // ---- Result visibility ----
        $mform->addElement('header', 'resultsheader', get_string('results_heading', 'learnguard'));

        $mform->addElement('select', 'show_results',
            get_string('show_results', 'learnguard'),
            [
                1 => get_string('show_results_immediate', 'learnguard'),
                0 => get_string('show_results_held',      'learnguard'),
            ]
        );
        $mform->setDefault('show_results', 1);
        $mform->addHelpButton('show_results', 'show_results', 'learnguard');

        // ---- Proctoring ----
        $mform->addElement('header', 'proctoring_header',
            get_string('proctoring_heading', 'learnguard'));

        $mform->addElement('advcheckbox', 'proctoring', get_string('proctoring', 'learnguard'));
        $mform->setDefault('proctoring', (int)(get_config('learnguard', 'proctoring') ?? 1));

        // Per-feature proctoring options. Defaults come from Site Administration →
        // Plugins → LearnGuard → "Default Proctoring Settings" (admin_setting_configcheckbox
        // of the same name), so an admin can actually change what pre-fills for new
        // assessments instead of every new activity silently getting a hardcoded 1.
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
            $mform->setDefault($name, (int)(get_config('learnguard', $name) ?? 1));
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
        $mform->setDefault('proctor_fcmethod', 'Local');
        $mform->hideIf('proctor_fcmethod', 'proctoring', 'eq', 0);

        $mform->addElement('text', 'autosuspend_score',
            get_string('autosuspend_score', 'learnguard'),
            ['size' => 5, 'placeholder' => 'Site default (' . (int)(get_config('learnguard', 'autosuspend_score') ?: 80) . ')']);
        // PARAM_RAW_TRIMMED, not PARAM_INT — a blank submission must stay blank (meaning
        // "inherit the site default"), not get coerced to 0 (which already separately
        // means "auto-suspend disabled"). See get_data()/validation() below for the real cast.
        $mform->setType('autosuspend_score', PARAM_RAW_TRIMMED);
        $mform->setDefault('autosuspend_score', '');
        $mform->addHelpButton('autosuspend_score', 'autosuspend_score', 'learnguard');
        $mform->hideIf('autosuspend_score', 'proctoring', 'eq', 0);

        // Tri-state: blank/'' = inherit the site-wide default (and follow it live if the
        // admin changes that setting later); '1'/'0' = an explicit per-activity choice that
        // overrides the site default for THIS activity only, in either direction. Mirrors
        // autosuspend_score's blank-means-inherit pattern — a plain checkbox can't represent
        // "not set" (it's always 0 or 1), which is exactly what made the old checkbox-only
        // version of these three fields silently override the site default for every
        // activity, whether an admin meant to set an override or not.
        foreach (['enable_chat' => 1, 'enable_break' => 0, 'enable_sos' => 1] as $name => $globaldefault) {
            $siteval = (bool)(get_config('learnguard', $name) ?? $globaldefault);
            $mform->addElement('select', $name, get_string($name, 'learnguard'), [
                ''  => 'Site default (currently ' . ($siteval ? 'ON' : 'OFF') . ')',
                '1' => 'Enabled for this activity',
                '0' => 'Disabled for this activity',
            ]);
            $mform->setType($name, PARAM_RAW_TRIMMED);
            $mform->setDefault($name, '');
            $mform->addHelpButton($name, $name . '_activity', 'learnguard');
        }

        // Standard module elements (availability, groups, completion, etc.)
        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    public function data_preprocessing(&$defaultvalues) {
        global $CFG; // Required — $CFG is a Moodle global, not in scope without declaration.
        parent::data_preprocessing($defaultvalues);

        // NULL means "inherit the site default" — show the field blank, not 0 or the
        // site default's number, so it's visually obvious this activity has no override.
        if (array_key_exists('autosuspend_score', $defaultvalues) && $defaultvalues['autosuspend_score'] === null) {
            $defaultvalues['autosuspend_score'] = '';
        }
        foreach (['enable_chat', 'enable_break', 'enable_sos'] as $name) {
            if (array_key_exists($name, $defaultvalues) && $defaultvalues[$name] === null) {
                $defaultvalues[$name] = '';
            }
        }

        // Restore the intro editor.
        if (isset($defaultvalues['instructions'])) {
            $defaultvalues['instructions_editor'] = [
                'text'   => $defaultvalues['instructions'],
                'format' => $defaultvalues['instructionsformat'] ?? FORMAT_HTML,
            ];
        }

        // Restore the "other units" pool multi-select from its own table.
        if (!empty($defaultvalues['id'])) {
            global $DB;
            $defaultvalues['pathway_other_units'] = array_values($DB->get_fieldset_select(
                'learnguard_pathway_other_units', 'unitid', 'learnguard = :lgid', ['lgid' => $defaultvalues['id']]));
        }

        // Restore the rubric criteria rows into the repeat_elements array fields.
        if (!empty($defaultvalues['id'])) {
            global $DB;
            $criteria = array_values($DB->get_records('learnguard_rubric_criteria',
                ['learnguard' => $defaultvalues['id']], 'sortorder ASC'));
            $defaultvalues['rubric_name']   = [];
            $defaultvalues['rubric_weight'] = [];
            $defaultvalues['rubric_focus']  = [];
            foreach ($criteria as $i => $crit) {
                $defaultvalues['rubric_name'][$i]   = $crit->name;
                $defaultvalues['rubric_weight'][$i] = $crit->weight;
                $defaultvalues['rubric_focus'][$i]  = $crit->focus;
            }
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
        // Blank = explicit "inherit the site default" -> store NULL, not 0 (0 already
        // separately means "auto-suspend disabled" — these must stay distinguishable).
        if ($data && isset($data->autosuspend_score)) {
            $trimmed = trim((string)$data->autosuspend_score);
            $data->autosuspend_score = ($trimmed === '') ? null : (int)$trimmed;
        }
        foreach (['enable_chat', 'enable_break', 'enable_sos'] as $name) {
            if ($data && isset($data->$name)) {
                $trimmed = trim((string)$data->$name);
                $data->$name = ($trimmed === '') ? null : (int)$trimmed;
            }
        }
        return $data;
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if (isset($data['autosuspend_score']) && trim((string)$data['autosuspend_score']) !== ''
                && !ctype_digit(trim((string)$data['autosuspend_score']))) {
            $errors['autosuspend_score'] = 'Must be blank (to use the site default) or a whole number of 0 or more.';
        }
        if (!empty($data['grade']) && $data['grade'] < 0) {
            $errors['grade'] = get_string('error:gradenegative', 'learnguard');
        }
        if (!empty($data['gradepass']) && !empty($data['grade']) &&
                $data['gradepass'] > $data['grade']) {
            $errors['gradepass'] = get_string('gradepassgreaterthangrade', 'grades');
        }

        // A pathway enabled with nothing to actually draw would let a student pick a
        // pathway and then hit "no questions available" — catch that at save time
        // instead of leaving it to be discovered live during an exam.
        if (!empty($data['pathway_enabled'])) {
            $ownCount   = (int)($data['pathway_own_count'] ?? 0);
            $otherCount = (int)($data['pathway_other_count'] ?? 0);
            if ($ownCount <= 0 && $otherCount <= 0) {
                $errors['pathway_own_count'] = 'Set at least one of "chosen unit" or "other units" to a number above 0.';
            }
        }

        // Rubric weights must add up to 100 — anything else makes a criterion's max
        // points ambiguous relative to the question's actual maxmark.
        if (!empty($data['rubric_enabled'])) {
            $names   = $data['rubric_name']   ?? [];
            $weights = $data['rubric_weight'] ?? [];
            $total = 0;
            $any   = false;
            foreach ($names as $i => $name) {
                if (trim((string)$name) === '') { continue; }
                $any = true;
                $total += (int)($weights[$i] ?? 0);
            }
            if (!$any) {
                $errors['rubric_name[0]'] = 'Add at least one criterion, or turn the rubric off.';
            } elseif ($total !== 100) {
                $errors['rubric_name[0]'] = 'Criteria weights must add up to 100 (currently ' . $total . ').';
            }
        }

        return $errors;
    }
}
