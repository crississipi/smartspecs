#!/usr/bin/env python3
"""
Add PCPartPicker links to components in JSON files.
This script adds a "link" field with a PCPartPicker search URL for each component.
"""

import json
import re
from pathlib import Path
from typing import Dict, List, Any
from urllib.parse import quote

# Directory containing JSON files
JSON_DIR = Path(__file__).parent / 'pcpartpicker_json'

# PCPartPicker base URL
PCPARTPICKER_BASE_URL = "https://pcpartpicker.com"

def create_slug_from_name(name: str) -> str:
    """
    Create a URL-friendly slug from component name.
    PCPartPicker URLs typically use lowercase, hyphens, and remove special characters.
    """
    # Convert to lowercase
    slug = name.lower()
    
    # Replace spaces and common separators with hyphens
    slug = re.sub(r'[\s\-_/]+', '-', slug)
    
    # Remove special characters, keep alphanumeric and hyphens
    slug = re.sub(r'[^a-z0-9\-]', '', slug)
    
    # Remove multiple consecutive hyphens
    slug = re.sub(r'-+', '-', slug)
    
    # Remove leading/trailing hyphens
    slug = slug.strip('-')
    
    return slug

def get_category_slug(filename: str) -> str:
    """Get PCPartPicker category slug from filename"""
    category_map = {
        'cpu.json': 'cpu',
        'video-card.json': 'video-card',
        'motherboard.json': 'motherboard',
        'memory.json': 'memory',
        'internal-hard-drive.json': 'internal-hard-drive',
        'power-supply.json': 'power-supply',
        'case.json': 'case',
        'cpu-cooler.json': 'cpu-cooler',
        'case-fan.json': 'case-fan',
        'case-accessory.json': 'case-accessory',
        'fan-controller.json': 'fan-controller',
        'external-hard-drive.json': 'external-hard-drive',
        'headphones.json': 'headphones',
        'keyboard.json': 'keyboard',
        'mouse.json': 'mouse',
        'monitor.json': 'monitor',
        'optical-drive.json': 'optical-drive',
        'os.json': 'os',
        'sound-card.json': 'sound-card',
        'speakers.json': 'speakers',
        'thermal-paste.json': 'thermal-paste',
        'ups.json': 'ups',
        'webcam.json': 'webcam',
        'wired-network-card.json': 'wired-network-card',
        'wireless-network-card.json': 'wireless-network-card'
    }
    return category_map.get(filename, 'product')

def generate_pcpartpicker_link(component: Dict[str, Any], filename: str) -> str:
    """
    Generate a PCPartPicker link for a component.
    Uses search URL format: https://pcpartpicker.com/product/[category]/[slug]/
    If product ID is not available, uses search URL.
    """
    name = component.get('name', '')
    category_slug = get_category_slug(filename)
    
    # Try to create a product URL (PCPartPicker format)
    # Format: https://pcpartpicker.com/product/[product-id]/[slug]/
    # Since we don't have product IDs, we'll use search format
    # Format: https://pcpartpicker.com/search/?q=[query]
    
    # Create search query from component name
    search_query = name.strip()
    
    # URL encode the search query
    encoded_query = quote(search_query)
    
    # Generate search URL
    search_url = f"{PCPARTPICKER_BASE_URL}/search/?q={encoded_query}"
    
    # Alternatively, try to generate a direct product URL using slug
    # This may not always work, but it's closer to the actual URL format
    product_slug = create_slug_from_name(name)
    
    # Try direct product URL format (may redirect, but better for SEO)
    # Using category in the path helps narrow down results
    direct_url = f"{PCPARTPICKER_BASE_URL}/product/{category_slug}/{product_slug}/"
    
    # For now, return search URL as it's more reliable
    # You can switch to direct_url if preferred
    return search_url

def update_links_in_file(file_path: Path, update_existing: bool = False) -> tuple[int, int]:
    """
    Update or add link fields in a JSON file.
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
            # Check if link already exists
            existing_link = item.get('link', '')
            
            # Skip if already has a link and update_existing is False
            if existing_link and not update_existing:
                skipped_count += 1
                continue
            
            # Generate PCPartPicker link
            link = generate_pcpartpicker_link(item, file_path.name)
            item['link'] = link
            updated_count += 1
            
            # Progress indicator
            if idx % 100 == 0:
                print(f"    Progress: {idx}/{total_count} (updated: {updated_count})")
        
        # Write back to file
        if updated_count > 0:
            with open(file_path, 'w', encoding='utf-8') as f:
                json.dump(data, f, indent=2, ensure_ascii=False)
            print(f"  ✅ {file_path.name}: Updated {updated_count} links ({skipped_count} skipped)")
        else:
            print(f"  ✅ {file_path.name}: No updates needed ({skipped_count} already had links)")
        
        return total_count, updated_count
    
    except json.JSONDecodeError as e:
        print(f"  ❌ Error parsing JSON in {file_path.name}: {e}")
        return 0, 0
    except Exception as e:
        print(f"  ❌ Error processing {file_path.name}: {e}")
        import traceback
        traceback.print_exc()
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
    
    print(f"🔗 Processing {len(json_files)} JSON files to add PCPartPicker links...\n")
    
    total_components = 0
    total_updated = 0
    
    for json_file in sorted(json_files):
        total, updated = update_links_in_file(json_file, update_existing)
        total_components += total
        total_updated += updated
    
    print(f"\n{'='*80}")
    print(f"📊 SUMMARY")
    print(f"{'='*80}")
    print(f"Total components processed: {total_components}")
    print(f"Total links added/updated: {total_updated}")
    print(f"✅ All files processed!")

if __name__ == '__main__':
    main()

