# Deployment Guide for Replit

This guide will help you deploy your AI Chatbot project to Replit.

## Prerequisites

1. **Replit Account** - Sign up at [replit.com](https://replit.com)
2. **GitHub Account** (optional) - For version control
3. **Database** - You can use AivenDB or any MySQL/PostgreSQL database

## Why Replit?

Replit offers:
- **Free background workers** - No payment required for background services
- **Always-on option** - Free tier allows always-on services
- **Easy deployment** - Simple one-click deployment
- **Multiple languages** - Supports both PHP and Python in the same repl
- **Built-in IDE** - Code directly in the browser

## Step 1: Create a New Repl

1. Go to [Replit](https://replit.com) and sign in
2. Click **+ Create Repl** or **Create** button
3. Choose **Import from GitHub** if your code is on GitHub, or **Blank Repl**
4. If importing from GitHub:
   - Enter your repository URL: `https://github.com/yourusername/ai-chatbot.git`
   - Choose language: **PHP**
   - Click **Import from GitHub**
5. If creating blank repl:
   - Choose template: **PHP**
   - Name: `ai-chatbot`
   - Click **Create Repl**

## Step 2: Configure Environment Variables

1. In your Repl, click on the **Secrets** tab (lock icon) in the left sidebar
2. Add the following environment variables:

### Required Environment Variables:

```
DB_HOST=your-database-host
DB_PORT=11634 (for MySQL) or 5432 (for PostgreSQL)
DB_USER=your-database-user
DB_PASS=your-database-password
DB_NAME=defaultdb
DB_SSL=true
GOOGLE_API_KEY=your-google-api-key
GOOGLE_CSE_ID=your-google-cse-id
HF_API_KEY=your-huggingface-api-key
```

### Optional Environment Variables:

```
PORT=5000 (optional, for Python service - defaults to 5000)
DEBUG=false
MODEL_NAME=microsoft/DialoGPT-medium
FRONTEND_URL=https://your-repl-slug.your-username.repl.co (auto-configured)
PYTHON_SERVICE_URL=http://localhost:5000 (auto-configured)
```

**Important**: 
- Never commit credentials to GitHub. Always use Replit Secrets.
- See `SECURITY.md` for security best practices.
- Copy `.env.example` to `.env` for local development (never commit `.env`).

## Step 3: Install Dependencies

The first time you run the repl, it will automatically:

1. **Install PHP dependencies** via `composer install` (defined in `.replit`)
2. **Install Python dependencies** via `pip install -r ai_service/requirements.txt`

You can also install manually:
```bash
# Install PHP dependencies
composer install --no-dev --optimize-autoloader

# Install Python dependencies
cd ai_service
pip install -r requirements.txt
cd ..
```

**Note**: The first build may take 10-15 minutes due to large Python dependencies (transformers, torch, etc.)

## Step 4: Run Your Application

1. Click the **Run** button in Replit (or press `Ctrl+Enter`)
2. The `start.sh` script will automatically:
   - Start PHP server on the main port (assigned by Replit)
   - Start Python service on port 5000 (or custom PORT)
   - Configure CORS for both services
   - Set up proper URLs

3. You should see output like:
```
=========================================
Starting AI Chatbot Services...
=========================================
Base URL: https://your-repl-slug.your-username.repl.co
PHP Service: https://your-repl-slug.your-username.repl.co
Python Service: http://localhost:5000
...
Services are running!
```

## Step 5: Access Your Application

1. After the services start, Replit will automatically:
   - Assign a public URL like: `https://your-repl-slug.your-username.repl.co`
   - Open a webview showing your application

2. The application will be accessible at:
   - Main URL: `https://your-repl-slug.your-username.repl.co`
   - Both PHP frontend and Python API run on the same domain

## Step 6: Enable Always-On (Optional)

By default, Replit free tier services sleep after inactivity. To keep your service always running:

1. Go to your Repl settings
2. Under **Usage**, find **Always On**
3. Enable it (free tier includes some always-on hours)

**Note**: Free tier has limitations on always-on hours per month. Consider upgrading for production use.

## Architecture

Your application runs with:
- **PHP Frontend**: Serves web pages, handles authentication, database operations
- **Python AI Service**: Runs Flask API on port 5000, handles AI model inference
- **Both services** communicate via HTTP on `localhost`

The `start.sh` script manages both services:
- Starts PHP built-in server in background
- Starts Python Flask service in background
- Handles process cleanup on shutdown
- Configures environment variables automatically

## Environment Variables

### Required Variables
- `DB_HOST` - Database hostname
- `DB_PORT` - Database port (11634 for MySQL, 5432 for PostgreSQL)
- `DB_USER` - Database username
- `DB_PASS` - Database password
- `DB_NAME` - Database name
- `DB_SSL` - SSL enabled (true/false)
- `HF_API_KEY` - Hugging Face API key for models
- `GOOGLE_API_KEY` - Google API key for image search

### Optional Variables
- `PORT` - Python service port (default: 5000)
- `DEBUG` - Debug mode (default: false)
- `MODEL_NAME` - AI model name (default: microsoft/DialoGPT-medium)
- `FRONTEND_URL` - Frontend URL (auto-detected from Replit)
- `PYTHON_SERVICE_URL` - Python service URL (auto-configured)

### Auto-Configured Variables
These are automatically set by Replit and the startup script:
- `REPL_SLUG` - Your repl identifier
- `REPL_OWNER` - Your username
- `REPL_ID` - Repl ID

## File Structure

```
ai-chatbot/
├── .replit              # Replit configuration
├── replit.nix           # Nix package dependencies
├── start.sh             # Startup script (runs both services)
├── config.php           # PHP configuration
├── index.php            # Main PHP entry point
├── api/                 # PHP API endpoints
├── ai_service/
│   ├── app.py           # Python Flask service
│   ├── requirements.txt # Python dependencies
│   └── ...
├── composer.json        # PHP dependencies
└── ...
```

## Troubleshooting

### Services Won't Start

1. **Check logs**:
   - PHP logs: `php-server.log`
   - Python logs: `python-server.log`

2. **Check dependencies**:
   ```bash
   composer install
   cd ai_service && pip install -r requirements.txt
   ```

3. **Check environment variables**:
   - Go to Secrets tab
   - Ensure all required variables are set

### Database Connection Fails

1. **Verify credentials** in Secrets tab
2. **Check SSL settings** - AivenDB requires SSL
3. **Test connection**:
   ```bash
   php test_connection.php
   ```

### Python Service Not Responding

1. **Check Python logs**: `python-server.log`
2. **Verify port**: Check if port 5000 is in use
3. **Check CORS**: Ensure `FRONTEND_URL` is set correctly

### Slow First Request

- First request after sleep takes longer due to cold start
- Python model loading takes time (30-60 seconds)
- Consider enabling Always-On for faster responses

### Build Fails

1. **Memory limits**: Large Python packages may exceed memory
   - Try building Python dependencies separately
   - Use smaller model variants if possible

2. **Dependency conflicts**:
   ```bash
   cd ai_service
   pip install --upgrade pip
   pip install -r requirements.txt --no-cache-dir
   ```

## Updating Your Application

1. **Via Replit Editor**:
   - Edit files directly in Replit
   - Click **Run** to redeploy

2. **Via GitHub**:
   - Push changes to GitHub
   - In Replit: Click **Version Control** → **Pull from GitHub**

## Performance Tips

1. **Enable Always-On**: Prevents cold starts
2. **Use Model Caching**: Python service caches loaded models
3. **Database Connection Pooling**: Already configured in Python service
4. **Optimize Dependencies**: Remove unused packages

## Free Tier Limitations

- **Spin-down**: Services sleep after inactivity (unless Always-On enabled)
- **Resource limits**: CPU and memory limits apply
- **Storage**: Limited disk space
- **Always-On hours**: Limited free always-on hours per month

## Production Considerations

For production use, consider:
- Upgrading to Replit Hacker/Pro plan for more resources
- Using external database (AivenDB, Supabase, etc.)
- Implementing rate limiting
- Adding monitoring and error tracking
- Setting up backups

## Support

For issues specific to Replit:
- [Replit Documentation](https://docs.replit.com)
- [Replit Community](https://replit.com/talk)

For application-specific issues:
- Check logs in `php-server.log` and `python-server.log`
- Review error messages in Replit console
- Verify environment variables are set correctly

## Migration from Render

If you're migrating from Render:
1. All environment variables work the same way
2. Update `PYTHON_SERVICE_URL` if needed (auto-configured on Replit)
3. Database connection settings remain the same
4. No changes needed to application code
