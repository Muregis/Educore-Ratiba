# EduCore Ratiba

**Ratiba** (Swahili: timetable / schedule) — multi-school, whole-school timetable generation for Kenyan CBC and legacy 8-4-4, built on the FET scheduling engine, PHP, and MySQL.

**Live:** [educoreratiba.vercel.app](https://educoreratiba.vercel.app)

Companion product: [EduCore](https://github.com/Muregis/educore-school-management-system) (school management SaaS). Optional SSO bridge only — separate app and database.

## Problem

Kenyan schools need conflict-free weekly timetables that respect grade-band lesson counts (KICD), shared teachers and rooms across classes, remedial slots, and weak connectivity. Building one class at a time fails when the same teacher is booked in two places.

## Solution

- **Whole-school FET runs** — one generation pass per school so shared teachers/rooms cannot double-book across classes
- **Multi-school isolation** — each school has its own teachers, rooms, classes, subjects, and admin login
- **Pre-flight checks** — class load vs band slots; teacher load vs maximum before invoking FET
- **Manual adjustments** with live clash validation (teacher / room / class)
- **PDF / print export** (Dompdf)
- **Offline / local-install mode** with conservative sync to a central server when online
- **CBC + 8-4-4 + tertiary exam-slot** band templates

## Architecture

```
School admin (PHP HTML)
        │
        ▼
  PHP 8 + PDO  ──▶  MySQL / MariaDB
        │
        ├── shell_exec → FET (fet-cl) whole-school solve
        ├── parse → scheduled_slots
        ├── export/ (PDF)
        └── sync.php ↔ local-install clients
```

| Layer | Choice |
|-------|--------|
| Scheduling | FET (`fet-cl`), whole-school, not per-class |
| App | PHP 8+, plain server-rendered HTML (intentionally light) |
| Data | MySQL / MariaDB (PDO) |
| PDF | Dompdf via Composer |
| Ops | `health.php`, `status.php`, Docker, Render config, uptime workflow |

## Key engineering decisions

1. **Whole-school generation** — only a combined FET model can enforce global teacher/room constraints when staff teach across bands.
2. **Human-readable pre-checks** — failures point at class or teacher load, not raw engine dumps.
3. **Conservative offline sync** — conflicts are held for review rather than silent merge when server data is newer.
4. **Separate from EduCore data** — SSO is identity handoff only; no shared operational database.

## Supported grade bands (summary)

| Band | Lessons/day | Length | Lessons/week | Source |
|------|-------------|--------|--------------|--------|
| PP1–PP2 | 5 | 30 min | 25 | KICD |
| Grade 1–3 | 6–7 | 30 min | 31 | KICD |
| Grade 4–6 | 7 | 35 min | 35 | KICD |
| Grade 7–9 | 8–9 | 40 min | 41 | KICD |
| Grade 10–12 | 8 | 40 min | 40 | KICD |
| Form 3–4 (8-4-4) | 9 | 40 min | ~45 | MoE |
| Tertiary | — | 120 min | exam-slot | — |

Grade 10–12 and Form 3–4 ship with **placeholder electives** — replace with real stream combinations before production use.

## Security

- Set real DB credentials (never deploy root/no-password defaults)
- Set `SSO_SHARED_SECRET` for EduCore bridge; set per-school sync tokens for local installs
- HTTPS in production for admin logins
- See `SECURITY.md` for deployment checklist

## Testing & CI

- Deploy / uptime workflows under `.github/workflows/`
- CI workflow on this branch validates Composer and PHP syntax on core entrypoints

## Quick start

**Requirements:** PHP 8+ (PDO MySQL), MySQL/MariaDB, FET binary in `engine/`, Composer

```bash
git clone https://github.com/Muregis/Educore-Ratiba.git
cd Educore-Ratiba
composer install
mysql -u root -p < db/schema.sql
# configure DB credentials (env / db config — do not commit secrets)
```

Create a super-admin password hash once:

```bash
php -r "echo password_hash('your-password', PASSWORD_DEFAULT), PHP_EOL;"
```

Insert into `super_admins`, then open `login.php` and create the first school.

## Project layout

```
engine/          # FET binary (you provide)
db/              # schema + connection
admin/           # school-admin UI
super/           # multi-school super-admin
view/            # public read-only timetable
export/          # PDF
data/bands/      # band templates
login.php sso.php sync.php sync-local.php
health.php status.php
```

## Status

Active development. Strong domain and constraint-solving signal for Kenyan school operations. Expand automated tests (clash validation, generation pre-checks) as the next credibility step.

## License

Proprietary — MuregiScore Technologies.

---

Built by **Victor Muregi** · part of the EduCore product family.
