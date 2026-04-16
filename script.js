const textarea = document.getElementById('textInput');
const navPage = document.getElementById('sideNav');
const placeholderText = "Write something...";
const menubtn = document.getElementById('menuBtn');
const navIcon = menubtn.querySelector("img");
const inputs = document.querySelectorAll('#digitInputs input');

const notif = document.getElementById('notif');
const notifText = document.getElementById('notifText');
const statusIcon = document.getElementById('statusIcon');

const loginPage = document.getElementById('loginPage');
const registerPage = document.getElementById('register');
const step1 = document.getElementById('step1');
const step2 = document.getElementById('step2');
const step3 = document.getElementById('step3');
const forgotPassPage = document.getElementById('forgotPass');
const profilePage = document.getElementById('profilePage');
const loginCard = document.getElementById('loginCard');
const mainContent = document.getElementById('mainContent');
const userMessage = document.querySelectorAll('.user-message');
const loginBtn = document.getElementById('loginAcc');
const forgotPassBtn = document.getElementById('forgotPassBtn');
const registerBtn = document.getElementById('registerBtn');
const backToRegister = document.getElementById('backToRegister');
const goToStep2 = document.getElementById('goToStep2');
const goToStep3 = document.getElementById('goToStep3');
const backToStep1 = document.getElementById('backToStep1');
const backToStep2 = document.getElementById('backToStep2');
const submitBtn = document.getElementById('submitBtn');
const profileBtn = document.getElementById('profileBtn');
const logoutAcc = document.getElementById('logoutAcc');
const nightModeBtn = document.getElementById('nightModeBtn');

const forgotEmail = document.getElementById('forgotEmail');
const toggleNightMode = document.getElementById('toggleNightMode');

const forms = document.querySelectorAll('.submit-form');

const logoImg = document.querySelectorAll('.logo-image');
const userImg = document.getElementById('userImage');
const nightImg = document.getElementById('nightImage');
const logoutImg = document.getElementById('logoutImage');
const sendImg = document.getElementById('sendImage');
const aboutImg = document.getElementById('aboutImage');

const newPass = document.getElementById('newPass');
const conPass = document.getElementById('confirmPass');
const newPassWarning = document.getElementById('newPassWarning');
const conPassWarning = document.getElementById('conPassWarning');

const userEmail = document.getElementById('userEmail');
const userPassword = document.getElementById('userPassword');
const signupEmail = document.getElementById('signupEmail');
const signupPassword = document.getElementById('signupPassword');
const confirmPassword = document.getElementById('confirmPassword');
const signupPassWarning = document.getElementById('signupPassWarning');
const signupConPassWarning = document.getElementById('signupConPassWarning');
const signupPage = document.getElementById('signup');
const showSignUpBtn = document.getElementById('showSignUpBtn');
const showLoginBtn = document.getElementById('showLoginBtn');
const googleLoginBtn = document.getElementById('googleLoginBtn');
const googleSignupBtn = document.getElementById('googleSignupBtn');
const threadInput = document.getElementById('threadInput');
const sendBtn = document.getElementById('sendButton');
const textInput = document.getElementById('textInput');
const threadMessages = document.querySelector('.thread-messages');
const convoHolder = document.querySelector('.convo-holder');
const threadTitleInput = document.querySelector('.thread-title input');
const newConvoBtn = document.querySelector('.new-convo');
const profileInfo = document.querySelector('.profile-info');
const deleteThreadBtn = document.getElementById('deleteThreadBtn');


// Fixed API Base URL - dynamically determine based on current path
const getApiBase = () => {
  // Get the current directory path
  const path = window.location.pathname;
  // Remove trailing slash and filename if present
  const dir = path.substring(0, path.lastIndexOf('/'));
  // Return the base path (empty string if at root)
  return dir || '';
};
const API_BASE = getApiBase();

// Enhanced API Helper Function
async function apiCall(endpoint, method = 'GET', data = null) {
  const options = {
    method: method,
    headers: {
      'Content-Type': 'application/json; charset=utf-8',
    },
    credentials: 'include',
  };
  
  if (data && method !== 'GET') {
    options.body = JSON.stringify(data);
  }
  
  try {
    const url = API_BASE + endpoint;
    console.log('API Call:', method, url, data);
    
    const response = await fetch(url, options);
    
    // Check if response is okay
    if (!response.ok) {
      const errorText = await response.text();
      console.error('HTTP Error:', response.status, errorText);
      return { 
        success: false, 
        message: `Server error (${response.status}): ${errorText || 'Unknown error'}` 
      };
    }
    
    // Check if response is JSON
    const contentType = response.headers.get('content-type');
    if (!contentType || !contentType.includes('application/json')) {
      const text = await response.text();
      console.error('Non-JSON response:', text);
      return { 
        success: false, 
        message: 'Server returned non-JSON response' 
      };
    }
    
    const result = await response.json();
    return result;
  } catch (error) {
    console.error('API Network Error:', error);
    return { 
      success: false, 
      message: 'Network error: ' + error.message 
    };
  }
}

// Helper function to clear textarea after login
function clearTextareaAfterLogin() {
  if (textInput) {
    const currentValue = textInput.value.trim();
    if (currentValue === 'Please login to send messages' || currentValue === '') {
      textInput.value = '';
      textInput.placeholder = 'Write something...';
    }
  }
}

// Check authentication on page load
async function checkAuth() {
  const result = await apiCall('/api/auth.php?action=check');
  if (result.success && result.authenticated) {
    loginPage.classList.add('hidden');
    if (mainContent) mainContent.classList.remove('hidden');
    clearTextareaAfterLogin();
    loadUserInfo();
    loadThreads();
    updateDeleteButtonVisibility();
  } else {
    loginPage.classList.remove('hidden');
    if (mainContent) mainContent.classList.add('hidden');
    updateDeleteButtonVisibility();
  }
}

// Show simple loading spinner
function showSimpleLoader(container) {
    if (!container) return null;
    const loader = document.createElement('div');
    loader.className = 'simple-loader';
    loader.innerHTML = '<div class="spinner-dot"></div><div class="spinner-dot"></div><div class="spinner-dot"></div>';
    container.appendChild(loader);
    return loader;
}

function removeSimpleLoader(loader) {
    if (loader && loader.parentNode) {
        loader.remove();
    }
}

// Load user information
async function loadUserInfo() {
  const loader = showSimpleLoader(profileInfo);
  try {
    const result = await apiCall('/api/user.php');
    if (result.success && result.user) {
      const user = result.user;
      if (profileInfo) {
        const nameSpan = profileInfo.querySelector('h2');
        const emailSpan = profileInfo.querySelector('span');
        if (nameSpan) nameSpan.textContent = user.name || user.email.split('@')[0];
        if (emailSpan) emailSpan.textContent = user.email;
      }
      
    // Load night mode preference
    if (result.user.preferences && result.user.preferences.night_mode) {
      document.body.classList.add('night');
      toggleNightMode.classList.add('turned-on');
      changeIcons();
    }
    }
  } finally {
    removeSimpleLoader(loader);
  }
}

// Load threads
async function loadThreads() {
  const loader = showSimpleLoader(convoHolder);
  try {
    const result = await apiCall('/api/threads.php');
    if (result.success && result.threads) {
      if (convoHolder) {
        convoHolder.innerHTML = '';
        
        if (result.threads.length === 0) {
          const emptyBtn = document.createElement('button');
          emptyBtn.className = 'nav-btn';
          emptyBtn.textContent = 'No conversations yet';
          emptyBtn.disabled = true;
          convoHolder.appendChild(emptyBtn);
        } else {
          result.threads.forEach(thread => {
            const btn = document.createElement('button');
            btn.className = 'nav-btn thread-btn';
            btn.type = 'button';
            btn.setAttribute('data-thread-id', thread.id); // Add this line
            btn.textContent = thread.title;
            btn.addEventListener('click', () => loadThread(thread.id));
            convoHolder.appendChild(btn);
          });
        }
      }
    }
  } finally {
    removeSimpleLoader(loader);
  }
}

// Add this function to help debug
function debugThreadLoading(threadId) {
  console.log('Attempting to load thread:', threadId);
  console.log('Current thread messages element:', threadMessages);
  console.log('Current thread title element:', threadTitleInput);
}

// Load a specific thread
async function loadThread(threadId) {
  console.log('Loading thread:', threadId);
  
  const loader = showSimpleLoader(threadMessages);
  try {
    const result = await apiCall(`/api/threads.php?id=${threadId}`);
    if (result.success && result.thread) {
      const thread = result.thread;
      
      // Update thread title
      if (threadTitleInput) {
        threadTitleInput.value = thread.title;
      }
      
      // Display messages
      if (threadMessages) {
        threadMessages.innerHTML = '';
        
        if (thread.messages && thread.messages.length > 0) {
          thread.messages.forEach(msg => {
            addMessageToUI(msg.content, msg.role);
          });
          
          // Scroll to bottom after loading messages
          setTimeout(() => {
            threadMessages.scrollTop = threadMessages.scrollHeight;
          }, 100);
        } else {
          showWelcomeTemplate();
        }
      }
      
      // Store current thread ID
      window.currentThreadId = threadId;
      
      // Update delete button visibility
      updateDeleteButtonVisibility();
      
      console.log('Loaded thread:', threadId, 'with', thread.messages?.length || 0, 'messages');
    } else {
      console.error('Failed to load thread:', result.message);
      notification(result.message || "Failed to load thread", "alert");
      
      // If thread doesn't exist, clear it and create a new one
      if (result.message && result.message.includes('not found')) {
        window.currentThreadId = null;
        if (threadMessages) {
          threadMessages.innerHTML = '';
          showWelcomeTemplate();
        }
        if (threadTitleInput) {
          threadTitleInput.value = '';
        }
        updateDeleteButtonVisibility();
      }
    }
  } finally {
    removeSimpleLoader(loader);
  }
}

