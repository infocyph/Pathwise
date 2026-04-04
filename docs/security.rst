Security
========

Namespace: ``Infocyph\Pathwise\Security``

``PolicyEngine`` provides operation-level allow/deny rules.

Brief capabilities:

* Register policy rules per operation/path pattern.
* Support conditional callbacks for context-aware checks.
* Enforce policy with explicit violations via ``PolicyViolationException``.

Typical use:

* Restrict write/delete to approved roots.
* Block sensitive operations for specific runtime contexts.
* Centralize file-operation authorization logic.

Example
-------

.. code-block:: php

   use Infocyph\Pathwise\FileManager\FileOperations;
   use Infocyph\Pathwise\Security\PolicyEngine;

   $policy = (new PolicyEngine())
       ->allow('*', '*')
       ->deny('delete', '/var/app/protected/*');

   $file = (new FileOperations('/var/app/data/report.txt'))
       ->setPolicyEngine($policy);

   $file->create('ok'); // allowed
