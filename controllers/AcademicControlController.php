<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/AcademicControlService.php';

final class AcademicControlController
{
    public function __construct(private AcademicControlService $service)
    {
    }

    public function createSchoolYear(array $input): array
    {
        $id = $this->service->createSchoolYear(
            $this->requireString($input, 'year_label'),
            $this->requireString($input, 'start_date'),
            $this->requireString($input, 'end_date'),
            isset($input['year_status']) ? $this->requireString($input, 'year_status') : 'planned'
        );
        return $this->success(['school_year_id' => $id]);
    }

    public function createSemester(array $input): array
    {
        $id = $this->service->createSemester(
            $this->requireInt($input, 'school_year_id'),
            $this->requireInt($input, 'semester_no'),
            $this->requireString($input, 'semester_name'),
            $this->requireString($input, 'start_date'),
            $this->requireString($input, 'end_date'),
            isset($input['semester_status']) ? $this->requireString($input, 'semester_status') : 'planned'
        );
        return $this->success(['semester_id' => $id]);
    }

    public function createSection(array $input): array
    {
        $id = $this->service->createSection(
            $this->requireInt($input, 'semester_id'),
            $this->requireString($input, 'section_code'),
            $this->requireString($input, 'section_name'),
            isset($input['capacity']) ? $this->requireInt($input, 'capacity') : 50,
            isset($input['section_status']) ? $this->requireString($input, 'section_status') : 'active'
        );
        return $this->success(['section_id' => $id]);
    }

    public function createStudent(array $input): array
    {
        return $this->success(['student_user_id' => $this->service->createStudentAccountWithProfile($input)]);
    }

    public function assignCoordinator(array $input): array
    {
        $this->service->assignCoordinator(
            $this->requireInt($input, 'section_id'),
            $this->requireInt($input, 'coordinator_user_id')
        );
        return $this->success(['assigned' => true]);
    }

    public function enrollStudentsBulk(array $input): array
    {
        $sectionId = $this->requireInt($input, 'section_id');
        $rawIds = $input['student_user_ids'] ?? null;
        if (!is_array($rawIds) || $rawIds === []) {
            throw new InvalidArgumentException('student_user_ids must be a non-empty array');
        }
        $count = $this->service->enrollStudentsBulk($sectionId, $rawIds);
        return $this->success(['enrolled_count' => $count]);
    }

    public function handleRequest(string $action, array $input): void
    {
        try {
            $response = match ($action) {
                'create_school_year' => $this->createSchoolYear($input),
                'create_semester' => $this->createSemester($input),
                'create_section' => $this->createSection($input),
                'assign_coordinator' => $this->assignCoordinator($input),
                'enroll_students_bulk' => $this->enrollStudentsBulk($input),
                'create_student' => $this->createStudent($input),
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
