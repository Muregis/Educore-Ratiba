# EduCore Ratiba - Schema Contract

This document defines the schema contract for the EduCore Ratiba database, ensuring that the frontend forms, backend queries, and database schema remain synchronized.

## Canonical Schema
The single source of truth is `db/schema_postgres.sql`. It contains all tables and columns, including previously flexible ones.

## Table Map

### `schools`
* **UI Owner:** Settings (`admin/settings.php`), Super Admin (`super/schools.php`)
* **Columns:**
  * `id`, `name`, `deployment_type`, `educore_school_id`, `sync_token`, `created_at`
  * `school_type`, `days_per_week`, `day_names`, `generation_time_limit`, `prefer_spread`, `settings_json`

### `bands`
* **UI Owner:** Bands (`admin/bands.php`)
* **Columns:**
  * `id`, `school_id`, `band_key`, `label`, `lessons_per_day`, `lesson_length_minutes`, `active`, `created_at`
  * `day_start_time`, `break_config`, `remedial_hours`

### `teachers`
* **UI Owner:** Teachers (`admin/teachers.php`)
* **Columns:**
  * `id`, `school_id`, `staff_id`, `name`, `subjects_taught`, `max_lessons_per_week`, `created_at`
  * `tsc_number` (Added for compliance)
  * `unavailable_json`, `preferred_max_daily`

### `rooms`
* **UI Owner:** Rooms (`admin/rooms.php`)
* **Columns:**
  * `id`, `school_id`, `name`, `capacity`, `room_type`, `created_at`

### `classes`
* **UI Owner:** Classes (`admin/classes.php`)
* **Columns:**
  * `id`, `school_id`, `band_id`, `name`, `student_count`, `room_id`, `active`, `created_at`

### `subjects`
* **UI Owner:** Subjects (`admin/subjects.php`)
* **Columns:**
  * `id`, `school_id`, `band_id`, `class_id`, `name`, `lessons_per_week`, `assigned_teacher_id`, `created_at`
  * `duration_slots`, `min_days_between`, `requires_room_type`

### `extra_activities` & `remedial_sessions`
* **UI Owner:** Activities (`admin/activities.php`), Remedials (`admin/remedials.php`)
* **Columns:** Match the standard schema fields (name, day_of_week, duration_slots, etc.)

### `school_calendar` & `school_holidays`
* **UI Owner:** Calendar (`admin/calendar.php`)
* **Columns:** `year`, `term_number`, `term_name`, `start_date`, `end_date`, `is_current` / `holiday_name`, `holiday_date`, `holiday_type`, `affects_timetabling`

## Principles
1. **No Runtime DDL:** PHP scripts must not execute `CREATE TABLE` or `ALTER TABLE`.
2. **SchoolConfig:** All scripts use `SchoolConfig` to retrieve school-level configuration (like days and limits) rather than scattering logic.
3. **Band Defaults:** Default breaks and times are written to the database upon band creation.
