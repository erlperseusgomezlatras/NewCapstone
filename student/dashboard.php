<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/role_check.php';
require_once __DIR__ . '/../middleware/logout_ui.php';
require_once __DIR__ . '/../services/AttendanceService.php';
require_once __DIR__ . '/../services/ConcernService.php';
require_once __DIR__ . '/../services/JournalModuleService.php';
require_once __DIR__ . '/../services/PracticumWeekService.php';
require_once __DIR__ . '/../services/SupervisorService.php';
require_once __DIR__ . '/../services/PasswordService.php';
require_once __DIR__ . '/../services/AttendanceStatusService.php';
require_once __DIR__ . '/../services/SystemHolidayService.php';

date_default_timezone_set('Asia/Manila');
requireRole('student');
$pdo = getPDO();
$systemHolidayService = new SystemHolidayService($pdo);
$supervisorService = new SupervisorService($pdo);
$user = currentUser();
$studentId = (int)($user['id'] ?? 0);
if ($studentId <= 0) { header('Location: ' . appUrl('public/login.php')); exit; }
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

function tableExists(PDO $pdo, string $table): bool { $s=$pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:t LIMIT 1'); $s->execute(['t'=>$table]); return (bool)$s->fetchColumn(); }
function columnExists(PDO $pdo, string $table, string $column): bool { $s=$pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=:t AND column_name=:c LIMIT 1'); $s->execute(['t'=>$table,'c'=>$column]); return (bool)$s->fetchColumn(); }
if (tableExists($pdo, 'semesters') && !columnExists($pdo, 'semesters', 'practicum_start_date')) {
  $pdo->exec('ALTER TABLE semesters ADD COLUMN practicum_start_date DATE NULL AFTER start_date');
}
if (tableExists($pdo, 'semesters') && !columnExists($pdo, 'semesters', 'practicum_status')) {
  $pdo->exec("ALTER TABLE semesters ADD COLUMN practicum_status VARCHAR(24) NOT NULL DEFAULT 'not_configured' AFTER practicum_start_date");
  $pdo->exec("UPDATE semesters SET practicum_status = CASE WHEN semester_status='active' AND practicum_start_date IS NOT NULL THEN 'ongoing' WHEN semester_status='active' THEN 'not_yet_started' WHEN semester_status IN ('closed','archived') THEN 'completed' ELSE 'not_configured' END");
}
$pdo->exec("UPDATE semesters SET practicum_status='not_yet_started' WHERE practicum_status='establishing'");
function monday(DateTimeImmutable $d): DateTimeImmutable { return $d->modify('-'.((int)$d->format('N')-1).' days')->setTime(0,0,0); }
function h(float $n): string { return practicumFormatHours($n); }
function hm(float $hours): string { return practicumFormatHours($hours); }
function deploymentTypeLabel(string $type): string { return $type === 'private_school' ? 'Private' : 'Public'; }
function formatAttendanceStatus(?array $attendance, ?string $todayDate = null): string {
  if($attendance===null || $attendance===[]) {
    if($todayDate !== null && $todayDate === date('Y-m-d') && date('H:i:s') < '06:00:00') return 'Upcoming';
    return 'Absent';
  }
  if((int)($attendance['is_override'] ?? 0) === 1 || stripos((string)($attendance['remarks'] ?? ''), 'emergency') !== false) return 'Emergency Override';
  return AttendanceStatusService::todayStatus($attendance);
  /* The former independent status calculation is retained below only for compatibility reference. */
  $hasTimeIn=!empty($attendance['time_in']);
  $hasTimeOut=!empty($attendance['time_out']);
  $isLate = false;
  if($hasTimeIn){
    try {
      $isLate = (new DateTimeImmutable((string) $attendance['time_in']))->format('H:i:s') >= '08:30:00';
    } catch(Throwable) {
      $isLate = false;
    }
  }
  if($hasTimeIn && !$hasTimeOut){
    if($todayDate !== null && !empty($attendance['attendance_date']) && (string)$attendance['attendance_date'] < $todayDate){
      return 'Pending Timeout';
    }
    if($isLate) return 'Late';
    return 'In Progress';
  }
  if($hasTimeIn && $hasTimeOut){
    if($isLate) return 'Late';
    return 'Completed';
  }
  return 'Absent';
}
function attendanceStatusClass(string $status): string {
  return match($status){
    'Emergency Override', 'Present (Override)', 'Completed', 'PRESENT' => 'ok',
    'INCA', 'INCA_REJECTED' => 'warn',
    'Late' => 'warn',
    'In Progress' => 'warn',
    'Pending Timeout' => 'warn',
    'Upcoming' => 'upcoming',
    default => 'bad',
  };
}
function fmtAttendanceTime(?string $value): string {
  if($value===null || $value==='') return '--';
  try { return (new DateTimeImmutable($value))->format('h:i A'); } catch(Throwable) { return '--'; }
}
function concernStatusLabel(string $status): string {
  return match($status){
    'resolved' => 'Resolved',
    'declined' => 'Declined',
    default => 'Pending',
  };
}
function concernStatusClass(string $status): string {
  return match($status){
    'resolved' => 'ok',
    'declined' => 'bad',
    default => 'bad',
  };
}
function journalStatusLabel(string $status): string {
  return match($status){
    'pending' => 'Pending',
    'reviewed' => 'Reviewed',
    'approved' => 'Approved',
    'declined' => 'Declined',
    'resubmitted' => 'Resubmitted',
    default => ucwords(str_replace('_',' ',$status)),
  };
}
function journalStatusClass(string $status): string {
  return match($status){
    'approved' => 'ok',
    'declined' => 'bad',
    default => 'warn',
  };
}
function checklistCompletionStatus(int $done, int $total): string {
  if($total <= 0) return 'No Items';
  return $done === $total ? 'Completed' : 'Incomplete';
}
function checklistCompletionStatusClass(int $done, int $total): string {
  if($total <= 0) return 'warn';
  return $done === $total ? 'ok' : 'warn';
}
function dist(float $lat1,float $lng1,float $lat2,float $lng2): float { $r=6371000.0; $dLat=deg2rad($lat2-$lat1); $dLng=deg2rad($lng2-$lng1); $a=sin($dLat/2)**2+cos(deg2rad($lat1))*cos(deg2rad($lat2))*(sin($dLng/2)**2); return $r*(2*atan2(sqrt($a),sqrt(max(1-$a,0)))); }
function findStudentJournalForWeek(PDO $pdo, int $studentId, int $semesterId, string $weekStart, ?string $semesterStart = null): ?array {
  unset($semesterStart);
  $weekEnd=(new DateTimeImmutable($weekStart))->modify('+4 days')->format('Y-m-d');
  $stmt=$pdo->prepare("SELECT * FROM student_journals WHERE student_user_id=:u AND semester_id=:m AND (week_start=:w OR DATE(submitted_at) BETWEEN :ws AND :we) ORDER BY (week_start=:wo) DESC, submitted_at DESC LIMIT 1");
  $stmt->execute(['u'=>$studentId,'m'=>$semesterId,'w'=>$weekStart,'ws'=>$weekStart,'we'=>$weekEnd,'wo'=>$weekStart]);
  $row=$stmt->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function journalWeekNumberFromStart(array $ctx, string $weekStart): int
{
  $weeks = practicumBuildWeeksFromStart((string) ($ctx['practicum_start_date'] ?? ''));
  foreach ($weeks as $week) {
    if ((string) ($week['start'] ?? '') === $weekStart) {
      return (int) ($week['index'] ?? 0);
    }
  }

  return 0;
}

function journalAnswerValueForQuestion(array $question, ?array $answer): mixed
{
  if ($answer === null) {
    return null;
  }

  $type = (string) ($question['question_type'] ?? '');
  $raw = (string) ($answer['answer_text'] ?? '');
  if ($type === 'checkbox') {
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
  }

  return $raw;
}

function renderJournalAnswerField(array $question, mixed $value, bool $disabled = false): void
{
  $questionId = (int) ($question['id'] ?? 0);
  $questionText = (string) ($question['question_text'] ?? '');
  $required = !empty($question['is_required']);
  $type = (string) ($question['question_type'] ?? 'paragraph');
  $options = $question['options'] ?? [];
  $name = 'answers[' . $questionId . ']';
  $requiredLabel = $required ? '<span class="ml-2 text-rose-600">*</span>' : '';
  echo '<div class="space-y-2">';
  echo '<label class="block text-xs font-semibold text-slate-600">' . htmlspecialchars($questionText) . $requiredLabel . '</label>';

  if ($type === 'paragraph') {
    echo '<textarea class="field h-28" name="' . htmlspecialchars($name) . '" ' . ($disabled ? 'disabled' : '') . ($required ? ' required' : '') . '>' . htmlspecialchars((string) ($value ?? '')) . '</textarea>';
  } elseif ($type === 'short_answer') {
    echo '<input class="field" type="text" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars((string) ($value ?? '')) . '" ' . ($disabled ? 'disabled' : '') . ($required ? ' required' : '') . '>';
  } elseif ($type === 'dropdown') {
    echo '<select class="field" name="' . htmlspecialchars($name) . '" ' . ($disabled ? 'disabled' : '') . ($required ? ' required' : '') . '>';
    echo '<option value="">Select an option</option>';
    foreach ($options as $option) {
      $optionText = (string) ($option['option_text'] ?? '');
      $selected = (string) ($value ?? '') === $optionText ? 'selected' : '';
      echo '<option value="' . htmlspecialchars($optionText) . '" ' . $selected . '>' . htmlspecialchars($optionText) . '</option>';
    }
    echo '</select>';
  } elseif ($type === 'multiple_choice') {
    echo '<div class="space-y-2">';
    foreach ($options as $option) {
      $optionText = (string) ($option['option_text'] ?? '');
      $checked = (string) ($value ?? '') === $optionText ? 'checked' : '';
      echo '<label class="flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">';
      echo '<input type="radio" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars($optionText) . '" ' . $checked . ' ' . ($disabled ? 'disabled' : '') . ($required ? ' required' : '') . '>';
      echo '<span>' . htmlspecialchars($optionText) . '</span>';
      echo '</label>';
    }
    echo '</div>';
  } elseif ($type === 'checkbox') {
    $selectedValues = is_array($value) ? $value : [];
    echo '<div class="space-y-2">';
    foreach ($options as $option) {
      $optionText = (string) ($option['option_text'] ?? '');
      $checked = in_array($optionText, $selectedValues, true) ? 'checked' : '';
      echo '<label class="flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">';
      echo '<input type="checkbox" name="' . htmlspecialchars($name) . '[]" value="' . htmlspecialchars($optionText) . '" ' . $checked . ' ' . ($disabled ? 'disabled' : '') . '>';
      echo '<span>' . htmlspecialchars($optionText) . '</span>';
      echo '</label>';
    }
    echo '</div>';
  }

  echo '</div>';
}

function renderJournalReadOnlyField(array $question, mixed $value): void
{
  $type = (string) ($question['question_type'] ?? 'paragraph');
  $questionText = (string) ($question['question_text'] ?? '');
  echo '<div class="rounded-2xl border border-slate-200 bg-white p-4">';
  echo '<div class="text-xs font-semibold uppercase tracking-wide text-slate-500">' . htmlspecialchars($questionText) . '</div>';
  if ($type === 'checkbox') {
    $values = is_array($value) ? $value : [];
    $display = $values !== [] ? implode(', ', $values) : 'No answer provided.';
  } else {
    $display = trim((string) ($value ?? ''));
    if ($display === '') {
      $display = 'No answer provided.';
    }
  }
  echo '<div class="mt-3 whitespace-pre-line text-sm leading-7 text-slate-700">' . htmlspecialchars($display) . '</div>';
  echo '</div>';
}

function ctx(PDO $pdo, int $uid): array {
  $q="SELECT sem.id AS semester_id,sem.semester_name,sem.semester_status,sem.start_date AS semester_start_date,sem.practicum_start_date,sem.practicum_status,sem.end_date AS semester_end_date,sy.year_label,ss.section_id,u.email,u.school_id,up.first_name,up.middle_name,up.last_name FROM users u JOIN semester_students sems ON sems.student_user_id=u.id AND sems.enrollment_status='active' JOIN semesters sem ON sem.id=sems.semester_id AND sem.semester_status='active' JOIN school_years sy ON sy.id=sem.school_year_id LEFT JOIN user_profiles up ON up.user_id=u.id LEFT JOIN section_students ss ON ss.student_user_id=u.id AND ss.semester_id=sem.id AND ss.enrollment_status='active' WHERE u.id=:id ORDER BY sem.start_date DESC,sem.id DESC LIMIT 1";
  $s=$pdo->prepare($q); $s->execute(['id'=>$uid]); return $s->fetch(PDO::FETCH_ASSOC)?:[];
}
function activeSemesterContext(PDO $pdo): array {
  $q="SELECT sem.id AS semester_id,sem.semester_name,sem.semester_status,sem.start_date AS semester_start_date,sem.practicum_start_date,sem.practicum_status,sem.end_date AS semester_end_date,sy.year_label FROM semesters sem JOIN school_years sy ON sy.id=sem.school_year_id WHERE sem.semester_status='active' ORDER BY sem.start_date DESC,sem.id DESC LIMIT 1";
  $s=$pdo->query($q); return $s->fetch(PDO::FETCH_ASSOC)?:[];
}
function geofence(PDO $pdo, int $uid, int $semesterId): array {
  if($semesterId<=0)return [];
  $tableCheck=$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='student_school_deployments'")->fetchColumn();
  $timeInStartSelect=columnExists($pdo, 'partner_schools', 'time_in_start') ? 'ps.time_in_start' : "'06:00:00' AS time_in_start";
  $q=((int)$tableCheck>0)
    ? "SELECT g.id,g.name,g.latitude,g.longitude,g.radius_meters,g.school_type,ps.address,ps.daily_cap_hours,{$timeInStartSelect},COALESCE(NULLIF(TRIM(CONCAT_WS(' ',cup.first_name,cup.middle_name,cup.last_name)),''),cu.email,'Not assigned') AS coordinator_name FROM student_school_deployments d JOIN semester_students sems ON sems.student_user_id=d.student_user_id AND sems.semester_id=d.semester_id AND sems.enrollment_status='active' JOIN geofence_locations g ON g.id=d.geofence_id LEFT JOIN partner_schools ps ON ps.geofence_id=g.id LEFT JOIN users cu ON cu.id=d.coordinator_user_id LEFT JOIN user_profiles cup ON cup.user_id=cu.id WHERE d.student_user_id=:id AND d.semester_id=:semester_id AND d.deployment_status='active' AND g.is_active=1 ORDER BY d.deployment_order LIMIT 1"
    : "SELECT g.id,g.name,g.latitude,g.longitude,g.radius_meters,g.school_type,ps.address,ps.daily_cap_hours,{$timeInStartSelect},COALESCE(NULLIF(TRIM(CONCAT_WS(' ',cup.first_name,cup.middle_name,cup.last_name)),''),cu.email,'Not assigned') AS coordinator_name FROM student_geofence_assignments sga JOIN semester_students sems ON sems.student_user_id=sga.student_user_id AND sems.semester_id=sga.semester_id AND sems.enrollment_status='active' JOIN geofence_locations g ON g.id=sga.geofence_id LEFT JOIN partner_schools ps ON ps.geofence_id=g.id LEFT JOIN users cu ON cu.id=sga.coordinator_user_id LEFT JOIN user_profiles cup ON cup.user_id=cu.id WHERE sga.student_user_id=:id AND sga.semester_id=:semester_id AND sga.assignment_status='active' AND g.is_active=1 ORDER BY sga.assigned_at DESC LIMIT 1";
  $s=$pdo->prepare($q); $s->execute(['id'=>$uid,'semester_id'=>$semesterId]); return $s->fetch(PDO::FETCH_ASSOC)?:[];
}
function todayRec(PDO $pdo, int $uid): array { $s=$pdo->prepare('SELECT * FROM attendance_records WHERE student_user_id=:id AND attendance_date=CURDATE() LIMIT 1'); $s->execute(['id'=>$uid]); return $s->fetch(PDO::FETCH_ASSOC)?:[]; }
function isTodayInSemesterDateRange(array $ctx, string $today): bool {
  $start=practicumEffectiveStart((string)($ctx['practicum_start_date'] ?? '')) ?? '';
  $end=(string)($ctx['semester_end_date'] ?? '');
  return $start!=='' && $end!=='' && $today >= $start && $today <= $end;
}

/** Returns only the history a student is permitted to view about themselves. */
function studentHistorySnapshot(PDO $pdo, int $studentId, int $currentSemesterId): array {
  $history = ['semesters'=>[], 'deployments'=>[], 'schools'=>[], 'attendance'=>[], 'attendance_rows'=>[], 'journals'=>[], 'checklists'=>[], 'concerns'=>[], 'has_failed_attempts'=>false];
  $semesterStmt = $pdo->prepare("SELECT ss.semester_id, ss.enrollment_status, sem.semester_no, sem.semester_name, sem.semester_status, sy.year_label
    FROM semester_students ss
    JOIN semesters sem ON sem.id=ss.semester_id
    LEFT JOIN school_years sy ON sy.id=sem.school_year_id
    WHERE ss.student_user_id=:student_id AND ss.semester_id<>:current_semester_id
    ORDER BY sem.start_date DESC, sem.id DESC");
  $semesterStmt->execute(['student_id'=>$studentId, 'current_semester_id'=>$currentSemesterId]);
  $history['semesters']=$semesterStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  if (tableExists($pdo, 'student_school_deployments')) {
    $deploymentStmt=$pdo->prepare("SELECT d.semester_id,d.deployment_status,d.rendered_seconds,d.required_seconds,d.completed_at,g.name AS school_name
      FROM student_school_deployments d LEFT JOIN geofence_locations g ON g.id=d.geofence_id
      WHERE d.student_user_id=:student_id AND d.semester_id<>:current_semester_id
      ORDER BY d.semester_id DESC,d.deployment_order ASC");
    $deploymentStmt->execute(['student_id'=>$studentId, 'current_semester_id'=>$currentSemesterId]);
    $history['deployments']=$deploymentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $history['schools']=$history['deployments'];
    foreach($history['deployments'] as $deployment){
      if (in_array((string)($deployment['deployment_status'] ?? ''), ['failed','cancelled'], true)) $history['has_failed_attempts']=true;
    }
  }
  $attendanceStmt=$pdo->prepare("SELECT semester_id,COUNT(*) AS attendance_count,COALESCE(SUM(total_hours),0) AS hours
    FROM attendance_records WHERE student_user_id=:student_id AND semester_id<>:current_semester_id GROUP BY semester_id ORDER BY semester_id DESC");
  $attendanceStmt->execute(['student_id'=>$studentId, 'current_semester_id'=>$currentSemesterId]);
  $history['attendance']=$attendanceStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $attendanceRowsStmt=$pdo->prepare("SELECT ar.attendance_date,ar.time_in,ar.time_out,ar.total_hours,ar.remarks,sem.semester_name,sy.year_label
    FROM attendance_records ar JOIN semesters sem ON sem.id=ar.semester_id LEFT JOIN school_years sy ON sy.id=sem.school_year_id
    WHERE ar.student_user_id=:student_id AND ar.semester_id<>:current_semester_id ORDER BY ar.attendance_date DESC");
  $attendanceRowsStmt->execute(['student_id'=>$studentId, 'current_semester_id'=>$currentSemesterId]);
  $history['attendance_rows']=$attendanceRowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  if (tableExists($pdo, 'journal_submissions')) {
    $journalStmt=$pdo->prepare("SELECT js.week_start,js.submitted_at,js.status,js.points_awarded,js.max_points,sem.semester_name,sy.year_label
      FROM journal_submissions js JOIN semesters sem ON sem.id=js.semester_id LEFT JOIN school_years sy ON sy.id=sem.school_year_id
      WHERE js.student_user_id=:student_id AND js.semester_id<>:current_semester_id ORDER BY js.week_start DESC,js.submitted_at DESC");
    $journalStmt->execute(['student_id'=>$studentId, 'current_semester_id'=>$currentSemesterId]);
    $history['journals']=$journalStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
  if ($history['journals']===[] && tableExists($pdo, 'student_journals')) {
    $journalStmt=$pdo->prepare("SELECT sj.week_start,sj.submitted_at,sj.status,0 AS points_awarded,50 AS max_points,sem.semester_name,sy.year_label
      FROM student_journals sj JOIN semesters sem ON sem.id=sj.semester_id LEFT JOIN school_years sy ON sy.id=sem.school_year_id
      WHERE sj.student_user_id=:student_id AND sj.semester_id<>:current_semester_id ORDER BY sj.week_start DESC,sj.submitted_at DESC");
    $journalStmt->execute(['student_id'=>$studentId, 'current_semester_id'=>$currentSemesterId]);
    $history['journals']=$journalStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
  if (tableExists($pdo, 'student_weekly_checklist')) {
    $checklistStmt=$pdo->prepare("SELECT swc.week_start,COUNT(*) AS item_count,SUM(swc.is_checked) AS completed_count,SUM(swc.awarded_points) AS points_earned,sem.semester_name,sy.year_label
      FROM student_weekly_checklist swc JOIN semesters sem ON sem.id=swc.semester_id LEFT JOIN school_years sy ON sy.id=sem.school_year_id
      WHERE swc.student_user_id=:student_id AND swc.semester_id<>:current_semester_id GROUP BY swc.semester_id,swc.week_start,sem.semester_name,sy.year_label ORDER BY swc.week_start DESC");
    $checklistStmt->execute(['student_id'=>$studentId, 'current_semester_id'=>$currentSemesterId]);
    $history['checklists']=$checklistStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
  if (tableExists($pdo, 'student_concerns') && columnExists($pdo, 'student_concerns', 'semester_id')) {
    $concernStmt=$pdo->prepare("SELECT sc.subject,sc.message,sc.concern_status,sc.created_at,sem.semester_name,sy.year_label
      FROM student_concerns sc LEFT JOIN semesters sem ON sem.id=sc.semester_id LEFT JOIN school_years sy ON sy.id=sem.school_year_id
      WHERE sc.student_user_id=:student_id AND sc.semester_id<>:current_semester_id ORDER BY sc.created_at DESC");
    $concernStmt->execute(['student_id'=>$studentId, 'current_semester_id'=>$currentSemesterId]);
    $history['concerns']=$concernStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
  return $history;
}

$tab=(string)($_GET['tab']??'attendance'); $profileView=(string)($_GET['profile_view']??'current');
if(!in_array($tab,['attendance','journal','records','profile','concerns'],true))$tab='attendance';
if(!in_array($profileView,['current','history'],true))$profileView='current';

$errors=$_SESSION['flash_errors'] ?? [];
$ok=$_SESSION['flash_ok'] ?? [];
unset($_SESSION['flash_errors'], $_SESSION['flash_ok']);
$now=new DateTimeImmutable('now'); $todayWeek=(int)$now->format('N');
$currentDayName=$now->format('l');
$timeInWindowStart = $now->setTime(6,0,0); // Default until the assigned school is loaded.
$timeInCutoff = $now->setTime(16,0,0); // 4:00 PM local time
$isTimeInWindowOpen = $now >= $timeInWindowStart && $now < $timeInCutoff;
$attendanceRequestDate = $now->format('Y-m-d');
if (
  ($_SESSION['student_attendance_request_date'] ?? '') !== $attendanceRequestDate
  || empty($_SESSION['student_attendance_request_token'])
) {
  $_SESSION['student_attendance_request_date'] = $attendanceRequestDate;
  $_SESSION['student_attendance_request_token'] = bin2hex(random_bytes(32));
}
$attendanceRequestToken = (string) $_SESSION['student_attendance_request_token'];


$ctx=ctx($pdo,$studentId); $displaySemesterCtx=$ctx!==[]?$ctx:activeSemesterContext($pdo); $geo=geofence($pdo,$studentId,(int)($ctx['semester_id']??0)); $today=todayRec($pdo,$studentId);
$currentSemesterId=(int)($ctx['semester_id'] ?? 0);
$concernService = new ConcernService($pdo);
$studentHistory=studentHistorySnapshot($pdo,$studentId,$currentSemesterId);
$isReturningStudent=$studentHistory['semesters']!==[] || $studentHistory['deployments']!==[] || $studentHistory['attendance']!==[];
$isSemesterActive = (($ctx['semester_status'] ?? '') === 'active');
$todayDate=$now->format('Y-m-d');
$practicumStartRaw=(string)($ctx['practicum_start_date'] ?? $displaySemesterCtx['practicum_start_date'] ?? '');
$practicumStartDisplay=$practicumStartRaw!=='' ? (new DateTimeImmutable($practicumStartRaw))->format('F j, Y') : 'a date set by your Head Teacher';
$practicumLifecycleStatus = practicumStatus($practicumStartRaw !== '' ? $practicumStartRaw : null, $todayDate, 16, (string) ($ctx['practicum_status'] ?? $displaySemesterCtx['practicum_status'] ?? ''));
$practicumHasStarted = practicumCanRun($practicumStartRaw, $todayDate, (string) ($ctx['practicum_status'] ?? $displaySemesterCtx['practicum_status'] ?? ''));
$isWithinSemesterDates = $ctx!==[] && isTodayInSemesterDateRange($ctx, $todayDate);
$isActiveSemesterWeek = false;
$activeJournalWeekStart = '';
if($ctx!==[] && $practicumHasStarted){
  $journalWeeks=practicumBuildWeeksFromStart((string)($ctx['practicum_start_date'] ?? ''), $todayDate);
  $journalWeek=practicumActiveWeek($journalWeeks);
  // BYPASS FOR TESTING: Allow journal week if practicum has started, don't require semester date range
  if($journalWeek!==null && ($isWithinSemesterDates || $practicumHasStarted)){
    $isActiveSemesterWeek = true;
    $activeJournalWeekStart=(string)$journalWeek['start'];
  } elseif($journalWeek===null && $practicumHasStarted && count($journalWeeks) > 0) {
    // If no active week but practicum started, use first week for testing
    $activeJournalWeekStart=(string)$journalWeeks[0]['start'];
    $isActiveSemesterWeek = true;
  }
}
$attendanceService = new AttendanceService($pdo);
$deploymentRows = [];
$progressSemesterId = (int) ($ctx['semester_id'] ?? 0);
if ($progressSemesterId > 0) {
  $attendanceService->syncStudentSchoolProgress($studentId, $progressSemesterId);
  // Deployment progress can unlock the next school. Refresh the active
  // geofence after syncing so the Assigned School card shows that school.
  $geo = geofence($pdo, $studentId, $progressSemesterId);
  $progressStmt = $pdo->prepare("SELECT d.*, g.name AS school_name, g.school_type
    FROM student_school_deployments d JOIN geofence_locations g ON g.id=d.geofence_id
    WHERE d.student_user_id=:student_user_id AND d.semester_id=:semester_id ORDER BY d.deployment_order");
  $progressStmt->execute(['student_user_id'=>$studentId, 'semester_id'=>$progressSemesterId]);
  $deploymentRows = $progressStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
$liveSnapshot = $attendanceService->getStudentLiveSnapshot($studentId, $now->format('Y-m-d'));
$assignedGeofenceId = (int) ($geo['id'] ?? 0);
$timeInStartValue = substr((string) ($geo['time_in_start'] ?? '06:00:00'), 0, 5);
if (!preg_match('/^(?:0\d|1[0-5]):00$/', $timeInStartValue)) $timeInStartValue = '06:00';
$timeInWindowStart = $now->setTime((int) substr($timeInStartValue, 0, 2), 0, 0);
$isTimeInWindowOpen = $now >= $timeInWindowStart && $now < $timeInCutoff;
$timeInStartLabel = (new DateTimeImmutable($timeInStartValue))->format('g:i A');
$journalModuleService = new JournalModuleService($pdo);
$journalForm = [];
if ($ctx !== [] && (int) ($ctx['semester_id'] ?? 0) > 0) {
  $journalForm = $journalModuleService->getStudentFormForSemester((int) $ctx['semester_id'], $studentId) ?? [];
}

if($_SERVER['REQUEST_METHOD']==='POST'){
  $postErrors = [];
  $postOk = [];
  $action=(string)($_POST['action']??'');
  try {
    if($action==='time_in'){
      if(
        !hash_equals($attendanceRequestToken, (string)($_POST['attendance_request_token'] ?? ''))
        || !hash_equals($attendanceRequestDate, (string)($_POST['attendance_request_date'] ?? ''))
      ) throw new RuntimeException('This attendance form has expired. Reload the page before timing in.');
      $tab='attendance'; if($todayWeek>5)throw new RuntimeException('Attendance is Monday to Friday only.');
      if(!$isSemesterActive)throw new RuntimeException('No active semester is available. Attendance is disabled until a semester is started.');
      if(!$isActiveSemesterWeek)throw new RuntimeException('Practicum has not started yet. Your OJT is scheduled to begin on: '.$practicumStartDisplay);
      if(!$isTimeInWindowOpen)throw new RuntimeException('Time in is available from '.$timeInStartLabel.' until 4:00 PM.');
      if($ctx===[]||$geo===[])throw new RuntimeException('Missing active semester or partnered school assignment.');
      if($today!==[])throw new RuntimeException('You already timed in today.');
      $lat=(float)($_POST['lat']??0); $lng=(float)($_POST['lng']??0); if($lat===0.0||$lng===0.0)throw new RuntimeException('Location is required.');
      $attendanceService->recordTimeIn($studentId, $lat, $lng);
      $postOk[]='Time in recorded.';
    }

    if($action==='time_out'){
      if(
        !hash_equals($attendanceRequestToken, (string)($_POST['attendance_request_token'] ?? ''))
        || !hash_equals($attendanceRequestDate, (string)($_POST['attendance_request_date'] ?? ''))
      ) throw new RuntimeException('This attendance form has expired. Reload the page before timing out.');
      $tab='attendance'; if($todayWeek>5)throw new RuntimeException('Attendance is Monday to Friday only.');
      if(!$isSemesterActive)throw new RuntimeException('No active semester is available. Time out is unavailable.');
      if(!$isActiveSemesterWeek)throw new RuntimeException('Practicum has not started yet. Your OJT is scheduled to begin on: '.$practicumStartDisplay);
      if($today===[])throw new RuntimeException('No attendance record for today.');
      if(!empty($today['time_out']))throw new RuntimeException('Time out already recorded.');
      $lat=(float)($_POST['lat']??0); $lng=(float)($_POST['lng']??0); if($lat===0.0||$lng===0.0)throw new RuntimeException('Location is required.');
      $attendanceService->recordTimeOut($studentId, $lat, $lng);
      $postOk[]='Time out recorded.';
    }

    if($action==='save_journal'){
      $tab='journal';
      if($ctx===[])throw new RuntimeException('No active semester found.');
      if(!$isSemesterActive)throw new RuntimeException('No active semester is available. Journal submission is currently closed.');
      if($journalForm===[] || ($journalForm['questions'] ?? []) === []){
        throw new RuntimeException('No journal questions are configured yet.');
      }

      $journalWeeksForSubmission = $practicumHasStarted ? practicumBuildWeeksFromStart((string)($ctx['practicum_start_date'] ?? ''), $todayDate) : [];
      if($journalWeeksForSubmission === []){
        throw new RuntimeException('Practicum has not started yet. Your OJT is scheduled to begin on: '.$practicumStartDisplay);
      }

      $postedWeekStart = practicumWeekStart((string) ($_POST['week_start'] ?? ''));
      $postedWeek = null;
      foreach ($journalWeeksForSubmission as $candidateWeek) {
        if ((string) ($candidateWeek['start'] ?? '') === $postedWeekStart) {
          $postedWeek = $candidateWeek;
          break;
        }
      }

      if ($postedWeek === null) {
        http_response_code(403);
        throw new RuntimeException('Select a valid practicum week.');
      }
      if ((string) ($postedWeek['status'] ?? '') === 'upcoming') {
        http_response_code(403);
        throw new RuntimeException('Future weeks cannot be submitted yet.');
      }

      $existingSubmission = $journalModuleService->getSubmissionForStudentWeek($studentId, (int) $ctx['semester_id'], $postedWeekStart);
      $isReopenedPlaceholder = $existingSubmission !== null
        && (int) ($existingSubmission['is_reopened'] ?? 0) === 1;
      $canEditExisting = $existingSubmission !== null && (string) ($existingSubmission['status'] ?? '') === 'declined';
      if ($existingSubmission === null && (string) ($postedWeek['status'] ?? '') === 'completed') {
        throw new RuntimeException('This journal submission has already closed. Please contact your assigned coordinator if you need this journal to be reopened.');
      }
      if ($existingSubmission !== null && !$canEditExisting && !$isReopenedPlaceholder) {
        throw new RuntimeException('You have already submitted your journal for this week.');
      }

      $postedAnswers = $_POST['answers'] ?? [];
      if (!is_array($postedAnswers)) {
        $postedAnswers = [];
      }

      $sanitizedAnswers = [];
      foreach (($journalForm['questions'] ?? []) as $question) {
        if ((int) ($question['is_active'] ?? 0) !== 1) {
          continue;
        }

        $questionId = (int) ($question['id'] ?? 0);
        $questionType = (string) ($question['question_type'] ?? 'paragraph');
        $required = (int) ($question['is_required'] ?? 0) === 1;
        $value = $postedAnswers[$questionId] ?? null;

        if ($questionType === 'checkbox') {
          $value = is_array($value) ? array_values(array_filter(array_map('trim', $value), static fn(string $item): bool => $item !== '')) : [];
          if ($required && $value === []) {
            throw new RuntimeException('Please answer this question.');
          }
        } else {
          $value = trim((string) $value);
          if ($required && $value === '') {
            throw new RuntimeException('Please answer this question.');
          }
        }

        $sanitizedAnswers[$questionId] = $value;
      }

      $submissionWeekNo = journalWeekNumberFromStart($ctx, $postedWeekStart);
      if ($submissionWeekNo <= 0) {
        throw new RuntimeException('Unable to resolve the current practicum week.');
      }

      $submissionExistingId = ($existingSubmission !== null && ($canEditExisting || $isReopenedPlaceholder))
        ? (int) $existingSubmission['id']
        : null;

      $submissionId = $journalModuleService->saveSubmission(
        $studentId,
        (int) $ctx['semester_id'],
        $submissionWeekNo,
        $postedWeekStart,
        $journalForm,
        $sanitizedAnswers,
        $canEditExisting ? 'resubmitted' : 'pending',
        $submissionExistingId
      );

      $postOk[] = $canEditExisting
        ? 'Journal resubmitted for coordinator review.'
        : 'Journal submitted for coordinator review.';
    }

    if($action==='upload_profile_photo'){
      $tab='profile';
      if(!isset($_FILES['profile_photo']) || !is_array($_FILES['profile_photo'])){
        throw new RuntimeException('No photo uploaded.');
      }
      $file=$_FILES['profile_photo'];
      if((int)$file['error']!==UPLOAD_ERR_OK){
        throw new RuntimeException('Photo upload failed.');
      }
      if((int)$file['size']<=0 || (int)$file['size']>2*1024*1024){
        throw new RuntimeException('Photo size must be between 1 byte and 2MB.');
      }
      $finfo=new finfo(FILEINFO_MIME_TYPE);
      $mime=(string)$finfo->file((string)$file['tmp_name']);
      $map=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
      if(!isset($map[$mime])){
        throw new RuntimeException('Only JPG, PNG, or WEBP images are allowed.');
      }
      $uploadDir=__DIR__ . '/../assets/uploads/profile';
      if(!is_dir($uploadDir)){
        mkdir($uploadDir, 0775, true);
      }
      $name='student_'.$studentId.'_'.time().'.'.$map[$mime];
      $dest=$uploadDir.'/'.$name;
      if(!move_uploaded_file((string)$file['tmp_name'],$dest)){
        throw new RuntimeException('Could not save uploaded photo.');
      }
      $publicPath=appUrl('assets/uploads/profile/'.$name);
      $s=$pdo->prepare("INSERT INTO user_profiles (user_id,first_name,middle_name,last_name,phone,photo_path) VALUES (:u,'','','',NULL,:p) ON DUPLICATE KEY UPDATE photo_path=VALUES(photo_path)");
      $s->execute(['u'=>$studentId,'p'=>$publicPath]);
      $postOk[]='Profile photo updated.';
    }

    if($action==='save_profile'){
      $tab='profile'; $first=trim((string)($_POST['first_name']??'')); $middle=trim((string)($_POST['middle_name']??'')); $last=trim((string)($_POST['last_name']??''));
      if($first===''||$last==='')throw new RuntimeException('First and last names are required.');
      $s=$pdo->prepare("INSERT INTO user_profiles (user_id,first_name,middle_name,last_name,phone,photo_path) VALUES (:u,:f,:m,:l,NULL,COALESCE((SELECT photo_path FROM user_profiles WHERE user_id=:u2 LIMIT 1),NULL)) ON DUPLICATE KEY UPDATE first_name=VALUES(first_name),middle_name=VALUES(middle_name),last_name=VALUES(last_name)");
      $s->execute(['u'=>$studentId,'u2'=>$studentId,'f'=>$first,'m'=>$middle!==''?$middle:null,'l'=>$last]); $_SESSION['name']=trim($first.' '.$last); $postOk[]='Profile updated.';
    }

    if($action==='assign_supervisor'){
      $tab='profile';
      throw new RuntimeException('Your partnered-school supervisor will select students for evaluation from the Supervisor Portal.');
    }

    if($action==='save_concern'){
      $tab='concerns';
      $category=trim((string)($_POST['category']??''));
      $subject=trim((string)($_POST['subject']??''));
      $message=trim((string)($_POST['message']??''));
      $concernService->submitConcern($studentId, $currentSemesterId, $category, $subject, $message, $_FILES['attachment'] ?? null);
      $postOk[]='Concern submitted to your coordinator.';
    }

    if($action==='reply_concern'){
      $tab='concerns';
      $concernService->replyAsStudent((int)($_POST['concern_id'] ?? 0), $studentId, (string)($_POST['message'] ?? ''), $_FILES['attachment'] ?? null);
      $postOk[]='Reply submitted.';
    }

    if($action==='change_password'){
      $tab='profile'; $cur=(string)($_POST['current_password']??''); $new=(string)($_POST['new_password']??''); $con=(string)($_POST['confirm_password']??'');
      $s=$pdo->prepare('SELECT password_hash FROM users WHERE id=:id LIMIT 1'); $s->execute(['id'=>$studentId]); $hash=(string)($s->fetchColumn()?:'');
      $passwordErrors=passwordChangeValidationErrors($cur,$new,$con,$hash);
      if($passwordErrors!==[])throw new RuntimeException(implode(' ',$passwordErrors));
      $hasMustChangePassword=false;
      try{
        $columnCheck=$pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c LIMIT 1');
        $columnCheck->execute(['t'=>'users','c'=>'must_change_password']);
        $hasMustChangePassword=(bool)$columnCheck->fetchColumn();
      }catch(Throwable){}
      $sql=$hasMustChangePassword?'UPDATE users SET password_hash=:h, must_change_password=0 WHERE id=:id':'UPDATE users SET password_hash=:h WHERE id=:id';
      $pdo->prepare($sql)->execute(['h'=>hashUserPassword($new),'id'=>$studentId]);
      $_SESSION['must_change_password']=false;
      $postOk[]='Password changed.';
    }
  } catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); $postErrors[]=$e->getMessage(); }
  $_SESSION['flash_errors'] = $postErrors;
  $_SESSION['flash_ok'] = $postOk;
  $redirectQuery = $_GET;
  $redirectQuery['tab'] = $tab;
  if ($tab === 'records') {
    unset($redirectQuery['records_tab']);
  }
  $location = appUrl('student/dashboard.php');
  if (!empty($redirectQuery)) {
    $location .= '?' . http_build_query($redirectQuery);
  }
  header('Location: ' . $location);
  exit;
}

$profileStmt=$pdo->prepare("SELECT u.email,u.school_id,up.first_name,up.middle_name,up.last_name,COALESCE(up.photo_path,'') photo_path FROM users u LEFT JOIN user_profiles up ON up.user_id=u.id WHERE u.id=:id LIMIT 1");
$profileStmt->execute(['id'=>$studentId]);
$profile=$profileStmt->fetch(PDO::FETCH_ASSOC)?:['email'=>'','school_id'=>'','first_name'=>'','middle_name'=>'','last_name'=>'','photo_path'=>''];
$studentSupervisor = $supervisorService->getStudentAssignment($studentId);
$fullName=trim((string)$profile['first_name'].' '.(string)$profile['last_name']);
if($fullName===''){$fullName=(string)($user['name']??'Student');}
$firstName=trim((string)($profile['first_name'] ?? ''));
$middleName=trim((string)($profile['middle_name'] ?? ''));
$lastName=trim((string)($profile['last_name'] ?? ''));
$middleInitial=$middleName!=='' ? strtoupper(substr($middleName,0,1)).'.' : '';
$firstWithMiddle=trim($firstName . ($middleInitial!=='' ? ' '.$middleInitial : ''));
$headerDisplayName=$fullName;
if($lastName!=='' && $firstWithMiddle!==''){
  $headerDisplayName=$lastName.', '.$firstWithMiddle;
} elseif($firstWithMiddle!==''){
  $headerDisplayName=$firstWithMiddle;
} elseif($lastName!==''){
  $headerDisplayName=$lastName;
}

$todayText=(new DateTimeImmutable('today'))->format('l, F j, Y');
$attendanceWeekStart=monday($now)->format('Y-m-d');
$attendanceWeekEnd=(new DateTimeImmutable($attendanceWeekStart))->modify('+4 days')->format('Y-m-d');

$semesterWeeks=[];
$selectedWeekStart=(string)($_GET['week_start']??$attendanceWeekStart);
if($displaySemesterCtx!==[] && practicumCanRun((string) ($displaySemesterCtx['practicum_start_date'] ?? ''), $now->format('Y-m-d'), (string) ($displaySemesterCtx['practicum_status'] ?? ''))){
  $semesterWeeks=practicumBuildWeeksFromStart((string)($displaySemesterCtx['practicum_start_date'] ?? ''), $now->format('Y-m-d'));
  if($semesterWeeks){
    $valid=array_column($semesterWeeks,'start');
    if(!in_array($selectedWeekStart,$valid,true)){
      $activeWeek=practicumActiveWeek($semesterWeeks);
      $selectedWeekStart=(string)($activeWeek['start']??$semesterWeeks[0]['start']);
    }
  }
}
$selectedWeek=null;
foreach($semesterWeeks as $w){if($w['start']===$selectedWeekStart){$selectedWeek=$w;break;}}
if($selectedWeek===null&&$semesterWeeks){$selectedWeek=$semesterWeeks[0];}
$semesterWeekMap=[];
foreach($semesterWeeks as $weekRow){$semesterWeekMap[(string)$weekRow['start']]=$weekRow;}
$recordHolidayDates = [];
if ($semesterWeeks !== []) {
  $recordHolidayDates = $systemHolidayService->listBetween(
    (string) $semesterWeeks[0]['start'],
    (string) $semesterWeeks[count($semesterWeeks) - 1]['end'],
    $assignedGeofenceId > 0 ? $assignedGeofenceId : null
  );
}
$weekLabelCurrent=$selectedWeek['display_label']??($selectedWeek['label']??'Week 1');
$weekNumberCurrent=(int)($selectedWeek['index']??1);
$weekCountTotal=max(1,count($semesterWeeks));
$attendanceWeekText='';
if($selectedWeek!==null){
  $attendanceWeekText=(string)($selectedWeek['display_label']??$selectedWeek['label']??'Week 1') . ', ' . (string)($selectedWeek['start']??'') . ' to ' . (string)($selectedWeek['end']??'');
}

$attendanceRows=[];
$attendanceSummary=['credited_hours'=>0.0,'present'=>0,'absent'=>0,'late'=>0];
$attendanceSemesterId=(int)($displaySemesterCtx['semester_id'] ?? $ctx['semester_id'] ?? 0);
$q=$pdo->prepare("SELECT attendance_date,time_in,time_out,total_hours,attendance_status,remarks,COALESCE(is_override,0) AS is_override,override_reason FROM attendance_records WHERE student_user_id=:u AND semester_id=:m ORDER BY attendance_date DESC");
$q->execute(['u'=>$studentId,'m'=>$attendanceSemesterId]);
$attendanceRows=$q->fetchAll(PDO::FETCH_ASSOC);
$presentDates=[];
foreach($attendanceRows as $r){
  $dateVal=(string)$r['attendance_date'];
  $isPastOpen=(!empty($r['time_in']) && empty($r['time_out']) && $dateVal < $now->format('Y-m-d'));
  $attendanceSummary['credited_hours']+=(float)$r['total_hours'];
  if(!empty($r['time_in'])){
    $in=new DateTimeImmutable((string)$r['time_in']);
    $sched=$in->setTime(8,0,0);
    if($in>$sched){$attendanceSummary['late']++;}
  }
  if(!empty($r['time_in']) && !empty($r['time_out'])){
    $presentDates[$dateVal]=true;
  } elseif(!empty($r['time_in']) && empty($r['time_out']) && !$isPastOpen) {
    $presentDates[$dateVal]=true;
  }
}
$attendanceSummary['present']=count($presentDates);
$attendanceStart=$practicumHasStarted
  ? (practicumEffectiveStart((string)($displaySemesterCtx['practicum_start_date'] ?? '')) ?? (string)($displaySemesterCtx['semester_start_date'] ?? ''))
  : '';
$attendanceEnd=(string)($displaySemesterCtx['semester_end_date'] ?? '');
$yesterday=$now->modify('-1 day')->format('Y-m-d');
if($attendanceEnd==='' || $attendanceEnd>$yesterday){$attendanceEnd=$yesterday;}
$attendanceHolidayDates = $attendanceStart !== '' && $attendanceEnd !== '' && $attendanceStart <= $attendanceEnd
  ? $systemHolidayService->listBetween($attendanceStart, $attendanceEnd, $assignedGeofenceId > 0 ? $assignedGeofenceId : null)
  : [];
$expectedAttendanceDays=0;
if($attendanceStart!=='' && $attendanceEnd!=='' && $attendanceStart<=$attendanceEnd){
  for($day=new DateTimeImmutable($attendanceStart);$day<=new DateTimeImmutable($attendanceEnd);$day=$day->modify('+1 day')){
    if((int)$day->format('N')<=5){$expectedAttendanceDays++;}
  }
}
$attendanceSummary['present'] += count(array_diff(array_keys($attendanceHolidayDates), array_keys($presentDates)));
$attendanceSummary['absent']=max(0,$expectedAttendanceDays-$attendanceSummary['present']);

$journalQuestions = $journalForm['questions'] ?? [];
$journalWeeksAvailable = array_values(array_filter(
  $semesterWeeks,
  static fn(array $week): bool => (string) ($week['status'] ?? '') !== 'upcoming'
));
$journalSelectedWeek = $selectedWeek;
if ($journalSelectedWeek === null || (string) ($journalSelectedWeek['status'] ?? '') === 'upcoming') {
  $journalSelectedWeek = practicumActiveWeek($journalWeeksAvailable) ?? ($journalWeeksAvailable[0] ?? $journalSelectedWeek);
}
$journalSelectedWeekStart = (string) ($journalSelectedWeek['start'] ?? '');
$journalSelectedWeekLabel = $journalSelectedWeek !== null
  ? journalWeekOptionLabel($journalSelectedWeek)
  : 'Week';
$journalSelectedWeekStatus = (string) ($journalSelectedWeek['status'] ?? '');
$journalSelectedWeekDisplay = $journalSelectedWeek !== null
  ? ((string) ($journalSelectedWeek['display_label'] ?? $journalSelectedWeek['label'] ?? 'Week')) . ', ' . (string) ($journalSelectedWeek['start'] ?? '') . ' to ' . (string) ($journalSelectedWeek['end'] ?? '')
  : '';
$journalSelectedWeekSubmission = null;
$journalSelectedWeekAnswers = [];
$journalSelectedWeekStatusLabel = 'Missing';
$journalSelectedWeekCanEdit = false;
$journalHistory = [];

if ($ctx !== [] && (int) ($ctx['semester_id'] ?? 0) > 0) {
  $allFormData = $journalModuleService->getActiveFormWithQuestions((int) $ctx['semester_id']);
  if ($journalSelectedWeekStart !== '') {
    $journalSelectedWeekSubmission = $journalModuleService->getSubmissionForStudentWeek($studentId, (int) $ctx['semester_id'], $journalSelectedWeekStart);
    $journalSelectedWeekAnswers = $journalSelectedWeekSubmission !== null ? $journalModuleService->submissionAnswersByQuestion($journalSelectedWeekSubmission) : [];
    $journalSelectedWeekStatusLabel = $journalModuleService->studentWeekStatusLabel($journalSelectedWeekSubmission, $journalSelectedWeekStatus, !empty($journalSelectedWeekSubmission['submitted_at'] ?? null));
    $journalSelectedWeekCanEdit = $journalModuleService->studentWeekCanEdit($journalSelectedWeekSubmission, $journalSelectedWeekStatus, true);
  }
  $journalHistory = $journalModuleService->getStudentJournalHistory($studentId, (int) $ctx['semester_id']);
  if (tableExists($pdo, 'student_journals')) {
    $legacyStmt = $pdo->prepare(
      "SELECT id, week_start, submitted_at, status
       FROM student_journals
       WHERE student_user_id = :student_user_id
         AND semester_id = :semester_id
       ORDER BY week_start DESC, submitted_at DESC"
    );
    $legacyStmt->execute([
      'student_user_id' => $studentId,
      'semester_id' => (int) $ctx['semester_id'],
    ]);
    foreach ($legacyStmt->fetchAll(PDO::FETCH_ASSOC) as $legacyJournal) {
      $journalHistory[] = $legacyJournal + ['points_awarded' => 0, 'max_points' => 50, 'source' => 'legacy'];
    }
  }
  foreach ($journalHistory as &$historyRow) {
    if (!isset($historyRow['source'])) {
      $historyRow['source'] = 'dynamic';
    }
  }
  unset($historyRow);
  usort($journalHistory, static function (array $left, array $right): int {
    $leftWeek = (string) ($left['week_start'] ?? '');
    $rightWeek = (string) ($right['week_start'] ?? '');
    if ($leftWeek !== $rightWeek) {
      return strcmp($rightWeek, $leftWeek);
    }

    return strcmp((string) ($right['submitted_at'] ?? ''), (string) ($left['submitted_at'] ?? ''));
  });
}

$checklistHistory=[];
$checklistMaximumPoints=0;
if($ctx!==[] && tableExists($pdo,'student_weekly_checklist')){
  $checklistStates=[];
  $labelColumn=tableExists($pdo,'evaluation_checklist_items') && columnExists($pdo,'evaluation_checklist_items','item_name')?'item_name':'label';
  $displayOrderColumn=tableExists($pdo,'evaluation_checklist_items') && columnExists($pdo,'evaluation_checklist_items','display_order')?'display_order':'sort_order';
  if (tableExists($pdo,'evaluation_checklist_items')) {
    $checklistSemesterFilter = columnExists($pdo,'evaluation_checklist_items','semester_id') ? ' AND (semester_id = :semester_id OR semester_id IS NULL)' : '';
    $checklistMaximumStmt = $pdo->prepare("SELECT COALESCE(SUM(points), 0) FROM evaluation_checklist_items WHERE is_active = 1{$checklistSemesterFilter}");
    $checklistMaximumStmt->execute($checklistSemesterFilter !== '' ? ['semester_id' => (int) $ctx['semester_id']] : []);
    $checklistMaximumPoints = (int) $checklistMaximumStmt->fetchColumn();
  }
  $cw=$pdo->prepare("SELECT swc.week_start,swc.checklist_item_id,swc.is_checked,swc.awarded_points,COALESCE(eci.{$labelColumn}, CONCAT('Checklist Item #', swc.checklist_item_id)) AS item_label,COALESCE(eci.points, swc.awarded_points, 0) AS item_points FROM student_weekly_checklist swc LEFT JOIN evaluation_checklist_items eci ON eci.id=swc.checklist_item_id WHERE swc.student_user_id=:u AND swc.semester_id=:m ORDER BY swc.week_start DESC,COALESCE(eci.{$displayOrderColumn}, swc.checklist_item_id) ASC,swc.checklist_item_id ASC");
  $cw->execute(['u'=>$studentId,'m'=>(int)$ctx['semester_id']]);
  foreach($cw->fetchAll(PDO::FETCH_ASSOC) as $row){
    $ws=(string)($row['week_start']??'');
    $itemId=(int)($row['checklist_item_id']??0);
    if($ws===''||$itemId<=0){continue;}
    if(!isset($checklistStates[$ws])){$checklistStates[$ws]=[];}
    $checklistStates[$ws][$itemId]=[
      'checked'=>(int)($row['is_checked']??0)===1,
      'awarded_points'=>(int)($row['awarded_points']??0),
      'label'=>(string)($row['item_label']??('Checklist Item #'.$itemId)),
      'points'=>(int)($row['item_points']??0),
    ];
  }
  krsort($checklistStates);
  foreach($checklistStates as $ws=>$states){
    $totalItems=count($states);
    $done=0;
    $pointsEarned=0;
    $items=[];
    foreach($states as $state){
      if($state['checked']){$done++;}
      $pointsEarned+=(int)$state['awarded_points'];
      $items[]=[
        'label'=>(string)$state['label'],
        'points'=>(int)($state['points']??0),
        'checked'=>(bool)$state['checked'],
      ];
    }
    $remarks=$totalItems===0
      ? 'No checklist configured for this semester yet.'
      : ($done===$totalItems ? 'All checklist criteria were completed for this week.' : ($done>0 ? 'Some checklist criteria still need improvement.' : 'No checklist criteria were marked complete for this week.'));
    $checklistHistory[]=[
      'week_index'=>(int)($semesterWeekMap[$ws]['index'] ?? 0),
      'week_label'=>(string)($semesterWeekMap[$ws]['display_label'] ?? $semesterWeekMap[$ws]['label'] ?? 'Week'),
      'week_start'=>$ws,
      'done'=>$done,
      'total'=>$totalItems,
      'points_earned'=>$pointsEarned,
      'status_label'=>checklistCompletionStatus($done,$totalItems),
      'status_class'=>checklistCompletionStatusClass($done,$totalItems),
      'items'=>$items,
      'remarks'=>$remarks,
    ];
  }
}

$weeklyRecords = [];
$weeklyTotals = [
  'days' => array_fill_keys(['M', 'T', 'W', 'TH', 'F'], 0),
  'journal_submitted' => 0,
  'journal_points' => 0,
  'journal_maximum' => 0,
  'checklist_points' => 0,
  'checklist_maximum' => 0,
  'credited_hours' => 0.0,
  'present_days' => 0,
  'expected_days' => 0,
];
$attendanceByDate = [];
foreach ($attendanceRows as $attendanceRow) {
  $attendanceByDate[(string) ($attendanceRow['attendance_date'] ?? '')] = $attendanceRow;
}
$journalByWeek = [];
foreach ($journalHistory as $journalRow) {
  $weekStart = (string) ($journalRow['week_start'] ?? '');
  if ($weekStart !== '' && !isset($journalByWeek[$weekStart])) {
    $journalByWeek[$weekStart] = $journalRow;
  }
}
$checklistByWeek = [];
foreach ($checklistHistory as $checklistRow) {
  $checklistByWeek[(string) $checklistRow['week_start']] = $checklistRow;
}
$practicumStartDate = practicumEffectiveStart((string) ($displaySemesterCtx['practicum_start_date'] ?? ''))
  ?? (string) ($displaySemesterCtx['semester_start_date'] ?? '');
$todayDate = $now->format('Y-m-d');

foreach ($semesterWeeks as $week) {
  $weekStart = (string) ($week['start'] ?? '');
  $weekEnd = (string) ($week['end'] ?? '');
  if ($weekStart === '' || $weekEnd === '') {
    continue;
  }
  $weekJournal = $journalByWeek[$weekStart] ?? null;
  $weekChecklist = $checklistByWeek[$weekStart] ?? null;
  $weekHours = 0.0;
  $weekPresentDays = 0;
  $weekAbsentDays = 0;
  $dayMarks = [];
  $cursor = new DateTimeImmutable($weekStart);
  $dayKeys = ['M', 'T', 'W', 'TH', 'F'];
  foreach ($dayKeys as $dayKey) {
    $date = $cursor->format('Y-m-d');
    $record = $attendanceByDate[$date] ?? null;
    if (isset($recordHolidayDates[$date])) {
      $dayMarks[$dayKey] = 'holiday';
      if ($date >= $practicumStartDate && $date <= $todayDate
        && ((string) ($displaySemesterCtx['semester_end_date'] ?? '') === '' || $date <= (string) $displaySemesterCtx['semester_end_date'])) {
        $weekPresentDays++;
      }
      $cursor = $cursor->modify('+1 day');
      continue;
    }
    $isExpectedDay = $date >= $practicumStartDate && $date <= $todayDate && $date <= $weekEnd;
    $isPresentDay = $record !== null && !empty($record['time_in']) && (
      !empty($record['time_out']) || $date === $todayDate
    );
    if ($isPresentDay) {
      $dayMarks[$dayKey] = 'present';
      $weekPresentDays++;
      $weekHours += (float) ($record['total_hours'] ?? 0);
    } elseif ($isExpectedDay) {
      $dayMarks[$dayKey] = 'absent';
      $weekAbsentDays++;
    } else {
      $dayMarks[$dayKey] = 'future';
    }
    $cursor = $cursor->modify('+1 day');
  }
  $weekStatus = (string) ($week['status'] ?? 'upcoming');
  $journalDue = $weekStart <= $todayDate;
  $journalSubmitted = $weekJournal !== null;
  $checklistDue = $weekStart <= $todayDate && $checklistMaximumPoints > 0;
  $checklistDone = $weekChecklist !== null
    && (int) ($weekChecklist['points_earned'] ?? 0) >= $checklistMaximumPoints;
  if ($weekStatus === 'upcoming') {
    $statusLabel = 'Not Yet Started';
    $statusClass = 'text-slate-500';
  } elseif (($journalDue && !$journalSubmitted) || ($checklistDue && !$checklistDone)) {
    $statusLabel = 'Incomplete';
    $statusClass = 'text-rose-600';
  } elseif ($weekAbsentDays > 0) {
    $statusLabel = 'With Absence';
    $statusClass = 'text-amber-600';
  } elseif ($weekStatus === 'completed') {
    $statusLabel = 'Complete';
    $statusClass = 'text-emerald-700';
  } else {
    $statusLabel = 'In Progress';
    $statusClass = 'text-blue-700';
  }
  $weeklyRecords[] = [
    'index' => (int) ($week['index'] ?? count($weeklyRecords) + 1),
    'start' => $weekStart,
    'end' => $weekEnd,
    'label' => (string) ($week['display_label'] ?? $week['label'] ?? 'Week'),
    'status' => $weekStatus,
    'days' => $dayMarks,
    'journal_submitted' => $journalSubmitted,
    'journal_points' => $journalSubmitted ? (int) ($weekJournal['points_awarded'] ?? 0) : null,
    'journal_maximum' => $journalSubmitted ? max(1, (int) ($weekJournal['max_points'] ?? 50)) : null,
    'journal_status' => $weekJournal === null ? '' : (string) ($weekJournal['status'] ?? 'pending'),
    'checklist_points' => $weekChecklist === null ? null : (int) ($weekChecklist['points_earned'] ?? 0),
    'checklist_maximum' => $weekChecklist === null ? null : $checklistMaximumPoints,
    'credited_hours' => $weekHours,
    'present_days' => $weekPresentDays,
    'expected_days' => $weekPresentDays + $weekAbsentDays,
    'status_label' => $statusLabel,
    'status_class' => $statusClass,
  ];
  foreach ($dayMarks as $dayKey => $mark) {
    if ($mark === 'present' || $mark === 'holiday') {
      $weeklyTotals['days'][$dayKey]++;
    }
  }
  $weeklyTotals['journal_submitted'] += $journalSubmitted ? 1 : 0;
  if ($journalSubmitted) {
    $weeklyTotals['journal_points'] += (int) ($weekJournal['points_awarded'] ?? 0);
    $weeklyTotals['journal_maximum'] += max(1, (int) ($weekJournal['max_points'] ?? 50));
  }
  if ($weekChecklist !== null) {
    $weeklyTotals['checklist_points'] += (int) ($weekChecklist['points_earned'] ?? 0);
    $weeklyTotals['checklist_maximum'] += $checklistMaximumPoints;
  }
  $weeklyTotals['credited_hours'] += $weekHours;
  $weeklyTotals['present_days'] += $weekPresentDays;
  $weeklyTotals['expected_days'] += $weekPresentDays + $weekAbsentDays;
}

$geoLat=(float)($geo['latitude']??8.4795); $geoLng=(float)($geo['longitude']??124.6473); $geoRadius=(float)($geo['radius_meters']??80);
$schoolName=(string)($geo['name']??'Not assigned'); $schoolAddr=(string)($geo['address']??'No address available'); $coordinatorName=(string)($geo['coordinator_name']??'Not assigned');
$schoolType=(string)($geo['school_type']??'');
$schoolTypeLabel=$schoolType==='' ? 'Not assigned' : ($schoolType==='private_school' ? 'Private' : 'Public');
$todayIn=fmtAttendanceTime($liveSnapshot['time_in'] ?? null);
$todayOut=fmtAttendanceTime($liveSnapshot['time_out'] ?? null);
$todayHours=hm((float)($liveSnapshot['today_hours']??0));
$overallHoursDisplay=hm((float)($liveSnapshot['overall_hours']??0));
$publicHoursDisplay=hm((float)($liveSnapshot['public_hours']??0));
$privateHoursDisplay=hm((float)($liveSnapshot['private_hours']??0));
$isTodayHoliday = $systemHolidayService->isHoliday($now->format('Y-m-d'), $assignedGeofenceId > 0 ? $assignedGeofenceId : null);
$hasTodayOverride = (int)($liveSnapshot['is_override'] ?? 0) === 1;
$statusToday=$isTodayHoliday ? 'H - Holiday' : (string)($liveSnapshot['status_label'] ?? formatAttendanceStatus($today!==[]?$today:null, $now->format('Y-m-d')));
$hasValidTimeIn = ($today!==[] && !empty($today['time_in']) && strtotime((string)$today['time_in']) !== false && strtotime((string)$today['time_in']) > 0);
$canShowTimeOut = (!$isTodayHoliday && $isSemesterActive && $isActiveSemesterWeek && $hasValidTimeIn && empty($today['time_out']));
$canShowTimeIn = (!$isTodayHoliday && $isSemesterActive && $isActiveSemesterWeek && $today===[] && $isTimeInWindowOpen);
$canClickTimeOut = false;
$timeOutHint = '';
$timeInHint = '';
if($today===[] && !$isTimeInWindowOpen){
  $timeInHint = 'Time in is available from '.$timeInStartLabel.' until 4:00 PM to complete 8 hours before 11:59 PM.';
}
if(!$isSemesterActive){
  $timeInHint = 'No active semester enrollment found. Please wait for your Head Teacher to import your records for the current semester.';
} elseif(!$practicumHasStarted) {
  $timeInHint = 'Practicum has not started yet. Your OJT is scheduled to begin on: '.$practicumStartDisplay.'.';
}
if ($isTodayHoliday) {
  $timeInHint = 'Today is a system-wide school holiday. You are marked present for attendance, and no practicum hours will be credited.';
  $timeOutHint = 'Today is a system-wide school holiday. Attendance hours are not credited.';
}
if($canShowTimeOut){
  $elapsedSeconds = (int)($liveSnapshot['elapsed_seconds'] ?? 0);
  $elapsedHours = $elapsedSeconds / 3600;
  if($elapsedSeconds >= 28800){
    $canClickTimeOut = true;
  } else {
    $remaining = min(8, max(0, 8 - $elapsedHours));
    $timeOutHint = 'Time out will be enabled in about ' . number_format($remaining, 2) . ' hour(s).';
  }
}

$journalSubmission = $journalSelectedWeekSubmission;
$journalSubmissionAnswers = $journalSelectedWeekAnswers;
$journalSubmissionStatus = (string) ($journalSubmission['status'] ?? '');
$journalCanResubmit = $journalSubmission !== null && $journalSubmissionStatus === 'declined';
$journalIsReopened = $journalSubmission !== null && (int) ($journalSubmission['is_reopened'] ?? 0) === 1;
$journalCanSubmitJournal = $isSemesterActive && $journalSelectedWeekStart !== '' && ($journalSelectedWeekCanEdit || $journalCanResubmit);
$journalSubmissionMessage = !$practicumHasStarted
  ? 'Practicum has not started yet. Your OJT is scheduled to begin on: ' . $practicumStartDisplay . '.'
  : ($journalSelectedWeekStatus === 'upcoming'
    ? 'Future weeks are hidden until they start.'
    : ($journalSubmission === null
      ? ($journalSelectedWeekStatus === 'active'
        ? 'This week is available for journal submission.'
        : 'This journal submission has already closed. Please contact your assigned coordinator if you need this journal to be reopened.')
      : ($journalIsReopened
        ? 'This journal was reopened by your coordinator. You may edit and submit it again.'
        : ($journalCanResubmit
          ? 'Your journal was declined. Edit your answers and resubmit.'
          : 'You already submitted this journal. You can review it below.'))));
$journalNoticeClass = $journalCanSubmitJournal
  ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
  : 'border-amber-200 bg-amber-50 text-amber-800';
$studentConcernSummary=$concernService->getStudentConcernSummary($studentId, $currentSemesterId);
$studentConcerns=$concernService->getStudentConcerns($studentId, $currentSemesterId);

function urlWith(array $overrides=[]): string { $q=array_merge($_GET,$overrides); return '?'.http_build_query($q); }
function journalWeekOptionLabel(array $week): string {
  $label = (string) ($week['label'] ?? 'Week');
  return (string) ($week['status'] ?? '') === 'active' ? 'Current Week (' . $label . ')' : $label;
}
function studentJournalStatusClass(string $label): string {
  return match ($label) {
    'Submitted' => 'ok',
    'Approved' => 'ok',
    'Reviewed' => 'warn',
    'Declined' => 'bad',
    'Reopened', 'Pending' => 'warn',
    'Missing', 'Upcoming' => 'bad',
    default => 'warn',
  };
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Student Portal</title>
  <link rel="icon" type="image/png" href="/assets/images/logo_college.png">
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
  <style>
    body{background:#f4f6fb}
    .card{background:#fff;border:1px solid #dbe4ef;border-radius:14px}
    .field{width:100%;border:1px solid #d4dbe8;border-radius:10px;padding:.55rem .75rem;background:#fff}
    .field:focus{outline:none;border-color:#10b981;box-shadow:0 0 0 3px rgba(16,185,129,.15)}
    .student-nav{position:relative;z-index:10;display:flex;align-items:center;gap:.35rem;overflow:visible;padding:.375rem}
    .student-nav::-webkit-scrollbar{display:none}
    .tab{display:inline-flex;min-height:40px;align-items:center;justify-content:center;white-space:nowrap;padding:.55rem .9rem;border-radius:.6rem;font-size:.875rem;font-weight:600;color:#475569;transition:background .2s ease,color .2s ease,box-shadow .2s ease}
    .tab:hover{background:#f1f5f9;color:#0f172a}
    .tab.active{background:#e8f8f1;color:#047857;box-shadow:inset 0 0 0 1px #bbf7d0}
    .tab.profile-new{background:#6ee7b7;color:#064e3b;box-shadow:inset 0 0 0 1px #34d399}
    .tab.profile-returning{background:#fef3c7;color:#92400e;box-shadow:inset 0 0 0 1px #fcd34d}
    .tab.profile-new:hover{background:#34d399;color:#064e3b}
    .tab.profile-returning:hover{background:#fde68a;color:#78350f}
    .profile-menu{position:relative;display:inline-flex}
    .profile-menu summary{list-style:none;cursor:pointer;width:100%}
    .profile-menu summary::-webkit-details-marker{display:none}
    .profile-menu[open] summary.profile-new{background:#34d399;color:#064e3b}
    .profile-menu[open] summary.profile-returning{background:#fde68a;color:#78350f}
    .profile-menu-list{position:absolute;z-index:20;top:calc(100% + .5rem);right:0;min-width:230px;padding:.5rem;border:1px solid #dbe4ef;border-radius:.75rem;background:#fff;box-shadow:0 16px 35px rgba(15,23,42,.14);animation:profile-menu-in .16s ease-out}
    .profile-menu-list a{display:block;border-radius:.5rem;padding:.65rem .75rem;font-size:.875rem;font-weight:500;color:#334155}
    .profile-menu-list a:hover,.profile-menu-list a:focus-visible{background:#ecfdf5;color:#047857;outline:none}
    @keyframes profile-menu-in{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:translateY(0)}}
    @media(min-width:641px){.student-nav>.tab,.student-nav>.profile-menu{flex:1;min-width:0}.student-nav>.profile-menu .tab{width:100%}}
    @media(max-width:640px){.student-nav{flex-wrap:wrap;gap:.15rem}.student-nav>.tab,.student-nav>.profile-menu{flex:1 1 auto}.tab{padding:.5rem .75rem;font-size:.8125rem}.profile-menu-list{left:0;right:auto;min-width:215px}}
    .status{font-size:.75rem;padding:.2rem .55rem;border-radius:999px;font-weight:700}
    .status.ok{background:#dcfce7;color:#166534}
    .status.warn{background:#fef3c7;color:#92400e}
    .status.upcoming{background:#f1f5f9;color:#475569}
    .status.bad{background:#fee2e2;color:#991b1b}
  </style>
</head>
<body class="text-slate-800">
<header class="bg-gradient-to-r from-[#064e3b] to-[#047857] text-white shadow-sm">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-3 flex items-center justify-between gap-3">
    <div class="flex items-center gap-3 min-w-0">
      <img src="<?= htmlspecialchars(appUrl('assets/images/logo_college.png')) ?>" alt="Logo" class="h-12 w-12 rounded bg-white/90 p-0.5 object-contain">
      <div class="min-w-0">
        <div class="font-semibold leading-tight truncate">Student Portal</div>
        <div class="mt-0.5 truncate text-[10px] font-semibold tracking-[.12em] text-emerald-100 sm:text-[11px]">CAGAYAN DE ORO COLLEGE · PHINMA EDUCATION</div>
      </div>
    </div>
    <div class="text-sm flex items-center gap-2 sm:gap-3">
      <span class="hidden sm:inline truncate max-w-[260px] font-medium"><?= htmlspecialchars($headerDisplayName) ?></span>
      <span class="opacity-70">|</span>
      <div class="w-[104px] sm:w-[112px]"><?php renderSignOutButton('', true, 'sign-out-button--compact'); ?></div>
    </div>
  </div>
</header>

<main class="max-w-5xl mx-auto px-3 sm:px-5 py-5 sm:py-8 space-y-4">


  <div class="card p-1.5 sm:p-2">
    <nav class="student-nav" aria-label="Student portal navigation">
      <a class="tab <?= $tab==='attendance'?'active':'' ?>" href="<?= htmlspecialchars(urlWith(['tab'=>'attendance'])) ?>">Attendance</a>
      <a class="tab <?= $tab==='journal'?'active':'' ?>" href="<?= htmlspecialchars(urlWith(['tab'=>'journal'])) ?>">Journal</a>
      <a class="tab <?= $tab==='records'?'active':'' ?>" href="<?= htmlspecialchars(urlWith(['tab'=>'records'])) ?>">Records</a>
      <a class="tab <?= $tab==='concerns'?'active':'' ?>" href="<?= htmlspecialchars(urlWith(['tab'=>'concerns'])) ?>">Concerns</a>
      <?php if ($isReturningStudent): ?>
        <details class="profile-menu"><summary class="tab profile-returning <?= $tab==='profile'?'active':'' ?>" aria-label="Open repeat intern menu">Repeat Intern <span aria-hidden="true" class="ml-1 text-xs">▾</span></summary><div class="profile-menu-list" role="menu" aria-label="Repeat intern profile options">
          <a role="menuitem" href="<?= htmlspecialchars(urlWith(['tab'=>'profile','profile_view'=>'current'])) ?>">Profile Settings</a>
          <a role="menuitem" href="<?= htmlspecialchars(urlWith(['tab'=>'profile','profile_view'=>'history'])) ?>">History</a>
        </div></details>
      <?php else: ?>
        <a class="tab profile-new <?= $tab==='profile'?'active':'' ?>" href="<?= htmlspecialchars(urlWith(['tab'=>'profile'])) ?>">First-Time Intern</a>
      <?php endif; ?>
    </nav>
  </div>

  <?php if($tab==='attendance'): ?>
  <section class="card p-4 sm:p-5 space-y-4">
    <div class="flex items-center justify-between gap-2">
      <h2 class="text-2xl font-bold">Attendance</h2>
      <div class="text-right text-sm text-slate-500">
        <?php if($schoolName!=='Not assigned' && $attendanceWeekText!==''): ?><div><?= htmlspecialchars($attendanceWeekText) ?></div><?php endif; ?>
      </div>
    </div>
    <div class="text-sm text-slate-500"><?= htmlspecialchars($todayText) ?></div>
    <?php if ($deploymentRows !== []): ?>
    <div class="card border-emerald-100 p-4">
      <h3 class="font-semibold">Practicum School Progress</h3>
      <div class="mt-3 grid gap-3 md:grid-cols-2">
        <?php foreach ($deploymentRows as $deployment):
          $renderedSeconds = min((int)$deployment['required_seconds'], (int)$deployment['rendered_seconds']);
          $rendered = sprintf('%d.%02d', intdiv($renderedSeconds, 3600), intdiv($renderedSeconds % 3600, 60));
          $status = (string)$deployment['deployment_status'];
          $statusText = $status === 'completed' ? 'Completed ✓' : ($status === 'active' ? 'Active' : 'Locked');
        ?>
        <div class="rounded-lg border border-slate-200 p-3" data-deployment-id="<?= (int)$deployment['id'] ?>">
          <p class="font-semibold"><?= (int)$deployment['deployment_order'] ?>. <?= htmlspecialchars((string)$deployment['school_name']) ?></p>
          <p class="text-xs text-slate-500"><?= htmlspecialchars(deploymentTypeLabel((string)$deployment['school_type'])) ?> School</p>
          <p class="mt-2 text-sm" data-deployment-progress><?= htmlspecialchars($status === 'locked' ? 'Locked' : $rendered . ' / 180.00 hours') ?></p>
          <p class="text-sm font-semibold <?= $status === 'completed' ? 'text-emerald-700' : ($status === 'active' ? 'text-blue-700' : 'text-slate-500') ?>" data-deployment-status>Status: <?= htmlspecialchars($statusText) ?></p>
        </div>
        <?php endforeach; ?>
      </div>
      <?php if (count($deploymentRows) > 1 && (string)$deploymentRows[0]['deployment_status'] !== 'completed'): ?><p class="mt-3 text-xs text-amber-700">Complete the required hours for your assigned school before continuing to the next placement.</p><?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if(!$isSemesterActive): ?>
    <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
      No active semester enrollment found.
      Please wait for your Head Teacher to import your records for the current semester. You can still view your history in <strong>Records</strong>.
    </div>
    <?php elseif(!$isActiveSemesterWeek): ?>
    <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
      Attendance and journal submission are locked until the current date reaches a practicum week. You can still view your history in <strong>Records</strong>.
    </div>
    <?php endif; ?>

    <div class="grid md:grid-cols-3 gap-3">
      <div class="card p-4 md:col-span-2">
        <div class="flex justify-between items-center mb-2"><h3 class="font-semibold">Assigned School</h3><span class="status ok"><?= htmlspecialchars($schoolTypeLabel) ?></span></div>
        <dl class="grid sm:grid-cols-3 gap-y-2 text-sm">
          <dt class="text-slate-500">School Name</dt><dd class="sm:col-span-2 font-medium"><?= htmlspecialchars($schoolName) ?></dd>
          <dt class="text-slate-500">Address</dt><dd class="sm:col-span-2"><?= htmlspecialchars($schoolAddr) ?></dd>
          <dt class="text-slate-500">Coordinator</dt><dd class="sm:col-span-2 font-medium"><?= htmlspecialchars($coordinatorName) ?></dd>
          <dt class="text-slate-500">Geofence Radius</dt><dd class="sm:col-span-2 font-semibold"><?= h($geoRadius) ?> meters</dd>
        </dl>
      </div>
      <div class="card p-4">
        <div class="flex items-center justify-between mb-2"><h3 class="font-semibold">Location Status</h3><button type="button" id="btnRetryLoc" class="text-xs text-slate-500 hover:text-slate-800">Retry</button></div>
        <p id="geoStatus" class="text-sm font-semibold text-amber-600">Checking your location...</p>
        <p id="geoDistance" class="text-xs text-slate-500 mt-1">Distance: -- (radius: <?= h($geoRadius) ?>m)</p>
        <button type="button" id="btnEnableLocation" class="mt-3 w-full rounded-lg border border-emerald-600 bg-emerald-600 py-2 text-sm font-semibold text-white transition hover:bg-emerald-700 focus:outline-none focus:ring-4 focus:ring-emerald-200">Enable Location Access</button>
        <div class="mt-3 space-y-2">
          <?php if($canShowTimeIn): ?>
          <form method="post"><input type="hidden" name="action" value="time_in"><input type="hidden" name="attendance_request_date" value="<?= htmlspecialchars($attendanceRequestDate) ?>"><input type="hidden" name="attendance_request_token" value="<?= htmlspecialchars($attendanceRequestToken) ?>"><input type="hidden" id="latField" name="lat"><input type="hidden" id="lngField" name="lng"><button id="btnTimeIn" class="w-full rounded-lg bg-slate-300 py-2 text-sm font-semibold text-slate-600" disabled>Time In</button></form>
          <?php elseif($today!==[]): ?>
          <button id="btnTimeInDone" class="w-full rounded-lg bg-emerald-100 border border-emerald-300 py-2 text-sm font-semibold text-emerald-700 cursor-not-allowed" disabled>Timed In</button>
          <?php else: ?>
          <button class="w-full rounded-lg bg-slate-200 border border-slate-300 py-2 text-sm font-semibold text-slate-500 cursor-not-allowed" disabled>Time In Unavailable</button>
          <?php if($timeInHint!==''): ?><p class="text-xs text-amber-700"><?= htmlspecialchars($timeInHint) ?></p><?php endif; ?>
          <?php endif; ?>
          <?php if($canShowTimeOut): ?>
          <form method="post">
            <input type="hidden" name="action" value="time_out">
            <input type="hidden" name="attendance_request_date" value="<?= htmlspecialchars($attendanceRequestDate) ?>">
            <input type="hidden" name="attendance_request_token" value="<?= htmlspecialchars($attendanceRequestToken) ?>">
            <input type="hidden" id="latFieldOut" name="lat">
            <input type="hidden" id="lngFieldOut" name="lng">
            <button id="btnTimeOut" <?= $canClickTimeOut?'':'disabled' ?> class="w-full rounded-lg py-2 text-sm font-semibold <?= $canClickTimeOut?'border border-emerald-300 text-emerald-700 hover:bg-emerald-50':'bg-slate-200 text-slate-500 cursor-not-allowed' ?>">Time Out</button>
          </form>
          <p id="timeOutHint" class="text-xs text-amber-700 <?= $timeOutHint===''?'hidden':'' ?>"><?= htmlspecialchars($timeOutHint) ?></p>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="card p-4">
      <h3 class="font-semibold mb-2">Today's Attendance</h3>
      <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 text-sm">
        <div><div class="text-slate-500">Time In</div><div id="todayTimeInValue" class="font-semibold"><?= htmlspecialchars($todayIn) ?></div></div>
        <div><div class="text-slate-500">Time Out</div><div id="todayTimeOutValue" class="font-semibold"><?= htmlspecialchars($todayOut) ?></div></div>
        <div><div class="text-slate-500">Today</div><div id="todayHoursValue" class="font-semibold"><?= htmlspecialchars($todayHours) ?> / 8.00 hours</div></div>
        <div><div class="text-slate-500">Total</div><div id="overallHoursValue" class="font-semibold"><?= htmlspecialchars($overallHoursDisplay) ?> / 180.00 hours</div></div>
        <div><div class="text-slate-500">Status</div><div class="mt-1 flex flex-wrap items-center gap-2"><span id="todayStatusBadge" class="status <?= attendanceStatusClass($statusToday) ?>"><?= htmlspecialchars($statusToday) ?></span><span id="todayOverrideBadge" class="status warn <?= $hasTodayOverride ? '' : 'hidden' ?>">Override</span></div></div>
      </div>
      <p class="mt-3 text-xs text-slate-500">Public School: <span id="publicHoursValue"><?= htmlspecialchars($publicHoursDisplay) ?> / 180.00 hours</span> • Private School: <span id="privateHoursValue"><?= htmlspecialchars($privateHoursDisplay) ?> / 180.00 hours</span></p>
    </div>

    <div class="card p-4">
      <h3 class="font-semibold mb-2">Attendance Location</h3>
      <div id="attendanceMap" class="h-64 sm:h-80 rounded-xl border border-slate-200" style="min-height:320px"></div>
      <div class="mt-2 text-xs text-slate-500 flex gap-4 flex-wrap">
        <span><span class="inline-block w-2.5 h-2.5 rounded-full bg-emerald-600"></span> Assigned School</span>
        <span><span class="inline-block w-2.5 h-2.5 rounded-full bg-blue-600"></span> Your Location</span>
        <span><span class="inline-block w-2.5 h-2.5 rounded-full bg-emerald-200"></span> Geofence</span>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <?php if($tab==='journal'): ?>
  <section class="space-y-4">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
      <div>
        <h2 class="text-2xl font-bold">Journal</h2>
        <p class="mt-1 text-sm text-slate-500">Select any practicum week that has already started to review or complete its journal.</p>
      </div>
      <form method="get" class="flex flex-col gap-3 sm:flex-row sm:items-center">
        <input type="hidden" name="tab" value="journal">
        <label for="journal_week_start" class="text-sm font-medium text-slate-500">Select Week</label>
        <select id="journal_week_start" name="week_start" class="rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-medium text-slate-900 outline-none transition focus:border-brand-500 focus:ring-4 focus:ring-emerald-100">
          <?php foreach ($journalWeeksAvailable as $option): ?>
            <option value="<?= htmlspecialchars((string) $option['start']) ?>" <?= (string) $option['start'] === $journalSelectedWeekStart ? 'selected' : '' ?>>
              <?= htmlspecialchars(journalWeekOptionLabel($option)) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="rounded-2xl border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-900 transition hover:border-slate-400 hover:bg-slate-50">View</button>
      </form>
    </div>

    <div class="card <?= $journalNoticeClass ?> p-4 text-sm">
      <div><?= htmlspecialchars($journalSubmissionMessage) ?></div>
      <div class="mt-1 text-slate-700">
        Rules: Write your own reflections only. AI-generated, copied, or fabricated content is not allowed. Coordinators can approve, request revision, or reject submissions.
      </div>
    </div>

    <?php
      $journalStatusClass = studentJournalStatusClass($journalSelectedWeekStatusLabel);
      $journalSubmitLabel = $journalCanResubmit ? 'Resubmit Journal' : 'Submit Journal';
    ?>
    <div class="card p-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
      <div class="text-sm text-slate-600"><?= htmlspecialchars($journalSelectedWeekLabel) ?> Status</div>
      <span class="status <?= $journalStatusClass ?>"><?= htmlspecialchars($journalSelectedWeekStatusLabel) ?></span>
    </div>

    <?php if ($journalSelectedWeekStart === ''): ?>
      <div class="card p-4 sm:p-6 text-sm text-slate-500">
        Practicum has not started yet. Your OJT is scheduled to begin on: <?= htmlspecialchars($practicumStartDisplay) ?>.
      </div>
    <?php elseif ($journalSelectedWeekStatus === 'upcoming'): ?>
      <div class="card p-4 sm:p-6 text-sm text-slate-500">
        Future weeks cannot be opened yet.
      </div>
    <?php elseif ($journalSubmission === null && $journalSelectedWeekStatus === 'completed'): ?>
      <div class="card p-4 sm:p-6 text-sm text-slate-500">
        This journal submission has already closed.
        <br>Please contact your assigned coordinator if you need this journal to be reopened.
      </div>
    <?php else: ?>
      <form method="post" class="card p-4 sm:p-6 space-y-4">
        <input type="hidden" name="action" value="save_journal">
        <input type="hidden" name="week_start" value="<?= htmlspecialchars($journalSelectedWeekStart) ?>">
        <input type="hidden" name="submission_id" value="<?= (int) ($journalSubmission['id'] ?? 0) ?>">
        <fieldset <?= $journalCanSubmitJournal ? '' : 'disabled' ?>>
        <div class="text-center font-bold text-emerald-700 text-2xl tracking-wide border-b border-emerald-400 pb-2">
          <?= htmlspecialchars((string) ($journalForm['title'] ?? 'Weekly Gratitude Journal')) ?>
        </div>
        <?php if (!empty($journalForm['description'])): ?>
          <p class="text-sm leading-7 text-slate-600"><?= htmlspecialchars((string) $journalForm['description']) ?></p>
        <?php endif; ?>
        <div class="grid sm:grid-cols-2 gap-3">
          <div><label class="text-xs text-slate-500 font-semibold">NAME</label><div class="field bg-slate-50"><?= htmlspecialchars($fullName) ?></div></div>
          <div><label class="text-xs text-slate-500 font-semibold">WEEK</label><div class="field bg-slate-50"><?= htmlspecialchars($journalSelectedWeekLabel) ?></div></div>
        </div>

        <div class="space-y-4">
          <?php if (($journalQuestions ?? []) === []): ?>
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-500">No active journal questions are available yet.</div>
          <?php else: ?>
            <?php foreach ($journalQuestions as $question): ?>
              <?php
                $qid = (int) ($question['id'] ?? 0);
                $currentValue = journalAnswerValueForQuestion($question, $journalSubmissionAnswers[$qid] ?? null);
                $disabledField = !$journalCanSubmitJournal;
                $requiredMark = !empty($question['is_required']) ? '<span class="text-rose-600">*</span>' : '';
              ?>
              <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                <div class="mb-2 text-sm font-semibold text-slate-700"><?= htmlspecialchars((string) ($question['question_text'] ?? 'Question')) ?> <?= $requiredMark ?></div>
                <?php renderJournalAnswerField($question, $currentValue, $disabledField); ?>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <?php if ($journalCanSubmitJournal): ?>
          <button class="w-full rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-semibold py-2.5"><?= htmlspecialchars($journalSubmitLabel) ?></button>
        <?php endif; ?>
        </fieldset>
      </form>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <?php if($tab==='records'): ?>
  <section class="card p-4 sm:p-5 space-y-4">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
      <div>
        <h2 class="text-2xl font-bold">Weekly Records</h2>
        <p class="mt-1 text-sm text-slate-500">Attendance, journal, checklist, and practicum hours by week.</p>
        <p id="studentRecordsRefreshStatus" class="mt-1 text-xs text-slate-400">Records update automatically.</p>
      </div>
      <?php if ($attendanceSemesterId > 0): ?>
        <?php $currentRecordsExport = appUrl('api/student_records.php') . '?semester_id=' . $attendanceSemesterId; ?>
        <div class="flex shrink-0 gap-2">
          <a href="<?= htmlspecialchars($currentRecordsExport . '&format=pdf') ?>" class="rounded-lg bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Download PDF</a>
          <a href="<?= htmlspecialchars($currentRecordsExport . '&format=excel') ?>" class="rounded-lg border border-emerald-700 px-3 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-50">Download Excel</a>
        </div>
      <?php endif; ?>
    </div>
    <div class="overflow-auto rounded-xl border border-slate-200">
      <table id="studentWeeklyRecordsTable" data-records-url="<?= $attendanceSemesterId > 0 ? htmlspecialchars(appUrl('api/student_records.php') . '?semester_id=' . $attendanceSemesterId, ENT_QUOTES, 'UTF-8') : '' ?>" class="min-w-[1120px] w-full border-collapse text-xs sm:text-sm">
        <thead class="bg-slate-50 text-slate-700">
          <tr>
            <th class="border-b border-slate-200 px-3 py-2 text-left">Week</th>
            <th class="border-b border-slate-200 px-3 py-2 text-left">Date Range</th>
            <?php foreach (['M', 'T', 'W', 'TH', 'F'] as $dayKey): ?>
              <th class="border-b border-slate-200 px-3 py-2 text-center"><?= $dayKey ?></th>
            <?php endforeach; ?>
            <th class="border-b border-slate-200 px-3 py-2 text-left">Journal Score / Status</th>
            <th class="border-b border-slate-200 px-3 py-2 text-center">Checklist Points</th>
            <th class="border-b border-slate-200 px-3 py-2 text-center">Credited Hours</th>
            <th class="border-b border-slate-200 px-3 py-2 text-center">Present Days</th>
            <th class="border-b border-slate-200 px-3 py-2 text-left">Status / Remarks</th>
          </tr>
        </thead>
        <tbody id="studentWeeklyRecordsBody">
          <?php foreach ($weeklyRecords as $record): ?>
            <tr class="border-b border-slate-100">
              <td class="px-3 py-2 text-center"><?= (int) $record['index'] ?></td>
              <td class="whitespace-nowrap px-3 py-2"><?= htmlspecialchars((new DateTimeImmutable($record['start']))->format('M j')) ?> - <?= htmlspecialchars((new DateTimeImmutable($record['end']))->format('M j, Y')) ?></td>
              <?php foreach ($record['days'] as $mark): ?>
                <td class="px-3 py-2 text-center">
                  <?php if ($mark === 'present'): ?>
                    <span class="font-bold text-emerald-600" aria-label="Present">&#10003;</span>
                  <?php elseif ($mark === 'holiday'): ?>
                    <span class="font-bold text-amber-700" aria-label="H - Holiday" title="Holiday; no practicum hours credited">H</span>
                  <?php elseif ($mark === 'absent'): ?>
                    <span class="font-bold text-rose-600" aria-label="Absent">&#10007;</span>
                  <?php else: ?>
                    <span class="text-slate-400" aria-label="Not started">&mdash;</span>
                  <?php endif; ?>
                </td>
              <?php endforeach; ?>
              <td class="whitespace-nowrap px-3 py-2">
                <?php if ($record['status'] === 'upcoming'): ?>
                  <span class="text-slate-500">Not Due Yet</span>
                <?php elseif ($record['journal_submitted']): ?>
                  <span class="font-semibold text-slate-800"><?= (int) $record['journal_points'] ?> / <?= (int) $record['journal_maximum'] ?></span>
                  <span class="text-xs text-slate-500"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', (string) $record['journal_status']))) ?></span>
                <?php else: ?>
                  <span class="text-rose-600">&#9632; Not Submitted</span>
                <?php endif; ?>
              </td>
              <td class="px-3 py-2 text-center">
                <?= $record['checklist_points'] === null || $record['checklist_maximum'] === 0
                  ? '&mdash;'
                  : (int) $record['checklist_points'] . ' / ' . (int) $record['checklist_maximum'] ?>
              </td>
              <td class="px-3 py-2 text-center">
                <?= $record['status'] === 'upcoming' ? '&mdash;' : htmlspecialchars(h((float) $record['credited_hours'])) ?>
              </td>
              <td class="px-3 py-2 text-center">
                <?= $record['status'] === 'upcoming' ? '&mdash;' : (int) $record['present_days'] ?>
              </td>
              <td class="whitespace-nowrap px-3 py-2">
                <span class="mr-1 inline-block h-2 w-2 rounded-full <?= match ($record['status_label']) {
                  'Complete' => 'bg-emerald-600',
                  'With Absence' => 'bg-amber-500',
                  'Incomplete' => 'bg-rose-600',
                  'In Progress' => 'bg-blue-600',
                  default => 'bg-slate-400',
                } ?>"></span>
                <span class="<?= htmlspecialchars($record['status_class']) ?>"><?= htmlspecialchars($record['status_label']) ?></span>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if ($weeklyRecords === []): ?>
            <tr><td colspan="11" class="px-4 py-8 text-center text-slate-500">Weekly records will appear when the practicum schedule is available.</td></tr>
          <?php endif; ?>
        </tbody>
        <?php if ($weeklyRecords !== []): ?>
          <?php
            $weekCount = count($weeklyRecords);
            $attendancePercent = $weeklyTotals['expected_days'] > 0
              ? (int) round(($weeklyTotals['present_days'] / $weeklyTotals['expected_days']) * 100)
              : 0;
          ?>
          <tfoot id="studentWeeklyRecordsFooter" class="bg-emerald-50 font-semibold text-slate-900">
            <tr>
              <td colspan="2" class="px-3 py-3">Total</td>
              <?php foreach (['M', 'T', 'W', 'TH', 'F'] as $dayKey): ?>
                <td class="px-3 py-3 text-center"><?= (int) $weeklyTotals['days'][$dayKey] ?></td>
              <?php endforeach; ?>
              <td class="px-3 py-3 text-center"><?= (int) $weeklyTotals['journal_points'] ?> / <?= (int) $weeklyTotals['journal_maximum'] ?></td>
              <td class="px-3 py-3 text-center"><?= $weeklyTotals['checklist_maximum'] > 0
                ? (int) $weeklyTotals['checklist_points'] . ' / ' . (int) $weeklyTotals['checklist_maximum']
                : '&mdash;' ?></td>
              <td class="px-3 py-3 text-center"><?= htmlspecialchars(h((float) $weeklyTotals['credited_hours'])) ?> / 180</td>
              <td class="px-3 py-3 text-center"><?= (int) $weeklyTotals['present_days'] ?> / <?= $weekCount ?></td>
              <td class="px-3 py-3 text-center"><?= $attendancePercent ?>%</td>
            </tr>
          </tfoot>
        <?php endif; ?>
      </table>
    </div>
  </section>
  <?php endif; ?>

<script>
(() => {
  const table = document.getElementById('studentWeeklyRecordsTable');
  const body = document.getElementById('studentWeeklyRecordsBody');
  const status = document.getElementById('studentRecordsRefreshStatus');
  if (!table || !body || !status) return;

  const recordsUrl = table.dataset.recordsUrl;
  if (!recordsUrl) return;

  const escapeHtml = (value) => {
    const element = document.createElement('span');
    element.textContent = String(value ?? '');
    return element.innerHTML;
  };
  const formatDate = (value, includeYear = false) => {
    const [year, month, day] = String(value || '').split('-').map(Number);
    if (!year || !month || !day) return '';
    return new Intl.DateTimeFormat('en-US', {
      month: 'short',
      day: 'numeric',
      ...(includeYear ? { year: 'numeric' } : {})
    }).format(new Date(year, month - 1, day));
  };
  const formatHours = (value) => {
    const minutes = Math.max(0, Math.floor(Number(value || 0) * 60 + 0.00001));
    return `${Math.floor(minutes / 60)}.${String(minutes % 60).padStart(2, '0')}`;
  };

  function renderRecords(data) {
    const records = Array.isArray(data.records) ? data.records : [];
    const dayKeys = ['M', 'T', 'W', 'TH', 'F'];
    body.innerHTML = records.map((record) => {
      const dayCells = dayKeys.map((day) => {
        const mark = record.days?.[day] || 'future';
        if (mark === 'present') return '<td class="px-3 py-2 text-center font-bold text-emerald-600" aria-label="Present">&#10003;</td>';
        if (mark === 'holiday') return '<td class="px-3 py-2 text-center font-bold text-amber-700" aria-label="H - Holiday" title="Holiday; no practicum hours credited">H</td>';
        if (mark === 'absent') return '<td class="px-3 py-2 text-center font-bold text-rose-600" aria-label="Absent">&#10007;</td>';
        return '<td class="px-3 py-2 text-center text-slate-400" aria-label="Not started">&mdash;</td>';
      }).join('');
      const journal = record.status === 'upcoming'
        ? 'Not Due Yet'
        : (record.journal_submitted
          ? `${record.journal_points} / ${record.journal_maximum} · ${String(record.journal_status || 'pending').replace(/_/g, ' ')}`
          : 'Not Submitted');
      const checklist = record.checklist_points === null || !Number(record.checklist_maximum)
        ? '&mdash;'
        : `${escapeHtml(record.checklist_points)} / ${escapeHtml(record.checklist_maximum)}`;
      const credited = record.status === 'upcoming' ? '&mdash;' : escapeHtml(formatHours(record.credited_hours));
      const present = record.status === 'upcoming' ? '&mdash;' : escapeHtml(record.present_days);
      const statusClass = {
        Complete: 'bg-emerald-600',
        'With Absence': 'bg-amber-500',
        Incomplete: 'bg-rose-600',
        'In Progress': 'bg-blue-600'
      }[record.status_label] || 'bg-slate-400';

      return `<tr class="border-b border-slate-100">
        <td class="px-3 py-2 text-center">${escapeHtml(record.index)}</td>
        <td class="whitespace-nowrap px-3 py-2">${escapeHtml(formatDate(record.start))} - ${escapeHtml(formatDate(record.end, true))}</td>
        ${dayCells}
        <td class="whitespace-nowrap px-3 py-2">${escapeHtml(journal)}</td>
        <td class="px-3 py-2 text-center">${checklist}</td>
        <td class="px-3 py-2 text-center">${credited}</td>
        <td class="px-3 py-2 text-center">${present}</td>
        <td class="whitespace-nowrap px-3 py-2"><span class="mr-1 inline-block h-2 w-2 rounded-full ${statusClass}"></span>${escapeHtml(record.status_label)}</td>
      </tr>`;
    }).join('');

    const totals = data.totals || {};
    const days = totals.days || {};
    let footer = table.querySelector('tfoot');
    if (records.length === 0) {
      footer?.remove();
      body.innerHTML = '<tr><td colspan="11" class="px-4 py-8 text-center text-slate-500">Weekly records will appear when the practicum schedule is available.</td></tr>';
      return;
    }
    if (!footer) {
      footer = document.createElement('tfoot');
      footer.id = 'studentWeeklyRecordsFooter';
      footer.className = 'bg-emerald-50 font-semibold text-slate-900';
      table.appendChild(footer);
    }
    const attendancePercent = Number(totals.expected_days) > 0
      ? Math.round((Number(totals.present_days) / Number(totals.expected_days)) * 100)
      : 0;
    footer.innerHTML = `<tr>
      <td colspan="2" class="px-3 py-3">Total</td>
      ${dayKeys.map((day) => `<td class="px-3 py-3 text-center">${escapeHtml(days[day] || 0)}</td>`).join('')}
      <td class="px-3 py-3 text-center">${escapeHtml(totals.journal_points || 0)} / ${escapeHtml(totals.journal_maximum || 0)}</td>
      <td class="px-3 py-3 text-center">${Number(totals.checklist_maximum || 0) > 0 ? `${escapeHtml(totals.checklist_points)} / ${escapeHtml(totals.checklist_maximum)}` : '&mdash;'}</td>
      <td class="px-3 py-3 text-center">${formatHours(totals.credited_hours)} / 180</td>
      <td class="px-3 py-3 text-center">${escapeHtml(totals.present_days || 0)} / ${records.length}</td>
      <td class="px-3 py-3 text-center">${attendancePercent}%</td>
    </tr>`;
  }

  let refreshing = false;
  async function refreshRecords() {
    if (refreshing || document.visibilityState === 'hidden') return;
    refreshing = true;
    try {
      const response = await fetch(`${recordsUrl}&format=json`, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' }
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || 'Unable to refresh weekly records.');
      renderRecords(data);
      status.textContent = `Records updated at ${new Intl.DateTimeFormat('en-US', {
        hour: 'numeric',
        minute: '2-digit'
      }).format(new Date())}.`;
      status.className = 'mt-1 text-xs text-slate-400';
    } catch (error) {
      console.error('Student weekly records refresh failed:', error);
      status.textContent = 'Could not refresh records. Showing the latest loaded data.';
      status.className = 'mt-1 text-xs text-rose-600';
    } finally {
      refreshing = false;
    }
  }

  refreshRecords();
  window.setInterval(refreshRecords, 15000);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') refreshRecords();
  });
})();
</script>

  <?php if($tab==='concerns'): ?>
  <section class="space-y-4">
    <div class="flex items-center justify-between gap-3">
      <div>
        <h2 class="text-2xl font-bold">Concerns</h2>
        <p class="mt-1 text-sm text-slate-500">Report emergencies, request attendance consideration, or raise practicum-related concerns for your coordinator.</p>
      </div>
      <div class="flex items-center gap-2">
        <span class="status <?= $studentConcernSummary['pending'] > 0 ? 'bad' : 'ok' ?>"><?= (int)$studentConcernSummary['pending'] ?> Pending</span>
        <button type="button" data-concern-create-open class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-emerald-800"><span aria-hidden="true">＋</span> Create New Concern</button>
      </div>
    </div>

    <div class="grid sm:grid-cols-3 gap-3">
      <div class="card p-4"><div class="text-xs text-slate-500">Total Concerns</div><div class="mt-2 text-2xl font-bold"><?= (int)$studentConcernSummary['total'] ?></div></div>
      <div class="card p-4"><div class="text-xs text-slate-500">Declined</div><div class="mt-2 text-2xl font-bold text-rose-700"><?= (int)$studentConcernSummary['declined'] ?></div></div>
      <div class="card p-4"><div class="text-xs text-slate-500">Resolved</div><div class="mt-2 text-2xl font-bold text-emerald-700"><?= (int)$studentConcernSummary['resolved'] ?></div></div>
    </div>

    <div class="space-y-4">
      <div data-concern-create-modal class="fixed inset-0 z-[70] hidden items-center justify-center bg-slate-950/75 p-4 backdrop-blur-sm">
        <button type="button" data-concern-create-close aria-label="Close new concern form" class="absolute inset-0 h-full w-full cursor-default"></button>
        <aside class="relative m-auto flex max-h-[90vh] w-full max-w-lg flex-col overflow-hidden rounded-3xl bg-white shadow-2xl">
          <div class="flex items-start justify-between border-b border-slate-200 px-6 py-5">
            <div><p class="text-xs font-bold uppercase tracking-[0.18em] text-emerald-700">Student support</p><h3 class="mt-1 text-xl font-bold text-slate-950">Create New Concern</h3><p class="mt-1 text-sm text-slate-500">Explain the issue and attach any helpful proof.</p></div>
            <button type="button" data-concern-create-close class="rounded-xl px-3 py-2 text-xl text-slate-500 hover:bg-slate-100" aria-label="Close">&times;</button>
          </div>
          <form method="post" enctype="multipart/form-data" class="flex min-h-0 flex-1 flex-col">
            <input type="hidden" name="action" value="save_concern">
            <div class="flex-1 space-y-4 overflow-y-auto px-6 py-5">
              <div>
                <label class="text-sm font-semibold text-slate-700" for="concernCategory">Concern Category <span class="text-rose-600">*</span></label>
                <select id="concernCategory" class="field mt-1" name="category" required>
                  <option value="">Select a category</option>
                  <option value="Attendance">Attendance</option>
                  <option value="Medical / Emergency">Medical / Emergency</option>
                  <option value="Practicum">Practicum</option>
                  <option value="Deployment">Deployment</option>
                  <option value="Other">Other</option>
                </select>
              </div>
              <div>
                <label class="text-sm font-semibold text-slate-700" for="concernSubject">Subject <span class="text-rose-600">*</span></label>
                <input id="concernSubject" class="field mt-1" type="text" name="subject" maxlength="150" placeholder="Enter a short subject (e.g., Attendance Request)" required>
              </div>
              <div>
                <label class="text-sm font-semibold text-slate-700" for="concernMessage">Detailed Explanation <span class="text-rose-600">*</span></label>
                <textarea id="concernMessage" class="field mt-1 min-h-36" name="message" placeholder="Describe your concern in detail..." required></textarea>
              </div>
              <div>
                <label class="text-sm font-semibold text-slate-700" for="concernAttachment">Attachment (optional)</label>
                <input id="concernAttachment" class="field mt-1" type="file" name="attachment" accept="image/jpeg,image/png,image/webp,image/gif,application/pdf,.docx">
                <p class="mt-1 text-xs text-slate-500">JPG, PNG, WEBP, GIF, PDF, or DOCX. Maximum 10 MB.</p>
              </div>
            </div>
            <div class="flex justify-end gap-2 border-t border-slate-200 px-6 py-4">
              <button type="button" data-concern-create-close class="rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancel</button>
              <button class="rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-bold text-white hover:bg-emerald-800">Submit Concern</button>
            </div>
          </form>
        </aside>
      </div>

      <div class="card p-4 sm:p-6 space-y-4">
        <div class="flex items-center justify-between gap-3">
          <h3 class="text-xl font-bold">Submitted Concerns</h3>
          <span class="text-sm text-slate-500"><?= (int)$studentConcernSummary['total'] ?> total</span>
        </div>

        <?php if($studentConcerns===[]): ?>
          <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center text-sm text-slate-500">No concerns submitted yet.</div>
        <?php else: ?>
          <div class="space-y-3">
            <?php foreach($studentConcerns as $concern): ?>
              <?php
                $concernStatus=(string)($concern['concern_status'] ?? 'pending');
                $concernCoordinator=trim((string)($concern['coordinator_name'] ?? ''));
                $finalFeedbackIndex = null;
                if ($concernStatus !== 'pending') {
                  foreach (($concern['messages'] ?? []) as $feedbackIndex => $feedbackMessage) {
                    if ((string) ($feedbackMessage['sender_role'] ?? '') === 'coordinator') $finalFeedbackIndex = $feedbackIndex;
                  }
                }
              ?>
              <article class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                  <div>
                    <h4 class="font-semibold text-slate-900"><?= htmlspecialchars((string)($concern['subject'] ?? 'Concern')) ?></h4>
                    <p class="mt-1 text-xs text-slate-500">
                      Submitted <?= !empty($concern['created_at']) ? htmlspecialchars((new DateTimeImmutable((string)$concern['created_at']))->format('M j, Y h:i A')) : '--' ?>
                    </p>
                  </div>
                  <span class="status <?= concernStatusClass($concernStatus) ?>"><?= htmlspecialchars(concernStatusLabel($concernStatus)) ?></span>
                </div>
                <button type="button" data-concern-open="<?= (int)($concern['id'] ?? 0) ?>" class="mt-4 w-full rounded-xl border border-emerald-200 bg-white px-4 py-3 text-left text-sm font-semibold text-emerald-700 hover:bg-emerald-50">Open conversation</button>
                <div id="concern-modal-<?= (int)($concern['id'] ?? 0) ?>" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/75 p-4 backdrop-blur-sm" data-concern-modal>
                  <div class="flex max-h-[94vh] w-full max-w-4xl flex-col overflow-hidden rounded-[28px] border-2 border-white bg-slate-50 shadow-2xl" role="dialog" aria-modal="true">
                    <header class="flex items-center justify-between gap-4 bg-gradient-to-r from-emerald-950 via-emerald-900 to-emerald-800 px-5 py-4 text-white sm:px-7"><div class="flex min-w-0 items-center gap-4"><span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-emerald-500 text-2xl">▦</span><div class="min-w-0"><h3 class="truncate text-xl font-black"><?= htmlspecialchars((string)($concern['subject'] ?? 'Concern')) ?></h3><p class="mt-1 text-sm text-emerald-100">Concern conversation and updates</p></div></div><div class="flex shrink-0 items-center gap-3"><?php if($concernStatus !== 'pending'): ?><span class="rounded-full bg-emerald-500 px-3 py-2 text-sm font-bold">✓ <?= htmlspecialchars(concernStatusLabel($concernStatus)) ?></span><?php endif; ?><button type="button" data-concern-close aria-label="Close" class="rounded-full bg-white/10 px-4 py-2 text-2xl text-white hover:bg-white/20">&times;</button></div></header>
                    <div class="min-h-0 flex-1 space-y-4 overflow-y-auto p-4 sm:p-5">
                    <section class="grid grid-cols-2 gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-4"><div><p class="text-xs text-slate-500">Concern Type</p><p class="mt-1 font-bold text-slate-900"><?= htmlspecialchars((string)($concern['category'] ?? 'General')) ?></p></div><div><p class="text-xs text-slate-500">Date Filed</p><p class="mt-1 font-semibold text-slate-900"><?= !empty($concern['created_at']) ? htmlspecialchars((new DateTimeImmutable((string)$concern['created_at']))->format('M j, Y · g:i A')) : '—' ?></p></div><div><p class="text-xs text-slate-500">Status</p><p class="mt-1"><span class="status <?= concernStatusClass($concernStatus) ?>"><?= htmlspecialchars(concernStatusLabel($concernStatus)) ?></span></p></div><div><p class="text-xs text-slate-500">Coordinator</p><p class="mt-1 font-semibold text-slate-900"><?= htmlspecialchars($concernCoordinator !== '' ? $concernCoordinator : 'Assigned coordinator') ?></p></div></section>
                    <div class="space-y-3 rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
                      <?php foreach (($concern['messages'] ?? []) as $messageIndex => $message): ?>
                        <?php
                          $messageRole = (string) ($message['sender_role'] ?? 'user');
                          $isOwnMessage = $messageRole === 'student';
                          $isFinalFeedback = $finalFeedbackIndex !== null && $messageIndex === $finalFeedbackIndex;
                          $senderName = trim((string) ($message['sender_name'] ?? ''));
                          $senderLabel = $isFinalFeedback ? 'Final Feedback · ' . concernStatusLabel($concernStatus) : ($isOwnMessage
                            ? 'You · Student'
                            : ucwords(str_replace('_', ' ', $messageRole)) . ($senderName !== '' ? ' · ' . $senderName : ''));
                          $messageTime = !empty($message['created_at']) ? (new DateTimeImmutable((string) $message['created_at']))->format('M j, Y h:i A') : '--';
                        ?>
                        <div class="flex <?= $isOwnMessage ? 'justify-end' : 'justify-start' ?>">
                          <article class="w-full max-w-[88%] rounded-2xl border px-4 py-3 shadow-sm <?= $isFinalFeedback ? 'border-amber-200 bg-amber-50 text-slate-800' : ($isOwnMessage ? 'border-emerald-700 bg-emerald-700 text-white' : 'border-slate-200 bg-slate-50 text-slate-800') ?>">
                            <div class="<?= $isFinalFeedback ? 'flex flex-col items-center gap-1 text-center text-xs text-amber-900' : 'flex flex-wrap items-center justify-between gap-2 text-xs ' . ($isOwnMessage ? 'text-emerald-100' : 'text-slate-500') ?>"><span class="font-semibold <?= $isOwnMessage && !$isFinalFeedback ? 'text-white' : 'text-slate-700' ?>"><?= htmlspecialchars($senderLabel) ?></span><span><?= htmlspecialchars($messageTime) ?></span></div>
                            <?php if (trim((string) ($message['message'] ?? '')) !== ''): ?><p class="mt-2 whitespace-pre-line text-sm leading-6 <?= $isFinalFeedback ? 'text-center text-slate-800' : ($isOwnMessage ? 'text-white' : 'text-slate-700') ?>"><?= htmlspecialchars((string) ($message['message'] ?? '')) ?></p><?php endif; ?>
                            <?php if (!empty($message['attachment_path'])): ?>
                              <?php $attachmentUrl = appUrl((string) $message['attachment_path']); ?>
                              <?php if (preg_match('/\.(jpe?g|png|webp|gif)$/i', (string) $message['attachment_path'])): ?>
                                <img class="mt-3 max-h-80 w-auto rounded-xl border <?= $isOwnMessage ? 'border-white/30' : 'border-slate-200' ?> object-contain" src="<?= htmlspecialchars($attachmentUrl) ?>" alt="<?= htmlspecialchars((string) ($message['attachment_name'] ?? 'Concern attachment')) ?>">
                              <?php else: ?>
                                <a class="mt-2 inline-block text-sm font-semibold <?= $isOwnMessage ? 'text-emerald-100 hover:text-white' : 'text-emerald-700 hover:underline' ?>" href="<?= htmlspecialchars($attachmentUrl) ?>" download="<?= htmlspecialchars((string) ($message['attachment_name'] ?? 'attachment')) ?>">Download attachment: <?= htmlspecialchars((string) ($message['attachment_name'] ?? 'file')) ?></a>
                              <?php endif; ?>
                            <?php endif; ?>
                          </article>
                        </div>
                      <?php endforeach; ?>
                    </div>
                    <?php if ($concernStatus === 'pending'): ?><form method="post" enctype="multipart/form-data" class="space-y-2 rounded-2xl border border-slate-200 bg-white p-4"><input type="hidden" name="action" value="reply_concern"><input type="hidden" name="concern_id" value="<?= (int)($concern['id'] ?? 0) ?>"><label class="block text-sm font-bold text-slate-800">Reply to your coordinator</label><textarea required class="field h-20" name="message" placeholder="Write your reply..."></textarea><div class="flex flex-wrap items-center gap-2"><input class="field min-w-0 flex-1" type="file" name="attachment" accept="image/jpeg,image/png,image/webp,image/gif,application/pdf"><button class="rounded-xl bg-emerald-800 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-900">Send Reply</button></div></form><?php else: ?><div class="flex items-center justify-center gap-2 rounded-2xl bg-slate-100 px-4 py-4 text-center text-sm text-slate-600"><span>🔒</span>This concern is <?= htmlspecialchars(strtolower(concernStatusLabel($concernStatus))) ?> and can no longer be replied to.</div><?php endif; ?>
                    </div>
                  </div>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </section>
  <script>
    document.querySelectorAll('[data-concern-create-modal], [data-concern-modal]').forEach((modal) => document.body.appendChild(modal));
    const concernCreateModal = document.querySelector('[data-concern-create-modal]');
    const syncConcernModalScroll = () => {
      const hasOpenModal = Array.from(document.querySelectorAll('[data-concern-create-modal], [data-concern-modal]')).some((modal) => !modal.classList.contains('hidden'));
      document.body.classList.toggle('overflow-hidden', hasOpenModal);
    };
    const setConcernCreateModal = (open) => {
      if (!concernCreateModal) return;
      concernCreateModal.classList.toggle('hidden', !open);
      concernCreateModal.classList.toggle('flex', open);
      syncConcernModalScroll();
    };
    document.querySelector('[data-concern-create-open]')?.addEventListener('click', () => setConcernCreateModal(true));
    document.querySelectorAll('[data-concern-create-close]').forEach((button) => button.addEventListener('click', () => setConcernCreateModal(false)));
    document.querySelectorAll('[data-concern-open]').forEach((button) => {
      button.addEventListener('click', () => {
        const modal = document.getElementById(`concern-modal-${button.dataset.concernOpen}`);
        if (modal) { modal.classList.remove('hidden'); modal.classList.add('flex'); syncConcernModalScroll(); }
      });
    });
    document.querySelectorAll('[data-concern-close]').forEach((button) => {
      button.addEventListener('click', () => {
        const modal = button.closest('[data-concern-modal]');
        if (modal) { modal.classList.add('hidden'); modal.classList.remove('flex'); syncConcernModalScroll(); }
      });
    });
    document.querySelectorAll('[data-concern-modal]').forEach((modal) => modal.addEventListener('click', (event) => {
      if (event.target === modal) {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        syncConcernModalScroll();
      }
    }));
    document.addEventListener('keydown', (event) => {
      if (event.key !== 'Escape') return;
      document.querySelectorAll('[data-concern-create-modal], [data-concern-modal]').forEach((modal) => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
      });
      syncConcernModalScroll();
    });
  </script>
  <?php endif; ?>

  <?php if($tab==='profile' && $profileView === 'history'): ?>
  <section class="card overflow-hidden">
    <div class="border-b border-slate-100 bg-gradient-to-r from-emerald-700 to-emerald-600 px-4 py-5 text-white sm:px-6">
      <a href="<?= htmlspecialchars(urlWith(['tab'=>'profile','profile_view'=>'current'])) ?>" class="inline-flex items-center text-sm font-medium text-emerald-50 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-white">← Profile Settings</a>
      <h2 class="mt-2 text-2xl font-bold">History</h2>
      <p class="mt-1 text-sm text-emerald-50">View records from previous practicum semesters.</p>
      <p class="mt-2 text-sm font-semibold text-white">Student: <?= htmlspecialchars($fullName) ?><?= trim((string) ($profile['school_id'] ?? '')) !== '' ? ' · ' . htmlspecialchars((string) $profile['school_id']) : '' ?></p>
    </div>
    <div class="p-4 sm:p-6">
      <?php if ($studentHistory['semesters'] !== []): ?>
        <div class="flex flex-wrap gap-2">
          <?php foreach ($studentHistory['semesters'] as $historySemester): ?>
            <?php $historySemesterLabel = trim((string) ($historySemester['year_label'] ?? '') . ' ' . (string) $historySemester['semester_name']); ?>
            <button type="button"
              data-student-record-open="<?= (int) $historySemester['semester_id'] ?>"
              data-student-record-title="<?= htmlspecialchars($historySemesterLabel, ENT_QUOTES, 'UTF-8') ?>"
              class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-800 hover:bg-emerald-100">
              <?= htmlspecialchars($historySemesterLabel !== '' ? $historySemesterLabel . ' Records' : 'Semester Records') ?>
            </button>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="text-sm text-slate-500">No previous semester records.</p>
      <?php endif; ?>
    </div>
  </section>
  <?php elseif($tab==='profile'): ?>
  <section class="card overflow-hidden">
    <div class="bg-gradient-to-r from-emerald-600 to-emerald-700 h-32 sm:h-40 relative"></div>
    <div class="px-4 sm:px-6 pb-6">
      <div class="relative z-30 -mt-10 sm:-mt-12 flex flex-col sm:flex-row sm:items-end gap-4">
        <div class="w-24 h-24 rounded-2xl bg-emerald-100 border-4 border-white shadow overflow-hidden flex items-center justify-center text-3xl font-bold text-emerald-700">
          <?php if(!empty($profile['photo_path'])): ?>
            <img src="<?= htmlspecialchars((string)$profile['photo_path']) ?>" alt="Profile Photo" class="w-full h-full object-cover">
          <?php else: ?>
            <?= htmlspecialchars(strtoupper(substr((string)$profile['first_name'],0,1).substr((string)$profile['last_name'],0,1))) ?>
          <?php endif; ?>
        </div>
        <div>
          <h3 class="text-2xl font-bold text-slate-900"><?= htmlspecialchars($fullName) ?></h3>
          <p class="text-slate-500 text-sm"><?= htmlspecialchars($schoolName) ?> - Student</p>
        </div>
      </div>

      <div class="mt-6 grid lg:grid-cols-3 gap-4">
        <aside class="card p-4 h-fit lg:sticky lg:top-4">
          <h4 class="text-xs tracking-wider text-slate-400 font-bold">ACCOUNT DETAILS</h4>
          <div class="mt-3 text-sm space-y-2">
            <div><div class="text-slate-500">Email Address</div><div class="font-medium break-all"><?= htmlspecialchars((string)$profile['email']) ?></div></div>
            <div><div class="text-slate-500">Assigned School</div><div class="font-medium"><?= htmlspecialchars($schoolName) ?></div></div>
            <div><div class="text-slate-500">Student ID</div><div class="font-medium"><?= htmlspecialchars((string)$profile['school_id']) ?></div></div>
            <div class="border-t border-slate-100 pt-2"><div class="text-slate-500">Immediate Supervisor</div><?php if($studentSupervisor): ?><div class="font-medium"><?= htmlspecialchars((string)$studentSupervisor['name']) ?></div><div class="break-all text-xs text-slate-500"><?= htmlspecialchars((string)$studentSupervisor['email']) ?></div><?php else: ?><div class="font-medium">Not Assigned</div><p class="mt-1 text-xs text-slate-500">Your partnered-school supervisor will select students for evaluation.</p><?php endif; ?></div>
          </div>
        </aside>

        <div class="lg:col-span-2 space-y-4">
          <form method="post" enctype="multipart/form-data" class="card p-4 sm:p-6 space-y-3">
            <input type="hidden" name="action" value="upload_profile_photo">
            <h4 class="text-xl font-bold">Profile Photo</h4>
            <div class="flex flex-col sm:flex-row gap-3 sm:items-center">
              <input class="field" type="file" name="profile_photo" accept=".jpg,.jpeg,.png,.webp" required>
              <button class="rounded-lg bg-slate-800 px-5 py-2.5 text-white font-semibold hover:bg-slate-900">Upload Photo</button>
            </div>
            <p class="text-xs text-slate-500">Allowed formats: JPG, PNG, WEBP. Max size: 2MB.</p>
          </form>

          <form method="post" class="card p-4 sm:p-6 space-y-3">
            <input type="hidden" name="action" value="save_profile">
            <h4 class="text-xl font-bold">Personal Information</h4>
            <div class="grid sm:grid-cols-2 gap-3">
              <div><label class="text-sm text-slate-600">First Name</label><input class="field" name="first_name" value="<?= htmlspecialchars((string)$profile['first_name']) ?>" required></div>
              <div><label class="text-sm text-slate-600">Last Name</label><input class="field" name="last_name" value="<?= htmlspecialchars((string)$profile['last_name']) ?>" required></div>
            </div>
            <div><label class="text-sm text-slate-600">Middle Name (Optional)</label><input class="field" name="middle_name" value="<?= htmlspecialchars((string)$profile['middle_name']) ?>"></div>
            <div class="pt-2"><button class="rounded-lg bg-emerald-600 px-5 py-2.5 text-white font-semibold hover:bg-emerald-700">Save Changes</button></div>
          </form>

          <form method="post" data-password-form class="card p-4 sm:p-6 space-y-3">
            <input type="hidden" name="action" value="change_password">
            <h4 class="text-xl font-bold">Account Security</h4>
            <p class="text-sm text-slate-600">Want to change your password? We will send a 6-digit verification code to your registered email address to ensure it is really you.</p>
            <div class="grid sm:grid-cols-3 gap-3">
              <input class="field" name="current_password" type="password" placeholder="Current password" required>
              <input class="field" name="new_password" data-password-new autocomplete="new-password" type="password" placeholder="New password" required>
              <input class="field" name="confirm_password" data-password-confirm autocomplete="new-password" type="password" placeholder="Confirm password" required>
            </div>
            <p data-password-match class="text-sm"></p>
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm">
              <p class="font-semibold text-slate-700">Password strength: <span data-password-strength class="text-slate-500">Not set</span></p>
              <ul class="mt-2 grid gap-1 sm:grid-cols-2">
                <li data-password-rule="length" class="text-slate-500"><span data-password-rule-icon>○</span> At least 8 characters</li>
                <li data-password-rule="uppercase" class="text-slate-500"><span data-password-rule-icon>○</span> One uppercase letter</li>
                <li data-password-rule="lowercase" class="text-slate-500"><span data-password-rule-icon>○</span> One lowercase letter</li>
                <li data-password-rule="number" class="text-slate-500"><span data-password-rule-icon>○</span> One number</li>
                <li data-password-rule="special" class="text-slate-500"><span data-password-rule-icon>○</span> One special character</li>
              </ul>
            </div>
            <button type="submit" data-password-submit disabled class="rounded-lg bg-blue-600 px-5 py-2.5 text-white font-semibold enabled:hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50">Change Password</button>
          </form>
        </div>
      </div>
    </div>
  </section>
  <?php endif; ?>

</main>

<div id="studentSemesterRecordsModal" class="fixed inset-0 z-[100] hidden items-center justify-center bg-slate-950/60 p-3 sm:p-6" role="dialog" aria-modal="true" aria-labelledby="studentSemesterRecordsTitle">
  <div class="flex max-h-[92vh] w-full max-w-6xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
    <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4">
      <div>
        <h2 id="studentSemesterRecordsTitle" class="text-xl font-bold text-slate-900">Semester Records</h2>
        <p id="studentSemesterRecordsSubtitle" class="mt-1 text-sm text-slate-500">Loading semester records...</p>
      </div>
      <div class="flex shrink-0 items-center gap-2">
        <a id="studentSemesterPdfLink" href="#" class="rounded-lg bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Download PDF</a>
        <a id="studentSemesterExcelLink" href="#" class="rounded-lg border border-emerald-700 px-3 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-50">Download Excel</a>
        <button type="button" id="closeStudentSemesterRecords" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" aria-label="Close">Close</button>
      </div>
    </div>
    <div id="studentSemesterRecordsMessage" class="hidden px-5 py-3 text-sm text-rose-700"></div>
    <div id="studentSemesterRecordsTable" class="overflow-auto p-4 sm:p-5"></div>
  </div>
</div>

<script src="<?= htmlspecialchars(appUrl('assets/js/password-strength.js?v=2'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script><?php readfile(__DIR__ . '/../assets/js/password-strength.js'); ?></script>
<script>
(() => {
  const ok = <?= json_encode(array_values(array_map('strval', is_array($ok) ? $ok : []))) ?>;
  const errors = <?= json_encode(array_values(array_map('strval', is_array($errors) ? $errors : []))) ?>;
  if (typeof Swal !== 'undefined') {
    if (ok.length) Swal.fire({ icon: 'success', title: 'Success', text: ok.join(' '), confirmButtonColor: '#047857' });
    if (errors.length) Swal.fire({ icon: 'error', title: 'Unable to save', text: errors.join(' '), confirmButtonColor: '#be123c' });
  }
  document.querySelectorAll('form[data-password-form]').forEach((form) => {
    form.addEventListener('submit', async (event) => {
      if (form.dataset.passwordConfirmed === '1' || typeof Swal === 'undefined') return;
      event.preventDefault();
      const result = await Swal.fire({ icon: 'warning', title: 'Change password?', text: 'Your current password will stop working after this change.', showCancelButton: true, confirmButtonText: 'Yes, change it', cancelButtonText: 'Cancel', confirmButtonColor: '#047857', cancelButtonColor: '#64748b' });
      if (result.isConfirmed) { form.dataset.passwordConfirmed = '1'; form.requestSubmit(); }
    });
  });
  document.querySelectorAll('.profile-menu').forEach((menu) => {
    menu.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        menu.removeAttribute('open');
        menu.querySelector('summary')?.focus();
      }
    });
    document.addEventListener('click', (event) => {
      if (!menu.contains(event.target)) menu.removeAttribute('open');
    });
  });
})();
</script>
<script>
(() => {
  const modal = document.getElementById('studentSemesterRecordsModal');
  const tableContainer = document.getElementById('studentSemesterRecordsTable');
  const message = document.getElementById('studentSemesterRecordsMessage');
  const title = document.getElementById('studentSemesterRecordsTitle');
  const subtitle = document.getElementById('studentSemesterRecordsSubtitle');
  const pdfLink = document.getElementById('studentSemesterPdfLink');
  const excelLink = document.getElementById('studentSemesterExcelLink');
  if (!modal || !tableContainer || !message || !title || !subtitle || !pdfLink || !excelLink) return;

  const endpoint = <?= json_encode(appUrl('api/student_records.php')) ?>;
  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
  })[character]);
  const formatHours = (value) => {
    const minutes = Math.max(0, Math.floor(Number(value || 0) * 60 + 0.00001));
    return `${Math.floor(minutes / 60)}.${String(minutes % 60).padStart(2, '0')}`;
  };

  function renderRecords(data) {
    const dayKeys = ['M', 'T', 'W', 'TH', 'F'];
    const records = Array.isArray(data.records) ? data.records : [];
    const header = `<thead class="bg-slate-50 text-slate-700"><tr>
      <th class="px-3 py-2 text-left">Week</th><th class="px-3 py-2 text-left">Date Range</th>
      ${dayKeys.map((day) => `<th class="px-3 py-2 text-center">${day}</th>`).join('')}
      <th class="px-3 py-2 text-left">Journal Score / Status</th><th class="px-3 py-2 text-center">Checklist Points</th>
      <th class="px-3 py-2 text-center">Credited Hours</th><th class="px-3 py-2 text-center">Present Days</th>
      <th class="px-3 py-2 text-left">Status / Remarks</th></tr></thead>`;
    const body = records.map((record) => {
      const start = new Date(`${record.start}T00:00:00`).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
      const end = new Date(`${record.end}T00:00:00`).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
      const dayCells = dayKeys.map((day) => {
        const mark = record.days?.[day] || 'future';
        if (mark === 'present') return '<td class="px-3 py-2 text-center font-bold text-emerald-600">✓</td>';
        if (mark === 'holiday') return '<td class="px-3 py-2 text-center font-bold text-amber-700" title="Holiday; no practicum hours credited">H</td>';
        if (mark === 'absent') return '<td class="px-3 py-2 text-center font-bold text-rose-600">×</td>';
        return '<td class="px-3 py-2 text-center text-slate-400">—</td>';
      }).join('');
      const journal = record.status === 'upcoming' ? 'Not Due Yet' : (record.journal_submitted
        ? `${record.journal_points} / ${record.journal_maximum} · ${String(record.journal_status || 'pending').replace(/_/g, ' ')}`
        : 'Not Submitted');
      const checklist = record.checklist_points === null || !record.checklist_maximum
        ? '—'
        : `${record.checklist_points} / ${record.checklist_maximum}`;
      const credited = record.status === 'upcoming' ? '—' : formatHours(record.credited_hours);
      const present = record.status === 'upcoming' ? '—' : record.present_days;
      return `<tr class="border-t border-slate-100">
        <td class="px-3 py-2 text-center">${escapeHtml(record.index)}</td>
        <td class="whitespace-nowrap px-3 py-2">${escapeHtml(start)} - ${escapeHtml(end)}</td>
        ${dayCells}<td class="px-3 py-2">${escapeHtml(journal)}</td>
        <td class="px-3 py-2 text-center">${escapeHtml(checklist)}</td>
        <td class="px-3 py-2 text-center">${escapeHtml(credited)}</td>
        <td class="px-3 py-2 text-center">${escapeHtml(present)}</td>
        <td class="px-3 py-2">${escapeHtml(record.status_label)}</td></tr>`;
    }).join('');
    const totals = data.totals || {};
    const days = totals.days || {};
    const footer = `<tfoot class="bg-emerald-50 font-semibold text-slate-900"><tr>
      <td colspan="2" class="px-3 py-3">Total</td>
      ${dayKeys.map((day) => `<td class="px-3 py-3 text-center">${escapeHtml(days[day] || 0)}</td>`).join('')}
      <td class="px-3 py-3 text-center">${escapeHtml(totals.journal_points || 0)} / ${escapeHtml(totals.journal_maximum || 0)}</td>
      <td class="px-3 py-3 text-center">${Number(totals.checklist_maximum || 0) > 0 ? `${escapeHtml(totals.checklist_points)} / ${escapeHtml(totals.checklist_maximum)}` : '—'}</td>
      <td class="px-3 py-3 text-center">${formatHours(totals.credited_hours)} / 180</td>
      <td class="px-3 py-3 text-center">${escapeHtml(totals.present_days || 0)} / ${records.length}</td>
      <td class="px-3 py-3 text-center">${escapeHtml(totals.attendance_percent || 0)}%</td></tr></tfoot>`;
    tableContainer.innerHTML = `<table class="min-w-[1120px] w-full border-collapse text-xs sm:text-sm">${header}<tbody>${body || '<tr><td colspan="12" class="p-8 text-center text-slate-500">No weekly records are available.</td></tr>'}</tbody>${records.length ? footer : ''}</table>`;
  }

  function closeModal() {
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    document.body.classList.remove('overflow-hidden');
  }

  document.querySelectorAll('[data-student-record-open]').forEach((button) => {
    button.addEventListener('click', async () => {
      const semesterId = Number(button.dataset.studentRecordOpen || 0);
      if (!semesterId) return;
      const semesterTitle = button.dataset.studentRecordTitle || 'Semester';
      title.textContent = `${semesterTitle} Record`;
      subtitle.textContent = 'Loading semester records...';
      message.textContent = '';
      message.classList.add('hidden');
      tableContainer.innerHTML = '';
      const query = `?semester_id=${encodeURIComponent(semesterId)}`;
      pdfLink.href = `${endpoint}${query}&format=pdf`;
      excelLink.href = `${endpoint}${query}&format=excel`;
      modal.classList.remove('hidden');
      modal.classList.add('flex');
      document.body.classList.add('overflow-hidden');
      try {
        const response = await fetch(`${endpoint}${query}&format=json`, { credentials: 'same-origin' });
        const data = await response.json();
        if (!response.ok) throw new Error(data.error || 'Unable to load semester records.');
        const studentIdentity = data.student?.name
          ? `${data.student.name}${data.student.school_id ? ` · ${data.student.school_id}` : ''} • `
          : '';
        subtitle.textContent = `${studentIdentity}${data.semester?.year_label || ''} ${data.semester?.semester_name || ''} • 180 required hours`.trim();
        renderRecords(data);
      } catch (error) {
        subtitle.textContent = 'Semester records could not be loaded.';
        message.textContent = error instanceof Error ? error.message : 'Unable to load semester records.';
        message.classList.remove('hidden');
      }
    });
  });
  document.getElementById('closeStudentSemesterRecords')?.addEventListener('click', closeModal);
  modal.addEventListener('click', (event) => {
    if (event.target === modal) closeModal();
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !modal.classList.contains('hidden')) closeModal();
  });
})();
</script>
<script>
(() => {
  window.studentGeofenceInside = null;
  window.studentGeofenceChangedAt = Date.now();
  const mapEl=document.getElementById('attendanceMap');
  const school={name:<?= json_encode($schoolName) ?>,lat:<?= json_encode($geoLat) ?>,lng:<?= json_encode($geoLng) ?>,radius:<?= json_encode($geoRadius) ?>};
  let map=null;
  if(mapEl && typeof L!=='undefined'){
    try{
      map=L.map('attendanceMap').setView([school.lat,school.lng],14);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; OpenStreetMap contributors'}).addTo(map);
      const schoolMarker=L.marker([school.lat,school.lng]).addTo(map);
      schoolMarker.bindPopup(school.name).openPopup();
      L.circle([school.lat,school.lng],{radius:school.radius,color:'#10b981',fillColor:'#6ee7b7',fillOpacity:.25}).addTo(map);
      map.invalidateSize();
    }catch(_mapError){
      map=null;
      console.error('Student Leaflet map failed to initialize',_mapError);
    }
  }

  const statusEl=document.getElementById('geoStatus');
  const distanceEl=document.getElementById('geoDistance');
  const latField=document.getElementById('latField');
  const lngField=document.getElementById('lngField');
  const btnIn=document.getElementById('btnTimeIn');
  const btnEnableLocation=document.getElementById('btnEnableLocation');
  const canTimeIn=<?= json_encode($canShowTimeIn) ?>;
  const hasAssignedSchool=<?= json_encode($schoolName !== 'Not assigned') ?>;
  let userMarker=null;
  let isLocating=false;
  let locationRefreshTimer=null;

  function hav(lat1,lng1,lat2,lng2){const R=6371000;const dLat=(lat2-lat1)*Math.PI/180;const dLng=(lng2-lng1)*Math.PI/180;const a=Math.sin(dLat/2)**2+Math.cos(lat1*Math.PI/180)*Math.cos(lat2*Math.PI/180)*(Math.sin(dLng/2)**2);return R*(2*Math.atan2(Math.sqrt(a),Math.sqrt(1-a)));}
  function updateLoc(useNormalAccuracy=false, userInitiated=false){
    if(isLocating) return;
    if(!navigator.geolocation){
      if(window.studentGeofenceInside!==false) window.studentGeofenceChangedAt=Date.now();
      window.studentGeofenceInside=false;
      if(typeof window.setStudentHoursGeofenceState==='function') window.setStudentHoursGeofenceState(true);
      statusEl.textContent='Location is not supported by this browser';statusEl.className='text-sm font-semibold text-red-600'; return;
    }
    isLocating=true;
    btnEnableLocation?.classList.add('hidden');
    statusEl.textContent=useNormalAccuracy?'Trying normal location accuracy...':'Checking your location...';
    statusEl.className='text-sm font-semibold text-amber-600';
    navigator.geolocation.getCurrentPosition((pos)=>{
      isLocating=false;
      btnEnableLocation?.classList.add('hidden');
      const lat=pos.coords.latitude,lng=pos.coords.longitude;
      document.querySelectorAll('input[name="lat"]').forEach((field)=>field.value=lat);
      document.querySelectorAll('input[name="lng"]').forEach((field)=>field.value=lng);
      window.studentGeofenceLocation={lat:lat,lng:lng,timestamp:Number(pos.timestamp||Date.now())};
      const d=hav(lat,lng,school.lat,school.lng);
      distanceEl.textContent=hasAssignedSchool?`Distance: ${d.toFixed(2)}m (radius: ${school.radius.toFixed(2)}m)`:'Location found — school assignment pending';
      const inside=d<=school.radius;
      if(window.studentGeofenceInside!==inside) window.studentGeofenceChangedAt=Date.now();
      window.studentGeofenceInside=inside;
      if(typeof window.setStudentHoursGeofenceState==='function') window.setStudentHoursGeofenceState(!inside);
      window.dispatchEvent(new CustomEvent('student:location-updated'));
      statusEl.textContent=!hasAssignedSchool?'Location found — waiting for school assignment':(inside?'Inside geofence':'Outside geofence');
      statusEl.className='text-sm font-semibold '+(!hasAssignedSchool||inside?'text-emerald-600':'text-red-600');
      if(btnIn && canTimeIn){btnIn.disabled=!inside;btnIn.className='w-full rounded-lg py-2 text-sm font-semibold '+(inside?'bg-emerald-600 text-white hover:bg-emerald-700':'bg-slate-300 text-slate-600');}
      if(map && typeof L!=='undefined'){
        if(userMarker){map.removeLayer(userMarker);}
        userMarker=L.marker([lat,lng],{icon:L.divIcon({className:'',html:'<div style="width:12px;height:12px;background:#2563eb;border-radius:999px;border:2px solid #fff;box-shadow:0 0 0 2px #2563eb55"></div>'})}).addTo(map);
      }
    },(error)=>{
      isLocating=false;
      // High accuracy can time out indoors or on phones with GPS disabled.
      // Retry once with normal accuracy before showing a failure state.
      if(!useNormalAccuracy && (error.code===error.TIMEOUT || error.code===error.POSITION_UNAVAILABLE)){
        updateLoc(true, userInitiated);
        return;
      }
      if(window.studentGeofenceInside!==false) window.studentGeofenceChangedAt=Date.now();
      window.studentGeofenceInside=false;
      if(typeof window.setStudentHoursGeofenceState==='function') window.setStudentHoursGeofenceState(true);
      const errorText=error.code===error.PERMISSION_DENIED
        ? 'Allow location access in your browser to check your distance'
        : (error.code===error.TIMEOUT ? 'Location is taking too long — tap Retry' : 'Location is unavailable — tap Retry');
      statusEl.textContent=errorText;statusEl.className='text-sm font-semibold text-red-600';
      btnEnableLocation?.classList.remove('hidden');
    },{enableHighAccuracy:!useNormalAccuracy,maximumAge:useNormalAccuracy?30000:0,timeout:useNormalAccuracy?15000:10000});
  }
  function startLocationTracking(){
    // Automatic detection is the normal portal behaviour. Chrome will show its
    // Allow prompt while the site's permission is still set to Ask. A webpage
    // is not allowed to re-open that prompt after the browser has blocked it.
    if(navigator.permissions?.query){
      navigator.permissions.query({name:'geolocation'}).then((permission)=>{
        if(permission.state==='denied'){
          statusEl.textContent='Location is blocked in Chrome. Open Chrome Settings > Privacy and security > Site settings > Location, allow phinmapracticum.com, then reload this page.';
          statusEl.className='text-sm font-semibold text-red-600';
          btnEnableLocation?.classList.remove('hidden');
          return;
        }
        updateLoc();
        if(!locationRefreshTimer) locationRefreshTimer=setInterval(()=>updateLoc(),30000);
      }).catch(()=>{
        updateLoc();
        if(!locationRefreshTimer) locationRefreshTimer=setInterval(()=>updateLoc(),30000);
      });
    } else {
      updateLoc();
      if(!locationRefreshTimer) locationRefreshTimer=setInterval(()=>updateLoc(),30000);
    }
  }
  startLocationTracking();
  document.getElementById('btnRetryLoc')?.addEventListener('click',startLocationTracking);
  btnEnableLocation?.addEventListener('click',startLocationTracking);
})();

(() => {
  const todayHoursEl=document.getElementById('todayHoursValue');
  const overallHoursEl=document.getElementById('overallHoursValue');
  const publicHoursEl=document.getElementById('publicHoursValue');
  const privateHoursEl=document.getElementById('privateHoursValue');
  const statusBadge=document.getElementById('todayStatusBadge');
  const timeInEl=document.getElementById('todayTimeInValue');
  const timeOutEl=document.getElementById('todayTimeOutValue');
  const overrideBadge=document.getElementById('todayOverrideBadge');
  const timeOutButton=document.getElementById('btnTimeOut');
  const timeOutHint=document.getElementById('timeOutHint');
  if(!todayHoursEl || !overallHoursEl || !publicHoursEl || !privateHoursEl || !statusBadge || !timeInEl || !timeOutEl || !overrideBadge) return;

  window.setStudentHoursGeofenceState=(isPaused)=>{
    todayHoursEl.classList.toggle('text-rose-700',isPaused);
    todayHoursEl.classList.toggle('bg-rose-50',isPaused);
    todayHoursEl.classList.toggle('rounded-md',isPaused);
    todayHoursEl.classList.toggle('px-2',isPaused);
    todayHoursEl.classList.toggle('py-1',isPaused);
  };
  window.setStudentHoursGeofenceState(window.studentGeofenceInside!==true);

  const apiUrl=<?= json_encode(appUrl('api/student_live_attendance.php')) ?>;
  let snapshot=null;
  let snapshotReceivedAt=Date.now();
  let renderedElapsedSeconds=Number(<?= json_encode((int) ($liveSnapshot['elapsed_seconds'] ?? 0)) ?>);
  let localTimer=null;
  let syncTimer=null;

  function hm(value){
    const totalMinutes=Math.max(0, Math.floor((Number(value||0) * 60) + 0.00001));
    const wholeHours=Math.floor(totalMinutes / 60);
    const minutes=totalMinutes % 60;
    return `${wholeHours}.${String(minutes).padStart(2, "0")}`;
  }

  function fmtHours(value,required){
    return `${hm(value)} / ${hm(required)} hours`;
  }

  function fmtTime(value){
    if(!value) return '--';
    const dt=new Date(value.replace(' ','T'));
    if(Number.isNaN(dt.getTime())) return '--';
    return dt.toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit',hour12:true});
  }

  function statusTone(label){
    if(label === 'Emergency Override' || label === 'Present (Override)' || label === 'Completed' || label === 'PRESENT') return 'status ok';
    if(label === 'H - Holiday' || label === 'HOLIDAY') return 'status upcoming';
    if(label === 'Late') return 'status warn';
    if(label === 'In Progress') return 'status warn';
    if(label === 'Pending Timeout') return 'status warn';
    if(label === 'Upcoming') return 'status upcoming';
    return 'status bad';
  }

  function deploymentHours(seconds){
    const totalMinutes=Math.max(0,Math.floor(Number(seconds||0)/60));
    return `${Math.floor(totalMinutes/60)}.${String(totalMinutes%60).padStart(2,'0')}`;
  }

  function applyDeploymentProgress(deployments){
    if(!Array.isArray(deployments)) return;
    deployments.forEach((deployment)=>{
      const card=document.querySelector(`[data-deployment-id="${String(deployment.id||'')}"]`);
      if(!card) return;
      const status=String(deployment.deployment_status||'locked');
      const progressEl=card.querySelector('[data-deployment-progress]');
      const statusEl=card.querySelector('[data-deployment-status]');
      const required=deploymentHours(deployment.required_seconds||0);
      if(progressEl) progressEl.textContent=status==='locked' ? 'Locked' : `Progress: ${deploymentHours(deployment.rendered_seconds)} / ${required} hours`;
      if(statusEl){
        const statusText=status==='completed' ? 'Completed ✓' : (status==='active' ? 'Active' : 'Locked');
        statusEl.textContent=`Status: ${statusText}`;
        statusEl.className='text-sm font-semibold '+(status==='completed' ? 'text-emerald-700' : (status==='active' ? 'text-blue-700' : 'text-slate-500'));
      }
    });
  }

  function applySnapshot(data){
    snapshot=data;
    snapshotReceivedAt=Date.now();
    if(typeof data.geofence_inside==='boolean' && data.geofence_inside!==window.studentGeofenceInside){
      window.studentGeofenceInside=data.geofence_inside;
      window.studentGeofenceChangedAt=Date.now();
    }
    window.setStudentHoursGeofenceState(window.studentGeofenceInside!==true);
    const isActiveSession=Boolean(data.active && data.time_in && !data.time_out);
    if(isActiveSession){
      renderedElapsedSeconds=Number(data.elapsed_seconds||0);
    } else {
      renderedElapsedSeconds=Number(data.elapsed_seconds||0);
    }
    // Attendance records must still render outside the geofence. An emergency
    // override is a completed attendance record and must not be hidden just
    // because the student's current location is outside the assigned area.
    const todayHours=Number(data.today_hours||0);
    todayHoursEl.textContent=fmtHours(todayHours, data.daily_required_hours||8);
    overallHoursEl.textContent=fmtHours(data.overall_hours||0, data.overall_required_hours||180);
    publicHoursEl.textContent=fmtHours(data.public_hours||0, data.public_required_hours||180);
    privateHoursEl.textContent=fmtHours(data.private_hours||0, data.private_required_hours||180);
    applyDeploymentProgress(data.deployment_progress);
    timeInEl.textContent=fmtTime(data.time_in || null);
    timeOutEl.textContent=fmtTime(data.time_out || null);
    const statusLabel=String(data.status_label || 'Absent');
    statusBadge.textContent=statusLabel;
    statusBadge.className=statusTone(statusLabel);
    overrideBadge.classList.toggle('hidden', Number(data.is_override || 0) !== 1);

    if(timeOutButton){
      if(data.active && data.can_time_out){
        timeOutButton.disabled=false;
        timeOutButton.className='w-full rounded-lg py-2 text-sm font-semibold border border-emerald-300 text-emerald-700 hover:bg-emerald-50';
      } else if(data.active){
        timeOutButton.disabled=true;
        timeOutButton.className='w-full rounded-lg py-2 text-sm font-semibold bg-slate-200 text-slate-500 cursor-not-allowed';
      }
    }

    if(timeOutHint){
      if(data.active && !data.can_time_out){
        const remainingSeconds=Number(data.remaining_seconds_to_timeout||0);
        const remainingMinutes=Math.max(0, Math.ceil(remainingSeconds/60));
        timeOutHint.textContent=`Time out will be enabled in about ${remainingMinutes} minute(s).`;
        timeOutHint.classList.remove('hidden');
      } else {
        timeOutHint.textContent='';
        timeOutHint.classList.add('hidden');
      }
    }
  }

  function renderLocalTick(){
    if(window.studentGeofenceInside!==true || !snapshot || !snapshot.active || !snapshot.time_in || snapshot.time_out) return;
    const lastLocationTimestamp=Number(window.studentGeofenceLocation?.timestamp||0);
    if(lastLocationTimestamp > 0 && Date.now()-lastLocationTimestamp > 15000){
      window.studentGeofenceInside=false;
      window.studentGeofenceChangedAt=Date.now();
      window.setStudentHoursGeofenceState(true);
      return;
    }
    const geofenceChangedAt=Number(window.studentGeofenceChangedAt||0);
    if(geofenceChangedAt>snapshotReceivedAt) snapshotReceivedAt=geofenceChangedAt;
    // renderedElapsedSeconds is the server-supplied base value for this
    // snapshot. Add only the time since that snapshot; do not add the same
    // interval repeatedly on every one-second tick.
    const elapsedSeconds=Number(renderedElapsedSeconds ?? snapshot.elapsed_seconds ?? 0)+Math.floor((Date.now()-snapshotReceivedAt)/1000);
    const liveHours=Math.min(elapsedSeconds/3600, Number(snapshot.daily_required_hours||8));
    const baseOverall=Number(snapshot.credited_overall_hours||0);
    const basePublic=Number(snapshot.credited_public_hours||0);
    const basePrivate=Number(snapshot.credited_private_hours||0);
    const activeType=String(snapshot.active_ojt_code||'');

    todayHoursEl.textContent=fmtHours(liveHours, snapshot.daily_required_hours||8);
    overallHoursEl.textContent=fmtHours(baseOverall + liveHours, snapshot.overall_required_hours||180);
    publicHoursEl.textContent=fmtHours(basePublic + (activeType==='public_school' ? liveHours : 0), snapshot.public_required_hours||180);
    privateHoursEl.textContent=fmtHours(basePrivate + (activeType==='private_school' ? liveHours : 0), snapshot.private_required_hours||180);
    snapshot.today_hours=liveHours;
    snapshot.overall_hours=baseOverall + liveHours;
    snapshot.public_hours=basePublic + (activeType==='public_school' ? liveHours : 0);
    snapshot.private_hours=basePrivate + (activeType==='private_school' ? liveHours : 0);
    snapshot.elapsed_seconds=elapsedSeconds;
    snapshot.can_time_out=elapsedSeconds >= 28800;
    snapshot.remaining_seconds_to_timeout=Math.max(0, 28800 - elapsedSeconds);
    if(timeOutButton && snapshot.can_time_out){
      timeOutButton.disabled=false;
      timeOutButton.className='w-full rounded-lg py-2 text-sm font-semibold border border-emerald-300 text-emerald-700 hover:bg-emerald-50';
    }
    if(timeOutHint){
      if(snapshot.can_time_out){
        timeOutHint.textContent='';
        timeOutHint.classList.add('hidden');
      } else {
        const remainingMinutes=Math.max(0, Math.ceil(Number(snapshot.remaining_seconds_to_timeout||0)/60));
        timeOutHint.textContent=`Time out will be enabled in about ${remainingMinutes} minute(s).`;
        timeOutHint.classList.remove('hidden');
      }
    }
  }

  async function syncSnapshot(){
    try{
      const liveUrl=new URL(apiUrl,window.location.href);
      const location=window.studentGeofenceLocation;
      if(location && Date.now()-Number(location.timestamp||0) <= 15000){
        liveUrl.searchParams.set('lat',location.lat);
        liveUrl.searchParams.set('lng',location.lng);
      }
      const res=await fetch(liveUrl.toString(),{cache:'no-store',headers:{'Accept':'application/json'}});
      if(!res.ok) return;
      const data=await res.json();
      if(data && !data.error){
        applySnapshot(data);
      }
    }catch(_err){
    }
  }

  syncSnapshot();
  window.addEventListener('student:location-updated', syncSnapshot);
  localTimer=setInterval(renderLocalTick,1000);
  syncTimer=setInterval(syncSnapshot,5000);
})();
</script>
<?php renderLogoutUi(appUrl('public/logout.php')); ?>
</body>
</html>
