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
 * Main view page for mod_learnguard.
 *
 * This page renders the SPA shell. Once JavaScript initialises, it takes
 * complete control of the page:
 *   - No further page reloads during the entire assessment.
 *   - Fullscreen persists throughout (only ONE fullscreen request needed).
 *   - Webcam stream is continuous.
 *   - Questions are loaded via AJAX into the SPA container.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link       https://www.linkedin.com/in/michaeladeniran/
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once($CFG->libdir . '/completionlib.php');

$id       = optional_param('id',       0, PARAM_INT); // Course module ID.
$action   = optional_param('action',   '', PARAM_ALPHA);
$attemptid = optional_param('attempt', 0, PARAM_INT);

// -----------------------------------------------------------------------
// Setup
// -----------------------------------------------------------------------
if ($id) {
    $cm       = get_coursemodule_from_id('learnguard', $id, 0, false, MUST_EXIST);
    $course   = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
    $instance = $DB->get_record('learnguard', ['id' => $cm->instance], '*', MUST_EXIST);
} else {
    throw new moodle_exception('invalidcoursemodule');
}

require_login($course, true, $cm);
$context = context_module::instance($cm->id);

$PAGE->set_url('/mod/learnguard/view.php', ['id' => $id]);
$PAGE->set_context($context);
$PAGE->set_title($course->shortname . ': ' . format_string($instance->name));
$PAGE->set_heading(format_string($course->fullname));
// Pass a blank-intro clone so Moodle's theme doesn't render the description
// at the top of the page — the SPA manages its own layout entirely.
$_display_instance = clone $instance;
$_display_instance->intro = '';
$PAGE->set_activity_record($_display_instance);
$PAGE->requires->css('/mod/learnguard/styles.css');

// TensorFlow.js CDN scripts are injected after $OUTPUT->header() below
// because $PAGE->requires->js() rejects external URLs in Moodle 4.x.

// MathJax for LaTeX rendering in question text and answer options.
// Uses Moodle's built-in MathJax loader if available, otherwise CDN fallback.
$PAGE->requires->js_call_amd('filter_mathjaxloader/loader', 'init', [], false);
// NOTE: body overflow is managed by assessment.js only when the student
// is actively inside the fixed-position assessment shell. Never apply it here
// because teachers and proctors viewing this page need scrolling.

// -----------------------------------------------------------------------
// Mobile device block
// -----------------------------------------------------------------------
if ($instance->block_mobile) {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $isMobile = preg_match('/Mobi|Android|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i', $ua) ||
                (isset($_SERVER['HTTP_SEC_CH_UA_MOBILE']) && $_SERVER['HTTP_SEC_CH_UA_MOBILE'] === '?1');

    if ($isMobile) {
        $PAGE->set_pagelayout('base');
        echo $OUTPUT->header();
        echo html_writer::div(
            html_writer::tag('div', '📵', ['style' => 'font-size:72px;text-align:center;']) .
            $OUTPUT->heading(get_string('error:mobileblocked', 'learnguard')) .
            html_writer::tag('p', get_string('error:mobileblocked', 'learnguard'), ['class' => 'text-center']),
            'alert alert-danger mt-4'
        );
        echo $OUTPUT->footer();
        exit;
    }
}

// -----------------------------------------------------------------------
// Access control
// -----------------------------------------------------------------------
require_capability('mod/learnguard:view', $context);

$canAttempt = has_capability('mod/learnguard:attempt',    $context);
$canManage  = has_capability('mod/learnguard:manage',     $context);
$canProctor = has_capability('mod/learnguard:liveproctor', $context);
$canReport  = has_capability('mod/learnguard:viewreports', $context);
$isGrader   = $canManage || $DB->record_exists('learnguard_graders',
    ['learnguard' => $instance->id, 'userid' => $USER->id]);

// Trigger course module viewed event.
$event = \mod_learnguard\event\course_module_viewed::create([
    'objectid' => $instance->id,
    'context'  => $context,
]);
$event->add_record_snapshot('course_modules', $cm);
$event->add_record_snapshot('course',         $course);
$event->add_record_snapshot('learnguard',     $instance);
$event->trigger();

// Check completion.
$completion = new completion_info($course);
$completion->set_module_viewed($cm);

// -----------------------------------------------------------------------
// Existing in-progress attempt
// -----------------------------------------------------------------------
// Includes 'suspended' so a suspended learner who reloads the page (e.g. the
// "Check Now" button on the suspended screen) is routed back into the resume
// flow and shown the "Attempt Suspended" screen, instead of the "Begin
// Assessment" screen — which could otherwise let them start a second attempt
// while the first is still suspended.
$existingattempt = $DB->get_record_select('learnguard_attempts',
    "learnguard = :lgid AND userid = :uid AND state IN ('inprogress', 'suspended')",
    ['lgid' => $instance->id, 'uid' => $USER->id]);

// Has questions? True if fixed slots OR pool rules exist.
$hasquestions = $DB->record_exists_select('learnguard_slots',
        'learnguard = :lgid AND attemptid = 0', ['lgid' => $instance->id])
    || $DB->record_exists('learnguard_pools', ['learnguard' => $instance->id]);

