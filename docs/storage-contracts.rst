Storage Capability Contract
===========================

Pathwise distinguishes the path syntax from the capability behind it. A mounted
``local`` adapter is still adapter-backed: Pathwise does not unwrap it and call
native PHP functions against its internal root.

Compatibility Matrix
--------------------

.. list-table::
   :header-rows: 1
   :widths: 34 20 20 26

   * - Capability
     - Direct local path
     - Default Flysystem path
     - Mounted scheme path
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
     - Streamed through local staging
     - Streamed through local staging
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
     - Rejected
     - Rejected
   * - Direct locks/handles and shell search
     - Platform-dependent
     - Rejected
     - Rejected
   * - Native process execution
     - Tool-dependent
     - Rejected
     - Rejected

``Adapter-dependent`` means Flysystem and the selected adapter must implement
the requested metadata, checksum, visibility, URL, or write operation. A
read-only adapter, for example, remains readable but rejects mutation.

Atomicity and Transactions
--------------------------

``SafeFileWriter::enableAtomicWrite()`` stages a local file and replaces the
destination at close time. Local same-filesystem rename is atomic on supported
operating systems; a mounted destination requires a final adapter write and is
not claimed to be atomic.

``FileOperations`` transactions are local-only. They use structured journal
entries and disk-backed copies, restore file existence/content and permission
bits, restore copy destinations, and reset the object's path after a rename
rollback. Transactions are process-local, reject nesting, and do not provide
database isolation. Commit and rollback outside an active transaction throw
``TransactionStateException``.

Locking and Append
------------------

Direct locks and ``append()`` operate only on local paths. Local append uses
``FILE_APPEND`` and optional ``LOCK_EX`` without reading the existing file.
Flysystem does not define portable append semantics, so mounted callers must
choose ``appendEmulated()`` and accept a complete object read/replacement. Audit
logging follows the same rule: local JSONL is locked append; remote sinks use
separate event objects or application callbacks.

ZIP Extraction
--------------

``FileCompression::decompress()``, ``batchExtractFiles()``, and
``DirectoryOperations::unzip()`` share one validator. Validation completes for
the entire archive before extraction and rejects absolute/drive paths, null
bytes, traversal, root escape, ZIP symbolic links, and existing destination
symlink chains. Remote archives and destinations are localized/streamed only
after applying the same validation. Entry-count, per-entry uncompressed-size,
total uncompressed-size, and compression-ratio limits are enforced before any
destination mutation. Remote compression stages each entry to bounded temporary
disk and registers the staged file with ``ZipArchive``; it does not load an
entire remote entry into one PHP string.

Synchronization
---------------

``syncTo()`` consumes source listings lazily and returns ``SyncReport``. Progress
events report ``total: null`` when obtaining a total would require buffering or
a second traversal. Comparison strategies are:

* ``SIZE_AND_MODIFIED_TIME``: default for two direct local paths.
* ``SIZE``: default when either side is adapter-backed.
* ``CHECKSUM``: explicit integrity-first comparison with extra reads/requests.
* ``ALWAYS_COPY``: overwrite every source file.

Orphan deletion necessarily buffers and reverse-sorts the destination listing
so children are deleted before parents.

Native Execution
----------------

``PHP`` never starts native tools. ``AUTO`` may attempt an available tool and
fall back to PHP. ``NATIVE`` validates tool availability and local paths, then
throws ``NativeExecutionException`` on any native failure without falling back.
Command arguments are escaped, and ``NativeExecutionResult`` retains command,
exit code, and output. Actual tools vary by platform (``cp``, ``rsync``,
``zip``/``unzip`` on Unix-like systems; ``cmd``, ``robocopy``, and PowerShell on
Windows).

Performance Characteristics
---------------------------

Streams are used for cross-filesystem file copy, downloads, writes, checksums,
and file-compression extraction. Directory listings remain lazy except where
ordering is required. Transaction backups consume temporary disk proportional
to the original local files. Remote emulated append consumes bandwidth and
memory proportional to the complete object, so partitioned writes are preferred
for logs and event workloads.
