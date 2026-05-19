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
        int $questionid, int $slotnum, array $saved = [], bool $review = false
    ): string {
        global $DB;

        $question = $DB->get_record('learnguard_qbank', ['id' => $questionid], '*', MUST_EXIST);
        $answers  = $DB->get_records('learnguard_qbank_answers', ['questionid' => $questionid], 'sortorder ASC');

        if ($question->shuffleanswers && !$review && !in_array($question->qtype, ['matching', 'truefalse'])) {
            $answers = self::shuffle_preserving_keys($answers);
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

        // Question text
        $html .= '<div class="lg-qtext" style="font-size:20px;line-height:1.75;color:#212529;' .
                 'margin-bottom:24px;font-weight:500;">';
        $html .= format_text($question->questiontext, $question->questiontextformat ?? FORMAT_HTML);
        $html .= '</div>';

        // Answer block
        $html .= '<div class="lg-ablock">';
        switch ($question->qtype) {
            case 'multichoice': $html .= self::render_multichoice($answers, $slotnum, $saved, $review); break;
            case 'multicheck':  $html .= self::render_multicheck($answers, $slotnum, $saved, $review);  break;
            case 'truefalse':   $html .= self::render_truefalse($questionid, $slotnum, $saved, $review);break;
            case 'shortanswer': $html .= self::render_shortanswer($answers, $slotnum, $saved, $review); break;
            case 'essay':          $html .= self::render_essay($slotnum, $saved, $review);                    break;
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

    private static function render_multichoice(array $answers, int $slot, array $saved, bool $review): string {
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

            $html .= '<label style="display:flex;align-items:flex-start;gap:0;' .
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

    private static function render_multicheck(array $answers, int $slot, array $saved, bool $review): string {
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

            $html .= '<label style="display:flex;align-items:flex-start;gap:14px;padding:13px 18px;' .
                     'border:2px solid ' . $border . ';border-radius:10px;background:' . $bg . ';cursor:pointer;">';
            $html .= '<input type="checkbox" name="' . s($name) . '" value="' . (int)$a->id . '"' .
                     ($checked ? ' checked' : '') . $disabled . ' style="width:20px;height:20px;margin-top:2px;flex-shrink:0;cursor:pointer;">';
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

    private static function render_truefalse(int $qid, int $slot, array $saved, bool $review): string {
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

    private static function render_shortanswer(array $answers, int $slot, array $saved, bool $review): string {
        $field    = "q{$slot}_answer";
        $savedval = $saved[$field] ?? '';
        $correct  = '';
        foreach ($answers as $a) {
            if ($a->fraction >= 1.0) { $correct = $a->answertext; break; }
        }

        $borderCol = '2px solid #dee2e6';
        if ($review && $savedval !== '') {
            $isRight = false;
            foreach ($answers as $a) {
                if ($a->fraction >= 1.0 &&
                    mb_strtolower(trim($savedval)) === mb_strtolower(trim($a->answertext ?? ''))) {
                    $isRight = true; break;
                }
            }
            $borderCol = $isRight ? '2px solid #28a745' : '2px solid #dc3545';
        }

        $disabled = $review ? ' disabled' : '';
        $html = '<input type="text" name="' . s($field) . '" value="' . s($savedval) . '"' . $disabled .
                ' style="width:100%;padding:12px 16px;border:' . $borderCol . ';border-radius:8px;' .
                'font-size:16px;outline:none;box-sizing:border-box;" placeholder="Type your answer here…">';

        if ($review && $correct !== '') {
            $html .= '<div style="margin-top:8px;padding:8px 12px;background:#d1f5d1;border-radius:6px;' .
                     'font-size:13px;color:#155724;">✓ Correct answer: <strong>' . s($correct) . '</strong></div>';
        }
        return $html;
    }

    private static function render_essay(int $slot, array $saved, bool $review): string {
        $field    = "q{$slot}_answer";
        $savedval = $saved[$field] ?? '';
        $disabled = $review ? ' disabled' : '';
        $html = '<textarea name="' . s($field) . '" rows="8"' . $disabled .
                ' style="width:100%;padding:14px 16px;border:2px solid #dee2e6;border-radius:8px;' .
                'font-size:15px;resize:vertical;line-height:1.6;box-sizing:border-box;' .
                'font-family:system-ui,sans-serif;" placeholder="Write your response here…">' .
                s($savedval) . '</textarea>';
        $html .= '<div style="display:flex;align-items:center;gap:6px;margin-top:8px;' .
                 'font-size:12px;color:#6c757d;">' .
                 '<span style="background:#fff3cd;border:1px solid #ffc107;border-radius:4px;' .
                 'padding:2px 8px;font-size:11px;font-weight:700;color:#856404;">ESSAY</span>' .
                 'This response will be reviewed and marked by your instructor.</div>';
        return $html;
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
                foreach ($answers as $a) {
                    $pattern = trim($a->answertext ?? '');
                    $given   = trim($response ?? '');
                    if ($pattern !== '' && mb_strtolower($given) === mb_strtolower($pattern)) {
                        return [(float)$a->fraction, 'complete'];
                    }
                    if (strpos($pattern, '*') !== false) {
                        $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/iu';
                        if (preg_match($regex, $given)) { return [(float)$a->fraction, 'complete']; }
                    }
                }
                return [0.0, 'complete'];

            case 'essay':
                return [1.0, 'needsgrading'];

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
        return $response !== null && $response !== '';
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

        if ($existing) {
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

    public static function calculate_total_grade(int $attemptid, \stdClass $instance): float {
        global $DB;
        $slots = $DB->get_records('learnguard_slots', ['learnguard' => $instance->id], 'slot ASC');
        if (empty($slots)) { return 0.0; }
        $totalraw = 0.0; $totalmax = 0.0;
        foreach ($slots as $slot) {
            $maxmark  = (float)$slot->maxmark;
            $totalmax += $maxmark;
            $response = $DB->get_record('learnguard_responses', ['attemptid' => $attemptid, 'slotid' => $slot->id]);
            if ($response) { $totalraw += (float)$response->fraction * $maxmark; }
        }
        if ($totalmax <= 0) { return 0.0; }
        return round(($totalraw / $totalmax) * (float)($instance->grade ?? 100), 5);
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

    private static function shuffle_preserving_keys(array $arr): array {
        $keys = array_keys($arr);
        shuffle($keys);
        $out = [];
        foreach ($keys as $k) { $out[$k] = $arr[$k]; }
        return $out;
    }
}
