Helper Functions
================

Global helper functions are autoloaded from ``src/functions.php``.

Available helpers (brief):

* ``getHumanReadableFileSize(int $bytes): string``
* ``isDirectoryEmpty(string $directoryPath): bool``
* ``deleteDirectory(string $directoryPath): bool``
* ``getDirectorySize(string $directoryPath): int``
* ``createDirectory(string $directoryPath, int $permissions = 0755): bool``
* ``listFiles(string $directoryPath): array``
* ``copyDirectory(string $source, string $destination): bool``

Notes:

* Helpers are Flysystem-aware and can work with mounted scheme paths.
* They keep return types small and script-friendly for utility usage.

Example
-------

.. code-block:: php

   createDirectory('/tmp/demo');
   file_put_contents('/tmp/demo/a.txt', 'data');

   $size = getDirectorySize('/tmp/demo');
   $files = listFiles('/tmp/demo');
