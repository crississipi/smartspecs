# Fly.io Deployment Guide

Fly.io is another excellent free alternative with generous limits:
- **3 shared-cpu-1x VMs free** (256MB RAM each, can combine)
- **Up to 3GB storage free**
- **Better for memory-intensive apps**

## Step 1: Install Fly CLI

```bash
# Windows (PowerShell)
iwr https://fly.io/install.ps1 -useb | iex

# Or download from: https://fly.io/docs/hands-on/install-flyctl/
```

## Step 2: Create Fly.io Account

```bash
fly auth signup
# Or: fly auth login
```

## Step 3: Create Fly.io App

In your project root:

```bash
cd ai_service
fly launch
```

Follow the prompts:
- App name: `ai-chatbot-python` (or your choice)
- Region: Choose closest to you
- Don't deploy yet (we'll configure first)

## Step 4: Configure fly.toml

A `fly.toml` file will be created. Update it:

```toml
app = "ai-chatbot-python"
primary_region = "iad"  # Change to your preferred region

[build]

[env]
  PORT = "5000"
  HOST = "0.0.0.0"
  MODEL_NAME = "distilgpt2"
  USE_LIGHTWEIGHT_MODEL = "true"
  DEBUG = "false"

[http_service]
  internal_port = 5000
  force_https = true
  auto_stop_machines = true
  auto_start_machines = true
  min_machines_running = 0
  processes = ["app"]

[[vm]]
  memory_mb = 512
  cpu_kind = "shared"
  cpus = 1
```

## Step 5: Set Secrets (Environment Variables)

```bash
fly secrets set DB_HOST=your_db_host
fly secrets set DB_PORT=3306
fly secrets set DB_USER=your_db_user
fly secrets set DB_PASS=your_db_password
fly secrets set DB_NAME=your_db_name
fly secrets set DB_SSL=true
fly secrets set HF_API_KEY=your_hf_api_key
fly secrets set FRONTEND_URL=https://your-php-service.onrender.com
```

## Step 6: Deploy

```bash
fly deploy
```

## Step 7: Get Your URL

After deployment, you'll get a URL like: `https://ai-chatbot-python.fly.dev`

Update your PHP service's `PYTHON_SERVICE_URL` environment variable.

## Memory Management

Fly.io allows you to scale memory:
- Free tier: 256MB per VM (can run multiple)
- Upgrade: 512MB, 1GB, etc. (paid)

## Cost

- **Free tier**: 3 shared-cpu-1x VMs (256MB each)
- **This service**: Uses 1 VM (~256-512MB)
- **Total cost**: $0/month on free tier

