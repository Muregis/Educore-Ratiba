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

## Testing & CI

```bash
php tests/ClashDetectorTest.php
```

| Test | Result |
|------|--------|
| Teacher double-booking | Covered |
| Room double-booking | Covered |
| Class double-booking | Covered |
| Valid schedule conflict-free | Covered |
| Teacher / class workload pre-checks | Covered |

CI (`.github/workflows/ci.yml`): PHP syntax + Composer + clash unit tests. No production data or secrets required.

Wire `ClashDetector` into admin edit/generate paths as the next step so UI and tests share the same rules.

## Security

- Set real DB credentials (never deploy root/no-password defaults)
- Set `SSO_SHARED_SECRET` for EduCore bridge; set per-school sync tokens for local installs
- HTTPS in production for admin logins
- See `SECURITY.md`

## Quick start

**Requirements:** PHP 8+ (PDO MySQL), MySQL/MariaDB, FET binary in `engine/`, Composer

```bash
git clone https://github.com/Muregis/Educore-Ratiba.git
cd Educore-Ratiba
composer install
mysql -u root -p < db/schema.sql
```

## Status

Active development. Clash unit tests live on `test/clash-precheck-suite`.

## License

Proprietary — MuregiScore Technologies.

---

Built by **Victor Muregi** · part of the EduCore product family.
