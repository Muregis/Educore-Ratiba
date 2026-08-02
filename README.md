EduCore Ratiba

Ratiba is Swahili for "timetable" / "schedule". A multi-school, whole-school timetable generation system built on the FET (Free Educational Timetabling) engine, PHP, and MySQL. Supports Kenyan CBC grade bands (PP1 through Grade 12), the legacy 8-4-4 system (Form 3-4), and tertiary/polytechnic exam-slot scheduling, with per-school teacher/room/subject management, manual timetable adjustments, PDF export, and offline-capable deployment for schools without reliable internet.

What this does
Generates conflict-free weekly timetables using the FET scheduling engine — no teacher or room is ever double-booked, breaks are locked, and each grade band's official lesson counts and lesson lengths are respected
Supports multiple schools from one system, each with its own teachers, rooms, classes, subjects, and admin login — fully isolated from one another
Handles teachers who teach across multiple classes and bands within the same school, checking for clashes across the entire school in one generation run, not per class
Lets a school administrator manually adjust a generated timetable (move a lesson to a different slot) with live double-booking validation on every change
Supports morning/evening remedial lessons as real timetabled sessions, and extra activities (clubs, parade, assembly)
Exports timetables as PDF or print-friendly view, per class or for the whole school
Works fully offline for schools without reliable internet, syncing to a central server when a connection is available
Optional single sign-on bridge for schools already using EduCore (kept as a separate app and database — no shared data, just an identity handoff)
Supported grade bands
Band	Lessons/day	Lesson length	Lessons/week	Source
PP1 – PP2 (Pre-Primary)	5	30 min	25	KICD guidelines
Grade 1 – 3 (Lower Primary)	6–7	30 min	31	KICD guidelines
Grade 4 – 6 (Upper Primary)	7	35 min	35	KICD guidelines
Grade 7 – 9 (Junior Secondary)	8–9	40 min	41	KICD guidelines
Grade 10 – 12 (Senior School)	8	40 min	40	KICD guidelines
Form 3 – 4 (legacy 8-4-4)	9	40 min	~45	MoE circular
Tertiary / Polytechnic	—	120 min	exam-slot style	—

Grade 10-12 and Form 3-4 ship with placeholder elective subjects — replace these with your school's actual stream combinations before using them for a real timetable.

Tech stack
Scheduling engine: FET (fet-cl.exe), invoked via PHP's shell_exec
Backend: PHP 8+, PDO/MySQL
Database: MySQL / MariaDB
PDF export: Dompdf
No frontend framework — plain PHP-rendered HTML, kept intentionally simple and dependency-light
Getting started
Requirements
PHP 8.0+ with PDO MySQL extension
MySQL or MariaDB
FET — download fet-cl.exe and place it in engine/
Composer (for PDF export via Dompdf)
Setup
bash
git clone <this-repo-url>
cd educore-ratiba

# Install PDF export dependency
composer require dompdf/dompdf

# Create the database (run once against a fresh MySQL/MariaDB instance)
mysql -u root -p < db/schema.sql

Edit db/db.php with your database credentials:

php
define('DB_HOST', 'localhost');
define('DB_NAME', 'fet_timetable');
define('DB_USER', 'your_user');
define('DB_PASS', 'your_password');

Create your first super-admin login (no UI for this yet — run once):

php
<?php
echo password_hash('your-chosen-password', PASSWORD_DEFAULT);
sql
INSERT INTO super_admins (username, password_hash)
VALUES ('your-username', '<paste the hash from above>');

Visit login.php, sign in, and create your first school from the super-admin panel.

Folder structure
fet-timetable/
├── engine/              FET binary (fet-cl.exe) — you provide this
├── db/                  Schema (schema.sql) and connection helper (db.php)
├── admin/               School-admin screens (teachers, rooms, bands,
│                        classes, subjects, activities, remedials,
│                        generate, edit-timetable)
├── super/               Super-admin screens (school creation/management)
├── view/                Public read-only timetable view (no login)
├── export/              PDF export
├── data/bands/          Grade-band FET templates + generator scripts
├── login.php            Sign-in (super-admin and school-admin)
├── sso.php              EduCore single sign-on bridge
├── sync.php             Central-server sync endpoint (offline schools)
├── sync-local.php       Local-install sync client (offline schools)
└── docs/                Full build plan and setup notes
How generation works

Every school's timetable is generated as a single, whole-school FET run — not one run per class. This is deliberate: teachers and rooms are often shared across multiple classes and grade bands within a school, and only a single combined run can guarantee that a shared teacher isn't double-booked across two different classes.

Before any FET run, the system checks:

Whether any class requires more lessons per week than its band has teaching slots for
Whether any teacher's total assigned load (across every class they teach) exceeds their stated maximum

Problems are reported in plain language, pointing at the specific class or teacher to fix — never as raw engine output.

After a successful generation, the result is parsed into a scheduled_slots table, which powers the manual editing screen, the read-only view, and PDF export — all filtered from the same underlying data.

Manual adjustments

School administrators can move any generated lesson to a different day or time slot. Every move is checked against the current timetable before saving — a move that would double-book a teacher, room, or class is rejected with a specific reason. Regenerating the whole timetable discards manual changes for that run.

Offline / local-install schools

A school can be deployed in local-install mode — the same codebase, plus a local MySQL database and local fet-cl.exe, all running on a machine physically at the school. This works fully with zero internet. When a connection is available, sync-local.php pushes local changes to the central server via sync.php.

Sync is deliberately conservative: if the central server has newer data for a school than the local device last saw, the sync is held as a conflict for manual review rather than automatically merged or overwritten in either direction.

Security notes before deploying publicly
Set real database credentials in db/db.php — do not deploy with the default local root/no-password setup
Set SSO_SHARED_SECRET in sso.php to a real, private value shared only with EduCore's backend
Set CENTRAL_SERVER_URL and a real per-school LOCAL_SYNC_TOKEN in sync-local.php for each local-install deployment
Use HTTPS in production — school-admin logins should never travel over plain HTTP
Roadmap
Exam-timetable mode (fixed-date exam sessions, reusing the same teacher/room/class data as the weekly timetable)
Polished desktop installer for local-install deployments
License

Proprietary — MuregiScore Technologies.# Educore Ratiba

