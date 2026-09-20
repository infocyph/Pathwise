# Pathwise

[![Security & Standards](https://github.com/infocyph/Pathwise/actions/workflows/security-standards.yml/badge.svg)](https://github.com/infocyph/Pathwise/actions/workflows/security-standards.yml)
![Packagist Downloads](https://img.shields.io/packagist/dt/infocyph/Pathwise?color=green\&link=https%3A%2F%2Fpackagist.org%2Fpackages%2Finfocyph%2Fpathwise)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](https://opensource.org/licenses/MIT)
![Packagist Version](https://img.shields.io/packagist/v/infocyph/pathwise)
![Packagist PHP Version](https://img.shields.io/packagist/dependency-v/infocyph/pathwise/php)
![GitHub Code Size](https://img.shields.io/github/languages/code-size/infocyph/Pathwise)
[![Documentation](https://img.shields.io/badge/Documentation-Pathwise-blue?logo=readthedocs&logoColor=white)](https://docs.infocyph.com/projects/Pathwise/)


Pathwise 4 is a framework-neutral PHP 8.4+ filesystem toolkit built on Flysystem 3. It combines safe local file operations with instance-scoped storage topology, hardened upload/download pipelines, archive controls, file-backed queueing, observability, retention, indexing, policy enforcement, and bounded trusted-local native filesystem acceleration.

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
use Infocyph\Pathwise\StreamHandler\UploadTrustProfile;

$uploader = new UploadProcessor();
$uploader->setStorageContext($storage);
$uploader->setTrustProfile(UploadTrustProfile::UNTRUSTED_DATA);
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

The strict untrusted-data profile uses private bounded staging, server-generated naming, content checks, controlled publication, and restrictive local permissions. Scanner policy remains explicit: `REQUIRED` fails closed. The client filename is metadata only and never becomes the authoritative destination path.

## Trusted public/static files

Do not map a raw URL directly to disk. Let application/Webrick policy choose the public root and candidate name, then resolve that relative candidate through Pathwise:

```php
use Infocyph\Pathwise\StreamHandler\PublicFileResolver;

$asset = (new PublicFileResolver())->resolve(
    '/srv/app/public',
    'assets/app.css',
);

// $asset->path is canonically contained and can now feed DownloadProcessor
// or an already-authorized response/file writer.
```

Traversal/root escape fails closed and symlink policy is explicit. Route eligibility, dotfile policy, HTTP caching/ranges, and transport remain Webrick/application concerns.

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

- canonical path/root containment and explicit public-root resolution;
- private upload staging, extension/MIME/signature validation, controlled publication and optional/required malware scanning;
- ZIP manifest validation, traversal/collision/special-entry rejection, path/name/depth bounds and extraction resource limits;
- trusted direct-local native filesystem acceleration with shell-free argv, timeout/output ceilings and deterministic cleanup;
- safe serialization boundaries that do not instantiate untrusted objects;
- queue state size/payload/job limits and lease ownership;
- policy enforcement, audit sinks, retention, indexing, and watcher workloads.

Generic application use of `NativeCommandRunner` is deprecated in 4.1; Foundation/application process work belongs to Runwire. Pathwise has no production dependency on Runwire, Webrick, Foundation, InterMix, or ReqShield.

Security-sensitive behavior is fail-closed where a configured capability is required. Local/remote capabilities remain explicit rather than silently emulated. Pathwise makes filesystem artifacts safe to treat as **data according to policy**; it does not make arbitrary uploaded/source/binary content safe to execute. See the documentation's **Trust Boundaries and Persistent Runtimes** guide for the full ownership model.


## Security

Do not disclose suspected vulnerabilities in a public issue, discussion or pull request. Follow [SECURITY.md](SECURITY.md) and use [GitHub private vulnerability reporting](https://github.com/infocyph/Pathwise/security/advisories/new).

Pathwise is protected by [PHPForge](https://github.com/infocyph/PHPForge), which provides automated tests, static and taint analysis, dependency auditing, architecture checks and release-readiness gates. Automated controls do not replace responsible disclosure or manual review.


---

<div align="center">
  <sub><strong>Made with ❤️ for the PHP community</strong></sub><br />
  <sub><a href="LICENSE">MIT Licensed</a></sub><br />
  <a href="https://docs.infocyph.com/projects/Pathwise/">Documentation</a> •
  <a href="SECURITY.md">Security</a> •
  <a href="CODE_OF_CONDUCT.md">Code of Conduct</a> •
  <a href="CONTRIBUTING.md">Contributing</a><br />
  <span title="Issue templates" aria-label="Issue templates">🗂️</span>
  <a href="https://github.com/infocyph/Pathwise/issues/new?template=bug_report.yml">Bug</a> •
  <a href="https://github.com/infocyph/Pathwise/issues/new?template=feature_request.yml">Feature</a> •
  <a href="https://github.com/infocyph/Pathwise/issues/new?template=docs_improvement.yml">Documentation</a> •
  <a href="https://github.com/infocyph/Pathwise/issues/new?template=question.yml">Question</a> •
  <a href="https://github.com/infocyph/Pathwise/issues/new?template=ci_failure.yml">CI failure</a><br />
  <span title="Pull request templates" aria-label="Pull request templates">🔀</span>
  <a href="https://github.com/infocyph/Pathwise/compare/main...HEAD?quick_pull=1&amp;template=PULL_REQUEST_TEMPLATE.md">General</a> •
  <a href="https://github.com/infocyph/Pathwise/compare/main...HEAD?quick_pull=1&amp;template=bug_fix.md">Bug fix</a> •
  <a href="https://github.com/infocyph/Pathwise/compare/main...HEAD?quick_pull=1&amp;template=feature.md">Feature</a> •
  <a href="https://github.com/infocyph/Pathwise/compare/main...HEAD?quick_pull=1&amp;template=refactor.md">Refactor</a> •
  <a href="https://github.com/infocyph/Pathwise/compare/main...HEAD?quick_pull=1&amp;template=performance.md">Performance</a> •
  <a href="https://github.com/infocyph/Pathwise/compare/main...HEAD?quick_pull=1&amp;template=security_reliability.md">Security &amp; reliability</a> •
  <a href="https://github.com/infocyph/Pathwise/compare/main...HEAD?quick_pull=1&amp;template=documentation.md">Documentation</a> •
  <a href="https://github.com/infocyph/Pathwise/compare/main...HEAD?quick_pull=1&amp;template=maintenance.md">Maintenance</a>
</div>
