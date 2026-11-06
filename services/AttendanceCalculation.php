<?php

declare(strict_types=1);

final class AttendanceCalculation
{
    public const DAILY_CAP_HOURS = 8.0;
    // Mobile browsers can delay location heartbeats. Preserve the full
    // interval while the last reported location is inside the geofence.
    public const MAX_LIVE_GAP_SECONDS = 86400;

    public static function renderedHoursSql(string $alias = 'ar', ?string $currentDateSql = null): string
    {
        $currentDateSql ??= 'CURDATE()';

        return "CASE
                    WHEN EXISTS (
                        SELECT 1
                        FROM system_holidays system_holiday
                        WHERE system_holiday.holiday_date = {$alias}.attendance_date
                    ) OR EXISTS (
                        SELECT 1
                        FROM student_school_deployments school_holiday_deployment
                        JOIN school_holidays school_holiday
                          ON school_holiday.geofence_id = school_holiday_deployment.geofence_id
                         AND school_holiday.holiday_date = {$alias}.attendance_date
                        WHERE school_holiday_deployment.id = {$alias}.deployment_id
                    ) THEN 0
                    WHEN {$alias}.time_in IS NULL THEN 0
                    WHEN {$alias}.time_out IS NULL AND {$alias}.attendance_date = {$currentDateSql} THEN
                        LEAST(
                            GREATEST(
                                COALESCE({$alias}.geofence_seconds, 0)
                                + CASE
                                    WHEN {$alias}.geofence_inside = 1 AND {$alias}.geofence_last_seen_at IS NOT NULL
                                    THEN LEAST(GREATEST(TIMESTAMPDIFF(SECOND, {$alias}.geofence_last_seen_at, NOW()), 0), " . self::MAX_LIVE_GAP_SECONDS . ")
                                    ELSE 0
                                  END,
                                0
                            ) / 3600,
                            " . self::DAILY_CAP_HOURS . "
                        )
                    ELSE LEAST({$alias}.total_hours, 8.00)
                END";
    }
}
