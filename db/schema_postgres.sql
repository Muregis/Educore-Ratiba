-- ============================================================
-- EduCore Ratiba — PostgreSQL / Supabase schema
-- Run this once in the Supabase SQL Editor
-- ============================================================

-- Schools
CREATE TABLE IF NOT EXISTS schools (
    id              SERIAL PRIMARY KEY,
    name            VARCHAR(255) NOT NULL,
    band_types      JSONB,
    deployment_type VARCHAR(30) NOT NULL DEFAULT 'server-hosted'
                    CHECK (deployment_type IN ('server-hosted', 'local-install')),
    educore_school_id VARCHAR(100) UNIQUE,
    sync_token      VARCHAR(64) UNIQUE,
    created_at      TIMESTAMPTZ DEFAULT NOW()
);

-- School admins
CREATE TABLE IF NOT EXISTS school_admins (
    id            SERIAL PRIMARY KEY,
    school_id     INT NOT NULL REFERENCES schools(id) ON DELETE CASCADE,
    username      VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at    TIMESTAMPTZ DEFAULT NOW(),
    UNIQUE (school_id, username)
);

-- Super admins
CREATE TABLE IF NOT EXISTS super_admins (
    id            SERIAL PRIMARY KEY,
    username      VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at    TIMESTAMPTZ DEFAULT NOW()
);

-- Grade bands
CREATE TABLE IF NOT EXISTS bands (
    id                    SERIAL PRIMARY KEY,
    school_id             INT NOT NULL REFERENCES schools(id) ON DELETE CASCADE,
    band_key              VARCHAR(50) NOT NULL,
    label                 VARCHAR(150) NOT NULL,
    lessons_per_day       INT NOT NULL,
    lesson_length_minutes INT NOT NULL,
    break_config          JSONB,
    remedial_hours        JSONB,
    active                BOOLEAN NOT NULL DEFAULT TRUE,
    created_at            TIMESTAMPTZ DEFAULT NOW(),
    UNIQUE (school_id, band_key)
);

