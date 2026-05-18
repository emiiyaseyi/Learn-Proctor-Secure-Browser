# LearnGuard Phase 4 — Enterprise AI Architecture

## 1. Full Architecture Overview

```
┌─────────────────────────────────────────────────────────────────────┐
│                         MOODLE SERVER                               │
│  ┌──────────────────────────────────────────────────────────────┐   │
│  │  mod_learnguard (PHP Activity Module)                        │   │
│  │  ├── SPA Assessment Engine                                   │   │
│  │  ├── Custom Question Bank                                    │   │
│  │  ├── Proctoring Dashboard (PHP + JS)                        │   │
│  │  ├── AI Settings Admin (siteadmin_ai.php)                   │   │
│  │  └── REST WS layer (external.php)                           │   │
│  └──────────────────────────────────────────────────────────────┘   │
└─────────────────────────┬──────────────────────────────────────────┘
                          │ HTTP (WS calls + AI gateway calls)
                          ▼
┌─────────────────────────────────────────────────────────────────────┐
│                    LEARNGUARD AI STACK (Docker)                     │
│                                                                     │
│  ┌─────────────────────┐   ┌─────────────────────┐                 │
│  │  ai-gateway          │   │  socket-server       │                 │
│  │  FastAPI :8765       │   │  Socket.IO :3001     │                 │
│  │  ├── /face           │   │  ├── student rooms   │                 │
│  │  ├── /objects        │   │  ├── proctor rooms   │                 │
│  │  ├── /gaze           │   │  ├── chat events     │                 │
│  │  ├── /audio          │   │  └── /emit (REST)    │                 │
│  │  ├── /essay          │   └─────────────────────┘                 │
│  │  └── /interview      │                                           │
│  └──────────┬──────────┘   ┌─────────────────────┐                 │
│             │              │  ollama :11434       │                 │
│             │              │  ├── llama3.2        │                 │
│  ┌──────────▼──────────┐   │  ├── mistral         │                 │
│  │  AI Services         │   │  └── any GGUF model │                 │
│  │  ├── OpenCV          │   └─────────────────────┘                 │
│  │  ├── DeepFace        │                                           │
│  │  ├── YOLOv8          │   ┌─────────────────────┐                 │
│  │  ├── MediaPipe       │   │  redis :6379         │                 │
│  │  ├── Whisper         │   │  ├── task queue      │                 │
│  │  ├── SentenceTransf. │   │  └── socket adapter │                 │
│  │  ├── Ollama client   │   └─────────────────────┘                 │
│  │  └── FFmpeg          │                                           │
│  └─────────────────────┘                                           │
└─────────────────────────────────────────────────────────────────────┘
```

## 2. Installation Guide

### Prerequisites
- Docker Desktop (Windows/Mac) or Docker Engine (Linux)
- 8 GB RAM minimum (16 GB recommended for large AI models)
- 20 GB free disk space
- NVIDIA GPU optional (10× faster AI inference)

### Step 1: Clone / copy AI folder
```bash
cp -r learnguard-ai/ /opt/learnguard-ai/
cd /opt/learnguard-ai/
```

### Step 2: Configure environment
```bash
cp .env.example .env
nano .env
# Set: MOODLE_URL, SOCKET_SECRET, LEARNGUARD_AI_KEY
```

### Step 3: Start services
```bash
docker compose up -d
docker compose ps  # verify all services are running
```

### Step 4: Download Ollama models
```bash
docker exec learnguard-ollama ollama pull llama3.2     # ~2GB — required for interview/essay
docker exec learnguard-ollama ollama pull nomic-embed-text  # ~274MB — optional
```

### Step 5: Configure in Moodle
1. Go to: Site Admin → LearnGuard → Site Admin → 🤖 AI Services
2. Set AI Gateway URL: `http://your-server-ip:8765`
3. Set API Key (same as LEARNGUARD_AI_KEY in .env)
4. Click "Service Status" tab to verify all services are online

---

## 3. AI Service Setup Guide

### OpenCV + DeepFace (Face Analysis)
- **Purpose**: Detect faces, verify identity, liveness
- **Model**: VGG-Face (bundled with DeepFace)
- **GPU**: Optional, 3× speedup
- **Config**: Moodle → AI Services → Face Analysis tab
- **Key setting**: Match threshold 0.6 (lower = stricter)

### YOLOv8 (Object Detection)
- **Purpose**: Phone detection, multiple persons, suspicious objects
- **Model**: YOLOv8n auto-downloaded on first run (~6MB)
- **GPU**: Optional, 5× speedup for large-scale
- **Config**: Moodle → AI Services → Object Detection tab
- **Key setting**: Phone confidence 0.5 (lower = more sensitive)

### MediaPipe (Gaze Tracking)
- **Purpose**: Eye tracking, head pose, attention monitoring
- **Model**: FaceMesh (bundled with MediaPipe, ~9MB)
- **GPU**: Not needed (CPU is fast enough)
- **Config**: Moodle → AI Services → Gaze Tracking tab

### Whisper (Audio Transcription)
- **Purpose**: Interview transcription, speech detection
- **Model**: `base` (~74MB) recommended; `large` (~1.5GB) for high accuracy
- **GPU**: Optional, 8× speedup
- **Config**: Moodle → AI Services → Audio/Whisper tab
- **Download**: Auto-downloaded on first use

