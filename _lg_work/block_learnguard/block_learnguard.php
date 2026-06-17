<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * LearnGuard block plugin — edwiser-style grading queue and proctor dashboard.
 *
 * Shows a course selector dropdown. Below it:
 *  - Graders: per-assessment stats (requires grading / graded / not attempted) + Grade button.
 *  - Proctors: per-assessment active/suspended learner counts + Live Monitor button.
 *  - Site admins: quick link to site-admin overview.
 *
 * @package    block_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2026 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

class block_learnguard extends block_base {

    public function init(): void {
        $this->title = get_string('pluginname', 'block_learnguard');
    }

    public function applicable_formats(): array {
        return ['all' => true];
    }

    public function has_config(): bool {
        return false;
    }

    public function get_content(): ?stdClass {
        global $DB, $USER, $CFG, $OUTPUT;

        if (!isloggedin() || isguestuser()) { return null; }
        if ($this->content !== null)         { return $this->content; }

        $this->content         = new stdClass();
        $this->content->footer = '';

        $uid     = (int)$USER->id;
        $sysctx  = context_system::instance();
        $isSysAdmin = has_capability('moodle/site:config', $sysctx);

        // ── Build course-grouped grader data ────────────────────────────────
        // One row per LearnGuard activity this user is assigned to grade,
        // enriched with grading stats.
        $graderSQL = "
            SELECT g.learnguard AS lgid,
                   lg.name      AS lgname,
                   cm.id        AS cmid,
                   c.id         AS courseid,
                   c.shortname  AS coursename,
                   c.fullname   AS coursefullname,
                   COUNT(DISTINCT a.id)                         AS total_finished,
                   COALESCE(SUM(CASE WHEN myeg.my_graded IS NULL OR myeg.my_graded = 0 THEN 1 ELSE 0 END), 0) AS needs_grading,
                   COALESCE(SUM(CASE WHEN myeg.my_graded > 0 THEN 1 ELSE 0 END), 0) AS graded_count
              FROM {learnguard_graders}  g
              JOIN {learnguard}          lg  ON lg.id  = g.learnguard
              JOIN {course_modules}      cm  ON cm.instance = lg.id
              JOIN {modules}             mdl ON mdl.id = cm.module AND mdl.name = 'learnguard'
              JOIN {course}              c   ON c.id = cm.course
         LEFT JOIN {learnguard_attempts} a
                ON a.learnguard = lg.id AND a.state = 'finished'
         LEFT JOIN (SELECT attemptid,
                           COUNT(DISTINCT slotid) AS my_graded
                      FROM {learnguard_essay_grades}
                     WHERE graderid = :uid2
                  GROUP BY attemptid) myeg ON myeg.attemptid = a.id
             WHERE g.userid = :uid
          GROUP BY g.learnguard, lg.name, cm.id, c.id, c.shortname, c.fullname
          ORDER BY c.shortname ASC, lg.name ASC";

        $graderRows = $DB->get_records_sql($graderSQL, ['uid' => $uid, 'uid2' => $uid]);

        // ── Build course-grouped proctor data ────────────────────────────────
        $proctorSQL = "
            SELECT DISTINCT p.learnguard AS lgid,
                            lg.name      AS lgname,
                            cm.id        AS cmid,
                            c.id         AS courseid,
                            c.shortname  AS coursename,
                            c.fullname   AS coursefullname,
                            (SELECT COUNT(*) FROM {learnguard_attempts} aa
                              WHERE aa.learnguard = lg.id AND aa.state = 'inprogress') AS active_count,
                            (SELECT COUNT(*) FROM {learnguard_attempts} aa
                              WHERE aa.learnguard = lg.id AND aa.state = 'suspended') AS suspended_count
              FROM {learnguard_proctors} p
              JOIN {learnguard}          lg  ON lg.id  = p.learnguard
              JOIN {course_modules}      cm  ON cm.instance = lg.id
              JOIN {modules}             mdl ON mdl.id = cm.module AND mdl.name = 'learnguard'
              JOIN {course}              c   ON c.id = cm.course
             WHERE p.userid = :uid
          ORDER BY c.shortname ASC, lg.name ASC";

        $proctorRows = $DB->get_records_sql($proctorSQL, ['uid' => $uid]);

        // ── Collect all distinct courses ─────────────────────────────────────
        $courses   = [];
        foreach (array_merge(array_values($graderRows), array_values($proctorRows)) as $r) {
            if (!isset($courses[$r->courseid])) {
                $courses[$r->courseid] = ['id' => $r->courseid, 'name' => $r->coursefullname, 'short' => $r->coursename];
            }
        }
        // Sort by fullname.
        usort($courses, fn($a, $b) => strcmp($a['name'], $b['name']));

        $hasGrader  = !empty($graderRows);
        $hasProctor = !empty($proctorRows);
        $isEmpty    = !$hasGrader && !$hasProctor && !$isSysAdmin;

        // ── Group rows by course ─────────────────────────────────────────────
        $graderByCourse  = [];
        foreach ($graderRows  as $r) { $graderByCourse[$r->courseid][]  = $r; }
        $proctorByCourse = [];
        foreach ($proctorRows as $r) { $proctorByCourse[$r->courseid][] = $r; }

        // ── Unique block ID (multiple blocks on same page) ───────────────────
        static $blockInstCount = 0;
        $bid = 'lgblk' . (++$blockInstCount);

        ob_start();
        ?>
<style>
#<?php echo $bid; ?> { font-family:inherit; font-size:0.9em; }
#<?php echo $bid; ?> .blg-head { background:#0f6cbf; color:#fff; border-radius:6px 6px 0 0;
  padding:8px 12px; font-weight:700; font-size:0.97em; display:flex; align-items:center; gap:6px; }
#<?php echo $bid; ?> .blg-course-sel {
  width:100%; border:1px solid #dee2e6; border-radius:0; padding:7px 10px;
  font-size:0.9em; background:#f8f9fa; cursor:pointer; }
#<?php echo $bid; ?> .blg-course-block { display:none; padding:0; }
#<?php echo $bid; ?> .blg-course-block.active { display:block; }
#<?php echo $bid; ?> .blg-sect-title {
  font-weight:700; font-size:0.88em; color:#495057; text-transform:uppercase;
  letter-spacing:.04em; padding:8px 10px 4px; border-top:1px solid #f0f0f0; }
#<?php echo $bid; ?> .blg-act-row {
  border-bottom:1px solid #f4f4f4; padding:8px 10px; }
#<?php echo $bid; ?> .blg-act-name {
  font-weight:600; font-size:0.9em; color:#212529; white-space:nowrap;
  overflow:hidden; text-overflow:ellipsis; max-width:100%; display:block; }
