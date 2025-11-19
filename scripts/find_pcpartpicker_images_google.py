#!/usr/bin/env python3
"""
Find PCPartPicker image URLs using Google Custom Search Engine.
Searches for exact component matches on PCPartPicker and extracts image URLs.
"""

import json
import time
import re
import os
from pathlib import Path
from typing import Dict, List, Optional, Tuple
from urllib.parse import quote, urlparse
import requests
from concurrent.futures import ThreadPoolExecutor, as_completed
from threading import Lock
from queue import Queue

# Directory containing JSON files
JSON_DIR = Path(__file__).parent / 'pcpartpicker_json'
CACHE_DIR = Path(__file__).parent / 'image_cache'
CACHE_DIR.mkdir(exist_ok=True)

# Load environment variables from .env file (if exists) for local development
try:
    from dotenv import load_dotenv
    load_dotenv()
except ImportError:
    pass  # python-dotenv not installed, skip

# Google Custom Search Engine Configuration
GOOGLE_CSE_ID = os.getenv('GOOGLE_CSE_ID', '')  # Must be set via environment variable
GOOGLE_API_KEY = os.getenv('GOOGLE_API_KEY', '')  # Set via environment variable

# Rate limiting: Google Custom Search API has strict per-minute limits
# Free tier: ~100 queries per 100 seconds = ~1 query per second maximum
# To be safe, we'll use sequential processing with delays
QUERIES_PER_MINUTE = 30  # Conservative limit
MIN_DELAY_BETWEEN_QUERIES = 2.5  # seconds - 2.5s = ~24 queries/minute (well under limit)
MAX_CONCURRENT_REQUESTS = 1  # Sequential processing only - Google enforces strict per-minute limits

# Daily limit tracking
DAILY_QUERY_LIMIT = 100  # Limited to 100 queries per day
QUERY_LOG_FILE = CACHE_DIR / 'query_log.json'
PROGRESS_FILE = CACHE_DIR / 'progress.json'  # Track last processed component

# Thread-safe rate limiter
_rate_limiter_lock = Lock()
_last_query_time = [0.0]  # Use list to allow modification in nested functions
_rate_limited = [False]  # Flag to pause all requests when rate limited
_backoff_until = [0.0]  # Timestamp until which we should back off

def load_query_log():
    """Load daily query count - resets to 0 if date doesn't match today"""
    if QUERY_LOG_FILE.exists():
        try:
            with open(QUERY_LOG_FILE, 'r') as f:
                log = json.load(f)
                today = time.strftime('%Y-%m-%d')
                log_date = log.get('date')
                if log_date == today:
                    return log.get('count', 0)
                else:
                    # Different day - reset count to 0 and update the file
                    save_query_log(0)
                    return 0
        except:
            pass
    return 0

def save_query_log(count: int):
    """Save daily query count"""
    today = time.strftime('%Y-%m-%d')
    with open(QUERY_LOG_FILE, 'w') as f:
        json.dump({'date': today, 'count': count}, f)

def check_daily_limit() -> bool:
    """Check if we've hit the daily query limit"""
    count = load_query_log()  # This already resets to 0 if date doesn't match
    if count >= DAILY_QUERY_LIMIT:
        print(f"⚠️  Daily query limit reached: {count}/{DAILY_QUERY_LIMIT}")
        return False
    return True

def increment_query_count():
    """Increment daily query count"""
    count = load_query_log()
    save_query_log(count + 1)

