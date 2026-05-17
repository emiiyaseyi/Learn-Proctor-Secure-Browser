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
 * LearnGuard Demo Setup.
 *
 * Run this page once (as site admin) to install a fully-configured
 * demo assessment with 40 questions spanning all 6 question types.
 *
 * Access: /mod/learnguard/demo_setup.php
 * Requires: site admin
 *
 * What it creates:
 *  - Course: "LearnGuard Demo Course"
 *  - Question categories: General Knowledge, Science, History, Logic
 *  - 40 sample questions (all 6 types)
 *  - LearnGuard assessment: "Demo Assessment (Randomised)"
 *    → pool of 40 questions, each student gets 20 random ones
 *  - LearnGuard assessment: "Demo Assessment (Fixed)"
 *    → all questions in fixed order
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once($CFG->dirroot . '/course/lib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

if (!data_submitted() || !confirm_sesskey()) {
    // Show confirmation page.
    $PAGE->set_url('/mod/learnguard/demo_setup.php');
    $PAGE->set_context(context_system::instance());
    $PAGE->set_title('LearnGuard Demo Setup');
    $PAGE->set_heading('LearnGuard Demo Setup');
    $PAGE->requires->css('/mod/learnguard/styles.css');

    echo $OUTPUT->header();
    echo learnguard_banner('LearnGuard Demo Setup',
        'Creates a demo course with 40 sample questions and 2 assessments.', '🎓');

    echo html_writer::start_div('alert alert-warning');
    echo '<strong>⚠️ This will create:</strong><ul>';
    echo '<li>A new course: <em>LearnGuard Demo Course</em></li>';
    echo '<li>4 question categories</li>';
    echo '<li>40 sample questions (all 6 question types)</li>';
    echo '<li>2 LearnGuard assessments (one randomised, one fixed)</li>';
    echo '</ul>';
    echo 'Running it twice is safe — existing demo data is skipped.';
    echo html_writer::end_div();

    echo html_writer::start_tag('form', ['method' => 'post']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => '🚀 Create Demo Now', 'class' => 'btn btn-primary btn-lg']);
    echo ' ';
    echo html_writer::link(new moodle_url('/mod/learnguard/siteadmin.php'), 'Cancel', ['class' => 'btn btn-outline-secondary btn-lg']);
    echo html_writer::end_tag('form');

    echo $OUTPUT->footer();
    exit;
}

// ============================================================================
// EXECUTE DEMO SETUP
// ============================================================================
$log = [];

function learnguard_demo_log(string $msg): void {
    global $log;
    $log[] = $msg;
}

// ---- 1. Course ----
$demoshortname = 'LGDEMO';
$course = $DB->get_record('course', ['shortname' => $demoshortname]);
if (!$course) {
    $coursedata = (object)[
        'fullname'   => 'LearnGuard Demo Course',
        'shortname'  => $demoshortname,
        'summary'    => 'Auto-generated demo course for LearnGuard testing.',
        'format'     => 'topics',
        'numsections' => 3,
        'startdate'  => time(),
        'visible'    => 1,
        'category'   => 1,
    ];
    $course = create_course($coursedata);
    learnguard_demo_log("✅ Created course: {$course->fullname} (id={$course->id})");
} else {
    learnguard_demo_log("ℹ️ Course already exists (id={$course->id})");
}

// ---- 2. Question categories ----
$catids = [];
$catnames = ['General Knowledge', 'Science', 'History', 'Logic'];
foreach ($catnames as $catname) {
    $existing = $DB->get_record('learnguard_qbank_cats', ['name' => $catname]);
    if (!$existing) {
        $catid = $DB->insert_record('learnguard_qbank_cats', (object)[
            'name'         => $catname,
            'description'  => 'Demo category: ' . $catname,
            'sortorder'    => 0,
            'timecreated'  => time(),
            'timemodified' => time(),
        ]);
        learnguard_demo_log("✅ Created category: $catname (id=$catid)");
    } else {
        $catid = $existing->id;
        learnguard_demo_log("ℹ️ Category exists: $catname (id=$catid)");
    }
    $catids[$catname] = $catid;
}

