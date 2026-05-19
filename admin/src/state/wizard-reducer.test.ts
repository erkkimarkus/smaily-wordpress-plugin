import { describe, expect, it } from 'vitest';

import { type ModeAccount, type WizardState } from './types';
import { wizardInitialState, wizardReducer } from './wizard-reducer';
import { buildSettingsInitialState } from './settings-reducer';

const baseState: WizardState = wizardInitialState;

describe('wizardReducer — Step 1 Connect', () => {
  it('merges credential field updates partially', () => {
    const next = wizardReducer(baseState, {
      type: 'SET_SMAILY_CREDENTIALS',
      payload: { subdomain: 'demo' },
    });

    expect(next.smailyCredentials.subdomain).toBe('demo');
    expect(next.smailyCredentials.username).toBe('');
    expect(next.smailyCredentials.password).toBe('');
  });

  it('invalidates a successful connection when credentials change', () => {
    const connected: WizardState = {
      ...baseState,
      smailyConnection: { kind: 'success' },
    };

    const next = wizardReducer(connected, {
      type: 'SET_SMAILY_CREDENTIALS',
      payload: { username: 'alice' },
    });

    expect(next.smailyConnection).toEqual({ kind: 'idle' });
  });

  it('transitions connection-test pending → success → failure', () => {
    let s = wizardReducer(baseState, { type: 'TEST_SMAILY_CONNECTION_START' });
    expect(s.smailyConnection.kind).toBe('pending');

    s = wizardReducer(s, {
      type: 'TEST_SMAILY_CONNECTION_SUCCESS',
      payload: { accountName: 'My Pet Shop' },
    });
    expect(s.smailyConnection).toEqual({ kind: 'success', message: 'My Pet Shop' });

    s = wizardReducer(s, {
      type: 'TEST_SMAILY_CONNECTION_FAILURE',
      payload: { error: 'invalid password' },
    });
    expect(s.smailyConnection).toEqual({ kind: 'failure', error: 'invalid password' });
  });
});

describe('wizardReducer — multilingual mode', () => {
  it('preserves perLanguageAccounts when toggling B → A', () => {
    const inB: WizardState = { ...baseState, multilingualMode: 'B' };

    const next = wizardReducer(inB, { type: 'SET_MULTILINGUAL_MODE', payload: 'A' });

    expect(next.multilingualMode).toBe('A');
    expect(next.perLanguageAccounts).toEqual([]);
  });

  it('clears perLanguageAccounts when leaving A → B', () => {
    const account: ModeAccount = {
      accountKey: 'et',
      language: 'et_EE',
      credentials: { subdomain: 'demo-et', username: 'u', password: 'p' },
      connection: { kind: 'idle' },
    };
    const inA: WizardState = {
      ...baseState,
      multilingualMode: 'A',
      perLanguageAccounts: [account],
    };

    const next = wizardReducer(inA, { type: 'SET_MULTILINGUAL_MODE', payload: 'B' });

    expect(next.multilingualMode).toBe('B');
    expect(next.perLanguageAccounts).toEqual([]);
  });

  it('refuses to add a duplicate account by accountKey', () => {
    const account: ModeAccount = {
      accountKey: 'et',
      language: 'et_EE',
      credentials: { subdomain: 'demo', username: 'u', password: 'p' },
      connection: { kind: 'idle' },
    };

    const after = wizardReducer(baseState, { type: 'ADD_MODE_ACCOUNT', payload: account });
    const stillOne = wizardReducer(after, { type: 'ADD_MODE_ACCOUNT', payload: account });

    expect(stillOne.perLanguageAccounts).toHaveLength(1);
  });

  it('updates credentials for the matched accountKey only', () => {
    const account: ModeAccount = {
      accountKey: 'et',
      language: 'et_EE',
      credentials: { subdomain: 'old', username: 'u', password: 'p' },
      connection: { kind: 'success' },
    };
    const seeded = wizardReducer(baseState, { type: 'ADD_MODE_ACCOUNT', payload: account });

    const updated = wizardReducer(seeded, {
      type: 'UPDATE_MODE_ACCOUNT_CREDENTIALS',
      payload: { accountKey: 'et', credentials: { subdomain: 'new' } },
    });

    expect(updated.perLanguageAccounts[0]?.credentials.subdomain).toBe('new');
    expect(updated.perLanguageAccounts[0]?.credentials.username).toBe('u');
    expect(updated.perLanguageAccounts[0]?.connection.kind).toBe('idle');
  });
});

