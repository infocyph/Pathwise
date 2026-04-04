Native Execution
================

Namespaces: ``Infocyph\Pathwise\Core`` and ``Infocyph\Pathwise\Native``

Pathwise can use OS-native commands for selected workflows via ``ExecutionStrategy``:

* ``PHP``: force pure PHP implementation.
* ``NATIVE``: force native command path.
* ``AUTO``: attempt native first, then fallback.

``NativeOperationsAdapter`` covers:

* file copy acceleration
* directory copy acceleration
* zip/unzip acceleration

Platform behavior:

* Windows: ``robocopy``, ``cmd copy``, PowerShell archive commands.
* Unix-like: ``rsync``, ``cp``, ``zip``/``unzip``.

Where to use
------------

Enable native mode when you are operating on large local trees/archives and OS
tools are available in the runtime environment.

Example
-------

.. code-block:: php

   use Infocyph\Pathwise\Core\ExecutionStrategy;
   use Infocyph\Pathwise\DirectoryManager\DirectoryOperations;

   $ops = new DirectoryOperations('/tmp/source');
   $ops->setExecutionStrategy(ExecutionStrategy::AUTO)
       ->copy('/tmp/target');
