<?php

namespace TemperWorks\DBMask\Tests;

use Config;
use DB;
use Illuminate\Database\Connection;
use Orchestra\Testbench\TestCase as Orchestra;
use Schema;

abstract class TestCase extends Orchestra
{
    protected Connection $source;
    protected Connection $target;

    public function setUp(): void
    {
        parent::setUp();

        $this->source = DB::connection('source');
        $this->target = DB::connection('target');
    }

    protected function getEnvironmentSetUp($app)
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
        Schema::dropAllTables();

        DB::connection()->getSchemaBuilder()->dropDatabaseIfExists(env('DB_DATABASE_TARGET'));
        DB::connection()->getSchemaBuilder()->createDatabase(env('DB_DATABASE_TARGET'));

        Config::set('database.connections.target', $connection + ['database' => env('DB_DATABASE_TARGET')]);

        Config::set('dbmask.masking', ['source' => 'source', 'target' => 'target']);
        Config::set('dbmask.materializing', ['source' => 'source', 'target' => 'target']);
    }
}
