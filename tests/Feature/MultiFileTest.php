<?php

use Goldnead\Entitlements\Enums\EntitlementState;
use Goldnead\LeadMagnets\Models\Grant;
use Goldnead\LeadMagnets\Models\Resource;
use Goldnead\LeadMagnets\Services\DeliveryService;
use Goldnead\LeadMagnets\Services\DownloadLink;
use Goldnead\LeadMagnets\Services\GrantService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Statamic\Facades\User;
use Symfony\Component\HttpFoundation\StreamedResponse;

/*
 * A freebie that carries an ordered list of files (label, group) — the
 * Baraye case: nine files in three voicings — and the promise that a freebie
 * with one file keeps working exactly as before.
 */

/**
 * Nine files in three voicings, listed interleaved on purpose: grouping must
 * follow the first appearance of a group, not the order the rows happen in.
 *
 * @return array<int, array{key: string, path: string, label: string, group: string}>
 */
function barayeFiles(): array
{
    $files = [];

    foreach (['SATB', 'SSA', 'TTBB'] as $voicing) {
        foreach (['Partitur (PDF)', 'MusicXML', 'Übe-MP3s (ZIP)'] as $kind) {
            $slug = strtolower($voicing).'-'.preg_replace('/\W+/', '', strtolower($kind));

            $files[] = [
                'key' => substr(md5($slug), 0, 8),
                'path' => 'baraye/'.$slug.'.dat',
                'label' => $kind,
                'group' => $voicing,
            ];
        }
    }

    // Interleave: row order is SATB, SSA, TTBB, SATB, SSA, TTBB …
    $interleaved = [];

    for ($i = 0; $i < 3; $i++) {
        foreach ([0, 1, 2] as $voicing) {
            $interleaved[] = $files[$voicing * 3 + $i];
        }
    }

    return $interleaved;
}

function makeMultiResource(array $attributes = []): Resource
{
    $files = $attributes['files'] ?? barayeFiles();

    foreach ($files as $file) {
        Storage::disk('lead-magnets')->put($file['path'], 'content of '.$file['path']);
    }

    return makeResource(array_merge([
        'handle' => 'baraye',
        'title' => 'Baraye',
        'file_path' => $files[0]['path'],
        'files' => $files,
        'requires_confirmation' => false,
    ], $attributes));
}

/** @return array<string, mixed> */
function requestAndLoadGrant(Resource $resource): Grant
{
    test()->post(route('lead-magnets.request'), [
        'email' => 'reader@example.invalid',
        'resource' => $resource->handle,
    ]);

    return Grant::query()->with(['resource', 'entitlement'])->sole();
}

function lastDeliveryMailBody(): string
{
    $messages = app('mailer')->getSymfonyTransport()->messages();
    $last = $messages[count($messages) - 1];

    return html_entity_decode(quoted_printable_decode($last->getMessage()->toString()));
}

/** @return list<string> every signed per-file link in a body */
function fileLinksIn(string $body): array
{
    preg_match_all('#https?://[^\s"<>]*?/download/\d+/[a-z0-9]+\?[^\s"<>]+#', $body, $matches);

    // An `href` carries `&amp;`; the browser reads it back as `&`.
    return array_values(array_unique(array_map('html_entity_decode', $matches[0])));
}

// -------------------------------------------------------------- old behaviour

it('keeps a single-file freebie exactly as it was, with no data change', function () {
    $resource = makeResource();

    expect($resource->getRawOriginal('files'))->toBeNull()
        ->and($resource->hasMultipleFiles())->toBeFalse()
        ->and($resource->fileList())->toHaveCount(1)
        ->and($resource->fileList()[0]['path'])->toBe('warm-up.txt');

    $grant = requestAndLoadGrant(makeResource(['handle' => 'solo', 'requires_confirmation' => false, 'file_path' => 'solo.txt']));

    $body = lastDeliveryMailBody();

    // The one link of old: `/download/{grant}?…`, no per-file segment, no list.
    expect(downloadUrlFromLastDeliveryMail())->not->toBeNull()
        ->and(fileLinksIn($body))->toBe([]);

    $this->get(downloadUrlFromLastDeliveryMail())->assertOk()->assertDownload('Warm-up routine.txt');

    expect($grant->fresh()->download_count)->toBe(1);
});

