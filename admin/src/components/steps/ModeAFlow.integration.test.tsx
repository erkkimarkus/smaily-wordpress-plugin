import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useReducer } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import * as testConnectionApi from '../../api/testConnection';
import { _resetUseWorkflowsCache } from '../../hooks/useWorkflows';
import { wizardInitialState, wizardReducer } from '../../state/wizard-reducer';
import { Step1Connect } from './Step1Connect';

describe('Step 1 — Mode A multi-account flow', () => {
  beforeEach(() => {
    _resetUseWorkflowsCache();
    vi.restoreAllMocks();
  });

  afterEach(() => {
    _resetUseWorkflowsCache();
    vi.restoreAllMocks();
  });

  it('shows per-language credential blocks + fallback radio when Mode A is picked', () => {
    const seeded = {
      ...wizardInitialState,
      multilingualMode: 'A' as const,
      env: {
        ...wizardInitialState.env,
        detectedLanguages: ['et_EE', 'en_US'],
      },
    };

    function Host(): React.JSX.Element {
      const [state, dispatch] = useReducer(wizardReducer, seeded);
      return <Step1Connect state={state} dispatch={dispatch} />;
    }

    render(<Host />);

    // "default account" appears twice (block heading + fallback label) so
    // we use getAllByText; per-language headings are unique.
    expect(screen.getAllByText(/default account/i).length).toBeGreaterThan(0);
    expect(screen.getByText(/account for et-EE/i)).toBeInTheDocument();
    expect(screen.getByText(/account for en-US/i)).toBeInTheDocument();

    // MultilingualModePicker (3 radios) + fallback picker (3 radios) =
    // six total when mode A is selected with 2 detected languages.
    expect(screen.getAllByRole('radio').length).toBe(6);
  });

  it('lazily creates perLanguageAccounts on first field edit', async () => {
    vi.spyOn(testConnectionApi, 'testSmailyConnection').mockResolvedValue({
      connected: true,
      accountName: 'Estonian shop',
    });

    const seeded = {
      ...wizardInitialState,
      multilingualMode: 'A' as const,
      env: {
        ...wizardInitialState.env,
        // Mode A only renders per-language blocks when there's more than
        // one detected language — single-language sites collapse back to
        // the default-only flow regardless of mode.
        detectedLanguages: ['et_EE', 'en_US'],
      },
    };

    function Host(): React.JSX.Element {
      const [state, dispatch] = useReducer(wizardReducer, seeded);
      return (
        <>
          <Step1Connect state={state} dispatch={dispatch} />
          <output data-testid="account-count">{state.perLanguageAccounts.length}</output>
          <output data-testid="et-subdomain">
            {state.perLanguageAccounts.find((a) => a.accountKey === 'account_et_EE')?.credentials
              .subdomain ?? ''}
          </output>
        </>
      );
    }

    render(<Host />);

    expect(screen.getByTestId('account-count')).toHaveTextContent('0');

    // Multiple "Subdomain" labels exist (default + per-language) so we
    // address the Estonian one by its id directly.
    const subdomainInput = document.querySelector<HTMLInputElement>('#smaily-subdomain-et_EE');
    expect(subdomainInput).not.toBeNull();
    fireEvent.change(subdomainInput!, { target: { value: 'estonia' } });

    await waitFor(() => {
      expect(screen.getByTestId('account-count')).toHaveTextContent('1');
    });
    expect(screen.getByTestId('et-subdomain')).toHaveTextContent('estonia');
  });
});
