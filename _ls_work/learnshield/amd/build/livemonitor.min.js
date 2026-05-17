// Learn Shield V1 — Live monitor module
// Auto-refreshes the proctor dashboard grid and handles alert polling.
//
// @package    quizaccess_learnshield
// @author     Michael Adeniran <michaeladeniransnr@gmail.com>
// @copyright  2025 Michael Adeniran
// @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

define(['jquery', 'core/ajax', 'core/notification'],
    function($, Ajax, Notification) {

        let lastAlertTime = Math.floor(Date.now() / 1000);
        let config        = {};
        let chatVisible   = false;
        let activeTile    = null;

        // -----------------------------------------------------------------------
        // Alert polling
        // -----------------------------------------------------------------------

        function pollAlerts() {
            Ajax.call([{
                methodname: 'quizaccess_learnshield_get_alerts',
                args: {courseid: config.courseid, since: lastAlertTime},
            }])[0]
            .done(function(res) {
                if (!res.alerts || res.alerts.length === 0) { return; }
                lastAlertTime = Math.floor(Date.now() / 1000);

                const panel = document.getElementById('ls-alert-panel');
                res.alerts.forEach(function(alert) {
                    const alertHtml = `
                        <div class="alert alert-${alert.severity === 'danger' ? 'danger' : 'warning'} alert-dismissible fade show" role="alert">
                            <strong>${alert.fullname}</strong> — ${alert.event_type.replace(/_/g, ' ')}
                            <button type="button" class="close" data-dismiss="alert">×</button>
                        </div>`;
                    if (panel) { panel.insertAdjacentHTML('afterbegin', alertHtml); }

                    Notification.addNotification({
                        message: `🚨 ${alert.fullname}: ${alert.event_type.replace(/_/g, ' ')}`,
                        type: alert.severity === 'danger' ? 'error' : 'warning',
                    });

                    // Flash the tile.
                    const tile = document.querySelector(`[data-userid="${alert.userid}"]`);
                    if (tile) {
                        tile.style.boxShadow = '0 0 0 4px #dc3545';
                        setTimeout(() => { tile.style.boxShadow = ''; }, 3000);
                    }
                });
            })
            .fail(function() {});
        }

        // -----------------------------------------------------------------------
        // Session grid refresh
        // -----------------------------------------------------------------------

        function refreshSnapshots() {
            Ajax.call([{
                methodname: 'quizaccess_learnshield_get_live_sessions',
                args: {courseid: config.courseid, quizid: config.cmid || 0},
            }])[0]
            .done(function(res) {
                (res.sessions || []).forEach(function(session) {
                    const tile = document.querySelector(`[data-sessionid="${session.id}"]`);
                    if (!tile) { return; }

                    // Update snapshot.
                    if (session.latest_snapshot) {
                        const img = tile.querySelector('.learnshield-tile-snapshot');
                        if (img && img.tagName === 'IMG') { img.src = session.latest_snapshot; }
                    }

                    // Update risk.
                    const badge = tile.querySelector('.learnshield-risk-badge');
                    if (badge) {
                        badge.textContent = session.risk_level.charAt(0).toUpperCase() + session.risk_level.slice(1);
                        badge.className   = `learnshield-risk-badge learnshield-risk-${session.risk_level}`;
                    }

                    // Update meta.
                    const meta = tile.querySelector('.learnshield-tile-meta');
                    if (meta) {
                        meta.textContent = `Score: ${session.risk_score} | Tabs: ${session.tab_switches}`;
                    }

                    // Risk class.
                    tile.classList.remove('risk-low', 'risk-medium', 'risk-high');
                    tile.classList.add(`risk-${session.risk_level}`);

                    // Alert dot.
                    let dot = tile.querySelector('.learnshield-tile-alert-dot');
                    if (session.risk_level === 'high' || session.is_flagged) {
                        if (!dot) {
                            const alertsDiv = tile.querySelector('.learnshield-tile-alerts') ||
                                (() => {
                                    const d = document.createElement('div');
                                    d.className = 'learnshield-tile-alerts';
                                    tile.appendChild(d);
                                    return d;
                                })();
                            alertsDiv.innerHTML = '<span class="learnshield-tile-alert-dot"></span>';
                        }
                    } else if (dot) {
                        dot.parentElement.innerHTML = '';
                    }
                });
            })
            .fail(function() {});
        }

        // -----------------------------------------------------------------------
        // Tile click → focus view + chat
        // -----------------------------------------------------------------------

        function setupTileInteraction() {
            document.getElementById('ls-session-grid').addEventListener('click', function(e) {
                const tile = e.target.closest('.learnshield-tile');
                if (!tile) { return; }

                const sessionid = tile.dataset.sessionid;
                const userid    = tile.dataset.userid;
                const username  = tile.querySelector('.learnshield-tile-name')?.textContent || 'Learner';

                if (activeTile) { activeTile.style.border = ''; }
                tile.style.border = '3px solid #0f6cbf';
                activeTile = tile;

                openChatPanel(parseInt(sessionid), username);
            });
        }

        // -----------------------------------------------------------------------
        // Chat panel
        // -----------------------------------------------------------------------

        function openChatPanel(sessionid, username) {
            const container = document.getElementById('ls-chat-container');
            if (!container) { return; }

            let lastMsgTime = 0;

            container.innerHTML = `
                <div class="card">
                  <div class="card-header d-flex justify-content-between align-items-center">
                    <strong>Chat — ${username}</strong>
                    <div class="d-flex gap-2">
                      <button class="btn btn-sm btn-warning" id="ls-flag-btn">Flag</button>
                      <button class="btn btn-sm btn-danger" id="ls-suspend-btn">Suspend</button>
                    </div>
                  </div>
                  <div class="card-body p-0">
                    <div class="learnshield-chat-panel">
                      <div id="ls-chat-messages" class="learnshield-chat-messages"></div>
                      <div class="learnshield-chat-input-row">
                        <textarea id="ls-chat-input" rows="2" placeholder="Type a message…"></textarea>
                        <button class="btn btn-primary" id="ls-chat-send">Send</button>
                      </div>
                    </div>
                  </div>
                </div>`;

            // Flag button.
            document.getElementById('ls-flag-btn').addEventListener('click', function() {
                Ajax.call([{
                    methodname: 'quizaccess_learnshield_flag_session',
                    args: {sessionid, flagged: true, note: ''},
                }])[0].done(function() {
                    Notification.addNotification({message: 'Learner flagged.', type: 'warning'});
                });
            });

            // Suspend button.
            document.getElementById('ls-suspend-btn').addEventListener('click', function() {
                if (!confirm('Suspend this learner\'s session?')) { return; }
                fetch(`/mod/quiz/accessrule/learnshield/intervene.php?action=suspend&sessionid=${sessionid}&sesskey=${M.cfg.sesskey}`, {method: 'POST'})
                    .then(r => r.json())
                    .then(data => {
                        Notification.addNotification({message: data.message, type: 'warning'});
                    });
            });

            // Send chat.
            document.getElementById('ls-chat-send').addEventListener('click', function() {
                const msg = document.getElementById('ls-chat-input').value.trim();
                if (!msg) { return; }
                Ajax.call([{
                    methodname: 'quizaccess_learnshield_send_message',
                    args: {
                        sessionid,
                        courseid:     config.courseid,
                        quizid:       config.cmid || 0,
                        recipient_id: 0,
                        message:      msg,
                        message_type: 'chat',
                    },
                }])[0].done(function() {
                    document.getElementById('ls-chat-input').value = '';
                });
            });

            // Poll messages.
            const msgInterval = setInterval(function() {
                if (!document.getElementById('ls-chat-messages')) {
                    clearInterval(msgInterval);
                    return;
                }
                Ajax.call([{
                    methodname: 'quizaccess_learnshield_get_messages',
                    args: {sessionid, since: lastMsgTime},
                }])[0].done(function(res) {
                    const msgs = document.getElementById('ls-chat-messages');
                    (res.messages || []).forEach(function(m) {
                        if (m.timemodified <= lastMsgTime) { return; }
                        lastMsgTime = m.timemodified;
                        const div = document.createElement('div');
                        div.className = `learnshield-chat-msg${m.is_broadcast ? ' learnshield-chat-broadcast' : ''}`;
                        div.innerHTML = `
                            <div class="learnshield-chat-bubble">${m.message}</div>
                            <div class="learnshield-chat-meta">${m.sender_name}</div>`;
                        msgs.appendChild(div);
                        msgs.scrollTop = msgs.scrollHeight;
                    });
                });
            }, 3000);
        }

        // -----------------------------------------------------------------------
        // Public API
        // -----------------------------------------------------------------------

        return {
            init(cfg) {
                config = cfg || {};

                setupTileInteraction();

                // Poll alerts every 5 seconds.
                setInterval(pollAlerts, 5000);

                // Refresh snapshots every 5 seconds.
                setInterval(refreshSnapshots, 5000);
            },
        };
    });
