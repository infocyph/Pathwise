Directory Manager
=================

Namespace: ``Infocyph\Pathwise\DirectoryManager``

Use ``DirectoryOperations`` for folder-level workflows such as recursive
copy/move/delete, discovery, synchronization and archive publication.

Capabilities
------------

``DirectoryOperations`` provides:

* idempotent ``create()``;
* recursive ``copy()``, ``move()`` and ``delete()``;
* listing/discovery through ``listContents()``, ``flatten()`` and ``find()``;
* metrics/structure helpers such as ``size()`` and ``getDepth()``;
* lazy ``syncTo()`` returning the typed ``SyncReport``;
* explicit ``SyncComparison`` strategies;
* ZIP helpers through ``zip()`` and ``unzip()``;
* optional ``ExecutionStrategy`` native acceleration for supported direct-local
  operations.

Storage Semantics
-----------------

Storage-neutral operations work with direct local paths and adapter-backed
paths when the selected Flysystem adapter provides the required capability.
POSIX permissions, direct iterators/handles, transactions, and native process
execution remain direct-local capabilities.

For application-owned named storage, resolve topology through
``StorageContext``. Low-level static/default/mount routing remains a separate
standalone utility surface; a logical adapter path is not treated as a native
local path merely because its implementation happens to use local disk.

Archive operations may localize/stream adapter-backed data through Pathwise-owned
temporary state when a local ZIP implementation requires it. The same hardened
archive manifest validation and publication checks apply before extracted data
is committed.

Synchronization
---------------

``syncTo()`` traverses source listings lazily and returns ``SyncReport``.
Comparison modes are explicit through ``SyncComparison``:

* size + modified time for efficient direct-local synchronization;
* size-only for the portable adapter-backed default;
* checksum for integrity-first comparison with additional I/O;
* always-copy when comparison should be bypassed.

Progress totals may be ``null`` when calculating them would require buffering or
a second traversal. Deleting destination orphans requires destination
materialization/reverse ordering so child paths are removed before parents.

Example
-------

.. code-block:: php

   use Infocyph\Pathwise\DirectoryManager\DirectoryOperations;

   $ops = new DirectoryOperations('/tmp/source');
   $ops->create();

   $report = $ops->syncTo('/tmp/target', deleteOrphans: true);
   $ops->zip('/tmp/source.zip');

   foreach ($report->created as $path) {
       // Observe the synchronization result.
   }

Native acceleration is an optimization, not a different correctness model.
``AUTO`` falls back when the capability is unavailable; forced ``NATIVE`` fails
explicitly. See :doc:`native-execution`, :doc:`storage-contracts`, and
:doc:`performance-portability`.
