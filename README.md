# AI Chat for Amazon Bedrock — WordPress sample plugin

A WordPress plugin that adds a chat assistant to any page with the `[ai_chat_bedrock]` shortcode. It
calls the [Amazon Bedrock Converse API](https://docs.aws.amazon.com/bedrock/latest/userguide/conversation-inference.html)
from your WordPress server, so it works with any Bedrock text model or inference profile that supports
Converse (Amazon Nova, Anthropic Claude, Meta Llama, Mistral and others).

Optionally, the model can use tools:

- **Site content tools**: search and read this site's *published, public* posts and pages.
- **MCP tools**: call tools of remote [Model Context Protocol](https://modelcontextprotocol.io) servers that
  an administrator adds and reviews (Streamable HTTP transport).

The plugin has no third-party runtime dependencies. It signs requests itself (AWS Signature Version 4)
and decodes the Bedrock response stream in PHP.

> This is sample code, intended to demonstrate a pattern. Review it, and adapt it to your security,
> privacy and cost requirements, before using it on a production site.

## How it works

```
Browser ──(1) POST /wp-json/aicfab/v1/chat ──▶ WordPress + plugin ──(3) Converse / ConverseStream (SigV4) ──▶ Amazon Bedrock
   ▲          message + prior turns               │  access policy, nonce,                                     │
   │                                              │  rate limit, daily cap,                                    │ toolUse
   └──(5) text / Server-Sent Events ◀─────────────┤  input validation (2)                                      ▼
                                                  └──(4) tool loop on the server: site content (published only) │ MCP servers (https, public IPs)
```

1. The chat UI sends the new message and the previous turns (kept in the browser only).
2. The REST endpoint checks who may chat (logged-in users by default; WordPress verifies the REST nonce),
   applies a per-visitor rate limit and a site-wide daily cap, and validates and bounds the input.
3. The plugin calls Bedrock with credentials from an IAM role or your configuration.
4. If the model asks for a tool, the server runs it (at most 4 rounds) and sends the result back to the
   model. Clients can never choose a tool or its arguments.
5. The answer is returned as JSON or streamed as Server-Sent Events, and rendered as plain text.

Conversations are not stored on the server, and prompts and responses are never logged.

## Prerequisites

- WordPress 6.3 or later and PHP 7.4 or later. Streaming needs the PHP `curl` extension. (Encryption uses the
  libsodium functions, which WordPress provides through its bundled `sodium_compat` when the extension is absent.)
- An AWS account with [access to the model](https://docs.aws.amazon.com/bedrock/latest/userguide/model-access.html)
  you want to use in your chosen Region.
- AWS credentials for the WordPress server, with permission to invoke that model (see below).

## Install

1. Copy the `ai-chat-for-amazon-bedrock` folder into `wp-content/plugins/` (or zip that folder and upload it
   under **Plugins → Add New → Upload Plugin**).
2. Activate **AI Chat for Amazon Bedrock**.
3. Go to **Settings → AI Chat (Bedrock)**, choose the Region and model, and select **Test connection**.
4. Add `[ai_chat_bedrock]` to a page. Optional attributes: `title="…"`, `height="480px"`.

### AWS credentials

Use, in order of preference:

1. **An IAM role** for the host: EC2 instance profile (IMDSv2), ECS task role, or EKS Pod Identity / IRSA.
   Nothing secret is stored in WordPress. Keep **Settings → Source** on *Automatic*.
2. **Constants in `wp-config.php`**, outside the database:
   ```php
   define( 'AICFAB_AWS_ACCESS_KEY_ID', '…' );
   define( 'AICFAB_AWS_SECRET_ACCESS_KEY', '…' );
   ```
   or the standard `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` environment variables.
3. **An access key stored in the database** (*Source: stored*). It is encrypted with a key derived from your
   `wp-config.php` salts and is never shown again in the admin screen. Prefer options 1 or 2, and use a
   dedicated IAM user with only the policy below.

Least-privilege policy for the default model (`us.amazon.nova-lite-v1:0`, a US cross-Region inference profile).
Replace `ACCOUNT_ID`; for another model, use its inference profile and the foundation-model ARNs it routes to
(`aws bedrock get-inference-profile --inference-profile-identifier <id>`):

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "InvokeChatModel",
      "Effect": "Allow",
      "Action": ["bedrock:InvokeModel", "bedrock:InvokeModelWithResponseStream"],
      "Resource": [
        "arn:aws:bedrock:us-east-1:ACCOUNT_ID:inference-profile/us.amazon.nova-lite-v1:0",
        "arn:aws:bedrock:us-east-1::foundation-model/amazon.nova-lite-v1:0",
        "arn:aws:bedrock:us-east-2::foundation-model/amazon.nova-lite-v1:0",
        "arn:aws:bedrock:us-west-2::foundation-model/amazon.nova-lite-v1:0"
      ]
    }
  ]
}
```

If you configure a guardrail, also allow `bedrock:ApplyGuardrail` on the guardrail's ARN.

## Settings

| Setting | Default | Notes |
|---|---|---|
| Region, model | `us-east-1`, `us.amazon.nova-lite-v1:0` | Any Converse-capable model or inference profile ID/ARN. |
| Max output tokens, temperature, system prompt | 1024, 0.5 | |
| Guardrail ID / version | empty | Recommended for public sites: an [Amazon Bedrock Guardrail](https://docs.aws.amazon.com/bedrock/latest/userguide/guardrails.html) filters harmful content and prompt attacks. |
| Streaming | on | Needs PHP cURL and a web server/proxy that does not buffer responses (the plugin sends `X-Accel-Buffering: no` for nginx). |
| Who can chat | logged-in users | *Everyone* lets anonymous visitors spend your AWS budget; keep the limits low. |
| Messages per visitor per minute / per day site-wide | 10 / 500 | Checked before Bedrock is called. |
| Max characters per message, previous turns sent | 2000 / 10 | Bounds tokens per request. |
| Site content tools, MCP tools | off | See below. |
| Debug log | off | Writes Bedrock error codes (never content or credentials) to the PHP error log when `WP_DEBUG_LOG` is on. |

### Site content tools

`site_search_posts`, `site_get_post` and `site_info` give the model read access to published,
non-password-protected posts and pages (filter `aicfab_site_tool_post_types` to change the post types).
Content is read as plain text from the post body; no other plugin's content filters run.

### MCP servers

Add servers under **Settings → AI Chat (Bedrock) → MCP servers**. A server URL must use `https` and resolve
only to public IP addresses; requests use `wp_safe_remote_post()` with redirects disabled, a 15-second
timeout and a 1 MB response cap. An optional bearer token is stored encrypted. The tool list is fetched
when you add or refresh a server and is then pinned; review it on that screen. The model decides what to
send to these tools, including text from the conversation, so only add servers you trust.

For local development only, `define( 'AICFAB_MCP_ALLOW_LOCAL', true );` allows `http` and loopback/private
addresses. Never set it on a public site.

## Security

Before going live:

- [ ] Use an IAM role or `wp-config.php` constants, with the least-privilege policy above.
- [ ] Keep *Who can chat* on *Logged-in users* unless you have a reason to open it; if you open it, keep the
      rate limit and daily cap low.
- [ ] Set an [AWS Budgets](https://docs.aws.amazon.com/cost-management/latest/userguide/budgets-managing-costs.html)
      alert and review your Bedrock [service quotas](https://docs.aws.amazon.com/bedrock/latest/userguide/quotas.html):
      the in-plugin counters are best-effort under heavy concurrency.
- [ ] Configure a Bedrock Guardrail for public-facing use (the admin screen warns if anonymous access is on
      without one).
- [ ] Keep AWS CloudTrail on for the account. If you need an audit trail of prompts and responses, enable
      [Bedrock model invocation logging](https://docs.aws.amazon.com/bedrock/latest/userguide/model-invocation-logging.html)
      to an encrypted destination with restricted access and a retention period, and mention it in your
      privacy policy (the plugin itself stores no conversations; it adds suggested text under **Settings → Privacy**).
- [ ] Serve the site over HTTPS. If WordPress is behind a reverse proxy, supply the real client IP to the rate
      limiter through the `aicfab_client_ip` filter (forwarded headers are ignored by default because clients
      can forge them).
- [ ] Tell visitors that answers are AI-generated and may be wrong (the widget shows a notice), and update your
      privacy policy: messages are sent to Amazon Bedrock in the Region you selected.

The design, trust boundaries and mitigations are described in [docs/threat-model.md](docs/threat-model.md).
To report a vulnerability, see [SECURITY.md](SECURITY.md).

## Costs

You pay for the Bedrock tokens each chat uses ([pricing](https://aws.amazon.com/bedrock/pricing/)). Each
request sends the system prompt, up to the configured number of previous turns and the new message; each
tool round is an additional model call. The plugin itself creates no AWS resources.

## Development and tests

```bash
composer install            # PHPUnit, WordPress Coding Standards, PHPCompatibility (dev only)
composer test               # unit tests: SigV4 against botocore vectors, event-stream decoding, validation
composer lint               # WordPress Coding Standards incl. security sniffs, PHP 7.4+ compatibility
```

End-to-end tests run against a **disposable** WordPress site (they change plugin settings) and a real Bedrock
model (a few hundred tokens):

```bash
WP="wp --path=/path/to/wp" SUB_PASS=… tests/e2e/seed.sh   # test content and a subscriber; prints the chat page ID
wp eval-file tests/e2e/checks.php                         # server-side checks inside WordPress
BASE=http://localhost:8080 WP="wp --path=/path/to/wp" USER_LOGIN=sub USER_PASS=… tests/e2e/run-http.sh
(cd tests/e2e && npm ci && BASE=… PAGE_ID=… USER_LOGIN=sub USER_PASS=… npm run browser-check)   # needs Google Chrome
```

`tests/e2e/mock_mcp_server.py` is a loopback-only MCP server for testing the MCP client: start it, define
`AICFAB_MCP_ALLOW_LOCAL` on the test site and pass `MCP_URL=http://127.0.0.1:8765/mcp` to `run-http.sh`
(`checks.php` must run without that constant).

## Uninstall

Deleting the plugin from **Plugins** removes its settings (including any stored key), the MCP server list
and its transients. It creates no tables and no AWS resources.

## License

This library is licensed under the MIT-0 License. See the [LICENSE](LICENSE) file.
