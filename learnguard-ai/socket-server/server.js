/**
 * LearnGuard Real-Time Socket.IO Server
 * ─────────────────────────────────────
 * Handles live proctoring events between:
 *  • Students (during exam)
 *  • Proctors (on the dashboard)
 *  • The Moodle backend (PHP → emit events via REST)
 *
 * Events emitted TO students:
 *   proctor:message        — chat message from proctor
 *   proctor:warning        — warning alert
 *   proctor:break_approved — break approved
 *   proctor:extra_time     — extra time granted
 *   proctor:force_submit   — force student to submit
 *   assessment:paused      — assessment paused by admin
 *   assessment:resumed     — assessment resumed
 *
 * Events emitted TO proctors:
 *   student:joined         — student started exam
 *   student:left           — student disconnected
 *   student:event          — proctoring event (tab switch, face missing, etc.)
 *   student:snapshot       — new webcam snapshot available
 *   student:message        — chat message from student
 *   student:break_request  — student requested a break
 *   student:submitted      — student finished exam
 *
 * PHP → Socket (via POST /emit):
 *   Moodle calls this endpoint to push events without direct socket access.
 */

require('dotenv').config();
const express   = require('express');
const http      = require('http');
const { Server } = require('socket.io');
const { createAdapter } = require('@socket.io/redis-adapter');
const { createClient }  = require('ioredis');
const jwt = require('jsonwebtoken');

const app    = express();
const server = http.createServer(app);
app.use(express.json());

const SECRET = process.env.SOCKET_SECRET || 'changeme_secret_key';
const PORT   = process.env.PORT || 3001;

// ── Redis adapter (for multi-server scaling) ─────────────────────────────────
const pubClient = createClient({ url: process.env.REDIS_URL || 'redis://localhost:6379/1' });
const subClient = pubClient.duplicate();

const io = new Server(server, {
  cors: { origin: '*', methods: ['GET', 'POST'] },
  transports: ['websocket', 'polling'],
  pingTimeout:  20000,
  pingInterval: 10000,
});

(async () => {
  await pubClient.connect();
  await subClient.connect();
  io.adapter(createAdapter(pubClient, subClient));
  console.log('Redis adapter connected.');
})();

// ── Rooms: each assessment attempt has its own room ──────────────────────────
// Room names:
//   attempt:{attemptid}         — student + their proctors
//   assessment:{learnguardid}   — all students in a given assessment (for mass actions)
//   proctor:{learnguardid}      — all proctors watching a given assessment

// ── Authentication middleware ─────────────────────────────────────────────────
io.use((socket, next) => {
  const token = socket.handshake.auth?.token || socket.handshake.query?.token;
  if (!token) return next(new Error('Missing auth token'));
  try {
    const payload = jwt.verify(token, SECRET);
    socket.user = payload;
    next();
  } catch (e) {
    next(new Error('Invalid auth token'));
  }
});

// ── Connection handler ────────────────────────────────────────────────────────
io.on('connection', (socket) => {
  const user = socket.user;
  console.log(`[+] ${user.role} ${user.userid} connected (attempt: ${user.attemptid})`);

  if (user.role === 'student') {
    handleStudent(socket, user);
  } else if (user.role === 'proctor' || user.role === 'manager') {
    handleProctor(socket, user);
  }

  socket.on('disconnect', (reason) => {
    console.log(`[-] ${user.role} ${user.userid} disconnected: ${reason}`);
    if (user.role === 'student' && user.attemptid) {
      io.to(`proctor:${user.learnguardid}`).emit('student:left', {
        attemptid: user.attemptid,
        userid:    user.userid,
        fullname:  user.fullname,
        reason,
      });
    }
  });
});

function handleStudent(socket, user) {
  const attemptRoom    = `attempt:${user.attemptid}`;
  const assessmentRoom = `assessment:${user.learnguardid}`;
  socket.join(attemptRoom);
  socket.join(assessmentRoom);

  // Notify proctors.
  io.to(`proctor:${user.learnguardid}`).emit('student:joined', {
    attemptid:  user.attemptid,
    userid:     user.userid,
    fullname:   user.fullname,
    timejoined: Date.now(),
  });

  // Student sends a chat message.
  socket.on('student:message', (data) => {
    io.to(`proctor:${user.learnguardid}`).emit('student:message', {
      attemptid: user.attemptid,
      userid:    user.userid,
      fullname:  user.fullname,
      message:   data.message,
      time:      Date.now(),
    });
  });

  // Student requests a break.
  socket.on('student:break_request', () => {
    io.to(`proctor:${user.learnguardid}`).emit('student:break_request', {
      attemptid: user.attemptid,
      userid:    user.userid,
      fullname:  user.fullname,
      time:      Date.now(),
    });
  });

  // Student submits exam.
  socket.on('student:submitted', () => {
    io.to(`proctor:${user.learnguardid}`).emit('student:submitted', {
      attemptid: user.attemptid,
      userid:    user.userid,
      time:      Date.now(),
    });
  });
}

function handleProctor(socket, user) {
  const proctorRoom = `proctor:${user.learnguardid}`;
  socket.join(proctorRoom);

  // Proctor sends chat message to a student.
  socket.on('proctor:message', (data) => {
    io.to(`attempt:${data.attemptid}`).emit('proctor:message', {
      message:   data.message,
      proctorname: user.fullname,
      time:      Date.now(),
    });
  });

  // Proctor sends a warning.
  socket.on('proctor:warning', (data) => {
    io.to(`attempt:${data.attemptid}`).emit('proctor:warning', {
      message: data.message,
      time:    Date.now(),
    });
  });

  // Proctor approves a break.
  socket.on('proctor:break_approved', (data) => {
    io.to(`attempt:${data.attemptid}`).emit('proctor:break_approved', {
      time: Date.now(),
    });
  });

  // Proctor grants extra time.
  socket.on('proctor:extra_time', (data) => {
    io.to(`attempt:${data.attemptid}`).emit('proctor:extra_time', {
      extra_seconds: data.extra_seconds,
      time:          Date.now(),
    });
  });

  // Proctor force-submits a student.
  socket.on('proctor:force_submit', (data) => {
    io.to(`attempt:${data.attemptid}`).emit('proctor:force_submit', { time: Date.now() });
  });
}

// ── REST endpoint: Moodle PHP → emit socket event ─────────────────────────────
app.post('/emit', (req, res) => {
  const apiKey = req.headers['x-api-key'];
  if (apiKey !== SECRET) return res.status(401).json({ error: 'Unauthorized' });

  const { room, event, data } = req.body;
  if (!room || !event) return res.status(400).json({ error: 'room and event required' });

  io.to(room).emit(event, { ...data, source: 'moodle', time: Date.now() });
  res.json({ ok: true, room, event });
});

// ── Assessment pause/resume (affects ALL students) ────────────────────────────
app.post('/assessment/pause', (req, res) => {
  const { learnguardid } = req.body;
  io.to(`assessment:${learnguardid}`).emit('assessment:paused', { time: Date.now() });
  res.json({ ok: true });
});

app.post('/assessment/resume', (req, res) => {
  const { learnguardid } = req.body;
  io.to(`assessment:${learnguardid}`).emit('assessment:resumed', { time: Date.now() });
  res.json({ ok: true });
});

server.listen(PORT, () => console.log(`LearnGuard Socket.IO running on port ${PORT}`));
