-- ============================================================
-- EduCore Ratiba — Flexible school scale upgrade (v1)
-- Run once in Supabase SQL Editor (or MySQL after adapting types)
-- Safe to re-run: uses IF NOT EXISTS patterns where possible
-- ============================================================

-- School-level flexibility
ALTER TABLE schools ADD COLUMN IF NOT EXISTS school_type VARCHAR(40) DEFAULT 'secondary';
ALTER TABLE schools ADD COLUMN IF NOT EXISTS days_per_week INT NOT NULL DEFAULT 5;
ALTER TABLE schools ADD COLUMN IF NOT EXISTS day_names JSONB DEFAULT '["Monday","Tuesday","Wednesday","Thursday","Friday"]'::jsonb;
ALTER TABLE schools ADD COLUMN IF NOT EXISTS generation_time_limit INT NOT NULL DEFAULT 300;
ALTER TABLE schools ADD COLUMN IF NOT EXISTS prefer_spread BOOLEAN NOT NULL DEFAULT TRUE;
ALTER TABLE schools ADD COLUMN IF NOT EXISTS settings_json JSONB DEFAULT '{}'::jsonb;

-- Band: day start time for building hour slots
ALTER TABLE bands ADD COLUMN IF NOT EXISTS day_start_time VARCHAR(8) DEFAULT '08:00';

-- Subjects: multi-slot lessons (labs = 2), min days between occurrences
ALTER TABLE subjects ADD COLUMN IF NOT EXISTS duration_slots INT NOT NULL DEFAULT 1;
ALTER TABLE subjects ADD COLUMN IF NOT EXISTS min_days_between INT NOT NULL DEFAULT 1;
ALTER TABLE subjects ADD COLUMN IF NOT EXISTS requires_room_type VARCHAR(50);

-- Teachers: optional unavailability notes (JSON array of {day, hour_label})
ALTER TABLE teachers ADD COLUMN IF NOT EXISTS unavailable_json JSONB DEFAULT '[]'::jsonb;
ALTER TABLE teachers ADD COLUMN IF NOT EXISTS preferred_max_daily INT;

-- Index helpers for large schools
CREATE INDEX IF NOT EXISTS idx_subjects_school_class ON subjects (school_id, class_id);
CREATE INDEX IF NOT EXISTS idx_classes_school_active ON classes (school_id, active);
CREATE INDEX IF NOT EXISTS idx_teachers_school ON teachers (school_id);