it('treats a stored list with one file like the single-file case', function () {
    $resource = makeMultiResource([
        'handle' => 'one',
        'files' => [['key' => 'aaaa1111', 'path' => 'one/only.pdf', 'label' => 'Only', 'group' => null]],
    ]);

    expect($resource->hasMultipleFiles())->toBeFalse();

    $grant = requestAndLoadGrant($resource);

    $this->get(app(DownloadLink::class)->for($grant))->assertOk()->assertDownload();
});

// ------------------------------------------------------------ multi-file mail

it('lists every file in the delivery mail, grouped, each with its own signed link', function () {
    $grant = requestAndLoadGrant(makeMultiResource());

    $body = lastDeliveryMailBody();
    $links = fileLinksIn($body);

    expect($links)->toHaveCount(9);

    foreach (['SATB', 'SSA', 'TTBB'] as $group) {
        expect($body)->toContain($group);
    }

    // Groups in order of first appearance, each once.
    expect(strpos($body, 'SATB'))->toBeLessThan(strpos($body, 'SSA'))
        ->and(strpos($body, 'SSA'))->toBeLessThan(strpos($body, 'TTBB'))
        ->and(substr_count($body, '>SATB<'))->toBe(1);

    // All nine download, each its own file.
    foreach ($links as $link) {
        $response = $this->get($link)->assertOk();
        expect($response->baseResponse)->toBeInstanceOf(StreamedResponse::class);
    }

    expect($grant->fresh()->download_count)->toBe(9)
        ->and($grant->downloads()->whereNotNull('file_key')->count())->toBe(9);
});

it('writes working links into the plain-text part, not HTML-escaped ones', function () {
    // `{{ }}` in a text view turns `&` into `&amp;`, which breaks the signed
    // URL's query string: the link in the text part answered 403.
    requestAndLoadGrant(makeMultiResource());

    $messages = app('mailer')->getSymfonyTransport()->messages();
    $text = $messages[count($messages) - 1]->getOriginalMessage()->getTextBody();

    expect($text)->not->toContain('&amp;');

    preg_match_all('#https?://\S+/download/\d+/[a-z0-9]+\?\S+#', $text, $matches);

    expect($matches[0])->toHaveCount(9);

    foreach ($matches[0] as $link) {
        $this->get($link)->assertOk();
    }
});

it('writes a working link into the plain-text part of a single-file mail', function () {
    requestAndLoadGrant(makeResource(['handle' => 'solo-text', 'requires_confirmation' => false, 'file_path' => 'solo-text.txt']));

    $messages = app('mailer')->getSymfonyTransport()->messages();
    $text = $messages[count($messages) - 1]->getOriginalMessage()->getTextBody();

    preg_match('#https?://\S+/download/\d+\?\S+#', $text, $match);

    expect($text)->not->toContain('&amp;');

    $this->get($match[0])->assertOk();
});

it('serves the file a link names and no other', function () {
    $resource = makeMultiResource();
    $grant = requestAndLoadGrant($resource);

    $second = $resource->fileList()[1];
    $url = app(DownloadLink::class)->forFile($grant, $second['key']);

    $response = $this->get($url)->assertOk();

    expect($response->streamedContent())->toBe('content of '.$second['path']);
});

it('names each download by group and label, so the same label in three groups stays three files', function () {
    $resource = makeMultiResource();
    $grant = requestAndLoadGrant($resource);
    $links = app(DownloadLink::class);

    // barayeFiles() row 0 is SATB / "Partitur (PDF)", row 1 is SSA / "Partitur (PDF)".
    $this->get($links->forFile($grant, $resource->fileList()[0]['key']))->assertDownload('Baraye - SATB - Partitur PDF.dat');
    $this->get($links->forFile($grant, $resource->fileList()[1]['key']))->assertDownload('Baraye - SSA - Partitur PDF.dat');
});

