<?php declare(strict_types=1);

namespace Tests;

use \Orchestra\Testbench\TestCase;

class DatabaseTest extends TestCase
{
    public function test_there_is_mysql()
    {
        $this->assertEquals('mysql', \DB::connection()->getDriverName());
    }
}
