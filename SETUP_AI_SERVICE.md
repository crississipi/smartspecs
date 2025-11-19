# AI Service Setup Guide

This guide explains how to set up the Python AI service for SmartSpecs.

## Prerequisites

1. Python 3.8 or higher installed
2. pip (Python package manager)
3. Your Hugging Face API key (already configured)

## Step 1: Install Python Dependencies

Navigate to the `ai_service` directory:

```bash
cd ai_service
pip install -r requirements.txt
```

**Note:** This may take several minutes as it downloads large AI models.

## Step 2: Run the AI Service

Start the Flask service:

```bash
python app.py
```

The service will start on `http://localhost:5000`

You should see:
```
Loading AI model...
Model loaded successfully!
 * Running on http://0.0.0.0:5000
```

## Step 3: Test the Service

Open a new terminal and test the service:

```bash
# Test health endpoint
curl http://localhost:5000/health

# Test title generation
curl -X POST http://localhost:5000/title \
  -H "Content-Type: application/json" \
  -d '{"message": "I need a PC for gaming with 20,000 pesos budget"}'

# Test AI generation
curl -X POST http://localhost:5000/generate \
  -H "Content-Type: application/json" \
  -d '{"message": "I need a PC for gaming with 20,000 pesos budget"}'
```

## Step 4: Keep Service Running

For development, keep the Python service running in a separate terminal window.

For production, use a process manager like:
- **PM2** (Node.js process manager, works with Python)
- **systemd** (Linux)
- **Supervisor** (Python process manager)

### Using PM2 (Recommended)

```bash
# Install PM2 globally
npm install -g pm2

# Start the service
cd ai_service
pm2 start app.py --name smartspecs-ai --interpreter python3

# Save PM2 configuration
pm2 save

# Set PM2 to start on boot
pm2 startup
```

## Step 5: Configure PHP Backend

The PHP backend is already configured to call `http://localhost:5000` by default.

If your Python service runs on a different port or URL, set the environment variable:

```php
// In config.php or .env
putenv('AI_SERVICE_URL=http://localhost:5000');
```

## Troubleshooting

### Model Loading Issues

If the model fails to load:
1. Check your internet connection (first run downloads the model)
2. Verify your Hugging Face API key is correct
3. Check available disk space (models can be several GB)

### Port Already in Use

If port 5000 is already in use:
```bash
# Change port in app.py
port = int(os.getenv('PORT', 5001))  # Use 5001 instead
```

Then update PHP config to use the new port.

### Slow Responses

The first request may be slow as the model initializes. Subsequent requests should be faster.

For production, consider:
- Using a GPU for faster inference
- Implementing caching
- Using a smaller/faster model

## Model Customization

To use a different Hugging Face model, edit `ai_service/app.py`:

```python
model_name = "your-model-name-here"
```

Popular alternatives:
- `gpt2` - Smaller, faster
- `microsoft/DialoGPT-small` - Smaller version
- Fine-tune your own model on PC component data

## Training Your Own Model

To train a custom model for PC recommendations:

1. Prepare a dataset of PC-related Q&A pairs
2. Use Hugging Face's training scripts
3. Upload your fine-tuned model to Hugging Face
4. Update `model_name` in `app.py`

See Hugging Face documentation for training guides.

## Production Deployment

For production:
1. Use a production WSGI server (Gunicorn, uWSGI)
2. Set up proper logging
3. Implement rate limiting
4. Use environment variables for sensitive data
5. Set up monitoring and alerts

Example with Gunicorn:
```bash
pip install gunicorn
gunicorn -w 4 -b 0.0.0.0:5000 app:app
```

