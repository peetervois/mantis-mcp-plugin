"""Exercise the real PHP smoke HTTP client against a disposable loopback server."""
import copy
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import os
from pathlib import Path
import subprocess
import threading
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get("PHP_BINARY", "php")
PRIMARY = "local-fixture-primary-token"
SECOND = "local-fixture-secondary-token"


class SmokeTransportTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        script = ("require 'MantisMcp/core/AttachmentDownload.php';"
                  "require 'MantisMcp/core/AttachmentUpload.php';"
                  "require 'MantisMcp/core/Tools.php';"
                  "echo json_encode((new Tauria\\MantisMcp\\Tools())->definitions());")
        cls.tools = json.loads(subprocess.check_output([PHP, "-r", script], cwd=ROOT, text=True))

    def setUp(self):
        self.requests = []
        self.mode = "normal"
        fixture = self

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *args):
                pass

            def do_POST(self):
                body = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
                token = self.headers.get("Authorization", "")
                fixture.requests.append((self.path, body["method"], token, self.headers.get("MCP-Protocol-Version")))
                if token not in ("Bearer " + PRIMARY, "Bearer " + SECOND):
                    if fixture.mode == "denial-body":
                        self.respond(401, b"private fixture diagnostic")
                    else:
                        self.respond(401, None)
                    return
                if fixture.mode == "redirect":
                    self.send_response(307)
                    self.send_header("Location", "/must-not-follow")
                    self.end_headers()
                    return
                if body["method"] == "notifications/initialized":
                    self.respond(202, None)
                    return
                result = {}
                if body["method"] == "initialize":
                    result = {"protocolVersion": "2025-06-18", "capabilities": {"tools": {}},
                              "serverInfo": {"name": "mantis-mcp-plugin", "version": "0.1.0"}}
                    if fixture.mode == "reflection":
                        result["serverInfo"]["version"] = "0.1.0+" + PRIMARY
                elif body["method"] == "tools/list":
                    result = {"tools": copy.deepcopy(fixture.tools)}
                elif body["method"] == "tools/call":
                    name = body["params"]["name"]
                    if name == "mantis_get_current_user":
                        data = {"id": 1 if token == "Bearer " + PRIMARY else 2}
                    elif name == "mantis_get_relationship_types":
                        data = {"types": [{"id": 1, "name": "related-to", "label": "related to",
                                          "complementary_type_id": 1}]}
                    else:
                        raise AssertionError("Unexpected tool call in basic loopback fixture")
                    result = {"isError": False, "structuredContent": data, "content": []}
                response = {"jsonrpc": "2.0", "id": body["id"], "result": result}
                if fixture.mode == "wrong-id":
                    response["id"] += 1000
                if fixture.mode == "oversize":
                    response["private_diagnostic"] = "x" * (2 * 1024 * 1024 + 1)
                self.respond(200, response)

            def respond(self, status, value):
                if isinstance(value, bytes):
                    raw = value
                else:
                    raw = b"" if value is None else json.dumps(value).encode()
                self.send_response(status)
                self.send_header("Content-Type", "application/json")
                self.send_header("Content-Length", str(len(raw)))
                if status == 401:
                    challenge = 'Bearer realm="private fixture"' if fixture.mode == "denial-realm" else "Bearer"
                    self.send_header("WWW-Authenticate", challenge)
                    if fixture.mode != "denial-cache":
                        self.send_header("Cache-Control", "private, no-store")
                    if fixture.mode == "denial-header":
                        self.send_header("X-Mantis-Username", "private fixture user")
                self.end_headers()
                try:
                    self.wfile.write(raw)
                except (BrokenPipeError, ConnectionResetError):
                    pass

        self.server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()

    def tearDown(self):
        self.server.shutdown()
        self.server.server_close()
        self.thread.join()

    def run_smoke(self, **values):
        env = {k: v for k, v in os.environ.items() if not k.startswith("MANTIS_")}
        env.update(MANTIS_MCP_URL=f"http://127.0.0.1:{self.server.server_port}/mcp", MANTIS_API_TOKEN=PRIMARY)
        env.update(values)
        result = subprocess.run([PHP, "tests/smoke.php"], cwd=ROOT, env=env, text=True,
                                capture_output=True, timeout=20)
        self.assertNotIn(PRIMARY, result.stdout + result.stderr)
        self.assertNotIn(SECOND, result.stdout + result.stderr)
        return result

    def test_old_version_actual_http_discovery_notifications_and_protocol_header(self):
        result = self.run_smoke()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("version 0.1.0", result.stdout)
        self.assertIn("3 optional groups skipped", result.stdout)
        self.assertTrue(any(r[1] == "notifications/initialized" for r in self.requests))
        self.assertTrue(any(r[1] == "ping" and r[3] == "2025-06-18" for r in self.requests))

    def test_separate_token_requests_and_primary_identity_readback(self):
        result = self.run_smoke(MANTIS_API_TOKEN_SECOND=SECOND,
                                MANTIS_MCP_EXPECTED_USER_ID="1", MANTIS_MCP_SECOND_EXPECTED_USER_ID="2")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.requests[-1][2], "Bearer " + PRIMARY)
        self.assertIn("separate account identities", result.stdout)

    def test_anonymous_discovery_checks_raw_denials_without_printing_private_details(self):
        for mode in ("denial-body", "denial-realm", "denial-header", "denial-cache"):
            with self.subTest(mode=mode):
                self.mode = mode
                result = self.run_smoke()
                self.assertEqual(result.returncode, 1)
                self.assertNotIn("private fixture", result.stdout + result.stderr)
                self.assertFalse(any(request[2] == "Bearer " + PRIMARY for request in self.requests))

    def test_reflected_credentials_are_redacted_from_version_output(self):
        self.mode = "reflection"
        result = self.run_smoke()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("[redacted]", result.stdout)

    def test_redirect_is_not_followed(self):
        self.mode = "redirect"
        result = self.run_smoke()
        self.assertEqual(result.returncode, 1)
        self.assertFalse(any(r[0] == "/must-not-follow" for r in self.requests))

    def test_wrong_rpc_id_fails_without_printing_response_body(self):
        self.mode = "wrong-id"
        result = self.run_smoke()
        self.assertEqual(result.returncode, 1)
        self.assertIn("response correlation", result.stderr)

    def test_oversized_response_fails_at_the_transport_boundary(self):
        self.mode = "oversize"
        result = self.run_smoke()
        self.assertEqual(result.returncode, 1)
        self.assertIn("Bounded response body", result.stderr)

    def test_malformed_credentials_and_ids_are_rejected_before_network_access(self):
        for values in ({"MANTIS_API_TOKEN": "bad\nheader"}, {"MANTIS_MCP_ISSUE_ID": "0"}):
            with self.subTest(values=values):
                result = self.run_smoke(**values)
                self.assertEqual(result.returncode, 1)
                self.assertEqual(self.requests, [])
