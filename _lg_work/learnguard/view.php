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

// Has questions?
$hasquestions = $DB->record_exists('learnguard_slots', ['learnguard' => $instance->id]);

// Attempt-count check — computed here so the JS can show a friendly
// "no attempts left" message instead of firing the WS and getting a
// cryptic "no permission" error.
$maxattempts    = (int)($instance->attempts ?? 0);
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
$myattempts = [];
foreach ($myattemptrecords as $a) {
    $myattempts[] = [
        'attempt'    => (int)$a->attempt,
        'state'      => $a->state,
        'timestart'  => (int)$a->timestart,
        'timefinish' => (int)$a->timefinish,
        'sumgrades'  => is_null($a->sumgrades) ? null : (float)$a->sumgrades,
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

$spaconfig = [
    'cmid'              => $cm->id,
    'instanceid'        => $instance->id,
    'courseid'          => $course->id,
    'userid'            => (int)$USER->id,
    'attemptid'         => $existingattempt ? (int)$existingattempt->id : 0,
    'timelimit'         => (int)$instance->timelimit,
    'timelimitformatted'=> $instance->timelimit > 0
        ? (int)floor($instance->timelimit / 60) . ' min' .
          ($instance->timelimit % 60 > 0 ? ' ' . ($instance->timelimit % 60) . ' sec' : '')
        : 'No limit',
    'questionsperpage'  => (int)$instance->questionsperpage,
    // Question pool info for instructions.
    'questionspool'     => $DB->count_records('learnguard_slots', ['learnguard' => $instance->id]),
    'randomize'         => (bool)$instance->randomize,
    'numquestions'      => (int)($instance->num_questions ?? 0),
    // Availability — effective per-user window (personal schedule overrides
    // the instance-level timeopen/timeclose when one has been assigned).
    'isavailable'       => $isavailable || $canManage,
    'timeopen'          => $efftimeopen,
    'timeclose'         => $efftimeclose,
    'hasschedule'       => $hasschedule,
    'servertime'        => time(),
    // Proctoring.
    'proctoring'        => (bool)$instance->proctoring,
    'enablefullscreen'  => (bool)$instance->proctor_fullscreen,
    'enablewebcam'      => (bool)$instance->proctor_webcam,
    'enableaudio'       => (bool)$instance->proctor_audio,
    'enabletabdetect'   => (bool)$instance->proctor_tabdetect,
    'enablekeyblock'    => (bool)$instance->proctor_keyblock,
    'enableclipboard'   => (bool)$instance->proctor_clipboard,
    'enableenvrecord'   => (bool)$instance->proctor_env_record,
    'noisethreshold'       => (int)(get_config('learnguard', 'noise_threshold') ?: 8),
    'snapinterval'         => (int)$instance->proctor_snapinterval * 1000,
    'rndscreenshotinterval'=> (int)(get_config('learnguard', 'rndscreenshotinterval') ?? 60),
    'fcmethod'          => $instance->proctor_fcmethod,
    'faceapprovalmode'  => get_config('learnguard', 'face_approval_mode') ?: 'auto',
    // Prefer per-activity value if explicitly changed from the install default (80);
    // otherwise use the site-wide admin setting so global changes take effect immediately.
    'autosuspend_score'          => ($instance->autosuspend_score != 80)
                                     ? (int)$instance->autosuspend_score
                                     : $lgscoreconfig('autosuspend_score', 80),
    'reinstate_threshold_delta'  => (int)(get_config('learnguard', 'reinstate_threshold_delta') ?: 20),
    // Risk-score points for flagged events — admin-configurable under
    // Site Administration > Plugins > LearnGuard > Risk Scores for Flagged Events.
    'scoreconfig'       => [
        'multiface'   => $lgscoreconfig('score_2nd_person',     25),
        'phone'       => $lgscoreconfig('score_phone_detected', 25),
        'faceanomaly' => $lgscoreconfig('score_face_anomaly',   10),
        'facemissing' => $lgscoreconfig('score_face_missing',   10),
        'audioclip'   => $lgscoreconfig('score_audio_clip',      5),
        'headdown'    => $lgscoreconfig('score_head_down',        5),
        'noblink'     => $lgscoreconfig('score_no_blink',        10),
    ],
    'head_down_threshold'    => $lgscoreconfig('head_down_threshold',    80),
    'head_down_consecutive'  => $lgscoreconfig('head_down_consecutive',   5),
    'no_blink_period'        => $lgscoreconfig('no_blink_period',       300),
    'no_blink_ear_drop'      => (float)(get_config('learnguard', 'no_blink_ear_drop') ?: 0.08),
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
    'enablechat'        => (bool)$instance->proctoring,  // chat only during proctored exams.
    'enablebreaks'      => (bool)$instance->proctoring,
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

        <!-- Primary management buttons -->
        <div class="d-flex gap-2 flex-wrap mt-3 mb-2">
            <?php if ($canManage): ?>
            <a href="<?php echo new moodle_url('/mod/learnguard/admin.php', ['id' => $cm->id]); ?>"
               class="btn btn-dark" style="background:<?php echo $brandprimary; ?>;border-color:<?php echo $brandprimary; ?>;">🛡 Admin Backend</a>
            <a href="<?php echo new moodle_url('/mod/learnguard/preview.php', ['id' => $cm->id]); ?>"
               class="btn btn-warning">👁 Preview</a>
            <?php endif; ?>
            <a href="<?php echo new moodle_url('/mod/learnguard/report.php', ['id' => $cm->id]); ?>"
               class="btn btn-primary">📊 Reports</a>
            <?php if ($canProctor): ?>
            <a href="<?php echo new moodle_url('/mod/learnguard/proctordashboard.php', ['id' => $cm->id]); ?>"
               class="btn btn-danger">🔴 Live Monitor</a>
            <?php endif; ?>
            <?php if ($canManage): ?>
            <a href="<?php echo new moodle_url('/mod/learnguard/editquestions.php', ['id' => $cm->id]); ?>"
               class="btn btn-secondary">📝 Questions</a>
            <a href="<?php echo new moodle_url('/mod/learnguard/questionbank.php', ['id' => $cm->id]); ?>"
               class="btn btn-outline-secondary">🏦 Bank</a>
            <?php endif; ?>
            <?php if ($canProctor): ?>
            <a href="<?php echo new moodle_url('/mod/learnguard/scores.php', ['id' => $cm->id]); ?>"
               class="btn btn-outline-primary">🏆 Scores</a>
            <?php endif; ?>
            <?php if ($isGrader): ?>
            <a href="<?php echo new moodle_url('/mod/learnguard/grader.php', ['id' => $cm->id]); ?>"
               class="btn btn-outline-primary">✏️ Grader</a>
            <?php endif; ?>
            <?php if ($canManage): ?>
            <a href="<?php echo new moodle_url('/mod/learnguard/schedule.php', ['id' => $cm->id]); ?>"
               class="btn btn-outline-success">📅 Schedule</a>
            <a href="<?php echo new moodle_url('/mod/learnguard/admin.php', ['id' => $cm->id, 'tab' => 'graders']); ?>"
               class="btn btn-outline-success">👥 Manage Graders</a>
            <a href="<?php echo new moodle_url('/mod/learnguard/admin.php', ['id' => $cm->id, 'tab' => 'settings']); ?>"
               class="btn btn-outline-secondary">⚙️ Settings</a>
            <a href="<?php echo new moodle_url('/mod/learnguard/admin.php', ['id' => $cm->id, 'tab' => 'brand']); ?>"
               class="btn btn-outline-secondary">🎨 Brand</a>
            <?php endif; ?>
            <?php if (is_siteadmin()): ?>
            <a href="<?php echo new moodle_url('/mod/learnguard/siteadmin.php'); ?>"
               class="btn btn-outline-dark">🌐 Site Admin</a>
            <a href="<?php echo new moodle_url('/mod/learnguard/overview.php'); ?>"
               class="btn btn-outline-secondary">🗺 Overview</a>
            <?php endif; ?>
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
    </div>
    <?php endif; ?>

    <!-- Main SPA container — JS renders all content here -->
    <div id="lg-spa-container" style="display:none;flex:1;overflow:hidden;position:relative;"></div>

</div>

<?php
echo $OUTPUT->footer();
