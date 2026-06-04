package main

import (
	"bytes"
	"crypto/sha256"
	"crypto/tls"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"io/ioutil"
	"log"
	"mime/multipart"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"sync"
)

// ═══════════════════════════════════════════════════════════════════════════════
//  MESH NODE — file replication over HTTP/HTTPS (Golang Port)
//  Single-file pre-Go 1.16 application
// ═══════════════════════════════════════════════════════════════════════════════

const (
	FilesDir     = "./files"
	InfoDir      = "./info"
	FilesIndex   = "./files.txt"
	ServersFile  = "./servers.txt"
	PubKeyFile   = "./public_key.txt"
	NodeInfoFile = "./node_info.txt"
	DataJsonFile = "./data.json"
	MaxComment   = 1024
)

var blockedExts = []string{"php", "php3", "php4", "php5", "php7", "phtml", "phar"}

var dataJsonMu sync.Mutex
var filesTxtMu sync.Mutex

// ── Init directories & files ──────────────────────────────────────────────────
func initDirs() {
	for _, dir := range []string{FilesDir, InfoDir} {
		if _, err := os.Stat(dir); os.IsNotExist(err) {
			os.MkdirAll(dir, 0755)
		}
	}
	if _, err := os.Stat(FilesIndex); os.IsNotExist(err) {
		ioutil.WriteFile(FilesIndex, []byte(""), 0644)
	}
}

// ── Helpers ───────────────────────────────────────────────────────────────────

func safeExt(filename string) string {
	ext := strings.ToLower(filepath.Ext(filename))
	ext = strings.TrimPrefix(ext, ".")
	re := regexp.MustCompile("[^a-z0-9]")
	return re.ReplaceAllString(ext, "")
}

func blockedExt(ext string) bool {
	for _, e := range blockedExts {
		if ext == e {
			return true
		}
	}
	return false
}

func jsonOut(w http.ResponseWriter, data interface{}, code int) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(code)
	enc := json.NewEncoder(w)
	enc.SetEscapeHTML(false)
	enc.Encode(data)
}

func readOptionalFile(path string) string {
	if b, err := ioutil.ReadFile(path); err == nil {
		return strings.TrimSpace(string(b))
	}
	return ""
}

func loadServers() []string {
	content, err := ioutil.ReadFile(ServersFile)
	if err != nil {
		return nil
	}
	lines := strings.Split(string(content), "\n")
	var out []string
	seen := make(map[string]bool)

	for _, l := range lines {
		line := strings.TrimSpace(l)
		if line == "" {
			continue
		}
		if !strings.HasPrefix(strings.ToLower(line), "http://") && !strings.HasPrefix(strings.ToLower(line), "https://") {
			line = "http://" + line
		}
		line = strings.TrimRight(line, "/")
		if !seen[line] {
			seen[line] = true
			out = append(out, line)
		}
	}
	return out
}

func appendIndex(entry string) {
	filesTxtMu.Lock()
	defer filesTxtMu.Unlock()

	content, _ := ioutil.ReadFile(FilesIndex)
	lines := strings.Split(string(content), "\n")
	for _, l := range lines {
		if strings.TrimSpace(l) == entry {
			return
		}
	}

	f, err := os.OpenFile(FilesIndex, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0644)
	if err == nil {
		defer f.Close()
		f.WriteString(entry + "\n")
	}
}

func appendFilesAndDataJson(hash string, infoMap map[string]interface{}) {
	dataJsonMu.Lock()
	defer dataJsonMu.Unlock()

	// 1. Ensure Files.txt
	ext, _ := infoMap["extension"].(string)
	baseName := hash
	if ext != "" {
		baseName = hash + "." + ext
	}
	// appendIndex already deduplicates, but we release mutex to call it to avoid deadlock
	go appendIndex(baseName) 

	// 2. data.json
	entry := map[string]interface{}{"hash": hash}
	for k, v := range infoMap {
		entry[k] = v
	}

	var arr []map[string]interface{}
	content, err := ioutil.ReadFile(DataJsonFile)
	if err == nil && len(bytes.TrimSpace(content)) > 0 {
		json.Unmarshal(content, &arr)
	}
	
	// Append & write back
	arr = append(arr, entry)
	outBytes, _ := json.MarshalIndent(arr, "", "    ")
	ioutil.WriteFile(DataJsonFile, outBytes, 0644)
}

