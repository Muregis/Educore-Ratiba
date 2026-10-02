# EduCore Ratiba — SaaS operations (uptime & backups)

## Target SLOs (public SaaS)

| Metric | Target | Notes |
|--------|--------|--------|
| Availability (monthly) | **99.5%** starter / **99.9%** with multi-instance | Excludes planned maintenance announced 48h ahead |
| API readiness | `GET /health.php?ready=1` returns 200 | DB must answer `SELECT 1` |
| RPO (data loss) | **≤ 24h** (daily dumps) or **≤ 1h** if hourly | Logical `pg_dump -Fc` |
| RTO (restore time) | **≤ 4h** | Restore dump + verify health + smoke generate |
| Support response | Best-effort pilot / contracted SLA later | Status page: `/status.php` |

## Architecture

- **App**: Render Docker web service; ephemeral FS except mounted `output/`
- **DB**: Supabase Postgres (source of truth)
- **Secrets**: env vars only; never in git

### Pilot → public SaaS

1. Upgrade Render off **Starter**; use **Standard+** and **≥2 instances** for 99.9%-class uptime.
2. Enable Supabase **PITR** (Pro) plus logical dumps.
3. Put Cloudflare (or similar) in front for TLS/DDoS.
4. External uptime monitor hitting `/health.php` and `/health.php?ready=1`.
5. Alert on 2 consecutive failures.

## Health endpoints

| URL | Purpose |
|-----|---------|
| `/health.php` | Liveness |
| `/health.php?ready=1` | Readiness (DB) |
| `/health.php?deep=1` | DB + output + FET |
| `/status.php` | Public human status |

## Formal backups

### Platform (ops)

```bash
export DATABASE_URL='postgresql://USER:PASS@HOST:5432/postgres'
export BACKUP_DIR=/var/backups/educore
export RETAIN_DAYS=14
./scripts/backup_postgres.sh
```

Store dumps in S3/R2 with versioning. Cron daily 02:00 EAT. Test restore quarterly with `scripts/restore_postgres.sh`.

### Tenant (school admin)

Settings → **Download school backup (JSON)** (`admin/backup_export.php`).

### Supabase

Enable daily backups + PITR on paid plan.

## Incident runbook

1. Check `/status.php` and `/health.php?ready=1`.
2. DB down → Supabase status + pooler credentials.
3. App down → Render logs / last deploy.
4. Generate-only → `/admin/diagnostic.php`.
5. Communicate; post-mortem for Sev-1 within 5 business days.

## Maintenance

Prefer Tue–Thu 22:00–00:00 EAT. Announce 48h ahead for paid tenants.
