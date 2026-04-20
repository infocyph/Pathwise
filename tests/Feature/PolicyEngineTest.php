<?php

use Infocyph\Pathwise\Exceptions\PolicyViolationException;
use Infocyph\Pathwise\Security\PolicyEngine;

test('it applies allow and deny rules with last match winning', function () {
    $policy = new PolicyEngine();
    $policy->deny('*', '*');
    $policy->allow('read', '/tmp/*');

    expect($policy->isAllowed('read', '/tmp/file.txt'))->toBeTrue()
        ->and($policy->isAllowed('delete', '/tmp/file.txt'))->toBeFalse();
});

test('it supports conditional policy rules', function () {
    $policy = new PolicyEngine();
    $policy->deny('delete', '*');
    $policy->allow('delete', '*', function (string $operation, string $path, array $context): bool {
        unset($operation, $path);

        return ($context['force'] ?? false) === true;
    });

    expect($policy->isAllowed('delete', '/tmp/file.txt', ['force' => false]))->toBeFalse()
        ->and($policy->isAllowed('delete', '/tmp/file.txt', ['force' => true]))->toBeTrue();
});

test('it throws on denied operations', function () {
    $policy = (new PolicyEngine())->deny('delete', '*');

    expect(fn () => $policy->assertAllowed('delete', '/tmp/file.txt'))->toThrow(PolicyViolationException::class);
});