-- Rooms
CREATE TABLE IF NOT EXISTS rooms (
    id         SERIAL PRIMARY KEY,
    school_id  INT NOT NULL REFERENCES schools(id) ON DELETE CASCADE,
    name       VARCHAR(150) NOT NULL,
    capacity   INT,
    room_type  VARCHAR(50),
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- Teachers
CREATE TABLE IF NOT EXISTS teachers (
    id                   SERIAL PRIMARY KEY,
    school_id            INT NOT NULL REFERENCES schools(id) ON DELETE CASCADE,
    staff_id             VARCHAR(50),
    name                 VARCHAR(150) NOT NULL,
    subjects_taught      TEXT,
    max_lessons_per_week INT,
    created_at           TIMESTAMPTZ DEFAULT NOW()
);

-- Classes
CREATE TABLE IF NOT EXISTS classes (
    id            SERIAL PRIMARY KEY,
    school_id     INT NOT NULL REFERENCES schools(id) ON DELETE CASCADE,
    band_id       INT NOT NULL REFERENCES bands(id) ON DELETE CASCADE,
    name          VARCHAR(150) NOT NULL,
    student_count INT,
    room_id       INT REFERENCES rooms(id) ON DELETE SET NULL,
    active        BOOLEAN NOT NULL DEFAULT TRUE,
    created_at    TIMESTAMPTZ DEFAULT NOW()
);

-- Subjects
CREATE TABLE IF NOT EXISTS subjects (
    id                  SERIAL PRIMARY KEY,
    school_id           INT NOT NULL REFERENCES schools(id) ON DELETE CASCADE,
    band_id             INT NOT NULL REFERENCES bands(id) ON DELETE CASCADE,
    class_id            INT NOT NULL REFERENCES classes(id) ON DELETE CASCADE,
    name                VARCHAR(150) NOT NULL,
    lessons_per_week    INT NOT NULL,
    assigned_teacher_id INT REFERENCES teachers(id) ON DELETE SET NULL,
    created_at          TIMESTAMPTZ DEFAULT NOW()
);

-- Extra activities (clubs, parade, assembly)
CREATE TABLE IF NOT EXISTS extra_activities (
    id                  SERIAL PRIMARY KEY,
    school_id           INT NOT NULL REFERENCES schools(id) ON DELETE CASCADE,
    band_id             INT NOT NULL REFERENCES bands(id) ON DELETE CASCADE,
    class_id            INT NOT NULL REFERENCES classes(id) ON DELETE CASCADE,
    name                VARCHAR(150) NOT NULL,
    day_of_week         VARCHAR(20),
    duration_slots      INT NOT NULL DEFAULT 1,
    frequency           VARCHAR(20) NOT NULL DEFAULT 'weekly'
                        CHECK (frequency IN ('weekly', 'daily', 'specific-day')),
    assigned_teacher_id INT REFERENCES teachers(id) ON DELETE SET NULL,
    room_id             INT REFERENCES rooms(id) ON DELETE SET NULL,
    created_at          TIMESTAMPTZ DEFAULT NOW()
);

-- Remedial sessions
CREATE TABLE IF NOT EXISTS remedial_sessions (
    id           SERIAL PRIMARY KEY,
    school_id    INT NOT NULL REFERENCES schools(id) ON DELETE CASCADE,
    band_id      INT NOT NULL REFERENCES bands(id) ON DELETE CASCADE,
    class_id     INT NOT NULL REFERENCES classes(id) ON DELETE CASCADE,
    subject_id   INT NOT NULL REFERENCES subjects(id) ON DELETE CASCADE,
    teacher_id   INT NOT NULL REFERENCES teachers(id) ON DELETE CASCADE,
    room_id      INT REFERENCES rooms(id) ON DELETE SET NULL,
    session_type VARCHAR(10) NOT NULL CHECK (session_type IN ('morning', 'evening')),
    day_of_week  VARCHAR(20) NOT NULL,
    created_at   TIMESTAMPTZ DEFAULT NOW()
);

-- Generated timetables (whole-school runs)
CREATE TABLE IF NOT EXISTS generated_timetables (
    id                   SERIAL PRIMARY KEY,
    school_id            INT NOT NULL REFERENCES schools(id) ON DELETE CASCADE,
    generated_at         TIMESTAMPTZ DEFAULT NOW(),
    xml_snapshot         TEXT,
    html_output_path     VARCHAR(500),
    status               VARCHAR(20) NOT NULL
                         CHECK (status IN ('success', 'failed', 'partial')),
    triggered_by_admin_id INT,
    triggered_by_type    VARCHAR(20) NOT NULL DEFAULT 'school_admin'
                         CHECK (triggered_by_type IN ('school_admin', 'super_admin'))
);

-- Scheduled slots (parsed from FET output + manual edits)
CREATE TABLE IF NOT EXISTS scheduled_slots (
    id                      SERIAL PRIMARY KEY,
    generated_timetable_id  INT NOT NULL REFERENCES generated_timetables(id) ON DELETE CASCADE,
    class_id                INT NOT NULL REFERENCES classes(id) ON DELETE CASCADE,
    subject_id              INT REFERENCES subjects(id) ON DELETE SET NULL,
    extra_activity_id       INT REFERENCES extra_activities(id) ON DELETE SET NULL,
    remedial_session_id     INT REFERENCES remedial_sessions(id) ON DELETE SET NULL,
    teacher_id              INT NOT NULL REFERENCES teachers(id) ON DELETE CASCADE,
    room_id                 INT REFERENCES rooms(id) ON DELETE SET NULL,
    day_of_week             VARCHAR(20) NOT NULL,
    hour_slot               VARCHAR(50) NOT NULL,
    is_manual_override      BOOLEAN NOT NULL DEFAULT FALSE,
    edited_by_admin_id      INT,
    edited_at               TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_clash_teacher
    ON scheduled_slots (generated_timetable_id, teacher_id, day_of_week, hour_slot);
CREATE INDEX IF NOT EXISTS idx_clash_room
    ON scheduled_slots (generated_timetable_id, room_id, day_of_week, hour_slot);
CREATE INDEX IF NOT EXISTS idx_clash_class
    ON scheduled_slots (generated_timetable_id, class_id, day_of_week, hour_slot);

-- Sync log (offline schools)
CREATE TABLE IF NOT EXISTS sync_log (
    id                        SERIAL PRIMARY KEY,
    school_id                 INT NOT NULL REFERENCES schools(id) ON DELETE CASCADE,
    sync_attempted_at         TIMESTAMPTZ DEFAULT NOW(),
    direction                 VARCHAR(10) NOT NULL CHECK (direction IN ('push', 'pull')),
    status                    VARCHAR(20) NOT NULL CHECK (status IN ('success', 'conflict', 'failed')),
    local_snapshot_timestamp  TIMESTAMPTZ,
    server_snapshot_timestamp TIMESTAMPTZ,
    notes                     TEXT,
    resolved_at               TIMESTAMPTZ
);

-- Audit log
CREATE TABLE IF NOT EXISTS audit_log (
    id         SERIAL PRIMARY KEY,
    school_id  INT REFERENCES schools(id) ON DELETE CASCADE,
    admin_id   INT,
    admin_type VARCHAR(20) CHECK (admin_type IN ('school_admin', 'super_admin')),
    action     VARCHAR(50) NOT NULL,
    entity     VARCHAR(50) NOT NULL,
    entity_id  INT,
    data_json  JSONB,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_audit_school_admin ON audit_log (school_id, admin_id);
CREATE INDEX IF NOT EXISTS idx_audit_entity ON audit_log (entity, entity_id);
CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_log (created_at);

-- Optional: create a starter super-admin after you generate a hash
-- INSERT INTO super_admins (username, password_hash)
-- VALUES ('admin', '$2y$10$...paste PHP password_hash result...');
