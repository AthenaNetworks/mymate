# Single sign-on (Microsoft Entra ID and OpenID Connect)

People can sign in to My Mate with their company account instead of a My Mate password. The login
page shows a **Login with SSO** button next to the normal sign-in. My Mate speaks standard OpenID
Connect, with a preset for Microsoft Entra ID (formerly Azure AD); other providers such as Okta,
Google Workspace, Authentik or Keycloak work with the generic option.

- **Who can sign in, and as what, is decided at the provider.** The ID token must carry one of two
  roles: the administrator role (default `MyMate.Admin`) or the operator role (default
  `MyMate.Operator`, read-only). With neither, the sign-in is refused.
- **Accounts are created on first sign-in**, with no password. If someone already has a My Mate
  account with the same email, the first SSO sign-in links to it instead (it keeps its history, API
  keys and password). After that, the account is matched by its identity at the provider, so an
  email change there doesn't break it.
- **Roles are re-checked at every sign-in.** Gaining or losing the administrator role takes effect
  on the next SSO sign-in. Losing both roles disables the account: its sessions end and its API
  keys stop working. Giving the role back re-enables it on the next sign-in.
- **Password sign-in keeps working** for accounts that have a password. Accounts created by SSO
  have none, so they can only use SSO. Keep at least one local administrator with a password as a
  way in if the provider or its secret ever breaks.
- **Restricted map access stays in My Mate.** Mark an operator restricted, or add them to user
  groups, under Settings > Operators as usual.

## Requirements

- My Mate reachable over **HTTPS on a fixed address** (Entra only allows plain http for
  `localhost`). See [deploy/ssl/README.md](deploy/ssl/README.md).
- The My Mate server can make outbound HTTPS requests to the provider (for Entra,
  `login.microsoftonline.com`).

## Set up Microsoft Entra ID

You need someone who can create app registrations in your tenant.

1. In My Mate, open **Settings > Security > Single sign-on** *from the address everyone uses* and
   copy the **Redirect URI** (it ends in `/auth/sso/callback`).
2. In the Entra admin centre: **App registrations > New registration**.
   - Name: `My Mate`.
   - Supported account types: **Accounts in this organizational directory only (single tenant)**.
   - Redirect URI: platform **Web**, paste the URI from step 1.
3. On the new app's **Overview**, note the **Application (client) ID** and the
   **Directory (tenant) ID**.
4. **Certificates & secrets > New client secret.** Copy the secret's **Value** (not its ID) right
   away - it's only shown once. Note its expiry date: secrets last at most 24 months, and sign-in
   stops working when it expires.
5. **App roles > Create app role**, twice, with *Allowed member types: Users/Groups*:
   - Display name `My Mate administrator`, value `MyMate.Admin`.
   - Display name `My Mate operator`, value `MyMate.Operator`.
6. Go to **Enterprise applications > My Mate**:
   - **Properties:** set **Assignment required?** to **Yes**, so only assigned people can sign in.
   - **Users and groups > Add user/group:** assign people or groups to one of the two roles.
7. Back in My Mate (Settings > Security > Single sign-on):
   - Provider: **Microsoft Entra ID**.
   - Directory (tenant) ID, Application (client) ID and the client secret from steps 3-4.
   - Leave the role names as they are unless you used different values in step 5.
   - Turn on **Show "Login with SSO" on the sign-in page**, **Save**, then **Test**.

Conditional Access and MFA policies you already have in Entra apply to My Mate automatically.

## Other OpenID Connect providers

Choose **Other OpenID Connect provider** and give the **Issuer URL** (My Mate reads
`<issuer>/.well-known/openid-configuration`), the client ID, and the client secret if the provider
issued one. Register the Redirect URI with the provider and request the `openid profile email`
scopes. The provider must put the role values in a claim in the ID token; set **Role claim** to that
claim's name (default `roles`). A provider that marks the email as unverified can't create or link
accounts.

## Passkeys and MFA

If **Require passkeys for all operators** is on, an SSO sign-in counts as the second factor when the
ID token says MFA was used (`amr` contains `mfa`). If the token doesn't say so, the passkey rule
applies as it does for password sign-ins. Microsoft Entra doesn't always include `amr` in ID tokens,
so with Entra plus required passkeys, expect people to be asked for a passkey as well; if your
Conditional Access policy already enforces MFA, you may prefer to leave passkeys optional.

## Disabling someone

- **At the provider:** remove their role assignment (or disable them). Their next SSO sign-in is
  refused and the account is disabled. A session they already have lasts until it expires
  (`SESSION_LIFETIME`, two hours by default), so for an immediate cut-off also disable them in
  My Mate.
- **In My Mate:** Settings > Operators > edit > **Disabled**. Their sessions end at once and their
  API keys are refused. An administrator's disable is never lifted by an SSO sign-in.

## Troubleshooting

A failed sign-in returns to the login page with a short message; the detail (for example the
provider's `AADSTS...` error code) is in the engine log as `auth: sso sign-in failed`.

| Message | Usually means |
|---|---|
| Not assigned a My Mate role | No `MyMate.Admin` / `MyMate.Operator` role assignment, or the role values don't match the settings |
| Sign-in wasn't completed at your identity provider | They cancelled, aren't assigned to the app, or the Redirect URI doesn't match exactly |
| Couldn't reach the identity provider | The server can't reach the provider over HTTPS, or the tenant / issuer is wrong (use **Test**) |
| Single sign-on failed (generic) | Often an expired or wrong client secret - check the engine log |
| Email already linked to a different sign-in | Another account is already linked to that email; an admin needs to sort out the duplicate |