#<?php echo $bid; ?> .blg-stats {
  display:flex; gap:6px; margin:4px 0; flex-wrap:wrap; }
#<?php echo $bid; ?> .blg-stat {
  font-size:0.78em; background:#f0f4ff; border:1px solid #cfe2ff; border-radius:4px;
  padding:2px 7px; color:#0a4d8c; font-weight:600; }
#<?php echo $bid; ?> .blg-stat.warn  { background:#fff3cd; border-color:#ffe082; color:#856404; }
#<?php echo $bid; ?> .blg-stat.ok    { background:#d4edda; border-color:#c3e6cb; color:#155724; }
#<?php echo $bid; ?> .blg-stat.danger { background:#f8d7da; border-color:#f5c6cb; color:#721c24; }
#<?php echo $bid; ?> .blg-act-btns {
  display:flex; gap:5px; margin-top:5px; flex-wrap:wrap; }
#<?php echo $bid; ?> .blg-btn {
  background:#0f6cbf; color:#fff; border:none; border-radius:5px;
  padding:4px 12px; font-size:0.82em; font-weight:700; text-decoration:none;
  cursor:pointer; white-space:nowrap; }
#<?php echo $bid; ?> .blg-btn:hover { background:#0a52a2; color:#fff; }
#<?php echo $bid; ?> .blg-btn.secondary {
  background:#fff; color:#0f6cbf; border:1px solid #0f6cbf; }
#<?php echo $bid; ?> .blg-btn.secondary:hover { background:#e8f0fb; }
#<?php echo $bid; ?> .blg-empty { color:#6c757d; font-size:0.85em; padding:6px 10px; }
#<?php echo $bid; ?> .blg-admin-row {
  padding:8px 10px; border-top:1px solid #dee2e6; margin-top:4px; text-align:center; }
</style>

<div id="<?php echo $bid; ?>">
<?php if ($isEmpty): ?>
  <p class="blg-empty">No LearnGuard assignments found for your account.</p>