// ---- 3. Questions ----
function learnguard_demo_add_q(string $qtype, string $name, string $text, string $cat, array $answers, array $catids): int {
    global $DB;
    if ($DB->record_exists('learnguard_qbank', ['name' => $name])) { return 0; }
    $qid = $DB->insert_record('learnguard_qbank', (object)[
        'categoryid'          => $catids[$cat] ?? 0,
        'name'                => $name,
        'questiontext'        => $text,
        'questiontextformat'  => FORMAT_HTML,
        'qtype'               => $qtype,
        'defaultmark'         => 1.0,
        'shuffleanswers'      => 1,
        'generalfeedback'     => '',
        'generalfeedbackformat' => FORMAT_HTML,
        'timecreated'         => time(),
        'timemodified'        => time(),
        'createdby'           => 2, // admin
    ]);
    $sort = 0;
    foreach ($answers as $atext => $fraction) {
        $DB->insert_record('learnguard_qbank_answers', (object)[
            'questionid'       => $qid,
            'answertext'       => $atext,
            'answertextformat' => FORMAT_PLAIN,
            'fraction'         => (float)$fraction,
            'feedback'         => $fraction > 0 ? 'Correct!' : 'Incorrect.',
            'sortorder'        => $sort++,
        ]);
    }
    return $qid;
}

$demoqs = [
    // General Knowledge — Multiple Choice
    ['multichoice', 'GK1: Capital of France', 'What is the capital of France?', 'General Knowledge',
        ['Paris' => 1, 'London' => 0, 'Berlin' => 0, 'Madrid' => 0]],
    ['multichoice', 'GK2: Olympic Rings', 'How many rings are on the Olympic flag?', 'General Knowledge',
        ['3' => 0, '4' => 0, '5' => 1, '6' => 0]],
    ['multichoice', 'GK3: Largest Ocean', 'Which is the largest ocean on Earth?', 'General Knowledge',
        ['Atlantic' => 0, 'Pacific' => 1, 'Indian' => 0, 'Arctic' => 0]],
    ['multichoice', 'GK4: Continents', 'How many continents are there?', 'General Knowledge',
        ['5' => 0, '6' => 0, '7' => 1, '8' => 0]],
    ['multichoice', 'GK5: Mona Lisa', 'Who painted the Mona Lisa?', 'General Knowledge',
        ['Picasso' => 0, 'Michelangelo' => 0, 'Da Vinci' => 1, 'Rembrandt' => 0]],
    ['multichoice', 'GK6: Fastest land animal', 'What is the fastest land animal?', 'General Knowledge',
        ['Lion' => 0, 'Cheetah' => 1, 'Horse' => 0, 'Greyhound' => 0]],
    ['multichoice', 'GK7: Currency of Japan', 'What is the currency of Japan?', 'General Knowledge',
        ['Yuan' => 0, 'Won' => 0, 'Yen' => 1, 'Dollar' => 0]],

    // Science — True/False
    ['truefalse', 'SCI1: Earth orbits Sun', 'The Earth orbits the Sun.', 'Science',
        ['true' => 1, 'false' => 0]],
    ['truefalse', 'SCI2: Light speed', 'Light travels faster than sound.', 'Science',
        ['true' => 1, 'false' => 0]],
    ['truefalse', 'SCI3: Humans and chimps', 'Humans share 98% DNA with chimpanzees.', 'Science',
        ['true' => 1, 'false' => 0]],
    ['truefalse', 'SCI4: Pluto planet', 'Pluto is classified as a planet by the IAU.', 'Science',
        ['true' => 0, 'false' => 1]],
    ['truefalse', 'SCI5: Water boiling', 'Water boils at 100°C at sea level.', 'Science',
        ['true' => 1, 'false' => 0]],
    ['truefalse', 'SCI6: Atom size', 'Atoms are visible to the naked eye.', 'Science',
        ['true' => 0, 'false' => 1]],
    ['truefalse', 'SCI7: Gravity moon', 'The Moon has gravity.', 'Science',
        ['true' => 1, 'false' => 0]],

    // Science — Short Answer
    ['shortanswer', 'SCI8: Chemical symbol water', 'What is the chemical symbol for water?', 'Science',
        ['H2O' => 1, 'h2o' => 1]],
    ['shortanswer', 'SCI9: Periodic table hydrogen', 'What is element number 1 on the periodic table?', 'Science',
        ['Hydrogen' => 1, 'hydrogen' => 1]],
    ['shortanswer', 'SCI10: Planet closest to sun', 'Which planet is closest to the Sun?', 'Science',
        ['Mercury' => 1, 'mercury' => 1]],
    ['shortanswer', 'SCI11: Speed of light', 'What is the approximate speed of light in km/s? (enter number)', 'Science',
        ['300000' => 1, '299792' => 1]],

    // History — Multiple Choice
    ['multichoice', 'HIS1: WW2 end', 'In what year did World War II end?', 'History',
        ['1943' => 0, '1944' => 0, '1945' => 1, '1946' => 0]],
    ['multichoice', 'HIS2: First moon landing', 'Who was the first person to walk on the Moon?', 'History',
        ['Buzz Aldrin' => 0, 'Neil Armstrong' => 1, 'Yuri Gagarin' => 0, 'John Glenn' => 0]],
    ['multichoice', 'HIS3: French Revolution', 'In what year did the French Revolution begin?', 'History',
        ['1776' => 0, '1789' => 1, '1799' => 0, '1815' => 0]],
    ['multichoice', 'HIS4: Inventor telephone', 'Who is credited with inventing the telephone?', 'History',
        ['Thomas Edison' => 0, 'Nikola Tesla' => 0, 'Alexander Bell' => 1, 'Guglielmo Marconi' => 0]],
    ['multichoice', 'HIS5: Berlin Wall fall', 'In what year did the Berlin Wall fall?', 'History',
        ['1987' => 0, '1988' => 0, '1989' => 1, '1990' => 0]],

    // Logic — Multi-select
    ['multicheck', 'LOG1: Prime numbers', 'Which of the following are prime numbers?', 'Logic',
        ['2' => 1, '4' => 0, '7' => 1, '9' => 0, '11' => 1]],
    ['multicheck', 'LOG2: Vowels', 'Which of the following are vowels in English?', 'Logic',
        ['A' => 1, 'B' => 0, 'E' => 1, 'I' => 1, 'O' => 1, 'U' => 1]],
    ['multicheck', 'LOG3: Mammals', 'Which of the following are mammals?', 'Logic',
        ['Dolphin' => 1, 'Shark' => 0, 'Bat' => 1, 'Eagle' => 0, 'Whale' => 1]],
    ['multicheck', 'LOG4: African countries', 'Which of the following are countries in Africa?', 'Logic',
        ['Nigeria' => 1, 'Brazil' => 0, 'Kenya' => 1, 'Australia' => 0, 'Ghana' => 1]],

    // Logic — Essay
    ['essay', 'ES1: Climate change', 'Explain in your own words what climate change is and its main causes.', 'Logic', []],
    ['essay', 'ES2: Democracy', 'Describe what democracy means and give one example of a democratic country.', 'Logic', []],
    ['essay', 'ES3: Internet impact', 'How has the internet changed the way we learn? Give three examples.', 'Logic', []],

    // Matching
    ['matching', 'MAT1: Capitals', 'Match each country to its capital city.', 'Logic',
        ['France' => -1, 'Germany' => -1, 'Japan' => -1, 'Brazil' => -1,
         'Paris' => 1, 'Berlin' => 1, 'Tokyo' => 1, 'Brasília' => 1]],
    ['matching', 'MAT2: Scientists', 'Match each scientist to their discovery.', 'Logic',
        ['Newton' => -1, 'Darwin' => -1, 'Curie' => -1,
         'Gravity' => 1, 'Evolution' => 1, 'Radioactivity' => 1]],
];

