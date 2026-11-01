"""Exercise the shipped administrator forms and published routes on a local fixture."""
import argparse
import hashlib
from html.parser import HTMLParser
import http.cookiejar
import json
import os
from pathlib import Path
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid


class Forms(HTMLParser):
    def __init__(self):
        super().__init__()
        self.forms = []
        self.current = None

    def handle_starttag(self, tag, attributes):
        attributes = dict(attributes)
        if tag == 'form':
            self.current = {'action': attributes.get('action', ''), 'inputs': {}, 'files': []}
        elif tag == 'input' and self.current is not None:
            name = attributes.get('name')
            if name:
                if attributes.get('type') == 'file':
                    self.current['files'].append(name)
                else:
                    self.current['inputs'][name] = attributes.get('value', '')

    def handle_endtag(self, tag):
        if tag == 'form' and self.current is not None:
            self.forms.append(self.current)
            self.current = None


def request(client, url, data=None, headers=None):
    try:
        with client.open(urllib.request.Request(url, data=data, headers=headers or {}), timeout=30) as response:
            return response.status, response.geturl(), response.read().decode('utf-8')
    except urllib.error.HTTPError as error:
        return error.code, error.geturl(), error.read().decode('utf-8')


def multipart(fields, files):
    boundary = 'archive-' + uuid.uuid4().hex
    body = bytearray()
    for name, value in fields.items():
        body.extend(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode())
    for name, content in files.items():
        body.extend(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"; filename="{name}.txt"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode())
        body.extend(content)
        body.extend(b'\r\n')
    body.extend(f'--{boundary}--\r\n'.encode())
    return bytes(body), {'Content-Type': 'multipart/form-data; boundary=' + boundary}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--base-url', required=True)
    parser.add_argument('--report', required=True)
    args = parser.parse_args()
    base = args.base_url.rstrip('/')
    parsed = urllib.parse.urlparse(base)
    assert parsed.scheme == 'http' and parsed.hostname == '127.0.0.1'
    password = os.environ['PAUSATF_ARCHIVE_WP_TEST_PASSWORD']
    checks = []

    def check(name, condition):
        checks.append({'name': name, 'passed': bool(condition)})
        assert condition, name

    def client(user=None):
        opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        if user:
            request(opener, base + '/wp-login.php')
            status, url, _ = request(opener, base + '/wp-login.php', urllib.parse.urlencode({'log': user, 'pwd': password, 'wp-submit': 'Log In', 'redirect_to': base + '/wp-admin/'}).encode())
            check('Native login ' + user, status == 200 and '/wp-admin/' in url)
        return opener

    anonymous = client()
    for _ in range(30):
        try:
            if request(anonymous, base + '/')[0] == 200:
                break
        except (urllib.error.URLError, TimeoutError):
            pass
        time.sleep(1)
    else:
        raise AssertionError('WordPress fixture did not become ready')

    producer = client('archive-producer')
    verifier = client('archive-verifier')
    subscriber = client('archive-subscriber')
    admin = base + '/wp-admin/admin.php?page=pausatf-archive-review'

    def page(opener, identifier=None):
        return request(opener, admin + ('' if identifier is None else '&release_id=' + str(identifier)))

    def form(opener, action, identifier=None):
        status, _, text = page(opener, identifier)
        check('Review screen reachable for ' + action, status == 200)
        forms = Forms()
        forms.feed(text)
        found = [f for f in forms.forms if f['inputs'].get('archive_action') == action]
        check('Rendered ' + action + ' form', len(found) == 1)
        check('Local nonce destination for ' + action, found[0]['action'] == base + '/wp-admin/admin-post.php' and bool(found[0]['inputs'].get('_wpnonce')))
        return found[0]

    def submit(opener, found, files=None, fields=None):
        values = dict(found['inputs'])
        values.update(fields or {})
        body, headers = multipart(values, files or {})
        return request(opener, found['action'], body, headers)

    installed = submit(producer, form(producer, 'install'))
    check('Explicit schema install completed', installed[0] == 200 and 'notice=saved' in installed[1])
    status, _, _ = request(subscriber, base + '/wp-admin/admin-post.php', urllib.parse.urlencode({'action': 'pausatf_archive_context', 'archive_action': 'install'}).encode())
    check('Subscriber administrative mutation denied', status == 403)
    fixtures = Path(__file__).resolve().parents[1] / 'fixtures/archive-context'
    bundle = (fixtures / '1996-5ka-bundle.json').read_bytes()
    source = (fixtures / '1996-5ka-excerpt.txt').read_bytes()
    stage_form = form(producer, 'stage')
    rejected = submit(producer, stage_form, {'bundle': bundle, 'source': source}, {'_wpnonce': 'invalid'})
    check('Invalid nonce denied', rejected[0] == 403)
    wrong_source = submit(producer, form(producer, 'stage'), {'bundle': bundle, 'source': source + b'changed'})
    check('Changed source rejected before staging', wrong_source[0] == 400)
    staged = submit(producer, form(producer, 'stage'), {'bundle': bundle, 'source': source})
    identifier = int(urllib.parse.parse_qs(urllib.parse.urlparse(staged[1]).query)['release_id'][0])
    check('First immutable candidate created', identifier == 1 and staged[0] == 200)
    public = base + '/wp-json/pausatf/v1/archive/releases/' + str(identifier)
    private = base + '/wp-json/pausatf/v1/archive/review/' + str(identifier)
    check('Anonymous candidate hidden', request(anonymous, public)[0] == 404)
    check('Anonymous private evidence denied', request(anonymous, private)[0] in (401, 403))
    status, _, producer_page = page(producer, identifier)
    parsed_forms = Forms()
    parsed_forms.feed(producer_page)
    check('Producer has no self-review form', not any(f['inputs'].get('archive_action') == 'review' for f in parsed_forms.forms))
    parsed_bundle = json.loads(bundle)
    report = {'bundle_sha256': hashlib.sha256(bundle).hexdigest(), 'source_sha256': hashlib.sha256(source).hexdigest(), 'outcome': 'pass', 'evidence': 'Compared every annotated fixture field with the original two source lines; preserved distinct places and truncated names.', 'findings': [], 'checked_records': [kind + ':' + record['key'] for kind, records in parsed_bundle['entities'].items() for record in records]}
    self_review_fields = {'archive_action': 'review', 'release_id': str(identifier)}
    denied = submit(producer, form(producer, 'stage'), {'report': json.dumps(report).encode(), 'source': source}, self_review_fields)
    # The capability layer (pausatf_archive_verify_release) refuses the producer before the workflow's own check.
    check('Server rejects crafted self-review', denied[0] == 403)
    reviewed = submit(verifier, form(verifier, 'review', identifier), {'report': json.dumps(report).encode(), 'source': source})
    check('Independent review saved', reviewed[0] == 200 and 'notice=saved' in reviewed[1])
    check('Verified candidate still private', request(anonymous, public)[0] == 404)
    published = submit(producer, form(producer, 'publish', identifier))
    check('Explicit publication completed', published[0] == 200 and 'notice=saved' in published[1])
    status, _, data = request(anonymous, public)
    check('Published metadata public', status == 200 and json.loads(data)['collections']['performances'] == 2)
    status, _, records = request(anonymous, public + '/rankings?per_page=1&page=2')
    result = json.loads(records)
    check('Public collection pagination preserves second place', status == 200 and result['records'][0]['published_place'] == '2' and result['total'] == 2)
    check('Invalid public pagination rejected', request(anonymous, public + '/performances?per_page=1000')[0] == 400)
    status, _, html = request(anonymous, base + '/archive-results/')
    check('Published shortcode renders source context', status == 200 and 'Christophe Impens' in html and 'Matt Guisto' in html and '14:13' in html and 'ratify records' in html)
    withdrawn = submit(producer, form(producer, 'withdraw', identifier))
    check('Explicit withdrawal completed', withdrawn[0] == 200 and 'notice=saved' in withdrawn[1])
    check('Withdrawn release hidden', request(anonymous, public)[0] == 404)
    Path(args.report).write_text(json.dumps({'executed': len(checks), 'checks': checks, 'passed': all(c['passed'] for c in checks)}, indent=2) + '\n')
    print(json.dumps({'executed': len(checks), 'passed': True}))


if __name__ == '__main__':
    main()
