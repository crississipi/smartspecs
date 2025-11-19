#!/usr/bin/env python3
"""
Update prices for components without prices in PCPartPicker JSON files.
Uses multiple strategies:
1. Find similar products with prices and estimate
2. Use average prices for the category
3. Allow manual price updates via CSV
"""

import json
import re
from pathlib import Path
from typing import Dict, List, Any, Optional
from collections import defaultdict
import statistics

# Directory containing JSON files
JSON_DIR = Path(__file__).parent / 'pcpartpicker_json'
MISSING_PRICES_FILE = Path(__file__).parent / 'components_without_prices.json'

def normalize_name(name: str) -> str:
    """Normalize component name for comparison"""
    return re.sub(r'[^\w\s]', '', name.lower()).strip()

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
        'Logitech', 'Razer', 'SteelSeries', 'Creative Labs', 'Kanto', 'Edifier'
    ]
    
    name_upper = name.upper()
    for brand in common_brands:
        if brand.upper() in name_upper:
            return brand
    return None

def find_similar_products(component: Dict[str, Any], all_components: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
    """Find similar products with prices"""
    component_name = normalize_name(component.get('name', ''))
    component_brand = extract_brand(component.get('name', ''))
    
    similar = []
    
    for comp in all_components:
        if comp.get('price') and comp.get('price') > 0:
            comp_name = normalize_name(comp.get('name', ''))
            comp_brand = extract_brand(comp.get('name', ''))
            
            # Check if same brand
            if component_brand and comp_brand and component_brand == comp_brand:
                # Check name similarity
                name_words = set(component_name.split())
                comp_words = set(comp_name.split())
                common_words = name_words.intersection(comp_words)
                
                if len(common_words) >= 2:  # At least 2 common words
                    similar.append(comp)
    
    return similar

def estimate_price(component: Dict[str, Any], similar_products: List[Dict[str, Any]], 
                  category_avg: float) -> Optional[float]:
    """Estimate price based on similar products"""
    if not similar_products:
        return None
    
    # Get prices from similar products
    prices = [p.get('price') for p in similar_products if p.get('price') and p.get('price') > 0]
    
    if prices:
        # Use median price from similar products
        return statistics.median(prices)
    
    return None

def get_category_average_price(file_path: Path) -> Optional[float]:
    """Calculate average price for components in a category"""
    try:
        with open(file_path, 'r', encoding='utf-8') as f:
            data = json.load(f)
        
        if not isinstance(data, list):
            return None
        
        prices = [item.get('price') for item in data 
                 if item.get('price') and isinstance(item.get('price'), (int, float)) and item.get('price') > 0]
        
        if prices:
            return statistics.median(prices)  # Use median instead of mean to avoid outliers
        
        return None
    except Exception:
        return None

def update_prices_in_file(file_path: Path, price_updates: Dict[str, float]) -> int:
    """Update prices in a JSON file"""
    try:
        with open(file_path, 'r', encoding='utf-8') as f:
            data = json.load(f)
        
        if not isinstance(data, list):
            return 0
        
        updated_count = 0
        
        for item in data:
            name = item.get('name', '')
            if name in price_updates:
                old_price = item.get('price')
                new_price = price_updates[name]
                item['price'] = new_price
                updated_count += 1
                print(f"  ✅ Updated {name}: {old_price} → {new_price}")
        
        if updated_count > 0:
            # Write back to file
            with open(file_path, 'w', encoding='utf-8') as f:
                json.dump(data, f, indent=2, ensure_ascii=False)
        
        return updated_count
    
    except Exception as e:
        print(f"  ❌ Error updating {file_path.name}: {e}")
        return 0

def main():
    """Main function to update missing prices"""
    if not MISSING_PRICES_FILE.exists():
        print(f"❌ Missing prices file not found: {MISSING_PRICES_FILE}")
        print("   Please run find_missing_prices.py first")
        return
    
    # Load components without prices
    with open(MISSING_PRICES_FILE, 'r', encoding='utf-8') as f:
        missing_data = json.load(f)
    
    components_without_prices = missing_data.get('components', [])
    
    if not components_without_prices:
        print("✅ No components without prices found!")
        return
    
    print(f"🔍 Processing {len(components_without_prices)} components without prices...\n")
    
    # Group by file
    by_file = defaultdict(list)
    for comp in components_without_prices:
        by_file[comp['file']].append(comp)
    
    total_updated = 0
    price_updates = {}  # {component_name: new_price}
    
    for filename, components in sorted(by_file.items()):
        file_path = JSON_DIR / filename
        
        if not file_path.exists():
            print(f"⚠️  File not found: {filename}")
            continue
        
        print(f"\n📁 Processing {filename} ({len(components)} components without prices)...")
        
        # Load all components from this file
        try:
            with open(file_path, 'r', encoding='utf-8') as f:
                all_components = json.load(f)
        except Exception as e:
            print(f"  ❌ Error reading file: {e}")
            continue
        
        # Get category average price
        category_avg = get_category_average_price(file_path)
        if category_avg:
            print(f"  📊 Category average price: ${category_avg:.2f}")
        
        # Process each component without price
        for comp_data in components:
            component = comp_data['record']
            name = component.get('name', '')
            
            # Find similar products
            similar = find_similar_products(component, all_components)
            
            # Estimate price
            estimated_price = None
            if similar:
                estimated_price = estimate_price(component, similar, category_avg)
                if estimated_price:
                    print(f"  💰 {name}: Estimated ${estimated_price:.2f} (based on {len(similar)} similar products)")
            elif category_avg:
                # Use category average if no similar products found
                estimated_price = category_avg
                print(f"  💰 {name}: Estimated ${estimated_price:.2f} (category average)")
            else:
                # Use a default small price for very common items
                if 'thermal paste' in name.lower() or 'paste' in name.lower():
                    estimated_price = 5.0
                elif 'fan controller' in name.lower() or 'hub' in name.lower():
                    estimated_price = 15.0
                elif 'network card' in name.lower() or 'wifi' in name.lower():
                    estimated_price = 25.0
                elif 'optical drive' in name.lower() or 'dvd' in name.lower() or 'blu-ray' in name.lower():
                    estimated_price = 30.0
                elif 'case accessory' in name.lower() or 'led' in name.lower():
                    estimated_price = 20.0
                elif 'windows' in name.lower() or 'os' in name.lower():
                    estimated_price = 100.0
                else:
                    estimated_price = 10.0  # Default fallback
                
                print(f"  💰 {name}: Estimated ${estimated_price:.2f} (default)")
            
            if estimated_price:
                price_updates[name] = estimated_price
        
        # Update the file
        updated = update_prices_in_file(file_path, price_updates)
        total_updated += updated
        price_updates.clear()  # Clear for next file
    
    print(f"\n{'='*80}")
    print(f"📊 SUMMARY")
    print(f"{'='*80}")
    print(f"✅ Total components updated: {total_updated}")
    print(f"✅ All JSON files have been updated!")
    print(f"\n💡 Note: Prices were estimated based on similar products and category averages.")
    print(f"   You may want to review and adjust prices manually if needed.")

if __name__ == '__main__':
    main()

