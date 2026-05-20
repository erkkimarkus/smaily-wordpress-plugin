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
          // Sub-PR 2.H.6 — !m-0 / !p-0 with !important override.
          //
          // wp-admin/css/forms.css ships:
          //   input[type=checkbox], input[type=radio] {
          //     margin: -0.25rem 0.25rem 0 0;
          //   }
          // Specificity 0,0,1,1 — beats Tailwind's `.m-0` (0,0,1,0)
          // unless we promote with !important. Erkki's DevTools
          // confirmed the selector by name; 2.H.5's plain m-0 was the
          // right axis but the wrong weight.
          className="peer absolute !m-0 h-full w-full cursor-inherit appearance-none rounded-full border border-border-strong bg-surface !p-0 checked:border-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-1"
          {...rest}
        />
        {/* Inner dot — visible only when :checked. */}
        <span
          className="pointer-events-none absolute left-1/2 top-1/2 h-2 w-2 -translate-x-1/2 -translate-y-1/2 rounded-full bg-brand opacity-0 peer-checked:opacity-100"
          aria-hidden
        />
      </span>

      {(label || description) && (
        // sub-PR 2.H.4 alignment fix.
        //
        // The previous attempts (items-center alone in 2.H.1, then
        // items-center + leading-[1rem] in 2.H.3) still drifted because
        // they relied on the text line-box height matching the dot
        // wrapper. Tailwind's arbitrary leading value worked locally
        // but Inter's vertical metrics inside the line-box left the
        // glyphs ~1px above the box centre on Erkki's staging.
        //
        // Concrete fix: give the label row an EXPLICIT 16px height
        // (h-4 = same as the dot wrapper) and centre the text inside
        // it via flex items-center. Pixel-perfect alignment now comes
        // from two identical 16px boxes, not from line-box trickery.
        // The description (when present) flows underneath at its own
        // natural leading.
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