def search_google_cse(query: str, num_results: int = 10, site_filter: str = None, search_type: str = 'image') -> Optional[Dict]:
    """
    Search Google Custom Search Engine for component.
    Returns search results or None if error.
    Thread-safe with rate limiting.
    
    Args:
        query: Search query
        num_results: Maximum number of results (max 10)
        site_filter: Optional site filter (e.g., 'site:pcpartpicker.com')
        search_type: 'image' for image search, None for web search (to get prices)
    """
    if not GOOGLE_API_KEY or len(GOOGLE_API_KEY) < 20:
        # API key not set or invalid (Google API keys are typically 39+ characters)
        # Don't print error for every search - too verbose
        # Error is already shown at script start
        return None
    
    # Thread-safe rate limiting
    with _rate_limiter_lock:
        if not check_daily_limit():
            return None
        
        # Check if we're currently rate limited
        current_time = time.time()
        if _rate_limited[0] and current_time < _backoff_until[0]:
            wait_time = _backoff_until[0] - current_time
            if wait_time > 0:
                print(f"  ⏸️  Rate limited, waiting {wait_time:.1f}s before retry...")
                print(f"  💡 All requests paused due to rate limit. This prevents further 429 errors.")
                time.sleep(min(wait_time, 120))  # Max 120s wait
            else:
                _rate_limited[0] = False  # Backoff period expired
                print(f"  ✅ Rate limit period expired. Resuming requests...")
        
        # Enforce rate limit (only delay if needed)
        time_since_last_query = current_time - _last_query_time[0]
        if time_since_last_query < MIN_DELAY_BETWEEN_QUERIES:
            sleep_time = MIN_DELAY_BETWEEN_QUERIES - time_since_last_query
            time.sleep(sleep_time)
        
        _last_query_time[0] = time.time()
    
    # Build search query
    if site_filter:
        search_query = f"{query} {site_filter}"
    else:
        search_query = query
    
    url = "https://www.googleapis.com/customsearch/v1"
    params = {
        'key': GOOGLE_API_KEY,
        'cx': GOOGLE_CSE_ID,
        'q': search_query,
        'num': min(num_results, 10),  # Google CSE max is 10 per request
        'safe': 'active'
    }
    
    # Add search type if specified (for image search)
    if search_type == 'image':
        params['searchType'] = 'image'
    # If None, do web search (to get product pages with prices)
    
    max_retries = 2  # Reduced retries to avoid wasting time
    retry_count = 0
    backoff_time = 60  # Start with 60 seconds - Google rate limits are per-minute
    
    while retry_count < max_retries:
        try:
            response = requests.get(url, params=params, timeout=30)
            
            # Handle 429 Too Many Requests with exponential backoff
            if response.status_code == 429:
                with _rate_limiter_lock:
                    _rate_limited[0] = True
                    _backoff_until[0] = time.time() + backoff_time
                
                if retry_count < max_retries - 1:
                    print(f"  ⚠️  Rate limited (429). Waiting {backoff_time}s before retry {retry_count + 1}/{max_retries}...")
                    print(f"  💡 Google enforces per-minute rate limits. Waiting longer to avoid further rate limits...")
                    time.sleep(backoff_time)
                    backoff_time = 120  # Wait 2 minutes on second retry
                    retry_count += 1
                    continue
                else:
                    print(f"  ❌ Rate limited (429). Max retries reached. Skipping this query.")
                    return None
            
            response.raise_for_status()
            
            result = response.json()
            
            # Check for API errors in response
            if 'error' in result:
                error = result.get('error', {})
                error_message = error.get('message', 'Unknown error')
                error_code = error.get('code', 0)
                
                # Handle rate limit errors in JSON response
                if error_code == 429:
                    with _rate_limiter_lock:
                        _rate_limited[0] = True
                        _backoff_until[0] = time.time() + backoff_time
                    
                    if retry_count < max_retries - 1:
                        print(f"  ⚠️  API Rate limited. Waiting {backoff_time}s before retry {retry_count + 1}/{max_retries}...")
                        print(f"  💡 Google enforces per-minute rate limits. Waiting longer to avoid further rate limits...")
                        time.sleep(backoff_time)
                        backoff_time = 120  # Wait 2 minutes on second retry
                        retry_count += 1
                        continue
                    else:
                        print(f"  ❌ API Rate limited. Max retries reached.")
                        return None
                
                print(f"  ⚠️  Google API Error: {error_message}")
                return None
            
            # Success - reset rate limit flag
            with _rate_limiter_lock:
                _rate_limited[0] = False
                increment_query_count()
                count = load_query_log()
                remaining = DAILY_QUERY_LIMIT - count
            
            return result
            
        except requests.exceptions.HTTPError as e:
            if e.response and e.response.status_code == 429:
                # Already handled above, but catch it here too
                if retry_count < max_retries - 1:
                    with _rate_limiter_lock:
                        _rate_limited[0] = True
                        _backoff_until[0] = time.time() + backoff_time
                    print(f"  ⚠️  Rate limited (HTTP 429). Waiting {backoff_time}s...")
                    print(f"  💡 Google enforces per-minute rate limits. Waiting longer to avoid further rate limits...")
                    time.sleep(backoff_time)
                    backoff_time = 120  # Wait 2 minutes on second retry
                    retry_count += 1
                    continue
                else:
                    print(f"  ❌ Rate limited. Max retries reached.")
                    return None
            print(f"  ⚠️  HTTP error: {str(e)}")
            return None
        except requests.exceptions.RequestException as e:
            print(f"  ⚠️  Request error: {str(e)}")
            return None
        except Exception as e:
            print(f"  ⚠️  Unexpected error: {str(e)}")
            return None
    
    return None

def extract_image_url(result: Dict, preferred_source: str = 'pcpartpicker') -> Optional[str]:
    """
    Extract image URL from search result.
    Prioritizes PCPartPicker format, but accepts other sources.
    For image search results, uses the 'image' field which contains the actual image URL.
    """
    link = result.get('link', '')
    
    # PCPartPicker image URL (preferred)
    # Check if link is a direct image URL
    if 'pcpartpicker.com' in link and 'images/product' in link:
        match = re.search(r'product/([a-f0-9]{32})\.256p\.jpg', link)
        if match:
            hash_value = match.group(1)
            return f"https://cdna.pcpartpicker.com/static/forever/images/product/{hash_value}.256p.jpg"
        return link
    
    # For PCPartPicker, also check the image field from Google Image Search
    if preferred_source == 'pcpartpicker':
        # Check if link is a PCPartPicker product page
        if 'pcpartpicker.com' in link and '/product/' in link:
            # Try to get image from Google's image field
            image_data = result.get('image', {})
            image_url = image_data.get('thumbnailLink') or image_data.get('link') or image_data.get('src')
            
            # If image URL is from PCPartPicker CDN, use it
            if image_url:
                if 'pcpartpicker.com' in image_url:
                    # Extract hash if it's a direct image URL
                    match = re.search(r'product/([a-f0-9]{32})\.256p\.jpg', image_url)
                    if match:
                        hash_value = match.group(1)
                        return f"https://cdna.pcpartpicker.com/static/forever/images/product/{hash_value}.256p.jpg"
                    return image_url
                # Google CDN URLs (googleusercontent.com) that proxy PCPartPicker images are also acceptable
                # These are valid image URLs from Google Image Search
                elif 'googleusercontent.com' in image_url or 'gstatic.com' in image_url:
                    # Accept Google CDN URLs if the source link is from PCPartPicker
                    return image_url
            
            # If no image URL found, try to extract from product page URL
            # PCPartPicker product URLs are like: https://pcpartpicker.com/product/XXXXXX/
            # We can't get the hash from the URL, so we need the image field
            # For now, return None and let the fallback handle it
            return None
        else:
            # Not a PCPartPicker link
            return None
    else:
        # For image search results, Google provides an 'image' field with the actual image URL
        # This is more reliable than trying to extract from the link
        image_url = result.get('image', {}).get('thumbnailLink') or result.get('image', {}).get('link') or result.get('link', '')
        
        if not image_url:
            return None
        
        # List of credible domains
        credible_domains = [
            # International e-commerce
            'newegg.com', 'amazon.com', 'bestbuy.com', 'bnhphotovideo.com',
            'microcenter.com', 'frys.com', 'canadacomputers.com',
            # Philippines e-commerce platforms
            'lazada.com.ph', 'lazada.ph', 'shopee.ph', 'shopee.com.ph',
            'temu.com', 'temu.com.ph',
            # Local Philippines retailers
            'pcx.com.ph', 'easypc.com.ph', 'villman.com', 'villman.com.ph',
            'pcgilmore.com', 'pchub.com', 'dynaquestpc.com',
            # Manufacturer websites
            'intel.com', 'amd.com', 'nvidia.com', 'asus.com', 'msi.com',
            'gigabyte.com', 'evga.com', 'corsair.com', 'thermaltake.com',
            'coolermaster.com', 'seasonic.com', 'crucial.com', 'kingston.com',
            'samsung.com', 'western digital', 'seagate.com', 'logitech.com',
            'razer.com', 'steelseries.com', 'hyperxgaming.com',
            # Google image CDN (for cached images)
            'googleusercontent.com', 'gstatic.com'
        ]
        
        # Check if image URL is from a credible source
        image_lower = image_url.lower()
        link_lower = link.lower()
        
        # Accept if it's from a credible domain OR if it's a direct image URL
        is_credible_domain = any(domain in image_lower or domain in link_lower for domain in credible_domains)
        is_direct_image = re.search(r'\.(jpg|jpeg|png|webp|gif)(\?|$)', image_lower, re.IGNORECASE)
        
        if is_credible_domain or is_direct_image:
            return image_url
        
        return None

