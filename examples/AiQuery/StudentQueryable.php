<?php

namespace App\AiQuery;

use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Scholar\AiQuery\Queryable;

/**
 * Example: drop a file like this in app/AiQuery/ and it is discovered and
 * registered automatically — no manual AiQuery::register() call needed.
 *
 * Key defaults to "students" (derived from the class name). Ask questions
 * against it with:
 *
 *   AiQuery::ask('Which students have not paid fees this month?');
 */
class StudentQueryable extends Queryable
{
    public function model(): string
    {
        return Student::class;
    }

    public function description(): string
    {
        return 'Enrolled students, their fee payments, and attendance records';
    }

    public function columns(): ?array
    {
        return null; // auto-resolved from Student::$fillable
    }

    public function relations(): array
    {
        return [
            'feePayments' => null,                          // auto-resolved from FeePayment::$fillable
            'attendanceRecords' => ['percentage', 'month'],  // explicit override
        ];
    }

    public function scope(Builder $query): void
    {
        // Never bypassable by anything the AI returns — this is where
        // tenant isolation lives for a multi-tenant School ERP.
        $query->where('school_id', tenant('id'));
    }
}
