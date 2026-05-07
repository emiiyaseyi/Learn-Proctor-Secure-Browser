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
 * Main quiz access rule class for Learn Shield V1.
 *
 * @package    quizaccess_learnshield
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link       https://www.linkedin.com/in/michaeladeniran/
 */

defined('MOODLE_INTERNAL') || die();

// Moodle 4.2+ uses the new namespace; older versions use the legacy base class.
if (class_exists('\mod_quiz\local\access_rule_base')) {
    class_alias('\mod_quiz\local\access_rule_base',    '\quizaccess_learnshield_parent_class_alias');
    class_alias('\mod_quiz\form\preflight_check_form', '\quizaccess_learnshield_preflight_form_alias');
} else {
    require_once($CFG->dirroot . '/mod/quiz/accessrule/accessrulebase.php');
    class_alias('\quiz_access_rule_base',          '\quizaccess_learnshield_parent_class_alias');
    class_alias('\mod_quiz_preflight_check_form',  '\quizaccess_learnshield_preflight_form_alias');
}

/**
 * Learn Shield V1 quiz access rule.
 *
 * Design notes on get_settings_sql() / make():
 *  - get_settings_sql() intentionally returns empty. Any SQL JOIN added here is
 *    merged into Moodle's core quiz SELECT and can break every quiz on the site
 *    if it conflicts with another plugin, the DB schema, or the Moodle version.
 *  - Instead, make() performs a single lightweight record_exists() check which is
 *    safe, version-independent, and never affects unrelated quizzes.
 */
class quizaccess_learnshield extends quizaccess_learnshield_parent_class_alias {

    // -----------------------------------------------------------------------
    // Preflight (webcam consent before starting attempt)
    // -----------------------------------------------------------------------

    public function is_preflight_check_required($attemptid) {
        return basename($this->get_topmost_script()) === 'view.php';
    }

    private function get_topmost_script(): string {
        $backtrace = debug_backtrace(
            defined('DEBUG_BACKTRACE_IGNORE_ARGS') ? DEBUG_BACKTRACE_IGNORE_ARGS : false
        );
        $top = array_pop($backtrace);
        return $top['file'] ?? '';
    }

    private function get_courseid_cmid(): array {
        return [
            'courseid' => $this->quiz->course,
            'quizid'   => $this->quiz->id,
            'cmid'     => $this->quiz->cmid,
        ];
    }

    private function render_webcam_modal(): string {
        global $OUTPUT;
        return $OUTPUT->render_from_template('quizaccess_learnshield/cam_modal_content', [
            'header'              => get_string('openwebcam',         'quizaccess_learnshield'),
            'proctoringstatement' => get_string('proctoringstatement', 'quizaccess_learnshield'),
            'videonotavailable'   => get_string('videonotavailable',   'quizaccess_learnshield'),
            'photoalt'            => get_string('photoalttext',        'quizaccess_learnshield'),
        ]);
    }

