/**
 * Nine Kings — Discord welcome listener
 *
 * Tiny companion bot that watches the Gateway for new members and forwards
 * the join event to the WordPress plugin's REST endpoint. WordPress does the
 * formatting, member numbering, and webhook posting.
 *
 * Setup:
 *   1. cp .env.example .env  (then fill in values)
 *   2. npm install
 *   3. npm start
 *
 * Production: run under pm2 / systemd / Docker so it stays up.
 */

require('dotenv').config();
const { Client, GatewayIntentBits, Partials } = require('discord.js');

const {
  DISCORD_BOT_TOKEN,
  WP_ENDPOINT,
  WP_SHARED_SECRET,
  GUILD_ID, // optional — limit to a single guild
} = process.env;

if (!DISCORD_BOT_TOKEN || !WP_ENDPOINT || !WP_SHARED_SECRET) {
  console.error('Missing required env vars: DISCORD_BOT_TOKEN, WP_ENDPOINT, WP_SHARED_SECRET.');
  process.exit(1);
}

const client = new Client({
  // GuildMembers is a privileged intent — enable it in the Discord Developer Portal
  // for your application (Bot tab → Privileged Gateway Intents → Server Members Intent).
  intents: [GatewayIntentBits.Guilds, GatewayIntentBits.GuildMembers],
  partials: [Partials.GuildMember],
});

client.once('ready', () => {
  console.log(`[nk-welcome] Logged in as ${client.user.tag}`);
  if (GUILD_ID) {
    console.log(`[nk-welcome] Restricted to guild ${GUILD_ID}`);
  }
});

client.on('guildMemberAdd', async (member) => {
  if (GUILD_ID && member.guild.id !== GUILD_ID) {
    return;
  }

  const payload = {
    discord_id: member.id,
    username: member.user.username,
    avatar_url: member.user.displayAvatarURL({ size: 256, extension: 'png' }),
    guild_name: member.guild.name,
  };

  try {
    const res = await fetch(WP_ENDPOINT, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-NK-Secret': WP_SHARED_SECRET,
      },
      body: JSON.stringify(payload),
    });

    const text = await res.text();
    if (!res.ok) {
      console.error(`[nk-welcome] WP returned ${res.status}: ${text}`);
      return;
    }
    console.log(`[nk-welcome] Forwarded join for ${payload.username} (${payload.discord_id}) → ${text}`);
  } catch (err) {
    console.error('[nk-welcome] Forward failed:', err);
  }
});

client.login(DISCORD_BOT_TOKEN);

// Graceful shutdown
process.on('SIGINT', () => { client.destroy(); process.exit(0); });
process.on('SIGTERM', () => { client.destroy(); process.exit(0); });
