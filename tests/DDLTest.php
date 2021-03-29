<?php
declare(strict_types=1);

namespace TemperWorks\DBMask\Tests;

use Config;
use Illuminate\Database\Schema\Blueprint;
use Schema;

class DDLTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function(Blueprint $table) {
            $table->increments('id');
            $table->string('email')->index();
            $table->string('password');
        });
    }

    public function test_it_masks()
    {
        Config::set('dbmask.tables.users', ['id', 'email', 'password']);

        $this->mask();

        // Expect only one VIEW in the target, with 3 columns
        $views = collect($this->masked->select("show full tables where table_type like 'VIEW'"));

        $this->assertEquals(1, $views->count());
        $this->assertEquals('users', $views->first()->Tables_in_masked);
        $this->assertEquals('VIEW', $views->first()->Table_type);

        $columns = collect($this->masked->select("show columns from users"));
        $this->assertEquals(3, $columns->count());
        $this->assertEquals(['id', 'email', 'password'], $columns->pluck('Field')->toArray());

        // Views do not have indexes
        $indexes = collect($this->masked->select("show index from users"));
        $this->assertEquals(0, $indexes->count());
    }

    public function test_it_materializes()
    {
        Config::set('dbmask.tables.users', ['id', 'email', 'password']);

        $this->materialize();

        // Expect only one TABLE in the target, with 3 columns
        $tables = collect($this->materialized->select("show full tables where table_type like 'BASE TABLE'"));
        $this->assertEquals(1, $tables->count());
        $this->assertEquals('users', $tables->first()->Tables_in_materialized);
        $this->assertEquals('BASE TABLE', $tables->first()->Table_type);

        $columns = collect($this->materialized->select("show columns from users"));
        $this->assertEquals(3, $columns->count());
        $this->assertEquals(['id', 'email', 'password'], $columns->pluck('Field')->toArray());

        // Indexes are present on the materialized copy
        $indexes = collect($this->materialized->select("show index from users"));
        $this->assertEquals(2, $indexes->count());
        $this->assertEquals(['PRIMARY', 'users_email_index'], $indexes->pluck('Key_name')->toArray());
        $this->assertEquals(['id', 'email'], $indexes->pluck('Column_name')->toArray());
    }
}
