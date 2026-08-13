Queue
=====

Namespace: ``Infocyph\Pathwise\Queue``

``FileJobQueue`` is a lightweight file-backed queue.

Brief capabilities:

* Enqueue jobs with payload and priority.
* Process jobs with a handler callback.
* Track ``pending``, ``processing``, and ``failed`` buckets.
* Return queue statistics via ``stats()``.
* Coordinate concurrent local readers and writers with file locks.

Queue job IDs use cryptographically secure random values. Invalid queue JSON is
reported as an error instead of being silently replaced, and ``maxJobs`` limits
all attempted jobs, including failures. Queue files must be direct-local paths;
mounted and default-Flysystem paths are rejected.

``FileJobQueue`` is intended for lightweight single-host workloads. It uses
local file locks and bounded payload/job/file sizes; it is not a remote or
distributed broker.

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
