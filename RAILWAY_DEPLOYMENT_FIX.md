# Railway Deployment Fix

## Problem: Composer Build Error

You're getting a composer error because Railway is trying to use the Dockerfile (for PHP) instead of the Python configuration.

## Solution: Configure Railway Correctly

### For Python Service on Railway:

1. **In Railway Dashboard:**
   - Go to your **Python service** (not PHP)
   - Go to **Settings** → **Source**
   - Set **Root Directory** to: `ai_service`
   - This tells Railway to look in the `ai_service` folder

2. **Or use the railway.json in ai_service:**
   - I've created `ai_service/railway.json` 
   - Railway will automatically detect this when root directory is set to `ai_service`

3. **Make sure Railway detects Python:**
   - Railway should auto-detect Python from `requirements.txt` in `ai_service/`
   - If not, go to **Settings** → **Build** and set:
     - **Build Command**: `pip install -r requirements.txt`
     - **Start Command**: `python app.py`

### For PHP Service (if deploying to Railway):

**Note:** PHP service is designed for Render. If you must use Railway:

1. **Don't use Dockerfile** - Use Nixpacks instead:
   - In Railway → Settings → Build
   - Set **Builder**: `NIXPACKS` (not Docker)
   - Railway will auto-detect PHP

2. **Or fix the Dockerfile order** (already fixed in the updated Dockerfile)

## Quick Fix Steps:

### Option 1: Python Service Only (Recommended)

1. In Railway, make sure you're deploying the **Python service**
2. Set **Root Directory** to: `ai_service`
3. Railway will use `ai_service/railway.json` or auto-detect Python
4. Deploy PHP service to Render (as originally designed)

### Option 2: Both Services on Railway

1. **Python Service:**
   - Root Directory: `ai_service`
   - Uses `ai_service/railway.json`

2. **PHP Service:**
   - Root Directory: `.` (root)
   - Builder: `NIXPACKS` (not Docker)
   - Or use the fixed Dockerfile

## Verify Configuration:

After setting root directory, check Railway logs:
- Should see: "Detected Python project" or "Installing Python dependencies"
- Should NOT see: "composer install" errors

## Still Getting Errors?

1. **Check which service is failing:**
   - Python service should be in `ai_service/` directory
   - PHP service should be in root directory

2. **Verify root directory:**
   - Railway → Service → Settings → Source → Root Directory
   - Python: `ai_service`
   - PHP: `.` (root)

3. **Check build logs:**
   - Look for "Detected Python" or "Detected PHP"
   - If it says "Detected PHP" for Python service, root directory is wrong

