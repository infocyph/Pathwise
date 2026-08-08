Native Execution
================

Namespaces: ``Infocyph\Pathwise\Core`` and ``Infocyph\Pathwise\Native``

Pathwise can use OS-native commands for selected workflows via ``ExecutionStrategy``:

* ``PHP``: force pure PHP implementation.
* ``NATIVE``: require a local path and available executable; throw on failure.
* ``AUTO``: attempt native first when supported, then fall back to PHP.

``NativeOperationsAdapter`` covers:

* file copy acceleration
* directory copy acceleration
* zip/unzip acceleration

Platform behavior:

* Windows: ``robocopy``, ``cmd copy``, PowerShell archive commands.
* Unix-like: ``rsync``, ``cp``, ``zip``/``unzip``.

Native mode is local-filesystem-only. Mounted and default-Flysystem paths are
rejected even when their backing adapter happens to use a local directory.
Failures retain the exit code and output in ``NativeExecutionResult`` or the
resulting ``NativeExecutionException``. Caller paths are passed as escaped
arguments; caller-provided shell fragments are not accepted.

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
