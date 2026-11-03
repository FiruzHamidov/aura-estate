const test = require("node:test");
const assert = require("node:assert/strict");
const { generateKeyPairSync, sign } = require("node:crypto");
const { verifyDelivery } = require("../deploy/tools/verify-delivery.cjs");

const { privateKey, publicKey } = generateKeyPairSync("rsa", { modulusLength: 2048 });
const key = { ...publicKey.export({ format: "jwk" }), kid: "fixture", alg: "RS256", use: "sig" };
const sha = "a".repeat(40);
const config = "b".repeat(64);
const archive = "c".repeat(64);
const repo = "FiruzHamidov/aura-estate";
const claims = {
  iss: "https://token.actions.githubusercontent.com",
  aud: ["aura-deploy", "backend", sha, config, archive].join("/"),
  repository: repo, repository_id: "988154786", ref: "refs/heads/main", sha,
  workflow_ref: repo + "/.github/workflows/deploy-production.yml@refs/heads/main",
  workflow_sha: sha, environment: "production", event_name: "push", nbf: 50, exp: 250,
};
const encode = obj => Buffer.from(JSON.stringify(obj)).toString("base64url");
function signed(overrides) {
  const payload = encode({ kid: "fixture", alg: "RS256" }) + "." + encode({ ...claims, ...overrides });
  return payload + "." + sign("sha256", Buffer.from(payload), privateKey).toString("base64url");
}
const check = token => verifyDelivery(token, [key], "backend", sha, config, archive, 100);

test("accepts the exact main release from the production workflow", () => {
  check(signed({}));
  check(signed({ event_name: "workflow_dispatch" }));
});
test("rejects validly signed credentials outside the release boundary", () => {
  for (const overrides of [{ aud: claims.aud.slice(0, -1) }, { iss: "other" }, { repository: "other" },
    { repository_id: "42" }, { ref: "refs/heads/dev" }, { sha: "other" }, { workflow_ref: "other" },
    { workflow_sha: "other" }, { environment: "other" }, { event_name: "pull_request" },
    { exp: 99 }, { nbf: 120 }]) {
    assert.throws(() => check(signed(overrides)), /Unauthorized/);
  }
});
test("rejects a forged signature", () => {
  const [header, body] = signed({}).split(".");
  assert.throws(() => check(header + "." + body + ".ZmFrZQ"), /signature/);
});
