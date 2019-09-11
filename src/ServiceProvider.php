<?php

namespace LocalDynamics\Revisionable;

use LocalDynamics\Revisionable\Models\Revision;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    public function boot()
    {
        $this->publishes([
            __DIR__ . '/../../config/revisionable.php' => config_path('revisionable.php'),
        ], 'config');

        $this->publishes([
            __DIR__ . '/../../migrations/' => database_path('migrations'),
        ], 'migrations');
    }

    public function register()
    {
        defined('PHP_PROCESS_UID')
        || define('PHP_PROCESS_UID', substr(hash('md5', uniqid('', true)), 0, 8));

        $this->app->bind('revisionableModel', config('revisionable.model', Revision::class));
    }
}
