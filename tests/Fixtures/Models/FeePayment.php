<?php

namespace Scholar\AiQuery\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeePayment extends Model
{
    // transaction_reference is deliberately fillable-but-never-registered
    // in tests below, so we can assert it never leaks through AI Query.
    protected $fillable = ['student_id', 'status', 'amount', 'month', 'transaction_reference'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
