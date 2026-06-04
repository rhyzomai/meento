# Mesh Node

A single-file PHP application that turns any web server into a node in a decentralised file replication network. When a user uploads a file, the node automatically pushes it to every peer it knows about, verifies storage, and keeps each peer's copy of the user account JSON up to date — with no central coordinator.

---

## Requirements

- PHP 7.4 or newer
- A web server that passes requests to `index_full.php` (Apache, Nginx, Caddy, etc.)
- cURL extension recommended (bundled with most PHP installs); the node falls back to `file_get_contents` if cURL is absent
- Write permission on the node's working directory

---

## Installation

1. Copy `index_full.php` to a publicly accessible directory on your web server, for example `/var/www/html/node/`.
2. Create a `servers.txt` file in the same directory listing the peer nodes you want to replicate to (one URL per line). The directory is created automatically on first request if it does not exist, but an empty `servers.txt` means no replication.
3. Optionally create `node_info.txt` with a short human-readable description of this node (e.g. `"Main node — Amsterdam"`).
4. Optionally create `public_key.txt` with the node's own public key.

The script creates all required subdirectories on first run:

```
node/
├── index_full.php        ← the application
├── servers.txt           ← peer URLs
├── node_info.txt         ← optional node description
├── public_key.txt        ← optional node public key
├── files/                ← stored files  (auto-created)
├── info/                 ← per-file JSON metadata  (auto-created)
├── accounts/             ← user account JSON files  (auto-created)
├── transactions/         ← per-user transaction logs  (auto-created)
├── files.txt             ← index of stored filenames  (auto-created)
└── data.json             ← aggregate file metadata  (auto-created)
```

---

## `servers.txt` format

One peer URL per line. Lines beginning with `#` are comments. The script accepts URLs with or without a trailing slash and with or without the script filename — all of the following resolve to the same peer:

```
# these three lines all mean the same node
http://peer.example.com/node/
http://peer.example.com/node
http://peer.example.com/node/index_full.php
```

Duplicate entries are silently collapsed by the normalisation logic, so keeping both forms in the file is harmless.

---

## Account system

Accounts are identified by a **public key** string, not a username. The public key is sanitised and truncated to produce a safe filesystem filename; the raw value is stored inside the JSON.

### Registration

`POST index_full.php`  
`Content-Type: application/json`

```json
{
  "action":     "register",
  "username":   "alice",
  "password":   "hunter2",
  "publicKey":  "<your public key>",
  "mail":       "alice@example.com",
  "aboutMe":    "Just a user.",
  "userServer": "http://alice.example.com/node"
}
```

`userServer` is optional. When set, every file Alice uploads is also pushed to that URL in addition to the nodes in `servers.txt`.

**Response:**

```json
{ "ok": true, "message": "Account created successfully." }
```

### Login

`POST index_full.php`  
`Content-Type: application/json`

```json
{
  "action":    "login",
  "publicKey": "<your public key>",
  "password":  "hunter2"
}
```

**Response on success:**

```json
{
  "ok":      true,
  "message": "Login successful.",
  "data": {
    "username":    "alice",
    "public_key":  "...",
    "mail":        "alice@example.com",
    "about_me":    "Just a user.",
    "user_server": "http://alice.example.com/node",
    "balance":     3,
    "files":       [...],
    "servers":     { "<url_key>": "<url>", ... },
    "dates":       { "<url_key>": "<iso_datetime>", ... },
    "server_ip_hashes": ["<sha256>", ...],
    "created_at":  "2025-01-01T00:00:00+00:00",
    "updated_at":  "2025-06-01T12:00:00+00:00"
  }
}
```

The password is never returned. The `session_nonce` required for uploads is derived server-side from the public key and today's date — it is not sent back in the login response. The client must compute it as:

```
nonce = sha256( pubkeyToFilename(publicKey) + "YYYY-MM-DD" )
```

