import { render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import * as eventsApi from '../../api/events';
import { EventLog } from './EventLog';

const ROW = {
  id: 1,
  source: 'rec_engine' as const,
  event_type: 'order.upsert',
  entity_id: '42',
  status: 'failed',
  attempts: 5,
  max_attempts: 5,
  last_error: 'http_503 service unavailable',
  created_at: '2026-06-09 10:00:00',
};

describe('EventLog', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('renders rows from the events API', async () => {
    vi.spyOn(eventsApi, 'listEvents').mockResolvedValue({
      events: [ROW],
      total: 1,
      page: 1,
      per_page: 50,
      failed_24h: 0,
    });

    render(<EventLog />);

    expect(await screen.findByText('order.upsert')).toBeInTheDocument();
    expect(screen.getByText('http_503 service unavailable')).toBeInTheDocument();
  });

  it('shows the sticky failed-24h banner when failures exist', async () => {
    vi.spyOn(eventsApi, 'listEvents').mockResolvedValue({
      events: [ROW],
      total: 1,
      page: 1,
      per_page: 50,
      failed_24h: 3,
    });

    render(<EventLog />);

    expect(await screen.findByText(/3 failed events in the last 24 hours/i)).toBeInTheDocument();
  });

  it('renders the empty state when there are no events', async () => {
    vi.spyOn(eventsApi, 'listEvents').mockResolvedValue({
      events: [],
      total: 0,
      page: 1,
      per_page: 50,
      failed_24h: 0,
    });

    render(<EventLog />);

    await waitFor(() => {
      expect(screen.getByText('No events yet.')).toBeInTheDocument();
    });
  });
});
