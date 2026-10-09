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
 * Email Preview / Sample Send — shows the branded HTML template every LearnGuard email
 * now uses (learnguard_html_email() in lib.php), rendered right on the page, with a
 * one-click "send a real sample to my own inbox" button so an admin can check exactly
 * how it looks and attaches in their own mail client (Gmail, Outlook, etc.) before
 * trusting it for real student-facing notifications.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2026 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url('/mod/learnguard/emailtest.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('LearnGuard — Email Preview');
$PAGE->set_heading('LearnGuard — Email Preview');
$PAGE->set_pagelayout('admin');

$sampleSubject = '✅ Sample LearnGuard Email — Preview';
$sampleBody = "Hello " . fullname($USER) . ",\n\n" .
    "This is a sample email so you can see exactly how LearnGuard's emails now look in " .
    "a real inbox — the same branded template is used for every notification the plugin " .
    "sends (reinstatement, suspension, reminders, review grants, Export Submissions PDFs, " .
    "and anything sent through the shared notification helper).\n\n" .
    "If this reads properly, with real formatting (not a plain block of text) and opens " .
    "cleanly in Gmail, Outlook, or whichever client you check it in, the template is working " .
    "as intended.\n\n" .
    "LearnGuard — Secure Assessment Platform";
$sampleCards = [
    ['Assessment', 'Sample Assessment'],
    ['Records', '32'],
    ['Generated', userdate(time(), '%d %b %Y, %H:%M')],
];

if (data_submitted() && confirm_sesskey() && optional_param('sendsample', 0, PARAM_INT)) {
    $fromEmail = get_config('learnguard', 'notify_from_email');
    $fromName  = get_config('learnguard', 'notify_from_name') ?: 'LearnGuard';
    $from = $fromEmail
        ? (object)['email' => $fromEmail, 'firstname' => $fromName, 'lastname' => '', 'maildisplay' => 1]
        : \core_user::get_support_user();
    $htmlBody = learnguard_html_email($sampleSubject, $sampleBody, '✅', $sampleCards);
    $sent = false;
    $err  = null;
    try {
        $sent = email_to_user($USER, $from, $sampleSubject, $sampleBody, $htmlBody);
    } catch (\Throwable $e) {
        $err = $e->getMessage();
    }
    redirect(new moodle_url('/mod/learnguard/emailtest.php'),
        $sent ? 'Sample email sent to ' . s($USER->email) . ' — check your inbox (and spam folder, just in case).'
              : 'Failed to send sample email.' . ($err ? ' (' . s($err) . ')' : ''),
        null, $sent ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_ERROR);
}

echo $OUTPUT->header();
echo learnguard_nav_bar(0, 'Email Preview');

echo html_writer::tag('h3', '📧 Email Preview / Sample Send');
echo html_writer::tag('p',
    'Every email LearnGuard sends now uses this same branded template (header banner, ' .
    'real paragraphs, optional info cards, footer) instead of a plain text dump. Use the ' .
    'button below to send a real sample to your own inbox (' . s($USER->email) . ') and ' .
    'confirm it renders correctly and attaches cleanly in your mail client.',
    ['style' => 'color:#6c757d;font-size:13px;max-width:700px;']);

echo '<form method="post" style="margin:16px 0;">';
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sendsample', 'value' => '1']);
echo '<button type="submit" class="btn btn-primary">📨 Send Sample Email To My Inbox (' . s($USER->email) . ')</button>';
echo '</form>';

echo html_writer::tag('h4', 'Live Preview', ['style' => 'margin-top:24px;']);
echo html_writer::div(
    '<div style="background:#f1f3f5;padding:24px;">' .
    learnguard_html_email($sampleSubject, $sampleBody, '✅', $sampleCards) .
    '</div>',
    '', ['style' => 'border:1px solid #dee2e6;border-radius:6px;overflow:hidden;max-width:650px;']
);

echo $OUTPUT->footer();
