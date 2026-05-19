import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import * as saveApi from '../../api/saveSettings';
import { _resetUseWorkflowsCache } from '../../hooks/useWorkflows';
import { Settings } from './Settings';

describe('Settings — tab routing + dirty + save', () => {
  beforeEach(() => {
    _resetUseWorkflowsCache();
    vi.restoreAllMocks();
    window.location.hash = '';
  });

  afterEach(() => {
    _resetUseWorkflowsCache();
    vi.restoreAllMocks();
    window.location.hash = '';
  });

  it('lands on Connection tab by default with Save + Discard disabled', () => {
    render(<Settings />);

    // Connection tab is the active panel — subdomain field is from Step1Connect.
    expect(screen.getByLabelText(/subdomain/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /save changes/i })).toBeDisabled();
    expect(screen.getByRole('button', { name: /discard changes/i })).toBeDisabled();
  });

  it('marks the Connection tab dirty after a credential edit and enables Save', () => {
    render(<Settings />);

    fireEvent.change(screen.getByLabelText(/subdomain/i), { target: { value: 'mypetshop' } });

    expect(screen.getByRole('button', { name: /save changes/i })).not.toBeDisabled();
    expect(screen.getByRole('button', { name: /discard changes/i })).not.toBeDisabled();
  });

  it('dispatches the connection payload on Save and clears the dirty flag', async () => {
    const saveSpy = vi.spyOn(saveApi, 'saveSettings').mockResolvedValue({
      saved: true,
      errors: [],
    });

    render(<Settings />);
    fireEvent.change(screen.getByLabelText(/subdomain/i), { target: { value: 'mypetshop' } });
    fireEvent.change(screen.getByLabelText(/api username/i), { target: { value: 'alice' } });
    fireEvent.change(screen.getByLabelText(/api password/i), { target: { value: 's3cret' } });

    fireEvent.click(screen.getByRole('button', { name: /save changes/i }));

    await waitFor(() => {
      expect(saveSpy).toHaveBeenCalledWith(
        expect.objectContaining({ tab: 'connection' }),
        expect.anything(),
      );
    });

    const callArg = saveSpy.mock.calls[0]?.[0];
    expect(callArg?.data).toMatchObject({
      smailyCredentials: {
        subdomain: 'mypetshop',
        username: 'alice',
        password: 's3cret',
      },
    });

    await waitFor(() => {
      expect(screen.getByRole('button', { name: /save changes/i })).toBeDisabled();
    });
    expect(screen.getByText(/settings saved/i)).toBeInTheDocument();
  });

  it('switches tabs based on a hash change without rerendering everything', async () => {
    render(<Settings />);

    // Initial — connection panel
    expect(screen.getByLabelText(/subdomain/i)).toBeInTheDocument();

    // Programmatic hash change should swap the panel
    window.location.hash = 'subscribers';
    window.dispatchEvent(new HashChangeEvent('hashchange'));

    await waitFor(() => {
      expect(screen.getByText(/sync contacts to smaily/i)).toBeInTheDocument();
    });
    expect(screen.queryByLabelText(/subdomain/i)).not.toBeInTheDocument();
  });

  it('hides Save / Discard footer on the Integrations tab', async () => {
    render(<Settings />);

    window.location.hash = 'integrations';
    window.dispatchEvent(new HashChangeEvent('hashchange'));

    await waitFor(() => {
      expect(screen.queryByRole('button', { name: /save changes/i })).not.toBeInTheDocument();
      expect(screen.queryByRole('button', { name: /discard changes/i })).not.toBeInTheDocument();
    });
  });
});