$createdQids = [];
$addedCount  = 0;
foreach ($demoqs as $q) {
    [$qtype, $name, $text, $cat, $answers] = $q;
    $qid = learnguard_demo_add_q($qtype, $name, $text, $cat, $answers, $catids);
    if ($qid) {
        $createdQids[] = $qid;
        $addedCount++;
    } else {
        // Fetch existing id.
        $createdQids[] = (int)$DB->get_field('learnguard_qbank', 'id', ['name' => $name]);
    }
}
learnguard_demo_log("✅ Questions ready: " . count($createdQids) . " total ($addedCount new)");

// ---- 4. LearnGuard modules ----
// Get section 1 of the demo course.
$section = $DB->get_record('course_sections', ['course' => $course->id, 'section' => 1]);
if (!$section) {
    course_create_sections_if_missing($course->id, 1);
    $section = $DB->get_record('course_sections', ['course' => $course->id, 'section' => 1]);
}

$moduleinfo = $DB->get_record('modules', ['name' => 'learnguard']);
if (!$moduleinfo) {
    learnguard_demo_log("❌ learnguard module not installed — cannot create activities.");
} else {

    foreach ([
        ['Demo Assessment (Randomised)', 1, 20,
         'Each student gets <strong>20 random questions</strong> from a pool of 40. Proctoring enabled.'],
        ['Demo Assessment (Fixed Order)', 0, 0,
         'All 40 questions in fixed order. Proctoring enabled.'],
    ] as [$actname, $randomize, $numq, $intro]) {

        if (!$DB->record_exists('learnguard', ['course' => $course->id, 'name' => $actname])) {
            $lgdata = (object)[
                'course'              => $course->id,
                'name'                => $actname,
                'intro'               => $intro,
                'introformat'         => FORMAT_HTML,
                'timecreated'         => time(),
                'timemodified'        => time(),
                'timelimit'           => 0,
                'attempts'            => 3,
                'questionsperpage'    => 1,
                'shufflequestions'    => 0,
                'shuffleanswers'      => 1,
                'grade'               => 100,
                'grademethod'         => 1,
                'decimalpoints'       => 2,
                'reviewattempt'       => 0,
                'reviewcorrectness'   => 0,
                'reviewfeedback'      => 0,
                'reviewmaxmarks'      => 0,
                'proctoring'          => 1,
                'proctor_fullscreen'  => 1,
                'proctor_webcam'      => 1,
                'proctor_audio'       => 1,
                'proctor_tabdetect'   => 1,
                'proctor_keyblock'    => 1,
                'proctor_clipboard'   => 1,
                'proctor_snapinterval'=> 30,
                'proctor_fcmethod'    => 'None',
                'proctor_env_record'  => 0,
                'block_mobile'        => 1,
                'autosuspend_score'   => 80,
                'gradepass'           => 50.0,
                'timeopen'            => 0,
                'timeclose'           => 0,
                'randomize'           => $randomize,
                'num_questions'       => $numq,
                'show_results'        => 1,
                'results_released'    => 0,
                'instructions'        => '<p>Welcome to the LearnGuard demo assessment!</p>' .
                    '<p>This is a proctored assessment. Your webcam and screen activity will be monitored.</p>',
                'instructionsformat'  => FORMAT_HTML,
            ];
            $lgid = $DB->insert_record('learnguard', $lgdata);

            // Add to course as a cm.
            $cm = (object)[
                'course'     => $course->id,
                'module'     => $moduleinfo->id,
                'instance'   => $lgid,
                'section'    => $section->id,
                'idnumber'   => '',
                'added'      => time(),
                'visible'    => 1,
                'groupmode'  => 0,
                'completion' => 0,
            ];
            $cmid = add_course_module($cm);
            course_add_cm_to_section($course->id, $cmid, 1);

            // Create grade item.
            $lgdata->id = $lgid;
            $lgdata->course = $course->id;
            learnguard_grade_item_update($lgdata);

            // Add all questions as slots.
            $slot = 1;
            foreach ($createdQids as $qid) {
                $q = $DB->get_record('learnguard_qbank', ['id' => $qid]);
                $DB->insert_record('learnguard_slots', (object)[
                    'learnguard'  => $lgid,
                    'questionid'  => $qid,
                    'slot'        => $slot++,
                    'maxmark'     => $q ? (float)$q->defaultmark : 1.0,
                    'page'        => 1,
                ]);
            }

            learnguard_demo_log("✅ Created assessment: $actname (cmid=$cmid, slots=$slot)");
        } else {
            learnguard_demo_log("ℹ️ Assessment already exists: $actname");
        }
    }
}

