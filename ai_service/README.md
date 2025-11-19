# AI Service for SmartSpecs

Python Flask service that provides AI-powered PC recommendations using Hugging Face models.

## Setup

1. Install Python 3.8 or higher
2. Install dependencies:
```bash
pip install -r requirements.txt
```

3. Set environment variable (optional, already in code):
```bash
export HF_API_KEY
```

4. Run the service:
```bash
python app.py
```

The service will run on `http://localhost:5000`

## Endpoints

### POST /generate
Generate PC recommendation based on user message.

Request:
```json
{
  "message": "I need a PC for gaming with 20,000 pesos budget",
  "history": []
}
```

Response:
```json
{
  "success": true,
  "response": "<html>...</html>",
  "recommendation": {...}
}
```

### POST /title
Extract thread title from user message.

Request:
```json
{
  "message": "I need a PC for gaming with 20,000 pesos budget"
}
```

Response:
```json
{
  "success": true,
  "title": "I need a PC for gaming with 20,000 pesos"
}
```

### GET /health
Check service health.

## Integration with PHP

The PHP backend calls this service via HTTP requests.

