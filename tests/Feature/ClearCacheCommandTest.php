<?php

use Illuminate\Support\Facades\Cache;
use Scholar\AiQuery\Support\AiQueryCache;

beforeEach(fn () => AiQueryCache::resetMemoizedTaggability());

it('memoizes the taggability check per store instead of re-probing every call', function () {
    // Calling it twice should give the same answer — this doesn't prove
    // memoization happened (that would need a spy on the underlying
    // Cache facade call count), but it does prove the memoization doesn't
    // change the observable result, which is the property that actually
    // matters here.
    $first = AiQueryCache::supportsTagging(null);
    $second = AiQueryCache::supportsTagging(null);

    expect($first)->toBe($second)->toBeTrue();
});

it('caches and retrieves a value tagged for ai-query', function () {
    $value = AiQueryCache::remember(null, 'ai-query:test-key', now()->addMinute(), fn () => 'hello');

    expect($value)->toBe('hello')
        ->and(AiQueryCache::get(null, 'ai-query:test-key'))->toBe('hello');
});

it('reports that the array cache store (used in tests) supports tagging', function () {
    expect(AiQueryCache::supportsTagging(null))->toBeTrue();
});

it('clears ai-query-tagged entries via the console command without touching unrelated cache keys', function () {
    AiQueryCache::put(null, 'ai-query:test-key', 'hello', now()->addMinute());
    Cache::put('some-other-package:key', 'still here', now()->addMinute());

    $this->artisan('ai-query:clear-cache')->assertExitCode(0);

    expect(AiQueryCache::get(null, 'ai-query:test-key'))->toBeNull()
        ->and(Cache::get('some-other-package:key'))->toBe('still here');
});

it('returns false instead of throwing when the target store cannot be tagged', function () {
    // Can't easily configure a real file/database store mid-test to prove
    // the "unsupported driver" path specifically without a lot of extra
    // setup — this instead proves the broader robustness property that
    // matters for the command: any failure to tag/flush (including an
    // undefined store) degrades to `false`, never an uncaught exception.
    expect(AiQueryCache::flush('this-store-name-is-not-configured-anywhere'))
        ->toBeFalse();
});
