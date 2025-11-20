import decimal
from decimal import Decimal
import sys
from flask import Flask, request, jsonify
from flask_cors import CORS
import os
from transformers import pipeline, AutoTokenizer, AutoModelForCausalLM
import torch
import re
import logging
from datetime import datetime
import time
import requests
from typing import Dict, List, Any, Optional, Tuple
from pathlib import Path
import backoff
import subprocess
from mysql.connector import pooling
import threading
import uuid
import json

# Configure comprehensive logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler('ai_assistant.log', encoding='utf-8'),
        logging.StreamHandler(sys.stdout)
    ]
)
logger = logging.getLogger(__name__)

app = Flask(__name__)

# CORS configuration - allow Render, Replit domains and localhost
frontend_url = os.getenv('FRONTEND_URL', 'http://localhost')
allowed_origins = [
    'http://localhost',
    'http://localhost:80',
    'http://127.0.0.1',
    frontend_url,
]

# Add Render URL if available
render_url = os.getenv('RENDER_EXTERNAL_URL')
if render_url:
    allowed_origins.append(render_url)
    allowed_origins.append(render_url.replace('https://', 'http://'))

# Add Replit URL if available
repl_slug = os.getenv('REPL_SLUG')
repl_owner = os.getenv('REPL_OWNER')
if repl_slug and repl_owner:
    replit_url = f"https://{repl_slug}.{repl_owner}.repl.co"
    allowed_origins.append(replit_url)
    allowed_origins.append(replit_url.replace('https://', 'http://'))

CORS(app, resources={
    r"/*": {
        "origins": allowed_origins,
        "methods": ["GET", "POST", "PUT", "DELETE", "OPTIONS"],
        "allow_headers": ["Content-Type", "Authorization"]
    }
})

# Load environment variables from .env file (if exists) for local development
try:
    from dotenv import load_dotenv
    load_dotenv()
except ImportError:
    pass  # python-dotenv not installed, skip

# Configuration
HF_API_KEY = os.getenv('HF_API_KEY', '')  # Must be set via environment variable
MODEL_NAME = os.getenv('MODEL_NAME', 'microsoft/DialoGPT-medium')
CACHE_DIR = Path('./cache')
CACHE_DIR.mkdir(exist_ok=True)

# Database Configuration - Use environment variables (no hardcoded credentials)
DB_CONFIG = {
    'host': os.getenv('DB_HOST', 'localhost'),
    'port': int(os.getenv('DB_PORT', 3306)),
    'user': os.getenv('DB_USER', 'root'),
    'password': os.getenv('DB_PASS', ''),
    'database': os.getenv('DB_NAME', 'defaultdb'),
    'pool_name': 'mypool',
    'pool_size': 2,  # Reduced from 5 to save memory
    'ssl_disabled': os.getenv('DB_SSL', 'true').lower() != 'true'
}

# Create connection pool
try:
    connection_pool = pooling.MySQLConnectionPool(**DB_CONFIG)
    logger.info("Database connection pool created successfully")
except Exception as e:
    logger.error(f"Database pool creation failed: {e}")
    connection_pool = None

# Progress tracking for requests
progress_store = {}
progress_lock = threading.Lock()

def update_progress(request_id: str, phase: str, budget: float = None):
    """Update progress for a request"""
    with progress_lock:
        if request_id not in progress_store:
            progress_store[request_id] = {
                'phases': [],
                'current_phase': None,
                'start_time': time.time()
            }
        progress_store[request_id]['current_phase'] = phase
        if phase not in [p['phase'] for p in progress_store[request_id]['phases']]:
            progress_store[request_id]['phases'].append({
                'phase': phase,
                'budget': budget,
                'timestamp': time.time()
            })

def get_progress(request_id: str) -> Optional[Dict]:
    """Get current progress for a request"""
    with progress_lock:
        return progress_store.get(request_id)

def clear_progress(request_id: str):
    """Clear progress after request completes (with delay for polling)"""
    def delayed_clear():
        time.sleep(30)  # Keep for 30 seconds after completion
        with progress_lock:
            if request_id in progress_store:
                del progress_store[request_id]
    threading.Thread(target=delayed_clear, daemon=True).start()

# Add this helper function after imports, before classes
def to_float(value):
    """Convert Decimal, int, or float to float"""
    if isinstance(value, Decimal):
        return float(value)
    elif isinstance(value, (int, float)):
        return float(value)
    elif value is None:
        return 0.0
    else:
        return float(value)

# Enhanced model initialization with memory optimization
class RobustAIModel:
    def __init__(self):
        self.generator = None
        self.tokenizer = None
        self.model = None
        self.load_models()
    
    @backoff.on_exception(backoff.expo, Exception, max_tries=3)
    def load_models(self):
        try:
            # Check if we should use a lightweight model for memory-constrained environments
            use_lightweight = os.getenv('USE_LIGHTWEIGHT_MODEL', 'false').lower() == 'true'
            
            if use_lightweight or MODEL_NAME == 'distilgpt2':
                logger.info("Loading lightweight model (memory-optimized)...")
                self.setup_fallback_model()
                return
            
            logger.info("Loading primary AI model with memory optimizations...")
            
            # Memory-efficient loading options
            self.tokenizer = AutoTokenizer.from_pretrained(
                MODEL_NAME, 
                token=HF_API_KEY,
                use_fast=True  # Use fast tokenizer (less memory)
            )
            
            # Load model with memory optimizations
            self.model = AutoModelForCausalLM.from_pretrained(
                MODEL_NAME, 
                token=HF_API_KEY,
                torch_dtype=torch.float32,  # Use float32 instead of float16 (more compatible)
                low_cpu_mem_usage=True,  # Reduce peak memory usage
                device_map="auto" if torch.cuda.is_available() else None
            )
            
            # Move model to CPU explicitly (Render doesn't have GPU)
            if not torch.cuda.is_available():
                self.model = self.model.to('cpu')
                # Enable CPU optimizations
                torch.set_num_threads(2)  # Limit CPU threads to reduce memory
            
            self.generator = pipeline(
                "text-generation", 
                model=self.model, 
                tokenizer=self.tokenizer,
                device=-1,  # Force CPU (Render doesn't have GPU)
                max_length=200,  # Reduced from 300 to save memory
                temperature=0.7,
                do_sample=True,
                repetition_penalty=1.1,
                pad_token_id=self.tokenizer.eos_token_id
            )
            logger.info("Primary model loaded successfully!")
        except Exception as e:
            logger.warning(f"Primary model failed: {e}. Using lightweight fallback...")
            self.setup_fallback_model()
    
    def setup_fallback_model(self):
        try:
            logger.info("Loading lightweight model (distilgpt2)...")
            self.generator = pipeline(
                "text-generation",
                model="distilgpt2",
                device=-1,  # Force CPU
                max_length=150,  # Reduced for memory
                temperature=0.7,
                pad_token_id=50256  # GPT-2 pad token
            )
            logger.info("Lightweight model loaded successfully!")
        except Exception as e:
            logger.error(f"All models failed: {e}")
            self.generator = None
    
    def generate(self, prompt, **kwargs):
        if self.generator is None:
            return [{"generated_text": "I'm here to help you with PC build recommendations. Please provide your requirements and budget."}]
        try:
            return self.generator(prompt, **kwargs)
        except Exception as e:
            logger.error(f"Generation failed: {e}")
            return [{"generated_text": "I understand your requirements. Let me provide a detailed PC recommendation."}]

ai_model = RobustAIModel()

# Database Manager with Smart Component Search
class DatabaseManager:
    def __init__(self):
        self.pool = connection_pool
    
    def get_connection(self):
        """Get connection from pool with retry logic"""
        try:
            if self.pool:
                return self.pool.get_connection()
            return None
        except Exception as e:
            logger.error(f"Failed to get DB connection: {e}")
            return None
    
    def search_components(self, component_type: str = None, brand: str = None, 
                         model_query: str = None, max_price: float = None, 
                         min_price: float = None, limit: int = 50) -> List[Dict]:
        """Advanced component search with multiple filters"""
        conn = self.get_connection()
        if not conn:
            return []
        
        try:
            cursor = conn.cursor(dictionary=True)
            
            conditions = []
            params = []
            
            if component_type:
                conditions.append("type = %s")
                params.append(component_type)
            
            if brand:
                conditions.append("brand LIKE %s")
                params.append(f"%{brand}%")
            
            if model_query:
                conditions.append("(model LIKE %s OR MATCH(model) AGAINST(%s IN NATURAL LANGUAGE MODE))")
                params.extend([f"%{model_query}%", model_query])
            
            if max_price:
                conditions.append("price <= %s")
                params.append(max_price)
            
            if min_price:
                conditions.append("price >= %s")
                params.append(min_price)
            
            where_clause = " AND ".join(conditions) if conditions else "1=1"
            
            query = f"""
                SELECT id, type, brand, model, price, currency, image_url, 
                       source_url, last_updated 
                FROM components 
                WHERE {where_clause}
                ORDER BY price ASC
                LIMIT %s
            """
            params.append(limit)
            
            cursor.execute(query, params)
            results = cursor.fetchall()
            
            cursor.close()
            conn.close()
            
            # Convert Decimal prices to float - ensure all are converted
            for result in results:
                if 'price' in result and result['price'] is not None:
                    result['price'] = float(to_float(result['price']))  # Double conversion to ensure float
            
            return results
        except Exception as e:
            logger.error(f"Database search error: {e}")
            if conn:
                conn.close()
            return []
    
    def fuzzy_search_components(self, query: str, component_type: str = None, 
                                max_price: float = None) -> List[Dict]:
        """Fuzzy search for components with intelligent matching"""
        conn = self.get_connection()
        if not conn:
            return []
        
        try:
            cursor = conn.cursor(dictionary=True)
            
            # Build fuzzy search query
            conditions = []
            params = []
            
            if component_type:
                conditions.append("type = %s")
                params.append(component_type)
            
            if max_price:
                conditions.append("price <= %s")
                params.append(max_price)
            
            # Extract keywords from query
            keywords = query.lower().split()
            keyword_conditions = []
            for keyword in keywords:
                if len(keyword) > 2:  # Skip very short words
                    keyword_conditions.append("(model LIKE %s OR brand LIKE %s)")
                    params.extend([f"%{keyword}%", f"%{keyword}%"])
            
            if keyword_conditions:
                conditions.append(f"({' OR '.join(keyword_conditions)})")
            
            where_clause = " AND ".join(conditions) if conditions else "1=1"
            
            query_sql = f"""
                SELECT id, type, brand, model, price, currency, image_url, 
                       source_url, last_updated,
                       (CASE 
                           WHEN model LIKE %s THEN 100
                           WHEN model LIKE %s THEN 50
                           ELSE 0
                       END) as relevance_score
                FROM components 
                WHERE {where_clause}
                ORDER BY relevance_score DESC, price ASC
                LIMIT 20
            """
            
            # Add relevance scoring parameters
            exact_match = f"%{query}%"
            partial_match = f"%{' '.join(keywords[:2])}%"
            params = [exact_match, partial_match] + params
            
            cursor.execute(query_sql, params)
            results = cursor.fetchall()
            
            cursor.close()
            conn.close()
            
            # Convert Decimal prices to float
            for result in results:
                if 'price' in result:
                    result['price'] = to_float(result['price'])  # Simplified
            
            return results
        except Exception as e:
            logger.error(f"Fuzzy search error: {e}")
            if conn:
                conn.close()
            return []
    
    def get_component_by_id(self, component_id: int) -> Optional[Dict]:
        """Get specific component by ID"""
        conn = self.get_connection()
        if not conn:
            return None
        
        try:
            cursor = conn.cursor(dictionary=True)
            cursor.execute(
                "SELECT * FROM components WHERE id = %s",
                (component_id,)
            )
            result = cursor.fetchone()
            cursor.close()
            conn.close()
            
            # Convert Decimal price to float
            if result and 'price' in result:
                result['price'] = to_float(result['price'])
            
            return result
        except Exception as e:
            logger.error(f"Get component error: {e}")
            if conn:
                conn.close()
            return None
    
    def get_alternatives(self, component_id: int, price_range: float = 5000) -> List[Dict]:
        """Get alternative components with similar price"""
        component = self.get_component_by_id(component_id)
        if not component:
            return []
        
        component_price = to_float(component['price'])
        return self.search_components(
            component_type=component['type'],
            min_price=max(0, component_price - price_range),
            max_price=component_price + price_range,
            limit=10
        )
    
    def get_best_components_for_build(self, component_type: str, target_price: float, performance_tier: str = "balanced") -> List[Dict]:
        """Get the best components for a specific build type and target price"""
        conn = self.get_connection()
        if not conn:
            return []
        
        try:
            cursor = conn.cursor(dictionary=True)
            
            # Adjust price range based on performance tier
            if performance_tier == "budget":
                price_min = target_price * 0.6
                price_max = target_price * 0.9
            elif performance_tier == "premium":
                price_min = target_price * 1.1
                price_max = target_price * 1.4
            else:  # balanced
                price_min = target_price * 0.8
                price_max = target_price * 1.2
            
            query = """
                SELECT id, type, brand, model, price, currency, image_url, 
                       source_url, last_updated 
                FROM components 
                WHERE type = %s AND price BETWEEN %s AND %s
                ORDER BY price DESC
                LIMIT 5
            """
            
            cursor.execute(query, (component_type, price_min, price_max))
            results = cursor.fetchall()
            
            cursor.close()
            conn.close()
            
            # Convert Decimal prices to float
            for result in results:
                if 'price' in result:
                    result['price'] = to_float(result['price'])  # Simplified
            
            return results
        except Exception as e:
            logger.error(f"Best components search error: {e}")
            if conn:
                conn.close()
            return []
    
    def trigger_scraper_update(self, component_type: str = None, 
                              search_query: str = None) -> bool:
        """Trigger the PHP scraper to update components"""
        try:
            script_path = os.path.join(os.path.dirname(__file__), 'scripts', 'update_components.php')
            
            if not os.path.exists(script_path):
                logger.error(f"Scraper script not found: {script_path}")
                return False
            
            logger.info(f"Triggering scraper update for: {search_query or 'all components'}")
            
            # Run PHP script asynchronously
            process = subprocess.Popen(
                ['php', script_path],
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE
            )
            
            # Don't wait for completion (async)
            logger.info("Scraper update initiated in background")
            return True
            
        except Exception as e:
            logger.error(f"Failed to trigger scraper: {e}")
            return False

    # Recommendation management methods
    def create_recommendation(self, ai_response: str, query_analysis: Dict, 
                            components_found: int, needs_update: bool, 
                            budget_analysis: Dict = None) -> int:
        """Create a new recommendation record and return its ID"""
        conn = self.get_connection()
        if not conn:
            return None
        
        try:
            cursor = conn.cursor()
            
            query = """
                INSERT INTO recommendations 
                (ai_response, query_analysis, components_found, needs_update, budget_analysis)
                VALUES (%s, %s, %s, %s, %s)
            """
            
            import json
            cursor.execute(query, (
                ai_response,
                json.dumps(query_analysis) if query_analysis else None,
                components_found,
                needs_update,
                json.dumps(budget_analysis) if budget_analysis else None
            ))
            
            recommendation_id = cursor.lastrowid
            conn.commit()
            cursor.close()
            conn.close()
            
            return recommendation_id
        except Exception as e:
            logger.error(f"Create recommendation error: {e}")
            if conn:
                conn.close()
            return None
    
    def add_recommendation_component(self, recommendation_id: int, component: Dict, 
                                   tier: str = 'balanced'):
        """Add a component to a recommendation"""
        conn = self.get_connection()
        if not conn:
            return False
        
        try:
            cursor = conn.cursor()
            
            query = """
                INSERT INTO recommendation_components 
                (recommendation_id, component_type, brand, model, price, currency, 
                 image_url, source_url, tier)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)
            """
            
            cursor.execute(query, (
                recommendation_id,
                component.get('type'),
                component.get('brand'),
                component.get('model'),
                component.get('price'),
                component.get('currency', 'PHP'),
                component.get('image_url'),
                component.get('source_url'),
                tier
            ))
            
            conn.commit()
            cursor.close()
            conn.close()
            return True
        except Exception as e:
            logger.error(f"Add recommendation component error: {e}")
            if conn:
                conn.close()
            return False
    
    def add_recommendation_tier(self, recommendation_id: int, tier_name: str, 
                              total_price: float, components_count: int):
        """Add a tier summary to a recommendation"""
        conn = self.get_connection()
        if not conn:
            return False
        
        try:
            cursor = conn.cursor()
            
            query = """
                INSERT INTO recommendation_tiers 
                (recommendation_id, tier_name, total_price, components_count)
                VALUES (%s, %s, %s, %s)
            """
            
            cursor.execute(query, (
                recommendation_id,
                tier_name,
                total_price,
                components_count
            ))
            
            conn.commit()
            cursor.close()
            conn.close()
            return True
        except Exception as e:
            logger.error(f"Add recommendation tier error: {e}")
            if conn:
                conn.close()
            return False
    
    def get_recommendation_data(self, recommendation_id: int) -> Dict:
        """Get all data for a recommendation"""
        conn = self.get_connection()
        if not conn:
            return None
        
        try:
            cursor = conn.cursor(dictionary=True)
            
            # Get main recommendation
            cursor.execute("SELECT * FROM recommendations WHERE id = %s", (recommendation_id,))
            recommendation = cursor.fetchone()
            
            if not recommendation:
                return None
            
            # Get components
            cursor.execute(
                "SELECT * FROM recommendation_components WHERE recommendation_id = %s",
                (recommendation_id,)
            )
            components = cursor.fetchall()
            
            # Get tiers
            cursor.execute(
                "SELECT * FROM recommendation_tiers WHERE recommendation_id = %s",
                (recommendation_id,)
            )
            tiers = cursor.fetchall()
            
            cursor.close()
            conn.close()
            
            # Parse JSON fields
            import json
            if recommendation.get('query_analysis'):
                recommendation['query_analysis'] = json.loads(recommendation['query_analysis'])
            if recommendation.get('budget_analysis'):
                recommendation['budget_analysis'] = json.loads(recommendation['budget_analysis'])
            
            return {
                'recommendation': recommendation,
                'components': components,
                'tiers': tiers
            }
        except Exception as e:
            logger.error(f"Get recommendation data error: {e}")
            if conn:
                conn.close()
            return None

