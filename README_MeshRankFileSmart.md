# MeshRankFileSmart

Ranks files across a mesh network by how many distinct IPs host them, and determines the authoritative `public_key` for each file through a majority vote across those servers.

---

## How It Works

### Server Discovery

Servers are read from `servers.txt`, one entry per line. Each entry can be:

- A bare host: `192.168.1.10`
- A full URL: `http://mynode.example.com/mesh/index.php`

Lines starting with `#` are treated as comments and ignored. Duplicate base URLs (after normalization) are deduplicated — only the first occurrence is kept.

### Probing

Each server is probed in parallel (up to 16 threads). For every server, the program:

1. Resolves the server's IP address and hashes it with SHA-256.
2. Fetches `/files.txt` — a newline-separated list of filenames (e.g. `<sha256>.jpg`).
3. Downloads each file from `/files/<filename>` and verifies its content hash matches the filename.
4. Fetches `/info/<hash>.json` and extracts the `public_key` field.

A server is marked **ineligible** and excluded entirely if:
- `/files.txt` cannot be fetched.
- Any listed file is missing or its content does not match its filename hash (MD5 or SHA-256).

> Duplicate IPs are **not** eliminated. Multiple servers can share an IP — they simply cannot inflate a file's score or skew its public key vote (see Scoring below).

---

## Scoring

Each file's score = **number of distinct IPs that host it**.

Rules:
- If two or more servers share the same IP, that IP counts as **one vote**, not many.
- Among servers sharing an IP, the **first one probed** casts the vote for that IP.

### Public Key — Majority Vote

For each file, every distinct IP casts one vote for the `public_key` found in `/info/<hash>.json` on that server. The key that appears in **strictly more than half** the votes wins and is listed as the file's authoritative key.

If no key reaches a majority (tie or split), the public key column shows `(no consensus)`.

### Example

| Server | IP       | Hosts       | public_key in /info/ |
|--------|----------|-------------|----------------------|
| A      | 1.1.1.1  | fAAA, fBBB  | pk_alice, pk_bob     |
| B      | 2.2.2.2  | fAAA, fBBB  | pk_alice, pk_bob     |
| C      | 2.2.2.2  | fAAA        | pk_alice *(same IP as B — not counted again)* |
| D      | 3.3.3.3  | fAAA, fBBB  | pk_alice, pk_WRONG   |

**Results:**

| File | Distinct IPs | Votes                          | Public Key   |
|------|-------------|--------------------------------|--------------|
| fAAA | 3           | pk_alice, pk_alice, pk_alice   | pk_alice     |
| fBBB | 3           | pk_bob, pk_bob, pk_WRONG       | pk_bob       |

- `fAAA`: B and C share the same IP → counted once → **3 distinct IPs**, all vote `pk_alice` → consensus.
- `fBBB`: 3 distinct IPs, two vote `pk_bob`, one votes `pk_WRONG` → `pk_bob` wins (2 out of 3 > 50%).

---

## Output

Results are written to **`rank_files_smart.txt`** in descending order by distinct-IP count.

```
============================================================
  MESH FILE SMART RANK — <timestamp>
============================================================

  Scoring : number of distinct IPs hosting the file.
  Key     : majority public_key across those IPs (one vote per IP).
  Total eligible servers : 42

  Rank  File Hash                                                         IPs     Public Key
  --------------------------------------------------------------------------------------
     1  a3f5...sha256hash...64chars                                       15      pk_alice...
     2  d41d...sha256hash...64chars                                       12      pk_bob...
     3  9b2e...sha256hash...64chars                                        8      (no consensus)
  ...
============================================================
```

Each line shows:
- **Rank** — position in the list.
- **File Hash** — the SHA-256 (or MD5) hash that identifies the file.
- **IPs** — number of distinct IPs hosting the file.
- **Public Key** — the majority-vote public key, or `(no consensus)` if no key holds a majority.

---

## Configuration

Edit the constants at the top of `MeshRankFileSmart.java`:

| Constant             | Default               | Description                              |
|----------------------|-----------------------|------------------------------------------|
| `CONNECT_TIMEOUT_MS` | `8000`                | TCP connection timeout per server (ms)   |
| `READ_TIMEOUT_MS`    | `30000`               | HTTP read timeout per request (ms)       |
| `THREAD_POOL_SIZE`   | `16`                  | Max parallel probing threads             |
| `MAX_RANK`           | `100`                 | Number of files shown in output          |
| `SERVERS_FILE`       | `servers.txt`         | Input file with server list              |
| `OUTPUT_FILE`        | `rank_files_smart.txt`| Output ranking file                      |

---

## Requirements

- Java 8 or later
- No external dependencies — uses only the Java standard library

## Build & Run

```bash
javac MeshRankFileSmart.java
java MeshRankFileSmart
```

Make sure `servers.txt` is in the same directory as the compiled class.

---

## Expected Server Layout

Each mesh node must expose the following HTTP endpoints:

```
/files.txt              — newline-separated list of filenames
/files/<filename>       — the actual file content
/info/<hash>.json       — JSON metadata, must contain a "public_key" field
```

Filenames must be named after their content hash (MD5 or SHA-256), optionally with an extension:

```
a3f5...64chars....jpg   ← SHA-256 named file
d41d...32chars....png   ← MD5 named file
```

The corresponding info file for `a3f5...jpg` must be at `/info/a3f5....json` (hash only, no extension, plus `.json`).

Example `/info/<hash>.json`:
```json
{
  "public_key": "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAA..."
}
```

---

## Comparison with MeshRankSmart

| Program             | Unit ranked | Score means                                      |
|---------------------|-------------|--------------------------------------------------|
| `MeshRankSmart`     | Server      | How many distinct foreign IPs host my files      |
| `MeshRankFileSmart` | File        | How many distinct IPs host this file             |

Both programs share the same eligibility rules, IP deduplication strategy, and majority-vote mechanism for public keys.