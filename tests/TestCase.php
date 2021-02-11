<?php

namespace TemperWorks\DBMask\Tests;

use Config;
use DB;
use Illuminate\Database\Connection;
use Orchestra\Testbench\TestCase as Orchestra;
use Schema;
use TemperWorks\DBMask\DBMask;

abstract class TestCase extends Orchestra
{
    protected Connection $source;
    protected Connection $materialized;
    protected Connection $masked;

    public function setUp(): void
    {
        parent::setUp();

        Config::set('dbmask.masking', ['source' => 'source', 'target' => 'masked']);
        Config::set('dbmask.materializing', ['source' => 'source', 'target' => 'materialized']);
        Config::set('dbmask.auto_include_pks', true);

        $this->source = DB::connection('source');
        $this->masked = DB::connection('masked');
        $this->materialized = DB::connection('materialized');
    }

    protected function getEnvironmentSetUp($app)
    {
        $this->resetDB();
    }

    protected function resetDB(): void
    {
        $connection = [
            'driver'   => 'mysql',
            'host' => env('DB_HOST'),
            'username' => env('DB_USERNAME'),
            'password' => env('DB_PASSWORD'),
            'port' => env('DB_PORT'),
            'charset' => 'utf8',
            'collation' => 'utf8_unicode_ci',
        ];

        Config::set('database.connections.source', $connection + ['database' => env('DB_DATABASE_SOURCE')]);

        DB::setDefaultConnection('source');
        DB::reconnect();

        Schema::dropAllTables();

        $db = env('DB_DATABASE_MASKTARGET');
        DB::statement("drop database if exists `{$db}`");
        DB::statement("create database {$db} default character set utf8 default collate utf8_unicode_ci");

        $db = env('DB_DATABASE_MATERIALIZETARGET');
        DB::statement("drop database if exists `{$db}`");
        DB::statement("create database {$db} default character set utf8 default collate utf8_unicode_ci");

        Config::set('database.connections.masked', $connection + ['database' => env('DB_DATABASE_MASKTARGET')]);
        Config::set('database.connections.materialized', $connection + ['database' => env('DB_DATABASE_MATERIALIZETARGET')]);
    }

    protected function mask()
    {
        $dbmask = new DBMask($this->source, $this->masked);
        $dbmask->mask();
    }

    protected function materialize()
    {
        $dbmask = new DBMask($this->source, $this->materialized);
        $dbmask->materialize();
    }
}