// ── Core Storage Logic ────────────────────────────────────────────────────────

func storeLocally(file multipart.File, originalName, description, pubKey, nodeInfo string) map[string]interface{} {
	ext := safeExt(originalName)
	if blockedExt(ext) {
		return map[string]interface{}{"ok": false, "error": "PHP files are not accepted."}
	}

	// Hash the file
	file.Seek(0, 0)
	hasher := sha256.New()
	io.Copy(hasher, file)
	hash := hex.EncodeToString(hasher.Sum(nil))

	baseName := hash
	if ext != "" {
		baseName = hash + "." + ext
	}

	destFile := filepath.Join(FilesDir, baseName)
	destInfo := filepath.Join(InfoDir, hash+".json")

	_, err := os.Stat(destFile)
	existed := !os.IsNotExist(err)

	if !existed {
		file.Seek(0, 0)
		out, err := os.Create(destFile)
		if err != nil {
			return map[string]interface{}{"ok": false, "error": "Could not write file to disk."}
		}
		io.Copy(out, file)
		out.Close()
		appendIndex(baseName)
	}

	// Always write info if absent
	if _, err := os.Stat(destInfo); os.IsNotExist(err) {
		stat, _ := os.Stat(destFile)
		size := int64(0)
		if stat != nil {
			size = stat.Size()
		}

		info := map[string]interface{}{
			"original_filename": originalName,
			"size":              size,
			"extension":         ext,
			"public_key":        pubKey,
			"node_info":         nodeInfo,
			"description":       description,
		}
		
		infoJSON, _ := json.MarshalIndent(info, "", "  ")
		ioutil.WriteFile(destInfo, infoJSON, 0644)

		// Also update data.json
		appendFilesAndDataJson(hash, info)
	}

	return map[string]interface{}{
		"ok":       true,
		"hash":     hash,
		"filename": baseName,
		"existed":  existed,
	}
}

// ── Peer Replication ──────────────────────────────────────────────────────────

func pushToServer(serverURL, filePath, filename, description, pubKey, nodeInfo string) {
	file, err := os.Open(filePath)
	if err != nil {
		return
	}
	defer file.Close()

	body := &bytes.Buffer{}
	writer := multipart.NewWriter(body)

	part, err := writer.CreateFormFile("file", filename)
	if err == nil {
		io.Copy(part, file)
	}

	_ = writer.WriteField("description", description)
	_ = writer.WriteField("public_key", pubKey)
	_ = writer.WriteField("node_info", nodeInfo)
	_ = writer.WriteField("peer_push", "1")
	writer.Close()

	req, err := http.NewRequest("POST", serverURL+"/", body)
	if err != nil {
		return
	}
	req.Header.Set("Content-Type", writer.FormDataContentType())
	req.Header.Set("X-Mesh-Node", "1")

	// Pre-Go 1.16 Insecure skip verify
	tr := &http.Transport{
		TLSClientConfig: &tls.Config{InsecureSkipVerify: true},
	}
	client := &http.Client{Transport: tr, Timeout: 20 * 1000000000} // 20s
	
	resp, err := client.Do(req)
	if err == nil {
		resp.Body.Close()
	}
}

// ── HTTP Handlers ─────────────────────────────────────────────────────────────