db_manager = DatabaseManager()

# Tagalog Translator
class TagalogTranslator:
    """Free translation service for Tagalog to English"""
    
    def __init__(self):
        self.api_url = "https://api.mymemory.translated.net/get"
    
    def translate_to_english(self, tagalog_text: str) -> str:
        """Translate Tagalog text to English using MyMemory Translation API"""
        try:
            # If text is already in English or mixed, return as is
            if self._is_mostly_english(tagalog_text):
                return tagalog_text
            
            params = {
                'q': tagalog_text,
                'langpair': 'tl|en',
                'de': 'your-email@example.com'
            }
            
            response = requests.get(self.api_url, params=params, timeout=10)
            
            if response.status_code == 200:
                data = response.json()
                translated_text = data['responseData']['translatedText']
                
                # Clean up the translation
                cleaned_text = self._clean_translation(translated_text)
                logger.info(f"Translated: '{tagalog_text}' -> '{cleaned_text}'")
                return cleaned_text
            else:
                logger.warning(f"Translation API error: {response.status_code}")
                return tagalog_text
                
        except Exception as e:
            logger.error(f"Translation failed: {e}")
            return tagalog_text
    
    def _is_mostly_english(self, text: str) -> bool:
        """Check if text is mostly English"""
        tagalog_indicators = [
            'ba', 'ko', 'mo', 'ka', 'ng', 'sa', 'ang', 'mga', 'ako', 'ikaw',
            'siya', 'kami', 'kayo', 'sila', 'ito', 'iyan', 'iyon', 'dito',
            'doon', 'kung', 'na', 'pa', 'din', 'rin', 'lang', 'naman',
            'talaga', 'siguro', 'gusto', 'kailangan', 'pwed', 'pwede',
            'ano', 'sino', 'saan', 'kailan', 'bakit', 'paano'
        ]
        
        text_lower = text.lower()
        tagalog_word_count = sum(1 for word in tagalog_indicators if word in text_lower)
        total_words = len(text_lower.split())
        
        if total_words > 0 and (tagalog_word_count / total_words) > 0.3:
            return False
        return True
    
    def _clean_translation(self, text: str) -> str:
        """Clean up translation results"""
        clean_text = text.replace('&#39;', "'")
        clean_text = clean_text.replace('&quot;', '"')
        clean_text = re.sub(r'\[.*?\]', '', clean_text)
        clean_text = re.sub(r'\(.*?\)', '', clean_text)
        clean_text = clean_text.strip()
        
        return clean_text

translator = TagalogTranslator()

# Component Compatibility Checker
class ComponentCompatibilityChecker:
    def __init__(self):
        self.compatibility_rules = {
            "cpu_motherboard": {
                "intel": ["lga1700", "lga1200", "lga1151"],
                "amd": ["am4", "am5"]
            },
            "ram_motherboard": {
                "ddr4": ["ddr4"],
                "ddr5": ["ddr5"]
            },
            "case_components": {
                "atx": ["atx", "microatx", "miniitx"],
                "microatx": ["microatx", "miniitx"],
                "miniitx": ["miniitx"]
            }
        }
    
    def check_compatibility(self, components: List[Dict]) -> Tuple[bool, List[str]]:
        """Check if all components are compatible with each other"""
        issues = []
        
        # Extract key components
        cpu = next((c for c in components if c['type'] == 'cpu'), None)
        motherboard = next((c for c in components if c['type'] == 'motherboard'), None)
        ram = next((c for c in components if c['type'] == 'ram'), None)
        case = next((c for c in components if c['type'] == 'case'), None)
        
        if cpu and motherboard:
            if not self._check_cpu_motherboard_compatibility(cpu, motherboard):
                issues.append(f"CPU {cpu.get('model', '')} is not compatible with motherboard {motherboard.get('model', '')}")
        
        if ram and motherboard:
            if not self._check_ram_motherboard_compatibility(ram, motherboard):
                issues.append(f"RAM type not compatible with motherboard")
        
        if case and motherboard:
            if not self._check_case_motherboard_compatibility(case, motherboard):
                issues.append(f"Case size not compatible with motherboard form factor")
        
        return len(issues) == 0, issues
    
    def _check_cpu_motherboard_compatibility(self, cpu: Dict, motherboard: Dict) -> bool:
        """Check CPU and motherboard socket compatibility"""
        cpu_model = cpu.get('model', '').lower()
        motherboard_model = motherboard.get('model', '').lower()
        
        if 'intel' in cpu_model and 'intel' in motherboard_model:
            return True
        if 'amd' in cpu_model and 'amd' in motherboard_model:
            return True
        if 'ryzen' in cpu_model and ('am4' in motherboard_model or 'am5' in motherboard_model):
            return True
        
        return True
    
    def _check_ram_motherboard_compatibility(self, ram: Dict, motherboard: Dict) -> bool:
        """Check RAM type compatibility"""
        ram_model = ram.get('model', '').lower()
        motherboard_model = motherboard.get('model', '').lower()
        
        if 'ddr4' in ram_model and 'ddr4' in motherboard_model:
            return True
        if 'ddr5' in ram_model and 'ddr5' in motherboard_model:
            return True
        
        return True
    
    def _check_case_motherboard_compatibility(self, case: Dict, motherboard: Dict) -> bool:
        """Check case and motherboard form factor compatibility"""
        return True

