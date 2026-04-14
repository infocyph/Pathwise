Installation
============

Install with Composer:

.. code-block:: bash

   composer require infocyph/pathwise

Requirements:

* PHP 8.4+
* ``league/flysystem`` 3.x
* ``ext-fileinfo``

Optional extensions:

* ``ext-zip`` for ZIP features.
* ``ext-pcntl`` for long-running watch loops.
* ``ext-posix`` for richer Unix ownership details.
* ``ext-xmlreader`` and ``ext-simplexml`` for XML helpers.

Optional adapter packages (choose per driver):

* AWS S3: ``league/flysystem-aws-s3-v3`` + ``aws/aws-sdk-php``
* Async AWS S3: ``league/flysystem-async-aws-s3`` + ``async-aws/s3``
* Azure Blob Storage: ``league/flysystem-azure-blob-storage``
* Google Cloud Storage: ``league/flysystem-google-cloud-storage``
* MongoDB GridFS: ``league/flysystem-gridfs``
* SFTP: ``league/flysystem-sftp-v3``
* SFTP (V2): ``league/flysystem-sftp-v2``
* FTP: ``league/flysystem-ftp``
* WebDAV: ``league/flysystem-webdav``
* ZIP archive: ``league/flysystem-ziparchive``
* In-memory: ``league/flysystem-memory``
* Read-only wrapper: ``league/flysystem-read-only``
* Path prefixing wrapper: ``league/flysystem-path-prefixing``

See ``storage-adapters`` for setup patterns.

Where to Use First
------------------

If you are evaluating Pathwise, start with the unified ``File`` facade, then
drop down to direct module classes as needed.

.. code-block:: php

   use Infocyph\Pathwise\File;

   $ops = File::at('/tmp/example.txt')->file();
   $ops->create('initial')->update('updated');
