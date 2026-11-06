<?php
declare(strict_types=1);

/** Canonical session-first attendance status rules for portal surfaces. */
final class AttendanceStatusService
{
    public static function todayStatus(array $record, ?DateTimeImmutable $now = null): string
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
        $storedStatus = strtoupper((string) ($record['attendance_status'] ?? $record['today_attendance_status'] ?? ''));
        if ($storedStatus === 'PRESENT') {
            return 'PRESENT';
        }
        if (in_array($storedStatus, ['INCA', 'INCA_REJECTED'], true)) {
            return 'INCA';
        }
        if ($storedStatus === 'LIVE') {
            return 'In Progress';
        }
        $remarks = (string) ($record['remarks'] ?? $record['today_remarks'] ?? '');
        if (stripos($remarks, 'Missing timeout') !== false || stripos($remarks, 'INCA') !== false) {
            return 'INCA';
        }
        $hasTimeIn = !empty($record['today_time_in']) || !empty($record['time_in']) || (int) ($record['is_live'] ?? 0) === 1;
        $hasTimeOut = !empty($record['today_time_out']) || !empty($record['time_out']);
        if ($hasTimeIn && !$hasTimeOut) {
            $attendanceDate = (string) ($record['attendance_date'] ?? $record['today_attendance_date'] ?? '');
            if ($attendanceDate !== '' && $attendanceDate < $now->format('Y-m-d')) {
                return 'INCA';
            }
            return 'In Progress';
        }
        if ($hasTimeIn && $hasTimeOut) return 'PRESENT';
        return 'ABSENT';
    }
}
