#!/usr/bin/env python3
"""
Remove all "type" fields from JSON files in pcpartpicker_json directory.
The component type will be determined from the filename by update_components.php
"""

import json
from pathlib import Path

# Directory containing JSON files
JSON_DIR = Path(__file__).parent / 'pcpartpicker_json'

def remove_type_fields_from_file(file_path: Path) -> tuple[int, int]:
    """
    Remove "type" fields from all records in a JSON file.
    Returns: (total_count, removed_count)
    """
    print(f"Processing: {file_path.name}...")
    
    try:
        with open(file_path, 'r', encoding='utf-8') as f:
            data = json.load(f)
        
        if not isinstance(data, list):
            print(f"  ⚠️  Skipping {file_path.name}: Not a JSON array")
            return 0, 0
        
        total_count = len(data)
        removed_count = 0
        
        for idx, item in enumerate(data, 1):
            if isinstance(item, dict) and 'type' in item:
                del item['type']
                removed_count += 1
            
            # Progress indicator
            if idx % 100 == 0:
                print(f"    Progress: {idx}/{total_count} (removed: {removed_count})")
        
        # Write back to file
        if removed_count > 0:
            with open(file_path, 'w', encoding='utf-8') as f:
                json.dump(data, f, indent=2, ensure_ascii=False)
            print(f"  ✅ {file_path.name}: Removed {removed_count} type fields")
        else:
            print(f"  ✅ {file_path.name}: No type fields found")
        
        return total_count, removed_count
    
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
    if not JSON_DIR.exists():
        print(f"❌ Directory not found: {JSON_DIR}")
        return
    
    json_files = list(JSON_DIR.glob('*.json'))
    
    if not json_files:
        print(f"❌ No JSON files found in {JSON_DIR}")
        return
    
    print(f"🗑️  Removing 'type' fields from {len(json_files)} JSON files...\n")
    print("   (Component type will be determined from filename by update_components.php)\n")
    
    total_components = 0
    total_removed = 0
    
    for json_file in sorted(json_files):
        total, removed = remove_type_fields_from_file(json_file)
        total_components += total
        total_removed += removed
    
    print(f"\n{'='*80}")
    print(f"📊 SUMMARY")
    print(f"{'='*80}")
    print(f"Total components processed: {total_components}")
    print(f"Total 'type' fields removed: {total_removed}")
    print(f"✅ All files processed!")
    print(f"\n💡 Note: Component types will now be determined from filenames")
    print(f"   by update_components.php using extractCategoryFromFilename()")

if __name__ == '__main__':
    main()

