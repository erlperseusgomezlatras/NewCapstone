-- Add the explicit practicum lifecycle without altering existing attendance data.
ALTER TABLE semesters
    ADD COLUMN practicum_status VARCHAR(24) NOT NULL DEFAULT 'not_configured'
    AFTER practicum_start_date;

UPDATE semesters
SET practicum_status = CASE
    WHEN semester_status = 'active' AND practicum_start_date IS NOT NULL THEN 'ongoing'
    WHEN semester_status = 'active' THEN 'not_yet_started'
    WHEN semester_status IN ('closed', 'archived') THEN 'completed'
    ELSE 'not_configured'
END;
