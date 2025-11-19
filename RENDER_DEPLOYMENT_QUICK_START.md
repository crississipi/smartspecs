# Render Deployment Quick Start Guide

Quick reference for deploying to Render.

## Architecture

- **PHP Web Service**: Frontend + API (`ai-chatbot-php`)
- **Python Web Service**: AI Service (`ai-chatbot-python`) - **MUST be web service** (not background worker)

## Deployment Order

1. **Deploy Python service FIRST**
2. **Deploy PHP service SECOND**
3. **Update URLs** (cross-reference both services)

## Step-by-Step

### 1. Deploy Python Service

1. Render Dashboard → **New** → **Web Service**
2. Connect GitHub repository
3. Configure:
   - Name: `ai-chatbot-python`
   - Environment: `Python 3`
   - Build: `pip install -r ai_service/requirements.txt`
   - Start: `cd ai_service && python app.py`
   - Plan: **Free**

4. Set Environment Variables:
   ```
   DB_HOST=<your-db-host>
   DB_PORT=<your-db-port>
   DB_USER=<your-db-user>
   DB_PASS=<your-db-pass>
   DB_NAME=<your-db-name>
   DB_SSL=true
   HF_API_KEY=<your-hf-key>
   MODEL_NAME=microsoft/DialoGPT-medium
   DEBUG=false
   ```

5. **Save** and wait for deployment
6. **Copy External URL** (e.g., `https://ai-chatbot-python.onrender.com`)

### 2. Deploy PHP Service

1. Render Dashboard → **New** → **Web Service**
2. Connect same GitHub repository
3. Configure:
   - Name: `ai-chatbot-php`
   - Environment: `Docker`
   - Dockerfile Path: `./Dockerfile`
   - Plan: **Free**

4. Set Environment Variables:
   ```
   DB_HOST=<same-as-python>
   DB_PORT=<same-as-python>
   DB_USER=<same-as-python>
   DB_PASS=<same-as-python>
   DB_NAME=<same-as-python>
   DB_SSL=true
   GOOGLE_API_KEY=<your-google-key>
   GOOGLE_CSE_ID=<your-cse-id>
   PYTHON_SERVICE_URL=https://ai-chatbot-python.onrender.com
   ```

5. **Save** and wait for deployment
6. **Copy External URL** (e.g., `https://ai-chatbot-php.onrender.com`)

### 3. Update Service URLs

1. **Python Service** → Environment → Update:
   ```
   FRONTEND_URL=https://ai-chatbot-php.onrender.com
   ```

2. **PHP Service** → Verify:
   ```
   PYTHON_SERVICE_URL=https://ai-chatbot-python.onrender.com
   ```

Both services will auto-redeploy.

## Using render.yaml (Blueprint)

1. Render Dashboard → **New** → **Blueprint**
2. Connect GitHub repository
3. Render will create both services
4. **Still need to:**
   - Set environment variables manually
   - Deploy Python first, then PHP
   - Update cross-references

## Testing

1. Test Python service: `https://ai-chatbot-python.onrender.com/health`
2. Test PHP service: `https://ai-chatbot-php.onrender.com`
3. Test AI chat functionality
4. Check logs in Render Dashboard

## Common Issues

**Python service not responding:**
- Verify it's deployed as **Web Service** (not Background Worker)
- Check build logs (large dependencies take 10-15 min)

**PHP can't connect to Python:**
- Verify `PYTHON_SERVICE_URL` is set correctly
- Test Python service directly
- Check CORS settings

**Slow first request:**
- Normal on free tier (services spin down after 15 min)
- First request after spin-down takes ~30 seconds

## Full Documentation

See `DEPLOYMENT.md` for detailed instructions.

