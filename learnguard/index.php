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
 * List all LearnGuard instances in a course.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT); // Course ID.

$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);

require_course_login($course);
$PAGE->set_pagelayout('incourse');

$event = \core\event\course_module_instance_list_viewed::create(['context' => context_course::instance($course->id)]);
$event->add_record_snapshot('course', $course);
$event->trigger();

$PAGE->set_url('/mod/learnguard/index.php', ['id' => $id]);
$PAGE->set_title($course->shortname . ': ' . get_string('modulenameplural', 'learnguard'));
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'learnguard'));

$instances = get_all_instances_in_course('learnguard', $course);

if (empty($instances)) {
    notice(get_string('thereareno', 'moodle', get_string('modulenameplural', 'learnguard')),
           new moodle_url('/course/view.php', ['id' => $course->id]));
}

$table = new html_table();
$table->head = [
    get_string('name'),
    get_string('attempts', 'learnguard'),
    get_string('grade', 'learnguard'),
];
$table->align = ['left', 'center', 'center'];
$table->attributes['class'] = 'generaltable';

foreach ($instances as $instance) {
    $link = html_writer::link(
        new moodle_url('/mod/learnguard/view.php', ['id' => $instance->coursemodule]),
        format_string($instance->name)
    );

    $attemptcount = $DB->count_records('learnguard_attempts', ['learnguard' => $instance->id]);

    $table->data[] = [
        $link,
        $attemptcount,
        ($instance->grade > 0 ? $instance->grade : get_string('nogrades', 'grades')),
    ];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
