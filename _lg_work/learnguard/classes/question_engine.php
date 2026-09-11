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
 * LearnGuard's own lightweight question engine.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_learnguard;

defined('MOODLE_INTERNAL') || die();

class question_engine {

    // -----------------------------------------------------------------------
    // Render
    // -----------------------------------------------------------------------

    public static function render_question(
        int $questionid, int $slotnum, array $saved = [], bool $review = false, int $attemptid = 0
    ): string {
        global $DB;

        $question = $DB->get_record('learnguard_qbank', ['id' => $questionid], '*', MUST_EXIST);
        $answers  = $DB->get_records('learnguard_qbank_answers', ['questionid' => $questionid], 'sortorder ASC');

        if ($question->shuffleanswers && !$review && !in_array($question->qtype, ['matching', 'truefalse'])) {
            // Use a deterministic seed so the same student always sees options in the same
            // order for a given question in their attempt. Without a seed, options reshuffle
            // on every load and students may accidentally re-select the wrong option.
            $seed = $attemptid > 0
                ? abs($attemptid * 31 + $questionid * 7 + $slotnum)
                : abs($questionid * 1000 + $slotnum);
            $answers = self::shuffle_preserving_keys($answers, $seed);
        }

        $maxmark  = (float)($question->defaultmark ?? 1.0);
        $typeLabel = [
            'multichoice'   => 'Multiple Choice',
            'multicheck'    => 'Multiple Answer',
            'truefalse'     => 'True / False',
            'shortanswer'   => 'Short Answer',
            'essay'         => 'Essay',
            'matching'      => 'Matching',
            'audio_response'=> 'Audio Response',
            'file_upload'   => 'File Upload',
            'choiceessay'   => 'Choice Essay',
        ][$question->qtype] ?? ucfirst($question->qtype);

        // ── Card shell ─────────────────────────────────────────────────────
        $html  = '<div class="lg-question-card" data-qtype="' . s($question->qtype) . '" ' .
                 'style="background:#fff;border-radius:14px;' .
                 'box-shadow:0 4px 24px rgba(0,0,0,0.10);overflow:hidden;margin-bottom:8px;">';

        // Question header strip: slot badge + type + marks
        $html .= '<div style="display:flex;align-items:center;justify-content:space-between;' .
                 'padding:10px 20px;border-bottom:1px solid #e9ecef;background:#f8f9fa;">';
        $html .= '<div style="display:flex;align-items:center;gap:10px;">' .
                 '<span style="display:inline-flex;align-items:center;justify-content:center;' .
                 'width:28px;height:28px;border-radius:50%;background:#0f6cbf;color:#fff;' .
                 'font-weight:800;font-size:13px;">' . $slotnum . '</span>' .
                 '<span style="font-size:12px;color:#6c757d;font-weight:600;letter-spacing:0.3px;">' .
                 strtoupper($typeLabel) . '</span>' .
                 '</div>';
        $html .= '<span style="font-size:12px;color:#6c757d;font-weight:600;">' .
                 $maxmark . ' ' . ($maxmark == 1.0 ? 'mark' : 'marks') . '</span>';
        $html .= '</div>';

        // Question body
        $html .= '<div style="padding:28px 32px;">';

        // Question text — apply nl2br for questions stored as plain text with newlines but no block HTML.
        $qtext = (string)($question->questiontext ?? '');
        if (($question->questiontextformat ?? FORMAT_HTML) === FORMAT_HTML
                && !preg_match('/<(p|div|br|table|ul|ol|h[1-6])\b/i', $qtext)) {
            $qtext = nl2br($qtext);
        }
        $html .= '<div class="lg-qtext" style="font-size:20px;line-height:1.75;color:#212529;' .
                 'margin-bottom:24px;font-weight:500;">';
        $html .= format_text($qtext, $question->questiontextformat ?? FORMAT_HTML);
        $html .= '</div>';

        // Answer block
        $html .= '<div class="lg-ablock">';
        switch ($question->qtype) {
            case 'multichoice': $html .= self::render_multichoice($answers, $slotnum, $saved, $review); break;
            case 'multicheck':  $html .= self::render_multicheck($answers, $slotnum, $saved, $review);  break;
            case 'truefalse':   $html .= self::render_truefalse($questionid, $slotnum, $saved, $review);break;
            case 'shortanswer': $html .= self::render_shortanswer($answers, $slotnum, $saved, $review, !empty($question->casesensitive)); break;
            case 'essay':          $html .= self::render_essay($slotnum, $saved, $review);                                   break;
            case 'choiceessay':    $html .= self::render_choiceessay($question, $slotnum, $saved, $review, $attemptid); break;
            case 'matching':       $html .= self::render_matching($answers, $slotnum, $saved, $review);         break;
            case 'audio_response': $html .= self::render_audio_response($slotnum, $saved, $review);             break;
            case 'file_upload':    $html .= self::render_file_upload($slotnum, $saved, $review);                break;
            default:               $html .= self::render_multichoice($answers, $slotnum, $saved, $review);
        }
        $html .= '</div>'; // ablock

        // General feedback (review only)
        if ($review && !empty($question->generalfeedback)) {
            $html .= '<div style="background:#e8f5e9;border-left:4px solid #28a745;' .
                'border-radius:0 4px 4px 0;padding:12px 16px;margin-top:16px;font-size:14px;">' .
                '<strong>Feedback:</strong> ' .
                format_text($question->generalfeedback, $question->generalfeedbackformat ?? FORMAT_HTML) .
                '</div>';
        }

        $html .= '</div>'; // body
        $html .= '</div>'; // card
        return $html;
    }

    // -----------------------------------------------------------------------
    // Individual renderers
    // -----------------------------------------------------------------------

    public static function render_multichoice(array $answers, int $slot, array $saved, bool $review): string {
        $field    = "q{$slot}_answer";
        $savedval = $saved[$field] ?? null;

        $html = '<div class="lg-mc-answers" style="display:flex;flex-direction:column;gap:8px;">';
        $i = 0;
        $letters = ['A','B','C','D','E','F','G','H'];
        foreach ($answers as $a) {
            $letter  = $letters[$i] ?? ($i + 1);
            $checked = ($savedval == $a->id);
            $correct = $review && $a->fraction > 0;
            $wrong   = $review && $checked && $a->fraction <= 0;
            $disabled = $review ? ' disabled' : '';

            // Colour states.
            if ($correct) {
                $bg = '#d1f5d1'; $border = '#28a745'; $lbg = '#28a745'; $lc = '#fff';
            } elseif ($wrong) {
                $bg = '#fde8e8'; $border = '#dc3545'; $lbg = '#dc3545'; $lc = '#fff';
            } elseif ($checked) {
                $bg = '#e8f0fe'; $border = '#0f6cbf'; $lbg = '#0f6cbf'; $lc = '#fff';
            } else {
                $bg = '#fff'; $border = '#dee2e6'; $lbg = '#f0f2f5'; $lc = '#6c757d';
            }

            $html .= '<label style="display:flex;align-items:center;gap:0;' .
                     'border:2px solid ' . $border . ';border-radius:10px;' .
                     'background:' . $bg . ';cursor:pointer;overflow:hidden;' .
                     'transition:border-color .15s,background .15s;">';
            // Letter badge
            $html .= '<span style="display:flex;align-items:center;justify-content:center;' .
                     'width:48px;min-height:52px;background:' . $lbg . ';color:' . $lc . ';' .
                     'font-weight:800;font-size:16px;flex-shrink:0;">' . $letter . '</span>';
            // Hidden radio
            $html .= '<input type="radio" name="' . s($field) . '" value="' . (int)$a->id . '"' .
                     ($checked ? ' checked' : '') . $disabled . ' style="display:none;">';
            // Answer text
            $html .= '<span style="flex:1;padding:13px 18px;font-size:17px;line-height:1.55;color:#212529;">' .
                     format_text($a->answertext, $a->answertextformat ?? FORMAT_HTML) . '</span>';
            // Review badge
            if ($review && $correct) { $html .= '<span style="padding:10px 12px;color:#28a745;font-size:18px;font-weight:700;">✓</span>'; }
            if ($review && $wrong)   { $html .= '<span style="padding:10px 12px;color:#dc3545;font-size:18px;font-weight:700;">✗</span>'; }
            $html .= '</label>';
            $i++;
        }
        $html .= '</div>';

        // Click-to-select: radio is hidden, full label card is the clickable element.
        // GREEN (#28a745) = selected to match the nav "Answered" indicator.
        if (!$review) {
            $html .= '<script>
(function(){
  var wrap = document.querySelector(".lg-mc-answers");
  if (!wrap) return;
  var labels = wrap.querySelectorAll("label");
  function resetAll() {
    labels.forEach(function(l){
      l.style.background="#fff"; l.style.borderColor="#dee2e6";
      var badge = l.querySelector("span:first-child");
      if (badge) { badge.style.background="#f0f2f5"; badge.style.color="#6c757d"; }
    });
  }
  labels.forEach(function(lbl){
    lbl.addEventListener("click", function(){
      var inp = lbl.querySelector("input[type=radio]");
      if (!inp) return;
      inp.checked = true;
      inp.dispatchEvent(new Event("change", {bubbles:true})); // trigger attachAnswerListeners
      resetAll();
      lbl.style.background="#d4edda"; lbl.style.borderColor="#28a745";
      var badge = lbl.querySelector("span:first-child");
      if (badge) { badge.style.background="#28a745"; badge.style.color="#fff"; }
    });
  });
  // Restore saved state on initial render (if page loaded with a pre-checked answer).
  labels.forEach(function(lbl){
    var inp = lbl.querySelector("input[type=radio]");
    if (inp && inp.checked) {
      lbl.style.background="#d4edda"; lbl.style.borderColor="#28a745";
      var badge = lbl.querySelector("span:first-child");
      if (badge) { badge.style.background="#28a745"; badge.style.color="#fff"; }
    }
  });
})();
</script>';
        }
        return $html;
    }

