<?php declare(strict_types=1);

namespace TemperWorks\DBMask\Tests;

use Config;
use DB;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Runs before all other tests as a sanity check
 */
class DatabaseCheck extends Orchestra
{
    public function test_it_connects()
    {
        $connection = [
            'driver'   => 'mysql',
            'host' => env('DB_HOST'),
            'username' => env('DB_USERNAME'),
            'password' => env('DB_PASSWORD'),
            'charset' => 'utf8',
            'collation' => 'utf8_unicode_ci',
        ];

        Config::set('database.connections.source', $connection + ['database' => env('DB_DATABASE_SOURCE')]);

        DB::setDefaultConnection('source');

        $databases = collect(DB::select('show databases'))->pluck('Database');
        $this->assertContains(env('DB_DATABASE_SOURCE'), $databases, 'The source schema does not exist');
    }
}
