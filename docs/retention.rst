Retention
=========

Namespace: ``Infocyph\Pathwise\Retention``

``RetentionManager`` applies cleanup policies to directories.

Brief capabilities:

* Keep only latest N files.
* Delete files older than configured age threshold.
* Combine count-based and age-based pruning.

Use cases:

* Rotating backups/log exports.
* Enforcing disk usage windows for generated artifacts.

Example
-------

.. code-block:: php

   use Infocyph\Pathwise\Retention\RetentionManager;

   $report = RetentionManager::apply(
       directory: '/tmp/backups',
       keepLast: 7,
       maxAgeDays: 30,
       sortBy: 'mtime',
   );
