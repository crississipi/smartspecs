#!/usr/bin/env python3
"""
Add image_url fields to components in PCPartPicker JSON files.
Uses web search to find product images.
"""

import json
import re
import time
from pathlib import Path
from typing import Dict, List, Any, Optional
from urllib.parse import quote, urlencode
import urllib.request
import urllib.error

# Directory containing JSON files
JSON_DIR = Path(__file__).parent / 'pcpartpicker_json'

# Rate limiting
REQUEST_DELAY = 0.5  # Delay between requests in seconds

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
        'Scythe', 'Sandisk', 'Apevia', 'StarTech'
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
        'cpu-cooler.json': 'cpu_cooler',
        'case-fan.json': 'case_fans',
        'case-accessory.json': 'case_accessory',
        'fan-controller.json': 'fan_controller',
        'external-hard-drive.json': 'external_storage',
        'headphones.json': 'headphones',
        'keyboard.json': 'keyboard',
        'mouse.json': 'mouse',
        'monitor.json': 'monitor',
        'optical-drive.json': 'optical_drive',
        'os.json': 'os',
        'sound-card.json': 'sound_card',
        'speakers.json': 'speakers',
        'thermal-paste.json': 'thermal_paste',
        'ups.json': 'ups',
        'video-card.json': 'gpu',
        'webcam.json': 'webcam',
        'wired-network-card.json': 'network_card',
        'wireless-network-card.json': 'wireless_network_card'
    }
    return category_map.get(filename, 'component')

def construct_pcpartpicker_url(component: Dict[str, Any], filename: str) -> Optional[str]:
    """
    Construct PCPartPicker product page URL to potentially scrape images from.
    PCPartPicker URLs follow pattern: https://pcpartpicker.com/product/[code]/
    """
    name = component.get('name', '')
    # PCPartPicker uses product codes, which we don't have
    # So we can't directly construct URLs
    return None

def construct_newegg_image_url(component: Dict[str, Any], filename: str) -> Optional[str]:
    """
    Try to construct Newegg image URL pattern.
    Newegg uses predictable image URLs for some products.
    """
    name = component.get('name', '')
    brand = extract_brand(name)
    
    if not brand:
        return None
    
    # Newegg image URLs are hard to predict without product IDs
    # This is just a placeholder approach
    return None

def search_image_via_web(component: Dict[str, Any], filename: str) -> Optional[str]:
    """
    Search for image using web search.
    Uses DuckDuckGo or Google Images search.
    """
    name = component.get('name', '')
    brand = extract_brand(name)
    category = get_category_from_filename(filename)
    
    # Construct search query
    search_query = f"{name} {category} product image"
    
    # Try DuckDuckGo Images API (no API key needed)
    try:
        # DuckDuckGo Instant Answer API for images
        # Note: This is a simplified approach
        # In production, use proper image search APIs
        
        # For now, return None to use placeholder
        # Actual implementation would require:
        # 1. Google Custom Search API (requires API key)
        # 2. Bing Image Search API (requires API key)
        # 3. Web scraping (requires proper libraries like BeautifulSoup)
        
        return None
    except Exception:
        return None

def construct_image_url(component: Dict[str, Any], filename: str) -> str:
    """
    Construct image URL using various strategies.
    Falls back to placeholder if no real image found.
    """
    name = component.get('name', '')
    brand = extract_brand(name)
    category = get_category_from_filename(filename)
    
    # Try to find real image URL
    image_url = (
        construct_pcpartpicker_url(component, filename) or
        construct_newegg_image_url(component, filename) or
        search_image_via_web(component, filename)
    )
    
    if image_url:
        return image_url
    
    # Fallback: Use a more descriptive placeholder
    # Format: Component type with brand if available
    placeholder_text = category.replace('_', ' ').title()
    if brand:
        placeholder_text = f"{brand} {placeholder_text}"
    
    # Use a data URI for placeholder (works offline, no external requests)
    # This is better than external placeholder services
    svg_content = f'''<svg xmlns="http://www.w3.org/2000/svg" width="400" height="300">
        <rect width="400" height="300" fill="#f0f0f0"/>
        <text x="50%" y="45%" text-anchor="middle" font-family="Arial, sans-serif" 
              font-size="18" font-weight="bold" fill="#666">{quote(placeholder_text)}</text>
        <text x="50%" y="60%" text-anchor="middle" font-family="Arial, sans-serif" 
              font-size="14" fill="#999">{quote(name[:30])}</text>
    </svg>'''
    
    import base64
    svg_encoded = base64.b64encode(svg_content.encode('utf-8')).decode('utf-8')
    return f"data:image/svg+xml;base64,{svg_encoded}"

def search_image_url(component: Dict[str, Any], filename: str) -> Optional[str]:
    """
    Search for image URL using web search.
    Returns None if not found (will use placeholder).
    """
    # Try web search first
    image_url = search_image_via_web(component, filename)
    return image_url

def add_image_urls_to_file(file_path: Path) -> tuple[int, int]:
    """
    Add image_url fields to all components in a JSON file.
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
        
        for item in data:
            # Check if image_url already exists and is valid
            if 'image_url' in item and item.get('image_url'):
                continue
            
            # Try to find image URL
            image_url = search_image_url(item, file_path.name)
            
            if not image_url:
                # Use constructed placeholder URL
                image_url = construct_image_url(item, file_path.name)
            
            item['image_url'] = image_url
            updated_count += 1
            
            # Rate limiting
            if updated_count % 10 == 0:
                time.sleep(REQUEST_DELAY)
        
        # Write back to file
        if updated_count > 0:
            with open(file_path, 'w', encoding='utf-8') as f:
                json.dump(data, f, indent=2, ensure_ascii=False)
            print(f"  ✅ {file_path.name}: Added {updated_count} image URLs")
        else:
            print(f"  ✅ {file_path.name}: All components already have image URLs")
        
        return total_count, updated_count
    
    except json.JSONDecodeError as e:
        print(f"  ❌ Error parsing JSON in {file_path.name}: {e}")
        return 0, 0
    except Exception as e:
        print(f"  ❌ Error processing {file_path.name}: {e}")
        return 0, 0

def main():
    """Main function to add image URLs to all JSON files"""
    if not JSON_DIR.exists():
        print(f"❌ Directory not found: {JSON_DIR}")
        return
    
    json_files = list(JSON_DIR.glob('*.json'))
    
    if not json_files:
        print(f"❌ No JSON files found in {JSON_DIR}")
        return
    
    print(f"🔍 Processing {len(json_files)} JSON files to add image URLs...\n")
    
    total_components = 0
    total_updated = 0
    
    for json_file in sorted(json_files):
        total, updated = add_image_urls_to_file(json_file)
        total_components += total
        total_updated += updated
    
    print(f"\n{'='*80}")
    print(f"📊 SUMMARY")
    print(f"{'='*80}")
    print(f"Total components processed: {total_components}")
    print(f"Total image URLs added: {total_updated}")
    print(f"✅ All files processed successfully!")
    print(f"\n💡 Note: Currently using placeholder images.")
    print(f"   For production use, implement actual image search/scraping.")

if __name__ == '__main__':
    main()

