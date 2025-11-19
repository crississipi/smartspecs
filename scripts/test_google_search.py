#!/usr/bin/env python3
"""
Test script to verify Google Custom Search API setup
"""

import os
import requests

# Load environment variables from .env file (if exists) for local development
try:
    from dotenv import load_dotenv
    load_dotenv()
except ImportError:
    pass  # python-dotenv not installed, skip

GOOGLE_CSE_ID = os.getenv('GOOGLE_CSE_ID', '')  # Must be set via environment variable
GOOGLE_API_KEY = os.getenv('GOOGLE_API_KEY', '')  # Set via environment variable

def test_search():
    """Test Google Custom Search API"""
    
    if not GOOGLE_API_KEY:
        print("❌ ERROR: GOOGLE_API_KEY not set!")
        print("\nPlease set it first:")
        print("  Windows: set GOOGLE_API_KEY=your_api_key_here")
        print("  Linux/Mac: export GOOGLE_API_KEY=your_api_key_here")
        print("  Or create a .env file with GOOGLE_API_KEY=your_api_key_here")
        return False
    
    if not GOOGLE_CSE_ID:
        print("❌ ERROR: GOOGLE_CSE_ID not set!")
        print("\nPlease set it first:")
        print("  Windows: set GOOGLE_CSE_ID=your_cse_id_here")
        print("  Linux/Mac: export GOOGLE_CSE_ID=your_cse_id_here")
        print("  Or create a .env file with GOOGLE_CSE_ID=your_cse_id_here")
        return False
    
    print(f"✅ API Key found: {GOOGLE_API_KEY[:10]}...")
    print(f"✅ Search Engine ID: {GOOGLE_CSE_ID}\n")
    
    # Test search
    query = "Intel Core i7-12700K site:pcpartpicker.com/product"
    
    print(f"🔍 Testing search: {query}")
    
    url = "https://www.googleapis.com/customsearch/v1"
    params = {
        'key': GOOGLE_API_KEY,
        'cx': GOOGLE_CSE_ID,
        'q': query,
        'num': 5,
        'searchType': 'image',
        'safe': 'active'
    }
    
    try:
        response = requests.get(url, params=params, timeout=30)
        response.raise_for_status()
        
        data = response.json()
        
        print(f"✅ Search successful!\n")
        print(f"📊 Results found: {data.get('searchInformation', {}).get('totalResults', 0)}")
        
        items = data.get('items', [])
        if items:
            print(f"\n📸 Found {len(items)} images:\n")
            for i, item in enumerate(items[:3], 1):
                title = item.get('title', 'N/A')[:60]
                link = item.get('link', 'N/A')[:80]
                print(f"{i}. {title}")
                print(f"   {link}\n")
            return True
        else:
            print("⚠️  No results found. Check your search engine configuration.")
            return False
            
    except requests.exceptions.RequestException as e:
        print(f"❌ API Error: {e}")
        if hasattr(e.response, 'text'):
            print(f"Response: {e.response.text}")
        return False
    except Exception as e:
        print(f"❌ Error: {e}")
        return False

if __name__ == '__main__':
    print("="*80)
    print("Google Custom Search API Test")
    print("="*80)
    print()
    
    success = test_search()
    
    if success:
        print("\n✅ Setup verified! You can now run:")
        print("   python scripts/find_pcpartpicker_images_google.py")
    else:
        print("\n❌ Setup failed. Please check your configuration.")

