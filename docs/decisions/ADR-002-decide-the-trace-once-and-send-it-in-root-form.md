# ADR-002: Decide each unit of work's trace once, and send it in X-Ray's Root= form

**Status**: Accepted
**Date**: 2026-10-14
**Supersedes**: ADR-001's RemoteRepository fallback, its mint at dispatch, its
opt-in registration, and its "every payload carries `nui_trace_id`", "nothing
records segments for them" and "behaves exactly as before" consequences;
narrows its per-job restore to a valid root; corrects its rationale for
rejecting Laravel `Context`

## Context

ADR-001 carried a job's trace id across the queue in its payload. RemoteRepository
still chose each call's trace for itself, from several sources: the inbound
`X-Amzn-Trace-Id` verbatim, else the carried id bare; and an `X-Correlation-ID`
from the inbound Root, else the raw header, else the carried id. Measured
against a deployed API Gateway with X-Ray tracing on:

- **A bare id is not an X-Ray header.** A gateway receiving
  `X-Amzn-Trace-Id: 1-...` starts a new trace and replaces the id at every hop,
  so every call a job or command made lost the trace that started it.
  `Root=1-...` is kept at every hop.
- **`Root=<id>;Sampled=1` with no Parent is continued and recorded**, in the
  caller's trace. `Sampled=0` is continued and not recorded. A Root with no flag
  is continued and gets the gateway's own sampling decision.
- **Forwarding a request's inbound header nests each downstream hop under its
  caller in X-Ray**, through its Parent.
- **`X-Correlation-ID` rarely reached the next service, and nothing read it.**
  A non-proxy API Gateway HTTP integration forwards only the headers it maps,
  and none mapped it; only the few proxy integrations passed it through.

## Decision

- **One source.** `TraceContext` holds the current unit of work's trace: root,
  parent and sampling decision. It is started once, at the entry:
  `StartTraceContext`, prepended to the global middleware stack, for a request;
  a `JobProcessing` listener for a queued job; a `CommandStarting` listener for
  a command run with no trace yet. Nothing after the entry reads the request to
  decide the trace it sends or stamps.
- **One writer.** Header forwarding configuration cannot replace or duplicate
  the trace header.
- **One shape.** Every RemoteRepository call sends
  `Root=<root>[;Parent=<parent>][;Sampled=<0|1>]`, and only those fields. A Root
  must be `1-<8 hex>-<24 hex>` and a Parent 16 hex, in lowercase with nothing
  after them, and a Sampled `0` or `1`. An
  invalid Root, inbound or in a job's payload, starts a new trace with a minted
  root; an invalid Parent or Sampled is dropped.
- **Parent on the request path only.** A request forwards the Parent it
  received. A queued job starts with none.
- **Sampling travels with jobs**, under `nui_trace_sampled` next to
  `nui_trace_id`.
- **No trace, no header.** Outside the three entries, such as a unit test,
  nothing is sent. A queued job dispatched there mints its own root when it
  starts, and a sync one runs with none.
- **`X-Correlation-ID` is removed.**
- **The provider is auto-discovered** through the package's `composer.json`.

## Alternatives considered

- **Forward a request's inbound header verbatim**, as before: the same X-Ray
  nesting, since X-Ray links on Parent and a load balancer rewrites `Self`, but
  it keeps a second header path and forwards fields (`Lineage`, unknown ones)
  that are not this service's to propagate.
- **Send Parent from jobs**: a job's call is not a child of its request's
  segment: the job runs on its own, usually after that segment has ended. With
  no Parent the gateway
  records the job's hop as its own segment inside the same trace.
- **Root alone from jobs**: every job hop would get the gateway's own sampling
  decision, so a sampled request's jobs would be recorded only sometimes, and an
  unsampled request's jobs sometimes too.
- **W3C `traceparent` through OpenTelemetry**: needs a collector, and the
  header mapped on every gateway integration, since an HTTP integration forwards
  only the headers it maps.
- **Keep the provider opt-in**: a service upgrading without registering it
  would silently send no trace header, since RemoteRepository no longer reads
  the request.
- **Laravel `Context` as the carrier**: not decided here; see the correction
  below.

## Correction to ADR-001

ADR-001 rejected Laravel `Context` partly because "the mint-when-absent step has
to run after Context hydrates a job, which ties correctness to the order two
providers register listeners in". That premise does not hold. Laravel's queue
listener hydrates `Context` on every `JobProcessing`, passing null when the
payload carried none, and `Illuminate\Log\Context\Repository::hydrate()`
dispatches `ContextHydrated` either way, so a `Context::hydrated()` callback runs for
every job and can mint there with no listener ordering. ADR-001's other reason
stands: hydration replaces the whole repository for each job.

Moving `TraceContext` onto `Context` stays open. `Context` values reach a
Monolog record under `extra` rather than `context`, which moves the log fields a
log pipeline indexes.

## Consequences

- One root joins a request, its jobs, its command runs and every downstream
  service's logs, and a sampled request's job hops appear in its X-Ray trace.
- RemoteRepository's trace path reads only `TraceContext`, and there is one
  header writer to test.
- Breaking for 0.7.x consumers (the guide's Upgrading to 0.8.0 lists each
  change). In particular, a service without the provider no longer behaves as
  before: it sends no trace header at all, where 0.7.x forwarded the inbound
  one.
- Discovery reaches a project only when its package manifest is rebuilt, so a
  checkout with a stale `bootstrap/cache/packages.php` needs
  `php artisan package:discover` once.
- Payloads are compatible both ways: `nui_trace_id` keeps its shape and
  `nui_trace_sampled` is optional. A worker restores only a valid root, where
  ADR-001's restored any non-empty id, so a payload id of any other shape starts
  a new trace.
- A request with no valid inbound Root mints a root for its jobs and calls, but
  its own log lines do not carry it: its log fields stay with the request
  logging middleware, which logs whatever header arrived, if any.
- `X-Request-ID` stays request-only.
