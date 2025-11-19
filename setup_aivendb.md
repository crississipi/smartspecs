# Setting Up AivenDB for SmartSpecs

## Database Configuration

Your AivenDB credentials have been configured in `config.php`. The connection uses SSL as required by AivenDB.

## Setting Up the Database Tables

Since AivenDB already has the `defaultdb` database created, you need to:

### Option 1: Using AivenDB Console (Recommended)

1. Go to your AivenDB console
2. Navigate to your service
3. Click on "Databases" or use the SQL editor
4. Copy and paste the contents of `database.sql` (excluding the CREATE DATABASE and USE statements)
5. Execute the SQL script

### Option 2: Using MySQL Command Line

If you have MySQL client installed and can connect to AivenDB:

```bash
mysql -h YOUR_DB_HOST -P YOUR_DB_PORT -u YOUR_DB_USER -p --ssl-mode=REQUIRED YOUR_DB_NAME < database.sql
```

When prompted, enter the password

### Option 3: Using phpMyAdmin or MySQL Workbench

1. Connect to your AivenDB instance:
   - Host: `YOUR_DB_HOST`
   - Port: `YOUR_DB_PORT`
   - Username: `YOUR_DB_USER`
   - Password: `YOUR_DB_PASS`
   - Database: `YOUR_DB_NAME`
   - Enable SSL

2. Import the `database.sql` file (you may need to remove the CREATE DATABASE and USE statements first)

## Verifying the Connection

You can test the connection by:

1. Accessing your application at `http://localhost/portfolio/ai-chatbot/`
2. Try to register a new user
3. Check the browser console and PHP error logs for any connection issues

## Troubleshooting

### SSL Connection Issues

If you encounter SSL connection errors:

1. Make sure your PHP installation has OpenSSL enabled
2. Check PHP error logs for detailed error messages
3. Verify that the AivenDB service is running and accessible

### Connection Timeout

If the connection times out:

1. Check your firewall settings
2. Verify that port 11634 is not blocked
3. Ensure your IP address is whitelisted in AivenDB (if IP restrictions are enabled)

## Security Notes

⚠️ **Important**: The database password is currently stored in plain text in `config.php`. For production:

1. Move credentials to environment variables
2. Use a `.env` file (not committed to version control)
3. Consider using AivenDB's connection pooling for better performance

## Next Steps

After setting up the database:

1. Test user registration
2. Test login functionality
3. Create a test conversation thread
4. Verify that messages are being saved correctly