// Attempt-count check — computed here so the JS can show a friendly
// "no attempts left" message instead of firing the WS and getting a
// cryptic "no permission" error.
$maxattempts    = (int)($instance->attempts ?? 0);

// Apply per-user extra_attempts from learnguard_schedules.
$userSchedule   = learnguard_get_schedule((int)$instance->id, $USER->id);
if ($userSchedule && !empty($userSchedule->extra_attempts)) {
    $maxattempts = ($maxattempts <= 0)
        ? 0   // unlimited stays unlimited
        : $maxattempts + (int)$userSchedule->extra_attempts;
}

// Apply per-user timelimit_override (stored in seconds; 0 = use activity default).
$effectiveTilelimit = (int)$instance->timelimit;
if ($userSchedule && !empty($userSchedule->timelimit_override)) {
    $effectiveTilelimit = (int)$userSchedule->timelimit_override;
}

$completedcount = (int)$DB->count_records_select(
    'learnguard_attempts',
    "learnguard = :lgid AND userid = :uid AND state IN ('finished','suspended')",
    ['lgid' => $instance->id, 'uid' => $USER->id]
);
// canstart: true when unlimited (maxattempts=0) OR attempts not yet exhausted.
$canstart = $canAttempt && ($maxattempts <= 0 || $completedcount < $maxattempts);

// All of this learner's own attempts (any state), most recent first — shown
// on the instructions screen so they can see attempted/in-progress/completed
// status without needing the admin Reports tab.
$myattemptrecords = $DB->get_records('learnguard_attempts',
    ['learnguard' => $instance->id, 'userid' => $USER->id], 'attempt DESC');
// Determine whether this student may see their own scores.
// Managers can always see scores; students only see them when show_results=1
// (default: show immediately) OR after the teacher has manually released them.
$showresults = $canManage
    || (!isset($instance->show_results) || (int)$instance->show_results === 1)
    || ((int)$instance->results_released > 0);

$myattempts = [];
foreach ($myattemptrecords as $a) {
    $myattempts[] = [
        'id'         => (int)$a->id,
        'attempt'    => (int)$a->attempt,
        'state'      => $a->state,
        'timestart'  => (int)$a->timestart,
        'timefinish' => (int)$a->timefinish,
        // Hide sumgrades from the history list when results are not yet released.
        'sumgrades'  => ($showresults && !is_null($a->sumgrades)) ? (float)$a->sumgrades : null,
    ];
}

// Availability check for students — takes any personal schedule
// (learnguard_schedules) into account, falling back to the instance-level window.
$isavailable = learnguard_is_available_for_user($instance, $USER->id);
$availmsg    = learnguard_availability_message_for_user($instance, $USER->id);
[$efftimeopen, $efftimeclose, $hasschedule] = learnguard_effective_window($instance, $USER->id);

// Branding.
$brandprimary   = get_config('learnguard', 'brand_primary')   ?: '#0f6cbf';
$brandaccent    = get_config('learnguard', 'brand_accent')    ?: '#28a745';
$brandname      = get_config('learnguard', 'brand_name')      ?: 'LearnGuard';
$brandtextcolor = get_config('learnguard', 'brand_textcolor') ?: '#ffffff';

// Per-activity branding override (admin.php → Brand tab) — only applied when
// the corresponding field is non-empty, otherwise the global setting above wins.
try {
    $brandoverride = $DB->get_record('learnguard_brand_overrides', ['learnguard' => $instance->id]);
    if ($brandoverride) {
        if (!empty($brandoverride->brand_name))      { $brandname      = $brandoverride->brand_name; }
        if (!empty($brandoverride->brand_primary))   { $brandprimary   = $brandoverride->brand_primary; }
        if (!empty($brandoverride->brand_accent))    { $brandaccent    = $brandoverride->brand_accent; }
        if (!empty($brandoverride->brand_textcolor)) { $brandtextcolor = $brandoverride->brand_textcolor; }
    }
} catch (Exception $e) {
    // Table not yet created (pre-upgrade) — fall back to global branding silently.
}

// -----------------------------------------------------------------------
// SPA configuration passed to JavaScript
// -----------------------------------------------------------------------
// Helper: read an int admin setting, falling back to $default only when the
// setting has never been saved — preserves an explicit "0" (unlike `?:`,
// which treats the string "0" as falsy).
$lgscoreconfig = function(string $key, int $default): int {
    $v = get_config('learnguard', $key);
    return ($v === false || $v === '') ? $default : (int)$v;
};

