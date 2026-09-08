Overview
========

Pathwise 4 is a framework-neutral filesystem/workflow toolkit for PHP 8.4+.
It deliberately separates three concerns:

* **storage topology** — Flysystem operators and logical names owned by
  ``StorageContext``;
* **filesystem/workflow mechanics** — file, directory, upload, download,
  archive, queue and utility classes;
* **application policy/integration** — supplied by the consuming framework or
  application rather than hidden inside Pathwise globals.

Why Pathwise
------------

Filesystem code often becomes a collection of subtly different path checks,
stream loops, temporary-file conventions, locks, archive rules, queue files,
scanner callbacks and adapter-specific workarounds. Pathwise centralizes those
generic mechanics and gives them typed failure/resource-ownership semantics.

The 4.0 design emphasizes:

* instance-scoped persistent storage topology;
* streaming and bounded resource use;
* fail-closed security policy where a capability is required;
* explicit local-only/platform-specific capabilities;
* typed results instead of ambiguous arrays;
* deterministic cleanup on success and failure;
* portability across PHP 8.4/8.5 and Windows/Linux release matrices.

Primary Namespaces
------------------

* ``Infocyph\Pathwise`` — stateless ``PathwiseFacade``;
* ``Infocyph\Pathwise\Storage`` — ``StorageContext`` / ``StorageFactory``;
* ``Infocyph\Pathwise\FileManager``;
* ``Infocyph\Pathwise\DirectoryManager``;
* ``Infocyph\Pathwise\StreamHandler``;
* ``Infocyph\Pathwise\Security``;
* ``Infocyph\Pathwise\Queue``;
* ``Infocyph\Pathwise\Observability``;
* ``Infocyph\Pathwise\Indexing``;
* ``Infocyph\Pathwise\Retention``;
* ``Infocyph\Pathwise\Native``;
* ``Infocyph\Pathwise\Results``;
* ``Infocyph\Pathwise\Utils``.

Path Models
-----------

There are two distinct path contexts.

Direct paths
   Absolute/direct-local paths used by local file operations and OS-level
   capabilities.

StorageContext logical paths
   Relative paths select a context default; ``name://path`` selects a named
   context filesystem. These paths do not require a process-global mount.

.. code-block:: php

   $storage = new StorageContext([
       'primary' => ['driver' => 'local', 'root' => '/srv/app/storage'],
       'archive' => ['filesystem' => $archiveFilesystem],
   ], 'primary');

   [$filesystem, $location] = $storage->resolve('archive://2026/report.pdf');

Low-level ``FlysystemHelper`` default/mount routing remains available for direct
standalone utility use. It is not the recommended persistent-runtime registry.

Framework Boundary
------------------

Pathwise is not an HTTP framework, dependency-injection container, distributed
broker, log service, or application path/config system. For example:

* Pathwise prepares and streams download ranges; the framework creates the HTTP
  response and handles request conditionals/offload policy.
* Pathwise materializes/validates upload sources; the framework extracts the
  uploaded-file object and application policy.
* Pathwise provides a direct-local queue; applications needing multi-host
  messaging should use a broker.
* Pathwise provides storage contexts; application-specific base/public/storage
  path conventions belong to the consuming framework.

This boundary lets projects such as Foundation reuse Pathwise mechanics without
copying them or coupling Pathwise to a specific HTTP/runtime framework.

Quick Example
-------------

.. code-block:: php

   use Infocyph\Pathwise\PathwiseFacade;

   PathwiseFacade::at('/tmp/demo.txt')
       ->file()
       ->create('hello')
       ->append("\nworld");

   $report = PathwiseFacade::at('/tmp/source')
       ->directory()
       ->syncTo('/tmp/backup', deleteOrphans: true);

Read Next
---------

* :doc:`capabilities` — module map;
* :doc:`quickstart` — recommended entry patterns;
* :doc:`storage-context` — persistent runtime storage;
* :doc:`security` — trust/capability model;
* :doc:`migration-4.0` — breaking changes from 3.x;
* :doc:`api-reference` — public types and errors.
