<?php

namespace Scholar\AiQuery\Support;

/**
 * Identical (or near-identical, after normalization) questions map to the
 * same query spec. Caching that mapping is the single biggest cost/latency
 * win available here — it turns a repeat question into zero LLM calls.
 */
final class SpecCache
{
    public function __construct(private readonly array $config)
    {
    }

    public function get(string $prompt, string $registryFingerprint, ?string $instructions = null): ?array
    {
        if (! $this->config['enabled']) {
            return null;
        }

        return AiQueryCache::get($this->store(), $this->key($prompt, $registryFingerprint, $instructions));
    }

    public function put(string $prompt, string $registryFingerprint, array $spec, ?string $instructions = null): void
    {
        if (! $this->config['enabled']) {
            return;
        }

        AiQueryCache::put(
            $this->store(),
            $this->key($prompt, $registryFingerprint, $instructions),
            $spec,
            $this->config['ttl']
        );
    }

    private function store(): ?string
    {
        return $this->config['store'] ?? null;
    }

    private function key(string $prompt, string $fingerprint, ?string $instructions): string
    {
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $prompt) ?? $prompt));

        // A question like "how many students are absent today" gets
        // resolved by the LLM into a concrete date inside the cached spec.
        // Scoping the key to today's date means a long TTL can't serve
        // yesterday's resolved date past midnight — but it also means a
        // fresh key is created every day for every distinct question. On
        // Redis/Memcached that's fine (TTL expiry reclaims it). On the
        // file/database drivers, nothing proactively deletes it once
        // unreachable — see ClearCacheCommand for reclaiming that space.
        $instructionsHash = ($instructions !== null && trim($instructions) !== '')
            ? sha1(strtolower(trim($instructions)))
            : '0';

        return "ai-query:spec:{$fingerprint}:" . now()->toDateString() . ":{$instructionsHash}:" . sha1($normalized);
    }
}
