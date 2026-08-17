# Supabase Setup Guide

This guide covers setting up EduCore Ratiba with Supabase as the database backend.

## Why Supabase?
- **Free tier** available for small projects
- **PostgreSQL** database with full SQL support
- **Built-in authentication** (optional, can use your own)
- **Real-time subscriptions** (for future features)
- **Easy deployment** and management
- **Automatic backups** and scaling

## Step 1: Create Supabase Project

1. Go to [supabase.com](https://supabase.com) and sign up/login
2. Click "New Project"
3. Fill in project details:
   - **Name**: `edu-timetable` (or your preferred name)
   - **Database Password**: Generate a strong password (save this!)
   - **Region**: Choose closest to your users
4. Wait for project to be created (2-3 minutes)

## Step 2: Get Database Credentials

1. Go to your project dashboard
2. Navigate to **Settings** → **Database**
3. Copy the following:
   - **Host**: `db.[project-id].supabase.co`
   - **Port**: `5432`
   - **Database name**: `postgres`
   - **Username**: `postgres`
   - **Password**: (the one you set during creation)

## Step 3: Set Up Database Schema

### Option A: Using Supabase SQL Editor (Recommended)
1. Go to **SQL Editor** in Supabase dashboard
2. Click "New Query"
3. Copy the contents of `db/schema_postgres.sql`
4. Paste and run the query
5. Verify all tables are created in **Table Editor**

### Option B: Using psql Command Line
```bash
psql -h db.[project-id].supabase.co -p 5432 -U postgres -d postgres < db/schema_postgres.sql
```

## Step 4: Configure Environment Variables

Create a `.env` file in your project root:

```bash
cp .env.example .env
```

Edit `.env` with your Supabase credentials:

```env
# Application Environment
APP_ENV=online

# Database Configuration (Supabase)
DB_DRIVER=pgsql
DB_HOST=db.your-project-id.supabase.co
DB_PORT=5432
DB_NAME=postgres
DB_USER=postgres
DB_PASS=your_supabase_password

# FET Engine Configuration
FET_ENGINE_PATH=/path/to/fet-cl
OUTPUT_DIR=/path/to/output/directory

# Security
SSO_SHARED_SECRET=your_sso_secret_key
CENTRAL_SERVER_URL=https://your-school-system.com
LOCAL_SYNC_TOKEN=your_local_sync_token

# Sync Configuration
AUTO_SYNC=true
SYNC_INTERVAL=3600
```

## Step 5: Enable Required Extensions

Supabase includes most PostgreSQL extensions by default, but verify:

1. Go to **Database** → **Extensions** in Supabase dashboard
2. Ensure these are enabled:
   - `uuid-ossp` (for UUID generation)
   - `pgcrypto` (for encryption functions)

## Step 6: Configure Row Level Security (Optional but Recommended)

For production, enable RLS to protect your data:

```sql
-- Enable RLS on all tables
ALTER TABLE schools ENABLE ROW LEVEL SECURITY;
ALTER TABLE school_admins ENABLE ROW LEVEL SECURITY;
-- ... enable for other tables

-- Example policy for schools table
CREATE POLICY "Users can view their own school" 
ON schools FOR SELECT 
USING (true);

-- Add similar policies for other tables as needed
```

## Step 7: Create Initial Admin User

Run this in Supabase SQL Editor:

```sql
-- Generate password hash first using PHP:
-- php -r "echo password_hash('your_chosen_password', PASSWORD_DEFAULT);"

INSERT INTO super_admins (username, password_hash)
VALUES ('admin', 'paste_hashed_password_here');
```

## Step 8: Test Connection

Create a test file `test_supabase.php`:

```php
<?php
require_once __DIR__ . '/db/db.php';

try {
    $pdo = db();
    echo "✓ Connected to Supabase successfully!\n";
    
    // Test query
    $stmt = $pdo->query("SELECT version()");
    echo "PostgreSQL Version: " . $stmt->fetchColumn() . "\n";
    
    // Check tables
    $stmt = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'");
    echo "\nTables created:\n";
    while ($row = $stmt->fetch()) {
        echo "  - " . $row['table_name'] . "\n";
    }
    
} catch (Exception $e) {
    echo "✗ Connection failed: " . $e->getMessage() . "\n";
}
```

Run it: `php test_supabase.php`

## Step 9: Deploy Your Application

### For Online Deployment:
1. Upload your code to your web server
2. Ensure `.env` is configured with Supabase credentials
3. Set proper file permissions
4. Access via your domain

### For Local Development:
1. Keep using your local setup
2. Switch to Supabase by updating `.env`
3. Test with `php test_supabase.php`

## Step 10: Monitor and Maintain

### Supabase Dashboard Features:
- **Database Monitoring**: View query performance, connections
- **Logs**: Check database logs for errors
- **Backups**: Automatic daily backups (free tier)
- **API Logs**: Monitor API usage if using Supabase API

### Performance Tips:
- Use connection pooling (Supabase provides this)
- Index frequently queried columns
- Monitor database size (free tier: 500MB)
- Optimize queries for PostgreSQL

## Troubleshooting

### Connection Issues:
- **SSL Error**: Add `sslmode=require` to connection string
- **Timeout**: Check network connectivity to Supabase
- **Auth Failed**: Verify credentials in `.env`

### Schema Issues:
- **Table not found**: Run schema setup again
- **Permission denied**: Check RLS policies
- **Type mismatch**: Ensure using PostgreSQL types

### Performance Issues:
- **Slow queries**: Use EXPLAIN ANALYZE in Supabase SQL Editor
- **Connection limits**: Free tier allows 60 connections
- **Size limits**: Monitor database size in dashboard

## Migration from MySQL

If migrating from existing MySQL database:

1. Export MySQL data: `mysqldump -u root -p fet_timetable > backup.sql`
2. Convert data types (MySQL → PostgreSQL):
   - `TINYINT` → `SMALLINT`
   - `ENUM` → `VARCHAR` + CHECK constraint
   - `DATETIME` → `TIMESTAMP`
   - `AUTO_INCREMENT` → `SERIAL`
3. Import to Supabase using converted schema
4. Verify data integrity

## Security Best Practices

1. **Never commit `.env`** to version control
2. **Use strong passwords** for database
3. **Enable RLS policies** for production
4. **Rotate passwords** regularly
5. **Use SSL connections** (enabled by default in Supabase)
6. **Monitor access logs** in Supabase dashboard
7. **Limit database user permissions** if possible

## Cost Considerations

### Supabase Free Tier:
- 500MB database storage
- 1GB bandwidth per month
- 60 concurrent database connections
- 2 API requests per second
- Daily backups (7-day retention)

### When to Upgrade:
- More than 60 concurrent connections needed
- Database exceeds 500MB
- Higher API rate limits needed
- Need longer backup retention

## Next Steps

After Supabase setup:
1. Test all application features
2. Set up monitoring alerts
3. Configure backup strategy (Supabase handles this)
4. Plan for scaling if needed
5. Document your Supabase configuration

## Support Resources

- [Supabase Documentation](https://supabase.com/docs)
- [PostgreSQL Documentation](https://www.postgresql.org/docs/)
- [Supabase Discord Community](https://supabase.com/discord)
- Application-specific: Check `DEPLOYMENT.md` for general deployment issues
