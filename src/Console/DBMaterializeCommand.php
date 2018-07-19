<?php declare(strict_types=1);

namespace TemperWorks\DBMask\Console;

use DB;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use TemperWorks\DBMask\DBMask;

class DBMaterializeCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'db:materialize {--force : Force the operation.} {--filter : Tables to ignore (comma separated)} {--remove : Removes all tables.}';
    protected $description = 'Generates materialized tables as specified in config/dbmask.php';

    public function handle(): void
    {
        if (!$this->confirmToProceed()) {
            return;
        }

        $mask = new DBMask(
            DB::connection(config('dbmask.materializing.source') ?? DB::getDefaultConnection()),
            DB::connection(config('dbmask.materializing.target')),
            $this
        );

        if($this->option('filter')) {
            $filters = collect(explode(',', $this->option('filter') ?: []))->flip()->map(function(){ return 'false'; });
        }

        $mask->setIgnore(
            array_merge(config('dbmask.table_filters'), $filters->toArray())
        );

        if ($this->option('remove')) {
            $mask->dropMaterialized();
            return;
        }

        try {
            $mask->dropMaterialized();
            $mask->materialize();
        } catch (Exception $exception) {
            $this->line('<fg=red>' . $exception->getMessage() . '</fg=red>');
            $mask->dropMaterialized();
        }
    }
}
