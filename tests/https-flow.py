#!/usr/bin/env python3
"""Local TLS fixture. Never contacts a merchant or transmits an actual payment."""
import http.server
import json
import os
from pathlib import Path
import ssl
import subprocess
import tempfile
import threading
import uuid

project = '11111111-1111-4111-8111-111111111111'
store = '22222222-2222-4222-8222-222222222222'
invoices, keys = {}, {}
control = {'status': 'new', 'amount_status': 'none', 'sequence': 1, 'requires_review': False, 'timing_status': 'on_time', 'http_status': 200}

class Handler(http.server.BaseHTTPRequestHandler):
    def log_message(self, *args): pass
    def reply(self, status, data):
        body = json.dumps(data).encode()
        self.send_response(status)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)
    def do_POST(self):
        raw = self.rfile.read(min(int(self.headers.get('Content-Length', 0)), 262145))
        if self.path == '/control':
            control.update(json.loads(raw))
            return self.reply(200, {'ok': True})
        if self.headers.get('Authorization') != 'Bearer ' + 'a' * 40:
            return self.reply(401, {'error': {'code': 'unauthorized'}})
        if self.path != f'/v1/projects/{project}/stores/{store}/invoices':
            return self.reply(404, {'error': {'code': 'wrong_route'}})
        key = self.headers.get('Idempotency-Key')
        if not key:
            return self.reply(400, {'error': {'code': 'missing_key'}})
        if key in keys:
            identifier, original = keys[key]
            if original != raw:
                return self.reply(409, {'error': {'code': 'idempotency_conflict'}})
        else:
            payload = json.loads(raw)
            if not isinstance(payload['amount'], str):
                return self.reply(400, {'error': {'code': 'invalid_amount'}})
            identifier = str(uuid.uuid4())
            keys[key] = identifier, raw
            invoices[identifier] = dict(payload, invoice_id=identifier, project_id=project, store_id=store)
        self.invoice(identifier)
    def do_GET(self):
        if self.path == '/stats': return self.reply(200, {'invoices': len(invoices)})
        if self.headers.get('Authorization') != 'Bearer ' + 'a' * 40:
            return self.reply(401, {'error': {'code': 'unauthorized'}})
        if self.path.endswith('/payment-assets'):
            return self.reply(200, {'data': []})
        identifier = self.path.split('/')[-1]
        if identifier not in invoices: return self.reply(404, {'error': {'code': 'invoice_not_found'}})
        self.invoice(identifier)
    def invoice(self, identifier):
        if control['http_status'] != 200:
            return self.reply(control['http_status'], {'error': {'code': 'test_unavailable'}})
        # Public invoice detail omits callback-only requires_review.
        state = {k: v for k, v in control.items() if k not in ('http_status', 'requires_review')}
        self.reply(200, {'data': dict(invoices[identifier], **state), 'links': {'checkout': 'https://pay.example.test/invoice/' + identifier}})

root = Path(__file__).resolve().parent
with tempfile.TemporaryDirectory(prefix='wholly-shop-tls-') as directory:
    fixture = Path(directory)
    subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1', '-subj', '/CN=localhost',
                    '-addext', 'subjectAltName=DNS:localhost', '-keyout', str(fixture/'key.pem'), '-out', str(fixture/'cert.pem')], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler)
    tls = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    tls.load_cert_chain(fixture/'cert.pem', fixture/'key.pem')
    server.socket = tls.wrap_socket(server.socket, server_side=True)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    env = dict(os.environ, WHOLLY_TEST_API_ORIGIN=f'https://localhost:{server.server_port}')
    try:
        script = os.environ.get('WHOLLY_TEST_FLOW', 'flow.php')
        if script not in ('flow.php', 'platform.php'): raise SystemExit('Unknown test flow')
        subprocess.run([os.environ.get('PHP_BINARY', 'php'), '-d', 'curl.cainfo='+str(fixture/'cert.pem'), str(root/script)], env=env, check=True)
    finally:
        server.shutdown()
        server.server_close()
