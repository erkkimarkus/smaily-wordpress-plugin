import { fireEvent, render, screen } from '@testing-library/react';
import { useReducer } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { listWorkflows } from '../../api/workflows';
import { _resetUseWorkflowsCache } from '../../hooks/useWorkflows';
import { buildSettingsInitialState } from '../../state/settings-reducer';
import { type WizardState } from '../../state/types';
import { wizardReducer } from '../../state/wizard-reducer';
import { TransactionalEmailsSection } from './TransactionalEmailsSection';

vi.mock('../../api/workflows', () => ({
  listWorkflows: vi.fn(),
}));

const workflowsMock = vi.mocked(listWorkflows);

function baseState(): WizardState {
  return {
    ...buildSettingsInitialState({
      orderStatuses: [
        { slug: 'completed', name: 'Completed' },
        { slug: 'shipped', name: 'Shipped' },
      ],
    }),
  };
}

function Harness({ initial }: { initial: WizardState }): React.JSX.Element {
  const [state, dispatch] = useReducer(wizardReducer, initial);
  return <TransactionalEmailsSection state={state} dispatch={dispatch} />;
}

describe('TransactionalEmailsSection', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    _resetUseWorkflowsCache();
    workflowsMock.mockResolvedValue({
      workflows: [{ id: '77', name: 'Order confirmed', status: 'ACTIVE' }],
    });
  });

  afterEach(() => {
    _resetUseWorkflowsCache();
  });

  it('renders only the enablement toggle when off — no credential fields, no mapping rows', () => {
    render(<Harness initial={baseState()} />);

    expect(screen.getByRole('switch', { name: /enable transactional emails/i })).not.toBeChecked();
    expect(screen.queryByLabelText(/subdomain/i)).not.toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: 'Order confirmation' })).not.toBeInTheDocument();
    expect(workflowsMock).not.toHaveBeenCalled();
  });

  it('reveals the transactional credential block + both trigger sections when enabled', async () => {
    render(<Harness initial={baseState()} />);

    fireEvent.click(screen.getByRole('switch', { name: /enable transactional emails/i }));

    expect(screen.getByLabelText(/subdomain/i)).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Order confirmation' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Shipping confirmation' })).toBeInTheDocument();

    // Workflow dropdowns for the two triggers fetch the TRANSACTIONAL
    // account's list, never the default account's.
    await screen.findByRole('option', { name: 'Order confirmed' });
    expect(workflowsMock).toHaveBeenCalledWith('transactional');
    expect(workflowsMock).not.toHaveBeenCalledWith('default');
  });

  it('lists the "counts as shipped" statuses from env.orderStatuses and toggles selection', async () => {
    render(<Harness initial={baseState()} />);
    fireEvent.click(screen.getByRole('switch', { name: /enable transactional emails/i }));

    // Let the workflow-fetch effects settle before interacting, so the
    // background state update doesn't land after the test finishes.
    await screen.findAllByRole('option', { name: 'Order confirmed' });

    const completed = screen.getByRole('checkbox', { name: 'Completed' });
    const shipped = screen.getByRole('checkbox', { name: 'Shipped' });
    expect(completed).not.toBeChecked();
    expect(shipped).not.toBeChecked();

    fireEvent.click(completed);
    expect(completed).toBeChecked();
    expect(shipped).not.toBeChecked();
  });
});
