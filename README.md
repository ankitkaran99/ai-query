# AI Query

A small Laravel library for answering natural-language questions over your
own models ("which students haven't paid this month?") using
[Prism](https://prismphp.com) — **without ever generating or executing
code**. The LLM returns structured JSON describing a query; this library
validates it against an allow-list you define, then builds the Eloquent
query itself.

## Install

```bash
composer require scholar/ai-query
php artisan vendor:publish --tag=ai-query-config
```

Set your provider credentials as you would for Prism directly (e.g.
`ANTHROPIC_API_KEY` in `.env`), and optionally override the provider/model
in `config/ai-query.php`.

## Quickstart

Create one class per model under `app/AiQuery/` — it's discovered and
registered automatically, no manual `AiQuery::register()` call needed:

```php
// app/AiQuery/StudentQueryable.php
namespace App\AiQuery;

use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Scholar\AiQuery\Queryable;

class StudentQueryable extends Queryable
{
    public function model(): string
    {
        return Student::class;
    }

    public function description(): string
    {
        return 'Enrolled students, their fee payments, and attendance records';
    }

    public function columns(): ?array
    {
        return null; // auto-resolved from Student::$fillable
    }

    public function relations(): array
    {
        return [
            'feePayments' => null,                          // auto-resolved from FeePayment::$fillable
            'attendanceRecords' => ['percentage', 'month'],  // explicit override
        ];
    }

    public function scope(Builder $query): void
    {
        $query->where('school_id', tenant('id')); // never bypassable by AI output
    }
}
```

The key used to ask questions (`'students'`) is inferred from the class
name (`StudentQueryable` -> `students`) — override `key()` if you want
something else. `columns()` returning `null`, and a relation mapped to
`null`, both mean "auto-resolve from `$fillable`"; you only write things
down explicitly when you want to *restrict* below what's fillable, or when
a model has no `$fillable` set.

If you'd rather register things dynamically (e.g. conditionally, or
outside the `app/AiQuery` convention), the fluent API still works — it's
what class discovery compiles down to internally:

```php
use Scholar\AiQuery\Facades\AiQuery;
use App\Models\Student;

AiQuery::register('students', Student::class)
    ->autoColumns()                      // from Student::$fillable
    ->exposeRelation('feePayments')      // from FeePayment::$fillable
    ->exposeRelation('attendanceRecords', ['percentage', 'month']) // explicit override
    ->scope(fn ($query) => $query->where('school_id', tenant('id')));
```

Then ask questions, from either registration approach. You only pass the
prompt — the model picks which registered queryable it's about itself
(more on that below):

```php
$result = AiQuery::ask('Which students have not paid fees this month?');

$result->results;   // Illuminate\Support\Collection of Student models
$result->summary;   // "3 students have unpaid fees for the current month."
$result->spec;       // the validated query spec, including which target was chosen
$result->count();    // rows actually fetched (0 for aggregate questions — see below)
```

Questions that ask for a number rather than a list are handled as real SQL
aggregates, not by fetching everything and counting in PHP:

```php
$result = AiQuery::ask('How many students have not paid fees this month?');

$result->isAggregate();     // true
$result->aggregateType;     // 'count'
$result->aggregateValue;    // e.g. 3 — from a real SQL COUNT(*), no rows fetched
$result->results->isEmpty(); // true — rows are never pulled for aggregate questions
```

