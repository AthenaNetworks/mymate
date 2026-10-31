# My Mate

Web-based replacement for MikroTik's The Dude: Laravel 13 / PHP 8.4 backend (Postgres, Redis,
Horizon, Reverb), React + TypeScript frontend, and a Go remote agent in `agent/`. Where a local
`context/` folder exists (it's not in git) it holds the detailed conventions: start with
`context/conventions/backend.md`, `frontend.md` and `tech-standards.md`.

## Rules

- **Agents stay backward compatible, always.** Agents in the field are upgraded on their own
  schedule, so every change to the server <-> agent protocol must keep working with older agents
  against a newer server AND a newer agent against an older server. Never make an agent upgrade
  a requirement of a server upgrade, or the other way round. The specifics are in
  [agent/COMPATIBILITY.md](agent/COMPATIBILITY.md); read it before touching `agent/internal/proto`, `app/Actions/Agent/*` or the agent hub.
- Every backend change ships with tests (`php artisan test`, against Postgres `mymate_test`).
- Frontend changes must pass `npx tsc --noEmit`; build with `npm run build` (never bare `vite build`).
- The long-running daemons (Horizon, `mymate:loop`, Reverb, the agent hub) keep old code loaded
  until they are restarted.
- Keep `CHANGELOG.md`'s `Unreleased` section up to date as you go.