    public static function render_multicheck(array $answers, int $slot, array $saved, bool $review): string {
        $field    = "q{$slot}_answer";
        $savedval = isset($saved[$field]) ? (array)$saved[$field] : [];

        $html = '<p style="font-size:13px;color:#6c757d;margin:0 0 10px;font-style:italic;">Select all that apply.</p>';
        $html .= '<div class="lg-mc-check" style="display:flex;flex-direction:column;gap:8px;">';
        foreach ($answers as $a) {
            $name    = "q{$slot}_answer[{$a->id}]";
            $checked = in_array($a->id, $savedval);
            $correct = $review && $a->fraction > 0;
            $wrong   = $review && $checked && $a->fraction <= 0;
            $disabled = $review ? ' disabled' : '';

            if ($correct) { $bg = '#d1f5d1'; $border = '#28a745'; }
            elseif ($wrong) { $bg = '#fde8e8'; $border = '#dc3545'; }
            elseif ($checked) { $bg = '#e8f0fe'; $border = '#0f6cbf'; }
            else { $bg = '#fff'; $border = '#dee2e6'; }

            $html .= '<label style="display:flex;align-items:center;gap:14px;padding:13px 18px;' .
                     'border:2px solid ' . $border . ';border-radius:10px;background:' . $bg . ';cursor:pointer;">';
            $html .= '<input type="checkbox" name="' . s($name) . '" value="' . (int)$a->id . '"' .
                     ($checked ? ' checked' : '') . $disabled . ' style="width:20px;height:20px;flex-shrink:0;cursor:pointer;">';
            $html .= '<span style="flex:1;font-size:17px;line-height:1.55;">' .
                     format_text($a->answertext, $a->answertextformat ?? FORMAT_HTML) . '</span>';
            if ($review && $correct) { $html .= '<span style="color:#28a745;font-weight:700;font-size:16px;">✓</span>'; }
            if ($review && $wrong)   { $html .= '<span style="color:#dc3545;font-weight:700;font-size:16px;">✗</span>'; }
            $html .= '</label>';
        }
        $html .= '</div>';

        if (!$review) {
            $html .= '<script>
(function(){
  var wrap = document.querySelector(".lg-mc-check");
  if (!wrap) return;
  wrap.querySelectorAll("label").forEach(function(lbl){
    var upd = function(){
      var chk = lbl.querySelector("input[type=checkbox]");
      if (!chk) return;
      lbl.style.background  = chk.checked ? "#d4edda" : "#fff";
      lbl.style.borderColor = chk.checked ? "#28a745" : "#dee2e6";
    };
    lbl.querySelector("input[type=checkbox]").addEventListener("change", upd);
    upd();
  });
})();
</script>';
        }
        return $html;
    }

    public static function render_truefalse(int $qid, int $slot, array $saved, bool $review): string {
        global $DB;
        $field    = "q{$slot}_answer";
        $savedval = $saved[$field] ?? null;
        $answers  = $DB->get_records('learnguard_qbank_answers', ['questionid' => $qid], 'sortorder ASC');
        $correctval = '';
        foreach ($answers as $a) {
            if ($a->fraction >= 1.0) { $correctval = strtolower(trim($a->answertext)); }
        }

        $html = '<div class="lg-tf-wrap" style="display:flex;gap:12px;margin-top:4px;">';
        foreach (['true' => 'True', 'false' => 'False'] as $val => $label) {
            $checked  = ($savedval == $val);
            $correct  = $review && $correctval === $val;
            $wrong    = $review && $checked && $correctval !== $val;
            $disabled = $review ? ' disabled' : '';

            // Colour: green=selected, red=wrong review, green=correct review, white=unselected.
            if ($correct)   { $bg = '#d1f5d1'; $border = '#28a745'; $tc = '#155724'; $icon = '✓'; }
            elseif ($wrong) { $bg = '#fde8e8'; $border = '#dc3545'; $tc = '#721c24'; $icon = '✗'; }
            elseif ($checked && !$review) { $bg = '#d4edda'; $border = '#28a745'; $tc = '#155724'; $icon = ''; }
            else { $bg = '#fff'; $border = '#dee2e6'; $tc = '#495057'; $icon = ''; }

            $emoji = $val === 'true' ? '✅' : '❌';
            $html .= '<label data-val="' . $val . '" ' .
                     'style="flex:1;display:flex;align-items:center;justify-content:center;gap:10px;' .
                     'padding:22px 20px;border:2px solid ' . $border . ';border-radius:12px;' .
                     'background:' . $bg . ';cursor:' . ($review ? 'default' : 'pointer') . ';' .
                     'font-size:20px;font-weight:700;color:' . $tc . ';transition:all .15s;">';
            // Radio hidden — label is the full clickable area.
            $html .= '<input type="radio" name="' . s($field) . '" value="' . $val . '"' .
                     ($checked ? ' checked' : '') . $disabled . ' style="display:none;">';
            $html .= $emoji . ' ' . $label;
            if ($review && ($correct || $wrong)) { $html .= ' <span style="font-size:20px;">' . $icon . '</span>'; }
            $html .= '</label>';
        }
        $html .= '</div>';

        // Add visual feedback JS for true/false selection.
        if (!$review) {
            $html .= '<script>
(function(){
  var wrap = document.querySelector(".lg-tf-wrap");
  if (!wrap) return;
  var labels = wrap.querySelectorAll("label");
  function resetTF() {
    labels.forEach(function(l){
      l.style.background="#fff"; l.style.borderColor="#dee2e6"; l.style.color="#495057";
    });
  }
  labels.forEach(function(lbl){
    lbl.addEventListener("click", function(){
      var inp = lbl.querySelector("input[type=radio]");
      if (!inp) return;
      inp.checked = true;
      inp.dispatchEvent(new Event("change", {bubbles:true})); // trigger attachAnswerListeners
      resetTF();
      lbl.style.background="#d4edda"; lbl.style.borderColor="#28a745"; lbl.style.color="#155724";
    });
  });
  // Restore saved state on page load.
  labels.forEach(function(lbl){
    var inp = lbl.querySelector("input[type=radio]");
    if (inp && inp.checked) {
      lbl.style.background="#d4edda"; lbl.style.borderColor="#28a745"; lbl.style.color="#155724";
    }
  });
})();
</script>';
        }
        return $html;
    }

