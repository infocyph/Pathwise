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
* Local-only structured transaction rollback and policy enforcement.
* Native local append plus explicit ``appendEmulated()`` object replacement for mounts.

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

* Streaming line, character, binary chunk, CSV, JSON Lines, and XML modes.
* Whole-document ``jsonArray()`` decoding for complete JSON arrays (memory use
  is proportional to the document size).
* Lock-aware reads for safer concurrent usage.
* Explicit generator APIs; the reader itself implements ``Countable``, not ``Iterator``.

Example:

.. code-block:: php

   use Infocyph\Pathwise\FileManager\SafeFileReader;

   $reader = new SafeFileReader('/tmp/report.txt');
   foreach ($reader->lines() as $line) {
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
   $writer->enableAtomicWrite();
   $writer->writeLine('started');
   $writer->writeLine('finished');
   $writer->close();

``FileCompression``
-------------------

Brief capabilities:

* ZIP compress/decompress.
* Password + AES modes.
* Include/exclude glob patterns.
* Ignore-file support (for example ``.pathwiseignore``).
* Hook and progress callback support.
* Shared pre-extraction validation for traversal, absolute/drive paths, null bytes,
  symbolic links, and destination breakout.

Example:

.. code-block:: php

   use Infocyph\Pathwise\FileManager\FileCompression;

   $zip = new FileCompression('/tmp/archive.zip', true);
   $zip->setGlobPatterns(includePatterns: ['*.txt'], excludePatterns: ['*.tmp'])
       ->compress('/tmp/source')
       ->save();
