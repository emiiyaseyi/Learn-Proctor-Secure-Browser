<?php
/**
 * Storage Management — shows disk usage by type, allows bulk/individual delete.
 *
 * @package    quizaccess_learnshield
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url(new moodle_url('/mod/quiz/accessrule/learnshield/storage.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Learn Shield — Storage Management');
$PAGE->set_heading('Learn Shield — Storage Management');

// ── Handle delete actions ────────────────────────────────────────────────────
$action = optional_param('action', '', PARAM_ALPHA);
$deltype = optional_param('type', '', PARAM_ALPHANUMEXT);
$delids  = optional_param_array('delids', [], PARAM_INT);

if (in_array($action, ['deletetype', 'deleteselected']) && confirm_sesskey()) {
    if ($action === 'deletetype' && !empty($deltype)) {
        if ($deltype === 'webcam') {
            $DB->execute("UPDATE {quizaccess_learnshield_logs} SET webcampicture='' WHERE (awsflag=0 OR awsflag IS NULL)");
        } elseif ($deltype === 'screen') {
            $DB->execute("UPDATE {quizaccess_learnshield_logs} SET webcampicture='' WHERE awsflag=4");
        } elseif ($deltype === 'photoid') {
            $DB->execute("UPDATE {quizaccess_learnshield_logs} SET webcampicture='' WHERE awsflag=3");
        } elseif ($deltype === 'env') {
            $DB->execute("UPDATE {quizaccess_learnshield_logs} SET webcampicture='' WHERE awsflag=2");
        } elseif ($deltype === 'audio') {
            try { $DB->execute("DELETE FROM {quizaccess_learnshield_audio_clips} WHERE 1=1"); } catch (Exception $e) {}
        } elseif ($deltype === 'old_events') {
            $cutoff = time() - (180 * 86400);
            $DB->execute("DELETE FROM {quizaccess_learnshield_events} WHERE timemodified < :c", ['c' => $cutoff]);
        }
        redirect(new moodle_url('/mod/quiz/accessrule/learnshield/storage.php'),
            'Deleted ' . $deltype . ' records.', 2);
    }
    if ($action === 'deleteselected' && !empty($delids)) {
        list($insql, $inp) = $DB->get_in_or_equal($delids, SQL_PARAMS_NAMED);
        $DB->execute("UPDATE {quizaccess_learnshield_logs} SET webcampicture='' WHERE id $insql", $inp);
        redirect(new moodle_url('/mod/quiz/accessrule/learnshield/storage.php'),
            count($delids) . ' snapshot(s) cleared.', 2);
    }
}

// ── Storage stats ─────────────────────────────────────────────────────────────
function ls_fmt_size($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . ' GB';
    if ($bytes >= 1048576)    return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)       return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

// Count records by type.
$webcamCount  = (int)$DB->count_records_select('quizaccess_learnshield_logs',
    "(awsflag=0 OR awsflag IS NULL) AND webcampicture != ''");
$screenCount  = (int)$DB->count_records_select('quizaccess_learnshield_logs',
    "awsflag=4 AND webcampicture != ''");
$photoidCount = (int)$DB->count_records_select('quizaccess_learnshield_logs',
    "awsflag=3 AND webcampicture != ''");
$envCount     = (int)$DB->count_records_select('quizaccess_learnshield_logs',
    "awsflag=2 AND webcampicture != ''");
$audioCount   = 0;
try { $audioCount = (int)$DB->count_records('quizaccess_learnshield_audio_clips'); } catch (Exception $e) {}
$eventCount   = 0;
try {
    $eventCount = (int)$DB->count_records_select('quizaccess_learnshield_events',
        'timemodified < :c', ['c' => time() - 180 * 86400]);
} catch (Exception $e) {}

// Estimate storage from webcampicture URL count × avg snapshot size (KB).
$avgSnapKB = 50; // ~50 KB per snapshot (JPEG at 0.8 quality, 230px wide)
$estimatedMB = round(($webcamCount + $screenCount + $photoidCount + $envCount) * $avgSnapKB / 1024, 1);

$summaryCards = [
    ['📷 Webcam Snapshots', $webcamCount, 'webcam', '#0f6cbf'],
    ['🖥 Screen Captures', $screenCount, 'screen', '#6610f2'],
    ['🪪 Photo ID Captures', $photoidCount, 'photoid', '#20c997'],
    ['🎥 360° Recordings', $envCount, 'env', '#fd7e14'],
    ['🎤 Audio Clips', $audioCount, 'audio', '#e83e8c'],
    ['📋 Events > 180 days', $eventCount, 'old_events', '#6c757d'],
];

// Recent snapshots for preview grid.
$filter  = optional_param('filter', 'all', PARAM_ALPHANUMEXT);
$pgnum   = optional_param('p', 0, PARAM_INT);
$perPage = 48;

$where = "webcampicture != ''";
if ($filter === 'webcam')  { $where .= " AND (awsflag=0 OR awsflag IS NULL)"; }
elseif ($filter === 'screen')  { $where .= " AND awsflag=4"; }
elseif ($filter === 'photoid') { $where .= " AND awsflag=3"; }
elseif ($filter === 'env')     { $where .= " AND awsflag=2"; }

$totalFiltered = (int)$DB->count_records_select('quizaccess_learnshield_logs', $where);
$pages = max(1, ceil($totalFiltered / $perPage));

$snaps = $DB->get_records_sql(
    "SELECT l.id, l.awsflag, l.timemodified, l.webcampicture, l.courseid,
            u.firstname, u.lastname
       FROM {quizaccess_learnshield_logs} l
       JOIN {user} u ON u.id = l.userid
      WHERE $where
      ORDER BY l.timemodified DESC",
    [], $pgnum * $perPage, $perPage
);

echo $OUTPUT->header();
?>

<div style="max-width:1200px;margin:0 auto;font-family:system-ui,sans-serif;padding:16px;">

    <!-- Top nav bar -->
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:20px;flex-wrap:wrap;
        padding:10px 16px;background:#fff;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,.1);">
        <a href="<?php echo new moodle_url('/admin/settings.php', ['section' => 'quizaccess_learnshield']); ?>"
           class="btn btn-outline-secondary btn-sm">← Settings</a>
        <a href="<?php echo new moodle_url('/mod/quiz/accessrule/learnshield/proctorview.php'); ?>"
           class="btn btn-outline-primary btn-sm">🛡 Live Monitor</a>
        <a href="<?php echo new moodle_url('/mod/quiz/accessrule/learnshield/videos360.php'); ?>"
           class="btn btn-outline-warning btn-sm">🎥 360° Videos</a>
        <a href="<?php echo new moodle_url('/mod/quiz/accessrule/learnshield/bulkassign.php'); ?>"
           class="btn btn-outline-success btn-sm">🗂 Bulk Assign</a>
        <div style="margin-left:auto;font-size:13px;color:#6c757d;">
            Estimated storage: <strong>~<?php echo $estimatedMB; ?> MB</strong>
        </div>
    </div>

    <h2 style="margin:0 0 4px;font-size:20px;">🗄 Storage Management</h2>
    <p style="color:#6c757d;font-size:13px;margin-bottom:20px;">
        Manage media stored by Learn Shield. Click "Delete All" on a type to bulk-clear it,
        or select individual items below.
    </p>

    <!-- Summary cards -->
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(175px,1fr));gap:10px;margin-bottom:24px;">
        <?php foreach ($summaryCards as [$label, $count, $type, $colour]): ?>
        <div style="background:#fff;border-radius:10px;padding:14px;
            border-left:4px solid <?php echo $colour; ?>;box-shadow:0 1px 6px rgba(0,0,0,.08);">
            <div style="font-size:22px;font-weight:800;color:<?php echo $colour; ?>;">
                <?php echo number_format($count); ?>
            </div>
            <div style="font-size:12px;color:#495057;margin-top:2px;"><?php echo $label; ?></div>
            <?php if ($count > 0): ?>
            <form method="post" action="" style="margin-top:8px;">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <input type="hidden" name="action" value="deletetype">
                <input type="hidden" name="type" value="<?php echo $type; ?>">
                <button type="submit" class="btn btn-outline-danger btn-sm" style="font-size:11px;padding:2px 8px;"
                    onclick="return confirm('Delete ALL <?php echo htmlspecialchars($label); ?>? This cannot be undone.')">
                    🗑 Delete All
                </button>
            </form>
            <?php else: ?>
            <span style="font-size:11px;color:#adb5bd;margin-top:8px;display:block;">No records</span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Filter tabs for snapshot preview -->
    <?php if ($totalFiltered > 0 || $filter !== 'all'): ?>
    <div style="display:flex;gap:6px;margin-bottom:14px;flex-wrap:wrap;align-items:center;">
        <strong style="font-size:13px;">Filter:</strong>
        <?php foreach (['all' => 'All', 'webcam' => '📷 Webcam', 'screen' => '🖥 Screen', 'photoid' => '🪪 Photo ID', 'env' => '🎥 360°'] as $f => $l): ?>
        <a href="<?php echo new moodle_url('/mod/quiz/accessrule/learnshield/storage.php', ['filter' => $f]); ?>"
           class="btn btn-sm <?php echo $filter === $f ? 'btn-primary' : 'btn-outline-secondary'; ?>">
            <?php echo $l; ?>
        </a>
        <?php endforeach; ?>
        <span style="margin-left:auto;font-size:12px;color:#6c757d;">
            Showing <?php echo number_format($totalFiltered); ?> item(s)
        </span>
    </div>

    <?php if (!empty($snaps)): ?>
    <form method="post" action="" id="ls-stor-form">
        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
        <input type="hidden" name="action" value="deleteselected">

        <div style="display:flex;gap:8px;align-items:center;margin-bottom:10px;flex-wrap:wrap;">
            <button type="button" class="btn btn-sm btn-outline-secondary"
                onclick="document.querySelectorAll('.ls-sc').forEach(c=>c.checked=true);lsChk()">
                ☑ Select All
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary"
                onclick="document.querySelectorAll('.ls-sc').forEach(c=>c.checked=false);lsChk()">
                ☐ Deselect
            </button>
            <button type="submit" id="ls-del-btn" class="btn btn-sm btn-danger" style="display:none;"
                onclick="return confirm('Delete selected items?')">
                🗑 Delete Selected (<span id="ls-sel-n">0</span>)
            </button>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(145px,1fr));gap:8px;margin-bottom:14px;">
        <?php foreach ($snaps as $snap):
            $src = (new moodle_url('/mod/quiz/accessrule/learnshield/snapshot.php',
                ['id' => $snap->id, 'sesskey' => sesskey()]))->out(false);
            $typeIcon = match((int)$snap->awsflag) {
                4 => '🖥', 3 => '🪪', 2 => '🎥', default => '📷'
            };
            $isVid = ((int)$snap->awsflag === 2);
        ?>
        <div style="background:#fff;border-radius:6px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.1);position:relative;">
            <input type="checkbox" name="delids[]" value="<?php echo $snap->id; ?>"
                class="ls-sc" onchange="lsChk()"
                style="position:absolute;top:5px;left:5px;z-index:2;width:15px;height:15px;accent-color:#dc3545;">
            <?php if ($isVid): ?>
            <video src="<?php echo $src; ?>" style="width:100%;height:95px;object-fit:cover;background:#111;" controls></video>
            <?php else: ?>
            <a href="<?php echo $src; ?>" target="_blank">
                <img src="<?php echo $src; ?>" loading="lazy"
                    style="width:100%;height:95px;object-fit:cover;display:block;">
            </a>
            <?php endif; ?>
            <div style="padding:4px 6px;font-size:10px;color:#6c757d;">
                <?php echo $typeIcon; ?> <?php echo htmlspecialchars(fullname((object)['firstname' => $snap->firstname, 'lastname' => $snap->lastname])); ?>
                <br><?php echo userdate($snap->timemodified, '%d/%m %H:%M'); ?>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </form>

    <?php if ($pages > 1): ?>
    <div style="display:flex;gap:5px;justify-content:center;flex-wrap:wrap;margin-top:8px;">
        <?php for ($i = 0; $i < $pages; $i++): ?>
        <a href="<?php echo new moodle_url('/mod/quiz/accessrule/learnshield/storage.php', ['filter' => $filter, 'p' => $i]); ?>"
           style="padding:4px 10px;border-radius:5px;font-size:12px;font-weight:600;text-decoration:none;
           background:<?php echo $i === $pgnum ? '#0f6cbf' : '#fff'; ?>;
           color:<?php echo $i === $pgnum ? '#fff' : '#495057'; ?>;border:1px solid #dee2e6;">
            <?php echo $i + 1; ?>
        </a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
    <?php else: ?>
    <div class="alert alert-info">No items found for this filter.</div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<script>
function lsChk() {
    var n = document.querySelectorAll('.ls-sc:checked').length;
    var b = document.getElementById('ls-del-btn');
    var c = document.getElementById('ls-sel-n');
    if (b) b.style.display = n > 0 ? 'inline-block' : 'none';
    if (c) c.textContent = n;
}
</script>

<?php echo $OUTPUT->footer(); ?>