    public static function render_shortanswer(array $answers, int $slot, array $saved, bool $review, bool $caseSensitive = false): string {
        $field    = "q{$slot}_answer";
        $savedval = $saved[$field] ?? '';

        // Collect all accepted answer variants grouped by credit level.
        $fullVariants    = []; // fraction == 1.0 → full marks
        $partialVariants = []; // 0 < fraction < 1 → partial credit
        foreach ($answers as $a) {
            $frac = (float)$a->fraction;
            if ($frac >= 1.0) {
                $fullVariants[] = $a->answertext;
            } elseif ($frac > 0) {
                $partialVariants[] = [$a->answertext, $frac];
            }
        }

        $borderCol = '2px solid #dee2e6';
        if ($review && $savedval !== '') {
            $isRight = false;
            foreach ($answers as $a) {
                if ((float)$a->fraction > 0) {
                    $given   = trim($savedval);
                    $pattern = trim($a->answertext ?? '');
                    $match   = $caseSensitive
                        ? ($given === $pattern)
                        : (mb_strtolower($given) === mb_strtolower($pattern));
                    if ($match) { $isRight = (float)$a->fraction >= 1.0; break; }
                }
            }
            // Full match → green, any match at lower credit → orange, no match → red.
            $anyMatch = false;
            foreach ($answers as $a) {
                if ((float)$a->fraction > 0) {
                    $given   = trim($savedval);
                    $pattern = trim($a->answertext ?? '');
                    $match   = $caseSensitive
                        ? ($given === $pattern)
                        : (mb_strtolower($given) === mb_strtolower($pattern));
                    if ($match) { $anyMatch = true; break; }
                }
            }
            if ($isRight) {
                $borderCol = '2px solid #28a745';
            } elseif ($anyMatch) {
                $borderCol = '2px solid #fd7e14'; // partial credit
            } else {
                $borderCol = '2px solid #dc3545';
            }
        }

        $disabled = $review ? ' disabled' : '';
        $html = '<input type="text" name="' . s($field) . '" value="' . s($savedval) . '"' . $disabled .
                ' style="width:100%;padding:12px 16px;border:' . $borderCol . ';border-radius:8px;' .
                'font-size:16px;outline:none;box-sizing:border-box;" placeholder="Type your answer here…">';

        if ($review && !empty($fullVariants)) {
            $variantList = implode('</li><li style="padding:2px 0;">', array_map('s', $fullVariants));
            $html .= '<div style="margin-top:8px;padding:8px 12px;background:#d1f5d1;border-radius:6px;font-size:13px;color:#155724;">' .
                     '✓ <strong>Accepted answer' . (count($fullVariants) > 1 ? 's' : '') . ':</strong>' .
                     '<ul style="margin:4px 0 0 16px;padding:0;list-style:disc;">' .
                     '<li style="padding:2px 0;">' . $variantList . '</li></ul></div>';
        }
        if ($review && !empty($partialVariants)) {
            $partList = implode('', array_map(function($v) {
                return '<li style="padding:2px 0;">' . s($v[0]) . ' <span style="color:#856404;font-size:11px;">(' . round($v[1] * 100) . '% credit)</span></li>';
            }, $partialVariants));
            $html .= '<div style="margin-top:6px;padding:8px 12px;background:#fff3cd;border-radius:6px;font-size:13px;color:#856404;">' .
                     '◑ <strong>Partial credit answers:</strong><ul style="margin:4px 0 0 16px;padding:0;list-style:disc;">' . $partList . '</ul></div>';
        }
        return $html;
    }

    private static function render_essay(int $slot, array $saved, bool $review): string {
        $field    = "q{$slot}_answer";
        $savedval = $saved[$field] ?? '';
        $idp      = 'lg-essay-' . $slot;

        // The hidden textarea is what actually gets submitted (collectCurrentFormData()
        // in assessment.js reads real <textarea>/<input> elements — a contenteditable div
        // has no .value and would otherwise be silently skipped, losing the answer). Its
        // saved value may be OLD plain text (pre-formatting-feature answers) or NEW HTML —
        // either way it's handed to the visible box as-is below, and old plain text with no
        // real tags renders identically whether read as text or as HTML.
        $html  = '<textarea name="' . s($field) . '" id="' . $idp . '-hid" style="display:none;">' .
                 s($savedval) . '</textarea>';
        $html .= self::render_richtext_box($idp, $savedval, $review);
        $html .= '<div style="display:flex;align-items:center;justify-content:space-between;gap:6px;margin-top:8px;' .
                 'font-size:12px;color:#6c757d;flex-wrap:wrap;">' .
                 '<span><span style="background:#fff3cd;border:1px solid #ffc107;border-radius:4px;' .
                 'padding:2px 8px;font-size:11px;font-weight:700;color:#856404;">ESSAY</span>' .
                 ' This response will be reviewed and marked by your instructor.</span>' .
                 '<span id="' . $idp . '-wc">0 words</span></div>';
        if (!$review) {
            $html .= self::render_richtext_sync_script($idp, $idp . '-hid', null);
        }
        return $html;
    }

    // Shared toolbar + contenteditable box used by both plain Essay and Choice Essay's
    // answer input — a lightweight rich-text editor via document.execCommand() rather than
    // pulling in a full editor library, consistent with the "no CDN" standing decision.
    // $savedHtml may be old plain text OR new HTML from a prior save; both render fine
    // dropped straight into innerHTML (old plain text has no real tags to misinterpret).
    private static function render_richtext_box(string $idPrefix, string $savedHtml, bool $review): string {
        $toolbar = '';
        if (!$review) {
            $btns = [
                ['bold', '<b>B</b>', 'Bold'],
                ['italic', '<i>I</i>', 'Italic'],
                ['underline', '<u>U</u>', 'Underline'],
                ['insertUnorderedList', '• List', 'Bullet list'],
                ['insertOrderedList', '1. List', 'Numbered list'],
            ];
            $toolbar = '<div style="display:flex;gap:4px;margin-bottom:6px;flex-wrap:wrap;">';
            foreach ($btns as [$cmd, $label, $title]) {
                $toolbar .= '<button type="button" class="lg-rte-btn" data-cmd="' . $cmd . '" ' .
                    'data-target="' . $idPrefix . '-ce" title="' . s($title) . '" ' .
                    'style="background:#fff;border:1px solid #dee2e6;border-radius:4px;padding:5px 10px;' .
                    'font-size:12px;cursor:pointer;min-width:32px;">' . $label . '</button>';
            }
            $toolbar .= '</div>';
        }
        $editAttr = $review ? 'false' : 'true';
        // render_essay_answer_html() handles both an OLD plain-text saved value (escapes it,
        // converts newlines to <br>) and a NEW rich-text HTML value (passed through as-is) —
        // inserting $savedHtml raw here would break on any old answer containing a bare
        // '<', '>' or '&' (e.g. "5 < 10 was my point"), misinterpreting it as a tag.
        // min-height keeps it from looking cramped when empty; max-height + overflow-y is
        // the actual fix — without a cap, a contenteditable div (unlike a <textarea>, which
        // scrolls internally once content exceeds its rows) just keeps growing taller
        // forever as the student types, pushing the rest of the page down instead of
        // staying put with its own scrollbar. resize:vertical still lets the student drag
        // it taller manually if they want more room, same as the old textarea allowed.
        return $toolbar .
            '<div id="' . $idPrefix . '-ce" contenteditable="' . $editAttr . '" ' .
            'style="width:100%;min-height:180px;max-height:340px;overflow-y:auto;overflow-x:hidden;' .
            'resize:vertical;padding:14px 16px;border:2px solid #dee2e6;border-radius:8px;' .
            'font-size:15px;line-height:1.6;box-sizing:border-box;font-family:system-ui,sans-serif;' .
            'overflow-wrap:break-word;' . ($review ? 'background:#f8f9fa;' : '') . '"' .
            ($review ? '' : ' data-placeholder="Write your response here…"') . '>' .
            self::render_essay_answer_html($savedHtml) . '</div>' .
            ($review ? '' : '<style>#' . $idPrefix . '-ce:empty:before{content:attr(data-placeholder);color:#adb5bd;}</style>');
    }

