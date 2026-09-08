Migrating from Pathwise 3.x to 4.0
==================================

Pathwise 4 is a major release. Compatibility was not preserved where the 3.x
surface encouraged process-global state, ambiguous security behavior, or unsafe
ownership assumptions. Migrate deliberately rather than changing only the
Composer constraint.

Runtime Requirements
--------------------

Pathwise 4 requires PHP 8.4+ and declares its production interfaces directly,
including ``psr/log ^3``. Optional Flysystem adapters and optional PHP
extensions remain capability-specific.

Storage: Global Topology -> StorageContext
------------------------------------------

The most important architecture change is storage topology ownership.

Removed/de-emphasized 3.x patterns include:

* ``StorageFactory::mount()``;
* ``StorageFactory::mountMany()``;
* process-global ``StorageFactory`` custom-driver registration;
* facade mount gateways that owned persistent topology.

Pathwise 4 keeps ``StorageFactory::createFilesystem()`` as a stateless
constructor and uses ``StorageContext`` for named application storage.

Before (3.x):

.. code-block:: php

   StorageFactory::mount('files', [
       'driver' => 'local',
       'root' => '/srv/app/files',
   ]);

After (4.0):

.. code-block:: php

   use Infocyph\Pathwise\Storage\StorageContext;

   $storage = new StorageContext([
       'files' => [
           'driver' => 'local',
           'root' => '/srv/app/files',
       ],
   ], 'files');

   [$filesystem, $location] = $storage->resolve('files://documents/a.txt');

``StorageContext`` owns its operators and custom drivers. It never registers a
global mount, so multiple applications/generations can reuse logical names
without cross-talk.

Upload/Download Processors and StorageContext
---------------------------------------------

If a processor uses relative or ``name://`` paths, inject the context **before**
path-dependent settings:

.. code-block:: php

   $uploader->setStorageContext($storage);
   $uploader->setDirectorySettings('files://uploads', tempDir: sys_get_temp_dir());

   $downloads->setStorageContext($storage);
   $downloads->setAllowedRoots(['files://downloads']);

Direct absolute paths remain local. Processor context routing does not mutate
``FlysystemHelper`` global state.

Framework Uploads: Synthetic Arrays -> UploadSource
---------------------------------------------------

``processUpload()`` remains for genuine PHP HTTP uploads and keeps
``is_uploaded_file()`` provenance. Framework uploaded-file abstractions should
use ``UploadSource`` instead of first materializing a temporary file in the
framework integration layer.

.. code-block:: php

   $source = UploadSource::fromMover(
       fn (string $target): void => $uploadedFile->moveTo($target),
       $uploadedFile->getClientFilename() ?? 'upload.bin',
       $uploadedFile->getSize(),
       $uploadedFile->getClientMediaType(),
       $uploadedFile->getError(),
   );

   $path = $uploader->ingestSource($source);

Ownership is explicit: borrowed paths survive, owned paths are consumed after
successful staging, caller streams are never closed, and Pathwise-owned staging
state is cleaned after success/failure.

Malware Scanner: Callable -> Typed Contract
-------------------------------------------

Pathwise 4 uses ``MalwareScannerInterface``:

.. code-block:: php

   use Infocyph\Pathwise\StreamHandler\MalwareScanMode;
   use Infocyph\Pathwise\StreamHandler\MalwareScannerInterface;

   $uploader->setMalwareScanner($scanner); // MalwareScannerInterface
   $uploader->setMalwareScanMode(MalwareScanMode::REQUIRED);

Scanner behavior is now explicit through ``MalwareScanRequest`` and
``MalwareScanVerdict``. A required scanner fails closed. Scanner exceptions are
not copied into the public upload error text, and Pathwise verifies that a
scanner did not mutate its scan input.

PolicyEngine Is Deny-by-Default
-------------------------------

``PolicyEngine`` now starts with ``defaultAllow: false``. An unmatched operation
is denied.

.. code-block:: php

   $policy = (new PolicyEngine())
       ->allow('read', '/srv/public/*')
       ->deny('read', '/srv/public/private/*');

Rules use last-match-wins precedence. If an intentionally permissive policy is
required, construct ``new PolicyEngine(defaultAllow: true)`` explicitly.

Queue API: Raw Jobs -> Typed Leases
-----------------------------------

