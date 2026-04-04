Observability
=============

Namespace: ``Infocyph\Pathwise\Observability``

``AuditTrail`` writes append-only JSONL records for operations.

Brief capabilities:

* Log timestamped operation events with context.
* Store audit output as line-delimited JSON.
* Integrate with ``FileOperations`` to trace file lifecycle actions.

Typical fields:

* operation name
* path/source/destination
* bytes/checksum/visibility context

Example
-------

.. code-block:: php

   use Infocyph\Pathwise\FileManager\FileOperations;
   use Infocyph\Pathwise\Observability\AuditTrail;

   $audit = new AuditTrail('/tmp/pathwise-audit.jsonl');

   (new FileOperations('/tmp/a.txt'))
       ->setAuditTrail($audit)
       ->create('hello')
       ->append("\nworld");
