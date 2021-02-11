<?php
declare(strict_types=1);

namespace TemperWorks\DBMask\Tests;

use Config;
use Hash;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Schema;
use TemperWorks\DBMask\DBMask;

class AnonymizationTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function(Blueprint $table) {
            $table->increments('id');
            $table->string('email')->nullable()->index();
            $table->string('password');
        });

        $model = fn() => new class() extends Model {
            public $table = 'users';
            public $timestamps = false;
        };

        $user = $model();
        $user->email = 'bob@example.com';
        $user->password = 'plaintext1';
        $user->save();

        $user = $model();
        $user->email = 'alice@example.com';
        $user->password = 'plaintext2';
        $user->save();
    }

    public function test_it_nulls_values()
    {
        Config::set('dbmask.tables.users', ['id', 'email' => 'null', 'password']);

        $this->mask();
        $this->materialize();

        $sourceUser = $this->source->table('users')
            ->where('email', 'bob@example.com')
            ->first();

        $this->assertNotNull($sourceUser);

        // Both in the masked & materialized DB, the id and password are equal but the email is nulled
        $anonimizedUser = $this->masked->table('users')->find($sourceUser->id);
        $this->assertEquals($sourceUser->password, $anonimizedUser->password);
        $this->assertNull($anonimizedUser->email);

        $anonimizedUser = $this->materialized->table('users')->find($sourceUser->id);
        $this->assertEquals($sourceUser->password, $anonimizedUser->password);
        $this->assertNull($anonimizedUser->email);

        // The index on the materialized DB has decreased in cardinality because all fields are nulled
        $this->assertEquals(2, $this->source->selectOne("show index from users where Key_name = 'users_email_index'")->Cardinality);
        $this->assertEquals(1, $this->materialized->selectOne("show index from users where Key_name = 'users_email_index'")->Cardinality);
    }

    public function test_it_bcrypts_values()
    {
        Config::set('dbmask.tables.users', ['id', 'email', 'password' => DBMask::bcrypt('secret')]);

        $this->mask();
        $this->materialize();

        $sourceUser = $this->source->table('users')
            ->where('email', 'bob@example.com')
            ->first();

        $this->assertNotNull($sourceUser);

        // Both in the masked & materialized DB, the password is set to the bcrypt hash of 'secret'
        $anonimizedUser = $this->masked->table('users')->find($sourceUser->id);
        $this->assertTrue(Hash::check('secret', $anonimizedUser->password));

        $anonimizedUser = $this->materialized->table('users')->find($sourceUser->id);
        $this->assertTrue(Hash::check('secret', $anonimizedUser->password));
    }
}