def find_best_image_match(query: str, component_name: str, results: List[Dict], preferred_source: str = 'pcpartpicker') -> Optional[str]:
    """
    Find the best matching image from search results.
    Prioritizes the top result (first result) from the search, then falls back to scoring.
    """
    if not results:
        return None
    
    component_lower = component_name.lower()
    component_words = set(component_lower.split())
    
    # Remove common words that might cause false matches
    stop_words = {'the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by'}
    component_words = {w for w in component_words if w not in stop_words and len(w) > 2}
    
    # Extract key identifiers (brand, model numbers) for better matching
    # Model numbers often have patterns like "TL-C12C-S", "X3", etc.
    key_identifiers = []
    for word in component_words:
        # Keep model numbers, codes, and important identifiers
        if any(char.isdigit() for char in word) or '-' in word or len(word) >= 3:
            key_identifiers.append(word)
    
    # Also extract brand (usually first word)
    brand = component_lower.split()[0] if component_lower.split() else ''
    
    # PRIORITY 1: Use the top result (first result) if it has a valid image URL
    # Google's search results are already ranked by relevance, so the first result is usually the best match
    top_result = results[0]
    top_image_url = extract_image_url(top_result, preferred_source)
    if top_image_url:
        # Verify it's from a credible source
        top_link = top_result.get('link', '').lower()
        credible_domains = [
            'pcpartpicker.com', 'newegg.com', 'amazon.com', 'bestbuy.com', 'bnhphotovideo.com',
            'microcenter.com', 'intel.com', 'amd.com', 'nvidia.com', 'asus.com', 'msi.com',
            'gigabyte.com', 'corsair.com', 'lazada.com.ph', 'lazada.ph', 'shopee.ph',
            'shopee.com.ph', 'temu.com', 'pcx.com.ph', 'easypc.com.ph', 'villman.com',
            'pcgilmore.com', 'pchub.com', 'dynaquestpc.com', 'googleusercontent.com', 'gstatic.com'
        ]
        if any(domain in top_link or domain in top_image_url.lower() for domain in credible_domains):
            return top_image_url
    
    # PRIORITY 2: If top result doesn't have a valid image, fall back to scoring all results
    best_match = None
    best_score = 0
    best_source_priority = 0  # Higher priority for preferred source
    
    for result in results:
        # Get title and snippet for matching
        title = result.get('title', '').lower()
        snippet = result.get('snippet', '').lower()
        display_link = result.get('displayLink', '').lower()
        link = result.get('link', '').lower()
        
        # Extract image URL (try preferred source first)
        image_url = extract_image_url(result, preferred_source)
        if not image_url:
            continue
        
        # Determine source priority (PCPartPicker = highest, then manufacturers, then e-commerce)
        source_priority = 0
        if 'pcpartpicker.com' in link:
            source_priority = 5  # Highest priority
        elif any(domain in link for domain in ['intel.com', 'amd.com', 'nvidia.com', 'asus.com', 'msi.com', 
                                                'gigabyte.com', 'evga.com', 'corsair.com', 'thermaltake.com',
                                                'coolermaster.com', 'seasonic.com', 'crucial.com', 'kingston.com',
                                                'samsung.com', 'seagate.com', 'logitech.com', 'razer.com',
                                                'steelseries.com', 'hyperxgaming.com', 'noctua.at', 'bequiet.com',
                                                'arctic.de', 'lian-li.com', 'nzxt.com', 'phanteks.com', 'fractal-design.com']):
            source_priority = 4  # Manufacturer websites - very high priority
        elif any(domain in link for domain in ['pcx.com.ph', 'easypc.com.ph', 'villman.com', 'pcgilmore.com',
                                                'pchub.com', 'dynaquestpc.com']):
            source_priority = 3  # Philippines retailers - good priority
        elif any(domain in link for domain in ['lazada.com.ph', 'lazada.ph', 'shopee.ph', 'shopee.com.ph']):
            source_priority = 2  # Philippines e-commerce
        elif any(domain in link for domain in ['newegg.com', 'amazon.com', 'bestbuy.com', 'microcenter.com', 
                                                'bnhphoto', 'temu.com']):
            source_priority = 2  # International e-commerce
        else:
            source_priority = 1  # Other credible sources
        
        # Score based on how many component words appear in title/snippet
        # Also check the image URL itself for component name matches
        title_words = set(title.split())
        snippet_words = set(snippet.split())
        all_words = title_words | snippet_words
        
        # Calculate match score
        score = len(component_words & all_words)
        
        # Bonus for key identifiers (model numbers, codes) - these are more important
        for identifier in key_identifiers:
            if identifier in title or identifier in snippet:
                score += 2  # Higher weight for model numbers
        
        # Bonus if brand is in title
        if brand and brand in title:
            score += 2
        
        # Bonus if exact component name is in title
        if component_lower in title:
            score += 5
        # Partial match bonus - if most of the component name is in title
        elif len(component_words) > 0:
            matching_words = len(component_words & title_words)
            if matching_words >= len(component_words) * 0.6:  # 60% of words match
                score += 3
        
        # For PCPartPicker, be very lenient - if it's from PCPartPicker and has brand/model, accept it
        if preferred_source == 'pcpartpicker' and 'pcpartpicker.com' in link:
            score += 3
            # If we have brand and at least one key identifier, accept it even with low score
            if brand and brand in title and len(key_identifiers) > 0:
                has_identifier = any(identifier in title or identifier in snippet for identifier in key_identifiers)
                if has_identifier:
                    score += 5  # Strong match for PCPartPicker with brand + model
        elif source_priority > 0:
            score += 1
        
        # Penalty if it's clearly not a product page
        if any(word in title for word in ['forum', 'discussion', 'review', 'compare', 'article', 'blog']):
            score -= 3
        
        # Penalty for very small images or thumbnails
        if any(size in link for size in ['thumb', 'thumbnail', '_small', '_tiny', '50x', '100x']):
            score -= 2
        
        # Prioritize by source, then score
        if source_priority > best_source_priority or (source_priority == best_source_priority and score > best_score):
            best_score = score
            best_match = image_url
            best_source_priority = source_priority
    
    # Return if we have a reasonable match
    # For PCPartPicker, be very lenient - if it's from PCPartPicker, accept it with minimal matching
    if preferred_source == 'pcpartpicker':
        # If we found a match from PCPartPicker, return it
        if best_source_priority == 5 and best_match:
            # For PCPartPicker, accept if we have at least brand match or 1 key identifier
            min_score = 1  # Very low threshold for PCPartPicker
            if best_score >= min_score:
                return best_match
            # Even if score is 0, if it's from PCPartPicker and has brand, accept it
            if brand and best_match:
                return best_match
        
        # If no match found but we have PCPartPicker results, accept the first one
        # This ensures we get images even if name matching isn't perfect
        for result in results:
            result_link = result.get('link', '').lower()
            if 'pcpartpicker.com' in result_link:
                # Try to extract image URL
                temp_url = extract_image_url(result, 'pcpartpicker')
                if temp_url:
                    # Check if brand matches (very lenient)
                    result_title = result.get('title', '').lower()
                    if not brand or brand in result_title:
                        return temp_url
    
    # Lower threshold for fallback sources - accept any credible source if no better match
    min_score = 0  # Accept any match from credible source
    if best_score >= min_score and best_match:
        return best_match
    
    # If no match found but we have results from credible sources, return the first one
    # This ensures we get images even if name matching isn't perfect
    if preferred_source == 'fallback' and best_match:
        return best_match
    
    return None

