<?php declare(strict_types=1);

namespace TemperWorks\DBMask\Console;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use TemperWorks\DBMask\DBMask;

class DBMaskCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'dbmask {--force : Force the operation.} {--remove : Removes all views.}';
    protected $description = 'Drop all masked views and generate new ones';

    public function handle(): void
    {
        if (!$this->confirmToProceed()) {
            return;
        }

        $mask = new DBMask($this);

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

        try {
            $mask->dropMaterialized();
            $mask->materialize();
        } catch (Exception $exception) {
            $this->line('<fg=red>' . $exception->getMessage() . '</fg=red>');
            $mask->dropMaterialized();
        }
    }
}
