<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/AttendanceService.php';

final class AttendanceController
{
    public function __construct(private AttendanceService $service)
    {
    }

    public function timeIn(array $input): array
    {
        $studentId = $this->requireInt($input, 'student_user_id');
        $lat = $this->requireFloat($input, 'lat');
        $lng = $this->requireFloat($input, 'lng');

        return $this->success($this->service->recordTimeIn($studentId, $lat, $lng));
    }

    public function timeOut(array $input): array
    {
        $studentId = $this->requireInt($input, 'student_user_id');
        return $this->success($this->service->recordTimeOut($studentId));
    }

    public function weeklyHours(array $input): array
    {
        $studentId = $this->requireInt($input, 'student_id');
        $weekStart = $this->requireString($input, 'week_start');
        $weekEnd = $this->requireString($input, 'week_end');

        return $this->success($this->service->weeklyHours($studentId, $weekStart, $weekEnd));
    }

    public function totalPublicHours(array $input): array
    {
        $studentId = $this->requireInt($input, 'student_id');
        return $this->success($this->service->totalPublicHours($studentId));
    }

    public function totalPrivateHours(array $input): array
    {
        $studentId = $this->requireInt($input, 'student_id');
        return $this->success($this->service->totalPrivateHours($studentId));
    }

    public function overallHours(array $input): array
    {
        $studentId = $this->requireInt($input, 'student_id');
        return $this->success($this->service->overallHours($studentId));
    }

    public function sectionSummary(array $input): array
    {
        $sectionId = $this->requireInt($input, 'section_id');
        $semesterId = $this->requireInt($input, 'semester_id');
        return $this->success($this->service->sectionSummary($sectionId, $semesterId));
    }

    public function handleRequest(string $action, array $input): void
    {
        try {
            $response = match ($action) {
                'time_in' => $this->timeIn($input),
                'time_out' => $this->timeOut($input),
                'weekly_hours' => $this->weeklyHours($input),
                'total_public_hours' => $this->totalPublicHours($input),
                'total_private_hours' => $this->totalPrivateHours($input),
                'overall_hours' => $this->overallHours($input),
                'section_summary' => $this->sectionSummary($input),
                default => throw new InvalidArgumentException('Unsupported action'),
            };
            $this->sendJson($response, 200);
        } catch (Throwable $e) {
            $status = $e instanceof InvalidArgumentException ? 422 : 400;
            $this->sendJson($this->error($e->getMessage()), $status);
        }
    }

    private function requireInt(array $input, string $field): int
    {
        if (!isset($input[$field]) || filter_var($input[$field], FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException("Invalid {$field}");
        }
        return (int) $input[$field];
    }

    private function requireFloat(array $input, string $field): float
    {
        if (!isset($input[$field]) || !is_numeric($input[$field])) {
            throw new InvalidArgumentException("Invalid {$field}");
        }
        return (float) $input[$field];
    }

    private function requireString(array $input, string $field): string
    {
        if (!isset($input[$field]) || trim((string) $input[$field]) === '') {
            throw new InvalidArgumentException("Missing {$field}");
        }
        return trim((string) $input[$field]);
    }

    private function success(array $data): array
    {
        return ['success' => true, 'data' => $data];
    }

    private function error(string $message): array
    {
        return ['success' => false, 'error' => $message];
    }

    private function sendJson(array $payload, int $status): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
