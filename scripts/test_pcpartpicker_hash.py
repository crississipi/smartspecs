#!/usr/bin/env python3
"""
Test script to decrypt PCPartPicker image URL hash pattern
"""

import hashlib
import urllib.parse

# Test cases from user
test_cases = [
    ('Intel Core Ultra 5 225', '58a1d2f8d0a6d13c9a6bd33593b65980'),
    ('Intel Core i7-12700K', '3f7037db801def4db8418df8e7498e6a'),
    ('AMD Ryzen 5 8600G', 'd5ae2e9f000a9c994f70e092b05c80ef'),
    ('Intel Core i7-11700F', 'fd3cc64653e9040372bb3570a019e71e'),
    ('Intel UHD Graphics 770', 'c3d3a6843e0c0a5d98155e9fa68c092c'),
]

def test_variations(name, expected_hash):
    """Test various string variations to find the hash pattern"""
    variations = [
        # Basic variations
        (name, 'Original'),
        (name.lower(), 'Lowercase'),
        (name.upper(), 'Uppercase'),
        (name.strip(), 'Stripped'),
        
        # Space variations
        (name.replace(' ', ''), 'No spaces'),
        (name.replace(' ', '-'), 'Spaces to hyphens'),
        (name.replace(' ', '_'), 'Spaces to underscores'),
        (' '.join(name.split()), 'Normalized spaces'),
        
        # Special character variations
        (name.replace('-', ''), 'No hyphens'),
        (name.replace('Core', 'Core').replace(' ', ''), 'No spaces (Core)'),
        
        # URL encoded
        (urllib.parse.quote(name), 'URL encoded'),
        (urllib.parse.quote_plus(name), 'URL encoded plus'),
        
        # Category prefixes
        (f'cpu/{name}', 'cpu/ prefix'),
        (f'cpu-{name}', 'cpu- prefix'),
        (f'gpu/{name}', 'gpu/ prefix'),
        
        # Normalized variations
        (name.replace('Core i7', 'Core-i7'), 'Hyphenated Core'),
        (name.replace('Core Ultra', 'Core-Ultra'), 'Hyphenated Ultra'),
        
        # Product slug variations (common PCPartPicker format)
        (name.replace(' ', '-').lower(), 'Slug format'),
        ('-'.join(name.lower().split()), 'Slug format alt'),
        
        # Brand variations
        (name.replace('Intel ', ''), 'No Intel'),
        (name.replace('AMD ', ''), 'No AMD'),
    ]
    
    print(f"\n{'='*80}")
    print(f"Testing: {name}")
    print(f"Expected hash: {expected_hash}")
    print(f"{'='*80}")
    
    matches = []
    for variant, description in variations:
        hash_result = hashlib.md5(variant.encode('utf-8')).hexdigest()
        match = hash_result == expected_hash
        status = "✓ MATCH!" if match else ""
        
        if match:
            matches.append((variant, description, hash_result))
            print(f"✓ FOUND: {description:30s} → '{variant}' → {hash_result}")
        else:
            # Only show non-matches if verbose
            pass
    
    return matches

if __name__ == '__main__':
    print("Testing PCPartPicker image hash patterns...")
    print("="*80)
    
    all_matches = []
    for name, expected_hash in test_cases:
        matches = test_variations(name, expected_hash)
        if matches:
            all_matches.extend(matches)
    
    print("\n" + "="*80)
    print("SUMMARY")
    print("="*80)
    
    if all_matches:
        print(f"\nFound {len(all_matches)} matching pattern(s):")
        for variant, description, hash_result in all_matches:
            print(f"  Pattern: {description}")
            print(f"  Example: '{variant}' → {hash_result}")
    else:
        print("\nNo exact matches found. The hash might be:")
        print("  1. Based on PCPartPicker's internal product ID (not name-based)")
        print("  2. Using a different hash algorithm")
        print("  3. Includes additional metadata (category, brand, etc.)")
        print("  4. Normalized in a specific way unique to PCPartPicker")

