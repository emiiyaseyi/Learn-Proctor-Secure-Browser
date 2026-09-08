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

// Queues a background export of one type — see classes/task/build_storage_export.php.
// Replaces the web-request-driven batch download above: even a bounded 5,000-item batch
// was confirmed live to hit a hard "Gateway Timeout" from the hosting infrastructure
// itself, a ceiling that exists independent of anything this application code can raise
// (core_php_time_limit::raise() only controls PHP's own limit, not a reverse proxy's).
// A cron-run ad-hoc task has no such constraint — it can take however long it needs.
if ($action === 'queueexport' && confirm_sesskey()) {
    $type = optional_param('type', '', PARAM_ALPHANUMEXT);
    if ($type) {
        $task = new \mod_learnguard\task\build_storage_export();
        $task->set_custom_data(['type' => $type]);
        \core\task\manager::queue_adhoc_task($task);
    }
    redirect(new moodle_url('/mod/learnguard/storage.php', ['filter' => $type]),
        'Export queued — this builds in the background via cron, so it is not limited ' .
        'by how long a single page load can run. Refresh this page in a few minutes to check on it.',
        null, \core\output\notification::NOTIFY_SUCCESS);
}

// Streams an already-built export — fast, since nothing is processed at download time,
// just an existing file read straight off disk.
if ($action === 'downloadexport' && confirm_sesskey()) {
    $type = optional_param('type', '', PARAM_ALPHANUMEXT);
    $st = \mod_learnguard\storage_export::status($type);
    if ($st['status'] !== 'ready') {
        redirect(new moodle_url('/mod/learnguard/storage.php', ['filter' => $type]),
            'Export is not ready yet.', null, \core\output\notification::NOTIFY_WARNING);
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="learnguard_' . $type . '_' . date('Ymd_His') . '.zip"');
    header('Content-Length: ' . $st['size']);
    readfile($st['path']);
    die;
}

// Discards a finished/failed export so a fresh one can be queued (e.g. after deleting
// some of the underlying items, or retrying past an error).
if ($action === 'clearexport' && confirm_sesskey()) {
    $type = optional_param('type', '', PARAM_ALPHANUMEXT);
    $dir = \mod_learnguard\storage_export::export_dir();
    foreach (['.zip', '.zip.tmp', '.building', '.error'] as $suffix) {
        @unlink($dir . '/' . $type . $suffix);
    }
    redirect(new moodle_url('/mod/learnguard/storage.php', ['filter' => $type]),
        'Export cleared.', null, \core\output\notification::NOTIFY_SUCCESS);
}

// Download every item of one type — as a SEQUENCE of bounded-size ZIP batches, not one
// attempt at everything. A single request trying to add 100,000+ individual entries to
// one ZipArchive was confirmed live to fail with a blank page and zero explanation —
// ZipArchive keeps an in-memory index of every entry until close(), so entry COUNT (not
// just total byte size) is its own real limit. Capping every batch at 5,000 items
// regardless of type also naturally bounds per-request size — even Screen Capture
// (36.8GB total) works out to roughly a few hundred MB per batch, so there's no separate
// byte-size ceiling needed here the way the old single-shot version had. The client
// (lgStorDownloadAllType() / lgStorShowNextDlBatch() / lgStorDownloadOneBatch() below)
// walks through batch numbers one at a time with a visible "Batch X of Y" progress state.
// SUPERSEDED by the background export above (queueexport/downloadexport) after this
// batch approach ALSO hit a hard Gateway Timeout on batch 1 — kept only as a fallback
// for a type small enough that a single batch reliably finishes.
if ($action === 'downloadallbatch') {
    require_sesskey();
    $type     = optional_param('type', '', PARAM_ALPHANUMEXT);
    $batchnum = optional_param('batch', 0, PARAM_INT);
    $batchsize = 5000;
    if (!$type) {
        redirect(new moodle_url('/mod/learnguard/storage.php'), 'No type specified.', null,
            \core\output\notification::NOTIFY_WARNING);
    }

    try {
        if (!class_exists('ZipArchive')) {
            throw new \Exception('The PHP zip extension is not available on this server — bulk ZIP download cannot work at all until that is enabled.');
        }

        core_php_time_limit::raise(300); // one bounded batch — should never need long.
        // Best-effort only — many hosts disallow this at runtime, in which case it's a
        // silent no-op rather than an error. A genuine memory-exhaustion fatal is one of
        // the few PHP failure modes that can bypass try/catch entirely regardless, which
        // is exactly why the batch size above was cut from "everything" to 5,000 items.
        @ini_set('memory_limit', '512M');
        $rows = $DB->get_records_sql(
            "SELECT s.id, s.snapshot_type, s.snapshot_url, s.timemodified, u.firstname, u.lastname
               FROM {learnguard_snapshots} s
               JOIN {user} u ON u.id = s.userid
              WHERE s.snapshot_type = :t
           ORDER BY s.id ASC",
            ['t' => $type], $batchnum * $batchsize, $batchsize
        );
        if (empty($rows)) {
            redirect(new moodle_url('/mod/learnguard/storage.php', ['filter' => $type]),
                'Nothing left in this batch — all items of this type have already been downloaded.',
                null, \core\output\notification::NOTIFY_SUCCESS);
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'lgstorall_');
        if ($tmpPath === false) {
            throw new \Exception('Could not create a temp file on the server (check disk space / temp directory permissions).');
        }
        $zip = new ZipArchive();
        if ($zip->open($tmpPath, ZipArchive::OVERWRITE) !== true) {
            @unlink($tmpPath);
            throw new \Exception('Could not create the ZIP archive on the server.');
        }
        foreach ($rows as $r) {
            $ext = (in_array($r->snapshot_type, ['360_video'], true) || strpos($r->snapshot_type, 'screen') !== false)
                ? 'webm' : 'jpg';
            $safeName = preg_replace('/[^A-Za-z0-9_-]/', '_', $r->firstname . '_' . $r->lastname);
            $fname = $r->id . '_' . $r->snapshot_type . '_' . $safeName . '_' .
                userdate($r->timemodified, '%Y%m%d_%H%M%S') . '.' . $ext;
            $binary = base64_decode($r->snapshot_url);
            $zip->addFromString($fname, $binary);
            unset($binary);
        }
        $zip->close();

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="learnguard_' . $type . '_batch' .
            ($batchnum + 1) . '_' . date('Ymd_His') . '.zip"');
        header('Content-Length: ' . filesize($tmpPath));
        readfile($tmpPath);
        unlink($tmpPath);
        die;
    } catch (\Throwable $e) {
        // Never let this fail as a blank page again — whatever went wrong is shown
        // plainly instead of a silent dead end.
        redirect(new moodle_url('/mod/learnguard/storage.php', ['filter' => $type]),
            'Download batch ' . ($batchnum + 1) . ' failed: ' . $e->getMessage(), null,
            \core\output\notification::NOTIFY_ERROR);
    }
}

