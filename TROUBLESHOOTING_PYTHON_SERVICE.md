# Troubleshooting Python Service Connection

## Problem: Fallback Messages Instead of AI Responses

If you're getting fallback messages like "Thank you for your message! I'm here to help...", it means the PHP service cannot reach the Python AI service.

## Step 1: Verify Python Service URL

**Your current PYTHON_SERVICE_URL is WRONG:**
```
https://ai-chatbot-php-production.up.railway.app/
```

This points to your **PHP service**, not the Python service!

### Find Your Correct Python Service URL

1. Go to [Railway Dashboard](https://railway.app)
2. Open your **Python service** (not the PHP service)
3. Go to **Settings** → **Networking**
4. Copy the **Public Domain** URL
5. It should look like: `https://ai-chatbot-python-production.up.railway.app` (NOT "php-production")

### Update PYTHON_SERVICE_URL in Render

1. Go to [Render Dashboard](https://dashboard.render.com)
2. Open your **PHP service** (`ai-chatbot-php`)
3. Go to **Environment** tab
4. Find `PYTHON_SERVICE_URL`
5. Update it to your **Python service URL** from Railway
6. Make sure it ends with `/` or doesn't have trailing slash (be consistent)
7. Click **Save Changes**
8. **Redeploy** your PHP service

## Step 2: Test Python Service Directly

Test if your Python service is accessible:

```bash
# Test health endpoint
curl https://your-python-service-url.up.railway.app/health

# Should return:
# {"status":"ok","model_loaded":true}
```

If this fails, your Python service isn't running or accessible.

## Step 3: Verify CORS Configuration

Your Python service needs to allow requests from your Render frontend.

### Update Python Service Environment Variables (Railway)

1. Go to Railway → Your Python Service → **Variables**
2. Add/Update:
   ```
   FRONTEND_URL=https://smartspecs.onrender.com
   ```
3. **Redeploy** your Python service

The Python service will automatically add this to allowed CORS origins.

## Step 4: Check Python Service Logs

1. Go to Railway → Your Python Service → **Deployments** → **View Logs**
2. Look for:
   - Model loading messages
   - Any errors
   - CORS errors
   - Database connection errors

## Step 5: Test the Connection

After updating the URL, test from your PHP service:

```bash
# From Render logs or test endpoint
curl -X POST https://your-python-service-url.up.railway.app/generate \
  -H "Content-Type: application/json" \
  -d '{"message": "test", "history": []}'
```

Should return a JSON response with AI-generated content, not an error.

## Common Issues

### Issue 1: Wrong URL Format
- ❌ `https://ai-chatbot-php-production.up.railway.app/` (PHP service)
- ✅ `https://ai-chatbot-python-production.up.railway.app/` (Python service)

### Issue 2: Missing Trailing Slash
- Make sure your URL is consistent:
  - Either: `https://service.up.railway.app` (no slash)
  - Or: `https://service.up.railway.app/` (with slash)
- The PHP code adds `/generate` and `/title` paths, so no trailing slash is better

### Issue 3: CORS Errors
- Check Python service logs for CORS errors
- Verify `FRONTEND_URL` is set in Python service
- Make sure it matches your Render frontend URL exactly

### Issue 4: Service Not Running
- Check Railway logs
- Verify the service is deployed and running
- Check if model is loading (can take 1-2 minutes on first start)

### Issue 5: Timeout Issues
- Python service might be slow to respond
- Check Railway service logs for timeout errors
- The PHP service has a 300-second (5 minute) timeout

## Quick Checklist

- [ ] Python service URL is correct (not PHP service URL)
- [ ] `PYTHON_SERVICE_URL` is set in Render PHP service
- [ ] `FRONTEND_URL` is set in Railway Python service
- [ ] Python service is deployed and running
- [ ] Python service `/health` endpoint works
- [ ] No CORS errors in logs
- [ ] Model is loaded (check logs)

## Still Not Working?

1. **Check Render PHP service logs:**
   - Look for "ERROR: Python AI service call failed"
   - Check the HTTP code and error message

2. **Check Railway Python service logs:**
   - Look for incoming requests
   - Check for errors or exceptions

3. **Test endpoints manually:**
   ```bash
   # Health check
   curl https://your-python-service.up.railway.app/health
   
   # Generate endpoint
   curl -X POST https://your-python-service.up.railway.app/generate \
     -H "Content-Type: application/json" \
     -d '{"message": "test", "history": []}'
   ```

4. **Verify environment variables:**
   - Render PHP: `PYTHON_SERVICE_URL`
   - Railway Python: `FRONTEND_URL`, `DB_HOST`, `DB_USER`, etc.