def load_cache() -> Dict[str, str]:
    """Load cached image URLs"""
    cache_file = CACHE_DIR / 'image_urls_cache.json'
    if cache_file.exists():
        try:
            with open(cache_file, 'r', encoding='utf-8') as f:
                return json.load(f)
        except:
            pass
    return {}

def save_cache(cache: Dict[str, str]):
    """Save cached image URLs"""
    cache_file = CACHE_DIR / 'image_urls_cache.json'
    with open(cache_file, 'w', encoding='utf-8') as f:
        json.dump(cache, f, indent=2, ensure_ascii=False)

def load_progress() -> Dict:
    """Load progress tracking data"""
    if PROGRESS_FILE.exists():
        try:
            with open(PROGRESS_FILE, 'r', encoding='utf-8') as f:
                return json.load(f)
        except:
            pass
    return {'last_file': None, 'last_component_index': -1, 'last_component_name': None}

def save_progress(file_name: str, component_index: int, component_name: str):
    """Save progress tracking data"""
    progress = {
        'last_file': file_name,
        'last_component_index': component_index,
        'last_component_name': component_name,
        'timestamp': time.strftime('%Y-%m-%d %H:%M:%S')
    }
    with open(PROGRESS_FILE, 'w', encoding='utf-8') as f:
        json.dump(progress, f, indent=2, ensure_ascii=False)

