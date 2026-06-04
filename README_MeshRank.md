# MeshRank

A single-file Java 8 command-line tool that audits a list of mesh-node servers, verifies the integrity of every file they host, and produces a ranked leaderboard saved to `rank.txt`.

---

## Requirements

- Java 8 or newer (no external libraries needed)
- Network access to the servers listed in `servers.txt`

---

## Quick start

```bash
# 1. Compile
javac MeshRank.java

# 2. Create servers.txt in the same directory
echo "http://localhost/meento/1/index.php" > servers.txt
echo "https://example.com" >> servers.txt

# 3. Run
java MeshRank

# 4. Press Enter to exit when finished
# Results are written to rank.txt
```

---

## Files

| File | Role |
|---|---|
| `MeshRank.java` | The entire program — one file, no dependencies |
| `servers.txt` | Input: one server URL per line |
| `rank.txt` | Output: generated leaderboard (created/overwritten on each run) |

---

## servers.txt format

One server entry per line. Any of the following formats are accepted:

```
# Lines starting with # are ignored
http://localhost/meento/1/index.php
https://example.com/index.php
https://example.com
https://example.com/
192.168.1.10/node/v2/index.php
```

If no scheme (`http://` / `https://`) is present, `http://` is assumed.

If the last path segment looks like a filename (contains a `.`), it is stripped to derive the directory base URL. Requests are then built relative to that base:

| Entry in `servers.txt` | Base URL used |
|---|---|
| `http://localhost/meento/1/index.php` | `http://localhost/meento/1` |
| `https://example.com/index.php` | `https://example.com` |
| `https://example.com` | `https://example.com` |
| `http://192.168.1.1/node/v2/index.php` | `http://192.168.1.1/node/v2` |

Duplicate base URLs (after normalisation) are deduplicated automatically.

---

## Expected server layout

Each server must expose the following paths relative to its base URL:

```
<base>/files.txt          — plain text, one filename per line
<base>/files/<filename>   — the actual stored file
<base>/info/<hash>.json   — JSON metadata for each file
```

### files.txt

Each line is a filename whose stem is the file's hash:

```
84706c1e3bbaabc5f83477a88c0638f1f11eec8827cc7c19211fb0487120c5e4.jpg
d7b746b1f2b69a6374b689a9a2b336e855bb8db7abca6ccf3d175635f6db420e.jpg
```

Supported hash formats:

| Length | Algorithm |
|---|---|
| 32 hex characters | MD5 |
| 64 hex characters | SHA-256 |

Filenames that do not match either length are silently skipped.

### info/\<hash\>.json

Metadata file for each stored file. Must contain at least the `public_key` field:

```json
{
    "original_filename": "photo.jpg",
    "size": 123515,
    "extension": "jpg",
    "public_key": "...",
    "node_info": "",
    "description": ""
}
```

---

## How it works

### 1. URL normalisation

Each entry in `servers.txt` is normalised to a base directory URL (see table above) before any requests are made.

### 2. Parallel probing

All servers are probed concurrently (up to 16 threads by default) to keep total runtime low even with large server lists.

### 3. File integrity check

For every filename listed in a server's `files.txt`, the program:

1. Downloads the file from `<base>/files/<filename>`.
2. Hashes the downloaded bytes with the algorithm implied by the filename length (MD5 or SHA-256).
3. Compares the computed hash against the filename stem.

If any file is missing or its hash does not match, **the entire server is eliminated** and a message is printed.

### 4. Duplicate IP elimination

Each server's hostname is resolved to an IP address, which is then hashed with SHA-256. If two servers resolve to the same IP, **both are eliminated** and a message is printed. This prevents a single physical machine from inflating its own rank by listing itself under multiple URLs.

### 5. Public-key consensus

After all servers have been probed, the program looks at the `public_key` field recorded in each server's `info/<hash>.json` for every file. Across all eligible servers it runs a majority vote per file hash:

- If one key appears in **more than 50 %** of the votes for that file, it wins.
- If there is a tie or no majority, the file is **discarded from the count** entirely.

This consensus step ensures that a server cannot improve its rank by reporting a fake public key that differs from what the rest of the network reports.

### 6. Ranking

**Server rank** — sorted descending by the number of files for which the server holds the consensus-winning public key (max 100 entries).

**Public-key rank** — sorted descending by the number of distinct eligible servers that carry a given public key as the majority key for at least one file (max 100 entries).

### 7. Output

Results are written to `rank.txt` in UTF-8. Example:

```
============================================================
  MESH NODE RANK — Sat May 16 21:00:00 UTC 2026
============================================================

── TOP 100 SERVERS BY FILE COUNT ─────────────────────────

    1. https://node-a.example.com                              42 file(s)
    2. https://node-b.example.com                              38 file(s)
    ...

── TOP 100 PUBLIC KEYS BY SERVER COUNT ──────────────────

    1. ssh-ed25519 AAAAC3Nza...                                17 server(s)
    2. ssh-ed25519 AAAAC3Nzb...                                 9 server(s)
    ...

============================================================
```

---

## Elimination messages

| Message | Meaning |
|---|---|
| `[SKIP] <url> — cannot fetch files.txt` | Server is unreachable or returned a non-200 status. Skipped, not hard-eliminated. |
| `[ELIMINATED] <url> — file not found: <filename>` | A file listed in `files.txt` could not be downloaded from `/files/`. |
| `[ELIMINATED] <url> — hash mismatch for <filename>` | The file was downloaded but its content does not match the hash in its filename. |
| `[ELIMINATED] Duplicate IP … <url-a> and <url-b>` | Two servers resolved to the same IP address. |

---

## Configuration

All tunables are constants at the top of `MeshRank.java`:

| Constant | Default | Description |
|---|---|---|
| `CONNECT_TIMEOUT_MS` | `8000` | TCP connect timeout per request (ms) |
| `READ_TIMEOUT_MS` | `30000` | Read timeout per request (ms) |
| `THREAD_POOL_SIZE` | `16` | Maximum concurrent server probes |
| `MAX_RANK` | `100` | Maximum entries in each ranking section |
| `SERVERS_FILE` | `servers.txt` | Input file name |
| `OUTPUT_FILE` | `rank.txt` | Output file name |

---

## Limitations

- HTTPS connections use the JVM's default trust store. Self-signed certificates will cause connection errors unless the certificate is imported into the trust store.
- Very large files are downloaded entirely into memory for hashing. Ensure sufficient heap if servers host large files (`java -Xmx512m MeshRank`).
- The program does not retry failed requests. Transient network errors will cause a server to be skipped or eliminated.
