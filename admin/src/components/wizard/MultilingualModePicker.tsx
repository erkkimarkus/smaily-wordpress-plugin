import { type ChangeEvent } from 'react';

import { type MultilingualMode } from '../../state/types';
import { Radio } from '../primitives';
import { cn } from '../../utils/cn';

export interface MultilingualModePickerProps {
  /** Currently selected mode — dispatched from WizardState.multilingualMode. */
  value: MultilingualMode;
  /** Wired to dispatch({ type: 'SET_MULTILINGUAL_MODE', payload: next }). */
  onChange: (next: MultilingualMode) => void;
  /**
   * Detected languages from the env payload. Single-language sites pass
   * an empty array (or a one-element one); the picker collapses to the
   * 'single' choice automatically.
   */
  detectedLanguages: string[];
  className?: string;
}

interface ModeOption {
  value: MultilingualMode;
  label: string;
  description: string;
}

/**
 * Three-radio-card mode picker for the Step 1 multilingual-setup
 * question (PLUGIN.md §4). Renders only when more than one language
 * is detected — single-language sites should hide the entire control
 * (caller's responsibility, since the picker doesn't render itself
 * away with `detectedLanguages.length <= 1`).
 *
 * Mode B is the default per PLUGIN.md §4 "Mode B (kõige tüüpilisem)".
 *
 * Layout mirrors the prototype's three-card row — at md+ they sit
 * side-by-side, below md they stack.
 */
export function MultilingualModePicker({
  value,
  onChange,
  detectedLanguages,
  className,
}: MultilingualModePickerProps): React.JSX.Element | null {
  if (detectedLanguages.length <= 1) {
    return null;
  }

  const handleChange = (event: ChangeEvent<HTMLInputElement>): void => {
    onChange(event.target.value as MultilingualMode);
  };

  return (
    <fieldset
      className={cn('grid gap-3 md:grid-cols-3', className)}
      aria-label="How is your Smaily setup organised for languages?"
    >
      {MODE_OPTIONS.map((option) => {
        const isSelected = option.value === value;
        return (
          <label
            key={option.value}
            className={cn(
              'flex cursor-pointer flex-col gap-2 rounded-lg border p-4',
              'transition-colors duration-120',
              'focus-within:ring-2 focus-within:ring-brand focus-within:ring-offset-2',
              isSelected
                ? 'border-brand bg-brand-soft-bg'
                : 'border-border bg-surface hover:border-border-cool',
            )}
          >
            <Radio
              name="smaily-multilingual-mode"
              value={option.value}
              checked={isSelected}
              onChange={handleChange}
              label={<span className="font-semibold text-text-primary">{option.label}</span>}
            />
            <p className="ml-7 text-sm text-text-secondary">{option.description}</p>
          </label>
        );
      })}
    </fieldset>
  );
}

const MODE_OPTIONS: ModeOption[] = [
  {
    value: 'A',
    label: 'Separate Smaily accounts',
    description: 'One Smaily subdomain per language. Each language has its own subscriber list and credentials.',
  },
  {
    value: 'B',
    label: 'One account, per-language automations',
    description: 'Single Smaily account, but a separate automation workflow per language. Most common setup.',
  },
  {
    value: 'C',
    label: 'One account, one automation with branches',
    description: 'Single Smaily workflow that branches on the contact’s language inside Smaily.',
  },
];
