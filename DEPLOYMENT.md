# Deployment Guide for Render

This guide will help you deploy your AI Chatbot project to Render.

> **⚠️ Memory Issue Note:** If you're experiencing memory errors with the Python AI service on Render (512MB limit), consider deploying the Python service to **Railway** or **Fly.io** instead. See `DEPLOYMENT_PLATFORMS_COMPARISON.md` for details. The PHP service can remain on Render.

## Prerequisites

1. **GitHub Account** - Your code must be in a GitHub repository
2. **Render Account** - Sign up at [render.com](https://render.com)
3. **Database** - You can use Render's PostgreSQL (free), AivenDB, or any MySQL/PostgreSQL database

## Architecture Overview

Your application consists of two web services:

1. **PHP Web Service** (`ai-chatbot-php`)
   - Frontend (HTML/CSS/JavaScript)
   - API endpoints for authentication, threads, messages
   - Calls Python service via HTTP

2. **Python Web Service** (`ai-chatbot-python`)
   - AI service with Flask API
   - Endpoints: `/generate`, `/title`, `/health`, etc.
   - Must be a web service (not background worker) to expose HTTP endpoints

## Step 1: Push Code to GitHub

1. Initialize git repository (if not already done):
   ```bash
   git init
   git add .
   git commit -m "Ready for Render deployment"
   ```

2. Create a GitHub repository and push:
   ```bash
   git remote add origin https://github.com/yourusername/ai-chatbot.git
   git branch -M main
   git push -u origin main
   ```

## Step 2: Create PostgreSQL Database on Render (Optional)

If you want to use Render's free PostgreSQL instead of AivenDB:

1. Go to Render Dashboard → **New** → **PostgreSQL**
2. Name: `ai-chatbot-db`
3. Plan: **Free**
4. Region: Choose closest to you
5. Click **Create Database**
6. Wait for it to be ready, then copy the **Internal Database URL** and **External Database URL**

**Note**: If using AivenDB or external database, skip this step.

## Step 3: Deploy Python AI Service (Deploy This First)

**Important**: Deploy Python service first because PHP service needs its URL.

1. Go to Render Dashboard → **New** → **Web Service**
2. Connect your GitHub repository
3. Configure:
   - **Name**: `ai-chatbot-python`
   - **Environment**: `Python 3`
   - **Region**: Choose closest to you
   - **Branch**: `main`
   - **Root Directory**: Leave empty (or `ai_service` if you want)
   - **Build Command**: `pip install -r ai_service/requirements.txt`
   - **Start Command**: `cd ai_service && python app.py`
   - **Plan**: **Free**

4. Add Environment Variables:
   ```
   PYTHON_VERSION=3.11.0
   PORT=5000
   HOST=0.0.0.0
   DB_HOST=<your-database-host>
   DB_PORT=<your-database-port> (5432 for PostgreSQL, 11634 for MySQL)
   DB_USER=<your-database-user>
   DB_PASS=<your-database-password>
   DB_NAME=<your-database-name>
   DB_SSL=true
   HF_API_KEY=<your-huggingface-api-key>
   MODEL_NAME=distilgpt2
   USE_LIGHTWEIGHT_MODEL=true
   FRONTEND_URL=<will-set-after-php-service-deployed>
   DEBUG=false
   ```
   
   **Note**: `PORT` will be automatically set by Render - you can leave it or set to 5000.

5. Click **Create Web Service**

6. Wait for deployment to complete, then copy the **External URL** (e.g., `https://ai-chatbot-python.onrender.com`)

**Important**: Save this URL - you'll need it for the PHP service.

## Step 4: Deploy PHP Web Service

1. Go to Render Dashboard → **New** → **Web Service**
2. Connect the same GitHub repository
3. Configure:
   - **Name**: `ai-chatbot-php`
   - **Environment**: `Docker`
   - **Region**: Same as Python service
   - **Branch**: `main`
   - **Dockerfile Path**: `./Dockerfile`
   - **Docker Context**: `.` (root directory)
   - **Plan**: **Free**

4. Add Environment Variables:
   ```
   DB_HOST=<your-database-host>
   DB_PORT=<your-database-port> (5432 for PostgreSQL, 11634 for MySQL)
   DB_USER=<your-database-user>
   DB_PASS=<your-database-password>
   DB_NAME=<your-database-name>
   DB_SSL=true
   GOOGLE_API_KEY=<your-google-api-key>
   GOOGLE_CSE_ID=<your-google-cse-id>
   HF_API_KEY=<your-huggingface-api-key>
   PYTHON_SERVICE_URL=https://ai-chatbot-python.onrender.com
   ```
   
   **Note**: `PYTHON_SERVICE_URL` should be the External URL from Step 3.

5. Click **Create Web Service**

6. Wait for deployment to complete, then copy the **External URL** (e.g., `https://ai-chatbot-php.onrender.com`)

## Step 5: Update Service URLs

After both services are deployed:

1. **Update Python Service:**
   - Go to your Python service → **Environment** tab
   - Update `FRONTEND_URL` to your PHP service URL (e.g., `https://ai-chatbot-php.onrender.com`)
   - Click **Save Changes** (service will automatically redeploy)

2. **Verify PHP Service:**
   - Check that `PYTHON_SERVICE_URL` is set correctly in PHP service environment variables

## Step 6: Database Migration (If Using Render PostgreSQL)

If you're migrating from MySQL to PostgreSQL:

1. Export your MySQL data:
   ```bash
   mysqldump -h your-mysql-host -u user -p database_name > backup.sql
   ```

2. Convert MySQL to PostgreSQL (use a tool like `pgloader` or manual conversion)

3. Import to Render PostgreSQL:
   ```bash
   psql $DATABASE_URL < converted_backup.sql
   ```

**Note**: You can also keep using AivenDB - just use your AivenDB credentials in the environment variables.

## Step 7: Using render.yaml (Alternative Method)

Instead of manual setup, you can use the `render.yaml` file:

1. Go to Render Dashboard → **New** → **Blueprint**
2. Connect your GitHub repository
3. Render will automatically detect `render.yaml` and create both services
4. You'll still need to:
   - Set environment variables manually (secrets are not in render.yaml)
   - Update `PYTHON_SERVICE_URL` in PHP service after Python service deploys
   - Update `FRONTEND_URL` in Python service after PHP service deploys

**Note**: With Blueprint, deploy services one at a time or manually set the URLs.

## Step 8: Test Your Deployment

1. Visit your PHP service URL: `https://ai-chatbot-php.onrender.com`
2. Check logs in Render Dashboard for any errors:
   - PHP service logs
   - Python service logs
3. Test the AI chat functionality
4. Verify database connection
5. Test authentication (login/register)

## Important Notes

### Free Tier Limitations

- **Spin-down**: Services spin down after 15 minutes of inactivity
- **Cold start**: First request after spin-down takes ~30 seconds
- **Monthly hours**: 750 hours/month total across all services
- **Build time**: Python service build may take 10-15 minutes due to large dependencies (transformers, torch)

### Environment Variables

Never commit credentials to GitHub. Always use Render's environment variable settings in the dashboard.

### SSL/HTTPS

Render automatically provides SSL certificates. The code is configured to detect HTTPS automatically.

### Service Communication

- PHP service calls Python service via HTTP (public URL)
- CORS is configured to allow requests from your PHP service domain
- Both services use `RENDER_EXTERNAL_URL` environment variable (auto-set by Render)

### File Storage

- JSON files in `scripts/pcpartpicker_json/` are stored in your GitHub repository
- Runtime cache files (in `cache/` directories) are ephemeral and will be lost on redeploy
- Database is persistent

## Troubleshooting

### Build Fails

**PHP Service:**
- Check Dockerfile logs in Render Dashboard
- Ensure `composer.json` has all dependencies
- Verify Dockerfile syntax

**Python Service:**
- Check build logs in Render Dashboard
- Large dependencies (transformers, torch) may take 10-15 minutes
- If build times out, consider using a Dockerfile with pre-installed dependencies
- Check that `ai_service/requirements.txt` exists

### Database Connection Fails

- Verify environment variables are set correctly in Render dashboard
- Check SSL settings (PostgreSQL requires SSL, MySQL may vary)
- Ensure database is accessible from Render's network
- For AivenDB, check that external connections are allowed
- Check database logs for connection attempts

### Python Service Not Responding

- Check worker logs in Render Dashboard
- Verify service is deployed as **Web Service** (not Background Worker)
- Check CORS settings - ensure `FRONTEND_URL` is set correctly
- Verify `PYTHON_SERVICE_URL` is set correctly in PHP service
- Test Python service directly: `https://ai-chatbot-python.onrender.com/health`

### PHP Service Can't Connect to Python Service

- Verify `PYTHON_SERVICE_URL` is set in PHP service environment variables
- Check Python service is running (visit its URL in browser)
- Check Python service logs for errors
- Verify CORS is configured correctly in `ai_service/app.py`
- Test Python service health endpoint: `curl https://ai-chatbot-python.onrender.com/health`

### Slow First Request

- This is normal on free tier due to spin-down
- Python service may take 30-60 seconds on first request (model loading)
- Consider upgrading to paid plan for always-on service
- Implement health checks to keep services warm

### CORS Errors

- Verify `FRONTEND_URL` is set correctly in Python service
- Check that PHP service URL matches `FRONTEND_URL`
- Verify `RENDER_EXTERNAL_URL` is being used (auto-set by Render)
- Check browser console for specific CORS error messages

### Memory Limit Exceeded (512MB)

If your Python service exceeds Render's 512MB memory limit:

1. **Use Lightweight Model** (Recommended):
   - Set `MODEL_NAME=distilgpt2` in environment variables
   - Set `USE_LIGHTWEIGHT_MODEL=true`
   - This uses a much smaller model (~250MB vs ~500MB+)

2. **Memory Optimizations Already Applied**:
   - Database connection pool reduced to 2 connections
   - Model loading uses `low_cpu_mem_usage=True`
   - CPU threads limited to 2
   - Max generation length reduced

3. **If Still Exceeding Memory**:
   - Consider upgrading to Render's paid plan (more memory)
   - Or use an external AI API service instead of local models
   - Or implement lazy model loading (load only when needed)

**Note**: The default configuration now uses `distilgpt2` which should fit within 512MB.

## Environment Variables Reference

### PHP Service Required Variables

- `DB_HOST` - Database hostname
- `DB_PORT` - Database port
- `DB_USER` - Database username
- `DB_PASS` - Database password
- `DB_NAME` - Database name
- `DB_SSL` - SSL enabled (true/false)
- `GOOGLE_API_KEY` - Google API key
- `GOOGLE_CSE_ID` - Google Custom Search Engine ID
- `PYTHON_SERVICE_URL` - Python service URL (e.g., `https://ai-chatbot-python.onrender.com`)

### Python Service Required Variables

- `DB_HOST` - Database hostname
- `DB_PORT` - Database port
- `DB_USER` - Database username
- `DB_PASS` - Database password
- `DB_NAME` - Database name
- `DB_SSL` - SSL enabled (true/false)
- `HF_API_KEY` - Hugging Face API key
- `FRONTEND_URL` - PHP service URL (e.g., `https://ai-chatbot-php.onrender.com`)

### Optional Variables

- `PORT` - Service port (auto-set by Render, but can override)
- `DEBUG` - Debug mode (default: false)
- `MODEL_NAME` - AI model name (default: distilgpt2 for memory efficiency)
- `USE_LIGHTWEIGHT_MODEL` - Force lightweight model (default: true for Render free tier)

### Auto-Configured Variables (Set by Render)

- `RENDER_EXTERNAL_URL` - Public URL of the service (automatically set)
- `RENDER_SERVICE_NAME` - Name of the service (automatically set)

## Deployment Checklist

Before deploying:

- [ ] Code pushed to GitHub
- [ ] All secrets removed from code (use environment variables)
- [ ] `.env` files excluded from git (in `.gitignore`)
- [ ] Database credentials ready
- [ ] API keys ready (Google, Hugging Face)

During deployment:

- [ ] Deploy Python service first
- [ ] Copy Python service URL
- [ ] Deploy PHP service with Python service URL
- [ ] Copy PHP service URL
- [ ] Update Python service with PHP service URL
- [ ] Set all environment variables in both services
- [ ] Verify both services are running

After deployment:

- [ ] Test PHP service URL in browser
- [ ] Test Python service health endpoint
- [ ] Test authentication (login/register)
- [ ] Test AI chat functionality
- [ ] Check logs for errors
- [ ] Verify database connections

## Support

For issues specific to Render:
- [Render Documentation](https://render.com/docs)
- [Render Community](https://community.render.com)

For application-specific issues:
- Check logs in Render Dashboard
- Review `SECURITY.md` for security best practices
- Check `DEPLOYMENT_AUDIT.md` for deployment configuration
