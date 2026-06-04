# MEENTO Feed — `feed_full.php`

A single-file PHP media feed with TikTok-style vertical scroll, likes, and comments. Connects to a MySQL database and serves images, videos, and audio files hosted across multiple servers.

---

## Requirements

- PHP 7.4+ with PDO and PDO_MySQL
- MySQL 5.7+ or MariaDB 10.3+
- A web server (Apache, Nginx, etc.)

---

## Setup

1. Copy `feed_full.php` to your web server root.
2. Open the file and update the database credentials near the top:

```php
$db_host = '127.0.0.1';
$db_user = 'root';
$db_pass = '';
$db_name = 'file_indexer';
```

3. Visit the page in your browser. All required tables are created automatically on the first load.

---

## Database Tables

The file auto-creates all five tables if they don't exist.

| Table | Purpose |
|---|---|
| `file_registry` | Master list of indexed media files |
| `users_preferences` | Files a user watched for 10+ seconds |
| `file_likes` | One row per user+file like |
| `file_comments` | User comments per file |

### `file_registry` — expected schema

Each row must have these columns for the feed to work:

| Column | Type | Description |
|---|---|---|
| `file_hash` | VARCHAR(255) | Unique file identifier (used to build the URL) |
| `original_filename` | VARCHAR(255) | Display name shown on the card |
| `file_size` | INT | Size in bytes |
| `extension` | VARCHAR(50) | File extension without the dot (e.g. `mp4`, `jpg`) |
| `description` | TEXT | Optional caption shown on the card |
| `hosts` | TEXT | JSON array or comma-separated list of host base URLs |

File URLs are resolved as: `{host}/files/{file_hash}.{extension}`

---

## Supported Media Types

| Type | Extensions |
|---|---|
| Image | `jpg` `jpeg` `png` `gif` `webp` `avif` `svg` `bmp` `tiff` |
| Video | `mp4` `webm` `ogg` `mov` `m4v` |
| Audio | `mp3` `wav` `ogg` `flac` `aac` `m4a` `opus` |

Files with any other extension are filtered out of the feed automatically.

---

## API Endpoints

All endpoints are on `feed_full.php` via the `?action=` query parameter.

### Feed

**`GET ?action=feed&page=N`**
Returns a paginated, session-seeded random page of media entries.

- `page` — page number (default: 1)
- `reset=1` — generates a new shuffle seed (sent automatically on page load)
- `seen=["hash1","hash2"]` — JSON array of hashes to exclude

```json
{
  "entries": [ { "file_hash": "...", "original_filename": "...", ... } ],
  "total": 320,
  "page": 1
}
```

**`GET ?action=random`**
Returns one random media entry (excluding `seen` hashes). Used to inject a surprise card between feed pages.

---

### Likes

**`POST ?action=toggle_like`**
Toggles a like for the current session user. Like is added if not present, removed if it is.

Request body:
```json
{ "file_hash": "abc123" }
```

Response:
```json
{ "ok": true, "liked": true, "count": 42 }
```

**`GET ?action=get_likes&file_hash=X`**
Returns the like count and whether the current user has liked the file.

```json
{ "ok": true, "count": 42, "liked": false }
```

---

### Comments

**`POST ?action=add_comment`**
Adds a comment. Maximum 500 characters.

Request body:
```json
{ "file_hash": "abc123", "comment": "Great file!" }
```

Response:
```json
{
  "ok": true,
  "comment": {
    "id": 7,
    "user_id": "usr_...",
    "comment": "Great file!",
    "created_at": "2025-06-01 12:00:00",
    "is_mine": true,
    "user_label": "You"
  }
}
```

**`GET ?action=get_comments&file_hash=X&page=N`**
Returns paginated comments for a file, newest first. 20 per page.

```json
{
  "ok": true,
  "comments": [ { "id": 7, "user_label": "You", "comment": "...", "created_at": "...", "is_mine": true } ],
  "total": 5,
  "page": 1
}
```

---

### Preferences (watch tracking)

**`POST ?action=record_preference`**
Called automatically after a user watches a file for 10 seconds. Saves it to `users_preferences`.

```json
{ "file_hash": "abc123" }
```

---

### Debug

**`GET ?action=debug`**
Returns a JSON summary of the database state: row counts, extension breakdown, and sample rows. Remove or password-protect this in production.

---

## How the Feed Works

1. On page load, the PHP generates a random seed stored in `$_SESSION['feed_seed']` and shuffles the media table with `ORDER BY RAND(seed)`. This keeps the order stable for pagination within a session, but different on each new visit.
2. The browser tracks all shown `file_hash` values in `sessionStorage` and sends them as a `seen` parameter so duplicates are never shown.
3. A random extra card is injected between pages to keep the feed feeling fresh.
4. When a card has been visible for 10 seconds, a preference record is saved automatically.

## Multi-host Fallback

Each file can have multiple hosts. The `hosts` column accepts either a JSON array or a comma-separated string:

```json
["https://cdn1.example.com", "https://cdn2.example.com"]
```

The browser shuffles the host list and tries each one in order on load error, so if one CDN is down the file still plays from the next available host.

---

## Session & User Identity

There is no login system. Each visitor gets a random `user_id` stored in the PHP session:

```
usr_4f3a9b1c2d8e0f7a
```

This ID is used to track likes, comments, and watch preferences. It persists for the duration of the browser session.

---

## UI Features

- Vertical scroll feed with snap-to-card, one file per screen
- Like button (♥) with live count — turns red when active
- Comment sheet — slides up from the bottom, paginated, with live posting
- Share button — uses the Web Share API on mobile, copies a link on desktop
- Download button — triggers a native browser download
- Watch ring — a circular progress indicator that fills over 10 seconds
- Multi-host error recovery — silent fallback with a brief error badge
- Audio visualizer — animated disc and waveform for audio files