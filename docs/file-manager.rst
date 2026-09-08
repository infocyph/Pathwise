File Manager
============

Namespace: ``Infocyph\Pathwise\FileManager``

Use this module for file-level I/O, transformation, integrity checks,
transactions, and archive handling. Storage-neutral operations can use direct
local paths or the low-level Flysystem routing surface; capabilities that depend
on real local filesystem semantics fail explicitly when a path is
adapter-backed.

``FileOperations``
------------------

Capabilities include:

* create/read/update/delete/rename/copy;
* checksum helpers such as ``verifyChecksum()``, ``writeAndVerify()`` and
  ``copyWithVerification()``;
* stream APIs through ``readStream()`` and ``writeStream()``;
* visibility/public-URL passthrough where the selected adapter supports it;
* direct-local structured transactions and rollback;
* policy and audit hooks;
* native local append plus explicit ``appendEmulated()`` whole-object
  replacement for adapter-backed storage.

.. code-block:: php

   use Infocyph\Pathwise\FileManager\FileOperations;

   $file = new FileOperations('/tmp/report.txt');
   $file->create('v1');
   $file->writeAndVerify("v2\n", 'sha256');
   $file->copyWithVerification('/tmp/report-copy.txt');

Transactions are direct-local, process-local mutation journals rather than a
database-style isolation mechanism. Nested transactions are rejected, rollback
state is private where supported, invalid lifecycle operations are typed, and a
rollback failure is reported explicitly rather than hiding it behind the
original operation error.

``SafeFileReader``
------------------

Capabilities include:

* streaming line, character, binary chunk, CSV, JSON Lines and XML modes;
* whole-document ``jsonArray()`` decoding when the complete JSON array is
  intentionally needed;
* lock-aware reads for direct-local concurrent usage;
* explicit generator APIs; the reader itself is ``Countable``, not an
  ``Iterator``;
* defensive serialized-value reading that disables PHP class instantiation and
  rejects unsafe decoded object/resource-like values.

.. code-block:: php

   use Infocyph\Pathwise\FileManager\SafeFileReader;

   $reader = new SafeFileReader('/tmp/report.txt');
   foreach ($reader->lines() as $line) {
       // Process incrementally.
   }

Prefer the streaming APIs for large data. Whole-document helpers necessarily
consume memory proportional to the document.

``SafeFileWriter``
------------------

Capabilities include:

* structured text/CSV/JSON/XML/binary writing;
* direct-local lock support;
* checksum verification;
* direct-local atomic replacement through ``enableAtomicWrite()``;
* ordinary staged adapter writes where atomic rename semantics cannot be
  promised.

.. code-block:: php

   use Infocyph\Pathwise\FileManager\SafeFileWriter;

   $writer = new SafeFileWriter('/tmp/events.log');
   $writer->enableAtomicWrite();
   $writer->writeLine('started');
   $writer->writeLine('finished');
   $writer->close();

Atomic mode is intentionally a local guarantee: Pathwise stages beside the
local destination and requires the final rename to succeed. Adapter-backed
publication is not described as atomic merely because Pathwise can stage data
before the final write.

``FileCompression``
-------------------

Capabilities include:

* ZIP creation and extraction;
* password/AES modes where the ZIP capability supports them;
* include/exclude glob patterns and ignore-file support;
* hook/progress callbacks;
* shared manifest-based archive validation used by the extraction APIs;
* entry-count, per-entry/total expanded-size, compression-ratio and actual
  streamed-byte enforcement;
* rejection of traversal/absolute/drive/UNC/null paths, canonical collisions,
  ZIP symlinks, unsupported special entries and destination breakout;
* deterministic local/adapter staging cleanup and publication checks.

.. code-block:: php

   use Infocyph\Pathwise\FileManager\FileCompression;

   $zip = new FileCompression('/tmp/archive.zip', true);
   $zip->setGlobPatterns(includePatterns: ['*.txt'], excludePatterns: ['*.tmp'])
       ->compress('/tmp/source')
       ->save();

Archive creation rejects source symlinks rather than following them into
content outside the selected source tree. See :doc:`storage-contracts`,
:doc:`security`, and :doc:`performance-portability` for the guarantee and
scaling boundaries.