def extract_price_from_snippet(snippet: str, title: str) -> Optional[float]:
    """
    Extract price in PHP from search result snippet/title.
    Returns price in USD (converted from PHP).
    Also handles USD prices directly.
    """
    # PHP to USD conversion rate (approximate)
    PHP_TO_USD = 1 / 56.0  # Assuming 56 PHP = 1 USD
    
    import re
    
    # Combine title and snippet
    text = f"{title} {snippet}"
    
    # First, try to find USD prices directly
    usd_patterns = [
        r'\$\s*([\d,]+\.?\d*)',
        r'USD\s*([\d,]+\.?\d*)',
        r'([\d,]+\.?\d*)\s*USD',
        r'([\d,]+\.?\d*)\s*\$',
    ]
    
    for pattern in usd_patterns:
        matches = re.findall(pattern, text, re.IGNORECASE)
        if matches:
            try:
                price_str = matches[0].replace(',', '')
                price_usd = float(price_str)
                # Sanity check: reasonable price range
                if 0.01 <= price_usd <= 50000:
                    return price_usd
            except (ValueError, IndexError):
                continue
    
    # Then try PHP prices: ₱1,234.56 or PHP 1,234.56 or 1234 PHP
    php_patterns = [
        r'₱\s*([\d,]+\.?\d*)',
        r'PHP\s*([\d,]+\.?\d*)',
        r'P\s*([\d,]+\.?\d*)',  # Just 'P' as currency
        r'([\d,]+\.?\d*)\s*PHP',
        r'([\d,]+\.?\d*)\s*₱',
        r'([\d,]+\.?\d*)\s*P\b',  # Just 'P' at end
    ]
    
    for pattern in php_patterns:
        matches = re.findall(pattern, text, re.IGNORECASE)
        if matches:
            try:
                # Get the first match and convert to float
                price_str = matches[0].replace(',', '').replace('P', '').replace('p', '').strip()
                if not price_str:
                    continue
                price_php = float(price_str)
                
                # Convert to USD
                price_usd = price_php * PHP_TO_USD
                
                # Sanity check: reasonable price range (exclude outliers)
                if 0.01 <= price_usd <= 50000:
                    return price_usd
            except (ValueError, IndexError):
                continue
    
    # Try to find any number that looks like a price (with ₱ or P prefix)
    fallback_patterns = [
        r'₱\s*([\d]{1,3}(?:[,\s]\d{3})*(?:\.\d{2})?)',
        r'P\s*([\d]{1,3}(?:[,\s]\d{3})*(?:\.\d{2})?)',
    ]
    
    for pattern in fallback_patterns:
        matches = re.findall(pattern, text, re.IGNORECASE)
        if matches:
            try:
                price_str = matches[0].replace(',', '').replace(' ', '').strip()
                price_php = float(price_str)
                price_usd = price_php * PHP_TO_USD
                if 0.01 <= price_usd <= 50000:
                    return price_usd
            except (ValueError, IndexError):
                continue
    
    return None

def find_image_for_component(component_name: str, cache: Dict[str, str], cache_lock: Lock = None) -> Optional[Tuple[str, str, Optional[float]]]:
    """
    Find image URL and price for a component.
    Tries PCPartPicker first, then falls back to Philippines e-commerce sites.
    Uses cache first, then Google Search if not cached.
    Returns tuple: (component_name, image_url, price_usd) or (component_name, None, None)
    Thread-safe when cache_lock is provided.
    """
    # Check cache first (thread-safe if lock provided)
    if cache_lock:
        with cache_lock:
            if component_name in cache:
                cached_url = cache[component_name]
                if cached_url and cached_url.startswith('http'):
                    return (component_name, cached_url, None)
    else:
        if component_name in cache:
            cached_url = cache[component_name]
            if cached_url and cached_url.startswith('http'):
                return (component_name, cached_url, None)
    
    best_url = None
    best_price = None
    
    # Step 1: Try PCPartPicker first
    # Try multiple search strategies for better results
    search_queries = [
        component_name,  # Full name
        component_name.split('(')[0].strip() if '(' in component_name else component_name,  # Remove parenthetical info
    ]
    
    # Extract brand and model for more targeted search
    name_parts = component_name.split()
    if len(name_parts) >= 2:
        # Try brand + model number (first 2-3 words usually contain brand and model)
        brand_model = ' '.join(name_parts[:min(3, len(name_parts))])
        search_queries.append(brand_model)
    
    for search_query in search_queries:
        search_results = search_google_cse(search_query, site_filter='site:pcpartpicker.com/product', search_type='image')
        
        if search_results is None:
            # API key not set or request failed - stop trying
            break
        
        if 'error' in search_results:
            # API error - stop trying
            break
        
        items = search_results.get('items', [])
        if items:
            best_url = find_best_image_match(search_query, component_name, items, preferred_source='pcpartpicker')
            if best_url:
                # Update cache (thread-safe if lock provided)
                if cache_lock:
                    with cache_lock:
                        cache[component_name] = best_url
                else:
                    cache[component_name] = best_url
                return (component_name, best_url, None)
        # If search returned but no items, try next query variation
        # (This is normal - some queries might not return results)
    
    # Step 2: Try manufacturer websites first (more reliable than e-commerce)
    manufacturer_sites = [
        'intel.com',
        'amd.com',
        'nvidia.com',
        'asus.com',
        'msi.com',
        'gigabyte.com',
        'evga.com',
        'corsair.com',
        'thermaltake.com',
        'coolermaster.com',
        'seasonic.com',
        'crucial.com',
        'kingston.com',
        'samsung.com',
        'seagate.com',
        'western digital',
        'logitech.com',
        'razer.com',
        'steelseries.com',
        'hyperxgaming.com',
        'noctua.at',
        'bequiet.com',
        'arctic.de',
        'lian-li.com',
        'nzxt.com',
        'phanteks.com',
        'fractal-design.com',
    ]
    
    # Search manufacturer sites
    for site in manufacturer_sites:
        site_filter = f'site:{site}'
        search_results = search_google_cse(component_name, site_filter=site_filter, num_results=10, search_type='image')
        
        if search_results:
            items = search_results.get('items', [])
            if items:
                # Try to find best match
                url = find_best_image_match(component_name, component_name, items, preferred_source='fallback')
                
                # If no match found, try to get any image from the first result
                if not url and items:
                    for item in items:
                        temp_url = extract_image_url(item, 'fallback')
                        if temp_url:
                            url = temp_url
                            break
                
                if url:
                    best_url = url
                    # Try to extract price from snippet
                    for item in items:
                        extracted_url = extract_image_url(item, 'fallback')
                        if extracted_url == url or (not extracted_url and url):
                            snippet = item.get('snippet', '')
                            title = item.get('title', '')
                            price = extract_price_from_snippet(snippet, title)
                            if price:
                                best_price = price
                            break
                    
                    if best_url:
                        break  # Found image, stop searching
    
    # Step 3: Try Philippines e-commerce sites (Lazada, Shopee, Temu) - Search each separately
    ph_sites = [
        'lazada.com.ph',
        'lazada.ph',
        'shopee.ph',
        'shopee.com.ph',
        'temu.com',
    ]
    
    # First try image search on each PH site
    for site in ph_sites:
        site_filter = f'site:{site}'
        search_results = search_google_cse(component_name, site_filter=site_filter, num_results=10, search_type='image')
        
        if search_results:
            items = search_results.get('items', [])
            if items:
                # Try to find best match
                url = find_best_image_match(component_name, component_name, items, preferred_source='fallback')
                
                # If no match found, try to get any image from the first result
                if not url and items:
                    # Accept first image from credible source if no better match
                    for item in items:
                        temp_url = extract_image_url(item, 'fallback')
                        if temp_url:
                            url = temp_url
                            break
                
                if url:
                    best_url = url
                    # Try to extract price from snippet
                    for item in items:
                        extracted_url = extract_image_url(item, 'fallback')
                        if extracted_url == url or (not extracted_url and url):
                            snippet = item.get('snippet', '')
                            title = item.get('title', '')
                            price = extract_price_from_snippet(snippet, title)
                            if price:
                                best_price = price
                            break
                    
                    # Also do a web search to get price from product page if not found
                    if not best_price:
                        web_results = search_google_cse(component_name, site_filter=site_filter, num_results=5, search_type=None)
                        if web_results:
                            web_items = web_results.get('items', [])
                            for item in web_items:
                                snippet = item.get('snippet', '')
                                title = item.get('title', '')
                                price = extract_price_from_snippet(snippet, title)
                                if price:
                                    best_price = price
                                    break
                    
                    if best_url:
                        break  # Found image, stop searching
    
    # Step 4: Try Philippines retailers
    if not best_url:
        ph_retailers = [
            'pcx.com.ph',
            'easypc.com.ph',
            'villman.com',
            'pcgilmore.com',
            'pchub.com',
            'dynaquestpc.com',
        ]
        
        for site in ph_retailers:
            site_filter = f'site:{site}'
            search_results = search_google_cse(component_name, site_filter=site_filter, num_results=10, search_type='image')
            
            if search_results:
                items = search_results.get('items', [])
                if items:
                    url = find_best_image_match(component_name, component_name, items, preferred_source='fallback')
                    
                    if not url and items:
                        for item in items:
                            temp_url = extract_image_url(item, 'fallback')
                            if temp_url:
                                url = temp_url
                                break
                    
                    if url:
                        best_url = url
                        # Try to extract price
                        for item in items:
                            extracted_url = extract_image_url(item, 'fallback')
                            if extracted_url == url or (not extracted_url and url):
                                snippet = item.get('snippet', '')
                                title = item.get('title', '')
                                price = extract_price_from_snippet(snippet, title)
                                if price:
                                    best_price = price
                                break
                        
                        if best_url:
                            break
    
    # Step 5: Fallback to broader search (international e-commerce)
    if not best_url:
        search_results = search_google_cse(f"{component_name} product image", num_results=15, search_type='image')
        
        if search_results:
            items = search_results.get('items', [])
            if items:
                url = find_best_image_match(component_name, component_name, items, preferred_source='fallback')
                
                # If no match found, try to get any image from credible source
                if not url and items:
                    for item in items:
                        temp_url = extract_image_url(item, 'fallback')
                        if temp_url:
                            url = temp_url
                            break
                
                if url:
                    best_url = url
                    # Try to extract price
                    for item in items:
                        extracted_url = extract_image_url(item, 'fallback')
                        if extracted_url == url or (not extracted_url and url):
                            snippet = item.get('snippet', '')
                            title = item.get('title', '')
                            price = extract_price_from_snippet(snippet, title)
                            if price:
                                best_price = price
                            break
    
    # Update cache (thread-safe if lock provided)
    if cache_lock:
        with cache_lock:
            cache[component_name] = best_url or ''
    else:
        cache[component_name] = best_url or ''
    
    return (component_name, best_url, best_price)

