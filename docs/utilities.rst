Utilities
=========

Namespace: ``Infocyph\Pathwise\Utils``

Path and metadata helpers:

* ``PathHelper``: normalize, join, relative/absolute conversion, scheme-aware paths.
* ``MetadataHelper``: size, mime, checksum, timestamps, ownership, path type.
* ``PermissionsHelper``: read/write/execute checks and permission formatting.

Ownership resolution:

* Uses OS-specific adapters in ``Utils\\Ownership`` (POSIX, Windows, fallback).
* Avoids shell-based ownership lookup.

File watch helper:

* ``FileWatcher`` provides snapshot/diff/watch flows for change tracking.

Examples
--------

Path and metadata:

.. code-block:: php

   use Infocyph\Pathwise\Utils\MetadataHelper;
   use Infocyph\Pathwise\Utils\PathHelper;

   $path = PathHelper::join('/tmp', 'reports', 'a.txt');
   $mime = MetadataHelper::getMimeType($path);
   $meta = MetadataHelper::getAllMetadata($path);

Watcher snapshot + diff:

.. code-block:: php

   use Infocyph\Pathwise\Utils\FileWatcher;

   $before = FileWatcher::snapshot('/tmp/reports');
   // perform file operations
   $after = FileWatcher::snapshot('/tmp/reports');
   $changes = FileWatcher::diff($before, $after);
