# Mesh Node

A single-file PHP application for decentralized file replication over HTTP/HTTPS. Upload a file to one node and it is automatically pushed to all known peer nodes in the background.

---

## Features

- **Single-file deployment** — the entire application lives in `index.php`
- **Content-addressed storage** — files are saved as `<sha256>.ext`, so duplicates are automatically deduplicated
- **Peer replication** — on upload, the file is propagated to every peer listed in `servers.txt`
- **Structured metadata** — each file gets a companion JSON record in `info/` and a global `data.json` index
- **Optional node identity** — attach a public key and node description to every file you upload
- **PHP file blocking** — `.php`, `.phar`, and related extensions are rejected at both the server and browser level
- **No external dependencies** — uses only built-in PHP stream functions (no cURL, no Composer)

---

## Requirements

- PHP 7.0 or later
- A web server (Apache, Nginx, Caddy, etc.) configured to execute PHP

---

## Installation

1. Copy `index.php` into a web-accessible directory.
2. Make sure PHP can write to that directory (the app will create `files/` and `info/` automatically on first run).
3. Open the URL in a browser.

---

## Configuration

All configuration is done through plain text files placed alongside `index.php`.

| File | Purpose |
|---|---|
| `servers.txt` | One peer URL per line (e.g. `https://node2.example.com`). Files are pushed to every peer on upload. |
| `public_key.txt` | Optional. Contents are attached to every file uploaded from this node as the `public_key` metadata field. |
| `node_info.txt` | Optional. A human-readable description of this node, attached to every upload as the `node_info` metadata field. |

Lines in `servers.txt` that lack a scheme default to `http://`. Duplicate URLs are ignored.

---

## Directory Structure

After the first upload the working directory will look like this:

```
index.php
servers.txt          ← peer list (you create this)
public_key.txt       ← optional node identity
node_info.txt        ← optional node description
files.txt            ← auto-generated index of stored filenames
data.json            ← auto-generated JSON array of all file metadata
files/
    <sha256>.<ext>   ← stored files
info/
    <sha256>.json    ← per-file metadata records
```

---

## File Metadata

Every stored file gets a JSON record written to `info/<sha256>.json` with the following fixed schema:

```json
{
  "original_filename": "photo.jpg",
  "size":              204800,
  "extension":         "jpg",
  "public_key":        "<contents of public_key.txt, or empty string>",
  "node_info":         "<contents of node_info.txt, or empty string>",
  "description":       "<user comment, or empty string>"
}
```

The same record is also appended to the root `data.json` array (with a `hash` field prepended) for easy bulk querying.

---

## API Endpoints

The application exposes three HTTP endpoints via `index.php`.

### `GET /?node_status=1`

Returns a JSON object describing the current node state.

```json
{
  "public_key": true,
  "node_info":  false,
  "peers":      3
}
```

### `POST /` — Browser upload

Accepts a `multipart/form-data` POST with:

| Field | Type | Description |
|---|---|---|
| `file` | file | The file to store |
| `comment` | string | Optional description (max 1 KB) |

Returns JSON:

```json
{
  "ok":          true,
  "hash":        "<sha256>",
  "filename":    "<sha256>.<ext>",
  "existed":     false,
  "public_key":  "✓ included",
  "node_info":   "— not found",
  "peers_total": 2
}
```

The response is flushed to the browser immediately; peer propagation happens in the background after the connection closes.

### `POST /` — Peer push (internal)

Sent automatically by other mesh nodes. Identical to the browser upload but includes the field `peer_push=1` and forwards `public_key` and `node_info` from the originating node. Never re-propagates further.

---

## Security Notes

- **PHP execution is blocked** — files with extensions `php`, `php3`, `php4`, `php5`, `php7`, `phtml`, and `phar` are rejected.
- **SSL peer verification is disabled** for outgoing peer-push requests, allowing self-signed certificates on peer nodes.
- **No authentication** — any client that can reach the URL can upload files. Restrict access at the web server level if needed (e.g. HTTP Basic Auth or IP allowlist).
- Files are never overwritten. If the same content is uploaded twice (identical SHA-256), the existing record is returned unchanged and no re-propagation occurs.

---

## Companion Page

The UI links to a `view.php` search page (not included in `index.php`). That file is a separate component for browsing and searching stored files.
