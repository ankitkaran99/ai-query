<?php

use Scholar\AiQuery\Support\QueryableDefinition;
use Scholar\AiQuery\Tests\Fixtures\Models\FeePayment;
use Scholar\AiQuery\Tests\Fixtures\Models\Student;

it('resolves columns from $fillable when using autoColumns', function () {
    $definition = (new QueryableDefinition('students', Student::class))->autoColumns();

    expect($definition->allowedColumns())
        ->toEqualCanonicalizing(['name', 'class_id', 'admission_no', 'school_id']);
});

it('excludes columns passed to autoColumns(except:)', function () {
    $definition = (new QueryableDefinition('students', Student::class))->autoColumns(except: ['admission_no']);

    expect($definition->allowedColumns())
        ->toEqualCanonicalizing(['name', 'class_id', 'school_id']);
});

it('resolves relation columns from the related model $fillable when using exposeRelation', function () {
    $definition = (new QueryableDefinition('students', Student::class))->exposeRelation('feePayments');

    expect($definition->relationColumns('feePayments'))
        ->toEqualCanonicalizing(['student_id', 'status', 'amount', 'month', 'transaction_reference']);
});

it('lets exposeRelation take an explicit override instead of auto-resolving', function () {
    $definition = (new QueryableDefinition('students', Student::class))
        ->exposeRelation('feePayments', ['status', 'month']);

    expect($definition->relationColumns('feePayments'))->toBe(['status', 'month']);
});

it('throws a clear error for a relation that does not exist on the model', function () {
    $definition = new QueryableDefinition('students', Student::class);

    expect(fn () => $definition->exposeRelation('nonexistentRelation'))
        ->toThrow(InvalidArgumentException::class);
});

it('throws a clear error when a named "relation" is not actually an Eloquent relation', function () {
    $definition = new QueryableDefinition('students', Student::class);

    // "getKey" exists on every Eloquent model but isn't a relation.
    expect(fn () => $definition->exposeRelation('getKey'))
        ->toThrow(InvalidArgumentException::class);
});

it('computes a different file-version fingerprint when the file mtime changes', function () {
    // This is the actual mechanism behind the pass-2/pass-6 cache-busting
    // fixes: prove the fingerprint itself changes with mtime, without
    // needing to prove a full Cache round-trip end to end.
    $definition = new QueryableDefinition('students', Student::class);
    $method = new ReflectionMethod($definition, 'fileVersion');
    $method->setAccessible(true);

    $before = $method->invoke($definition, FeePayment::class);

    $file = (new ReflectionClass(FeePayment::class))->getFileName();
    touch($file, filemtime($file) + 5);
    clearstatcache();

    $after = $method->invoke($definition, FeePayment::class);

    expect($after)->not->toBe($before);
});
