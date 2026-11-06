<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../middleware/role_check.php';
requireRole('head_teacher');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/HeadTeacherAdminVerification.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed.']); exit; }
try {
    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) { $payload = $_POST; }
    $user = currentUser() ?? [];
    verifyHeadTeacherAdministrativePassword(getPDO(), (int) ($user['id'] ?? 0), (string) ($payload['password'] ?? ''));
    echo json_encode(['success' => true, 'expires_at' => headTeacherAdminSessionExpiresAt()]);
} catch (Throwable $e) { http_response_code(422); echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
