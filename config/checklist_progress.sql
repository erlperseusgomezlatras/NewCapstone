CREATE TABLE evaluation_checklist_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_name VARCHAR(255) NOT NULL,
    points INT NOT NULL DEFAULT 1,
    display_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_evaluation_checklist_active (is_active),
    KEY idx_evaluation_checklist_display_order (display_order),
    CONSTRAINT fk_evaluation_checklist_created_by
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE checklist_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    section_id BIGINT UNSIGNED NOT NULL,
    semester_id INT UNSIGNED NOT NULL,
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
    section_id BIGINT UNSIGNED NOT NULL,
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
