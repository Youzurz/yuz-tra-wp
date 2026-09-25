"""Exercise only the dedicated YUZ-TRA state lock, never an existing lock."""
import base64
import json
import pathlib
import urllib.error
import urllib.request
import uuid
import datetime

auth = json.loads(pathlib.Path('/home/ubuntu/.config/yuz-tra/terraform-auth.json').read_text())
endpoint = 'https://git.youzurz.com/api/v4/projects/20/terraform/state/yuz-tra-github/lock'
header = 'Basic ' + base64.b64encode((auth['username'] + ':' + auth['password']).encode()).decode()

def request(method, payload):
    req = urllib.request.Request(endpoint, data=json.dumps(payload).encode(), method=method,
                                 headers={'Authorization': header, 'Content-Type': 'application/json'})
    try:
        with urllib.request.urlopen(req, timeout=20) as response:
            return response.status
    except urllib.error.HTTPError as error:
        return error.code

own = {'ID': str(uuid.uuid4()), 'Operation': 'YUZ-TRA labelled lock acceptance test',
       'Who': 'yuz-tra-ci', 'Info': 'Explicit test, no state write', 'Version': '1.16.2',
       'Created': datetime.datetime.now(datetime.timezone.utc).isoformat(),
       'Path': 'project-20/yuz-tra-github'}
other = dict(own, ID=str(uuid.uuid4()))
acquired = request('POST', own)
assert acquired in (200, 201), f'Cannot acquire lock (HTTP {acquired}); leave existing lock untouched'
try:
    assert request('POST', other) in (409, 423), 'Concurrent lock was not refused'
    print('PASS concurrent Terraform lock refused')
finally:
    released = request('DELETE', own)
    assert released in (200, 204), f'Own test lock requires cleanup (HTTP {released})'
print('PASS own test lock released')
