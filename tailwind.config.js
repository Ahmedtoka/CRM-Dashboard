import defaultTheme from 'tailwindcss/defaultTheme';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: ['class'],
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.{vue,js,ts,jsx,tsx}',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['"IBM Plex Sans Arabic"', 'Segoe UI', 'Tahoma', ...defaultTheme.fontFamily.sans],
            },
            fontSize: {
                // Type scale (DESIGN §2.2): 14px body at 1.6 for Arabic, 13px tables and filters, 12px chips and time.
                '2xs': ['0.6875rem', { lineHeight: '1rem' }],
                xs: ['0.75rem', { lineHeight: '1.05rem' }],
                13: ['0.8125rem', { lineHeight: '1.25rem' }],
                sm: ['0.875rem', { lineHeight: '1.375rem' }],
                base: ['1rem', { lineHeight: '1.5rem' }],
                lg: ['1.125rem', { lineHeight: '1.6rem' }],
                xl: ['1.25rem', { lineHeight: '1.7rem' }],
                '2xl': ['1.5rem', { lineHeight: '1.95rem' }],
            },
            // Radius grows with the element (DESIGN §2.4): 6 chips, 8 buttons and fields, 12 panels and tables, 16 big cards.
            borderRadius: {
                sm: '6px',
                md: '8px',
                DEFAULT: '8px',
                lg: '12px',
                xl: '16px',
            },
            boxShadow: {
                e1: 'var(--e1)',
                e2: 'var(--e2)',
                e3: 'var(--e3)',
            },
            transitionTimingFunction: {
                out: 'var(--ease-out)',
            },
            colors: {
                background: 'hsl(var(--background))',
                foreground: 'hsl(var(--foreground))',
                card: {
                    DEFAULT: 'hsl(var(--card))',
                    foreground: 'hsl(var(--card-foreground))',
                },
                popover: {
                    DEFAULT: 'hsl(var(--popover))',
                    foreground: 'hsl(var(--popover-foreground))',
                },
                primary: {
                    DEFAULT: 'hsl(var(--primary))',
                    foreground: 'hsl(var(--primary-foreground))',
                },
                secondary: {
                    DEFAULT: 'hsl(var(--secondary))',
                    foreground: 'hsl(var(--secondary-foreground))',
                },
                muted: {
                    DEFAULT: 'hsl(var(--muted))',
                    foreground: 'hsl(var(--muted-foreground))',
                },
                accent: {
                    DEFAULT: 'hsl(var(--accent))',
                    foreground: 'hsl(var(--accent-foreground))',
                },
                destructive: {
                    DEFAULT: 'hsl(var(--destructive))',
                    foreground: 'hsl(var(--destructive-foreground))',
                },
                success: {
                    DEFAULT: 'hsl(var(--success))',
                    foreground: 'hsl(var(--success-foreground))',
                },
                info: {
                    soft: 'hsl(var(--info-soft) / <alpha-value>)',
                    DEFAULT: 'hsl(var(--info))',
                    foreground: 'hsl(var(--info-foreground))',
                },
                overdue: {
                    DEFAULT: 'hsl(var(--overdue))',
                    foreground: 'hsl(var(--overdue-foreground))',
                },
                note: {
                    DEFAULT: 'hsl(var(--note) / <alpha-value>)',
                    soft: 'hsl(var(--note-soft) / <alpha-value>)',
                    ink: 'hsl(var(--note-ink) / <alpha-value>)',
                },
                ok: {
                    DEFAULT: 'hsl(var(--ok) / <alpha-value>)',
                    soft: 'hsl(var(--ok-soft) / <alpha-value>)',
                },
                warn: {
                    DEFAULT: 'hsl(var(--warn) / <alpha-value>)',
                    soft: 'hsl(var(--warn-soft) / <alpha-value>)',
                    solid: 'hsl(var(--warn-solid) / <alpha-value>)',
                },
                danger: {
                    DEFAULT: 'hsl(var(--danger) / <alpha-value>)',
                    soft: 'hsl(var(--danger-soft) / <alpha-value>)',
                    solid: 'hsl(var(--danger-solid) / <alpha-value>)',
                },
                brand: {
                    DEFAULT: 'hsl(var(--brand) / <alpha-value>)',
                    hover: 'hsl(var(--brand-hover) / <alpha-value>)',
                    soft: 'hsl(var(--brand-soft) / <alpha-value>)',
                    ink: 'hsl(var(--brand-ink) / <alpha-value>)',
                    on: 'hsl(var(--on-brand) / <alpha-value>)',
                },
                rail: {
                    DEFAULT: 'hsl(var(--rail) / <alpha-value>)',
                    2: 'hsl(var(--rail-2) / <alpha-value>)',
                    active: 'hsl(var(--rail-active) / <alpha-value>)',
                    fg: 'hsl(var(--rail-fg) / <alpha-value>)',
                    'fg-2': 'hsl(var(--rail-fg-2) / <alpha-value>)',
                    line: 'hsl(var(--rail-line) / <alpha-value>)',
                },
                'surface-2': 'hsl(var(--surface-2) / <alpha-value>)',
                sunken: 'hsl(var(--sunken) / <alpha-value>)',
                line: {
                    DEFAULT: 'hsl(var(--line) / <alpha-value>)',
                    strong: 'hsl(var(--line-strong) / <alpha-value>)',
                },
                'fg-2': 'hsl(var(--fg-2) / <alpha-value>)',
                'fg-3': 'hsl(var(--fg-3) / <alpha-value>)',
                warning: {
                    DEFAULT: 'hsl(var(--warning))',
                    foreground: 'hsl(var(--warning-foreground))',
                },
                elevated: 'hsl(var(--elevated))',
                'surface-accent': 'hsl(var(--surface-accent))',
                'primary-hover': 'hsl(var(--primary-hover))',
                border: 'hsl(var(--border))',
                input: 'hsl(var(--input))',
                ring: 'hsl(var(--ring))',
                chart: {
                    1: 'hsl(var(--chart-1))',
                    2: 'hsl(var(--chart-2))',
                    3: 'hsl(var(--chart-3))',
                    4: 'hsl(var(--chart-4))',
                    5: 'hsl(var(--chart-5))',
                    soft: 'hsl(var(--chart-soft))',
                },
                sidebar: {
                    DEFAULT: 'hsl(var(--sidebar-background))',
                    foreground: 'hsl(var(--sidebar-foreground))',
                    primary: 'hsl(var(--sidebar-primary))',
                    'primary-foreground': 'hsl(var(--sidebar-primary-foreground))',
                    accent: 'hsl(var(--sidebar-accent))',
                    'accent-foreground': 'hsl(var(--sidebar-accent-foreground))',
                    border: 'hsl(var(--sidebar-border))',
                    ring: 'hsl(var(--sidebar-ring))',
                },
            },
        },
    },
    plugins: [require('tailwindcss-animate')],
};
