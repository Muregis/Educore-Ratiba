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
- **Manual adjustments** with live clash validation (teacher / room / class) in `admin/edit_actions.php`
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

Clash rules are covered by pure-PHP unit tests (no DB, no FET binary, no secrets):

```bash
php tests/ClashDetectorTest.php
```

| Scenario | Covered |
|----------|---------|
| Teacher A booked Mon P2 in two classes | Yes |
| Room 1 double-booked same day/period | Yes |
| Class 7A two subjects same slot | Yes |
| Valid multi-class parallel schedule | Yes |
| Teacher / class workload pre-checks | Yes |

Manual edit path (`admin/edit_actions.php`) also rejects teacher/room/class clashes against `scheduled_slots` at runtime.

CI (`.github/workflows/ci.yml` on **main**): PHP syntax + Composer + `php tests/ClashDetectorTest.php`.

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
php tests/ClashDetectorTest.php   # should print all PASS
```

## Status

Active development. Clash unit tests and CI are on **main**.

## License

Proprietary — MuregiScore Technologies.

---

Built by **Victor Muregi** · part of the EduCore product family.
