#!/usr/bin/env python3
# Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
# SPDX-License-Identifier: MIT-0
"""Tiny MCP Streamable HTTP server for end-to-end tests (standard library only, loopback only).

Exposes one tool, get_weather(city). tools/call replies as text/event-stream to exercise the SSE path;
the other methods reply as application/json. Requires 'Authorization: Bearer test-token'.
"""
import json
import sys
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

TOKEN = "test-token"  # nosec B105 # pragma: allowlist secret -- fixed token of a loopback-only test server.
SESSION = "e2e-session-1"
TOOLS = [{
    "name": "get_weather",
    "description": "Get today's weather for a city.",
    "inputSchema": {"type": "object", "properties": {"city": {"type": "string"}}, "required": ["city"]},
}]


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def _send(self, status, body=b"", ctype="application/json", extra=None):
        self.send_response(status)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(body)))
        for k, v in (extra or {}).items():
            self.send_header(k, v)
        self.end_headers()
        self.wfile.write(body)

    def do_POST(self):
        if self.headers.get("Authorization") != "Bearer " + TOKEN:
            return self._send(401, b'{"error":"unauthorized"}')
        msg = json.loads(self.rfile.read(int(self.headers.get("Content-Length", 0))) or b"{}")
        method = msg.get("method")
        if method != "initialize" and self.headers.get("Mcp-Session-Id") != SESSION:
            return self._send(400, b'{"error":"missing session"}')
        if "id" not in msg:
            return self._send(202)
        if method == "initialize":
            result = {"protocolVersion": "2025-06-18", "capabilities": {"tools": {}}, "serverInfo": {"name": "e2e", "version": "1"}}
            return self._send(200, json.dumps({"jsonrpc": "2.0", "id": msg["id"], "result": result}).encode(), extra={"Mcp-Session-Id": SESSION})
        if method == "tools/list":
            return self._send(200, json.dumps({"jsonrpc": "2.0", "id": msg["id"], "result": {"tools": TOOLS}}).encode())
        if method == "tools/call":
            city = msg.get("params", {}).get("arguments", {}).get("city", "?")
            result = {"content": [{"type": "text", "text": "Weather in %s: sunny, 23 C, code MCP-OK-314." % city}]}
            sse = "event: message\ndata: %s\n\n" % json.dumps({"jsonrpc": "2.0", "id": msg["id"], "result": result})
            return self._send(200, sse.encode(), ctype="text/event-stream")
        return self._send(200, json.dumps({"jsonrpc": "2.0", "id": msg["id"], "error": {"code": -32601, "message": "not found"}}).encode())


if __name__ == "__main__":
    ThreadingHTTPServer(("127.0.0.1", int(sys.argv[1]) if len(sys.argv) > 1 else 8765), Handler).serve_forever()
