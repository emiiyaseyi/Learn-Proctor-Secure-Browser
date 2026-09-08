<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Ad-hoc task: runs OPTIMIZE TABLE on learnguard_snapshots/learnguard_audio_clips to
 * physically reclaim disk space after a bulk delete — moved off the request/response
 * cycle entirely after a live report of the synchronous version ("🗜 Reclaim Disk
 * Space" button in siteadmin.php) hanging indefinitely with the browser showing no
 * feedback. OPTIMIZE TABLE on a table with hundreds of thousands of rows and large BLOB
 * content is a genuine full-table rebuild that can run far longer than a web
 * request/gateway timeout allows — same underlying problem already fixed for bulk ZIP
 * export in v2.0.175, same fix shape here.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_learnguard\task;

defined('MOODLE_INTERNAL') || die();

class reclaim_disk_space extends \core\task\adhoc_task {

    public function execute(): void {
        global $DB;

        set_config('reclaim_status', 'running', 'learnguard');
        set_config('reclaim_started', time(), 'learnguard');

        if ($DB->get_dbfamily() !== 'mysql') {
            set_config('reclaim_status', 'error', 'learnguard');
            set_config('reclaim_error', 'Disk space reclaim is only supported on MySQL/MariaDB.', 'learnguard');
            return;
        }

        try {
            foreach (['learnguard_snapshots', 'learnguard_audio_clips'] as $rtable) {
                if ($DB->get_manager()->table_exists($rtable)) {
                    mtrace("LearnGuard reclaim: OPTIMIZE TABLE $rtable starting…");
                    $DB->execute('OPTIMIZE TABLE {' . $rtable . '}');
                    mtrace("LearnGuard reclaim: OPTIMIZE TABLE $rtable done.");
                }
            }
            set_config('reclaim_status', 'done', 'learnguard');
            set_config('reclaim_finished', time(), 'learnguard');
        } catch (\Throwable $e) {
            set_config('reclaim_status', 'error', 'learnguard');
            set_config('reclaim_error', $e->getMessage(), 'learnguard');
            mtrace('LearnGuard reclaim FAILED: ' . $e->getMessage());
        }
    }
}
