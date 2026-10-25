# EduCore Ratiba

[![CI](https://github.com/Muregis/Educore-Ratiba/actions/workflows/ci.yml/badge.svg)](https://github.com/Muregis/Educore-Ratiba/actions/workflows/ci.yml)

**Ratiba** (Swahili: timetable / schedule) — multi-school timetable management and automated scheduling for Kenyan CBC and legacy 8-4-4, built on PHP 8+, MySQL/PostgreSQL, and the open-source FET timetabling solver.

* **Live:** [educoreratiba.vercel.app](https://educoreratiba.vercel.app) · alternate: [educore-ratiba.onrender.com](https://educore-ratiba.onrender.com)
* **Companion:** [EduCore](https://github.com/Muregis/educore-school-management-system) (school management SaaS; optional SSO bridge)

---

## Problem

Kenyan schools need conflict-aware weekly timetables that respect grade-band lesson counts (KICD), shared teachers and rooms, and weak connectivity. Building one class at a time fails when the same teacher is booked in two places.

## Solution

- **Whole-school FET runs** — one generation pass so shared teachers/rooms are not double-booked across classes
- **Multi-school isolation** — per-school teachers, rooms, classes, subjects, and admin login
- **Pre-flight checks** — class/teacher load vs capacity before invoking FET
- **Manual adjustments** with live clash validation (teacher / room / class)
- **PDF / print export** (Dompdf)
- **Offline / local-install** patterns for low-connectivity schools
- **CBC + 8-4-4** band templates (schools must configure real bells and loads)

---

## Architecture

```
School admin (PHP UI)
        │
        ▼
  PHP 8 + PDO  ──▶  MySQL / PostgreSQL
        │
        ├── proc_open → FET (fet-cl) whole-school solve
        ├── parse → scheduled_slots
        ├── ClashDetector (pre-flight + post-gen + manual edits)
        └── export/ (PDF)
```

---

## Constraints & tests

| Constraint | Enforced in |
|------------|-------------|
| Teacher double-booking | FET + `ClashDetector` + manual edit path |
| Class concurrency | FET + ClashDetector |
| Room double-booking | FET + ClashDetector |
| Workload pre-checks | ClashDetector pre-flight |
| FET failure diagnostics | `FetFailureReportingTest.php` |

```bash
composer test
# Or run individual test batteries:
# php tests/ClashDetectorTest.php
# php tests/FetFailureReportingTest.php
# php tests/SecurityTest.php
# php tests/DatabaseCompatibilityTest.php
```

CI (`.github/workflows/ci.yml`): Composer validate, strict `php -l` on all PHP files, and the full 78-test master test suite (`composer test`).

> Unit tests prove rule logic without a live FET binary. Production quality still depends on school configuration, engine install, and ops limits documented below.

---

## Quick start

**Requirements:** PHP 8.1+ (PDO MySQL), MySQL/MariaDB or PostgreSQL, Composer, FET binary in `engine/`

```bash
git clone https://github.com/Muregis/Educore-Ratiba.git
cd Educore-Ratiba
composer install
cp .env.example .env   # set DB + FET path
mysql -u root -p < db/schema.sql
php tests/ClashDetectorTest.php
php -S 127.0.0.1:8000 router_dev.php
```

Demo credentials (local seed only): see login page / seed scripts — **change before any public demo.**

---

## Production notes

1. Seed data is a **template** — configure bands, subjects, and loads per school circulars.
2. FET is CPU-heavy — queue or isolate generation under multi-school load.
3. Offline sync uses conservative last-write-wins; avoid multi-master concurrent edits.
4. Set strong `SSO_SHARED_SECRET` / sync tokens; HTTPS in production.

See `SECURITY.md` where present.

---

## For employers & schools

**Inspect:** `tests/ClashDetector*.php`, `admin/edit_actions.php`, CI workflow, live demo generation + a deliberate conflicting move.

**Interview topics:** constraint modeling, pre-flight vs solver, safe `proc_open`, multi-school isolation, what breaks when config is wrong.

---

## License

Proprietary — MuregiScore Technologies.

Built by **Victor Muregi** · EduCore product family.
