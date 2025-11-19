# SmartSpecs - AI Chatbot Backend

A PHP backend for the SmartSpecs AI chatbot application that helps users choose computer specifications.

## Features

- User authentication (login, register, logout)
- Password reset with OTP verification
- Thread/conversation management
- Message storage and retrieval
- User preferences (night mode)
- Session management

## Setup Instructions

### 1. Database Setup

Since you're using AivenDB, you need to set up the database tables:

**Option 1: Using AivenDB Console (Recommended)**
1. Go to your AivenDB console
2. Navigate to your service and open the SQL editor
3. Copy and paste the contents of `database.sql`
4. Execute the SQL script

**Option 2: Using MySQL Command Line**
```bash
mysql -h YOUR_DB_HOST -P YOUR_DB_PORT -u YOUR_DB_USER -p --ssl-mode=REQUIRED YOUR_DB_NAME < database.sql
```
When prompted, enter password

**Option 3: Using MySQL Client (phpMyAdmin/MySQL Workbench)**
1. Connect to AivenDB with SSL enabled
2. Import the `database.sql` file

See `setup_aivendb.md` for detailed instructions.

### 2. Database Configuration

The application uses environment variables for database configuration. Set up your `.env` file:

```bash
# Copy .env.example to .env and fill in your values
cp .env.example .env
```

Required environment variables:
- `DB_HOST` - Database hostname
- `DB_PORT` - Database port
- `DB_USER` - Database username  
- `DB_PASS` - Database password
- `DB_NAME` - Database name
- `DB_SSL` - SSL enabled (true/false)

**Note**: For AivenDB, SSL is required (`DB_SSL=true`).

To test the connection, visit: `http://localhost/portfolio/ai-chatbot/test_connection.php`

### 3. File Structure

Your project structure should look like this:
```
ai-chatbot/
├── api/
│   ├── auth.php
│   ├── messages.php
│   ├── threads.php
│   └── user.php
├── assets/
├── config.php
├── database.sql
├── index.html
├── script.js
└── styles.css
```

### 4. Access the Application

1. Make sure your files are in the XAMPP `htdocs` directory:
   - Windows: `C:\xampp\htdocs\portfolio\ai-chatbot`
   - Or configure your web server to point to this directory

2. Access the application at:
   ```
   http://localhost/portfolio/ai-chatbot/
   ```

## API Endpoints

### Authentication (`/api/auth.php`)

- `POST /api/auth.php?action=register` - Register new user
- `POST /api/auth.php?action=login` - Login user
- `POST /api/auth.php?action=logout` - Logout user
- `GET /api/auth.php?action=check` - Check authentication status
- `POST /api/auth.php?action=forgot-password` - Request password reset OTP
- `POST /api/auth.php?action=verify-otp` - Verify OTP
- `POST /api/auth.php?action=reset-password` - Reset password

### Threads (`/api/threads.php`)

- `GET /api/threads.php` - Get all threads for current user
- `GET /api/threads.php?id={id}` - Get specific thread with messages
- `POST /api/threads.php` - Create new thread
- `PUT /api/threads.php?id={id}` - Update thread title
- `DELETE /api/threads.php?id={id}` - Delete thread

### Messages (`/api/messages.php`)

- `POST /api/messages.php` - Send a message (creates thread if needed)

### User (`/api/user.php`)

- `GET /api/user.php` - Get user information and preferences
- `PUT /api/user.php` - Update user preferences

## Security Notes

1. **OTP in Development**: The OTP is currently returned in the API response for development. Remove this in production and implement actual email sending.

2. **Password Hashing**: Passwords are hashed using PHP's `password_hash()` with bcrypt.

3. **Session Security**: Sessions are configured with httpOnly cookies. Enable secure cookies in production when using HTTPS.

4. **CORS**: CORS headers are set to allow all origins. Restrict this in production.

## AI Integration

The `generateAIResponse()` function in `api/messages.php` currently returns placeholder responses. To integrate with an AI service:

1. Add your AI API credentials to `config.php`
2. Update the `generateAIResponse()` function in `api/messages.php` to call your AI service (OpenAI, Anthropic, etc.)

Example:
```php
function generateAIResponse($userMessage) {
    // Call OpenAI API
    $apiKey = 'your-api-key';
    // ... implementation
}
```

## Troubleshooting

1. **Database Connection Error**: 
   - Check if MySQL is running in XAMPP
   - Verify database credentials in `config.php`
   - Ensure database `smartspecs` exists

2. **Session Issues**:
   - Check PHP session configuration
   - Ensure cookies are enabled in your browser

3. **API Not Working**:
   - Check browser console for errors
   - Verify API endpoints are accessible
   - Check PHP error logs in XAMPP

## Next Steps

1. Implement actual email sending for OTP
2. Integrate with an AI service (OpenAI, Anthropic, etc.)
3. Add input validation and sanitization
4. Implement rate limiting
5. Add error logging
6. Set up HTTPS in production

