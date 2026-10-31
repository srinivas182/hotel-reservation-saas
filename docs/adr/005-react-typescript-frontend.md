# ADR-005: React + TypeScript as the standard frontend

- **Status:** Accepted
- **Date:** 2026-10-31

## Context

Earlier MCS products use Vue 3 with Inertia. The director has set Laravel + React as the default for all new projects.

## Decision

- **React 19 + Inertia 3 + TypeScript (strict)**, starting from Laravel's official React starter kit.
- **Tailwind 4 + shadcn/ui** for components. shadcn/ui components live in our source tree, so they can be rebranded per hotel or partner.
- **Wayfinder** for type-safe routes and form actions generated from Laravel routes.
- State: Inertia page props and `useForm` by default; a small global store (Zustand) only where state is shared across the app, introduced when first needed.
- Translations: react-i18next reading the same language files as Laravel (introduced in S1).
- Quality: Vite+ (`vp check`) for linting and formatting, `tsc --noEmit`, and Vitest with React Testing Library.

## Alternatives considered

| Option             | Why not                                                                                                                                    |
| ------------------ | ------------------------------------------------------------------------------------------------------------------------------------------ |
| Vue 3 + Inertia    | Team familiarity, but the company standard for new projects is now React.                                                                  |
| Separate SPA + API | Duplicates routing and auth; Inertia gives SPA behaviour with server-side routing. A public API is still added where integrations need it. |

## Consequences

- Existing Vue projects are unaffected; new projects use this stack.
- Generated Wayfinder files are not committed; CI and builds generate them.
