Utilities
=========

Namespace: ``Infocyph\Pathwise\Utils``

Pathwise utilities are shared low-level building blocks. They do not replace
``StorageContext`` as application-owned storage topology.

Path and Storage Helpers
------------------------

``PathHelper``
   Normalize/join/relative/absolute/temp and scheme-aware path operations.

``FlysystemHelper``
   Low-level direct-local/default/mount storage routing used by the standalone
   storage-neutral APIs. Persistent applications should prefer
   ``StorageContext`` for named filesystem ownership and isolation.

``MetadataHelper``
   Size, MIME, checksum, timestamp, ownership and path-type helpers subject to
   the selected storage/platform capability.

``PermissionsHelper``
   Direct-local permission checks/formatting and mutation where supported.

Ownership resolution is delegated to OS-capability implementations under
``Utils\Ownership`` (POSIX, Windows and fallback). Pathwise does not shell out
merely to discover ownership metadata.

Path and Metadata Example
-------------------------

.. code-block:: php

   use Infocyph\Pathwise\Utils\MetadataHelper;
   use Infocyph\Pathwise\Utils\PathHelper;

   $path = PathHelper::join('/tmp', 'reports', 'a.txt');
   $mime = MetadataHelper::getMimeType($path);
   $meta = MetadataHelper::getAllMetadata($path);

File Watcher
------------

``FileWatcher`` provides deterministic snapshot/diff/polling workflows:

* ``snapshot()`` returns a path-keyed ``mtime``/``size`` map sorted by path;
* ``diff()`` returns typed ``SnapshotDiff`` with sorted created/modified/deleted
  lists;
* ``watch()`` invokes a callback for non-empty diffs and returns typed
  ``WatchResult``;
* watch duration must be at least one second;
* polling interval must be at least 10 milliseconds;
* snapshots of a directory necessarily retain one metadata entry per observed
  file, so memory grows with the watched set.

.. code-block:: php

   use Infocyph\Pathwise\Results\SnapshotDiff;
   use Infocyph\Pathwise\Utils\FileWatcher;

   $before = FileWatcher::snapshot('/tmp/reports');
   // Perform file operations.
   $after = FileWatcher::snapshot('/tmp/reports');
   $changes = FileWatcher::diff($before, $after);

   $result = FileWatcher::watch(
       '/tmp/reports',
       static function (SnapshotDiff $diff): void {
           // Handle one deterministic change set.
       },
       durationSeconds: 5,
       intervalMilliseconds: 500,
   );

``FileWatcher`` is polling, not an OS event-stream abstraction. For very large
namespaces or long-running distributed watching, use a platform/application
service designed for that scale and treat Pathwise snapshots as bounded
filesystem workflows. See :doc:`performance-portability`.