    /**
     * Add the webcam consent form fields shown before the quiz starts.
     */
    public function add_preflight_check_form_fields(
        quizaccess_learnshield_preflight_form_alias $quizform,
        MoodleQuickForm $mform,
        $attemptid
    ) {
        global $PAGE, $DB, $USER, $CFG;

        $coursedata = $this->get_courseid_cmid();

        $delayrow     = $DB->get_record('config_plugins', ['plugin' => 'quizaccess_learnshield', 'name' => 'autoreconfigurecamshotdelay']);
        $camshotdelay = !empty($delayrow) ? ((int)$delayrow->value * 1000) : 30000;

        $faceidrow   = $DB->get_record('config_plugins', ['plugin' => 'quizaccess_learnshield', 'name' => 'fcheckstartchk']);
        $faceidcheck = $faceidrow->value ?? '0';

        $jsrecord = [
            'id'               => 0,
            'courseid'         => (int)$coursedata['courseid'],
            'cmid'             => (int)$coursedata['cmid'],
            'attemptid'        => (int)$attemptid,
            'imagewidth'       => (int)(get_config('quizaccess_learnshield', 'autoreconfigureimagewidth') ?: 230),
            'screenshotinterval' => $camshotdelay,
            'examurl'          => (new moodle_url('/mod/quiz/startattempt.php'))->out(false),
            'enablefullscreen' => (int)(get_config('quizaccess_learnshield', 'enablefullscreen') ?: 0),
            'enabletabdetect'  => (int)(get_config('quizaccess_learnshield', 'enabletabdetect')  ?: 0),
            'enablekeyblock'   => (int)(get_config('quizaccess_learnshield', 'enablekeyblock')   ?: 0),
            'enableclipboard'  => (int)(get_config('quizaccess_learnshield', 'enableclipboard')  ?: 0),
            'scoreconfig'      => [
                'tab_switch'       => (int)(get_config('quizaccess_learnshield', 'score_tab_switch')     ?: 10),
                'fs_exit'          => (int)(get_config('quizaccess_learnshield', 'score_fs_exit')         ?: 15),
                'face_missing'     => (int)(get_config('quizaccess_learnshield', 'score_face_missing')    ?: 20),
                'keyboard'         => (int)(get_config('quizaccess_learnshield', 'score_keyboard')        ?: 10),
                'clipboard'        => (int)(get_config('quizaccess_learnshield', 'score_clipboard')       ?: 10),
                'medium_threshold' => (int)(get_config('quizaccess_learnshield', 'medium_risk_threshold') ?: 30),
                'high_threshold'   => (int)(get_config('quizaccess_learnshield', 'high_risk_threshold')   ?: 60),
            ],
        ];

        $fcmethod = get_config('quizaccess_learnshield', 'fcmethod');
        $modelurl = null;
        if ($fcmethod === 'BS' || $fcmethod === 'Local') {
            $modelurl = $CFG->wwwroot . '/mod/quiz/accessrule/learnshield/thirdpartylibs/models';
            $PAGE->requires->js('/mod/quiz/accessrule/learnshield/amd/build/face-api.min.js', true);
        }
        $PAGE->requires->js_call_amd('quizaccess_learnshield/startAttempt', 'setup', [$jsrecord, $modelurl]);

        $mform->addElement('html', "<div class='quiz-check-form'>");
        $mform->addElement('html', $this->render_webcam_modal());

        $profileimageurl = $USER->picture
            ? (new moodle_url("/user/pix.php/{$USER->id}/f1.jpg"))->out(false)
            : '';

        $mform->addElement('html', sprintf(
            '<input type="hidden" id="courseidval"  value="%d"/>
             <input type="hidden" id="cmidval"      value="%d"/>
             <input type="hidden" id="profileimage" value="%s"/>',
            (int)$coursedata['courseid'],
            (int)$coursedata['cmid'],
            s($profileimageurl)
        ));

        if ($faceidcheck === '1') {
            $mform->addElement('html', sprintf(
                "<div class='container'><div class='row'><div class='col'>
                 %s&nbsp;<span id='face_validation_result'>%s</span>
                 <button id='fcvalidate' class='btn btn-primary mt-3' style='display:flex;align-items:center;'>
                   <div class='learnshield-loadingspinner' id='loading_spinner'></div>%s
                 </button></div></div></div>",
                get_string('modal:facevalidation', 'quizaccess_learnshield'),
                get_string('modal:pending',         'quizaccess_learnshield'),
                get_string('modal:validateface',    'quizaccess_learnshield')
            ));
            $mform->addElement('html', '<div id="form_activate" style="visibility:hidden">');
        }

        $mform->addElement('checkbox', 'proctoring', '', get_string('proctoringlabel', 'quizaccess_learnshield'));

        if ($faceidcheck === '1') {
            $mform->addElement('html', '</div>');
        }
        $mform->addElement('html', '</div>');
        $mform->addRule('proctoring', get_string('youmustagree', 'quizaccess_learnshield'), 'required', null, 'client');
    }

    public function validate_preflight_check($data, $files, $errors, $attemptid) {
        if (method_exists(get_parent_class($this), 'validate_preflight_check')) {
            $errors = parent::validate_preflight_check($data, $files, $errors, $attemptid);
        }
        if (empty($data['proctoring'])) {
            $errors['proctoring'] = get_string('youmustagree', 'quizaccess_learnshield');
        }
        return $errors;
    }

    // -----------------------------------------------------------------------
    // Core rule lifecycle
    // -----------------------------------------------------------------------

    /**
     * Determine whether Learn Shield applies to this quiz.
     *
     * Uses a direct DB record_exists() check instead of relying on
     * get_settings_sql() — see class docblock for the reason.
     */
    public static function make($quizobj, $timenow, $canignoretimelimits) {
        global $DB;

        // Safety: if our table doesn't exist (mid-install / post-uninstall),
        // return null silently so no quiz is ever broken.
        static $tableok = null;
        if ($tableok === null) {
            try {
                $tableok = $DB->get_manager()->table_exists('quizaccess_learnshield');
            } catch (Throwable $e) {
                $tableok = false;
            }
        }
        if (!$tableok) {
            return null;
        }

        try {
            // get_quizid() returns the quiz TABLE id (not course-module id).
            $quizid = method_exists($quizobj, 'get_quizid')
                ? $quizobj->get_quizid()
                : $quizobj->get_quiz()->id;

            if (!$DB->record_exists('quizaccess_learnshield', ['quizid' => $quizid])) {
                return null;
            }
        } catch (Throwable $e) {
            return null;
        }

        return new self($quizobj, $timenow);
    }

