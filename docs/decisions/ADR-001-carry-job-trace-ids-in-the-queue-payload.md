# ADR-001: Carry a job's trace id in its queue payload, restored per job

**Status**: Proposed
**Date**: 2026-10-05

## Context

A request's trace id is the root of its inbound `X-Amzn-Trace-Id`, and request
logs carry it as `request.amz_trace_id` (and the legacy `request.trace_id`).
Work that runs outside a request, a queued job or a console command, has no
inbound header to read, so without help its logs and its RemoteRepository calls
carry no trace id at all, and joining a job back to what caused it comes down to
timestamps.

The decision rests on these premises:

- **The dispatcher's id is only known at dispatch time.** By the time a worker
  picks a job up, the request, job or command run that queued it is gone.
- **Dispatch sites cannot know their own origin.** A model observer or
  repository method that queues a job runs under requests, jobs and commands
  alike, so the id has to come from the current unit of work, not from the
  call site.
- **A queue worker is one long-lived process running many jobs in turn**, so
  any state that outlives a job can attribute one job's id to the next.
- **Downstream services and the log pipeline join on the X-Ray id shape.** An
  id that is not in that shape lands in a different field, or nowhere.

## Decision

`TracingServiceProvider` carries the id across the queue itself:

- A queue payload hook stamps the current unit of work's id onto every job
  under `nui_trace_id`, minting one when the unit of work has none.
- A `JobProcessing` listener restores that id into a scoped `TraceContext`, or
  mints a fresh one when the payload has none, and adds it to the log context.
- A `CommandStarting` listener gives each console command run one id for the
  whole run.
- RemoteRepository falls back to `TraceContext` for its trace headers when
  there is no inbound request header.

Minted ids use the X-Ray trace id format, `1-<8 hex epoch seconds>-<24 hex>`.
Services opt in by registering the provider.

## Alternatives considered

- **Laravel `Context`**: rejected because the mint-when-absent step has to run
  after `Context` hydrates a job, which ties correctness to the order two
  providers register listeners in, and because hydration replaces the whole
  repository per job, coupling the trace id to whatever else an application
  keeps there.
- **The error tracker's own trace propagation**: rejected because its trace id
  is unrelated to the X-Ray id request logs carry, so a job could not join its
  request's logs without a bridge, and log correlation would depend on the
  error tracker's configuration.
- **Passing the id to each job's constructor**: rejected because dispatch
  sites cannot know which unit of work they run under (see Context), so most
  of them would have to guess.
- **Building repositories fresh per job**: rejected as the carrier because it
  isolates jobs from each other but gives a job no way to learn its
  dispatcher's id.

## Consequences

- A job shares the id of the request, job or command run that dispatched it,
  so a request's whole fan-out joins on one key. Retries keep the original id,
  since the payload is reused.
- Every job payload carries `nui_trace_id`. A producer outside Laravel can set
  it to join an existing trace; one that does not set it gets a fresh id per
  message.
- Minted ids are correlation ids in X-Ray shape, not X-Ray traces: nothing
  records segments for them.
- A service that does not register the provider behaves exactly as before.
- `X-Request-ID` stays request-only, because each service mints its own.
