<?php

use Goldnead\LeadMagnets\Models\Grant;

/**
 * The form field `_redirect` is written by whoever builds the request, so it
 * must never send the reader to another host (open redirect).
 */
function postWithRedirect(mixed $redirect, array $server = [])
{
    makeResource();

    return test()->withServerVariables($server)->post(route('lead-magnets.request'), [
        'email' => 'reader@example.com',
        'resource' => 'warm_up',
        '_redirect' => $redirect,
    ]);
}

dataset('foreign redirect targets', [
    'foreign host' => ['https://evil.example'],
    'foreign host with path' => ['https://evil.example/phish?x=1'],
    'protocol-relative' => ['//evil.example'],
    'protocol-relative with path' => ['//evil.example/phish'],
    'backslash after slash' => ['/\\evil.example'],
    'double backslash' => ['\\\\evil.example'],
    'single backslash host' => ['\\evil.example'],
    'tab inside the slashes' => ["/\t/evil.example"],
    'newline inside the slashes' => ["/\n/evil.example"],
    'javascript scheme' => ['javascript:alert(1)'],
    'data scheme' => ['data:text/html,<script>alert(1)</script>'],
    'userinfo trick' => ['https://adriangoldner.com@evil.example'],
    'own host as userinfo of another host' => ['https://localhost@evil.example/'],
    'own host as a subdomain prefix' => ['https://localhost.evil.example/'],
    'scheme without slashes' => ['https:evil.example'],
    'bare host' => ['evil.example'],
    'leading space' => [' https://evil.example'],
]);

it('refuses a foreign redirect target and falls back to the previous page', function (string $target) {
    $response = postWithRedirect($target, ['HTTP_REFERER' => 'http://localhost/landing']);

    $response->assertRedirect('http://localhost/landing');
    expect($response->headers->get('Location'))->not->toContain('evil.example');
    expect(Grant::query()->count())->toBe(1);
})->with('foreign redirect targets');

it('follows a relative path', function () {
    postWithRedirect('/thanks')->assertRedirect('/thanks');
});

it('follows a relative path with query and fragment', function () {
    postWithRedirect('/thanks?utm=1#top')->assertRedirect('/thanks?utm=1#top');
});

it('follows an absolute URL on its own host', function () {
    $own = url('/thanks');

    postWithRedirect($own)->assertRedirect($own);
});

it('refuses an absolute URL on its own host with another scheme or port', function () {
    $host = request()->getHost();

    $response = postWithRedirect('ftp://'.$host.'/thanks', ['HTTP_REFERER' => 'http://localhost/landing']);

    $response->assertRedirect('http://localhost/landing');
});

it('ignores a non-string redirect value', function () {
    postWithRedirect(['https://evil.example'], ['HTTP_REFERER' => 'http://localhost/landing'])
        ->assertRedirect('http://localhost/landing');
});