// Show welcome template
function showWelcomeTemplate() {
  if (threadMessages) {
    threadMessages.innerHTML = `
      <div class="prelim-template">
        <img src="assets/circuit.png" alt="empty template image" id="circuitImage"/>
        <h2>Welcome to SmartSpecs!</h2>
        <p>Your AI assistant in choosing the best computer setup.</p>
        <h3>What we do?</h3>
        <ul>
          <li>Suggest best compatible parts based on your specific needs.</li>
          <li>Assists you in making best choices in buying your setup.</li>
          <li>Generate recommendation for future upgrades.</li>
        </ul>
        <h4>Start Now!</h4>
        <p>Try typing: <em>"Provide me a specs for a computer. My budget is 20,000 pesos."</em></p>
      </div>
    `;
  }
}

window.onload = function() {
  changeIcons();
  checkAuth();
  updateDeleteButtonVisibility();
};

document.addEventListener("DOMContentLoaded", function() {
  changeIcons();
  checkAuth();
  updateDeleteButtonVisibility();
  setupSendButton(); // Set up send button after DOM is loaded
});

// Handle send message - set up event listeners
function setupSendButton() {
  const btn = document.getElementById('sendButton');
  const input = document.getElementById('textInput');
  
  if (btn && input) {
    // Remove existing listeners to avoid duplicates
    const newBtn = btn.cloneNode(true);
    btn.parentNode.replaceChild(newBtn, btn);
    
    // Add click event
    newBtn.addEventListener('click', (e) => {
      e.preventDefault();
      sendMessage();
    });
    
    // Add Enter key event
    input.addEventListener('keypress', (e) => {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
      }
    });
    
    console.log('Send button event listeners attached');
  } else {
    console.warn('Send button or text input not found');
  }
}

// Set up send button when DOM is ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', setupSendButton);
} else {
  // DOM already loaded, set up immediately
  setTimeout(setupSendButton, 100); // Small delay to ensure elements are available
}

