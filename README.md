# EduCore Ratiba

**Ratiba** (Swahili: timetable / schedule) is a Kenyan multi-school timetable management and automated scheduling engine supporting both CBC (Competency-Based Curriculum) and legacy 8-4-4, built on PHP 8+, MySQL/PostgreSQL, and the open-source FET timetabling solver.

* **Live Demo:** [educore-ratiba.onrender.com](https://educore-ratiba.onrender.com)
* **Companion Product:** [EduCore](https://github.com/Muregis/educore-school-management-system) (school management SaaS via optional SSO bridge).

---

## The Problem

Kenyan educational institutions face severe timetabling challenges:
* **Complex Grade Bands (KICD):** Lessons per week, period lengths (35 vs 40 minutes), and break times differ widely across Early Years (PP1–PP2), Middle School (Grade 1–6), Junior School (Grade 7–9), and Senior Secondary.
* **Shared Specialist Teachers:** Teachers are shared across classes and streams. Generating class timetables independently leads to severe double-booking.
* **Specialized Rooms:** Science laboratories, computer labs, workshops, and sports fields cannot host multiple streams simultaneously.
* **Intermittent Connectivity:** Many rural and sub-county schools operate offline or with erratic internet access.

Ratiba solves these challenges by combining **multi-school isolation**, **pre-flight configuration validation**, **whole-school FET engine constraint solves**, **live clash detection during manual adjustments**, and **offline/local-install capabilities**.

---

## Architecture

```
  ┌────────────────────────────────────────────────────────┐
  │                 School Administrator                   │
  │     (Responsive Web UI: Auth, Settings, Classes)       │
  └───────────────────────────┬────────────────────────────┘
                              │
                              ▼
  ┌────────────────────────────────────────────────────────┐
  │                  PHP 8 Application Core                │
  │  - Multi-School Isolation & PDO Database Access        │
  │  - Pure-PHP Pre-Flight Workload Validator              │
  │  - Whole-School FET XML Serializer                     │
  │  - Safe Engine Runner (proc_open with argument bypass) │
  │  - Solution XML Parser & Scheduled Slot Store          │
  │  - Live ClashDetector (Post-Gen & Manual Edits)        │
  │  - Dompdf PDF Timetable Generator                      │
  └───────────────┬────────────────────────┬───────────────┘
                  │                        │
                  ▼                        ▼
  ┌────────────────────────┐      ┌────────────────────────┐
  │  Database Layer        │      │   Scheduling Engine    │
  │  MySQL 8 / PostgreSQL  │      │   FET (fet-cl) 7.9.2   │
  │  (Multi-tenant scoped) │      │   Heuristic Solver     │
  └────────────────────────┘      └────────────────────────┘
```

---

## Scheduling Algorithm & FET Integration

Ratiba delegates heavy NP-complete constraint-satisfaction solving to **FET (Free Educational Timetabling)**:

1. **Pre-flight Checks (`ClashDetector::validatePreflightConfig`):**
   Validates teacher workloads against stated weekly caps, ensures active classes fit their band teaching capacity, checks that every subject has an assigned teacher, and flags classroom shortages before launching the solver.
2. **Whole-School XML Generation (`admin/generate_engine.php`):**
   Constructs a single combined FET XML specification encompassing all active classes, teachers, rooms, and subjects across all grade bands.
   * Day and period definitions with band-specific prefixes (`demo-lower__08:00-08:35`).
   * Break and lunch periods locked using `ConstraintStudentsSetNotAvailableTimes`.
   * Subject distribution spreads enforced using `ConstraintMinDaysBetweenActivities` with `Activity_Id` references.
   * Space allocation enforced via `ConstraintBasicCompulsorySpace`.
3. **Safe Engine Execution (`admin/fet_runtime.php`):**
   * Uses `proc_open` with direct argument arrays, bypassing shell interpreters (`sh`/`cmd.exe`) to prevent command injection.
   * Strictly enforces time limits per school scale (default 300s–600s).
   * Verifies engine exit codes (exit 0) **and** confirms generation success via output logs.
   * Verifies the presence of a non-empty `_activities.xml` solution file before declaring success.
4. **Solution Ingestion & Post-Generation Clash Check:**
   * Solution XML is parsed back into normalized `scheduled_slots` rows in the database.
   * Cross-band slots are audited and purged.
   * `ClashDetector::findConflicts` validates the resulting schedule to guarantee 100% clash-free schedules before presenting to users.

---

## Core Constraints Enforced

| Constraint | Engine Enforcement | Manual Edit Enforcement | Automated Test |
|------------|--------------------|-------------------------|----------------|
| **Teacher Double-Booking** | FET Hard Constraint | `ClashDetector::validateManualMove / Swap` | `ClashDetectorTest.php` |
| **Class Concurrency** | FET Hard Constraint | Blocked if class has simultaneous lesson | `ClashDetectorTest.php` |
| **Room Double-Booking** | FET Space Constraint | Blocked if room already occupied | `ClashDetectorTest.php` |
| **Duplicate Slots** | XML Activity IDs | Rejected on duplicate lesson allocation | `ClashDetectorTest.php` |
| **Workload Limits** | Pre-flight rejection | Teacher weekly cap warnings | `ClashDetectorTest.php` |
| **Band Hour Availability** | StudentsSetNotAvailableTimes | Grid cells locked to band teaching hours | `ClashDetectorTest.php` |
| **Subject Day Spread** | MinDaysBetweenActivities | Manual move spread verification | `ClashDetectorTest.php` |

---

## Supported Curricula

* **CBC (Competency-Based Curriculum):**
  * **Pre-Primary (PP1–PP2):** 25–30 min lessons, 5 lessons/day.
  * **Lower Primary (Grade 1–3):** 35 min lessons, 7 lessons/day.
  * **Upper Primary (Grade 4–6):** 35 min lessons, 8 lessons/day.
  * **Junior School (Grade 7–9):** 40–45 min lessons, 9 lessons/day with integrated sciences, pre-technical, agriculture.
  * **Senior School (Grade 10–12):** Pathway tracks (STEM, Social Sciences, Arts & Sports Science).
* **Legacy 8-4-4:**
  * Standard 40-minute and 45-minute lesson blocks (9–10 periods/day).
* **TVET / Tertiary Modular Timetables:**
  * Support for elective blocks and modular laboratory slots.

> [!NOTE]
> The default seed data in `db/schema.sql` and `db/rebalance_demo_academy.sql` provides placeholder CBC and 8-4-4 band templates for demonstration. Production schools should configure their exact timetable bands, lesson lengths, and bell times on the **Bands** and **Settings** pages to match school-specific circulars.

---

## Automated Testing & CI

Ratiba includes a pure-PHP automated test suite covering all 8 scheduling and clash constraint categories without requiring an active database or external binary:

```bash
php tests/ClashDetectorTest.php
php tests/FetFailureReportingTest.php
```

### Test Battery Coverage:
1. **Teacher Conflicts:** Simultaneous booking across multiple classes and unassigned outdoor rooms.
2. **Class Conflicts:** Concurrent lesson detection using standard and DB column aliases.
3. **Room Conflicts:** Double-booking prevention; null/outdoor room safety.
4. **Duplicate Slots:** Detection of exact slot ID duplicates and identical multi-subject allocations.
5. **Invalid Workloads:** Rejection of teacher overload, negative loads, zero band slots, and negative lesson counts.
6. **Manual Adjustments:** Validation of conflict-free moves, move clash rejections, safe swaps, and swap clash rejections.
7. **Generation Preflight:** Detection of overloaded classes, unassigned subject teachers, and teacher capacity deficits.
8. **Valid Schedules:** End-to-end multi-teacher/multi-class verification of 100% clash-free schedules.
9. **FET Failure Diagnostics:** Pinpoints unplaced activities, missing binaries, permission issues, and XML malformations.

### GitHub Actions CI (`.github/workflows/ci.yml`):
* `composer validate --strict` — strict package and dependency validation.
* Syntax check on all entrypoints, admin, config, db, export, and test PHP files (`php -l`).
* Execution of the automated clash detection test suite (`ClashDetectorTest.php`).
* Execution of the automated FET failure reporting test suite (`FetFailureReportingTest.php`).

---

## Local Setup & Development

### Requirements
* PHP 8.1+ with `pdo_mysql`, `curl`, `mbstring`, `fileinfo`, `openssl` extensions
* MySQL 8.0+ or PostgreSQL 14+
* Composer v2
* FET CLI executable (`engine/fet-cl` or `engine/fet-cl.exe`)

### Setup Instructions
1. Clone the repository:
   ```bash
   git clone https://github.com/Muregis/Educore-Ratiba.git
   cd Educore-Ratiba
   ```
2. Install dependencies:
   ```bash
   composer install
   ```
3. Configure environment:
   ```bash
   cp .env.example .env
   # Edit .env with your database credentials and FET engine path
   ```
4. Initialize database schema:
   ```bash
   mysql -u root -p fet_timetable < db/schema.sql
   mysql -u root -p fet_timetable < db/rebalance_demo_academy.sql
   ```
5. Run the development server:
   ```bash
   php -S 127.0.0.1:8000 router_dev.php
   ```
6. Access the application:
   * **URL:** `http://127.0.0.1:8000/login.php`
   * **Demo Admin:** `demo_admin` / `DemoAdmin#2026`
   * **Health Check:** `http://127.0.0.1:8000/health.php?deep=1`

---

## Production Deployment

### Docker Deployment
The repository includes a production-ready `Dockerfile` and `render.yaml`:
* Installs Debian's official `fet` package (`/usr/bin/fet-cl`).
* Configures PHP-FPM with memory and execution time limits.
* Supports Supabase PostgreSQL Connection Pooler (`DB_DRIVER=pgsql`, port 5432/6543).

### Security Best Practices
* Always configure strong random strings for `SSO_SHARED_SECRET` and `LOCAL_SYNC_TOKEN`.
* Deploy behind HTTPS with secure cookie parameters (`session_set_cookie_params`).
* Never run with default root/empty passwords in production.
* Review `SECURITY.md` for role isolation and session lifecycle details.

---

## Known Limitations & Production Notes

1. **Curriculum Placeholders:** Initial seed data contains sample classes and subjects. Schools must review subject allocations before generating production timetables.
2. **Single-Node FET Concurrency:** High-concurrency generation across dozens of schools simultaneously should be queued or run on worker instances to avoid CPU contention.
3. **Offline Sync Conflict Resolution:** Local-install sync uses last-write-wins per school timetable generation; multi-master simultaneous edits must be avoided.

---

## License

Proprietary — MuregiScore Technologies. Built by **Victor Muregi** · part of the EduCore product family.
