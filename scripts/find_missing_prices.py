#!/usr/bin/env python3
"""
Find components in PCPartPicker JSON files that have no price or invalid price.
"""

import json
from pathlib import Path
from typing import Dict, List, Any

# Directory containing JSON files
JSON_DIR = Path(__file__).parent / 'pcpartpicker_json'

def is_price_valid(price: Any) -> bool:
    """Check if price is valid (not null, not 0, is a number)"""
    if price is None:
        return False
    if isinstance(price, (int, float)):
        return price > 0
    if isinstance(price, str):
        try:
            price_float = float(price)
            return price_float > 0
        except (ValueError, TypeError):
            return False
    return False

def find_missing_prices_in_file(file_path: Path) -> List[Dict[str, Any]]:
    """Find all components with missing or invalid prices in a JSON file"""
    missing_prices = []
    
    try:
        with open(file_path, 'r', encoding='utf-8') as f:
            data = json.load(f)
        
        if not isinstance(data, list):
            return []
        
        for idx, record in enumerate(data):
            price = record.get('price')
            if not is_price_valid(price):
                missing_prices.append({
                    'file': file_path.name,
                    'index': idx,
                    'name': record.get('name', 'Unknown'),
                    'price': price,
                    'record': record
                })
    
    except json.JSONDecodeError as e:
        print(f"  ❌ Error parsing JSON in {file_path.name}: {e}")
    except Exception as e:
        print(f"  ❌ Error processing {file_path.name}: {e}")
    
    return missing_prices

def main():
    """Main function to find all components with missing prices"""
    if not JSON_DIR.exists():
        print(f"❌ Directory not found: {JSON_DIR}")
        return
    
    json_files = list(JSON_DIR.glob('*.json'))
    
    if not json_files:
        print(f"❌ No JSON files found in {JSON_DIR}")
        return
    
    print(f"🔍 Scanning {len(json_files)} JSON files for missing prices...\n")
    
    all_missing = []
    file_stats = {}
    
    for json_file in sorted(json_files):
        missing = find_missing_prices_in_file(json_file)
        if missing:
            all_missing.extend(missing)
            file_stats[json_file.name] = len(missing)
            print(f"  ⚠️  {json_file.name}: {len(missing)} components without price")
        else:
            print(f"  ✅ {json_file.name}: All components have prices")
    
    print(f"\n{'='*80}")
    print(f"📊 SUMMARY")
    print(f"{'='*80}")
    print(f"Total components without price: {len(all_missing)}")
    print(f"Files with missing prices: {len(file_stats)}")
    
    if all_missing:
        print(f"\n{'='*80}")
        print(f"📋 DETAILED LIST OF COMPONENTS WITHOUT PRICES")
        print(f"{'='*80}\n")
        
        # Group by file
        by_file = {}
        for item in all_missing:
            filename = item['file']
            if filename not in by_file:
                by_file[filename] = []
            by_file[filename].append(item)
        
        for filename in sorted(by_file.keys()):
            items = by_file[filename]
            print(f"\n📁 {filename} ({len(items)} components):")
            print("-" * 80)
            
            for item in items[:20]:  # Show first 20 per file
                price_display = item['price'] if item['price'] is not None else "null"
                print(f"  • {item['name']} (price: {price_display})")
            
            if len(items) > 20:
                print(f"  ... and {len(items) - 20} more")
        
        # Save to JSON file for reference
        output_file = JSON_DIR.parent / 'components_without_prices.json'
        with open(output_file, 'w', encoding='utf-8') as f:
            json.dump({
                'total_count': len(all_missing),
                'files_affected': len(file_stats),
                'by_file': file_stats,
                'components': all_missing
            }, f, indent=2, ensure_ascii=False)
        
        print(f"\n💾 Detailed list saved to: {output_file.name}")
    else:
        print("\n✅ All components have valid prices!")

if __name__ == '__main__':
    main()