// Choice Essay grading rubric preview — computed up front (not inline in $spaconfig
// below) so a missing learnguard_rubric_criteria table (e.g. an interrupted upgrade —
// rubric_enabled is a plain column and degrades safely to false, but a genuinely
// missing table throws) can never take down this student-facing exam page entirely.
$rubricPreview = [];
if (!empty($instance->rubric_enabled) && !empty($instance->rubric_show_learner)) {
    try {
        $rubricPreview = array_values(array_map(fn($c) => [
            'name'   => format_string($c->name),
            'weight' => (int)$c->weight,
            'focus'  => format_string($c->focus ?? ''),
        ], $DB->get_records('learnguard_rubric_criteria', ['learnguard' => $instance->id], 'sortorder ASC')));
    } catch (Exception $e) { $rubricPreview = []; }
}

$spaconfig = [
    'cmid'              => $cm->id,
    'instanceid'        => $instance->id,
    'courseid'          => $course->id,
    'userid'            => (int)$USER->id,
    'attemptid'         => $existingattempt ? (int)$existingattempt->id : 0,
    'pathwayenabled'    => !empty($instance->pathway_enabled),
    // Choice Essay grading rubric preview — only ever populated when the admin has both
    // enabled the rubric AND chosen to show it to students; the actual grading
    // (grader.php) always enforces rubric_enabled server-side regardless of this flag.
    'rubric'            => $rubricPreview,
    'timelimit'         => $effectiveTilelimit,
    'timelimitformatted'=> $effectiveTilelimit > 0
        ? (int)floor($effectiveTilelimit / 60) . ' min' .
          ($effectiveTilelimit % 60 > 0 ? ' ' . ($effectiveTilelimit % 60) . ' sec' : '')
        : 'No limit',
    'questionsperpage'  => (int)$instance->questionsperpage,
    // Question pool info for instructions.
    // Fixed slots and pool-draw rules are mutually exclusive modes; only add pool drawcount
    // when no fixed slots are defined, so the count is not double-counted.
    'questionspool'     => (function() use ($DB, $instance) {
        $nfixed = $DB->count_records_select('learnguard_slots',
            'learnguard = :lgid AND attemptid = 0', ['lgid' => $instance->id]);
        $npool  = ($nfixed > 0) ? 0 : (int)$DB->get_field_sql(
            'SELECT COALESCE(SUM(drawcount), 0) FROM {learnguard_pools} WHERE learnguard = :lgid2',
            ['lgid2' => $instance->id]);
        return $nfixed + $npool;
    })(),
    'randomize'              => (bool)$instance->randomize,
    'numquestions'           => (int)($instance->num_questions ?? 0),
    'passmark'               => (float)($instance->passmark ?? 0),
    'show_section_breaks'    => !isset($instance->show_section_breaks) || (bool)$instance->show_section_breaks,
    // Section config — one entry per pool bank, sorted by sortorder.
    // Lets the SPA show section dividers, intro overlays, and per-section timers.
    'sections'          => (function() use ($DB, $instance) {
        $pools = $DB->get_records('learnguard_pools', ['learnguard' => $instance->id], 'sortorder ASC');
        $out   = [];
        foreach ($pools as $pool) {
            $cat  = $DB->get_record('learnguard_qbank_cats', ['id' => $pool->catid]);
            $out[] = [
                'catid'       => (int)$pool->catid,
                'name'        => $cat ? (string)$cat->name : 'Section',
                'drawcount'   => (int)$pool->drawcount,
                'totalinbank' => (int)$DB->count_records('learnguard_qbank', ['categoryid' => $pool->catid]),
                'timelimit'   => isset($pool->timelimit) ? (int)$pool->timelimit : 0,
                'sortorder'   => (int)$pool->sortorder,
            ];
        }
        return $out;
    })(),
    // Availability — effective per-user window (personal schedule overrides
    // the instance-level timeopen/timeclose when one has been assigned).
    'isavailable'       => $isavailable || $canManage,
    'timeopen'          => $efftimeopen,
    'timeclose'         => $efftimeclose,
    'hasschedule'       => $hasschedule,
    'servertime'        => time(),
    // Proctoring.
    'proctoring'        => (bool)$instance->proctoring,
    'skip_precheck'        => ($userSchedule && !empty($userSchedule->skip_precheck)),
    'exclude_violations'   => ($userSchedule && !empty($userSchedule->exclude_violations))
                                 ? array_values(array_filter(array_map('trim', explode(',', $userSchedule->exclude_violations))))
                                 : [],
    'enablefullscreen'  => (bool)$instance->proctor_fullscreen,
    'enablewebcam'      => (bool)$instance->proctor_webcam,
    'enableaudio'       => (bool)$instance->proctor_audio,
    'enabletabdetect'   => (bool)$instance->proctor_tabdetect,
    'enablekeyblock'    => (bool)$instance->proctor_keyblock,
    'enableclipboard'   => (bool)$instance->proctor_clipboard,
    'enableenvrecord'   => (bool)$instance->proctor_env_record,
    'noisethreshold'       => (int)(get_config('learnguard', 'noise_threshold') ?: 8),
    // Use per-activity interval when explicitly changed from the install default (30s);
    // otherwise fall back to the global plugin setting so site-wide changes take effect.
    'snapinterval'         => ((int)$instance->proctor_snapinterval !== 30
                               ? (int)$instance->proctor_snapinterval
                               : (int)(get_config('learnguard', 'snapinterval') ?: 30)) * 1000,
    'rndscreenshotinterval'=> (int)(get_config('learnguard', 'rndscreenshotinterval') ?? 60),
    'fcmethod'          => $instance->proctor_fcmethod,
    'faceapprovalmode'  => get_config('learnguard', 'face_approval_mode') ?: 'auto',
    // NULL = this activity has no override, so the site-wide admin setting applies and
    // picks up global changes immediately. A real stored number (including 0, which
    // disables auto-suspend for just this activity) always wins over the site default.
    // A "Continue & Review" reopened attempt (Assessment Manager override) always uses
    // the dedicated review_autosuspend_score setting instead, regardless of the above —
    // kept consistent with attempt_manager::get_effective_autosuspend_score(), which the
    // server applies for the same attempt on every subsequent scored event.
    'autosuspend_score'          => (!empty($existingattempt) && !empty($existingattempt->reopened_for_review))
                                     ? $lgscoreconfig('review_autosuspend_score', 50)
                                     : (($instance->autosuspend_score !== null)
                                        ? (int)$instance->autosuspend_score
                                        : $lgscoreconfig('autosuspend_score', 80)),
    'reinstate_threshold_delta'  => (int)(get_config('learnguard', 'reinstate_threshold_delta') ?: 20),
    // Risk-score points for flagged events — admin-configurable under
    // Site Administration > Plugins > LearnGuard > Risk Scores for Flagged Events.
    'scoreconfig'       => [
        'multiface'     => $lgscoreconfig('score_2nd_person',      25),
        'phone'         => $lgscoreconfig('score_phone_detected',  25),
        'faceanomaly'   => $lgscoreconfig('score_face_anomaly',    10),
        'facemissing'   => $lgscoreconfig('score_face_missing',    10),
        'audioclip'     => $lgscoreconfig('score_audio_clip',       5),
        'headdown'      => $lgscoreconfig('score_head_down',         5),
        'noblink'       => $lgscoreconfig('score_no_blink',         10),
        'tabswitch'     => $lgscoreconfig('score_tab_switch',        5),
        'fullscreenexit'=> $lgscoreconfig('score_fullscreen_exit',  10),
        'bgchange'      => $lgscoreconfig('score_bg_change',        10),
        'cameracovered' => $lgscoreconfig('score_camera_covered',   20),
        'keyviolation'  => $lgscoreconfig('score_key_violation',     5),
        'copypaste'     => $lgscoreconfig('score_copy_paste',        5),
        'printscreen'   => $lgscoreconfig('score_printscreen',      10),
        'devtoolsopen'  => $lgscoreconfig('score_devtools_open',    15),
        'devtoolsf12'   => $lgscoreconfig('score_devtools_f12',     10),
        'webcamdenied'  => $lgscoreconfig('score_webcam_denied',    20),
        'screenshareattempt' => $lgscoreconfig('score_screen_share_attempt', 25),
        'screensharestopped' => $lgscoreconfig('score_screen_share_stopped', 10),
        'poorlighting'  => $lgscoreconfig('score_poor_lighting',     5),
        'unusualdisplay'=> $lgscoreconfig('score_unusual_display',  10),
        'vmsuspected'   => $lgscoreconfig('score_vm_suspected',     10),
        'multimonitor'  => $lgscoreconfig('score_multi_monitor',    15),
        'objectbook'    => $lgscoreconfig('score_object_book',      10),
        'objectlaptop'  => $lgscoreconfig('score_object_laptop',    20),
        'objectremote'  => $lgscoreconfig('score_object_remote',     5),
        'objectkeyboard'=> $lgscoreconfig('score_object_keyboard',  10),
        'objectheadphones' => $lgscoreconfig('score_object_headphones', 15),
        'typingmismatch'=> $lgscoreconfig('score_typing_mismatch',   8),
    ],
    'head_down_threshold'    => $lgscoreconfig('head_down_threshold',    80),
    'head_down_consecutive'  => $lgscoreconfig('head_down_consecutive',   5),
    'no_blink_period'        => $lgscoreconfig('no_blink_period',       300),
    'no_blink_ear_drop'      => (float)(get_config('learnguard', 'no_blink_ear_drop') ?: 0.08),
    'break_min_minutes'           => (int)(get_config('learnguard', 'break_min_minutes') ?: 5),
    'break_max_minutes'           => (int)(get_config('learnguard', 'break_max_minutes') ?: 10),
    'checkin_violation_threshold' => (int)(get_config('learnguard', 'checkin_violation_threshold') ?: 5),
    'instructions'      => (!empty($instance->instructions))
                            ? $instance->instructions
                            : (get_config('learnguard', 'default_instructions') ?? ''),
    'privacynotice'     => get_config('learnguard', 'privacy_notice') ?: '',
    // Branding.
    'brandprimary'      => $brandprimary,
    'brandaccent'       => $brandaccent,
    'brandname'         => $brandname,
    'brandtextcolor'    => $brandtextcolor,
    'assessmentname'    => format_string($instance->name),
    // Moodle WS config.
    'wwwroot'           => $CFG->wwwroot,
    'sesskey'           => sesskey(),
    'canAttempt'        => $canAttempt,
    'canManage'         => $canManage,
    'canstart'          => ($canstart || $canManage),   // JS gate for Begin button.
    'maxattempts'       => $maxattempts,                // 0 = unlimited.
    'attemptstaken'     => $completedcount,             // how many finished/suspended.
    'myattempts'        => $myattempts,                 // this learner's attempt history.
    'maxgrade'          => (float)$instance->grade,
    // Phase 3.
    'requirepin'        => !empty(trim($instance->access_pin ?? '')),
    // Chat/Break also require proctoring to be on (both are exam-time features).
    // Chat/Break/SOS are each a tri-state: this activity's own explicit choice (0 or 1)
    // wins over the site-wide default in either direction; only when the activity has
    // never had an explicit choice made (NULL) does the site default apply — see
    // learnguard_feature_enabled() in lib.php (shared with classes/external.php's
    // server-side enforcement, so the two can never disagree).
    'enablechat'        => (bool)$instance->proctoring
                            && learnguard_feature_enabled($instance->enable_chat ?? null, 'enable_chat', true),
    'enablebreaks'      => (bool)$instance->proctoring
                            && learnguard_feature_enabled($instance->enable_break ?? null, 'enable_break', false),
    'enablesos'         => learnguard_feature_enabled($instance->enable_sos ?? null, 'enable_sos', true),
    // Face-api model URL.
    'modelurl'          => ($instance->proctor_fcmethod !== 'None')
                            ? $CFG->wwwroot . '/mod/learnguard/thirdpartylibs/models'
                            : null,
    // Report URLs.
    'reporturl'         => (new moodle_url('/mod/learnguard/report.php', ['id' => $cm->id]))->out(false),
    'liveurl'           => (new moodle_url('/mod/learnguard/proctordashboard.php', ['id' => $cm->id]))->out(false),
];

