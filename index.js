const http = require('http');
const https = require('https');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

// ═══════════════════════════════════════════════════════════════════════════════
//  MESH NODE — file replication over HTTP/HTTPS (Node.js Port)
//  Single-file application, zero external dependencies
// ═══════════════════════════════════════════════════════════════════════════════

const DIRS = {
    files: path.join(__dirname, 'files'),
    info: path.join(__dirname, 'info')
};

const FILES = {
    index: path.join(__dirname, 'files.txt'),
    servers: path.join(__dirname, 'servers.txt'),
    pubKey: path.join(__dirname, 'public_key.txt'),
    nodeInfo: path.join(__dirname, 'node_info.txt'),
    dataJson: path.join(__dirname, 'data.json')
};

const MAX_COMMENT = 1024;
const BLOCKED_EXTS = ['php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar'];

// ── Init directories & files ──────────────────────────────────────────────────
function initDirs() {
    Object.values(DIRS).forEach(dir => {
        if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
    });
    if (!fs.existsSync(FILES.index)) fs.writeFileSync(FILES.index, '');
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function safeExt(filename) {
    let ext = (filename || '').split('.').pop().toLowerCase();
    return ext.replace(/[^a-z0-9]/g, '');
}

function readOptionalFile(filePath) {
    try {
        return fs.readFileSync(filePath, 'utf8').trim();
    } catch (e) {
        return '';
    }
}

function loadServers() {
    try {
        const content = fs.readFileSync(FILES.servers, 'utf8');
        const lines = content.split('\n').map(l => l.trim()).filter(Boolean);
        const seen = new Set();
        const out = [];

        for (let line of lines) {
            if (!line.startsWith('http://') && !line.startsWith('https://')) {
                line = 'http://' + line;
            }
            line = line.replace(/\/$/, '');
            if (!seen.has(line)) {
                seen.add(line);
                out.push(line);
            }
        }
        return out;
    } catch (e) {
        return [];
    }
}

function appendIndex(entry) {
    try {
        const content = fs.readFileSync(FILES.index, 'utf8');
        const lines = content.split('\n');
        if (!lines.includes(entry)) {
            fs.appendFileSync(FILES.index, entry + '\n');
        }
    } catch (e) {}
}

function appendFilesAndDataJson(hash, infoMap) {
    const ext = infoMap.extension;
    const baseName = ext ? `${hash}.${ext}` : hash;
    appendIndex(baseName);

    try {
        let arr = [];
        if (fs.existsSync(FILES.dataJson)) {
            const content = fs.readFileSync(FILES.dataJson, 'utf8').trim();
            if (content) arr = JSON.parse(content);
        }
        
        const entry = { hash, ...infoMap };
        arr.push(entry);
        fs.writeFileSync(FILES.dataJson, JSON.stringify(arr, null, 4));
    } catch (e) {}
}

function sendJson(res, data, statusCode = 200) {
    res.writeHead(statusCode, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify(data));
}

// ── Custom Multipart Parser ───────────────────────────────────────────────────
function parseMultipart(req) {
    return new Promise((resolve, reject) => {
        const contentType = req.headers['content-type'] || '';
        const match = contentType.match(/boundary=(?:"([^"]+)"|([^;]+))/i);
        if (!match) return reject('Invalid multipart request');
        
        const boundaryBuffer = Buffer.from('--' + (match[1] || match[2]));
        const chunks = [];
        
        req.on('data', chunk => chunks.push(chunk));
        req.on('error', err => reject(err));
        req.on('end', () => {
            const body = Buffer.concat(chunks);
            const fields = {};
            let file = null;
            let pos = 0;

            while (pos < body.length) {
                const start = body.indexOf(boundaryBuffer, pos);
                if (start === -1) break;
                
                pos = start + boundaryBuffer.length;
                if (body[pos] === 45 && body[pos + 1] === 45) break; // -- (End of form)
                pos += 2; // \r\n

                const headerEnd = body.indexOf(Buffer.from('\r\n\r\n'), pos);
                if (headerEnd === -1) break;
                
                const headerStr = body.slice(pos, headerEnd).toString();
                pos = headerEnd + 4;

                const nextBoundary = body.indexOf(boundaryBuffer, pos);
                if (nextBoundary === -1) break;
                
                const contentBuffer = body.slice(pos, nextBoundary - 2); // -2 for \r\n
                pos = nextBoundary;

                const nameMatch = headerStr.match(/name="([^"]+)"/);
                if (nameMatch) {
                    const name = nameMatch[1];
                    const filenameMatch = headerStr.match(/filename="([^"]+)"/);
                    if (filenameMatch) {
                        file = {
                            filename: filenameMatch[1],
                            data: contentBuffer
                        };
                    } else {
                        fields[name] = contentBuffer.toString();
                    }
                }
            }
            resolve({ fields, file });
        });
    });
}