<?php else: ?>

  <!-- Course selector -->
  <?php if (!empty($courses)): ?>
  <select class="blg-course-sel"
    onchange="(function(sel){var bid='<?php echo $bid; ?>';document.querySelectorAll('#'+bid+' .blg-course-block').forEach(function(b){b.classList.remove('active')});var t=document.getElementById(bid+'-course-'+sel.value);if(t){t.classList.add('active');}})(this)">
    <option value="">— Select a course —</option>
    <?php foreach ($courses as $c): ?>
    <option value="<?php echo (int)$c['id']; ?>"><?php echo s($c['short'] ?: $c['name']); ?></option>
    <?php endforeach; ?>
  </select>
  <?php endif; ?>

  <!-- Per-course panels -->
  <?php foreach ($courses as $c):
    $cid = (int)$c['id'];
    $cGraderRows  = $graderByCourse[$cid]  ?? [];
    $cProctorRows = $proctorByCourse[$cid] ?? [];
  ?>
  <div class="blg-course-block" id="<?php echo $bid; ?>-course-<?php echo $cid; ?>">

    <?php if (!empty($cGraderRows)): ?>
    <div class="blg-sect-title">📝 Grading Queue</div>
    <?php foreach ($cGraderRows as $r):
      $needsGrading = (int)$r->needs_grading;
      $gradedCount  = (int)$r->graded_count;
      $total        = (int)$r->total_finished;
      $notAttempted = max(0, $total - $needsGrading - $gradedCount);
    ?>
    <div class="blg-act-row">
      <span class="blg-act-name"><?php echo s($r->lgname); ?></span>
      <div class="blg-stats">
        <span class="blg-stat <?php echo $needsGrading > 0 ? 'warn' : ''; ?>">
          Requires Grading: <strong><?php echo $needsGrading; ?></strong>
        </span>
        <span class="blg-stat ok">
          Graded: <strong><?php echo $gradedCount; ?></strong>
        </span>
        <span class="blg-stat">
          Finished: <strong><?php echo $total; ?></strong>
        </span>
      </div>
      <div class="blg-act-btns">
        <a href="<?php echo $CFG->wwwroot . '/mod/learnguard/grader.php?id=' . (int)$r->cmid; ?>"
           class="blg-btn">
          <?php echo $needsGrading > 0 ? '✏️ Grade (' . $needsGrading . ')' : '✅ Review'; ?>
        </a>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!empty($cProctorRows)): ?>
    <div class="blg-sect-title">🛡️ Proctoring</div>
    <?php foreach ($cProctorRows as $r):
      $active    = (int)$r->active_count;
      $suspended = (int)$r->suspended_count;
    ?>
    <div class="blg-act-row">
      <span class="blg-act-name"><?php echo s($r->lgname); ?></span>
      <div class="blg-stats">
        <span class="blg-stat ok">Active: <strong><?php echo $active; ?></strong></span>
        <?php if ($suspended > 0): ?>
        <span class="blg-stat danger">Suspended: <strong><?php echo $suspended; ?></strong></span>
        <?php endif; ?>
      </div>
      <div class="blg-act-btns">
        <a href="<?php echo $CFG->wwwroot . '/mod/learnguard/proctordashboard.php?id=' . (int)$r->cmid; ?>"
           class="blg-btn">📺 Live Monitor</a>
        <a href="<?php echo $CFG->wwwroot . '/mod/learnguard/proctorview.php?id=' . (int)$r->cmid; ?>"
           class="blg-btn secondary">🔍 View</a>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php if (empty($cGraderRows) && empty($cProctorRows)): ?>
    <p class="blg-empty">No assignments for this course.</p>
    <?php endif; ?>

  </div><!-- /course-block -->
  <?php endforeach; ?>

  <!-- Auto-select the first course if there is exactly one -->
  <?php if (count($courses) === 1): ?>
  <script>
  (function(){
    var sel = document.querySelector('#<?php echo $bid; ?> .blg-course-sel');
    if (sel) { sel.value = '<?php echo reset($courses)['id']; ?>'; sel.dispatchEvent(new Event('change')); }
  })();
  </script>
  <?php endif; ?>

<?php endif; ?>

  <?php if ($isSysAdmin): ?>
  <div class="blg-admin-row">
    <a class="blg-btn" href="<?php echo $CFG->wwwroot . '/mod/learnguard/siteadmin.php'; ?>">⚙️ LearnGuard Admin</a>
  </div>
  <?php endif; ?>
</div><!-- /block -->
<?php
        $this->content->text = ob_get_clean();
        return $this->content;
    }
}
