const assert = require("node:assert/strict");
const test = require("node:test");
const { buildConfig } = require("../deploy/tools/public-build-config.cjs");

test("only known public variables cross the build boundary", () => {
  const input = { APP_KEY: "private", TG_BOT_TOKEN: "private", NEXT_PUBLIC_UNKNOWN_TOKEN: "private",
    NEXT_PUBLIC_API_URL: "https://backend.aura.tj", ASSET_URL: "https://assets.example" };
  assert.deepEqual(buildConfig("front", input, "pilot", "12,34"), {
    NEXT_PUBLIC_API_URL: input.NEXT_PUBLIC_API_URL, RESIDENTIAL_UI_MODE: "pilot", RESIDENTIAL_UI_PILOT_IDS: "12,34"
  });
  assert.deepEqual(buildConfig("backend", input), {ASSET_URL: input.ASSET_URL});
});
test("configuration cannot inject runner environment entries or interpolate secrets", () => {
  for (const value of ["test\nPATH=unsafe", "test\rBAD=yes", "$APP_KEY"]) {
    assert.throws(() => buildConfig("front", {NEXT_PUBLIC_API_URL: value}, "off", ""));
  }
});