`sum`/`avg` work the same way, aggregating one allow-listed column
(`aggregate_field` in the spec) directly in SQL. Note: aggregates apply to
the target's own columns only — aggregating a relation's column (e.g. "sum
of fee amounts") isn't supported yet; that would need to run against the
related model directly.

### Custom instructions

Pass a second argument to fold extra, developer-supplied guidance into the
system prompt — things you always want honored regardless of how a
particular question is worded:

```php
AiQuery::ask(
    'Which students are absent a lot?',
    instructions: 'When no threshold is given, treat "a lot" as attendance below 75%. '
        . 'Prefer the current academic term when no date range is given.'
);
```

This is meant for **trusted, developer-controlled text** — application
policy, business rules, defaults — not for forwarding raw end-user input.
Passing untrusted text here reopens the prompt-injection surface that
having the LLM emit structured data (instead of code) otherwise closes.

### Multiple registered queryables

If you register more than one (`students`, `teachers`, `classes`, ...),
the model chooses which one a question is about from the descriptions and
columns you gave each — you never say which. That's convenient, but it is
a real tradeoff: a wrong-but-structurally-valid target (e.g. a students
question accidentally routed at "teachers" because the wording overlaps)
validates and runs fine — `QuerySpecValidator` checks that fields/operators
are allowed for *whichever* target was chosen, not that the *right* target
was chosen. Keep `description()` specific per queryable if you register
several with overlapping vocabulary, and treat `$result->spec['target']`
as worth logging if you want an audit trail of what the model actually
picked.

In a controller:

```php
Route::post('/reports/ask', function (Request $request) {
    $result = AiQuery::ask($request->string('prompt'));

    return response()->json($result->toArray());
});
```

## Why class discovery, not reflection off the model itself

It's tempting to skip declaring columns/relations entirely and just
introspect `Student::class` directly for whatever relations Eloquent
already knows about. That was deliberately not done, because "how to
navigate a relationship" (Eloquent's job) and "what's safe to expose to
something parsing untrusted natural-language input" (a security decision)
are different questions. A `Student` model will likely gain relations over
time — `documents()`, `guardianContacts()`, `disciplinaryNotes()` — that
should never become AI-queryable just because they exist. Naming a
relation in `relations()` is the actual opt-in; the columns underneath are
what get to be inferred, via `$fillable`, since fillable is already a
security-conscious allow-list you've likely defined for mass-assignment
anyway.

## Why no code generation

Earlier designs for this kind of feature often have the LLM write Eloquent
code that gets wrapped in a closure and `eval`'d, with an AST validator
(e.g. `nikic/php-parser`) checking for dangerous calls. That pattern is
fragile: an AST can block `exec()`, but it can't tell that a *syntactically
valid* Eloquent query just crossed a tenant boundary, selected a sensitive
column, or joined something it shouldn't have. Because query shapes here
are a closed set (filter + aggregate over a few models), there's no need to
accept arbitrary code at all.

This library instead has the LLM produce **data** (a JSON spec matching a
fixed schema), and every field/relation/operator in that spec is checked
against a registry you define before any query runs. `scope()` is applied
last and can never be bypassed by the model's output. There is no `eval`,
no dynamic `include`, and no code path where model output becomes
executable PHP.

## What's optimized

- **Spec caching** — identical (normalized) questions reuse a cached query
  spec instead of calling the LLM again. Configurable TTL and cache store.
- **Cached prompt schema** — the registry's human-readable description
  (sent as part of the system prompt) is rebuilt only when the registry's
  shape changes, not on every request.
- **Column-scoped selects** — queries only ever `select()` allow-listed
  columns, keeping payloads small and preventing accidental leakage of
  unregistered columns.
- **Relation loading only when referenced** — `with()` is applied solely
  for relations the spec actually filters on, avoiding N+1 queries without
  eager-loading things nobody asked about.
- **Hard row cap** — `max_rows` in config bounds every query regardless of
  what was asked, protecting against runaway result sets.
- **Lightweight discovery** — `app/AiQuery` is scanned once per boot with
  plain `glob()` + autoloaded `class_exists()` checks; a handful of files,
  negligible cost, no reflection over your whole app.

## Cache cleanup

Every cache entry AI Query writes (query specs, resolved `$fillable`
columns, the rendered prompt schema) is tagged together and shares the
`ai-query.cache.store` config value — a `store` option that used to only
affect spec caching now covers all three, so there's one place to point
somewhere else if you want AI Query's caching isolated from the rest of
your app's cache.

```bash
php artisan ai-query:clear-cache
```

Worth understanding *why* this exists, not just that it does: the spec
cache key deliberately includes the current date (so a cached "what's due
today" doesn't get served after midnight), which means a **new** cache
entry is created every day for every distinct question asked. On
Redis/Memcached, that's fine — TTL expiry reclaims it automatically. On
Laravel's `file` or `database` cache drivers, an entry's expiration is
only checked when something reads that exact key again; if nobody does,
it just sits there. Correctness isn't affected either way (an expired
entry is never served), but disk/table space can grow unless something
proactively clears it — which is what this command is for.

Schedule it if you want that reclaimed automatically:

```php
// routes/console.php (Laravel 11+) or app/Console/Kernel.php
Schedule::command('ai-query:clear-cache')->daily();
```

One real constraint, stated plainly rather than papered over: Laravel can
only do a *selective* flush (clearing just AI Query's entries, leaving the
rest of your app's cache alone) on stores that support tagging — Redis,
Memcached, and the array driver. The `file` and `database` drivers don't
implement tagging at all, and `file` in particular hashes keys into opaque
filenames, so there's no way to find "just AI Query's files" without
tracking every key ourselves. On those drivers, the command reports that
it couldn't do a selective flush and suggests either `php artisan
cache:clear` (clears everything, not just AI Query) or switching
`AI_QUERY_CACHE_STORE` to a taggable store.

## API changes

- **`ask()` no longer takes a target argument.** It used to be
  `ask($prompt, 'students')`; now it's just `ask($prompt)`, and an optional
  `ask($prompt, $instructions)` for developer-supplied guidance. The model
  was already being asked to choose a `target` from an enum of every
  registered key as part of the structured output — the target argument
  was actually redundant with that, just used to force a single choice.
  Dropping it means the model now genuinely picks from everything you've
  registered; see "Multiple registered queryables" above for the tradeoff
  that comes with that (a wrong-but-valid target isn't something
  `QuerySpecValidator` can catch).

## Bug fixes / optimizations changelog

- **Aggregate handling was a no-op.** The LLM's spec always included an
  `aggregate` field (`count`/`list`/`sum`/`avg`), but nothing in the code
  ever read it — every question, including "how many," fetched full rows
  via `->get()`. `count`/`sum`/`avg` now run as real SQL aggregates and
  never fetch rows at all. `sum`/`avg` gained a required, allow-listed
  `aggregate_field` (previously present in the enum with no way to say
  *which* column to aggregate).
- **Eager-loaded relation columns could silently break hydration.** The
  `select()` applied to a `with()` eager load hardcoded `'id'` as the only
  extra column kept. For a `HasMany`/`HasOne`, Eloquent actually needs the
  *foreign key on the related table* to re-attach rows to their parent —
  without it, the relation comes back empty even when matching rows exist,
  with no error. Now resolved per relation type (`HasOneOrMany` ->
  foreign key, `BelongsTo` -> owner key).
- **`between`/`in` values weren't shape-checked.** A malformed value (e.g.
  one value for `between`) reached `whereBetween()`/`whereIn()` directly,
  producing a wrong-but-not-failed query instead of a clear validation
  error. Now checked in `QuerySpecValidator` before any query is built.
- **Reflection work repeated on every request.** `autoColumns()`,
  `exposeRelation()`, and class discovery all resolve columns via
  `$model->getFillable()` / relation instantiation inside
  `AiQueryServiceProvider::boot()`, which runs on every request whether or
  not `AiQuery::ask()` is ever called. That resolution is now cached
  (`Cache::remember`, 24h TTL) the same way `promptSchema()` already was.
- **`AiQueryResult::count()` was ambiguous for aggregates.** Split into
  `count()` (rows actually fetched — always 0 for aggregate results) and
  `aggregateValue`/`isAggregate()` (the real count/sum/avg number), so
  callers can't misread one for the other.

### Second pass

- **A single bad `app/AiQuery` class could 500 the entire app.**
  Discovery runs in `ServiceProvider::boot()`, which fires on every
  request. An uncaught exception while resolving one queryable (typo'd
  relation name, a model not migrated yet on a fresh install) previously
  took down every page, not just AI Query. Each class is now resolved in
  isolation with `report()` on failure instead of letting it bubble.
- **`newInstance()` bypassed the container.** Discovered `Queryable`
  classes are now resolved via `$this->app->make()`, so constructor
  dependencies (a repository, the current tenant, anything typehinted)
  work the normal Laravel way instead of erroring.
- **Column cache could outlive a security-relevant deploy.** The 24h
  column-resolution cache added in the first pass had no invalidation —
  removing a column from `$fillable` specifically to hide it from the AI
  could still leave it exposed for up to a day post-deploy. Cache keys now
  include the model file's mtime, so editing/redeploying the model busts
  the cache immediately (works in local dev too, since saving the file
  changes its mtime).
- **Relative-date prompts could outlive their correctness.** A cached spec
  for "today"/"this month" bakes in a concrete resolved date. With a long
  TTL (some deployments raise it for cost), that date could still be
  served after the day rolls over. The spec cache key now includes the
  current date, so this can't happen regardless of configured TTL.

### Third pass

- **Two filters on the same relation could silently drop each other, or
  change meaning.** If the spec had two separate `relation_filters` entries
  for the same relation (e.g. status=unpaid *and* amount>100 on
  `feePayments`), they became two independent `whereHas()` EXISTS
  subqueries — satisfiable by two *different* rows instead of requiring
  one row to match both — and two `with()` calls for the same relation
  silently overwrote each other, so the first constraint's column
  restriction vanished. Same-relation filters are now merged into one
  EXISTS subquery / one `with()` call before building.
- **`avg` of zero matching rows was reported as 0, not "no data".**
  Eloquent's `sum()` coalesces an empty result to `0` internally, but
  `avg()` does not — `AVG()` over zero rows is `NULL` in SQL and Eloquent
  passes that through untouched. Casting it to `(float)` silently turned
  "nothing to average" into a misleading "average is 0". `AiQueryResult`
  now tracks `aggregateType` separately from `aggregateValue`, so a null
  average is distinguishable from "this wasn't an aggregate query" and
  from "the average genuinely is 0".

### Fourth pass

- **Validator assumed well-shaped input.** Every check assumed `filters`
  was genuinely an array, each filter genuinely an object with a string
  `field`/`operator`, etc. That's a safe assumption for Claude's
  structured output, but this library explicitly supports weaker/local
  providers too (Ollama, DeepSeek, OpenRouter) that are less consistent
  about strictly following a JSON schema. A malformed response previously
  risked a raw PHP `TypeError` or an `Array to string conversion` warning
  deep inside validation instead of a clean, catchable
  `InvalidQuerySpecException`. Every level is now shape-checked explicitly
  before its contents are read.
- **Validation could be bypassed by a stale cache entry.** It only ran on
  a cache miss. The cache key is scoped to the registry's *data*
  fingerprint (columns/relations), but not to the validation *logic*
  itself — a library update that tightens a rule (e.g. removing an
  operator) wouldn't invalidate previously-cached specs, so they'd keep
  bypassing the new rule until natural TTL expiry. Validation is pure and
  cheap, so it now always runs, cache hit or not.

### Fifth pass

- **`composer.json` had an invalid version constraint.** `"prism-php/prism":
  "^0.x"` mixes caret (`^`, which needs a concrete version) with a `.x`
  wildcard (which needs a plain prefix) — the two aren't combinable.
  `composer require`/`composer install` would fail to parse this and
  refuse to install the package at all, which would have made every fix
  in every earlier pass moot. Changed to `"^0.1|^1.0"`.

### Sixth pass

- **A cache-busting fix from an earlier pass had a gap of its own.**
  `resolveRelationColumns()` cached its result keyed only by the *parent*
  model's file version (e.g. `Student.php`) — but the value cached is the
  *related* model's columns (e.g. `FeePayment`'s `$fillable`). Editing
  `FeePayment` to remove a column — the exact scenario that fix was
  supposed to protect — left `Student.php` untouched, so the cache never
  busted and kept serving the stale, wider list. Removed the redundant
  outer cache entirely; the inner `resolveModelColumns()` cache (correctly
  keyed by the actual related class) already covers this, and relation
  instantiation itself is cheap enough not to need its own cache layer.
- **Column-scoped eager loading was only verified for three relation
  types.** `HasOneOrMany`, `BelongsTo`, and `BelongsToMany` have documented
  key-column requirements this was checked against; `HasManyThrough` and
  polymorphic `MorphTo` (both plausible in a School ERP — e.g. students
  through enrollments to classes) don't, and guessing at their internals
  from memory without a way to execute and verify risked silently
  reintroducing the exact hydration bug from the first pass, just for a
  rarer relation type. Any relation type not explicitly verified now falls
  back to an unrestricted `with()` — correct always, optimized only where
  actually confirmed safe.

### Seventh pass (reviewing the cache-cleanup feature itself)

- **The taggability probe re-threw an exception on every single cache
  operation, not just at boot.** `SpecCache::get()`/`put()` run on every
  AI query; each call was re-attempting `Cache::tags()` and catching the
  failure fresh on non-taggable stores (file/database) — real overhead
  for something that's a fixed property of your config, not something
  that changes per request. Memoized per store name now; a
  `resetMemoizedTaggability()` escape hatch exists for long-lived workers
  (Octane/RoadRunner) that reconfigure cache connections mid-process.
- **Naming collision with Laravel's own cache internals.** The helper was
  originally named `TaggedCache` — but `Illuminate\Cache\Repository::tags()`
  itself returns an instance of `Illuminate\Cache\TaggedCache`. Different
  namespaces, so not a compile error, but a genuinely confusing collision
  for anyone grepping the codebase later. Renamed to `AiQueryCache`.

### Eighth pass — the first bug caught by actually running the suite

Every fix up to this point came from reading code, not executing it (this
environment has no PHP interpreter). This one is different: it was caught
by `composer test` against a real Laravel app, and it's exactly the kind
of thing static reading kept flagging as a risk without being able to
confirm.

- **`TypeError` on every relation eager load.** Eloquent's `with(['relation'
  => function ($q) { ... }])` closure form passes the **Relation** instance
  itself (e.g. `HasMany`) to the closure — not a `Builder`, which is what
  `whereHas()`'s closure receives. The eager-load closure here was typed
  `Builder $q`, so real execution hit `TypeError: Argument #1 ($q) must be
  of type Illuminate\Database\Eloquent\Builder, Illuminate\Database\Eloquent\Relations\HasMany given`
  on every single restricted-column relation load — which is to say, on
  exactly the code path the first pass's regression tests were written to
  protect. `Relation` forwards `where()`/`select()` to its underlying query
  via `__call`, so the logic itself was fine; retyped the parameter to
  `Relation`, which is both accurate and still type-safe (as opposed to
  removing the hint entirely).

This is the strongest evidence in this whole changelog for why "read the
code again" has a ceiling that running it doesn't.


### Token usage

Every `AiQueryResult` carries the token cost of producing it, summed across
every underlying Prism call (spec generation, plus the optional summary
call) — zero for the spec-generation part of a cache hit, since no LLM
call happened for it that time:

```php
$result = AiQuery::ask('Which students have not paid fees this month?');

$result->tokenUsage->promptTokens;
$result->tokenUsage->completionTokens;
$result->tokenUsage->totalTokens();
$result->toArray()['token_usage']; // ['prompt_tokens' => ..., 'completion_tokens' => ..., 'total_tokens' => ...]
```

Field names match Prism's own response object (`$response->usage->promptTokens`,
confirmed against Prism's documentation rather than assumed).

## Testing

```bash
composer install
composer test
```

The suite uses [Pest](https://pestphp.com) + [Orchestra Testbench](https://packages.tools/testbench),
with an in-memory SQLite database and fixture `Student`/`FeePayment`/
`AttendanceRecord` models under `tests/Fixtures`.

What's covered:

- **`QuerySpecValidator`** — every allow/reject rule (unregistered field,
  disallowed operator, malformed `between`/`in` values, unregistered
  relation, an aggregate missing its `aggregate_field`) plus the
  malformed-shape hardening pass (non-array `filters`, a filter that's a
  string instead of an object, a non-scalar value) — asserting each fails
  with `InvalidQuerySpecException`, not a raw PHP error.
- **`QueryBuilder`**, against a real database — direct filters, that
  `scope()` is applied even when the spec never mentions it, that a
  restricted-column eager load on a `hasMany` actually hydrates (the
  first-pass regression), that an unregistered relation column never
  leaks through, that two `relation_filters` on the same relation combine
  into one condition rather than two independent ones (the third-pass
  regression), and that the built query composes with real `count()`/
  `sum()` calls.
- **`AiQueryResult`** — the `aggregateType`/`aggregateValue` split, in
  particular that a `null` average (zero matching rows) is distinguished
  from both a `0` count and "not an aggregate at all."
- **`QueryableDefinition`** — `autoColumns()`/`exposeRelation()` resolving
  correctly from `$fillable`, error handling for a nonexistent or
  non-relation method, and that the file-mtime cache-busting fingerprint
  actually changes when a file's mtime changes.
- **`QueryableRegistry`** — fingerprint stability/change semantics and the
  unknown-key exception.
- **`AiQueryCache` / `ai-query:clear-cache`** — that tagged writes are
  readable, that the command clears AI Query's own entries without
  touching unrelated cache keys, and that a flush failure (unsupported or
  undefined store) degrades to `false` rather than an uncaught exception.
  The array driver used throughout this suite does support tagging, so
  the "file/database can't selectively flush" behavior described in the
  README is reasoned about, not exercised here directly — reproducing
  that would need an actual file/database cache store configured in the
  test environment.

What's **not** covered, deliberately: `AiQueryService::ask()` end-to-end,
`SpecGenerator`, and anything that goes through Prism. That requires
either a live API key or mocking Prism's own testing utilities, which
this suite intentionally avoids asserting the shape of since it wasn't
verified against a running instance. If you wire up `Prism::fake()` (or
equivalent) in your own app, a small `AiQueryServiceTest` that stubs
`SpecGenerator::generate()` to return a hand-built spec would close that
last gap — the validate → build → aggregate branching in `ask()` is
straightforward to test once the LLM call itself is out of the picture.

## Extending

- Add more operators/aggregates by extending `QuerySpecValidator` and the
  schema in `SpecGenerator`.
- Swap providers per call by registering multiple `AiQueryService`
  instances with different config, if you need e.g. a cheaper model for
  simple lookups and a stronger one for complex reports.
- Log every `$result->spec` for an audit trail of what the AI actually
  queried — this is cheap since the spec is small, structured JSON.
- Disable discovery (`AI_QUERY_DISCOVERY_ENABLED=false`) and register
  everything fluently if you'd rather keep it all in one place.
