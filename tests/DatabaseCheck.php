<?php declare(strict_types=1);

namespace TemperWorks\DBMask\Tests;

use DB;

/**
 * Runs before all other tests as a sanity check
 */
class DatabaseCheck extends TestCase
{
    public function test_source_and_target_exist()
    {
        $databases = collect(DB::select('show databases'))->pluck('Database');
        $this->assertContains(env('DB_DATABASE_SOURCE'), $databases, 'The source schema does not exist');
        $this->assertContains(env('DB_DATABASE_TARGET'), $databases, 'The target schema does not exist');
    }
}
