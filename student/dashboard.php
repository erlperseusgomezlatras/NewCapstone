<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../middleware/role_check.php';
require_once __DIR__ . '/../middleware/logout_ui.php';

date_default_timezone_set('Asia/Manila');
requireRole('student');
$pdo = getPDO();
$user = currentUser();
$studentId = (int)($user['id'] ?? 0);
if ($studentId <= 0) { header('Location: /practicum_system/public/login.php'); exit; }
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

function tableExists(PDO $pdo, string $table): bool { $s=$pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:t LIMIT 1'); $s->execute(['t'=>$table]); return (bool)$s->fetchColumn(); }
function monday(DateTimeImmutable $d): DateTimeImmutable { return $d->modify('-'.((int)$d->format('N')-1).' days')->setTime(0,0,0); }
function h(float $n): string { return number_format($n,2); }
function dist(float $lat1,float $lng1,float $lat2,float $lng2): float { $r=6371000.0; $dLat=deg2rad($lat2-$lat1); $dLng=deg2rad($lng2-$lng1); $a=sin($dLat/2)**2+cos(deg2rad($lat1))*cos(deg2rad($lat2))*(sin($dLng/2)**2); return $r*(2*atan2(sqrt($a),sqrt(max(1-$a,0)))); }

function ctx(PDO $pdo, int $uid): array {
  $q="SELECT ss.section_id,ss.semester_id,s.section_name,s.section_code,sem.semester_name,sem.semester_status,sem.start_date AS semester_start_date,sy.year_label,u.email,u.school_id,up.first_name,up.middle_name,up.last_name FROM section_students ss JOIN sections s ON s.id=ss.section_id JOIN semesters sem ON sem.id=ss.semester_id JOIN school_years sy ON sy.id=sem.school_year_id JOIN users u ON u.id=ss.student_user_id LEFT JOIN user_profiles up ON up.user_id=ss.student_user_id WHERE ss.student_user_id=:id AND ss.enrollment_status='active' ORDER BY sy.start_date DESC, sem.semester_no DESC LIMIT 1";
  $s=$pdo->prepare($q); $s->execute(['id'=>$uid]); return $s->fetch(PDO::FETCH_ASSOC)?:[];
}
function geofence(PDO $pdo, int $uid): array {
  $q="SELECT g.id,g.name,g.latitude,g.longitude,g.radius_meters,g.school_type,ps.address,ps.daily_cap_hours FROM student_geofence_assignments sga JOIN geofence_locations g ON g.id=sga.geofence_id LEFT JOIN partner_schools ps ON ps.geofence_id=g.id WHERE sga.student_user_id=:id AND sga.assignment_status='active' AND g.is_active=1 ORDER BY sga.assigned_at DESC LIMIT 1";
  $s=$pdo->prepare($q); $s->execute(['id'=>$uid]); return $s->fetch(PDO::FETCH_ASSOC)?:[];
}
function todayRec(PDO $pdo, int $uid): array { $s=$pdo->prepare('SELECT * FROM attendance_records WHERE student_user_id=:id AND attendance_date=CURDATE() LIMIT 1'); $s->execute(['id'=>$uid]); return $s->fetch(PDO::FETCH_ASSOC)?:[]; }

if (!tableExists($pdo,'student_journals')) {
  $pdo->exec("CREATE TABLE student_journals (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, student_user_id BIGINT UNSIGNED NOT NULL, semester_id INT UNSIGNED NOT NULL, week_start DATE NOT NULL, grateful_for TEXT NULL, proud_of TEXT NULL, words_to_inspire TEXT NULL, affirmations TEXT NULL, look_forward_to TEXT NULL, feeling ENUM('great','good','neutral','lean_not','not_good') NULL, status ENUM('pending_review','approved','revise') NOT NULL DEFAULT 'pending_review', coordinator_feedback TEXT NULL, submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uq_student_journal_week (student_user_id, semester_id, week_start), FOREIGN KEY (student_user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE, FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE CASCADE ON UPDATE CASCADE) ENGINE=InnoDB");
}

$tab=(string)($_GET['tab']??'attendance'); $rtab=(string)($_GET['records_tab']??'attendance');
if(!in_array($tab,['attendance','journal','records','profile'],true))$tab='attendance';
if(!in_array($rtab,['attendance','checklist','journal'],true))$rtab='attendance';

$errors=$_SESSION['flash_errors'] ?? [];
$ok=$_SESSION['flash_ok'] ?? [];
unset($_SESSION['flash_errors'], $_SESSION['flash_ok']);
$now=new DateTimeImmutable('now'); $todayWeek=(int)$now->format('N');
$timeInCutoff=$now->setTime(16,0,0); // 4:00 PM local time
$isTimeInWindowOpen = $now < $timeInCutoff;

// Auto-mark stale records as absent (no timeout by 11:59 PM of that day => 0 hours).
$autoAbsent=$pdo->prepare("UPDATE attendance_records SET total_hours=0.00, remarks='Absent: no time out by 11:59 PM', updated_at=NOW() WHERE student_user_id=:u AND attendance_date<CURDATE() AND time_in IS NOT NULL AND time_out IS NULL");
$autoAbsent->execute(['u'=>$studentId]);

$ctx=ctx($pdo,$studentId); $geo=geofence($pdo,$studentId); $today=todayRec($pdo,$studentId);
$isSemesterActive = (($ctx['semester_status'] ?? '') === 'active');

if($_SERVER['REQUEST_METHOD']==='POST'){
  $postErrors = [];
  $postOk = [];
  $action=(string)($_POST['action']??'');
  try {
    if($action==='time_in'){
      $tab='attendance'; if($todayWeek>5)throw new RuntimeException('Attendance is Monday to Friday only.');
      if(!$isSemesterActive)throw new RuntimeException('Semester is not active yet. Attendance is disabled until semester start.');
      if(!$isTimeInWindowOpen)throw new RuntimeException('Time in window is closed after 4:00 PM.');
      if($ctx===[]||$geo===[])throw new RuntimeException('Missing active section/geofence assignment.');
      if($today!==[])throw new RuntimeException('You already timed in today.');
      $lat=(float)($_POST['lat']??0); $lng=(float)($_POST['lng']??0); if($lat===0.0||$lng===0.0)throw new RuntimeException('Location is required.');
      $d=dist($lat,$lng,(float)$geo['latitude'],(float)$geo['longitude']); if($d>(float)$geo['radius_meters'])throw new RuntimeException('Outside geofence radius.');
      $ot=$pdo->prepare('SELECT id FROM ojt_types WHERE ojt_code=:c AND is_active=1 LIMIT 1'); $ot->execute(['c'=>(string)$geo['school_type']]); $ojt=(int)($ot->fetchColumn()?:0); if($ojt<=0)throw new RuntimeException('OJT type mapping not found.');
      $pdo->beginTransaction();
      $i=$pdo->prepare("INSERT INTO attendance_records (student_user_id,section_id,semester_id,ojt_type_id,attendance_date,time_in,total_hours,remarks) VALUES (:u,:s,:m,:o,CURDATE(),NOW(),0.00,'Time in via student portal')");
      $i->execute(['u'=>$studentId,'s'=>(int)$ctx['section_id'],'m'=>(int)$ctx['semester_id'],'o'=>$ojt]);
      $aid=(int)$pdo->lastInsertId();
      $pdo->prepare("INSERT INTO attendance_sessions (attendance_id,event_type,event_time,source) VALUES (:a,'time_in',NOW(),'student_portal')")->execute(['a'=>$aid]);
      $g=$pdo->prepare("INSERT INTO attendance_geofence_logs (attendance_id,geofence_id,latitude,longitude,distance_from_center,inside_radius,logged_at) VALUES (:a,:g,:lat,:lng,:d,1,NOW())");
      $g->execute(['a'=>$aid,'g'=>(int)$geo['id'],'lat'=>$lat,'lng'=>$lng,'d'=>round($d,2)]);
      $pdo->commit(); $postOk[]='Time in recorded.';
    }

    if($action==='time_out'){
      $tab='attendance'; if($todayWeek>5)throw new RuntimeException('Attendance is Monday to Friday only.');
      if(!$isSemesterActive)throw new RuntimeException('Semester is not active. Time out is unavailable.');
      if($today===[])throw new RuntimeException('No attendance record for today.');
      if(!empty($today['time_out']))throw new RuntimeException('Time out already recorded.');
      $elapsedStmt = $pdo->prepare("SELECT GREATEST(TIMESTAMPDIFF(SECOND, time_in, NOW()), 0) FROM attendance_records WHERE id=:id LIMIT 1");
      $elapsedStmt->execute(['id'=>(int)$today['id']]);
      $elapsedSeconds = (int)($elapsedStmt->fetchColumn() ?: 0);
      if($elapsedSeconds < 28800){ throw new RuntimeException('Time out is allowed only after completing 8.0 hours from time in.'); }
      $hrs=round($elapsedSeconds/3600,2);
      $pdo->beginTransaction();
      $pdo->prepare("UPDATE attendance_records SET time_out=NOW(), total_hours=:h, remarks='Time out via student portal' WHERE id=:id")->execute(['h'=>$hrs,'id'=>(int)$today['id']]);
      $pdo->prepare("INSERT INTO attendance_sessions (attendance_id,event_type,event_time,source) VALUES (:a,'time_out',NOW(),'student_portal')")->execute(['a'=>(int)$today['id']]);
      $pdo->commit(); $postOk[]='Time out recorded.';
    }

    if($action==='save_journal'){
      $tab='journal'; if($ctx===[])throw new RuntimeException('No active semester found.');
      if(!$isSemesterActive)throw new RuntimeException('Semester is not active. Journal submission is currently closed.');
      $grateful=trim((string)($_POST['grateful_for']??'')); $proud=trim((string)($_POST['proud_of']??'')); $look=trim((string)($_POST['look_forward_to']??'')); $feel=trim((string)($_POST['feeling']??''));
      $wl=$_POST['words_to_inspire_list']??[]; $al=$_POST['affirmations_list']??[]; if(!is_array($wl))$wl=[]; if(!is_array($al))$al=[];
      $wl=array_values(array_filter(array_map(static fn($v)=>trim((string)$v),$wl),static fn($v)=>$v!==''));
      $al=array_values(array_filter(array_map(static fn($v)=>trim((string)$v),$al),static fn($v)=>$v!==''));
      if($grateful===''||$proud===''||$look==='')throw new RuntimeException('Please complete required journal fields.');
      if($feel!==''&&!in_array($feel,['great','good','neutral','lean_not','not_good'],true))throw new RuntimeException('Invalid mood value.');
      $ws=monday($now)->format('Y-m-d');
      $s=$pdo->prepare("INSERT INTO student_journals (student_user_id,semester_id,week_start,grateful_for,proud_of,words_to_inspire,affirmations,look_forward_to,feeling,status,submitted_at) VALUES (:u,:m,:w,:g,:p,:wd,:af,:lf,:f,'pending_review',NOW()) ON DUPLICATE KEY UPDATE grateful_for=VALUES(grateful_for),proud_of=VALUES(proud_of),words_to_inspire=VALUES(words_to_inspire),affirmations=VALUES(affirmations),look_forward_to=VALUES(look_forward_to),feeling=VALUES(feeling),status='pending_review',submitted_at=NOW()");
      $s->execute(['u'=>$studentId,'m'=>(int)$ctx['semester_id'],'w'=>$ws,'g'=>$grateful,'p'=>$proud,'wd'=>implode("\n",$wl),'af'=>implode("\n",$al),'lf'=>$look,'f'=>$feel!==''?$feel:null]);
      $postOk[]='Journal submitted for coordinator review.';
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
      $publicPath='/practicum_system/assets/uploads/profile/'.$name;
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

    if($action==='change_password'){
      $tab='profile'; $cur=(string)($_POST['current_password']??''); $new=(string)($_POST['new_password']??''); $con=(string)($_POST['confirm_password']??'');
      if($cur===''||$new===''||$con==='')throw new RuntimeException('All password fields are required.');
      if($new!==$con)throw new RuntimeException('Passwords do not match.');
      if(strlen($new)<8)throw new RuntimeException('New password must be at least 8 chars.');
      $s=$pdo->prepare('SELECT password_hash FROM users WHERE id=:id LIMIT 1'); $s->execute(['id'=>$studentId]); $hash=(string)($s->fetchColumn()?:'');
      if($hash===''||!password_verify($cur,$hash))throw new RuntimeException('Current password is invalid.');
      $pdo->prepare('UPDATE users SET password_hash=:h WHERE id=:id')->execute(['h'=>password_hash($new,PASSWORD_DEFAULT),'id'=>$studentId]);
      $postOk[]='Password changed.';
    }
  } catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); $postErrors[]=$e->getMessage(); }
  $_SESSION['flash_errors'] = $postErrors;
  $_SESSION['flash_ok'] = $postOk;
  $redirectQuery = $_GET;
  $redirectQuery['tab'] = $tab;
  if ($tab === 'records') {
    $redirectQuery['records_tab'] = $rtab;
  }
  $location = '/practicum_system/student/dashboard.php';
  if (!empty($redirectQuery)) {
    $location .= '?' . http_build_query($redirectQuery);
  }
  header('Location: ' . $location);
  exit;
}

