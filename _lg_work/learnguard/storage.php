<?php
/**
 * Storage management — shows total disk usage for LearnGuard media, allows filtering and deletion.
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
$PAGE->set_url('/mod/learnguard/storage.php');
$PAGE->set_title('LearnGuard Storage');
$PAGE->set_heading('LearnGuard — Storage Management');
$PAGE->requires->css('/mod/learnguard/styles.css');

// Handle bulk delete.
$action = optional_param('action', '', PARAM_ALPHA);
if ($action === 'deleteall' && confirm_sesskey()) {
    $type = optional_param('type', '', PARAM_ALPHANUMEXT);
    if ($type) {
        $DB->delete_records_select('learnguard_snapshots', 'snapshot_type = :t', ['t' => $type]);
    } else {
        $DB->delete_records('learnguard_snapshots', []);
    }
    redirect(new moodle_url('/mod/learnguard/storage.php'), 'Deleted successfully.', 2);
}
if ($action === 'deleteids' && confirm_sesskey()) {
    $delIds = optional_param_array('delids', [], PARAM_INT);
    foreach ($delIds as $id) {
        $DB->delete_records('learnguard_snapshots', ['id' => (int)$id]);
    }
    redirect(new moodle_url('/mod/learnguard/storage.php'), count($delIds) . ' items deleted.', 2);
}

// Gather storage stats (count by type; estimates sizes from Moodle files table).
$stats = $DB->get_records_sql(
    "SELECT snapshot_type, COUNT(*) AS item_count
       FROM {learnguard_snapshots}
      GROUP BY snapshot_type
      ORDER BY item_count DESC"
);

// Get actual file sizes from Moodle file storage.
$fileSizes = [];
try {
    $fsRows = $DB->get_records_sql(
        "SELECT component, filearea, SUM(filesize) AS total, COUNT(*) AS cnt
           FROM {files}
          WHERE component='mod_learnguard' AND filesize > 0
          GROUP BY component, filearea");
    foreach ($fsRows as $row) {
        $fileSizes[$row->filearea] = $row->total;
    }
} catch (Exception $e) {}

$totalBytes = array_sum($fileSizes);
$grandTotal = $totalBytes;

function lg_fmtSize($bytes) {
    if ($bytes >= 1073741824) { return round($bytes / 1073741824, 1) . ' GB'; }
    if ($bytes >= 1048576)    { return round($bytes / 1048576, 1) . ' MB'; }
    if ($bytes >= 1024)       { return round($bytes / 1024, 1) . ' KB'; }
    return max(0, (int)$bytes) . ' B';
}

$typeLabels = [
    'periodic'          => '📸 Webcam Snapshot',
    'photo_id'          => '🪪 Photo ID',
    '360_video'         => '🎥 360° Video',
    'face'              => '👤 Face Photo',
    'screen_capture'    => '🖥 Screen Capture',
    'random_screenshot' => '🎲 Random Screenshot',
    'object_cell_phone' => '📱 Phone Detected',
    'background_change' => '🖼 Background Change',
    'multiple_persons'  => '👥 Multiple Persons',
    'environment_recording' => '🎥 Environment Recording',
];

$filterType = optional_param('filter', '', PARAM_ALPHANUMEXT);

// Get filtered snapshots for the grid.
$snapWhere = $filterType ? 'snapshot_type = :ft' : '1=1';
$snapParams = $filterType ? ['ft' => $filterType] : [];
$snaps = $DB->get_records_sql(
    "SELECT s.id, s.snapshot_type, s.timemodified, s.userid, u.firstname, u.lastname
       FROM {learnguard_snapshots} s
       JOIN {user} u ON u.id = s.userid
      WHERE $snapWhere
      ORDER BY s.timemodified DESC",
    $snapParams, 0, 100
);

echo $OUTPUT->header();
echo learnguard_nav_bar(0, 'Storage Management');
?>

<div style="padding:16px;font-family:system-ui,sans-serif;max-width:1200px;margin:0 auto;">

  <!-- Top nav bar -->
  <div style="display:flex;align-items:center;gap:8px;margin-bottom:20px;flex-wrap:wrap;
    padding:10px 16px;background:#fff;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,.1);">
    <a href="<?php echo new moodle_url('/admin/settings.php', ['section' => 'mod_learnguard']); ?>"
       class="btn btn-outline-secondary btn-sm">← Settings</a>
    <a href="<?php echo new moodle_url('/mod/learnguard/proctorview.php'); ?>"
       class="btn btn-outline-primary btn-sm">🛡 Live Monitor</a>
    <a href="<?php echo new moodle_url('/mod/learnguard/videos360.php'); ?>"
       class="btn btn-outline-warning btn-sm">🎥 360° Videos</a>
    <a href="<?php echo new moodle_url('/mod/learnguard/bulkassign.php'); ?>"
       class="btn btn-outline-success btn-sm">🗂 Bulk Assign</a>
    <?php if ($grandTotal > 0): ?>
    <div style="margin-left:auto;font-size:13px;color:#6c757d;">
        Moodle files: <strong><?php echo lg_fmtSize($grandTotal); ?></strong>
    </div>
    <?php endif; ?>
  </div>

  <h2 style="margin-bottom:4px;">💾 Storage Management — LearnGuard</h2>
  <p style="color:#6c757d;margin-bottom:24px;">Manage media files stored by the LearnGuard proctoring plugin.</p>

  <!-- Summary cards -->
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;margin-bottom:24px;">
    <div style="background:#0f6cbf;color:#fff;border-radius:10px;padding:16px;text-align:center;">
      <?php $totalSnaps = array_sum(array_column((array)$stats, 'item_count')); ?>
      <div style="font-size:28px;font-weight:900;"><?php echo number_format($totalSnaps); ?></div>
      <div style="font-size:12px;opacity:.85;">Total Snapshots/Videos</div>
    </div>
    <?php foreach ($stats as $s): ?>
    <div style="background:#fff;border-radius:10px;padding:14px;box-shadow:0 1px 6px rgba(0,0,0,.08);cursor:pointer;"
         onclick="location.href='?filter=<?php echo urlencode($s->snapshot_type); ?>'">
      <div style="font-size:22px;font-weight:800;color:#0f6cbf;"><?php echo number_format($s->item_count); ?></div>
      <div style="font-size:12px;color:#6c757d;margin:2px 0;"><?php echo $typeLabels[$s->snapshot_type] ?? $s->snapshot_type; ?></div>
      <div style="font-size:11px;color:#adb5bd;">items</div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Controls -->
  <div style="display:flex;gap:10px;align-items:center;margin-bottom:16px;flex-wrap:wrap;">
    <select class="form-control" style="max-width:220px;" onchange="location.href='?filter='+this.value">
      <option value="">— All types —</option>
      <?php foreach ($typeLabels as $tv => $tl): ?>
      <option value="<?php echo $tv; ?>" <?php echo $filterType === $tv ? 'selected' : ''; ?>><?php echo $tl; ?></option>
      <?php endforeach; ?>
    </select>
    <?php if ($filterType): ?>
    <a href="?" class="btn btn-sm btn-outline-secondary">✕ Clear filter</a>
    <?php endif; ?>
    <div style="margin-left:auto;display:flex;gap:8px;align-items:center;">
      <label style="font-size:12px;cursor:pointer;display:flex;align-items:center;gap:5px;">
        <input type="checkbox" id="lg-stor-sel-all" onchange="lgStorSelAll(this.checked)" style="width:14px;height:14px;"> Select All
      </label>
      <button onclick="lgStorDeleteSelected()" class="btn btn-sm btn-danger" id="lg-stor-del-btn" style="display:none;">
        🗑 Delete Selected (<span id="lg-stor-sel-count">0</span>)
      </button>
      <?php if ($filterType): ?>
      <form method="post" style="display:inline;" onsubmit="return confirm('Delete ALL <?php echo addslashes($typeLabels[$filterType] ?? $filterType); ?> items? This cannot be undone.');">
        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
        <input type="hidden" name="action" value="deleteall">
        <input type="hidden" name="type" value="<?php echo htmlspecialchars($filterType); ?>">
        <button type="submit" class="btn btn-sm btn-outline-danger">🗑 Delete ALL <?php echo $typeLabels[$filterType] ?? $filterType; ?></button>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- Grid -->
  <?php if (empty($snaps)): ?>
  <div class="alert alert-info">No items found.</div>
  <?php else: ?>
  <form id="lg-stor-form" method="post">
    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
    <input type="hidden" name="action" value="deleteids">
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;">
    <?php foreach ($snaps as $s):
        $src = (new moodle_url('/mod/learnguard/snapshot.php',['id'=>$s->id,'sesskey'=>sesskey()]))->out(false);
        $isVid = ($s->snapshot_type === '360_video' || $s->snapshot_type === 'environment_recording');
        $icon  = $typeLabels[$s->snapshot_type] ?? '📷';
        $name  = htmlspecialchars(fullname((object)['firstname'=>$s->firstname,'lastname'=>$s->lastname]));
    ?>
    <div class="lg-stor-item" style="background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 6px rgba(0,0,0,.08);position:relative;">
      <label style="position:absolute;top:6px;left:6px;z-index:2;cursor:pointer;">
        <input type="checkbox" name="delids[]" class="lg-stor-chk" value="<?php echo $s->id; ?>"
               style="width:16px;height:16px;accent-color:#dc3545;" onchange="lgStorChkChanged()">
      </label>
      <?php if ($isVid): ?>
      <video src="<?php echo $src; ?>" controls style="width:100%;height:110px;object-fit:cover;background:#111;"></video>
      <?php else: ?>
      <img src="<?php echo $src; ?>" loading="lazy" style="width:100%;height:110px;object-fit:cover;">
      <?php endif; ?>
      <div style="padding:6px 8px;">
        <div style="font-size:10px;font-weight:600;"><?php echo $icon; ?> <?php echo $name; ?></div>
        <div style="font-size:10px;color:#adb5bd;"><?php echo userdate($s->timemodified,'%d/%m/%y %H:%M'); ?></div>
        <div style="font-size:10px;color:#6c757d;"><?php echo $typeLabels[$s->snapshot_type] ?? $s->snapshot_type; ?></div>
      </div>
    </div>
    <?php endforeach; ?>
    </div>
  </form>
  <?php endif; ?>
</div>

<script>
function lgStorChkChanged() {
    var n = document.querySelectorAll('.lg-stor-chk:checked').length;
    var btn = document.getElementById('lg-stor-del-btn');
    var cnt = document.getElementById('lg-stor-sel-count');
    if (btn) { btn.style.display = n > 0 ? 'inline-block' : 'none'; }
    if (cnt) { cnt.textContent = n; }
}
function lgStorSelAll(v) {
    document.querySelectorAll('.lg-stor-chk').forEach(function(c){ c.checked = v; });
    lgStorChkChanged();
}
function lgStorDeleteSelected() {
    var n = document.querySelectorAll('.lg-stor-chk:checked').length;
    if (!n) { return; }
    if (!confirm('Delete ' + n + ' selected item(s)? This cannot be undone.')) { return; }
    document.getElementById('lg-stor-form').submit();
}
</script>

<?php echo $OUTPUT->footer(); ?>
