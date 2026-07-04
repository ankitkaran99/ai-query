<?php

namespace Scholar\AiQuery\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRecord extends Model
{
    protected $fillable = ['student_id', 'percentage', 'month'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
