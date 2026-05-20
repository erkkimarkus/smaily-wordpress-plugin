import { forwardRef, type InputHTMLAttributes, type ReactNode } from 'react';

import { cn } from '../../utils/cn';

export interface RadioProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type' | 'children'> {
  label?: ReactNode;
  description?: ReactNode;
}

/**
 * Radio button — one-of-many. Used for the MultilingualModePicker
 * (Mode A / B / C / single) and for the Step 3 "default fallback"
 * marker per language row.
 *
 * Same hidden-native-input pattern as Checkbox; just a circular dot
 * instead of a checkmark for the checked state.
 */
export const Radio = forwardRef<HTMLInputElement, RadioProps>(function Radio(
  { label, description, className, disabled, ...rest },
  ref,
) {
  return (
    <label
      className={cn(
        // items-center keeps the dot vertically centred with the label's
        // first line — items-start (the old value) drifted the dot up a
        // pixel or two on font-semibold labels like the
        // MultilingualModePicker card titles, which Erkki's staging
        // walkthrough surfaced as visibly misaligned.
        'group inline-flex items-center gap-3',
        disabled ? 'cursor-not-allowed opacity-60' : 'cursor-pointer',
        className,
      )}
    >
      <span className="relative inline-flex h-4 w-4 shrink-0">
        <input
          ref={ref}
          type="radio"
          disabled={disabled}
          className="peer absolute h-full w-full cursor-inherit appearance-none rounded-full border border-border-strong bg-surface checked:border-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-1"
          {...rest}
        />
        {/* Inner dot — visible only when :checked. */}
        <span
          className="pointer-events-none absolute left-1/2 top-1/2 h-2 w-2 -translate-x-1/2 -translate-y-1/2 rounded-full bg-brand opacity-0 peer-checked:opacity-100"
          aria-hidden
        />
      </span>

      {(label || description) && (
        // leading-[1rem] makes the label line-box exactly 16px tall —
        // identical to the dot wrapper — so items-center on the parent
        // produces a pixel-perfect alignment. Inter's natural ascent
        // pushed leading-snug (1.375) above the dot by ~1.5px on Erkki's
        // staging, which sub-PR 2.H.1's items-center alone didn't fix.
        // Description (when present) flows underneath via the block span;
        // its leading is independent so multi-line copy still reads OK.
        <span className="select-none text-sm leading-[1rem]">
          {label && <span className="block text-text-primary">{label}</span>}
          {description && (
            <span className="block leading-snug text-text-secondary">{description}</span>
          )}
        </span>
      )}
    </label>
  );
});
