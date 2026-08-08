Directory Manager
=================

Namespace: ``Infocyph\Pathwise\DirectoryManager``

Where it fits:

* Use this module when working with folder-level workflows like mirroring,
  recursive copies, reporting, and archive staging.

``DirectoryOperations`` provides directory-level workflows:

* Idempotent ``create()``.
* Recursive ``copy()``, ``move()``, ``delete()``.
* Listing and discovery: ``listContents()``, ``flatten()``, ``find()``.
* Metrics and structure helpers: ``size()``, ``getDepth()``.
* Lazy sync API returning ``SyncReport`` with configurable ``SyncComparison``.
* Archive helpers: ``zip()`` and ``unzip()``.

Flysystem-aware behavior:

* Storage-neutral workflows work with local and mounted paths when the adapter
  provides their capabilities; POSIX permissions and direct iterators are local-only.
* Uses storage-safe resolution for relative paths.
* Can bridge non-local ZIP source/destination through temporary streaming.

Native acceleration:

* Optional via ``ExecutionStrategy`` for local copy/zip/unzip paths.

Example
-------

.. code-block:: php

   use Infocyph\Pathwise\DirectoryManager\DirectoryOperations;

   $ops = new DirectoryOperations('/tmp/source');
   $ops->create();

   $diff = $ops->syncTo('/tmp/target', deleteOrphans: true);
   $ops->zip('/tmp/source.zip');
