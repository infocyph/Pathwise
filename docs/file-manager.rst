File Manager
============

Namespace: ``Infocyph\Pathwise\FileManager``

Where it fits:

* Use this module when your main workload is file-level IO, transformation,
  integrity checks, and archive handling.

``FileOperations``
------------------

Brief capabilities:

* Create/read/update/append/delete/rename/copy.
* Checksum helpers: ``verifyChecksum()``, ``writeAndVerify()``, ``copyWithVerification()``.
* Stream APIs: ``readStream()``, ``writeStream()``.
* Visibility/URL passthrough where adapter supports it.
* Optional transaction rollback and policy enforcement.

Example:

.. code-block:: php

   use Infocyph\Pathwise\FileManager\FileOperations;

   $file = new FileOperations('/tmp/report.txt');
   $file->create('v1');
   $file->writeAndVerify("v2\n", 'sha256');
   $file->copyWithVerification('/tmp/report-copy.txt');

``SafeFileReader``
------------------

Brief capabilities:

* Memory-safe reads: line, char, binary chunk, CSV, JSON, XML.
* Lock-aware reads for safer concurrent usage.
* Iterator-friendly API.

Example:

.. code-block:: php

   use Infocyph\Pathwise\FileManager\SafeFileReader;

   $reader = new SafeFileReader('/tmp/report.txt');
   foreach ($reader->line() as $line) {
       // process line
   }

``SafeFileWriter``
------------------

Brief capabilities:

* Structured writers for text/CSV/JSON/XML/binary.
* Lock support.
* Atomic write mode (temp file + rename).
* Checksum verification support.

Example:

.. code-block:: php

   use Infocyph\Pathwise\FileManager\SafeFileWriter;

   $writer = new SafeFileWriter('/tmp/events.log');
   $writer->enableAtomicWrite()
       ->line('started')
       ->line('finished');

``FileCompression``
-------------------

Brief capabilities:

* ZIP compress/decompress.
* Password + AES modes.
* Include/exclude glob patterns.
* Ignore-file support (for example ``.pathwiseignore``).
* Hook and progress callback support.

Example:

.. code-block:: php

   use Infocyph\Pathwise\FileManager\FileCompression;

   $zip = new FileCompression('/tmp/archive.zip', true);
   $zip->setGlobPatterns(includePatterns: ['*.txt'], excludePatterns: ['*.tmp'])
       ->compress('/tmp/source')
       ->save();
