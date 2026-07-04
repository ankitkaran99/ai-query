<?php

use Scholar\AiQuery\Exceptions\UnknownQueryableException;
use Scholar\AiQuery\QueryableRegistry;
use Scholar\AiQuery\Tests\Fixtures\Models\Student;

it('throws a clear exception for an unregistered key', function () {
    $registry = new QueryableRegistry();

    expect(fn () => $registry->get('ghost'))->toThrow(UnknownQueryableException::class);
});

it('returns a registered definition by key', function () {
    $registry = new QueryableRegistry();
    $registry->register('students', Student::class)->columns(['id', 'name']);

    expect($registry->get('students')->modelClass)->toBe(Student::class);
});

it('changes its fingerprint when a registered definition changes shape', function () {
    $registryA = new QueryableRegistry();
    $registryA->register('students', Student::class)->columns(['id', 'name']);

    $registryB = new QueryableRegistry();
    $registryB->register('students', Student::class)->columns(['id', 'name', 'class_id']);

    expect($registryA->fingerprint())->not->toBe($registryB->fingerprint());
});

it('keeps the same fingerprint when nothing about the shape changed', function () {
    $registryA = new QueryableRegistry();
    $registryA->register('students', Student::class)->columns(['id', 'name']);

    $registryB = new QueryableRegistry();
    $registryB->register('students', Student::class)->columns(['id', 'name']);

    expect($registryA->fingerprint())->toBe($registryB->fingerprint());
});