describe('wizardReducer — Step 2 Subscribers', () => {
  it('toggles a sync field on then off', () => {
    const without = wizardReducer(baseState, {
      type: 'TOGGLE_SYNC_FIELD',
      payload: { field: 'first_name' },
    });
    expect(without.syncFields).not.toContain('first_name');

    const restored = wizardReducer(without, {
      type: 'TOGGLE_SYNC_FIELD',
      payload: { field: 'first_name' },
    });
    expect(restored.syncFields).toContain('first_name');
  });

  it('replaces the whole sync-fields list on SET_SYNC_FIELDS', () => {
    const next = wizardReducer(baseState, {
      type: 'SET_SYNC_FIELDS',
      payload: ['phone', 'birthday'],
    });

    expect(next.syncFields).toEqual(['phone', 'birthday']);
  });

  it('tracks backfill progress lifecycle', () => {
    let s = wizardReducer(baseState, {
      type: 'BACKFILL_START',
      payload: { jobType: 'contacts' },
    });
    expect(s.contactsBackfill.status).toBe('running');
    expect(s.contactsBackfill.error).toBeNull();

    s = wizardReducer(s, {
      type: 'BACKFILL_PROGRESS',
      payload: {
        jobType: 'contacts',
        progress: {
          status: 'running',
          processed: 25,
          total: 100,
          percent: 25,
          etaSeconds: 180,
          error: null,
        },
      },
    });
    expect(s.contactsBackfill.percent).toBe(25);

    s = wizardReducer(s, {
      type: 'BACKFILL_CANCEL',
      payload: { jobType: 'contacts' },
    });
    expect(s.contactsBackfill.status).toBe('cancelled');
  });
});

describe('wizardReducer — navigation', () => {
  it('clamps WIZARD_GO_TO_STEP into [1, 6]', () => {
    expect(
      wizardReducer(baseState, { type: 'WIZARD_GO_TO_STEP', payload: { step: 99 } }).currentStep,
    ).toBe(6);

    expect(
      wizardReducer(baseState, { type: 'WIZARD_GO_TO_STEP', payload: { step: 0 } }).currentStep,
    ).toBe(1);

    expect(
      wizardReducer(baseState, { type: 'WIZARD_GO_TO_STEP', payload: { step: 3 } }).currentStep,
    ).toBe(3);
  });

  it('walks forward and backward without overshooting', () => {
    let s = baseState;
    for (let i = 0; i < 10; i += 1) {
      s = wizardReducer(s, { type: 'WIZARD_NEXT_STEP' });
    }
    expect(s.currentStep).toBe(6);

    for (let i = 0; i < 10; i += 1) {
      s = wizardReducer(s, { type: 'WIZARD_PREVIOUS_STEP' });
    }
    expect(s.currentStep).toBe(1);
  });
});

describe('wizardReducer — exhaustiveness guard', () => {
  it('throws when handed an action type the switch does not cover', () => {
    // This intentionally bypasses the TypeScript narrowing — production code
    // can never reach here, but a malformed dispatch from non-typed sources
    // (browser dev-tools, malicious extension) should fail loudly rather
    // than silently return state.
    expect(() =>
      // @ts-expect-error — deliberately invalid action to exercise assertNever
      wizardReducer(baseState, { type: 'NEVER_DEFINED_ACTION' }),
    ).toThrow(/unhandled action/);
  });
});

describe('buildSettingsInitialState', () => {
  it('marks the state as inSettings and zeroes currentStep', () => {
    const s = buildSettingsInitialState();
    expect(s.inSettings).toBe(true);
    expect(s.currentStep).toBe(0);
  });

  it('hydrates Smaily credentials but never password', () => {
    const s = buildSettingsInitialState({
      smailyCredentials: { subdomain: 'demo', username: 'alice' },
      smailyConnected: true,
    });

    expect(s.smailyCredentials.subdomain).toBe('demo');
    expect(s.smailyCredentials.username).toBe('alice');
    expect(s.smailyCredentials.password).toBe('');
    expect(s.smailyConnection.kind).toBe('success');
  });

  it('returns sane defaults for an empty env payload', () => {
    const s = buildSettingsInitialState();
    expect(s.subscriberSyncEnabled).toBe(true);
    expect(s.syncFields).not.toHaveLength(0);
    expect(s.multilingualMode).toBe('single');
  });

  it('lets settings re-use the wizardReducer end-to-end', () => {
    let s = buildSettingsInitialState({
      smailyCredentials: { subdomain: 'demo', username: 'alice' },
      smailyConnected: true,
    });

    s = wizardReducer(s, {
      type: 'SET_SMAILY_CREDENTIALS',
      payload: { username: 'eve' },
    });

    expect(s.smailyCredentials.username).toBe('eve');
    expect(s.smailyConnection.kind).toBe('idle');
    expect(s.inSettings).toBe(true);
  });
});
