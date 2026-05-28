<?php

namespace App\Providers;

use App\Attachments\AttachmentService;
use App\Attachments\Storage\LocalStorageProvider;
use App\Attachments\Storage\StorageProvider;
use Illuminate\Support\ServiceProvider;

/**
 * FASE 11 — Wire-up do AttachmentService.
 *
 * Mantém StorageProvider abstrato no container: pra plugar S3/R2/Supabase no
 * futuro, basta trocar o bind (ou condicionar por config('attachments.driver')).
 */
class AttachmentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StorageProvider::class, function ($app) {
            // Hoje só local. Quando S3 entrar:
            //   match (config('attachments.driver', 'local')) {
            //     's3' => new S3StorageProvider(...),
            //     ...
            //   }
            return new LocalStorageProvider();
        });

        $this->app->singleton(AttachmentService::class, function ($app) {
            return new AttachmentService($app->make(StorageProvider::class));
        });
    }

    public function boot(): void {}
}
