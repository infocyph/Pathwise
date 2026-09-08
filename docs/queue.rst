Queue
=====

Namespace: ``Infocyph\Pathwise\Queue``

``FileJobQueue`` is a lightweight, durable, single-host file-backed queue with
explicit lease ownership.

Capabilities
------------

* Enqueue jobs with payload and priority.
* Reserve a job through a typed ``QueueReservation``.
* Renew an active lease for long-running manual workers.
* Acknowledge, release, or fail a reservation only while its lease is current.
* Process jobs through a convenience handler loop.
* Track ``pending``, ``processing``, and ``failed`` buckets.
* Return queue statistics via ``stats()``.
* Coordinate concurrent local workers through a stable lock file.
* Replace committed queue state through a same-directory temporary file rather
  than truncating the live JSON document in place.

Queue job and lease identifiers use cryptographically secure random values.
The on-disk state is versioned and validated strictly. Invalid JSON, empty or
truncated state, duplicate job identifiers, malformed bucket state, and an
unsupported state version are reported explicitly rather than being silently
replaced.

``FileJobQueue`` requires a direct-local path. Mounted/default Flysystem paths
are rejected because queue coordination relies on local locking and atomic
same-directory state replacement. Pathwise-created queue/lock state is private
by default where POSIX permissions are available.

``FileJobQueue`` is intended for lightweight single-host workloads. It is not a
replacement for a distributed broker such as RabbitMQ, NATS, Kafka, or a
managed queue service.

Good fit:

* Small background workflows without an external broker.
* Local worker processes sharing one filesystem host.
* Deterministic local job orchestration in scripts and tools.

Simple processing
-----------------

.. code-block:: php

   use Infocyph\Pathwise\Queue\FileJobQueue;
   use Infocyph\Pathwise\Queue\QueueReservation;

   $queue = new FileJobQueue('/var/lib/my-app/jobs.json');
   $queue->enqueue('thumbnail.generate', ['id' => 12], priority: 10);

   $result = $queue->process(function (QueueReservation $reservation): void {
       $type = $reservation->type;
       $payload = $reservation->payload;

       // Process the job. Returning successfully acknowledges the current lease.
   });

``process()`` reserves one job at a time. A successful handler is acknowledged;
a thrown exception moves the current lease to the failed bucket. If the lease
has expired and another worker has reclaimed it before acknowledgement, the
stale worker cannot remove or fail the newer worker's reservation.

Manual lease lifecycle
----------------------

For workers that need explicit lifecycle control, use ``reserve()`` directly:

.. code-block:: php

   use Infocyph\Pathwise\Queue\FileJobQueue;

   $queue = new FileJobQueue(
       '/var/lib/my-app/jobs.json',
       reservationTimeout: 60,
   );

   $reservation = $queue->reserve();
   if ($reservation === null) {
       return;
   }

   try {
       // For work that may approach the lease timeout, renew before expiry.
       $reservation = $queue->renew($reservation);

       // Perform work using $reservation->type and $reservation->payload.

       $queue->acknowledge($reservation);
   } catch (Throwable $failure) {
       $queue->fail($reservation, $failure);
   }

Available lease operations:

``reserve()``
   Claims the highest-priority pending job and returns a new lease token.

``renew($reservation)``
   Verifies current ownership, advances the reservation timestamp, and returns
   an updated ``QueueReservation`` with the same lease token and new expiry.

``acknowledge($reservation)``
   Removes the job only if the supplied job ID and lease token still identify
   the current processing reservation.

``release($reservation)``
   Returns the currently owned job to the pending bucket and clears its lease.
   A later reservation receives a different lease token.

``fail($reservation, $failure)``
   Moves the currently owned job to the failed bucket with bounded failure
   detail.

Stale workers
-------------

A lease token is ownership, while ``reservedAt`` is only the expiry clock.
Consider two workers:

1. Worker A reserves job ``J`` and receives lease ``A``.
2. A runs beyond the reservation timeout.
3. Worker B reserves the reclaimed job and receives lease ``B``.
4. A later calls ``acknowledge()``, ``release()``, ``renew()``, or ``fail()``
   using lease ``A``.
5. Pathwise rejects A as stale; B remains the owner and can complete safely.

This prevents an expired worker from deleting or mutating a newer worker's
reservation merely because both workers refer to the same job ID.

Persistence model
-----------------

The queue uses two local files:

* ``jobs.json`` — versioned committed queue state.
* ``jobs.json.lock`` — stable coordination lock that is never replaced during a
  state commit.

Writers hold the stable lock, encode and flush a private same-directory
temporary file, then replace the committed state. A crash before replacement
leaves the previous committed state intact and may leave an orphan Pathwise
temporary file; the next initialization removes such orphan temporary state
under the stable exclusive lock. Pathwise does not guess recovery from a
corrupt live state: corrupt, unsupported, empty, or truncated committed state
fails explicitly.

Operational notes
-----------------

* Keep the queue on a local filesystem with reliable file locking and rename
  semantics.
* Set ``reservationTimeout`` longer than the normal interval between lease
  renewals for manual workers.
* A handler used with ``process()`` should normally complete within the
  reservation timeout because synchronous ``process()`` cannot heartbeat a
  handler while user code is executing.
* For long-running work requiring heartbeats, use the manual reservation API and
  renew the lease from the worker's own execution model.
* Queue payload, total state size, and total job count remain bounded by the
  constructor limits.
