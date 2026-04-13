Capabilities
============

This page answers one question: **what does Pathwise include today?**

At a Glance
-----------

Pathwise combines two layers:

* Storage-safe file operations (local paths and mounted scheme paths).
* Higher-level workflows (upload pipeline, compression, retention, queue, audit, policy).

Primary Modules
---------------

File IO (``Infocyph\Pathwise\FileManager``)
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

Classes:

* ``FileOperations``
* ``SafeFileReader``
* ``SafeFileWriter``
* ``FileCompression``

What you get:

* Create/read/update/delete, stream reads/writes, checksum verify/copy verify.
* Atomic-safe writer mode, lock support, structured read/write helpers.
* ZIP workflows with password/encryption, include/exclude patterns, progress callbacks.

Directory Workflows (``Infocyph\Pathwise\DirectoryManager``)
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

Class:

* ``DirectoryOperations``

What you get:

* Idempotent create, recursive copy/move/delete.
* Listing, flattening, find/filter, size/depth metrics.
* Directory sync with diff report.
* Zip/unzip helpers for local and mounted paths.

Uploads (``Infocyph\Pathwise\StreamHandler``)
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

Class:

* ``UploadProcessor``

What you get:

* Standard upload handling and destination strategy.
* Validation presets (image/video/document), MIME and size rules.
* Chunked/resumable upload flow.
* Extension allowlist/blocklist controls.
* Upload ID validation for chunk/session identifiers.
* Strict content checks (MIME-extension agreement and file signature checks).
* Optional or required malware scan callback.

Downloads (``Infocyph\Pathwise\StreamHandler``)
^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^

Class:

* ``DownloadProcessor``

What you get:

* Secure download metadata generation for HTTP adapters.
* Extension allowlist/blocklist controls.
* Allowed-root restriction to prevent path breakout.
* Hidden-file blocking and max-size limits.
* Optional range request handling and partial-download metadata.
* Stream copy into caller-provided output resources.

Security and Operations
^^^^^^^^^^^^^^^^^^^^^^^

Classes:

* ``PolicyEngine`` (allow/deny + conditions)
* ``AuditTrail`` (JSONL audit logging)
* ``FileJobQueue`` (file-backed queue)
* ``ChecksumIndexer`` (duplicate/index workflows)
* ``RetentionManager`` (keep-last and age-based cleanup)
* ``FileWatcher`` (snapshot/diff/watch)

Storage and Path Model
----------------------

Pathwise accepts:

* Local paths (absolute or relative).
* Mounted scheme paths like ``assets://images/logo.png``.

Mounting is done through ``FlysystemHelper::mount()``. Once mounted, most
high-level modules can use the scheme path directly.

Runtime and Extensions
----------------------

Required:

* PHP 8.4+
* ``league/flysystem`` 3.x
* ``ext-fileinfo``

Optional:

* ``ext-zip`` (archive features)
* ``ext-pcntl`` (watch loop process patterns)
* ``ext-posix`` (richer Unix ownership data)
* ``ext-xmlreader``, ``ext-simplexml`` (XML helpers)

What to Read Next
-----------------

* ``quickstart`` for first-use examples.
* ``recipes`` for end-to-end flows.
