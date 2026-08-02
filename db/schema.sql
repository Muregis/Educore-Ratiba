-- ============================================================
-- Multi-School Timetable System — Database Schema
-- MySQL / MariaDB (Laragon-bundled or hosted equivalent)
--
-- Matches docs/MULTI_SCHOOL_PLAN.md sections 1, 3a, 4b, 4c, 4d.
-- Run this once against a fresh, empty database dedicated to this
-- app (kept separate from EduCore's own database — see plan
-- section 7 "What does NOT change").
-- ============================================================

CREATE DATABASE IF NOT EXISTS fet_timetable
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE fet_timetable;

-- ------------------------------------------------------------
-- SCHOOLS — one row per client school. Everything else is
-- scoped to a school_id, enforced at the query layer (see
-- db/db.php helper functions), not just the UI.
-- ------------------------------------------------------------
CREATE TABLE schools (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    band_types JSON NULL COMMENT 'which grade bands this school runs, e.g. ["grade-1-3","grade-4-6"]',
    deployment_type ENUM('server-hosted', 'local-install') NOT NULL DEFAULT 'server-hosted',
    educore_school_id VARCHAR(100) NULL UNIQUE COMMENT 'EduCore''s own school ID, for the SSO bridge (plan section 7a) - a reference only, NOT shared data',
    sync_token VARCHAR(64) NULL UNIQUE COMMENT 'authenticates a local-install instance to sync.php (plan section 4c) - only set for deployment_type = local-install',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- SCHOOL ADMINS — login credentials, one school each.
-- Super-admin (you) is handled separately, not a row here —
-- see super_admins below.
-- ------------------------------------------------------------
CREATE TABLE school_admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    username VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_school_username (school_id, username)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- SUPER ADMINS — you. Separate table, not scoped to any
-- school, sees everything.
-- ------------------------------------------------------------
CREATE TABLE super_admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- BANDS — which grade bands a school has active, plus that
-- band's own hour structure (they genuinely differ - see the
-- six band files already built and validated). break_config
-- and remedial_hours are JSON so each band can define its own
-- slot structure without needing new columns per band type.
-- ------------------------------------------------------------
CREATE TABLE bands (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    band_key VARCHAR(50) NOT NULL COMMENT 'pp1-pp2 / grade-1-3 / grade-4-6 / grade-7-9 / grade-10-12 / form-3-4 / tertiary',
    label VARCHAR(150) NOT NULL,
    lessons_per_day INT NOT NULL,
    lesson_length_minutes INT NOT NULL,
    break_config JSON NULL COMMENT 'which slots are locked breaks, e.g. [{"time":"10:00-10:20","label":"BREAK"}]',
    remedial_hours JSON NULL COMMENT 'optional morning/evening slots outside normal hours, e.g. {"morning":"06:30-07:30","evening":"16:40-17:40"}',
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_school_band (school_id, band_key)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- ROOMS
-- ------------------------------------------------------------
CREATE TABLE rooms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    capacity INT NULL,
    room_type VARCHAR(50) NULL COMMENT 'classroom / lab / hall / etc.',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- TEACHERS — staff_id is a plain school-assigned serial
-- (e.g. "1", "T-014"), NOT assumed to be a TSC number, since
-- not every client school is a public TSC school.
-- ------------------------------------------------------------
CREATE TABLE teachers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    staff_id VARCHAR(50) NULL,
    name VARCHAR(150) NOT NULL,
    subjects_taught TEXT NULL COMMENT 'free text summary; authoritative assignment is via subjects.assigned_teacher_id',
    max_lessons_per_week INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- CLASSES — actual class groups (e.g. "Grade 4 East"),
-- belongs to one band, has a default room.
-- ------------------------------------------------------------
CREATE TABLE classes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    band_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    student_count INT NULL,
    room_id INT NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    FOREIGN KEY (band_id) REFERENCES bands(id) ON DELETE CASCADE,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- SUBJECTS — one row per class's subject requirement. The
-- same teacher can appear across many rows in different
-- classes (shared teachers, plan section 4) — this is what
-- makes whole-school generation necessary for correct
-- double-booking checks.
-- ------------------------------------------------------------
CREATE TABLE subjects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    band_id INT NOT NULL,
    class_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    lessons_per_week INT NOT NULL,
    assigned_teacher_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    FOREIGN KEY (band_id) REFERENCES bands(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_teacher_id) REFERENCES teachers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- EXTRA ACTIVITIES — clubs, parade, assembly. Not a normal
-- lessons/week subject; may be tied to a fixed day.
-- ------------------------------------------------------------
CREATE TABLE extra_activities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    band_id INT NOT NULL,
    class_id INT NOT NULL,
    name VARCHAR(150) NOT NULL COMMENT 'e.g. "Parade", "Club - Chess", "Games Afternoon"',
    day_of_week VARCHAR(20) NULL COMMENT 'NULL if flexible/solver-placed',
    duration_slots INT NOT NULL DEFAULT 1,
    frequency ENUM('weekly', 'daily', 'specific-day') NOT NULL DEFAULT 'weekly',
    assigned_teacher_id INT NULL,
    room_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    FOREIGN KEY (band_id) REFERENCES bands(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_teacher_id) REFERENCES teachers(id) ON DELETE SET NULL,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- REMEDIAL SESSIONS — morning/evening remedial lessons, real
-- timetabled lessons (specific subject+teacher+class) but
-- outside the band's normal Hours_List, in that band's own
-- remedial_hours window (see bands.remedial_hours above).
-- Still goes through the same whole-school FET run and
-- double-booking checks as normal lessons.
-- ------------------------------------------------------------
CREATE TABLE remedial_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    band_id INT NOT NULL,
    class_id INT NOT NULL,
    subject_id INT NOT NULL,
    teacher_id INT NOT NULL,
    room_id INT NULL,
    session_type ENUM('morning', 'evening') NOT NULL,
    day_of_week VARCHAR(20) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    FOREIGN KEY (band_id) REFERENCES bands(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- GENERATED TIMETABLES — WHOLE SCHOOL, not per-class (plan
-- section 4). One row per generation run. xml_snapshot keeps
-- the full combined XML that was actually run, for audit
-- history and as a lighter-weight recovery path than a full
-- database restore (plan section 6, backup strategy).
-- ------------------------------------------------------------
CREATE TABLE generated_timetables (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    xml_snapshot LONGTEXT NULL,
    html_output_path VARCHAR(500) NULL,
    status ENUM('success', 'failed', 'partial') NOT NULL,
    triggered_by_admin_id INT NULL COMMENT 'school_admins.id or super_admins.id, see triggered_by_type',
    triggered_by_type ENUM('school_admin', 'super_admin') NOT NULL DEFAULT 'school_admin',
    FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- SCHEDULED SLOTS — one row per actual scheduled lesson,
-- parsed out of FET's XML output right after generation. This
-- is what the manual editor (plan section 4b) and the
-- double-booking check both read and write against, instead
-- of re-parsing XML on every edit.
-- ------------------------------------------------------------
CREATE TABLE scheduled_slots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    generated_timetable_id INT NOT NULL,
    class_id INT NOT NULL,
    subject_id INT NULL,
    extra_activity_id INT NULL,
    remedial_session_id INT NULL,
    teacher_id INT NOT NULL,
    room_id INT NULL,
    day_of_week VARCHAR(20) NOT NULL,
    hour_slot VARCHAR(50) NOT NULL COMMENT 'e.g. "08:00-08:40"',
    is_manual_override BOOLEAN NOT NULL DEFAULT FALSE,
    edited_by_admin_id INT NULL,
    edited_at TIMESTAMP NULL,
    FOREIGN KEY (generated_timetable_id) REFERENCES generated_timetables(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE SET NULL,
    FOREIGN KEY (extra_activity_id) REFERENCES extra_activities(id) ON DELETE SET NULL,
    FOREIGN KEY (remedial_session_id) REFERENCES remedial_sessions(id) ON DELETE SET NULL,
    FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL,
    -- Indexes supporting the clash checks the manual editor needs to
    -- run on every proposed move (plan section 4b): does this
    -- teacher/room/class already have something at this day+slot,
    -- within this same generated_timetable (i.e. the current
    -- possibly-hand-edited grid).
    INDEX idx_clash_teacher (generated_timetable_id, teacher_id, day_of_week, hour_slot),
    INDEX idx_clash_room (generated_timetable_id, room_id, day_of_week, hour_slot),
    INDEX idx_clash_class (generated_timetable_id, class_id, day_of_week, hour_slot)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- SYNC LOG — for offline local-install schools (plan section
-- 4c). Tracks each sync attempt and whether it succeeded or
-- was flagged as a conflict needing manual review. Kept
-- deliberately simple: no automatic merging, conflicts are
-- held, not silently resolved.
-- ------------------------------------------------------------
CREATE TABLE sync_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    sync_attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    direction ENUM('push', 'pull') NOT NULL,
    status ENUM('success', 'conflict', 'failed') NOT NULL,
    local_snapshot_timestamp TIMESTAMP NULL COMMENT 'the local devices last-known sync point',
    server_snapshot_timestamp TIMESTAMP NULL COMMENT 'what the server actually had at sync time',
    notes TEXT NULL,
    resolved_at TIMESTAMP NULL COMMENT 'when a conflict was manually reviewed and resolved',
    FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- AUDIT LOG — tracks all administrative actions for security
-- and accountability. Records who did what, when, and from
-- which IP address.
-- ------------------------------------------------------------
CREATE TABLE audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    admin_id INT NOT NULL COMMENT 'school_admins.id or super_admins.id',
    admin_type ENUM('school_admin', 'super_admin') NOT NULL,
    action VARCHAR(50) NOT NULL COMMENT 'create, update, delete, login, logout, etc.',
    entity VARCHAR(50) NOT NULL COMMENT 'teacher, room, band, class, subject, etc.',
    entity_id INT NULL COMMENT 'ID of the affected entity',
    details JSON NULL COMMENT 'Additional context about the action',
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    INDEX idx_school_admin (school_id, admin_id),
    INDEX idx_entity (entity, entity_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB;
