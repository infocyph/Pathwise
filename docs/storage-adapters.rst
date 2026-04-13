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

Driver config modes:

* direct adapter object:
  ``['driver' => 'aws-s3', 'adapter' => $adapter]``
* constructor arguments for official adapter classes:
  ``['driver' => 'aws-s3', 'constructor' => [$client, $bucket, $prefix]]``

``StorageFactory`` also exposes:

* ``StorageFactory::officialDrivers()`` for official driver metadata.
* ``StorageFactory::suggestedPackage($driver)`` for install guidance.

Official Adapter Coverage
-------------------------

The following official Flysystem adapters are mapped by driver key:

* ``local`` -> ``league/flysystem-local`` -> ``League\Flysystem\Local\LocalFilesystemAdapter``
* ``ftp`` -> ``league/flysystem-ftp`` -> ``League\Flysystem\Ftp\FtpAdapter``
* ``inmemory`` (alias: ``in-memory``) -> ``league/flysystem-memory`` -> ``League\Flysystem\InMemory\InMemoryFilesystemAdapter``
* ``read-only`` (alias: ``readonly``) -> ``league/flysystem-read-only`` -> ``League\Flysystem\ReadOnly\ReadOnlyFilesystemAdapter``
* ``path-prefixing`` (alias: ``path-prefix``) -> ``league/flysystem-path-prefixing`` -> ``League\Flysystem\PathPrefixing\PathPrefixedAdapter``
* ``aws-s3`` (aliases: ``s3``, ``aws``) -> ``league/flysystem-aws-s3-v3`` -> ``League\Flysystem\AwsS3V3\AwsS3V3Adapter``
* ``async-aws-s3`` -> ``league/flysystem-async-aws-s3`` -> ``League\Flysystem\AsyncAwsS3\AsyncAwsS3Adapter``
* ``azure-blob-storage`` (alias: ``azure``) -> ``league/flysystem-azure-blob-storage`` -> ``League\Flysystem\AzureBlobStorage\AzureBlobStorageAdapter``
* ``google-cloud-storage`` (alias: ``gcs``) -> ``league/flysystem-google-cloud-storage`` -> ``League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter``
* ``mongodb-gridfs`` (alias: ``gridfs``) -> ``league/flysystem-gridfs`` -> ``League\Flysystem\GridFS\GridFSAdapter``
* ``sftp-v2`` (alias: ``sftp2``) -> ``league/flysystem-sftp-v2`` -> ``League\Flysystem\PhpseclibV2\SftpAdapter``
* ``sftp-v3`` (alias: ``sftp3``) -> ``league/flysystem-sftp-v3`` -> ``League\Flysystem\PhpseclibV3\SftpAdapter``
* ``webdav`` -> ``league/flysystem-webdav`` -> ``League\Flysystem\WebDAV\WebDAVAdapter``
* ``ziparchive`` (alias: ``zip``) -> ``league/flysystem-ziparchive`` -> ``League\Flysystem\ZipArchive\ZipArchiveAdapter``

If a package is missing, ``StorageFactory`` throws an install hint with the package name.

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

Constructor mode example (official drivers):

.. code-block:: php

   StorageFactory::mount('s3', [
       'driver' => 's3',
       'constructor' => [$client, 'my-bucket', 'app-prefix'],
   ]);

Read-only/path-prefix wrappers (official adapters):

.. code-block:: php

   use League\Flysystem\Local\LocalFilesystemAdapter;

   StorageFactory::mount('readonly', [
       'driver' => 'read-only',
       'constructor' => [new LocalFilesystemAdapter('/srv/storage')],
   ]);

   StorageFactory::mount('prefixed', [
       'driver' => 'path-prefixing',
       'constructor' => [new LocalFilesystemAdapter('/srv/storage'), 'tenant-a'],
   ]);

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
