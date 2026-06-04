# MeshBlockchain

A peer-to-peer blockchain where **proof-of-work is earned by storing files** across a mesh network of servers — not by burning CPU cycles. Built on top of the MeshRank protocol, compatible with **Java 8+**, zero external libraries.

---

## How it works

### The network

A `servers.txt` file lists the mesh nodes. Each node exposes:

```
/files.txt          — one filename per line  (e.g. <sha256>.jpg)
/files/<filename>   — the actual file
/info/<hash>.json   — metadata JSON, must contain a "public_key" field
```

Filenames are their own integrity proof: the name (without extension) is the SHA-256 hash of the file's content.

### Proof-of-Work — "Prove You Store Everything"

To earn the right to add a block, you must:

1. **Fetch every `files.txt`** from every server listed in `servers.txt`.
2. **Download every file** listed across all servers, plus each file's `/info/<hash>.json`.
3. **Verify integrity** — each file's SHA-256 must match its filename.
4. **Pass majority attestation** — more than 50% of reachable servers must confirm that all their listed files are present and intact on your end.

Only after all four steps pass will a block be created and saved.

### Block filename rule

Each block is saved as `json_blocks/<name>.json` where `<name>` must be a filename found in at least one server's `files.txt` (without extension). Rules:

- A filename can only be used once — **existing blocks cannot be overwritten**.
- Once every known filename has a corresponding block, **no new blocks can be added**.

### Rewards

Every successfully mined block mints **50 coins** to the miner's public key. Balances are computed by summing the `reward` field across all blocks where `miner_public_key` matches.

### Keys and signatures

- An **RSA-2048 key pair** is generated on first run and saved to `keys/`.
- Every block is signed with `SHA256withRSA` over its canonical data fields.
- The chain verifier re-computes each hash and signature to detect tampering.

---

## Directory layout

```
project/
├── servers.txt              ← list of mesh server URLs (one per line)
├── MeshBlockchain.java      ← source file
│
├── keys/
│   ├── private.key          ← your RSA private key (keep secret)
│   └── public.key           ← your RSA public key
│
├── cache/
│   ├── files/               ← downloaded mesh files (reused across runs)
│   └── info/                ← downloaded info JSONs
│
└── json_blocks/             ← the blockchain (one .json file per block)
```

All directories are created automatically on first run.

---

## Getting started

### Requirements

- Java 8 or later
- No external libraries needed

### Compile

```bash
javac MeshBlockchain.java
```

### Run

```bash
java MeshBlockchain
```

### servers.txt format

One server URL per line. Lines starting with `#` are ignored.

```
http://node1.example.com
http://node2.example.com/mesh/1
# this line is a comment
https://node3.example.com/index.php
```

The program normalises URLs automatically — trailing slashes and filename segments (like `index.php`) are stripped.

---

## Menu options

```
1. Mine a block       — download all files, prove storage, write a block
2. Show blockchain    — list all blocks with key fields
3. Check balance      — look up coin balance for any public key
4. Verify chain       — re-verify every block's hash, previous-hash link, and signature
5. Show my public key — print your full Base64-encoded RSA public key
0. Exit
```

---

## Block JSON structure

```json
{
  "index": 1,
  "timestamp": 1716000000000,
  "timestamp_iso": "2026-05-18T00:00:00Z",
  "block_filename": "abc123def456...jpg",
  "file_hash": "abc123def456...",
  "previous_hash": "0000000000000000000000000000000000000000000000000000000000000000",
  "miner_public_key": "<Base64-encoded RSA-2048 public key>",
  "server_public_key": "<majority public_key from server info JSONs>",
  "reward": 50,
  "signature": "<Base64-encoded SHA256withRSA signature>",
  "block_hash": "<SHA-256 of all fields above>"
}
```

Field notes:

| Field | Description |
|---|---|
| `index` | Sequential block number, starting at 1 |
| `block_filename` | The mesh filename this block is named after |
| `file_hash` | SHA-256 of the file (also the base of the filename) |
| `previous_hash` | Hash of the preceding block (genesis uses all zeros) |
| `miner_public_key` | Base64 RSA public key of the miner |
| `server_public_key` | Majority `public_key` value from the file's server info JSONs |
| `reward` | Coins awarded to the miner for this block |
| `signature` | RSA signature over all fields except `signature` and `block_hash` |
| `block_hash` | SHA-256 of the canonical JSON (all fields except `block_hash`) |

---

## Security notes

- **Keep `keys/private.key` secret.** Anyone with your private key can forge blocks signed as you.
- IP addresses are never stored in any block field.
- The chain is append-only: block files are never overwritten. Attempting to write a duplicate filename is silently rejected.
- Chain integrity can be fully verified offline with option 4 — no network access needed.

---

## Relationship to MeshRank

MeshBlockchain is built on the same mesh network protocol as [MeshRank](MeshRank.java). Where MeshRank *ranks* servers by how many valid files they store, MeshBlockchain *rewards* users who replicate that storage locally. The same server layout, file integrity rules, and majority-vote logic apply to both programs.
