/**
 * Ads action tones (DESIGN §3 Button): a stop is «danger» on a light tint, a scale-up is «ok» on a light tint, a
 * snooze is ghost. Applied on top of `<Button variant="ghost">` so the shared button stays untouched.
 */
export const ADS_TONE = {
    danger: 'bg-danger-soft text-danger hover:bg-danger-soft hover:brightness-95 dark:hover:brightness-125',
    ok: 'bg-ok-soft text-ok hover:bg-ok-soft hover:brightness-95 dark:hover:brightness-125',
    quiet: 'text-fg-2 hover:bg-sunken hover:text-foreground',
    brand: 'bg-brand-soft text-brand-ink hover:bg-brand-soft hover:brightness-95 dark:hover:brightness-125',
} as const;

/** A native select dressed as a FilterBar field (DESIGN §3 FilterBar `fsel`). */
export const ADS_FIELD =
    'h-9 rounded-md border border-line-strong bg-card px-2.5 text-[13px] font-medium text-foreground hover:border-fg-3 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';

/** The one-line meta under a title (account · campaign · time). */
export const ADS_META = 'truncate text-[12.5px] text-fg-2';
