Here are concrete, actionable suggestions ordered roughly by impact:

---

## Authentication

**Replace the daily nonce with a real session token.** The current nonce is `sha256(pubkeyFilename + date)` — it's the same value for all requests from the same user on the same day, never expires mid-day, and anyone who captures it has full upload access for up to 24 hours. Replace it with a server-generated random token (at least 32 bytes, `bin2hex(random_bytes(32))`) stored in a `sessions/` directory with an expiry timestamp. Invalidate it on logout.

**Salt the password hashes.** SHA-512 without a salt means identical passwords produce identical hashes, and pre-computed rainbow tables work directly against your `accounts/` files if they leak. Use `password_hash($password, PASSWORD_ARGON2ID)` and `password_verify()` — one-line change that gives you salting, stretching, and future algorithm agility for free.

**Add rate limiting on login.** There is currently no brute-force protection. Track failed attempts per public key (a simple counter file in `accounts/` works) and impose a lockout or exponential delay after 5–10 failures.

---

## Inter-node trust

**Nodes currently accept pushes from anyone.** Any HTTP client that knows your endpoint can push files to your `files/` directory and overwrite account JSON. Add a **shared node secret** (a pre-shared key in `node_secret.txt`) that is HMAC-signed into every inter-node request. The receiver checks the signature before processing. This is a single config file — low friction for a private network.

**Enable SSL certificate verification between nodes.** `verify_peer = false` is currently hardcoded. Add a config flag; default it to `true` for `https://` peers. Self-signed certs can be handled with a `trusted_certs/` directory rather than disabling verification entirely.

**Validate `account_sync` more carefully.** Right now a peer can send any JSON it wants as the account — there is no signature proving the account came from the legitimate owner. Sign the account JSON with the user's private key on upload and verify the signature on sync. Without this, a compromised peer can corrupt another peer's copy of a user's account.

---

## File storage

**The `files/` directory should not be web-browsable.** Anyone who knows or guesses a SHA-256 hash can download any stored file directly via `files/<hash>.ext`. If files are meant to be private, either move `files/` outside the web root and serve them through a PHP controller that checks authorisation, or add a `.htaccess` / Nginx rule to block direct access.

**The `accounts/` and `transactions/` directories are also web-accessible.** A direct request to `accounts/<key>.json` returns the full account including the password hash. Move sensitive directories outside the web root or deny access with server config.

**Restrict upload size.** There is no file size limit in the PHP code — the only cap is PHP's `upload_max_filesize`. Set an explicit application-level limit and return a clear error, so one large upload cannot fill the disk or be used as a DoS vector.

---

## Input and output

**The `account_json` field sent in peer pushes can be arbitrarily large.** A malicious peer could send a gigabyte of JSON to exhaust memory or disk. Add a size cap (e.g. 512 KB) before attempting to decode or write it.

**`node_info.txt` content is echoed into stored metadata without sanitisation.** If this is later rendered in a web UI, it is an XSS vector. Strip or escape HTML before storing.

**The `debug_verify` endpoint exposes internal server topology** (all known peers, their URLs) to anyone on localhost, and to anyone with the token if `DEBUG_VERIFY_TOKEN` is set. Remove it from production builds or guard it behind the node secret.

---

## Data integrity

**Files are stored but never re-verified after the initial push.** A peer could silently delete or corrupt a file after confirming storage. Add a periodic re-verification job (a CLI script or cron) that re-checks `HEAD /files/<hash>` on each recorded server and updates `last_verified` — or flags servers that have gone silent.

**Atomic writes use `rename()` which is not safe across filesystems.** If `files/` is on a different mount than the tmp directory, `rename()` silently falls back to a copy-then-delete that is not atomic. Use `sys_get_temp_dir()` on the same filesystem as the target, or explicitly write the tmp file into the same directory as the destination.

---

## Infrastructure

**Run PHP under a dedicated low-privilege user** with write access only to the node's working directory — not to the web root as a whole. This limits the damage if the node is compromised.

**Add a `Content-Security-Policy` header** if the PHP file serves any HTML (the UI pages). The current code mixes API responses and a full HTML frontend in the same file, so XSS in the UI has direct access to the API.

**Log and alert on anomalies** — repeated pushes of the same hash from different peers in a short window, account sync requests for accounts that don't exist locally (probing), or file extensions that approach the blocked list. A simple append-only `security.log` goes a long way.