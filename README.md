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
`AttendanceRecord` models under `tests/Fixtures`. As of the eighth
changelog pass below, this passes fully against a real Laravel app: 59
tests, 74 assertions, 0 failures.

If you see `Constant PDO::MYSQL_ATTR_SSL_CA is deprecated` noise, that's
`orchestra/testbench-core`'s own default skeleton config evaluating a
MySQL connection default even though `TestCase::getEnvironmentSetUp()`
overrides `database.default` to sqlite — not this package's code.
`phpunit.xml.dist` sets `error_reporting` to suppress `E_DEPRECATED` for
test output clarity; it doesn't change what actually runs.

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
