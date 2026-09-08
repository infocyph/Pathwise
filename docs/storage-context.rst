Storage Context
===============

``Infocyph\Pathwise\Storage\StorageContext`` is Pathwise 4's preferred
storage-topology boundary for applications, workers, long-running processes,
and multi-application runtimes. A context owns its filesystem configuration,
default filesystem, lazily-created Flysystem operators, and custom driver
factories. It does **not** register process-global mounts.

Why use a context?
------------------

Use ``StorageContext`` when storage configuration belongs to an application or
execution host rather than to the whole PHP process. Two contexts may safely
reuse names such as ``files`` or ``archive`` while pointing at completely
different roots or adapters.

The low-level static ``FlysystemHelper`` mount/default APIs still exist for
standalone scripts and direct compatibility use. They are not the recommended
persistent-runtime topology model.

Basic local context
-------------------

.. code-block:: php

   use Infocyph\Pathwise\Storage\StorageContext;

   $storage = new StorageContext(
       configurations: [
           'files' => [
               'driver' => 'local',
               'root' => '/srv/app/storage',
           ],
           'archive' => [
               'driver' => 'local',
               'root' => '/srv/app/archive',
           ],
       ],
       defaultFilesystem: 'files',
   );

   $storage->filesystem()->write('documents/readme.txt', 'hello');
   $storage->filesystem('archive')->write('2026/readme.txt', 'archived');

   [$filesystem, $location] = $storage->resolve('archive://2026/readme.txt');
   $contents = $filesystem->read($location);

Logical paths
-------------

Relative paths select the context default filesystem. ``name://path`` selects
an explicitly configured filesystem.

.. code-block:: php

   $defaultPath = $storage->path('reports/q1.pdf');
   // files://reports/q1.pdf

   $archivePath = $storage->path('reports/q1.pdf', 'archive');
   // archive://reports/q1.pdf

   [$filesystem, $location] = $storage->resolve('archive://reports/q1.pdf');
   // $location === 'reports/q1.pdf'

Logical paths are adapter-relative. Absolute logical paths, parent-directory
traversal, null bytes, Windows drive paths, and UNC-style absolute paths are
rejected rather than reinterpreted.

Local-path capability
---------------------

A local context filesystem exposes its physical root through ``localPath()``.
Remote/adapted filesystems do not pretend to have native paths.

.. code-block:: php

   if ($storage->isLocal('files')) {
       $nativePath = $storage->localPath('reports/q1.pdf', 'files');
   }

Calling ``localPath()`` for a non-local filesystem throws an
``InvalidArgumentException``. Use ``filesystem()`` or ``resolve()`` for
storage-neutral operations instead.

Processor integration
---------------------

``UploadProcessor`` and ``DownloadProcessor`` accept a context directly. This
keeps their logical paths isolated from process-global mounts.

.. code-block:: php

   use Infocyph\Pathwise\StreamHandler\DownloadProcessor;
   use Infocyph\Pathwise\StreamHandler\UploadProcessor;
   use Infocyph\Pathwise\StreamHandler\UploadSource;

   $uploads = new UploadProcessor();
   $uploads->setStorageContext($storage);
   $uploads->setDirectorySettings('files://uploads');

   $stored = $uploads->ingestSource(
       UploadSource::fromPath('/srv/import/report.pdf', 'report.pdf'),
   );

   $downloads = new DownloadProcessor();
   $downloads->setStorageContext($storage);
   $downloads->setAllowedRoots(['files://uploads']);

   $preparation = $downloads->prepareDownload($stored);
   foreach ($downloads->streamChunks($preparation) as $chunk) {
       echo $chunk;
   }

Processor context configuration is optional. Without a context, the existing
low-level ``FlysystemHelper`` routing model remains available for direct local,
default-Flysystem, and explicitly mounted helper paths.

Custom context drivers
----------------------

Custom driver factories belong to a context rather than a global registry.
Each factory receives the filesystem configuration and must return a Flysystem
``FilesystemOperator``.

.. code-block:: php

   use Infocyph\Pathwise\Storage\StorageContext;
   use League\Flysystem\Filesystem;
   use League\Flysystem\Local\LocalFilesystemAdapter;

   $storage = new StorageContext(
       configurations: [
           'tenant' => [
               'driver' => 'tenant-local',
               'tenant' => 'acme',
           ],
       ],
       defaultFilesystem: 'tenant',
       drivers: [
           'tenant-local' => static function (array $config): Filesystem {
               $tenant = (string) ($config['tenant'] ?? 'default');

               return new Filesystem(
                   new LocalFilesystemAdapter('/srv/tenants/' . $tenant),
               );
           },
       ],
   );

The same custom driver name may be defined differently by another context
without cross-talk. Official driver names are reserved and continue to be
created through ``StorageFactory``.

Prebuilt adapters and operators
-------------------------------

A context configuration may contain any configuration accepted by
``StorageFactory::createFilesystem()``. For example, a prebuilt Flysystem
adapter can be supplied directly:

.. code-block:: php

   $storage = new StorageContext([
       'remote' => [
           'adapter' => $adapter,
       ],
   ], 'remote');

Or a complete ``FilesystemOperator`` can be supplied:

.. code-block:: php

   $storage = new StorageContext([
       'remote' => [
           'filesystem' => $filesystem,
       ],
   ], 'remote');

See :doc:`storage-adapters` for official adapter package names and constructor
configuration examples.

Multiple applications in one process
------------------------------------

Do not create unique global mount names as an application-isolation strategy.
Give each application its own ``StorageContext`` instead:

.. code-block:: php

   $appA = new StorageContext([
       'files' => ['driver' => 'local', 'root' => '/srv/a/storage'],
   ], 'files');

   $appB = new StorageContext([
       'files' => ['driver' => 'local', 'root' => '/srv/b/storage'],
   ], 'files');

   $appA->filesystem()->write('same.txt', 'A');
   $appB->filesystem()->write('same.txt', 'B');

Both contexts use ``files`` without sharing operators or mutable topology.