where `YYYY-MM-DD` is the current UTC date and `pubkeyToFilename` strips everything except `[A-Za-z0-9+/=\-_]`, collapses underscores, and truncates to 200 characters.

### Get profile

`POST index_full.php`  
`Content-Type: application/json`

```json
{
  "action":     "get_profile",
  "public_key": "<public key>"
}
```

Returns the full account object (without the password hash).

---

## Uploading a file

`POST index_full.php`  
`Content-Type: multipart/form-data`

| Field            | Required | Description                                        |
|------------------|----------|----------------------------------------------------|
| `file`           | yes      | The file to upload                                 |
| `public_key`     | yes      | The uploader's public key                          |
| `session_nonce`  | yes      | `sha256(pubkeyFilename + "YYYY-MM-DD")` (UTC date) |
| `comment`        | no       | Description, max 1 024 bytes                       |

PHP files (`.php`, `.phar`, `.phtml`, and variants) are rejected.

**Response:**

```json
{
  "ok":               true,
  "hash":             "c33c4f0db1d7a3fc...",
  "filename":         "c33c4f0db1d7a3fc....jpg",
  "existed":          false,
  "peers_pushed":     2,
  "peers_verified":   2,
  "verified_servers": ["http://peer1.example.com/node", "http://alice.example.com/node"],
  "push_details":     [...],
  "verify_details":   [...],
  "account_updated":  true,
  "account_message":  "File record added."
}
```

If the file was already stored (`"existed": true`), the response is returned immediately without pushing to peers again.

### What happens on upload

1. The file is hashed with SHA-256 and stored as `files/<hash>.<ext>`. If an extension cannot be determined the file is stored as `files/<hash>`.
2. Metadata is written to `info/<hash>.json` (original filename, size, extension, uploader public key, description).
3. The file is pushed via multipart POST to every target in `servers.txt` **and** to the uploader's `user_server` (if set and not already in `servers.txt`). The current account JSON is embedded in the push so the receiving peer can update `accounts/` immediately.
4. Each peer that accepted the push is verified: a confirmed push response carrying the correct hash counts as proof of storage; an additional HTTP HEAD/GET check is attempted as a secondary confirmation.
5. For every confirmed peer, `reportServer` updates the user's `balance` (+1 for each new server) and records the server URL and IP hash in the account.
6. The fully updated account JSON is broadcast to all confirmed peers a second time via a dedicated `account_sync` call so every copy is consistent.

---

## Server deduplication

The node prevents a single physical machine from being counted multiple times.

**By URL** — the URL is lowercased, the scheme and trailing slashes are stripped, and the script filename is removed. The result is SHA-256 hashed to produce a `url_key`. Two URLs that differ only in scheme, slash, or script name produce the same key and are treated as one server.

**By IP** — the hostname is resolved via DNS and the resulting IP address is SHA-256 hashed. The hash is stored in `server_ip_hashes` on the account. If a new server resolves to an IP whose hash is already in that list, the server is rejected as a duplicate even if its URL is different.

The `servers` and `dates` maps in the account JSON use `url_key` (a SHA-256 hex string) as the map key; the raw URL is stored as the value.

---

## Peer-to-peer protocol

All communication between nodes uses the same `index_full.php` endpoint.

### File push (`peer_push=1`)

`POST <peer>/index_full.php`  
`Content-Type: multipart/form-data`

| Field          | Description                                    |
|----------------|------------------------------------------------|
| `file`         | The file binary                                |
| `peer_push`    | Must be `"1"`                                  |
| `public_key`   | Uploader's public key                          |
| `description`  | File description                               |
| `node_info`    | Sending node's info string                     |
| `account_json` | Full uploader account JSON (optional but sent) |

The peer stores the file and, if `account_json` is present and valid, overwrites its local copy of the account.

### Account sync (`account_sync=1`)

`POST <peer>/index_full.php`  
`Content-Type: application/x-www-form-urlencoded`