$profileStmt=$pdo->prepare("SELECT u.email,u.school_id,up.first_name,up.middle_name,up.last_name,COALESCE(up.photo_path,'') photo_path FROM users u LEFT JOIN user_profiles up ON up.user_id=u.id WHERE u.id=:id LIMIT 1");
$profileStmt->execute(['id'=>$studentId]);
$profile=$profileStmt->fetch(PDO::FETCH_ASSOC)?:['email'=>'','school_id'=>'','first_name'=>'','middle_name'=>'','last_name'=>'','photo_path'=>''];
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
if($ctx!==[]){
  $start=monday(new DateTimeImmutable((string)$ctx['semester_start_date']));
  $end=new DateTimeImmutable((string)($ctx['semester_end_date']??$now->format('Y-m-d')));
  $ix=1; $cursor=$start;
  while($cursor <= $end){
    $ws=$cursor->format('Y-m-d'); $we=$cursor->modify('+4 days')->format('Y-m-d');
    $semesterWeeks[]=['index'=>$ix,'start'=>$ws,'end'=>$we,'label'=>'Week '.$ix.' ('.date('M j',strtotime($ws)).'-'.date('j, Y',strtotime($we)).')'];
    $cursor=$cursor->modify('+7 days'); $ix++;
  }
  if($semesterWeeks){
    $valid=array_column($semesterWeeks,'start');
    if(!in_array($selectedWeekStart,$valid,true)){$selectedWeekStart=$semesterWeeks[0]['start'];}
  }
}
$selectedWeek=null;
foreach($semesterWeeks as $w){if($w['start']===$selectedWeekStart){$selectedWeek=$w;break;}}
if($selectedWeek===null&&$semesterWeeks){$selectedWeek=$semesterWeeks[0];}
$weekLabelCurrent=$selectedWeek['label']??'Week 1';
$weekNumberCurrent=(int)($selectedWeek['index']??1);
$weekCountTotal=max(1,count($semesterWeeks));

