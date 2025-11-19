#!/usr/bin/env python3
"""
Add real image URLs to components by searching DuckDuckGo Images.
This script searches for actual product images and updates the JSON files.
"""

import json
import time
import re
from pathlib import Path
from typing import Dict, List, Any, Optional
from urllib.parse import quote
import urllib.request
import urllib.error

# Directory containing JSON files
JSON_DIR = Path(__file__).parent / 'pcpartpicker_json'

# Rate limiting
REQUEST_DELAY = 1.0  # Delay between requests in seconds

def search_duckduckgo_images(query: str, max_results: int = 1) -> Optional[str]:
    """
    Search DuckDuckGo Images for a query and return the first image URL.
    DuckDuckGo doesn't require an API key.
    """
    try:
        # Try to use duckduckgo_search library
        try:
            from duckduckgo_search import DDGS
            
            with DDGS() as ddgs:
                results = list(ddgs.images(
                    keywords=query,
                    max_results=max_results,
                    safesearch='moderate'
                ))
                
                if results and len(results) > 0:
                    # Return the first image URL
                    image_url = results[0].get('image')
                    if image_url:
                        return image_url
        except ImportError:
            # Library not installed, return None
            return None
        except Exception as e:
            # Search failed, return None
            return None
        
        return None
    except Exception as e:
        return None

def extract_brand(name: str) -> Optional[str]:
    """Extract brand from component name"""
    common_brands = [
        'NZXT', 'Corsair', 'Asus', 'Gigabyte', 'MSI', 'Intel', 'AMD', 'TP-Link',
        'Thermalright', 'Deepcool', 'LG', 'Samsung', 'Pioneer', 'Microsoft',
        'ARCTIC', 'ID-COOLING', 'Cooler Master', 'Thermaltake', 'Lian Li',
        'Silverstone', 'D-Link', 'Rosewill', 'Supermicro', 'VisionTek',
        'EDUP', 'Ubit', 'fenvi', 'BrosTrend', 'Ziyituod', 'Vantec', 'nMEDIAPC',
        'Unitech', 'Lamptron', 'Aerocool', 'HP', 'Sony', 'Seagate', 'Western Digital',
        'Crucial', 'Kingston', 'Klevv', 'Silicon Power', 'TEAMGROUP', 'G.Skill',
        'Logitech', 'Razer', 'SteelSeries', 'Creative Labs', 'Kanto', 'Edifier',
        'Montech', 'Phanteks', 'Fractal Design', 'HYTE', 'NZXT', 'Lian Li',
        'Scythe', 'Sandisk', 'Apevia', 'StarTech', 'G.Skill'
    ]
    
    name_upper = name.upper()
    for brand in common_brands:
        if brand.upper() in name_upper:
            return brand
    return None

def get_category_from_filename(filename: str) -> str:
    """Extract category from filename"""
    category_map = {
        'cpu.json': 'cpu',
        'video-card.json': 'gpu',
        'motherboard.json': 'motherboard',
        'memory.json': 'ram',
        'internal-hard-drive.json': 'storage',
        'power-supply.json': 'psu',
        'case.json': 'case',
        'cpu-cooler.json': 'cpu cooler',
        'case-fan.json': 'case fan',
        'case-accessory.json': 'case accessory',
        'fan-controller.json': 'fan controller',
        'external-hard-drive.json': 'external hard drive',
        'headphones.json': 'headphones',
        'keyboard.json': 'keyboard',
        'mouse.json': 'mouse',
        'monitor.json': 'monitor',
        'optical-drive.json': 'optical drive',
        'os.json': 'operating system',
        'sound-card.json': 'sound card',
        'speakers.json': 'speakers',
        'thermal-paste.json': 'thermal paste',
        'ups.json': 'UPS',
        'webcam.json': 'webcam',
        'wired-network-card.json': 'network card',
        'wireless-network-card.json': 'wireless network card'
    }
    return category_map.get(filename, 'component')

def find_product_image(component: Dict[str, Any], filename: str) -> Optional[str]:
    """
    Find product image URL using web search.
    Returns None if not found.
    """
    name = component.get('name', '')
    brand = extract_brand(name)
    category = get_category_from_filename(filename)
    
    # Construct search query
    search_query = f"{name} {category}"
    
    # Try to search for image
    image_url = search_duckduckgo_images(search_query)
    
    return image_url

