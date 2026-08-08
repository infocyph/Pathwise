<?php

declare(strict_types=1);

test('generic global helper functions are not autoloaded', function () {
    expect(function_exists('listFiles'))->toBeFalse()
        ->and(function_exists('createDirectory'))->toBeFalse()
        ->and(function_exists('deleteDirectory'))->toBeFalse()
        ->and(function_exists('copyDirectory'))->toBeFalse()
        ->and(function_exists('createFilesystem'))->toBeFalse()
        ->and(function_exists('mountStorage'))->toBeFalse();
});
