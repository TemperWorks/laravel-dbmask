<?php declare(strict_types=1);

namespace TemperWorks\DBMask;

use Illuminate\Support\ServiceProvider;
use TemperWorks\DBMask\Console\{DBMaskCommand, DBMaterializeCommand};

class DBMaskServiceProvider extends ServiceProvider
{
    protected $config = __DIR__.'/../resources/config/dbmask.php';

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([DBMaskCommand::class]);
            $this->commands([DBMaterializeCommand::class]);
        }

        $this->publishes([$this->config => config_path('dbmask.php')], 'dbmask');
    }

    public function register()
    {
        $this->app->bind(DBMask::class, function ($app) {
            return new DBMask();
        });

        $this->mergeConfigFrom($this->config, 'dbmask');
    }
}
