# Approved temporary build audit exception

This exception is not an upstream fix or a dismissal of the vulnerability. On 2026-11-18 the release owner explicitly accepted the proposed exception by replying “Сделай полный дпелой” to the question requesting approval for this advisory, the existing development dependencies and the November 25 deadline. The owner subsequently requested a combined release with the adjacent permissions/history task. This records the human risk decision for the scope below; it does not authorize any broader audit exception.

- Advisory: [GHSA-fc5q-39xc-vjpf / CVE-2026-112366](https://github.com/advisories/GHSA-fc5q-39xc-vjpf), ReDoS via attacker-controlled repeated extglob patterns. GitHub reviewed the affected range on 2026-11-17; the latest upstream release remains 4.0.7, and no patched release is listed.
- Failed release: [44247977251](https://github.com/FiruzHamidov/aura-estate/actions/runs/44247977251), commit `ebcc82442bc01ba7f6c381775bbd4a265941bd7c`. Build stopped at npm audit; deployment did not execute.
- Reviewed lockfile: `package-lock.json`, SHA-256 `7ccbe23ce9bf961d65fac2000c70a340d7f00ab4154d89446b161737185c9e51`, unchanged from the release commit.
- Copies: `node_modules/picomatch` 4.0.7 (Vite/fdir/tinyglobby), and `node_modules/vite-plugin-full-reload/node_modules/picomatch` 2.3.2 (laravel-vite-plugin). Both are marked development-only in the lockfile.
- Context: the backend build runs on a disposable GitHub runner against reviewed repository inputs. Only static `public/build` files are delivered; the backend's Node dependency tree is not deployed to the PHP application. Residual risk remains in processing malicious build-time glob patterns; this is not a claim that the packages are fixed or universally safe.

The gate runs the ordinary npm audit, fails on incomplete data or audit execution errors, and permits only this advisory's dependency chain, only for this exact lockfile and development-only paths, until **2026-11-25 00:00 UTC (05:00 Asia/Dushanbe)**. Other moderate/high/critical findings remain blocking. New lockfiles, runtime copies, new package paths and expired exceptions fail closed. Existing PHP tests, build, signed delivery and production health checks are unchanged.

Validation: seven local tests cover the allowed copies, expiry, other advisories, production paths, lockfile changes, incomplete/cyclic reports and clean audits after expiry. The captured failing audit is accepted only under this narrow exception. No dependency version is changed.

Publish this change through a reviewed PR and complete the normal production workflow. Recheck upstream before expiry and remove the exception after an audited upgrade.