// Face-api.js: prefer local copy (thirdpartylibs/) over CDN.
// $PAGE->requires->js() rejects external URLs in Moodle 4.x, so CDN is injected
// via echo '<script>' after $OUTPUT->header() — same pattern as TF.js below.
if ($instance->proctoring && $instance->proctor_webcam) {
    $lgFaceApi = $CFG->dirroot . '/mod/learnguard/thirdpartylibs/face-api.min.js';
    $lsFaceApi = $CFG->dirroot . '/mod/quiz/accessrule/learnshield/amd/build/face-api.min.js';
    if (file_exists($lgFaceApi)) {
        $spaconfig['faceapiurl'] = $CFG->wwwroot . '/mod/learnguard/thirdpartylibs/face-api.min.js';
    } elseif (file_exists($lsFaceApi)) {
        $spaconfig['faceapiurl'] = $CFG->wwwroot . '/mod/quiz/accessrule/learnshield/amd/build/face-api.min.js';
    } else {
        // CDN fallback — always works without any local files.
        $spaconfig['faceapiurl'] = 'https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js';
    }

    // Model URL: local folder preferred, CDN fallback (jsDelivr-hosted weights).
    $lgModels = $CFG->dirroot . '/mod/learnguard/thirdpartylibs/models';
    $lsModels = $CFG->dirroot . '/mod/quiz/accessrule/learnshield/thirdpartylibs/models';
    if (is_dir($lgModels)) {
        $spaconfig['modelurl'] = $CFG->wwwroot . '/mod/learnguard/thirdpartylibs/models';
    } elseif (is_dir($lsModels)) {
        $spaconfig['modelurl'] = $CFG->wwwroot . '/mod/quiz/accessrule/learnshield/thirdpartylibs/models';
    } else {
        // CDN fallback — face-api.js official weights hosted on jsDelivr.
        $spaconfig['modelurl'] = 'https://cdn.jsdelivr.net/gh/justadudewhohacks/face-api.js@0.22.2/weights';
    }
}

