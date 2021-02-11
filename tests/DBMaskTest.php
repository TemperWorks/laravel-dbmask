<?php
declare(strict_types=1);


namespace TemperWorks\DBMask\Tests;


use Config;
use DB;
use Illuminate\Database\Schema\Blueprint;
use Schema;
use TemperWorks\DBMask\DBMask;

class DBMaskTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        Config::set('dbmask.tables.users', [
            'id',
            'email' => "concat(first_name,'@example.com')",
            'first_name',
            'last_name' => "'Smith'"
        ]);

        Schema::create('users', function(Blueprint $table) {
            $table->increments('id');
            $table->string('email');
            $table->string('first_name');
            $table->string('last_name');
        });
    }

    public function test_it_masks()
    {
        $dbmask = new DBMask($this->source, $this->target);
        $dbmask->mask();

//        $views = collect(DB::connection('target')->select("show full tables where table_type like 'VIEW';"))->first();
//
//        $this->assertEquals('users', $views->Tables_in_target);
//        $this->assertEquals('VIEW', $views->Table_type);
    }

    public function test_it_materializes()
    {
        $dbmask = new DBMask($this->source, $this->target);
        $dbmask->materialize();

//        $views = collect(DB::connection('target')->select("show full tables where table_type like 'BASE TABLE';"))->first();
//
//        $this->assertEquals('users', $views->Tables_in_target);
//        $this->assertEquals('BASE TABLE', $views->Table_type);
    }


}
