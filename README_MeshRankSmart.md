# MeshRankSmart

Ranks mesh-node servers based on how widely their files are replicated across the network, using a smart scoring system that is resistant to IP duplication abuse.

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
4. Fetches `/info/<hash>.json` for metadata (e.g. `public_key`).

A server is marked **ineligible** and excluded from scoring if:
- `/files.txt` cannot be fetched.
- Any listed file is missing or its content does not match its filename hash (MD5 or SHA-256).

> Duplicate IPs are **not** eliminated. Multiple servers can share an IP — they just cannot inflate each other's scores (see Scoring below).

---

## Scoring

Each server's score = **number of distinct foreign IPs that host at least one of its files**.

Rules:
- The server's own IP is never counted toward its own score.
- If multiple servers share the same IP, that IP counts as **one point** regardless of how many of those servers host the file.

### Example

| Server | IP       | Files hosted          |
|--------|----------|-----------------------|
| A      | 1.1.1.1  | f1, f2, f3, f4, f5   |
| B      | 2.2.2.2  | f1, f2               |
| C      | 3.3.3.3  | f3                   |
| D      | 2.2.2.2  | f2, f3  *(same IP as B)* |
| E      | 4.4.4.4  | f99 *(none of A's)*  |

**Score of A = 2**

- B and D both host files from A, but share the same IP → counted as **1 point**.
- C hosts a file from A with a different IP → **1 point**.
- E hosts none of A's files → **0 points**.
- A's own IP (1.1.1.1) is excluded.

---

## Output

Results are written to **`rank_smart.txt`** in descending order by score.

```
============================================================
  MESH NODE SMART RANK — <timestamp>
============================================================

  Scoring: number of distinct foreign IPs hosting your files.
  Duplicate IPs count as one. Own IP not counted.
  Total eligible servers: 42

-- TOP 100 SERVERS (descending by smart score) ----------

    1. http://bestnode.example.com               files=980     score=15
    2. http://node2.example.com                  files=500     score=9
  ...
============================================================
```

Each line shows:
- **Rank** — position in the list.
- **Base URL** — normalized server address.
- **files** — number of valid files the server hosts.
- **score** — number of distinct foreign IPs replicating at least one of those files.

---

## Configuration

Edit the constants at the top of `MeshRankSmart.java`:

| Constant            | Default        | Description                              |
|---------------------|----------------|------------------------------------------|
| `CONNECT_TIMEOUT_MS`| `8000`         | TCP connection timeout per server (ms)   |
| `READ_TIMEOUT_MS`   | `30000`        | HTTP read timeout per request (ms)       |
| `THREAD_POOL_SIZE`  | `16`           | Max parallel probing threads             |
| `MAX_RANK`          | `100`          | Number of servers shown in output        |
| `SERVERS_FILE`      | `servers.txt`  | Input file with server list              |
| `OUTPUT_FILE`       | `rank_smart.txt` | Output ranking file                    |

---

## Requirements

- Java 8 or later
- No external dependencies — uses only the Java standard library

## Build & Run

```bash
javac MeshRankSmart.java
java MeshRankSmart
```

Make sure `servers.txt` is in the same directory as the compiled class.

---

## Expected Server Layout

Each mesh node must expose the following HTTP endpoints:

```
/files.txt              — newline-separated list of filenames
/files/<filename>       — the actual file content
/info/<hash>.json       — JSON metadata with at least a "public_key" field
```

Filenames must be named after their content hash (MD5 or SHA-256), optionally with an extension:

```
a3f5...64chars....jpg   ← SHA-256 named file
d41d...32chars....png   ← MD5 named file
```