<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Infocyph\Foundation\Filesystem\FilesystemTransferFactory;
use Infocyph\Foundation\Filesystem\StorageRegistry;
use Infocyph\Foundation\Foundation;
use Infocyph\Pathwise\Integration\Runwire\RunwireExecutionContext;
use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\RuntimeCapabilities;
use Infocyph\Runwire\RuntimeContext;

function requireHost(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function cleanupHost(string $root): void
{
    if (!is_dir($root)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}

function makeHost(string $root): array
{
    mkdir($root, 0700, true);
    $app = Foundation::web([
        'base_path' => $root,
        '_config_cache' => false,
        'router' => ['cache' => false],
    ]);
    $app->boot();
    $storage = $app->make(StorageRegistry::class);
    $storage->disk('uploads')->write('host/fixture.txt', 'host-proof');

    return [$app, $storage, $app->make(FilesystemTransferFactory::class)->download('host', 'uploads')];
}

$rootA = sys_get_temp_dir() . '/pathwise-foundation3-a-' . bin2hex(random_bytes(5));
$rootB = sys_get_temp_dir() . '/pathwise-foundation3-b-' . bin2hex(random_bytes(5));

try {
    [$appA, $storageA, $downA] = makeHost($rootA);
    [$appB, $storageB, $downB] = makeHost($rootB);
    $storageB->disk('uploads')->write('host/fixture.txt', 'other-host');

    requireHost($storageA->disk('uploads')->read('host/fixture.txt') === 'host-proof', 'Host A storage changed.');
    requireHost($storageB->disk('uploads')->read('host/fixture.txt') === 'other-host', 'Host B storage changed.');

    $capabilities = new RuntimeCapabilities(RuntimeDriver::NATIVE, supportsRunwireCoroutines: true);
    $runtime = RuntimeContext::fromCapabilities($capabilities, 'foundation3-host', generation: 1);
    $requestA = RequestContext::create($runtime);
    $requestB = RequestContext::create($runtime);
    $runwire = new CoroutineRuntime();
    $records = [];

    $runwire->run(function (CoroutineScope $scope) use (
        $runtime, $requestA, $requestB, $downA, $downB, &$records,
    ): void {
        $forward = static function (
            RunwireExecutionContext $borrowed,
            object $owner,
            string $path,
        ): string {
            return $owner->withRunwire(
                $borrowed,
                static function ($download) use ($path): string {
                    $prepared = $download->prepareDownload($path);

                    return implode('', iterator_to_array($download->streamChunks($prepared), false));
                },
            );
        };

        $contextA = new RunwireExecutionContext($runtime, $requestA, $scope, checkpointEvery: 1);
        $contextB = new RunwireExecutionContext($runtime, $requestB, $scope, checkpointEvery: 1);

        $one = $scope->spawn(function () use ($forward, $contextA, $downA, &$records): void {
            $records['a'] = $forward($contextA, $downA, 'uploads://host/fixture.txt');
        });
        $two = $scope->spawn(function () use ($forward, $contextB, $downB, &$records): void {
            $records['b'] = $forward($contextB, $downB, 'uploads://host/fixture.txt');
        });
        $one->await();
        $two->await();

        requireHost($records['a'] === 'host-proof', 'Request A leaked storage context.');
        requireHost($records['b'] === 'other-host', 'Request B leaked storage context.');

        $requestA->cancel(CancellationReason::HOST_CANCELLED);
        $blocked = false;
        try {
            $forward($contextA, $downA, 'uploads://host/fixture.txt');
        } catch (CancelledException) {
            $blocked = true;
        }
        requireHost($blocked, 'Cancelled request was allowed to reenter Pathwise.');
        requireHost($forward($contextB, $downB, 'uploads://host/fixture.txt') === 'other-host',
            'Request B became contaminated by request A cancellation.');
    });

    requireHost(!$requestB->completed(), 'Pathwise completed a host-owned request.');
    $requestA->complete();
    $requestB->complete();
    echo "Foundation 3.0.1 / Runwire 2.1.1 host integration, isolation and cancellation PASS\n";
} finally {
    cleanupHost($rootA);
    cleanupHost($rootB);
}
