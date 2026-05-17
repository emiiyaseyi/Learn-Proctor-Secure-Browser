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
 * Privacy API implementation for Learn Shield V1.
 *
 * @package    quizaccess_learnshield
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link       https://www.linkedin.com/in/michaeladeniran/
 */

namespace quizaccess_learnshield\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\deletion_criteria;
use core_privacy\local\request\helper;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for Learn Shield V1.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    /**
     * Describe all personal data stored by this plugin.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('quizaccess_learnshield_logs', [
            'courseid'      => 'privacy:metadata:courseid',
            'quizid'        => 'privacy:metadata:quizid',
            'userid'        => 'privacy:metadata:userid',
            'webcampicture' => 'privacy:metadata:webcampicture',
            'status'        => 'privacy:metadata:status',
            'timemodified'  => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:quizaccess_learnshield_logs');

        $collection->add_database_table('quizaccess_learnshield_sessions', [
            'courseid'     => 'privacy:metadata:courseid',
            'quizid'       => 'privacy:metadata:quizid',
            'userid'       => 'privacy:metadata:userid',
            'ip_address'   => 'privacy:metadata:ip_address',
            'browser_info' => 'privacy:metadata:browser_info',
            'geolocation'  => 'privacy:metadata:geolocation',
            'risk_score'   => 'privacy:metadata:risk_score',
        ], 'privacy:metadata:quizaccess_learnshield_sessions');

        $collection->add_database_table('quizaccess_learnshield_events', [
            'userid'       => 'privacy:metadata:userid',
            'event_type'   => 'privacy:metadata:event_type',
            'event_data'   => 'privacy:metadata:event_data',
            'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:quizaccess_learnshield_events');

        $collection->add_subsystem_link('core_files', [], 'privacy:core_files');

        return $collection;
    }

    /**
     * Return context list for a user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $contextlist->add_system_context();
        return $contextlist;
    }

    /**
     * Return user list for a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \context_system) {
            return;
        }
        $userlist->add_from_sql('userid',
            'SELECT DISTINCT userid FROM {quizaccess_learnshield_logs}', []);
    }

    /**
     * Export user data.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;
        $logs   = $DB->get_records('quizaccess_learnshield_logs', ['userid' => $userid]);

        foreach ($logs as $log) {
            $context = \context_system::instance();
            writer::with_context($context)->export_data(
                [get_string('pluginname', 'quizaccess_learnshield'), $log->id],
                $log
            );
        }
    }

    /**
     * Delete data for all users in a context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if (!$context instanceof \context_system) {
            return;
        }
        $DB->delete_records('quizaccess_learnshield_logs');
        $DB->delete_records('quizaccess_learnshield_sessions');
        $DB->delete_records('quizaccess_learnshield_events');
    }

    /**
     * Delete data for a specific user.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        $DB->delete_records('quizaccess_learnshield_logs',     ['userid' => $userid]);
        $DB->delete_records('quizaccess_learnshield_sessions', ['userid' => $userid]);
        $DB->delete_records('quizaccess_learnshield_events',   ['userid' => $userid]);
    }

    /**
     * Delete data for a list of users in a context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        if (!$context instanceof \context_system) {
            return;
        }
        foreach ($userlist->get_userids() as $userid) {
            $DB->delete_records('quizaccess_learnshield_logs',     ['userid' => $userid]);
            $DB->delete_records('quizaccess_learnshield_sessions', ['userid' => $userid]);
            $DB->delete_records('quizaccess_learnshield_events',   ['userid' => $userid]);
        }
    }
}