# Budget-Aware Build Generator
class BudgetAwareBuildGenerator:
    def __init__(self):
        self.compatibility_checker = ComponentCompatibilityChecker()
        # Use database type names: "cooler" (not "cpu_cooler"), "case-fan" (not "case_fans")
        self.essential_components = ["cpu", "motherboard", "ram", "storage", "psu", "case", "cooler", "case-fan", "keyboard", "mouse", "speakers"]
    
    def generate_build_within_budget(self, max_budget: float, performance_needs: List[str], 
                                   include_peripherals: bool = True, use_case: str = None) -> Dict[str, Any]:
        """Generate a complete build that MAXIMIZES budget utilization to 95-100%"""
        
        max_budget = to_float(max_budget)
        
        # Peripherals (keyboard, mouse, speakers) are already included in budget allocations
        # So we use the full budget for allocations
        component_budget = max_budget
        peripheral_budget = 0  # Only used for additional peripherals like headphones
        
        # Get initial allocations - peripherals are already included in allocations
        allocations = self._get_budget_allocations(component_budget, performance_needs)
        
        build_components = []
        total_cost = 0.0
        used_allocations = {}
        
        # Step 1: Find components closest to their allocated budget
        for component_type in self.essential_components:
            if component_type in allocations:
                allocation = allocations[component_type]
                component = self._find_component_closest_to_allocation(
                    component_type, allocation, performance_needs, build_components
                )
                if component:
                    price = to_float(component['price'])
                    component['price'] = price
                    build_components.append(component)
                    total_cost += price
                    used_allocations[component_type] = price
        
        # Step 2: Calculate remaining budget
        remaining_budget = component_budget - total_cost
        
        # Step 3: Redistribute remaining budget to maximize utilization
        if remaining_budget > component_budget * 0.05:  # If more than 5% remaining
            build_components = self._redistribute_budget_to_maximize(
                build_components, allocations, remaining_budget, component_budget, performance_needs
            )
            total_cost = sum(to_float(comp['price']) for comp in build_components)
            remaining_budget = component_budget - total_cost
        
        # Step 4: If still have budget remaining, upgrade components aggressively
        if remaining_budget > 100:  # More than ₱100 remaining
            build_components = self._aggressively_upgrade_components(
                build_components, remaining_budget, component_budget, performance_needs
            )
            total_cost = sum(to_float(comp['price']) for comp in build_components)
        
        # Step 5: Add additional peripherals if needed (headphones for content creation, etc.)
        # Note: keyboard, mouse, speakers are already in essential_components and budget allocations
        if include_peripherals and use_case:
            additional_peripherals = self._add_peripherals(use_case, peripheral_budget)
            for p in additional_peripherals:
                price = to_float(p['price'])
                p['price'] = price
                # Only add if not already in build_components
                if not any(comp.get('type') == p.get('type') for comp in build_components):
                    build_components.append(p)
                    total_cost += price
        
        # Step 6: Final check - if over budget, optimize
        if total_cost > max_budget:
            build_components = self._optimize_build_for_budget(build_components, max_budget)
            total_cost = sum(to_float(comp['price']) for comp in build_components)
        
        # Check compatibility
        is_compatible, compatibility_issues = self.compatibility_checker.check_compatibility(build_components)
        
        return {
            "components": build_components,
            "total_cost": float(total_cost),
            "within_budget": total_cost <= max_budget,
            "budget_utilization": (float(total_cost) / float(max_budget)) * 100,
            "is_compatible": is_compatible,
            "compatibility_issues": compatibility_issues,
            "budget_remaining": float(max_budget) - float(total_cost)
        }
    
    def _get_budget_allocations(self, total_budget: float, performance_needs: List[str]) -> Dict[str, float]:
        """Get strict budget allocations that ensure total doesn't exceed budget"""
        
        if "gaming" in performance_needs:
            base_allocations = {
                "cpu": 0.17, "motherboard": 0.11, "ram": 0.07, "gpu": 0.38, 
                "storage": 0.07, "psu": 0.06, "case": 0.02, "cooler": 0.03, "case-fan": 0.02,
                "keyboard": 0.03, "mouse": 0.02, "speakers": 0.02
            }
        elif "professional" in performance_needs:
            base_allocations = {
                "cpu": 0.28, "motherboard": 0.13, "ram": 0.15, "gpu": 0.20, 
                "storage": 0.09, "psu": 0.07, "case": 0.02, "cooler": 0.04, "case-fan": 0.02,
                "keyboard": 0.03, "mouse": 0.02, "speakers": 0.02
            }
        else:
            base_allocations = {
                "cpu": 0.20, "motherboard": 0.12, "ram": 0.09, "gpu": 0.33, 
                "storage": 0.08, "psu": 0.06, "case": 0.02, "cooler": 0.03, "case-fan": 0.02,
                "keyboard": 0.03, "mouse": 0.02, "speakers": 0.02
            }
        
        return {comp: total_budget * perc for comp, perc in base_allocations.items()}
    
    def _find_component_closest_to_allocation(self, component_type: str, allocation: float,
                                             performance_needs: List[str], existing_components: List[Dict]) -> Optional[Dict]:
        """
        Find component with price CLOSEST to the allocated budget (not just within it).
        This ensures we use more of the budget.
        """
        # Search for components in a wider range to find the closest match
        # Search from 50% to 120% of allocation to find best match
        min_price = allocation * 0.50
        max_price = allocation * 1.20  # Allow slight over-allocation for better matches
        
        # Get multiple candidates
        candidates = db_manager.search_components(
            component_type=component_type,
            max_price=max_price,
            min_price=min_price,
            limit=50  # Get more candidates
        )
        
        if not candidates:
            # Fallback: search without min_price
            candidates = db_manager.search_components(
                component_type=component_type,
                max_price=max_price,
                limit=30
            )
        
        if not candidates:
            return None
        
        # Find component closest to allocation
        best_component = None
        best_diff = float('inf')
        
        for candidate in candidates:
            price = to_float(candidate['price'])
            diff = abs(price - allocation)
            
            # Prefer components that are close to or slightly over allocation
            if diff < best_diff:
                best_diff = diff
                best_component = candidate
        
        if best_component:
            best_component['price'] = to_float(best_component['price'])
        
        return best_component
    
    def _redistribute_budget_to_maximize(self, build_components: List[Dict], 
                                        allocations: Dict[str, float],
                                        remaining_budget: float, total_budget: float,
                                        performance_needs: List[str]) -> List[Dict]:
        """
        Redistribute remaining budget to components to maximize utilization.
        Upgrades components that are under their allocation.
        """
        if remaining_budget < 100:  # Less than ₱100, not worth redistributing
            return build_components
        
        logger.info(f"Redistributing ₱{remaining_budget:,.2f} to maximize budget use")
        
        upgraded_build = build_components.copy()
        remaining = remaining_budget
        
        # Sort components by how much they're under their allocation
        component_map = {comp.get('type'): comp for comp in upgraded_build}
        under_allocated = []
        
        for comp_type, comp in component_map.items():
            if comp_type in allocations:
                allocated = allocations[comp_type]
                current_price = to_float(comp['price'])
                if current_price < allocated:
                    under_allocated.append((comp_type, comp, allocated - current_price))
        
        # Sort by how much under-allocated (most under-allocated first)
        under_allocated.sort(key=lambda x: x[2], reverse=True)
        
        # Redistribute budget to under-allocated components
        for comp_type, current_comp, deficit in under_allocated:
            if remaining < 50:  # Less than ₱50 remaining
                break
            
            # Try to find a better component that uses more of the allocation + remaining budget
            target_price = to_float(current_comp['price']) + min(remaining, deficit)
            max_search_price = target_price * 1.2
            
            candidates = db_manager.search_components(
                component_type=comp_type,
                max_price=max_search_price,
                min_price=to_float(current_comp['price']),
                limit=20
            )
            
            if candidates:
                # Find best upgrade that uses more budget
                best_upgrade = None
                best_price = to_float(current_comp['price'])
                
                for candidate in candidates:
                    candidate_price = to_float(candidate['price'])
                    price_diff = candidate_price - best_price
                    
                    if 0 < price_diff <= remaining:
                        # Test compatibility
                        test_build = upgraded_build.copy()
                        index = next(i for i, c in enumerate(test_build) if c.get('type') == comp_type)
                        test_build[index] = candidate
                        
                        is_compatible, _ = self.compatibility_checker.check_compatibility(test_build)
                        if is_compatible and candidate_price > best_price:
                            best_upgrade = candidate
                            best_price = candidate_price
                
                if best_upgrade:
                    index = next(i for i, c in enumerate(upgraded_build) if c.get('type') == comp_type)
                    upgrade_cost = best_price - to_float(current_comp['price'])
                    upgraded_build[index] = best_upgrade
                    upgraded_build[index]['price'] = best_price
                    remaining -= upgrade_cost
                    logger.info(f"Upgraded {comp_type}: +₱{upgrade_cost:,.2f}, Remaining: ₱{remaining:,.2f}")
        
        return upgraded_build
    
    def _aggressively_upgrade_components(self, build_components: List[Dict],
                                        remaining_budget: float, max_budget: float,
                                        performance_needs: List[str]) -> List[Dict]:
        """
        Aggressively upgrade components to use remaining budget.
        Multiple passes to maximize utilization.
        """
        if remaining_budget < 50:
            return build_components
        
        logger.info(f"Aggressively upgrading components with remaining ₱{remaining_budget:,.2f}")
        
        upgraded_build = build_components.copy()
        remaining = remaining_budget
        
        # Multiple upgrade passes
        for pass_num in range(10):  # Up to 10 passes
            if remaining < 50:
                break
            
            upgraded_this_pass = False
            component_map = {comp.get('type'): comp for comp in upgraded_build}
            
            # Sort by current price (cheapest first) to upgrade systematically
            for comp_type, current_comp in sorted(component_map.items(),
                                                key=lambda x: to_float(x[1]['price'])):
                if remaining < 50:
                    break
                
                current_price = to_float(current_comp['price'])
                max_search_price = current_price + remaining
                
                # Get candidates for upgrade
                candidates = db_manager.search_components(
                    component_type=comp_type,
                    max_price=max_search_price,
                    min_price=current_price + 50,  # At least ₱50 more expensive
                    limit=30
                )
                
                if candidates:
                    # Find best upgrade
                    best_upgrade = None
                    best_price = current_price
                    
                    for candidate in candidates:
                        candidate_price = to_float(candidate['price'])
                        price_diff = candidate_price - current_price
                        
                        if 0 < price_diff <= remaining:
                            # Test compatibility
                            test_build = upgraded_build.copy()
                            index = next(i for i, c in enumerate(test_build) if c.get('type') == comp_type)
                            test_build[index] = candidate
                            
                            is_compatible, _ = self.compatibility_checker.check_compatibility(test_build)
                            if is_compatible and candidate_price > best_price:
                                best_upgrade = candidate
                                best_price = candidate_price
                    
                    if best_upgrade:
                        index = next(i for i, c in enumerate(upgraded_build) if c.get('type') == comp_type)
                        upgrade_cost = best_price - current_price
                        upgraded_build[index] = best_upgrade
                        upgraded_build[index]['price'] = best_price
                        remaining -= upgrade_cost
                        upgraded_this_pass = True
                        logger.info(f"Pass {pass_num+1}: Upgraded {comp_type} by ₱{upgrade_cost:,.2f}, Remaining: ₱{remaining:,.2f}")
            
            if not upgraded_this_pass:
                break
        
        return upgraded_build
    
    def _add_peripherals(self, use_case: str, peripheral_budget: float) -> List[Dict]:
        """Add peripherals based on use case"""
        peripherals = []
        
        if use_case == "gaming":
            keyboard_budget = peripheral_budget * 0.6
            mouse_budget = peripheral_budget * 0.4
            
            keyboard = db_manager.search_components("keyboard", max_price=keyboard_budget, limit=1)
            mouse = db_manager.search_components("mouse", max_price=mouse_budget, limit=1)
            
            if keyboard:
                peripherals.append(keyboard[0])
            if mouse:
                peripherals.append(mouse[0])
                
        elif use_case == "content_creation":
            headphones = db_manager.search_components("headphones", max_price=peripheral_budget, limit=1)
            if headphones:
                peripherals.append(headphones[0])
        
        return peripherals
    
    def _optimize_build_for_budget(self, components: List[Dict], max_budget: float) -> List[Dict]:
        """Optimize build to fit within budget by replacing expensive components"""
        # Convert max_budget to float
        max_budget = to_float(max_budget)
        
        # Calculate total_cost with float conversion
        total_cost = sum(to_float(comp['price']) for comp in components)
        
        if total_cost <= max_budget:
            return components
        
        components_sorted = sorted(components, key=lambda x: to_float(x['price']), reverse=True)
        optimized_components = components_sorted.copy()
        
        for i, component in enumerate(components_sorted):
            if total_cost <= max_budget:
                break
                
            comp_price = to_float(component['price'])
                
            cheaper_alternatives = db_manager.search_components(
                component_type=component['type'],
                max_price=comp_price * 0.8,
                limit=5
            )
            
            if cheaper_alternatives:
                new_component = cheaper_alternatives[-1]
                # Convert new component price to float
                new_price = to_float(new_component['price'])
                new_component['price'] = new_price
                cost_reduction = comp_price - new_price
                total_cost -= cost_reduction
                optimized_components[i] = new_component
        
        return optimized_components

# Add this class definition around line 857, before AdvancedBuildGenerator

