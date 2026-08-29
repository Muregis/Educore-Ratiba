# Render Deployment Guide (Docker)

This guide covers deploying EduCore Ratiba to Render.com using Docker.

## Prerequisites

- GitHub repository with this code
- Supabase project (PostgreSQL)
- Render account (sign up at render.com with GitHub)

## Step 1: Push Clean Code to GitHub

```bash
# Ensure .env is not tracked
git rm --cached .env
echo ".env" >> .gitignore
git add .gitignore
git commit -m "Remove .env from version control"
git push origin main --force
```

## Step 2: Rotate Exposed Credentials

1. Go to your Supabase dashboard → Settings → Database
2. Click **Reset database password**
3. Save the new password securely
4. Update your local `.env` with the new password

## Step 3: Set Up Render Web Service (Docker)

1. Go to [render.com](https://render.com) and sign in with GitHub
2. Click **New +** → **Web Service**
3. Connect your GitHub repository
4. Configure:
   - **Name**: `edu-timetable`
   - **Region**: Choose closest to your users
   - **Branch**: `main`
   - **Runtime**: **Docker** (not PHP)
   - Render will auto-detect the `Dockerfile` and `render.yaml`
5. Click **Create Web Service**

## Step 4: Add Persistent Disk

1. In your service dashboard, go to **Disks**
2. Click **Add Disk**
   - **Name**: `edu-timetable-data`
   - **Mount Path**: `/app`
   - **Size**: 1 GB
3. Save

## Step 5: Set Environment Variables

In Render dashboard → your service → **Environment**:

| Key | Value |
|-----|-------|
| `APP_ENV` | `online` |
| `DB_DRIVER` | `pgsql` |
| `DB_HOST` | Your Supabase host |
| `DB_PORT` | `5432` |
| `DB_NAME` | `postgres` |
| `DB_USER` | `postgres` |
| `DB_PASS` | Your Supabase password |
| `FET_ENGINE_PATH` | `/app/engine/fet-cl` |
| `OUTPUT_DIR` | `/app/output` |
| `SSO_SHARED_SECRET` | (optional, 64-char hex) |
| `CENTRAL_SERVER_URL` | (optional) |

Mark sensitive values as **Secret** in Render.

## Step 6: Set Up Database Schema

1. Go to Supabase dashboard → **SQL Editor**
2. Run the contents of `db/schema_postgres.sql`
3. Verify tables in **Table Editor**

## Step 7: Create Initial Admin

Run this locally to generate a password hash:
```bash
php -r "echo password_hash('your-strong-password', PASSWORD_DEFAULT), PHP_EOL;"
```

Then run in Supabase SQL Editor:
```sql
INSERT INTO super_admins (username, password_hash)
VALUES ('admin', 'paste_hash_here');
```

## Step 8: Deploy

1. Push any final changes to GitHub
2. Render automatically builds the Docker image and deploys
3. Build takes 3-5 minutes on first deploy
4. Visit `https://edu-timetable.onrender.com` (or your custom domain)

## Step 9: Add Custom Domain (Optional)

1. In Render dashboard → **Settings** → **Custom Domains**
2. Add your domain (e.g., `timetable.yourschool.com`)
3. Update DNS records as instructed by Render
4. SSL certificate is provisioned automatically

## Local Testing with Docker

```bash
# Build and start
docker-compose up --build

# App will be available at http://localhost:8080
```

## Troubleshooting

### Docker Build Fails
- Check Render build logs for specific errors
- Verify `Dockerfile` syntax
- Ensure all files are committed to GitHub

### FET Engine Not Found
- Check Render build logs for download errors
- Verify the FET download URL is correct
- Ensure `engine/fet-cl` exists in the deployed filesystem

### Database Connection Errors
- Verify Supabase credentials in Render environment variables
- Check Supabase project is active (not paused)
- Test connection locally first

### Timetable Generation Hangs
- Render Standard plan ($25/mo) recommended for generation
- Free tier has limited CPU and may timeout on large schools

## Monitoring

- View logs in Render dashboard → **Logs**
- Set up UptimeRobot for external monitoring
- Monitor Supabase dashboard for database metrics

## Costs

| Component | Cost |
|-----------|------|
| Render Starter (512MB RAM) | $7/month |
| Render Standard (2GB RAM) | $25/month |
| Supabase Free (500MB) | $0/month |
| Supabase Pro (8GB) | $25/month |
| Domain | ~$12/year |

Total: $7-50/month depending on plan.
