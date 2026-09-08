Storage Capability Contract
===========================

Pathwise separates **path syntax**, **runtime topology**, and **storage
capability**. ``StorageContext`` is the preferred instance-scoped topology for
applications and persistent runtimes. Low-level ``FlysystemHelper``
default/mount routing remains available for standalone utility use.

A local Flysystem adapter is still adapter-backed when addressed through a
context/default/mount. Pathwise does not unwrap the adapter and silently claim
native filesystem guarantees that the logical storage surface cannot promise.

Compatibility Matrix
--------------------

.. list-table::
   :header-rows: 1
   :widths: 34 20 23 23

   * - Capability
     - Direct local path
     - StorageContext / adapter path
     - Low-level default/mount path
   * - Read/write/stream/copy/visibility
     - Supported
     - Adapter-dependent
     - Adapter-dependent
   * - Directory traversal and synchronization
     - Supported
     - Adapter-dependent
     - Adapter-dependent
   * - Upload/download processing
     - Supported
     - Adapter-dependent
     - Adapter-dependent
   * - ZIP creation/extraction
     - Supported
     - Streamed through bounded local staging where needed
     - Streamed through bounded local staging where needed
   * - Native append
     - Supported
     - Rejected
     - Rejected
   * - Emulated append/object replacement
     - Supported
     - Explicit ``appendEmulated()``
     - Explicit ``appendEmulated()``
   * - Transactions
     - Supported
     - Rejected
     - Rejected
   * - POSIX modes, owner, and group
     - Platform-dependent
     - Rejected as a portable storage guarantee
     - Rejected as a portable storage guarantee
   * - Direct locks/handles and native tools
     - Platform-dependent
     - Rejected
     - Rejected

``Adapter-dependent`` means Flysystem and the selected adapter must implement
the requested metadata, checksum, visibility, URL, or write operation. A
read-only adapter, for example, remains readable but rejects mutation.

Runtime Topology
----------------

Use ``StorageContext`` when logical names belong to an application/runtime:

.. code-block:: php

   use Infocyph\Pathwise\Storage\StorageContext;

   $storage = new StorageContext([
       'files' => ['driver' => 'local', 'root' => '/srv/app/files'],
   ], 'files');

   [$filesystem, $location] = $storage->resolve('files://reports/a.csv');

Contexts own their filesystem instances and custom driver factories. Two
contexts may reuse the same logical name without process-global cross-talk.
Inject the context into ``UploadProcessor`` or ``DownloadProcessor`` before
configuring relative or ``name://`` paths.

Atomicity and Transactions
--------------------------

``SafeFileWriter::enableAtomicWrite()`` is a **direct-local guarantee**. It
stages in the destination directory and requires the final local rename to
succeed. Adapter-backed destinations do not pretend that a final object write
is an atomic rename; request normal staged writing instead.

``FileOperations`` transactions are direct-local only. They use structured
journal entries and disk-backed private rollback copies, restore file
existence/content and permission bits, restore copy destinations, and reset the
object path after rename rollback. Transactions are process-local, reject
nesting, and do not provide database isolation. Invalid commit/rollback
lifecycle raises ``TransactionStateException`` and rollback failure is explicit.

Locking and Append
------------------

Direct locks and ``append()`` operate only on local paths. Local append uses
native append semantics and optional exclusive locking without reading the
existing file. Flysystem does not define portable append semantics, so
adapter-backed callers must choose ``appendEmulated()`` and accept a complete
object read/replacement.

Audit logging follows the same capability rule: local JSONL uses locked append;
remote/application pipelines should use ``PartitionedAuditSink`` or
``CallbackAuditSink`` rather than hiding whole-object rewrites.

ZIP Extraction
--------------

``FileCompression::decompress()``, selective extraction, and
``DirectoryOperations::unzip()`` share the hardened archive validation path.
The complete manifest is validated before publication and rejects traversal,
absolute/drive/UNC paths, null bytes, canonical/case-fold collisions, symbolic
links, unsupported special entries, and destination breakout.

Entry-count, per-entry expanded-size, total expanded-size, compression-ratio,
and actual streamed-byte bounds are enforced. Containment and destination
symlink state are revalidated at publication time. Adapter-backed archives and
destinations use Pathwise-owned local staging only where necessary and clean it
deterministically.

Synchronization
---------------

``syncTo()`` consumes source listings lazily and returns ``SyncReport``. Progress
events may report ``total: null`` when obtaining a total would require buffering
or a second traversal. Comparison strategies are:

* ``SIZE_AND_MODIFIED_TIME``: default for two direct local paths;
* ``SIZE``: default when either side is adapter-backed;
* ``CHECKSUM``: explicit integrity-first comparison with extra reads/requests;
* ``ALWAYS_COPY``: overwrite every source file.

Orphan deletion necessarily buffers and reverse-sorts the destination listing
so children are deleted before parents.

Native Execution
----------------

``PHP`` never starts native tools. ``AUTO`` may use a supported native
capability and otherwise falls back to PHP. ``NATIVE`` requires the capability
and fails explicitly with ``NativeExecutionException`` when it cannot be used.

Native execution is direct-local only and is bounded by
``NativeExecutionLimits``: finite timeout, stdout/stderr caps, termination grace,
and polling interval. Pathwise invokes argument vectors rather than accepting
caller shell fragments. See :doc:`native-execution`.

Performance Characteristics
---------------------------

Streams are used for cross-filesystem copy, upload/download transfer, checksums,
and archive publication/extraction. Directory listings remain lazy except where
ordering or orphan deletion requires materialization. Transaction rollback state
consumes temporary disk proportional to the affected local files. Remote
emulated append consumes bandwidth and memory proportional to the full object,
so partitioned writes are preferred for logs and event workloads.

See :doc:`performance-portability` for the release workload and scaling
recommendations.
