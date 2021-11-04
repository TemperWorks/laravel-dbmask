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

    public function test_it_conditionally_anonymizes_values()
    {
        Config::set('dbmask.tables.users', [
            'id',
            'email' => 'if(email is null, null, concat("user_", id, "@example.com"))',
            'password'
        ]);

        $this->source->table('users')
            ->where('email', 'bob@example.com')
            ->update(['email' => null]);

        $this->mask();
        $this->materialize();

        // Both in the masked & materialized DB, bob's email stays null while alice's email is masked
        $anonimizedUsers = $this->masked->table('users')->get();
        $this->assertEquals(null, $anonimizedUsers->firstWhere('password', 'plaintext1')->email);
        $this->assertEquals('user_2@example.com', $anonimizedUsers->firstWhere('password', 'plaintext2')->email);

        $anonimizedUsers = $this->materialized->table('users')->get();
        $this->assertEquals(null, $anonimizedUsers->firstWhere('password', 'plaintext1')->email);
        $this->assertEquals('user_2@example.com', $anonimizedUsers->firstWhere('password', 'plaintext2')->email);
    }

    public function test_it_partially_anonymizes_generated_columns()
    {
        Schema::table('users', function(Blueprint $table) {
            $table->string('email_uppercase_generated')
                ->storedAs('upper(email)');
        });

        Config::set('dbmask.tables.users', [
            'id',
            'email' => 'concat("user_", id, "@example.com")',
            'email_uppercase_generated',
            'password'
        ]);

        $this->mask();
        $this->materialize();

        // Both in the masked & materialized DB, bob's email stays null while alice's email is masked
        $anonimizedUsers = $this->masked->table('users')->get();
        $this->assertEquals('user_1@example.com', $anonimizedUsers->firstWhere('password', 'plaintext1')->email);
        $this->assertEquals('BOB@EXAMPLE.COM', $anonimizedUsers->firstWhere('password', 'plaintext1')->email_uppercase_generated);

        $anonimizedUsers = $this->materialized->table('users')->get();
        $this->assertEquals('user_1@example.com', $anonimizedUsers->firstWhere('password', 'plaintext1')->email);
        $this->assertEquals('USER_1@EXAMPLE.COM', $anonimizedUsers->firstWhere('password', 'plaintext1')->email_uppercase_generated);
    }

    public function test_it_anonymizes_views()
    {
        $this->source->statement("create view users_view as select id, upper(email) as email, password from users");

        // Anonimization happens in the regular table
        Config::set('dbmask.tables.users', [
            'id',
            'email' => 'concat("user_", id, "@example.com")',
            'password'
        ]);

        // Anonymize the source view
        Config::set('dbmask.tables.users_view');

        $this->mask();
        $this->materialize();

        $anonimizedUsers = $this->masked->table('users_view')->get();
        $this->assertEquals('USER_1@EXAMPLE.COM', $anonimizedUsers->firstWhere('password', 'plaintext1')->email);

        $anonimizedUsers = $this->materialized->table('users_view')->get();
        $this->assertEquals('USER_1@EXAMPLE.COM', $anonimizedUsers->firstWhere('password', 'plaintext1')->email);
    }

    public function test_it_validates_generated_sql()
    {
        Config::set('dbmask.tables.users', ['id', 'email', 'password']);
        $validation = $this->validate();
        $this->assertEmpty($validation, $validation->toJson(JSON_PRETTY_PRINT));

        // SQL null is allowed (will set masked column to null).
        // SQL expressions & functions like encrypt are also allowed
        Config::set('dbmask.tables.users', ['id' => 'id + 1', 'email' => 'null', 'password' => 'encrypt(password)']);
        $validation = $this->validate();
        $this->assertEmpty($validation, $validation->toJson(JSON_PRETTY_PRINT));

        // Incrementors are not valid SQL expressions
        Config::set('dbmask.tables.users', ['id' => 'id++', 'email', 'password']);
        $validation = $this->validate();
        $this->assertNotEmpty($validation, $validation->toJson(JSON_PRETTY_PRINT));

        // Type in encrypt, should not pass validation
        Config::set('dbmask.tables.users', ['id', 'email' => 'encyrpt(email)', 'password']);
        $validation = $this->validate();
        $this->assertNotEmpty($validation, $validation->toJson(JSON_PRETTY_PRINT));

        // PHP null is not allowed as a column masking definition, SQL null should be quoted
        Config::set('dbmask.tables.users', ['id', 'email' => null, 'password']);
        $validation = $this->validate();
        $this->assertNotEmpty($validation, $validation->toJson(JSON_PRETTY_PRINT));

        // There is no column passwords in users
        Config::set('dbmask.tables.users', ['id', 'email', 'passwords']);
        $validation = $this->validate();
        $this->assertNotEmpty($validation, $validation->toJson(JSON_PRETTY_PRINT));
    }
}