// Download a specific set of selected snapshot IDs as one ZIP (capped, matching the
// same pattern as Audio Clips' "Download Selected").
if ($action === 'downloadids' && confirm_sesskey()) {
    $ids = optional_param_array('ids', [], PARAM_INT);
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (empty($ids)) {
        redirect(new moodle_url('/mod/learnguard/storage.php'), 'No items selected to download.', null,
            \core\output\notification::NOTIFY_WARNING);
    }
    if (count($ids) > 300) {
        redirect(new moodle_url('/mod/learnguard/storage.php'), 'Please select 300 items or fewer per download.', null,
            \core\output\notification::NOTIFY_WARNING);
    }
    core_php_time_limit::raise(300);
    [$idsql, $idparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
    $rows = $DB->get_records_sql(
        "SELECT s.id, s.snapshot_type, s.snapshot_url, s.timemodified, u.firstname, u.lastname
           FROM {learnguard_snapshots} s
           JOIN {user} u ON u.id = s.userid
          WHERE s.id $idsql",
        $idparams
    );
    $tmpPath = tempnam(sys_get_temp_dir(), 'lgstorsel_');
    $zip = new ZipArchive();
    $zip->open($tmpPath, ZipArchive::OVERWRITE);
    foreach ($rows as $r) {
        $ext = (in_array($r->snapshot_type, ['360_video'], true) || strpos($r->snapshot_type, 'screen') !== false)
            ? 'webm' : 'jpg';
        $safeName = preg_replace('/[^A-Za-z0-9_-]/', '_', $r->firstname . '_' . $r->lastname);
        $fname = $r->id . '_' . $r->snapshot_type . '_' . $safeName . '_' .
            userdate($r->timemodified, '%Y%m%d_%H%M%S') . '.' . $ext;
        $binary = base64_decode($r->snapshot_url);
        $zip->addFromString($fname, $binary);
        unset($binary);
    }
    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="learnguard_selected_' . date('Ymd_His') . '.zip"');
    header('Content-Length: ' . filesize($tmpPath));
    readfile($tmpPath);
    unlink($tmpPath);
    die;
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
      <button onclick="lgStorDownloadSelected()" class="btn btn-sm btn-outline-primary" id="lg-stor-dl-btn" style="display:none;">
        ⬇ Download Selected (<span id="lg-stor-dlsel-count">0</span>)
      </button>
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

  <?php if ($filterType):
      $exportStatus = \mod_learnguard\storage_export::status($filterType);
      $typeLbl = $typeLabels[$filterType] ?? $filterType;
  ?>
  <div style="margin-bottom:16px;background:#e7f1ff;border:1px solid #b8d8f8;border-radius:8px;padding:12px 16px;">
    <div style="font-size:13px;font-weight:700;color:#0a4d8c;margin-bottom:6px;">⬇ Download ALL <?php echo $typeLbl; ?> (<?php echo number_format($totalFiltered); ?>)</div>
    <?php if ($exportStatus['status'] === 'none'): ?>
      <p style="font-size:12px;color:#0a4d8c;margin-bottom:8px;">
        Built in the background via cron — not limited by how long a page load can run,
        so this works reliably even at this size. Requires cron to be running on this site.
      </p>
      <form method="post" style="display:inline;">
        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
        <input type="hidden" name="action" value="queueexport">
        <input type="hidden" name="type" value="<?php echo htmlspecialchars($filterType); ?>">
        <button type="submit" class="btn btn-sm btn-primary">▶ Build Export</button>
      </form>
    <?php elseif ($exportStatus['status'] === 'building'): ?>
      <p style="font-size:13px;color:#0a4d8c;">⏳ Building now — refresh this page in a few minutes to check.</p>
    <?php elseif ($exportStatus['status'] === 'ready'): ?>
      <p style="font-size:13px;color:#0a4d8c;margin-bottom:8px;">
        ✅ Ready — <?php echo lg_fmtSize($exportStatus['size']); ?>, built
        <?php echo userdate($exportStatus['mtime']); ?>.
      </p>
      <a class="btn btn-sm btn-primary"
         href="<?php echo (new moodle_url('/mod/learnguard/storage.php',
             ['action' => 'downloadexport', 'sesskey' => sesskey(), 'type' => $filterType]))->out(false); ?>">
        ⬇ Download ZIP
      </a>
      <form method="post" style="display:inline;">
        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
        <input type="hidden" name="action" value="clearexport">
        <input type="hidden" name="type" value="<?php echo htmlspecialchars($filterType); ?>">
        <button type="submit" class="btn btn-sm btn-outline-secondary"
                onclick="return confirm('Discard this export? You can rebuild it later.');">✕ Discard</button>
      </form>
    <?php elseif ($exportStatus['status'] === 'error'): ?>
      <p style="font-size:13px;color:#dc3545;margin-bottom:8px;">⚠️ Export failed: <?php echo s($exportStatus['error']); ?></p>
      <form method="post" style="display:inline;">
        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
        <input type="hidden" name="action" value="queueexport">
        <input type="hidden" name="type" value="<?php echo htmlspecialchars($filterType); ?>">
        <button type="submit" class="btn btn-sm btn-primary">↻ Retry</button>
      </form>
    <?php endif; ?>
    <p style="font-size:11px;color:#6c757d;margin:8px 0 0;">
      Prefer not to wait on cron? A slower manual option (bounded batches, one click each) is
      <a href="javascript:void(0)" onclick="lgStorDownloadAllType('<?php echo htmlspecialchars($filterType); ?>', '<?php echo addslashes($typeLbl); ?>', <?php echo (int)$totalFiltered; ?>)">still available here</a> —
      note this hit a Gateway Timeout on this site even at 5,000 items/batch, so the background export above is the recommended path for a type this large.
    </p>
  </div>
  <div id="lg-stor-dlall-progress" style="display:none;margin-bottom:16px;background:#e7f1ff;border:1px solid #b8d8f8;
       border-radius:8px;padding:10px 16px;font-size:13px;color:#0a4d8c;"></div>
  <?php endif; ?>

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
    var btn   = document.getElementById('lg-stor-del-btn');
    var cnt   = document.getElementById('lg-stor-sel-count');
    var dlbtn = document.getElementById('lg-stor-dl-btn');
    var dlcnt = document.getElementById('lg-stor-dlsel-count');
    if (btn)   { btn.style.display = n > 0 ? 'inline-block' : 'none'; }
    if (cnt)   { cnt.textContent = n; }
    if (dlbtn) { dlbtn.style.display = (n > 0 && n <= 300) ? 'inline-block' : 'none'; }
    if (dlcnt) { dlcnt.textContent = n; }
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

