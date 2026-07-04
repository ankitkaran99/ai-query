<?php

namespace Scholar\AiQuery;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Base class for declaring what a model exposes to AI Query.
 *
 * Create one subclass per model under app/AiQuery (e.g. StudentQueryable)
 * and it is discovered and registered automatically — no manual
 * AiQuery::register() call needed.
 */
abstract class Queryable
{
    /** The Eloquent model class this queryable exposes. */
    abstract public function model(): string;

    /**
     * The key used when asking questions, e.g. AiQuery::ask($prompt, 'students').
     * Defaults to a snake_case, pluralized guess from the class name
     * (StudentQueryable -> "students"). Override to be explicit.
     */
    public function key(): string
    {
        $base = class_basename(static::class);
        $base = str_ends_with($base, 'Queryable') ? substr($base, 0, -9) : $base;

        return Str::snake(Str::plural($base));
    }

    /** Optional human description folded into the LLM system prompt. */
    public function description(): string
    {
        return '';
    }

    /**
     * Columns the AI may filter/select on. Return null (the default) to
     * auto-resolve from the model's $fillable (falls back to $visible).
     */
    public function columns(): ?array
    {
        return null;
    }

    /**
     * Relations the AI may filter through, mapped to allowed columns.
     * A null value auto-resolves from the related model's $fillable.
     *
     *   return [
     *       'feePayments' => null,                          // auto-resolved
     *       'attendanceRecords' => ['percentage', 'month'],  // explicit
     *   ];
     */
    public function relations(): array
    {
        return [];
    }

    /**
     * Applied to every query built for this queryable, after all AI-provided
     * filters. Use this for tenant scoping, soft-delete rules, etc. — it
     * cannot be bypassed by anything the model returns.
     */
    public function scope(Builder $query): void
    {
        //
    }
}