it('leaves the group out of the name when there is none, and spells umlauts out in the ASCII fallback', function () {
    $resource = makeMultiResource([
        'handle' => 'umlaut',
        'title' => 'Baraye Arrangement',
        'files' => [
            ['key' => 'um000001', 'path' => 'a.pdf', 'label' => 'Übe-MP3s', 'group' => 'Hohe Stimme'],
            ['key' => 'um000002', 'path' => 'b.pdf', 'label' => 'Straße', 'group' => null],
        ],
    ]);
    $grant = requestAndLoadGrant($resource);
    $links = app(DownloadLink::class);

    $header = $this->get($links->forFile($grant, 'um000001'))->assertOk()->headers->get('Content-Disposition');

    expect($header)->toContain('filename=')
        // The ASCII fallback: German spelling, not "Ube".
        ->and($header)->toContain('Baraye Arrangement - Hohe Stimme - Uebe-MP3s.pdf')
        // The real name, UTF-8 encoded for clients that read it.
        ->and(urldecode($header))->toContain("filename*=utf-8''Baraye Arrangement - Hohe Stimme - Übe-MP3s.pdf");

    $this->get($links->forFile($grant, 'um000002'))->assertDownload('Baraye Arrangement - Strasse.pdf');
});

it('refuses the file route for a pending, lapsed or revoked grant', function (string $state) {
    $resource = makeMultiResource(['requires_confirmation' => true]);
    $key = $resource->fileList()[0]['key'];

    $grant = makeGrant($resource, 'gate@example.invalid', EntitlementState::from($state));

    // A link signed for a grant that does not (or no longer) stand: the
    // signature holds, the access does not.
    $this->get(app(DownloadLink::class)->forFile($grant, $key))->assertForbidden();

    expect($grant->fresh()->download_count)->toBe(0);
})->with(['pending', 'expired', 'revoked']);

it('refuses the overview page for a pending, lapsed or revoked grant', function (string $state) {
    $resource = makeMultiResource(['requires_confirmation' => true]);

    $grant = makeGrant($resource, 'gate@example.invalid', EntitlementState::from($state));

    $this->get(app(DownloadLink::class)->for($grant))->assertForbidden();
})->with(['pending', 'expired', 'revoked']);

it('says in the log when a published delivery view does not list the files', function () {
    $dir = sys_get_temp_dir().'/lm-views-'.uniqid();
    mkdir($dir.'/mail', 0777, true);
    file_put_contents($dir.'/mail/delivery.blade.php', '<p><a href="{{ $downloadUrl }}">Download</a></p>');
    app('view')->getFinder()->prependNamespace('lead-magnets', [$dir]);

    Log::spy();

    requestAndLoadGrant(makeMultiResource());

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'published delivery view') && str_contains($message, 'vendor:publish'))->once();

    unlink($dir.'/mail/delivery.blade.php');
    rmdir($dir.'/mail');
    rmdir($dir);
});

it('says nothing in the log when the delivery view lists the files', function () {
    Log::spy();

    requestAndLoadGrant(makeMultiResource());

    Log::shouldNotHaveReceived('warning');
});

it('groups by first appearance and keeps list order inside a group', function () {
    $resource = makeMultiResource();
    $grant = requestAndLoadGrant($resource);

    $groups = app(DownloadLink::class)->groupedFor($grant);

    expect(array_column($groups, 'name'))->toBe(['SATB', 'SSA', 'TTBB']);

    foreach ($groups as $group) {
        expect(array_column($group['files'], 'label'))->toBe(['Partitur (PDF)', 'MusicXML', 'Übe-MP3s (ZIP)']);
    }
});