File queue reservations are ownership capabilities in 4.0. ``reserve()``
returns ``?QueueReservation``. Pass that reservation to ``renew()``,
``acknowledge()``, ``release()``, or ``fail()``.

.. code-block:: php

   $reservation = $queue->reserve();
   if ($reservation !== null) {
       try {
           handle($reservation->payload);
           $queue->acknowledge($reservation);
       } catch (Throwable $failure) {
           $queue->fail($reservation, $failure);
       }
   }

Expired/stale lease tokens cannot mutate a job after a new worker owns it. Queue
state is strict/versioned and stored with bounded size/payload/job limits. The
queue is direct-local only; do not point it at object storage.

Downloads: Preparation + Iterable Body
--------------------------------------

Use ``prepareDownload()`` to obtain ``DownloadPreparation`` and
``streamChunks()`` for framework response bodies. The iterator owns source
stream closure and exact range accounting.

.. code-block:: php

   $prepared = $downloads->prepareDownload($path, rangeHeader: $range);
   foreach ($downloads->streamChunks($prepared) as $chunk) {
       yield $chunk;
   }

A preparation is revalidated before streaming; it is not an authorization
capability.

Native Execution Is Bounded
---------------------------

Native optimization is constrained by ``NativeExecutionLimits``. Defaults are
finite: 300-second timeout, 4 MiB stdout, 4 MiB stderr, a termination grace
period, and bounded polling. Execution uses argv-style invocation rather than
shell command composition. Limit/termination failures are typed and cleanup is
deterministic.

Review applications that assumed unbounded native output or indefinite command
runtime.

Archives Are Manifest-Validated
-------------------------------

ZIP extraction/processing now validates an entry manifest before publication
and enforces path, collision, entry-type, per-entry/total-size, compression-ratio
and write-time checks. Unsafe special entries and source symlinks are rejected.
Do not rely on permissive extraction of ambiguous or malformed archives.

Serialization Is a Trust Boundary
---------------------------------

Serialized-value validation is defensive parsing, not object reconstruction.
Untrusted serialized data must not be treated as a safe way to instantiate
classes. Applications that relied on implicit object creation should move that
logic outside the Pathwise validation boundary.

File Transactions and Symlinks
------------------------------

Transactional file mutation and safe symlink operations are direct-local
capabilities. Rollback failures are reported explicitly rather than being
hidden behind the original exception. ``SafeSymlinkManager`` validates allowed
link/target roots and existing targets before activation/removal.

Typed Results
-------------

Several workflows return typed result objects rather than associative arrays.
Important examples:

* ``ChunkUploadState`` — use ``$state->complete``;
* ``DownloadPreparation`` / ``RangeDownloadMetadata``;
* ``DownloadStreamResult``;
* ``QueueProcessResult``;
* ``SyncReport``;
* ``SymlinkStatus``;
* ``SnapshotDiff`` / ``WatchResult``;
* ``RetentionResult`` / ``DeduplicationResult``;
* ``NativeExecutionResult``.

Audit application code for array access such as ``$state['isComplete']``.

Facade and Global Helpers
-------------------------

``PathwiseFacade`` is stateless convenience in 4.0. It creates operation
objects/results but does not own persistent storage topology. Use direct module
classes when dependency injection or explicit lifecycle ownership is clearer.

Low-level ``FlysystemHelper`` default/mount APIs remain storage-neutral utility
mechanics for direct standalone use, but they are not the persistent-runtime
registry model. Application frameworks should use ``StorageContext`` instead.

Recommended Migration Order
---------------------------

1. Raise the runtime to PHP 8.4+ and install declared production dependencies.
2. Replace global storage topology with one ``StorageContext`` per application
   runtime/generation.
3. Inject that context into upload/download processors before path settings.
4. Replace framework upload materialization with ``UploadSource``.
5. Implement ``MalwareScannerInterface`` and choose an explicit scan mode.
6. Update policy configuration for deny-by-default semantics.
7. Convert queue consumers to typed reservations/lease renewal.
8. Convert download adapters to preparation + iterable streaming where useful.
9. Review native/archive/transaction/local-only assumptions.
10. Run the PHP 8.4/8.5, lowest/stable, Windows, optional-adapter, docs and
    release-workload gates before deployment.

See :doc:`api-reference`, :doc:`security`, and
:doc:`performance-portability` for final 4.0 contracts.
