<?php

namespace Goldnead\LeadMagnets\Integrations;

use Goldnead\EmailTemplates\Facades\EmailTemplates;

/**
 * Optional: goldnead/statamic-email-templates.
 *
 * Lets an editor author the confirmation and delivery mails in the Control
 * Panel. Without it the Blade views in resources/views/mail are used, and
 * those are publishable — so changing the wording never requires the sibling.
 */
class EmailTemplatesBridge extends Bridge
{
    /** @return class-string */
    protected function facade(): string
    {
        return EmailTemplates::class;
    }

    public function available(): bool
    {
        return $this->enabled('email_templates') && class_exists($this->facade());
    }

    /**
     * The rendered body and subject for a template slug, or null to fall back.
     *
     * @param  array<string, string>  $variables
     * @return array{html: string, subject: string|null}|null
     */
    public function render(string $slug, array $variables): ?array
    {
        if (! $this->available() || $slug === '') {
            return null;
        }

        $facade = $this->facade();

        if (! $this->rootHas($facade, 'resolve')) {
            return null;
        }

        return $this->attempt('email-templates render ['.$slug.']', function () use ($facade, $slug, $variables) {
            $template = $facade::resolve($slug);

            if ($template === null) {
                return null;
            }

            $html = $this->readString($template, ['html', 'body', 'content']);

            if ($html === null || $html === '') {
                return null;
            }

            $subject = $this->readString($template, ['subject', 'title']);

            return [
                'html' => $this->interpolate($html, $variables),
                'subject' => $subject === null ? null : $this->interpolate($subject, $variables),
            ];
        });
    }

    /**
     * Read the first property or method that exists, without assuming the
     * sibling's data shape. It is a `EmailTemplateData`, not an interface this
     * addon owns, and guessing wrong should degrade to the Blade view rather
     * than throw inside a mail send.
     *
     * @param  list<string>  $candidates
     */
    protected function readString(object $template, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (isset($template->{$candidate}) && is_string($template->{$candidate})) {
                return $template->{$candidate};
            }

            if (method_exists($template, $candidate)) {
                $value = $template->{$candidate}();

                if (is_string($value)) {
                    return $value;
                }
            }
        }

        return null;
    }

    /** @param  array<string, string>  $variables */
    protected function interpolate(string $body, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $body = str_replace(['{{ '.$key.' }}', '{{'.$key.'}}'], $value, $body);
        }

        return $body;
    }
}