def update_image_urls_in_file(file_path: Path, cache: Dict[str, str], skip_existing: bool = True) -> Tuple[int, int]:
    """
    Update image_url fields in a JSON file with PCPartPicker URLs.
    Uses concurrent processing for faster execution.
    Returns: (total_count, updated_count)
    """
    print(f"\n{'='*80}")
    print(f"Processing: {file_path.name}")
    print(f"{'='*80}")
    
    cache_lock = Lock()  # Thread-safe cache access
    
    try:
        with open(file_path, 'r', encoding='utf-8') as f:
            data = json.load(f)
        
        if not isinstance(data, list):
            print(f"  ⚠️  Skipping {file_path.name}: Not a JSON array")
            return 0, 0
        
        # Collect components that need image URLs
        components_to_process = []
        item_map = {}  # Map component name to item index
        
        # Find the last component WITH an image_url to determine where to start
        last_with_image_idx = -1
        progress = load_progress()
        
        # If we have progress for this file, use it as a starting hint
        if progress.get('last_file') == file_path.name:
            last_with_image_idx = progress.get('last_component_index', -1)
            if last_with_image_idx >= 0:
                print(f"  📍 Last component with image: index {last_with_image_idx + 1} ({progress.get('last_component_name', 'N/A')})")
        
        # Scan to find the actual last component with image_url (more reliable)
        for idx, item in enumerate(data):
            current_url = item.get('image_url', '')
            if current_url and current_url.startswith('http'):
                # Check if it's from a credible source
                credible_domains = [
                    'pcpartpicker.com', 'newegg.com', 'amazon.com', 'bestbuy.com', 
                    'bnhphotovideo.com', 'microcenter.com', 'intel.com', 'amd.com', 
                    'nvidia.com', 'asus.com', 'msi.com', 'gigabyte.com', 'corsair.com',
                    'lazada.com.ph', 'lazada.ph', 'shopee.ph', 'shopee.com.ph',
                    'temu.com', 'temu.com.ph', 'pcx.com.ph', 'easypc.com.ph',
                    'villman.com', 'pcgilmore.com', 'pchub.com', 'dynaquestpc.com'
                ]
                if any(domain in current_url.lower() for domain in credible_domains):
                    last_with_image_idx = idx
        
        # Now collect components that need processing, starting AFTER the last one with image
        start_from_idx = last_with_image_idx + 1 if last_with_image_idx >= 0 else 0
        
        if start_from_idx > 0:
            print(f"  🚀 Starting from index {start_from_idx + 1} (first component without image after index {last_with_image_idx + 1})")
        
        for idx, item in enumerate(data):
            # Only process components after the last one with image
            if idx < start_from_idx:
                continue
            
            name = item.get('name', '')
            if not name:
                continue
            
            current_url = item.get('image_url', '')
            
            # Skip if already has a real image URL (PCPartPicker or other credible sources)
            if skip_existing and current_url and current_url.startswith('http'):
                # Check if it's from a credible source
                credible_domains = [
                    # International e-commerce
                    'newegg.com', 'amazon.com', 'bestbuy.com', 'bnhphotovideo.com',
                    'microcenter.com', 'intel.com', 'amd.com', 'nvidia.com',
                    'asus.com', 'msi.com', 'gigabyte.com', 'corsair.com',
                    # Philippines e-commerce
                    'lazada.com.ph', 'lazada.ph', 'shopee.ph', 'shopee.com.ph',
                    'temu.com', 'temu.com.ph',
                    # Philippines retailers
                    'pcx.com.ph', 'easypc.com.ph', 'villman.com', 'pcgilmore.com',
                    'pchub.com', 'dynaquestpc.com'
                ]
                # PCPartPicker check
                if 'pcpartpicker.com' in current_url and '/images/product/' in current_url:
                    # Update last_with_image_idx and continue (don't process)
                    last_with_image_idx = idx
                    save_progress(file_path.name, idx, name)
                    continue
                # Other credible sources
                if any(domain in current_url.lower() for domain in credible_domains):
                    # Update last_with_image_idx and continue (don't process)
                    last_with_image_idx = idx
                    save_progress(file_path.name, idx, name)
                    continue
            
            # Only process placeholder SVG images or empty image_url
            if not current_url or current_url.startswith('data:image/svg'):
                components_to_process.append(name)
                item_map[name] = idx
        
        total_count = len(data)
        need_processing = len(components_to_process)
        already_had_url = total_count - need_processing - (total_count - len(item_map))
        
        print(f"  📊 Total components: {total_count}")
        print(f"  🔍 Need processing: {need_processing}")
        print(f"  ✅ Already have URLs: {already_had_url}")
        print(f"  🚀 Using {MAX_CONCURRENT_REQUESTS} concurrent workers...\n")
        
        if need_processing == 0:
            print(f"  ✅ {file_path.name}: No updates needed")
            return total_count, 0
        
        # Process components concurrently
        results = {}
        updated_count = 0
        failed_count = 0
        
        with ThreadPoolExecutor(max_workers=MAX_CONCURRENT_REQUESTS) as executor:
            # Submit all tasks
            future_to_component = {
                executor.submit(find_image_for_component, name, cache, cache_lock): name
                for name in components_to_process
            }
            
            # Process completed tasks
            completed = 0
            for future in as_completed(future_to_component):
                completed += 1
                component_name = future_to_component[future]
                
                try:
                    name, image_url, price_usd = future.result()
                    results[name] = (image_url, price_usd)
                    
                    if image_url:
                        data[item_map[name]]['image_url'] = image_url
                        
                        # Update price if found from Philippines e-commerce
                        if price_usd is not None:
                            old_price = data[item_map[name]].get('price', 0)
                            data[item_map[name]]['price'] = round(price_usd, 2)
                            price_update_info = f" | Price: ${price_usd:.2f} (was ${old_price:.2f})" if old_price > 0 else f" | Price: ${price_usd:.2f}"
                        else:
                            price_update_info = ""
                        
                        updated_count += 1
                        # Save progress when we successfully update an image
                        save_progress(file_path.name, item_map[name], name)
                        # Show source indicator
                        if 'pcpartpicker.com' in image_url:
                            source = "PCPartPicker"
                        elif 'lazada' in image_url.lower():
                            source = "Lazada"
                        elif 'shopee' in image_url.lower():
                            source = "Shopee"
                        elif 'temu.com' in image_url.lower():
                            source = "Temu"
                        elif any(domain in image_url.lower() for domain in ['pcx.com.ph', 'easypc.com.ph', 'villman.com', 'pcgilmore.com']):
                            source = "PH Retailer"
                        elif any(domain in image_url.lower() for domain in ['intel.com', 'amd.com', 'nvidia.com', 'asus.com', 'msi.com',
                                                                             'gigabyte.com', 'corsair.com', 'thermaltake.com', 'coolermaster.com',
                                                                             'seasonic.com', 'crucial.com', 'kingston.com', 'samsung.com',
                                                                             'seagate.com', 'logitech.com', 'razer.com', 'noctua.at',
                                                                             'bequiet.com', 'arctic.de', 'lian-li.com', 'nzxt.com', 'phanteks.com']):
                            source = "Manufacturer"
                        elif any(domain in image_url.lower() for domain in ['newegg.com', 'amazon.com', 'bestbuy.com']):
                            source = "E-commerce"
                        else:
                            source = "Other"
                        print(f"  ✅ [{completed}/{need_processing}] {name[:40]}... → Found ({source}){price_update_info}")
                    else:
                        failed_count += 1
                        print(f"  ⚠️  [{completed}/{need_processing}] {name[:45]}... → No match")
                    
                    # Save cache periodically (every 10 updates)
                    if completed % 10 == 0:
                        with cache_lock:
                            save_cache(cache)
                        
                        # Print progress
                        count = load_query_log()
                        remaining = DAILY_QUERY_LIMIT - count
                        print(f"    📊 Progress: {completed}/{need_processing} | API calls: {count}/{DAILY_QUERY_LIMIT} | Remaining: {remaining}")
                        
                except Exception as e:
                    failed_count += 1
                    print(f"  ❌ Error processing {component_name}: {e}")
        
        # Write back to file if we made updates
        if updated_count > 0:
            with open(file_path, 'w', encoding='utf-8') as f:
                json.dump(data, f, indent=2, ensure_ascii=False)
            print(f"\n  ✅ {file_path.name}: Updated {updated_count} image URLs")
            print(f"     (Failed: {failed_count}, Already had URL: {already_had_url})")
        else:
            print(f"\n  ✅ {file_path.name}: No updates made")
            print(f"     (Failed: {failed_count}, Already had URL: {already_had_url})")
        
        # Save cache
        with cache_lock:
            save_cache(cache)
        
        return total_count, updated_count
    
    except json.JSONDecodeError as e:
        print(f"  ❌ Error parsing JSON in {file_path.name}: {e}")
        return 0, 0
    except Exception as e:
        print(f"  ❌ Error processing {file_path.name}: {e}")
        import traceback
        traceback.print_exc()
        return 0, 0

