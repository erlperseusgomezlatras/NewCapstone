-- Ordered coordinator/student school deployment migration.
-- Safe to run on an existing database: existing assignments are retained.

CREATE TABLE IF NOT EXISTS student_school_deployments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_user_id BIGINT UNSIGNED NOT NULL,
    coordinator_user_id BIGINT UNSIGNED NULL,
    geofence_id BIGINT UNSIGNED NOT NULL,
    semester_id INT UNSIGNED NOT NULL,
    deployment_order TINYINT UNSIGNED NOT NULL,
    required_seconds INT UNSIGNED NOT NULL DEFAULT 648000,
    rendered_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    deployment_status ENUM('locked','active','completed') NOT NULL DEFAULT 'locked',
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_student_school_order (student_user_id, semester_id, deployment_order),
    UNIQUE KEY uq_student_school_location (student_user_id, semester_id, geofence_id),
    KEY idx_student_school_active (student_user_id, semester_id, deployment_status),
    CONSTRAINT fk_student_school_deploy_user FOREIGN KEY (student_user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_student_school_deploy_coord FOREIGN KEY (coordinator_user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_student_school_deploy_geo FOREIGN KEY (geofence_id) REFERENCES geofence_locations(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_student_school_deploy_sem FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

ALTER TABLE attendance_records ADD COLUMN IF NOT EXISTS deployment_id BIGINT UNSIGNED NULL AFTER semester_id;
ALTER TABLE coordinator_deployments ADD COLUMN IF NOT EXISTS semester_id INT UNSIGNED NULL AFTER geofence_id;
ALTER TABLE coordinator_deployments ADD COLUMN IF NOT EXISTS deployment_order TINYINT UNSIGNED NULL AFTER semester_id;
ALTER TABLE coordinator_schools ADD COLUMN IF NOT EXISTS semester_id INT UNSIGNED NULL AFTER geofence_id;
ALTER TABLE coordinator_schools ADD COLUMN IF NOT EXISTS deployment_order TINYINT UNSIGNED NULL AFTER semester_id;

-- Drop the legacy one-school-per-coordinator constraints when upgrading the
-- original schema. The application also performs this upgrade defensively.
ALTER TABLE coordinator_deployments DROP INDEX uq_coordinator_deployment;
ALTER TABLE coordinator_schools DROP INDEX uq_coordinator_school;
ALTER TABLE coordinator_deployments ADD UNIQUE KEY uq_coordinator_deployment_order (coordinator_user_id, semester_id, deployment_order);
ALTER TABLE coordinator_schools ADD UNIQUE KEY uq_coordinator_school_order (coordinator_user_id, semester_id, deployment_order);
