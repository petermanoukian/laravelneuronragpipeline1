<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Interface\ContentRepositoryInterface;
use App\Repository\EloquentContentRepository;
use App\Interface\ContentPipelineInterface;
use App\Repository\EloquentContentPipelineRepository;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind Content Repository Interface to its Eloquent implementation
        $this->app->bind(
            ContentRepositoryInterface::class,
            EloquentContentRepository::class
        );

        // Bind Content Pipeline Interface to its Eloquent implementation
        $this->app->bind(
            ContentPipelineInterface::class,
            EloquentContentPipelineRepository::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}