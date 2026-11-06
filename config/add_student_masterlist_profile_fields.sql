ALTER TABLE user_profiles
    ADD COLUMN gender VARCHAR(32) NULL AFTER last_name,
    ADD COLUMN campus VARCHAR(150) NULL AFTER gender,
    ADD COLUMN college VARCHAR(200) NULL AFTER campus,
    ADD COLUMN course VARCHAR(200) NULL AFTER college,
    ADD COLUMN subject VARCHAR(200) NULL AFTER course,
    ADD COLUMN original_section VARCHAR(120) NULL AFTER subject,
    ADD COLUMN semester_name VARCHAR(120) NULL AFTER original_section,
    ADD COLUMN imported_semester_id INT UNSIGNED NULL AFTER semester_name;
