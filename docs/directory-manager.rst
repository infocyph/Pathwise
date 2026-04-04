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
* Sync API with diff report: ``syncTo()``.
* Archive helpers: ``zip()`` and ``unzip()``.

Flysystem-aware behavior:

* Works with local paths and mounted scheme paths.
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
