Queue
=====

Namespace: ``Infocyph\Pathwise\Queue``

``FileJobQueue`` is a lightweight file-backed queue.

Brief capabilities:

* Enqueue jobs with payload and priority.
* Process jobs with a handler callback.
* Track ``pending``, ``processing``, and ``failed`` buckets.
* Return queue statistics via ``stats()``.

Good fit:

* Small background workflows without external brokers.
* Deterministic local job orchestration in scripts/tools.

Example
-------

.. code-block:: php

   use Infocyph\Pathwise\Queue\FileJobQueue;

   $queue = new FileJobQueue('/tmp/jobs.json');
   $queue->enqueue('thumbnail.generate', ['id' => 12], priority: 10);

   $result = $queue->process(function (array $job): void {
       // handle $job['type'] and $job['payload']
   });
