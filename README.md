# Pathwise

[![Security & Standards](https://github.com/infocyph/Pathwise/actions/workflows/security-standards.yml/badge.svg)](https://github.com/infocyph/Pathwise/actions/workflows/security-standards.yml)
![Packagist Downloads](https://img.shields.io/packagist/dt/infocyph/Pathwise?color=green\&link=https%3A%2F%2Fpackagist.org%2Fpackages%2Finfocyph%2FPathwise)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](https://opensource.org/licenses/MIT)
![Packagist Version](https://img.shields.io/packagist/v/infocyph/Pathwise)
![Packagist PHP Version](https://img.shields.io/packagist/dependency-v/infocyph/Pathwise/php)
![GitHub Code Size](https://img.shields.io/github/languages/code-size/infocyph/Pathwise)
[![Documentation](https://img.shields.io/badge/Documentation-Pathwise-blue?logo=readthedocs&logoColor=white)](https://docs.infocyph.com/projects/Pathwise/en/latest/)

High-level PHP filesystem workflows powered by Flysystem, including safe I/O, uploads, downloads, archives, directory synchronization, retention, policy enforcement, auditing and storage adapters.

Pathwise 3.0 requires PHP 8.4 or newer. It is a direct breaking release: reader and writer methods are explicit, complex operations return readonly result objects, and unsupported mounted-storage operations fail with focused exceptions.

## Installation

```bash
composer require infocyph/pathwise
```

`ext-fileinfo` is required. ZIP and XML features check for `ext-zip`, `ext-xmlreader`, and `ext-simplexml` at runtime and report a clear `MissingExtensionException` when unavailable.

## Quick start

```php
use Infocyph\Pathwise\FileManager\FileOperations;
use Infocyph\Pathwise\FileManager\SafeFileReader;
use Infocyph\Pathwise\FileManager\SafeFileWriter;

$file = new FileOperations('/tmp/example.txt');
$file->create('hello')->append("\nworld");

foreach ((new SafeFileReader('/tmp/example.txt'))->lines() as $line) {
    echo $line;
}

$writer = new SafeFileWriter('/tmp/events.json');
$writer->writeJson(['status' => 'ready']);
$writer->close();
```

The reader exposes `lines()`, `characters()`, `chunks()`, `csv()`, `jsonLines()`, `jsonArray()`, `fixedWidth()`, `xmlElements()`, `serializedValues()`, and `matchingLines()`. The writer exposes the corresponding `write*` methods. There is no runtime `__call()` dispatch and no global helper-function autoloading.

## Storage model

```php
use Infocyph\Pathwise\Storage\StorageFactory;
use Infocyph\Pathwise\Utils\FlysystemHelper;

StorageFactory::mount('assets', [
    'driver' => 'local',
    'root' => '/srv/storage/assets',
]);

FlysystemHelper::write('assets://reports/a.txt', 'hello');
```

Storage-neutral reads, writes, copies, streams, uploads, downloads, ZIP staging, retention, and synchronization accept local, default-Flysystem, and mounted scheme paths where the adapter supplies the required capability. POSIX modes/ownership, native processes, shell searching, direct locks/handles, and transactions are local-filesystem-only and throw `UnsupportedStorageOperationException` for mounted paths.

Local `append()` uses native append mode. Mounted stores must opt into `appendEmulated()`, which visibly represents a complete object replacement. Local transactions use a structured, disk-backed rollback journal and reject nesting.

See the `storage capability contract` in the documentation for the compatibility matrix, atomicity, locking, sync, native execution, archive security, and performance characteristics.

## Synchronization and result types

```php
use Infocyph\Pathwise\Core\SyncComparison;
use Infocyph\Pathwise\DirectoryManager\DirectoryOperations;

$report = (new DirectoryOperations('/srv/source'))->syncTo(
    '/srv/target',
    deleteOrphans: true,
    comparison: SyncComparison::SIZE_AND_MODIFIED_TIME,
);
```

`syncTo()` returns a readonly `SyncReport`. Download preparation/ranges, chunk uploads, queue processing, native execution, retention, deduplication, and file watching likewise return dedicated readonly result objects rather than significant associative arrays.

## Secure archives

Every extraction path validates every ZIP member before writing. Absolute paths, Windows drive paths, null bytes, traversal segments, symbolic-link entries, extraction-root escapes, and existing destination-symlink breakouts are rejected with `UnsafeArchiveEntryException`.

## Auditing

`AuditTrail` accepts a local JSONL path or an `AuditSink`. `LocalJsonlAuditSink` uses locked append. `PartitionedAuditSink` writes one object per event and is suitable for mounted object stores. `CallbackAuditSink` integrates application loggers. Remote audit append is never silently emulated by reading and rewriting a log object.

## Native execution

`ExecutionStrategy::PHP` always uses PHP, `AUTO` may use an available native executable and fall back, and `NATIVE` either completes natively or throws `NativeExecutionException`. Native execution accepts local paths only; command arguments are escaped and execution results retain command, output, and exit code.

## Security

Do not disclose suspected vulnerabilities in a public issue, discussion or pull request. Review the
[security policy](SECURITY.md), then use [GitHub private vulnerability reporting](https://github.com/infocyph/Pathwise/security/advisories/new)
to contact the maintainers confidentially.

Pathwise is protected by [PHPForge](https://github.com/infocyph/PHPForge), an automated quality and security gate covering
tests, static and taint analysis, dependency auditing, architecture checks, and release readiness. Automated controls reduce
risk but do not replace responsible disclosure or manual review.

---

<div align="center">
  <sub><strong>Made with ❤️ for the PHP community</strong></sub><br />
  <sub><a href="LICENSE">MIT Licensed</a></sub><br />
  <a href="https://docs.infocyph.com/projects/Pathwise/en/latest/">Documentation</a> •
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
