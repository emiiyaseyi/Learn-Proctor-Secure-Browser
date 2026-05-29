#!/usr/bin/env python3
"""
LearnGuard / LearnShield — Load Test Script
Tests plugin performance with concurrent simulated learners.

Usage:
    python loadtest.py --url https://your-moodle.com --users 50
    python loadtest.py --url https://your-moodle.com --users 200 --plugin shield

Requirements: pip install requests

Author: Michael Adeniran
"""

import argparse
import threading
import time
import json
import random
import statistics
from datetime import datetime

try:
    import requests
except ImportError:
    print("Install requests: pip install requests")
    raise


# ── Configuration ──────────────────────────────────────────────────────────────

DEFAULT_URL   = 'https://your-moodle.com'   # Replace with your Moodle URL
ADMIN_USER    = 'admin'                       # Moodle admin username
ADMIN_PASS    = 'yourpassword'                # Moodle admin password

# Test parameters — adjust per your setup.
QUIZ_CMID     = 1     # LearnShield: Course Module ID of a proctored quiz
LG_CMID       = 1     # LearnGuard:  Course Module ID of a LearnGuard activity
COURSE_ID     = 1     # Course ID
SESSION_ID    = 1     # A session ID to use for WS calls (create one manually first)

# Learner WS call intervals (seconds) — must match plugin configuration.
CAMSHOT_INTERVAL  = 15
HEARTBEAT_INTERVAL = 60
MESSAGE_POLL       = 30


# ── Results tracking ───────────────────────────────────────────────────────────

results = {
    'total':    0,
    'success':  0,
    'error':    0,
    'times':    [],
    'errors':   [],
}
lock = threading.Lock()

def record(elapsed, success, error=None):
    with lock:
        results['total'] += 1
        results['times'].append(elapsed)
        if success:
            results['success'] += 1
        else:
            results['error'] += 1
            if error:
                results['errors'].append(str(error)[:120])


# ── Moodle WS helper ───────────────────────────────────────────────────────────

def ws_call(session, base_url, sesskey, method, args):
    """Make one Moodle WS AJAX call. Returns (ok, response_data, elapsed_ms)."""
    start = time.time()
    try:
        resp = session.post(
            f'{base_url}/lib/ajax/service.php?sesskey={sesskey}',
            json=[{'index': 0, 'methodname': method, 'args': args}],
            headers={'Content-Type': 'application/json'},
            timeout=15,
        )
        elapsed = int((time.time() - start) * 1000)
        if resp.status_code == 200:
            data = resp.json()
            if data and data[0].get('error'):
                return False, data[0], elapsed
            return True, data[0].get('data', {}), elapsed
        return False, {'http': resp.status_code}, elapsed
    except Exception as e:
        return False, {'exception': str(e)}, int((time.time() - start) * 1000)


def moodle_login(base_url, username, password):
    """Log in to Moodle and return (session, sesskey) or (None, None)."""
    s = requests.Session()
    # Get login token.
    r = s.get(f'{base_url}/login/index.php', timeout=10)
    token = ''
    for line in r.text.split('\n'):
        if 'logintoken' in line and 'value=' in line:
            token = line.split('value="')[1].split('"')[0]
            break
    # Post credentials.
    r = s.post(f'{base_url}/login/index.php', data={
        'username': username, 'password': password,
        'logintoken': token, 'anchor': '',
    }, timeout=10, allow_redirects=True)
    # Extract sesskey from dashboard page.
    sesskey = ''
    for line in r.text.split('\n'):
        if '"sesskey"' in line:
            try:
                sesskey = line.split('"sesskey":"')[1].split('"')[0]
                break
            except Exception:
                pass
    if not sesskey:
        return None, None
    return s, sesskey


# ── Simulated learner thread ───────────────────────────────────────────────────

