// Verify an Actions OIDC token before accepting bytes from the restricted SSH key.
const { createPublicKey, verify } = require("node:crypto");
const { readFileSync } = require("node:fs");
const issuer = "https://token.actions.githubusercontent.com";
const repositories = {
  backend: ["FiruzHamidov/aura-estate", "988154786"],
  front: ["FiruzHamidov/aura-front", "951444883"],
};

function verifyDelivery(token, keys, target, sha, config, archive, now = Date.now() / 1000) {
  const parts = token.split(".");
  if (parts.length !== 3 || !parts.every(p => /^[A-Za-z0-9_-]+$/.test(p))) throw Error("Invalid token");
  const [h, p, sig] = parts;
  const header = JSON.parse(Buffer.from(h, "base64url").toString());
  const key = keys.find(k => k.kid === header.kid && k.kty === "RSA" && k.use === "sig" && k.alg === "RS256");
  if (!key || header.alg !== "RS256" || !verify("RSA-SHA256", Buffer.from(h + "." + p),
    createPublicKey({key, format:"jwk"}), Buffer.from(sig, "base64url"))) throw Error("Invalid issuer signature");
  const claims = JSON.parse(Buffer.from(p, "base64url").toString());
  const [repo, id] = repositories[target];
  const expected = { iss: issuer, aud: ["aura-deploy", target, sha, config, archive].join("/"),
    repository: repo, repository_id: id, ref: "refs/heads/main", sha,
    workflow_ref: repo + "/.github/workflows/deploy-production.yml@refs/heads/main",
    workflow_sha: sha, environment: "production" };
  if (!Object.entries(expected).every(([k,v]) => claims[k] === v) ||
      !["push","workflow_dispatch"].includes(claims.event_name) ||
      !Number.isFinite(claims.exp) || !Number.isFinite(claims.nbf) ||
      claims.exp <= now || claims.nbf > now + 10) throw Error("Unauthorized or expired delivery");
}

async function main() {
  const token = readFileSync(0,"utf8");
  if (token.length > 16384) throw Error("Token too large");
  const result = await fetch(issuer + "/.well-known/jwks", { redirect: "error", signal: AbortSignal.timeout(15000) });
  if (!result.ok) throw Error("Cannot obtain issuer keys");
  const { keys } = await result.json();
  verifyDelivery(token, keys, ...process.argv.slice(2));
}

if (require.main === module) main().catch(() => { console.error("GitHub delivery verification failed"); process.exitCode = 1; });
module.exports = { verifyDelivery };
