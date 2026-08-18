# Simple Deployment Guide

Deploy EduCore Ratiba as a standalone timetable system that schools access directly.

## Quick Deployment

### Option 1: Render (Free, Recommended)
1. Go to [render.com](https://render.com)
2. Sign up with GitHub
3. Click "New +" → "Web Service"
4. Connect your GitHub repository
5. Configure:
   - **Name**: `edu-timetable`
   - **Runtime**: PHP
   - **Build Command**: `composer install --no-dev`
   - **Start Command**: `php -S 0.0.0.0:10000 -t .`
6. Add environment variables:
   ```
   DB_DRIVER=pgsql
   DB_HOST=db.wofnvdfnsuevnebpkdsw.supabase.co
   DB_PORT=5432
   DB_NAME=postgres
   DB_USER=postgres
   DB_PASS=Muregiv13922
   ```
7. Deploy

### Option 2: Shared Hosting
1. Upload all files to your hosting
2. Create `.env` file with database credentials
3. Import `db/schema_postgres.sql` to Supabase
4. Access via your domain

### Option 3: VPS (DigitalOcean, Linode)
1. Install PHP, Apache/Nginx
2. Upload files
3. Configure virtual host
4. Set up `.env` with database credentials

## Database Setup

1. Go to your Supabase project
2. Navigate to SQL Editor
3. Run `db/schema_postgres.sql`
4. Create first school and admin:

```sql
-- Create a school
INSERT INTO schools (name, deployment_type) 
VALUES ('Demo School', 'server-hosted');

-- Create admin (replace password_hash with actual hash)
INSERT INTO school_admins (school_id, username, password_hash)
VALUES (1, 'admin', '$2y$10$your_hashed_password_here');
```

## How Schools Access

1. **Direct Login**: Schools go to your deployed URL
2. **School Admin Login**: Use username/password created in database
3. **Access Their Data**: System filters by school_id automatically

## School ID System

Each school gets a unique `school_id` in the database:
- All data (teachers, rooms, classes) is scoped to their school_id
- Schools only see their own data
- No cross-school data access

## Environment Variables

Create `.env` file:
```env
APP_ENV=online
DB_DRIVER=pgsql
DB_HOST=db.wofnvdfnsuevnebpkdsw.supabase.co
DB_PORT=5432
DB_NAME=postgres
DB_USER=postgres
DB_PASS=Muregiv13922
FET_ENGINE_PATH=/opt/fet/fet-cl
OUTPUT_DIR=/var/data/output
```

## Testing

1. Deploy to Render
2. Access the URL
3. Login with admin credentials
4. Create school data
5. Generate timetable

## Cost

- **Render Free**: $0/month (512MB RAM)
- **Shared Hosting**: $5-10/month
- **VPS**: $5-20/month

That's it. Simple standalone deployment with no complex integration needed.
