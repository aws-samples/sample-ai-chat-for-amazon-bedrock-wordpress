# Test fixtures

`sigv4-botocore.json` holds reference signatures produced by botocore's `SigV4Auth` (botocore 1.43.6) for
Converse requests, one with and one without a session token. The credentials are fake test values
(`AKIDEXAMPLE` / `test-only-not-a-real-secret`); they are not AWS credentials.

Regenerate:

```python
import json, datetime
from urllib.parse import quote
from botocore.auth import SigV4Auth
from botocore.awsrequest import AWSRequest
from botocore.credentials import Credentials

model = "us.amazon.nova-lite-v1:0"
url = "https://bedrock-runtime.us-east-1.amazonaws.com/model/%s/converse" % quote(model, safe="")
body = json.dumps({"messages": [{"role": "user", "content": [{"text": "Hi"}]}]}, separators=(",", ":"))
req = AWSRequest(method="POST", url=url, data=body, headers={"Content-Type": "application/json", "Accept": "application/json"})
SigV4Auth(Credentials("AKIDEXAMPLE", "test-only-not-a-real-secret"), "bedrock", "us-east-1").add_auth(req)  # pragma: allowlist secret
print(req.headers["X-Amz-Date"], req.headers["Authorization"])
```

Store `timestamp` as the Unix time of `X-Amz-Date`, and the credential scope, signed-header list and
signature from the `Authorization` header as separate fields (`scope`, `signed_headers`, `signature`).
