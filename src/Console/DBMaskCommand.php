<?php declare(strict_types=1);

namespace TemperWorks\DBMask\Console;

use DB;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use TemperWorks\DBMask\DBMask;

class DBMaskCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'db:mask {--force : Force the operation.} {--remove : Removes all views.}';
    protected $description = 'Generates masked views as specified in config/dbmask.php';

    public function handle(): void
    {
        if (!$this->confirmToProceed()) {
            return;
        }

        $mask = new DBMask(
            DB::connection(config('dbmask.masking.source') ?? DB::getDefaultConnection()),
            DB::connection(config('dbmask.masking.target')),
            $this
        );

        if ($this->option('remove')) {
            $mask->dropMasked();
            return;
        }

        try {
            $mask->dropMasked();
            $mask->mask();
        } catch (Exception $exception) {
            $this->line('<fg=red>' . $exception->getMessage() . '</fg=red>');
            $mask->dropMasked();
        }
    }
}