// ONNX Runtime Web for YOLOv5n object detection (bundled, open-source, no CDN needed).
// Resolved here (before js_call_amd) so cfg.onnxurl reaches the SPA config — the
// matching <script src> tag is echoed after $OUTPUT->header() further down.
$onnxJsSrc = null;
if ($instance->proctoring && $instance->proctor_webcam) {
    $lgOnnxJs = $CFG->dirroot . '/mod/learnguard/thirdpartylibs/onnx/ort.min.js';
    $lsOnnxJs = $CFG->dirroot . '/mod/quiz/accessrule/learnshield/thirdpartylibs/onnx/ort.min.js';
    if (file_exists($lgOnnxJs)) {
        $onnxJsSrc = $CFG->wwwroot . '/mod/learnguard/thirdpartylibs/onnx/ort.min.js';
        $spaconfig['onnxurl'] = $CFG->wwwroot . '/mod/learnguard/thirdpartylibs/onnx';
    } elseif (file_exists($lsOnnxJs)) {
        $onnxJsSrc = $CFG->wwwroot . '/mod/quiz/accessrule/learnshield/thirdpartylibs/onnx/ort.min.js';
        $spaconfig['onnxurl'] = $CFG->wwwroot . '/mod/quiz/accessrule/learnshield/thirdpartylibs/onnx';
    }
}

