<?php

declare(strict_types=1);

require_once __DIR__ . '/../middleware/role_check.php';
requireLogin();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function portalLiveTableSummaries(PDO $pdo): array
{
    // These are operational records that change what Head Teacher pages show.
    // Only counts, identifiers, and mutation timestamps are fingerprinted;
    // no student, coordinator, location, or journal content leaves the server.
    $tables = [
        'users', 'user_profiles', 'sections', 'section_students',
        'section_coordinators', 'semester_students', 'partner_schools',
        'geofence_locations', 'coordinator_deployments', 'coordinator_schools',
        'student_school_deployments', 'student_geofence_assignments',
        'journal_forms', 'journal_questions', 'journal_submissions',
        'evaluation_forms', 'evaluation_questions', 'evaluation_submissions',
        'checklist_sessions', 'student_weekly_checklist', 'attendance_records',
        'attendance_sessions', 'student_concerns',
    ];
    $placeholders = implode(',', array_fill(0, count($tables), '?'));
    $columnsStmt = $pdo->prepare(
        "SELECT TABLE_NAME, COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($placeholders)"
    );
    $columnsStmt->execute($tables);
    $columns = [];
    foreach ($columnsStmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $columns[(string) $column['TABLE_NAME']][(string) $column['COLUMN_NAME']] = true;
    }

    $summaries = [];
    foreach ($tables as $table) {
        if (empty($columns[$table])) {
            continue;
        }
        $tableColumns = $columns[$table];
        $idExpression = isset($tableColumns['id']) ? 'COALESCE(MAX(`id`), 0)' : '0';
        $changedColumn = isset($tableColumns['updated_at']) ? 'updated_at'
            : (isset($tableColumns['created_at']) ? 'created_at'
            : (isset($tableColumns['assigned_at']) ? 'assigned_at' : null));
        $changedExpression = $changedColumn === null
            ? '0'
            : 'COALESCE(MAX(UNIX_TIMESTAMP(`' . $changedColumn . '`)), 0)';
        $summary = $pdo->query(
            "SELECT COUNT(*) AS row_count, $idExpression AS max_id, $changedExpression AS changed_at
             FROM `$table`"
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $summaries[] = [$table, (int) ($summary['row_count'] ?? 0), (int) ($summary['max_id'] ?? 0), (int) ($summary['changed_at'] ?? 0)];
    }
    return $summaries;
}

try {
    $pdo = getPDO();
    $rows = $pdo->query(
        "SELECT sem.id, sem.semester_status, sem.practicum_status, sem.practicum_start_date,
                sy.id AS school_year_id, sy.year_status
         FROM semesters sem
         JOIN school_years sy ON sy.id = sem.school_year_id
         ORDER BY sem.id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
    $userRows = $pdo->query(
        "SELECT id, role_id, account_status
         FROM users
         ORDER BY id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    // This compact fingerprint covers portal-wide academic and roster state.
    // It detects semester lifecycle changes as well as student/coordinator
    // creation or account-status changes, without exposing profile data.
    $state = array_map(static fn(array $row): array => [
        (int) $row['id'],
        (string) $row['semester_status'],
        (string) $row['practicum_status'],
        (string) ($row['practicum_start_date'] ?? ''),
        (int) $row['school_year_id'],
        (string) $row['year_status'],
    ], $rows ?: []);

    $rosterState = array_map(static fn(array $row): array => [
        (int) $row['id'],
        (int) $row['role_id'],
        (string) $row['account_status'],
    ], $userRows ?: []);
    $operationalState = portalLiveTableSummaries($pdo);

    echo json_encode([
        'success' => true,
        'state_version' => hash('sha256', json_encode([$state, $rosterState, $operationalState], JSON_THROW_ON_ERROR)),
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    error_log('Portal live-state check failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to check portal updates.']);
}
