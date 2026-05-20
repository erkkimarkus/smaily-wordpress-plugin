import { forwardRef, type InputHTMLAttributes, type ReactNode } from 'react';

import { cn } from '../../utils/cn';

export interface CheckboxProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type' | 'children'> {
  /** Visible label rendered to the right of the box. Omit for icon-only contexts. */
  label?: ReactNode;
  /** Secondary description rendered below the label in smaller text. */
  description?: ReactNode;
}

/**
 * Checkbox — single-state boolean toggle. Used heavily in Step 2 for the
 * sync-field grid and Step 4 for the rec-engine feature toggles.
 *
 * Layout pattern: <label> wraps an <input> + visible label text so the
 * full row is clickable. The native checkbox is hidden visually but kept
 * for accessibility (keyboard tabs, screen readers, form submission); a
 * styled square renders on top via peer-checked Tailwind utilities.
 */
export const Checkbox = forwardRef<HTMLInputElement, CheckboxProps>(function Checkbox(
  { label, description, className, disabled, ...rest },
  ref,
) {
  return (
    <label
      className={cn(
        // items-center keeps the box vertically centred against single-line
        // labels (the dominant case — Step 2 field grid, Step 4 toggles).
        // Same fix as Radio.tsx in sub-PR 2.H.1; checkbox missed the
        // sweep, Erkki's screenshot caught it in the next walkthrough.
        // Multi-line labels (description) still flow underneath because
        // the label is rendered with `block` spans.
        'group inline-flex items-center gap-3',
        disabled ? 'cursor-not-allowed opacity-60' : 'cursor-pointer',
        className,
      )}
    >
      <span className="relative inline-flex h-4 w-4 shrink-0">
        <input
          ref={ref}
          type="checkbox"
          disabled={disabled}
          className="peer absolute h-full w-full cursor-inherit appearance-none rounded border border-border-strong bg-surface checked:border-brand checked:bg-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-1"
          {...rest}
        />
        {/* Checkmark icon — visible only when the input is :checked. */}
        <svg
          className="pointer-events-none absolute inset-0 h-full w-full text-text-white opacity-0 peer-checked:opacity-100"
          viewBox="0 0 16 16"
          fill="currentColor"
          aria-hidden
        >
          <path d="M13.2 4.4 6.6 11l-3.8-3.8 1.2-1.2L6.6 8.6l5.4-5.4z" />
        </svg>
      </span>

      {(label || description) && (
        // Same explicit-box alignment fix as Radio — see comment there.
        description ? (
          <span className="flex flex-col gap-0.5 select-none">
            {label && (
              <span className="flex h-4 items-center text-sm text-text-primary">{label}</span>
            )}
            <span className="text-sm leading-snug text-text-secondary">{description}</span>
          </span>
        ) : (
          <span className="flex h-4 items-center select-none text-sm text-text-primary">
            {label}
          </span>
        )
      )}
    </label>
  );
});