func handleRequest(w http.ResponseWriter, r *http.Request) {
	// CORS / Options (for safety)
	w.Header().Set("Access-Control-Allow-Origin", "*")

	// 1. JSON UI Endpoint
	if r.URL.Query().Get("node_status") == "1" {
		pkContent := readOptionalFile(PubKeyFile)
		_, err := os.Stat(NodeInfoFile)
		niFound := !os.IsNotExist(err) && readOptionalFile(NodeInfoFile) != ""
		
		jsonOut(w, map[string]interface{}{
			"public_key": pkContent != "",
			"node_info":  niFound,
			"peers":      len(loadServers()),
		}, 200)
		return
	}

	// 2. GET request -> serve UI
	if r.Method == http.MethodGet {
		w.Header().Set("Content-Type", "text/html; charset=utf-8")
		w.Write([]byte(htmlTemplate))
		return
	}

	// 3. POST request -> upload logic
	if r.Method == http.MethodPost {
		err := r.ParseMultipartForm(50 << 20) // 50MB memory buffer
		if err != nil {
			jsonOut(w, map[string]interface{}{"ok": false, "error": "Upload error: " + err.Error()}, 400)
			return
		}

		file, header, err := r.FormFile("file")
		if err != nil {
			jsonOut(w, map[string]interface{}{"ok": false, "error": "Missing file payload"}, 400)
			return
		}
		defer file.Close()

		if blockedExt(safeExt(header.Filename)) {
			jsonOut(w, map[string]interface{}{"ok": false, "error": "PHP files are not accepted."}, 400)
			return
		}

		isPeerPush := r.FormValue("peer_push") == "1"
		
		descField := "comment"
		if isPeerPush { descField = "description" }
		
		description := r.FormValue(descField)
		if len(description) > MaxComment {
			description = description[:MaxComment]
		}

		pubKey := ""
		if isPeerPush {
			pubKey = strings.TrimSpace(r.FormValue("public_key"))
		} else {
			pubKey = readOptionalFile(PubKeyFile)
			if pubKey == "" && r.FormValue("public_key") != "" {
				rawPk := strings.TrimSpace(r.FormValue("public_key"))
				if len(rawPk) > 512 { rawPk = rawPk[:512] }
				pubKey = rawPk
			}
		}

		nodeInfo := ""
		if isPeerPush {
			nodeInfo = strings.TrimSpace(r.FormValue("node_info"))
		} else {
			nodeInfo = readOptionalFile(NodeInfoFile)
		}

		result := storeLocally(file, header.Filename, description, pubKey, nodeInfo)
		if !result["ok"].(bool) {
			jsonOut(w, result, 500)
			return
		}

		// A. Originating from PEER -> Just respond ok.
		if isPeerPush {
			code := 200
			if !result["ok"].(bool) { code = 500 }
			jsonOut(w, result, code)
			return
		}

		// B. Originating from BROWSER -> Send result, then push to peers
		servers := loadServers()
		existed := result["existed"].(bool)
		
		pkMsg, niMsg := "— not found", "— not found"
		if pubKey != "" { pkMsg = "✓ included" }
		if nodeInfo != "" { niMsg = "✓ included" }

		response := map[string]interface{}{
			"ok":          true,
			"hash":        result["hash"],
			"filename":    result["filename"],
			"existed":     existed,
			"public_key":  pkMsg,
			"node_info":   niMsg,
			"peers_total": len(servers),
			"peers":       []interface{}{},
		}

		jsonOut(w, response, 200)

		// Fire goroutine to forward to network peers in background
		if !existed && len(servers) > 0 {
			filePath := filepath.Join(FilesDir, result["filename"].(string))
			go func() {
				for _, server := range servers {
					pushToServer(server, filePath, header.Filename, description, pubKey, nodeInfo)
				}
			}()
		}
	}
}

// ── Application Entry ─────────────────────────────────────────────────────────

func main() {
	initDirs()
	
	http.HandleFunc("/", handleRequest)
	
	// Serve actual uploaded files
	fs := http.StripPrefix("/files/", http.FileServer(http.Dir(FilesDir)))
	http.Handle("/files/", fs)

	fmt.Println("Mesh Node (Go pre-1.16) running on http://localhost:8080")
	log.Fatal(http.ListenAndServe(":8080", nil))
}

// ── HTML Frontend String Literal ──────────────────────────────────────────────

