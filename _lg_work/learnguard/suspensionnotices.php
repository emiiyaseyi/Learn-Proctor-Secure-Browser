<?php
/**
 * Suspension Notices Log — audit trail of every "Confirm Suspension (Notify Student)"
 * email (from attemptdetail.php) and every "Reinstate & Notify" email (from report.php):
 * reason/message, evidence attached, and whether the email actually delivered (or was
 * skipped because its admin setting was disabled), so proctors/admins can review what a
 * student was told later on.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_context($context);
$PAGE->set_url('/mod/learnguard/suspensionnotices.php');
$PAGE->set_title('LearnGuard Suspension Notices');
$PAGE->set_heading('LearnGuard — Suspension Notices Log');

$search   = trim(optional_param('search', '', PARAM_TEXT));
$mailfilter = optional_param('mailstatus', '', PARAM_ALPHA);
$typefilter = optional_param('noticetype', '', PARAM_ALPHA);
$pgnum    = optional_param('p', 0, PARAM_INT);
$perPage  = 50;

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = $DB->sql_like('
        CONCAT(su.firstname, \' \', su.lastname, \' \', su.email, \' \',
               pu.firstname, \' \', pu.lastname, \' \', lg.name)', ':search', false);
    $params['search'] = '%' . $DB->sql_like_escape($search) . '%';
}
if (in_array($mailfilter, ['sent', 'failed', 'skipped'], true)) {
    $where[] = 'n.mail_status = :mailstatus';
    $params['mailstatus'] = $mailfilter;
}
if (in_array($typefilter, ['suspend', 'reinstate'], true)) {
    $where[] = 'n.noticetype = :noticetype';
    $params['noticetype'] = $typefilter;
}
$whereSql = implode(' AND ', $where);

$totalCount = (int)$DB->count_records_sql(
    "SELECT COUNT(*)
       FROM {learnguard_suspension_notices} n
       JOIN {user} su ON su.id = n.userid
       JOIN {user} pu ON pu.id = n.proctorid
       JOIN {learnguard} lg ON lg.id = n.learnguard
      WHERE $whereSql", $params
);
$sentCount    = (int)$DB->count_records('learnguard_suspension_notices', ['mail_status' => 'sent']);
$failedCount  = (int)$DB->count_records('learnguard_suspension_notices', ['mail_status' => 'failed']);
$skippedCount = (int)$DB->count_records('learnguard_suspension_notices', ['mail_status' => 'skipped']);

$pages = max(1, (int)ceil($totalCount / $perPage));
$pgnum = max(0, min($pgnum, $pages - 1));

$notices = $DB->get_records_sql(
    "SELECT n.*,
            su.firstname AS s_fn, su.lastname AS s_ln, su.email AS s_email,
            pu.firstname AS p_fn, pu.lastname AS p_ln,
            lg.name AS lgname
       FROM {learnguard_suspension_notices} n
       JOIN {user} su ON su.id = n.userid
       JOIN {user} pu ON pu.id = n.proctorid
       JOIN {learnguard} lg ON lg.id = n.learnguard
      WHERE $whereSql
   ORDER BY n.timecreated DESC",
    $params, $pgnum * $perPage, $perPage
);

// Batch-resolve course module IDs for the attemptdetail.php links (one query, not N+1).
$lgIds = array_unique(array_map(fn($n) => (int)$n->learnguard, $notices));
$cmids = [];
if (!empty($lgIds)) {
    [$insql, $inparams] = $DB->get_in_or_equal($lgIds, SQL_PARAMS_NAMED);
    $cmRows = $DB->get_records_sql(
        "SELECT cm.id AS cmid, cm.instance
           FROM {course_modules} cm
           JOIN {modules} m ON m.id = cm.module AND m.name = 'learnguard'
          WHERE cm.instance $insql AND cm.deletioninprogress = 0", $inparams
    );
    foreach ($cmRows as $r) { $cmids[(int)$r->instance] = (int)$r->cmid; }
}

echo $OUTPUT->header();
echo learnguard_nav_bar(0, 'Suspension Notices Log');
?>

<div style="padding:16px;font-family:system-ui,sans-serif;max-width:1300px;margin:0 auto;">

  <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:20px;">
    <div style="background:#fff;border-radius:10px;padding:14px 20px;box-shadow:0 1px 4px rgba(0,0,0,.1);flex:1;min-width:160px;">
      <div style="font-size:12px;color:#6c757d;">Total Notices</div>
      <div style="font-size:24px;font-weight:800;"><?php echo $totalCount; ?></div>
    </div>
    <div style="background:#fff;border-radius:10px;padding:14px 20px;box-shadow:0 1px 4px rgba(0,0,0,.1);flex:1;min-width:160px;">
      <div style="font-size:12px;color:#6c757d;">✅ Sent</div>
      <div style="font-size:24px;font-weight:800;color:#28a745;"><?php echo $sentCount; ?></div>
    </div>
    <div style="background:#fff;border-radius:10px;padding:14px 20px;box-shadow:0 1px 4px rgba(0,0,0,.1);flex:1;min-width:160px;">
      <div style="font-size:12px;color:#6c757d;">❌ Failed</div>
      <div style="font-size:24px;font-weight:800;color:#dc3545;"><?php echo $failedCount; ?></div>
    </div>
    <div style="background:#fff;border-radius:10px;padding:14px 20px;box-shadow:0 1px 4px rgba(0,0,0,.1);flex:1;min-width:160px;">
      <div style="font-size:12px;color:#6c757d;">⏭ Skipped (disabled)</div>
      <div style="font-size:24px;font-weight:800;color:#6c757d;"><?php echo $skippedCount; ?></div>
    </div>
  </div>

  <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;
    background:#fff;padding:12px 16px;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,.1);">
    <input type="text" name="search" value="<?php echo s($search); ?>"
      placeholder="Search student, proctor, or assessment…"
      style="flex:1;min-width:220px;padding:7px 10px;border:1px solid #ced4da;border-radius:6px;">
    <select name="noticetype" style="padding:7px 10px;border:1px solid #ced4da;border-radius:6px;">
      <option value="">All notice types</option>
      <option value="suspend" <?php echo $typefilter === 'suspend' ? 'selected' : ''; ?>>🚫 Confirmed Suspension</option>
      <option value="reinstate" <?php echo $typefilter === 'reinstate' ? 'selected' : ''; ?>>✅ Reinstatement</option>
    </select>
    <select name="mailstatus" style="padding:7px 10px;border:1px solid #ced4da;border-radius:6px;">
      <option value="">All mail statuses</option>
      <option value="sent" <?php echo $mailfilter === 'sent' ? 'selected' : ''; ?>>✅ Sent</option>
      <option value="failed" <?php echo $mailfilter === 'failed' ? 'selected' : ''; ?>>❌ Failed</option>
      <option value="skipped" <?php echo $mailfilter === 'skipped' ? 'selected' : ''; ?>>⏭ Skipped (disabled)</option>
    </select>
    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
    <?php if ($search !== '' || $mailfilter !== '' || $typefilter !== ''): ?>
      <a href="<?php echo new moodle_url('/mod/learnguard/suspensionnotices.php'); ?>" class="btn btn-outline-secondary btn-sm">Clear</a>
    <?php endif; ?>
  </form>

  <div style="background:#fff;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,.1);overflow:hidden;">
    <div style="overflow-x:auto;">
    <table class="table table-hover mb-0" style="min-width:1000px;">
      <thead style="background:#f8f9fa;">
        <tr>
          <th>Date/Time</th>
          <th>Type</th>
          <th>Assessment</th>
          <th>Student</th>
          <th>Proctor</th>
          <th>Reason / Message</th>
          <th>Evidence</th>
          <th>Mail Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($notices)): ?>
        <tr><td colspan="9" style="text-align:center;padding:30px;color:#6c757d;">No notices logged yet.</td></tr>
        <?php else: foreach ($notices as $n): ?>
        <tr>
          <td style="white-space:nowrap;font-size:13px;"><?php echo userdate((int)$n->timecreated, '%d/%m/%y %H:%M'); ?></td>
          <td style="white-space:nowrap;">
            <?php if (($n->noticetype ?? 'suspend') === 'reinstate'): ?>
              <span class="badge" style="background:#28a745;color:#fff;">✅ Reinstate</span>
            <?php else: ?>
              <span class="badge" style="background:#dc3545;color:#fff;">🚫 Suspend</span>
            <?php endif; ?>
          </td>
          <td style="font-size:13px;"><?php echo s(format_string($n->lgname)); ?></td>
          <td style="font-size:13px;">
            <?php echo s(fullname((object)['firstname' => $n->s_fn, 'lastname' => $n->s_ln])); ?><br>
            <span style="color:#6c757d;font-size:11px;"><?php echo s($n->s_email); ?></span>
          </td>
          <td style="font-size:13px;"><?php echo s(fullname((object)['firstname' => $n->p_fn, 'lastname' => $n->p_ln])); ?></td>
          <td style="font-size:12px;max-width:260px;">
            <span title="<?php echo s($n->reason); ?>">
              <?php echo s(mb_strimwidth($n->reason, 0, 90, '…')); ?>
            </span>
          </td>
          <td style="font-size:12px;white-space:nowrap;">
            <?php if ((int)$n->snapcount > 0): ?><span title="Evidence images">📷 <?php echo (int)$n->snapcount; ?></span><?php endif; ?>
            <?php if ((int)$n->audiocount > 0): ?> <span title="Audio clips">🎤 <?php echo (int)$n->audiocount; ?></span><?php endif; ?>
            <?php if ((int)$n->snapcount === 0 && (int)$n->audiocount === 0): ?><span style="color:#adb5bd;">—</span><?php endif; ?>
          </td>
          <td>
            <?php if ($n->mail_status === 'sent'): ?>
              <span class="badge" style="background:#28a745;color:#fff;">✅ Sent</span>
            <?php elseif ($n->mail_status === 'skipped'): ?>
              <span class="badge" style="background:#6c757d;color:#fff;" title="<?php echo s($n->mail_error ?? ''); ?>">⏭ Skipped</span>
            <?php else: ?>
              <span class="badge" style="background:#dc3545;color:#fff;" title="<?php echo s($n->mail_error ?? ''); ?>">❌ Failed</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if (!empty($cmids[(int)$n->learnguard])): ?>
              <a href="<?php echo new moodle_url('/mod/learnguard/attemptdetail.php',
                  ['id' => $cmids[(int)$n->learnguard], 'attempt' => (int)$n->attemptid]); ?>"
                 class="btn btn-sm btn-outline-primary">View Attempt</a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
    </div>
  </div>

  <?php if ($pages > 1): ?>
  <div style="margin-top:16px;display:flex;gap:6px;justify-content:center;flex-wrap:wrap;">
    <?php for ($i = 0; $i < $pages; $i++): ?>
      <a href="<?php echo new moodle_url('/mod/learnguard/suspensionnotices.php',
          ['search' => $search, 'mailstatus' => $mailfilter, 'noticetype' => $typefilter, 'p' => $i]); ?>"
         class="btn btn-sm <?php echo $i === $pgnum ? 'btn-primary' : 'btn-outline-secondary'; ?>">
         <?php echo $i + 1; ?>
      </a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>

</div>

<?php
echo $OUTPUT->footer();
