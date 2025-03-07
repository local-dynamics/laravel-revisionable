<?php

namespace LocalDynamics\Revisionable;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../../config/revisionable.php' => config_path('revisionable.php'),
        ], 'config');

        $this->publishes([
            __DIR__.'/../../migrations/' => database_path('migrations'),
        ], 'migrations');
    }

    public function register(): void
    {
        defined('PHP_PROCESS_UID')
        || define('PHP_PROCESS_UID', substr(hash('md5', uniqid('', true)), 0, 8));
    }
}
