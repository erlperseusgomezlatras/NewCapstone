-- Legacy schema login seed
-- Use this only if your users table has: full_name, role, status columns.

USE practicum_system;

INSERT INTO users (full_name, email, password_hash, role, status)
VALUES
    ('Maria Santos', 'head.teacher@phinmaed.com', '$2y$10$g3jG9.Ub5CsiNAnrcvm9hes42gtbUF3zd0pUaYyzw2U/xJLGw6jr2', 'head_teacher', 'active'),
    ('Juan Dela Cruz', 'coordinator@phinmaed.com', '$2y$10$n8XlBQjhsLThi0hIfxAuj.E.rpbm.93OCp1sLSgugl/TmaV9mtKWW', 'coordinator', 'active'),
    ('Ana Reyes', 'student@phinmaed.com', '$2y$10$ioggfUvAfXgH5aJ9jJYHC.cZlsuZb6t9sATEmMV.kjACHVqZC0fKu', 'student', 'active')
ON DUPLICATE KEY UPDATE
    full_name = VALUES(full_name),
    password_hash = VALUES(password_hash),
    role = VALUES(role),
    status = VALUES(status);