$attendanceRows=[];
$attendanceSummary=['credited_hours'=>0.0,'present'=>0,'absent'=>0,'late'=>0];
if($ctx!==[]){
  $q=$pdo->prepare("SELECT attendance_date,time_in,time_out,total_hours,remarks FROM attendance_records WHERE student_user_id=:u AND semester_id=:m ORDER BY attendance_date DESC LIMIT 200");
  $q->execute(['u'=>$studentId,'m'=>(int)$ctx['semester_id']]);
  $attendanceRows=$q->fetchAll(PDO::FETCH_ASSOC);
  foreach($attendanceRows as $r){
    $dateVal=(string)$r['attendance_date'];
    $isPastOpen=(!empty($r['time_in']) && empty($r['time_out']) && $dateVal < $now->format('Y-m-d'));
    $attendanceSummary['credited_hours']+=(float)$r['total_hours'];
    if(!empty($r['time_in']) && !empty($r['time_out'])){
      $in=new DateTimeImmutable((string)$r['time_in']);
      $sched=$in->setTime(8,0,0);
      if($in>$sched){$attendanceSummary['late']++;}
      $attendanceSummary['present']++;
    } elseif(!empty($r['time_in']) && empty($r['time_out']) && !$isPastOpen) {
      $attendanceSummary['present']++;
    } else {
      $attendanceSummary['absent']++;
    }
  }
}

