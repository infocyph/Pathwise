Storage Adapters
================

Pathwise 4 uses Flysystem 3 for storage-neutral I/O while keeping storage
topology explicit. There are two complementary APIs:

* ``StorageFactory`` is a stateless filesystem constructor and driver metadata
  helper.
* ``StorageContext`` owns named filesystems, default selection, lazy operator
  instances, logical paths, and custom driver factories for one runtime.

There is no process-global custom-driver registry in ``StorageFactory`` and no
``StorageFactory::mount()``/``mountMany()`` topology API in Pathwise 4.

StorageFactory
--------------

``StorageFactory::createFilesystem(array $config)`` accepts exactly one storage
construction mode:

* local driver configuration, for example
  ``['driver' => 'local', 'root' => '/srv/storage']``;
* a prebuilt ``FilesystemOperator`` through ``['filesystem' => $operator]``;
* a prebuilt ``FilesystemAdapter`` through ``['adapter' => $adapter]``;
* an official driver plus positional adapter constructor arguments.

Ambiguous combinations are rejected.

.. code-block:: php

   use Infocyph\Pathwise\Storage\StorageFactory;

   $filesystem = StorageFactory::createFilesystem([
       'driver' => 'local',
       'root' => '/srv/storage',
   ]);

   $filesystem->write('reports/a.txt', "hello\n");

The metadata helpers are:

* ``StorageFactory::officialDrivers()``
* ``StorageFactory::isOfficialDriver($driver)``
* ``StorageFactory::suggestedPackage($driver)``

Official Drivers
----------------

Pathwise maps these Flysystem driver keys:

.. list-table::
   :header-rows: 1

   * - Driver
     - Package
   * - ``local``
     - ``league/flysystem-local``
   * - ``ftp``
     - ``league/flysystem-ftp``
   * - ``inmemory``
     - ``league/flysystem-memory``
   * - ``read-only``
     - ``league/flysystem-read-only``
   * - ``path-prefixing``
     - ``league/flysystem-path-prefixing``
   * - ``aws-s3``
     - ``league/flysystem-aws-s3-v3``
   * - ``async-aws-s3``
     - ``league/flysystem-async-aws-s3``
   * - ``azure-blob-storage``
     - ``league/flysystem-azure-blob-storage``
   * - ``google-cloud-storage``
     - ``league/flysystem-google-cloud-storage``
   * - ``mongodb-gridfs``
     - ``league/flysystem-gridfs``
   * - ``sftp-v2``
     - ``league/flysystem-sftp-v2``
   * - ``sftp-v3``
     - ``league/flysystem-sftp-v3``
   * - ``webdav``
     - ``league/flysystem-webdav``
   * - ``ziparchive``
     - ``league/flysystem-ziparchive``

Aliases such as ``s3``/``aws``, ``memory``, ``readonly``, ``gcs``, ``azure``,
``sftp2``, ``sftp3`` and ``zip`` normalize to the canonical driver names.
Optional adapter packages stay optional. If a selected adapter class is not
installed, Pathwise returns an explicit install/configuration error rather than
silently falling back.

Prebuilt Adapter Example
------------------------

.. code-block:: php

   use Aws\S3\S3Client;
   use Infocyph\Pathwise\Storage\StorageFactory;
   use League\Flysystem\AwsS3V3\AwsS3V3Adapter;

   $client = new S3Client([
       'version' => 'latest',
       'region' => 'us-east-1',
   ]);

   $adapter = new AwsS3V3Adapter($client, 'my-bucket', 'app-prefix');

   $filesystem = StorageFactory::createFilesystem([
       'adapter' => $adapter,
   ]);

Official Constructor Mode
-------------------------

When the adapter package is installed, official drivers may receive positional
constructor arguments:

.. code-block:: php

   $filesystem = StorageFactory::createFilesystem([
       'driver' => 'aws-s3',
       'constructor' => [$client, 'my-bucket', 'app-prefix'],
   ]);

For complex adapters, constructing the adapter in application/bootstrap code
and passing ``adapter`` or ``filesystem`` is often clearer and easier to test.

StorageContext: Recommended Runtime Model
-----------------------------------------

.. code-block:: php

   use Infocyph\Pathwise\Storage\StorageContext;

   $storage = new StorageContext([
       'primary' => [
           'driver' => 'local',
           'root' => '/srv/app/storage',
       ],
       'objects' => [
           'filesystem' => $s3Filesystem,
       ],
   ], 'primary');

   [$filesystem, $location] = $storage->resolve('objects://uploads/a.pdf');
   $filesystem->write($location, $contents);

Relative logical paths select the context default. ``name://path`` selects a
configured filesystem. Absolute filesystem paths are deliberately not accepted
as logical context paths; use ``localPath()`` when a local context disk must be
exposed to a native/local-only capability.

Custom Drivers Are Context-Scoped
---------------------------------

.. code-block:: php

   use Infocyph\Pathwise\Storage\StorageContext;
   use League\Flysystem\Filesystem;
   use League\Flysystem\Local\LocalFilesystemAdapter;

   $storage = new StorageContext(
       ['tenant' => ['driver' => 'tenant-local', 'tenant' => 'acme']],
       'tenant',
       [
           'tenant-local' => static function (array $config): Filesystem {
               $tenant = (string) ($config['tenant'] ?? 'default');

               return new Filesystem(
                   new LocalFilesystemAdapter('/srv/tenants/' . $tenant),
               );
           },
       ],
   );

Custom factories are isolated to that context. Two applications in the same
process can reuse ``tenant`` and ``tenant-local`` without cross-talk.

Processor Integration
---------------------

``UploadProcessor`` and ``DownloadProcessor`` accept a context directly:

.. code-block:: php

   $uploader->setStorageContext($storage);
   $uploader->setDirectorySettings('objects://uploads', tempDir: sys_get_temp_dir());

   $downloads->setStorageContext($storage);
   $downloads->setAllowedRoots(['objects://downloads']);

Configure the context **before** path-dependent processor settings. Processors
do not register global mounts. Direct absolute paths remain local; context
relative/scheme paths route through the configured context.

Local-Only Capabilities
-----------------------

Some Pathwise features deliberately require a direct local path because they
need OS primitives that remote object stores cannot provide safely:

* file queue locking/durable state;
* native processes;
* POSIX/Windows ownership operations;
* local transactions and native atomic rename semantics;
* safe symbolic-link creation/removal.

Do not emulate these capabilities on object storage. Keep a local working area
when a workflow needs them, then move the resulting artifact through Flysystem.

See :doc:`storage-context`, :doc:`storage-contracts`, and
:doc:`performance-portability` for the complete runtime and capability model.
