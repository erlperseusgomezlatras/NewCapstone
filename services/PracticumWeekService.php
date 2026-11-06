<?php

declare(strict_types=1);

function practicumToday(): string
{
    return (new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
}

function practicumWeekStart(string $date): string
{
    return (new DateTimeImmutable($date, new DateTimeZone('Asia/Manila')))->modify('monday this week')->format('Y-m-d');
}

function practicumEffectiveStart(?string $practicumStartDate): ?string
{
    $date = trim((string) $practicumStartDate);
    if ($date === '') {
        return null;
    }

    return practicumWeekStart($date);
}

function practicumStatus(?string $practicumStartDate, ?string $today = null, int $maxWeeks = 16, ?string $lifecycleStatus = null): string
{
    $lifecycle = strtolower(trim((string) $lifecycleStatus));
    if (in_array($lifecycle, ['establishing', 'not_yet_started', 'not_started', 'not_configured'], true)) {
        return 'not_started';
    }
    if (in_array($lifecycle, ['completed', 'ended'], true)) {
        return 'ended';
    }

    $currentDate = $today ?? practicumToday();
    if ($lifecycle === 'scheduled') {
        return trim((string) $practicumStartDate) !== '' && $currentDate >= (string) $practicumStartDate
            ? 'ongoing'
            : 'scheduled';
    }

    $effectiveStart = practicumEffectiveStart($practicumStartDate);
    if ($effectiveStart === null) {
        return 'not_started';
    }

    if ($currentDate < $effectiveStart) {
        return 'not_started';
    }

    $completedAfter = (new DateTimeImmutable($effectiveStart, new DateTimeZone('Asia/Manila')))
        ->modify('+' . ($maxWeeks * 7) . ' days')
        ->format('Y-m-d');

    return $currentDate >= $completedAfter ? 'ended' : 'ongoing';
}

function practicumStatusLabel(string $status): string
{
    return match ($status) {
        'establishing', 'not_yet_started', 'not_started', 'not_configured' => 'Not Started',
        'scheduled' => 'Scheduled',
        'ongoing' => 'Ongoing',
        'completed', 'ended' => 'Ended',
        default => 'Not Started',
    };
}

function practicumCanRun(?string $practicumStartDate, ?string $today = null, ?string $lifecycleStatus = null): bool
{
    $lifecycle = strtolower(trim((string) $lifecycleStatus));
    if ($lifecycle !== '' && !in_array($lifecycle, ['ongoing', 'completed', 'ended', 'scheduled'], true)) {
        return false;
    }

    return practicumStatus($practicumStartDate, $today, 16, $lifecycleStatus) === 'ongoing';
}

/** Persist the scheduled-to-ongoing transition whenever the system reads attendance state. */
function practicumAutoActivateScheduled(PDO $pdo, ?int $semesterId = null): int
{
    $sql = "UPDATE semesters
            SET practicum_status = 'ongoing'
            WHERE semester_status = 'active'
              AND practicum_status = 'scheduled'
              AND practicum_start_date IS NOT NULL
              AND practicum_start_date <= :today";
    $params = ['today' => practicumToday()];
    if ($semesterId !== null && $semesterId > 0) {
        $sql .= ' AND id = :semester_id';
        $params['semester_id'] = $semesterId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

function practicumWeekBaseLabel(string $weekStart, string $weekEnd, int $index): string
{
    return 'Week ' . $index;
}

function practicumWeekStatus(string $weekStart, string $weekEnd, ?string $today = null): string
{
    $currentDate = $today ?? practicumToday();

    if ($currentDate < $weekStart) {
        return 'upcoming';
    }

    if ($currentDate <= $weekEnd) {
        return 'active';
    }

    return 'completed';
}

function practicumWeekDisplayLabel(array $week): string
{
    $label = (string) ($week['week_label'] ?? $week['label'] ?? '');
    $status = (string) ($week['status'] ?? '');

    return $status === 'active' ? $label . ' (Active)' : $label;
}

function practicumWeekFilterLabel(array $week): string
{
    return practicumWeekDisplayLabel($week);
}

function practicumBuildWeeks(string $semesterStart, string $semesterEnd, ?string $today = null, int $maxWeeks = 16): array
{
    $start = new DateTimeImmutable(practicumWeekStart($semesterStart));
    $weeks = [];
    unset($semesterEnd);

    for ($index = 1; $index <= min(16, $maxWeeks); $index++) {
        $weekStartDate = $start->modify('+' . (($index - 1) * 7) . ' days');

        $weekStart = $weekStartDate->format('Y-m-d');
        $weekEnd = $weekStartDate->modify('+4 days')->format('Y-m-d');
        $status = practicumWeekStatus($weekStart, $weekEnd, $today);
        $label = practicumWeekBaseLabel($weekStart, $weekEnd, $index);

        $weeks[] = [
            'index' => $index,
            'week_no' => $index,
            'start' => $weekStart,
            'start_date' => $weekStart,
            'end' => $weekEnd,
            'end_date' => $weekEnd,
            'label' => $label,
            'week_label' => $label,
            'display_label' => practicumWeekDisplayLabel([
                'week_label' => $label,
                'status' => $status,
            ]),
            'status' => $status,
            'is_current' => $status === 'active',
        ];
    }

    return $weeks;
}

function practicumBuildWeeksFromStart(?string $practicumStartDate, ?string $today = null, int $maxWeeks = 16): array
{
    $effectiveStart = practicumEffectiveStart($practicumStartDate);
    if ($effectiveStart === null) {
        return [];
    }

    $currentDate = $today ?? practicumToday();
    if ($currentDate < $effectiveStart) {
        return [];
    }

    return practicumBuildWeeks($effectiveStart, $effectiveStart, $currentDate, $maxWeeks);
}

function practicumJournalWeeksToDisplay(
    ?string $practicumStartDate,
    ?string $today = null,
    int $maxWeeks = 16,
    ?string $semesterEndDate = null,
    ?string $lifecycleStatus = null
): int {
    $startDate = trim((string) $practicumStartDate);
    if ($startDate === '') {
        return 0;
    }

    $currentDate = $today ?? practicumToday();
    if ($currentDate < $startDate) {
        return 0;
    }

    $lifecycle = strtolower(trim((string) $lifecycleStatus));
    if (in_array($lifecycle, ['establishing', 'not_yet_started', 'not_started', 'not_configured'], true)) {
        return 0;
    }

    $rangeEnd = $currentDate;
    $endDate = trim((string) $semesterEndDate);
    if (in_array($lifecycle, ['completed', 'ended', 'closed', 'archived'], true)
        && $endDate !== ''
        && $endDate < $rangeEnd
    ) {
        $rangeEnd = $endDate;
    }

    $weeks = practicumBuildWeeksFromStart($startDate, $rangeEnd, max(0, min(16, $maxWeeks)));
    $visibleWeeks = 0;
    foreach ($weeks as $week) {
        if (($week['status'] ?? '') !== 'upcoming') {
            $visibleWeeks = (int) ($week['index'] ?? $visibleWeeks);
        }
    }

    return $visibleWeeks;
}

function practicumActiveWeek(array $weeks): ?array
{
    foreach ($weeks as $week) {
        if (($week['status'] ?? '') === 'active') {
            return $week;
        }
    }

    return null;
}

function practicumWeeksWithFallbackActive(array $weeks, ?string $today = null): array
{
    if ($weeks === [] || practicumActiveWeek($weeks) !== null) {
        return $weeks;
    }

    $currentDate = $today ?? practicumToday();
    $firstStart = (string) ($weeks[0]['start'] ?? '');
    $activeIndex = $currentDate < $firstStart ? 0 : count($weeks) - 1;

    foreach ($weeks as $index => &$week) {
        if ($index === $activeIndex) {
            $week['status'] = 'active';
            $week['is_current'] = true;
        } elseif ($index < $activeIndex) {
            $week['status'] = 'completed';
            $week['is_current'] = false;
        } else {
            $week['status'] = 'upcoming';
            $week['is_current'] = false;
        }

        $week['display_label'] = practicumWeekDisplayLabel($week);
    }
    unset($week);

    return $weeks;
}

function practicumSelectWeek(array $weeks, ?string $requestedWeekStart = null): ?array
{
    if ($weeks === []) {
        return null;
    }

    if ($requestedWeekStart !== null && $requestedWeekStart !== '') {
        $requestedWeekStart = practicumWeekStart($requestedWeekStart);
        foreach ($weeks as $week) {
            if (($week['start'] ?? '') === $requestedWeekStart) {
                return $week;
            }
        }
    }

    return practicumActiveWeek($weeks) ?? $weeks[0];
}

function practicumWeekStatusBadge(array $week): string
{
    return match ((string) ($week['status'] ?? 'upcoming')) {
        'active' => '<span class="inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-200">Active</span>',
        'completed' => '<span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600 ring-1 ring-inset ring-slate-200">Completed</span>',
        default => '<span class="inline-flex items-center rounded-full bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-500 ring-1 ring-inset ring-slate-200">Upcoming</span>',
    };
}

function practicumWeekSurfaceClass(array $week): string
{
    return match ((string) ($week['status'] ?? 'upcoming')) {
        'active' => 'border-emerald-300 bg-emerald-50 ring-1 ring-emerald-200',
        'completed' => 'border-slate-200 bg-slate-50',
        default => 'border-slate-200 bg-white text-slate-500',
    };
}

/**
 * Format decimal hours as H.MM (hours dot minutes), e.g. 0.05 hours -> "0.03".
 */
function practicumFormatHours(float $hours): string
{
    $totalMinutes = max(0, (int) floor(($hours * 60) + 0.00001));
    $wholeHours = intdiv($totalMinutes, 60);
    $minutes = $totalMinutes % 60;

    return sprintf('%d.%02d', $wholeHours, $minutes);
}