// ── Core Storage Logic ────────────────────────────────────────────────────────
function storeLocally(fileData, originalName, description, pubKey, nodeInfo) {
    const ext = safeExt(originalName);
    if (BLOCKED_EXTS.includes(ext)) {
        return { ok: false, error: 'PHP files are not accepted.' };
    }

    const hash = crypto.createHash('sha256').update(fileData).digest('hex');
    const baseName = ext ? `${hash}.${ext}` : hash;
    const destFile = path.join(DIRS.files, baseName);
    const destInfo = path.join(DIRS.info, `${hash}.json`);

    const existed = fs.existsSync(destFile);

    if (!existed) {
        fs.writeFileSync(destFile, fileData);
        appendIndex(baseName);
    }

    if (!fs.existsSync(destInfo)) {
        const stats = fs.statSync(destFile);
        const info = {
            original_filename: originalName,
            size: stats.size,
            extension: ext,
            public_key: pubKey,
            node_info: nodeInfo,
            description: description
        };
        fs.writeFileSync(destInfo, JSON.stringify(info, null, 2));
        appendFilesAndDataJson(hash, info);
    }

    return { ok: true, hash, filename: baseName, existed };
}

// ── Peer Replication ──────────────────────────────────────────────────────────
function pushToServer(serverUrl, filePath, filename, description, pubKey, nodeInfo) {
    try {
        const fileData = fs.readFileSync(filePath);
        const crlf = '\r\n';
        const boundary = '----MeshNodeBoundary' + crypto.randomBytes(8).toString('hex');
        
        const fields = {
            description: description || '',
            public_key: pubKey || '',
            node_info: nodeInfo || '',
            peer_push: '1'
        };

        let payload = Buffer.alloc(0);
        const append = (str) => { payload = Buffer.concat([payload, Buffer.from(str)]); };
        const appendBuf = (buf) => { payload = Buffer.concat([payload, buf]); };

        for (const [key, val] of Object.entries(fields)) {
            append(`--${boundary}${crlf}`);
            append(`Content-Disposition: form-data; name="${key}"${crlf}${crlf}`);
            append(`${val}${crlf}`);
        }

        append(`--${boundary}${crlf}`);
        append(`Content-Disposition: form-data; name="file"; filename="${filename}"${crlf}`);
        append(`Content-Type: application/octet-stream${crlf}${crlf}`);
        appendBuf(fileData);
        append(`${crlf}--${boundary}--${crlf}`);

        const urlObj = new URL(serverUrl);
        const client = urlObj.protocol === 'https:' ? https : http;
        
        const req = client.request(serverUrl + '/', {
            method: 'POST',
            headers: {
                'Content-Type': `multipart/form-data; boundary=${boundary}`,
                'Content-Length': payload.length,
                'X-Mesh-Node': '1'
            },
            rejectUnauthorized: false // Match Go insecure skip verify
        });

        req.on('error', () => {}); // Silently fail on unreachable peers
        req.write(payload);
        req.end();

    } catch (e) {
        // Skip silently
    }
}

