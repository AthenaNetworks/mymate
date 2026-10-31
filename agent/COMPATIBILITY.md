# Agent compatibility (mandatory)

Remote agents run on customer networks and are upgraded on their own schedule, sometimes months
after the server. A server upgrade must never strand an agent, and a newer agent must never break
against an older server. **Backward compatibility of the server <-> agent protocol is kept at all
times, in both directions.**

## The protocol is append-only
- **Add, never change.** New JSON fields and new message types only. Never rename, remove, retype
  or repurpose an existing field or message type (`agent/internal/proto/proto.go`, and the
  arrays built in `DispatchAgentJobs` / read in `IngestAgentResults`).
- **New fields are optional on both sides.** Go: `omitempty` on what the agent sends, and a safe
  zero value when the server didn't send something. PHP: read agent payloads with `?? default`,
  never assume a key exists.
- **Unknown is ignored, not fatal.** The agent ignores message types and fields it doesn't know
  (`handle`'s default case); the hub ignores unknown message types. Keep it that way.
- **Meaning can't change under the same name.** If a field needs different semantics, add a new
  field and keep filling the old one for older peers.

## Gate new behaviour on what the peer supports
- The agent says what it is in `hello` (`version`, `platform`, `capabilities`). When the server
  needs an agent to do something new, check the agent's reported capability; an agent that doesn't
  report it gets the old behaviour (or the feature is shown as unavailable for that agent, with a
  reason). Don't compare version strings to infer features - advertise a capability instead.
- An agent can't know the server's version, so a newer agent must cope with an older server simply
  never sending the new fields or messages.

## Never in lockstep
- No server change may require agents to be upgraded at the same time, and no agent change may
  require a server upgrade first. Removing support for an old agent version is a deliberate,
  announced decision in `CHANGELOG.md`, never a side effect.
- Remote agent upgrades (`upgrade` message) are an operator action with automatic rollback; they
  are never forced, and an agent that can't upgrade itself keeps working.

## Tests
- When you add to the protocol, test both directions: the server with a payload missing the new
  fields (an older agent), and the agent with a job missing them (an older server).
- `agent/test/integration.sh` runs the real agent against the real hub; keep it passing.