// Downloads exactly the ticked items as one ZIP (capped at 300/request server-side —
// same limit as the Audio Clips tab's "Download Selected", chosen to stay safely within
// one request regardless of file size mix).
function lgStorDownloadSelected() {
    var ids = Array.prototype.map.call(document.querySelectorAll('.lg-stor-chk:checked'), function(c) { return c.value; });
    if (!ids.length) { return; }
    if (ids.length > 300) { alert('Please select 300 items or fewer per download.'); return; }
    var form = document.createElement('form');
    form.method = 'post';
    form.action = '<?php echo (new moodle_url('/mod/learnguard/storage.php'))->out(false); ?>';
    var fields = {sesskey: '<?php echo sesskey(); ?>', action: 'downloadids'};
    Object.keys(fields).forEach(function(k) {
        var inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = k; inp.value = fields[k];
        form.appendChild(inp);
    });
    ids.forEach(function(id) {
        var inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = 'ids[]'; inp.value = id;
        form.appendChild(inp);
    });
    document.body.appendChild(form);
    form.submit();
}

// Downloads EVERY item of the current filtered type as a SEQUENCE of bounded ZIP
// batches (5,000 items each server-side) rather than one attempt at everything —
// trying to add 100,000+ entries to a single ZipArchive was confirmed live to fail with
// a blank page and no explanation (ZipArchive holds an in-memory index of every entry
// until close(), so entry COUNT is its own limit independent of total byte size).
// A file-download response can't tell JS when it's actually finished, so this can't be
// safely auto-sequenced — each batch is a deliberate click showing "Batch X of Y",
// which also means you always know exactly how far through you are.
var lgStorDlBatchState = null;
function lgStorDownloadAllType(type, label, totalCount) {
    var batchSize   = 5000;
    var totalBatches = Math.max(1, Math.ceil(totalCount / batchSize));
    lgStorDlBatchState = {type: type, label: label, next: 0, total: totalBatches};
    lgStorShowNextDlBatch();
}
function lgStorShowNextDlBatch() {
    var s = lgStorDlBatchState;
    var box = document.getElementById('lg-stor-dlall-progress');
    if (!s || !box) { return; }
    box.style.display = 'block';
    if (s.next >= s.total) {
        box.innerHTML = '✅ All ' + s.total + ' batch(es) of ' + s.label + ' downloaded. Check your Downloads folder for all the files.';
        lgStorDlBatchState = null;
        return;
    }
    box.innerHTML = 'Batch ' + (s.next + 1) + ' of ' + s.total + ' for ' + s.label + ' — ' +
        '<button type="button" class="btn btn-sm btn-primary" onclick="lgStorDownloadOneBatch()">⬇ Download Batch ' + (s.next + 1) + '</button> ' +
        '<span style="font-size:11px;color:#0a4d8c;">Wait for it to finish saving, then click again for the next batch.</span>';
}
function lgStorDownloadOneBatch() {
    var s = lgStorDlBatchState;
    if (!s) { return; }
    window.location.href = '<?php echo (new moodle_url('/mod/learnguard/storage.php',
        ['action' => 'downloadallbatch', 'sesskey' => sesskey()]))->out(false); ?>' +
        '&type=' + encodeURIComponent(s.type) + '&batch=' + s.next;
    s.next++;
    setTimeout(lgStorShowNextDlBatch, 800); // give the download a moment to actually start
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
