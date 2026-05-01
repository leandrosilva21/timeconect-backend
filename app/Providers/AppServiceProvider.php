<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use App\Listeners\EmailSentListener;
use App\Models\Contract;
use App\Models\Project;
use App\Models\Timesheet;
use App\Observers\ContractObserver;
use App\Observers\ProjectObserver;
use App\Policies\ProjectPolicy;
use App\Policies\TimesheetPolicy;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Registrar listeners de email para debug
        Event::listen(MessageSending::class, EmailSentListener::class);
        Event::listen(MessageSent::class, EmailSentListener::class);

        // Registrar observers
        Project::observe(ProjectObserver::class);
        Contract::observe(ContractObserver::class);

        // Registrar Policies
        Gate::policy(Timesheet::class, TimesheetPolicy::class);
        Gate::policy(Project::class, ProjectPolicy::class);

    }
}
