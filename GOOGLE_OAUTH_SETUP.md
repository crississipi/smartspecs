# Google OAuth Setup Guide

This guide explains how to implement Google authentication for your SmartSpecs application.

## Prerequisites

1. A Google Cloud Platform (GCP) account
2. Access to Google Cloud Console

## Step 1: Create OAuth 2.0 Credentials

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project or select an existing one
3. Navigate to **APIs & Services** > **Credentials**
4. Click **Create Credentials** > **OAuth client ID**
5. If prompted, configure the OAuth consent screen:
   - Choose **External** (unless you have a Google Workspace)
   - Fill in the required information (App name, User support email, etc.)
   - Add your email to test users
   - Save and continue through the scopes and test users screens
6. For the OAuth client:
   - Application type: **Web application**
   - Name: **SmartSpecs Web Client**
   - Authorized JavaScript origins:
     - `http://localhost` (for development)
     - `http://localhost/portfolio/ai-chatbot` (for your XAMPP setup)
     - Your production domain (when deployed)
   - Authorized redirect URIs:
     - `http://localhost/portfolio/ai-chatbot/` (for development)
     - Your production callback URL (when deployed)
7. Click **Create**
8. Copy your **Client ID** (you'll need this)

## Step 2: Update Your HTML

Add the Google Identity Services script to your `index.html` in the `<head>` section:

```html
<script src="https://accounts.google.com/gsi/client" async defer></script>
```

## Step 3: Update Your JavaScript

Replace the placeholder `handleGoogleAuth` function in `script.js` with this implementation:

```javascript
async function handleGoogleAuth(isSignup = false) {
  // Your Google Client ID from Step 1
  const GOOGLE_CLIENT_ID = 'YOUR_CLIENT_ID_HERE';
  
  // Load Google Identity Services if not already loaded
  if (typeof google === 'undefined') {
    const script = document.createElement('script');
    script.src = 'https://accounts.google.com/gsi/client';
    script.async = true;
    script.defer = true;
    document.head.appendChild(script);
    
    script.onload = () => {
      initializeGoogleAuth(GOOGLE_CLIENT_ID, isSignup);
    };
  } else {
    initializeGoogleAuth(GOOGLE_CLIENT_ID, isSignup);
  }
  
  function initializeGoogleAuth(clientId, isSignup) {
    google.accounts.id.initialize({
      client_id: clientId,
      callback: handleGoogleCallback,
      context: isSignup ? 'signup' : 'login'
    });
    
    // Show the Google One Tap prompt
    google.accounts.id.prompt((notification) => {
      if (notification.isNotDisplayed() || notification.isSkippedMoment()) {
        // Fallback: Show a button to trigger the popup
        google.accounts.oauth2.initTokenClient({
          client_id: clientId,
          scope: 'email profile',
          callback: handleGoogleTokenResponse,
        }).requestAccessToken();
      }
    });
  }
  
  async function handleGoogleCallback(response) {
    // Send the credential to your backend
    const result = await apiCall('/auth.php?action=google-auth', 'POST', {
      credential: response.credential
    });
    
    if (result.success) {
      loginPage.classList.add('hidden');
      if (mainContent) mainContent.classList.remove('hidden');
      notification("Logged in successfully with Google.", "success");
      loadUserInfo();
      loadThreads();
    } else {
      notification(result.message || "Google authentication failed", "alert");
    }
  }
  
  function handleGoogleTokenResponse(tokenResponse) {
    // Alternative method using OAuth 2.0 token
    // You can decode the token or send it to your backend
    fetch('https://www.googleapis.com/oauth2/v2/userinfo', {
      headers: {
        'Authorization': `Bearer ${tokenResponse.access_token}`
      }
    })
    .then(response => response.json())
    .then(async (userInfo) => {
      const result = await apiCall('/auth.php?action=google-auth', 'POST', {
        email: userInfo.email,
        name: userInfo.name,
        picture: userInfo.picture
      });
      
      if (result.success) {
        loginPage.classList.add('hidden');
        if (mainContent) mainContent.classList.remove('hidden');
        notification("Logged in successfully with Google.", "success");
        loadUserInfo();
        loadThreads();
      } else {
        notification(result.message || "Google authentication failed", "alert");
      }
    });
  }
}
```

## Step 4: Create Backend Endpoint

Add Google authentication handling to `api/auth.php`:

```php
case 'google-auth':
    handleGoogleAuth($conn, $data);
    break;

// Add this function to auth.php
function handleGoogleAuth($conn, $data) {
    // Option 1: Verify JWT token from Google
    if (isset($data['credential'])) {
        $credential = $data['credential'];
        // Verify and decode the JWT token
        // Use a JWT library like firebase/php-jwt
        
        // Extract user info from token
        // $email = $decoded->email;
        // $name = $decoded->name;
    }
    
    // Option 2: Use OAuth token
    if (isset($data['email'])) {
        $email = trim($data['email']);
        $name = $data['name'] ?? '';
        $picture = $data['picture'] ?? '';
        
        // Check if user exists
        $stmt = $conn->prepare("SELECT id, email FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            // Create new user (no password for Google users)
            $stmt = $conn->prepare("INSERT INTO users (email, name, password) VALUES (?, ?, '')");
            $stmt->bind_param("ss", $email, $name);
            $stmt->execute();
            $userId = $conn->insert_id;
        } else {
            $user = $result->fetch_assoc();
            $userId = $user['id'];
        }
        
        // Set session
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_email'] = $email;
        
        sendJSON([
            'success' => true,
            'message' => 'Google authentication successful',
            'user' => [
                'id' => $userId,
                'email' => $email
            ]
        ]);
    } else {
        sendJSON(['success' => false, 'message' => 'Invalid Google authentication data'], 400);
    }
}
```

## Step 5: Update Database (Optional)

If you want to track Google-authenticated users separately, you can add a column to your users table:

```sql
ALTER TABLE users ADD COLUMN auth_provider VARCHAR(50) DEFAULT 'email';
ALTER TABLE users ADD COLUMN google_id VARCHAR(255) NULL;
```

## Testing

1. Make sure your Client ID is correctly set in the JavaScript
2. Test on `http://localhost/portfolio/ai-chatbot/`
3. Click the "Google Account" button
4. You should see the Google sign-in popup
5. After signing in, you should be logged into your application

## Troubleshooting

### "Error 400: redirect_uri_mismatch"
- Make sure your redirect URI in Google Console matches exactly (including trailing slashes)
- Check both JavaScript origins and redirect URIs

### "This app isn't verified"
- This is normal for development
- Click "Advanced" > "Go to [Your App] (unsafe)" to continue testing
- For production, you'll need to verify your app with Google

### Token verification fails
- Make sure you're using the correct Client ID
- Check that the JWT library is properly installed (if using credential method)

## Security Notes

1. **Never expose your Client Secret** in frontend code
2. Always verify tokens on the backend
3. Use HTTPS in production
4. Implement proper session management
5. Consider rate limiting for authentication endpoints

## Additional Resources

- [Google Identity Services Documentation](https://developers.google.com/identity/gsi/web)
- [Google OAuth 2.0 Guide](https://developers.google.com/identity/protocols/oauth2)
- [JWT Verification](https://developers.google.com/identity/gsi/web/guides/verify-google-id-token)