    /**
     * Add the proctoring on/off selector to the quiz settings form.
     *
     * Because get_settings_sql() returns empty, the current proctoring status
     * is NOT pre-loaded into the quiz object. We load it here directly from DB
     * so teachers see the correct current value when editing a quiz.
     */
    public static function add_settings_form_fields($quizform, MoodleQuickForm $mform) {
        global $DB;

        $mform->addElement('select', 'proctoringrequired',
            get_string('proctoringrequired', 'quizaccess_learnshield'),
            [
                0 => get_string('notrequired',              'quizaccess_learnshield'),
                1 => get_string('proctoringrequiredoption', 'quizaccess_learnshield'),
            ]
        );
        $mform->addHelpButton('proctoringrequired', 'proctoringrequired', 'quizaccess_learnshield');

        // Load the current proctoring state so the form shows the right value.
        // 'update' is the course-module id when editing an existing quiz.
        $cmid = optional_param('update', 0, PARAM_INT);
        if ($cmid > 0) {
            try {
                $cm = get_coursemodule_from_id('quiz', $cmid);
                if ($cm) {
                    $enabled = $DB->record_exists('quizaccess_learnshield', ['quizid' => $cm->instance]) ? 1 : 0;
                    $mform->setDefault('proctoringrequired', $enabled);
                }
            } catch (Throwable $e) {
                // On any error, leave the default at 0 (safe fallback).
            }
        }
    }

    /**
     * Persist the proctoring setting when quiz settings are saved.
     *
     * $quiz->id is the quiz TABLE id.
     */
    public static function save_settings($quiz) {
        global $DB;
        if (empty($quiz->proctoringrequired)) {
            $DB->delete_records('quizaccess_learnshield', ['quizid' => $quiz->id]);
        } else {
            if (!$DB->record_exists('quizaccess_learnshield', ['quizid' => $quiz->id])) {
                $DB->insert_record('quizaccess_learnshield', (object)[
                    'quizid'             => $quiz->id,
                    'proctoringrequired' => 1,
                ]);
            }
        }
    }

    public static function delete_settings($quiz) {
        global $DB;
        $DB->delete_records('quizaccess_learnshield', ['quizid' => $quiz->id]);
    }

    /**
     * Return SQL to merge into Moodle's core quiz SELECT.
     *
     * INTENTIONALLY EMPTY. Any JOIN we add here is merged into every quiz
     * SELECT across the entire site. A single error (wrong alias, missing table,
     * column conflict with another plugin, or DB engine difference) breaks ALL
     * quizzes. We avoid this entirely — proctoring status is checked via
     * direct DB query in make() instead.
     */
    public static function get_settings_sql($quizid) {
        return ['', '', []];
    }

    // -----------------------------------------------------------------------
    // Description shown on the quiz view page (above the Start button)
    // -----------------------------------------------------------------------

    public function description() {
        global $PAGE;

        $PAGE->requires->js_call_amd('quizaccess_learnshield/proctoring', 'init', [(object)[
            'allowcamerawarning' => get_string('warning:cameraallowwarning', 'quizaccess_learnshield'),
            'cameraallow'        => get_string('info:cameraallow',           'quizaccess_learnshield'),
        ]]);

        return [
            get_string('proctoringheader', 'quizaccess_learnshield'),
            $this->get_report_button(),
        ];
    }

    // -----------------------------------------------------------------------
    // Attempt page setup: create log entry, start session, inject JS
    // -----------------------------------------------------------------------

