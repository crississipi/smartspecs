# Force Railway to Use Nixpacks (Not Docker)

## The Problem

Railway keeps trying to use the `Dockerfile` even though you want to deploy the Python service with Nixpacks. The error occurs because Railway auto-detects Docker when it sees a Dockerfile.

## Solution 1: Set Root Directory (MOST IMPORTANT)

**This is the #1 fix you must do:**

1. Go to [Railway Dashboard](https://railway.app)
2. Open your **Python service**
3. Go to **Settings** → **Source**
4. Set **Root Directory** to: `ai_service`
5. **Save Changes**
6. **Trigger a new deployment**

When Root Directory is set to `ai_service`, Railway will:
- Only look in the `ai_service/` folder
- Not see the root `Dockerfile`
- Auto-detect Python from `requirements.txt`
- Use Nixpacks automatically

## Solution 2: Explicitly Set Builder to Nixpacks

If Root Directory doesn't work:

1. Go to Railway → Your Python Service
2. Go to **Settings** → **Build**
3. Set **Builder**: `NIXPACKS` (explicitly, not "Auto")
4. **Save** and **Redeploy**

## Solution 3: Use nixpacks.toml (Created)

I've created `ai_service/nixpacks.toml` which explicitly tells Railway to use Nixpacks.

Make sure:
- Root Directory is set to `ai_service`
- The file `ai_service/nixpacks.toml` exists (I just created it)

## Solution 4: Temporarily Rename Dockerfile

If Railway still detects Docker:

1. **Rename** `Dockerfile` to `Dockerfile.render` (for Render only)
2. **Commit and push**
3. **Redeploy on Railway**
4. Railway won't see Dockerfile anymore
5. Render can still use `Dockerfile.render` if you update render.yaml

**To rename:**
```bash
git mv Dockerfile Dockerfile.render
git commit -m "Rename Dockerfile for Railway compatibility"
git push
```

Then update `render.yaml`:
```yaml
dockerfilePath: ./Dockerfile.render
```

## Verify It's Working

After applying the fix, check Railway build logs:
- ✅ Should see: "Using Nixpacks" or "Detected Python"
- ✅ Should see: `pip install -r requirements.txt`
- ✅ Should see: `python app.py`
- ❌ Should NOT see: "Building with Docker" or "composer install"

## Why This Happens

Railway's build detection priority:
1. If `Dockerfile` exists → Use Docker
2. If `nixpacks.toml` exists → Use Nixpacks
3. If `requirements.txt` exists → Auto-detect Python + Nixpacks
4. Otherwise → Auto-detect

Since `Dockerfile` is in root, Railway sees it first and tries Docker.

## Recommended Action

**Do this now:**
1. Set **Root Directory** to `ai_service` in Railway
2. If that doesn't work, rename `Dockerfile` to `Dockerfile.render`
3. Redeploy

This will definitely fix the issue.

