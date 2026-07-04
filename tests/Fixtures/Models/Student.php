<?php

namespace Scholar\AiQuery\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Student extends Model
{
    protected $fillable = ['name', 'class_id', 'admission_no', 'school_id'];

    public function feePayments(): HasMany
    {
        return $this->hasMany(FeePayment::class);
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }
}
