# My Mate agent on Windows

The agent is a single `mymate-agent.exe` - no runtime to install. It dials out to the central
app over an encrypted WebSocket and polls devices locally, so nothing inbound is exposed.

## Install as a service (recommended)

1. Unzip somewhere permanent, e.g. `C:\Program Files\mymate-agent\`.
2. Open **PowerShell** or **Command Prompt as Administrator** in that folder.
3. Register + start the service, passing your server URL and agent token:

   ```
   .\mymate-agent.exe install --url https://app.example.com --token YOUR_AGENT_TOKEN
   ```

   Get the token from the app: **Settings -> Agents -> Add agent**, or the CLI
   `php artisan mymate:agent:create`.

That's it - it installs a Windows service called **My Mate Agent** (set to start automatically and
restart on failure) and starts it. The URL/token are written to
`C:\ProgramData\mymate-agent\agent.env`.

## Manage it

```
.\mymate-agent.exe status        show running / stopped
.\mymate-agent.exe stop
.\mymate-agent.exe start
.\mymate-agent.exe uninstall     stop + remove the service (leaves the config file)
```

You can also use the Services control panel (`services.msc`) - look for **My Mate Agent**.

## Run in the foreground (testing)

```
.\mymate-agent.exe run --url ... --token ...
```

or set the environment first (`MYMATE_URL`, `MYMATE_AGENT_TOKEN`, optional `MYMATE_AGENT_NAME`,
`MYMATE_AGENT_LOG=debug`) and just run `.\mymate-agent.exe`.

## Notes

- Optional flags on `install`: `--name` (label shown in the app; defaults to the hostname).
- To change the URL/token later: edit `C:\ProgramData\mymate-agent\agent.env` and restart, or
  `uninstall` then `install` again.
- Logs go to stderr in the foreground; as a service, use the Windows Event Log / the app's agent
  page to confirm it connected.
