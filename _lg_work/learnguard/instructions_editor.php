<?php
/**
 * Admin page to edit the default exam instruction message.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url('/mod/learnguard/instructions_editor.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('LearnGuard — Instruction Editor');
$PAGE->set_heading('LearnGuard — Edit Default Exam Instructions');
$PAGE->requires->css('/mod/learnguard/styles.css');

$brand = learnguard_brand();

if (data_submitted() && confirm_sesskey()) {
    $newhtml = optional_param('instructions_html', '', PARAM_RAW);
    set_config('default_instructions', $newhtml, 'learnguard');
    redirect(new moodle_url('/mod/learnguard/instructions_editor.php'),
        'Instructions updated successfully.', null, \core\output\notification::NOTIFY_SUCCESS);
}

$current = get_config('learnguard', 'default_instructions') ?: '';

echo $OUTPUT->header();
echo learnguard_nav_bar(0, 'Instruction Editor');

echo learnguard_banner('📄 Default Exam Instruction Editor',
    'This message is shown to students before they start any assessment (unless the assessment has its own instructions set).');

echo html_writer::start_tag('form', ['method' => 'post']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

// Show a rich text area using Moodle's editor
$editor = $PAGE->get_renderer('core');
echo html_writer::start_div('', ['style' => 'max-width:960px;margin:0 auto;']);
echo html_writer::tag('h5', 'Default Instructions (HTML)', ['class' => 'mb-2']);
echo html_writer::tag('p', 'This supports HTML. Use &lt;ul&gt;, &lt;li&gt;, &lt;strong&gt;, &lt;p&gt; etc.', ['style' => 'font-size:13px;color:#6c757d;']);

echo '<textarea name="instructions_html" id="lg-instructions-area" rows="24" ' .
     'style="width:100%;padding:14px;font-family:monospace;font-size:13px;border:1px solid #dee2e6;' .
     'border-radius:8px;line-height:1.6;background:#fafafa;" spellcheck="false">' .
     htmlspecialchars($current) . '</textarea>';

echo html_writer::start_div('mt-3 d-flex gap-3 align-items-center');
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => '💾 Save Instructions', 'class' => 'btn btn-primary btn-lg']);
echo html_writer::link(new moodle_url('/mod/learnguard/siteadmin.php'),
    '← Back to Admin', ['class' => 'btn btn-outline-secondary']);
echo html_writer::end_div();

echo html_writer::end_div();
echo html_writer::end_tag('form');

// Live preview panel
echo '<div style="max-width:960px;margin:24px auto 0;">';
echo html_writer::tag('h5', '👁 Live Preview', ['class' => 'mb-2']);
echo '<div id="lg-instructions-preview" style="border:1px solid #dee2e6;border-radius:8px;padding:20px;' .
     'min-height:200px;background:#fff;font-family:Segoe UI,system-ui,sans-serif;font-size:15px;line-height:1.7;"></div>';
echo '</div>';

?>
<script>
(function() {
    var ta = document.getElementById('lg-instructions-area');
    var pv = document.getElementById('lg-instructions-preview');
    function update() { if (pv) pv.innerHTML = ta.value; }
    if (ta) { ta.addEventListener('input', update); update(); }
})();
</script>
<?php
echo $OUTPUT->footer();
