# Hybrid Deployment Guide

## Overview
This guide covers deploying EduCore Ratiba for both online and offline environments. The system automatically detects and configures for:
- **Online**: Connected to your school management system via web server
- **Offline**: Local installation with optional sync to central server

## Prerequisites
- PHP 8.0+ with PDO MySQL extension
- MySQL or MariaDB database
- Windows or Linux/Mac server
- FET scheduling engine (appropriate version for your OS)
- Composer (for dependency management)
- Web server (IIS, Apache, or Nginx)

## Step 1: Get FET Engine
Download the appropriate FET engine for your server OS:

1. Download FET from: https://www.lalescu.ro/liviu/fet/download/
2. Extract and get the executable:
   - Windows: `fet-cl.exe`
   - Linux/Mac: `fet-cl`
3. Upload it to your server's `engine/` directory
4. For Linux/Mac, make it executable: `chmod +x engine/fet-cl`

## Step 2: Configure Environment Variables
Create a `.env` file in the project root:

```bash
cp .env.example .env
```

Edit `.env` with your production settings:

```env
# Database Configuration
DB_HOST=your_database_host
DB_NAME=your_database_name
DB_USER=your_database_user
DB_PASS=your_database_password

# FET Engine Configuration
FET_ENGINE_PATH=/full/path/to/engine/fet-cl
OUTPUT_DIR=/full/path/to/output/directory

# Security (if using SSO integration)
SSO_SHARED_SECRET=your_random_secret_key
CENTRAL_SERVER_URL=https://your-school-system.com
LOCAL_SYNC_TOKEN=your_sync_token
```

## Step 3: Set Up Database
Import the database schema:

```bash
mysql -u your_user -p your_db < db/schema.sql
```

## Step 4: Install Dependencies
Run composer to install PHP dependencies:

```bash
composer install --no-dev --optimize-autoloader
```

## Step 5: Set Directory Permissions
Ensure the web server can write to necessary directories:

```bash
chmod -R 755 .
chmod -R 777 output/
chmod -R 777 data/
```

## Step 6: Configure Web Server

### Apache Example
Create a virtual host configuration:

```apache
<VirtualHost *:80>
    ServerName timetable.yourschool.com
    DocumentRoot /var/www/timetable/public
    
    <Directory /var/www/timetable>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog ${APACHE_LOG_DIR}/timetable-error.log
    CustomLog ${APACHE_LOG_DIR}/timetable-access.log combined
</VirtualHost>
```

### Nginx Example
```nginx
server {
    listen 80;
    server_name timetable.yourschool.com;
    root /var/www/timetable;
    index index.php;
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
    
    location ~ /\. {
        deny all;
    }
}
```

## Step 7: Create Admin User
Create your first admin user by running:

```bash
php -r "echo password_hash('your_chosen_password', PASSWORD_DEFAULT);"
```

Then insert into database:
```sql
INSERT INTO super_admins (username, password_hash)
VALUES ('your_username', 'paste_hash_here');
```

## Step 8: Configure HTTPS (Recommended)
Use Let's Encrypt for free SSL:

```bash
sudo certbot --apache -d timetable.yourschool.com
```

## Integration with School Management System

### SSO Integration
If integrating with EduCore or another system via SSO:

1. Set `SSO_SHARED_SECRET` in `.env` to match your school system
2. Configure your school system to redirect to: `https://timetable.yourschool.com/sso.php?token=<jwt>`
3. The SSO endpoint will automatically create schools and users as needed

### Direct Database Integration
For direct integration, you can:
- Use the existing API endpoints
- Create custom middleware in `admin/` directory
- Leverage the existing `sync.php` for data synchronization

## Troubleshooting

### FET Engine Issues
- Ensure `fet-cl` is executable: `chmod +x engine/fet-cl`
- Check that `FET_ENGINE_PATH` in `.env` is correct
- Test manually: `/path/to/fet-cl --help`

### Permission Issues
- Check `output/` directory is writable by web server
- Verify database credentials in `.env`
- Ensure PHP has shell_exec enabled (required for FET)

### Database Connection
- Test connection: `mysql -h DB_HOST -u DB_USER -p DB_NAME`
- Check PDO MySQL extension is installed: `php -m | grep pdo`

## Security Notes
- Never commit `.env` file to version control
- Use strong passwords for database and admin accounts
- Enable HTTPS in production
- Keep PHP and dependencies updated
- Regularly backup database and generated timetables
- Consider implementing rate limiting on login endpoints
