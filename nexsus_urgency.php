<?php
declare(strict_types=1);
// Match NEXSUS MySQL local dates (DB connection uses +02:00).
date_default_timezone_set('Africa/Johannesburg');

/**
 * Live visual deadline classification; never rewrites task or request statuses.
 * A record is overdue only after its due date has passed and while it is open.
 * Pending > 7 days or In Progress > 14 days is flagged as taking too long,
 * provided the deadline is not already due within the next two days.
 */
function nexUrgency(string $status, ?string $dueDate, ?string $startDate = null, ?string $today = null): array {
    $today ??= date('Y-m-d');
    if (in_array($status, ['completed', 'cancelled'], true)) {
        return ['key'=>'closed', 'label'=>ucfirst($status), 'days'=>0];
    }
    $ref = DateTimeImmutable::createFromFormat('!Y-m-d', $today);
    $due = $dueDate ? DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate) : false;
    if (!$ref || !$due) return ['key'=>'on_track', 'label'=>'No valid deadline', 'days'=>0];
    $daysUntil = (int)$ref->diff($due)->format('%r%a');
    if ($daysUntil < 0) {
        $late = abs($daysUntil);
        return ['key'=>'overdue', 'label'=>'Overdue by '.$late.' '.($late===1?'day':'days'), 'days'=>$late];
    }
    if ($daysUntil===0) return ['key'=>'due_today', 'label'=>'Due today', 'days'=>0];
    if ($daysUntil<=2) return ['key'=>'due_soon', 'label'=>$daysUntil===1?'Due tomorrow':'Due in 2 days', 'days'=>$daysUntil];

    $start = $startDate ? DateTimeImmutable::createFromFormat('!Y-m-d', $startDate) : false;
    if ($start && $start <= $ref) {
        $age = (int)$start->diff($ref)->days;
        if (($status==='pending' && $age >= 7) || ($status==='in_progress' && $age >= 14)) {
            return ['key'=>'ageing', 'label'=>'Taking too long · '.$age.' days open', 'days'=>$age];
        }
    }
    return ['key'=>'on_track', 'label'=>'On track', 'days'=>$daysUntil];
}
function nexUrgencyRank(string $key): int {
    return ['overdue'=>0,'due_today'=>1,'due_soon'=>2,'ageing'=>3,'on_track'=>4,'closed'=>5][$key] ?? 6;
}
