<?php

namespace Scholar\AiQuery\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Scholar\AiQuery\AiQueryServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [AiQueryServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        // Array cache keeps tests fast and isolated from each other by
        // default; individual tests override this when they specifically
        // need to exercise caching behavior.
        $app['config']->set('cache.default', 'array');

        // Discovery scans app_path('AiQuery'), which doesn't exist in this
        // package's own test app skeleton — keep it off so boot() doesn't
        // do pointless directory checks on every test.
        $app['config']->set('ai-query.discovery.enabled', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->migrateFixtures();
    }

    private function migrateFixtures(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('class_id')->nullable();
            $table->string('admission_no')->nullable();
            $table->string('school_id')->default('school-a');
            $table->timestamps();
        });

        Schema::create('fee_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained();
            $table->string('status');
            $table->decimal('amount', 10, 2);
            $table->string('month');
            $table->string('transaction_reference')->nullable();
            $table->timestamps();
        });

        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained();
            $table->unsignedTinyInteger('percentage');
            $table->string('month');
            $table->timestamps();
        });
    }
}