$journalCurrent=['grateful_for'=>'','proud_of'=>'','words_to_inspire'=>'','affirmations'=>'','look_forward_to'=>'','feeling'=>''];
$journalHistory=[];
if($ctx!==[] && tableExists($pdo,'student_journals')){
  $ws=$selectedWeek['start']??$attendanceWeekStart;
  $jc=$pdo->prepare("SELECT * FROM student_journals WHERE student_user_id=:u AND semester_id=:m AND week_start=:w LIMIT 1");
  $jc->execute(['u'=>$studentId,'m'=>(int)$ctx['semester_id'],'w'=>$ws]);
  $row=$jc->fetch(PDO::FETCH_ASSOC); if($row){$journalCurrent=array_merge($journalCurrent,$row);} 
  $jh=$pdo->prepare("SELECT week_start,submitted_at,status FROM student_journals WHERE student_user_id=:u AND semester_id=:m ORDER BY week_start DESC");
  $jh->execute(['u'=>$studentId,'m'=>(int)$ctx['semester_id']]);
  $journalHistory=$jh->fetchAll(PDO::FETCH_ASSOC);
}

$checklistItems=[];
if($ctx!==[] && tableExists($pdo,'evaluation_checklist_items')){
  $ci=$pdo->prepare("SELECT id,label,points,sort_order FROM evaluation_checklist_items WHERE semester_id=:m AND is_active=1 ORDER BY sort_order ASC,id ASC");
  $ci->execute(['m'=>(int)$ctx['semester_id']]);
  $checklistItems=$ci->fetchAll(PDO::FETCH_ASSOC);
}
$checklistHistory=[];
if($ctx!==[]){
  $weeks=[];
  foreach($attendanceRows as $r){
    $d=new DateTimeImmutable((string)$r['attendance_date']);
    $ws=monday($d)->format('Y-m-d');
    if(!isset($weeks[$ws])){$weeks[$ws]=0.0;}
    $weeks[$ws]+=(float)$r['total_hours'];
  }
  krsort($weeks);
  foreach($weeks as $ws=>$hours){
    $totalItems=count($checklistItems);
    $done=$totalItems>0 ? max(0,min($totalItems,(int)floor(($hours/40)*$totalItems))) : 0;
    $items=[];$idx=0; foreach($checklistItems as $it){$idx++; $items[]=['label'=>(string)$it['label'],'points'=>(int)($it['points']??0),'checked'=>$idx<=$done];}
    $checklistHistory[]=['week_start'=>$ws,'hours'=>$hours,'done'=>$done,'total'=>$totalItems,'items'=>$items,'remarks'=>$totalItems===0?'No checklist configured for this semester yet.':($done===$totalItems?'Good compliance this week.':'Please improve checklist compliance next week.')];
  }
}

$geoLat=(float)($geo['latitude']??8.4795); $geoLng=(float)($geo['longitude']??124.6473); $geoRadius=(float)($geo['radius_meters']??80);
$schoolName=(string)($geo['name']??'Not assigned'); $schoolAddr=(string)($geo['address']??'No address available');
$todayIn=!empty($today['time_in'])?(new DateTimeImmutable((string)$today['time_in']))->format('h:i A'):'--';
$todayOut=!empty($today['time_out'])?(new DateTimeImmutable((string)$today['time_out']))->format('h:i A'):'--';
$todayHours=number_format((float)($today['total_hours']??0),2);
$statusToday=($today===[]?'Pending':(!empty($today['time_out'])?'Completed':'In Progress'));
$hasValidTimeIn = ($today!==[] && !empty($today['time_in']) && strtotime((string)$today['time_in']) !== false && strtotime((string)$today['time_in']) > 0);
$canShowTimeOut = ($isSemesterActive && $hasValidTimeIn && empty($today['time_out']));
$canShowTimeIn = ($isSemesterActive && $today===[] && $isTimeInWindowOpen);
$canClickTimeOut = false;
$timeOutHint = '';
$timeInHint = '';
if($today===[] && !$isTimeInWindowOpen){
  $timeInHint = 'Time in is available only until 4:00 PM to complete 8 hours before 11:59 PM.';
}
if(!$isSemesterActive){
  $timeInHint = 'Semester is not active. Attendance actions are disabled until the semester is started.';
}
if($canShowTimeOut){
  $elapsedStmt = $pdo->prepare("SELECT GREATEST(TIMESTAMPDIFF(SECOND, time_in, NOW()), 0) FROM attendance_records WHERE id=:id LIMIT 1");
  $elapsedStmt->execute(['id'=>(int)$today['id']]);
  $elapsedSeconds = (int)($elapsedStmt->fetchColumn() ?: 0);
  $elapsedHours = $elapsedSeconds / 3600;
  if($elapsedSeconds >= 28800){
    $canClickTimeOut = true;
  } else {
    $remaining = min(8, max(0, 8 - $elapsedHours));
    $timeOutHint = 'Time out will be enabled in about ' . number_format($remaining, 2) . ' hour(s).';
  }
}

$wordsInspire=array_values(array_filter(array_map('trim',explode("\n",(string)($journalCurrent['words_to_inspire']??''))),static fn($v)=>$v!==''));
$affirmations=array_values(array_filter(array_map('trim',explode("\n",(string)($journalCurrent['affirmations']??''))),static fn($v)=>$v!==''));
while(count($wordsInspire)<3){$wordsInspire[]='';}
while(count($affirmations)<3){$affirmations[]='';}

