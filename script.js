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
const sendBtn = threadInput ? threadInput.querySelector('button') : null;
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

// Check authentication on page load
async function checkAuth() {
  const result = await apiCall('/api/auth.php?action=check');
  if (result.success && result.authenticated) {
    loginPage.classList.add('hidden');
    if (mainContent) mainContent.classList.remove('hidden');
    loadUserInfo();
    loadThreads();
    updateDeleteButtonVisibility();
  } else {
    loginPage.classList.remove('hidden');
    if (mainContent) mainContent.classList.add('hidden');
    updateDeleteButtonVisibility();
  }
}

// Load user information
async function loadUserInfo() {
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
      loginCard.classList.add('night');
      mainContent.classList.add('night-messages');
      toggleNightMode.classList.add('turned-on');
      changeIcons();
    }
  }
}

// Load threads
async function loadThreads() {
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
});

// Handle send message
if (sendBtn && textInput) {
  sendBtn.addEventListener('click', sendMessage);
  textInput.addEventListener('keypress', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      sendMessage();
    }
  });
}

// Render recommendation data into HTML
function renderRecommendation(data) {
    if (!data || !data.ai_message) {
        return '<div class="ai-message">No response</div>';
    }
    
    // Extract plain text from ai_message (remove HTML if present)
    let messageText = data.ai_message;
    
    // Remove HTML tags if present
    if (messageText.includes('<')) {
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = messageText;
        messageText = tempDiv.textContent || tempDiv.innerText || messageText;
    }
    
    // Clean up: remove extra whitespace
    messageText = messageText.replace(/\s+/g, ' ').trim();
    
    // Return only the introduction text
    return '<div class="ai-message">' + escapeHtml(messageText) + '</div>';
}

