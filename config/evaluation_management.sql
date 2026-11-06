-- Dynamic Head Teacher performance evaluation forms.
-- Apply after practicum_rebuild_full.sql.
CREATE TABLE IF NOT EXISTS evaluation_forms (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    semester_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    target_school_type ENUM('public_school', 'private_school') NOT NULL DEFAULT 'public_school',
    institution_name VARCHAR(255) NOT NULL DEFAULT 'PHINMA - CAGAYAN DE ORO COLLEGE',
    department_name VARCHAR(255) NOT NULL DEFAULT 'COLLEGE OF EDUCATION',
    description TEXT NULL,
    instructions TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_evaluation_forms_semester FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_evaluation_forms_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_evaluation_forms_semester_status (semester_id, is_active),
    INDEX idx_evaluation_forms_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS evaluation_rating_scales (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    form_id BIGINT UNSIGNED NOT NULL,
    rating_value VARCHAR(32) NOT NULL,
    rating_label VARCHAR(120) NOT NULL,
    display_order INT NOT NULL DEFAULT 0,
    CONSTRAINT fk_evaluation_rating_form FOREIGN KEY (form_id) REFERENCES evaluation_forms(id) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_evaluation_rating_form_order (form_id, display_order, id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS evaluation_sections (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    form_id BIGINT UNSIGNED NOT NULL,
    section_title VARCHAR(255) NOT NULL,
    display_order INT NOT NULL DEFAULT 0,
    CONSTRAINT fk_evaluation_section_form FOREIGN KEY (form_id) REFERENCES evaluation_forms(id) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_evaluation_section_form_order (form_id, display_order, id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS evaluation_questions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    section_id BIGINT UNSIGNED NOT NULL,
    question_text TEXT NOT NULL,
    display_order INT NOT NULL DEFAULT 0,
    is_required TINYINT(1) NOT NULL DEFAULT 1,
    CONSTRAINT fk_evaluation_question_section FOREIGN KEY (section_id) REFERENCES evaluation_sections(id) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_evaluation_question_section_order (section_id, display_order, id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS evaluation_comment_fields (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    form_id BIGINT UNSIGNED NOT NULL,
    field_title VARCHAR(255) NOT NULL,
    placeholder VARCHAR(255) NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 0,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    display_order INT NOT NULL DEFAULT 0,
    CONSTRAINT fk_evaluation_comment_form FOREIGN KEY (form_id) REFERENCES evaluation_forms(id) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_evaluation_comment_form_order (form_id, display_order, id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS evaluation_certification_settings (
    form_id BIGINT UNSIGNED PRIMARY KEY,
    certification_text TEXT NULL,
    require_supervisor_name TINYINT(1) NOT NULL DEFAULT 1,
    require_signature TINYINT(1) NOT NULL DEFAULT 1,
    require_date TINYINT(1) NOT NULL DEFAULT 1,
    require_position TINYINT(1) NOT NULL DEFAULT 1,
    CONSTRAINT fk_evaluation_certification_form FOREIGN KEY (form_id) REFERENCES evaluation_forms(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;
