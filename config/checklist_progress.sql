CREATE TABLE checklist_progress (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id BIGINT UNSIGNED NOT NULL,
    section_id BIGINT UNSIGNED NOT NULL,
    semester_id INT UNSIGNED NOT NULL,
    week DATE NOT NULL,
    orientation TINYINT(1) NOT NULL DEFAULT 0,
    uniform TINYINT(1) NOT NULL DEFAULT 0,
    observation TINYINT(1) NOT NULL DEFAULT 0,
    demo TINYINT(1) NOT NULL DEFAULT 0,
    portfolio TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_checklist_student_week (student_id, semester_id, week),
    KEY idx_checklist_section_week (section_id, semester_id, week),
    CONSTRAINT fk_checklist_progress_student
        FOREIGN KEY (student_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_checklist_progress_section
        FOREIGN KEY (section_id) REFERENCES sections(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_checklist_progress_semester
        FOREIGN KEY (semester_id) REFERENCES semesters(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;
