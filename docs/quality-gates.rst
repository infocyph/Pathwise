Quality Gates
=============

Pathwise includes a cognitive complexity quality gate using:

* ``phpstan/phpstan``
* ``tomasvotruba/cognitive-complexity``

Run
---

.. code-block:: bash

   composer test:phpstan

This runs PHPStan on ``src`` with the cognitive-complexity rules enabled.

Current Thresholds
------------------

Configured in ``phpstan.neon.dist``:

* class: ``250``
* function/method: ``9``
* dependency tree: ``400``

Notes
-----

* Lower thresholds over time as the codebase is refactored.
* Keep this gate focused on ``src`` to keep signal high and runtime predictable.