it('shows ungrouped files without a heading, in list order', function () {
    $resource = makeMultiResource([
        'handle' => 'mixed',
        'files' => [
            ['key' => 'k0000001', 'path' => 'a.pdf', 'label' => 'Loose', 'group' => null],
            ['key' => 'k0000002', 'path' => 'b.pdf', 'label' => 'In group', 'group' => 'Alto'],
            ['key' => 'k0000003', 'path' => 'c.pdf', 'label' => 'Also loose', 'group' => ''],
        ],
    ]);

    $groups = app(DownloadLink::class)->groupedFor(requestAndLoadGrant($resource));

    expect($groups)->toHaveCount(2)
        ->and($groups[0]['name'])->toBeNull()
        ->and(array_column($groups[0]['files'], 'label'))->toBe(['Loose', 'Also loose'])
        ->and($groups[1]['name'])->toBe('Alto');
});

it('falls back to the file name when a row has no label', function () {
    $resource = makeMultiResource([
        'handle' => 'unlabelled',
        'files' => [
            ['key' => 'u0000001', 'path' => 'sheet/Baraye-SATB.pdf', 'label' => null, 'group' => null],
            ['key' => 'u0000002', 'path' => 'sheet/Baraye-SSA.pdf', 'label' => null, 'group' => null],
        ],
    ]);

    $groups = app(DownloadLink::class)->groupedFor(requestAndLoadGrant($resource));

    expect(array_column($groups[0]['files'], 'label'))->toBe(['Baraye-SATB.pdf', 'Baraye-SSA.pdf']);
});

it('escapes labels in the mail', function () {
    $resource = makeMultiResource([
        'handle' => 'xss',
        'files' => [
            ['key' => 'x0000001', 'path' => 'a.pdf', 'label' => '<script>alert(1)</script>', 'group' => '<b>G</b>'],
            ['key' => 'x0000002', 'path' => 'b.pdf', 'label' => 'B', 'group' => '<b>G</b>'],
        ],
    ]);

    requestAndLoadGrant($resource);

    // The HTML part, raw and not entity-decoded: decoding would turn the
    // escaped text back into the markup this asserts is absent. The text part
    // is plain text and carries labels as typed.
    $messages = app('mailer')->getSymfonyTransport()->messages();
    $body = $messages[count($messages) - 1]->getOriginalMessage()->getHtmlBody();

    expect($body)->toContain('&lt;b&gt;G&lt;/b&gt;')
        ->and($body)->not->toContain('<script>alert(1)</script>')
        ->and($body)->not->toContain('<b>G</b>');
});

// ------------------------------------------------------- signature and expiry

it('refuses an expired per-file link', function () {
    $resource = makeMultiResource(['link_ttl' => 60]);
    $grant = requestAndLoadGrant($resource);

    $url = app(DownloadLink::class)->forFile($grant, $resource->fileList()[0]['key']);

    $this->get($url)->assertOk();

    Carbon::setTestNow(Carbon::now()->addMinutes(61));

    $this->get($url)->assertForbidden();

    Carbon::setTestNow();
});

it('refuses a per-file link whose file key or grant was edited', function () {
    $resource = makeMultiResource();
    $grant = requestAndLoadGrant($resource);
    $other = makeGrant($resource, 'someone-else@example.invalid');

    [$a, $b] = [$resource->fileList()[0]['key'], $resource->fileList()[1]['key']];
    $url = app(DownloadLink::class)->forFile($grant, $a);

    $this->get(str_replace('/'.$a.'?', '/'.$b.'?', $url))->assertForbidden();
    $this->get(str_replace('/download/'.$grant->id.'/', '/download/'.$other->id.'/', $url))->assertForbidden();
    $this->get(preg_replace('#\?.*$#', '', $url))->assertForbidden();
});

it('does not outlive the grant it belongs to', function () {
    $resource = makeMultiResource(['grant_ttl_days' => 1]);
    $grant = requestAndLoadGrant($resource);

    $url = app(DownloadLink::class)->forFile($grant, $resource->fileList()[0]['key']);

    parse_str(parse_url($url, PHP_URL_QUERY), $query);

    expect((int) $query['expires'])->toBeLessThanOrEqual(Carbon::now()->addDay()->timestamp);
});

