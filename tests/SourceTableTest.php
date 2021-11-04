<?php


namespace TemperWorks\DBMask\Tests;

use Config;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Schema;
use TemperWorks\DBMask\SourceTable;

class SourceTableTest extends TestCase
{
    public function test_it_finds_special_columns()
    {
        Schema::create('table', function(Blueprint $table) {
            $table->increments('id');
            $table->timestamps();
        });

        $sourceTable = new SourceTable($this->source, 'table', []);

        $this->assertEquals(['id'], $sourceTable->getPKColumns()->toArray());
        $this->assertEquals(['created_at', 'updated_at'], $sourceTable->getTimestampColumns()->toArray());
        $this->assertEquals(['id', 'created_at', 'updated_at'], $sourceTable->getColumnOrdinalPositions()->toArray());
    }

    public function test_it_auto_includes_pks_and_timestamps()
    {
        Schema::create('ordered_table', function(Blueprint $table) {
            $table->increments('id');
            $table->string('foo')->index();
            $table->timestamps();
        });

        $model = (fn() => new class() extends Model { public $table = 'ordered_table'; })();
        $model->foo = 'test';
        $model->save();

        Config::set('dbmask.tables.ordered_table', ['foo']);

        $this->mask();
        $this->materialize();

        $record = collect($this->masked->table('ordered_table')->first());
        $this->assertEquals(['id', 'foo', 'created_at', 'updated_at'], $record->keys()->toArray());
        $this->assertEquals([1, 'test'], $record->take(2)->values()->toArray());

        $record = collect($this->materialized->table('ordered_table')->first());
        $this->assertEquals(['id', 'foo', 'created_at', 'updated_at'], $record->keys()->toArray());
        $this->assertEquals([1, 'test'], $record->take(2)->values()->toArray());
    }

    public function test_it_maintains_column_order()
    {
        Schema::create('unordered_table', function(Blueprint $table) {
            $table->timestamps();
            $table->string('foo')->index();
            $table->increments('id');
        });

        $model = (fn() => new class() extends Model { public $table = 'unordered_table'; })();
        $model->foo = 'test';
        $model->save();

        Config::set('dbmask.tables.unordered_table', ['foo']);

        $this->mask();
        $this->materialize();

        $record = collect($this->masked->table('unordered_table')->first());
        $this->assertEquals(['created_at', 'updated_at', 'foo', 'id'], $record->keys()->toArray());
        $this->assertEquals(['test', 1], $record->slice(2)->values()->toArray());

        $record = collect($this->materialized->table('unordered_table')->first());
        $this->assertEquals(['created_at', 'updated_at', 'foo', 'id'], $record->keys()->toArray());
        $this->assertEquals(['test', 1], $record->slice(2)->values()->toArray());
    }
}
