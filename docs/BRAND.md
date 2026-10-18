# PRISM Brand Guide

## Overview
- **Product:** PRISM, Predictive Real-time Infrastructure Sensor Monitoring. A dashboard that supports CNC milling machine maintenance.
- **Audience:** maintenance technicians, CNC operators, small machine shop owners.
- **Personality:** clear, dependable, precise, calm under pressure, industrial, modern, professional.
- **Concept:** one incoming signal separates into four defined paths (Focused Split). The paths do not map to the sensor inputs.
- **Tagline:** Machine Risk, Made Clear.
- PRISM supports technician decisions. It does not replace inspection.

## Logo system
All assets are in `public/brand/`. Blade components: `<x-prism-logo variant="compact|primary|stacked|symbol" tone="default|ink|white" />` and `<x-prism-mark />`.

| Asset | File | Use |
|---|---|---|
| Primary horizontal | `prism-logo-primary.svg`, `variant="primary"` | About pages, documents |
| Compact horizontal | `prism-logo-compact.svg`, `variant="compact"` | App header |
| Stacked | `prism-logo-stacked.svg`, `variant="stacked"` | 404, login, covers |
| Symbol only | `prism-mark.svg`, `variant="symbol"` | Compact navigation |
| Monochrome | `prism-logo-mono-ink.svg`, `prism-logo-mono-white.svg`, `prism-mark-mono-*.svg` | Print, documents |
| App icon | `app-icon.svg` (reference geometry) | Tiles, docs |
| Favicon | `favicon.svg`, `public/favicon.ico` (16, 32, 48), `icon-16/32/48/96/192/512.png`, `apple-touch-icon.png` | Browser, install |

The mark geometry (viewBox 0 0 260 100) is canonical. See `resources/views/components/prism-mark.blade.php`. Favicons at 48 px and below use thicker strokes for recognition.

Standalone SVG wordmarks use live text set in IBM Plex Sans with a system fallback. Convert text to outlines before sending them to a printer.

### Rules
- Default symbol color #8AB4F8. Wordmark #E8EEFB on dark backgrounds.
- Clear space: at least the height of one dot row spacing (about 20% of mark height) on every side.
- Minimum size: mark 24 px wide in UI. Use the favicon artwork below 24 px.
- Allowed backgrounds: #0B1220, #111A2E, white (ink version).
- All four paths use one color. Never use risk colors in a logo.

### Incorrect usage
- Stretching, rotating or skewing the mark.
- Coloring paths by severity.
- Adding gradients, glow or shadow.
- Replacing the wordmark font.
- Using the mark below legible size.
- Placing it on low-contrast or noisy backgrounds.

## Color
Tokens live in `public/css/prism.css` as `--prism-*`.

| Token | Hex | Use |
|---|---|---|
| bg | #0B1220 | Canvas |
| surface | #111A2E | Cards, panels |
| surface-raised | #172340 | Pressed toggles, notices, bars |
| border | #26355A | Dividers, outlines |
| accent | #8AB4F8 | Primary actions, active states, charts for non-risk data |
| text | #E8EEFB | Main text |
| muted | #A3B3D1 | Supporting text |
| risk-low | #34D399 | Low risk only |
| risk-elevated | #FBBF24 | Elevated risk only |
| risk-high | #F87171 | High risk only |

Risk colors are reserved for machine-risk status and its data. Do not use them for logos, navigation, buttons, loading indicators, status dots or decoration. Every risk state carries a text label. System errors use the neutral notice style.

Measured contrast (WCAG relative luminance): text on bg 16.1, muted on surface 8.2, accent on surface 8.2, button ink on accent 8.9 (hover 10.9, pressed 7.5), low 9.0, elevated 10.4, high 6.3 on surface. The #26355A border is 1.55 against bg. It is used for decorative separation only. Interactive controls carry a text label and a focus outline of #8AB4F8.

## Typography
IBM Plex Sans (400 body, 500 labels and navigation, 600 headings and wordmark, 700 emphasis) and IBM Plex Mono (400 secondary numbers, 500 readings, 600 risk score). Fonts are self-hosted in `public/fonts/` (SIL OFL, license in `OFL-IBM-Plex.txt`), so PRISM makes no third-party requests.

Scale: page heading 28 to 32 px, section 20 to 24 px, card heading 16 px, body 16 px, supporting text 14 px, risk score 56 px. Use `font-variant-numeric: tabular-nums` for values.

## Components
- **Buttons:** `.btn` secondary, `.btn--primary`, `.btn--tertiary`. States: hover, focus-visible, active, disabled, `aria-busy="true"` loading, `aria-pressed="true"` selected. Minimum 44 px tall.
- **Cards:** `.card`, surface background, 1 px border, 12 px radius, no shadow.
- **Notice:** `.notice` for instructions and technical failures. Not a risk alert.
- **Risk result:** large Plex Mono score, text classification, reason, next action, disclaimer. Color appears only after a real result.
- **Navigation:** `partials/app-header.blade.php`. Active page uses `aria-current="page"` and a blue underline.
- **Failure modes:** one row per mode returned by the scorer. The bar color shows stress level, not the category.

## Writing
Short, direct, factual, action-oriented. Lead with the finding. Explain causes in plain English. Separate predictions from confirmed conditions. Give a next step only when the scorer supplies one. Leave the decision to the technician. No marketing language in the dashboard.
