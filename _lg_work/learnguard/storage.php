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
if ($action === 'deleteids' && confirm_sesskey()) {
    $delIds = optional_param_array('delids', [], PARAM_INT);
    foreach ($delIds as $id) {
        $DB->delete_records('learnguard_snapshots', ['id' => (int)$id]);
    }
    redirect(new moodle_url('/mod/learnguard/storage.php'), count($delIds) . ' items deleted.', 2);
}

// Chunked "Delete ALL [type]" — a live storage crisis is exactly when this table is at
// its biggest, so a single DELETE ... WHERE snapshot_type = X (even with the new
// type_time index) can still run long enough to hit a web server/proxy request timeout
// on a truly huge type. Deletes one bounded batch per call instead; the client
// (lgStorDeleteAllChunked() below) calls this repeatedly with a live progress bar until
// nothing is left, the same pattern already used for Assessment Manager's chunked saves.
if ($action === 'deletebatch' && confirm_sesskey()) {
    $type      = optional_param('type', '', PARAM_ALPHANUMEXT);
    $batchsize = 2000;
    $where     = $type ? 'snapshot_type = :t' : '1=1';
    $params    = $type ? ['t' => $type] : [];
    $idRows = $DB->get_records_select('learnguard_snapshots', $where, $params, '', 'id', 0, $batchsize);
    $ids    = array_keys($idRows);
    if (!empty($ids)) {
        [$idsql, $idparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'delid');
        $DB->delete_records_select('learnguard_snapshots', "id $idsql", $idparams);
    }
    $remaining = $DB->count_records_select('learnguard_snapshots', $where, $params);
    header('Content-Type: application/json');
    echo json_encode(['deleted' => count($ids), 'remaining' => $remaining]);
    exit;
}

// SIZE STATS AJAX — returns per-type byte totals as JSON (called after page loads).
// Computing SUM(LENGTH(snapshot_url)) on the full table is slow when there are
// many large base64 blobs; returning it via a deferred AJAX call keeps the page
// snappy and lets counts appear immediately.
if ($action === 'getstats' && confirm_sesskey()) {
    // CRITICAL FIX: the previous 200-row sample used "ORDER BY id DESC" before applying
    // the LIMIT. With no index covering (snapshot_type, id), the database cannot use the
    // new type_time index to satisfy that ordering — it has to gather EVERY row matching
    // the type first and sort the whole result by id before it can hand back the top 200.
    // For a type with 500,000+ rows, that is an expensive sort running on every single
    // page load (this endpoint fires unconditionally, on every visit, for every type,
    // regardless of which filter is active) — confirmed live: this took the database down
    // hard enough to stall the ENTIRE LMS, not just this page, right after the very
    // outage this page exists to help recover from. Dropping ORDER BY entirely fixes it:
    // an unordered LIMIT 200 lets the engine return the first 200 matching rows it finds
    // via the index and stop, with no sort step at all. Which specific 200 rows come back
    // is irrelevant here — this is only ever used to average an estimate, not to look at
    // particular items.
    $out = [];
    $typeCounts = $DB->get_records_sql(
        "SELECT snapshot_type, COUNT(*) AS cnt FROM {learnguard_snapshots} GROUP BY snapshot_type"
    );
    foreach ($typeCounts as $tc) {
        $stype = $tc->snapshot_type;
        $cnt   = (int)$tc->cnt;
        if ($cnt <= 500) {
            $total = (int)$DB->get_field_sql(
                "SELECT SUM(LENGTH(snapshot_url)) FROM {learnguard_snapshots} WHERE snapshot_type = :t",
                ['t' => $stype]
            );
            $out[$stype] = ['bytes' => $total, 'estimated' => false];
        } else {
            $sampleRows = $DB->get_records_sql(
                "SELECT id, LENGTH(snapshot_url) AS len FROM {learnguard_snapshots}
                  WHERE snapshot_type = :t",
                ['t' => $stype], 0, 200
            );
            $sizes = array_column($sampleRows, 'len');
            $avg   = !empty($sizes) ? array_sum($sizes) / count($sizes) : 0;
            $out[$stype] = ['bytes' => (int)round($avg * $cnt), 'estimated' => true];
        }
    }
    header('Content-Type: application/json');
    echo json_encode($out);
    exit;
}

// Gather storage stats: counts only (fast — COUNT with GROUP BY uses index).
// Byte-size totals are fetched asynchronously via ?action=getstats after page load.
$stats = $DB->get_records_sql(
    "SELECT snapshot_type, COUNT(*) AS item_count
       FROM {learnguard_snapshots}
      GROUP BY snapshot_type
      ORDER BY item_count DESC"
);

$grandTotal = 0; // populated by AJAX after page load

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
$pgnum      = optional_param('p', 0, PARAM_INT);
$perPage    = 100;

// Get filtered snapshots for the grid.
$snapWhere  = $filterType ? 'snapshot_type = :ft' : '1=1';
$snapParams = $filterType ? ['ft' => $filterType] : [];

