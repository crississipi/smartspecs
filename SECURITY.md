# Security Guide

This document outlines security best practices for deploying and running this application.

## Environment Variables

**CRITICAL: Never commit secrets to version control!**

All sensitive credentials must be set via environment variables:

### Required Environment Variables

- `DB_HOST` - Database hostname
- `DB_PORT` - Database port (3306 for MySQL, 5432 for PostgreSQL, 11634 for AivenDB MySQL)
- `DB_USER` - Database username
- `DB_PASS` - Database password
- `DB_NAME` - Database name
- `DB_SSL` - SSL enabled (true/false)
- `GOOGLE_API_KEY` - Google Custom Search API key
- `GOOGLE_CSE_ID` - Google Custom Search Engine ID
- `HF_API_KEY` - Hugging Face API key for AI models

### Optional Environment Variables

- `PORT` - Python service port (default: 5000)
- `DEBUG` - Debug mode (default: false)
- `MODEL_NAME` - AI model name (default: microsoft/DialoGPT-medium)
- `FRONTEND_URL` - Frontend URL (auto-detected)
- `PYTHON_SERVICE_URL` - Python service URL (auto-configured)

## Setting Environment Variables

### Local Development

1. Copy `.env.example` to `.env`
2. Fill in your actual values in `.env`
3. The application will automatically load from `.env` via dotenv

### Replit Deployment

1. Go to your Repl → **Secrets** tab (lock icon)
2. Add all required environment variables
3. Never paste credentials in code comments or documentation

### Other Platforms

Set environment variables according to your platform's documentation:
- **Heroku**: `heroku config:set KEY=value`
- **Railway**: Environment tab in dashboard
- **DigitalOcean**: App settings → Environment Variables

## Security Checklist

Before deploying:

- [ ] All hardcoded credentials removed from code
- [ ] `.env` file added to `.gitignore`
- [ ] Log files excluded from git (already in `.gitignore`)
- [ ] API keys stored in environment variables only
- [ ] Database credentials never committed
- [ ] Secrets never appear in error messages
- [ ] HTTPS enabled in production
- [ ] CORS properly configured
- [ ] Session security enabled (httponly, secure cookies)

## Files That Must Never Contain Secrets

- Any `.php` files (except loading from env)
- Any `.py` files (except loading from env)
- Configuration files (`.yaml`, `.json`, `.ini`)
- Documentation files (`.md`)
- Script files
- Commit history (use git filter-branch if needed)

## Rotating Secrets

If you suspect a secret has been exposed:

1. **Immediately rotate the exposed secret** in your service provider
2. Update the secret in your environment variables
3. Check git history for exposed secrets (see below)
4. Consider using git filter-branch to remove from history

## Cleaning Git History

If you've accidentally committed secrets:

```bash
# Use git filter-branch or BFG Repo Cleaner
git filter-branch --force --index-filter \
  "git rm --cached --ignore-unmatch path/to/file" \
  --prune-empty --tag-name-filter cat -- --all

# Force push (WARNING: Rewrites history)
git push origin --force --all
```

**Note**: This rewrites history. Coordinate with your team if working collaboratively.

## Log Files

Log files may contain sensitive information:
- User emails
- Database queries
- Error messages with credentials

All log files are excluded from git via `.gitignore`:
- `*.log`
- `php-error.log`
- `ai_assistant.log`
- `php-server.log`
- `python-server.log`

## Database Security

- Always use SSL connections in production (`DB_SSL=true`)
- Use strong, unique passwords
- Restrict database access to application servers only
- Regularly update database credentials
- Monitor database access logs

## API Key Security

- Rotate API keys regularly
- Use least-privilege access (minimum required permissions)
- Monitor API usage for suspicious activity
- Never expose API keys in:
  - Client-side JavaScript
  - Public repositories
  - Log messages
  - Error messages
  - Screenshots or documentation

## Reporting Security Issues

If you discover a security vulnerability:

1. **Do not** open a public issue
2. Contact the repository maintainer privately
3. Provide details of the vulnerability
4. Wait for confirmation before disclosing publicly

## Additional Resources

- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [GitHub Security Best Practices](https://docs.github.com/en/code-security)
- [Replit Security Documentation](https://docs.replit.com/security)

