# Academic + Attendance Backend Changes

This repository now contains concrete backend artifacts for the Academic Control + Attendance module.

## Added SQL Schema
- `config/academic_attendance_schema.sql`
  - Fully normalized relational design
  - InnoDB tables with PK/FK/unique constraints
  - Role-integrity triggers
  - Attendance weekday/time validation triggers

## Added Repository Layer
- `models/UserRepository.php`
- `models/AcademicRepository.php`
- `models/AttendanceRepository.php`

## Added Service Layer
- `services/AcademicControlService.php`
- `services/AttendanceService.php`
- `services/ProfileService.php`

All write-heavy operations use transactions (`beginTransaction`, `commit`, `rollBack`) in service classes.

## Added Controller Examples
- `controllers/AcademicControlController.php`
- `controllers/AttendanceController.php`

## Reporting SQL (No stored derived totals)

### 1) Student weekly attendance summary
```sql
SELECT
    ar.student_user_id,
    MIN(ar.attendance_date) AS week_start,
    MAX(ar.attendance_date) AS week_end,
    SUM(ar.total_hours) AS weekly_total_hours
FROM attendance_records ar
WHERE ar.student_user_id = :student_user_id
  AND ar.attendance_date BETWEEN :week_start AND :week_end
GROUP BY ar.student_user_id;
```

### 2) Total public OJT hours
```sql
SELECT COALESCE(SUM(ar.total_hours), 0) AS total_public_school_hours
FROM attendance_records ar
JOIN ojt_types ot ON ot.id = ar.ojt_type_id
WHERE ar.student_user_id = :student_user_id
  AND ot.ojt_code = 'public_school';
```

### 3) Total private OJT hours
```sql
SELECT COALESCE(SUM(ar.total_hours), 0) AS total_private_school_hours
FROM attendance_records ar
JOIN ojt_types ot ON ot.id = ar.ojt_type_id
WHERE ar.student_user_id = :student_user_id
  AND ot.ojt_code = 'private_school';
```

### 4) Overall accumulated hours
```sql
SELECT COALESCE(SUM(total_hours), 0) AS overall_total_hours
FROM attendance_records
WHERE student_user_id = :student_user_id;
```

### 5) Section-level summary report
```sql
SELECT
    ss.section_id,
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
WHERE ss.section_id = :section_id
  AND ss.semester_id = :semester_id
GROUP BY ss.section_id, s.section_name, ss.student_user_id, up.last_name, up.first_name
ORDER BY up.last_name, up.first_name;
```