// NEW FUNCTION: Render component table separately
function renderComponentTable(data) {
    if (!data) return '';
    
    let html = '';
    
    // Render components table if components exist
    if (data.components && data.components.length > 0) {
        html += '<div class="components-table-section">';
        html += '<h4>Recommended Components (' + data.components.length + ' found)</h4>';
        html += '<div class="table-container">';
        html += '<table class="components-table">';
        html += '<thead>';
        html += '<tr>';
        html += '<th>Type</th>';
        html += '<th>Brand</th>';
        html += '<th>Model</th>';
        html += '<th>Price</th>';
        html += '<th>Image</th>';
        html += '<th>Actions</th>';
        html += '</tr>';
        html += '</thead>';
        html += '<tbody>';
        
        data.components.slice(0, 12).forEach((comp, index) => {
            const imageUrl = comp.image_url || comp.image || '';
            const sourceUrl = comp.source_url || comp.url || '#';
            const price = comp.price || 0;
            const compId = comp.id || comp.component_id || 0;
            const compType = comp.component_type || comp.type || '';
            const brand = comp.brand || 'N/A';
            const model = comp.model || 'N/A';
            
            // Use data URI for placeholder (1x1 transparent pixel) instead of external URL
            const placeholderImage = 'data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'60\' height=\'60\'%3E%3Crect width=\'60\' height=\'60\' fill=\'%23f0f0f0\'/%3E%3Ctext x=\'50%25\' y=\'50%25\' text-anchor=\'middle\' dy=\'.3em\' font-size=\'10\' fill=\'%23999\'%3ENo Image%3C/text%3E%3C/svg%3E';
            const finalImageUrl = imageUrl && imageUrl.trim() ? imageUrl : placeholderImage;
            const rowId = 'component-row-' + compId;
            const alternativesRowId = 'alternatives-row-' + compId;
            
            html += '<tr class="component-row" id="' + rowId + '">';
            html += '<td class="component-type-cell">';
            html += '<span class="component-type-badge">' + escapeHtml(compType.toUpperCase()) + '</span>';
            html += '</td>';
            html += '<td class="component-brand-cell">';
            html += '<div class="component-brand">' + escapeHtml(brand) + '</div>';
            html += '</td>';
            html += '<td class="component-model-cell">';
            html += '<div class="component-model">' + escapeHtml(model) + '</div>';
            html += '</td>';
            html += '<td class="component-price-cell">';
            html += '<span class="price-amount">₱' + formatNumber(price, 2) + '</span>';
            html += '</td>';
            html += '<td class="component-image-cell">';
            // Remove onerror handler - use placeholder directly
            html += '<img src="' + escapeHtml(finalImageUrl) + '" alt="' + escapeHtml(model) + '" class="component-image">';
            html += '</td>';
            html += '<td class="component-actions-cell">';
            html += '<div class="table-actions">';
            html += '<a href="' + escapeHtml(sourceUrl) + '" target="_blank" class="btn-view">View</a>';
            if (compId) {
                html += '<button onclick="toggleAlternatives(' + compId + ', ' + index + ')" class="btn-alternate" id="alt-btn-' + compId + '">Alternatives</button>';
            }
            html += '</div>';
            html += '</td>';
            html += '</tr>';
            // Add hidden row for alternatives
            html += '<tr class="alternatives-row" id="' + alternativesRowId + '" style="display: none;">';
            html += '<td colspan="6" class="alternatives-cell">';
            html += '<div class="alternatives-container">';
            html += '<div class="alternatives-loading" id="alt-loading-' + compId + '" style="display: none; padding: 20px; text-align: center; color: #6c757d;">Loading alternatives...</div>';
            html += '<div class="alternatives-content" id="alt-content-' + compId + '"></div>';
            html += '</div>';
            html += '</td>';
            html += '</tr>';
        });
        
        html += '</tbody>';
        html += '</table>';
        html += '</div>';
        html += '</div>';
    }
    
    // REMOVED: Multiple recommendations section
    // if (data.multiple_recommendations) {
    //     html += renderMultipleRecommendations(data.multiple_recommendations);
    // }
    
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
        if (multipleRecs[tier] && multipleRecs[tier].length > 0) {
            const total = multipleRecs[tier].reduce((sum, comp) => sum + (comp.price || 0), 0);
            html += '<div class="recommendation-tier ' + tier + '">';
            html += '<h5>' + tierNames[tier] + ' - ₱' + formatNumber(total, 2) + '</h5>';
            html += '<div class="tier-components">';
            
            multipleRecs[tier].forEach(comp => {
                const imageUrl = comp.image_url || comp.image || '';
                // Use data URI placeholder instead of external URL
                const placeholderImage = 'data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'40\' height=\'40\'%3E%3Crect width=\'40\' height=\'40\' fill=\'%23f0f0f0\'/%3E%3Ctext x=\'50%25\' y=\'50%25\' text-anchor=\'middle\' dy=\'.3em\' font-size=\'8\' fill=\'%23999\'%3E%3C/text%3E%3C/svg%3E';
                const finalImageUrl = imageUrl && imageUrl.trim() ? imageUrl : placeholderImage;
                
                html += '<div class="tier-component">';
                html += '<img src="' + escapeHtml(finalImageUrl) + '" alt="' + escapeHtml(comp.model || '') + '" class="component-thumbnail">';
                html += '<div class="tier-component-info">';
                html += '<div class="tier-component-type">' + escapeHtml((comp.type || comp.component_type || '').toUpperCase()) + '</div>';
                html += '<div class="tier-component-name">' + escapeHtml(comp.brand || '') + ' ' + escapeHtml(comp.model || '') + '</div>';
                html += '</div>';
                html += '<div class="tier-component-price">₱' + formatNumber(comp.price || 0, 2) + '</div>';
                html += '</div>';
            });
            
            html += '</div>';
            html += '</div>';
        }
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
        if (data && data.data_type === 'recommendation' && data.data) {
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
            // Plain text
            const messageContent = document.createElement('div');
            messageContent.className = 'ai-message';
            messageContent.textContent = content;
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

// Function to show loading animation
function showLoadingAnimation() {
    if (!threadMessages) return null;
    
    const loadingDiv = document.createElement('div');
    loadingDiv.className = 'ai-input loading-message';
    loadingDiv.id = 'loading-indicator';
    loadingDiv.innerHTML = `
        <div class="ai-message">
            <div class="loading-spinner">
                <div class="spinner-dot"></div>
                <div class="spinner-dot"></div>
                <div class="spinner-dot"></div>
            </div>
            <span class="loading-text">Generating your PC build recommendation...</span>
        </div>
    `;
    
    threadMessages.appendChild(loadingDiv);
    scrollToBottom();
    return loadingDiv;
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
    
    // Show loading animation
    const loadingIndicator = showLoadingAnimation();
    
    try {
        // Send to PHP backend
        const result = await apiCall('/api/messages.php', 'POST', {
            thread_id: window.currentThreadId || null,
            message: message
        });
        
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
                await refreshThreadMessages(window.currentThreadId);
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
  document.body.classList.toggle('night');
  loginCard.classList.toggle('night');
  mainContent.classList.toggle('night-messages');
  userMessage.forEach((message) => {
    message.classList.toggle('night-thread');
  });
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
function toggleAlternatives(componentId, rowIndex) {
    if (!componentId) {
        alert('Component ID not available for alternatives.');
        return;
    }
    
    const alternativesRow = document.getElementById('alternatives-row-' + componentId);
    const alternativesContent = document.getElementById('alt-content-' + componentId);
    const loadingDiv = document.getElementById('alt-loading-' + componentId);
    const button = document.getElementById('alt-btn-' + componentId);
    
    if (!alternativesRow) return;
    
    // Toggle visibility
    const isVisible = alternativesRow.style.display !== 'none';
    
    if (isVisible) {
        // Hide alternatives
        alternativesRow.style.display = 'none';
        if (button) {
            button.textContent = 'Alternatives';
            button.classList.remove('active');
        }
    } else {
        // Show alternatives
        alternativesRow.style.display = '';
        if (button) {
            button.textContent = 'Hide Alternatives';
            button.classList.add('active');
        }
        
        // If alternatives haven't been loaded yet, fetch them
        if (!alternativesContent.dataset.loaded) {
            loadingDiv.style.display = 'block';
            alternativesContent.innerHTML = '';
            
            fetch('http://localhost:5000/alternatives', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({component_id: componentId})
            })
            .then(response => response.json())
            .then(data => {
                loadingDiv.style.display = 'none';
                
                if (data.success && data.alternatives && data.alternatives.length > 0) {
                    // Render alternatives inline
                    let altHtml = '<div class="alternatives-header">';
                    altHtml += '<strong>Alternative Components:</strong> ';
                    altHtml += '<span class="alt-count">' + data.alternatives.length + ' found</span>';
                    altHtml += '</div>';
                    altHtml += '<div class="alternatives-list">';
                    
                    data.alternatives.forEach(alt => {
                        const altImageUrl = alt.image_url || alt.image || '';
                        const altPlaceholderImage = 'data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'40\' height=\'40\'%3E%3Crect width=\'40\' height=\'40\' fill=\'%23f0f0f0\'/%3E%3Ctext x=\'50%25\' y=\'50%25\' text-anchor=\'middle\' dy=\'.3em\' font-size=\'8\' fill=\'%23999\'%3ENo Image%3C/text%3E%3C/svg%3E';
                        const altFinalImageUrl = altImageUrl && altImageUrl.trim() ? altImageUrl : altPlaceholderImage;
                        const altSourceUrl = alt.source_url || alt.url || '#';
                        
                        altHtml += '<div class="alternative-item">';
                        altHtml += '<div class="alt-image">';
                        altHtml += '<img src="' + escapeHtml(altFinalImageUrl) + '" alt="' + escapeHtml(alt.model || '') + '" class="alt-component-image">';
                        altHtml += '</div>';
                        altHtml += '<div class="alt-info">';
                        altHtml += '<div class="alt-brand-model">' + escapeHtml(alt.brand || 'N/A') + ' ' + escapeHtml(alt.model || 'N/A') + '</div>';
                        altHtml += '<div class="alt-type">' + escapeHtml((alt.type || '').toUpperCase()) + '</div>';
                        altHtml += '</div>';
                        altHtml += '<div class="alt-price">';
                        altHtml += '<span class="alt-price-amount">₱' + formatNumber(alt.price || 0, 2) + '</span>';
                        altHtml += '</div>';
                        altHtml += '<div class="alt-actions">';
                        altHtml += '<a href="' + escapeHtml(altSourceUrl) + '" target="_blank" class="btn-view-small">View</a>';
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
                console.error('Error:', error);
                alternativesContent.innerHTML = '<div class="alternatives-error">Error loading alternatives. Please try again.</div>';
            });
        }
    }
    
    // Scroll to show the alternatives row
    setTimeout(() => {
        if (alternativesRow.style.display !== 'none') {
            alternativesRow.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }, 100);
}