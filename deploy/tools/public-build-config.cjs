// Installed outside the checkout. Only literal, browser-visible values cross into CI.
const { existsSync, readFileSync } = require("node:fs");
const { parseEnv } = require("node:util");

function buildConfig(project, input, mode, ids) {
  const result = {};
  for (const [key, value] of Object.entries(input)) {
    if (project === "backend" ? key !== "ASSET_URL" : !publicKeys.has(key)) continue;
    if (/[\r\n\x00$]/.test(value)) throw new Error("Nonliteral build setting: " + key);
    result[key] = value;
  }
  if (project === "front") {
    const validIds = /^\d+(,\d+)*$/.test(ids);
    if (!["all", "off", "pilot"].includes(mode) || (mode === "pilot" && !validIds)) {
      throw new Error("Invalid residential rollout settings");
    }
    result.RESIDENTIAL_UI_MODE = mode;
    result.RESIDENTIAL_UI_PILOT_IDS = validIds ? ids : "";
  }
  return result;
}

const publicKeys = new Set(`
  APP_URL APP_VERSION API_URL BASE_URL SITE_URL STORAGE_URL COOKIE_DOMAIN COOKIE_SECURE
  YANDEX_MAPS_API_KEY YM_ID GA_MEASUREMENT_ID GOOGLE_ADS_CLIENT_ID ENABLE_ADS
  TELEGRAM_BOT_USERNAME DEBUG_AUTH DEBUG_MIDDLEWARE KPI_STRICT_V2 KPI_QA_DEBUG
  FIREBASE_API_KEY FIREBASE_PROJECT_ID FIREBASE_APP_ID FIREBASE_STORAGE_BUCKET
  FIREBASE_VAPID_KEY FIREBASE_AUTH_DOMAIN FIREBASE_MESSAGING_SENDER_ID
  REVERB_APP_KEY REVERB_HOST REVERB_PORT REVERB_SCHEME
`.trim().split(/\s+/).map(key => "NEXT_PUBLIC_" + key));

if (require.main === module) {
  const project = process.argv[2];
  if (!["backend", "front"].includes(project)) throw new Error("Unknown build target");
  if (process.argv[3] === "--systemd") {
    const snapshot = JSON.parse(readFileSync(0, "utf8"));
    const config = buildConfig(project, snapshot, snapshot.RESIDENTIAL_UI_MODE, snapshot.RESIDENTIAL_UI_PILOT_IDS);
    for (const key of Object.keys(config).sort()) {
      const value = config[key].replace(/["\\`]/g, character => "\\" + character);
      console.log(key + '="' + value + '"');
    }
  } else {
    const files = project === "backend" ? ["/var/www/aura-estate/.env"] :
      [".env", ".env.production", ".env.local", ".env.production.local"]
        .map(file => "/etc/aura-deploy/frontend-env/" + file);
    const input = {};
    for (const file of files) {
      if (existsSync(file)) Object.assign(input, parseEnv(readFileSync(file, "utf8")));
    }
    const config = buildConfig(project, input, process.env.RESIDENTIAL_UI_MODE || "off",
      process.env.RESIDENTIAL_UI_PILOT_IDS || "");
    console.log(JSON.stringify(config, Object.keys(config).sort()));
  }
}
module.exports = { buildConfig };
