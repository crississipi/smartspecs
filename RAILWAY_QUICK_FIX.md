# Quick Fix for Railway Docker Error

## The Problem

Railway is trying to use the `Dockerfile` (for PHP) instead of Nixpacks (for Python). The error occurs because Railway detects the Dockerfile in the root directory.

## Solution: Configure Railway to Use Nixpacks

### Option 1: Set Root Directory (Easiest)

1. Go to [Railway Dashboard](https://railway.app)
2. Open your **Python service**
3. Go to **Settings** → **Source**
4. Set **Root Directory** to: `ai_service`
5. **Save Changes**
6. **Redeploy**

This tells Railway to:
- Look in `ai_service/` folder
- Use `ai_service/railway.json` or auto-detect Python
- Ignore the root `Dockerfile`

### Option 2: Force Nixpacks in Railway Settings

1. Go to Railway → Your Python Service
2. Go to **Settings** → **Build**
3. Set **Builder**: `NIXPACKS` (not Docker)
4. Set **Build Command**: Leave empty (auto-detect) or `pip install -r requirements.txt`
5. Set **Start Command**: `python app.py`
6. **Save** and **Redeploy**

### Option 3: Use railway.toml (Created)

I've created `railway.toml` in the root. If Railway still uses Docker:

1. Make sure **Root Directory** is set to `ai_service`
2. Or move `railway.toml` to `ai_service/railway.toml`

## Verify It's Working

After redeploying, check the build logs:
- ✅ Should see: "Detected Python" or "Installing Python dependencies"
- ✅ Should see: `pip install -r requirements.txt`
- ❌ Should NOT see: "composer install" or Docker build steps

## If Still Failing

1. **Delete the service** and create a new one
2. When creating, set **Root Directory** to `ai_service` immediately
3. This prevents Railway from detecting the Dockerfile

## Why This Happens

- Railway auto-detects build method
- If it sees `Dockerfile` in root, it tries to use Docker
- Dockerfile is for PHP service (Render), not Python
- Python service should use Nixpacks (auto-detects from `requirements.txt`)

## Summary

**The fix:** Set **Root Directory** to `ai_service` in Railway settings. This is the most reliable solution.