def get_status() -> Dict:
    """Get current status (queries used, remaining, progress) - returns JSON for API"""
    queries_used = load_query_log()
    remaining = max(0, DAILY_QUERY_LIMIT - queries_used)
    progress = load_progress()
    
    return {
        'queries_used': queries_used,
        'queries_limit': DAILY_QUERY_LIMIT,
        'queries_remaining': remaining,
        'can_run': remaining > 0,
        'progress': progress
    }

def main():
    """Main function"""
    import sys
    
    # Check if status is requested (for API)
    if len(sys.argv) > 0 and '--status' in sys.argv:
        import json
        status = get_status()
        print(json.dumps(status))
        return
    
    if not GOOGLE_API_KEY or len(GOOGLE_API_KEY) < 20:
        print("❌ ERROR: GOOGLE_API_KEY environment variable not set or invalid!")
        if GOOGLE_API_KEY:
            print(f"   Current value: '{GOOGLE_API_KEY[:10]}...' (length: {len(GOOGLE_API_KEY)})")
            print("   Google API keys are typically 39+ characters long")
        print("\nTo set it:")
        print("  Windows: set GOOGLE_API_KEY=your_api_key_here")
        print("  Linux/Mac: export GOOGLE_API_KEY=your_api_key_here")
        print("\nGet your API key from: https://console.cloud.google.com/apis/credentials")
        print("  1. Go to Google Cloud Console")
        print("  2. Create a project (or select existing)")
        print("  3. Enable 'Custom Search API'")
        print("  4. Create credentials (API Key)")
        print("\n⚠️  Without a valid API key, the script cannot search for images.")
        return
    
    if not JSON_DIR.exists():
        print(f"❌ Directory not found: {JSON_DIR}")
        return
    
    json_files = list(JSON_DIR.glob('*.json'))
    
    if not json_files:
        print(f"❌ No JSON files found in {JSON_DIR}")
        return
    
    # Load cache
    cache = load_cache()
    print(f"📦 Loaded {len(cache)} cached image URLs")
    
    # Check daily limit
    queries_used = load_query_log()
    remaining = DAILY_QUERY_LIMIT - queries_used
    print(f"📊 Queries used today: {queries_used}/{DAILY_QUERY_LIMIT} (remaining: {remaining})")
    
    if remaining <= 0:
        print("❌ Daily query limit reached. Please try again tomorrow.")
        return
    
    print(f"\n🔍 Processing {len(json_files)} JSON files to find image URLs...")
    print(f"⏱️  Rate limit: {QUERIES_PER_MINUTE} queries/minute (~{MIN_DELAY_BETWEEN_QUERIES:.1f}s delay)")
    print(f"🚀 Concurrent processing: {MAX_CONCURRENT_REQUESTS} parallel requests")
    print(f"🌏 Sources: PCPartPicker → Manufacturers → PH Retailers → E-commerce")
    print(f"💡 Optimized for speed and efficiency. Progress is saved automatically.\n")
    
    # Filter files if specified
    files_to_process = json_files
    if len(sys.argv) > 1:
        filter_names = sys.argv[1:]
        files_to_process = [f for f in json_files if any(filter_name in f.name for filter_name in filter_names)]
        print(f"📝 Filtered to {len(files_to_process)} files: {[f.name for f in files_to_process]}\n")
    
    total_components = 0
    total_updated = 0
    
    try:
        for json_file in sorted(files_to_process):
            # Check remaining queries before each file
            queries_used = load_query_log()
            remaining = DAILY_QUERY_LIMIT - queries_used
            if remaining <= 0:
                print(f"\n⚠️  Daily query limit reached. Stopping.")
                break
            
            total, updated = update_image_urls_in_file(json_file, cache)
            total_components += total
            total_updated += updated
            
            # Save cache after each file
            save_cache(cache)
        
        queries_used = load_query_log()
        remaining = DAILY_QUERY_LIMIT - queries_used
        
        print(f"\n{'='*80}")
        print(f"📊 FINAL SUMMARY")
        print(f"{'='*80}")
        print(f"Total components processed: {total_components}")
        print(f"Total image URLs updated: {total_updated}")
        print(f"Queries used today: {queries_used}/{DAILY_QUERY_LIMIT} (remaining: {remaining})")
        print(f"✅ All files processed!")
        
    except KeyboardInterrupt:
        print("\n\n⚠️  Interrupted by user. Progress saved.")
        save_cache(cache)
        queries_used = load_query_log()
        remaining = DAILY_QUERY_LIMIT - queries_used
        print(f"Queries used: {queries_used}/{DAILY_QUERY_LIMIT} (remaining: {remaining})")
        print("💡 Run the script again to continue where you left off.")

if __name__ == '__main__':
    main()