### Ollama (Local LLM)
- **Purpose**: AI interviewing, essay grading, candidate summaries
- **Models**: llama3.2 (default), mistral, phi3-mini (lighter)
- **GPU**: Strongly recommended (8GB VRAM for llama3.2)
- **Setup**: `docker exec learnguard-ollama ollama pull llama3.2`

### Sentence Transformers (Essay Analysis)
- **Purpose**: Semantic similarity, plagiarism indicators
- **Model**: all-MiniLM-L6-v2 (~90MB, fast + accurate)
- **GPU**: Not needed
- **Config**: Moodle → AI Services → Essay Analysis tab

---

## 4. Admin Usage Guide

### Enabling AI Proctoring
1. Create a LearnGuard assessment → Enable Proctoring
2. In assessment settings, configure:
   - Face Detection: ON
   - Object Detection: ON
   - Gaze Tracking: ON
   - Audio Monitoring: ON
3. The AI gateway automatically processes webcam snapshots

### Live Proctoring Dashboard
- Go to: Assessment → Live Monitor (🔴 Live)
- View all student feeds with real-time risk scores
- Chat with individual students
- Approve break requests
- Grant extra time

### AI Interview Setup
- Go to: Assessment → Edit → Enable AI Interview
- Configure: job title, question count, model
- Students complete video interview asynchronously
- View transcripts and AI scores in Reports

---

## 5. Infrastructure Sizing Guide

| Scale | Students | RAM | CPU | GPU | Storage/month |
|---|---|---|---|---|---|
| Small | <50  | 8 GB  | 4 cores | Optional | 20 GB |
| Medium | 50–200 | 16 GB | 8 cores | 1× 8GB | 100 GB |
| Large | 200–1000 | 32 GB | 16 cores | 2× 8GB | 500 GB |
| Enterprise | 1000+ | 64 GB | 32 cores | 4× 8GB | 2 TB |

---

## 6. Security Best Practices

1. **API Key**: Change `LEARNGUARD_AI_KEY` from default before going live
2. **Network**: Run AI stack on private network; only expose port 8765 to Moodle
3. **HTTPS**: Use nginx reverse proxy with SSL for production
4. **Data**: Snapshots auto-deleted per Moodle retention policy
5. **Ollama**: Keep Ollama internal (not exposed to internet)
6. **Redis**: Password-protect Redis in production

---

## 7. Scalability Recommendations

- **Horizontal**: Add `--scale ai-gateway=3` to docker compose for more workers
- **GPU**: Enable NVIDIA GPU support in docker-compose.yml deploy section
- **Queue**: All heavy AI tasks run async via Celery + Redis
- **CDN**: Store processed clips in Moodle File API with CDN offload
- **Monitoring**: Add Prometheus metrics endpoint to ai-gateway

---

## 8. Production Deployment Checklist

- [ ] Change all default passwords and API keys
- [ ] Configure SSL/TLS (Let's Encrypt or your CA)
- [ ] Set `LEARNGUARD_AI_KEY` to a 32-character random string
- [ ] Pull Ollama models: `ollama pull llama3.2`
- [ ] Test each AI service in the Status tab
- [ ] Set Moodle cron to run every minute
- [ ] Configure Moodle file retention settings
- [ ] Verify snapshot cleanup scheduled task is running
- [ ] Load test with 10 concurrent students before launch
- [ ] Monitor Redis memory usage (`redis-cli info memory`)
- [ ] Set up Docker restart policies (`restart: unless-stopped`)
- [ ] Back up .env file securely

---

## 9. Docker Deployment Guide

### GPU support (NVIDIA)
```yaml
# Add to ai-gateway service in docker-compose.yml:
deploy:
  resources:
    reservations:
      devices:
        - driver: nvidia
          count: 1
          capabilities: [gpu]
```

```bash
# Install nvidia-container-toolkit first:
distribution=$(. /etc/os-release;echo $ID$VERSION_ID)
curl -s -L https://nvidia.github.io/nvidia-docker/gpgkey | sudo apt-key add -
curl -s -L https://nvidia.github.io/nvidia-docker/$distribution/nvidia-docker.list | \
  sudo tee /etc/apt/sources.list.d/nvidia-docker.list
sudo apt-get update && sudo apt-get install -y nvidia-docker2
sudo systemctl restart docker
```

### Useful commands
```bash
docker compose up -d                    # Start all services
docker compose logs ai-gateway -f       # Stream gateway logs
docker compose logs socket-server -f    # Stream socket logs
docker compose restart ai-gateway       # Restart after config change
docker compose down && docker compose up -d  # Full restart
docker exec learnguard-ai-gateway curl http://localhost:8765/health
```

---

## 10. Moodle Integration Guide

The Moodle plugin calls the AI gateway via PHP's `curl` class:

```php
// Example: send webcam snapshot to face detection
$curl = new curl();
$curl->setHeader(['X-API-Key: ' . get_config('learnguard', 'ai_gateway_key')]);
$response = $curl->post(
    get_config('learnguard', 'ai_gateway_url') . '/api/v1/face/detect',
    json_encode(['imagedata' => $base64_image, 'attemptid' => $attemptid]),
    ['CURLOPT_HTTPHEADER' => ['Content-Type: application/json']]
);
$result = json_decode($response, true);
// $result['face_found'], $result['face_count'], $result['multi_person']
```

The Moodle plugin sends snapshots to the AI gateway every N seconds (configurable).
Results are stored in `learnguard_events` and update the attempt risk score.