const htmlTemplate = `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mesh Node (Go)</title>
<style>
/* –– minimalist reset –– */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{min-height:100%;background:#fff;color:#111;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
body{display:flex;flex-direction:column;align-items:center;padding:2rem 1.5rem}

/* –– container –– */
.page{max-width:36rem;width:100%}

/* –– header –– */
header{margin-bottom:2rem;display:flex;align-items:baseline;justify-content:space-between;flex-wrap:wrap;gap:0.8rem}
h1{font-size:1.4rem;font-weight:700;letter-spacing:-0.02em}
h1 em{font-weight:400;color:#555;font-style:normal}
.sub{font-size:0.8rem;color:#777;margin-top:0.2rem}
.search-link{font-size:0.85rem;color:#0044cc;text-decoration:none;white-space:nowrap}
.search-link:hover{text-decoration:underline}

/* –– nav menu –– */
.head-nav {display:flex;gap:1rem;align-items:center;}
.head-nav a {font-size:0.85rem;color:#0044cc;text-decoration:none;font-weight:500;}
.head-nav a:hover {text-decoration:underline;}

/* –– node status bar –– */
#node-bar{display:flex;gap:0.75rem;flex-wrap:wrap;margin-bottom:1.75rem;font-size:0.8rem;color:#555}
.nb-pill{display:flex;align-items:center;gap:0.35rem;padding:0.25rem 0.6rem;background:#f5f5f5;border-radius:4px;border:1px solid #e0e0e0}
.nb-dot{width:7px;height:7px;border-radius:50%;background:#aaa}
.nb-dot.ok{background:#1a8e3f}
.nb-dot.blue{background:#0044cc}
.nb-val{font-weight:500}

/* –– drop zone –– */
#drop-zone{border:2px dashed #ccc;border-radius:6px;padding:2rem 1rem;text-align:center;cursor:pointer;transition:border-color 0.2s,background 0.2s}
#drop-zone:hover,#drop-zone:focus{background:#fafafa;border-color:#999}
#drop-zone.over{border-color:#0044cc;background:#f0f4ff}
.dz-icon{margin-bottom:0.75rem;font-size:1.8rem;color:#777}
.dz-title{font-size:1rem;font-weight:600;margin-bottom:0.3rem}
.dz-sub{font-size:0.8rem;color:#666;line-height:1.5}
.dz-sub b{color:#0044cc;font-weight:600}
#file-input{display:none}

/* –– upload panel –– */
#panel{display:none;margin-top:1.25rem;border:1px solid #ddd;border-radius:6px;overflow:hidden}
.panel-top{display:flex;align-items:center;gap:0.8rem;padding:0.8rem 1rem;border-bottom:1px solid #eee}
.f-icon{width:2rem;height:2rem;background:#f0f4ff;border-radius:4px;display:grid;place-items:center;flex-shrink:0;font-size:1rem;color:#0044cc}
#f-name{font-size:0.9rem;flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
#f-size{font-size:0.8rem;color:#777;flex-shrink:0}
.x-btn{background:none;border:none;cursor:pointer;font-size:1.2rem;color:#999;padding:0 0.2rem;transition:color 0.15s}
.x-btn:hover{color:#d00}

/* –– metadata pills –– */
.meta-pills{padding:0.5rem 1rem;display:flex;gap:0.5rem;flex-wrap:wrap;border-bottom:1px solid #eee;background:#fafafa}
.mpill{font-size:0.75rem;padding:0.2rem 0.6rem;border-radius:4px;border:1px solid #ddd;color:#555;background:#fff;display:flex;align-items:center;gap:0.3rem}
.mpill.has{border-color:#1a8e3f;color:#1a8e3f;background:#f0fff0}
.mpill svg{width:0.9rem;height:0.9rem;stroke:currentColor;fill:none;stroke-width:2.5;stroke-linecap:round;stroke-linejoin:round}

/* –– description –– */
.panel-mid{padding:1rem}
.fl{display:block;font-size:0.75rem;color:#777;margin-bottom:0.5rem}
textarea#comment-box{width:100%;min-height:5rem;padding:0.6rem 0.8rem;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:0.9rem;resize:vertical;outline:none;transition:border-color 0.2s}
textarea#comment-box:focus{border-color:#0044cc}
.cmeta{display:flex;justify-content:space-between;margin-top:0.4rem;font-size:0.75rem;color:#999}
.ccount.over{color:#d00}

/* –– panel footer –– */
.panel-bot{padding:0.8rem 1rem;border-top:1px solid #eee;display:flex;align-items:center;justify-content:space-between;gap:0.8rem}
.peer-info{font-size:0.8rem;color:#555}
.peer-info b{font-weight:600}
.btn-send{padding:0.5rem 1.2rem;background:#0044cc;color:#fff;border:none;border-radius:4px;font-weight:600;font-size:0.85rem;cursor:pointer;transition:opacity 0.2s;display:flex;align-items:center;gap:0.4rem}
.btn-send:hover{opacity:0.9}
.btn-send.busy{opacity:0.5;pointer-events:none}
.btn-send svg{width:1rem;height:1rem;stroke:#fff;stroke-width:2.2;fill:none;stroke-linecap:round;stroke-linejoin:round}

/* –– progress bar –– */
#prog-wrap{height:3px;background:#eee;border-radius:3px;overflow:hidden;display:none;margin-top:1rem}
#prog-bar{height:100%;width:0%;background:#0044cc;transition:width 0.1s linear}

/* –– status message –– */
#status{display:none;margin-top:1rem;padding:0.8rem 1rem;border-radius:4px;font-size:0.9rem;line-height:1.5;animation:fadeUp 0.25s ease}
#status.ok{background:#f0fff0;border:1px solid #c0e0c0;color:#1a8e3f}
#status.fail{background:#fff0f0;border:1px solid #e0c0c0;color:#d00}
.hash-line{margin-top:0.6rem}
.file-link{display:inline-flex;align-items:center;gap:0.4rem;padding:0.4rem 1rem;font-size:0.85rem;text-decoration:none;color:#0044cc;border:1px solid #0044cc;border-radius:4px;transition:background 0.15s}
.file-link:hover{background:#f0f4ff}
.file-link svg{width:0.9rem;height:0.9rem;stroke:currentColor;stroke-width:2.2;fill:none;stroke-linecap:round;stroke-linejoin:round}

/* –– info box –– */
.json-preview{margin-top:2rem;border:1px solid #ddd;border-radius:4px;overflow:hidden}
.json-preview-head{padding:0.5rem 1rem;border-bottom:1px solid #eee;font-size:0.75rem;color:#777;background:#fafafa;display:flex;align-items:center;gap:0.4rem}
.json-preview-head svg{width:0.9rem;height:0.9rem;stroke:#777;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
pre#json-schema{padding:1rem;font-family:"SF Mono","Fira Code","Fira Mono","Roboto Mono",monospace;font-size:0.8rem;line-height:1.6;color:#333;overflow-x:auto;margin:0}
.jk{color:#0044cc} .js{color:#1a8e3f} .jn{color:#b04000} .jb{color:#d00}

/* –– footer –– */
footer{border-top:1px solid #eee;padding-top:1.2rem;margin-top:2rem;display:flex;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;font-size:0.75rem;color:#aaa}

@keyframes fadeUp{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}
</style>
</head>
<body>
<div class="page">

<header>
  <div>
    <h1>Mesh <em>Node</em></h1>
    <p class="sub">Peer replication · SHA-256 storage</p>
  </div>
  <nav class="head-nav">
    <a href="/">Upload</a>
    <a href="/view">Search</a>
    <a href="/servers">Servers</a>
  </nav>
</header>

<div id="node-bar">
  <div class="nb-pill">
    <span class="nb-dot blue"></span>
    peers <span class="nb-val" id="nb-peers">…</span>
  </div>
  <div class="nb-pill">
    <span class="nb-dot" id="nb-pk-dot"></span>
    public_key.txt <span class="nb-val" id="nb-pk">…</span>
  </div>
  <div class="nb-pill">
    <span class="nb-dot" id="nb-ni-dot"></span>
    node_info.txt <span class="nb-val" id="nb-ni">…</span>
  </div>
</div>

<div id="drop-zone" tabindex="0" role="button" aria-label="Select or drop a file">
  <div class="dz-icon">☁️</div>
  <p class="dz-title">Drop a file or click to select</p>
  <p class="dz-sub">Stored as <b>sha256.ext</b> · pushed to all peers · <b>.php</b> blocked</p>
</div>
<input type="file" id="file-input">

<div id="panel">
  <div class="panel-top">
    <div class="f-icon">📄</div>
    <span id="f-name">—</span>
    <span id="f-size"></span>
    <button class="x-btn" id="x-btn" aria-label="Remove file">✕</button>
  </div>

  <div class="meta-pills">
    <div class="mpill" id="mpill-pk">
      <svg viewBox="0 0 24 24"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 11-7.778 7.778 5.5 5.5 0 017.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
      public_key
    </div>
    <input type="text" id="pk-inline" maxlength="512" placeholder="public key…" style="display:none;flex:1;min-width:0;padding:0.2rem 0.5rem;border:1px solid #ccc;border-radius:4px;font-family:inherit;font-size:0.78rem;outline:none;color:#333;background:#fff;transition:border-color 0.2s" onfocus="this.style.borderColor='#0044cc'" onblur="this.style.borderColor='#ccc'">
    <div class="mpill" id="mpill-ni">
      <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      node_info
    </div>
    <div class="mpill has">
      <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
      filename · size · extension
    </div>
  </div>

  <div class="panel-mid">
    <label class="fl" for="comment-box">Description <span style="color:#aaa">(optional · max 1 KB)</span></label>
    <textarea id="comment-box" placeholder="Add an optional description for this file…"></textarea>
    <div class="cmeta">
      <span>Stored as “description” field</span>
      <span class="ccount" id="ccount">0 / 1 000</span>
    </div>
  </div>

  <div class="panel-bot">
    <span class="peer-info">Sending to <b id="peer-count">…</b> peer(s)</span>
    <button class="btn-send" id="send-btn">
      <svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
      Send to network
    </button>
  </div>
</div>

<div id="prog-wrap"><div id="prog-bar"></div></div>
<div id="status"></div>

<div class="json-preview">
  <div class="json-preview-head">
    <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
    info/&lt;hash&gt;.json — stored structure
  </div>
  <pre id="json-schema">{
  <span class="jk">"original_filename"</span>: <span class="js">"photo.jpg"</span>,
  <span class="jk">"size"</span>:              <span class="jn">204800</span>,
  <span class="jk">"extension"</span>:        <span class="js">"jpg"</span>,
  <span class="jk">"public_key"</span>:       <span class="js">"&lt;contents of public_key.txt or empty string&gt;"</span>,
  <span class="jk">"node_info"</span>:        <span class="js">"&lt;contents of node_info.txt or empty string&gt;"</span>,
  <span class="jk">"description"</span>:      <span class="js">"&lt;user comment or empty string&gt;"</span>
}</pre>
</div>

</div><footer class="page">
  <p>Mesh Node · files stored as sha256.ext · info JSON has 6 fixed fields</p>
  <p>Golang (Native Standard Library)</p>
</footer>

<script>
(function(){
'use strict';

const dz       = document.getElementById('drop-zone');
const fi       = document.getElementById('file-input');
const panel    = document.getElementById('panel');
const fName    = document.getElementById('f-name');
const fSize    = document.getElementById('f-size');
const xBtn     = document.getElementById('x-btn');
const commentB = document.getElementById('comment-box');
const ccount   = document.getElementById('ccount');
const sendBtn  = document.getElementById('send-btn');
const peerCnt  = document.getElementById('peer-count');
const status   = document.getElementById('status');
const progWrap = document.getElementById('prog-wrap');
const progBar  = document.getElementById('prog-bar');
const mpillPk  = document.getElementById('mpill-pk');
const mpillNi  = document.getElementById('mpill-ni');
const pkInline = document.getElementById('pk-inline');

const MAX     = 1000;  // bytes (1 KB)
const BLOCKED = ['php','php3','php4','php5','php7','phtml','phar'];
let file = null;
let ns   = { public_key: false, node_info: false, peers: 0 };

// ── fetch node status ──────────────────────────────────────────────────────────
fetch(window.location.href.split('?')[0] + '?node_status=1')
  .then(r => r.json())
  .then(d => {
    ns = d;
    document.getElementById('nb-peers').textContent = d.peers;
    peerCnt.textContent = d.peers;

    const dot = (id, ok) => {
      const el = document.getElementById(id);
      if(el) el.className = 'nb-dot ' + (ok ? 'ok' : '');
    };
    dot('nb-pk-dot', d.public_key);
    dot('nb-ni-dot', d.node_info);
    document.getElementById('nb-pk').textContent = d.public_key ? '✓ found' : '— missing';
    document.getElementById('nb-ni').textContent = d.node_info  ? '✓ found' : '— missing';
    updatePills();
  })
  .catch(() => {
    ['nb-peers','nb-pk','nb-ni'].forEach(id => {
      const el = document.getElementById(id); if(el) el.textContent='?';
    });
    peerCnt.textContent = '?';
  });

function updatePills(){
  const hasPk = ns.public_key;
  mpillPk.style.display = hasPk ? '' : 'none';
  pkInline.style.display = hasPk ? 'none' : '';
  mpillNi.className = 'mpill ' + (ns.node_info  ? 'has' : '');
}

function fmt(b){
  if(b<1024) return b+' B';
  if(b<1048576) return (b/1024).toFixed(1)+' KB';
  return (b/1048576).toFixed(2)+' MB';
}

function extOf(n){ return n.split('.').pop().toLowerCase(); }

function setFile(f){
  if(!f) return;
  if(BLOCKED.includes(extOf(f.name))){ show('fail','PHP files are not accepted.'); return; }
  file = f;
  fName.textContent = f.name;
  fSize.textContent = fmt(f.size);
  panel.style.display = 'block';
  commentB.value = '';
  updateCount();
  updatePills();
  hide();
}

function clear(){
  file = null; fi.value = '';
  panel.style.display = 'none';
  commentB.value = '';
  pkInline.value = '';
}

dz.addEventListener('click', () => fi.click());
dz.addEventListener('keydown', e => { if(e.key==='Enter'||e.key===' ') fi.click(); });
fi.addEventListener('change', () => { if(fi.files[0]) setFile(fi.files[0]); });
xBtn.addEventListener('click', clear);

['dragenter','dragover'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.add('over'); }));
['dragleave','drop'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.remove('over'); }));
dz.addEventListener('drop', e => { if(e.dataTransfer.files[0]) setFile(e.dataTransfer.files[0]); });

function updateCount(){
  const len = new TextEncoder().encode(commentB.value).length;
  ccount.textContent = len.toLocaleString()+' / '+MAX.toLocaleString();
  ccount.classList.toggle('over', len > MAX);
}
commentB.addEventListener('input', updateCount);

function show(type, html){ status.className=type; status.innerHTML=html; status.style.display='block'; }
function hide(){ status.style.display='none'; }

sendBtn.addEventListener('click', () => {
  if(!file) return;
  if(new TextEncoder().encode(commentB.value).length > MAX){
    show('fail','Description exceeds the 1 KB limit.'); return;
  }

  const fd = new FormData();
  fd.append('file', file);
  fd.append('comment', commentB.value);
  if (!ns.public_key && pkInline.value.trim() !== '') {
    fd.append('public_key', pkInline.value.trim().slice(0, 512));
  }

  const xhr = new XMLHttpRequest();
  sendBtn.classList.add('busy');
  sendBtn.lastChild.textContent = ' Transmitting…';
  progWrap.style.display = 'block';
  progBar.style.width = '0%';
  hide();

  xhr.upload.addEventListener('progress', e => {
    if(e.lengthComputable) progBar.style.width = Math.round(e.loaded/e.total*100)+'%';
  });

  xhr.addEventListener('load', () => {
    sendBtn.classList.remove('busy');
    sendBtn.lastChild.textContent = ' Send to network';
    progBar.style.width = '100%';
    setTimeout(() => { progWrap.style.display='none'; progBar.style.width='0%'; }, 700);

    let res;
    try {
      const raw = xhr.responseText.replace(/^[\s\S]*?(\{)/, '$1');
      res = JSON.parse(raw);
    } catch(e){
      const m = xhr.responseText.match(/"filename"\s*:\s*"([^"]+)"/);
      if(m){
        showLink(m[1], false);
        clear(); return;
      }
      show('fail','Unexpected server response.'); return;
    }
    if(!res.ok){ show('fail','✗ '+(res.error||'Unknown error')); return; }

    showLink(res.filename, !!res.existed);
    clear();
  });

  xhr.addEventListener('error', () => {
    sendBtn.classList.remove('busy');
    sendBtn.lastChild.textContent = ' Send to network';
    show('fail','✗ Network error.'); progWrap.style.display='none';
  });

  xhr.open('POST', window.location.href.split('?')[0]);
  xhr.send(fd);
});

function showLink(filename, existed){
  const fileUrl = 'files/' + esc(filename);
  const note    = existed ? ' <span style="opacity:0.6">(already stored)</span>' : '';
  show('ok',
    '✓ File stored' + note +
    '<div class="hash-line">' +
      '<a class="file-link" href="' + fileUrl + '" target="_blank" rel="noopener noreferrer">' +
        '<svg viewBox="0 0 24 24"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>' +
        'Open file in new tab' +
      '</a>' +
    '</div>'
  );
}

function esc(s){
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

})();
</script>
</body>
</html>`