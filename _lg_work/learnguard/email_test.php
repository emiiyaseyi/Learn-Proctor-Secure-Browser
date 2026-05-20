<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Test email delivery from LearnGuard.
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

$PAGE->set_url('/mod/learnguard/email_test.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('LearnGuard — Test Email');
$PAGE->set_heading('LearnGuard — Test Email Delivery');

$sent = false;
$error = '';

if (data_submitted() && confirm_sesskey()) {
    $toEmail = required_param('to_email', PARAM_EMAIL);
    $toUser = $DB->get_record('user', ['email' => $toEmail, 'deleted' => 0]);
    if (!$toUser) {
        // Create a minimal user object for email_to_user
        $toUser = (object)[
            'id'         => 0,
            'email'      => $toEmail,
            'firstname'  => 'Test',
            'lastname'   => 'Recipient',
            'mailformat' => 1,
            'auth'       => 'manual',
            'confirmed'  => 1,
            'deleted'    => 0,
            'suspended'  => 0,
        ];
    }
    $result = learnguard_send_email(
        $toUser,
        'LearnGuard Test Email — ' . date('d/m/Y H:i'),
        "Hello,\n\nThis is a test email from LearnGuard to confirm your outgoing mail is working correctly.\n\n" .
        "If you receive this, your email configuration is set up properly.\n\n" .
        "Sent at: " . date('d/m/Y H:i:s') . "\n\n" .
        "LearnGuard — Secure Assessment Platform"
    );
    if ($result) { $sent = true; }
    else         { $error = 'Email function returned false — check your Moodle outgoing mail settings at Site admin → Server → Email.'; }
}

echo $OUTPUT->header();
echo learnguard_nav_bar(0, 'Test Email');
echo learnguard_banner('Test Email Delivery', 'Verify LearnGuard can send emails using your Moodle mail configuration.', '📧');

if ($sent): ?>
<div class="alert alert-success">
  ✅ Test email sent successfully! Check the inbox of the address you entered.
  <br><small>If it doesn't arrive, check spam/junk folder or verify your
  <a href="/admin/settings.php?section=outgoingmailconfig" target="_blank">Moodle outgoing mail settings</a>.</small>
</div>
<?php endif;
if ($error): ?>
<div class="alert alert-danger">❌ <?php echo s($error); ?></div>
<?php endif; ?>

<div style="max-width:500px;background:#fff;border-radius:10px;padding:24px;box-shadow:0 2px 12px rgba(0,0,0,0.08);">
  <p style="color:#495057;font-size:14px;margin-bottom:16px;">
    LearnGuard uses Moodle's outgoing mail system for all notifications.
    Configure your SMTP server at:
    <a href="/admin/settings.php?section=outgoingmailconfig" target="_blank">
      Site admin → Server → Email → Outgoing mail configuration
    </a>.
  </p>

  <form method="post">
    <?php echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]); ?>
    <div style="margin-bottom:14px;">
      <label style="font-weight:700;font-size:14px;display:block;margin-bottom:6px;">Send test email to:</label>
      <input type="email" name="to_email" required class="form-control"
             value="<?php echo s($USER->email); ?>"
             placeholder="recipient@example.com" style="max-width:100%;">
      <small class="text-muted">Defaults to your own email address.</small>
    </div>
    <button type="submit" class="btn btn-primary">📧 Send Test Email</button>
    <a href="<?php echo new moodle_url('/admin/settings.php?section=learnguard'); ?>"
       class="btn btn-outline-secondary ms-2">← Back to Settings</a>
  </form>

  <hr style="margin:20px 0;">

  <h5 style="font-size:14px;font-weight:700;">Current configuration:</h5>
  <table style="font-size:13px;width:100%;">
    <tr><td style="color:#6c757d;padding:3px 0;width:140px;">From address:</td>
        <td><?php echo s(get_config('learnguard', 'notify_from_email') ?: '(Moodle support email)'); ?></td></tr>
    <tr><td style="color:#6c757d;padding:3px 0;">From name:</td>
        <td><?php echo s(get_config('learnguard', 'notify_from_name') ?: 'LearnGuard'); ?></td></tr>
    <tr><td style="color:#6c757d;padding:3px 0;">Notifications:</td>
        <td><?php echo get_config('learnguard', 'notify_email_enabled') ? '✅ Enabled' : '❌ Disabled'; ?></td></tr>
    <tr><td style="color:#6c757d;padding:3px 0;">Proctor alerts:</td>
        <td><?php echo get_config('learnguard', 'notify_proctors_enabled') ? '✅ Enabled' : '❌ Disabled'; ?></td></tr>
    <tr><td style="color:#6c757d;padding:3px 0;">Hours before exam:</td>
        <td><?php echo (int)(get_config('learnguard', 'notify_hours_before') ?: 24); ?> hours</td></tr>
    <tr><td style="color:#6c757d;padding:3px 0;">SMTP (Moodle):</td>
        <td><?php echo s($CFG->smtphosts ?: 'Not configured — using sendmail/PHP mail'); ?></td></tr>
  </table>
</div>

<?php echo $OUTPUT->footer();