$PAGE->requires->js_call_amd('mod_learnguard/assessment', 'init', [$spaconfig]);

// -----------------------------------------------------------------------
// Page output — the SPA shell
// -----------------------------------------------------------------------
// Use a minimal page layout so JavaScript has full control.
$PAGE->set_pagelayout('embedded');

echo $OUTPUT->header();

// Inject AI libraries after header so Moodle's URL validator is bypassed.
// All run on the STUDENT's browser — zero server RAM consumed.
if ($instance->proctoring && $instance->proctor_webcam) {
    // face-api.js for precheck face verification + live face matching.
    if (!empty($spaconfig['faceapiurl'])) {
        echo '<script src="' . s($spaconfig['faceapiurl']) . '"></script>' . "\n";
    }
    // ONNX Runtime Web for YOLOv5n object detection (bundled, open-source, no CDN needed).
    // $onnxJsSrc was resolved earlier (before js_call_amd) so cfg.onnxurl is correct.
    if ($onnxJsSrc !== null) {
        echo '<script src="' . s($onnxJsSrc) . '"></script>' . "\n";
    } else {
        // CDN fallback for COCO-SSD when ONNX not available.
        echo '<script src="https://cdn.jsdelivr.net/npm/@tensorflow/tfjs@4.20.0/dist/tf.min.js"></script>' . "\n";
        echo '<script src="https://cdn.jsdelivr.net/npm/@tensorflow-models/coco-ssd@2.2.3/dist/coco-ssd.min.js"></script>' . "\n";
    }
}
?>

