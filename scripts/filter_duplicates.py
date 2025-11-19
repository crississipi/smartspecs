#!/usr/bin/env python3
"""
Filter duplicate records in PCPartPicker JSON files.
- Removes records with the same name
- If duplicates have different colors, combines colors into comma-separated string
"""

import json
import os
from pathlib import Path
from collections import defaultdict
from typing import Dict, List, Any

# Directory containing JSON files
JSON_DIR = Path(__file__).parent / 'pcpartpicker_json'

def normalize_name(name: str) -> str:
    """Normalize component name for comparison (case-insensitive, strip whitespace)"""
    return name.strip().lower()

def extract_individual_colors(color_value: Any) -> List[str]:
    """Extract individual colors from a color value (handles strings, lists, comma-separated strings)"""
    if not color_value:
        return []
    
    colors = []
    
    if isinstance(color_value, list):
        # If it's already a list, process each item
        for item in color_value:
            if item:
                item_str = str(item).strip()
                # Split if comma-separated
                if ',' in item_str:
                    colors.extend([c.strip() for c in item_str.split(',') if c.strip()])
                else:
                    colors.append(item_str)
    else:
        # It's a string (or other type)
        color_str = str(color_value).strip()
        if ',' in color_str:
            # Split comma-separated string
            colors.extend([c.strip() for c in color_str.split(',') if c.strip()])
        else:
            colors.append(color_str)
    
    return colors

def combine_colors(colors: List[Any]) -> str:
    """Combine multiple colors into a comma-separated string, removing duplicates"""
    # First, extract all individual colors from each color value
    all_colors = []
    for color in colors:
        all_colors.extend(extract_individual_colors(color))
    
    # Remove duplicates (case-insensitive) while preserving order
    unique_colors = []
    seen_lower = set()
    
    for color in all_colors:
        color_lower = color.lower().strip()
        if color_lower and color_lower not in seen_lower:
            unique_colors.append(color.strip())
            seen_lower.add(color_lower)
    
    return ', '.join(unique_colors) if unique_colors else None

def merge_duplicates(records: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
    """
    Merge duplicate records based on name.
    If duplicates have different colors, combine them.
    """
    # Group records by normalized name
    name_groups = defaultdict(list)
    
    for record in records:
        name = record.get('name', '').strip()
        if name:
            normalized = normalize_name(name)
            name_groups[normalized].append(record)
    
    merged_records = []
    
    for normalized_name, group in name_groups.items():
        if len(group) == 1:
            # No duplicates, keep as is
            merged_records.append(group[0])
        else:
            # Multiple records with same name - merge them
            # Use the first record as base
            merged = group[0].copy()
            
            # Collect all colors from duplicates
            colors = []
            for record in group:
                color = record.get('color')
                if color:
                    colors.append(color)
            
            # Combine colors if we have multiple unique colors
            if colors:
                combined_color = combine_colors(colors)
                if combined_color:
                    merged['color'] = combined_color
                elif 'color' in merged:
                    # If no valid colors, remove color field
                    del merged['color']
            else:
                # No colors found, remove color field if it exists
                if 'color' in merged:
                    del merged['color']
            
            # For other fields, prefer non-null values or use first record's value
            for record in group[1:]:
                for key, value in record.items():
                    if key not in ['name', 'color']:  # Skip name (already merged) and color (already handled)
                        # If merged record has null/empty value, use the duplicate's value
                        if key not in merged or not merged[key] or merged[key] == 'null':
                            if value and value != 'null':
                                merged[key] = value
            
            merged_records.append(merged)
    
    return merged_records

def process_json_file(file_path: Path) -> tuple[int, int]:
    """
    Process a single JSON file to remove duplicates.
    Returns: (original_count, filtered_count)
    """
    print(f"Processing: {file_path.name}...")
    
    try:
        # Read JSON file
        with open(file_path, 'r', encoding='utf-8') as f:
            data = json.load(f)
        
        if not isinstance(data, list):
            print(f"  ⚠️  Skipping {file_path.name}: Not a JSON array")
            return 0, 0
        
        original_count = len(data)
        
        # Merge duplicates
        filtered_data = merge_duplicates(data)
        filtered_count = len(filtered_data)
        
        duplicates_removed = original_count - filtered_count
        
        # Write back to file
        with open(file_path, 'w', encoding='utf-8') as f:
            json.dump(filtered_data, f, indent=2, ensure_ascii=False)
        
        print(f"  ✅ {file_path.name}: {original_count} → {filtered_count} records ({duplicates_removed} duplicates removed)")
        
        return original_count, filtered_count
        
    except json.JSONDecodeError as e:
        print(f"  ❌ Error parsing JSON in {file_path.name}: {e}")
        return 0, 0
    except Exception as e:
        print(f"  ❌ Error processing {file_path.name}: {e}")
        return 0, 0

def main():
    """Main function to process all JSON files"""
    if not JSON_DIR.exists():
        print(f"❌ Directory not found: {JSON_DIR}")
        return
    
    json_files = list(JSON_DIR.glob('*.json'))
    
    if not json_files:
        print(f"❌ No JSON files found in {JSON_DIR}")
        return
    
    print(f"🔍 Found {len(json_files)} JSON files to process\n")
    
    total_original = 0
    total_filtered = 0
    
    for json_file in sorted(json_files):
        original, filtered = process_json_file(json_file)
        total_original += original
        total_filtered += filtered
    
    print(f"\n📊 Summary:")
    print(f"  Total original records: {total_original}")
    print(f"  Total filtered records: {total_filtered}")
    print(f"  Duplicates removed: {total_original - total_filtered}")
    print(f"  ✅ All files processed successfully!")

if __name__ == '__main__':
    main()

