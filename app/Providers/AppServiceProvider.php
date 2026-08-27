<?php

namespace App\Providers;

use App\Events\ContactCreated;
use App\Events\DealStageChanged;
use App\Events\LeadCreated;
use App\Events\TaskCompleted;
use App\Listeners\DispatchAutomation;
use App\Models\Activity;
use App\Models\Automation;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\EntityDefinition;
use App\Models\EntityRecord;
use App\Models\FieldDefinition;
use App\Models\FileRecord;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Pipeline;
use App\Models\Task;
use App\Models\User;
use App\Policies\ActivityPolicy;
use App\Policies\AutomationPolicy;
use App\Policies\ContactPolicy;
use App\Policies\DealPolicy;
use App\Policies\EntityDefinitionPolicy;
use App\Policies\FieldDefinitionPolicy;
use App\Policies\LeadPolicy;
use App\Policies\OrganizationPolicy;
use App\Policies\PipelinePolicy;
use App\Policies\TaskPolicy;
use App\Services\IntegrationManager;
use App\Support\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(IntegrationManager::class, function (): IntegrationManager {
            $manager = new IntegrationManager;
            foreach (config('integrations.providers', []) as $providerClass) {
                $manager->register($this->app->make($providerClass));
            }

            return $manager;
        });
    }

    public function boot(): void
    {
        Gate::policy(Contact::class, ContactPolicy::class);
        Gate::policy(Organization::class, OrganizationPolicy::class);
        Gate::policy(Lead::class, LeadPolicy::class);
        Gate::policy(Deal::class, DealPolicy::class);
        Gate::policy(Pipeline::class, PipelinePolicy::class);
        Gate::policy(Task::class, TaskPolicy::class);
        Gate::policy(FieldDefinition::class, FieldDefinitionPolicy::class);
        Gate::policy(EntityDefinition::class, EntityDefinitionPolicy::class);
        Gate::policy(Automation::class, AutomationPolicy::class);
        Gate::policy(Activity::class, ActivityPolicy::class);

        Relation::enforceMorphMap([
            'user' => User::class,
            'contact' => Contact::class,
            'organization' => Organization::class,
            'lead' => Lead::class,
            'deal' => Deal::class,
            'task' => Task::class,
            'file' => FileRecord::class,
            'entity_record' => EntityRecord::class,
        ]);

        RateLimiter::for('auth', fn ($request) => Limit::perMinute(10)->by((string) $request->ip()));
        RateLimiter::for('api', fn ($request) => Limit::perMinute(120)->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('webhooks', fn ($request) => Limit::perMinute(60)->by((string) $request->ip()));
        RateLimiter::for('exports', fn ($request) => Limit::perMinute(10)->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        Event::listen(ContactCreated::class, DispatchAutomation::class);
        Event::listen(LeadCreated::class, DispatchAutomation::class);
        Event::listen(DealStageChanged::class, DispatchAutomation::class);
        Event::listen(TaskCompleted::class, DispatchAutomation::class);
    }
}
