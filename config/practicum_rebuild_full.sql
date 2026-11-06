USE practicum_system;

SET FOREIGN_KEY_CHECKS = 0;

DROP TRIGGER IF EXISTS trg_section_coordinators_role_ins;
DROP TRIGGER IF EXISTS trg_section_coordinators_role_upd;
DROP TRIGGER IF EXISTS trg_section_students_role_ins;
DROP TRIGGER IF EXISTS trg_section_students_role_upd;
DROP TRIGGER IF EXISTS trg_section_students_match_semester_ins;
DROP TRIGGER IF EXISTS trg_section_students_match_semester_upd;
DROP TRIGGER IF EXISTS trg_attendance_weekday_ins;
DROP TRIGGER IF EXISTS trg_attendance_weekday_upd;

DROP TABLE IF EXISTS attendance_geofence_logs;
DROP TABLE IF EXISTS attendance_sessions;
DROP TABLE IF EXISTS coordinator_deployments;
DROP TABLE IF EXISTS section_deployments;
DROP TABLE IF EXISTS student_geofence_assignments;
DROP TABLE IF EXISTS student_concerns;
DROP TABLE IF EXISTS checklist_student_confirmations;
DROP TABLE IF EXISTS student_weekly_checklist;
DROP TABLE IF EXISTS checklist_sessions;
DROP TABLE IF EXISTS journal_answers;
DROP TABLE IF EXISTS journal_submissions;
DROP TABLE IF EXISTS journal_question_options;
DROP TABLE IF EXISTS journal_questions;
DROP TABLE IF EXISTS journal_forms;
DROP TABLE IF EXISTS student_journals;
DROP TABLE IF EXISTS journal_reopen_audit;
DROP TABLE IF EXISTS partner_schools;
DROP TABLE IF EXISTS geofence_locations;
DROP TABLE IF EXISTS attendance_records;
DROP TABLE IF EXISTS attendance_override_logs;
DROP TABLE IF EXISTS evaluation_checklist_items;
DROP TABLE IF EXISTS coordinator_schools;
DROP TABLE IF EXISTS section_coordinators;
DROP TABLE IF EXISTS section_students;
DROP TABLE IF EXISTS semester_students;
DROP TABLE IF EXISTS sections;
DROP TABLE IF EXISTS semesters;
DROP TABLE IF EXISTS school_years;
DROP TABLE IF EXISTS school_terms;
DROP TABLE IF EXISTS user_school_year_status;
DROP TABLE IF EXISTS history_school_year_archive;
DROP TABLE IF EXISTS user_profiles;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS roles;
DROP TABLE IF EXISTS ojt_types;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE roles (
    id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_code VARCHAR(32) NOT NULL,
    role_name VARCHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_roles_role_code UNIQUE (role_code)
) ENGINE=InnoDB;

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_id TINYINT UNSIGNED NOT NULL,
    school_id VARCHAR(64) NOT NULL,
    email VARCHAR(191) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    account_status ENUM('active', 'inactive', 'archived') NOT NULL DEFAULT 'active',
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_users_school_id UNIQUE (school_id),
    CONSTRAINT uq_users_email UNIQUE (email),
    CONSTRAINT fk_users_role
        FOREIGN KEY (role_id) REFERENCES roles(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_users_role_id (role_id),
    INDEX idx_users_status (account_status)
) ENGINE=InnoDB;

