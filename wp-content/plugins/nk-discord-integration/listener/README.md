# Nine Kings — Discord Welcome Listener

Tiny Node.js companion bot for the Nine Kings Discord Integration WordPress plugin. It maintains the Gateway connection that WordPress can't, listens for `guildMemberAdd`, and forwards each event to a REST endpoint on the website. WordPress does the rest (member numbering, message templating, webhook posting).

## Why this exists

WordPress is request/response — it can't hold a persistent WebSocket connection to Discord's Gateway. The listener handles the one thing PHP can't: receiving real-time events. Everything else lives in the plugin so it can be edited from wp-admin.

## Architecture at a glance

```
Discord Gateway ──websocket──▶ Listener (this folder, on the same Ubuntu box)
                                    │
                                    │ POST /wp-json/nk-discord/v1/member-joined
                                    │ X-NK-Secret: <shared secret>
                                    ▼
                               WordPress (same box)
                                    │
                                    │ POST webhook
                                    ▼
                               Discord welcome channel
```

## Discord-side prep (do this once)

1. <https://discord.com/developers/applications> → New Application (or use your existing Nine Kings app).
2. **Bot tab** → Reset Token → copy the token somewhere safe.
3. **Bot tab** → Privileged Gateway Intents → enable **Server Members Intent**. Without this, `guildMemberAdd` never fires.
4. **OAuth2 → URL Generator** → scopes: `bot`. Permissions: `View Channels` is sufficient for join detection. Open the generated URL and add the bot to your server.
5. Discord channel for welcomes → Channel Settings → Integrations → Webhooks → New Webhook → copy the URL.

## WordPress-side prep

In wp-admin → Settings → Nine Kings Discord, fill in the **Welcome Channel** section:

- **Welcome Webhook URL**: paste from step 5 above
- **Member Count Offset**: your current member count (e.g. `1561`) so the next join is numbered correctly
- **Message Template**: edit if you want, the default mirrors the existing format
- **Embed Title**: optional override
- **Listener Shared Secret**: generate a long random string (`openssl rand -hex 32` is fine) and paste it here. You'll paste the *same* value into the listener's `.env` next.

Save.

## Deploying the listener on Ubuntu (recommended)

These instructions assume the listener lives at `/var/www/nine-kings/wp-content/plugins/nk-discord-integration/listener` — adjust paths if your WordPress install is elsewhere.

### 1. Install Node 20

```bash
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt-get install -y nodejs
node --version  # should be v20.x
```

### 2. Install dependencies

```bash
cd /var/www/nine-kings/wp-content/plugins/nk-discord-integration/listener
sudo -u www-data npm install --omit=dev
```

(Running as `www-data` avoids permission churn since that user already owns the WordPress files.)

### 3. Create the env file

```bash
sudo -u www-data cp .env.example .env
sudo -u www-data nano .env
```

Fill in:

```env
DISCORD_BOT_TOKEN=...your bot token...
WP_ENDPOINT=https://nine-kings.com/wp-json/nk-discord/v1/member-joined
WP_SHARED_SECRET=...same value as the WP settings page...
GUILD_ID=...your Discord server ID, optional but recommended...
```

Lock down the env file so other users can't read the bot token:

```bash
sudo chmod 600 .env
sudo chown www-data:www-data .env
```

### 4. Install the systemd unit

A template is in `nk-welcome.service.example`. Copy it to systemd and enable:

```bash
sudo cp nk-welcome.service.example /etc/systemd/system/nk-welcome.service
sudo nano /etc/systemd/system/nk-welcome.service  # adjust paths/user if needed
sudo systemctl daemon-reload
sudo systemctl enable --now nk-welcome
```

Check it's running:

```bash
sudo systemctl status nk-welcome
sudo journalctl -u nk-welcome -f   # follow logs
```

You should see `[nk-welcome] Logged in as <bot-name>`.

### 5. Test the welcome

Easiest test: invite a throwaway account to the server. If you don't have one handy, use the admin preview endpoint while logged in as a WP admin:

```bash
curl -X POST https://nine-kings.com/wp-json/nk-discord/v1/welcome-test \
     -H "Cookie: $(cat ~/wp-admin-cookie.txt)" \
     -i
```

Or hit it from the browser dev tools while logged into wp-admin (no shell required).

## Optional: keep the join traffic on loopback

By default the listener calls the public site URL, which round-trips through DNS → your box's public IP → Nginx → PHP-FPM. That's fine — it's one POST per join. If you want to skip the public path:

```env
WP_ENDPOINT=http://127.0.0.1/wp-json/nk-discord/v1/member-joined
```

…and add a Host header in `index.js` so WordPress sees the right siteurl:

```js
headers: {
  'Content-Type': 'application/json',
  'X-NK-Secret': WP_SHARED_SECRET,
  'Host': 'nine-kings.com',  // match WP's siteurl
}
```

This is a minor optimization; skip it unless you're noticing DNS/TLS overhead in logs.

## Operating notes

- **Restarts**: `sudo systemctl restart nk-welcome` after editing `.env` or pulling new code.
- **Updates**: `cd listener && sudo -u www-data git pull && sudo -u www-data npm install --omit=dev && sudo systemctl restart nk-welcome`.
- **Outage behavior**: if the listener is down, joins won't be welcomed (Discord doesn't queue them). Set up a basic uptime check on the systemd service if this matters.
- **Memory footprint**: ~70-100 MB resident. Negligible.
- **Bot intents**: the listener only requests `Guilds` + `GuildMembers`. If you add features later (messages, voice, reactions), add the matching intents in `index.js` *and* enable them in the Developer Portal.

## Adding more events later

When you outgrow welcome-only, this listener is the natural place to add more handlers (`messageCreate`, `voiceStateUpdate`, etc.) — each one POSTs to its own WordPress REST route, and the plugin handles the business logic.

For slash commands specifically, you can skip the listener entirely and use Discord's **Interactions Endpoint URL** pointed at a WordPress REST route — that's request/response and fits PHP perfectly.
