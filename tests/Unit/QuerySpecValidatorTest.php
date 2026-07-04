<?php

use Scholar\AiQuery\Exceptions\InvalidQuerySpecException;
use Scholar\AiQuery\Support\QueryableDefinition;
use Scholar\AiQuery\Support\QuerySpecValidator;
use Scholar\AiQuery\Tests\Fixtures\Models\Student;

beforeEach(function () {
    $this->definition = (new QueryableDefinition('students', Student::class))
        ->columns(['id', 'name', 'class_id'])
        ->relation('feePayments', ['status', 'amount', 'month']); // transaction_reference intentionally NOT exposed

    $this->validator = new QuerySpecValidator();
});

function assertAccepted(QuerySpecValidator $validator, array $spec, QueryableDefinition $definition): void
{
    expect(fn () => $validator->validate($spec, $definition))->not->toThrow(InvalidQuerySpecException::class);
}

function assertRejected(QuerySpecValidator $validator, array $spec, QueryableDefinition $definition): void
{
    expect(fn () => $validator->validate($spec, $definition))->toThrow(InvalidQuerySpecException::class);
}

it('accepts a well-formed spec', function () {
    assertAccepted($this->validator, [
        'target' => 'students',
        'filters' => [['field' => 'class_id', 'operator' => '=', 'value' => '5']],
    ], $this->definition);
});

it('rejects a target that does not match the queryable being asked', function () {
    assertRejected($this->validator, ['target' => 'teachers', 'filters' => []], $this->definition);
});

it('rejects a field that is not on the allow-list', function () {
    assertRejected($this->validator, [
        'target' => 'students',
        'filters' => [['field' => 'ssn', 'operator' => '=', 'value' => '123']],
    ], $this->definition);
});

it('rejects an operator that is not on the allow-list', function () {
    assertRejected($this->validator, [
        'target' => 'students',
        'filters' => [['field' => 'class_id', 'operator' => 'REGEXP', 'value' => '.*']],
    ], $this->definition);
});

it('accepts "between" with exactly two values', function () {
    assertAccepted($this->validator, [
        'target' => 'students',
        'filters' => [['field' => 'class_id', 'operator' => 'between', 'value' => '1,10']],
    ], $this->definition);
});

it('rejects "between" with the wrong number of values', function (string $value) {
    assertRejected($this->validator, [
        'target' => 'students',
        'filters' => [['field' => 'class_id', 'operator' => 'between', 'value' => $value]],
    ], $this->definition);
})->with(['5', '1,2,3', '']);

it('rejects "in" with no values', function () {
    assertRejected($this->validator, [
        'target' => 'students',
        'filters' => [['field' => 'class_id', 'operator' => 'in', 'value' => '']],
    ], $this->definition);
});

it('rejects a relation that was not registered', function () {
    assertRejected($this->validator, [
        'target' => 'students',
        'filters' => [],
        'relation_filters' => [
            ['relation' => 'documents', 'filters' => [['field' => 'title', 'operator' => '=', 'value' => 'x']]],
        ],
    ], $this->definition);
});

it('rejects a relation field that was not exposed, even though it exists on the related model', function () {
    // transaction_reference genuinely exists on FeePayment and is
    // $fillable there — it's just never added to relation() above. This
    // is the actual security boundary: existing on the model is not the
    // same as being exposed to the AI.
    assertRejected($this->validator, [
        'target' => 'students',
        'filters' => [],
        'relation_filters' => [
            ['relation' => 'feePayments', 'filters' => [['field' => 'transaction_reference', 'operator' => '=', 'value' => 'x']]],
        ],
    ], $this->definition);
});

it('accepts a relation field that was explicitly exposed', function () {
    assertAccepted($this->validator, [
        'target' => 'students',
        'filters' => [],
        'relation_filters' => [
            ['relation' => 'feePayments', 'filters' => [['field' => 'status', 'operator' => '=', 'value' => 'unpaid']]],
        ],
    ], $this->definition);
});

it('rejects sum/avg without an aggregate_field', function (string $aggregate) {
    assertRejected($this->validator, [
        'target' => 'students',
        'filters' => [],
        'aggregate' => $aggregate,
    ], $this->definition);
})->with(['sum', 'avg']);

it('rejects an aggregate_field that is not on the allow-list', function () {
    assertRejected($this->validator, [
        'target' => 'students',
        'filters' => [],
        'aggregate' => 'sum',
        'aggregate_field' => 'ssn',
    ], $this->definition);
});

it('accepts sum with a valid aggregate_field', function () {
    assertAccepted($this->validator, [
        'target' => 'students',
        'filters' => [],
        'aggregate' => 'sum',
        'aggregate_field' => 'class_id',
    ], $this->definition);
});

// Regression tests for the "less reliable LLM providers" hardening pass:
// malformed shapes should fail cleanly with InvalidQuerySpecException,
// never with a raw TypeError or a PHP notice/warning.
it('rejects malformed spec shapes instead of fataling', function (array $spec) {
    assertRejected($this->validator, $spec, $this->definition);
})->with([
    'filters is a string, not an array' => [['target' => 'students', 'filters' => 'not-an-array']],
    'relation_filters is a string, not an array' => [['target' => 'students', 'filters' => [], 'relation_filters' => 'nope']],
    'a filter entry is a string, not an object' => [['target' => 'students', 'filters' => ['just-a-string']]],
    'field is an integer' => [['target' => 'students', 'filters' => [['field' => 123, 'operator' => '=', 'value' => '1']]]],
    'operator is missing' => [['target' => 'students', 'filters' => [['field' => 'class_id', 'value' => '1']]]],
    'value is an array' => [['target' => 'students', 'filters' => [['field' => 'class_id', 'operator' => '=', 'value' => ['nope']]]]],
    'aggregate is an integer' => [['target' => 'students', 'filters' => [], 'aggregate' => 1]],
]);
