#!/usr/bin/env python3
"""
Generate PCPartPicker image URLs from component names.
Note: The hash in the URL is likely based on PCPartPicker's internal product ID,
not the product name itself, so exact matching requires product ID lookup.
"""

import json
import hashlib
import re
from pathlib import Path
from urllib.parse import quote

# Directory containing JSON files
JSON_DIR = Path(__file__).parent / 'pcpartpicker_json'

# PCPartPicker image URL base
PCPARTPICKER_IMAGE_BASE = "https://cdna.pcpartpicker.com/static/forever/images/product"

def extract_hash_from_url(url):
    """Extract hash from existing PCPartPicker image URL"""
    if not url or not isinstance(url, str):
        return None
    
    # Pattern: https://cdna.pcpartpicker.com/static/forever/images/product/{hash}.256p.jpg
    match = re.search(r'product/([a-f0-9]{32})\.256p\.jpg', url)
    if match:
        return match.group(1)
    return None

def generate_image_url_from_hash(hash_value):
    """Generate PCPartPicker image URL from hash"""
    if not hash_value or len(hash_value) != 32:
        return None
    
    return f"{PCPARTPICKER_IMAGE_BASE}/{hash_value}.256p.jpg"

def find_existing_image_urls(file_path: Path):
    """Find existing PCPartPicker image URLs in JSON file"""
    try:
        with open(file_path, 'r', encoding='utf-8') as f:
            data = json.load(f)
        
        if not isinstance(data, list):
            return []
        
        existing_urls = {}
        for item in data:
            name = item.get('name', '')
            image_url = item.get('image_url', '')
            
            if image_url and 'pcpartpicker.com' in image_url:
                hash_value = extract_hash_from_url(image_url)
                if hash_value:
                    existing_urls[name] = {
                        'hash': hash_value,
                        'url': image_url
                    }
        
        return existing_urls
    except Exception as e:
        print(f"Error reading {file_path.name}: {e}")
        return {}

def update_image_urls_in_file(file_path: Path, hash_mapping: dict = None) -> tuple[int, int]:
    """
    Update image_url fields in a JSON file with PCPartPicker URLs if hash is available.
    hash_mapping should be a dict like: { "Product Name": "hash_value" }
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
        
        # Find existing hashes in file
        existing_urls = find_existing_image_urls(file_path)
        
        for idx, item in enumerate(data, 1):
            name = item.get('name', '')
            current_url = item.get('image_url', '')
            
            # Skip if already has a real PCPartPicker URL
            if current_url and 'pcpartpicker.com' in current_url and extract_hash_from_url(current_url):
                skipped_count += 1
                continue
            
            # Try to find hash from mapping or existing URLs
            hash_value = None
            if hash_mapping and name in hash_mapping:
                hash_value = hash_mapping[name]
            elif name in existing_urls:
                hash_value = existing_urls[name]['hash']
            
            if hash_value:
                new_url = generate_image_url_from_hash(hash_value)
                if new_url:
                    item['image_url'] = new_url
                    updated_count += 1
                else:
                    skipped_count += 1
            else:
                skipped_count += 1
            
            # Progress indicator
            if idx % 100 == 0:
                print(f"    Progress: {idx}/{total_count} (updated: {updated_count})")
        
        # Write back to file
        if updated_count > 0:
            with open(file_path, 'w', encoding='utf-8') as f:
                json.dump(data, f, indent=2, ensure_ascii=False)
            print(f"  ✅ {file_path.name}: Updated {updated_count} image URLs ({skipped_count} skipped)")
        else:
            print(f"  ✅ {file_path.name}: No updates needed ({skipped_count} already have URLs or no hash)")
        
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
    
    # Hash mapping from user's examples
    # Format: { "Product Name": "hash_value" }
    known_hashes = {
        "Intel Core Ultra 5 225": "58a1d2f8d0a6d13c9a6bd33593b65980",
        "Intel Core i7-12700K": "3f7037db801def4db8418df8e7498e6a",
        "AMD Ryzen 5 8600G": "d5ae2e9f000a9c994f70e092b05c80ef",
        "Intel Core i7-11700F": "fd3cc64653e9040372bb3570a019e71e",
        "Intel UHD Graphics 770": "c3d3a6843e0c0a5d98155e9fa68c092c",
    }
    
    if not JSON_DIR.exists():
        print(f"❌ Directory not found: {JSON_DIR}")
        return
    
    json_files = list(JSON_DIR.glob('*.json'))
    
    if not json_files:
        print(f"❌ No JSON files found in {JSON_DIR}")
        return
    
    print(f"🖼️  Updating PCPartPicker image URLs in {len(json_files)} JSON files...\n")
    print("📝 Note: Hash is based on PCPartPicker's internal product ID, not product name.")
    print("   This script will use known hashes and existing URLs in files.\n")
    
    total_components = 0
    total_updated = 0
    
    for json_file in sorted(json_files):
        total, updated = update_image_urls_in_file(json_file, known_hashes)
        total_components += total
        total_updated += updated
    
    print(f"\n{'='*80}")
    print(f"📊 SUMMARY")
    print(f"{'='*80}")
    print(f"Total components processed: {total_components}")
    print(f"Total image URLs updated: {total_updated}")
    print(f"✅ All files processed!")
    print(f"\n💡 To get more image hashes, you need to:")
    print(f"   1. Scrape PCPartPicker product pages for image URLs")
    print(f"   2. Extract hashes from product detail pages")
    print(f"   3. Or use PCPartPicker API (if available)")

if __name__ == '__main__':
    main()