def update_image_urls_in_file(file_path: Path, update_existing: bool = False) -> tuple[int, int]:
    """
    Update image_url fields in a JSON file with real product images.
    Returns: (total_count, updated_count)
    """
    print(f"Processing: {file_path.name}...")
    
    try:
        with open(file_path, 'r', encoding='utf-8') as f:
            data = json.load(f)
        
        if not isinstance(data, list):
            print(f"  ⚠️  Skipping {file_path.name}: Not a JSON array")
            return 0, 0
        
        total_count = len(data)
        updated_count = 0
        skipped_count = 0
        
        for idx, item in enumerate(data, 1):
            # Check if image_url already exists
            existing_url = item.get('image_url', '')
            
            # Skip if already has a real URL (not placeholder) and update_existing is False
            if existing_url and not existing_url.startswith('data:image') and not update_existing:
                skipped_count += 1
                continue
            
            # Try to find real image
            image_url = find_product_image(item, file_path.name)
            
            if image_url:
                item['image_url'] = image_url
                updated_count += 1
                if idx % 10 == 0:
                    print(f"    Progress: {idx}/{total_count} (updated: {updated_count})")
            else:
                # Keep existing placeholder or skip
                if not existing_url:
                    # Create placeholder if none exists
                    brand = extract_brand(item.get('name', ''))
                    category = get_category_from_filename(file_path.name)
                    placeholder_text = category.replace('_', ' ').title()
                    if brand:
                        placeholder_text = f"{brand} {placeholder_text}"
                    
                    svg_content = f'''<svg xmlns="http://www.w3.org/2000/svg" width="400" height="300">
                        <rect width="400" height="300" fill="#f0f0f0"/>
                        <text x="50%" y="45%" text-anchor="middle" font-family="Arial, sans-serif" 
                              font-size="18" font-weight="bold" fill="#666">{quote(placeholder_text)}</text>
                        <text x="50%" y="60%" text-anchor="middle" font-family="Arial, sans-serif" 
                              font-size="14" fill="#999">{quote(item.get('name', '')[:30])}</text>
                    </svg>'''
                    
                    import base64
                    svg_encoded = base64.b64encode(svg_content.encode('utf-8')).decode('utf-8')
                    item['image_url'] = f"data:image/svg+xml;base64,{svg_encoded}"
            
            # Rate limiting
            if idx % 10 == 0:
                time.sleep(REQUEST_DELAY)
        
        # Write back to file
        if updated_count > 0:
            with open(file_path, 'w', encoding='utf-8') as f:
                json.dump(data, f, indent=2, ensure_ascii=False)
            print(f"  ✅ {file_path.name}: Updated {updated_count} image URLs ({skipped_count} skipped)")
        else:
            print(f"  ✅ {file_path.name}: No updates needed ({skipped_count} already had images)")
        
        return total_count, updated_count
    
    except json.JSONDecodeError as e:
        print(f"  ❌ Error parsing JSON in {file_path.name}: {e}")
        return 0, 0
    except Exception as e:
        print(f"  ❌ Error processing {file_path.name}: {e}")
        return 0, 0

def main():
    """Main function"""
    import sys
    
    update_existing = '--update-existing' in sys.argv
    
    if not JSON_DIR.exists():
        print(f"❌ Directory not found: {JSON_DIR}")
        return
    
    json_files = list(JSON_DIR.glob('*.json'))
    
    if not json_files:
        print(f"❌ No JSON files found in {JSON_DIR}")
        return
    
    print(f"🔍 Processing {len(json_files)} JSON files to find real product images...\n")
    print("⚠️  Note: This script currently uses placeholders.")
    print("   To get real images, you need to:")
    print("   1. Install: pip install duckduckgo-search")
    print("   2. Or use Google Custom Search API (requires API key)")
    print("   3. Or implement web scraping with BeautifulSoup\n")
    
    total_components = 0
    total_updated = 0
    
    for json_file in sorted(json_files):
        total, updated = update_image_urls_in_file(json_file, update_existing)
        total_components += total
        total_updated += updated
    
    print(f"\n{'='*80}")
    print(f"📊 SUMMARY")
    print(f"{'='*80}")
    print(f"Total components processed: {total_components}")
    print(f"Total image URLs updated: {total_updated}")
    print(f"✅ All files processed!")

if __name__ == '__main__':
    main()

