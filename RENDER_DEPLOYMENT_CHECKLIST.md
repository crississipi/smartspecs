# Render Deployment Checklist

> **⚠️ NOTE: This project has been migrated to Replit. This checklist is kept for reference only.**
> **See `DEPLOYMENT.md` for Replit deployment instructions.**

## ✅ Files Created/Updated (Historical - Render Deployment)

- [x] `render.yaml` - Multi-service configuration
- [x] `Dockerfile` - PHP web service Docker configuration
- [x] `.dockerignore` - Files to exclude from Docker build
- [x] `ai_service/Procfile` - Python worker startup
- [x] `.renderignore` - Files to exclude from deployment
- [x] `config.php` - Updated to use environment variables
- [x] `ai_service/app.py` - Updated to use environment variables and CORS
- [x] `api/messages.php` - Updated to use PYTHON_SERVICE_URL
- [x] `DEPLOYMENT.md` - Complete deployment guide

## 🔧 Configuration Changes Made

### Dockerfile
- ✅ PHP 8.2 with Apache
- ✅ All required PHP extensions installed
- ✅ Composer dependencies installed
- ✅ Handles Render's PORT environment variable

### config.php
- ✅ Database credentials now use environment variables
- ✅ CORS allows Render domain automatically
- ✅ HTTPS detection for secure cookies

### ai_service/app.py
- ✅ Database config uses environment variables
- ✅ CORS configured for Render domains
- ✅ Port and host from environment variables

### api/messages.php
- ✅ Python service URL from environment variable

## 📋 Next Steps

1. **Push to GitHub**
   ```bash
   git add .
   git commit -m "Prepare for Render deployment"
   git push origin main
   ```

2. **Create PostgreSQL Database on Render** (optional)
   - Dashboard → New → PostgreSQL
   - Name: `ai-chatbot-db`
   - Plan: Free

3. **Deploy PHP Web Service**
   - Dashboard → New → Web Service
   - Connect GitHub repo
   - **Environment**: Docker (not PHP)
   - **Dockerfile Path**: `./Dockerfile`
   - Use settings from `DEPLOYMENT.md`

4. **Deploy Python Worker**
   - Dashboard → New → Background Worker
   - Connect same GitHub repo
   - Use settings from `DEPLOYMENT.md`

5. **Set Environment Variables**
   - See `DEPLOYMENT.md` for complete list
   - Important: Set `PYTHON_SERVICE_URL` after Python service is deployed

## 🔑 Required Environment Variables

### PHP Service
```
DB_HOST=<database-host>
DB_PORT=5432 (PostgreSQL) or 11634 (MySQL)
DB_USER=<database-user>
DB_PASS=<database-password>
DB_NAME=defaultdb
DB_SSL=true
GOOGLE_API_KEY=<your-key>
HF_API_KEY=<your-key>
PYTHON_SERVICE_URL=https://ai-chatbot-python.onrender.com
```

### Python Service
```
PORT=5000
HOST=0.0.0.0
DB_HOST=<same-as-php>
DB_PORT=<same-as-php>
DB_USER=<same-as-php>
DB_PASS=<same-as-php>
DB_NAME=defaultdb
DB_SSL=true
HF_API_KEY=<your-key>
MODEL_NAME=microsoft/DialoGPT-medium
FRONTEND_URL=https://ai-chatbot-php.onrender.com
PYTHON_VERSION=3.11.0
DEBUG=false
```

## ⚠️ Important Notes

1. **Database**: You can keep using AivenDB (MySQL) or switch to Render PostgreSQL
2. **Service URLs**: Update `PYTHON_SERVICE_URL` and `FRONTEND_URL` after deployment
3. **Free Tier**: Services spin down after 15 min inactivity
4. **Build Time**: Python service may take 10-15 minutes to build

## 🐛 Troubleshooting

- Check logs in Render Dashboard
- Verify all environment variables are set
- Ensure database is accessible
- Check CORS settings if API calls fail