| Field          | Description                  |
|----------------|------------------------------|
| `account_sync` | Must be `"1"`                |
| `public_key`   | Account owner's public key   |
| `account_json` | Full account JSON            |

The peer only overwrites an account file that already exists locally — it never creates new accounts from sync requests. The `public_key` field inside the JSON must match the `public_key` POST parameter or the request is rejected.

---

## Utility endpoints

### Node status

```
GET index_full.php?node_status
```

```json
{ "public_key": true, "node_info": true, "peers": 3 }
```

### Debug verification (localhost only)

Verify that a specific file is accessible on a remote server without uploading anything. Restricted to `127.0.0.1` / `::1` by default.

```
GET index_full.php?debug_verify
```

Returns usage info and the list of known servers.

```
GET index_full.php?debug_verify&server=http://peer.example.com/node&hash=<sha256>&filename=<hash.ext>
```

Returns the result of running the full verification logic against that server. Useful for diagnosing why a server is not being confirmed.

To allow remote access, set the environment variable `DEBUG_VERIFY_TOKEN` and pass `?token=<value>` in the URL.

---

## Java command-line client (`MeshNode.java`)

A zero-dependency Java 8+ client that pushes files from the local `files/` directory to peers without needing a web browser. It reads the same `servers.txt`, `info/`, and `accounts/` directories as the PHP node.

### Compile

```bash
javac MeshNode.java
```

### Run

```bash
# Interactive menu
java MeshNode

# Push all files non-interactively
java MeshNode --all

# Push specific files
java MeshNode --file c33c4f0db1d7a3fc....jpg abc123....png
```

The interactive menu offers: push all files, choose specific files by number, list local files with metadata, list peer servers, and view account info.

The client mirrors the PHP verification logic: a push is considered confirmed if either the peer echoed back the correct SHA-256 hash or the subsequent HTTP HEAD/GET check returns 2xx. Confirmed servers are written back to the local account JSON using the same deduplication rules as the PHP node.

---

## Account JSON structure

```json
{
  "username":          "alice",
  "public_key":        "...",
  "mail":              "alice@example.com",
  "about_me":          "Just a user.",
  "user_server":       "http://alice.example.com/node",
  "balance":           3,
  "files": [
    {
      "hash":              "c33c4f0db1d7a3fc...",
      "original_filename": "photo.jpg",
      "extension":         "jpg",
      "size":              204800,
      "description":       "Holiday photo",
      "servers":           ["http://peer1.example.com/node"],
      "dates":             { "http://peer1.example.com/node": "2025-06-01T12:00:00+00:00" },
      "uploaded_at":       "2025-06-01T12:00:00+00:00",
      "last_verified":     "2025-06-01T12:05:00+00:00"
    }
  ],
  "servers": {
    "<sha256_url_key>": "http://peer1.example.com/node"
  },
  "dates": {
    "<sha256_url_key>": "2025-06-01T12:00:00+00:00"
  },
  "server_ip_hashes":  ["<sha256_of_ip>"],
  "created_at":        "2025-01-01T00:00:00+00:00",
  "updated_at":        "2025-06-01T12:05:00+00:00"
}
```

The `password` field (SHA-512 hash) is present in the stored file but is never returned by any API endpoint.

The `balance` field increments by 1 each time a new server is confirmed for the first time. Duplicate servers (same URL key or same resolved IP) do not increment the balance.

---

## Security notes

- Passwords are stored as SHA-512 hashes. There is no salting — consider adding application-level salting if you deploy this publicly.
- SSL certificate verification is disabled for inter-node HTTP calls (`verify_peer = false`). This is intentional for self-signed / private network deployments; enable it if your peers use valid certificates.
- The session nonce rotates daily (based on UTC date). A nonce captured today stops working at midnight UTC.
- PHP files are blocked from being stored or replicated.
- The `account_sync` endpoint never creates accounts — it can only overwrite an account that was registered locally — so a rogue peer cannot inject arbitrary users.