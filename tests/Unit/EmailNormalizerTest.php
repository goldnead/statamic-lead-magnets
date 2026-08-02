<?php

use Goldnead\LeadMagnets\Support\ConfirmationToken;
use Goldnead\LeadMagnets\Support\EmailNormalizer;

it('folds case and trims so the unique index means what it says', function () {
    expect(EmailNormalizer::normalize('  Reader@Example.COM '))->toBe('reader@example.com')
        ->and(EmailNormalizer::normalize('READER@EXAMPLE.COM'))->toBe('reader@example.com');
});

it('leaves dots and plus-tags alone', function () {
    // Provider policy, not the standard. Merging them would join two addresses
    // a strict provider treats as different — and in a consent record that is
    // a mistake in the direction that matters.
    expect(EmailNormalizer::normalize('first.last+leadmagnet@example.com'))
        ->toBe('first.last+leadmagnet@example.com');
});

it('survives input that is not an address at all', function () {
    expect(EmailNormalizer::normalize(null))->toBe('')
        ->and(EmailNormalizer::normalize('  '))->toBe('')
        ->and(EmailNormalizer::normalize('NoAtSign'))->toBe('noatsign');
});

it('mints a 64-character token that is never the same twice', function () {
    $tokens = collect(range(1, 50))->map(fn () => ConfirmationToken::mint());

    expect($tokens->unique())->toHaveCount(50)
        ->and($tokens->first())->toHaveLength(64)
        ->and($tokens->first())->toMatch('/^[a-f0-9]{64}$/');
});

it('hashes one way and compares in constant time', function () {
    $token = ConfirmationToken::mint();
    $hash = ConfirmationToken::hash($token);

    expect($hash)->not->toBe($token)
        ->and($hash)->toHaveLength(64)
        ->and(ConfirmationToken::matches($token, $hash))->toBeTrue()
        ->and(ConfirmationToken::matches('wrong', $hash))->toBeFalse()
        ->and(ConfirmationToken::matches($token, null))->toBeFalse();
});
