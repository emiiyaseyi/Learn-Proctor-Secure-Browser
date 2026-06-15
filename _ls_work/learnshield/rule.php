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

require_once(__DIR__ . '/lib.php');

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
        // Only run on view.php — precheck runs when learner clicks "Start attempt" in modal.
        // After precheck, completeWizard() uses fetch() to POST directly to startattempt.php
        // and navigates to attempt.php — no intermediate page shown to learner.
        return basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'view.php';
    }

    private function get_topmost_script(): string {
        // Lightweight — just read the current script path.
        return $_SERVER['SCRIPT_FILENAME'] ?? '';
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
     * Add the pre-exam wizard launcher to the Moodle preflight form.
     *
     * The wizard handles: browser check, internet, camera, audio,
     * face detection, liveness, privacy policy, and fullscreen.
     * The standard webcam modal is NOT shown here — it is inside the wizard.
     */
    public function add_preflight_check_form_fields(
        quizaccess_learnshield_preflight_form_alias $quizform,
        MoodleQuickForm $mform,
        $attemptid
    ) {
        global $PAGE, $DB, $USER, $CFG;

        $coursedata = $this->get_courseid_cmid();
        $quizTableId = $this->quiz->id;

        // Per-quiz or global fullscreen setting.
        $cfg = function(string $key, $default) {
            $v = get_config('quizaccess_learnshield', $key);
            return ($v === false) ? $default : $v;
        };
        $qcfg = function(string $feature, $globaldefault) use ($quizTableId, $cfg) {
            $v = get_config('quizaccess_learnshield', "quiz_{$feature}_{$quizTableId}");
            if ($v !== false && $v !== '') { return (int)$v; }
            return (int)$cfg($feature, $globaldefault);
        };

        $enablefullscreen = $qcfg('enablefullscreen', 1);

        // Build script URLs — passed to JS which loads them dynamically (non-blocking).
        $base = $CFG->wwwroot . '/mod/quiz/accessrule/learnshield/thirdpartylibs';
        $modelurl   = null;
        $faceapiurl = null;
        $onnxurl    = null;
        if (file_exists($CFG->dirroot . '/mod/quiz/accessrule/learnshield/thirdpartylibs/models/tiny_face_detector_model-shard1')) {
            $modelurl   = $base . '/models';
            $faceapiurl = $CFG->wwwroot . '/mod/quiz/accessrule/learnshield/amd/build/face-api.min.js';
            $onnxurl    = $base . '/onnx';
        }

        // Quiz-specific instructions.
        $instructions = get_config('quizaccess_learnshield', "quiz_instructions_{$quizTableId}") ?: '';

        // Wizard config passed to JS (JS dynamically loads face-api + ort scripts).
        $wizconfig = [
            'courseid'         => (int)$coursedata['courseid'],
            'cmid'             => (int)$coursedata['cmid'],
            'quizid'           => (int)$cm->instance, // Quiz TABLE id — needed to call mod_quiz_start_attempt WS.
            'attemptid'        => (int)$attemptid,
            'instructions'     => $instructions,
            'privacynotice'    => get_config('quizaccess_learnshield', 'privacy_notice') ?: '',
            'enablefullscreen' => $enablefullscreen,
            'faceapprovalmode' => get_config('quizaccess_learnshield', 'face_approval_mode') ?: 'auto',
            'faceapiurl'       => $faceapiurl,
            'onnxurl'          => $onnxurl,
        ];

        // Load the wizard JS and launch it on page ready.
        $PAGE->requires->js_call_amd('quizaccess_learnshield/startAttempt', 'setup', [$wizconfig, $modelurl]);

        // The Moodle proctoring checkbox (auto-checked by wizard on completion).
        // Hidden from student — the wizard handles consent.
        $mform->addElement('html', '<div style="display:none">');
        $mform->addElement('checkbox', 'proctoring', '', get_string('proctoringlabel', 'quizaccess_learnshield'));
        $mform->addElement('html', '</div>');

        // Required rule still runs server-side (wizard checks it client-side first).
        $mform->addRule('proctoring', get_string('youmustagree', 'quizaccess_learnshield'), 'required', null, 'client');

        // Wrapper placeholder so Moodle renders the form correctly.
        $mform->addElement('html', '<div id="ls-preflight-placeholder"></div>');
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
     * Check whether a quiz attempt is currently blocked (emergency pause, mobile, suspended).
     *
     * Called before quiz access is granted. Returns an array of error messages
     * or an empty array if access is allowed.
     */
    public function prevent_access() {
        global $CFG;

        $messages = [];

        // ---- Emergency pause ----
        $paused = (bool)(int)(get_config('quizaccess_learnshield', 'emergency_pause_active') ?: 0);
        if ($paused) {
            $messages[] = html_writer::div(
                html_writer::tag('strong', '🚨 All examinations are currently paused.') .
                '<br>This is a temporary measure. Please contact your administrator.',
                'alert alert-danger'
            );
        }

        // ---- Mobile device block ----
        $blockmobile = (bool)(int)(get_config('quizaccess_learnshield', 'block_mobile') ?: 1);
        if ($blockmobile && self::is_mobile_device()) {
            $msg = get_config('quizaccess_learnshield', 'mobile_blocked_message')
                ?: 'Mobile phones and tablets are not permitted for proctored assessments. Please use a desktop or laptop computer.';
            $messages[] = html_writer::div(
                html_writer::tag('strong', '📵 Mobile Device Detected') .
                '<br>' . s($msg),
                'alert alert-danger'
            );
        }

        return $messages;
    }

    /**
     * Detect if the current request is from a mobile phone or tablet.
     *
     * @return bool
     */
    private static function is_mobile_device(): bool {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        // Comprehensive mobile UA detection.
        $mobilepatterns = [
            'Mobile', 'Android', 'iPhone', 'iPad', 'iPod',
            'BlackBerry', 'Windows Phone', 'Opera Mini', 'IEMobile',
            'Silk', 'Kindle', 'Tablet',
        ];
        foreach ($mobilepatterns as $pattern) {
            if (stripos($ua, $pattern) !== false) {
                return true;
            }
        }
        // Check screen width hint from client hints (modern browsers).
        if (isset($_SERVER['HTTP_SEC_CH_UA_MOBILE']) && $_SERVER['HTTP_SEC_CH_UA_MOBILE'] === '?1') {
            return true;
        }
        return false;
    }

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
     * Add the proctoring on/off selector + custom instructions to the quiz settings form.
     *
     * Because get_settings_sql() returns empty, the current proctoring status
     * is NOT pre-loaded into the quiz object. We load it here directly from DB.
     */
    /**
     * Add Learn Shield settings to the quiz settings form.
     * Includes: enable/disable proctoring, custom instructions,
     * and per-quiz overrides for every security feature.
     */
    public static function add_settings_form_fields($quizform, MoodleQuickForm $mform) {
        global $DB;

        // ----------------------------------------------------------------
        // Section 1: Enable / disable proctoring for this quiz
        // ----------------------------------------------------------------
        $mform->addElement('select', 'proctoringrequired',
            get_string('proctoringrequired', 'quizaccess_learnshield'),
            [
                0 => get_string('notrequired',              'quizaccess_learnshield'),
                1 => get_string('proctoringrequiredoption', 'quizaccess_learnshield'),
            ]
        );
        $mform->addHelpButton('proctoringrequired', 'proctoringrequired', 'quizaccess_learnshield');

        // ----------------------------------------------------------------
        // Section 2: Custom exam instructions for this quiz
        // ----------------------------------------------------------------
        $mform->addElement('header', 'ls_instructions_header',
            '📋 ' . get_string('quiz_instructions_label', 'quizaccess_learnshield'));
        $mform->addElement('textarea', 'ls_instructions',
            get_string('quiz_instructions_label', 'quizaccess_learnshield'),
            ['rows' => 4, 'cols' => 60, 'class' => 'form-control',
             'placeholder' => 'e.g. No notes allowed. Keep your face visible at all times. Do not leave your desk.']
        );
        $mform->setType('ls_instructions', PARAM_TEXT);
        $mform->addElement('static', 'ls_instructions_note', '',
            html_writer::tag('small',
                get_string('quiz_instructions_help', 'quizaccess_learnshield'),
                ['class' => 'text-muted']
            )
        );

        // ----------------------------------------------------------------
        // Section 3: Per-quiz security control overrides
        // These override the global plugin settings for THIS quiz only.
        // ----------------------------------------------------------------
        $mform->addElement('header', 'ls_security_header',
            '🔒 ' . get_string('perquiz_security_header', 'quizaccess_learnshield'));

        $mform->addElement('static', 'ls_security_note', '',
            html_writer::tag('p',
                get_string('perquiz_security_desc', 'quizaccess_learnshield'),
                ['class' => 'text-muted small']
            )
        );

        // Override options: blank = use global, 1 = force ON, 0 = force OFF.
        $overrideopts = [
            ''  => get_string('perquiz_use_global',  'quizaccess_learnshield'),
            '1' => get_string('perquiz_force_on',    'quizaccess_learnshield'),
            '0' => get_string('perquiz_force_off',   'quizaccess_learnshield'),
        ];

        $securityfields = [
            'ls_fullscreen' => get_string('setting:enablefullscreen', 'quizaccess_learnshield'),
            'ls_tabdetect'  => get_string('setting:enabletabdetect',  'quizaccess_learnshield'),
            'ls_keyblock'   => get_string('setting:enablekeyblock',   'quizaccess_learnshield'),
            'ls_clipboard'  => get_string('setting:enableclipboard',  'quizaccess_learnshield'),
            'ls_precheck'   => get_string('setting:enableprecheck',   'quizaccess_learnshield'),
        ];

        foreach ($securityfields as $fieldname => $label) {
            $mform->addElement('select', $fieldname, $label, $overrideopts);
            $mform->setType($fieldname, PARAM_TEXT);
            $mform->setDefault($fieldname, '');
        }

        // Snapshot interval override (0 = use global setting).
        $mform->addElement('text', 'ls_snapinterval',
            get_string('perquiz_snapinterval', 'quizaccess_learnshield'),
            ['size' => '5', 'placeholder' => '0']
        );
        $mform->setType('ls_snapinterval', PARAM_INT);
        $mform->setDefault('ls_snapinterval', 0);
        $mform->addElement('static', 'ls_snapinterval_note', '',
            html_writer::tag('small',
                get_string('perquiz_snapinterval_help', 'quizaccess_learnshield'),
                ['class' => 'text-muted']
            )
        );

        // ----------------------------------------------------------------
        // Load current values when editing an existing quiz
        // ----------------------------------------------------------------
        $cmid = optional_param('update', 0, PARAM_INT);
        if ($cmid > 0) {
            try {
                $cm = get_coursemodule_from_id('quiz', $cmid);
                if ($cm) {
                    $qid = $cm->instance;

                    // Proctoring enabled flag.
                    $mform->setDefault('proctoringrequired',
                        $DB->record_exists('quizaccess_learnshield', ['quizid' => $qid]) ? 1 : 0
                    );

                    // Custom instructions.
                    $saved = get_config('quizaccess_learnshield', "quiz_instructions_{$qid}");
                    if ($saved !== false) { $mform->setDefault('ls_instructions', $saved); }

                    // Per-quiz security overrides.
                    foreach (array_keys($securityfields) as $field) {
                        $configkey = str_replace('ls_', 'quiz_', $field) . "_{$qid}";
                        $val = get_config('quizaccess_learnshield', $configkey);
                        if ($val !== false) { $mform->setDefault($field, $val); }
                    }

                    // Snapshot interval.
                    $si = get_config('quizaccess_learnshield', "quiz_snapinterval_{$qid}");
                    if ($si !== false) { $mform->setDefault('ls_snapinterval', (int)$si); }
                }
            } catch (Throwable $e) {
                // Safe fallback — defaults stay as-is.
            }
        }
    }

    /**
     * Persist all per-quiz Learn Shield settings when the quiz form is saved.
     */
    public static function save_settings($quiz) {
        global $DB;
        $qid = $quiz->id;

        // Proctoring enabled flag.
        if (empty($quiz->proctoringrequired)) {
            $DB->delete_records('quizaccess_learnshield', ['quizid' => $qid]);
        } else {
            if (!$DB->record_exists('quizaccess_learnshield', ['quizid' => $qid])) {
                $DB->insert_record('quizaccess_learnshield', (object)[
                    'quizid'             => $qid,
                    'proctoringrequired' => 1,
                ]);
            }
        }

        // Custom instructions.
        if (isset($quiz->ls_instructions)) {
            set_config("quiz_instructions_{$qid}", $quiz->ls_instructions, 'quizaccess_learnshield');
        }

        // Per-quiz security overrides.
        $perquizfields = ['ls_fullscreen', 'ls_tabdetect', 'ls_keyblock', 'ls_clipboard', 'ls_precheck'];
        foreach ($perquizfields as $field) {
            if (isset($quiz->$field)) {
                $configkey = str_replace('ls_', 'quiz_', $field) . "_{$qid}";
                if ($quiz->$field === '') {
                    unset_config($configkey, 'quizaccess_learnshield');
                } else {
                    set_config($configkey, $quiz->$field, 'quizaccess_learnshield');
                }
            }
        }

        // Snapshot interval override.
        if (isset($quiz->ls_snapinterval) && (int)$quiz->ls_snapinterval > 0) {
            set_config("quiz_snapinterval_{$qid}", (int)$quiz->ls_snapinterval, 'quizaccess_learnshield');
        } else {
            unset_config("quiz_snapinterval_{$qid}", 'quizaccess_learnshield');
        }
    }

    public static function delete_settings($quiz) {
        global $DB;
        $qid = $quiz->id;
        $DB->delete_records('quizaccess_learnshield', ['quizid' => $qid]);

        // Clean up all per-quiz config keys.
        foreach (['instructions', 'fullscreen', 'tabdetect', 'keyblock', 'clipboard', 'precheck', 'snapinterval'] as $k) {
            unset_config("quiz_{$k}_{$qid}", 'quizaccess_learnshield');
        }
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
        global $PAGE, $USER;

        // init() on the view page is intentionally a no-op (no camera shown here).
        $PAGE->requires->js_call_amd('quizaccess_learnshield/proctoring', 'init', [(object)[]]);

        // Build ONE combined message so there is never more than one description block.
        $lines = [get_string('proctoringheader', 'quizaccess_learnshield')];

        // Per-learner scheduling: show a live "opens in" / "closes in" countdown
        // based on this user's effective window ({quiz_overrides} set via schedule.php,
        // falling back to the quiz's own timeopen/timeclose).
        [$effopen, $effclose] = quizaccess_learnshield_effective_window($this->quiz->id, $USER->id);
        $countdown = quizaccess_learnshield_countdown_widget($effopen, $effclose);
        if ($countdown !== '') {
            $lines[] = $countdown;
        }

        // Append any quiz-specific admin instructions.
        $instructions = get_config('quizaccess_learnshield', 'quiz_instructions_' . $this->quiz->id);
        if (!empty($instructions)) {
            $lines[] = html_writer::div(
                html_writer::tag('strong', 'Exam Instructions: ') . s($instructions),
                'alert alert-info mt-2'
            );
        }

        // Append the report button (teachers only) in the same block.
        $btn = $this->get_report_button();
        if ($btn) {
            $lines[] = $btn;
        }

        // Return as a SINGLE element so Moodle only renders one description block.
        return [implode('', $lines)];
    }

    // -----------------------------------------------------------------------
    // Attempt page setup: create log entry, start session, inject JS
    // -----------------------------------------------------------------------

    public function setup_attempt_page($page) {
        global $CFG, $DB, $COURSE, $USER;

        // Only inject proctoring JS on the actual quiz attempt page (zero overhead check).
        if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') !== 'attempt.php') {
            return;
        }

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

        // ---- Build JS config with per-quiz overrides ----
        // Global config helper: returns $default if the config was never saved.
        $cfg = function(string $key, $default) {
            $v = get_config('quizaccess_learnshield', $key);
            return ($v === false) ? $default : $v;
        };

        // Per-quiz override helper: returns the quiz-level value if set,
        // otherwise falls back to the global config value.
        $qid = $cm->instance; // quiz TABLE id (not CM id)
        $qcfg = function(string $feature, $globaldefault) use ($qid, $cfg) {
            $perquizval = get_config('quizaccess_learnshield', "quiz_{$feature}_{$qid}");
            if ($perquizval !== false && $perquizval !== '') {
                return (int)$perquizval; // Explicit per-quiz override.
            }
            return (int)$cfg($feature, $globaldefault); // Fall back to global.
        };

        // Snapshot delay: check quiz-level override first.
        $snapIntervalOverride = get_config('quizaccess_learnshield', "quiz_snapinterval_{$qid}");
        if ($snapIntervalOverride !== false && (int)$snapIntervalOverride > 0) {
            $camshotdelay = (int)$snapIntervalOverride * 1000;
        } else {
            $camshotdelay = ((int)$cfg('autoreconfigurecamshotdelay', 30)) * 1000;
        }
        $imagewidth = (int)$cfg('autoreconfigureimagewidth', 230);

        // Per-learner scheduling: pass this user's effective close time (if any)
        // so proctoring.js can show a "Window closes in" countdown badge during
        // the live attempt. Moodle's own quiz timer already enforces this via
        // {quiz_overrides} — this is purely an informational display.
        [, $effclose] = quizaccess_learnshield_effective_window((int)$cm->instance, (int)$USER->id);

        // Security features: per-quiz overrides take priority over global settings.
        // All default to ON (1) so they are active even if admin never saved settings.
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
            'enablefullscreen' => $qcfg('enablefullscreen', 1),
            'enabletabdetect'  => $qcfg('enabletabdetect',  1),
            'enablekeyblock'   => $qcfg('enablekeyblock',   1),
            'enableclipboard'  => $qcfg('enableclipboard',  1),
            'timeclose'        => (int)$effclose,
            'servertime'       => time(),
            'scoreconfig'      => [
                'tab_switch'       => (int)$cfg('score_tab_switch',     10),
                'fs_exit'          => (int)$cfg('score_fs_exit',         15),
                'face_missing'     => (int)$cfg('score_face_missing',    10),
                'keyboard'         => (int)$cfg('score_keyboard',        10),
                'clipboard'        => (int)$cfg('score_clipboard',       10),
                'medium_threshold' => (int)$cfg('medium_risk_threshold', 30),
                'high_threshold'   => (int)$cfg('high_risk_threshold',   60),
            ],
        ];

        // Build script URLs — JS loads them dynamically (non-blocking, no head injection).
        $base = $CFG->wwwroot . '/mod/quiz/accessrule/learnshield/thirdpartylibs';
        $modelurl   = null;
        $faceapiurl = null;
        $onnxurl    = null;
        if (file_exists($CFG->dirroot . '/mod/quiz/accessrule/learnshield/thirdpartylibs/models/tiny_face_detector_model-shard1')) {
            $modelurl   = $base . '/models';
            $faceapiurl = $CFG->wwwroot . '/mod/quiz/accessrule/learnshield/amd/build/face-api.min.js';
            $onnxurl    = $base . '/onnx';
        }
        $jsrecord['onnxurl']    = $onnxurl;
        $jsrecord['faceapiurl'] = $faceapiurl;
        $page->requires->js_call_amd('quizaccess_learnshield/proctoring', 'setup', [$jsrecord, $modelurl]);
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    private function get_report_button(): string {
        global $OUTPUT, $USER;

        $context  = context_module::instance($this->quiz->cmid, MUST_EXIST);
        $courseid = $this->quiz->course;
        $cmid     = $this->quiz->cmid;

        $canviewreport = has_capability('quizaccess/learnshield:viewreport', $context, $USER->id);
        $canproctor    = has_capability('quizaccess/learnshield:liveproctor', $context, $USER->id);
        $canoverride   = has_capability('mod/quiz:manageoverrides', $context, $USER->id);

        if (!$canviewreport && !$canproctor && !$canoverride) {
            return '';
        }

        $links = [];
        if ($canproctor) {
            $links[] = $OUTPUT->single_button(
                new moodle_url('/mod/quiz/accessrule/learnshield/proctordashboard.php',
                    ['courseid' => $courseid, 'cmid' => $cmid]),
                '🏠 Dashboard', 'get');
            $links[] = $OUTPUT->single_button(
                new moodle_url('/mod/quiz/accessrule/learnshield/proctorview.php', ['tab' => 'live']),
                '🛡 Live Monitor', 'get');
        }
        if ($canviewreport) {
            $links[] = $OUTPUT->single_button(
                new moodle_url('/mod/quiz/accessrule/learnshield/report.php',
                    ['courseid' => $courseid, 'cmid' => $cmid]),
                get_string('picturesreport', 'quizaccess_learnshield'), 'get');
            $links[] = $OUTPUT->single_button(
                new moodle_url('/mod/quiz/report.php', ['id' => $cmid, 'mode' => 'overview']),
                '🏆 Grades', 'get');
        }
        if ($canoverride) {
            $links[] = $OUTPUT->single_button(
                new moodle_url('/mod/quiz/accessrule/learnshield/schedule.php',
                    ['courseid' => $courseid, 'cmid' => $cmid]),
                '📅 Schedule', 'get');
        }

        return html_writer::div(implode(' ', $links), 'd-flex flex-wrap gap-2 mt-2');
    }
}