it('refuses a link for a revoked grant', function () {
    $resource = makeMultiResource();
    $grant = requestAndLoadGrant($resource);
    $url = app(DownloadLink::class)->forFile($grant, $resource->fileList()[0]['key']);

    app(GrantService::class)->revoke($grant, 'test');

    $this->get($url)->assertForbidden();
});

it('answers 404 for a key the resource does not have, even when signed', function () {
    $resource = makeMultiResource();
    $grant = requestAndLoadGrant($resource);

    $this->get(app(DownloadLink::class)->forFile($grant, 'nosuchkey'))->assertNotFound();
});

// -------------------------------------------------------------- overview page

it('renders the overview page on the one signed link, with fresh per-file links', function () {
    $resource = makeMultiResource();
    $grant = requestAndLoadGrant($resource);

    $page = app(DownloadLink::class)->for($grant);

    $response = $this->get($page)->assertOk();

    $html = $response->getContent();

    expect(fileLinksIn($html))->toHaveCount(9)
        ->and($html)->toContain('SATB')->toContain('TTBB');

    // Opening the page is not a download.
    expect($grant->fresh()->download_count)->toBe(0);

    $this->get(fileLinksIn($html)[0])->assertOk();
});

it('caps per-file links on the page at the page link\'s own expiry', function () {
    $resource = makeMultiResource(['link_ttl' => 60]);
    $grant = requestAndLoadGrant($resource);

    $page = app(DownloadLink::class)->for($grant);

    parse_str(parse_url($page, PHP_URL_QUERY), $pageQuery);

    Carbon::setTestNow(Carbon::now()->addMinutes(30));

    $html = $this->get($page)->assertOk()->getContent();

    foreach (fileLinksIn($html) as $link) {
        parse_str(parse_url($link, PHP_URL_QUERY), $query);
        expect((int) $query['expires'])->toBeLessThanOrEqual((int) $pageQuery['expires']);
    }

    Carbon::setTestNow();
});

it('refuses a tampered or expired overview link', function () {
    $resource = makeMultiResource(['link_ttl' => 60]);
    $grant = requestAndLoadGrant($resource);
    $page = app(DownloadLink::class)->for($grant);

    $this->get($page.'&x=1')->assertForbidden();

    Carbon::setTestNow(Carbon::now()->addMinutes(61));
    $this->get($page)->assertForbidden();
    Carbon::setTestNow();
});

// -------------------------------------------------------------- download cap

it('counts the cap per file for a multi-file freebie', function () {
    $resource = makeMultiResource(['max_downloads' => 2]);
    $grant = requestAndLoadGrant($resource);

    [$a, $b] = [$resource->fileList()[0]['key'], $resource->fileList()[1]['key']];
    $links = app(DownloadLink::class);

    $this->get($links->forFile($grant, $a))->assertOk();
    $this->get($links->forFile($grant, $a))->assertOk();
    $this->get($links->forFile($grant, $a))->assertForbidden();

    // The other files are untouched, and so is the page.
    $this->get($links->forFile($grant, $b))->assertOk();
    $this->get($links->for($grant->fresh()))->assertOk();
});

// ------------------------------------------------------------------ re-sending

it('resends the same list with fresh links', function () {
    $resource = makeMultiResource();
    $grant = requestAndLoadGrant($resource);
    $before = sentMailCount();

    expect(app(DeliveryService::class)->deliver($grant))->toBeTrue()
        ->and(sentMailCount())->toBe($before + 1)
        ->and(fileLinksIn(lastDeliveryMailBody()))->toHaveCount(9);
});

// -------------------------------------------------------------------------- CP

