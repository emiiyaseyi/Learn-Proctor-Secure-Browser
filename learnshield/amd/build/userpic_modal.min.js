// Learn Shield V1 — User picture lightbox modal
//
// @package    quizaccess_learnshield
// @author     Michael Adeniran <michaeladeniransnr@gmail.com>
// @copyright  2025 Michael Adeniran
// @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

define(['jquery'], function($) {
    return {
        init() {
            $(document).on('click', '.ls-snapshot-thumb', function(e) {
                e.preventDefault();
                const src   = $(this).attr('href') || $(this).attr('src');
                const modal = $(`
                    <div class="modal fade" tabindex="-1" style="display:block;background:rgba(0,0,0,.7);">
                      <div class="modal-dialog modal-lg modal-dialog-centered">
                        <div class="modal-content">
                          <div class="modal-header">
                            <h5 class="modal-title">Snapshot</h5>
                            <button type="button" class="close" data-dismiss="modal">×</button>
                          </div>
                          <div class="modal-body text-center">
                            <img src="${src}" class="img-fluid" style="max-height:70vh;"/>
                          </div>
                        </div>
                      </div>
                    </div>`);
                $('body').append(modal);
                modal.find('[data-dismiss]').on('click', function() { modal.remove(); });
                modal.on('click', function(ev) { if ($(ev.target).is(modal)) { modal.remove(); } });
            });
        },
    };
});
