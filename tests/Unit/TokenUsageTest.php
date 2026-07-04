<?php

use Scholar\AiQuery\Support\TokenUsage;

it('sums prompt and completion tokens for totalTokens()', function () {
    $usage = new TokenUsage(promptTokens: 100, completionTokens: 40);

    expect($usage->totalTokens())->toBe(140);
});

it('adds two usages together', function () {
    $a = new TokenUsage(promptTokens: 100, completionTokens: 40);
    $b = new TokenUsage(promptTokens: 30, completionTokens: 10);

    $sum = $a->add($b);

    expect($sum->promptTokens)->toBe(130)
        ->and($sum->completionTokens)->toBe(50)
        ->and($sum->totalTokens())->toBe(180);
});

it('represents "no usage" as all zeros', function () {
    $none = TokenUsage::none();

    expect($none->promptTokens)->toBe(0)
        ->and($none->completionTokens)->toBe(0)
        ->and($none->totalTokens())->toBe(0);
});

it('extracts usage from a Prism-shaped response object', function () {
    $response = (object) ['usage' => (object) ['promptTokens' => 250, 'completionTokens' => 60]];

    $usage = TokenUsage::fromPrismResponse($response);

    expect($usage->promptTokens)->toBe(250)
        ->and($usage->completionTokens)->toBe(60)
        ->and($usage->totalTokens())->toBe(310);
});

it('tolerates a response with no usage property instead of throwing', function () {
    $response = (object) ['structured' => ['foo' => 'bar']]; // no ->usage at all

    expect(TokenUsage::fromPrismResponse($response)->totalTokens())->toBe(0);
});

it('serializes to a flat array with a computed total', function () {
    $usage = new TokenUsage(promptTokens: 10, completionTokens: 5);

    expect($usage->toArray())->toBe([
        'prompt_tokens' => 10,
        'completion_tokens' => 5,
        'total_tokens' => 15,
    ]);
});