    // Wires up one rich-text box: toolbar buttons -> execCommand on the focused editor,
    // input -> syncs innerHTML into $hiddenId (a real form field) + updates the word count,
    // and $onChosen (choiceessay only) fires additionally so its own JSON sync can run too.
    private static function render_richtext_sync_script(string $idPrefix, ?string $hiddenId, ?string $onChosenCallback): string {
        $ceId = $idPrefix . '-ce';
        $wcId = $idPrefix . '-wc';
        $hiddenSync = $hiddenId ? 'var h=document.getElementById("' . $hiddenId . '");if(h){h.value=ce.innerHTML;}' : '';
        $extra = $onChosenCallback ?? '';
        return '<script>(function(){' .
            'var ce=document.getElementById("' . $ceId . '");' .
            'var wc=document.getElementById("' . $wcId . '");' .
            'if(!ce){return;}' .
            'function upd(){' .
            $hiddenSync .
            'if(wc){var t=(ce.textContent||"").trim();var words=t?t.split(/\s+/).length:0;' .
            'wc.textContent=words+" word"+(words===1?"":"s")+" · "+t.length+" character"+(t.length===1?"":"s");}' .
            $extra .
            '}' .
            'ce.addEventListener("input",upd);' .
            'document.querySelectorAll(\'.lg-rte-btn[data-target="' . $ceId . '"]\').forEach(function(b){' .
            'b.addEventListener("click",function(){' .
            'ce.focus();try{document.execCommand(b.dataset.cmd,false,null);}catch(e){}upd();' .
            '});});' .
            'upd();' .
            '})();</script>';
    }