<!-- LearnGuard SPA Shell — JavaScript takes over this container -->
<div id="learnguard-app" style="height:100vh;display:flex;flex-direction:column;background:#fff;">

    <!-- Loading screen — shown until JS initialises -->
    <div id="lg-loading" style="display:flex;flex-direction:column;align-items:center;justify-content:center;
         flex:1;font-family:system-ui,sans-serif;text-align:center;padding:40px;">
        <div style="font-size:64px;margin-bottom:20px;">🛡️</div>
        <h2 style="font-size:24px;margin-bottom:8px;">Learn Guard</h2>
        <p style="color:#6c757d;">Initialising secure assessment environment…</p>
        <div style="margin-top:20px;width:40px;height:40px;border:4px solid #e9ecef;
             border-top-color:#0f6cbf;border-radius:50%;animation:lg-spin 0.8s linear infinite;"></div>
        <style>@keyframes lg-spin { to { transform: rotate(360deg); } }</style>
    </div>

    <!-- Teacher / proctor view (shown if student has no attempt yet or for managers) -->
    <?php if ($canManage || $canReport): ?>
    <div id="lg-teacher-view" style="display:none;padding:24px;max-width:960px;margin:0 auto;width:100%;">
        <?php echo $OUTPUT->heading(format_string($instance->name)); ?>
        <p><?php echo format_module_intro('learnguard', $instance, $cm->id); ?></p>

        <!-- Primary management buttons — CSS grid, every cell identical width -->
        <?php
        // One common button style — no width here; the grid cell enforces equal sizing.
        $lgBtnStyle = 'display:flex;align-items:center;justify-content:center;gap:5px;' .
                      'padding:10px 8px;font-size:13px;font-weight:600;border-radius:7px;' .
                      'text-decoration:none;border:2px solid transparent;' .
                      'text-align:center;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;';

        function lgBtn(string $label, string $url, string $bg, string $fg = '#fff', string $border = ''): string {
            global $lgBtnStyle;
            $b = $border ?: $bg;
            return '<a href="' . $url . '" style="' . $lgBtnStyle .
                   'background:' . $bg . ';color:' . $fg . ';border-color:' . $b . ';">' . $label . '</a>';
        }
        function lgBtnO(string $label, string $url, string $color): string {
            global $lgBtnStyle;
            return '<a href="' . $url . '" style="' . $lgBtnStyle .
                   'background:#fff;color:' . $color . ';border-color:' . $color . ';">' . $label . '</a>';
        }

        // Build the flat list of buttons grouped by section.
        // Each section: ['sep' => 'Label'] OR ['btn' => html_string]
        $lgSections = [];

        if ($canManage) {
            $lgSections[] = ['sep' => 'Assessment & Content'];
            $lgSections[] = ['btn' => lgBtn('🛡 Admin Backend', (new moodle_url('/mod/learnguard/admin.php', ['id' => $cm->id]))->out(false), $brandprimary)];
            $lgSections[] = ['btn' => lgBtn('👁 Preview',       (new moodle_url('/mod/learnguard/preview.php', ['id' => $cm->id]))->out(false), '#e6a817')];
            $lgSections[] = ['btn' => lgBtn('📝 Questions',     (new moodle_url('/mod/learnguard/editquestions.php', ['id' => $cm->id]))->out(false), '#6c757d')];
            $lgSections[] = ['btn' => lgBtnO('🏦 Bank',         (new moodle_url('/mod/learnguard/questionbank.php', ['id' => $cm->id]))->out(false), '#6c757d')];
            $lgSections[] = ['btn' => lgBtnO('📥 Import',       (new moodle_url('/mod/learnguard/questionimport.php', ['id' => $cm->id]))->out(false), '#6c757d')];
        }

        if ($canProctor || $isGrader) {
            $lgSections[] = ['sep' => 'Monitoring & Results'];
            if ($canProctor) {
                $lgSections[] = ['btn' => lgBtn('🔴 Live Monitor', (new moodle_url('/mod/learnguard/proctordashboard.php', ['id' => $cm->id]))->out(false), '#dc3545')];
                $lgSections[] = ['btn' => lgBtnO('📊 Reports',     (new moodle_url('/mod/learnguard/report.php', ['id' => $cm->id]))->out(false), '#0d6efd')];
                $lgSections[] = ['btn' => lgBtnO('🏆 Scores',      (new moodle_url('/mod/learnguard/scores.php', ['id' => $cm->id]))->out(false), '#0d6efd')];
            }
            if ($isGrader) {
                $lgSections[] = ['btn' => lgBtnO('✏️ Grader',      (new moodle_url('/mod/learnguard/grader.php', ['id' => $cm->id]))->out(false), '#0d6efd')];
            }
        }

        if ($canManage) {
            $lgSections[] = ['sep' => 'Management & Configuration'];
            $lgSections[] = ['btn' => lgBtnO('📅 Schedule',       (new moodle_url('/mod/learnguard/schedule.php', ['id' => $cm->id]))->out(false), '#198754')];
            $lgSections[] = ['btn' => lgBtnO('👥 Manage Graders', (new moodle_url('/mod/learnguard/admin.php', ['id' => $cm->id, 'tab' => 'graders']))->out(false), '#198754')];
            $lgSections[] = ['btn' => lgBtnO('⚙️ Settings',       (new moodle_url('/mod/learnguard/admin.php', ['id' => $cm->id, 'tab' => 'settings']))->out(false), '#495057')];
            $lgSections[] = ['btn' => lgBtnO('🎨 Brand',          (new moodle_url('/mod/learnguard/admin.php', ['id' => $cm->id, 'tab' => 'brand']))->out(false), '#495057')];
            $lgSections[] = ['btn' => lgBtnO('🎓 Course Page',    (new moodle_url('/course/view.php', ['id' => $cm->course]))->out(false), '#0f6cbf')];
            if (is_siteadmin()) {
                $lgSections[] = ['btn' => lgBtnO('🌐 Site Admin',      (new moodle_url('/mod/learnguard/siteadmin.php'))->out(false), '#343a40')];
                $lgSections[] = ['btn' => lgBtnO('🗺 Overview',        (new moodle_url('/mod/learnguard/overview.php'))->out(false), '#495057')];
                $lgSections[] = ['btn' => lgBtnO('⚙️ Plugin Settings', (new moodle_url('/admin/settings.php', ['section' => 'modsettinglearnguard']))->out(false), '#495057')];
            }
        }

        // Render: one 5-column CSS grid; separators span all 5 columns.
        ?>
        <div style="margin-top:14px;margin-bottom:16px;
                    display:grid;grid-template-columns:repeat(5,1fr);gap:8px;align-items:stretch;">
            <?php foreach ($lgSections as $item): ?>
                <?php if (isset($item['sep'])): ?>
                    <div style="grid-column:1/-1;font-size:11px;font-weight:700;text-transform:uppercase;
                                color:#6c757d;letter-spacing:.6px;margin-top:6px;padding-top:6px;
                                border-top:1px solid #e9ecef;">
                        <?php echo s($item['sep']); ?>
                    </div>
                <?php else: ?>
                    <?php echo $item['btn']; ?>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php if (learnguard_is_proctor($instance->id) && !$canManage): ?>
        <div class="alert alert-info mt-2" style="display:flex;align-items:center;gap:10px;">
            <span>👤 <strong>You are assigned as a proctor</strong> for this assessment.</span>
            <a href="<?php echo new moodle_url('/mod/learnguard/proctordashboard.php', ['id' => $cm->id]); ?>"
               class="btn btn-danger btn-sm">🔴 Live Dashboard</a>
            <a href="<?php echo new moodle_url('/mod/learnguard/report.php', ['id' => $cm->id]); ?>"
               class="btn btn-outline-secondary btn-sm">📊 Reports</a>
        </div>
        <?php endif; ?>
        <?php if (!$hasquestions): ?>
        <div class="alert alert-warning mt-3">
            <?php echo get_string('error:noquestions', 'learnguard'); ?>
        </div>
        <?php endif; ?>

        <?php if ($canManage || $canProctor): ?>
        <?php
        // Learner Progress — counts of ENROLLED LEARNERS, not attempts (a learner with
        // 2 attempts would otherwise be double-counted, and a learner with none at all
        // wouldn't show up anywhere). Placed below everything else on this page, since
        // it's a summary rather than an action.
        try {
            global $DB;
            $lpEnrolledUsers = get_enrolled_users($context, 'mod/learnguard:attempt', 0, 'u.id');
            $lpTotalLearners = count($lpEnrolledUsers);
            $lpUserIds = array_keys($lpEnrolledUsers);
            $lpCompleted = 0; $lpInProgress = 0;
            if (!empty($lpUserIds)) {
                [$lpInSql, $lpInParams] = $DB->get_in_or_equal($lpUserIds, SQL_PARAMS_NAMED);
                $lpStateRows = $DB->get_records_sql(
                    "SELECT userid, MAX(CASE WHEN state = 'finished' THEN 1 ELSE 0 END) AS hasfinished,
                            MAX(CASE WHEN state = 'inprogress' THEN 1 ELSE 0 END) AS hasinprogress
                       FROM {learnguard_attempts}
                      WHERE learnguard = :lgid AND userid $lpInSql
                   GROUP BY userid",
                    array_merge(['lgid' => $instance->id], $lpInParams)
                );
                foreach ($lpStateRows as $lpr) {
                    if ((int)$lpr->hasfinished > 0) { $lpCompleted++; }
                    else if ((int)$lpr->hasinprogress > 0) { $lpInProgress++; }
                }
            }
            $lpNotStarted = max(0, $lpTotalLearners - $lpCompleted - $lpInProgress);
            $lpPctC = $lpTotalLearners > 0 ? round($lpCompleted   / $lpTotalLearners * 100, 1) : 0;
            $lpPctP = $lpTotalLearners > 0 ? round($lpInProgress  / $lpTotalLearners * 100, 1) : 0;
            $lpPctN = $lpTotalLearners > 0 ? round($lpNotStarted  / $lpTotalLearners * 100, 1) : 0;
        ?>
        <div style="margin-top:22px;border:1px solid #e9ecef;border-radius:12px;padding:20px 24px;background:#fbfcfd;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px;">
                <div style="font-size:14px;font-weight:700;color:#343a40;">👥 Learner Progress</div>
                <div style="font-size:12px;color:#6c757d;">
                    <strong style="color:#212529;font-size:15px;"><?php echo $lpTotalLearners; ?></strong> enrolled learner<?php echo $lpTotalLearners === 1 ? '' : 's'; ?>
                </div>
            </div>
            <?php if ($lpTotalLearners > 0): ?>
            <div style="display:flex;height:10px;border-radius:6px;overflow:hidden;background:#e9ecef;margin-bottom:14px;">
                <?php if ($lpPctC > 0): ?><div style="width:<?php echo $lpPctC; ?>%;background:#28a745;" title="Completed: <?php echo $lpCompleted; ?>"></div><?php endif; ?>
                <?php if ($lpPctP > 0): ?><div style="width:<?php echo $lpPctP; ?>%;background:#fd7e14;" title="In Progress: <?php echo $lpInProgress; ?>"></div><?php endif; ?>
                <?php if ($lpPctN > 0): ?><div style="width:<?php echo $lpPctN; ?>%;background:#ced4da;" title="Not Yet Started: <?php echo $lpNotStarted; ?>"></div><?php endif; ?>
            </div>
            <?php else: ?>
            <div style="font-size:13px;color:#adb5bd;font-style:italic;margin-bottom:14px;">No learners enrolled with attempt access yet.</div>
            <?php endif; ?>
            <div style="display:flex;gap:24px;flex-wrap:wrap;">
                <?php foreach ([
                    ['#28a745', 'Completed',       $lpCompleted,  $lpPctC],
                    ['#fd7e14', 'In Progress',     $lpInProgress, $lpPctP],
                    ['#ced4da', 'Not Yet Started', $lpNotStarted, $lpPctN, '#6c757d'],
                ] as $lpRow):
                    [$lpDot, $lpLabel, $lpCount, $lpPct] = $lpRow;
                    $lpTextColour = $lpRow[4] ?? '#343a40';
                ?>
                <div style="display:flex;align-items:center;gap:8px;">
                    <span style="width:11px;height:11px;border-radius:50%;background:<?php echo $lpDot; ?>;flex-shrink:0;"></span>
                    <span style="font-size:18px;font-weight:700;color:<?php echo $lpTextColour; ?>;"><?php echo $lpCount; ?></span>
                    <span style="font-size:12px;color:#6c757d;"><?php echo s($lpLabel); ?><?php echo $lpTotalLearners > 0 ? ' (' . $lpPct . '%)' : ''; ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php } catch (Exception $e) { /* enrolment lookup failed — skip this panel, not fatal */ } ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Main SPA container — JS renders all content here -->
    <div id="lg-spa-container" style="display:none;flex:1;overflow:hidden;position:relative;"></div>

</div>

<?php
echo $OUTPUT->footer();
