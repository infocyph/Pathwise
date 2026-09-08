# Pathwise

Pathwise 4 is a framework-neutral PHP 8.4+ filesystem toolkit built on Flysystem 3. It combines safe local file operations with instance-scoped storage topology, hardened upload/download pipelines, archive controls, file-backed queueing, observability, retention, indexing, policy enforcement, and bounded native execution.

## Requirements

- PHP `>=8.4`
- `ext-fileinfo`
- `league/flysystem ^3.35.2`
- `psr/log ^3.0.2`

ZIP, POSIX ownership, XML parsing, and remote Flysystem adapters are optional capabilities. Install only the extensions/adapters your application uses.

```bash
composer require infocyph/pathwise:^4.0
```

## Storage topology

Use `StorageContext` for applications, workers, long-lived runtimes, or any process that can host more than one storage topology. Contexts do not register process-global mounts.

```php
use Infocyph\Pathwise\Storage\StorageContext;

$storage = new StorageContext([
    'primary' => ['driver' => 'local', 'root' => '/srv/app/storage'],
    'archive' => ['driver' => 'local', 'root' => '/srv/app/archive'],
], 'primary');

[$filesystem, $location] = $storage->resolve('archive://reports/q1.txt');
$filesystem->write($location, "ready\n");

$local = $storage->localPath('documents/readme.txt');
```

`StorageFactory::createFilesystem()` remains the stateless constructor for built-in/official Flysystem adapters. Custom driver factories belong to a `StorageContext`, not a global registry.

## File and directory operations

```php
use Infocyph\Pathwise\PathwiseFacade;

$file = PathwiseFacade::at('/tmp/example.txt')->file();
$file->create("v1\n")->append("v2\n");

$report = PathwiseFacade::at('/tmp/source')
    ->directory()
    ->syncTo('/tmp/backup', deleteOrphans: true);
```

The facade is stateless convenience. Persistent storage topology belongs to `StorageContext`.

## Framework-neutral uploads

```php
use Infocyph\Pathwise\StreamHandler\MalwareScanMode;
use Infocyph\Pathwise\StreamHandler\UploadProcessor;
use Infocyph\Pathwise\StreamHandler\UploadSource;

$uploader = new UploadProcessor();
$uploader->setStorageContext($storage);
$uploader->setDirectorySettings('primary://uploads', tempDir: sys_get_temp_dir());
$uploader->setValidationProfile('document');
$uploader->setMalwareScanMode(MalwareScanMode::REQUIRED);
$uploader->setMalwareScanner($scanner);

$source = UploadSource::fromMover(
    mover: fn (string $target): void => $uploadedFile->moveTo($target),
    clientFilename: $uploadedFile->getClientFilename() ?? 'upload.bin',
    size: $uploadedFile->getSize(),
    clientMediaType: $uploadedFile->getClientMediaType(),
    error: $uploadedFile->getError(),
);

$path = $uploader->ingestSource($source);
```

Pathwise owns the staging file created for `UploadSource`, cleans it on success/failure, scans before content parsing, and fails closed when malware scanning is required.

## Secure downloads and ranges

```php
use Infocyph\Pathwise\StreamHandler\DownloadProcessor;

$downloads = new DownloadProcessor();
$downloads->setStorageContext($storage);
$downloads->setAllowedRoots(['primary://downloads']);

$prepared = $downloads->prepareDownload(
    'primary://downloads/video.mp4',
    rangeHeader: $_SERVER['HTTP_RANGE'] ?? null,
);

foreach ($downloads->streamChunks($prepared) as $chunk) {
    echo $chunk;
}
```

`DownloadPreparation` carries status/headers/range metadata. `streamChunks()` revalidates the preparation, reads exactly the prepared range, and closes the source stream even when iteration ends early.

## Durable local file queue

```php
use Infocyph\Pathwise\Queue\FileJobQueue;

$queue = new FileJobQueue('/var/lib/app/jobs.json');
$queue->enqueue('thumbnail', ['id' => 'asset-42']);

$reservation = $queue->reserve();
if ($reservation !== null) {
    // Work with $reservation->payload, renew long jobs when required.
    $queue->acknowledge($reservation);
}
```

The queue is intentionally direct-local: it uses typed opaque leases, stale-worker rejection, strict versioned state, locking, and crash-safe persistence. It is not a distributed broker.

## Security model

Pathwise 4 includes explicit controls for:

- extension/MIME/signature validation and optional malware scanning;
- path/root restrictions, hidden-file blocking, safe symlink management;
- ZIP manifest validation, traversal/collision/special-entry rejection and extraction limits;
- bounded native commands with timeout/output ceilings and deterministic cleanup;
- safe serialization boundaries that do not instantiate untrusted objects;
- queue state size/payload/job limits and lease ownership;
- policy enforcement, audit sinks, retention, indexing, and watcher workloads.

Security-sensitive behavior is fail-closed where a configured capability is required. Adapter/native/metadata capabilities remain explicit rather than silently emulated.

## Documentation

The Sphinx documentation is the canonical user guide and is built in CI with warnings treated as errors. Start with:

- `docs/quickstart.rst`
- `docs/storage-context.rst`
- `docs/upload-processing.rst`
- `docs/download-processing.rst`
- `docs/security.rst`
- `docs/migration-4.0.rst`
- `docs/api-reference.rst`
- `docs/performance-portability.rst`

## Development

```bash
composer install
composer ic:test:code
composer ic:qa
```

The release matrix covers PHP 8.4/8.5, stable and lowest dependencies, Windows, optional adapter contracts, static analysis/quality gates, clean install, documentation, and release workloads.

## License

MIT
