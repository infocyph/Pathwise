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
* SFTP: ``league/flysystem-sftp-v3``
* FTP: ``league/flysystem-ftp``
* Async AWS S3: ``league/flysystem-async-aws-s3``

See ``storage-adapters`` for setup patterns.

Where to Use First
------------------

If you are evaluating Pathwise, start with ``FileOperations`` and
``DirectoryOperations``. They cover the largest set of day-to-day file tasks.

.. code-block:: php

   use Infocyph\Pathwise\FileManager\FileOperations;

   $ops = new FileOperations('/tmp/example.txt');
   $ops->create('initial')->update('updated');