// ---- Output results ----
$PAGE->set_url('/mod/learnguard/demo_setup.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('LearnGuard Demo Setup');
$PAGE->set_heading('LearnGuard Demo Setup');
$PAGE->requires->css('/mod/learnguard/styles.css');

echo $OUTPUT->header();
echo learnguard_banner('LearnGuard Demo Setup Complete', 'Your demo course is ready.', '🎓');

echo html_writer::div(
    implode('<br>', $log),
    'alert alert-success', ['style' => 'font-family:monospace;font-size:13px;line-height:1.8;']
);

echo html_writer::tag('h4', 'Next Steps', ['class' => 'mt-3']);
echo html_writer::start_tag('ol', ['class' => 'mb-4']);
echo html_writer::tag('li', 'Go to the demo course: ' .
    html_writer::link(new moodle_url('/course/view.php', ['id' => $course->id]),
        'LearnGuard Demo Course', ['class' => 'btn btn-sm btn-primary', 'target' => '_blank']));
echo html_writer::tag('li', 'Enrol as a student (or use a student test account) and attempt the randomised assessment.');
echo html_writer::tag('li', 'Come back as teacher/admin and check the reports and live dashboard.');
echo html_writer::tag('li', 'Visit ' . html_writer::link(
    new moodle_url('/mod/learnguard/siteadmin.php'),
    'LearnGuard Site Admin', ['class' => 'btn btn-sm btn-outline-secondary']
) . ' to see the question bank and all assessments.');
echo html_writer::end_tag('ol');

echo html_writer::link(new moodle_url('/mod/learnguard/siteadmin.php'),
    '← Site Admin', ['class' => 'btn btn-outline-secondary']);

echo $OUTPUT->footer();
