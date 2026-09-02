# Connecting Gmail (send-only) — one-time Google Cloud setup

Outreach sends through Gmail using Google's **send-only** permission
(`https://www.googleapis.com/auth/gmail.send`). The app can send as the connected
mailbox and learn its address; it holds no permission that could read, list,
search or delete mail. You will see exactly that on Google's consent screen.

You need this once per Google Cloud project, not once per mailbox. Afterwards each
Gmail mailbox is connected with two clicks from **Admin → Email accounts → Connect Gmail**.

Time: about 15 minutes.

## 1. Create a Google Cloud project

1. Open <https://console.cloud.google.com/> signed in with the Google account that
   owns the mailboxes (any account works, but keep it one you control long-term).
2. Top bar → project picker → **New project** → name it `Outreach` → **Create**.
3. Make sure the new project is selected in the picker before continuing.

## 2. Enable the Gmail API

1. Left menu → **APIs & Services → Library**.
2. Search **Gmail API** → open it → **Enable**.

## 3. Configure the OAuth consent screen

1. **APIs & Services → OAuth consent screen** (Google may call this "Google Auth Platform → Branding").
2. User type: **External** → **Create**.
3. App information:
   - App name: `Outreach`
   - User support email: your email
   - Developer contact email: your email
   - Leave logo/domain fields empty (adding a logo triggers a review).
4. **Save and continue** through the remaining steps. On the **Scopes** step you can
   skip adding scopes here — the app requests exactly `openid`, `email` and
   `gmail.send` at connect time.
5. **Publishing status**: on the consent-screen overview click **Publish app** and
   confirm. This is important:
   - In *Testing* status Google expires refresh tokens after **7 days**, which would
     force you to reconnect every week.
   - In *Production* status (unverified) the app keeps working indefinitely. Google
     shows a one-time **"Google hasn't verified this app"** warning when you connect a
     mailbox — click **Advanced → Go to Outreach (unsafe)**. That wording is Google's
     standard notice for private apps that have not gone through its paid
     verification; `gmail.send` is classed *sensitive* (not *restricted*), so no
     security assessment is required and up to 100 Google accounts can connect.

## 4. Create the OAuth client

1. **APIs & Services → Credentials → Create credentials → OAuth client ID**.
2. Application type: **Web application**. Name: `Outreach web`.
3. **Authorised redirect URIs** — add every URL the app runs on, exactly:
   - Local development: `http://localhost:8000/oauth/google/callback`
   - Production (later): `https://YOUR-DOMAIN/oauth/google/callback`

   Google only accepts `http://` for `localhost`; a Laragon `.test` domain or a bare
   IP will be rejected, and a mismatch of even one character makes Google refuse
   with `redirect_uri_mismatch`.
4. **Create**. Copy the **Client ID** and **Client secret**.

## 5. Put the credentials in `.env`

```
APP_URL=http://localhost:8000
GOOGLE_CLIENT_ID=1234567890-abc.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=GOCSPX-…
GOOGLE_REDIRECT_URI="${APP_URL}/oauth/google/callback"
```

Restart `php artisan serve` (or clear the config cache in production:
`php artisan config:clear`). **Admin → Settings → Server setup** shows a green tick
next to *Google OAuth client* when the values are picked up.

## 6. Connect a mailbox

1. Run the app on `http://localhost:8000` (`php artisan serve --host=localhost --port=8000`).
   Start and finish the flow on the same host name — `127.0.0.1` and `localhost` are
   different origins to the browser's cookies.
2. **Admin → Email accounts → Connect Gmail**.
3. Pick the Gmail account, click through the unverified-app notice, and make sure the
   **"Send email on your behalf"** box stays ticked. Untick it and the app refuses the
   connection (it cannot work without it).
4. You land back on Email accounts with the mailbox connected. Set its display name,
   daily cap, signature and which members may use it, then **Send test to me**.

## Everyday facts

- Sent mail **does** appear in that Gmail account's Sent folder (Gmail files it;
  the app still never reads anything).
- Google revokes the connection if the account's password changes, if access is
  removed at <https://myaccount.google.com/permissions>, or after six months without
  use. The account then shows **Needs reconnect** and a banner appears for admins;
  **Reconnect** repeats step 6 for that mailbox.
- Personal Gmail allows roughly 500 outgoing messages a day; Google Workspace 2,000.
  Your daily cap in Outreach should stay far below that (the default is 30).
- To move to a production domain, add its redirect URI in step 4 (both can coexist)
  and update `APP_URL` / `GOOGLE_REDIRECT_URI` on the server.
