# Railway Deployment Guide

Railway is an excellent alternative to Render for Python services with AI models. It offers:
- **$5 free credit per month** (enough for small projects)
- **512MB-1GB RAM** (more than Render's 512MB)
- **Better memory management**
- **Easy environment variable configuration**

## Step 1: Create Railway Account

1. Go to [railway.app](https://railway.app)
2. Sign up with GitHub
3. Create a new project

## Step 2: Deploy Python Service

1. Click **"New Project"** > **"Deploy from GitHub repo"**
2. Select your repository
3. Railway will auto-detect Python
4. Set the **Root Directory** to `ai_service`
5. Set the **Start Command** to: `python app.py`

## Step 3: Configure Environment Variables

In Railway dashboard, go to your service > **Variables** tab and add:

```
PORT=5000
HOST=0.0.0.0
DB_HOST=your_db_host
DB_PORT=3306
DB_USER=your_db_user
DB_PASS=your_db_password
DB_NAME=your_db_name
DB_SSL=true
HF_API_KEY=your_hf_api_key
MODEL_NAME=distilgpt2
USE_LIGHTWEIGHT_MODEL=true
FRONTEND_URL=https://your-php-service.onrender.com
DEBUG=false
```

## Step 4: Get Service URL

1. Railway will provide a URL like: `https://your-service.up.railway.app`
2. Copy this URL
3. Update your PHP service's `PYTHON_SERVICE_URL` environment variable

## Step 5: Update PHP Service (Render)

In your Render PHP service, update the `PYTHON_SERVICE_URL` environment variable to your Railway URL.

## Memory Optimization Tips

Railway's free tier has more memory, but you can still optimize:

1. Use `distilgpt2` model (already configured)
2. Set `USE_LIGHTWEIGHT_MODEL=true`
3. Monitor usage in Railway dashboard

## Cost

- **Free tier**: $5 credit/month
- **Estimated cost**: ~$2-4/month for this service
- **Upgrade**: If you exceed, you'll be notified

