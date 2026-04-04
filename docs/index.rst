Pathwise Documentation
======================

Pathwise is a PHP 8.4+ file-system toolkit focused on safe file IO, directory automation,
compression workflows, upload processing, and operational tooling.

Installation
------------

.. code-block:: bash

   composer require infocyph/pathwise

Requirements:

* PHP 8.4+
* Optional extensions:
  * ``ext-zip`` for ZIP features
  * ``ext-posix`` for rich ownership data on Unix-like systems
  * ``ext-xmlreader`` and ``ext-simplexml`` for XML helpers

Feature Overview
----------------

File Operations
^^^^^^^^^^^^^^^

``Infocyph\Pathwise\FileManager\FileOperations`` includes:

* Create, read, update, append, delete, rename, copy
* Checksum APIs: ``verifyChecksum()``, ``writeAndVerify()``, ``copyWithVerification()``
* Optional progress callbacks for copy
* Optional transaction API with rollback:
  * ``beginTransaction()``, ``commitTransaction()``, ``rollbackTransaction()``, ``transaction()``
* Optional policy enforcement via ``PolicyEngine``
* Optional audit logging via ``AuditTrail``
* Optional execution strategy (PHP/native) for copy operations
* MIME detection fallback chain (`mime_content_type` -> ``finfo`` -> extension map)

Directory Operations
^^^^^^^^^^^^^^^^^^^^

``Infocyph\Pathwise\DirectoryManager\DirectoryOperations`` includes:

* Idempotent ``create()`` behavior
* Recursive copy/delete/move/list/size/find/flatten
* Directory sync with diff report via ``syncTo()``
* Progress callback support for copy and sync
* ZIP/unzip with explicit source validation and domain exceptions
* Optional execution strategy (PHP/native) for copy/zip/unzip

Compression
^^^^^^^^^^^

``Infocyph\Pathwise\FileManager\FileCompression`` includes:

* ZIP compress/decompress, password support, AES encryption
* Include/exclude glob patterns
* Ignore-file support (``.pathwiseignore``, ``.gitignore`` configurable)
* Batch add/extract support
* Progress callbacks for compress/decompress
* Hook system (before/after add)
* Optional execution strategy for native compress/decompress

Upload Processing
^^^^^^^^^^^^^^^^^

``Infocyph\Pathwise\StreamHandler\UploadProcessor`` includes:

* Validation profiles: ``image``, ``video``, ``document``
* MIME, size, and optional image dimension validation
* Naming strategies: hash/timestamp
* Chunked/resumable upload API:
  * ``processChunkUpload()``
  * ``finalizeChunkUpload()``
* Optional malware scanner callback hook

Safe Reader/Writer
^^^^^^^^^^^^^^^^^^

* ``SafeFileReader``: memory-safe iterators (line, csv, json, xml, binary, etc.) with locking
* ``SafeFileWriter``: multi-format writes, lock support, checksum verification, and atomic-write mode (temp file + rename)

Utilities
---------

Path and Metadata
^^^^^^^^^^^^^^^^^

* ``PathHelper`` for cross-platform normalization/join/relative/absolute helpers
* ``MetadataHelper`` for MIME, checksum, ownership, timestamps, and file metadata
* Ownership lookup uses OS-specific adapters instead of shell ownership commands

Operational Utilities
^^^^^^^^^^^^^^^^^^^^^

* ``FileWatcher`` snapshot/diff/watch helpers
* ``RetentionManager`` for keep-last/max-age cleanup policies
* ``ChecksumIndexer`` for checksum indexing, duplicate detection, and hard-link dedupe attempts
* ``FileJobQueue`` lightweight file-backed queue with priority and failure tracking
* ``AuditTrail`` append-only JSONL audit records
* ``PolicyEngine`` allow/deny policy rules with glob and conditional callbacks

Native Execution Strategy
-------------------------

Native acceleration is available for selected operations through
``Infocyph\Pathwise\Core\ExecutionStrategy`` and
``Infocyph\Pathwise\Native\NativeOperationsAdapter``.

Supported native command families:

* Windows: ``robocopy``, ``cmd copy``, PowerShell archive commands
* Unix-like: ``rsync``, ``cp``, ``zip``/``unzip``

All native paths gracefully fall back to PHP implementations unless explicitly forced.

Testing
-------

.. code-block:: bash

   php vendor/bin/pest

