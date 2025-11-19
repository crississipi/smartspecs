#!/usr/bin/env python3
"""Verify that image_url fields have been added to all components"""

import json
from pathlib import Path

JSON_DIR = Path(__file__).parent / 'pcpartpicker_json'

def verify_file(file_path: Path):
    """Verify image URLs in a single file"""
    try:
        with open(file_path, 'r', encoding='utf-8') as f:
            data = json.load(f)
        
        if not isinstance(data, list):
            return None, None, None
        
        total = len(data)
        with_image_url = sum(1 for x in data if 'image_url' in x and x.get('image_url'))
        
        # Check if URLs are placeholders or real URLs
        placeholder_count = sum(1 for x in data 
                               if x.get('image_url', '').startswith('data:image/svg'))
        real_url_count = with_image_url - placeholder_count
        
        return total, with_image_url, placeholder_count, real_url_count
    except Exception as e:
        return None, None, None, None

def main():
    json_files = list(JSON_DIR.glob('*.json'))
    
    print("🔍 Verifying image URLs in JSON files...\n")
    
    total_components = 0
    total_with_images = 0
    total_placeholders = 0
    total_real_urls = 0
    
    for json_file in sorted(json_files):
        total, with_url, placeholders, real = verify_file(json_file)
        
        if total is not None:
            total_components += total
            total_with_images += with_url
            total_placeholders += placeholders
            total_real_urls += real
            
            status = "✅" if with_url == total else "⚠️"
            print(f"{status} {json_file.name}:")
            print(f"    Total components: {total}")
            print(f"    With image_url: {with_url} ({with_url/total*100:.1f}%)")
            print(f"    Placeholders: {placeholders}")
            print(f"    Real URLs: {real}")
        else:
            print(f"❌ {json_file.name}: Error reading file")
    
    print(f"\n{'='*80}")
    print(f"📊 SUMMARY")
    print(f"{'='*80}")
    print(f"Total components: {total_components}")
    print(f"Components with image_url: {total_with_images} ({total_with_images/total_components*100:.1f}%)")
    print(f"Placeholder images: {total_placeholders}")
    print(f"Real image URLs: {total_real_urls}")
    
    if total_with_images == total_components:
        print(f"\n✅ SUCCESS: All components have image_url fields!")
    else:
        print(f"\n⚠️  WARNING: {total_components - total_with_images} components missing image_url")

if __name__ == '__main__':
    main()