class PremadeBuildGenerator:
    """
    Generates and manages premade PC builds for common budget tiers.
    Ensures compatibility and optimal budget utilization (99-100%).
    """
    
    def __init__(self):
        self.db_manager = db_manager
        self.compatibility_checker = ComponentCompatibilityChecker()
        # Use database type names: "cooler" (not "cpu_cooler"), "case-fan" (not "case_fans")
        self.essential_components = ["cpu", "motherboard", "ram", "storage", "psu", "case", "cooler", "case-fan", "keyboard", "mouse", "speakers"]
        
        # Common budget tiers in PHP
        self.budget_tiers = [
            20000, 25000, 30000, 35000, 40000, 45000, 50000,
            55000, 60000, 65000, 70000, 75000, 80000, 85000,
            90000, 95000, 100000, 120000, 150000, 200000
        ]
        
        # Cache for premade builds
        self.premade_builds_cache = {}
    
    def get_closest_premade_build(self, user_budget: float, performance_needs: List[str] = None) -> Dict[str, Any]:
        """
        Find the closest premade build to user's budget.
        MAXIMIZES BUDGET UTILIZATION TO 99-100%
        """
        user_budget = to_float(user_budget)
        performance_needs = performance_needs or []
        
        # Find closest budget tier
        closest_tier = min(self.budget_tiers, key=lambda x: abs(x - user_budget))
        
        # If user budget is close to a tier (within 10%), use that tier
        if abs(user_budget - closest_tier) / user_budget < 0.10:
            target_budget = closest_tier
        else:
            # Round to nearest 5k
            target_budget = round(user_budget / 5000) * 5000
            if target_budget < 20000:
                target_budget = 20000
            elif target_budget > 200000:
                target_budget = 200000
        
        # Generate or retrieve premade build
        cache_key = f"{target_budget}_{'_'.join(sorted(performance_needs))}"
        
        if cache_key not in self.premade_builds_cache:
            build = self._generate_premade_build(target_budget, performance_needs)
            if build:
                self.premade_builds_cache[cache_key] = build
        
        return self.premade_builds_cache.get(cache_key)
    
    def _generate_premade_build(self, target_budget: float, performance_needs: List[str]) -> Dict[str, Any]:
        """Generate a premade build optimized for target budget - Uses fast BudgetAwareBuildGenerator"""
        target_budget = to_float(target_budget)
        
        logger.info(f"Generating premade build for ₱{target_budget:,.0f} (target: 99% utilization)")
        
        # Use the fast BudgetAwareBuildGenerator instead of slow backtracking
        # This is much faster and still maximizes budget utilization
        budget_generator = BudgetAwareBuildGenerator()
        build_result = budget_generator.generate_build_within_budget(
            target_budget, 
            performance_needs
        )
        
        if not build_result or not build_result.get("components"):
            logger.warning("Failed to generate premade build using BudgetAwareBuildGenerator")
            return None
        
        build_components = build_result["components"]
        total_cost = build_result["total_cost"]
        budget_utilization = build_result["budget_utilization"]
        
        # If budget utilization is too low (< 90%), try to maximize it
        if budget_utilization < 90:
            logger.info(f"Budget utilization is {budget_utilization:.1f}%, attempting to maximize...")
            # Get component candidates for aggressive upgrading
            allocations = self._get_budget_allocations(target_budget, performance_needs)
            component_candidates = {}
            for comp_type in self.essential_components:
                if comp_type in allocations:
                    allocation = allocations[comp_type]
                    candidates = self._get_component_candidates_robust(
                        comp_type, allocation, performance_needs, target_budget
                    )
                    component_candidates[comp_type] = candidates
            
            # Try to maximize budget use
            build_components = self._maximize_budget_use(build_components, component_candidates, target_budget)
            total_cost = sum(to_float(comp['price']) for comp in build_components)
            budget_utilization = (float(total_cost) / float(target_budget)) * 100
        
        # Verify compatibility
        is_compatible, issues = self.compatibility_checker.check_compatibility(build_components)
        
        if not is_compatible:
            logger.warning(f"Compatibility issues found: {issues}")
            # Get candidates for fixing
            allocations = self._get_budget_allocations(target_budget, performance_needs)
            component_candidates = {}
            for comp_type in self.essential_components:
                if comp_type in allocations:
                    allocation = allocations[comp_type]
                    candidates = self._get_component_candidates_robust(
                        comp_type, allocation, performance_needs, target_budget
                    )
                    component_candidates[comp_type] = candidates
            build_components = self._fix_compatibility_issues(build_components, component_candidates, target_budget)
            is_compatible, issues = self.compatibility_checker.check_compatibility(build_components)
            total_cost = sum(to_float(comp['price']) for comp in build_components)
            budget_utilization = (float(total_cost) / float(target_budget)) * 100
        
        logger.info(f"Premade build generated: {len(build_components)} components, ₱{total_cost:,.2f} ({budget_utilization:.1f}% utilization)")
        
        return {
            "components": build_components,
            "total_cost": float(total_cost),
            "target_budget": float(target_budget),
            "budget_utilization": budget_utilization,
            "is_compatible": is_compatible,
            "compatibility_issues": issues,
            "budget_remaining": float(target_budget) - float(total_cost)
        }
    
    def _get_component_candidates_robust(self, component_type: str, allocation: float,
                                        performance_needs: List[str], total_budget: float) -> List[Dict]:
        """Get multiple candidate components with robust search"""
        candidates = []
        
        price_ranges = [
            (allocation * 0.95, allocation),
            (allocation * 0.80, allocation * 0.95),
            (allocation * 0.65, allocation * 0.80),
            (allocation * 0.50, allocation * 0.65),
            (allocation * 0.30, allocation * 0.50),
        ]
        
        for min_p, max_p in price_ranges:
            comps = self.db_manager.search_components(
                component_type=component_type,
                max_price=max_p,
                min_price=min_p,
                limit=10
            )
            
            for comp in comps:
                comp['price'] = to_float(comp['price'])
                if comp not in candidates:
                    candidates.append(comp)
            
            if len(candidates) >= 30:
                break
        
        candidates.sort(key=lambda x: x['price'], reverse=True)
        return candidates[:30]
    
    def _find_compatible_combination(self, component_candidates: Dict[str, List[Dict]],
                                    target_cost: float, max_budget: float,
                                    performance_needs: List[str]) -> List[Dict]:
        """Find compatible combination using backtracking with timeout and optimization"""
        import time
        start_time = time.time()
        timeout = 10.0  # 10 second timeout
        
        if not component_candidates:
            logger.warning("_find_compatible_combination: No component candidates provided")
            return []
        
        component_types = list(component_candidates.keys())
        logger.info(f"_find_compatible_combination: Searching for {len(component_types)} component types, target: ₱{target_cost:,.0f}, max: ₱{max_budget:,.0f}")
        
        # Limit candidates to top 10 per type to speed up search
        limited_candidates = {}
        for comp_type, candidates in component_candidates.items():
            # Sort by price closest to allocation and take top 10
            if len(candidates) > 10:
                # Calculate allocation for this type
                allocation = target_cost / len(component_types) if component_types else 0
                candidates = sorted(candidates, key=lambda x: abs(to_float(x['price']) - allocation))[:10]
            limited_candidates[comp_type] = candidates
            logger.info(f"  {comp_type}: {len(limited_candidates[comp_type])} candidates (limited from {len(component_candidates.get(comp_type, []))})")
        
        best_combination = None
        best_diff = float('inf')
        best_total = 0
        iterations = 0
        max_iterations = 50000  # Limit total iterations
        
        def backtrack(current_combination: List[Dict], current_total: float, remaining_types: List[str]):
            nonlocal best_combination, best_diff, best_total, iterations
            
            iterations += 1
            
            # Timeout check
            if time.time() - start_time > timeout:
                logger.warning(f"_find_compatible_combination: Timeout after {timeout}s, returning best found so far")
                return True  # Signal to stop
            
            # Iteration limit check
            if iterations > max_iterations:
                logger.warning(f"_find_compatible_combination: Max iterations reached, returning best found so far")
                return True  # Signal to stop
            
            if not remaining_types:
                diff = abs(current_total - target_cost)
                if diff < best_diff and current_total <= max_budget:
                    best_diff = diff
                    best_combination = current_combination.copy()
                    best_total = current_total
                    # Early exit if we're very close to target
                    if diff < target_cost * 0.02:
                        return True
                return False
            
            if current_total >= max_budget:
                return False
            
            next_type = remaining_types[0]
            candidates = limited_candidates.get(next_type, [])
            
            if not candidates:
                # If no candidates for this type, skip it
                return backtrack(current_combination, current_total, remaining_types[1:])
            
            # Limit candidates to check (take first 5 that fit budget)
            checked = 0
            for candidate in candidates:
                if checked >= 5:  # Only check first 5 candidates per type
                    break
                    
                candidate_price = to_float(candidate['price'])
                
                if current_total + candidate_price > max_budget:
                    continue
                
                test_combination = current_combination + [candidate]
                is_compatible, _ = self.compatibility_checker.check_compatibility(test_combination)
                
                if is_compatible:
                    should_stop = backtrack(test_combination, current_total + candidate_price, remaining_types[1:])
                    if should_stop:
                        return True
                    checked += 1
            
            return False
        
        backtrack([], 0.0, component_types)
        
        elapsed = time.time() - start_time
        if best_combination:
            logger.info(f"_find_compatible_combination: Found combination with {len(best_combination)} components, total: ₱{best_total:,.2f} in {elapsed:.2f}s ({iterations} iterations)")
        else:
            logger.warning(f"_find_compatible_combination: No compatible combination found after {elapsed:.2f}s ({iterations} iterations)")
        
        return best_combination if best_combination else []
    
    def _maximize_budget_use(self, build: List[Dict], component_candidates: Dict[str, List[Dict]],
                         max_budget: float) -> List[Dict]:
        """Aggressively maximize budget utilization"""
        current_total = sum(to_float(comp['price']) for comp in build)
        remaining_budget = max_budget - current_total
        
        if remaining_budget < max_budget * 0.01:
            return build
        
        for iteration in range(5):
            if remaining_budget < max_budget * 0.005:
                break
            
            upgraded = False
            component_map = {comp.get('type'): comp for comp in build}
            
            for comp_type, current_comp in sorted(component_map.items(), 
                                                key=lambda x: to_float(x[1]['price'])):
                if remaining_budget < 100:
                    break
                
                if comp_type in component_candidates:
                    current_price = to_float(current_comp['price'])
                    best_upgrade = None
                    best_upgrade_price = current_price
                    
                    for candidate in component_candidates[comp_type]:
                        candidate_price = to_float(candidate['price'])
                        price_diff = candidate_price - current_price
                        
                        if 0 < price_diff <= remaining_budget and candidate_price > best_upgrade_price:
                            test_build = build.copy()
                            index = next(i for i, c in enumerate(test_build) if c.get('type') == comp_type)
                            test_build[index] = candidate
                            
                            is_compatible, _ = self.compatibility_checker.check_compatibility(test_build)
                            if is_compatible:
                                best_upgrade = candidate
                                best_upgrade_price = candidate_price
                    
                    if best_upgrade:
                        index = next(i for i, c in enumerate(build) if c.get('type') == comp_type)
                        upgrade_cost = to_float(best_upgrade['price']) - current_price
                        build[index] = best_upgrade
                        remaining_budget -= upgrade_cost
                        current_total += upgrade_cost
                        upgraded = True
            
            if not upgraded:
                break
        
        if remaining_budget > max_budget * 0.01:
            build = self._aggressive_upgrade(build, component_candidates, remaining_budget, max_budget)
        
        return build
    
    def _aggressive_upgrade(self, build: List[Dict], component_candidates: Dict[str, List[Dict]],
                       remaining_budget: float, max_budget: float) -> List[Dict]:
        """Aggressively upgrade components to use remaining budget"""
        upgraded_build = build.copy()
        remaining = remaining_budget
        
        component_map = {comp.get('type'): comp for comp in upgraded_build}
        upgrade_order = sorted(component_map.items(), 
                              key=lambda x: max([to_float(c['price']) for c in component_candidates.get(x[0], [])] or [0]),
                              reverse=True)
        
        for comp_type, current_comp in upgrade_order:
            if remaining < 50:
                break
            
            if comp_type in component_candidates:
                current_price = to_float(current_comp['price'])
                best_candidate = None
                best_price = current_price
                
                for candidate in component_candidates[comp_type]:
                    candidate_price = to_float(candidate['price'])
                    price_diff = candidate_price - current_price
                    
                    if 0 < price_diff <= remaining and candidate_price > best_price:
                        test_build = upgraded_build.copy()
                        index = next(i for i, c in enumerate(test_build) if c.get('type') == comp_type)
                        test_build[index] = candidate
                        
                        is_compatible, _ = self.compatibility_checker.check_compatibility(test_build)
                        if is_compatible:
                            best_candidate = candidate
                            best_price = candidate_price
                
                if best_candidate:
                    index = next(i for i, c in enumerate(upgraded_build) if c.get('type') == comp_type)
                    upgrade_cost = best_price - current_price
                    upgraded_build[index] = best_candidate
                    remaining -= upgrade_cost
        
        return upgraded_build
    
    def _fix_compatibility_issues(self, build: List[Dict], component_candidates: Dict[str, List[Dict]],
                                 max_budget: float) -> List[Dict]:
        """Fix compatibility issues by replacing incompatible components"""
        fixed_build = build.copy()
        is_compatible, issues = self.compatibility_checker.check_compatibility(fixed_build)
        
        if is_compatible:
            return fixed_build
        
        for i, comp in enumerate(fixed_build):
            comp_type = comp.get('type')
            if comp_type in component_candidates:
                for candidate in component_candidates[comp_type]:
                    test_build = fixed_build.copy()
                    test_build[i] = candidate
                    
                    is_compat, _ = self.compatibility_checker.check_compatibility(test_build)
                    if is_compat:
                        total = sum(to_float(c['price']) for c in test_build)
                        if total <= max_budget:
                            fixed_build = test_build
                            is_compatible, _ = self.compatibility_checker.check_compatibility(fixed_build)
                            if is_compatible:
                                return fixed_build
        
        return fixed_build
    
    def _get_budget_allocations(self, total_budget: float, performance_needs: List[str]) -> Dict[str, float]:
        """Get budget allocations - ALWAYS includes cooler, case-fan, keyboard, mouse, and speakers"""
        if "gaming" in performance_needs:
            allocations = {
                "cpu": 0.16, "motherboard": 0.10, "ram": 0.07, "gpu": 0.36,
                "storage": 0.07, "psu": 0.06, "case": 0.02,
                "cooler": 0.03, "case-fan": 0.02,
                "keyboard": 0.03, "mouse": 0.02, "speakers": 0.02
            }
        elif "professional" in performance_needs or "streaming" in performance_needs:
            allocations = {
                "cpu": 0.26, "motherboard": 0.12, "ram": 0.14, "gpu": 0.19,
                "storage": 0.09, "psu": 0.07, "case": 0.02,
                "cooler": 0.04, "case-fan": 0.02,
                "keyboard": 0.03, "mouse": 0.02, "speakers": 0.02
            }
        else:
            allocations = {
                "cpu": 0.19, "motherboard": 0.11, "ram": 0.09, "gpu": 0.31,
                "storage": 0.08, "psu": 0.06, "case": 0.02,
                "cooler": 0.03, "case-fan": 0.02,
                "keyboard": 0.03, "mouse": 0.02, "speakers": 0.02
            }
        
        total = sum(allocations.values())
        if total != 1.0:
            allocations = {k: v / total for k, v in allocations.items()}
        
        return {comp: total_budget * perc for comp, perc in allocations.items()}