// ── HTTP Request Handler ──────────────────────────────────────────────────────
const server = http.createServer(async (req, res) => {
    res.setHeader('Access-Control-Allow-Origin', '*');

    const url = new URL(req.url, `http://${req.headers.host || 'localhost'}`);

    // 1. JSON UI Endpoint
    if (url.searchParams.get('node_status') === '1') {
        const pkContent = readOptionalFile(FILES.pubKey);
        const niFound = fs.existsSync(FILES.nodeInfo) && readOptionalFile(FILES.nodeInfo) !== '';
        return sendJson(res, {
            public_key: pkContent !== '',
            node_info: niFound,
            peers: loadServers().length
        });
    }

    // 2. Serve uploaded files securely
    if (req.method === 'GET' && url.pathname.startsWith('/files/')) {
        const filename = url.pathname.replace('/files/', '');
        const safePath = path.join(DIRS.files, filename);
        
        // Prevent path traversal
        if (safePath.startsWith(DIRS.files) && fs.existsSync(safePath)) {
            const ext = safeExt(filename);
            const contentTypes = {
                'jpg': 'image/jpeg', 'jpeg': 'image/jpeg', 'png': 'image/png',
                'gif': 'image/gif', 'pdf': 'application/pdf', 'txt': 'text/plain'
            };
            res.writeHead(200, { 'Content-Type': contentTypes[ext] || 'application/octet-stream' });
            return fs.createReadStream(safePath).pipe(res);
        } else {
            res.writeHead(404);
            return res.end('File not found');
        }
    }

    // 3. GET request -> serve UI
    if (req.method === 'GET') {
        res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
        return res.end(htmlTemplate);
    }

    // 4. POST request -> upload logic
    if (req.method === 'POST') {
        try {
            const { fields, file } = await parseMultipart(req);
            
            if (!file) return sendJson(res, { ok: false, error: 'Missing file payload' }, 400);

            const isPeerPush = fields.peer_push === '1';
            const descField = isPeerPush ? 'description' : 'comment';
            let description = (fields[descField] || '').substring(0, MAX_COMMENT);

            let pubKey = '';
            if (isPeerPush) {
                pubKey = (fields.public_key || '').trim();
            } else {
                pubKey = readOptionalFile(FILES.pubKey);
                if (!pubKey && fields.public_key) {
                    pubKey = fields.public_key.trim().substring(0, 512);
                }
            }

            let nodeInfo = isPeerPush ? (fields.node_info || '').trim() : readOptionalFile(FILES.nodeInfo);

            const result = storeLocally(file.data, file.filename, description, pubKey, nodeInfo);
            if (!result.ok) return sendJson(res, result, 500);

            // A. Originating from PEER -> Just respond ok.
            if (isPeerPush) return sendJson(res, result, 200);

            // B. Originating from BROWSER -> Send result, then push to peers
            const servers = loadServers();
            const pkMsg = pubKey ? '✓ included' : '— not found';
            const niMsg = nodeInfo ? '✓ included' : '— not found';

            sendJson(res, {
                ok: true,
                hash: result.hash,
                filename: result.filename,
                existed: result.existed,
                public_key: pkMsg,
                node_info: niMsg,
                peers_total: servers.length,
                peers: []
            });

            // Fire and forget peer push in background
            if (!result.existed && servers.length > 0) {
                const filePath = path.join(DIRS.files, result.filename);
                servers.forEach(serverUrl => {
                    pushToServer(serverUrl, filePath, file.filename, description, pubKey, nodeInfo);
                });
            }
        } catch (err) {
            return sendJson(res, { ok: false, error: 'Upload error: ' + err.toString() }, 400);
        }
    }
});

// ── Application Entry ─────────────────────────────────────────────────────────
initDirs();
const PORT = 8080;
server.listen(PORT, () => {
    console.log(`Mesh Node (Node.js) running on http://localhost:${PORT}`);
});

// ── HTML Frontend String Literal ──────────────────────────────────────────────