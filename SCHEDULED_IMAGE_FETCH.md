# Scheduled Image Fetch Setup Guide

This guide explains how to automatically run `find_pcpartpicker_images_google.py` at scheduled times or when the website is accessed.

## Option 1: Scheduled Task (12am Philippine Time) ⭐ RECOMMENDED

### For Local Development (Windows)

1. **Open Task Scheduler:**
   - Press `Win + R`, type `taskschd.msc`, press Enter

2. **Create Basic Task:**
   - Click "Create Basic Task" in the right panel
   - Name: `Fetch Component Images`
   - Description: `Runs find_pcpartpicker_images_google.py daily at 12am`

3. **Set Trigger:**
   - Trigger: Daily
   - Start time: `12:00:00 AM`
   - Time zone: `(UTC+08:00) Philippine Time`

4. **Set Action:**
   - Action: Start a program
   - Program/script: `python` (or full path: `C:\Python311\python.exe`)
   - Add arguments: `"C:\xampp\htdocs\portfolio\ai-chatbot\scripts\find_pcpartpicker_images_google.py"`
   - Start in: `C:\xampp\htdocs\portfolio\ai-chatbot\scripts`

5. **Save and test:**
   - Right-click the task → Run

### For Local Development (Linux/Mac)

Create a cron job:

```bash
# Edit crontab
crontab -e

# Add this line (runs at 12:00 AM Philippine time = 4:00 PM UTC previous day)
# Note: Adjust timezone offset as needed
0 16 * * * cd /path/to/ai-chatbot/scripts && /usr/bin/python3 find_pcpartpicker_images_google.py >> image_cache/cron.log 2>&1
```

**For Philippine Time (UTC+8):**
- 12:00 AM PHT = 4:00 PM UTC (previous day)
- So use: `0 16 * * *` (16:00 UTC = 12:00 AM PHT next day)

### For Render Deployment

Render doesn't support cron jobs on free tier. Use an external cron service:

1. **Use cron-job.org (Free):**
   - Sign up at https://cron-job.org
   - Create new cron job
   - URL: `https://your-app.onrender.com/api/run_image_fetch.php?key=your-secret-key`
   - Schedule: Daily at 12:00 AM (Philippine Time = 4:00 PM UTC previous day)
   - Method: GET

2. **Use EasyCron (Free tier available):**
   - Sign up at https://www.easycron.com
   - Similar setup as above

### For Railway Deployment

Railway supports cron jobs via `railway.json`:

```json
{
  "cron": {
    "fetch-images": {
      "schedule": "0 16 * * *",
      "command": "cd scripts && python3 find_pcpartpicker_images_google.py"
    }
  }
}
```

**Note:** Railway cron runs in UTC. 12:00 AM PHT = 16:00 UTC (previous day).

### For Fly.io Deployment

Fly.io supports cron jobs via `fly.toml`:

```toml
[[services]]
  [[services.schedule]]
    schedule = "0 16 * * *"  # 12:00 AM PHT = 4:00 PM UTC
    command = "cd scripts && python3 find_pcpartpicker_images_google.py"
```

## Option 2: Run on Website Access (Background Process)

The script will run in the background when someone visits a specific URL. **The process continues even if the user closes the browser.**

### Method A: Using Existing Endpoint (`api/fetch_images.php`)

This endpoint already exists and handles status checking and running:

```bash
# Check status
curl https://your-site.com/api/fetch_images.php?action=status

# Run script (starts in background)
curl -X POST https://your-site.com/api/fetch_images.php?action=run
```

### Method B: Using New Endpoint (`api/run_image_fetch.php`)

1. **Set API Key** (for security):
   ```bash
   # In your .env file or environment variables
   IMAGE_FETCH_API_KEY=your-secret-key-here
   ```

2. **Access the endpoint:**
   ```
   https://your-site.com/api/run_image_fetch.php?key=your-secret-key-here
   ```

3. **Or trigger from PHP code:**
   ```php
   // In your index.php or any page
   if (isset($_GET['trigger_fetch']) && $_GET['trigger_fetch'] === 'your-secret-key') {
       file_get_contents('http://localhost/api/run_image_fetch.php?key=your-secret-key');
   }
   ```

### Auto-trigger on First Visit Each Day

Add this to your `index.php` (after user authentication check):

```php
<?php
// Auto-trigger image fetch once per day (runs in background)
if ($isLoggedIn) {
    $last_fetch_file = __DIR__ . '/scripts/image_cache/.last_auto_fetch';
    $today = date('Y-m-d');
    
    // Check if we've already triggered today
    if (!file_exists($last_fetch_file) || file_get_contents($last_fetch_file) !== $today) {
        // Trigger fetch in background (non-blocking)
        $url = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/api/fetch_images.php?action=run';
        @file_get_contents($url, false, stream_context_create([
            'http' => [
                'timeout' => 1, // 1 second timeout (don't wait for response)
                'ignore_errors' => true
            ]
        ]));
        
        // Mark as triggered today
        file_put_contents($last_fetch_file, $today);
    }
}
?>
```

### How It Works

- The PHP endpoint (`api/run_image_fetch.php`) starts the Python script in the background
- The script runs independently of the web request
- **The process continues even if the user closes the browser** (runs on server)
- A lock file prevents multiple instances from running simultaneously
- Progress is saved automatically (the script has built-in progress tracking)

### Check Status

```bash
# Check if script is running
curl https://your-site.com/api/run_image_fetch.php?key=your-secret-key

# View logs (if deployed)
# Logs are saved to: scripts/image_cache/fetch_output.log
```

## Option 3: Manual Trigger (Recommended for Testing)

For testing, you can manually trigger it:

```bash
# Local
cd scripts
python find_pcpartpicker_images_google.py

# Or via API
curl http://localhost/api/run_image_fetch.php?key=your-secret-key
```

## Process Management

### Will the process stop when user exits?

**No!** The script runs on the **server**, not in the user's browser. Once started:
- ✅ Continues running even if user closes browser
- ✅ Continues running even if user navigates away
- ✅ Only stops when:
  - Script completes
  - Server is restarted
  - Process is manually killed
  - Daily query limit is reached

### Check if script is running:

```bash
# Windows
tasklist | findstr python

# Linux/Mac
ps aux | grep find_pcpartpicker_images_google.py
```

### Stop the script:

```bash
# Find PID
# Windows: tasklist | findstr python
# Linux/Mac: ps aux | grep find_pcpartpicker_images_google.py

# Kill process
# Windows: taskkill /PID <pid> /F
# Linux/Mac: kill <pid>
```

## Security Notes

1. **Set a strong API key** in environment variables
2. **Don't expose the API endpoint** publicly without authentication
3. **Use HTTPS** in production
4. **Rate limit** the endpoint to prevent abuse

## Troubleshooting

### Script not running:
- Check Python is installed: `python --version`
- Check script path is correct
- Check file permissions (Linux/Mac): `chmod +x find_pcpartpicker_images_google.py`
- Check logs: `scripts/image_cache/fetch_output.log`

### Script stops unexpectedly:
- Check daily query limit (100 queries/day)
- Check Google API key is valid
- Check server has enough resources
- Check logs for errors

### Multiple instances running:
- The lock file should prevent this
- If lock file exists but script isn't running, delete: `scripts/image_cache/.fetch_lock`