def simulate_learner(base_url, sesskey, plugin, learner_num, duration_secs):
    """Simulates one learner making WS calls for duration_secs seconds."""
    session = requests.Session()
    end_time = time.time() + duration_secs
    call_count = 0

    prefix = 'quizaccess_learnshield' if plugin == 'shield' else 'mod_learnguard'
    cmid   = QUIZ_CMID if plugin == 'shield' else LG_CMID

    last_camshot  = 0
    last_heartbeat = 0
    last_msgpoll  = 0

    print(f'  [Learner {learner_num:03d}] started (plugin={plugin})')

    while time.time() < end_time:
        now = time.time()

        # Webcam snapshot every 15s.
        if now - last_camshot >= CAMSHOT_INTERVAL:
            last_camshot = now
            ok, data, ms = ws_call(session, base_url, sesskey,
                f'{prefix}_send_camshot', {
                    'courseid':      COURSE_ID,
                    'screenshotid':  SESSION_ID,
                    'quizid':        cmid,
                    'webcampicture': 'data:image/jpeg;base64,/9j/4A==',  # Minimal JPEG stub.
                    'imagetype':     1,
                    'parenttype':    'camshot_image',
                    'faceimage':     '',
                    'facefound':     0,
                })
            record(ms, ok, None if ok else data)
            call_count += 1

        # Heartbeat event log every 60s.
        if now - last_heartbeat >= HEARTBEAT_INTERVAL:
            last_heartbeat = now
            if plugin == 'shield':
                ok, data, ms = ws_call(session, base_url, sesskey,
                    'quizaccess_learnshield_log_event', {
                        'sessionid':  SESSION_ID,
                        'courseid':   COURSE_ID,
                        'quizid':     cmid,
                        'attemptid':  0,
                        'event_type': 'session_heartbeat',
                        'event_data': '{}',
                        'severity':   'info',
                    })
            else:
                ok, data, ms = ws_call(session, base_url, sesskey,
                    'mod_learnguard_log_event', {
                        'attemptid':   SESSION_ID,
                        'eventtype':   'session_heartbeat',
                        'eventdata':   '{}',
                        'severity':    'info',
                        'scoreimpact': 0,
                    })
            record(ms, ok, None if ok else data)
            call_count += 1

        # Message poll every 30s.
        if now - last_msgpoll >= MESSAGE_POLL:
            last_msgpoll = now
            if plugin == 'shield':
                ok, data, ms = ws_call(session, base_url, sesskey,
                    'quizaccess_learnshield_get_messages', {
                        'sessionid': SESSION_ID, 'since': 0,
                    })
                record(ms, ok, None if ok else data)
                call_count += 1

        time.sleep(random.uniform(0.5, 2.0))  # Randomise to avoid thundering herd.

    print(f'  [Learner {learner_num:03d}] done — {call_count} calls made')


# ── Main test runner ───────────────────────────────────────────────────────────

