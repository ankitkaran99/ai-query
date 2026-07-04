<?php

use Scholar\AiQuery\Support\QueryBuilder;
use Scholar\AiQuery\Support\QueryableDefinition;
use Scholar\AiQuery\Tests\Fixtures\Models\FeePayment;
use Scholar\AiQuery\Tests\Fixtures\Models\Student;

beforeEach(function () {
    $this->builder = new QueryBuilder();

    $this->alice = Student::create(['name' => 'Alice', 'class_id' => 5, 'admission_no' => 'A1', 'school_id' => 'school-a']);
    $this->bob = Student::create(['name' => 'Bob', 'class_id' => 5, 'admission_no' => 'B1', 'school_id' => 'school-b']);

    FeePayment::create([
        'student_id' => $this->alice->id, 'status' => 'unpaid', 'amount' => 500,
        'month' => '2026-07', 'transaction_reference' => 'TXN-SECRET-1',
    ]);
    FeePayment::create([
        'student_id' => $this->bob->id, 'status' => 'paid', 'amount' => 300,
        'month' => '2026-07', 'transaction_reference' => 'TXN-SECRET-2',
    ]);
});

it('applies a direct filter on the target', function () {
    $definition = (new QueryableDefinition('students', Student::class))->columns(['id', 'name', 'class_id']);

    $spec = ['target' => 'students', 'filters' => [['field' => 'name', 'operator' => '=', 'value' => 'Alice']]];
    $results = $this->builder->build($spec, $definition)->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->name)->toBe('Alice');
});

it('never lets the spec bypass the scope, since nothing in the spec even mentions it', function () {
    $definition = (new QueryableDefinition('students', Student::class))
        ->columns(['id', 'name', 'school_id'])
        ->scope(fn ($query) => $query->where('school_id', 'school-a'));

    $spec = ['target' => 'students', 'filters' => []]; // no tenant filter in the spec at all

    $results = $this->builder->build($spec, $definition)->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->name)->toBe('Alice');
});

it('hydrates a hasMany relation correctly when eager-load columns are restricted', function () {
    // Regression test for the first-pass bug: restricting select() on a
    // hasMany eager load without including the foreign key silently
    // returned an empty relation, even with a matching row in the DB.
    $definition = (new QueryableDefinition('students', Student::class))
        ->columns(['id', 'name'])
        ->relation('feePayments', ['status', 'amount', 'month']);

    $spec = [
        'target' => 'students',
        'filters' => [],
        'relation_filters' => [
            ['relation' => 'feePayments', 'filters' => [['field' => 'status', 'operator' => '=', 'value' => 'unpaid']]],
        ],
    ];

    $student = $this->builder->build($spec, $definition)->first();

    expect($student->name)->toBe('Alice')
        ->and($student->feePayments)->toHaveCount(1)
        ->and($student->feePayments->first()->status)->toBe('unpaid');
});

it('never leaks a column through a relation that was not registered for it', function () {
    $definition = (new QueryableDefinition('students', Student::class))
        ->columns(['id', 'name'])
        ->relation('feePayments', ['status', 'amount', 'month']); // transaction_reference deliberately excluded

    $spec = [
        'target' => 'students',
        'filters' => [],
        'relation_filters' => [
            ['relation' => 'feePayments', 'filters' => [['field' => 'status', 'operator' => '=', 'value' => 'unpaid']]],
        ],
    ];

    $payment = $this->builder->build($spec, $definition)->first()->feePayments->first();

    expect($payment->transaction_reference)->toBeNull();
});

it('combines two filters on the same relation into one condition, not two independent ones', function () {
    // Regression test for the third-pass bug: two separate relation_filters
    // entries for the same relation became two independent EXISTS
    // subqueries — satisfiable by two *different* rows — instead of
    // requiring a single row to match both conditions.
    FeePayment::create([
        'student_id' => $this->alice->id, 'status' => 'paid', 'amount' => 50,
        'month' => '2026-06', 'transaction_reference' => 'TXN-3',
    ]);
    // Alice now has two payments: unpaid/500, and paid/50.
    // No single payment is both "paid" AND "amount > 400".

    $definition = (new QueryableDefinition('students', Student::class))
        ->columns(['id', 'name'])
        ->relation('feePayments', ['status', 'amount', 'month']);

    $spec = [
        'target' => 'students',
        'filters' => [],
        'relation_filters' => [
            ['relation' => 'feePayments', 'filters' => [['field' => 'status', 'operator' => '=', 'value' => 'paid']]],
            ['relation' => 'feePayments', 'filters' => [['field' => 'amount', 'operator' => '>', 'value' => '400']]],
        ],
    ];

    $results = $this->builder->build($spec, $definition)->get();

    // If the two conditions were wrongly split, Alice would incorrectly
    // match here (she satisfies each condition independently, just not
    // with the same row).
    expect($results)->toHaveCount(0);
});

it('produces a query that composes correctly with a real SQL count aggregate', function () {
    $definition = (new QueryableDefinition('students', Student::class))->columns(['id', 'name']);

    $spec = ['target' => 'students', 'filters' => [['field' => 'class_id', 'operator' => '=', 'value' => '5']]];

    expect($this->builder->build($spec, $definition)->count())->toBe(2);
});

it('produces a query that composes correctly with a real SQL sum aggregate', function () {
    $definition = (new QueryableDefinition('students', Student::class))
        ->columns(['id', 'name'])
        ->relation('feePayments', ['status', 'amount', 'month']);

    $spec = [
        'target' => 'students',
        'filters' => [],
        'relation_filters' => [
            ['relation' => 'feePayments', 'filters' => [['field' => 'status', 'operator' => '=', 'value' => 'unpaid']]],
        ],
    ];

    // Sums the target's own class_id here only as a stand-in numeric
    // column to prove sum() composes with the built query; real usage
    // would sum an actual amount-like column on the target.
    expect($this->builder->build($spec, $definition)->sum('class_id'))->toEqual(5);
});
