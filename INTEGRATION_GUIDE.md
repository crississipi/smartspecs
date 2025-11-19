# Complete Integration Guide

This guide explains the complete AI integration for SmartSpecs.

## Overview

The system consists of:
1. **Python AI Service** - Handles AI model inference and PC recommendations
2. **PHP Backend** - Manages database and calls Python service
3. **Frontend** - Displays AI responses with component images

## Quick Start

### 1. Start Python AI Service

**Windows:**
```bash
cd ai_service
pip install -r requirements.txt
python app.py
```

**Linux/Mac:**
```bash
cd ai_service
pip3 install -r requirements.txt
python3 app.py
```

Or use the provided scripts:
- Windows: `ai_service/start.bat`
- Linux/Mac: `ai_service/start.sh` (make executable: `chmod +x start.sh`)

### 2. Verify Service is Running

Open browser: `http://localhost:5000/health`

Should return: `{"status": "ok", "model_loaded": true}`

### 3. Test Your Application

1. Start XAMPP (Apache)
2. Open: `http://localhost/portfolio/ai-chatbot/`
3. Login/Register
4. Send a message like: "I need a PC for gaming with 20,000 pesos budget"

## Features Implemented

### ✅ Automatic Thread Title Generation
- When a user sends their first message, the AI automatically generates a relevant thread title
- Title is extracted from the user's message using the Python AI service

### ✅ AI-Powered PC Recommendations
- Analyzes user's budget, requirements, and use case
- Provides component recommendations with pricing
- Generates HTML tables with component details
- Supports gaming, productivity, content creation, and upgrade scenarios

### ✅ Component Images
- Component images are automatically loaded for recommended parts
- Images are displayed in the recommendation tables
- Uses placeholder images (can be enhanced with real component images)

### ✅ Budget Analysis
- Automatically extracts budget from user messages (PHP, USD, ranges)
- Categorizes into: budget, mid, high, premium tiers
- Adjusts recommendations based on budget tier

## How It Works

### Message Flow

1. **User sends message** → Frontend (JavaScript)
2. **Frontend** → PHP Backend (`api/messages.php`)
3. **PHP Backend**:
   - Creates/updates thread
   - Generates thread title (calls Python `/title` endpoint)
   - Generates AI response (calls Python `/generate` endpoint)
   - Saves messages to database
4. **Python AI Service**:
   - Analyzes message
   - Extracts budget and requirements
   - Generates component recommendations
   - Returns HTML-formatted response
5. **PHP Backend** → Frontend
6. **Frontend**:
   - Displays AI response
   - Loads component images
   - Updates thread title

### AI Model

Currently using: `microsoft/DialoGPT-medium`

This can be changed to:
- A fine-tuned model for PC recommendations
- A larger model for better responses
- A custom trained model

## Customization

### Change AI Model

Edit `ai_service/app.py`:
```python
model_name = "your-model-name-here"
```

### Add More Components

Edit `ai_service/app.py` in the `PC_COMPONENTS` dictionary:
```python
PC_COMPONENTS = {
    "cpu": {
        "budget": ["Your Component"],
        # ...
    }
}
```

### Add Real Component Images

1. Get a Pexels API key (free): https://www.pexels.com/api/
2. Update `api/components.php` with Pexels API integration
3. Or use a component image database

### Improve Thread Titles

Edit `extract_thread_title()` function in `ai_service/app.py` to use a more sophisticated extraction method.

## Troubleshooting

### Python Service Not Starting

1. Check Python version: `python --version` (need 3.8+)
2. Install dependencies: `pip install -r requirements.txt`
3. Check port 5000 is not in use
4. Check Hugging Face API key is correct

### PHP Can't Connect to Python Service

1. Verify Python service is running: `http://localhost:5000/health`
2. Check firewall settings
3. Verify `AI_SERVICE_URL` in PHP (defaults to `http://localhost:5000`)

### Slow Responses

- First request is slow (model initialization)
- Consider using a smaller model
- Use GPU if available
- Implement caching for common queries

### Component Images Not Showing

- Check `api/components.php` is accessible
- Verify image URLs are valid
- Check browser console for errors

## Production Deployment

### Python Service

Use a process manager:
```bash
# PM2
pm2 start app.py --name smartspecs-ai --interpreter python3

# Or Gunicorn
gunicorn -w 4 -b 0.0.0.0:5000 app:app
```

### PHP Configuration

Set environment variable:
```php
putenv('AI_SERVICE_URL=http://your-server:5000');
```

### Security

1. Add authentication to Python service
2. Implement rate limiting
3. Validate all inputs
4. Use HTTPS in production
5. Secure API keys

## Next Steps

1. **Fine-tune the AI model** on PC component data
2. **Add real component images** from a component database
3. **Implement caching** for faster responses
4. **Add more component options** and pricing data
5. **Create a component database** with real-time pricing

## Support

For issues:
1. Check Python service logs
2. Check PHP error logs
3. Check browser console
4. Verify all services are running