def run_test(base_url, num_users, plugin, duration_secs, admin_user, admin_pass):
    print(f'\n{"="*60}')
    print(f'LearnGuard/LearnShield Load Test')
    print(f'Target:   {base_url}')
    print(f'Plugin:   {plugin.upper()}')
    print(f'Learners: {num_users}')
    print(f'Duration: {duration_secs}s per learner')
    print(f'{"="*60}')

    print('\n[1/3] Logging in as admin to get sesskey…')
    admin_session, sesskey = moodle_login(base_url, admin_user, admin_pass)
    if not admin_session or not sesskey:
        print('❌ Login failed. Check credentials and Moodle URL.')
        return

    print(f'  ✅ Logged in, sesskey: {sesskey[:8]}…')

    print(f'\n[2/3] Spawning {num_users} concurrent learner threads…')
    start = time.time()
    threads = []
    for i in range(1, num_users + 1):
        t = threading.Thread(
            target=simulate_learner,
            args=(base_url, sesskey, plugin, i, duration_secs),
            daemon=True,
        )
        threads.append(t)
        t.start()
        time.sleep(0.05)  # Stagger starts by 50ms to avoid thundering herd.

    # Wait for all threads to complete.
    for t in threads:
        t.join()

    elapsed = time.time() - start

    print(f'\n[3/3] Results after {elapsed:.1f}s:')
    print(f'{"="*60}')
    r = results
    if r['times']:
        p50  = statistics.median(r['times'])
        p95  = sorted(r['times'])[int(len(r['times']) * 0.95)]
        p99  = sorted(r['times'])[int(len(r['times']) * 0.99)]
        mean = statistics.mean(r['times'])
        print(f'  Total WS calls:   {r["total"]:,}')
        print(f'  Successful:       {r["success"]:,}  ({r["success"]/max(r["total"],1)*100:.1f}%)')
        print(f'  Errors:           {r["error"]:,}')
        print(f'  Throughput:       {r["total"]/elapsed:.1f} calls/sec')
        print(f'  Response times:')
        print(f'    Mean:           {mean:.0f} ms')
        print(f'    Median (P50):   {p50:.0f} ms')
        print(f'    P95:            {p95:.0f} ms')
        print(f'    P99:            {p99:.0f} ms')
        print(f'    Max:            {max(r["times"]):.0f} ms')

        if p95 > 3000:
            print(f'\n  ⚠️  WARNING: P95 response time {p95:.0f}ms exceeds 3000ms — server is under strain.')
        elif p95 > 1500:
            print(f'\n  ⚠️  CAUTION: P95 response time {p95:.0f}ms — acceptable but watch server load.')
        else:
            print(f'\n  ✅ Performance is good — P95 under 1500ms.')

        if r['error'] / max(r['total'], 1) > 0.05:
            print(f'  ⚠️  ERROR RATE {r["error"]/r["total"]*100:.1f}% exceeds 5% — plugin or server has issues.')
        else:
            print(f'  ✅ Error rate {r["error"]/r["total"]*100:.1f}% — acceptable.')

    if r['errors'][:5]:
        print(f'\n  Sample errors:')
        for e in r['errors'][:5]:
            print(f'    • {e}')

    print(f'\n{"="*60}')
    print(f'Recommendation for {num_users} learners:')
    if r['times']:
        if p95 < 1000 and r['error'] / max(r['total'], 1) < 0.01:
            print(f'  ✅ Server handles {num_users} learners comfortably. Can scale higher.')
        elif p95 < 2000 and r['error'] / max(r['total'], 1) < 0.05:
            print(f'  ⚠️  Server handles {num_users} learners but is near capacity.')
            print(f'     Consider: PHP-FPM pool size increase, DB connection pool, Redis session cache.')
        else:
            print(f'  ❌ Server struggles with {num_users} learners. Optimise before going live.')
            print(f'     Immediate actions: Increase PHP-FPM workers, add DB indexes, enable OPcache.')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description='LearnShield/LearnGuard Load Tester')
    parser.add_argument('--url',      default=DEFAULT_URL, help='Moodle base URL')
    parser.add_argument('--users',    type=int, default=50, help='Number of concurrent learners')
    parser.add_argument('--plugin',   choices=['shield', 'guard'], default='shield')
    parser.add_argument('--duration', type=int, default=60, help='Test duration per learner (seconds)')
    parser.add_argument('--user',     default=ADMIN_USER, help='Moodle admin username')
    parser.add_argument('--password', default=ADMIN_PASS, help='Moodle admin password')
    parser.add_argument('--ramp',     action='store_true',
                        help='Ramp test: run 50 → 100 → 150 → 200 automatically')
    args = parser.parse_args()

    if args.ramp:
        for n in [50, 100, 150, 200]:
            # Reset results between runs.
            for k in results:
                results[k] = [] if isinstance(results[k], list) else 0
            run_test(args.url, n, args.plugin, args.duration, args.user, args.password)
            print('\nWaiting 30s before next ramp level…\n')
            time.sleep(30)
    else:
        run_test(args.url, args.users, args.plugin, args.duration, args.user, args.password)
