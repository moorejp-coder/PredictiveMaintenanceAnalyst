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
- Allowed backgrounds: #101725, #162033, white (ink version).
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
| bg | #101725 | Canvas |
| surface | #162033 | Panels |
| surface-raised | #1E2A41 | Readout panel, selected toggles, notices, bar tracks |
| border | #33435F | Dividers, outlines |
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
- **Shape:** controls 4 px, panels 6 px, dialogs 8 px, readout cells and table cells 0. No shadows except on dialogs and the cookie notice.
- **Panels:** `.card`, surface background, 1 px border. The dashboard readout is one panel in two halves: the score on the raised tone, the reasons beside it.
- **Segmented control:** `.seg` joins buttons that act as one choice. The selected button carries a 3 px accent bar.
- **Form fields:** 1 px `--prism-control` border (3:1 or better against the surface), accent border on focus.
- **Tables:** horizontal rules only. The header row sits over a heavier rule.
- **Long-form pages:** `body.doc-page` sets one 68ch measure, 1.65 line height and sections set apart by space.
- **Notice:** `.notice` for instructions and technical failures. Not a risk alert.
- **Risk result:** large Plex Mono score, text classification, reason, next action, disclaimer. Color appears only after a real result.
- **Navigation:** `partials/app-header.blade.php`. Active page uses `aria-current="page"` and a blue underline.
- **Failure modes:** one row per mode returned by the scorer. The bar color shows stress level, not the category.

## Writing
Short, direct, factual, action-oriented. Lead with the finding. Explain causes in plain English. Separate predictions from confirmed conditions. Give a next step only when the scorer supplies one. Leave the decision to the technician. No marketing language in the dashboard.

## Light theme

PRISM follows the visitor's system setting (`prefers-color-scheme`). A switch in the header overrides it and saves the choice in the browser. The logo mark uses `--prism-accent` and the wordmark uses `--prism-text`, so both follow the active theme. The brand rule allowing white backgrounds with the ink logo (above) covers the light surface.

| Token | Dark | Light |
| --- | --- | --- |
| --prism-bg | #101725 | #F3F5F9 |
| --prism-surface | #162033 | #FFFFFF |
| --prism-surface-raised | #1E2A41 | #E7ECF4 |
| --prism-border | #33435F | #B3BFD4 |
| --prism-control | #6B7FA6 | #6B7C9C |
| --prism-accent | #8AB4F8 | #1A4FBF |
| --prism-accent-hover | #A9C7FA | #153F99 |
| --prism-accent-pressed | #7AA5EE | #10337D |
| --prism-accent-ink | #0B1220 | #FFFFFF |
| --prism-text | #E8EEFB | #0F1A30 |
| --prism-muted | #A3B3D1 | #46557A |
| --prism-disabled-text | #8494B5 | #6B7893 |
| --prism-risk-low | #34D399 | #047857 |
| --prism-risk-elevated | #FBBF24 | #955300 |
| --prism-risk-high | #F87171 | #B42318 |

Measured contrast for the light theme (WCAG 2.2 relative luminance formula, calculated by script on October 20, 2026. The author then checked the light theme by eye in a browser on the same day: High score view, AI pop-up, override form, FAQ and AI use pages). Values are on bg, surface and surface-raised in that order.
- Text: 16.3, 17.3, 15.0
- Muted text: 7.0, 7.4, 6.4
- Accent (links, focus outline): 6.8, 7.2, 6.2
- Button text (white on accent): 7.2 (hover 9.5, pressed 11.7)
- Low: 5.2, 5.5, 4.8
- Elevated: 5.6, 6.0, 5.2
- High: 6.2, 6.6, 5.7
- Border #AEBAD3: 1.83 on bg. As in the dark theme, the border is decorative. Controls carry a text label and a focus outline.

## Update, October 22, 2026
Neutrals shifted to a lighter slate in both themes. Text, muted, accent and risk colors are unchanged. Contrast, calculated by script on the new neutrals (bg, surface, raised): dark text 15.4, 14.0, 12.3; dark muted 8.5, 7.7, 6.8; dark accent 8.5, 7.7, 6.8; light text 15.9, 17.3, 14.6; light muted 6.8, 7.4, 6.2; light accent 6.6, 7.2, 6.1. Risk colors stay at 4.6 or higher in both themes. Control borders reach 3.5 or higher on the surface. The score ring and each stress bar mark the 40 and 70 band edges. This update was checked in Chromium screenshots of every page in both themes, not with a screen reader.
