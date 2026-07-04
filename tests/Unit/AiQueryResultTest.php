<?php

use Scholar\AiQuery\AiQueryResult;

it('is not an aggregate when no aggregate type is set', function () {
    $result = new AiQueryResult('q', [], collect(), null, false);

    expect($result->isAggregate())->toBeFalse();
});

it('treats a count of zero as empty', function () {
    $result = new AiQueryResult('q', [], collect(), null, false, 'count', 0);

    expect($result->isEmpty())->toBeTrue();
});

it('does not treat a sum of zero as empty', function () {
    // A sum of 0 can be a legitimate result over real rows (e.g. sum of
    // waived fees might genuinely be 0) — unlike avg, SUM() over zero
    // matching rows is not what this represents.
    $result = new AiQueryResult('q', [], collect(), null, false, 'sum', 0.0);

    expect($result->isEmpty())->toBeFalse();
});

it('treats a null avg as empty, since AVG() over zero rows is NULL in SQL', function () {
    $result = new AiQueryResult('q', [], collect(), null, false, 'avg', null);

    expect($result->isEmpty())->toBeTrue();
});

it('still reports isAggregate() as true for a null avg', function () {
    // Regression test: aggregate-ness is tracked via aggregateType, not
    // by checking whether aggregateValue is non-null — otherwise a
    // zero-row average would be indistinguishable from "not an aggregate
    // query at all".
    $result = new AiQueryResult('q', [], collect(), null, false, 'avg', null);

    expect($result->isAggregate())->toBeTrue();
});

it('reports the number of fetched rows as zero for any aggregate result', function () {
    $result = new AiQueryResult('q', [], collect(), null, false, 'count', 42);

    expect($result->count())->toBe(0); // rows were never fetched for a count query
});

it('falls back to checking the results collection when there is no aggregate', function () {
    $empty = new AiQueryResult('q', [], collect(), null, false);
    $nonEmpty = new AiQueryResult('q', [], collect([1, 2]), null, false);

    expect($empty->isEmpty())->toBeTrue()
        ->and($nonEmpty->isEmpty())->toBeFalse();
});
