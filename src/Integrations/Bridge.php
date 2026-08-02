<?php

namespace Goldnead\LeadMagnets\Integrations;

use Illuminate\Support\Facades\Log;

/**
 * What every optional sibling integration shares.
 *
 * Three rules, each of which has cost this family a day:
 *
 * 1. **`class_exists` before anything else.** Not `interface_exists` on a
 *    contract the sibling might rename, not a config flag alone — the class
 *    the bridge actually calls.
 *
 * 2. **Never `method_exists()` on a Facade class.** A Facade answers every
 *    static call through `__callStatic`, so it declares none of the methods it
 *    forwards and `method_exists(SomeFacade::class, 'thing')` is always false.
 *    Probe `getFacadeRoot()` — the object behind it — via `rootHas()` below.
 *    This is why a whole set of leadhub bridges silently did nothing.
 *
 * 3. **A sibling's failure is never this addon's failure.** Delivering the
 *    resource is the promise; tagging a contact is a courtesy. `attempt()`
 *    logs and swallows, so a broken sibling cannot stop a download.
 */
abstract class Bridge
{
    /** Set once the bridge has successfully wired itself up. */
    protected bool $booted = false;

    abstract public function available(): bool;

    protected function enabled(string $key): bool
    {
        return (bool) config('lead-magnets.integrations.'.$key, true);
    }

    /**
     * Whether the object behind a facade really has this method.
     *
     * @param  class-string  $facade
     */
    protected function rootHas(string $facade, string $method): bool
    {
        if (! class_exists($facade)) {
            return false;
        }

        try {
            /** @var object|null $root */
            $root = $facade::getFacadeRoot();
        } catch (\Throwable) {
            return false;
        }

        return $root !== null && method_exists($root, $method);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|null
     */
    protected function attempt(string $what, callable $callback)
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            Log::warning('[lead-magnets] '.$what.' failed: '.$e->getMessage());

            return null;
        }
    }
}