    public function setup_attempt_page($page) {
        global $CFG, $DB, $COURSE, $USER;

        $cmid    = optional_param('cmid',    0, PARAM_INT);
        $attempt = optional_param('attempt', 0, PARAM_INT);

        $page->set_title($this->quizobj->get_course()->shortname . ': ' . $page->title);
        $page->set_popup_notification_allowed(false);
        $page->set_heading($page->title);

        if (!$cmid) {
            return;
        }

        // Get the course-module record (quizid in logs = CM id, matching the original design).
        $cm = $DB->get_record('course_modules', ['id' => $cmid]);
        if (!$cm) {
            return;
        }

        // Insert webcam log record for this attempt page view.
        $logid = 0;
        try {
            $logid = $DB->insert_record('quizaccess_learnshield_logs', (object)[
                'courseid'         => (int)$COURSE->id,
                'quizid'           => (int)$cm->id,
                'userid'           => (int)$USER->id,
                'webcampicture'    => '',
                'status'           => (int)$attempt,
                'awsscore'         => 0,
                'awsflag'          => 0,
                'deletionprogress' => 0,
                'timemodified'     => time(),
            ]);
        } catch (Throwable $e) {
            debugging('Learn Shield: log insert failed — ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        // Upsert session record for risk scoring / live dashboard.
        $sessionid = 0;
        try {
            $existing = $DB->get_record('quizaccess_learnshield_sessions', [
                'courseid'  => (int)$COURSE->id,
                'quizid'    => (int)$cm->id,
                'userid'    => (int)$USER->id,
                'attemptid' => (int)$attempt,
            ]);
            if ($existing) {
                $sessionid = (int)$existing->id;
            } else {
                $sessionid = (int)$DB->insert_record('quizaccess_learnshield_sessions', (object)[
                    'courseid'            => (int)$COURSE->id,
                    'quizid'              => (int)$cm->id,
                    'userid'              => (int)$USER->id,
                    'attemptid'           => (int)$attempt,
                    'status'              => 'active',
                    'risk_score'          => 0,
                    'risk_level'          => 'low',
                    'start_time'          => time(),
                    'ip_address'          => getremoteaddr(),
                    'browser_info'        => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                    'consent_given'       => 1,
                    'fullscreen_exits'    => 0,
                    'tab_switches'        => 0,
                    'clipboard_events'    => 0,
                    'keyboard_violations' => 0,
                    'face_missing_count'  => 0,
                    'is_flagged'          => 0,
                    'timemodified'        => time(),
                ]);
            }
        } catch (Throwable $e) {
            debugging('Learn Shield: session upsert failed — ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        // Build JS config.
        $camshotdelay = ((int)(get_config('quizaccess_learnshield', 'autoreconfigurecamshotdelay') ?: 30)) * 1000;
        $imagewidth   = (int)(get_config('quizaccess_learnshield', 'autoreconfigureimagewidth') ?: 230);

        $jsrecord = [
            'id'               => (int)$logid,
            'courseid'         => (int)$COURSE->id,
            'quizid'           => (int)$cm->id,
            'cmid'             => (int)$cmid,
            'userid'           => (int)$USER->id,
            'attemptid'        => (int)$attempt,
            'sessionid'        => $sessionid,
            'camshotdelay'     => $camshotdelay,
            'image_width'      => $imagewidth,
            'quizurl'          => (new moodle_url('/mod/quiz/view.php', ['id' => $cmid]))->out(),
            'enablefullscreen' => (int)(get_config('quizaccess_learnshield', 'enablefullscreen') ?: 0),
            'enabletabdetect'  => (int)(get_config('quizaccess_learnshield', 'enabletabdetect')  ?: 0),
            'enablekeyblock'   => (int)(get_config('quizaccess_learnshield', 'enablekeyblock')   ?: 0),
            'enableclipboard'  => (int)(get_config('quizaccess_learnshield', 'enableclipboard')  ?: 0),
            'scoreconfig'      => [
                'tab_switch'       => (int)(get_config('quizaccess_learnshield', 'score_tab_switch')     ?: 10),
                'fs_exit'          => (int)(get_config('quizaccess_learnshield', 'score_fs_exit')         ?: 15),
                'face_missing'     => (int)(get_config('quizaccess_learnshield', 'score_face_missing')    ?: 20),
                'keyboard'         => (int)(get_config('quizaccess_learnshield', 'score_keyboard')        ?: 10),
                'clipboard'        => (int)(get_config('quizaccess_learnshield', 'score_clipboard')       ?: 10),
                'medium_threshold' => (int)(get_config('quizaccess_learnshield', 'medium_risk_threshold') ?: 30),
                'high_threshold'   => (int)(get_config('quizaccess_learnshield', 'high_risk_threshold')   ?: 60),
            ],
        ];

        $fcmethod = get_config('quizaccess_learnshield', 'fcmethod');
        $modelurl = null;
        if ($fcmethod === 'BS' || $fcmethod === 'Local') {
            $modelurl = $CFG->wwwroot . '/mod/quiz/accessrule/learnshield/thirdpartylibs/models';
            $page->requires->js('/mod/quiz/accessrule/learnshield/amd/build/face-api.min.js', true);
        }
        $page->requires->js_call_amd('quizaccess_learnshield/proctoring', 'setup', [$jsrecord, $modelurl]);
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    private function get_report_button(): string {
        global $OUTPUT, $USER;

        $context = context_module::instance($this->quiz->cmid, MUST_EXIST);
        if (!has_capability('quizaccess/learnshield:viewreport', $context, $USER->id)) {
            return '';
        }

        $url = new moodle_url('/mod/quiz/accessrule/learnshield/report.php', [
            'courseid' => $this->quiz->course,
            'cmid'     => $this->quiz->cmid,
        ]);
        return $OUTPUT->single_button($url, get_string('picturesreport', 'quizaccess_learnshield'), 'get');
    }
}
