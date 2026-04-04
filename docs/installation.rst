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

Where to Use First
------------------

If you are evaluating Pathwise, start with ``FileOperations`` and
``DirectoryOperations``. They cover the largest set of day-to-day file tasks.

.. code-block:: php

   use Infocyph\Pathwise\FileManager\FileOperations;

   $ops = new FileOperations('/tmp/example.txt');
   $ops->create('initial')->update('updated');
