<?php
declare(strict_types=1);

namespace App\Modules\Attendance;

/**
 * Evaluate enrollment as of the actual session date, not its current status.
 * A transfer/dropout takes effect at the start of its effective date.
 * Completed enrollments have already passed the batch-end check on transition.
 */
final class AttendanceEligibility
{
    public static function atSessionDate(): string
    {
        return "(
            e.status IN ('active','completed')
            OR (
                e.status IN ('transferred','dropout')
                AND EXISTS (
                    SELECT 1 FROM student_events ev
                    WHERE ev.institute_id=e.institute_id
                      AND ev.enrollment_id=e.id
                      AND ev.event_type=CASE e.status WHEN 'transferred' THEN 'transfer' ELSE 'dropout' END
                      AND ev.effective_on>?
                )
            )
        )";
    }
}
