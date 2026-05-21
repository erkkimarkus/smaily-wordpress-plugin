import { apiRequest } from './client';

export type BackfillJobType = 'contacts';

export interface BackfillStartResponse {
  job_id: number;
  status: string;
  total: number;
}

export interface BackfillStatusResponse {
  status: 'idle' | 'running' | 'completed' | 'failed' | 'cancelled';
  processed: number;
  total: number;
  percent: number;
  eta_seconds: number | null;
  /** MySQL DATETIME in UTC, or null when the job has never been started. */
  started_at: string | null;
  /** Set once the job reaches a terminal status; null while running/idle. */
  completed_at: string | null;
}

export interface BackfillCancelResponse {
  cancelled: boolean;
}

/**
 * Wrappers for the three /backfill/* sub-routes (sub-PR 2.0). The
 * snake_case payload + response shapes mirror the PHP REST controller
 * exactly so we don't have to maintain a translation layer on the wire.
 *
 * Phase 3 adds 'orders' / 'customers' / 'products' to BackfillJobType
 * — the wrappers don't change, only the union does.
 */

export function startBackfill(
  jobType: BackfillJobType,
  signal?: AbortSignal,
): Promise<BackfillStartResponse> {
  return apiRequest<BackfillStartResponse>('/backfill/start', {
    method: 'POST',
    body: { job_type: jobType },
    signal,
  });
}

export function getBackfillStatus(
  jobType: BackfillJobType,
  signal?: AbortSignal,
): Promise<BackfillStatusResponse> {
  return apiRequest<BackfillStatusResponse>(
    `/backfill/status?job_type=${encodeURIComponent(jobType)}`,
    { signal },
  );
}

export function cancelBackfill(
  jobType: BackfillJobType,
  signal?: AbortSignal,
): Promise<BackfillCancelResponse> {
  return apiRequest<BackfillCancelResponse>('/backfill/cancel', {
    method: 'POST',
    body: { job_type: jobType },
    signal,
  });
}
