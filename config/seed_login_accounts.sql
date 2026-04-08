-- Seed login accounts for BOTH schema versions (legacy + modern).
-- Run this after your schema file.

USE practicum_system;

-- Passwords used for all demo accounts:
-- HeadTeacher@123
-- Coordinator@123
-- Student@123

-- =====================================================
-- MODERN NORMALIZED SCHEMA (roles/users/user_profiles)
-- =====================================================
INSERT INTO roles (role_code, role_name)
VALUES
    ('head_teacher', 'Head Teacher'),
    ('coordinator', 'Coordinator'),
    ('student', 'Student')
ON DUPLICATE KEY UPDATE role_name = VALUES(role_name);

INSERT INTO ojt_types (ojt_code, ojt_name, is_active)
VALUES
    ('public_school', 'Public School', 1),
    ('private_school', 'Private School', 1)
ON DUPLICATE KEY UPDATE
    ojt_name = VALUES(ojt_name),
    is_active = VALUES(is_active);

INSERT INTO users (role_id, school_id, email, password_hash, account_status)
SELECT
    r.id,
    t.school_id,
    t.email,
    t.password_hash,
    'active'
FROM (
    SELECT 'head_teacher' AS role_code, 'HT-0001' AS school_id, 'head.teacher@phinmaed.com' AS email, '$2y$10$g3jG9.Ub5CsiNAnrcvm9hes42gtbUF3zd0pUaYyzw2U/xJLGw6jr2' AS password_hash
    UNION ALL
    SELECT 'coordinator', 'COORD-0001', 'coordinator@phinmaed.com', '$2y$10$n8XlBQjhsLThi0hIfxAuj.E.rpbm.93OCp1sLSgugl/TmaV9mtKWW'
    UNION ALL
    SELECT 'student', 'STU-0001', 'student@phinmaed.com', '$2y$10$ioggfUvAfXgH5aJ9jJYHC.cZlsuZb6t9sATEmMV.kjACHVqZC0fKu'
) t
JOIN roles r ON r.role_code = t.role_code
ON DUPLICATE KEY UPDATE
    role_id = VALUES(role_id),
    password_hash = VALUES(password_hash),
    account_status = VALUES(account_status);

INSERT INTO user_profiles (user_id, first_name, middle_name, last_name, phone, photo_path)
SELECT u.id, p.first_name, p.middle_name, p.last_name, p.phone, NULL
FROM (
    SELECT 'head.teacher@phinmaed.com' AS email, 'Maria' AS first_name, NULL AS middle_name, 'Santos' AS last_name, '+63 917 000 0001' AS phone
    UNION ALL
    SELECT 'coordinator@phinmaed.com', 'Juan', NULL, 'Dela Cruz', '+63 917 000 0002'
    UNION ALL
    SELECT 'student@phinmaed.com', 'Ana', NULL, 'Reyes', '+63 917 000 0003'
) p
JOIN users u ON u.email = p.email
ON DUPLICATE KEY UPDATE
    first_name = VALUES(first_name),
    middle_name = VALUES(middle_name),
    last_name = VALUES(last_name),
    phone = VALUES(phone);

-- For legacy schema seed, use config/seed_login_accounts_legacy.sql