function urlWith(array $overrides=[]): string { $q=array_merge($_GET,$overrides); return '?'.http_build_query($q); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Student Portal</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
  <style>
    body{background:#f4f6fb}
    .card{background:#fff;border:1px solid #dbe4ef;border-radius:14px}
    .field{width:100%;border:1px solid #d4dbe8;border-radius:10px;padding:.55rem .75rem;background:#fff}
    .field:focus{outline:none;border-color:#10b981;box-shadow:0 0 0 3px rgba(16,185,129,.15)}
    .tab{padding:.55rem .8rem;border-radius:.55rem;font-weight:600;color:#475569}
    .tab.active{background:#e8f8f1;color:#047857}
    .status{font-size:.75rem;padding:.2rem .55rem;border-radius:999px;font-weight:700}
    .status.ok{background:#dcfce7;color:#166534}
    .status.warn{background:#fef3c7;color:#92400e}
    .status.bad{background:#fee2e2;color:#991b1b}
    .logout-link{transition:all .2s ease;border:1px solid transparent;border-radius:999px;padding:.4rem .65rem}
    .logout-link:hover{background:rgba(255,255,255,.12);border-color:rgba(255,255,255,.25)}
  </style>
</head>
<body class="text-slate-800">
<header class="bg-emerald-700 text-white">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-3 flex items-center justify-between gap-3">
    <div class="flex items-center gap-3 min-w-0">
      <img src="/practicum_system/assets/images/logo_college.png" alt="Logo" class="h-12 w-12 rounded bg-white/90 p-0.5 object-contain">
      <div class="font-semibold truncate">Student / Portal</div>
    </div>
    <div class="text-sm flex items-center gap-2 sm:gap-3">
      <span class="hidden sm:inline truncate max-w-[260px] font-medium"><?= htmlspecialchars($headerDisplayName) ?></span>
      <span class="opacity-70">|</span>
      <a id="logoutLink" data-logout-trigger class="logout-link font-semibold flex items-center gap-2" href="/practicum_system/public/logout.php">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
          <polyline points="16 17 21 12 16 7"/>
          <line x1="21" y1="12" x2="9" y2="12"/>
        </svg>
        <span>Sign out</span>
      </a>
    </div>
  </div>
</header>

<main class="max-w-5xl mx-auto px-3 sm:px-5 py-5 sm:py-8 space-y-4">

  <?php foreach($errors as $e): ?><div class="card border-red-200 bg-red-50 text-red-700 px-4 py-3 text-sm"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
  <?php foreach($ok as $m): ?><div class="card border-emerald-200 bg-emerald-50 text-emerald-700 px-4 py-3 text-sm"><?= htmlspecialchars($m) ?></div><?php endforeach; ?>

  <div class="card p-2 sm:p-3">
    <nav class="flex flex-wrap gap-1 sm:gap-2">
      <a class="tab <?= $tab==='attendance'?'active':'' ?>" href="<?= htmlspecialchars(urlWith(['tab'=>'attendance'])) ?>">Attendance</a>
      <a class="tab <?= $tab==='journal'?'active':'' ?>" href="<?= htmlspecialchars(urlWith(['tab'=>'journal'])) ?>">Journal</a>
      <a class="tab <?= $tab==='records'?'active':'' ?>" href="<?= htmlspecialchars(urlWith(['tab'=>'records'])) ?>">Records</a>
      <a class="tab <?= $tab==='profile'?'active':'' ?>" href="<?= htmlspecialchars(urlWith(['tab'=>'profile'])) ?>">Profile</a>
    </nav>
  </div>

  <?php if($tab==='attendance'): ?>
  <section class="card p-4 sm:p-5 space-y-4">
    <div class="flex items-center justify-between gap-2">
      <h2 class="text-2xl font-bold">Attendance</h2>
      <span class="text-sm text-slate-500"><?= htmlspecialchars((string)($ctx['section_name']??'No section')) ?></span>
    </div>
    <div class="text-sm text-slate-500"><?= htmlspecialchars($todayText) ?></div>
    <?php if(!$isSemesterActive): ?>
    <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
      This semester is currently <strong><?= htmlspecialchars(strtoupper((string)($ctx['semester_status'] ?? 'closed'))) ?></strong>.
      Attendance and journal submission are locked. You can still view your history in <strong>Records</strong>.
    </div>
    <?php endif; ?>

    <div class="grid md:grid-cols-3 gap-3">
      <div class="card p-4 md:col-span-2">
        <div class="flex justify-between items-center mb-2"><h3 class="font-semibold">Assigned School</h3><span class="status ok"><?= htmlspecialchars((string)($geo['school_type']==='private_school'?'Private':'Public')) ?></span></div>
        <dl class="grid sm:grid-cols-3 gap-y-2 text-sm">
          <dt class="text-slate-500">School Name</dt><dd class="sm:col-span-2 font-medium"><?= htmlspecialchars($schoolName) ?></dd>
          <dt class="text-slate-500">Address</dt><dd class="sm:col-span-2"><?= htmlspecialchars($schoolAddr) ?></dd>
          <dt class="text-slate-500">Geofence Radius</dt><dd class="sm:col-span-2 font-semibold"><?= h($geoRadius) ?> meters</dd>
        </dl>
      </div>
      <div class="card p-4">
        <div class="flex items-center justify-between mb-2"><h3 class="font-semibold">Location Status</h3><button type="button" id="btnRetryLoc" class="text-xs text-slate-500 hover:text-slate-800">Retry</button></div>
        <p id="geoStatus" class="text-sm font-semibold text-amber-600">Checking location...</p>
        <p id="geoDistance" class="text-xs text-slate-500 mt-1">Distance: -- (radius: <?= h($geoRadius) ?>m)</p>
        <div class="mt-3 space-y-2">
          <?php if($canShowTimeIn): ?>
          <form method="post"><input type="hidden" name="action" value="time_in"><input type="hidden" id="latField" name="lat"><input type="hidden" id="lngField" name="lng"><button id="btnTimeIn" class="w-full rounded-lg bg-slate-300 py-2 text-sm font-semibold text-slate-600" disabled>Time In</button></form>
          <?php elseif($today!==[]): ?>
          <button id="btnTimeInDone" class="w-full rounded-lg bg-emerald-100 border border-emerald-300 py-2 text-sm font-semibold text-emerald-700 cursor-not-allowed" disabled>Timed In</button>
          <?php else: ?>
          <button class="w-full rounded-lg bg-slate-200 border border-slate-300 py-2 text-sm font-semibold text-slate-500 cursor-not-allowed" disabled>Time In Unavailable</button>
          <?php if($timeInHint!==''): ?><p class="text-xs text-amber-700"><?= htmlspecialchars($timeInHint) ?></p><?php endif; ?>
          <?php endif; ?>
          <?php if($canShowTimeOut): ?>
          <form method="post">
            <input type="hidden" name="action" value="time_out">
            <button <?= $canClickTimeOut?'':'disabled' ?> class="w-full rounded-lg py-2 text-sm font-semibold <?= $canClickTimeOut?'border border-emerald-300 text-emerald-700 hover:bg-emerald-50':'bg-slate-200 text-slate-500 cursor-not-allowed' ?>">Time Out</button>
          </form>
          <?php if($timeOutHint!==''): ?><p class="text-xs text-amber-700"><?= htmlspecialchars($timeOutHint) ?></p><?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="card p-4">
      <h3 class="font-semibold mb-2">Today's Attendance</h3>
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
        <div><div class="text-slate-500">Time In</div><div class="font-semibold"><?= htmlspecialchars($todayIn) ?></div></div>
        <div><div class="text-slate-500">Time Out</div><div class="font-semibold"><?= htmlspecialchars($todayOut) ?></div></div>
        <div><div class="text-slate-500">Hours</div><div class="font-semibold"><?= htmlspecialchars($todayHours) ?> / 8.00</div></div>
        <div><div class="text-slate-500">Status</div><div class="mt-1"><span class="status <?= $statusToday==='Completed'?'ok':($statusToday==='In Progress'?'warn':'bad') ?>"><?= htmlspecialchars($statusToday) ?></span></div></div>
      </div>
    </div>

    <div class="card p-4">
      <h3 class="font-semibold mb-2">Attendance Location</h3>
      <div id="attendanceMap" class="h-64 sm:h-80 rounded-xl border border-slate-200"></div>
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
    <h2 class="text-2xl font-bold">Journal</h2>
    <div class="card border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700">
      <div>Journal submission is open every Friday only.</div>
      <div>Closes at 11:59 PM every Friday.</div>
      <div class="mt-2 text-slate-700">
        Rules: Write your own reflections only. AI-generated, copied, or fabricated content is not allowed. Coordinators can approve, request revision, or reject submissions.
      </div>
    </div>

    <?php
      $journalStatus=(string)($journalCurrent['status']??'');
      $journalStatusLabel=$journalStatus!==''?ucwords(str_replace('_',' ',$journalStatus)):'Not submitted';
      $journalStatusClass=$journalStatus==='approved'?'ok':($journalStatus==='revise'?'bad':($journalStatus==='pending_review'?'warn':'warn'));
    ?>
    <div class="card p-3 flex items-center justify-between">
      <div class="text-sm text-slate-600">Current Week Status</div>
      <span class="status <?= $journalStatusClass ?>"><?= htmlspecialchars($journalStatusLabel) ?></span>
    </div>

    <form method="post" class="card p-4 sm:p-6 space-y-4">
      <input type="hidden" name="action" value="save_journal">
      <div class="text-center font-bold text-emerald-700 text-2xl tracking-wide border-b border-emerald-400 pb-2">GRATITUDE JOURNAL</div>
      <div class="grid sm:grid-cols-2 gap-3">
        <div><label class="text-xs text-slate-500 font-semibold">NAME</label><div class="field bg-slate-50"><?= htmlspecialchars($fullName) ?></div></div>
        <div><label class="text-xs text-slate-500 font-semibold">WEEK</label><div class="field bg-slate-50">Week <?= $weekNumberCurrent ?> of <?= $weekCountTotal ?></div></div>
      </div>

      <div class="grid md:grid-cols-2 gap-3">
        <div><label class="text-xs font-semibold text-slate-600">I'M GRATEFUL FOR</label><textarea class="field h-28" name="grateful_for" placeholder="List things you're grateful for this week..."><?= htmlspecialchars((string)$journalCurrent['grateful_for']) ?></textarea></div>
        <div><label class="text-xs font-semibold text-slate-600">SOMETHING I'M PROUD OF</label><textarea class="field h-28" name="proud_of" placeholder="What are you proud of this week?"><?= htmlspecialchars((string)$journalCurrent['proud_of']) ?></textarea></div>
      </div>

      <div class="grid md:grid-cols-2 gap-3">
        <div>
          <label class="text-xs font-semibold text-slate-600">WORDS TO INSPIRE</label>
          <?php foreach($wordsInspire as $i=>$v): ?><input class="field mt-2" name="words_to_inspire_list[]" value="<?= htmlspecialchars($v) ?>" placeholder="Enter inspiring word #<?= $i+1 ?>"><?php endforeach; ?>
        </div>
        <div>
          <label class="text-xs font-semibold text-slate-600">WORDS OF AFFIRMATION</label>
          <?php foreach($affirmations as $i=>$v): ?><input class="field mt-2" name="affirmations_list[]" value="<?= htmlspecialchars($v) ?>" placeholder="Enter affirmation sentence #<?= $i+1 ?>"><?php endforeach; ?>
        </div>
      </div>

      <div><label class="text-xs font-semibold text-slate-600">NEXT WEEK I LOOK FORWARD TO</label><input class="field" name="look_forward_to" value="<?= htmlspecialchars((string)$journalCurrent['look_forward_to']) ?>" placeholder="What are you looking forward to next week?"></div>
      <div>
        <label class="text-xs font-semibold text-slate-600">HOW HAVE I FELT THIS WEEK?</label>
        <div class="grid grid-cols-2 sm:grid-cols-5 gap-2 mt-2 text-xs">
          <?php $moods=['great'=>'GOOD','good'=>'LEAN GOOD','neutral'=>'NEUTRAL','lean_not'=>'LEAN NOT','not_good'=>'NOT GOOD']; foreach($moods as $k=>$lbl): ?>
          <label class="flex items-center gap-2 border rounded-lg px-2 py-2"><input type="radio" name="feeling" value="<?= $k ?>" <?= ((string)$journalCurrent['feeling']===$k)?'checked':'' ?>><?= $lbl ?></label>
          <?php endforeach; ?>
        </div>
      </div>

      <button class="w-full rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-semibold py-2.5">Submit Journal</button>
    </form>
  </section>
  <?php endif; ?>

  <?php if($tab==='records'): ?>
  <section class="card p-4 sm:p-5 space-y-4">
    <h2 class="text-2xl font-bold">Student Records</h2>
    <div class="flex flex-wrap gap-1">
      <a class="tab <?= $rtab==='attendance'?'active':'' ?>" href="<?= htmlspecialchars(urlWith(['tab'=>'records','records_tab'=>'attendance'])) ?>">Attendance</a>
      <a class="tab <?= $rtab==='checklist'?'active':'' ?>" href="<?= htmlspecialchars(urlWith(['tab'=>'records','records_tab'=>'checklist'])) ?>">Checklist</a>
      <a class="tab <?= $rtab==='journal'?'active':'' ?>" href="<?= htmlspecialchars(urlWith(['tab'=>'records','records_tab'=>'journal'])) ?>">Journal</a>
    </div>

    <?php if($rtab==='attendance'): ?>
      <div class="grid sm:grid-cols-4 gap-3">
        <div class="card p-3"><div class="text-xs text-slate-500">Credited Hours</div><div class="text-2xl font-bold"><?= h((float)$attendanceSummary['credited_hours']) ?></div></div>
        <div class="card p-3"><div class="text-xs text-slate-500">Days Present</div><div class="text-2xl font-bold"><?= (int)$attendanceSummary['present'] ?></div></div>
        <div class="card p-3"><div class="text-xs text-slate-500">Days Absent</div><div class="text-2xl font-bold"><?= (int)$attendanceSummary['absent'] ?></div></div>
        <div class="card p-3"><div class="text-xs text-slate-500">Late Count</div><div class="text-2xl font-bold"><?= (int)$attendanceSummary['late'] ?></div></div>
      </div>
      <div class="overflow-auto border rounded-xl">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50"><tr><th class="text-left p-3">Date</th><th class="text-left p-3">Day</th><th class="text-left p-3">Time In</th><th class="text-left p-3">Time Out</th><th class="text-left p-3">Credited Hrs</th><th class="text-left p-3">Status</th></tr></thead>
          <tbody>
          <?php foreach($attendanceRows as $r): $d=new DateTimeImmutable((string)$r['attendance_date']); $isPastOpen=(!empty($r['time_in']) && empty($r['time_out']) && $d->format('Y-m-d') < $now->format('Y-m-d')); $st=!empty($r['time_in'])?(!empty($r['time_out'])?'Present':($isPastOpen?'Absent':'Pending')):'Absent'; ?>
            <tr class="border-t"><td class="p-3"><?= $d->format('M j, Y') ?></td><td class="p-3"><?= $d->format('l') ?></td><td class="p-3"><?= !empty($r['time_in'])?(new DateTimeImmutable((string)$r['time_in']))->format('h:i A'):'--' ?></td><td class="p-3"><?= !empty($r['time_out'])?(new DateTimeImmutable((string)$r['time_out']))->format('h:i A'):'--' ?></td><td class="p-3"><?= h((float)$r['total_hours']) ?></td><td class="p-3"><span class="status <?= $st==='Present'?'ok':($st==='Pending'?'warn':'bad') ?>"><?= $st ?></span></td></tr>
          <?php endforeach; ?>
          <?php if(!$attendanceRows): ?><tr><td colspan="6" class="p-4 text-center text-slate-500">No attendance records yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    <?php elseif($rtab==='checklist'): ?>
      <?php foreach($checklistHistory as $wh): ?>
      <div class="card p-4">
        <div class="flex justify-between"><h4 class="font-semibold">Week of <?= date('M j, Y',strtotime((string)$wh['week_start'])) ?></h4><div class="text-sm text-slate-500"><?= (int)$wh['done'] ?> / <?= (int)$wh['total'] ?> completed</div></div>
        <div class="mt-2 space-y-2 text-sm">
        <?php foreach($wh['items'] as $it): ?><div class="flex items-center justify-between gap-2 <?= $it['checked']?'text-emerald-700':'text-red-600' ?>"><div class="flex items-center gap-2"><span><?= $it['checked']?'✓':'✕' ?></span><span><?= htmlspecialchars((string)$it['label']) ?></span></div><span class="text-xs font-semibold"><?= (int)($it['points']??0) ?> pts</span></div><?php endforeach; ?>
        <div class="mt-2 rounded-lg border bg-slate-50 p-3 text-slate-600"><span class="font-semibold">Coordinator Remarks:</span> <?= htmlspecialchars((string)$wh['remarks']) ?></div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if(!$checklistHistory): ?><div class="text-sm text-slate-500">No checklist records available yet.</div><?php endif; ?>
    <?php else: ?>
      <div class="overflow-auto border rounded-xl">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50"><tr><th class="text-left p-3">Week</th><th class="text-left p-3">Submitted At</th><th class="text-left p-3">Status</th></tr></thead>
          <tbody>
            <?php foreach($journalHistory as $j): ?>
              <tr class="border-t"><td class="p-3"><?= htmlspecialchars((string)$j['week_start']) ?></td><td class="p-3"><?= !empty($j['submitted_at'])?(new DateTimeImmutable((string)$j['submitted_at']))->format('M j, Y h:i A'):'--' ?></td><td class="p-3"><span class="status <?= $j['status']==='approved'?'ok':($j['status']==='revise'?'bad':'warn') ?>"><?= htmlspecialchars(ucwords(str_replace('_',' ',(string)$j['status']))) ?></span></td></tr>
            <?php endforeach; ?>
            <?php if(!$journalHistory): ?><tr><td colspan="3" class="p-4 text-center text-slate-500">No journal history yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <?php if($tab==='profile'): ?>
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
          <p class="text-slate-500 text-sm"><?= htmlspecialchars((string)($ctx['section_name']??'No section')) ?> - Student</p>
        </div>
      </div>

      <div class="mt-6 grid lg:grid-cols-3 gap-4">
        <aside class="card p-4 h-fit lg:sticky lg:top-4">
          <h4 class="text-xs tracking-wider text-slate-400 font-bold">ACCOUNT DETAILS</h4>
          <div class="mt-3 text-sm space-y-2">
            <div><div class="text-slate-500">Email Address</div><div class="font-medium break-all"><?= htmlspecialchars((string)$profile['email']) ?></div></div>
            <div><div class="text-slate-500">Section</div><div class="font-medium"><?= htmlspecialchars((string)($ctx['section_name']??'N/A')) ?></div></div>
            <div><div class="text-slate-500">Student ID</div><div class="font-medium"><?= htmlspecialchars((string)$profile['school_id']) ?></div></div>
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

          <form method="post" class="card p-4 sm:p-6 space-y-3">
            <input type="hidden" name="action" value="change_password">
            <h4 class="text-xl font-bold">Account Security</h4>
            <p class="text-sm text-slate-600">Want to change your password? We will send a 6-digit verification code to your registered email address to ensure it is really you.</p>
            <div class="grid sm:grid-cols-3 gap-3">
              <input class="field" name="current_password" type="password" placeholder="Current password" required>
              <input class="field" name="new_password" type="password" placeholder="New password" required>
              <input class="field" name="confirm_password" type="password" placeholder="Confirm password" required>
            </div>
            <button class="rounded-lg bg-blue-600 px-5 py-2.5 text-white font-semibold hover:bg-blue-700">Change Password</button>
          </form>
        </div>
      </div>
    </div>
  </section>
  <?php endif; ?>

</main>

<script>
(() => {
  const mapEl=document.getElementById('attendanceMap');
  if(!mapEl || typeof L==='undefined') return;
  const school={lat:<?= json_encode($geoLat) ?>,lng:<?= json_encode($geoLng) ?>,radius:<?= json_encode($geoRadius) ?>};
  const map=L.map('attendanceMap').setView([school.lat,school.lng],14);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; OpenStreetMap contributors'}).addTo(map);
  const schoolMarker=L.marker([school.lat,school.lng]).addTo(map);
  schoolMarker.bindPopup('Assigned School').openPopup();
  L.circle([school.lat,school.lng],{radius:school.radius,color:'#10b981',fillColor:'#6ee7b7',fillOpacity:.25}).addTo(map);

  const statusEl=document.getElementById('geoStatus');
  const distanceEl=document.getElementById('geoDistance');
  const latField=document.getElementById('latField');
  const lngField=document.getElementById('lngField');
  const btnIn=document.getElementById('btnTimeIn');
  const canTimeIn=<?= json_encode($canShowTimeIn) ?>;
  let userMarker=null;

  function hav(lat1,lng1,lat2,lng2){const R=6371000;const dLat=(lat2-lat1)*Math.PI/180;const dLng=(lng2-lng1)*Math.PI/180;const a=Math.sin(dLat/2)**2+Math.cos(lat1*Math.PI/180)*Math.cos(lat2*Math.PI/180)*(Math.sin(dLng/2)**2);return R*(2*Math.atan2(Math.sqrt(a),Math.sqrt(1-a)));}
  function updateLoc(){
    if(!navigator.geolocation){statusEl.textContent='Geolocation not supported';statusEl.className='text-sm font-semibold text-red-600'; return;}
    navigator.geolocation.getCurrentPosition((pos)=>{
      const lat=pos.coords.latitude,lng=pos.coords.longitude; latField && (latField.value=lat); lngField && (lngField.value=lng);
      const d=hav(lat,lng,school.lat,school.lng);
      distanceEl.textContent=`Distance: ${d.toFixed(2)}m (radius: ${school.radius.toFixed(2)}m)`;
      const inside=d<=school.radius;
      statusEl.textContent=inside?'Inside geofence':'Outside geofence';
      statusEl.className='text-sm font-semibold '+(inside?'text-emerald-600':'text-red-600');
      if(btnIn && canTimeIn){btnIn.disabled=!inside;btnIn.className='w-full rounded-lg py-2 text-sm font-semibold '+(inside?'bg-emerald-600 text-white hover:bg-emerald-700':'bg-slate-300 text-slate-600');}
      if(userMarker){map.removeLayer(userMarker);} userMarker=L.marker([lat,lng],{icon:L.divIcon({className:'',html:'<div style="width:12px;height:12px;background:#2563eb;border-radius:999px;border:2px solid #fff;box-shadow:0 0 0 2px #2563eb55"></div>'})}).addTo(map);
    },()=>{statusEl.textContent='Unable to fetch location';statusEl.className='text-sm font-semibold text-red-600';});
  }
  updateLoc();
  document.getElementById('btnRetryLoc')?.addEventListener('click',updateLoc);
})();
</script>
<?php renderLogoutUi('/practicum_system/public/logout.php'); ?>
</body>
</html>
