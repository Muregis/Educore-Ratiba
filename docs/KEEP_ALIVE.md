# Keeping Render & Supabase Awake (24/7 Anti-Sleep Guide)

This guide documents how to prevent **Render** (free web service 15-minute idle sleep) and **Supabase** (free PostgreSQL 7-day inactivity pause) from sleeping.

---

## 1. Why They Sleep

| Platform | Tier | Sleep Behavior | Consequence |
|---|---|---|---|
| **Render** | Free Web Service | Spins down after **15 minutes** of no HTTP traffic | Next visitor experiences a 30–60 second cold start delay |
| **Render** | Starter ($7/mo) | **Never sleeps** (24/7 always on) | Instant response |
| **Supabase** | Free Tier | Pauses project after **7 days** of no database queries or API traffic | Database is locked; requires manual unpause in dashboard |
| **Supabase** | Pro ($25/mo) | **Never pauses** | Continuous operation |

---

## 2. The 2-in-1 Solution in EduCore Ratiba

EduCore Ratiba already includes a readiness endpoint designed for this exact purpose:
`GET /health.php?ready=1`

When this endpoint receives a request:
1. **Render is kept awake**: Render receives an incoming HTTP request and resets its 15-minute idle timer.
2. **Supabase is kept awake**: `health.php` executes `$pdo = db(); $pdo->query('SELECT 1');`, performing an active database query that resets Supabase's 7-day inactivity timer.

---

## 3. Recommended Setup (Zero Cost, 100% Reliable)

### Option A: External Uptime Monitor (UptimeRobot) — *Best Practice*
External monitors run 24/7 without consuming GitHub Actions minutes and never pause due to repo inactivity.

1. Create a free account at [UptimeRobot](https://uptimerobot.com) (or [cron-job.org](https://cron-job.org) / [Better Stack](https://betterstack.com)).
2. Click **Add New Monitor**:
   - **Monitor Type**: `HTTP(s)`
   - **Friendly Name**: `EduCore Ratiba Keep-Alive`
   - **URL / IP**: `https://<your-app>.onrender.com/health.php?ready=1`
   - **Monitoring Interval**: `5 minutes` or `10 minutes`
3. Click **Create Monitor**.

> [!TIP]
> Pinging every **5 to 10 minutes** ensures Render never reaches its 15-minute inactivity threshold, and Supabase receives hundreds of queries a day, ensuring neither platform will ever sleep.

---

### Option B: Automated GitHub Actions Keep-Alive
A GitHub Actions workflow is provided in [`.github/workflows/uptime.yml`](../.github/workflows/uptime.yml).

- Runs every **10 minutes** (`cron: '*/10 * * * *'`).
- Pings both `/health.php` (Render liveness) and `/health.php?ready=1` (Supabase readiness).
- Includes 60s timeout handling for cold-starts and retries.

**To configure:**
1. In your GitHub repository, go to **Settings** → **Secrets and variables** → **Actions**.
2. Add secret:
   - `PRODUCTION_URL`: `https://<your-app-name>.onrender.com`
   - *(Optional)* `SUPABASE_URL`: `https://<your-project>.supabase.co`
   - *(Optional)* `SUPABASE_ANON_KEY`: `<your-supabase-anon-key>`

> [!IMPORTANT]
> GitHub automatically disables scheduled workflows if the repository has had no git commits for 60 consecutive days. If your codebase is inactive for long periods, **Option A (UptimeRobot)** is required.

---

### Option C: Supabase Direct Database Heartbeat (`pg_cron`)
As an additional safety net directly within PostgreSQL, Supabase has the `pg_cron` extension.

In the **Supabase SQL Editor**, run:

```sql
-- Enable pg_cron
CREATE EXTENSION IF NOT EXISTS pg_cron;

-- Schedule a heartbeat query every 6 hours
SELECT cron.schedule(
    'supabase-keepalive-heartbeat',
    '0 */6 * * *',
    'SELECT 1;'
);
```

To verify the cron job:
```sql
SELECT * FROM cron.job;
```

---

## 4. Render Free Tier Usage Note

- Render provides **750 free instance hours per month** for web services.
- A single service running continuously 24/7 for a 31-day month consumes **744 hours** (`31 * 24`), which is safely within the 750-hour free allowance.
- **Warning**: If you have multiple free web services on the same Render account, they share the 750-hour pool and will run out of hours before the end of the month. In that scenario, upgrade the primary app to **Render Starter** ($7/mo in `render.yaml`), which includes 24/7 always-on service without instance hour caps.
