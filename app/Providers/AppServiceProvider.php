<?php

namespace App\Providers;

use App\Contracts\SupportEscalationNotifier;
use App\Events\ContactCreated;
use App\Events\DealStageChanged;
use App\Events\LeadCaptured;
use App\Events\LeadCreated;
use App\Events\MeetingBooked;
use App\Events\QuoteAccepted;
use App\Events\TaskCompleted;
use App\Events\TimelineEventRecorded;
use App\Listeners\DispatchAutomation;
use App\Listeners\DispatchLeadQualification;
use App\Listeners\QueueAcceptedQuoteToErp;
use App\Listeners\StopSequencesFromMeeting;
use App\Listeners\StopSequencesFromTimeline;
use App\Models\Activity;
use App\Models\ApprovalProcess;
use App\Models\ApprovalRequest;
use App\Models\ApprovalRule;
use App\Models\Audience;
use App\Models\Automation;
use App\Models\Branch;
use App\Models\Bundle;
use App\Models\BundleRule;
use App\Models\CalendarConnection;
use App\Models\Campaign;
use App\Models\ConsentLink;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Currency;
use App\Models\Deal;
use App\Models\Discount;
use App\Models\DiscountRule;
use App\Models\EntityDefinition;
use App\Models\EntityRecord;
use App\Models\ErpSync;
use App\Models\FieldDefinition;
use App\Models\FileRecord;
use App\Models\ForecastSnapshot;
use App\Models\Form;
use App\Models\Goal;
use App\Models\GoalTarget;
use App\Models\Inbox;
use App\Models\Journey;
use App\Models\KnowledgeArticle;
use App\Models\KnowledgeArticleVersion;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeCategory;
use App\Models\KnowledgeTag;
use App\Models\Lead;
use App\Models\LeadRoutingRule;
use App\Models\MeetingBooking;
use App\Models\MeetingType;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Pipeline;
use App\Models\Playbook;
use App\Models\PlaybookExecution;
use App\Models\PriceList;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductDependency;
use App\Models\ProductVariant;
use App\Models\Quote;
use App\Models\SalesTeam;
use App\Models\ScoringModel;
use App\Models\Segment;
use App\Models\Sequence;
use App\Models\SlaBusinessCalendar;
use App\Models\SlaPolicy;
use App\Models\SupportAgent;
use App\Models\SupportQueue;
use App\Models\Task;
use App\Models\Tax;
use App\Models\Territory;
use App\Models\TerritoryAssignment;
use App\Models\TerritoryRule;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use App\Observers\ContactObserver;
use App\Observers\DealObserver;
use App\Observers\LeadObserver;
use App\Observers\MarketingMessageObserver;
use App\Observers\OrganizationObserver;
use App\Observers\TaskObserver;
use App\Policies\ActivityPolicy;
use App\Policies\ApprovalProcessPolicy;
use App\Policies\ApprovalRequestPolicy;
use App\Policies\AutomationPolicy;
use App\Policies\CalendarConnectionPolicy;
use App\Policies\CatalogPolicy;
use App\Policies\ContactPolicy;
use App\Policies\ConversationPolicy;
use App\Policies\DealPolicy;
use App\Policies\EntityDefinitionPolicy;
use App\Policies\ErpSyncPolicy;
use App\Policies\FieldDefinitionPolicy;
use App\Policies\ForecastSnapshotPolicy;
use App\Policies\FormPolicy;
use App\Policies\GoalPolicy;
use App\Policies\InboxPolicy;
use App\Policies\KnowledgeArticlePolicy;
use App\Policies\KnowledgePolicy;
use App\Policies\LeadPolicy;
use App\Policies\LeadRoutingRulePolicy;
use App\Policies\MarketingPolicy;
use App\Policies\MeetingBookingPolicy;
use App\Policies\MeetingTypePolicy;
use App\Policies\OrganizationPolicy;
use App\Policies\PipelinePolicy;
use App\Policies\PlaybookExecutionPolicy;
use App\Policies\PlaybookPolicy;
use App\Policies\PricingPolicy;
use App\Policies\QuotePolicy;
use App\Policies\SalesStructurePolicy;
use App\Policies\ScoringModelPolicy;
use App\Policies\SequencePolicy;
use App\Policies\SupportPolicy;
use App\Policies\TaskPolicy;
use App\Policies\TerritoryPolicy;
use App\Policies\TicketPolicy;
use App\Services\Calendar\CalendarProviderManager;
use App\Services\Calendar\GoogleCalendarProvider;
use App\Services\Calendar\MicrosoftCalendarProvider;
use App\Services\Calendar\ZoomMeetingProvider;
use App\Services\Email\EmailProviderManager;
use App\Services\Email\GoogleEmailProvider;
use App\Services\Email\MicrosoftEmailProvider;
use App\Services\Email\SmtpEmailProvider;
use App\Services\Erp\ErpProviderManager;
use App\Services\Erp\VantexErpProvider;
use App\Services\ExistingSupportEscalationNotifier;
use App\Services\IntegrationManager;
use App\Services\Messaging\EmailMessageTransport;
use App\Services\Messaging\MessageTransportManager;
use App\Services\Messaging\SmsMessageTransport;
use App\Services\Messaging\WebChatMessageTransport;
use App\Services\Messaging\WhatsAppMessageTransport;
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
        $this->app->bind(SupportEscalationNotifier::class, ExistingSupportEscalationNotifier::class);
        $this->app->singleton(IntegrationManager::class, function (): IntegrationManager {
            $manager = new IntegrationManager((array) config('integrations.providers', []));
            foreach (config('integrations.adapters', []) as $providerClass) {
                $manager->register($this->app->make($providerClass));
            }

            return $manager;
        });
        $this->app->singleton(MessageTransportManager::class, function (): MessageTransportManager {
            $manager = new MessageTransportManager;
            $manager->register($this->app->make(WebChatMessageTransport::class));
            $manager->register($this->app->make(EmailMessageTransport::class));
            $manager->register($this->app->make(WhatsAppMessageTransport::class));
            $manager->register($this->app->make(SmsMessageTransport::class));

            return $manager;
        });
        $this->app->singleton(EmailProviderManager::class, function (): EmailProviderManager {
            $manager = new EmailProviderManager;
            $manager->register($this->app->make(GoogleEmailProvider::class));
            $manager->register($this->app->make(MicrosoftEmailProvider::class));
            $manager->register($this->app->make(SmtpEmailProvider::class));

            return $manager;
        });
        $this->app->singleton(CalendarProviderManager::class, function (): CalendarProviderManager {
            $manager = new CalendarProviderManager;
            $manager->register($this->app->make(GoogleCalendarProvider::class));
            $manager->register($this->app->make(MicrosoftCalendarProvider::class));
            $manager->register($this->app->make(ZoomMeetingProvider::class));

            return $manager;
        });
        $this->app->singleton(ErpProviderManager::class, function (): ErpProviderManager {
            $manager = new ErpProviderManager;
            $manager->register($this->app->make(VantexErpProvider::class));

            return $manager;
        });
    }

    public function boot(): void
    {
        Gate::policy(Contact::class, ContactPolicy::class);
        Gate::policy(Inbox::class, InboxPolicy::class);
        Gate::policy(Conversation::class, ConversationPolicy::class);
        Gate::policy(Organization::class, OrganizationPolicy::class);
        Gate::policy(Lead::class, LeadPolicy::class);
        Gate::policy(Form::class, FormPolicy::class);
        Gate::policy(LeadRoutingRule::class, LeadRoutingRulePolicy::class);
        Gate::policy(ScoringModel::class, ScoringModelPolicy::class);
        Gate::policy(Sequence::class, SequencePolicy::class);
        Gate::policy(MeetingType::class, MeetingTypePolicy::class);
        Gate::policy(MeetingBooking::class, MeetingBookingPolicy::class);
        Gate::policy(CalendarConnection::class, CalendarConnectionPolicy::class);
        Gate::policy(Deal::class, DealPolicy::class);
        Gate::policy(Pipeline::class, PipelinePolicy::class);
        Gate::policy(Task::class, TaskPolicy::class);
        Gate::policy(FieldDefinition::class, FieldDefinitionPolicy::class);
        Gate::policy(EntityDefinition::class, EntityDefinitionPolicy::class);
        Gate::policy(Automation::class, AutomationPolicy::class);
        Gate::policy(Activity::class, ActivityPolicy::class);
        foreach ([Currency::class, ProductCategory::class, Product::class, ProductVariant::class, Bundle::class] as $model) {
            Gate::policy($model, CatalogPolicy::class);
        }
        foreach ([Tax::class, Discount::class, PriceList::class, PricingRule::class, DiscountRule::class, BundleRule::class, ProductDependency::class, ApprovalRule::class] as $model) {
            Gate::policy($model, PricingPolicy::class);
        }
        Gate::policy(Quote::class, QuotePolicy::class);
        Gate::policy(ApprovalProcess::class, ApprovalProcessPolicy::class);
        Gate::policy(ApprovalRequest::class, ApprovalRequestPolicy::class);
        Gate::policy(ErpSync::class, ErpSyncPolicy::class);
        foreach ([Branch::class, SalesTeam::class] as $model) {
            Gate::policy($model, SalesStructurePolicy::class);
        }
        foreach ([Territory::class, TerritoryRule::class, TerritoryAssignment::class] as $model) {
            Gate::policy($model, TerritoryPolicy::class);
        }
        foreach ([Goal::class, GoalTarget::class] as $model) {
            Gate::policy($model, GoalPolicy::class);
        }
        Gate::policy(ForecastSnapshot::class, ForecastSnapshotPolicy::class);
        Gate::policy(Playbook::class, PlaybookPolicy::class);
        Gate::policy(PlaybookExecution::class, PlaybookExecutionPolicy::class);
        foreach ([Segment::class, Audience::class, Campaign::class, Journey::class, ConsentLink::class] as $model) {
            Gate::policy($model, MarketingPolicy::class);
        }

        foreach ([KnowledgeBase::class, KnowledgeCategory::class, KnowledgeTag::class, KnowledgeArticleVersion::class] as $model) {
            Gate::policy($model, KnowledgePolicy::class);
        }
        Gate::policy(KnowledgeArticle::class, KnowledgeArticlePolicy::class);

        foreach ([SupportAgent::class, TicketCategory::class, SupportQueue::class, SlaBusinessCalendar::class, SlaPolicy::class] as $model) {
            Gate::policy($model, SupportPolicy::class);
        }
        Gate::policy(Ticket::class, TicketPolicy::class);

        Relation::enforceMorphMap([
            'user' => User::class,
            'contact' => Contact::class,
            'conversation' => Conversation::class,
            'organization' => Organization::class,
            'lead' => Lead::class,
            'deal' => Deal::class,
            'task' => Task::class,
            'activity' => Activity::class,
            'file' => FileRecord::class,
            'product' => Product::class,
            'quote' => Quote::class,
            'entity_record' => EntityRecord::class,
        ]);

        Contact::observe(ContactObserver::class);
        Organization::observe(OrganizationObserver::class);
        Lead::observe(LeadObserver::class);
        Deal::observe(DealObserver::class);
        Task::observe(TaskObserver::class);
        Message::observe(MarketingMessageObserver::class);

        RateLimiter::for('auth', fn ($request) => Limit::perMinute(10)->by((string) $request->ip()));
        RateLimiter::for('api', fn ($request) => Limit::perMinute(120)->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('webhooks', fn ($request) => Limit::perMinute(60)->by((string) $request->ip()));
        RateLimiter::for('exports', fn ($request) => Limit::perMinute(10)->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('public-forms', fn ($request) => [
            Limit::perMinute(20)->by((string) $request->route('publicId').'|'.$request->ip()),
            Limit::perDay(500)->by((string) $request->route('publicId').'|'.$request->ip()),
        ]);
        RateLimiter::for('public-meetings', fn ($request) => [
            Limit::perMinute(30)->by((string) ($request->route('publicId') ?? $request->route('bookingPublicId')).'|'.$request->ip()),
            Limit::perDay(300)->by((string) ($request->route('publicId') ?? $request->route('bookingPublicId')).'|'.$request->ip()),
        ]);
        RateLimiter::for('public-quotes', fn ($request) => [
            Limit::perMinute(30)->by((string) $request->route('publicId').'|'.$request->ip()),
            Limit::perDay(300)->by((string) $request->route('publicId').'|'.$request->ip()),
        ]);
        RateLimiter::for('public-preferences', fn ($request) => [
            Limit::perMinute(30)->by((string) $request->ip()),
            Limit::perDay(300)->by(hash('sha256', (string) $request->route('token')).'|'.$request->ip()),
        ]);
        RateLimiter::for('public-knowledge', fn ($request) => [
            Limit::perMinute(60)->by((string) $request->route('basePublicId').'|'.$request->ip()),
            Limit::perDay(1000)->by((string) $request->route('basePublicId').'|'.$request->ip()),
        ]);

        Event::listen(ContactCreated::class, DispatchAutomation::class);
        Event::listen(LeadCreated::class, DispatchAutomation::class);
        Event::listen(LeadCaptured::class, DispatchLeadQualification::class);
        Event::listen(DealStageChanged::class, DispatchAutomation::class);
        Event::listen(TaskCompleted::class, DispatchAutomation::class);
        Event::listen(TimelineEventRecorded::class, StopSequencesFromTimeline::class);
        Event::listen(MeetingBooked::class, StopSequencesFromMeeting::class);
        Event::listen(QuoteAccepted::class, DispatchAutomation::class);
        Event::listen(QuoteAccepted::class, QueueAcceptedQuoteToErp::class);
    }
}