// Lightweight Markdown to HTML parser
function parseMarkdown(text) {
    if (!text) return '';
    
    // Escape HTML first to prevent injection
    let html = text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
    
    // Process line by line for lists and paragraphs
    const lines = html.split('\n');
    let result = [];
    let inUl = false;
    let inOl = false;
    
    for (let i = 0; i < lines.length; i++) {
        let line = lines[i];
        
        // Unordered list items (- item or • item or * item at start)
        const ulMatch = line.match(/^\s*[-•*]\s+(.+)/);
        // Ordered list items (1. item, 2. item, etc.)
        const olMatch = line.match(/^\s*(\d+)\.\s+(.+)/);
        
        if (ulMatch) {
            if (inOl) { result.push('</ol>'); inOl = false; }
            if (!inUl) { result.push('<ul>'); inUl = true; }
            result.push('<li>' + ulMatch[1] + '</li>');
        } else if (olMatch) {
            if (inUl) { result.push('</ul>'); inUl = false; }
            if (!inOl) { result.push('<ol>'); inOl = true; }
            result.push('<li>' + olMatch[2] + '</li>');
        } else {
            // Close any open lists
            if (inUl) { result.push('</ul>'); inUl = false; }
            if (inOl) { result.push('</ol>'); inOl = false; }
            
            // Empty line = paragraph break
            if (line.trim() === '') {
                result.push('<br>');
            } else {
                result.push(line);
            }
        }
    }
    
    // Close any remaining open lists
    if (inUl) result.push('</ul>');
    if (inOl) result.push('</ol>');
    
    html = result.join('\n');
    
    // Bold: **text** or __text__
    html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    html = html.replace(/__(.+?)__/g, '<strong>$1</strong>');
    
    // Italic: *text* or _text_ (but not inside bold)
    html = html.replace(/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/g, '<em>$1</em>');
    html = html.replace(/(?<!_)_(?!_)(.+?)(?<!_)_(?!_)/g, '<em>$1</em>');
    
    // Inline code: `text`
    html = html.replace(/`([^`]+)`/g, '<code>$1</code>');
    
    // Convert remaining newlines to <br> (but not inside list items)
    html = html.replace(/\n(?!<\/?[uo]l|<\/?li|<br)/g, '<br>');
    
    // Clean up multiple <br> tags
    html = html.replace(/(<br>\s*){3,}/g, '<br><br>');
    
    // Add emoji support for common patterns
    html = html.replace(/:\)/g, '😊');
    
    return html;
}

// Render recommendation data into HTML
function renderRecommendation(data) {
    if (!data || !data.ai_message) {
        return '<div class="ai-message">No response</div>';
    }
    
    // Extract plain text from ai_message (remove HTML if present)
    let messageText = data.ai_message;
    
    // Remove HTML tags if present (but keep markdown)
    if (messageText.includes('<') && !messageText.includes('**') && !messageText.includes('- ')) {
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = messageText;
        messageText = tempDiv.textContent || tempDiv.innerText || messageText;
    }
    
    // Return with markdown formatting
    return '<div class="ai-message">' + parseMarkdown(messageText) + '</div>';
}

// NEW FUNCTION: Render component cards separately
function renderComponentTable(data) {
    if (!data) return '';
    
    let html = '';
    const isUpgrade = data.type === 'upgrade_suggestion' || data.is_upgrade_suggestion;
    
    // Render component cards if components exist
    if (data.components && data.components.length > 0) {
        html += '<div class="components-card-section">';
        
        if (isUpgrade) {
            html += '<div class="components-section-header"><span class="section-icon">🔧</span> Upgrade Options <span class="section-count">' + data.components.length + ' suggestions</span></div>';
        } else {
            html += '<div class="components-section-header"><span class="section-icon">💻</span> Recommended Components <span class="section-count">' + data.components.length + ' found</span></div>';
        }
        
        html += '<div class="components-card-grid">';
        
        data.components.slice(0, 12).forEach((comp, index) => {
            const imageUrl = comp.image_url || comp.image || '';
            const sourceUrl = comp.source_url || comp.url || '#';
            const price = comp.price || 0;
            const compId = comp.id || comp.component_id || index;
            const compType = comp.component_type || comp.type || '';
            const brand = comp.brand || 'N/A';
            const model = comp.model || 'N/A';
            const storeName = comp.store_name || '';
            const reason = comp.reason || '';
            const currency = comp.currency || 'PHP';
            const currencySymbol = currency === 'PHP' ? '₱' : currency === 'USD' ? '$' : currency === 'EUR' ? '€' : currency + ' ';
            
            const finalImageUrl = safeExternalUrl(imageUrl);
            const finalSourceUrl = safeExternalUrl(sourceUrl);
            const cardId = 'component-card-' + index;
            
            html += '<div class="component-card' + (isUpgrade ? ' upgrade-card' : '') + '" id="' + cardId + '" style="animation-delay: ' + (index * 0.06) + 's">';
            
            // Card image section
            html += '<div class="component-card-image">';
            html += '<span class="component-type-badge">' + escapeHtml(compType.toUpperCase()) + '</span>';
            if (finalImageUrl && finalSourceUrl) {
              html += '<a href="' + escapeHtml(finalSourceUrl) + '" target="_blank" rel="noopener noreferrer" class="component-media-link">';
              html += '<img src="' + escapeHtml(finalImageUrl) + '" alt="' + escapeHtml(model) + '" class="component-image" loading="lazy">';
              html += '</a>';
            } else if (finalImageUrl) {
              html += '<img src="' + escapeHtml(finalImageUrl) + '" alt="' + escapeHtml(model) + '" class="component-image" loading="lazy">';
            } else {
              html += '<div class="component-image-empty">No image</div>';
            }
            html += '</div>';
            
            // Card body
            html += '<div class="component-card-body">';
            html += '<div class="component-card-info">';
            html += '<div class="component-brand">' + escapeHtml(brand) + '</div>';
            if (finalSourceUrl) {
              html += '<a href="' + escapeHtml(finalSourceUrl) + '" target="_blank" rel="noopener noreferrer" class="component-model component-model-link">' + escapeHtml(model) + '</a>';
            } else {
              html += '<div class="component-model">' + escapeHtml(model) + '</div>';
            }
            if (reason) {
                html += '<div class="component-reason">' + escapeHtml(reason) + '</div>';
            }
            html += '</div>';
            
            // Upgrade info
            if (isUpgrade && comp.current_component) {
                html += '<div class="component-upgrade-info">';
                html += '<div class="upgrade-from">';
                html += '<span class="upgrade-label">Current:</span>';
                html += '<span class="current-name">' + escapeHtml(comp.current_component) + '</span>';
                html += '</div>';
                const priceDiff = comp.price_difference || 0;
                const priceDiffPct = comp.price_difference_percent || 0;
                const diffClass = priceDiff >= 0 ? 'price-increase' : 'price-decrease';
                html += '<div class="price-difference ' + diffClass + '">';
                html += '<span class="diff-amount">+' + currencySymbol + formatNumber(priceDiff, 2) + '</span>';
                html += '<span class="diff-percent">(+' + formatNumber(priceDiffPct, 1) + '%)</span>';
                html += '</div>';
                html += '</div>';
            }
            
            // Price section
            html += '<div class="component-card-price">';
            html += '<span class="price-amount">' + currencySymbol + formatNumber(price, 2) + '</span>';
            if (storeName) {
                html += '<span class="store-name">' + escapeHtml(storeName) + '</span>';
            }
            html += '</div>';
            
            // Actions
            html += '<div class="component-card-actions">';
            if (finalSourceUrl) {
              html += '<a href="' + escapeHtml(finalSourceUrl) + '" target="_blank" rel="noopener noreferrer" class="btn-view" title="Open product page"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg> Product Link</a>';
            }
            if (!isUpgrade) {
                const altData = encodeURIComponent(JSON.stringify({type: compType, brand: brand, model: model, price: price}));
                html += '<button onclick="toggleAlternativesOnline(this, ' + index + ')" data-component="' + altData + '" class="btn-alternate" id="alt-btn-' + index + '"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 3h5v5"/><path d="M8 3H3v5"/><path d="M12 22v-8.3a4 4 0 00-1.172-2.872L3 3"/><path d="m15 9 6-6"/></svg> Alternatives</button>';
            }
            html += '</div>';
            
            // Alternatives expandable section (hidden by default)
            html += '<div class="component-card-alternatives" id="alternatives-row-' + index + '" style="display: none;">';
            html += '<div class="alternatives-container">';
            html += '<div class="alternatives-loading" id="alt-loading-' + index + '" style="display: none;">Loading alternatives...</div>';
            html += '<div class="alternatives-content" id="alt-content-' + index + '"></div>';
            html += '</div>';
            html += '</div>';
            
            html += '</div>'; // card-body
            html += '</div>'; // component-card
        });
        
        html += '</div>'; // components-card-grid
        html += '</div>'; // components-card-section
    }
    
    // Render build_info (compatibility notes, assumptions) for single builds
    if (data.build_info && !isUpgrade) {
        const bi = data.build_info;
        if (bi.compatibility_notes && bi.compatibility_notes.length > 0) {
            html += '<div class="build-compatibility-notes">';
            html += '<div class="compatibility-header">✓ Compatibility Verified</div>';
            html += '<ul>';
            bi.compatibility_notes.forEach(note => {
                html += '<li>' + escapeHtml(note) + '</li>';
            });
            html += '</ul></div>';
        }
        if (bi.assumptions && bi.assumptions.length > 0) {
            html += '<div class="build-assumptions">';
            html += '<div class="assumptions-header">💡 Assumptions</div>';
            html += '<ul>';
            bi.assumptions.forEach(a => {
                html += '<li>' + escapeHtml(a) + '</li>';
            });
            html += '</ul></div>';
        }
    }
    
    // Multiple recommendations section (budget/balanced/premium tiers)
    if (data.multiple_recommendations && Object.keys(data.multiple_recommendations).length > 0) {
        html += renderMultipleRecommendations(data.multiple_recommendations);
    }
    
    return html;
}

// Helper function to render multiple recommendation tiers
function renderMultipleRecommendations(multipleRecs) {
    if (!multipleRecs || Object.keys(multipleRecs).length === 0) return '';
    
    let html = '<div class="multiple-recommendations">';
    html += '<h4>Build Options</h4>';
    html += '<div class="recommendation-tabs">';
    
    const tiers = ['budget', 'balanced', 'premium'];
    const tierNames = { budget: 'Budget Build', balanced: 'Balanced Build', premium: 'Premium Build' };
    
    tiers.forEach(tier => {
        // Support both old format (array) and new format (object with components key)
        let tierData = multipleRecs[tier];
        if (!tierData) return;
        let components = Array.isArray(tierData) ? tierData : (tierData.components || []);
        let buildName = !Array.isArray(tierData) ? tierData.build_name : null;
        let compatNotes = !Array.isArray(tierData) ? (tierData.compatibility_notes || []) : [];
        let assumptions = !Array.isArray(tierData) ? (tierData.assumptions || []) : [];
        
        if (components.length === 0) return;
        
        const total = components.reduce((sum, comp) => sum + (comp.price || 0), 0);
        // Detect currency from first component
        const firstComp = components[0];
        const currency = firstComp.currency || 'PHP';
        const currencySymbol = currency === 'PHP' ? '₱' : currency === 'USD' ? '$' : currency === 'EUR' ? '€' : currency === 'GBP' ? '£' : currency === 'JPY' ? '¥' : currency + ' ';
        
        html += '<div class="recommendation-tier ' + tier + '">';
        // Use build_name if available, otherwise use default tier name
        const displayName = buildName || tierNames[tier];
        html += '<div class="tier-header">';
        html += '<h5>' + escapeHtml(displayName) + '</h5>';
        html += '<span class="tier-total">' + currencySymbol + formatNumber(total, 2) + '</span>';
        html += '</div>';
        html += '<div class="tier-components-grid">';
        
        components.forEach((comp, compIdx) => {
            const imageUrl = comp.image_url || comp.image || '';
          const finalImageUrl = safeExternalUrl(imageUrl);
            const sourceUrl = comp.source_url || '#';
          const finalSourceUrl = safeExternalUrl(sourceUrl);
            const storeName = comp.store_name || '';
            const compCurrency = comp.currency || currency;
            const compSymbol = compCurrency === 'PHP' ? '₱' : compCurrency === 'USD' ? '$' : compCurrency === 'EUR' ? '€' : compCurrency === 'GBP' ? '£' : compCurrency === 'JPY' ? '¥' : compCurrency + ' ';
            
            html += '<div class="tier-component-card" style="animation-delay: ' + (compIdx * 0.05) + 's">';
            html += '<div class="tier-card-image">';
            html += '<span class="tier-type-badge">' + escapeHtml((comp.type || comp.component_type || '').toUpperCase()) + '</span>';
            if (finalImageUrl && finalSourceUrl) {
              html += '<a href="' + escapeHtml(finalSourceUrl) + '" target="_blank" rel="noopener noreferrer" class="component-media-link">';
              html += '<img src="' + escapeHtml(finalImageUrl) + '" alt="' + escapeHtml(comp.model || '') + '" class="component-thumbnail" loading="lazy">';
              html += '</a>';
            } else if (finalImageUrl) {
              html += '<img src="' + escapeHtml(finalImageUrl) + '" alt="' + escapeHtml(comp.model || '') + '" class="component-thumbnail" loading="lazy">';
            } else {
              html += '<div class="component-image-empty">No image</div>';
            }
            html += '</div>';
            html += '<div class="tier-card-body">';
            if (finalSourceUrl) {
              html += '<a href="' + escapeHtml(finalSourceUrl) + '" target="_blank" rel="noopener noreferrer" class="tier-component-name component-model-link">' + escapeHtml(comp.brand || '') + ' ' + escapeHtml(comp.model || '') + '</a>';
            } else {
              html += '<div class="tier-component-name">' + escapeHtml(comp.brand || '') + ' ' + escapeHtml(comp.model || '') + '</div>';
            }
            if (comp.reason) {
                html += '<div class="tier-component-reason">' + escapeHtml(comp.reason) + '</div>';
            }
            html += '<div class="tier-card-footer">';
            html += '<span class="tier-component-price">' + compSymbol + formatNumber(comp.price || 0, 2) + '</span>';
            if (finalSourceUrl) {
              html += '<a href="' + escapeHtml(finalSourceUrl) + '" target="_blank" rel="noopener noreferrer" class="btn-view-small" title="' + escapeHtml(storeName) + '">Product Link</a>';
            }
            html += '</div>';
            html += '</div>';
            html += '</div>';
        });
        
        html += '</div>';
        
        // Render compatibility notes if available
        if (compatNotes.length > 0) {
            html += '<div class="tier-compatibility-notes">';
            html += '<div class="compatibility-header">✓ Compatibility Verified</div>';
            html += '<ul>';
            compatNotes.forEach(note => {
                html += '<li>' + escapeHtml(note) + '</li>';
            });
            html += '</ul></div>';
        }
        
        html += '</div>';
    });
    
    html += '</div>';
    html += '</div>';
    return html;
}

// Helper functions
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function safeExternalUrl(value) {
  if (!value || typeof value !== 'string') return '';
  const trimmed = value.trim();
  if (!/^https?:\/\//i.test(trimmed)) return '';
  return trimmed;
}

function formatNumber(num, decimals = 0) {
    return Number(num).toLocaleString('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
    });
}

// Update addMessageToUI to render introduction and table separately
function addMessageToUI(content, role, data = null) {
    if (!threadMessages) return;
    
    const messageDiv = document.createElement('div');
    messageDiv.className = role === 'user' ? 'user-input' : 'ai-input';
    
    if (role === 'user') {
        const span = document.createElement('span');
        span.className = 'user-message';
        span.textContent = content;
        messageDiv.appendChild(span);
    } else {
        // Check if it's structured data (recommendation)
        if (data && (data.data_type === 'recommendation' || data.data_type === 'upgrade_suggestion') && data.data) {
            // Render introduction text
            const messageContent = document.createElement('div');
            messageContent.className = 'ai-message';
            messageContent.innerHTML = renderRecommendation(data.data);
            messageDiv.appendChild(messageContent);
            
            // Render component table separately (if components exist)
            const tableHtml = renderComponentTable(data.data);
            if (tableHtml) {
                const tableContainer = document.createElement('div');
                tableContainer.className = 'component-table-container';
                tableContainer.innerHTML = tableHtml;
                messageDiv.appendChild(tableContainer);
            }
        } else if (typeof content === 'string' && content.trim().startsWith('<')) {
            // Legacy HTML content
            const messageContent = document.createElement('div');
            messageContent.className = 'ai-message';
            messageContent.innerHTML = content;
            messageDiv.appendChild(messageContent);
        } else {
            // Plain text - use markdown parser for formatted responses
            const messageContent = document.createElement('div');
            messageContent.className = 'ai-message';
            messageContent.innerHTML = parseMarkdown(content);
            messageDiv.appendChild(messageContent);
        }
    }
    
    // Remove welcome template if exists
    const welcomeTemplate = threadMessages.querySelector('.prelim-template');
    if (welcomeTemplate) {
        welcomeTemplate.remove();
    }
    
    threadMessages.appendChild(messageDiv);
    scrollToBottom();
}

// Update loadThread to pass data
async function loadThread(threadId) {
    console.log('Loading thread:', threadId);
    
    const result = await apiCall(`/api/threads.php?id=${threadId}`);
    if (result.success && result.thread) {
        const thread = result.thread;
        
        // Update thread title
        if (threadTitleInput) {
            threadTitleInput.value = thread.title;
        }
        
        // Display messages
        if (threadMessages) {
            threadMessages.innerHTML = '';
            
            if (thread.messages && thread.messages.length > 0) {
                thread.messages.forEach(msg => {
                    addMessageToUI(msg.content, msg.role, msg);
                });
                
                // Scroll to bottom after loading messages
                setTimeout(() => {
                    scrollToBottom();
                }, 100);
            } else {
                showWelcomeTemplate();
            }
        }
        
        // Store current thread ID
        window.currentThreadId = threadId;
        
        // Update delete button visibility
        updateDeleteButtonVisibility();
        
        console.log('Loaded thread:', threadId, 'with', thread.messages?.length || 0, 'messages');
    } else {
        console.error('Failed to load thread:', result.message);
        notification(result.message || "Failed to load thread", "alert");
        
        // If thread doesn't exist, clear it and create a new one
        if (result.message && result.message.includes('not found')) {
            window.currentThreadId = null;
            if (threadMessages) {
                threadMessages.innerHTML = '';
                showWelcomeTemplate();
            }
            if (threadTitleInput) {
                threadTitleInput.value = '';
            }
            updateDeleteButtonVisibility();
        }
    }
}

// Add a flag to prevent multiple simultaneous refreshes
let isRefreshingThread = false;

// Typing animation component
class TypingAnimation {
    constructor(container, phases = []) {
        this.container = container;
        this.phases = phases;
        this.currentPhaseIndex = 0;
        this.currentText = '';
        this.isTyping = false;
        this.isDeleting = false;
        this.typingSpeed = 50; // ms per character
        this.deletingSpeed = 30; // ms per character
        this.pauseAfterTyping = 1500; // ms to pause after typing
        this.pauseAfterDeleting = 500; // ms to pause after deleting
        this.textElement = null;
        this.cursorElement = null;
        this.animationFrame = null;
        this.init();
    }
    
    init() {
        this.textElement = document.createElement('span');
        this.textElement.className = 'typing-text';
        this.cursorElement = document.createElement('span');
        this.cursorElement.className = 'typing-cursor';
        this.cursorElement.textContent = '|';
        
        const wrapper = document.createElement('div');
        wrapper.className = 'typing-animation-wrapper';
        wrapper.appendChild(this.textElement);
        wrapper.appendChild(this.cursorElement);
        
        this.container.innerHTML = '';
        this.container.appendChild(wrapper);
        
        this.start();
    }
    
    start() {
        if (this.phases.length === 0) return;
        this.currentPhaseIndex = 0;
        this.typePhase(this.phases[0]);
    }
    
    updatePhase(newPhase, budget = null) {
        // Format phase text with budget if provided
        let phaseText = newPhase;
        if (budget !== null && budget > 0) {
            phaseText = phaseText.replace(/\$your/g, `₱${budget.toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0})}`);
        }
        
        // Check if this phase is already in the list
        const existingIndex = this.phases.findIndex(p => {
            if (typeof p === 'string') return p === newPhase;
            return p.text === newPhase;
        });
        
        if (existingIndex === -1) {
            // New phase - add it
            this.phases.push(phaseText);
            if (!this.isTyping && !this.isDeleting) {
                this.currentPhaseIndex = this.phases.length - 1;
                this.typePhase(phaseText);
            }
        } else if (existingIndex > this.currentPhaseIndex) {
            // Phase exists but we haven't reached it yet - update it
            this.phases[existingIndex] = phaseText;
        } else if (existingIndex === this.currentPhaseIndex && this.isTyping) {
            // Currently typing this phase - update the target
            this.phases[existingIndex] = phaseText;
        }
    }
    
    typePhase(phaseText) {
        this.isTyping = true;
        this.isDeleting = false;
        const targetText = typeof phaseText === 'string' ? phaseText : phaseText.text || phaseText;
        let charIndex = 0;
        
        const type = () => {
            if (charIndex < targetText.length) {
                // Add slight randomness to typing speed for realism
                const speedVariation = this.typingSpeed + (Math.random() * 30 - 15);
                this.currentText = targetText.substring(0, charIndex + 1);
                this.textElement.textContent = this.currentText;
                charIndex++;
                this.animationFrame = setTimeout(type, speedVariation);
            } else {
                // Finished typing this phase
                this.isTyping = false;
                setTimeout(() => {
                    this.deletePhase();
                }, this.pauseAfterTyping);
            }
        };
        
        type();
    }
    
    deletePhase() {
        if (this.currentText.length === 0) {
            // Move to next phase (loop back to start if at end)
            this.currentPhaseIndex++;
            if (this.currentPhaseIndex >= this.phases.length) {
                // Loop back to the beginning
                this.currentPhaseIndex = 0;
            }
            
            const nextPhase = this.phases[this.currentPhaseIndex];
            const nextText = typeof nextPhase === 'string' ? nextPhase : nextPhase.text || nextPhase;
            setTimeout(() => {
                this.typePhase(nextText);
            }, this.pauseAfterDeleting);
            return;
        }
        
        this.isDeleting = true;
        this.isTyping = false;
        
        const deleteChar = () => {
            if (this.currentText.length > 0) {
                // Add slight randomness to deleting speed
                const speedVariation = this.deletingSpeed + (Math.random() * 20 - 10);
                this.currentText = this.currentText.substring(0, this.currentText.length - 1);
                this.textElement.textContent = this.currentText;
                this.animationFrame = setTimeout(deleteChar, speedVariation);
            } else {
                // Finished deleting - move to next phase (loop if needed)
                this.isDeleting = false;
                this.currentPhaseIndex++;
                if (this.currentPhaseIndex >= this.phases.length) {
                    // Loop back to the beginning for continuous animation
                    this.currentPhaseIndex = 0;
                }
                
                const nextPhase = this.phases[this.currentPhaseIndex];
                const nextText = typeof nextPhase === 'string' ? nextPhase : nextPhase.text || nextPhase;
                setTimeout(() => {
                    this.typePhase(nextText);
                }, this.pauseAfterDeleting);
            }
        };
        
        deleteChar();
    }
    
    stop() {
        if (this.animationFrame) {
            clearTimeout(this.animationFrame);
        }
        this.isTyping = false;
        this.isDeleting = false;
    }
    
    destroy() {
        this.stop();
        if (this.container) {
            this.container.innerHTML = '';
        }
    }
}

// Function to show loading animation with typing effect
// Detect message type for context-aware loading phases
function detectMessageType(message) {
    if (!message) return 'general';
    const msg = message.toLowerCase();
    
    // Greeting patterns
    if (/^(hi|hello|hey|good\s*(morning|afternoon|evening)|sup|yo|what'?s?\s*up)\b/.test(msg) && msg.length < 30) {
        return 'greeting';
    }

    if (/(update|refresh|latest|current|recheck)\s+(all\s+)?(component\s+)?prices?/.test(msg) || /prices?\s+(update|refresh|recheck)/.test(msg)) {
      return 'price_update';
    }
    
    // Build/recommendation patterns
    if (/\b(build|recommend|suggest|pc\s*build|gaming\s*(pc|setup|rig)|workstation|budget.*build|assemble|setup.*pc|parts?\s*list)\b/.test(msg)) {
        return 'build';
    }
    
    // Component search patterns
    if (/\b(best|top|compare|find|search|looking\s*for|where\s*(to\s*)?buy|price|cheap|affordable)\b.*\b(gpu|cpu|ram|ssd|motherboard|graphics\s*card|processor|monitor|keyboard|mouse|case|psu|power\s*supply|cooler|fan)\b/.test(msg) ||
        /\b(gpu|cpu|ram|ssd|motherboard|graphics\s*card|processor|monitor|keyboard|mouse|case|psu|power\s*supply|cooler|fan)\b.*\b(best|top|compare|find|search|price|cheap|affordable)\b/.test(msg)) {
        return 'search';
    }
    
    // Tips/advice patterns
    if (/\b(how\s*to|tip|trick|hack|advice|fix|troubleshoot|optimize|improve|overclock|clean|maintain|upgrade|install|setup|configure|tweak|boost|speed\s*up|slow|issue|problem|error|crash|blue\s*screen|bsod|lag|freeze|overheat)\b/.test(msg)) {
        return 'tips';
    }
    
    // Upgrade patterns
    if (/\b(upgrade|replace|swap|better|improve|boost)\b.*\b(gpu|cpu|ram|ssd|motherboard|graphics|processor|system|pc|laptop|computer)\b/.test(msg)) {
        return 'upgrade';
    }
    
    return 'general';
}

// Get loading phases based on message type
function getLoadingPhases(messageType) {
    switch (messageType) {
        case 'greeting':
            return [
                "Thinking",
                "Preparing a response"
            ];
        case 'build':
            return [
                "Thinking",
                "Analyzing your requirements",
                "Browsing through the internet",
                "Searching for the latest components",
                "Comparing prices across stores",
                "Checking part compatibility",
                "Optimizing for your budget",
                "Finalizing recommendations"
            ];
        case 'search':
            return [
                "Thinking",
                "Understanding your search",
                "Browsing through the internet",
                "Searching across multiple stores",
                "Comparing prices and availability",
                "Verifying product details",
                "Preparing your results"
            ];
        case 'tips':
            return [
                "Thinking",
                "Analyzing your request",
                "Researching solutions",
                "Gathering expert recommendations",
                "Generating response"
            ];
        case 'upgrade':
            return [
                "Thinking",
                "Analyzing your current setup",
                "Browsing through the internet",
                "Searching for compatible upgrades",
                "Comparing upgrade options",
                "Evaluating performance improvements",
                "Finalizing suggestions"
            ];
        case 'price_update':
          return [
            "Reading component data files",
            "Checking product pages for current prices",
            "Updating verified prices",
            "Recalculating pricing ranges",
            "Finalizing component data update"
          ];
        default:
            return [
                "Thinking",
                "Analyzing your request",
                "Browsing through the internet",
                "Generating response"
            ];
    }
}

function showLoadingAnimation(requestId = null, userMessage = null) {
    if (!threadMessages) return null;
    
    const loadingDiv = document.createElement('div');
    loadingDiv.className = 'ai-input';
    loadingDiv.id = 'loading-indicator';
    
    // Build the loading UI directly — no wrapping .ai-message div
    const inner = document.createElement('div');
    inner.className = 'loading-bubble';
    
    const spinner = document.createElement('div');
    spinner.className = 'loading-spinner';
    spinner.innerHTML = '<span></span><span></span><span></span>';
    
    const typingContainer = document.createElement('div');
    typingContainer.className = 'typing-container';

    const skeletonStrip = document.createElement('div');
    skeletonStrip.className = 'loading-skeleton-strip';
    skeletonStrip.innerHTML = '<div class="loading-skeleton-card"></div><div class="loading-skeleton-card"></div><div class="loading-skeleton-card"></div>';
    
    inner.appendChild(spinner);
    inner.appendChild(typingContainer);
    inner.appendChild(skeletonStrip);
    loadingDiv.appendChild(inner);
    
    threadMessages.appendChild(loadingDiv);
    scrollToBottom();
    
    // Determine context-aware loading phrases
    const messageType = detectMessageType(userMessage);
    const phases = getLoadingPhases(messageType);
    
    const typingAnim = new TypingAnimation(typingContainer, phases);
    loadingDiv.typingAnimation = typingAnim;
    loadingDiv.requestId = requestId;
    
    // Start polling for progress if requestId is provided
    if (requestId) {
        pollProgress(requestId, typingAnim);
    }
    
    return loadingDiv;
}

// Poll progress from Python service
async function pollProgress(requestId, typingAnim) {
    if (!requestId || !typingAnim) return;
    
    // Try to get Python service URL from meta tag or environment
    let pythonServiceUrl = document.querySelector('meta[name="python-service-url"]')?.content;
    if (!pythonServiceUrl) {
        // Try to infer from current location (for Render deployment)
        const currentHost = window.location.hostname;
        if (currentHost.includes('render.com') || currentHost.includes('onrender.com')) {
            // For Render, Python service is on Railway - we can't directly access it from frontend
            // So we'll use the PHP backend as a proxy
            pythonServiceUrl = window.location.origin;
        } else if (currentHost === 'localhost' || currentHost === '127.0.0.1') {
            pythonServiceUrl = 'http://localhost:5000';
        }
    }
    
    if (!pythonServiceUrl) {
        // Can't poll, use default animation
        return;
    }
    
    // If we're using the PHP backend as proxy, use the API endpoint
    const progressUrl = pythonServiceUrl === window.location.origin 
        ? `${API_BASE}/api/progress.php?request_id=${requestId}`
        : `${pythonServiceUrl}/progress/${requestId}`;
    
    const maxAttempts = 120; // 2 minutes max (1 second intervals)
    let attempts = 0;
    
    const poll = async () => {
        if (attempts >= maxAttempts) return;
        
        try {
            const response = await fetch(progressUrl, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json'
                },
                credentials: 'include'
            });
            
            if (response.ok) {
                const data = await response.json();
                if (data.success && data.progress) {
                    const currentPhase = data.progress.current_phase;
                    const budget = data.progress.phases?.find(p => p.phase === currentPhase)?.budget;
                    
                    if (currentPhase) {
                        typingAnim.updatePhase(currentPhase, budget);
                    }
                }
            }
        } catch (error) {
            console.error('Progress poll error:', error);
        }
        
        attempts++;
        if (attempts < maxAttempts) {
            setTimeout(poll, 1000); // Poll every second
        }
    };
    
    // Start polling after a short delay
    setTimeout(poll, 500);
}

// Function to remove loading animation
function removeLoadingAnimation() {
    const loadingIndicator = document.getElementById('loading-indicator');
    if (loadingIndicator) {
        loadingIndicator.remove();
    }
}

// Function to scroll to bottom of messages
function scrollToBottom() {
    if (threadMessages) {
        // Use requestAnimationFrame for smooth scrolling
        requestAnimationFrame(() => {
            threadMessages.scrollTop = threadMessages.scrollHeight;
        });
    }
}

// Update sendMessage to handle structured response
async function sendMessage() {
    const message = textInput.value.trim();
    const placeholderText = textInput.getAttribute('placeholder') || '';
    
    if (!message || message === placeholderText) {
        return;
    }
    
    // Add user message to UI immediately
    addMessageToUI(message, 'user');
    
    // Clear input
    textInput.value = '';
    textInput.style.height = 'auto';
    
    // Show loading animation (context-aware based on message)
    let loadingIndicator = showLoadingAnimation(null, message);
    let requestId = null;
    
    try {
        // Send to PHP backend
        const result = await apiCall('/api/messages.php', 'POST', {
            thread_id: window.currentThreadId || null,
            message: message
        });
        
        // Get request_id from response if available
        if (result.request_id) {
            requestId = result.request_id;
            // Update loading indicator with requestId
            if (loadingIndicator) {
                loadingIndicator.requestId = requestId;
                if (loadingIndicator.typingAnimation) {
                    pollProgress(requestId, loadingIndicator.typingAnimation);
                }
            }
        }
        
        // Remove loading animation
        removeLoadingAnimation();
        
        if (result.success) {
            // Handle successful response
            if (result.thread_id && !window.currentThreadId) {
                window.currentThreadId = result.thread_id;
                updateDeleteButtonVisibility();
                
                if (threadTitleInput && result.thread_title) {
                    threadTitleInput.value = result.thread_title;
                }
            }
            
            if (result.ai_message) {
                addMessageToUI(
                    result.ai_message.content, 
                    'assistant', 
                    result.ai_message
                );
            }
            
            // Refresh thread messages only once after response is received
            if (!isRefreshingThread && window.currentThreadId) {
                isRefreshingThread = true;
                // Refresh the current thread to get updated messages
                await loadThread(window.currentThreadId);
                // Refresh thread list in sidebar (but don't reload the current thread)
                loadThreads();
                // Ensure scroll to bottom after refresh
                setTimeout(() => {
                    scrollToBottom();
                }, 200);
                isRefreshingThread = false;
            } else {
                // If not refreshing, just scroll to bottom
                setTimeout(() => {
                    scrollToBottom();
                }, 100);
            }
        } else {
            // Show user-friendly error message
            const errorMsg = result.message || "Failed to send message";
            notification(errorMsg, "alert");
            
            // Add a fallback response
            addMessageToUI("I apologize, but I'm experiencing technical difficulties. Please try again in a moment.", 'assistant');
        }
    } catch (error) {
        // Remove loading animation on error
        removeLoadingAnimation();
        
        console.error('Send message error:', error);
        notification("Network error: " + error.message, "alert");
        
        // Add fallback response
        addMessageToUI("I'm having trouble connecting right now. Please check your connection and try again.", 'assistant');
    }
}

// Enhance component images in AI responses
async function enhanceComponentImages() {
  // Find component names in tables and add images
  const aiInputs = document.querySelectorAll('.ai-input');
  const lastAiInput = aiInputs[aiInputs.length - 1];
  
  if (!lastAiInput) return;
  
  // Find all table rows with component recommendations
  const rows = lastAiInput.querySelectorAll('table tbody tr');
  rows.forEach(async (row) => {
    const cells = row.querySelectorAll('td');
    if (cells.length >= 2) {
      const componentType = cells[0].textContent.trim().toLowerCase();
      const componentName = cells[1].textContent.trim();
      
      if (componentType && componentName && componentType !== 'component') {
        try {
          const imageResult = await apiCall(`/api/components.php?name=${encodeURIComponent(componentName)}&type=${encodeURIComponent(componentType)}`);
          if (imageResult.success && imageResult.image_url) {
            // Add image to the component cell
            const img = document.createElement('img');
            img.src = imageResult.image_url;
            img.style.width = '100px';
            img.style.height = 'auto';
            img.style.marginTop = '5px';
            img.style.borderRadius = '5px';
            img.alt = componentName;
            cells[1].appendChild(document.createElement('br'));
            cells[1].appendChild(img);
          }
        } catch (error) {
          console.error('Error loading component image:', error);
        }
      }
    }
  });
}

// Handle new conversation
if (newConvoBtn) {
  newConvoBtn.addEventListener('click', async () => {
    const result = await apiCall('/api/threads.php', 'POST', { title: 'New Conversation' });
    
    if (result.success) {
      window.currentThreadId = result.thread.id;
      if (threadMessages) {
        threadMessages.innerHTML = '';
        showWelcomeTemplate();
      }
      if (threadTitleInput) {
        threadTitleInput.value = 'New Conversation';
      }
      updateDeleteButtonVisibility();
      loadThreads();
    } else {
      notification(result.message || "Failed to create conversation", "alert");
    }
  });
}

// Handle thread title update
if (threadTitleInput) {
  let titleUpdateTimeout;
  threadTitleInput.addEventListener('input', () => {
    clearTimeout(titleUpdateTimeout);
    titleUpdateTimeout = setTimeout(async () => {
      if (window.currentThreadId) {
        const result = await apiCall(`/api/threads.php?id=${window.currentThreadId}`, 'PUT', {
          title: threadTitleInput.value
        });
        if (result.success) {
          loadThreads();
        }
      }
    }, 1000);
  });
}

// Handle delete thread
if (deleteThreadBtn) {
  deleteThreadBtn.addEventListener('click', async () => {
    if (!window.currentThreadId) {
      notification("No thread selected to delete", "info");
      return;
    }
    
    // Confirm deletion
    if (!confirm("Are you sure you want to delete this conversation? This action cannot be undone.")) {
      return;
    }
    
    const result = await apiCall(`/api/threads.php?id=${window.currentThreadId}`, 'DELETE');
    
    if (result.success) {
      notification("Thread deleted successfully", "success");
      
      // Clear current thread
      window.currentThreadId = null;
      
      // Clear messages
      if (threadMessages) {
        threadMessages.innerHTML = '';
        showWelcomeTemplate();
      }
      
      // Clear title
      if (threadTitleInput) {
        threadTitleInput.value = '';
      }
      
      // Reload threads list
      loadThreads();
    } else {
      notification(result.message || "Failed to delete thread", "alert");
    }
  });
}

// Show/hide delete button based on thread state
function updateDeleteButtonVisibility() {
  if (deleteThreadBtn) {
    if (window.currentThreadId) {
      deleteThreadBtn.style.display = 'flex';
    } else {
      deleteThreadBtn.style.display = 'none';
    }
  }
}

// Function to update thread title in the input field
function updateThreadTitle(title) {
  if (threadTitleInput && title) {
    threadTitleInput.value = title;
  }
}

document.addEventListener('click', function (event) {
  if (profilePage && !profilePage.contains(event.target) && (!profileBtn || !profileBtn.contains(event.target))) {
    profilePage.classList.add('hidden');
  }

  const isMobile = window.innerWidth <= 768;
  if (!isMobile) return;

  if (navPage && !navPage.contains(event.target) && (!menubtn || !menubtn.contains(event.target))) {
    navPage.classList.remove('open');
    navIcon.src = document.body.classList.contains('night') ?  "assets/light/dots.png" : "assets/dots.png";
  }

  // Handle clicks on thread buttons (including those loaded initially)
  if (event.target.classList.contains('thread-btn') || event.target.closest('.thread-btn')) {
    const threadBtn = event.target.classList.contains('thread-btn') ? event.target : event.target.closest('.thread-btn');
    const threadId = threadBtn.getAttribute('data-thread-id');
    if (threadId) {
      console.log('Thread button clicked, loading thread:', threadId);
      loadThread(threadId);
      
      // Close mobile nav if open
      if (window.innerWidth <= 768 && navPage.classList.contains('open')) {
        navPage.classList.remove('open');
        if (navIcon) {
          navIcon.src = document.body.classList.contains('night') ? "assets/light/dots.png" : "assets/dots.png";
        }
      }
    }
  }
});

function changeIcons() {
  if (document.body.classList.contains('night')) {
    navIcon.src = navIcon.src === "assets/light/close.png" ? "assets/light/close.png" : "assets/light/dots.png";
    logoImg.forEach((logo) => {
      logo.src = "assets/light/favicon.png";
    });
    userImg.src = "assets/light/user.png";
    nightImg.src = "assets/light/moon.png";
    logoutImg.src = "assets/light/logout.png";
    sendImg.src = "assets/light/send.png";
    aboutImg.src = "assets/light/about.png";
    textarea.style.color = "#FEFEFE";
  } else {
    navIcon.src = navIcon.src === "assets/close.png" ? "assets/close.png" : "assets/dots.png";
    logoImg.forEach((logo) => {
      logo.src = "assets/favicon.png";
    });
    userImg.src = "assets/user.png";
    nightImg.src = "assets/moon.png";
    logoutImg.src = "assets/logout.png";
    sendImg.src = "assets/send.png";
    aboutImg.src = "assets/about.png";
    textarea.style.color = "#1B1B1B";
  }
}

function notification(text, statIcon) {
  notif.classList.remove('hidden');
  notifText.innerText = text;
  if( statIcon === "alert" ) {
    statusIcon.src = "assets/warning.png";
    notif.classList.remove('info');
    notif.classList.remove('success');
  } else if ( statIcon === "info" ) {
    statusIcon.src = "assets/info.png";
    notif.classList.add('info');
    notif.classList.remove('success');
  } else {
    statusIcon.src = "assets/check.png";
    notif.classList.remove('info');
    notif.classList.add('success');
  }
  setTimeout(() => {
    notif.classList.add('hidden');
  }, 3000);
}

// Set initial state
if (!textarea.value.trim()) {
  textarea.value = placeholderText;
  textarea.style.color = "#999";
} else {
  textarea.style.color = "#1B1B1B";
}

// Input handler (resize + text color)
textarea.addEventListener('input', () => {
  textarea.style.height = 'auto';
  textarea.style.height = Math.min(textarea.scrollHeight, 100) + 'px';
  
  if (textarea.value.trim() === "" || textarea.value === placeholderText) {
    textarea.style.color = "#999";
  } else {
    if (document.body.classList.contains('night')) {
      textarea.style.color = "#FEFEFE";
    } else {
      textarea.style.color = "#1B1B1B";
    }
  }
});

// On focus: clear placeholder if present
textarea.addEventListener('focus', () => {
  if (textarea.value === placeholderText) {
    textarea.value = "";
  }
  textarea.style.color = "#1B1B1B";
});

// On blur: restore placeholder if empty
textarea.addEventListener('blur', () => {
  if (textarea.value.trim() === "") {
    textarea.value = placeholderText;
    textarea.style.color = "#999";
  }
});

menubtn.addEventListener("click", () => {
  const isOpen = navPage.classList.toggle("open"); // toggles open class

  navIcon.src = isOpen ? "assets/close.png" : "assets/dots.png";
  if (document.body.classList.contains('night')) {
    navIcon.src = isOpen ? "assets/light/close.png" : "assets/light/dots.png";
  } else {
    navIcon.src = isOpen ? "assets/close.png" : "assets/dots.png";
  }
  navIcon.className = isOpen ? "show-nav-btn" : "close-nav-btn";
});

inputs.forEach((input, index) => {
  // Only allow 1 digit and move to next
  input.addEventListener('input', () => {
    input.value = input.value.slice(0, 1); // enforce 1 character max

    if (input.value && index < inputs.length - 1) {
      inputs[index + 1].focus();
    }
  });

  // On click: if input has value, clear itself and all following inputs
  input.addEventListener('focus', () => {
    if (input.value !== '') {
      for (let i = index; i < inputs.length; i++) {
        inputs[i].value = '';
      }
    }
  });
});

// Google OAuth functionality - FIXED
const GOOGLE_CLIENT_ID = '338351232733-6700oof90crie3eu34ju8hfl72aeql46.apps.googleusercontent.com';

async function handleGoogleAuth(isSignup = false) {
  // Load Google Identity Services if not already loaded
  if (typeof google === 'undefined') {
    const script = document.createElement('script');
    script.src = 'https://accounts.google.com/gsi/client';
    script.async = true;
    script.defer = true;
    document.head.appendChild(script);
    
    script.onload = () => {
      initializeGoogleAuth(isSignup);
    };
    return;
  }
  
  initializeGoogleAuth(isSignup);
}

function initializeGoogleAuth(isSignup) {
  google.accounts.id.initialize({
    client_id: GOOGLE_CLIENT_ID,
    callback: handleGoogleCallback,
    context: isSignup ? 'signup' : 'signin'
  });
  
  // Prompt for Google Sign-In
  google.accounts.id.prompt((notification) => {
    console.log('Google prompt notification:', notification);
    
    if (notification.isNotDisplayed() || notification.isSkippedMoment()) {
      // If One Tap doesn't show, fall back to popup
      console.log('One Tap not displayed, trying popup method');
      
      // Use the popup method as fallback
      const client = google.accounts.oauth2.initTokenClient({
        client_id: GOOGLE_CLIENT_ID,
        scope: 'https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/userinfo.profile',
        callback: handleGoogleTokenResponse,
      });
      client.requestAccessToken();
    }
  });
}

async function handleGoogleCallback(response) {
  try {
    console.log('Google credential received');
    
    // Send the credential to your backend
    const result = await apiCall('/api/auth.php?action=google-auth', 'POST', {
      credential: response.credential
    });
    
    if (result.success) {
      loginPage.classList.add('hidden');
      if (mainContent) mainContent.classList.remove('hidden');
      clearTextareaAfterLogin();
      notification("Logged in successfully with Google.", "success");
      loadUserInfo();
      loadThreads();
      updateDeleteButtonVisibility();
    } else {
      notification(result.message || "Google authentication failed", "alert");
    }
  } catch (error) {
    console.error('Google auth error:', error);
    notification("An error occurred during Google authentication", "alert");
  }
}

async function handleGoogleTokenResponse(tokenResponse) {
  try {
    console.log('Getting user info from token');
    
    // Get user info from Google
    const userInfoResponse = await fetch('https://www.googleapis.com/oauth2/v2/userinfo', {
      headers: {
        'Authorization': `Bearer ${tokenResponse.access_token}`
      },
    });
    
    if (!userInfoResponse.ok) {
      throw new Error('Failed to get user info from Google');
    }
    
    const userInfo = await userInfoResponse.json();
    console.log('User info received:', userInfo.email);
    
    // Send to your backend
    const result = await apiCall('/api/auth.php?action=google-auth', 'POST', {
      email: userInfo.email,
      name: userInfo.name,
      picture: userInfo.picture,
      google_id: userInfo.id
    });
    
    if (result.success) {
      loginPage.classList.add('hidden');
      if (mainContent) mainContent.classList.remove('hidden');
      clearTextareaAfterLogin();
      notification("Logged in successfully with Google.", "success");
      loadUserInfo();
      loadThreads();
      updateDeleteButtonVisibility();
    } else {
      notification(result.message || "Google authentication failed", "alert");
    }
  } catch (error) {
    console.error('Google token error:', error);
    notification("An error occurred during Google authentication: " + error.message, "alert");
  }
}

// Google login button
if (googleLoginBtn) {
  googleLoginBtn.addEventListener('click', () => {
    handleGoogleAuth(false);
  });
}

// Google signup button
if (googleSignupBtn) {
  googleSignupBtn.addEventListener('click', () => {
    handleGoogleAuth(true);
  });
}

// Handle login form submission
const loginForm = document.getElementById('loginForm');
if (loginForm) {
  loginForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const email = userEmail.value.trim();
    const password = userPassword.value;
    
    if (!email || !password) {
      notification("Please enter email and password", "alert");
      return;
    }
    
    const result = await apiCall('/api/auth.php?action=login', 'POST', { email, password });
    
    if (result.success) {
      loginPage.classList.add('hidden');
      if (mainContent) mainContent.classList.remove('hidden');
      clearTextareaAfterLogin();
      notification("Logged in successfully.", "success");
      loadUserInfo();
      loadThreads();
    } else {
      notification(result.message || "Login failed", "alert");
    }
  });
}

// Handle signup form submission
const signupForm = document.getElementById('signupForm');
if (signupForm) {
  signupForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const email = signupEmail.value.trim();
    const password = signupPassword.value;
    const confirmPass = confirmPassword.value;
    
    if (!email || !password || !confirmPass) {
      notification("Please fill in all fields", "alert");
      return;
    }
    
    if (password.length < 8) {
      notification("Password must be at least 8 characters", "alert");
      return;
    }
    
    if (password !== confirmPass) {
      notification("Passwords do not match", "alert");
      return;
    }
    
    const result = await apiCall('/api/auth.php?action=register', 'POST', { email, password });
    
    if (result.success) {
      loginPage.classList.add('hidden');
      if (mainContent) mainContent.classList.remove('hidden');
      clearTextareaAfterLogin();
      notification("Registration successful.", "success");
      loadUserInfo();
      loadThreads();
    } else {
      notification(result.message || "Registration failed", "alert");
    }
  });
}

// Toggle between login and signup
if (showSignUpBtn) {
  showSignUpBtn.addEventListener('click', () => {
    registerPage.classList.add('hidden');
    signupPage.classList.remove('hidden');
    forgotPassPage.classList.add('hidden');
  });
}

if (showLoginBtn) {
  showLoginBtn.addEventListener('click', () => {
    signupPage.classList.add('hidden');
    registerPage.classList.remove('hidden');
    forgotPassPage.classList.add('hidden');
  });
}

// Password validation for signup
if (signupPassword) {
  signupPassword.addEventListener('input', () => {
    if (signupPassword.value.length > 0) {
      signupPassWarning.classList.remove('hidden');
      if (signupPassword.value.length < 8) {
        signupPassWarning.innerText = "Password should contain minimum of 8 characters.";
        signupPassWarning.style.color = "#CF0505";
      } else {
        signupPassWarning.innerText = "✔ Strong Password";
        signupPassWarning.style.color = "limegreen";
      }
    } else {
      signupPassWarning.classList.add('hidden');
    }
  });
}

if (confirmPassword) {
  confirmPassword.addEventListener('input', () => {
    if (confirmPassword.value.length > 0) {
      signupConPassWarning.classList.remove('hidden');
      if (confirmPassword.value !== signupPassword.value) {
        signupConPassWarning.innerText = "Password does not match.";
        signupConPassWarning.style.color = "#CF0505";
      } else {
        signupConPassWarning.innerText = "✔ Password Match";
        signupConPassWarning.style.color = "limegreen";
      }
    } else {
      signupConPassWarning.classList.add('hidden');
    }
  });
}

forgotPassBtn.addEventListener('click', () => {
  registerPage.classList.add('hidden');
  forgotPassPage.classList.remove('hidden');
  if (step1.classList.contains('hidden')) { step1.classList.remove('hidden'); }
});

registerBtn.addEventListener('click', () => {
  forgotPassPage.classList.add('hidden');
  registerPage.classList.remove('hidden');
  signupPage.classList.add('hidden');
});

backToRegister.addEventListener('click', () => {
  forgotPassPage.classList.add('hidden');
  registerPage.classList.remove('hidden');
  signupPage.classList.add('hidden');
});

goToStep2.addEventListener('click', async () => {
  const email = forgotEmail.value.trim();
  if (email === '') {
    notification("Please enter your email", "alert");
    return;
  }
  
  const result = await apiCall('/api/auth.php?action=forgot-password', 'POST', { email });
  
  if (result.success) {
    step1.classList.add('hidden');
    step2.classList.remove('hidden');
    notification("OTP sent to your email", "success");
    // Store email for later steps
    window.resetEmail = email;
    // In development, show OTP in console (remove in production)
    if (result.otp) {
      console.log('OTP:', result.otp);
    }
  } else {
    notification(result.message || "Failed to send OTP", "alert");
  }
});

goToStep3.addEventListener('click', async () => {
  const email = window.resetEmail || forgotEmail.value.trim();
  const otpInputs = document.querySelectorAll('#digitInputs input');
  const otp = Array.from(otpInputs).map(input => input.value).join('');
  
  if (otp.length !== 5) {
    notification("Please enter the complete OTP", "alert");
    return;
  }
  
  const result = await apiCall('/api/auth.php?action=verify-otp', 'POST', { email, otp });
  
  if (result.success) {
    step1.classList.add('hidden');
    step2.classList.add('hidden');
    step3.classList.remove('hidden');
    notification("OTP verified", "success");
  } else {
    notification(result.message || "Invalid OTP", "alert");
  }
});

backToStep1.addEventListener('click', () => {
  step2.classList.add('hidden');
  step1.classList.remove('hidden');
  step3.classList.add('hidden');
});

backToStep2.addEventListener('click', () => {
  step3.classList.add('hidden');
  step2.classList.remove('hidden');
  step1.classList.add('hidden');
});

submitBtn.addEventListener('click', async () => {
  const email = window.resetEmail || forgotEmail.value.trim();
  const password = newPass.value;
  const confirmPassword = conPass.value;
  
  if (password.length < 8) {
    notification("Password must be at least 8 characters", "alert");
    return;
  }
  
  if (password !== confirmPassword) {
    notification("Passwords do not match", "alert");
    return;
  }
  
  const result = await apiCall('/api/auth.php?action=reset-password', 'POST', { email, password });
  
  if (result.success) {
    step3.classList.add('hidden');
    step2.classList.add('hidden');
    step1.classList.remove('hidden');
    forgotPassPage.classList.add('hidden');
    registerPage.classList.remove('hidden');
    loginPage.classList.add('hidden');
    notification("Password reset successful. Please login.", "success");
    // Clear reset email
    window.resetEmail = null;
  } else {
    notification(result.message || "Password reset failed", "alert");
  }
});

profileBtn.addEventListener('click', () => {
  profilePage.classList.toggle('hidden');
});

logoutAcc.addEventListener('click', async () => {
  const result = await apiCall('/api/auth.php?action=logout', 'POST');
  
  if (result.success) {
    loginPage.classList.remove('hidden');
    mainContent.classList.add('hidden');
    notification("Logged out successfully.", "success");
    // Clear current thread
    window.currentThreadId = null;
    if (threadMessages) threadMessages.innerHTML = '';
    if (convoHolder) convoHolder.innerHTML = '';
    updateDeleteButtonVisibility();
  } else {
    notification(result.message || "Logout failed", "alert");
  }
});

forms.forEach((form) => {
  form.addEventListener('submit', function (e) {
    e.preventDefault();
  });
});

nightModeBtn.addEventListener('click', async () => {
  const isNightMode = !document.body.classList.contains('night');
  toggleNightMode.classList.toggle('turned-on');
  // Just toggle night class on body - CSS filter handles the rest
  document.body.classList.toggle('night');
  changeIcons();
  
  // Save preference
  if (isLoggedIn()) {
    await apiCall('/api/user.php', 'PUT', { night_mode: isNightMode ? 1 : 0 });
  }
});

// Check if user is logged in (helper)
function isLoggedIn() {
  return !loginPage || !loginPage.classList.contains('hidden');
}

newPass.addEventListener('input', () => {
  newPassWarning.classList.remove('hidden'); 
  if (newPass.value.length < 8) { 
    newPassWarning.innerText = "Password should contain minimum of 8 characters.";
    newPassWarning.style.color = "#CF0505";
  } else {
    newPassWarning.innerText = "✔ Strong Password";
    newPassWarning.style.color = "limegreen";
  }
});

conPass.addEventListener('input', () => {
  conPassWarning.classList.remove('hidden');
  if (conPass.value !== newPass.value) { 
    conPassWarning.innerText = "Password does not match.";
    conPassWarning.style.color = "#CF0505";
  } else {
    conPassWarning.innerText = "✔ Password Match";
    conPassWarning.style.color = "limegreen";
  }
});

// Toggle alternatives visibility and fetch if needed
function toggleAlternativesOnline(buttonElement, rowIndex) {
    // Get component data from button's data attribute
    const compDataStr = decodeURIComponent(buttonElement.getAttribute('data-component'));
    let compData;
    try {
        compData = JSON.parse(compDataStr);
    } catch (e) {
        console.error('Failed to parse component data:', e);
        return;
    }
    
    const alternativesRowId = 'alternatives-row-' + rowIndex;
    const alternativesRow = document.getElementById(alternativesRowId);
    const alternativesContent = document.getElementById('alt-content-' + rowIndex);
    const loadingDiv = document.getElementById('alt-loading-' + rowIndex);
    const button = document.getElementById('alt-btn-' + rowIndex);
    
    if (!alternativesRow) return;
    
    // Toggle visibility with smooth animation
    const isVisible = alternativesRow.style.display !== 'none';
    
    if (isVisible) {
        // Hide alternatives with animation
        alternativesRow.style.maxHeight = '0';
        alternativesRow.style.opacity = '0';
        setTimeout(() => {
            alternativesRow.style.display = 'none';
        }, 300);
        if (button) {
            button.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 3h5v5"/><path d="M8 3H3v5"/><path d="M12 22v-8.3a4 4 0 00-1.172-2.872L3 3"/><path d="m15 9 6-6"/></svg> Alternatives';
            button.classList.remove('active');
        }
    } else {
        // Show alternatives with animation
        alternativesRow.style.display = '';
        requestAnimationFrame(() => {
            alternativesRow.style.maxHeight = '2000px';
            alternativesRow.style.opacity = '1';
        });
        if (button) {
            button.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18"/><path d="M6 6l12 12"/></svg> Hide';
            button.classList.add('active');
        }
        
        // If alternatives haven't been loaded yet, fetch them
        if (!alternativesContent.dataset.loaded) {
            loadingDiv.style.display = 'block';
            alternativesContent.innerHTML = '';
            
            // Send component details (not ID) for AI-powered alternative search
            apiCall('/api/alternatives.php', 'POST', {
                component_type: compData.type,
                brand: compData.brand,
                model: compData.model,
                price: compData.price
            })
            .then(data => {
                loadingDiv.style.display = 'none';
                
                if (data.success && data.alternatives && data.alternatives.length > 0) {
                    let altHtml = '<div class="alternatives-header">';
                    altHtml += '<strong>Alternative Components</strong> ';
                    altHtml += '<span class="alt-count">' + data.alternatives.length + ' found</span>';
                    if (data.compatibility_note) {
                        altHtml += '<div class="alt-note">' + escapeHtml(data.compatibility_note) + '</div>';
                    }
                    altHtml += '</div>';
                    altHtml += '<div class="alternatives-card-grid">';
                    
                    data.alternatives.forEach((alt, altIdx) => {
                        const altImageUrl = alt.image_url || alt.image || '';
                      const altFinalImageUrl = safeExternalUrl(altImageUrl);
                        const altSourceUrl = alt.source_url || alt.url || '#';
                      const finalAltSourceUrl = safeExternalUrl(altSourceUrl);
                        const altStoreName = alt.store_name || '';
                        const altReason = alt.reason || '';
                        const altCurrency = alt.currency || 'PHP';
                        const altSymbol = altCurrency === 'PHP' ? '₱' : altCurrency === 'USD' ? '$' : altCurrency === 'EUR' ? '€' : altCurrency + ' ';
                        
                        altHtml += '<div class="alt-card" style="animation-delay: ' + (altIdx * 0.05) + 's">';
                        altHtml += '<div class="alt-card-image">';
                        if (altFinalImageUrl && finalAltSourceUrl) {
                          altHtml += '<a href="' + escapeHtml(finalAltSourceUrl) + '" target="_blank" rel="noopener noreferrer" class="component-media-link">';
                          altHtml += '<img src="' + escapeHtml(altFinalImageUrl) + '" alt="' + escapeHtml(alt.model || '') + '" class="alt-component-image" loading="lazy">';
                          altHtml += '</a>';
                        } else if (altFinalImageUrl) {
                          altHtml += '<img src="' + escapeHtml(altFinalImageUrl) + '" alt="' + escapeHtml(alt.model || '') + '" class="alt-component-image" loading="lazy">';
                        } else {
                          altHtml += '<div class="component-image-empty">No image</div>';
                        }
                        altHtml += '</div>';
                        altHtml += '<div class="alt-card-body">';
                        altHtml += '<div class="alt-brand-model">' + escapeHtml(alt.brand || 'N/A') + ' ' + escapeHtml(alt.model || 'N/A') + '</div>';
                        if (altReason) {
                            altHtml += '<div class="alt-reason">' + escapeHtml(altReason) + '</div>';
                        }
                        altHtml += '<div class="alt-card-footer">';
                        altHtml += '<span class="alt-price-amount">' + altSymbol + formatNumber(alt.price || 0, 2) + '</span>';
                        if (altStoreName) {
                            altHtml += '<span class="alt-store">' + escapeHtml(altStoreName) + '</span>';
                        }
                        altHtml += '</div>';
                        if (finalAltSourceUrl) {
                          altHtml += '<a href="' + escapeHtml(finalAltSourceUrl) + '" target="_blank" rel="noopener noreferrer" class="btn-view-small" title="Search at ' + escapeHtml(altStoreName || 'store') + '">Product Link</a>';
                        }
                        altHtml += '</div>';
                        altHtml += '</div>';
                    });
                    
                    altHtml += '</div>';
                    alternativesContent.innerHTML = altHtml;
                    alternativesContent.dataset.loaded = 'true';
                } else {
                    alternativesContent.innerHTML = '<div class="no-alternatives">No alternative components found for this item.</div>';
                    alternativesContent.dataset.loaded = 'true';
                }
            })
            .catch(error => {
                loadingDiv.style.display = 'none';
                console.error('Error loading alternatives:', error);
                alternativesContent.innerHTML = '<div class="alternatives-error">Error loading alternatives. Please try again.</div>';
            });
        }
    }
    
    // Scroll to show the alternatives
    setTimeout(() => {
        if (alternativesRow.style.display !== 'none') {
            alternativesRow.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }, 150);
}