import { forwardRef, type InputHTMLAttributes, type ReactNode } from 'react';

import { cn } from '../../utils/cn';

export interface ToggleProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type' | 'children'> {
  label?: ReactNode;
  description?: ReactNode;
  /** When true, the toggle and label go below 50 % opacity. */
  disabled?: boolean;
}

/**
 * Toggle switch — semantically a checkbox, visually a slider. Used for
 * the "enable feature" rows in Step 4 (Recommendations) and the
 * subscription-checkbox row in Step 2.
 *
 * `role="switch"` is implicit on a checkbox with the visual treatment,
 * but we add it explicitly so VoiceOver/NVDA announce "switch, on/off"
 * instead of "checkbox, checked".
 */
export const Toggle = forwardRef<HTMLInputElement, ToggleProps>(function Toggle(
  { label, description, className, disabled, ...rest },
  ref,
) {
  return (
    <label
      className={cn(
        'group inline-flex items-start gap-3',
        disabled ? 'cursor-not-allowed opacity-60' : 'cursor-pointer',
        className,
      )}
    >
      <span className="relative mt-0.5 inline-flex h-5 w-9 shrink-0">
        <input
          ref={ref}
          type="checkbox"
          role="switch"
          disabled={disabled}
          className="peer absolute h-full w-full cursor-inherit appearance-none rounded-full bg-border-strong transition-colors duration-120 checked:bg-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-1"
          {...rest}
        />
        {/* Thumb. Translate on :checked to slide right. */}
        <span
          className="pointer-events-none absolute left-0.5 top-0.5 h-4 w-4 rounded-full bg-text-white transition-transform duration-120 peer-checked:translate-x-4"
          aria-hidden
        />
      </span>

      {(label || description) && (
        <span className="select-none text-sm leading-snug">
          {label && <span className="block text-text-primary">{label}</span>}
          {description && <span className="block text-text-secondary">{description}</span>}
        </span>
      )}
    </label>
  );
});
