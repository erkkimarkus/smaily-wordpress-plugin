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
        // Sub-PR 2.H.12 — `flex` (block-level) instead of `inline-flex`
        // so vertical containers (space-y-* / flex-col) actually stack
        // these block-by-block. Erkki's "two toggles on the same row"
        // bug came from <label inline-flex> children sitting in a
        // <div space-y-4>: inline-tasandi flow rule packed them
        // side-by-side until one ran off the right edge.
        'group flex items-center gap-3',
        disabled ? 'cursor-not-allowed opacity-60' : 'cursor-pointer',
        className,
      )}
    >
      <span className="relative inline-flex h-5 w-9 shrink-0">
        <input
          ref={ref}
          type="checkbox"
          role="switch"
          disabled={disabled}
          // Sub-PR 2.H.10 — full WP-core CSS override.
          //
          // Erkki's DevTools Computed pane (screenshot 2026-05-20) showed
          // wp-admin/css/forms.css ships:
          //   input[type=checkbox], input[type=radio] {
          //     width: 1rem; height: 1rem; min-width: 1rem;
          //     border: 1px solid #8c8f94; border-radius: 4px;
          //     background: #fff;
          //     box-shadow: inset 0 1px 2px rgba(0,0,0,.1);
          //     margin: -.25rem .25rem 0 0;
          //     padding: 0 !important;
          //   }
          // Specificity 0,0,1,1 — beats utility classes (0,0,1,0). The
          // native input rendered as a 16x16 white-with-grey-border
          // square INSIDE the 20x36 toggle pill, with a -4px top margin
          // pulling it skew. Promoting every overriding class with `!`
          // makes the native input fill the pill cleanly.
          className="peer absolute !m-0 !p-0 !h-full !w-full !min-w-0 !shadow-none !border-0 !rounded-full !bg-border-strong cursor-inherit appearance-none transition-colors duration-120 checked:!bg-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-1"
          {...rest}
        />
        {/* Thumb. Translate on :checked to slide right. */}
        <span
          className="pointer-events-none absolute left-0.5 top-0.5 h-4 w-4 rounded-full bg-text-white transition-transform duration-120 peer-checked:translate-x-4"
          aria-hidden
        />
      </span>

      {(label || description) && (
        description ? (
          <span className="flex flex-col gap-0.5 select-none">
            {label && (
              <span className="flex h-5 items-center text-sm text-text-primary">{label}</span>
            )}
            <span className="text-sm leading-snug text-text-secondary">{description}</span>
          </span>
        ) : (
          <span className="flex h-5 items-center select-none text-sm text-text-primary">
            {label}
          </span>
        )
      )}
    </label>
  );
});
