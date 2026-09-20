<?php

declare(strict_types=1);

use Infocyph\Pathwise\Native\NativeCommandRunner;

it('does not retain executable lookup state across persistent calls', function (): void {
    if (PHP_OS_FAMILY === 'Windows') {
        expect(NativeCommandRunner::supportsBoundedExecution())->toBeFalse();

        return;
    }

    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pathwise_native_' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create native lookup fixture.');
    }

    $command = 'pathwise-probe-' . bin2hex(random_bytes(8));
    $executable = $directory . DIRECTORY_SEPARATOR . $command;
    file_put_contents($executable, "#!/bin/sh\nexit 0\n");
    chmod($executable, 0700);
    $originalPath = getenv('PATH');

    try {
        putenv('PATH=' . $directory);
        expect(NativeCommandRunner::commandExists($command))->toBeTrue();

        putenv('PATH=');
        expect(NativeCommandRunner::commandExists($command))->toBeFalse();
    } finally {
        if (is_string($originalPath)) {
            putenv('PATH=' . $originalPath);
        } else {
            putenv('PATH');
        }
        unlink($executable);
        rmdir($directory);
    }
});