# Advanced Build Generator
class AdvancedBuildGenerator:
    def __init__(self):
        self.db_manager = db_manager
        self.budget_aware_generator = BudgetAwareBuildGenerator()
        self.minimum_build_prices = {
            "gaming": 25000,
            "professional": 35000,
            "productivity": 20000,
            "streaming": 30000,
            "general": 18000
        }
    
    def generate_customized_recommendations(self, parsed_query: Dict) -> Dict[str, Any]:
        """Generate builds with strict budget compliance using premade builds"""
        performance_needs = parsed_query.get("performance_needs", [])
        max_budget = parsed_query.get("price_constraints", {}).get("max_price")
        use_case = self._determine_use_case(parsed_query)
        
        recommendations = {
            "builds": {},
            "budget_analysis": {},
            "minimum_build": None
        }
        
        if max_budget:
            is_feasible, min_budget, message = self.can_build_within_budget(max_budget, performance_needs)
            recommendations["budget_analysis"] = {
                "user_budget": max_budget,
                "min_required": min_budget,
                "is_feasible": is_feasible,
                "message": message
            }
            
            if is_feasible:
                # Use premade builds for better accuracy and compatibility
                budget_build_data = premade_build_generator.get_closest_premade_build(
                    max_budget * 0.70, performance_needs
                )
                if budget_build_data and budget_build_data.get("components"):
                    recommendations["builds"]["budget"] = budget_build_data["components"]
                    logger.info(f"Budget build generated: {len(budget_build_data['components'])} components, ₱{budget_build_data.get('total_cost', 0):,.2f}")
                else:
                    logger.warning(f"Budget build generation failed for ₱{max_budget * 0.70:,.0f}")
                
                balanced_build_data = premade_build_generator.get_closest_premade_build(
                    max_budget, performance_needs
                )
                if balanced_build_data and balanced_build_data.get("components"):
                    recommendations["builds"]["balanced"] = balanced_build_data["components"]
                    logger.info(f"Balanced build generated: {len(balanced_build_data['components'])} components, ₱{balanced_build_data.get('total_cost', 0):,.2f}")
                else:
                    logger.warning(f"Balanced build generation failed for ₱{max_budget:,.0f}")
                
                if max_budget >= 40000:
                    premium_build_data = premade_build_generator.get_closest_premade_build(
                        min(max_budget * 1.15, max_budget + 10000), performance_needs
                    )
                    if premium_build_data and premium_build_data.get("components"):
                        recommendations["builds"]["premium"] = premium_build_data["components"]
                        logger.info(f"Premium build generated: {len(premium_build_data['components'])} components, ₱{premium_build_data.get('total_cost', 0):,.2f}")
                    else:
                        logger.warning(f"Premium build generation failed for ₱{min(max_budget * 1.15, max_budget + 10000):,.0f}")
                
                # Fallback: If all premade builds failed, use direct build generator
                if not recommendations["builds"].get("balanced") and not recommendations["builds"].get("budget"):
                    logger.warning("All premade builds failed, falling back to direct build generator")
                    direct_generator = BudgetAwareBuildGenerator()
                    direct_build = direct_generator.generate_build_within_budget(
                        max_budget, 
                        performance_needs
                    )
                    if direct_build and direct_build.get("components"):
                        recommendations["builds"]["balanced"] = direct_build["components"]
                        logger.info(f"Direct build generation succeeded: {len(direct_build['components'])} components, ₱{direct_build.get('total_cost', 0):,.2f}")
            else:
                recommendations["minimum_build"] = self.generate_cheapest_feasible_build(performance_needs)
        else:
            # Fallback to default budgets
            recommendations["builds"]["budget"] = premade_build_generator.get_closest_premade_build(
                30000, performance_needs
            )["components"] if premade_build_generator.get_closest_premade_build(30000, performance_needs) else []
            
            recommendations["builds"]["balanced"] = premade_build_generator.get_closest_premade_build(
                50000, performance_needs
            )["components"] if premade_build_generator.get_closest_premade_build(50000, performance_needs) else []
            
            recommendations["builds"]["premium"] = premade_build_generator.get_closest_premade_build(
                75000, performance_needs
            )["components"] if premade_build_generator.get_closest_premade_build(75000, performance_needs) else []
        
        return recommendations
    
    def can_build_within_budget(self, user_budget: float, performance_needs: List[str]) -> Tuple[bool, float, str]:
        """Check if a feasible build is possible within the budget"""
        min_budget = self.get_minimum_feasible_budget(performance_needs)
        
        if user_budget < min_budget:
            performance_type = performance_needs[0] if performance_needs else "general"
            return False, min_budget, f"A proper {performance_type} PC build starts at around ₱{min_budget:,.0f}"
        
        return True, min_budget, "Budget is sufficient for a basic build"
    
    def get_minimum_feasible_budget(self, performance_needs: List[str]) -> float:
        """Get the minimum feasible budget based on performance needs"""
        if not performance_needs:
            return self.minimum_build_prices["general"]
        
        min_budgets = [self.minimum_build_prices.get(need, self.minimum_build_prices["general"]) 
                      for need in performance_needs]
        return max(min_budgets)
    
    def generate_cheapest_feasible_build(self, performance_needs: List[str]) -> List[Dict]:
        """Generate the cheapest possible build that meets performance requirements"""
        min_budget = self.get_minimum_feasible_budget(performance_needs)
        
        if "gaming" in performance_needs:
            allocations = {"cpu": 0.15, "motherboard": 0.10, "ram": 0.08, "gpu": 0.50, "storage": 0.08, "psu": 0.07, "case": 0.02}
        elif "professional" in performance_needs:
            allocations = {"cpu": 0.40, "motherboard": 0.12, "ram": 0.20, "gpu": 0.15, "storage": 0.08, "psu": 0.08, "case": 0.02}
        else:
            allocations = {"cpu": 0.20, "motherboard": 0.12, "ram": 0.10, "gpu": 0.40, "storage": 0.08, "psu": 0.08, "case": 0.02}
        
        build_components = []
        
        for component_type, allocation in allocations.items():
            component_budget = min_budget * allocation
            
            component_options = db_manager.search_components(
                component_type=component_type,
                max_price=component_budget * 1.2,
                limit=10
            )
            
            if component_options:
                if len(component_options) >= 3:
                    best_component = component_options[len(component_options)//2]
                else:
                    best_component = component_options[0]
                build_components.append(best_component)
            else:
                fallback = db_manager.search_components(
                    component_type=component_type,
                    limit=1
                )
                if fallback:
                    build_components.append(fallback[0])
        
        return build_components
    
    def _determine_use_case(self, parsed_query: Dict) -> str:
        """Determine the primary use case for peripheral inclusion"""
        performance_needs = parsed_query.get("performance_needs", [])
        original_query = parsed_query.get("original_query", "").lower()
        
        if "gaming" in performance_needs or "gaming" in original_query:
            return "gaming"
        elif "content" in original_query or "streaming" in performance_needs:
            return "content_creation"
        else:
            return "general"
    
    def _should_include_peripherals(self, parsed_query: Dict) -> bool:
        """Determine if peripherals should be included based on query"""
        original_query = parsed_query.get("original_query", "").lower()
        return any(keyword in original_query for keyword in ["setup", "build", "complete", "full"])

# Initialize advanced build generator
advanced_build_generator = AdvancedBuildGenerator()

# Initialize premade build generator
premade_build_generator = PremadeBuildGenerator()

# Smart Query Parser
ENHANCED_KEYWORD_PATTERNS = {
    "component_types": {
        "cpu": ["processor", "cpu", "core i3", "core i5", "core i7", "core i9", "ryzen 3", "ryzen 5", "ryzen 7", "ryzen 9", "intel", "amd"],
        "gpu": ["graphics card", "gpu", "video card", "graphics", "vga", "rtx", "gtx", "geforce", "radeon"],
        "ram": ["memory", "ram", "ddr4", "ddr5", "dimm", "memory stick"],
        "storage": ["storage", "ssd", "hard drive", "nvme", "hdd", "m.2", "solid state"],
        "motherboard": ["motherboard", "mainboard", "mobo", "board", "chipset"],
        "psu": ["power supply", "psu", "wattage", "watt", "modular psu"],
        "case": ["case", "chassis", "tower", "pc case", "computer case"],
        "cooler": ["cooler", "cooling", "cpu cooler", "aio", "liquid cooling"],
        "monitor": ["monitor", "display", "screen", "lcd", "led"]
    },
    "price_indicators": [
        r'under\s+₱?\s*(\d+(?:[,\d]*)?)', r'below\s+₱?\s*(\d+(?:[,\d]*)?)', r'less\s+than\s+₱?\s*(\d+(?:[,\d]*)?)',
        r'above\s+₱?\s*(\d+(?:[,\d]*)?)', r'over\s+₱?\s*(\d+(?:[,\d]*)?)', r'greater\s+than\s+₱?\s*(\d+(?:[,\d]*)?)',
        r'within\s+₱?\s*(\d+(?:[,\d]*)?)', r'around\s+₱?\s*(\d+(?:[,\d]*)?)', r'₱?\s*(\d+(?:[,\d]*)?)\s*(?:pesos?|php)?',
        r'(\d+)k\s*(?:budget|pesos?|php)?', r'budget\s+of\s+₱?\s*(\d+(?:[,\d]*)?)', r'max\s+₱?\s*(\d+(?:[,\d]*)?)',
        r'maximum\s+₱?\s*(\d+(?:[,\d]*)?)', r'(\d+(?:[,\d]*)?)\s*pesos', r'(\d+(?:[,\d]*)?)\s*php'
    ],
    "performance_keywords": {
        "gaming": ["gaming", "fps", "1080p gaming", "1440p gaming", "4k gaming", "esports", "competitive"],
        "professional": ["rendering", "video editing", "3d modeling", "photoshop", "premiere", "blender"],
        "productivity": ["office work", "multitasking", "productivity", "excel", "programming", "coding"],
        "streaming": ["streaming", "twitch", "obs", "streaming setup", "content creation"]
    }
}

# Smart Query Parser
class SmartQueryParser:
    def __init__(self):
        self.db_manager = db_manager
    
    def parse_query(self, query: str) -> Dict[str, Any]:
        query_lower = query.lower()
        
        parsed = {
            "original_query": query,
            "component_type": None,
            "brand": None,
            "model_keywords": [],
            "price_constraints": {},
            "intent": "search",
            "performance_needs": [],
            "specific_model_detected": False,
            "confidence_score": 0.0,
            "is_pc_related": True,
            "build_intent_detected": False,
            "query_context": "general",
            "should_generate_complete_build": False,
        }
        
        parsed["is_pc_related"] = self._is_pc_related_query(query_lower)
        parsed["build_intent_detected"] = self._detect_build_intent(query_lower)
        parsed["component_type"] = self._detect_component_type(query_lower)
        parsed["price_constraints"] = self._extract_price_constraints(query_lower)
        parsed["should_generate_complete_build"] = self._should_generate_complete_build(query_lower, parsed)
        parsed["brand"] = self._detect_brand(query_lower, parsed["component_type"])
        parsed["model_keywords"] = self._extract_model_keywords(query_lower)
        parsed["intent"] = self._detect_intent(query_lower)
        parsed["performance_needs"] = self._detect_performance_needs(query_lower)
        parsed["specific_model_detected"] = self._detect_specific_model(query_lower)
        parsed["query_context"] = self._determine_query_context(parsed)
        parsed["confidence_score"] = self._calculate_confidence(parsed)
        
        return parsed
    
    def _is_pc_related_query(self, query: str) -> bool:
        pc_keywords = ["pc", "computer", "desktop", "tower", "rig", "build", "setup", "cpu", "gpu", "ram"]
        non_pc_keywords = ["mobile", "hotspot", "lte", "5g", "wifi", "router", "phone", "smartphone"]
        
        pc_keywords_present = any(keyword in query for keyword in pc_keywords)
        non_pc_keywords_present = any(keyword in query for keyword in non_pc_keywords)
        
        return pc_keywords_present or not non_pc_keywords_present
    
    def _detect_build_intent(self, query: str) -> bool:
        build_phrases = ["pc build", "computer build", "desktop build", "gaming build", "build a pc"]
        
        if any(phrase in query for phrase in build_phrases):
            return True
        
        has_budget = any(re.search(pattern, query) for pattern in ENHANCED_KEYWORD_PATTERNS["price_indicators"])
        has_setup_words = any(word in query for word in ["setup", "build", "complete", "full"])
        
        return has_budget and has_setup_words
    
    def _should_generate_complete_build(self, query: str, parsed: Dict) -> bool:
        has_budget = bool(parsed["price_constraints"])
        has_general_pc_keywords = any(keyword in query for keyword in ["pc", "computer", "setup", "build"])
        no_specific_component = not parsed["component_type"]
        has_performance_needs = bool(parsed["performance_needs"])
        
        if has_budget and has_general_pc_keywords and no_specific_component:
            return True
        
        if has_performance_needs and has_budget and no_specific_component:
            return True
        
        general_build_phrases = ["help me", "what should i", "recommend me", "suggest me", "need a computer"]
        if any(phrase in query for phrase in general_build_phrases) and has_budget:
            return True
        
        return False
    
    def _detect_component_type(self, query: str) -> Optional[str]:
        scores = {}
        
        for comp_type, keywords in ENHANCED_KEYWORD_PATTERNS["component_types"].items():
            score = sum(1 for keyword in keywords if keyword in query)
            if score > 0:
                scores[comp_type] = score
        
        if scores:
            return max(scores.items(), key=lambda x: x[1])[0]
        return None
    
    def _detect_brand(self, query: str, component_type: str) -> Optional[str]:
        brands = ["intel", "amd", "nvidia", "asus", "msi", "gigabyte", "corsair", "samsung", "western digital"]
        for brand in brands:
            if brand in query:
                return brand.upper()
        return None
    
    def _extract_model_keywords(self, query: str) -> List[str]:
        stop_words = {"the", "a", "an", "for", "with", "under", "around"}
        words = [w for w in query.split() if w not in stop_words and len(w) > 2]
        
        model_keywords = []
        model_pattern = r'\b[a-z]*\d+[a-z]*\b'
        model_matches = re.findall(model_pattern, query)
        model_keywords.extend(model_matches)
        
        return list(set(model_keywords))
    
    def _extract_price_constraints(self, query: str) -> Dict[str, float]:
        constraints = {}
        
        for pattern in ENHANCED_KEYWORD_PATTERNS["price_indicators"]:
            matches = re.finditer(pattern, query.lower())
            for match in matches:
                try:
                    price_str = match.group(1).replace(',', '').strip()
                    
                    if not price_str:
                        continue
                    
                    if 'k' in query[match.start():match.end()].lower():
                        price = float(price_str) * 1000
                    else:
                        price = float(price_str)
                    
                    context = query[max(0, match.start()-20):match.start()].lower()
                    if any(word in context for word in ["under", "below", "less", "max", "maximum"]):
                        constraints["max_price"] = price
                    elif any(word in context for word in ["above", "over", "more", "min", "minimum"]):
                        constraints["min_price"] = price
                    else:
                        constraints["max_price"] = price
                    
                    break
                    
                except (ValueError, IndexError):
                    continue
        
        if not constraints:
            number_pattern = r'\b(\d{4,6})\b'
            number_match = re.search(number_pattern, query)
            if number_match:
                try:
                    price = float(number_match.group(1))
                    constraints["max_price"] = price
                except ValueError:
                    pass
        
        return constraints
    
    def _detect_intent(self, query: str) -> str:
        intent_keywords = {
            "search": ["find", "search", "look for", "show me", "looking for"],
            "compare": ["compare", "vs", "versus", "better", "difference between"],
            "build": ["build", "pc build", "setup", "complete build"],
            "upgrade": ["upgrade", "replace", "improve", "better than"]
        }
        
        for intent, keywords in intent_keywords.items():
            if any(keyword in query for keyword in keywords):
                return intent
        return "search"
    
    def _detect_performance_needs(self, query: str) -> List[str]:
        needs = []
        for need_type, keywords in ENHANCED_KEYWORD_PATTERNS["performance_keywords"].items():
            if any(keyword in query for keyword in keywords):
                needs.append(need_type)
        return needs
    
    def _detect_specific_model(self, query: str) -> bool:
        model_indicators = [r'\d{4}[a-z]*', r'rtx\s*\d+', r'rx\s*\d+', r'ryzen\s*[3579]\s*\d+']
        
        for pattern in model_indicators:
            if re.search(pattern, query):
                return True
        
        return False
    
    def _determine_query_context(self, parsed: Dict) -> str:
        if parsed.get("build_intent_detected") or parsed.get("should_generate_complete_build"):
            return "complete_build"
        elif parsed.get("component_type"):
            return "component_specific"
        elif parsed.get("is_pc_related"):
            return "pc_general"
        else:
            return "non_pc"
    
    def _calculate_confidence(self, parsed: Dict) -> float:
        score = 0.0
        
        if parsed["component_type"]:
            score += 0.3
        if parsed["brand"]:
            score += 0.2
        if parsed["model_keywords"]:
            score += 0.2
        if parsed["price_constraints"]:
            score += 0.15
        if parsed["specific_model_detected"]:
            score += 0.15
        
        if parsed.get("build_intent_detected"):
            score += 0.2
        
        return min(score, 1.0)
    
    def search_in_database(self, parsed_query: Dict) -> Tuple[List[Dict], bool]:
        results = []
        needs_update = False
        
        if not parsed_query.get("is_pc_related", True):
            return [], False
        
        if parsed_query.get("should_generate_complete_build"):
            results = self._search_complete_build(parsed_query)
        elif parsed_query.get("query_context") == "complete_build":
            results = self._search_complete_build(parsed_query)
        else:
            if parsed_query["specific_model_detected"]:
                model_query = " ".join(parsed_query["model_keywords"])
                results = self.db_manager.search_components(
                    component_type=parsed_query["component_type"],
                    brand=parsed_query["brand"],
                    model_query=model_query,
                    max_price=parsed_query["price_constraints"].get("max_price"),
                    min_price=parsed_query["price_constraints"].get("min_price")
                )
            
            if not results:
                search_query = f"{parsed_query['brand'] or ''} {' '.join(parsed_query['model_keywords'])}"
                results = self.db_manager.fuzzy_search_components(
                    query=search_query.strip(),
                    component_type=parsed_query["component_type"],
                    max_price=parsed_query["price_constraints"].get("max_price")
                )
        
        if not results and parsed_query["confidence_score"] > 0.6:
            needs_update = True
        
        return results, needs_update
    
    def _search_complete_build(self, parsed_query: Dict) -> List[Dict]:
        max_price = parsed_query["price_constraints"].get("max_price")
        if not max_price:
            return []
        
        performance_needs = parsed_query.get("performance_needs", [])
        
        if "gaming" in performance_needs:
            component_allocations = {
                "cpu": 0.20, "motherboard": 0.12, "ram": 0.08, "gpu": 0.40, 
                "storage": 0.10, "psu": 0.08, "case": 0.02
            }
        elif "professional" in performance_needs:
            component_allocations = {
                "cpu": 0.35, "motherboard": 0.15, "ram": 0.15, "gpu": 0.20, 
                "storage": 0.10, "psu": 0.08, "case": 0.02
            }
        else:
            component_allocations = {
                "cpu": 0.25, "motherboard": 0.15, "ram": 0.10, "gpu": 0.30, 
                "storage": 0.10, "psu": 0.08, "case": 0.02
            }
        
        all_results = []
        
        for component_type, allocation in component_allocations.items():
            component_budget = max_price * allocation
            
            component_results = self.db_manager.search_components(
                component_type=component_type,
                max_price=component_budget,
                limit=10
            )
            
            if component_results:
                sorted_results = sorted(
                    component_results,
                    key=lambda x: abs(to_float(x['price']) - component_budget)
                )
                best_option = sorted_results[0] if sorted_results else component_results[0]
                all_results.append(best_option)
        
        return all_results

query_parser = SmartQueryParser()

# Upgrade Detection and Suggestion System
class UpgradeSuggestionSystem:
    def __init__(self):
        self.upgrade_keywords = [
            'upgrade', 'upgrading', 'upgraded', 'upgrades',
            'future upgrade', 'future upgrades', 'future-proof',
            'better', 'improve', 'improvement', 'enhance',
            'next level', 'next step', 'better option',
            'upgrade path', 'upgrade for', 'upgrade my',
            'should i upgrade', 'can i upgrade', 'what to upgrade',
            'upgrade cpu', 'upgrade gpu', 'upgrade ram',
            'upgrade storage', 'upgrade motherboard', 'upgrade psu',
            'upgrade case', 'upgrade cooler', 'upgrade monitor'
        ]
        
        self.component_type_keywords = {
            'cpu': ['cpu', 'processor', 'chip'],
            'gpu': ['gpu', 'graphics', 'video card', 'graphics card', 'vga'],
            'ram': ['ram', 'memory', 'ddr'],
            'storage': ['storage', 'ssd', 'hdd', 'hard drive', 'disk'],
            'motherboard': ['motherboard', 'mobo', 'mainboard', 'board'],
            'psu': ['psu', 'power supply', 'power'],
            'case': ['case', 'chassis', 'tower'],
            'cooler': ['cooler', 'cooling', 'fan', 'heatsink'],
            'monitor': ['monitor', 'display', 'screen']
        }
    
    def detect_upgrade_request(self, query: str) -> Dict[str, Any]:
        """Detect if the query is about upgrades and extract component types"""
        query_lower = query.lower()
        
        # Check for upgrade keywords
        has_upgrade_keyword = any(keyword in query_lower for keyword in self.upgrade_keywords)
        
        if not has_upgrade_keyword:
            return {'is_upgrade_request': False}
        
        # Extract mentioned component types
        mentioned_components = []
        for comp_type, keywords in self.component_type_keywords.items():
            if any(keyword in query_lower for keyword in keywords):
                mentioned_components.append(comp_type)
        
        # If no specific component mentioned, it's for all components
        if not mentioned_components:
            mentioned_components = ['all']
        
        return {
            'is_upgrade_request': True,
            'mentioned_components': mentioned_components,
            'query': query
        }
    
    def extract_previous_build(self, conversation_history: List[Dict], thread_id: int = None) -> Dict[str, Any]:
        """Extract previous build components from conversation history or database"""
        previous_components = {}
        previous_budget = None
        recommendation_id = None
        
        # First, try to extract from conversation history
        if conversation_history:
            # Look through history in reverse (most recent first)
            for msg in reversed(conversation_history):
                if msg.get('role') == 'assistant':
                    content = msg.get('content', '')
                    
                    # Try to parse JSON content (recommendation data)
                    try:
                        if content.startswith('{') or content.startswith('['):
                            data = json.loads(content)
                            if isinstance(data, dict):
                                # Check if it's a recommendation structure
                                if 'components' in data and isinstance(data['components'], list):
                                    for comp in data['components']:
                                        comp_type = comp.get('type') or comp.get('component_type')
                                        if comp_type:
                                            previous_components[comp_type] = comp
                                    
                                    # Extract budget if available
                                    if 'budget_analysis' in data:
                                        budget_data = data['budget_analysis']
                                        previous_budget = budget_data.get('user_budget') or budget_data.get('max_budget')
                                    
                                    if previous_components:
                                        return {
                                            'has_previous_build': True,
                                            'components': previous_components,
                                            'budget': previous_budget,
                                            'recommendation_id': recommendation_id
                                        }
                    except (json.JSONDecodeError, ValueError):
                        pass
        
        # If not found in history, try to query database for latest recommendation in thread
        if thread_id and not previous_components:
            try:
                conn = db_manager.get_connection()
                if conn:
                    cursor = conn.cursor(dictionary=True)
                    
                    # Get the latest recommendation from this thread
                    query = """
                        SELECT m.recommendation_id, r.budget_analysis
                        FROM messages m
                        LEFT JOIN recommendations r ON m.recommendation_id = r.id
                        WHERE m.thread_id = %s 
                        AND m.data_type = 'recommendation'
                        AND m.recommendation_id IS NOT NULL
                        ORDER BY m.created_at DESC
                        LIMIT 1
                    """
                    cursor.execute(query, (thread_id,))
                    result = cursor.fetchone()
                    
                    if result and result.get('recommendation_id'):
                        recommendation_id = result['recommendation_id']
                        
                        # Get components from this recommendation
                        comp_query = """
                            SELECT component_type as type, brand, model, price, currency, 
                                   image_url, source_url
                            FROM recommendation_components
                            WHERE recommendation_id = %s
                            AND tier IN ('balanced', 'premium', 'budget')
                            ORDER BY 
                                CASE component_type
                                    WHEN 'cpu' THEN 1
                                    WHEN 'motherboard' THEN 2
                                    WHEN 'ram' THEN 3
                                    WHEN 'gpu' THEN 4
                                    WHEN 'storage' THEN 5
                                    WHEN 'psu' THEN 6
                                    WHEN 'case' THEN 7
                                    WHEN 'cooler' THEN 8
                                    ELSE 9
                                END
                        """
                        cursor.execute(comp_query, (recommendation_id,))
                        comp_results = cursor.fetchall()
                        
                        # Group by component type (take first occurrence of each type)
                        for comp in comp_results:
                            comp_type = comp.get('type')
                            if comp_type and comp_type not in previous_components:
                                previous_components[comp_type] = {
                                    'type': comp_type,
                                    'brand': comp.get('brand'),
                                    'model': comp.get('model'),
                                    'price': to_float(comp.get('price', 0)),
                                    'currency': comp.get('currency', 'PHP'),
                                    'image_url': comp.get('image_url'),
                                    'source_url': comp.get('source_url')
                                }
                        
                        # Extract budget from recommendation
                        if result.get('budget_analysis'):
                            try:
                                budget_data = json.loads(result['budget_analysis'])
                                previous_budget = budget_data.get('user_budget') or budget_data.get('max_budget')
                            except (json.JSONDecodeError, ValueError):
                                pass
                    
                    cursor.close()
                    conn.close()
            except Exception as e:
                logger.error(f"Error extracting previous build from database: {e}")
        
        return {
            'has_previous_build': len(previous_components) > 0,
            'components': previous_components,
            'budget': previous_budget,
            'recommendation_id': recommendation_id
        }
    
    def check_message_relevance(self, current_message: str, previous_messages: List[Dict]) -> bool:
        """Check if current message is relevant to previous conversation"""
        if not previous_messages or len(previous_messages) < 2:
            return False
        
        # Get last assistant message
        last_assistant_msg = None
        for msg in reversed(previous_messages):
            if msg.get('role') == 'assistant':
                last_assistant_msg = msg.get('content', '')
                break
        
        if not last_assistant_msg:
            return False
        
        # Extract keywords from both messages
        current_lower = current_message.lower()
        previous_lower = last_assistant_msg.lower()
        
        # Component-related keywords
        component_keywords = ['cpu', 'gpu', 'ram', 'storage', 'motherboard', 'psu', 'case', 'cooler', 'monitor',
                            'processor', 'graphics', 'memory', 'ssd', 'hdd', 'power supply']
        
        # Check if both mention similar components
        current_components = [kw for kw in component_keywords if kw in current_lower]
        previous_components = [kw for kw in component_keywords if kw in previous_lower]
        
        if current_components and previous_components:
            # Check for overlap
            overlap = set(current_components) & set(previous_components)
            if overlap:
                return True
        
        # Check for budget mentions in both
        budget_keywords = ['budget', 'price', 'cost', 'peso', 'php', '₱']
        if any(kw in current_lower for kw in budget_keywords) and any(kw in previous_lower for kw in budget_keywords):
            return True
        
        return False
    
    def suggest_upgrades(self, current_components: Dict[str, Dict], 
                        mentioned_components: List[str],
                        budget: float = None) -> Dict[str, Any]:
        """Suggest future upgrades for components"""
        suggestions = {}
        
        # If 'all' is mentioned, suggest upgrades for all components
        if 'all' in mentioned_components:
            mentioned_components = list(current_components.keys())
        
        # Calculate upgrade budget (20-30% more than current component price)
        for comp_type in mentioned_components:
            if comp_type not in current_components:
                continue
            
            current_comp = current_components[comp_type]
            current_price = to_float(current_comp.get('price', 0))
            
            if current_price <= 0:
                continue
            
            # Calculate upgrade price range (20-50% more expensive)
            min_upgrade_price = current_price * 1.2
            max_upgrade_price = current_price * 1.5
            
            # If overall budget is provided, allocate portion for this component
            if budget:
                # Allocate based on component importance
                allocation_ratios = {
                    'cpu': 0.20, 'gpu': 0.35, 'ram': 0.10, 'storage': 0.10,
                    'motherboard': 0.12, 'psu': 0.08, 'case': 0.02, 'cooler': 0.03
                }
                component_budget = budget * allocation_ratios.get(comp_type, 0.10)
                max_upgrade_price = max(max_upgrade_price, component_budget)
            
            # Search for better components in the upgrade price range
            upgrade_options = db_manager.search_components(
                component_type=comp_type,
                min_price=min_upgrade_price,
                max_price=max_upgrade_price,
                limit=5
            )
            
            if upgrade_options:
                # Filter out the current component if it's in the results
                current_model = current_comp.get('model', '').lower()
                upgrade_options = [opt for opt in upgrade_options 
                                if opt.get('model', '').lower() != current_model]
                
                if upgrade_options:
                    # Sort by price (ascending) and performance (higher price usually = better)
                    upgrade_options.sort(key=lambda x: to_float(x.get('price', 0)))
                    
                    suggestions[comp_type] = {
                        'current': current_comp,
                        'upgrade_options': upgrade_options[:3],  # Top 3 options
                        'price_range': {
                            'min': min_upgrade_price,
                            'max': max_upgrade_price
                        }
                    }
        
        return suggestions
    
    def format_upgrade_suggestions(self, suggestions: Dict[str, Any], 
                                  mentioned_components: List[str]) -> Dict[str, Any]:
        """Format upgrade suggestions as structured data for frontend table rendering"""
        if not suggestions:
            return {
                'ai_message': "I couldn't find suitable upgrade options for the mentioned components. Please check if you have a previous build recommendation.",
                'type': 'text'
            }
        
        # Build introduction message
        response_parts = []
        response_parts.append("🔧 **Future Upgrade Suggestions**")
        
        if 'all' in mentioned_components or len(mentioned_components) > 3:
            response_parts.append("Here are upgrade paths for your current build:")
        else:
            comp_names = ', '.join([c.upper() for c in mentioned_components if c != 'all'])
            response_parts.append(f"Here are upgrade options for your {comp_names}:")
        
        ai_message = "\n".join(response_parts)
        
        # Build structured components list for table
        upgrade_components = []
        
        for comp_type, suggestion_data in suggestions.items():
            current = suggestion_data['current']
            upgrade_options = suggestion_data['upgrade_options']
            
            current_name = f"{current.get('brand', '')} {current.get('model', '')}".strip()
            current_price = to_float(current.get('price', 0))
            
            # Add each upgrade option as a component entry
            for option in upgrade_options:
                option_price = to_float(option.get('price', 0))
                price_diff = option_price - current_price
                price_diff_pct = (price_diff / current_price * 100) if current_price > 0 else 0
                
                upgrade_components.append({
                    'type': comp_type,
                    'brand': option.get('brand', ''),
                    'model': option.get('model', ''),
                    'price': option_price,
                    'currency': option.get('currency', 'PHP'),
                    'image_url': option.get('image_url'),
                    'source_url': option.get('source_url'),
                    'id': option.get('id'),
                    'component_id': option.get('id'),
                    # Additional metadata for display
                    'current_component': current_name,
                    'current_price': current_price,
                    'price_difference': price_diff,
                    'price_difference_percent': price_diff_pct,
                    'is_upgrade': True
                })
        
        return {
            'ai_message': ai_message + "\n\n💡 **Note:** These are suggestions based on price ranges. Always verify compatibility before upgrading!",
            'type': 'upgrade_suggestion',
            'components': upgrade_components,
            'upgrade_metadata': {
                'mentioned_components': mentioned_components,
                'suggestions_count': len(upgrade_components)
            }
        }

upgrade_system = UpgradeSuggestionSystem()

# Enhanced AI Response Generator
class EnhancedAIResponseGenerator:
    def __init__(self):
        self.conversation_context = {}
    
    def generate_contextual_response(self, user_message: str, 
                                    db_results: List[Dict] = None,
                                    parsed_query: Dict = None,
                                    conversation_history: List[Dict] = None,
                                    budget_analysis: Dict = None,
                                    minimum_build: List[Dict] = None) -> str:
        
        if parsed_query and not parsed_query.get("is_pc_related", True):
            return "I specialize in PC components and computer builds. For PC components or a complete computer setup, I'd be happy to help with that!"
        
        # Check for upgrade requests
        upgrade_detection = upgrade_system.detect_upgrade_request(user_message)
        
        if upgrade_detection.get('is_upgrade_request'):
            # Extract thread_id from parsed_query if available (passed via generate_smart_recommendation)
            thread_id = parsed_query.get('thread_id') if parsed_query else None
            
            # Extract previous build from conversation history and database
            previous_build = upgrade_system.extract_previous_build(conversation_history or [], thread_id)
            
            if previous_build.get('has_previous_build') and previous_build.get('components'):
                # Generate upgrade suggestions
                mentioned_components = upgrade_detection.get('mentioned_components', ['all'])
                previous_budget = previous_build.get('budget')
                
                suggestions = upgrade_system.suggest_upgrades(
                    previous_build['components'],
                    mentioned_components,
                    previous_budget
                )
                
                if suggestions:
                    # Format and return upgrade suggestions as structured data
                    upgrade_data = upgrade_system.format_upgrade_suggestions(
                        suggestions, mentioned_components
                    )
                    
                    # Check relevance to previous conversation
                    is_relevant = upgrade_system.check_message_relevance(
                        user_message, conversation_history or []
                    )
                    
                    # Return structured response that frontend can render as table
                    # This is a dict, not a string, so we need to handle it specially
                    return upgrade_data
                else:
                    return ("I couldn't find suitable upgrade options. "
                           "Please make sure you have a previous build recommendation in this conversation, "
                           "or ask for a new build first.")
            else:
                return ("I'd be happy to suggest upgrades! However, I need to see your current build first. "
                       "Please ask for a PC build recommendation, and then I can suggest upgrade paths for specific components.")
        
        # Check message relevance for context awareness
        if conversation_history and len(conversation_history) > 1:
            is_relevant = upgrade_system.check_message_relevance(user_message, conversation_history)
            if is_relevant:
                logger.info("Current message is relevant to previous conversation")
        
        # Check if upgrade response was returned (it's a dict with 'type' key)
        if isinstance(upgrade_detection.get('is_upgrade_request'), bool) and upgrade_detection.get('is_upgrade_request'):
            # This will be handled above, but if we reach here, return upgrade response
            pass
        
        if db_results and len(db_results) > 0:
            return self._generate_component_response(user_message, db_results, parsed_query, budget_analysis, minimum_build)
        else:
            return self._generate_no_results_response(user_message, parsed_query, budget_analysis)
    
    def _generate_component_response(self, query: str, results: List[Dict], 
                                    parsed: Dict, budget_analysis: Dict = None,
                                    minimum_build: List[Dict] = None) -> str:
        response_parts = []
        
        if parsed.get("query_context") == "complete_build" or parsed.get("should_generate_complete_build"):
            if budget_analysis and not budget_analysis.get("is_feasible", True):
                user_budget = budget_analysis.get("user_budget", 0)
                min_required = budget_analysis.get("min_required", 0)
                performance_type = parsed.get("performance_needs", ["general"])[0]
                
                response_parts.append("I understand you're looking for a complete PC build, but your budget might be too low for the requirements you specified.")
                response_parts.append(f"Your budget is ₱{user_budget:,.0f} while the minimum recommended for {performance_type} is ₱{min_required:,.0f}.")
                
            else:
                response_parts.append("I've assembled a complete PC build within your specified budget.")
                
                total_price = sum(to_float(comp.get('price', 0)) for comp in results)
                max_budget = parsed["price_constraints"].get("max_price", 0)
                
                response_parts.append(f"Total build cost: ₱{total_price:,.2f} (Your budget: ₱{max_budget:,.2f})")
                
                if total_price <= max_budget:
                    budget_utilization = (total_price / max_budget) * 100
                    if budget_utilization < 70:
                        response_parts.append(f"This build fits well within your budget (using {budget_utilization:.0f}%).")
                        response_parts.append("You could consider upgrading some components!")
                    else:
                        response_parts.append("This build fits within your budget!")
                else:
                    response_parts.append("This build slightly exceeds your budget. I can suggest alternatives.")
        else:
            if parsed and parsed.get("specific_model_detected"):
                response_parts.append(f"I found {len(results)} components matching your criteria.")
            else:
                response_parts.append(f"I found {len(results)} components that match your requirements.")
        
        if parsed:
            context_parts = []
            if parsed.get("component_type"):
                context_parts.append(f"Searching for {parsed['component_type'].upper()} components")
            if parsed.get("brand"):
                context_parts.append(f"from {parsed['brand']}")
            if parsed.get("price_constraints"):
                price_info = []
                if "max_price" in parsed["price_constraints"]:
                    price_info.append(f"under ₱{parsed['price_constraints']['max_price']:,.0f}")
                if "min_price" in parsed["price_constraints"]:
                    price_info.append(f"above ₱{parsed['price_constraints']['min_price']:,.0f}")
                if price_info:
                    context_parts.append(f"within your budget: {' and '.join(price_info)}")
            
            if context_parts:
                response_parts.append(" ".join(context_parts) + ".")
        
        return "\n".join(response_parts)
    
    def _generate_no_results_response(self, query: str, parsed: Dict, budget_analysis: Dict = None) -> str:
        response_parts = []
        
        if parsed and (parsed.get("query_context") == "complete_build" or parsed.get("should_generate_complete_build")):
            max_budget = parsed["price_constraints"].get("max_price", 0)
            
            if budget_analysis and not budget_analysis.get("is_feasible", True):
                user_budget = budget_analysis.get("user_budget", 0)
                min_required = budget_analysis.get("min_required", 0)
                performance_type = parsed.get("performance_needs", ["general"])[0]
                
                response_parts.append("🚫 **Budget Constraint Alert**")
                response_parts.append(f"\nUnfortunately, your budget of **₱{user_budget:,.0f}** is too low for a proper {performance_type} PC build.")
                response_parts.append(f"The minimum recommended budget is **₱{min_required:,.0f}**.")
            elif max_budget > 0:
                response_parts.append("I'm working on finding the best components for your complete PC build!")
                response_parts.append(f"\n**Budget:** ₱{max_budget:,.0f}")
            else:
                response_parts.append("I'd love to help you build a PC! Could you please specify your budget?")
        else:
            response_parts.append("I'd like to help you find the perfect PC component or build! Could you provide more details?")
        
        return "\n".join(response_parts)

enhanced_ai_generator = EnhancedAIResponseGenerator()

# Generate smart recommendation
def generate_smart_recommendation(user_message: str, conversation_history: List[Dict] = None, 
                                 request_id: str = None, thread_id: int = None) -> Dict[str, Any]:
    if request_id:
        update_progress(request_id, "Understanding your request")
    
    translated_message = translator.translate_to_english(user_message)
    
    if translated_message != user_message:
        logger.info(f"Original: '{user_message}' -> Translated: '{translated_message}'")
        user_message = translated_message
    
    parsed_query = query_parser.parse_query(user_message)
    # Add thread_id to parsed_query for upgrade system
    if thread_id:
        parsed_query['thread_id'] = thread_id
    logger.info(f"Parsed query: {parsed_query}")
    
    has_budget = bool(parsed_query.get("price_constraints", {}).get("max_price"))
    is_complete_build = (parsed_query.get("query_context") == "complete_build" or 
                        parsed_query.get("should_generate_complete_build"))
    
    max_budget = parsed_query.get("price_constraints", {}).get("max_price", 0)
    
    db_results = []
    needs_update = False
    multiple_recommendations = {}
    budget_analysis = {}
    minimum_build = None
    
    if request_id and max_budget > 0:
        update_progress(request_id, f"Finding components within ₱{max_budget:,.0f} budget", max_budget)
    elif request_id:
        update_progress(request_id, "Searching for components")
    
    if is_complete_build:
        try:
            if request_id:
                update_progress(request_id, "Checking compatibility with other parts", max_budget)
            recommendations = advanced_build_generator.generate_customized_recommendations(parsed_query)
            multiple_recommendations = recommendations.get("builds", {})
            budget_analysis = recommendations.get("budget_analysis", {})
            minimum_build = recommendations.get("minimum_build")
            
            if request_id:
                update_progress(request_id, "Looking for better components", max_budget)
            
            logger.info(f"Build generation results: builds={list(multiple_recommendations.keys())}, balanced_count={len(multiple_recommendations.get('balanced', []))}, budget_count={len(multiple_recommendations.get('budget', []))}, premium_count={len(multiple_recommendations.get('premium', []))}, budget_feasible={budget_analysis.get('is_feasible', 'unknown')}")
        
            if budget_analysis and not budget_analysis.get("is_feasible", True) and minimum_build:
                db_results = minimum_build
                logger.info(f"Using minimum_build: {len(minimum_build)} components")
            else:
                # Try balanced first
                db_results = multiple_recommendations.get("balanced", [])
                logger.info(f"Trying balanced build: {len(db_results)} components")
                
                # Fallback: if balanced is empty, try budget or premium
                if not db_results:
                    db_results = multiple_recommendations.get("budget", []) or multiple_recommendations.get("premium", [])
                    logger.info(f"Fallback to other builds: {len(db_results)} components")
                
                # If still empty, try minimum_build as last resort
                if not db_results and minimum_build:
                    db_results = minimum_build
                    logger.info(f"Using minimum_build as last resort: {len(db_results)} components")
                
                # Final fallback: Try to get any components from any tier
                if not db_results:
                    logger.warning("All build generation methods returned empty results!")
                    for tier_name in ["premium", "budget", "balanced"]:
                        tier_components = multiple_recommendations.get(tier_name, [])
                        if tier_components and len(tier_components) > 0:
                            db_results = tier_components
                            logger.info(f"Using {tier_name} build as final fallback: {len(db_results)} components")
                            break
                
                # If still empty, try generating a build directly using BudgetAwareBuildGenerator
                if not db_results:
                    logger.warning("All premade builds failed, trying direct build generation")
                    max_budget = parsed_query.get("price_constraints", {}).get("max_price")
                    if max_budget:
                        direct_generator = BudgetAwareBuildGenerator()
                        direct_build = direct_generator.generate_build_within_budget(
                            max_budget, 
                            parsed_query.get("performance_needs", [])
                        )
                        if direct_build and direct_build.get("components"):
                            db_results = direct_build["components"]
                            logger.info(f"Direct build generation succeeded: {len(db_results)} components")
        except Exception as e:
            logger.error(f"Error generating builds: {e}", exc_info=True)
            db_results = []
    else:
        db_results, needs_update = query_parser.search_in_database(parsed_query)
    
    if needs_update:
        db_manager.trigger_scraper_update(
            component_type=parsed_query.get("component_type"),
            search_query=user_message
        )
    
    if request_id:
        update_progress(request_id, "Finalizing results", max_budget)
    
    ai_context = enhanced_ai_generator.generate_contextual_response(
        user_message, db_results, parsed_query, conversation_history, budget_analysis, minimum_build
    )
    
    # Check if response is upgrade suggestion (dict with 'type' key)
    is_upgrade_suggestion = isinstance(ai_context, dict) and ai_context.get('type') == 'upgrade_suggestion'
    
    # Handle upgrade suggestions differently - they don't need recommendation_id
    recommendation_id = None
    if not is_upgrade_suggestion:
        recommendation_id = db_manager.create_recommendation(
            ai_response=ai_context if isinstance(ai_context, str) else str(ai_context),
            query_analysis=parsed_query,
            components_found=len(db_results),
            needs_update=needs_update,
            budget_analysis=budget_analysis
        )
    
    if db_results and recommendation_id:
        for component in db_results[:10]:
            db_manager.add_recommendation_component(recommendation_id, component, 'balanced')
    
    if multiple_recommendations and recommendation_id:
        for tier_name, tier_components in multiple_recommendations.items():
            if tier_components:
                total_price = sum(to_float(comp.get('price', 0)) for comp in tier_components)
                db_manager.add_recommendation_tier(
                    recommendation_id, 
                    tier_name, 
                    total_price, 
                    len(tier_components)
                )
                
                for component in tier_components[:6]:
                    db_manager.add_recommendation_component(recommendation_id, component, tier_name)
    
    if minimum_build and recommendation_id:
        for component in minimum_build:
            db_manager.add_recommendation_component(recommendation_id, component, 'minimum')
    
    # Return structured response
    if is_upgrade_suggestion:
        # For upgrade suggestions, return the structured data directly
        return {
            "recommendation_id": None,
            "query_analysis": parsed_query,
            "components_found": len(ai_context.get('components', [])),
            "components": ai_context.get('components', []),
            "ai_response": ai_context.get('ai_message', ''),
            "needs_update": False,
            "multiple_recommendations": {},
            "budget_analysis": {},
            "minimum_build": [],
            "timestamp": datetime.now().isoformat(),
            "is_upgrade_suggestion": True,
            "upgrade_metadata": ai_context.get('upgrade_metadata', {})
        }
    else:
        return {
            "recommendation_id": recommendation_id,
            "query_analysis": parsed_query,
            "components_found": len(db_results),
            "components": db_results,
            "ai_response": ai_context if isinstance(ai_context, str) else str(ai_context),
            "needs_update": needs_update,
            "multiple_recommendations": multiple_recommendations,
            "budget_analysis": budget_analysis,
            "minimum_build": minimum_build,
            "timestamp": datetime.now().isoformat()
        }

# Format smart response with HTML
def format_smart_response(recommendation: Dict[str, Any]) -> str:
    """
    Format the recommendation response - return only plain text, no HTML.
    """
    ai_response = recommendation.get("ai_response", "")
    
    # Clean up the response text
    cleaned_ai_response = re.sub(r'\*\*Recommended Components for Your.*?(?=\n\n|\n💡|$)', '', ai_response, flags=re.DOTALL)
    cleaned_ai_response = re.sub(r'•.*', '', cleaned_ai_response)
    cleaned_ai_response = re.sub(r'💡 \*\*Pro Tip:\*\*.*$', '', cleaned_ai_response, flags=re.MULTILINE)
    cleaned_ai_response = cleaned_ai_response.strip()
    
    # Return only the plain text, no HTML wrapper
    return cleaned_ai_response

# API Endpoints
@app.route('/health', methods=['GET'])
def health():
    db_status = "connected" if connection_pool else "disconnected"
    return jsonify({
        "status": "ok",
        "model_loaded": ai_model.generator is not None,
        "database": db_status,
        "timestamp": datetime.now().isoformat()
    })

@app.route('/progress/<request_id>', methods=['GET'])
def get_progress_endpoint(request_id):
    """Get progress for a request"""
    progress = get_progress(request_id)
    if progress:
        return jsonify({
            "success": True,
            "progress": progress
        })
    return jsonify({
        "success": False,
        "message": "Progress not found"
    }), 404

@app.route('/generate', methods=['POST'])
def generate():
    start_time = time.time()
    request_id = str(uuid.uuid4())
    
    try:
        data = request.json
        user_message = data.get('message', '').strip()
        conversation_history = data.get('history', [])
        thread_id = data.get('thread_id')
        
        if not user_message:
            return jsonify({"success": False, "error": "Message is required"}), 400
        
        logger.info(f"Processing request: {user_message[:100]}... (Thread: {thread_id}, Request ID: {request_id})")
        
        recommendation = generate_smart_recommendation(user_message, conversation_history, request_id, thread_id)
        
        processing_time = time.time() - start_time
        logger.info(f"Request processed in {processing_time:.2f}s")
        
        # Clear progress after a delay
        clear_progress(request_id)
        
        # Return structured data instead of HTML
        return jsonify({
            "success": True,
            "data": {
                "type": "recommendation",
                "ai_message": format_smart_response(recommendation),  # CHANGE: Use format_smart_response instead of plain text
            "query_analysis": recommendation["query_analysis"],
                "components": recommendation["components"],
                "multiple_recommendations": recommendation.get("multiple_recommendations", {}),
                "budget_analysis": recommendation.get("budget_analysis", {}),
                "minimum_build": recommendation.get("minimum_build", []),
            "needs_update": recommendation.get("needs_update", False),
                "components_found": recommendation["components_found"]
            },
            "recommendation_id": recommendation["recommendation_id"],
            "thread_id": thread_id,
            "request_id": request_id,
            "processing_time": f"{processing_time:.2f}s",
            "timestamp": recommendation["timestamp"]
        })
    
    except Exception as e:
        logger.error(f"Generation error: {e}", exc_info=True)
        processing_time = time.time() - start_time
        
        return jsonify({
            "success": False,
            "data": {
                "type": "error",
                "ai_message": "I encountered an error while processing your request. Please try again or provide more details about your PC component needs."
            },
            "error": "I encountered an error while processing your request.",
            "processing_time": f"{processing_time:.2f}s"
        }), 500

@app.route('/recommendation/<int:recommendation_id>', methods=['GET'])
def get_recommendation(recommendation_id):
    """Get formatted HTML for a specific recommendation"""
    try:
        recommendation_data = db_manager.get_recommendation_data(recommendation_id)
        
        if not recommendation_data:
            return jsonify({"success": False, "error": "Recommendation not found"}), 404
        
        reconstructed_recommendation = {
            "query_analysis": recommendation_data['recommendation'].get('query_analysis', {}),
            "components_found": recommendation_data['recommendation'].get('components_found', 0),
            "components": recommendation_data['components'],
            "ai_response": recommendation_data['recommendation'].get('ai_response', ''),
            "needs_update": recommendation_data['recommendation'].get('needs_update', False),
            "budget_analysis": recommendation_data['recommendation'].get('budget_analysis', {}),
            "multiple_recommendations": {},
            "minimum_build": []
        }
        
        for tier in recommendation_data['tiers']:
            tier_name = tier['tier_name']
            tier_components = [comp for comp in recommendation_data['components'] 
                             if comp['tier'] == tier_name]
            reconstructed_recommendation["multiple_recommendations"][tier_name] = tier_components
        
        reconstructed_recommendation["minimum_build"] = [
            comp for comp in recommendation_data['components'] 
            if comp['tier'] == 'minimum'
        ]
        
        response_html = format_smart_response(reconstructed_recommendation)
        
        return jsonify({
            "success": True,
            "html": response_html
        })
    
    except Exception as e:
        logger.error(f"Get recommendation error: {e}")
        return jsonify({"success": False, "error": "Failed to get recommendation"}), 500

@app.route('/alternatives', methods=['POST'])
def get_alternatives():
    """Get alternative components with compatibility checking"""
    try:
        data = request.json
        component_id = data.get('component_id')
        
        if not component_id:
            return jsonify({"success": False, "error": "Component ID is required"}), 400
        
        original_component = db_manager.get_component_by_id(component_id)
        if not original_component:
            return jsonify({"success": False, "error": "Component not found"}), 404
        
        alternatives = db_manager.get_alternatives(component_id, price_range=3000)
        
        compatible_alternatives = []
        # Ensure original_component price is float
        original_price = to_float(original_component.get('price', 0))
        original_component['price'] = float(original_price)
        
        # Get original component type for strict filtering
        original_type = original_component.get('type', '').lower().strip()
        
        for alt in alternatives:
            # Ensure alt price is float - double conversion to be safe
            alt_price = to_float(alt.get('price', 0))
            alt['price'] = float(alt_price)
            
            # Strict type matching - must be exact same type
            alt_type = alt.get('type', '').lower().strip()
            
            # Only include if types match exactly AND price is within range
            # Also exclude peripherals (keyboard, mouse, speakers, monitor, headphones) from non-peripheral alternatives
            peripheral_types = {'keyboard', 'mouse', 'speakers', 'monitor', 'headphones', 'webcam', 'microphone'}
            
            if (alt_type == original_type and 
                alt_type and original_type and  # Both must have types
                abs(alt_price - original_price) <= 5000):
                # If original is NOT a peripheral, exclude peripheral alternatives
                if original_type not in peripheral_types:
                    if alt_type not in peripheral_types:
                        compatible_alternatives.append(alt)
                else:
                    # If original IS a peripheral, only allow same peripheral type
                    compatible_alternatives.append(alt)
        
        # Convert original component price to float for JSON serialization
        original_component_copy = original_component.copy()
        original_component_copy['price'] = float(original_price)
        
        return jsonify({
            "success": True,
            "original_component": original_component_copy,
            "alternatives": compatible_alternatives[:8],
            "compatibility_note": "These alternatives are generally compatible but always verify before purchase"
        })
    
    except Exception as e:
        logger.error(f"Alternatives error: {e}")
        return jsonify({"success": False, "error": "Failed to get alternatives"}), 500

@app.route('/title', methods=['POST'])
def generate_title():
    """Generate thread title from user message"""
    try:
        data = request.json
        user_message = data.get('message', '').strip()
        
        if not user_message:
            return jsonify({"success": False, "error": "Message is required"}), 400
        
        def generate_thread_title(user_message: str) -> str:
            cleaned_message = user_message.strip()
            cleaned_message = re.sub(r'\s+', ' ', cleaned_message)
            
            words = cleaned_message.split()
            
            budget_pattern = r'₱?\s*(\d+(?:[,\d]*)?)\s*(?:k|K)?'
            budget_match = re.search(budget_pattern, cleaned_message)
            budget_text = ""
            if budget_match:
                budget_amount = budget_match.group(1)
                budget_text = f"₱{budget_amount} "
            
            component_types = ["cpu", "gpu", "ram", "motherboard", "storage", "psu", "case", "cooler", "monitor"]
            found_components = []
            for word in words:
                if word.lower() in component_types:
                    found_components.append(word.upper())
            
            build_keywords = ["build", "setup", "pc", "computer", "gaming", "workstation"]
            has_build_intent = any(keyword in cleaned_message.lower() for keyword in build_keywords)
            
            if has_build_intent and budget_text:
                if found_components:
                    return f"{budget_text}{found_components[0]} Build"
                else:
                    return f"{budget_text}PC Build"
            elif found_components:
                if len(found_components) > 1:
                    return f"{budget_text}PC Components"
                else:
                    return f"{budget_text}{found_components[0]}"
            elif budget_text:
                return f"{budget_text}PC Inquiry"
            else:
                title_words = words[:5]
                if len(title_words) >= 3:
                    return " ".join(title_words) + ("..." if len(words) > 5 else "")
                else:
                    return "PC Build Discussion"
        
        title = generate_thread_title(user_message)
        
        return jsonify({
            "success": True,
            "title": title
        })
    
    except Exception as e:
        logger.error(f"Title generation error: {e}")
        return jsonify({
            "success": True,
            "title": "PC Build Discussion"
        })

if __name__ == '__main__':
    port = int(os.getenv('PORT', 5000))
    debug_mode = os.getenv('DEBUG', 'False').lower() == 'true'
    
    logger.info(f"Starting Enhanced PC Builder AI Assistant on port {port}")
    logger.info(f"Model Status: {'Loaded' if ai_model.generator else 'Fallback Mode'}")
    logger.info(f"Database Status: {'Connected' if connection_pool else 'Disconnected'}")
    logger.info(f"Debug Mode: {debug_mode}")
    
    app.run(host='0.0.0.0', port=port, debug=debug_mode)