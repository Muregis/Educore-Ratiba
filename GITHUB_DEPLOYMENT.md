# GitHub Deployment Guide

This guide covers deploying EduCore Ratiba to GitHub for testing before choosing a production frontend.

## Quick Start Options

### Option 1: Render (Recommended for PHP)
- **Free tier** available
- **Native PHP support**
- **Easy GitHub integration**
- **Automatic deployments**

### Option 2: Heroku
- **Free tier** (limited)
- **PHP support** with buildpacks
- **Add-ons** for databases

### Option 3: DigitalOcean App Platform
- **Free trial** available
- **Good performance**
- **Flexible scaling**

## Setup for Render (Recommended)

### Step 1: Prepare GitHub Repository

1. **Initialize Git** (if not already done):
```bash
cd "c:\laragon\www\fet-timetable\Educore Ratiba"
git init
git add .
git commit -m "Initial commit with Supabase integration"
```

2. **Create GitHub Repository**:
   - Go to [github.com](https://github.com)
   - Click "New Repository"
   - Name: `edu-timetable`
   - Make it private or public as needed
   - Don't initialize with README (you already have one)

3. **Push to GitHub**:
```bash
git remote add origin https://github.com/YOUR_USERNAME/edu-timetable.git
git branch -M main
git push -u origin main
```

### Step 2: Set Up Render Account

1. Go to [render.com](https://render.com)
2. Sign up/login with GitHub
3. Authorize Render to access your repositories

### Step 3: Create Web Service

1. Click "New +" → "Web Service"
2. Connect your `edu-timetable` repository
3. Configure:
   - **Name**: `edu-timetable`
   - **Region**: Choose closest to your users
   - **Branch**: `main`
   - **Runtime**: `PHP`
   - **Build Command**: `composer install --no-dev --optimize-autoloader`
   - **Start Command**: `php -S 0.0.0.0:10000 -t .`

### Step 4: Configure Environment Variables

In Render Dashboard → your service → Environment:

```
APP_ENV=online
DB_DRIVER=pgsql
DB_HOST=db.wofnvdfnsuevnebpkdsw.supabase.co
DB_PORT=5432
DB_NAME=postgres
DB_USER=postgres
DB_PASS=Muregiv13922
SSO_SHARED_SECRET=your_sso_secret_key
CENTRAL_SERVER_URL=https://your-school-system.com
FET_ENGINE_PATH=/opt/fet/fet-cl
OUTPUT_DIR=/var/data/output
```

### Step 5: Deploy

1. Click "Create Web Service"
2. Render will automatically deploy on push to main
3. Wait for deployment to complete (2-3 minutes)
4. Access your app at `https://edu-timetable.onrender.com`

## Setup for Heroku

### Step 1: Install Heroku CLI
```bash
# Download from https://devcenter.heroku.com/articles/heroku-cli
```

### Step 2: Create Heroku App
```bash
heroku create edu-timetable
```

### Step 3: Configure Environment Variables
```bash
heroku config:set APP_ENV=online
heroku config:set DB_DRIVER=pgsql
heroku config:set DB_HOST=db.wofnvdfnsuevnebpkdsw.supabase.co
heroku config:set DB_PORT=5432
heroku config:set DB_NAME=postgres
heroku config:set DB_USER=postgres
heroku config:set DB_PASS=Muregiv13922
heroku config:set SSO_SHARED_SECRET=your_sso_secret_key
heroku config:set CENTRAL_SERVER_URL=https://your-school-system.com
```

### Step 4: Deploy
```bash
git push heroku main
```

## Setup for GitHub Actions (CI/CD)

### Step 1: Add Secrets to GitHub

Go to your repository → Settings → Secrets and variables → Actions:

```
DB_HOST=db.wofnvdfnsuevnebpkdsw.supabase.co
DB_PORT=5432
DB_NAME=postgres
DB_USER=postgres
DB_PASS=Muregiv13922
SSO_SHARED_SECRET=your_sso_secret_key
CENTRAL_SERVER_URL=https://your-school-system.com
RENDER_API_KEY=your_render_api_key
RENDER_SERVICE_ID=your_render_service_id
```

### Step 2: Enable GitHub Actions

The `.github/workflows/deploy.yml` file is already configured. Push to main to trigger deployment.

## Testing Your Deployment

### 1. Check Application Status
Visit your deployed URL and check:
- Login page loads
- No PHP errors
- Database connection works

### 2. Test Database Connection
Create `test_deployment.php`:
```php
<?php
require_once 'db/db.php';
try {
    $pdo = db();
    echo "✓ Database connected";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage();
}
```

### 3. Test Admin Features
- Create a test school
- Add teachers, rooms, classes
- Generate a timetable

## Frontend Testing Options

Since you want to test before choosing a frontend, here are options:

### Option A: Keep Current PHP UI
- The current PHP interface is fully functional
- Test all features with existing UI
- Decide later if you want to replace it

### Option B: Add React/Vue Frontend
- Keep PHP backend as API
- Build separate React/Vue frontend
- Test both simultaneously

### Option C: Use Supabase Auth
- Integrate Supabase authentication
- Build modern frontend with Supabase SDK
- Keep PHP for timetable generation logic

## Monitoring and Logs

### Render Logs:
- Dashboard → your service → Logs
- Real-time log streaming
- Error tracking

 ### GitHub Actions:
- Repository → Actions tab
- View deployment logs
- Debug deployment issues

## Troubleshooting

### Deployment Fails:
- Check build logs in Render/GitHub
- Verify composer.json exists
- Ensure PHP version compatibility

### Database Connection Issues:
- Verify Supabase credentials
- Check if Supabase project is active
- Test connection locally first

### Permission Issues:
- Ensure output directory is writable
- Check file permissions
- Use absolute paths in environment variables

## Cost Comparison

### Render Free Tier:
- 512MB RAM
- 0.1 CPU
- Free SSL certificate
- Automatic deployments

### Heroku Free Tier:
- 512MB RAM
- Sleeps after 30 minutes inactivity
- Limited add-ons

### DigitalOcean Free Trial:
- $200 credit for 60 days
- More resources
- Better performance

## Next Steps

1. **Deploy to Render** (easiest for PHP)
2. **Test all features** with current UI
3. **Evaluate frontend options**:
   - Keep PHP UI
   - Build React/Vue frontend
   - Use Supabase Auth + custom frontend
4. **Scale up** based on needs

## Security Considerations

1. **Never commit** `.env` file
2. **Use GitHub Secrets** for sensitive data
3. **Enable HTTPS** (automatic on Render)
4. **Monitor access logs**
5. **Keep dependencies updated**

## Performance Optimization

1. **Enable caching** for generated timetables
2. **Use CDN** for static assets
3. **Optimize database queries**
4. **Implement rate limiting**
5. **Monitor resource usage**

## Support Resources

- [Render Documentation](https://render.com/docs)
- [Heroku PHP Guide](https://devcenter.heroku.com/articles/getting-started-with-php)
- [GitHub Actions Docs](https://docs.github.com/en/actions)
- [Supabase Docs](https://supabase.com/docs)
