# Proposed design direction — 2026-10-08

Recommendation based on the owner's two options in `color-palette.md`: use Option 2's blue/slate foundation with Option 1's off-white canvas. This is a recommendation for the visual redesign, not a claim that a colour makes a marketplace trustworthy. The owner's original palette file is preserved.

| Role            | Colour    | Use                                                                    |
| --------------- | --------- | ---------------------------------------------------------------------- |
| Page background | `#F8F9FA` | Quiet canvas                                                           |
| Surfaces        | `#FFFFFF` | Product cards, search and forms                                        |
| Main text       | `#2C3E50` | Headings, prices, body text                                            |
| Primary         | `#0066CC` | Main actions, links and selected states                                |
| Primary hover   | `#0052A3` | Hover/pressed actions                                                  |
| Supporting text | `#526477` | Locations, timestamps, hints                                           |
| Borders         | `#DCE3EA` | Subtle separation; stronger outlines for inputs/focus                  |
| Accent          | `#FFC107` | Small highlights with slate text; never an invented verification badge |
| Error           | `#B42318` | Validation and destructive actions                                     |

Calculated contrast: white on blue `#0066CC` is 5.57:1; slate on amber is 6.74:1. White on coral `#FF6B6B` is only 2.78:1 and white on sea green `#2E8B57` is 4.25:1. Both fall below WCAG AA's 4.5:1 threshold for ordinary text. Use darker button fills or dark labels if those colours are retained. [W3C contrast guidance](https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum).

## What needs to change with the palette

- Default to light presentation. Provide consistent semantic colour tokens across public pages, settings, forms and buttons instead of mixing default black controls with hard-coded emerald utilities.
- Replace the oversized empty hero with a practical marketplace header, prominent search/location controls and a clear Sell action.
- Use real product photographs for explicitly identified demo inventory. Never invent sellers, successful transactions, reviews or trust badges to fill the page.
- Give cards consistent image proportions, clear prices, restrained labels and useful location/date information. Keep decorative treatments subtle.
- Improve the empty states and remove development-status copy from the finished product.
- Show honest seller information, reporting access and understandable moderation states. Colour is only one part of credibility.
- Review home, search, listing details, seller profile and posting together on mobile and desktop. Verify keyboard focus, contrast and form states during the design pass.

Do not apply a global blue replacement alone and label it a finished redesign. This proposal keeps the functional phase work moving while defining a coherent target for the dedicated visual pass.