CREATE TABLE user_profiles (
    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NOT NULL,
    gender VARCHAR(32) NULL,
    campus VARCHAR(150) NULL,
    college VARCHAR(200) NULL,
    course VARCHAR(200) NULL,
    subject VARCHAR(200) NULL,
    original_section VARCHAR(120) NULL,
    semester_name VARCHAR(120) NULL,
    imported_semester_id INT UNSIGNED NULL,
    phone VARCHAR(32) NULL,
    photo_path VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_profiles_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE ojt_types (
    id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ojt_code VARCHAR(32) NOT NULL,
    ojt_name VARCHAR(64) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_ojt_types_code UNIQUE (ojt_code)
) ENGINE=InnoDB;

CREATE TABLE school_years (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    year_label VARCHAR(16) NOT NULL UNIQUE,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    year_status ENUM('planned', 'active', 'closed', 'archived') NOT NULL DEFAULT 'planned',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_school_year_dates CHECK (end_date > start_date)
) ENGINE=InnoDB;

CREATE TABLE semesters (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    school_year_id INT UNSIGNED NOT NULL,
    semester_no TINYINT UNSIGNED NOT NULL,
    semester_name VARCHAR(32) NOT NULL,
    start_date DATE NOT NULL,
    practicum_start_date DATE NULL,
    practicum_status VARCHAR(24) NOT NULL DEFAULT 'not_configured',
    completed_at DATETIME NULL,
    end_date DATE NOT NULL,
    semester_status ENUM('planned', 'active', 'completed', 'closed', 'archived') NOT NULL DEFAULT 'planned',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_semester_school_year_no UNIQUE (school_year_id, semester_no),
    CONSTRAINT fk_semesters_school_year
        FOREIGN KEY (school_year_id) REFERENCES school_years(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_semester_dates CHECK (end_date > start_date),
    INDEX idx_semesters_school_year (school_year_id)
) ENGINE=InnoDB;

CREATE TABLE evaluation_checklist_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_name VARCHAR(255) NOT NULL,
    points INT NOT NULL DEFAULT 1,
    display_order INT NOT NULL DEFAULT 0,
    semester_id INT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_evaluation_checklist_active (is_active),
    INDEX idx_evaluation_checklist_display_order (display_order),
    INDEX idx_evaluation_checklist_semester (semester_id),
    CONSTRAINT fk_evaluation_checklist_semester
        FOREIGN KEY (semester_id) REFERENCES semesters(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_evaluation_checklist_created_by
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE sections (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    semester_id INT UNSIGNED NOT NULL,
    section_code VARCHAR(32) NOT NULL,
    section_name VARCHAR(100) NOT NULL,
    capacity SMALLINT UNSIGNED NOT NULL DEFAULT 50,
    section_status ENUM('active', 'inactive', 'archived') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_section_semester_code UNIQUE (semester_id, section_code),
    CONSTRAINT uq_sections_id_semester UNIQUE (id, semester_id),
    CONSTRAINT fk_sections_semester
        FOREIGN KEY (semester_id) REFERENCES semesters(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_sections_semester (semester_id)
) ENGINE=InnoDB;

CREATE TABLE section_students (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    section_id BIGINT UNSIGNED NOT NULL,
    semester_id INT UNSIGNED NOT NULL,
    student_user_id BIGINT UNSIGNED NOT NULL,
    enrollment_status ENUM('active', 'inactive', 'archived', 'dropped', 'completed') NOT NULL DEFAULT 'active',
    enrolled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_section_student UNIQUE (section_id, student_user_id),
    CONSTRAINT uq_semester_student UNIQUE (semester_id, student_user_id),
    CONSTRAINT uq_enrollment_triplet UNIQUE (section_id, semester_id, student_user_id),
    CONSTRAINT fk_section_students_section
        FOREIGN KEY (section_id) REFERENCES sections(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_section_students_semester
        FOREIGN KEY (semester_id) REFERENCES semesters(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_section_students_user
        FOREIGN KEY (student_user_id) REFERENCES users(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_section_students_student (student_user_id),
    INDEX idx_section_students_semester (semester_id)
) ENGINE=InnoDB;

CREATE TABLE section_coordinators (
    section_id BIGINT UNSIGNED NOT NULL,
    coordinator_user_id BIGINT UNSIGNED NOT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (section_id, coordinator_user_id),
    UNIQUE KEY uq_section_single_coordinator (section_id),
    CONSTRAINT fk_section_coordinators_section
        FOREIGN KEY (section_id) REFERENCES sections(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_section_coordinators_user
        FOREIGN KEY (coordinator_user_id) REFERENCES users(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_section_coordinators_user (coordinator_user_id)
) ENGINE=InnoDB;

CREATE TABLE semester_students (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    semester_id INT UNSIGNED NOT NULL,
    student_user_id BIGINT UNSIGNED NOT NULL,
    enrollment_status ENUM('active', 'inactive', 'archived', 'dropped', 'completed') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_semester_students_student (semester_id, student_user_id),
    KEY idx_semester_students_student (student_user_id),
    KEY idx_semester_students_status (semester_id, enrollment_status),
    CONSTRAINT fk_semester_students_semester
        FOREIGN KEY (semester_id) REFERENCES semesters(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_semester_students_user
        FOREIGN KEY (student_user_id) REFERENCES users(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE checklist_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    section_id BIGINT UNSIGNED NULL,
    semester_id INT UNSIGNED NULL,
    week_start DATE NOT NULL,
    checklist_date DATE NULL,
    closed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_checklist_session (section_id, semester_id, week_start),
    KEY idx_checklist_session_week (semester_id, week_start),
    CONSTRAINT fk_checklist_sessions_section
        FOREIGN KEY (section_id) REFERENCES sections(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_checklist_sessions_semester
        FOREIGN KEY (semester_id) REFERENCES semesters(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE student_weekly_checklist (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_user_id BIGINT UNSIGNED NOT NULL,
    section_id BIGINT UNSIGNED NULL,
    semester_id INT UNSIGNED NOT NULL,
    week_start DATE NOT NULL,
    checklist_item_id BIGINT UNSIGNED NOT NULL,
    is_checked TINYINT(1) NOT NULL DEFAULT 0,
    awarded_points INT NOT NULL DEFAULT 0,
    checked_by BIGINT UNSIGNED NOT NULL,
    checked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_student_week_item (student_user_id, week_start, checklist_item_id),
    KEY idx_student_weekly_checklist_section_week (section_id, semester_id, week_start),
    KEY idx_student_weekly_checklist_item (checklist_item_id),
    CONSTRAINT fk_student_weekly_checklist_student
        FOREIGN KEY (student_user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_student_weekly_checklist_section
        FOREIGN KEY (section_id) REFERENCES sections(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_student_weekly_checklist_semester
        FOREIGN KEY (semester_id) REFERENCES semesters(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_student_weekly_checklist_item
        FOREIGN KEY (checklist_item_id) REFERENCES evaluation_checklist_items(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_student_weekly_checklist_checked_by
        FOREIGN KEY (checked_by) REFERENCES users(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE student_journals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_user_id BIGINT UNSIGNED NOT NULL,
    semester_id INT UNSIGNED NOT NULL,
    week_start DATE NOT NULL,
    grateful_for TEXT NULL,
    proud_of TEXT NULL,
    words_to_inspire TEXT NULL,
    affirmations TEXT NULL,
    look_forward_to TEXT NULL,
    feeling ENUM('great', 'good', 'neutral', 'lean_not', 'not_good') NULL,
    status ENUM('pending', 'reviewed', 'approved', 'declined', 'resubmitted') NOT NULL DEFAULT 'pending',
    coordinator_feedback TEXT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_student_journal_week (student_user_id, semester_id, week_start),
    CONSTRAINT fk_student_journals_user
        FOREIGN KEY (student_user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_student_journals_semester
        FOREIGN KEY (semester_id) REFERENCES semesters(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_student_journals_student (student_user_id),
    INDEX idx_student_journals_semester (semester_id),
    INDEX idx_student_journals_week (week_start),
    INDEX idx_student_journals_status (status)
) ENGINE=InnoDB;

CREATE TABLE journal_forms (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    max_points INT UNSIGNED NOT NULL DEFAULT 50,
    semester_id INT UNSIGNED NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_journal_forms_semester (semester_id),
    INDEX idx_journal_forms_active (semester_id, is_active)
) ENGINE=InnoDB;

CREATE TABLE journal_questions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    form_id BIGINT UNSIGNED NOT NULL,
    question_text TEXT NOT NULL,
    question_type ENUM('paragraph','short_answer','multiple_choice','checkbox','dropdown') NOT NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_journal_questions_form (form_id),
    INDEX idx_journal_questions_sort (form_id, sort_order, id)
) ENGINE=InnoDB;

CREATE TABLE journal_question_options (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    question_id BIGINT UNSIGNED NOT NULL,
    option_text VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    INDEX idx_journal_question_options_question (question_id, sort_order, id)
) ENGINE=InnoDB;

CREATE TABLE journal_submissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_user_id BIGINT UNSIGNED NOT NULL,
    semester_id INT UNSIGNED NOT NULL,
    week_no INT NOT NULL,
    week_start DATE NOT NULL,
    form_id BIGINT UNSIGNED NOT NULL,
    points_awarded INT UNSIGNED NOT NULL DEFAULT 0,
    max_points INT UNSIGNED NOT NULL DEFAULT 50,
    status ENUM('pending','reviewed','approved','declined','resubmitted') NOT NULL DEFAULT 'pending',
    is_reopened TINYINT(1) NOT NULL DEFAULT 0,
    reopened_at DATETIME NULL,
    reopened_by BIGINT UNSIGNED NULL,
    coordinator_feedback TEXT NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_journal_submission_week (student_user_id, semester_id, week_start),
    INDEX idx_journal_submission_semester_week (semester_id, week_no, week_start),
    INDEX idx_journal_submission_form (form_id),
    INDEX idx_journal_submission_status (status)
) ENGINE=InnoDB;

CREATE TABLE journal_reopen_audit (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    coordinator_user_id BIGINT UNSIGNED NOT NULL,
    student_user_id BIGINT UNSIGNED NOT NULL,
    semester_id INT UNSIGNED NOT NULL,
    week_start DATE NOT NULL,
    reopened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_journal_reopen_audit_student_week (student_user_id, semester_id, week_start),
    INDEX idx_journal_reopen_audit_coordinator (coordinator_user_id, reopened_at)
) ENGINE=InnoDB;

CREATE TABLE journal_answers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    submission_id BIGINT UNSIGNED NOT NULL,
    question_id BIGINT UNSIGNED NOT NULL,
    question_text_snapshot TEXT NOT NULL,
    question_type_snapshot VARCHAR(30) NOT NULL,
    question_options_snapshot JSON NULL,
    answer_text LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_journal_answer_submission_question (submission_id, question_id),
    INDEX idx_journal_answers_submission (submission_id),
    INDEX idx_journal_answers_question (question_id)
) ENGINE=InnoDB;

CREATE TABLE student_concerns (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_user_id BIGINT UNSIGNED NOT NULL,
    coordinator_user_id BIGINT UNSIGNED NULL,
    category VARCHAR(64) NOT NULL DEFAULT 'General',
    subject VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    concern_status ENUM('pending', 'resolved', 'declined') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_concern_student
        FOREIGN KEY (student_user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_concern_coordinator
        FOREIGN KEY (coordinator_user_id) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_student_concerns_student (student_user_id),
    INDEX idx_student_concerns_status (concern_status),
    INDEX idx_student_concerns_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE concern_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    concern_id BIGINT UNSIGNED NOT NULL,
    sender_user_id BIGINT UNSIGNED NOT NULL,
    sender_role ENUM('student', 'coordinator', 'head_teacher') NOT NULL,
    message TEXT NOT NULL,
    attachment_path VARCHAR(255) NULL,
    attachment_name VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_concern_messages_concern
        FOREIGN KEY (concern_id) REFERENCES student_concerns(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_concern_messages_sender
        FOREIGN KEY (sender_user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_concern_messages_concern (concern_id, created_at),
    INDEX idx_concern_messages_sender (sender_user_id)
) ENGINE=InnoDB;

CREATE TABLE attendance_records (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_user_id BIGINT UNSIGNED NOT NULL,
    section_id BIGINT UNSIGNED NULL,
    semester_id INT UNSIGNED NOT NULL,
    deployment_id BIGINT UNSIGNED NULL,
    ojt_type_id TINYINT UNSIGNED NOT NULL,
    attendance_date DATE NOT NULL,
    time_in DATETIME NOT NULL,
    time_out DATETIME NULL,
    total_hours DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    geofence_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    geofence_last_seen_at DATETIME NULL,
    geofence_inside TINYINT(1) NOT NULL DEFAULT 0,
    remarks VARCHAR(255) NULL,
    is_override TINYINT(1) NOT NULL DEFAULT 0,
    override_by BIGINT UNSIGNED NULL,
    override_reason VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_attendance_student_date UNIQUE (student_user_id, attendance_date),
    CONSTRAINT fk_attendance_ojt
        FOREIGN KEY (ojt_type_id) REFERENCES ojt_types(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_total_hours_non_negative CHECK (total_hours >= 0),
    INDEX idx_attendance_date (attendance_date),
    INDEX idx_attendance_section_date (section_id, attendance_date),
    INDEX idx_attendance_semester_date (semester_id, attendance_date),
    INDEX idx_attendance_ojt_date (ojt_type_id, attendance_date),
    CONSTRAINT fk_attendance_override_by
        FOREIGN KEY (override_by) REFERENCES users(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE student_school_deployments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_user_id BIGINT UNSIGNED NOT NULL,
    coordinator_user_id BIGINT UNSIGNED NULL,
    geofence_id BIGINT UNSIGNED NOT NULL,
    semester_id INT UNSIGNED NOT NULL,
    deployment_order TINYINT UNSIGNED NOT NULL,
    required_seconds INT UNSIGNED NOT NULL DEFAULT 648000,
    rendered_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    deployment_status ENUM('locked','active','completed') NOT NULL DEFAULT 'locked',
    started_at DATETIME NULL, completed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_student_school_order (student_user_id, semester_id, deployment_order),
    UNIQUE KEY uq_student_school_location (student_user_id, semester_id, geofence_id),
    KEY idx_student_school_active (student_user_id, semester_id, deployment_status)
) ENGINE=InnoDB;

CREATE TABLE attendance_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attendance_id BIGINT UNSIGNED NOT NULL,
    event_type ENUM('time_in', 'time_out') NOT NULL,
    event_time DATETIME NOT NULL,
    source VARCHAR(32) NOT NULL DEFAULT 'manual',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_attendance_sessions_attendance
        FOREIGN KEY (attendance_id) REFERENCES attendance_records(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_attendance_sessions_attendance (attendance_id),
    INDEX idx_attendance_sessions_event_time (event_time)
) ENGINE=InnoDB;

CREATE TABLE geofence_locations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    latitude DECIMAL(10,7) NOT NULL,
    longitude DECIMAL(10,7) NOT NULL,
    radius_meters DECIMAL(8,2) NOT NULL,
    school_type ENUM('public_school', 'private_school') NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_geofence_school_type (school_type),
    INDEX idx_geofence_active (is_active)
) ENGINE=InnoDB;

CREATE TABLE partner_schools (
    geofence_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    address VARCHAR(255) NOT NULL DEFAULT '',
    daily_cap_hours DECIMAL(4,2) NOT NULL DEFAULT 8.00,
    school_status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_partner_schools_geofence
        FOREIGN KEY (geofence_id) REFERENCES geofence_locations(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE attendance_override_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attendance_id BIGINT UNSIGNED NOT NULL,
    coordinator_id BIGINT UNSIGNED NOT NULL,
    reason TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_attendance_override_logs_attendance
        FOREIGN KEY (attendance_id) REFERENCES attendance_records(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_attendance_override_logs_coordinator
        FOREIGN KEY (coordinator_id) REFERENCES users(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_attendance_override_logs_attendance (attendance_id),
    INDEX idx_attendance_override_logs_coordinator (coordinator_id)
) ENGINE=InnoDB;

CREATE TABLE coordinator_schools (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    coordinator_user_id BIGINT UNSIGNED NOT NULL,
    geofence_id BIGINT UNSIGNED NOT NULL,
    semester_id INT UNSIGNED NULL,
    deployment_order TINYINT UNSIGNED NOT NULL,
    assigned_by BIGINT UNSIGNED NOT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_coordinator_school_order (coordinator_user_id, semester_id, deployment_order),
    KEY idx_coordinator_schools_geofence (geofence_id),
    CONSTRAINT fk_coordinator_schools_user
        FOREIGN KEY (coordinator_user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_coordinator_schools_geofence
        FOREIGN KEY (geofence_id) REFERENCES geofence_locations(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_coordinator_schools_assigned_by
        FOREIGN KEY (assigned_by) REFERENCES users(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE coordinator_deployments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    coordinator_user_id BIGINT UNSIGNED NOT NULL,
    geofence_id BIGINT UNSIGNED NOT NULL,
    semester_id INT UNSIGNED NULL,
    deployment_order TINYINT UNSIGNED NOT NULL,
    deployment_status ENUM('active', 'ended') NOT NULL DEFAULT 'active',
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_coordinator_deployment_order (coordinator_user_id, semester_id, deployment_order),
    KEY idx_coordinator_deployments_geofence (geofence_id),
    KEY idx_coordinator_deployments_status (deployment_status),
    CONSTRAINT fk_coordinator_deployments_user
        FOREIGN KEY (coordinator_user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_coordinator_deployments_geofence
        FOREIGN KEY (geofence_id) REFERENCES geofence_locations(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE student_geofence_assignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_user_id BIGINT UNSIGNED NOT NULL,
    geofence_id BIGINT UNSIGNED NOT NULL,
    coordinator_user_id BIGINT UNSIGNED NULL,
    semester_id INT UNSIGNED NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    assignment_status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    CONSTRAINT uq_student_geofence_semester UNIQUE (student_user_id, geofence_id, semester_id),
    CONSTRAINT fk_student_geofence_user
        FOREIGN KEY (student_user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_student_geofence_geofence
        FOREIGN KEY (geofence_id) REFERENCES geofence_locations(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_student_geofence_coordinator
        FOREIGN KEY (coordinator_user_id) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_student_geofence_semester
        FOREIGN KEY (semester_id) REFERENCES semesters(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_student_geofence_user (student_user_id),
    INDEX idx_student_geofence_geofence (geofence_id),
    INDEX idx_student_geofence_coordinator_semester (coordinator_user_id, semester_id)
) ENGINE=InnoDB;

CREATE TABLE attendance_geofence_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attendance_id BIGINT UNSIGNED NOT NULL,
    geofence_id BIGINT UNSIGNED NOT NULL,
    latitude DECIMAL(10,7) NOT NULL,
    longitude DECIMAL(10,7) NOT NULL,
    distance_from_center DECIMAL(10,2) NOT NULL,
    inside_radius TINYINT(1) NOT NULL,
    logged_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_attendance_geo_attendance
        FOREIGN KEY (attendance_id) REFERENCES attendance_records(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_attendance_geo_geofence
        FOREIGN KEY (geofence_id) REFERENCES geofence_locations(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_attendance_geo_attendance (attendance_id),
    INDEX idx_attendance_geo_geofence (geofence_id),
    INDEX idx_attendance_geo_inside (inside_radius)
) ENGINE=InnoDB;

DELIMITER $$

CREATE TRIGGER trg_section_coordinators_role_ins
BEFORE INSERT ON section_coordinators
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM users u
        JOIN roles r ON r.id = u.role_id
        WHERE u.id = NEW.coordinator_user_id
          AND r.role_code = 'coordinator'
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Assigned user must have coordinator role';
    END IF;
END$$

CREATE TRIGGER trg_section_coordinators_role_upd
BEFORE UPDATE ON section_coordinators
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM users u
        JOIN roles r ON r.id = u.role_id
        WHERE u.id = NEW.coordinator_user_id
          AND r.role_code = 'coordinator'
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Assigned user must have coordinator role';
    END IF;
END$$

CREATE TRIGGER trg_section_students_role_ins
BEFORE INSERT ON section_students
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM users u
        JOIN roles r ON r.id = u.role_id
        WHERE u.id = NEW.student_user_id
          AND r.role_code = 'student'
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Enrolled user must have student role';
    END IF;
END$$

CREATE TRIGGER trg_section_students_role_upd
BEFORE UPDATE ON section_students
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM users u
        JOIN roles r ON r.id = u.role_id
        WHERE u.id = NEW.student_user_id
          AND r.role_code = 'student'
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Enrolled user must have student role';
    END IF;
END$$

CREATE TRIGGER trg_section_students_match_semester_ins
BEFORE INSERT ON section_students
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM sections s
        WHERE s.id = NEW.section_id
          AND s.semester_id = NEW.semester_id
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'section_id does not belong to the provided semester_id';
    END IF;
END$$

CREATE TRIGGER trg_section_students_match_semester_upd
BEFORE UPDATE ON section_students
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM sections s
        WHERE s.id = NEW.section_id
          AND s.semester_id = NEW.semester_id
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'section_id does not belong to the provided semester_id';
    END IF;
END$$

CREATE TRIGGER trg_attendance_weekday_ins
BEFORE INSERT ON attendance_records
FOR EACH ROW
BEGIN
    IF WEEKDAY(NEW.attendance_date) > 4 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Attendance allowed only Monday-Friday';
    END IF;
    IF NEW.time_out IS NOT NULL AND NEW.time_out < NEW.time_in THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'time_out cannot be earlier than time_in';
    END IF;
END$$

CREATE TRIGGER trg_attendance_weekday_upd
BEFORE UPDATE ON attendance_records
FOR EACH ROW
BEGIN
    IF WEEKDAY(NEW.attendance_date) > 4 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Attendance allowed only Monday-Friday';
    END IF;
    IF NEW.time_out IS NOT NULL AND NEW.time_out < NEW.time_in THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'time_out cannot be earlier than time_in';
    END IF;
END$$

DELIMITER ;

-- Seed core reference data
INSERT INTO roles (role_code, role_name)
VALUES ('head_teacher', 'Head Teacher'),
       ('coordinator', 'Coordinator'),
       ('student', 'Student')
ON DUPLICATE KEY UPDATE role_name = VALUES(role_name);

INSERT INTO ojt_types (ojt_code, ojt_name, is_active)
VALUES ('public_school', 'Public School', 1),
       ('private_school', 'Private School', 1)
ON DUPLICATE KEY UPDATE
    ojt_name = VALUES(ojt_name),
    is_active = VALUES(is_active);

-- Seed users
INSERT INTO users (role_id, school_id, email, password_hash, account_status)
SELECT r.id, t.school_id, t.email, t.password_hash, 'active'
FROM (
    SELECT 'head_teacher' AS role_code, 'HT-0001' AS school_id, 'head.teacher@phinmaed.com' AS email, '$2y$10$g3jG9.Ub5CsiNAnrcvm9hes42gtbUF3zd0pUaYyzw2U/xJLGw6jr2' AS password_hash
    UNION ALL
    SELECT 'coordinator', 'COORD-0001', 'coordinator@phinmaed.com', '$2y$10$n8XlBQjhsLThi0hIfxAuj.E.rpbm.93OCp1sLSgugl/TmaV9mtKWW'
    UNION ALL
    SELECT 'student', 'STU-0001', 'student@phinmaed.com', '$2y$10$ioggfUvAfXgH5aJ9jJYHC.cZlsuZb6t9sATEmMV.kjACHVqZC0fKu'
    UNION ALL
    SELECT 'student', 'STU-0002', 'student2@phinmaed.com', '$2y$10$ioggfUvAfXgH5aJ9jJYHC.cZlsuZb6t9sATEmMV.kjACHVqZC0fKu'
) t
JOIN roles r ON r.role_code = t.role_code
WHERE t.role_code = 'head_teacher'
ON DUPLICATE KEY UPDATE
    role_id = VALUES(role_id),
    password_hash = VALUES(password_hash),
    account_status = VALUES(account_status);

INSERT INTO user_profiles (user_id, first_name, middle_name, last_name, phone, photo_path)
SELECT u.id, p.first_name, p.middle_name, p.last_name, p.phone, NULL
FROM (
    SELECT 'head.teacher@phinmaed.com' AS email, 'Maria' AS first_name, NULL AS middle_name, 'Santos' AS last_name, '+63 917 000 1001' AS phone
    UNION ALL
    SELECT 'coordinator@phinmaed.com', 'Juan', NULL, 'Dela Cruz', '+63 917 000 1002'
    UNION ALL
    SELECT 'student@phinmaed.com', 'Ana', NULL, 'Reyes', '+63 917 000 1003'
    UNION ALL
    SELECT 'student2@phinmaed.com', 'Bea', NULL, 'Dizon', '+63 917 000 1004'
) p
JOIN users u ON u.email = p.email
WHERE p.email = 'head.teacher@phinmaed.com'
ON DUPLICATE KEY UPDATE
    first_name = VALUES(first_name),
    middle_name = VALUES(middle_name),
    last_name = VALUES(last_name),
    phone = VALUES(phone);

INSERT INTO school_years (year_label, start_date, end_date, year_status)
VALUES ('2026-2027', '2026-06-01', '2027-05-31', 'active')
ON DUPLICATE KEY UPDATE
    start_date = VALUES(start_date),
    end_date = VALUES(end_date),
    year_status = VALUES(year_status);

INSERT INTO semesters (school_year_id, semester_no, semester_name, start_date, practicum_start_date, end_date, semester_status)
SELECT sy.id, 1, '1st Semester', '2026-06-01', NULL, '2026-10-31', 'planned'
FROM school_years sy
WHERE sy.year_label = '2026-2027'
ON DUPLICATE KEY UPDATE
    semester_name = VALUES(semester_name),
    start_date = VALUES(start_date),
    practicum_start_date = NULL,
    end_date = VALUES(end_date),
    semester_status = VALUES(semester_status);

INSERT INTO sections (semester_id, section_code, section_name, capacity, section_status)
SELECT sem.id, 'SEC-A', 'Section A', 50, 'active'
FROM semesters sem
JOIN school_years sy ON sy.id = sem.school_year_id
WHERE sy.year_label = '2026-2027' AND sem.semester_no = 1
ON DUPLICATE KEY UPDATE
    section_name = VALUES(section_name),
    capacity = VALUES(capacity),
    section_status = VALUES(section_status);

INSERT INTO section_students (section_id, semester_id, student_user_id, enrollment_status)
SELECT sec.id, sem.id, u.id, 'active'
FROM sections sec
JOIN semesters sem ON sem.id = sec.semester_id
JOIN school_years sy ON sy.id = sem.school_year_id
JOIN users u ON u.email IN ('student@phinmaed.com', 'student2@phinmaed.com')
WHERE sy.year_label = '2026-2027'
  AND sem.semester_no = 1
  AND sec.section_code = 'SEC-A'
ON DUPLICATE KEY UPDATE
    enrollment_status = VALUES(enrollment_status);

INSERT INTO semester_students (semester_id, student_user_id, enrollment_status)
SELECT sem.id, u.id, 'active'
FROM semesters sem
JOIN school_years sy ON sy.id = sem.school_year_id
JOIN users u ON u.email IN ('student@phinmaed.com', 'student2@phinmaed.com')
WHERE sy.year_label = '2026-2027'
  AND sem.semester_no = 1
ON DUPLICATE KEY UPDATE
    enrollment_status = VALUES(enrollment_status),
    updated_at = CURRENT_TIMESTAMP;

/* Partner school seed data is intentionally disabled. Add schools from the application. */
/*
INSERT INTO geofence_locations (name, latitude, longitude, radius_meters, school_type, is_active)
VALUES
    ('CDO Public Campus', 8.4795000, 124.6473000, 120.00, 'public_school', 1),
    ('CDO Private Partner', 8.4820000, 124.6502000, 120.00, 'private_school', 1)
ON DUPLICATE KEY UPDATE
    latitude = VALUES(latitude),
    longitude = VALUES(longitude),
    radius_meters = VALUES(radius_meters),
    school_type = VALUES(school_type),
    is_active = VALUES(is_active);
*/

INSERT INTO partner_schools (geofence_id, address, daily_cap_hours, school_status)
SELECT g.id, p.address, p.daily_cap_hours, p.school_status
FROM (
    SELECT 'CDO Public Campus' AS name, 'Cagayan de Oro City, Misamis Oriental' AS address, 8.00 AS daily_cap_hours, 'active' AS school_status
    UNION ALL
    SELECT 'CDO Private Partner', 'Carmen, Cagayan de Oro City', 8.00, 'active'
) p
JOIN geofence_locations g ON g.name = p.name
ON DUPLICATE KEY UPDATE
    address = VALUES(address),
    daily_cap_hours = VALUES(daily_cap_hours),
    school_status = VALUES(school_status);

INSERT INTO coordinator_schools (coordinator_user_id, geofence_id, semester_id, deployment_order, assigned_by)
SELECT coordinator.id, g.id, sem.id, 1, head_teacher.id
FROM users coordinator
JOIN users head_teacher ON head_teacher.email = 'head.teacher@phinmaed.com'
JOIN geofence_locations g ON g.name = 'CDO Public Campus'
CROSS JOIN semesters sem
JOIN school_years sy ON sy.id = sem.school_year_id
WHERE coordinator.email = 'coordinator@phinmaed.com'
  AND sy.year_label = '2026-2027' AND sem.semester_no = 1
ON DUPLICATE KEY UPDATE
    geofence_id = VALUES(geofence_id),
    assigned_by = VALUES(assigned_by),
    assigned_at = CURRENT_TIMESTAMP;

INSERT INTO coordinator_deployments (coordinator_user_id, geofence_id, semester_id, deployment_order, deployment_status, started_at)
SELECT coordinator.id, g.id, sem.id, 1, 'active', NOW()
FROM users coordinator
JOIN geofence_locations g ON g.name = 'CDO Public Campus'
JOIN roles r ON r.id = coordinator.role_id
CROSS JOIN semesters sem
JOIN school_years sy ON sy.id = sem.school_year_id
WHERE coordinator.email = 'coordinator@phinmaed.com'
  AND r.role_code = 'coordinator'
  AND sy.year_label = '2026-2027' AND sem.semester_no = 1
ON DUPLICATE KEY UPDATE
    geofence_id = VALUES(geofence_id),
    deployment_status = VALUES(deployment_status),
    started_at = VALUES(started_at),
    ended_at = NULL;

INSERT INTO student_geofence_assignments (student_user_id, geofence_id, coordinator_user_id, semester_id, assignment_status)
SELECT u.id, g.id, coordinator.id, sem.id, 'active'
FROM users u
JOIN geofence_locations g ON g.name = 'CDO Public Campus'
JOIN users coordinator ON coordinator.email = 'coordinator@phinmaed.com'
CROSS JOIN semesters sem
JOIN school_years sy ON sy.id = sem.school_year_id
WHERE u.email = 'student@phinmaed.com'
  AND sy.year_label = '2026-2027'
  AND sem.semester_no = 1
ON DUPLICATE KEY UPDATE
    coordinator_user_id = VALUES(coordinator_user_id),
    semester_id = VALUES(semester_id),
    assignment_status = VALUES(assignment_status),
    assigned_at = CURRENT_TIMESTAMP;

INSERT INTO student_geofence_assignments (student_user_id, geofence_id, coordinator_user_id, semester_id, assignment_status)
SELECT u.id, g.id, coordinator.id, sem.id, 'active'
FROM users u
JOIN geofence_locations g ON g.name = 'CDO Private Partner'
JOIN users coordinator ON coordinator.email = 'coordinator@phinmaed.com'
CROSS JOIN semesters sem
JOIN school_years sy ON sy.id = sem.school_year_id
WHERE u.email = 'student2@phinmaed.com'
  AND sy.year_label = '2026-2027'
  AND sem.semester_no = 1
ON DUPLICATE KEY UPDATE
    coordinator_user_id = VALUES(coordinator_user_id),
    semester_id = VALUES(semester_id),
    assignment_status = VALUES(assignment_status),
    assigned_at = CURRENT_TIMESTAMP;

INSERT INTO attendance_records (
    student_user_id,
    section_id,
    semester_id,
    ojt_type_id,
    attendance_date,
    time_in,
    time_out,
    total_hours,
    remarks
)
SELECT
    u.id,
    sec.id,
    sem.id,
    ot.id,
    '2026-06-02',
    '2026-06-02 08:00:00',
    '2026-06-02 16:00:00',
    8.00,
    'On time'
FROM users u
JOIN sections sec ON sec.section_code = 'SEC-A'
JOIN semesters sem ON sem.id = sec.semester_id
JOIN school_years sy ON sy.id = sem.school_year_id
JOIN ojt_types ot ON ot.ojt_code = 'public_school'
WHERE u.email = 'student@phinmaed.com'
  AND sy.year_label = '2026-2027'
  AND sem.semester_no = 1
ON DUPLICATE KEY UPDATE
    time_in = VALUES(time_in),
    time_out = VALUES(time_out),
    total_hours = VALUES(total_hours),
    remarks = VALUES(remarks);

INSERT INTO attendance_geofence_logs (
    attendance_id,
    geofence_id,
    latitude,
    longitude,
    distance_from_center,
    inside_radius
)
SELECT ar.id, g.id, 8.4795100, 124.6472800, 3.20, 1
FROM attendance_records ar
JOIN users u ON u.id = ar.student_user_id
JOIN geofence_locations g ON g.name = 'CDO Public Campus'
WHERE u.email = 'student@phinmaed.com'
  AND ar.attendance_date = '2026-06-02'
ON DUPLICATE KEY UPDATE
    latitude = VALUES(latitude),
    longitude = VALUES(longitude),
    distance_from_center = VALUES(distance_from_center),
    inside_radius = VALUES(inside_radius),
    logged_at = CURRENT_TIMESTAMP;

-- ===========================
-- REPORT QUERIES (EXECUTABLE)
-- ===========================

SET @student_user_id := (SELECT id FROM users WHERE email = 'student@phinmaed.com' LIMIT 1);
SET @week_start := '2026-06-02';
SET @week_end := '2026-06-06';
SET @section_id := (SELECT id FROM sections WHERE section_code = 'SEC-A' LIMIT 1);
SET @semester_id := (SELECT semester_id FROM sections WHERE id = @section_id LIMIT 1);

-- 1) Weekly hours
SELECT
    ar.student_user_id,
    SUM(ar.total_hours) AS weekly_total_hours
FROM attendance_records ar
WHERE ar.student_user_id = @student_user_id
  AND ar.attendance_date BETWEEN @week_start AND @week_end
GROUP BY ar.student_user_id;

-- 2) Public OJT total
SELECT
    ar.student_user_id,
    COALESCE(SUM(ar.total_hours), 0) AS total_public_hours
FROM attendance_records ar
JOIN ojt_types ot ON ot.id = ar.ojt_type_id
WHERE ar.student_user_id = @student_user_id
  AND ot.ojt_code = 'public_school'
GROUP BY ar.student_user_id;

-- 3) Private OJT total
SELECT
    ar.student_user_id,
    COALESCE(SUM(ar.total_hours), 0) AS total_private_hours
FROM attendance_records ar
JOIN ojt_types ot ON ot.id = ar.ojt_type_id
WHERE ar.student_user_id = @student_user_id
  AND ot.ojt_code = 'private_school'
GROUP BY ar.student_user_id;

-- 4) Overall accumulated total
SELECT
    ar.student_user_id,
    COALESCE(SUM(ar.total_hours), 0) AS overall_total_hours
FROM attendance_records ar
WHERE ar.student_user_id = @student_user_id
GROUP BY ar.student_user_id;

-- 5) Section summary
SELECT
    ss.section_id,
    s.section_code,
    s.section_name,
    ss.student_user_id,
    up.last_name,
    up.first_name,
    COALESCE(SUM(ar.total_hours), 0) AS total_hours
FROM section_students ss
JOIN sections s ON s.id = ss.section_id
JOIN user_profiles up ON up.user_id = ss.student_user_id
LEFT JOIN attendance_records ar
    ON ar.section_id = ss.section_id
   AND ar.semester_id = ss.semester_id
   AND ar.student_user_id = ss.student_user_id
WHERE ss.section_id = @section_id
  AND ss.semester_id = @semester_id
GROUP BY
    ss.section_id,
    s.section_code,
    s.section_name,
    ss.student_user_id,
    up.last_name,
    up.first_name
ORDER BY up.last_name, up.first_name;

-- Geofence validation logic (nearest assigned geofence + inside radius flag)
SET @lat := 8.4795100;
SET @lng := 124.6472800;

SELECT
    g.id AS geofence_id,
    g.name,
    g.radius_meters,
    6371000 * 2 * ASIN(
        SQRT(
            POWER(SIN(RADIANS((@lat - g.latitude) / 2)), 2)
            + COS(RADIANS(g.latitude))
            * COS(RADIANS(@lat))
            * POWER(SIN(RADIANS((@lng - g.longitude) / 2)), 2)
        )
    ) AS distance_from_center,
    CASE
        WHEN (
            6371000 * 2 * ASIN(
                SQRT(
                    POWER(SIN(RADIANS((@lat - g.latitude) / 2)), 2)
                    + COS(RADIANS(g.latitude))
                    * COS(RADIANS(@lat))
                    * POWER(SIN(RADIANS((@lng - g.longitude) / 2)), 2)
                )
            )
        ) <= g.radius_meters
        THEN 1 ELSE 0
    END AS inside_radius
FROM geofence_locations g
JOIN student_geofence_assignments sga
    ON sga.geofence_id = g.id
   AND sga.student_user_id = @student_user_id
   AND sga.assignment_status = 'active'
WHERE g.is_active = 1
ORDER BY inside_radius DESC, distance_from_center ASC
LIMIT 1;
