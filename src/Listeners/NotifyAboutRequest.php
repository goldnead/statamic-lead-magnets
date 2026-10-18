<?php

namespace Goldnead\LeadMagnets\Listeners;

use Goldnead\LeadMagnets\Events\ResourceRequested;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Tells a chat webhook that somebody asked for a resource.
 *
 * Off until `lead-magnets.notifications.webhook_url` is set. The body is the
 * `{"content": …}` shape Discord and Slack-compatible endpoints accept. The
 * wording is the host's: `notifications.message` with `:handle`, `:email` and
 * `:time` filled in.
 *
 * A notice is a courtesy and never stops a request. A dead endpoint is logged
 * and the visitor carries on, so the post has a short timeout and swallows
 * every failure.
 *
 * Registered by Statamic from the type hint on `handle()`, like the other
 * listeners in this folder.
 */
class NotifyAboutRequest
{
    public function handle(ResourceRequested $event): void
    {
        $url = (string) config('lead-magnets.notifications.webhook_url', '');

        if ($url === '') {
            return;
        }

        $message = strtr((string) config('lead-magnets.notifications.message'), [
            ':handle' => (string) $event->grant->resource?->handle,
            ':email' => $event->grant->email,
            ':time' => now()->format('d.m.Y H:i'),
        ]);

        try {
            // No mentions from outside: the address comes from an unconfirmed
            // request, and whatever it contains is not a ping.
            Http::timeout(5)->throw()->post($url, ['content' => $message, 'allowed_mentions' => ['parse' => []]]);
        } catch (\Throwable $e) {
            // The class and the reason, never the message of a connection error:
            // it carries the webhook URL, and a Discord URL is its own secret.
            Log::warning('statamic-lead-magnets: the request notice could not be posted ('.$e::class.').');
        }
    }
}
