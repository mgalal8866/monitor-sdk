<?php

use Trk\Monitor\Support\Sanitizer;

beforeEach(fn () => $this->sanitizer = new Sanitizer(config('trk-monitor.privacy.sensitive_fields')));

it('redacts sensitive keys recursively', function () {
    $out = $this->sanitizer->sanitize([
        'email' => 'a@b.c',
        'password' => 'hunter2',
        'nested' => ['deep' => ['API-Key' => 'abc', 'refresh_token' => 'x', 'name' => 'ok']],
        'user_access_token' => 'tok',
        'creditCardNumber' => '4111111111111111',
    ]);

    expect($out['password'])->toBe(Sanitizer::REDACTED)
        ->and($out['nested']['deep']['API-Key'])->toBe(Sanitizer::REDACTED)
        ->and($out['nested']['deep']['refresh_token'])->toBe(Sanitizer::REDACTED)
        ->and($out['nested']['deep']['name'])->toBe('ok')
        ->and($out['user_access_token'])->toBe(Sanitizer::REDACTED)
        ->and($out['creditCardNumber'])->toBe(Sanitizer::REDACTED)
        ->and($out['email'])->toBe('a@b.c');
});

it('redacts credentials embedded in string values', function () {
    $out = $this->sanitizer->sanitize([
        'note' => 'called with Bearer eyJhbGciOiJIUzI1NiJ9.payload.sig',
        'msg' => 'card 4111 1111 1111 1111 declined',
        'order' => 'order 1234567890123 shipped',
    ]);

    expect($out['note'])->toBe('called with Bearer '.Sanitizer::REDACTED)
        ->and($out['msg'])->toBe('card '.Sanitizer::REDACTED.' declined')
        ->and($out['order'])->toBe('order 1234567890123 shipped');
});

it('honours the allow list', function () {
    $sanitizer = new Sanitizer(['token'], ['csrf_token']);

    expect($sanitizer->sanitize(['csrf_token' => 'keep', 'token' => 'drop']))
        ->toBe(['csrf_token' => 'keep', 'token' => Sanitizer::REDACTED]);
});

it('always redacts authentication headers', function () {
    $out = $this->sanitizer->sanitizeHeaders([
        'Authorization' => ['Bearer abc.def.ghi'],
        'Cookie' => ['laravel_session=xyz'],
        'X-Api-Key' => ['k'],
        'Accept' => ['application/json'],
    ]);

    expect($out)->toBe([
        'authorization' => Sanitizer::REDACTED,
        'cookie' => Sanitizer::REDACTED,
        'x-api-key' => Sanitizer::REDACTED,
        'accept' => 'application/json',
    ]);
});

it('does not serialize arbitrary objects', function () {
    expect($this->sanitizer->sanitize(['model' => new stdClass]))->toBe(['model' => '[stdClass]']);
});
