INSERT INTO roles (role_code, role_name)
VALUES ('supervisor', 'Supervisor')
ON DUPLICATE KEY UPDATE role_name = VALUES(role_name);

CREATE TABLE IF NOT EXISTS supervisor_profiles (
    user_id BIGINT UNSIGNED PRIMARY KEY,
    partnered_school VARCHAR(255) NOT NULL,
    position_title VARCHAR(150) NULL,
    CONSTRAINT fk_supervisor_profile_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS supervisor_assignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id BIGINT UNSIGNED NOT NULL,
    supervisor_id BIGINT UNSIGNED NOT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_supervisor_student UNIQUE (student_id),
    CONSTRAINT fk_assignment_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_assignment_supervisor FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_assignment_supervisor (supervisor_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS supervisor_assignment_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id BIGINT UNSIGNED NOT NULL,
    supervisor_id BIGINT UNSIGNED NOT NULL,
    supervisor_name VARCHAR(255) NULL,
    supervisor_email VARCHAR(255) NULL,
    partnered_school VARCHAR(255) NULL,
    position_title VARCHAR(150) NULL,
    semester_id INT UNSIGNED NOT NULL,
    assignment_status ENUM('archived') NOT NULL DEFAULT 'archived',
    assigned_at DATETIME NULL,
    archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_supervisor_assignment_history (student_id, semester_id),
    CONSTRAINT fk_supervisor_history_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_supervisor_history_supervisor FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_supervisor_history_semester FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS supervisor_semester_access (
    supervisor_id BIGINT UNSIGNED NOT NULL,
    semester_id INT UNSIGNED NOT NULL,
    access_status ENUM('active', 'archived', 'inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (supervisor_id, semester_id),
    CONSTRAINT fk_supervisor_access_supervisor FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_supervisor_access_semester FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS supervisor_student_selections (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supervisor_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    semester_id INT UNSIGNED NOT NULL,
    selected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_supervisor_selection_student_semester (student_id, semester_id),
    UNIQUE KEY uq_supervisor_selection_supervisor_student_semester (supervisor_id, student_id, semester_id),
    KEY idx_supervisor_selection_supervisor_semester (supervisor_id, semester_id),
    CONSTRAINT fk_supervisor_selection_supervisor FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_supervisor_selection_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_supervisor_selection_semester FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS evaluation_submissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    evaluation_form_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    supervisor_id BIGINT UNSIGNED NOT NULL,
    semester_id INT UNSIGNED NOT NULL,
    deployment_id BIGINT UNSIGNED NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    overall_score DECIMAL(10,2) NULL,
    comments TEXT NULL,
    CONSTRAINT uq_evaluation_student_form_semester_deployment_supervisor UNIQUE (evaluation_form_id, student_id, semester_id, deployment_id, supervisor_id),
    CONSTRAINT fk_submission_form FOREIGN KEY (evaluation_form_id) REFERENCES evaluation_forms(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_submission_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_submission_supervisor FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_submission_semester FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_submission_student (student_id),
    INDEX idx_submission_supervisor (supervisor_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS evaluation_answers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    submission_id BIGINT UNSIGNED NOT NULL,
    question_id BIGINT UNSIGNED NOT NULL,
    rating_value VARCHAR(32) NOT NULL,
    CONSTRAINT uq_evaluation_answer UNIQUE (submission_id, question_id),
    CONSTRAINT fk_answer_submission FOREIGN KEY (submission_id) REFERENCES evaluation_submissions(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_answer_question FOREIGN KEY (question_id) REFERENCES evaluation_questions(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS evaluation_submission_comments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    submission_id BIGINT UNSIGNED NOT NULL,
    comment_field_id BIGINT UNSIGNED NOT NULL,
    comment_text TEXT NULL,
    CONSTRAINT uq_submission_comment UNIQUE (submission_id, comment_field_id),
    CONSTRAINT fk_submission_comment_submission FOREIGN KEY (submission_id) REFERENCES evaluation_submissions(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_submission_comment_field FOREIGN KEY (comment_field_id) REFERENCES evaluation_comment_fields(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS evaluation_signatures (
    submission_id BIGINT UNSIGNED PRIMARY KEY,
    signature_image MEDIUMBLOB NOT NULL,
    mime_type VARCHAR(50) NOT NULL DEFAULT 'image/png',
    signed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_signature_submission FOREIGN KEY (submission_id) REFERENCES evaluation_submissions(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS evaluation_submission_certification (
    submission_id BIGINT UNSIGNED PRIMARY KEY,
    supervisor_name VARCHAR(255) NULL,
    supervisor_position VARCHAR(255) NULL,
    signed_date DATE NULL,
    CONSTRAINT fk_submission_certification FOREIGN KEY (submission_id) REFERENCES evaluation_submissions(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS evaluation_submission_ratings (
    submission_id BIGINT UNSIGNED NOT NULL,
    rating_value VARCHAR(32) NOT NULL,
    rating_label VARCHAR(120) NOT NULL DEFAULT '',
    display_order INT NOT NULL,
    PRIMARY KEY (submission_id, display_order),
    CONSTRAINT fk_submission_rating_submission FOREIGN KEY (submission_id) REFERENCES evaluation_submissions(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;
