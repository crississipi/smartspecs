# How to Find Your Railway Python Service URL

## Step 1: Go to Railway Dashboard

1. Go to [railway.app](https://railway.app)
2. Log in to your account
3. Open your project

## Step 2: Find Your Python Service

1. In your project, you should see your **Python service** (the one you just deployed)
2. Click on the **Python service** (not the PHP service)

## Step 3: Get the Public URL

### Method 1: From Service Overview

1. In your Python service dashboard, look for **"Settings"** tab
2. Go to **Settings** → **Networking**
3. You'll see **"Public Domain"** or **"Generate Domain"**
4. Click **"Generate Domain"** if you haven't already
5. Copy the URL (e.g., `https://ai-chatbot-python-production.up.railway.app`)

### Method 2: From Deployments

1. Go to **Deployments** tab
2. Click on the latest successful deployment
3. Look for the **"Public URL"** or **"Domain"** in the deployment details

### Method 3: From Service Settings

1. Go to **Settings** → **Networking**
2. Under **"Public Networking"**, you'll see:
   - **"Generate Domain"** button (if not generated)
   - Or the domain URL (if already generated)

## Step 4: Copy the URL

The URL will look like:
```
https://your-service-name-production.up.railway.app
```

Or:
```
https://your-service-name.up.railway.app
```

**Important:** 
- Copy the **full URL** including `https://`
- **Don't include a trailing slash** (`/`) at the end
- Example: `https://ai-chatbot-python-production.up.railway.app` ✅
- Not: `https://ai-chatbot-python-production.up.railway.app/` ❌

## Step 5: Set in Render

1. Go to [Render Dashboard](https://dashboard.render.com)
2. Open your **PHP service** (`ai-chatbot-php`)
3. Go to **Environment** tab
4. Find `PYTHON_SERVICE_URL`
5. Update it with your Railway URL:
   ```
   https://your-python-service-url.up.railway.app
   ```
6. Click **Save Changes**
7. **Redeploy** your PHP service (or it will auto-redeploy)

## Step 6: Verify Connection

After setting the URL, test it:

1. **Test Python service directly:**
   ```bash
   curl https://your-python-service-url.up.railway.app/health
   ```
   Should return: `{"status":"ok","model_loaded":true}`

2. **Test from your frontend:**
   - Send a message in your app
   - Check if AI responses work (not fallback messages)

## Troubleshooting

### Can't find the URL?
- Make sure your service is **deployed and running**
- Check if you need to **generate a domain** first
- Look in **Settings** → **Networking** → **Public Domain**

### URL not working?
- Make sure the service is **running** (not sleeping)
- Check Railway logs for errors
- Verify the service is accessible: `curl https://your-url/health`

### Still getting fallback messages?
- Double-check the URL is correct (no trailing slash)
- Verify `PYTHON_SERVICE_URL` is set in Render
- Check Render PHP service logs for connection errors
- Make sure Python service has `FRONTEND_URL` set to your Render URL