    private static function render_choiceessay(
        \stdClass $question, int $slot, array $saved, bool $review, int $attemptid
    ): string {
        global $DB;

        $field     = "q{$slot}_answer";
        $savedJson = $saved[$field] ?? '';
        $savedData = [];
        if ($savedJson !== '') {
            $dec = json_decode($savedJson, true);
            if (is_array($dec)) { $savedData = $dec; }
        }
        $chosenPromptId = (int)($savedData['promptid'] ?? 0);
        $savedText      = (string)($savedData['text'] ?? '');
        $storedShown    = $savedData['shown'] ?? [];

        $allPrompts = array_values($DB->get_records(
            'learnguard_ce_prompts', ['questionid' => $question->id], 'sortorder ASC'
        ));

        if (empty($allPrompts)) {
            return '<p style="color:#6c757d;font-style:italic;">(No prompts configured for this question.)</p>';
        }

        // Determine which prompts to show this student.
        $showCount    = (int)($question->show_prompts_count ?? 0);
        $promptsToShow = $allPrompts;

        if ($showCount > 0 && count($allPrompts) > $showCount) {
            // Re-derive shown prompts from seed (reproducible per attempt+question).
            if (!empty($storedShown) && count($storedShown) === $showCount) {
                $byId = [];
                foreach ($allPrompts as $p) { $byId[$p->id] = $p; }
                $resolved = array_values(array_filter(
                    array_map(fn($id) => $byId[$id] ?? null, $storedShown)
                ));
                if (count($resolved) === $showCount) { $promptsToShow = $resolved; }
                else { $promptsToShow = self::select_prompts_seeded($allPrompts, $showCount, $attemptid, (int)$question->id); }
            } else {
                $promptsToShow = self::select_prompts_seeded($allPrompts, $showCount, $attemptid, (int)$question->id);
            }
        }

        if ($review) {
            return self::render_choiceessay_review($promptsToShow, $chosenPromptId, $savedText);
        }

        // Compute the shown IDs to embed in the saved JSON.
        $shownIds = array_map(fn($p) => (int)$p->id, $promptsToShow);
        // Chosen prompt HTML for the essay view.
        $chosenHtml = '';
        if ($chosenPromptId > 0) {
            foreach ($promptsToShow as $p) {
                if ((int)$p->id === $chosenPromptId) {
                    $chosenHtml = format_text($p->prompttext, $p->prompttextformat ?? FORMAT_HTML);
                    break;
                }
            }
        }
        $showList = ($chosenPromptId === 0);

        // Prompts JSON for client-side switching.
        $promptsJs = json_encode(array_map(fn($p) => [
            'id'   => (int)$p->id,
            'html' => format_text($p->prompttext, $p->prompttextformat ?? FORMAT_HTML),
        ], $promptsToShow), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

        // Initial hidden-input JSON (includes shown IDs for grader/reconnect).
        $initJson = htmlspecialchars(json_encode([
            'promptid' => $chosenPromptId,
            'text'     => $savedText,
            'shown'    => array_values($shownIds),
        ]), ENT_QUOTES);

        $h  = '';
        $h .= '<input type="hidden" name="' . s($field) . '" id="lg-ce-hid-' . $slot . '" value="' . $initJson . '">';

        // ── Prompt list view ─────────────────────────────────────────────────
        // Admin-editable per-question (questionedit.php) — empty/unset falls back to this
        // same default text, so an already-created Choice Essay question with nothing
        // saved in ce_instructions yet behaves exactly as it always has.
        $ceInstr = trim((string)($question->ce_instructions ?? ''));
        if ($ceInstr === '') {
            $ceInstr = 'Read all questions below, then choose <strong>ONE</strong> to answer:';
        }
        $h .= '<div id="lg-ce-pl-' . $slot . '" style="display:' . ($showList ? 'block' : 'none') . ';">';
        $h .= '<p style="font-size:14px;color:#6c757d;margin-bottom:16px;font-style:italic;">'
            . format_text($ceInstr, FORMAT_HTML) . '</p>';
        foreach ($promptsToShow as $idx => $p) {
            $h .= '<div data-pid="' . (int)$p->id . '" style="border:2px solid #dee2e6;border-radius:10px;'
                . 'padding:20px;margin-bottom:14px;background:#fafafa;">';
            $h .= '<div style="font-size:11px;color:#6c757d;font-weight:700;letter-spacing:0.5px;'
                . 'margin-bottom:10px;text-transform:uppercase;">Question ' . ($idx + 1) . '</div>';
            $h .= '<div style="font-size:16px;line-height:1.7;color:#212529;margin-bottom:16px;">'
                . format_text($p->prompttext, $p->prompttextformat ?? FORMAT_HTML) . '</div>';
            $h .= '<button type="button" class="lg-ce-choose" data-slot="' . $slot . '" data-pid="' . (int)$p->id . '" '
                . 'style="background:#0f6cbf;color:#fff;border:none;border-radius:6px;'
                . 'padding:9px 20px;font-size:13px;font-weight:700;cursor:pointer;">'
                . 'Choose this question &rarr;</button>';
            $h .= '</div>';
        }
        $h .= '</div>';

        // ── Essay view ───────────────────────────────────────────────────────
        $h .= '<div id="lg-ce-ev-' . $slot . '" style="display:' . ($showList ? 'none' : 'block') . ';">';
        $h .= '<button type="button" class="lg-ce-back" data-slot="' . $slot . '" '
            . 'style="background:none;border:none;color:#0f6cbf;cursor:pointer;font-size:13px;'
            . 'padding:0;margin-bottom:16px;text-decoration:underline;">&larr; Back to question list</button>';
        $h .= '<div id="lg-ce-cp-' . $slot . '" '
            . 'style="background:#e8f0fe;border-left:4px solid #0f6cbf;border-radius:0 6px 6px 0;'
            . 'padding:16px 20px;margin-bottom:16px;font-size:15px;line-height:1.7;">'
            . ($chosenHtml !== '' ? $chosenHtml : '<em style="color:#6c757d;">No question selected.</em>') . '</div>';
        $ceIdp = 'lg-ce-rte-' . $slot;
        $h .= self::render_richtext_box($ceIdp, $savedText, $review);
        $h .= '<div style="display:flex;align-items:center;justify-content:space-between;gap:6px;margin-top:8px;' .
              'font-size:12px;color:#6c757d;flex-wrap:wrap;">' .
              '<span><span style="background:#fff3cd;border:1px solid #ffc107;border-radius:4px;' .
              'padding:2px 8px;font-size:11px;font-weight:700;color:#856404;">CHOICE ESSAY</span>' .
              ' Your response will be reviewed and marked by your instructor.</span>' .
              '<span id="' . $ceIdp . '-wc">0 words</span></div>';
        $h .= '</div>';

        // ── Interaction script ───────────────────────────────────────────────
        // ss({text: ce.innerHTML}) is the sync callback passed to
        // render_richtext_sync_script() below — it writes into the SAME hidden JSON field
        // (lg-ce-hid-{slot}) the prompt-choice logic already uses, rather than a separate
        // hidden textarea, since choiceessay's whole answer (chosen prompt + text) is one
        // JSON blob submitted together.
        $h .= '<script>
(function(){
  var s=' . $slot . ',P=' . $promptsJs . ';
  var hid=document.getElementById("lg-ce-hid-"+s);
  var pl =document.getElementById("lg-ce-pl-"+s);
  var ev =document.getElementById("lg-ce-ev-"+s);
  var cp =document.getElementById("lg-ce-cp-"+s);
  var ce =document.getElementById("' . $ceIdp . '-ce");
  function gs(){try{return JSON.parse(hid.value||"{}");}catch(e){return{};}}
  function ss(patch){var d=gs();Object.assign(d,patch);hid.value=JSON.stringify(d);}
  window["lgCeSync' . $slot . '"]=function(){ if(ce){ ss({text:ce.innerHTML}); } };
  if(pl){pl.querySelectorAll(".lg-ce-choose").forEach(function(b){
    b.addEventListener("click",function(){
      var pid=parseInt(this.dataset.pid,10);
      var pm=P.find(function(p){return p.id===pid;});
      ss({promptid:pid});
      if(pm&&cp){cp.innerHTML=pm.html;}
      pl.style.display="none"; ev.style.display="block";
      if(ce){ce.focus();}
    });
  });}
  if(ev){var bb=ev.querySelector(".lg-ce-back");
    if(bb){bb.addEventListener("click",function(){ev.style.display="none";pl.style.display="block";});}}
})();
</script>';
        if (!$review) {
            $h .= self::render_richtext_sync_script($ceIdp, null, 'if(window["lgCeSync' . $slot . '"]){window["lgCeSync' . $slot . '"]();}');
        }
        return $h;
    }

    private static function select_prompts_seeded(array $prompts, int $count, int $attemptid, int $questionid): array {
        $n = count($prompts);
        $indices = range(0, $n - 1);
        // Minimal LCG seeded by attemptid + questionid — no srand so it doesn't pollute global state.
        $seed = abs($attemptid * 997 + $questionid * 31) | 1;
        for ($i = $n - 1; $i > 0; $i--) {
            $seed = ($seed * 1103515245 + 12345) & 0x7FFFFFFF;
            $j    = $seed % ($i + 1);
            [$indices[$i], $indices[$j]] = [$indices[$j], $indices[$i]];
        }
        $sel = array_slice($indices, 0, $count);
        sort($sel);
        return array_map(fn($idx) => $prompts[$idx], $sel);
    }

    private static function render_choiceessay_review(array $prompts, int $chosenId, string $savedText): string {
        $h  = '<p style="font-size:12px;color:#6c757d;font-weight:700;margin-bottom:10px;'
            . 'letter-spacing:0.5px;text-transform:uppercase;">Questions shown to this student:</p>';
        foreach ($prompts as $idx => $p) {
            $chosen = ((int)$p->id === $chosenId);
            $border = $chosen ? '#0f6cbf' : '#dee2e6';
            $bg     = $chosen ? '#e8f0fe' : '#f8f9fa';
            $h .= '<div style="border:2px solid ' . $border . ';border-radius:8px;padding:16px;'
                . 'margin-bottom:10px;background:' . $bg . ';">';
            $h .= '<div style="font-size:11px;font-weight:700;color:#6c757d;margin-bottom:8px;">'
                . 'QUESTION ' . ($idx + 1)
                . ($chosen ? ' &nbsp;<span style="color:#0f6cbf;font-size:12px;">&larr; Student chose this</span>' : '')
                . '</div>';
            $h .= '<div style="font-size:15px;line-height:1.65;">'
                . format_text($p->prompttext, $p->prompttextformat ?? FORMAT_HTML) . '</div>';
            $h .= '</div>';
        }
        $h .= '<div style="margin-top:16px;">';
        $h .= '<p style="font-size:12px;font-weight:700;color:#6c757d;margin-bottom:8px;'
            . 'letter-spacing:0.5px;text-transform:uppercase;">Student response:</p>';
        if (trim($savedText) === '') {
            $h .= '<p style="color:#6c757d;font-style:italic;">No response submitted.</p>';
        } else {
            $h .= '<div style="background:#f8f9fa;border:1px solid #dee2e6;border-radius:8px;'
                . 'padding:16px;font-size:15px;line-height:1.7;">'
                . self::render_essay_answer_html($savedText) . '</div>';
        }
        $h .= '</div>';
        return $h;
    }

    /**
     * Safely renders a stored essay/choiceessay answer for display, whether it's an OLD
     * plain-text answer (from before the rich-text editor existed — raw newlines, no real
     * HTML tags) or a NEW rich-text answer (real HTML from the contenteditable box).
     * Every place that displays a saved essay answer to a human should go through this,
     * not a raw s()/htmlspecialchars call, or new-format answers show literal tag text.
     */
    public static function render_essay_answer_html(string $raw): string {
        if ($raw === strip_tags($raw)) {
            // No real HTML tags present — treat as the old plain-text format.
            return nl2br(s($raw));
        }
        return format_text($raw, FORMAT_HTML, ['noclean' => false]);
    }

    private static function render_audio_response(int $slot, array $saved, bool $review): string {
        $field    = "q{$slot}_audio";
        $savedval = $saved[$field] ?? '';

        $html = '<div style="text-align:center;">';
        if ($review) {
            if ($savedval) {
                $html .= '<audio controls src="data:audio/webm;base64,' . $savedval . '" style="width:100%;max-width:400px;margin:10px auto;display:block;"></audio>';
                $html .= '<p style="font-size:13px;color:#28a745;">✓ Audio response recorded.</p>';
            } else {
                $html .= '<p style="color:#6c757d;">No audio response submitted.</p>';
            }
            return $html . '</div>';
        }

        $html .= '<p style="color:#6c757d;margin-bottom:12px;">Record your spoken response below. Click Start to begin.</p>';
        $html .= '<div id="lg-audio-rec-' . $slot . '">';
        $html .= '<button id="lg-ar-start-' . $slot . '" type="button" ' .
                 'style="background:#dc3545;color:#fff;border:none;border-radius:8px;padding:12px 28px;' .
                 'font-size:15px;cursor:pointer;font-weight:700;">🎙 Start Recording</button> ';
        $html .= '<button id="lg-ar-stop-' . $slot . '" type="button" disabled ' .
                 'style="background:#6c757d;color:#fff;border:none;border-radius:8px;padding:12px 28px;' .
                 'font-size:15px;cursor:pointer;">⏹ Stop</button>';
        $html .= '<div id="lg-ar-status-' . $slot . '" style="margin-top:8px;font-size:13px;color:#6c757d;">Not recording.</div>';
        $html .= '<audio id="lg-ar-playback-' . $slot . '" controls style="display:none;width:100%;max-width:400px;margin-top:10px;"></audio>';
        $html .= '<input type="hidden" name="' . s($field) . '" id="lg-ar-data-' . $slot . '">';
        $html .= '</div>';
        $html .= '<script>
(function(){
  var slot = ' . $slot . ';
  var startBtn  = document.getElementById("lg-ar-start-" + slot);
  var stopBtn   = document.getElementById("lg-ar-stop-" + slot);
  var statusEl  = document.getElementById("lg-ar-status-" + slot);
  var playback  = document.getElementById("lg-ar-playback-" + slot);
  var dataInput = document.getElementById("lg-ar-data-" + slot);
  var recorder  = null; var chunks = [];
  if (!startBtn) return;
  startBtn.addEventListener("click", function(){
    navigator.mediaDevices.getUserMedia({audio:true}).then(function(stream){
      chunks = [];
      recorder = new MediaRecorder(stream);
      recorder.ondataavailable = function(e){ if (e.data.size > 0) chunks.push(e.data); };
      recorder.onstop = function(){
        var blob = new Blob(chunks, {type:"audio/webm"});
        playback.src = URL.createObjectURL(blob);
        playback.style.display = "block";
        var reader = new FileReader();
        reader.onloadend = function(){
          var b64 = reader.result.split(",")[1];
          if (dataInput) dataInput.value = b64;
          if (statusEl) statusEl.textContent = "✓ Recording saved. Review above.";
        };
        reader.readAsDataURL(blob);
      };
      recorder.start(1000);
      startBtn.disabled = true; stopBtn.disabled = false;
      if (statusEl) statusEl.innerHTML = "<span style=\"color:#dc3545;\">🔴 Recording…</span>";
    }).catch(function(){ if (statusEl) statusEl.textContent = "Mic access denied."; });
  });
  stopBtn.addEventListener("click", function(){
    if (recorder && recorder.state !== "inactive") recorder.stop();
    startBtn.disabled = false; stopBtn.disabled = true;
  });
})();
</script>';
        return $html . '</div>';
    }

    private static function render_file_upload(int $slot, array $saved, bool $review): string {
        $field    = "q{$slot}_file";
        $savedval = $saved[$field] ?? '';

        if ($review) {
            return $savedval
                ? '<div style="background:#d4edda;border-radius:6px;padding:10px 14px;font-size:13px;color:#155724;">✓ File submitted: ' . s($savedval) . '</div>'
                : '<p style="color:#6c757d;">No file uploaded.</p>';
        }

        $html  = '<p style="color:#6c757d;font-size:13px;margin-bottom:8px;">Upload your answer file. Allowed: PDF, DOCX, TXT, PNG, JPG (max 10 MB).</p>';
        $html .= '<input type="file" name="' . s($field) . '" id="lg-file-' . $slot . '" ' .
                 'accept=".pdf,.docx,.txt,.png,.jpg,.jpeg" ' .
                 'style="display:block;width:100%;padding:8px;border:2px dashed #dee2e6;border-radius:8px;' .
                 'background:#f8f9fa;cursor:pointer;">';
        $html .= '<div id="lg-file-status-' . $slot . '" style="font-size:12px;color:#6c757d;margin-top:6px;"></div>';
        $html .= '<script>
document.getElementById("lg-file-' . $slot . '").addEventListener("change", function(){
  var f = this.files[0];
  var st = document.getElementById("lg-file-status-' . $slot . '");
  if (!f) return;
  if (f.size > 10 * 1024 * 1024) {
    if (st) st.innerHTML = "<span style=\'color:#dc3545;\'>File too large (max 10 MB).</span>";
    this.value = ""; return;
  }
  if (st) st.innerHTML = "✓ Selected: " + f.name + " (" + Math.round(f.size/1024) + " KB)";
});
</script>';
        return $html;
    }

    private static function render_matching(array $answers, int $slot, array $saved, bool $review): string {
        $lefts  = [];
        $rights = [];
        foreach ($answers as $a) {
            if ($a->fraction < 0) { $lefts[$a->id]  = $a->answertext; }
            else                   { $rights[$a->id] = $a->answertext; }
        }

        $html = '<div style="display:flex;flex-direction:column;gap:10px;">';
        foreach ($lefts as $lid => $ltext) {
            $field    = "q{$slot}_match_{$lid}";
            $savedval = $saved[$field] ?? '';
            $disabled = $review ? ' disabled' : '';

            $html .= '<div style="display:flex;align-items:center;gap:10px;">';
            $html .= '<div style="flex:1;padding:10px 14px;background:#f8f9fa;border:1px solid #e9ecef;' .
                     'border-radius:8px;font-size:14px;">' . format_text($ltext, FORMAT_HTML) . '</div>';
            $html .= '<span style="color:#adb5bd;font-size:18px;">→</span>';
            $html .= '<select name="' . s($field) . '"' . $disabled .
                     ' style="flex:1;padding:10px 12px;border:2px solid #dee2e6;border-radius:8px;' .
                     'font-size:14px;background:#fff;">';
            $html .= '<option value="">— Select —</option>';
            foreach ($rights as $rid => $rtext) {
                $selected = ($savedval == $rid) ? ' selected' : '';
                $html .= '<option value="' . (int)$rid . '"' . $selected . '>' .
                         s(strip_tags($rtext)) . '</option>';
            }
            $html .= '</select>';
            $html .= '</div>';
        }
        $html .= '</div>';
        return $html;
    }

    // -----------------------------------------------------------------------
    // Grading
    // -----------------------------------------------------------------------

    public static function grade_response(int $questionid, int $slotnum, array $responsedata): array {
        global $DB;

        $question = $DB->get_record('learnguard_qbank', ['id' => $questionid]);
        if (!$question) { return [0.0, 'noresponse']; }

        $field    = "q{$slotnum}_answer";
        $response = $responsedata[$field] ?? null;

        if ($response === null || $response === '') {
            if ($question->qtype !== 'matching') { return [0.0, 'noresponse']; }
        }

        switch ($question->qtype) {

            case 'multichoice':
                $answer = $DB->get_record('learnguard_qbank_answers',
                    ['id' => (int)$response, 'questionid' => $questionid]);
                return $answer ? [(float)$answer->fraction, 'complete'] : [0.0, 'complete'];

            case 'multicheck':
                $selected = is_array($response) ? $response : (array)json_decode($response ?? '[]');
                $answers  = $DB->get_records('learnguard_qbank_answers', ['questionid' => $questionid]);
                $totalCorrect = 0; $totalPossible = 0; $penalty = 0;
                foreach ($answers as $a) {
                    if ($a->fraction > 0) { $totalPossible++; }
                    if (in_array($a->id, $selected)) {
                        $totalCorrect += ($a->fraction > 0) ? 1 : 0;
                        $penalty      += ($a->fraction <= 0) ? 1 : 0;
                    }
                }
                if ($totalPossible == 0) { return [0.0, 'complete']; }
                return [(float)max(0.0, ($totalCorrect - $penalty) / $totalPossible), 'complete'];

            case 'truefalse':
                $answers = $DB->get_records('learnguard_qbank_answers', ['questionid' => $questionid]);
                foreach ($answers as $a) {
                    if ($a->fraction >= 1.0 &&
                        strtolower(trim($a->answertext ?? '')) === strtolower(trim($response ?? ''))) {
                        return [1.0, 'complete'];
                    }
                }
                return [0.0, 'complete'];

            case 'shortanswer':
                $answers = $DB->get_records('learnguard_qbank_answers',
                    ['questionid' => $questionid], 'fraction DESC');
                $caseSensitive = !empty($question->casesensitive);
                foreach ($answers as $a) {
                    $pattern = trim($a->answertext ?? '');
                    $given   = trim($response ?? '');
                    if ($pattern === '') { continue; }
                    $match = $caseSensitive
                        ? ($given === $pattern)
                        : (mb_strtolower($given) === mb_strtolower($pattern));
                    if ($match) { return [(float)$a->fraction, 'complete']; }
                    if (strpos($pattern, '*') !== false) {
                        $flags = $caseSensitive ? '/^%s$/u' : '/^%s$/iu';
                        $regex = sprintf($flags, str_replace('\*', '.*', preg_quote($pattern, '/')));
                        if (preg_match($regex, $given)) { return [(float)$a->fraction, 'complete']; }
                    }
                }
                return [0.0, 'complete'];

            case 'essay':
                // Fraction stays 0 until a grader assigns a mark.
                return [0.0, 'needsgrading'];

            case 'choiceessay': {
                $data = json_decode($response ?? '{}', true);
                $text = is_array($data) ? (string)($data['text'] ?? '') : '';
                return [0.0, !self::is_essay_text_blank($text) ? 'needsgrading' : 'noresponse'];
            }

            case 'matching':
                $answers = $DB->get_records('learnguard_qbank_answers', ['questionid' => $questionid]);
                $lefts   = [];
                $correct = 0; $total = 0;
                foreach ($answers as $a) {
                    if ($a->fraction < 0) { $lefts[$a->id] = $a; }
                }
                foreach ($lefts as $lid => $left) {
                    $fieldm   = "q{$slotnum}_match_{$lid}";
                    $selected = (int)($responsedata[$fieldm] ?? 0);
                    $total++;
                    $ans = $DB->get_record('learnguard_qbank_answers', ['id' => $selected, 'questionid' => $questionid]);
                    if ($ans && $ans->fraction >= 1.0) { $correct++; }
                }
                return $total > 0 ? [(float)round($correct / $total, 5), 'complete'] : [0.0, 'complete'];

            default:
                return [0.0, 'complete'];
        }
    }

    // -----------------------------------------------------------------------
    // Check if a response has actual content (not empty).
    // Used to decide "answered" vs "unattempted" in the nav grid.
    // -----------------------------------------------------------------------
    public static function has_response(int $slotnum, string $qtype, array $responsedata): bool {
        $field    = "q{$slotnum}_answer";
        $response = $responsedata[$field] ?? null;

        if ($qtype === 'matching') {
            // Matching: at least one select must have a value.
            foreach ($responsedata as $k => $v) {
                if (strpos($k, "q{$slotnum}_match_") === 0 && !empty($v)) { return true; }
            }
            return false;
        }
        if ($qtype === 'multicheck') {
            return !empty($response) && (is_array($response) ? count($response) > 0 : $response !== '');
        }
        if ($qtype === 'choiceessay') {
            if (!$response) { return false; }
            $data = json_decode($response, true);
            return is_array($data) && !self::is_essay_text_blank((string)($data['text'] ?? ''));
        }
        if ($qtype === 'essay') {
            return $response !== null && !self::is_essay_text_blank((string)$response);
        }
        return $response !== null && $response !== '';
    }

    /**
     * A rich-text essay/choiceessay answer is "blank" when it has no real text content,
     * even if the underlying HTML isn't literally an empty string — an emptied
     * contenteditable box commonly leaves behind a stray "<br>" or "<p><br></p>" rather
     * than "" once a student has typed and then deleted everything, which a plain
     * trim($html) !== '' check would wrongly count as "answered".
     */
    public static function is_essay_text_blank(string $html): bool {
        return trim(strip_tags($html)) === '';
    }

    // -----------------------------------------------------------------------
    // Response persistence
    // -----------------------------------------------------------------------

    /**
     * @param int $attemptslot  The 1-based slot position in the student's attempt
     *                          (from learnguard_attempt_questions.slotnum).
     *                          Pass 0 to fall back to the bank slot number (legacy).
     */
    public static function save_response(
        int $attemptid, int $slotid, int $questionid, array $responsedata, bool $grade = true, int $attemptslot = 0
    ): \stdClass {
        global $DB;

        $existing = $DB->get_record('learnguard_responses', ['attemptid' => $attemptid, 'slotid' => $slotid]);
        // Use the attempt slot number (position in THIS student's attempt) for field-name resolution.
        // Falling back to the bank slot is wrong for randomised assessments where bank slot ≠ attempt slot.
        $slotnum  = $attemptslot > 0
            ? $attemptslot
            : (int)$DB->get_field('learnguard_slots', 'slot', ['id' => $slotid]);
        $question = $DB->get_record('learnguard_qbank', ['id' => $questionid]);
        $encoded  = json_encode($responsedata);

        [$fraction, $state] = $grade
            ? self::grade_response($questionid, $slotnum, $responsedata)
            : [0.0, 'todo'];

        // If no actual content and grading, mark as noresponse not complete.
        if ($grade && $state !== 'needsgrading' && $question &&
            !self::has_response($slotnum, $question->qtype, $responsedata)) {
            $state = 'noresponse';
        }

        // PROTECTION: For choice questions (MCQ / multicheck / T/F), a student cannot
        // "unselect" an option — they can only switch to a different one. If the incoming
        // submission has no answer content BUT the existing row already holds a confirmed
        // answer, this is a spurious empty save (e.g. form container showed a loading
        // spinner during rapid navigation). Reject it so real answers are never lost.
        if ($existing && $grade && $question &&
            in_array($question->qtype, ['multichoice', 'multicheck', 'truefalse']) &&
            !in_array($existing->state, ['todo', 'noresponse']) &&
            !self::has_response($slotnum, $question->qtype, $responsedata)
        ) {
            return $existing;
        }

        if ($existing) {
            // Log the previous state to history before overwriting, so answers can
            // always be recovered. Only log when the row has real saved content.
            if ($grade && !in_array($existing->state, ['todo', 'noresponse'])) {
                $DB->insert_record('learnguard_response_history', (object)[
                    'responseid'   => (int)$existing->id,
                    'attemptid'    => (int)$existing->attemptid,
                    'slotid'       => (int)$existing->slotid,
                    'questionid'   => (int)$existing->questionid,
                    'responsedata' => $existing->responsedata,
                    'fraction'     => (float)$existing->fraction,
                    'state'        => (string)$existing->state,
                    'savedbefore'  => (int)$existing->timemodified,
                    'timecreated'  => time(),
                ]);
            }
            $existing->responsedata = $encoded;
            $existing->fraction     = $grade ? $fraction : $existing->fraction;
            $existing->state        = $grade ? $state    : $existing->state;
            $existing->timemodified = time();
            $DB->update_record('learnguard_responses', $existing);
            return $existing;
        }

        $record = (object)[
            'attemptid'    => $attemptid,
            'slotid'       => $slotid,
            'questionid'   => $questionid,
            'responsedata' => $encoded,
            'fraction'     => $grade ? $fraction : 0.0,
            'state'        => $grade ? $state    : 'todo',
            'timecreated'  => time(),
            'timemodified' => time(),
        ];
        $record->id = $DB->insert_record('learnguard_responses', $record);
        return $record;
    }

    public static function get_saved_response(int $attemptid, int $slotid): array {
        global $DB;
        $record = $DB->get_record('learnguard_responses', ['attemptid' => $attemptid, 'slotid' => $slotid]);
        if (!$record || empty($record->responsedata)) { return []; }
        $decoded = json_decode($record->responsedata, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Returns true when the activity has essay questions AND at least one of this
     * attempt's assigned essay slots has not yet been manually graded.
     * A score must not be published until this returns false.
     */
    public static function has_ungraded_essays(int $attemptid, int $learnguardid): bool {
        global $DB;
        // Check whether the activity has any essay-type slots at all.
        // Choice Essay is manually graded exactly like a plain Essay (one written answer
        // per attempt, keyed to whichever prompt the student chose) — it must block score
        // publication the same way until graded, or a score could be pushed to the
        // gradebook while a Choice Essay answer still sits ungraded.
        $essaySlotIds = $DB->get_fieldset_sql(
            "SELECT s.id FROM {learnguard_slots} s
               JOIN {learnguard_qbank} q ON q.id = s.questionid
              WHERE s.learnguard = :lgid AND q.qtype IN ('essay', 'choiceessay')",
            ['lgid' => $learnguardid]
        );
        if (empty($essaySlotIds)) {
            return false;
        }
        // Find the essay slots actually assigned to this attempt.
        $aqSlotIds    = $DB->get_fieldset_select(
            'learnguard_attempt_questions', 'slotid', 'attemptid = :aid', ['aid' => $attemptid]
        );
        $hasAqRecords = !empty($aqSlotIds);
        if (!$hasAqRecords) {
            // Legacy attempt (no AQ records) — fall back to response rows.
            $aqSlotIds = $DB->get_fieldset_select(
                'learnguard_responses', 'slotid', 'attemptid = :aid', ['aid' => $attemptid]
            );
        }
        $assignedEssaySlots = array_values(array_intersect(
            array_map('intval', $aqSlotIds),
            array_map('intval', $essaySlotIds)
        ));
        if (empty($assignedEssaySlots)) {
            if ($hasAqRecords) {
                return false; // Modern attempt: AQ shows no essay slots were assigned.
            }
            // Legacy attempt: no AQ records and no essay response rows at all.
            // Ungraded unless every essay slot has a grade in learnguard_essay_grades.
            [$insql, $params] = $DB->get_in_or_equal($essaySlotIds, SQL_PARAMS_NAMED, 'es');
            $params['aid'] = $attemptid;
            $gradedCount = (int)$DB->count_records_sql(
                "SELECT COUNT(DISTINCT slotid) FROM {learnguard_essay_grades}
                  WHERE slotid $insql AND attemptid = :aid",
                $params
            );
            return $gradedCount < count($essaySlotIds);
        }
        // An essay slot is "graded" when a row exists in learnguard_essay_grades for it.
        // This aligns with calculate_total_grade() which uses essay_grades as source of truth.
        [$insql, $params] = $DB->get_in_or_equal($assignedEssaySlots, SQL_PARAMS_NAMED, 'es');
        $params['aid'] = $attemptid;
        $gradedCount = (int)$DB->count_records_sql(
            "SELECT COUNT(DISTINCT slotid) FROM {learnguard_essay_grades}
              WHERE slotid $insql AND attemptid = :aid",
            $params
        );
        return $gradedCount < count($assignedEssaySlots);
    }

    /**
     * Compute the scaled total grade for a finished attempt.
     *
     * Grade sources (in priority order, per slot):
     *   1. Latest manual grade in learnguard_essay_grades   — for any type (grader override)
     *   2. Auto-graded fraction in learnguard_responses     — for non-essay types
     *   3. 0 earned                                         — unanswered / ungraded slot
     *
     * Denominator = sum of maxmarks for every slot the student was assigned (AQ records).
     * Legacy attempts with no AQ records fall back to response rows.
     *
     * Returns: round( totalEarned / totalMax × instance->grade, 5 )
     */
    public static function calculate_total_grade(int $attemptid, \stdClass $instance): float {
        global $DB;

        // ── 1. Assigned slots (AQ primary, response fallback for legacy) ──────────
        $aqRows = $DB->get_records('learnguard_attempt_questions',
            ['attemptid' => $attemptid], 'slotnum ASC');

        if (!empty($aqRows)) {
            $slotIds = array_unique(array_column(array_values($aqRows), 'slotid'));
        } else {
            // Legacy path: no AQ records — use response rows as the slot list.
            $legacyResp = $DB->get_records('learnguard_responses', ['attemptid' => $attemptid]);
            if (empty($legacyResp)) { return 0.0; }
            $slotIds = array_unique(array_map('intval', array_column(array_values($legacyResp), 'slotid')));
            // Build synthetic aq-like rows so the loop below works uniformly.
            $aqRows = [];
            foreach ($legacyResp as $r) {
                $aqRows[(int)$r->slotid] = (object)['slotid' => $r->slotid, 'slotnum' => 0];
            }
        }

        if (empty($slotIds)) { return 0.0; }

        // ── 2. Batch-load slot maxmarks and question types ────────────────────────
        [$slSql, $slP] = $DB->get_in_or_equal($slotIds, SQL_PARAMS_NAMED, 'sl');
        $slotMap = $DB->get_records_sql(
            "SELECT s.id, s.maxmark, COALESCE(q.qtype, 'essay') AS qtype
               FROM {learnguard_slots} s
               LEFT JOIN {learnguard_qbank} q ON q.id = s.questionid
              WHERE s.id $slSql", $slP);

        // ── 3. Batch-load latest manual grade per slot (any grader) ──────────────
        // ORDER BY timegraded DESC so the first row seen per slot = most recent.
        [$slSql2, $slP2] = $DB->get_in_or_equal($slotIds, SQL_PARAMS_NAMED, 'sl2');
        $manualRs = $DB->get_recordset_sql(
            "SELECT slotid, grade FROM {learnguard_essay_grades}
              WHERE attemptid = :aid AND slotid $slSql2
              ORDER BY timegraded DESC",
            array_merge(['aid' => $attemptid], $slP2));
        $manualGradeMap = [];
        foreach ($manualRs as $mr) {
            $sid = (int)$mr->slotid;
            if (!isset($manualGradeMap[$sid])) { // first = most recent
                $manualGradeMap[$sid] = (float)$mr->grade;
            }
        }
        $manualRs->close();

        // ── 4. Batch-load auto-grade fractions from responses ─────────────────────
        [$slSql3, $slP3] = $DB->get_in_or_equal($slotIds, SQL_PARAMS_NAMED, 'sl3');
        $respRs = $DB->get_recordset_sql(
            "SELECT slotid, COALESCE(fraction, 0) AS fraction
               FROM {learnguard_responses}
              WHERE attemptid = :aid AND slotid $slSql3
              ORDER BY id ASC",
            array_merge(['aid' => $attemptid], $slP3));
        $respFracMap = [];
        foreach ($respRs as $rr) {
            $sid = (int)$rr->slotid;
            // Overwrite with later rows; last row per slot wins (most recent save).
            $respFracMap[$sid] = (float)$rr->fraction;
        }
        $respRs->close();

        // ── 5. Sum earned marks and max marks ─────────────────────────────────────
        // Essay questions require a manual grade; all other types are auto-graded.
        // Manual override in essay_grades takes priority for ANY question type.
        $totalMax    = 0.0;
        $totalEarned = 0.0;

        foreach ($aqRows as $aq) {
            $sid     = (int)$aq->slotid;
            $slot    = $slotMap[$sid] ?? null;
            $maxmark = $slot ? (float)$slot->maxmark : 1.0;
            $qtype   = $slot ? $slot->qtype : 'essay';

            $totalMax += $maxmark;

            if (isset($manualGradeMap[$sid])) {
                // Manual grade (essay_grades) takes priority — cap at maxmark.
                $totalEarned += min((float)$manualGradeMap[$sid], $maxmark);
            } elseif ($qtype !== 'essay' && isset($respFracMap[$sid])) {
                // Auto-graded (MCQ, SA, TF, matching, etc.): use stored fraction.
                $totalEarned += (float)$respFracMap[$sid] * $maxmark;
            }
            // Essay with no manual grade yet: earned = 0 (pending).
        }

        if ($totalMax <= 0.0) { return 0.0; }
        return round(($totalEarned / $totalMax) * (float)($instance->grade ?? 100), 5);
    }

    public static function get_navigation_summary(int $attemptid, int $learnguardid): array {
        global $DB;
        $slots   = $DB->get_records('learnguard_slots', ['learnguard' => $learnguardid], 'slot ASC');
        $summary = [];
        foreach ($slots as $slot) {
            $response = $DB->get_record('learnguard_responses', ['attemptid' => $attemptid, 'slotid' => $slot->id]);
            $state    = 'todo';
            $answered = false;
            if ($response) {
                $state    = $response->state;
                $answered = !in_array($state, ['todo', 'noresponse']);
            }
            $summary[] = [
                'slot'     => (int)$slot->slot,
                'slotid'   => (int)$slot->id,
                'state'    => $state,
                'answered' => $answered,
                'maxmark'  => (float)$slot->maxmark,
            ];
        }
        return $summary;
    }

    private static function shuffle_preserving_keys(array $arr, int $seed = 0): array {
        if ($seed !== 0) { mt_srand($seed % PHP_INT_MAX); }
        $keys = array_keys($arr);
        shuffle($keys);
        if ($seed !== 0) { mt_srand(); }
        $out = [];
        foreach ($keys as $k) { $out[$k] = $arr[$k]; }
        return $out;
    }
}
