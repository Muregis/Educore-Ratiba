-- ============================================================
-- db/migrate_audit.sql
-- Safe migration: adds audit_log table if not present.
-- Run via db/setup.php or manually in phpMyAdmin.
-- ============================================================

CREATE TABLE IF NOT EXISTS audit_log (
    id           BIGINT AUTO_INCREMENT PRIMARY KEY,
    school_id    INT NULL,
    admin_id     INT NULL,
    admin_type   ENUM('school_admin','super_admin') NULL,
    action       VARCHAR(50)  NOT NULL COMMENT 'create | update | delete | login | logout',
    entity       VARCHAR(80)  NOT NULL COMMENT 'teacher | room | band | class | subject | activity | remedial | timetable',
    entity_id    INT NULL,
    data_json    JSON NULL COMMENT 'snapshot of changed fields',
    ip_address   VARCHAR(45)  NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_school (school_id),
    INDEX idx_entity (entity, entity_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB;
