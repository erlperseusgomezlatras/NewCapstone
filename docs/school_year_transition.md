# School Year Transition Logic

## Rule Mapping

1. **Within 2026-2027 (1st/2nd/Summer)**  
   Call:
   - `SchoolYearService::setTermInactiveState('2026-2027', $termName)`
   Result:
   - All sections for that school year -> `inactive`
   - All students -> `inactive`
   - All teachers (`head_teacher`, `coordinator`) -> `inactive`
   - No archive is created

2. **When starting a new school year (e.g. 2027-2028)**  
   Call:
   - `SchoolYearService::startNewSchoolYear('2027-2028', $adminEmail)`
   Result:
   - Previous active school year is archived to `history_school_year_archive`
   - Previous school year status -> `archived`
   - Previous year user statuses -> `archived`
   - New school year + 3 terms created
   - New year starts clean (`inactive` statuses by default)

3. **History behavior**
   - Viewable via `SELECT` from `history_school_year_archive`
   - Not editable (DB triggers block `UPDATE` and `DELETE`)

## Suggested Endpoint Pseudocode

```php
<?php
require_once __DIR__ . '/../services/SchoolYearService.php';

$service = new SchoolYearService();

// Term switch endpoint
// POST: school_year=2026-2027, term=1st Semester
$service->setTermInactiveState($_POST['school_year'], $_POST['term']);

// New school year endpoint
// POST: new_school_year=2027-2028
$service->startNewSchoolYear($_POST['new_school_year'], $_SESSION['email']);
```

## History Query Example

```sql
SELECT id, source_school_year_label, archived_at, archived_by
FROM history_school_year_archive
ORDER BY archived_at DESC;
```

