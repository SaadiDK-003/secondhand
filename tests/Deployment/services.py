"""Loopback-only S3 protocol and SMTPS fixtures for the disposable Ubuntu VM test.

These are NOT production services and do not implement real cloud authorization.
"""
import base64
import hashlib
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
import socketserver
import ssl
import sys
import threading

ROOT = Path(sys.argv[1])
OBJECTS = {}


class Storage(BaseHTTPRequestHandler):
    def log_message(self, *_):
        pass

    def authorized(self):
        if not self.headers.get('Authorization', '').startswith('AWS4-HMAC-SHA256 '):
            self.send_response(403)
            self.end_headers()
            return False
        return True

    def do_PUT(self):
        if not self.authorized():
            return
        if self.headers.get('x-amz-acl') != 'private':
            self.send_response(400)
            self.end_headers()
            return
        value = self.rfile.read(int(self.headers['Content-Length']))
        OBJECTS[self.path] = value
        self.send_response(200)
        self.send_header('ETag', '"' + hashlib.md5(value).hexdigest() + '"')
        self.end_headers()

    def do_GET(self):
        if not self.authorized():
            return
        value = OBJECTS.get(self.path)
        self.send_response(200 if value is not None else 404)
        self.send_header('Content-Type', 'application/octet-stream')
        self.send_header('Content-Length', str(len(value or b'')))
        self.end_headers()
        if value is not None:
            self.wfile.write(value)

    do_HEAD = do_GET

    def do_DELETE(self):
        if not self.authorized():
            return
        OBJECTS.pop(self.path, None)
        self.send_response(204)
        self.end_headers()


class SMTP(socketserver.StreamRequestHandler):
    def handle(self):
        def reply(value):
            self.wfile.write(value.encode() + b'\r\n')
            self.wfile.flush()
        reply('220 localhost fixture SMTP')
        while line := self.rfile.readline():
            verb = line.split(b' ', 1)[0].strip().upper()
            if verb in {b'EHLO', b'HELO'}:
                reply('250-localhost')
                reply('250 AUTH PLAIN')
            elif verb == b'AUTH':
                if (ROOT / 'fail-smtp').exists():
                    reply('535 5.7.8 Authentication failed')
                else:
                    # Only PLAIN with the known test credentials is accepted.
                    parts = line.strip().split()
                    valid = len(parts) == 3 and parts[1] == b'PLAIN' and base64.b64decode(parts[2]) == b'\x00fixture-user\x00fixture-password'
                    reply('235 2.7.0 Authenticated' if valid else '535 5.7.8 Authentication failed')
            elif verb == b'QUIT':
                reply('221 Bye')
                break
            else:
                # No email is accepted/delivered by this fixture.
                reply('550 Sending is disabled in this fixture')


class SMTPS(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    def get_request(self):
        socket, address = super().get_request()
        return self.context.wrap_socket(socket, server_side=True), address


http = ThreadingHTTPServer(('127.0.0.1', 29000), Storage)
smtp = SMTPS(('127.0.0.1', 2465), SMTP)
smtp.context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
smtp.context.load_cert_chain(ROOT / 'services.crt', ROOT / 'services.key')
threading.Thread(target=http.serve_forever, daemon=True).start()
smtp.serve_forever()
