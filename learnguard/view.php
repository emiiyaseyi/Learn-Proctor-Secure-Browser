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
$PAGE->set_activity_record($instance);
$PAGE->requires->css('/mod/learnguard/styles.css');

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
$existingattempt = $DB->get_record('learnguard_attempts', [
    'learnguard' => $instance->id,
    'userid'     => $USER->id,
    'state'      => 'inprogress',
]);

// Has questions?
$hasquestions = $DB->record_exists('learnguard_slots', ['learnguard' => $instance->id]);

// -----------------------------------------------------------------------
// SPA configuration passed to JavaScript
// -----------------------------------------------------------------------
$spaconfig = [
    'cmid'              => $cm->id,
    'instanceid'        => $instance->id,
    'courseid'          => $course->id,
    'userid'            => (int)$USER->id,
    'attemptid'         => $existingattempt ? (int)$existingattempt->id : 0,
    'timelimit'         => (int)$instance->timelimit,
    'questionsperpage'  => (int)$instance->questionsperpage,
    // Proctoring.
    'proctoring'        => (bool)$instance->proctoring,
    'enablefullscreen'  => (bool)$instance->proctor_fullscreen,
    'enablewebcam'      => (bool)$instance->proctor_webcam,
    'enableaudio'       => (bool)$instance->proctor_audio,
    'enabletabdetect'   => (bool)$instance->proctor_tabdetect,
    'enablekeyblock'    => (bool)$instance->proctor_keyblock,
    'enableclipboard'   => (bool)$instance->proctor_clipboard,
    'enableenvrecord'   => (bool)$instance->proctor_env_record,
    'snapinterval'      => (int)$instance->proctor_snapinterval * 1000,
    'fcmethod'          => $instance->proctor_fcmethod,
    'autosuspend_score' => (int)$instance->autosuspend_score,
    'instructions'      => $instance->instructions ?? '',
    // Moodle WS config.
    'wwwroot'           => $CFG->wwwroot,
    'sesskey'           => sesskey(),
    'canAttempt'        => $canAttempt,
    'canManage'         => $canManage,
    // Face-api model URL.
    'modelurl'          => ($instance->proctor_fcmethod !== 'None')
                            ? $CFG->wwwroot . '/mod/learnguard/thirdpartylibs/models'
                            : null,
    // Report URLs.
    'reporturl'         => (new moodle_url('/mod/learnguard/report.php', ['id' => $cm->id]))->out(false),
    'liveurl'           => (new moodle_url('/mod/learnguard/proctordashboard.php', ['id' => $cm->id]))->out(false),
];

// Load face-api if needed.
if ($instance->proctoring && $instance->proctor_fcmethod !== 'None') {
    $PAGE->requires->js('/mod/learnguard/thirdpartylibs/face-api.min.js', true);
}

$PAGE->requires->js_call_amd('mod_learnguard/assessment', 'init', [$spaconfig]);

// -----------------------------------------------------------------------
// Page output — the SPA shell
// -----------------------------------------------------------------------
// Use a minimal page layout so JavaScript has full control.
$PAGE->set_pagelayout('embedded');

echo $OUTPUT->header();
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
               class="btn btn-dark">🛡 Admin Backend</a>
            <?php endif; ?>
            <?php if (is_siteadmin()): ?>
            <a href="<?php echo new moodle_url('/mod/learnguard/siteadmin.php'); ?>"
               class="btn btn-outline-dark">🌐 Site Admin</a>
            <?php endif; ?>
            <a href="<?php echo new moodle_url('/mod/learnguard/report.php', ['id' => $cm->id]); ?>"
               class="btn btn-primary">📊 Attempts &amp; Reports</a>
            <?php if ($canProctor): ?>
            <a href="<?php echo new moodle_url('/mod/learnguard/proctordashboard.php', ['id' => $cm->id]); ?>"
               class="btn btn-danger">🔴 Live Dashboard</a>
            <?php endif; ?>
            <?php if ($canManage): ?>
            <a href="<?php echo new moodle_url('/mod/learnguard/editquestions.php', ['id' => $cm->id]); ?>"
               class="btn btn-secondary">📝 Questions</a>
            <?php endif; ?>
        </div>
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
