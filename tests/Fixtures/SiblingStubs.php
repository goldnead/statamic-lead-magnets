<?php

namespace Goldnead\LeadMagnets\Tests\Fixtures;

use Goldnead\LeadMagnets\Integrations\ActivityBridge;
use Goldnead\LeadMagnets\Integrations\EmailTemplatesBridge;
use Goldnead\LeadMagnets\Integrations\LeadhubBridge;
use Goldnead\LeadMagnets\Integrations\MarketingBridge;
use Goldnead\LeadMagnets\Integrations\SuppressionBridge;
use Illuminate\Support\Facades\Facade;

/**
 * Stand-ins for the sibling addons.
 *
 * The siblings are not installed, deliberately and permanently — see
 * `tests/Feature/NoSiblingsInstalledTest.php`. But the bridges still need
 * covering: a bridge nobody exercises is a bridge whose guards nobody has read,
 * and the family's most expensive defect to date was a set of bridges that
 * registered nothing and said nothing about it.
 *
 * The stubs are reached through each bridge's overridable class seam rather
 * than `class_alias` into the sibling namespace. An alias is process-global and
 * cannot be undone: installed by one test file, it makes every later file in
 * the run see a sibling that is not there, which is exactly the pollution that
 * would make the "works without siblings" proof meaningless.
 *
 * What this covers is what the bridges own — the guards, the ordering, the
 * failure handling. The siblings' own behaviour is their own suite's job.
 */
final class SiblingStubs
{
    public static function reset(): void
    {
        FakeLeadHubFacade::$contacts = [];
        FakeLeadHubFacade::$tags = [];
        FakeLeadHubFacade::$throwOnAddTag = false;
        FakeSuppressionGate::$suppressed = [];
        FakeSuppressionGate::$throws = false;
        FakeActivityFacade::$records = [];
        FakeEmailTemplatesFacade::$templates = [];
        FakeMarketingService::$subscriptions = [];
        FakeMailingListRepository::$lists = [];
    }

    /** Bind every bridge to its stand-in for the current test. */
    public static function bindAll(): void
    {
        self::reset();

        app()->singleton(LeadhubBridge::class, fn () => new StubbedLeadhubBridge);
        app()->singleton(SuppressionBridge::class, fn () => new StubbedSuppressionBridge);
        app()->singleton(EmailTemplatesBridge::class, fn () => new StubbedEmailTemplatesBridge);
        app()->singleton(ActivityBridge::class, fn () => new StubbedActivityBridge);
        app()->singleton(MarketingBridge::class, fn () => new StubbedMarketingBridge);
        app()->bind(FakeMailingListRepository::class, fn () => new FakeMailingListRepository);
    }
}

class StubbedLeadhubBridge extends LeadhubBridge
{
    protected function facade(): string
    {
        return FakeLeadHubFacade::class;
    }
}

class StubbedSuppressionBridge extends SuppressionBridge
{
    protected function facade(): string
    {
        return FakeSuppressionGate::class;
    }
}

class StubbedEmailTemplatesBridge extends EmailTemplatesBridge
{
    protected function facade(): string
    {
        return FakeEmailTemplatesFacade::class;
    }
}

class StubbedActivityBridge extends ActivityBridge
{
    protected function facade(): string
    {
        return FakeActivityFacade::class;
    }
}

class StubbedMarketingBridge extends MarketingBridge
{
    protected function service(): string
    {
        return FakeMarketingService::class;
    }

    protected function repository(): string
    {
        return FakeMailingListRepository::class;
    }

    public function available(): bool
    {
        // The real bridge asks `interface_exists()` on marketing's repository
        // contract; the stand-in is a class. Everything else about the guard
        // chain is inherited unchanged.
        return $this->enabled('marketing') && class_exists($this->service());
    }
}

/**
 * A facade-shaped stub, and shaped that way on purpose.
 *
 * A Facade forwards through `__callStatic` and declares none of the methods it
 * forwards, so `method_exists(FakeLeadHubFacade::class, 'findByEmail')` is
 * false. A bridge that probed the facade class would conclude the sibling
 * cannot do anything and quietly do nothing — the exact defect that once cost
 * leadhub fourteen trigger registrations, with nothing in any log.
 * `Bridge::rootHas()` asks `getFacadeRoot()` instead, and these stubs exist in
 * this shape so a regression to the naive check turns red here.
 */
class FakeLeadHubFacade extends Facade
{
    /** @var array<string, array<string, mixed>> */
    public static array $contacts = [];

    /** @var array<string, list<string>> */
    public static array $tags = [];

    public static bool $throwOnAddTag = false;

    protected static function getFacadeAccessor(): string
    {
        return FakeLeadHubRoot::class;
    }
}

class FakeLeadHubRoot
{
    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return FakeLeadHubFacade::$contacts[$email] ?? null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function create(array $attributes): array
    {
        $id = 'contact-'.(count(FakeLeadHubFacade::$contacts) + 1);

        return FakeLeadHubFacade::$contacts[$attributes['email']] = $attributes + ['id' => $id];
    }

    /** @return array<string, mixed> */
    public function addTag(string $id, string $tag): array
    {
        if (FakeLeadHubFacade::$throwOnAddTag) {
            throw new \RuntimeException('leadhub is having a bad day');
        }

        FakeLeadHubFacade::$tags[$id][] = $tag;

        return ['id' => $id];
    }
}

class FakeSuppressionGate extends Facade
{
    /** @var list<string> */
    public static array $suppressed = [];

    public static bool $throws = false;

    protected static function getFacadeAccessor(): string
    {
        return FakeSuppressionRoot::class;
    }
}

class FakeSuppressionRoot
{
    public function isSuppressed(string $email, ?int $brandId = null): bool
    {
        if (FakeSuppressionGate::$throws) {
            throw new \RuntimeException('the suppression list is unreachable');
        }

        return in_array($email, FakeSuppressionGate::$suppressed, true);
    }
}

class FakeActivityFacade extends Facade
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public static array $records = [];

    protected static function getFacadeAccessor(): string
    {
        return FakeActivityRoot::class;
    }
}

class FakeActivityRoot
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function record(string $eventType, array $attributes = []): array
    {
        FakeActivityFacade::$records[] = [$eventType, $attributes];

        return ['id' => count(FakeActivityFacade::$records)];
    }
}

class FakeEmailTemplatesFacade extends Facade
{
    /** @var array<string, object> */
    public static array $templates = [];

    protected static function getFacadeAccessor(): string
    {
        return FakeEmailTemplatesRoot::class;
    }
}

class FakeEmailTemplatesRoot
{
    public function resolve(string $slug, ?callable $fallback = null): ?object
    {
        return FakeEmailTemplatesFacade::$templates[$slug] ?? null;
    }
}

class FakeEmailTemplate
{
    public function __construct(public string $html, public ?string $subject = null) {}
}

class FakeMailingListRepository
{
    /** @var array<string, object> */
    public static array $lists = [];

    public function find(string $handle): ?object
    {
        return self::$lists[$handle] ?? null;
    }
}

class FakeMarketingService
{
    /** @var list<array{handle: string, email: string, context: array<string, mixed>}> */
    public static array $subscriptions = [];

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $context
     */
    public function subscribe(object $list, string $email, array $attributes = [], array $context = []): object
    {
        self::$subscriptions[] = [
            'handle' => $list->handle,
            'email' => $email,
            'context' => $context,
        ];

        return (object) ['status' => 'pending'];
    }
}