describe('Control Panel', function () {
    beforeEach(function () {
        Gate::before(fn () => true);

        $manager = User::make()->email('cp-multi@example.invalid');
        $manager->save();

        $this->actingAs($manager);
    });

    /**
     * What the form sends: a group once, its files underneath. Three groups of
     * three for nine files; fewer files fill the groups in order.
     *
     * @return list<array{group: string, files: list<array{file: string, label: string}>}>
     */
    function groupsPayload(int $count = 9): array
    {
        $blocks = [];

        // barayeFiles() is interleaved on purpose; the form's blocks are not.
        $files = collect(array_slice(barayeFiles(), 0, $count))->sortBy('group')->values();

        foreach ($files as $file) {
            $blocks[$file['group']] ??= ['group' => $file['group'], 'files' => []];
            $blocks[$file['group']]['files'][] = [
                'file' => makeMagnetAsset(basename($file['path']))->id(),
                'label' => $file['label'],
            ];
        }

        return array_values($blocks);
    }

    /** The flat list the first release took. */
    function flatPayload(int $count = 3): array
    {
        $rows = [];

        foreach (groupsPayload($count) as $block) {
            foreach ($block['files'] as $file) {
                $rows[] = $file + ['group' => $block['group']];
            }
        }

        return $rows;
    }

    it('stores the blocks as one ordered list and keeps the first path in file_path', function () {
        $this->post(cp_route('lead-magnets.resources.store'), [
            'title' => 'Baraye',
            'delivery_type' => 'file',
            'groups' => groupsPayload(),
        ])->assertRedirect();

        $resource = Resource::query()->sole();
        $list = $resource->fileList();

        expect($list)->toHaveCount(9)
            ->and(array_column($list, 'group'))->toBe(['SATB', 'SATB', 'SATB', 'SSA', 'SSA', 'SSA', 'TTBB', 'TTBB', 'TTBB'])
            ->and($list[0]['label'])->toBe('Partitur (PDF)')
            ->and($resource->file_path)->toBe($list[0]['path'])
            ->and(collect($list)->pluck('key')->unique())->toHaveCount(9)
            ->and($resource->hasMultipleFiles())->toBeTrue();
    });

    it('still takes the flat list of the first release', function () {
        $this->post(cp_route('lead-magnets.resources.store'), [
            'title' => 'Flat', 'delivery_type' => 'file', 'files' => flatPayload(3),
        ])->assertRedirect();

        expect(Resource::query()->sole()->fileList())->toHaveCount(3);
    });

    it('keeps a file\'s key across two saves and a swapped order, so links already mailed still work', function () {
        $blocks = groupsPayload(6);

        $this->post(cp_route('lead-magnets.resources.store'), [
            'title' => 'Six', 'delivery_type' => 'file', 'groups' => $blocks,
        ]);

        $resource = Resource::query()->sole();
        $keys = collect($resource->fileList())->pluck('key', 'path');
        $update = cp_route('lead-magnets.resources.update', $resource->id);

        // First save: unchanged. Second save: the blocks swapped and the files
        // inside the first one reversed.
        $this->patch($update, ['title' => 'Six', 'delivery_type' => 'file', 'groups' => $blocks])->assertRedirect();

        expect(collect($resource->fresh()->fileList())->pluck('key', 'path')->all())->toBe($keys->all());

        $swapped = array_reverse($blocks);
        $swapped[0]['files'] = array_reverse($swapped[0]['files']);

        $this->patch($update, ['title' => 'Six', 'delivery_type' => 'file', 'groups' => $swapped])->assertRedirect();

        $after = collect($resource->fresh()->fileList());

        expect($after->pluck('group')->unique()->values()->all())->toBe(['TTBB', 'SSA', 'SATB'])
            ->and($after->pluck('key', 'path')->sortKeys()->all())->toBe($keys->sortKeys()->all())
            ->and($after->pluck('path')->all())->not->toBe($keys->keys()->all());
    });

    it('refuses the same file twice, in one block and across blocks', function () {
        $row = groupsPayload(1)[0]['files'][0];

        $this->post(cp_route('lead-magnets.resources.store'), [
            'title' => 'Twice', 'delivery_type' => 'file',
            'groups' => [['group' => 'A', 'files' => [$row]], ['group' => 'B', 'files' => [$row]]],
        ])->assertSessionHasErrors('groups');

        $this->post(cp_route('lead-magnets.resources.store'), [
            'title' => 'Twice', 'delivery_type' => 'file',
            'groups' => [['group' => 'A', 'files' => [$row, $row]]],
        ])->assertSessionHasErrors('groups');

        $this->post(cp_route('lead-magnets.resources.store'), [
            'title' => 'Twice flat', 'delivery_type' => 'file', 'files' => [$row, $row],
        ])->assertSessionHasErrors('files');

        expect(Resource::query()->count())->toBe(0);
    });

    it('refuses an asset from another container', function () {
        $this->post(cp_route('lead-magnets.resources.store'), [
            'title' => 'Foreign',
            'delivery_type' => 'file',
            'groups' => [['group' => null, 'files' => [['file' => 'assets::nope.pdf', 'label' => 'x']]]],
        ])->assertSessionHasErrors('groups.0.files.0.file');
    });

    it('requires a file when the blocks are all empty on a new resource', function () {
        $this->post(cp_route('lead-magnets.resources.store'), [
            'title' => 'Empty', 'delivery_type' => 'file', 'groups' => [['group' => 'A', 'files' => []]],
        ])->assertSessionHasErrors('file_asset');
    });

    it('still accepts the old single file_asset field', function () {
        $this->post(cp_route('lead-magnets.resources.store'), [
            'title' => 'Legacy form',
            'delivery_type' => 'file',
            'file_asset' => makeMagnetAsset('legacy.pdf')->id(),
        ])->assertRedirect();

        $resource = Resource::query()->sole();

        expect($resource->file_path)->toBe('legacy.pdf')
            ->and($resource->fileList())->toHaveCount(1);
    });

    it('does not touch the file of a legacy freebie when only the title changes', function () {
        $resource = makeResource(['file_path' => 'old/hand-set.txt', 'file_disk' => 'lead-magnets']);

        $this->patch(cp_route('lead-magnets.resources.update', $resource->id), [
            'title' => 'Renamed',
            'delivery_type' => 'file',
            'files' => [],
        ])->assertRedirect();

        $fresh = $resource->fresh();

        expect($fresh->title)->toBe('Renamed')
            ->and($fresh->file_path)->toBe('old/hand-set.txt')
            ->and($fresh->getRawOriginal('files'))->toBeNull();
    });

    it('keeps the stored list when the blocks come back empty', function () {
        $resource = makeMultiResource();
        $before = $resource->fresh()->files;

        $this->patch(cp_route('lead-magnets.resources.update', $resource->id), [
            'title' => 'Renamed', 'delivery_type' => 'file', 'groups' => [],
        ])->assertRedirect();

        $fresh = $resource->fresh();

        expect($fresh->title)->toBe('Renamed')
            ->and($fresh->files)->toBe($before)
            ->and($fresh->file_path)->toBe($before[0]['path']);
    });

    it('shows the stored list as group blocks, each with its files and labels', function () {
        makeMultiResource();
        $resource = Resource::query()->sole();

        // The interleaved flat list reads as three blocks, in order of first
        // appearance: SATB, SSA, TTBB, three files each.
        $this->get(cp_route('lead-magnets.resources.show', $resource->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('lead-magnets::Resources/Show')
                ->has('fileField.values.groups', 3)
                ->where('fileField.values.groups.0.group', 'SATB')
                ->where('fileField.values.groups.1.group', 'SSA')
                ->has('fileField.values.groups.2.files', 3)
                ->where('fileField.values.groups.0.files.0.label', 'Partitur (PDF)')
            );
    });

    it('shows a legacy freebie as one block without a heading', function () {
        $resource = makeResource();

        $this->get(cp_route('lead-magnets.resources.show', $resource->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('fileField.values.groups', 1)
                ->where('fileField.values.groups.0.group', null)
                ->has('fileField.values.groups.0.files', 1)
            );
    });

    it('says how many files a resource carries on the listing', function () {
        makeMultiResource();

        $this->get(cp_route('lead-magnets.resources.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('resources.0.delivery_source', '9 files'));
    });
});
