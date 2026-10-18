<?php

namespace Goldnead\LeadMagnets\Integrations;

use Goldnead\StatamicAutomations\Contracts\AutomationRepository;
use Goldnead\StatamicAutomations\Models\AutomationRun;

/**
 * Optional: goldnead/statamic-automations.
 *
 * One question: does a mail series take over the delivery mail for this
 * address? Asked before access is granted, because granting access is what
 * starts the series, and afterwards there would always be a run.
 *
 * "Series" is an automation, named by its handle. It takes over when it exists,
 * is switched on, and has never run for this address: a series that starts each
 * address once (`_restart_policy: ignore`) will not start again for a reader who
 * asks twice, so that reader needs the delivery mail.
 *
 * Fails to **false**: without the addon, with the series missing or switched
 * off, or when the history cannot be read, the normal delivery mail goes out.
 * Nobody is left without a mail after confirming. An unreadable history counts
 * as "had it": a second series start is the worse mistake, and the delivery mail
 * is always safe to send.
 */
class AutomationsBridge extends Bridge
{
    /** @return class-string */
    protected function repository(): string
    {
        return AutomationRepository::class;
    }

    /** @return class-string */
    protected function runModel(): string
    {
        return AutomationRun::class;
    }

    public function available(): bool
    {
        return $this->enabled('automations')
            && interface_exists($this->repository())
            && class_exists($this->runModel());
    }

    /**
     * Whether the series is switched on, or null when that cannot be told.
     */
    public function isLive(string $handle): ?bool
    {
        $automation = $this->find($handle);

        return $automation === null ? null : $automation['enabled'];
    }

    /** Whether the series runs for this address now: live, and never run for it before. */
    public function takesOver(string $handle, string $email): bool
    {
        $automation = $this->find($handle);

        if ($automation === null || ! $automation['enabled']) {
            return false;
        }

        $had = $this->attempt('automations run history ['.$handle.']', fn () => $this->hasRun($automation['uuid'], mb_strtolower(trim($email))));

        return $had === false;
    }

    /**
     * @return array{uuid: string, enabled: bool}|null
     */
    protected function find(string $handle): ?array
    {
        if ($handle === '' || ! $this->available()) {
            return null;
        }

        return $this->attempt('automations lookup ['.$handle.']', function () use ($handle): ?array {
            foreach (app($this->repository())->all() as $automation) {
                if ($automation->handle === $handle) {
                    return ['uuid' => (string) $automation->uuid, 'enabled' => (bool) $automation->enabled];
                }
            }

            return null;
        });
    }

    /** Any non-test run of the automation for this address, whatever its status. */
    protected function hasRun(string $uuid, string $subjectKey): bool
    {
        return $this->runModel()::query()
            ->where('automation_uuid', $uuid)
            ->where('subject_key', $subjectKey)
            ->where('is_test', false)
            ->exists();
    }
}