$totalFiltered = (int)$DB->count_records_select('learnguard_snapshots', $snapWhere, $snapParams);
$pages         = max(1, (int)ceil($totalFiltered / $perPage));
$pgnum         = max(0, min($pgnum, $pages - 1));

$snaps = $DB->get_records_sql(
    "SELECT s.id, s.snapshot_type, s.timemodified, s.userid, u.firstname, u.lastname
       FROM {learnguard_snapshots} s
       JOIN {user} u ON u.id = s.userid
      WHERE $snapWhere
      ORDER BY s.timemodified DESC",
    $snapParams, $pgnum * $perPage, $perPage
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
    <a href="<?php echo new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'media']); ?>"
       class="btn btn-outline-primary btn-sm">🖼 Media Gallery (filter by date, download, delete)</a>
    <a href="<?php echo new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'audio']); ?>"
       class="btn btn-outline-primary btn-sm">🎤 Audio Clips (filter by date, download, delete)</a>
    <a href="<?php echo new moodle_url('/mod/learnguard/bulkassign.php'); ?>"
       class="btn btn-outline-success btn-sm">🗂 Bulk Assign</a>
    <a href="<?php echo new moodle_url('/mod/learnguard/orphan_data_report.php'); ?>"
       class="btn btn-outline-secondary btn-sm">🔍 Orphaned Data Report</a>
    <a href="<?php echo new moodle_url('/mod/learnguard/suspensionnotices.php'); ?>"
       class="btn btn-outline-danger btn-sm">🚫 Suspension Notices Log</a>
    <div style="margin-left:auto;font-size:13px;color:#6c757d;">
        Total storage: <strong id="lg-stor-grand-total">calculating…</strong>
    </div>
  </div>

  <h2 style="margin-bottom:4px;">💾 Storage Management — LearnGuard</h2>
  <p style="color:#6c757d;margin-bottom:24px;">Manage media files stored by the LearnGuard proctoring plugin.</p>

  <!-- Summary cards -->
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;margin-bottom:24px;">
    <div style="background:#0f6cbf;color:#fff;border-radius:10px;padding:16px;text-align:center;">
      <?php $totalSnaps = array_sum(array_column((array)$stats, 'item_count')); ?>
      <div style="font-size:28px;font-weight:900;"><?php echo number_format($totalSnaps); ?></div>
      <div style="font-size:12px;opacity:.85;">Total Snapshots/Videos</div>
      <div style="font-size:12px;opacity:.85;margin-top:4px;" id="lg-stor-total-size">calculating…</div>
    </div>
    <?php foreach ($stats as $s): ?>
    <div style="background:#fff;border-radius:10px;padding:14px;box-shadow:0 1px 6px rgba(0,0,0,.08);cursor:pointer;"
         onclick="location.href='?filter=<?php echo urlencode($s->snapshot_type); ?>'">
      <div style="font-size:22px;font-weight:800;color:#0f6cbf;"><?php echo number_format($s->item_count); ?></div>
      <div style="font-size:12px;color:#6c757d;margin:2px 0;"><?php echo $typeLabels[$s->snapshot_type] ?? $s->snapshot_type; ?></div>
      <div style="font-size:11px;color:#adb5bd;"
           id="lg-stor-size-<?php echo htmlspecialchars($s->snapshot_type); ?>">items · …</div>
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
    <?php if ($totalFiltered > 0): ?>
    <span style="font-size:12px;color:#6c757d;">
      Showing <?php echo number_format($pgnum * $perPage + 1); ?>–<?php echo number_format(min($totalFiltered, ($pgnum + 1) * $perPage)); ?>
      of <?php echo number_format($totalFiltered); ?> item(s)
    </span>
    <?php endif; ?>
    <div style="margin-left:auto;display:flex;gap:8px;align-items:center;">
      <label style="font-size:12px;cursor:pointer;display:flex;align-items:center;gap:5px;">
        <input type="checkbox" id="lg-stor-sel-all" onchange="lgStorSelAll(this.checked)" style="width:14px;height:14px;"> Select All (this page)
      </label>
      <button onclick="lgStorDeleteSelected()" class="btn btn-sm btn-danger" id="lg-stor-del-btn" style="display:none;">
        🗑 Delete Selected (<span id="lg-stor-sel-count">0</span>)
      </button>
      <?php if ($filterType): ?>
      <button type="button" class="btn btn-sm btn-outline-danger" id="lg-stor-delall-btn"
              onclick="lgStorDeleteAllChunked('<?php echo htmlspecialchars($filterType); ?>', '<?php echo addslashes($typeLabels[$filterType] ?? $filterType); ?>')">
        🗑 Delete ALL <?php echo $typeLabels[$filterType] ?? $filterType; ?> (<?php echo number_format($totalFiltered); ?>)
      </button>
      <?php endif; ?>
    </div>
  </div>
  <div id="lg-stor-delall-progress" style="display:none;margin-bottom:16px;background:#fff3cd;border:1px solid #ffc107;
       border-radius:8px;padding:10px 16px;font-size:13px;color:#856404;"></div>

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

  <?php if ($pages > 1): ?>
  <div style="display:flex;gap:5px;justify-content:center;flex-wrap:wrap;margin-top:16px;">
    <?php for ($i = 0; $i < $pages; $i++): ?>
    <a href="<?php echo new moodle_url('/mod/learnguard/storage.php', ['filter' => $filterType, 'p' => $i]); ?>"
       style="padding:4px 10px;border-radius:5px;font-size:12px;font-weight:600;text-decoration:none;
       background:<?php echo $i === $pgnum ? '#0f6cbf' : '#fff'; ?>;
       color:<?php echo $i === $pgnum ? '#fff' : '#495057'; ?>;border:1px solid #dee2e6;">
        <?php echo $i + 1; ?>
    </a>
    <?php endfor; ?>
  </div>
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

// Deletes an entire type in bounded batches (server deletes up to 2000 rows per call)
// instead of one single DELETE for the whole type — a live storage crisis is exactly
// when a type can be large enough that even an indexed single-request DELETE risks
// hitting a web server/proxy timeout. Shows live progress; reloads the page once done.
function lgStorDeleteAllChunked(type, label) {
    if (!confirm('Delete ALL ' + label + ' items? This cannot be undone.')) { return; }
    var btn = document.getElementById('lg-stor-delall-btn');
    var box = document.getElementById('lg-stor-delall-progress');
    if (btn) { btn.disabled = true; }
    if (box) { box.style.display = 'block'; box.textContent = 'Deleting ' + label + '… starting.'; }
    var totalDeleted = 0;
    var url = '<?php echo (new moodle_url('/mod/learnguard/storage.php', ['action' => 'deletebatch', 'sesskey' => sesskey()]))->out(false); ?>' + '&type=' + encodeURIComponent(type);
    function nextBatch() {
        fetch(url, {method: 'POST'}).then(function(r) { return r.json(); }).then(function(data) {
            totalDeleted += data.deleted || 0;
            if (box) {
                box.textContent = 'Deleting ' + label + '… ' + totalDeleted.toLocaleString() +
                    ' deleted so far, ' + (data.remaining || 0).toLocaleString() + ' remaining.';
            }
            if ((data.remaining || 0) > 0 && (data.deleted || 0) > 0) {
                nextBatch();
            } else {
                if (box) { box.textContent = '✅ Done — ' + totalDeleted.toLocaleString() + ' ' + label + ' item(s) deleted. Reloading…'; }
                setTimeout(function() { window.location.href = '<?php echo new moodle_url('/mod/learnguard/storage.php'); ?>'; }, 1200);
            }
        }).catch(function() {
            if (box) { box.textContent = '⚠️ A batch failed (network/server hiccup) — retrying…'; }
            setTimeout(nextBatch, 3000);
        });
    }
    nextBatch();
}
// Lazy-load storage sizes so the page renders fast (COUNT is instant; SUM(LENGTH) is slow).
function lgFmtSize(b) {
    if (b >= 1073741824) return (b / 1073741824).toFixed(1) + ' GB';
    if (b >= 1048576)    return (b / 1048576).toFixed(1) + ' MB';
    if (b >= 1024)       return (b / 1024).toFixed(1) + ' KB';
    return Math.max(0, Math.round(b)) + ' B';
}
(function() {
    var url = '<?php echo (new moodle_url('/mod/learnguard/storage.php', ['action' => 'getstats', 'sesskey' => sesskey()]))->out(false); ?>';
    fetch(url).then(function(r){ return r.json(); }).then(function(data) {
        var grand = 0;
        var anyEstimated = false;
        Object.keys(data).forEach(function(type) {
            var entry = data[type] || {bytes: 0, estimated: false};
            var bytes = entry.bytes || 0;
            grand += bytes;
            if (entry.estimated) { anyEstimated = true; }
            var el = document.getElementById('lg-stor-size-' + type);
            if (el) el.textContent = 'items · ' + (entry.estimated ? '≈' : '') + lgFmtSize(bytes);
        });
        var tot = document.getElementById('lg-stor-total-size');
        if (tot) tot.textContent = (anyEstimated ? '≈ ' : '') + lgFmtSize(grand);
        var grand2 = document.getElementById('lg-stor-grand-total');
        if (grand2) grand2.textContent = (anyEstimated ? '≈ ' : '') + lgFmtSize(grand);
    }).catch(function() {
        var tot = document.getElementById('lg-stor-total-size');
        if (tot) tot.textContent = '(size unavailable)';
        var grand2 = document.getElementById('lg-stor-grand-total');
        if (grand2) grand2.textContent = '(unavailable)';
    });
})();
</script>

<?php echo $OUTPUT->footer(); ?>
