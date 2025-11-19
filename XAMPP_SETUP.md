# Running SmartSpecs in XAMPP - Step by Step Guide

## Prerequisites

- XAMPP installed on your computer
- Your project is already in: `C:\xampp\htdocs\portfolio\ai-chatbot\`
- Internet connection (for AivenDB)

## Step 1: Start XAMPP Services

1. **Open XAMPP Control Panel**
   - Find "XAMPP Control Panel" in your Start Menu
   - Or navigate to: `C:\xampp\xampp-control.exe`

2. **Start Apache**
   - Click the **"Start"** button next to Apache
   - Wait until it shows "Running" (green background)
   - The port should show (usually 80 or 8080)

3. **Note about MySQL**
   - You don't need to start MySQL in XAMPP because you're using AivenDB (cloud database)
   - However, if you want to use phpMyAdmin for testing, you can start MySQL too

## Step 2: Set Up Database Tables

Since you're using AivenDB, you need to create the database tables:

### Option A: Using AivenDB Console (Easiest)

1. Go to [Aiven Console](https://console.aiven.io/)
2. Log in to your account
3. Select your **smartspecs** service
4. Click on **"Databases"** or find the **SQL Editor**
5. Open the `database.sql` file from your project
6. Copy all the SQL commands (starting from `CREATE TABLE IF NOT EXISTS users`)
7. Paste into the SQL editor
8. Click **"Execute"** or **"Run"**

### Option B: Using Command Line

1. Open **Command Prompt** or **PowerShell**
2. Navigate to your project directory:
   ```cmd
   cd C:\xampp\htdocs\portfolio\ai-chatbot
   ```
3. Run the MySQL command (if you have MySQL client installed):
   ```cmd
   mysql -h YOUR_DB_HOST -P YOUR_DB_PORT -u YOUR_DB_USER -p --ssl-mode=REQUIRED YOUR_DB_NAME < database.sql
   ```
4. When prompted, enter password

## Step 3: Test Database Connection

1. Open your web browser
2. Go to:
   ```
   http://localhost/portfolio/ai-chatbot/test_connection.php
   ```
3. You should see:
   - ✓ Database connection successful!
   - ✓ All tables are set up correctly!
   - ✓ SSL Connection Active

If you see errors, check:
- Apache is running in XAMPP
- Your internet connection is working
- AivenDB service is active

## Step 4: Access Your Application

1. Open your web browser
2. Navigate to:
   ```
   http://localhost/portfolio/ai-chatbot/
   ```
   or
   ```
   http://localhost/portfolio/ai-chatbot/index.html
   ```

3. You should see the SmartSpecs login page

## Step 5: Test the Application

1. **Register a New User:**
   - Enter an email address
   - Enter a password (minimum 8 characters)
   - The form will automatically register you

2. **Login:**
   - Use the same email and password
   - Click "LOG IN"

3. **Create a Conversation:**
   - Click "New Conversation" in the sidebar
   - Type a message in the input box
   - Click send or press Enter

## Troubleshooting

### Problem: "This site can't be reached" or "Connection refused"

**Solution:**
- Make sure Apache is running in XAMPP Control Panel
- Check if port 80 is being used by another application
- Try: `http://localhost:8080/portfolio/ai-chatbot/` (if Apache is on port 8080)

### Problem: Database connection failed

**Solution:**
- Check your internet connection
- Verify AivenDB service is running in Aiven console
- Check PHP error logs: `C:\xampp\apache\logs\error.log`
- Make sure OpenSSL is enabled in PHP (usually enabled by default)

### Problem: "404 Not Found" for API endpoints

**Solution:**
- Make sure `.htaccess` file exists in your project root
- Check if `mod_rewrite` is enabled in Apache
- In XAMPP, `mod_rewrite` is usually enabled by default

### Problem: Tables don't exist

**Solution:**
- Go back to Step 2 and run the `database.sql` script
- Check the test connection page to see which tables are missing

### Problem: PHP errors appear

**Solution:**
- Check PHP error logs: `C:\xampp\php\logs\php_error_log`
- Make sure PHP version is 7.4 or higher
- Verify all required PHP extensions are enabled (mysqli, openssl)

## Quick Reference URLs

- **Application:** `http://localhost/portfolio/ai-chatbot/`
- **Test Connection:** `http://localhost/portfolio/ai-chatbot/test_connection.php`
- **XAMPP Dashboard:** `http://localhost/dashboard/`
- **phpMyAdmin:** `http://localhost/phpmyadmin/` (if MySQL is started)

## File Locations

- **Project Root:** `C:\xampp\htdocs\portfolio\ai-chatbot\`
- **Apache Logs:** `C:\xampp\apache\logs\`
- **PHP Logs:** `C:\xampp\php\logs\`
- **XAMPP Config:** `C:\xampp\apache\conf\httpd.conf`

## Next Steps

Once everything is working:
1. Test user registration and login
2. Create some conversation threads
3. Test sending messages
4. Try the password reset feature
5. Test night mode toggle

## Stopping XAMPP

When you're done:
1. Open XAMPP Control Panel
2. Click **"Stop"** next to Apache
3. (Optional) Stop MySQL if it's running

---

**Note:** Your project is already in the correct location (`htdocs`), so you don't need to move any files!

