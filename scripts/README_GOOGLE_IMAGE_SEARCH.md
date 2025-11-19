# Google Custom Search for PCPartPicker Images

This script uses Google Custom Search Engine to find PCPartPicker image URLs for components.

## Setup

1. **Get Google API Key:**
   - Go to [Google Cloud Console](https://console.cloud.google.com/)
   - Enable "Custom Search API"
   - Create credentials (API Key)
   - Copy your API Key

2. **Set Environment Variable:**
   
   **Windows (CMD):**
   ```cmd
   set GOOGLE_API_KEY=your_api_key_here
   ```
   
   **Windows (PowerShell):**
   ```powershell
   $env:GOOGLE_API_KEY="your_api_key_here"
   ```
   
   **Linux/Mac:**
   ```bash
   export GOOGLE_API_KEY=your_api_key_here
   ```

3. **Install Required Python Package:**
   ```bash
   pip install requests
   ```

## Usage

### Basic Usage:
```bash
python scripts/find_pcpartpicker_images_google.py
```

### Process Specific Files:
```bash
python scripts/find_pcpartpicker_images_google.py cpu memory
```

## Features

- ✅ **Exact Matching**: Only matches images to exact component names
- ✅ **Caching**: Saves results to avoid duplicate API calls
- ✅ **Rate Limiting**: Respects 10,000 queries/day limit (5 queries/minute)
- ✅ **Progress Tracking**: Saves progress so you can resume if interrupted
- ✅ **Smart Filtering**: Only searches for components that don't already have PCPartPicker URLs

## Daily Query Limit

- **Limit**: 100 queries/day
- **Rate**: ~5 queries/minute (conservative to avoid hitting limit)
- **Tracking**: Automatically tracked in `scripts/image_cache/query_log.json`

## Cache

- Image URLs are cached in `scripts/image_cache/image_urls_cache.json`
- Prevents re-searching for the same component
- Cache persists between runs

## Output

The script will:
1. Search Google Custom Search Engine for each component
2. Find the best matching PCPartPicker image URL
3. Update the JSON files with the found image URLs
4. Save progress automatically

## Notes

- Only searches for components that currently have placeholder SVG images
- Skips components that already have PCPartPicker image URLs
- Can be interrupted (Ctrl+C) and resumed later
- Progress is saved after each file and every 10 updates

## Troubleshooting

**Error: GOOGLE_API_KEY not set**
- Make sure you've set the environment variable before running the script
- Check that the variable name is exactly `GOOGLE_API_KEY`

**Daily limit reached**
- The script will stop automatically when the limit is reached
- Wait until the next day (resets at midnight Pacific Time)
- Check `scripts/image_cache/query_log.json` for current usage

**No results found**
- Some components might not have PCPartPicker product pages
- The script will mark them as "no match" to avoid re-searching
- You can manually add image URLs to the JSON files if needed

