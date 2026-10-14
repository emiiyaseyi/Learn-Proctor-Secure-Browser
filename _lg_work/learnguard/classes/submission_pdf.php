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

namespace mod_learnguard;

defined('MOODLE_INTERNAL') || die();

/**
 * Builds a plain "question + your answer" PDF for one finished attempt — no score, no
 * grade, no rubric breakdown, no proctoring data. Per explicit request: this is purely
 * so a student can see what they submitted, not a grade report. Uses Moodle's own
 * bundled TCPDF wrapper (lib/pdflib.php) — no new dependency, no CDN, matching the
 * plugin's standing "no external libraries" decision.
 */
class submission_pdf {

    /**
     * @param int $attemptid
     * @return string raw PDF binary content
     */
    public static function build(int $attemptid): string {
        global $DB, $CFG;
        require_once($CFG->libdir . '/pdflib.php');

        $attempt = $DB->get_record('learnguard_attempts', ['id' => $attemptid], '*', MUST_EXIST);
        $instance = $DB->get_record('learnguard', ['id' => $attempt->learnguard], '*', MUST_EXIST);
        $student  = $DB->get_record('user', ['id' => $attempt->userid], '*', MUST_EXIST);

        // Only essay/choiceessay slots — this is "your question and answer", not every
        // auto-graded MCQ/TF/etc question type.
        $rows = $DB->get_records_sql(
            "SELECT aq.slotnum, aq.slotid, aq.questionid, q.qtype, q.questiontext, q.questiontextformat,
                    r.responsedata
               FROM {learnguard_attempt_questions} aq
               JOIN {learnguard_qbank} q ON q.id = aq.questionid
          LEFT JOIN {learnguard_responses} r ON r.attemptid = aq.attemptid AND r.slotid = aq.slotid
              WHERE aq.attemptid = :aid AND q.qtype IN ('essay', 'choiceessay')
           ORDER BY aq.slotnum ASC",
            ['aid' => $attemptid]
        );

        $pdf = new \pdf();
        $pdf->SetCreator('LearnGuard');
        $pdf->SetAuthor(fullname($student));
        $pdf->SetTitle(format_string($instance->name) . ' - ' . fullname($student));
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->AddPage();

        $html = '<h2>' . s(format_string($instance->name)) . '</h2>' .
            '<p><strong>Student:</strong> ' . s(fullname($student)) . '<br>' .
            '<strong>Email:</strong> ' . s($student->email) . '<br>' .
            '<strong>Submitted:</strong> ' . ($attempt->timefinish ? userdate($attempt->timefinish, '%d %B %Y, %H:%M') : 'N/A') .
            '</p><hr>';

        if (empty($rows)) {
            $html .= '<p><em>This assessment had no essay or written-answer questions ' .
                'to show — multiple-choice/short-answer responses are not included in ' .
                'this document.</em></p>';
        }

        $qnum = 0;
        foreach ($rows as $row) {
            $qnum++;
            $answerText = '';
            $questionDisplay = '';

            if ($row->qtype === 'choiceessay') {
                $decoded = $row->responsedata ? json_decode($row->responsedata, true) : null;
                $field   = "q{$row->slotnum}_answer";
                $ceRaw   = is_array($decoded) ? ($decoded[$field] ?? '') : '';
                $ceData  = $ceRaw ? json_decode((string)$ceRaw, true) : null;
                $promptId = is_array($ceData) ? (int)($ceData['promptid'] ?? 0) : 0;
                $answerText = is_array($ceData) ? (string)($ceData['text'] ?? '') : '';
                $prompt = $promptId > 0
                    ? $DB->get_record('learnguard_ce_prompts', ['id' => $promptId], 'prompttext, prompttextformat')
                    : null;
                $questionDisplay = $prompt
                    ? format_text($prompt->prompttext, $prompt->prompttextformat ?? FORMAT_HTML)
                    : format_text($row->questiontext, $row->questiontextformat ?? FORMAT_HTML);
            } else {
                $decoded = $row->responsedata ? json_decode($row->responsedata, true) : null;
                $field   = "q{$row->slotnum}_answer";
                $answerText = is_array($decoded) ? (string)($decoded[$field] ?? '') : '';
                $questionDisplay = format_text($row->questiontext, $row->questiontextformat ?? FORMAT_HTML);
            }

            $answerHtml = trim(strip_tags($answerText)) === ''
                ? '<em>No response submitted.</em>'
                : question_engine::render_essay_answer_html($answerText);

            $html .= '<h3>Question ' . $qnum . '</h3>' .
                '<div style="margin-bottom:8px;">' . $questionDisplay . '</div>' .
                '<p><strong>Your answer:</strong></p>' .
                '<div style="background:#f5f5f5;padding:8px;">' . $answerHtml . '</div><br>';
        }

        $pdf->writeHTML($html, true, false, true, false, '');
        return $pdf->Output('submission.pdf', 'S');
    }

    /**
     * Emails the PDF to the student who made this attempt.
     *
     * @param int $attemptid
     * @return bool whether the email was accepted for sending
     */
    public static function email_to_student(int $attemptid): bool {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/learnguard/lib.php');
        $attempt  = $DB->get_record('learnguard_attempts', ['id' => $attemptid], '*', MUST_EXIST);
        $instance = $DB->get_record('learnguard', ['id' => $attempt->learnguard], '*', MUST_EXIST);
        $student  = $DB->get_record('user', ['id' => $attempt->userid], '*', MUST_EXIST);

        $pdfContent = self::build($attemptid);
        $filename   = clean_filename(format_string($instance->name) . '.pdf');

        $tmpDir = make_temp_directory('learnguard_submission_pdf');
        $tmpPath = $tmpDir . '/' . uniqid('sub_') . '.pdf';
        file_put_contents($tmpPath, $pdfContent);

        $noreply = \core_user::get_noreply_user();
        $subject = 'Your submitted answer — ' . format_string($instance->name);
        $supportEmail = trim((string)get_config('learnguard', 'export_support_email'));
        $body = "Hello " . fullname($student) . ",\n\n" .
            "Attached is a copy of the question(s) and your submitted answer(s) for \"" .
            format_string($instance->name) . "\".\n\n" .
            "This is a record of what you submitted — it does not include your score.\n\n" .
            ($supportEmail !== ''
                ? "If you have any questions about this document, please contact {$supportEmail}.\n\n"
                : '') .
            "LearnGuard — Secure Assessment Platform";

        $htmlBody = \learnguard_html_email($subject, $body, '📄');

        $result = false;
        try {
            $result = email_to_user($student, $noreply, $subject, $body, $htmlBody, $tmpPath, $filename);
        } finally {
            @unlink($tmpPath);
        }
        return (bool)$result;
    }
}
