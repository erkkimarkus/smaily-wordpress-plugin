import { useCallback, useEffect, useState } from 'react';

import {
  getEventDetail,
  listEvents,
  type EventDetailResponse,
  type EventRow,
  type EventSource,
} from '../../api/events';
import { Banner, Button, Card, Pill, Select, type PillTone } from '../primitives';

const PER_PAGE = 50;

function statusTone(status: string): PillTone {
  switch (status) {
    case 'sent':
      return 'success';
    case 'failed':
      return 'danger';
    case 'blocked':
      return 'warning';
    case 'pending':
      return 'brand';
    default:
      return 'neutral';
  }
}

/**
 * Event Log (PLUGIN.md §13) — read-only diagnostic view over both durable
 * queues. The merchant answers "did X sync?" (status column) and the developer
 * "why not?" (last_error + the drill-down payload). Recovery (retry) is 3.10.1.
 */
export function EventLog(): React.JSX.Element {
  const [rows, setRows] = useState<EventRow[]>([]);
  const [total, setTotal] = useState(0);
  const [failed24h, setFailed24h] = useState(0);
  const [page, setPage] = useState(1);
  const [source, setSource] = useState<EventSource | ''>('');
  const [status, setStatus] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [detail, setDetail] = useState<EventDetailResponse | null>(null);

  const load = useCallback(
    async (signal?: AbortSignal): Promise<void> => {
      setLoading(true);
      setError(null);
      try {
        const res = await listEvents({ page, perPage: PER_PAGE, source, status }, signal);
        setRows(res.events);
        setTotal(res.total);
        setFailed24h(res.failed_24h);
      } catch (e) {
        if (signal?.aborted) {
          return;
        }
        setError(e instanceof Error ? e.message : 'Failed to load the event log.');
      } finally {
        setLoading(false);
      }
    },
    [page, source, status],
  );

  useEffect(() => {
    const ctrl = new AbortController();
    void load(ctrl.signal);
    return () => ctrl.abort();
  }, [load]);

  const openDetail = useCallback(async (row: EventRow): Promise<void> => {
    try {
      setDetail(await getEventDetail(row.source, row.id));
    } catch {
      // The list row already shows last_error; a failed detail fetch is non-fatal.
    }
  }, []);

  const totalPages = Math.max(1, Math.ceil(total / PER_PAGE));

  return (
    <div className="space-y-4">
      {failed24h > 0 && (
        <Banner
          tone="danger"
          title={`${failed24h} failed ${failed24h === 1 ? 'event' : 'events'} in the last 24 hours`}
          actions={
            <Button
              variant="secondary"
              type="button"
              onClick={() => {
                setStatus('failed');
                setPage(1);
              }}
            >
              View only failed
            </Button>
          }
        >
          Some records didn&apos;t reach their destination. Review them below.
        </Banner>
      )}

      <Card
        title="Event log"
        description="Sync activity across the Smaily and recommendation-engine queues. Read-only — last 7 days of events."
      >
        <div className="flex flex-wrap items-end gap-3">
          <Select
            aria-label="Filter by source"
            value={source}
            onChange={(e) => {
              setSource(e.target.value as EventSource | '');
              setPage(1);
            }}
            options={[
              { value: '', label: 'All sources' },
              { value: 'rec_engine', label: 'Recommendations engine' },
              { value: 'smaily', label: 'Smaily' },
            ]}
          />
          <Select
            aria-label="Filter by status"
            value={status}
            onChange={(e) => {
              setStatus(e.target.value);
              setPage(1);
            }}
            options={[
              { value: '', label: 'All statuses' },
              { value: 'pending', label: 'Pending' },
              { value: 'sent', label: 'Sent' },
              { value: 'failed', label: 'Failed' },
              { value: 'blocked', label: 'Blocked' },
            ]}
          />
          <Button variant="ghost" type="button" onClick={() => void load()}>
            Refresh
          </Button>
        </div>

        {error !== null && (
          <Banner tone="danger" className="mt-4">
            {error}
          </Banner>
        )}

        <div className="mt-4 overflow-x-auto">
          <table className="w-full text-left text-xs">
            <thead className="text-text-tertiary">
              <tr className="border-b border-border">
                <th className="py-2 pr-3 font-medium">Time (UTC)</th>
                <th className="py-2 pr-3 font-medium">Source</th>
                <th className="py-2 pr-3 font-medium">Event</th>
                <th className="py-2 pr-3 font-medium">Entity</th>
                <th className="py-2 pr-3 font-medium">Status</th>
                <th className="py-2 pr-3 font-medium">Attempts</th>
                <th className="py-2 pr-3 font-medium">Last error</th>
                <th className="py-2 font-medium" />
              </tr>
            </thead>
            <tbody>
              {rows.length === 0 && !loading && (
                <tr>
                  <td colSpan={8} className="py-6 text-center text-text-tertiary">
                    No events yet.
                  </td>
                </tr>
              )}
              {rows.map((row) => (
                <tr key={`${row.source}-${row.id}`} className="border-b border-border">
                  <td className="whitespace-nowrap py-2 pr-3 font-mono text-text-secondary">
                    {row.created_at}
                  </td>
                  <td className="py-2 pr-3">{row.source === 'rec_engine' ? 'Rec' : 'Smaily'}</td>
                  <td className="py-2 pr-3 font-mono">{row.event_type}</td>
                  <td className="py-2 pr-3 font-mono text-text-secondary">{row.entity_id || '—'}</td>
                  <td className="py-2 pr-3">
                    <Pill tone={statusTone(row.status)} dot>
                      {row.status}
                    </Pill>
                  </td>
                  <td className="py-2 pr-3">
                    {row.attempts}
                    {row.max_attempts !== null ? `/${row.max_attempts}` : ''}
                  </td>
                  <td
                    className="max-w-xs truncate py-2 pr-3 text-danger"
                    title={row.last_error}
                  >
                    {row.last_error || '—'}
                  </td>
                  <td className="py-2 text-right">
                    <Button variant="ghost" type="button" onClick={() => void openDetail(row)}>
                      Details
                    </Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className="mt-4 flex items-center justify-between text-xs text-text-secondary">
          <span>
            {total} {total === 1 ? 'event' : 'events'}
            {loading ? ' · loading…' : ''}
          </span>
          <div className="flex items-center gap-2">
            <Button
              variant="ghost"
              type="button"
              disabled={page <= 1}
              onClick={() => setPage((p) => Math.max(1, p - 1))}
            >
              Previous
            </Button>
            <span>
              Page {page} of {totalPages}
            </span>
            <Button
              variant="ghost"
              type="button"
              disabled={page >= totalPages}
              onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
            >
              Next
            </Button>
          </div>
        </div>
      </Card>

      {detail !== null && <EventDetailModal detail={detail} onClose={() => setDetail(null)} />}
    </div>
  );
}

function EventDetailModal({
  detail,
  onClose,
}: {
  detail: EventDetailResponse;
  onClose: () => void;
}): React.JSX.Element {
  const { event, payload } = detail;
  let pretty = payload;
  try {
    pretty = JSON.stringify(JSON.parse(payload), null, 2);
  } catch {
    // Non-JSON payload (shouldn't happen) — show it raw.
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      {/* Backdrop is a real button so closing on outside-click stays a11y-valid. */}
      <button
        type="button"
        aria-label="Close details"
        className="absolute inset-0 h-full w-full cursor-default bg-text-primary/40"
        onClick={onClose}
      />
      <div className="relative z-10 max-h-[80vh] w-full max-w-2xl overflow-auto rounded-lg bg-surface p-6 shadow-xl">
        <div className="flex items-start justify-between gap-4">
          <h3 className="font-mono text-lg font-semibold text-text-primary">{event.event_type}</h3>
          <Button variant="ghost" type="button" onClick={onClose}>
            Close
          </Button>
        </div>
        <dl className="mt-3 grid grid-cols-2 gap-2 text-xs">
          <div>
            <dt className="text-text-tertiary">Source</dt>
            <dd>{event.source}</dd>
          </div>
          <div>
            <dt className="text-text-tertiary">Status</dt>
            <dd>{event.status}</dd>
          </div>
          <div>
            <dt className="text-text-tertiary">Entity</dt>
            <dd className="font-mono">{event.entity_id || '—'}</dd>
          </div>
          <div>
            <dt className="text-text-tertiary">Attempts</dt>
            <dd>
              {event.attempts}
              {event.max_attempts !== null ? `/${event.max_attempts}` : ''}
            </dd>
          </div>
          <div className="col-span-2">
            <dt className="text-text-tertiary">Created (UTC)</dt>
            <dd className="font-mono">{event.created_at}</dd>
          </div>
        </dl>
        {event.last_error !== '' && (
          <div className="mt-3">
            <p className="text-xs font-medium text-text-tertiary">Last error</p>
            <pre className="mt-1 whitespace-pre-wrap rounded bg-surface-muted p-2 text-xs text-danger">
              {event.last_error}
            </pre>
          </div>
        )}
        <div className="mt-3">
          <p className="text-xs font-medium text-text-tertiary">Payload</p>
          <pre className="mt-1 max-h-64 overflow-auto whitespace-pre-wrap rounded bg-surface-muted p-2 font-mono text-xs text-text-secondary">
            {pretty}
          </pre>
        </div>
      </div>
    </div>
  );
}
