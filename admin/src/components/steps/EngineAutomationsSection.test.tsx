import { fireEvent, render, screen } from '@testing-library/react';
import { useReducer } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
  getAutomationsCatalog,
  getAutomationsConfig,
  type AutomationsCatalogResponse,
} from '../../api/automations';
import { ApiError } from '../../api/client';
import { listWorkflows } from '../../api/workflows';
import { _resetUseWorkflowsCache } from '../../hooks/useWorkflows';
import { buildSettingsInitialState } from '../../state/settings-reducer';
import { type WizardState } from '../../state/types';
import { wizardReducer } from '../../state/wizard-reducer';
import { EngineAutomationsSection } from './EngineAutomationsSection';

vi.mock('../../api/automations', async (importOriginal) => {
  const original = await importOriginal<Record<string, unknown>>();
  return {
    ...original,
    getAutomationsCatalog: vi.fn(),
    getAutomationsConfig: vi.fn(),
    putAutomationsConfig: vi.fn(),
  };
});

vi.mock('../../api/workflows', () => ({
  listWorkflows: vi.fn(),
}));

const catalogMock = vi.mocked(getAutomationsCatalog);
const configMock = vi.mocked(getAutomationsConfig);
const workflowsMock = vi.mocked(listWorkflows);

/**
 * A catalog whose trigger key this plugin has never heard of — proving
 * the render is purely catalog-driven (contract §11: a new trigger
 * ships with an engine deploy, no plugin release).
 */
const CATALOG: AutomationsCatalogResponse = {
  triggers: [
    {
      key: 'quantum_upsell_2027',
      name_et: 'Kvant-lisamüük',
      name_en: 'Quantum upsell',
      description_et: 'Kirjeldus eesti keeles.',
      description_en: 'Description in English.',
      recipe_et: 'Ehita Smailys vastav automatsioon.',
    },
  ],
  language_modes: ['single', 'per_language'],
  docs: 'https://intelligence.smaily.example/docs/templates',
};

function connectedState(): WizardState {
  return {
    ...buildSettingsInitialState({ smailyConnected: true, detectedLanguages: ['et'] }),
    recEngineConnection: { kind: 'success', message: 'Test tenant' },
  };
}

function disconnectedState(): WizardState {
  return buildSettingsInitialState({ smailyConnected: true });
}

function Harness({
  initial,
  inSettings = true,
}: {
  initial: WizardState;
  inSettings?: boolean;
}): React.JSX.Element {
  const [state, dispatch] = useReducer(wizardReducer, initial);
  return <EngineAutomationsSection state={state} dispatch={dispatch} inSettings={inSettings} />;
}

describe('EngineAutomationsSection', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    _resetUseWorkflowsCache();
    window.location.hash = '';
    document.documentElement.lang = 'en-US';
    catalogMock.mockResolvedValue(CATALOG);
    configMock.mockResolvedValue({ configs: [] });
    workflowsMock.mockResolvedValue({
      workflows: [
        { id: '123', name: 'Replenishment flow', status: 'ACTIVE' },
        { id: '124', name: 'Old flow', status: 'INACTIVE' },
      ],
    });
  });

  afterEach(() => {
    _resetUseWorkflowsCache();
    window.location.hash = '';
    document.documentElement.lang = '';
  });

  it('shows the upsell banner with a tab CTA when the engine is not connected (settings)', () => {
    render(<Harness initial={disconnectedState()} />);

    expect(screen.getByText(/connect campaign intelligence to unlock/i)).toBeInTheDocument();
    expect(catalogMock).not.toHaveBeenCalled();

    fireEvent.click(screen.getByRole('button', { name: /open campaign intelligence/i }));
    expect(window.location.hash).toBe('#recommendations');
  });

  it('shows the next-step hint instead of a CTA in the wizard context', () => {
    render(<Harness initial={disconnectedState()} inSettings={false} />);

    expect(screen.getByText(/in the next step/i)).toBeInTheDocument();
    expect(
      screen.queryByRole('button', { name: /open campaign intelligence/i }),
    ).not.toBeInTheDocument();
  });

  it('renders an unknown catalog trigger dynamically with the locale-matched copy + docs link', async () => {
    render(<Harness initial={connectedState()} />);

    // English admin locale → _en fields; recipe_et is always shown.
    expect(await screen.findByText('Quantum upsell')).toBeInTheDocument();
    expect(screen.getByText('Description in English.')).toBeInTheDocument();
    expect(screen.getByText(/ehita smailys vastav automatsioon/i)).toBeInTheDocument();

    const docsLink = screen.getByRole('link', { name: /smaily templates guide/i });
    expect(docsLink).toHaveAttribute('href', CATALOG.docs);

    // Fail-closed default row: off + test mode with the test-address field.
    expect(screen.getByText('Off')).toBeInTheDocument();
    expect(screen.getByText(/test mode is on/i)).toBeInTheDocument();
  });

  it('uses the _et copy when the admin locale is Estonian', async () => {
    document.documentElement.lang = 'et';
    render(<Harness initial={connectedState()} />);

    expect(await screen.findByText('Kvant-lisamüük')).toBeInTheDocument();
    expect(screen.getByText('Kirjeldus eesti keeles.')).toBeInTheDocument();
  });

  it('filters INACTIVE workflows out of the dropdown', async () => {
    render(<Harness initial={connectedState()} />);

    const select = await screen.findByRole('combobox');
    const optionLabels = Array.from(select.querySelectorAll('option')).map((o) => o.textContent);
    expect(optionLabels).toContain('Replenishment flow');
    expect(optionLabels).not.toContain('Old flow');
  });

  it('requires a confirm before switching test mode off, and offers the way back', async () => {
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);
    render(<Harness initial={connectedState()} />);

    const activate = await screen.findByRole('button', { name: /activate for real/i });

    // Declined → still in test mode.
    fireEvent.click(activate);
    expect(confirmSpy).toHaveBeenCalledTimes(1);
    expect(screen.getByText(/test mode is on/i)).toBeInTheDocument();

    // Confirmed → live, with a "back to test mode" escape hatch.
    confirmSpy.mockReturnValue(true);
    fireEvent.click(activate);
    expect(await screen.findByText(/live — real customers/i)).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /back to test mode/i }));
    expect(await screen.findByText(/test mode is on/i)).toBeInTheDocument();
  });

  it('flags an enabled trigger without a workflow at the field before any PUT', async () => {
    render(<Harness initial={connectedState()} />);

    const toggle = await screen.findByRole('switch', { name: /enable quantum upsell/i });
    fireEvent.click(toggle);

    expect(
      await screen.findByText(/pick a workflow before enabling/i),
    ).toBeInTheDocument();
  });

  it('shows the key-rejected banner when the proxy reports 502 api_key_rejected', async () => {
    catalogMock.mockRejectedValue(
      new ApiError('GET → 502', 502, { error: 'api_key_rejected', message: 'nope' }),
    );

    render(<Harness initial={connectedState()} />);

    expect(
      await screen.findByText(/rejected the stored api key/i),
    ).toBeInTheDocument();
  });

  it('offers Retry on a generic load failure and re-fetches on click', async () => {
    catalogMock.mockRejectedValueOnce(new ApiError('GET → 502', 502, { error: 'engine_unreachable', message: 'down' }));

    render(<Harness initial={connectedState()} />);

    const retry = await screen.findByRole('button', { name: /retry/i });
    fireEvent.click(retry);

    expect(await screen.findByText('Quantum upsell')).toBeInTheDocument();
    expect(catalogMock).toHaveBeenCalledTimes(2);
  });
});
