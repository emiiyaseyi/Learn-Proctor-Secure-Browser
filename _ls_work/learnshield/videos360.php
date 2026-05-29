<?php
/**
 * 360° Precheck Video Viewer — lists and plays all environment recordings.
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

$PAGE->set_url(new moodle_url('/mod/quiz/accessrule/learnshield/videos360.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Learn Shield — 360° Precheck Videos');
$PAGE->set_heading('Learn Shield — 360° Precheck Videos');

// Handle delete.
$action = optional_param('action', '', PARAM_ALPHA);
$delids = optional_param_array('delids', [], PARAM_INT);
if ($action === 'delete' && !empty($delids) && confirm_sesskey()) {
    list($insql, $inp) = $DB->get_in_or_equal($delids, SQL_PARAMS_NAMED);
    $DB->execute("UPDATE {quizaccess_learnshield_logs} SET webcampicture='' WHERE id $insql", $inp);
    redirect(new moodle_url('/mod/quiz/accessrule/learnshield/videos360.php'),
        count($delids) . ' video(s) removed.', 2);
}

// Filters.
$search = optional_param('search', '', PARAM_TEXT);
$pgnum  = optional_param('p', 0, PARAM_INT);
$perPg  = 24;

$where  = "l.awsflag = 2 AND l.webcampicture != ''";
$params = [];
if (!empty($search)) {
    $where .= " AND (" . $DB->sql_like('u.firstname', ':s1', false) .
              " OR "  . $DB->sql_like('u.lastname',  ':s2', false) .
              " OR "  . $DB->sql_like('u.email',     ':s3', false) . ")";
    $params['s1'] = '%' . $DB->sql_like_escape($search) . '%';
    $params['s2'] = '%' . $DB->sql_like_escape($search) . '%';
    $params['s3'] = '%' . $DB->sql_like_escape($search) . '%';
}

$total = (int)$DB->count_records_sql(
    "SELECT COUNT(*) FROM {quizaccess_learnshield_logs} l JOIN {user} u ON u.id=l.userid WHERE $where",
    $params);
$pages = max(1, ceil($total / $perPg));

$videos = $DB->get_records_sql(
    "SELECT l.id, l.userid, l.quizid, l.courseid, l.timemodified, l.webcampicture,
            u.firstname, u.lastname, u.email,
            c.fullname AS coursename,
            q.name AS quizname
       FROM {quizaccess_learnshield_logs} l
       JOIN {user}           u  ON u.id  = l.userid
  LEFT JOIN {course_modules} cm ON cm.id = l.quizid
  LEFT JOIN {quiz}           q  ON q.id  = cm.instance
  LEFT JOIN {course}         c  ON c.id  = l.courseid
      WHERE $where
      ORDER BY l.timemodified DESC",
    $params, $pgnum * $perPg, $perPg
);

echo $OUTPUT->header();
?>

<div style="max-width:1200px;margin:0 auto;font-family:system-ui,sans-serif;padding:16px;">

    <!-- Top nav -->
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:20px;flex-wrap:wrap;
        padding:10px 16px;background:#fff;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,.1);">
        <a href="<?php echo new moodle_url('/admin/settings.php', ['section' => 'quizaccess_learnshield']); ?>"
           class="btn btn-outline-secondary btn-sm">← Settings</a>
        <a href="<?php echo new moodle_url('/mod/quiz/accessrule/learnshield/proctorview.php'); ?>"
           class="btn btn-outline-primary btn-sm">🛡 Live Monitor</a>
        <a href="<?php echo new moodle_url('/mod/quiz/accessrule/learnshield/storage.php'); ?>"
           class="btn btn-outline-secondary btn-sm">🗄 Storage</a>
    </div>

    <h2 style="margin:0 0 4px;font-size:20px;">🎥 360° Precheck Environment Videos</h2>
    <p style="color:#6c757d;font-size:13px;margin-bottom:20px;">
        All environment recordings captured by learners during the precheck process.
        <?php echo number_format($total); ?> video<?php echo $total !== 1 ? 's' : ''; ?> found.
    </p>

    <!-- Search + controls -->
    <div style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap;align-items:center;">
        <form method="get" action="" style="display:flex;gap:6px;flex:1;max-width:360px;">
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                placeholder="Search by learner name or email…"
                class="form-control form-control-sm" style="flex:1;">
            <button type="submit" class="btn btn-sm btn-primary">Search</button>
            <?php if ($search): ?>
            <a href="<?php echo new moodle_url('/mod/quiz/accessrule/learnshield/videos360.php'); ?>"
               class="btn btn-sm btn-outline-secondary">✕</a>
            <?php endif; ?>
        </form>
        <?php if (!empty($videos)): ?>
        <div style="margin-left:auto;display:flex;gap:6px;">
            <button type="button" class="btn btn-sm btn-outline-secondary"
                onclick="document.querySelectorAll('.ls360-chk').forEach(c=>c.checked=true);ls360Chk()">
                ☑ Select All
            </button>
            <button type="button" id="ls360-del-btn" class="btn btn-sm btn-danger" style="display:none;"
                onclick="ls360DelSelected()">
                🗑 Delete Selected (<span id="ls360-n">0</span>)
            </button>
        </div>
        <?php endif; ?>
    </div>

    <?php if (empty($videos)): ?>
    <div class="alert alert-info">
        No 360° environment recordings found.
        <?php if ($search): ?>
        <a href="<?php echo new moodle_url('/mod/quiz/accessrule/learnshield/videos360.php'); ?>">Clear search</a>
        <?php else: ?>
        Recordings are made during the precheck wizard environment recording step.
        <?php endif; ?>
    </div>
    <?php else: ?>

    <form method="post" action="" id="ls360-form">
        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
        <input type="hidden" name="action" value="delete">

        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px;margin-bottom:16px;">
        <?php foreach ($videos as $vid):
            $src = (new moodle_url('/mod/quiz/accessrule/learnshield/snapshot.php',
                ['id' => $vid->id, 'sesskey' => sesskey()]))->out(false);
            $learnerName = fullname((object)['firstname' => $vid->firstname, 'lastname' => $vid->lastname]);
        ?>
        <div style="background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.1);">
            <div style="position:relative;background:#111;">
                <input type="checkbox" name="delids[]" value="<?php echo $vid->id; ?>"
                    class="ls360-chk" onchange="ls360Chk()"
                    style="position:absolute;top:8px;left:8px;z-index:2;width:16px;height:16px;accent-color:#dc3545;">
                <video src="<?php echo $src; ?>" controls preload="metadata"
                    style="width:100%;height:180px;object-fit:cover;display:block;background:#111;">
                    Your browser does not support video playback.
                </video>
            </div>
            <div style="padding:10px 12px;">
                <div style="font-weight:700;font-size:14px;margin-bottom:2px;">
                    <?php echo htmlspecialchars($learnerName); ?>
                </div>
                <div style="font-size:12px;color:#6c757d;"><?php echo htmlspecialchars($vid->email); ?></div>
                <div style="font-size:12px;color:#6c757d;margin-top:4px;">
                    📅 <?php echo userdate($vid->timemodified, '%d %b %Y, %H:%M'); ?>
                </div>
                <?php if (!empty($vid->quizname)): ?>
                <div style="font-size:12px;color:#6c757d;">📝 <?php echo htmlspecialchars($vid->quizname); ?></div>
                <?php endif; ?>
                <?php if (!empty($vid->coursename)): ?>
                <div style="font-size:12px;color:#6c757d;">📚 <?php echo htmlspecialchars($vid->coursename); ?></div>
                <?php endif; ?>
                <div style="margin-top:8px;display:flex;gap:6px;">
                    <a href="<?php echo $src; ?>" target="_blank" class="btn btn-sm btn-outline-primary" style="flex:1;font-size:11px;">
                        ⬇ Download
                    </a>
                    <form method="post" action="" style="display:inline;">
                        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="delids[]" value="<?php echo $vid->id; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger" style="font-size:11px;"
                            onclick="return confirm('Delete this video?')">🗑</button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </form>

    <!-- Pagination -->
    <?php if ($pages > 1): ?>
    <div style="display:flex;gap:5px;justify-content:center;flex-wrap:wrap;margin-top:8px;">
        <?php for ($i = 0; $i < $pages; $i++): ?>
        <a href="<?php echo new moodle_url('/mod/quiz/accessrule/learnshield/videos360.php',
            ['search' => $search, 'p' => $i]); ?>"
           style="padding:5px 12px;border-radius:6px;font-size:13px;font-weight:600;text-decoration:none;
           background:<?php echo $i === $pgnum ? '#0f6cbf' : '#fff'; ?>;
           color:<?php echo $i === $pgnum ? '#fff' : '#495057'; ?>;border:1px solid #dee2e6;">
            <?php echo $i + 1; ?>
        </a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<script>
function ls360Chk() {
    var n = document.querySelectorAll('.ls360-chk:checked').length;
    var b = document.getElementById('ls360-del-btn');
    var c = document.getElementById('ls360-n');
    if (b) b.style.display = n > 0 ? 'inline-block' : 'none';
    if (c) c.textContent = n;
}
function ls360DelSelected() {
    var n = document.querySelectorAll('.ls360-chk:checked').length;
    if (!n) return;
    if (!confirm('Delete ' + n + ' video(s)?')) return;
    document.getElementById('ls360-form').submit();
}
</script>

<?php echo $OUTPUT->footer(); ?>
