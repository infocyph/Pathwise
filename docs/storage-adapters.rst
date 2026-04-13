Storage Adapters
================

Pathwise is built on Flysystem 3, so you can use **any Flysystem adapter**
as soon as its package is installed and mounted.

Use ``Infocyph\Pathwise\Storage\StorageFactory`` to standardize setup.

What ``StorageFactory`` Supports
--------------------------------

``StorageFactory::createFilesystem(array $config)`` accepts:

* local driver config: ``['driver' => 'local', 'root' => '/srv/storage']``
* prebuilt filesystem: ``['filesystem' => $filesystemOperator]``
* adapter instance: ``['adapter' => $adapter, 'options' => [...]]``
* custom named drivers registered at runtime.

``StorageFactory::mount(string $name, array $config)`` creates and mounts in one step.

``StorageFactory::mountMany(array $mounts)`` mounts multiple storages at once.

Basic Local Example
-------------------

.. code-block:: php

   use Infocyph\Pathwise\Storage\StorageFactory;
   use Infocyph\Pathwise\Utils\FlysystemHelper;

   StorageFactory::mount('assets', [
       'driver' => 'local',
       'root' => '/srv/storage/assets',
   ]);

   FlysystemHelper::write('assets://images/logo.txt', 'ok');

Any Adapter Example (S3)
------------------------

Install adapter package first (example):

.. code-block:: bash

   composer require league/flysystem-aws-s3-v3 aws/aws-sdk-php

Then pass the adapter directly:

.. code-block:: php

   use Infocyph\Pathwise\Storage\StorageFactory;
   use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
   use Aws\S3\S3Client;

   $client = new S3Client([
       'version' => 'latest',
       'region' => 'us-east-1',
       'credentials' => [
           'key' => getenv('AWS_ACCESS_KEY_ID'),
           'secret' => getenv('AWS_SECRET_ACCESS_KEY'),
       ],
   ]);

   $adapter = new AwsS3V3Adapter($client, 'my-bucket', 'app-prefix');

   StorageFactory::mount('s3', [
       'adapter' => $adapter,
   ]);

   // Works with all Pathwise modules that accept paths:
   // s3://uploads/a.pdf

Custom Driver Registration
--------------------------

If you want environment-driven config, register a custom driver once:

.. code-block:: php

   use Infocyph\Pathwise\Storage\StorageFactory;
   use League\Flysystem\Filesystem;
   use League\Flysystem\Local\LocalFilesystemAdapter;

   StorageFactory::registerDriver('tenant-local', function (array $config): Filesystem {
       $tenant = (string) ($config['tenant'] ?? 'default');
       $root = '/srv/tenants/' . $tenant;

       return new Filesystem(new LocalFilesystemAdapter($root));
   });

   StorageFactory::mount('tenant', [
       'driver' => 'tenant-local',
       'tenant' => 'acme',
   ]);

   // tenant://docs/report.txt

Helper Functions (autoloaded)
-----------------------------

Global helpers mirror the factory:

* ``createFilesystem(array $config): FilesystemOperator``
* ``mountStorage(string $name, array $config): FilesystemOperator``
* ``mountStorages(array $mounts): void``

Example:

.. code-block:: php

   mountStorage('media', [
       'driver' => 'local',
       'root' => '/srv/media',
   ]);

Processor Integration Notes
---------------------------

``UploadProcessor`` and ``DownloadProcessor`` already work with mounted paths.

Examples:

* upload destination: ``$uploader->setDirectorySettings('s3://uploads')``
* chunk temp dir on mounted storage: ``$uploader->setDirectorySettings('s3://uploads', false, 's3://tmp')``
* download root restriction for mounted storage:
  ``$downloads->setAllowedRoots(['s3://uploads'])``

Recommended Operational Pattern
-------------------------------

For remote object stores, common production setup is:

* receive chunks on fast local temp storage
* finalize and write merged object to remote mount

This reduces object churn and upload latency compared with writing every chunk
as a separate remote object.
