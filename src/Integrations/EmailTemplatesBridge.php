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
    /**
     * The variables inserted raw into the HTML body, without escaping.
     *
     * Both are links this addon builds itself — a confirmation token, a signed
     * download URL — and both are used as an `href`, where the query string's
     * `&` must survive intact. Everything else is escaped: `email` is whatever
     * a visitor typed into the form, and the mail carrying it goes to an
     * address nobody has confirmed yet.
     *
     * @var list<string>
     */
    public const RAW_VARIABLES = ['confirm_url', 'download_url'];

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
                // A subject line is not HTML. Escaping it would put a literal
                // `&amp;` in front of the reader instead of protecting one.
                'subject' => $subject === null ? null : $this->interpolate($subject, $variables, escape: false),
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

    /**
     * Put the variables into the template text.
     *
     * Values are HTML-escaped, because what goes in here is a visitor's own
     * input and what comes out is the body of a mail. The exceptions are named
     * in {@see self::RAW_VARIABLES}; `$escape` switches escaping off entirely
     * for output that is not HTML, which is the subject line.
     *
     * @param  array<string, string>  $variables
     */
    protected function interpolate(string $body, array $variables, bool $escape = true): string
    {
        foreach ($variables as $key => $value) {
            $replacement = $escape && ! in_array($key, self::RAW_VARIABLES, true)
                ? e($value)
                : $value;

            $body = str_replace(['{{ '.$key.' }}', '{{'.$key.'}}'], $replacement, $body);
        }

        return $body;
    }
}
