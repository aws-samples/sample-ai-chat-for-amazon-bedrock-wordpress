# AI Chat for Amazon Bedrock — WordPress sample plugin

A WordPress plugin that adds a chat assistant to any page with the `[ai_chat_bedrock]` shortcode. It
calls the [Amazon Bedrock Converse API](https://docs.aws.amazon.com/bedrock/latest/userguide/conversation-inference.html)
from your WordPress server, so it works with any Bedrock text model or inference profile that supports
Converse (Amazon Nova, Anthropic Claude, Meta Llama, Mistral and others).

Optionally, the model can use **site content tools** to search and read this site's *published, public*
posts and pages.

The plugin calls Bedrock through the [AWS SDK for PHP](https://aws.amazon.com/sdk-for-php/), which signs
requests and obtains credentials from the server's IAM role. The plugin never asks for or stores AWS keys.

> This is sample code, intended to demonstrate a pattern. Review it, and adapt it to your security,
> privacy and cost requirements, before using it on a production site.

## How it works

```
Browser ──(1) POST /wp-json/aicfab/v1/chat ──▶ WordPress + plugin ──(3) Converse / ConverseStream ──▶ Amazon Bedrock
   ▲          message + prior turns               │  access policy, nonce,          (AWS SDK for PHP,           │
   │                                              │  rate limit, daily cap,          IAM role credentials)      │ toolUse
   └──(5) text / Server-Sent Events ◀─────────────┤  input validation (2)                                       ▼
                                                  └──(4) tool loop on the server: site content tools (published posts only)
```

1. The chat UI sends the new message and the previous turns (kept in the browser only).
2. The REST endpoint checks who may chat (logged-in users by default; WordPress verifies the REST nonce),
   applies a per-visitor rate limit and a site-wide daily cap, and validates and bounds the input.
3. The plugin calls Bedrock with the AWS SDK, using temporary credentials from the server's IAM role.
4. If the model asks for a site content tool, the server runs it (at most 4 rounds) and sends the result
   back to the model. Clients can never choose a tool or its arguments.
5. The answer is returned as JSON or streamed as Server-Sent Events, and rendered as plain text.

Conversations are not stored on the server, and prompts and responses are never logged.

## Prerequisites

- WordPress 6.3 or later and PHP 8.1 or later (with the `curl` extension, which the AWS SDK uses).
- An AWS account with [access to the model](https://docs.aws.amazon.com/bedrock/latest/userguide/model-access.html)
  you want to use in your chosen Region.
- WordPress running on AWS with an IAM role (see [AWS credentials](#aws-credentials)).
- To build the package: [Composer](https://getcomposer.org/) 2 and `zip`.

## Build and install

The repository does not contain third-party code. Build the installable package, which adds the AWS SDK for
PHP and its dependencies (versions pinned in `ai-chat-for-amazon-bedrock/composer.lock`):

```bash
bin/build.sh        # creates dist/ai-chat-for-amazon-bedrock.zip
```

1. In WordPress, go to **Plugins → Add New → Upload Plugin**, upload `dist/ai-chat-for-amazon-bedrock.zip`
   and activate **AI Chat for Amazon Bedrock**.
2. Go to **Settings → AI Chat (Bedrock)**, choose the Region and model, and select **Test connection**.
3. Add `[ai_chat_bedrock]` to a page. Optional attributes: `title="…"`, `height="480px"`.

### AWS credentials

The AWS SDK for PHP resolves credentials with its
[default credential provider chain](https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/guide_credentials_default_chain.html).
Run WordPress on AWS with an IAM role and nothing secret is configured anywhere:

- **Amazon EC2 / Lightsail**: attach an instance profile; require IMDSv2 on the instance.
- **Amazon ECS / AWS Fargate**: use a task role.
- **Amazon EKS**: use EKS Pod Identity or IAM roles for service accounts (IRSA).

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
| Streaming | on | Needs a web server/proxy that does not buffer responses (the plugin sends `X-Accel-Buffering: no` for nginx). |
| Who can chat | logged-in users | *Everyone* lets anonymous visitors spend your AWS budget; keep the limits low. |
| Messages per visitor per minute / per day site-wide | 10 / 500 | Checked before Bedrock is called. |
| Max characters per message, previous turns sent | 2000 / 10 | Bounds tokens per request. |
| Site content tools | off | See below. |
| Debug log | off | Writes Bedrock error codes (never prompts or responses) to the PHP error log when `WP_DEBUG_LOG` is on. |

### Site content tools

`site_search_posts`, `site_get_post` and `site_info` give the model read access to published,
non-password-protected posts and pages (filter `aicfab_site_tool_post_types` to change the post types).
Content is read as plain text from the post body; no other plugin's content filters run.

## Security

Before going live:

- [ ] Use an IAM role with the least-privilege policy above.
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

## Third-party software

The package built by `bin/build.sh` includes the AWS SDK for PHP (Apache-2.0) and its dependencies (MIT and
Apache-2.0); the build writes the list to `THIRD-PARTY-LICENSES.txt` inside the package. The repository itself
contains only code licensed under MIT-0.

## Costs

You pay for the Bedrock tokens each chat uses ([pricing](https://aws.amazon.com/bedrock/pricing/)). Each
request sends the system prompt, up to the configured number of previous turns and the new message; each
tool round is an additional model call. The plugin itself creates no AWS resources.

## Development and tests

```bash
composer install                                   # PHPUnit, WordPress Coding Standards, PHPCompatibility (dev only)
(cd ai-chat-for-amazon-bedrock && composer install --no-dev)   # runtime dependencies for local testing
composer test                                      # unit tests (AWS SDK mocked, no network)
composer lint                                      # WordPress Coding Standards incl. security sniffs, PHP 8.1+
```

End-to-end tests run against a **disposable** WordPress site (they change plugin settings) and a real Bedrock
model (a few hundred tokens). The web server's PHP needs AWS credentials through the SDK chain:

```bash
WP="wp --path=/path/to/wp" SUB_PASS=… tests/e2e/seed.sh   # test content and a subscriber; prints the chat page ID
wp eval-file tests/e2e/checks.php                         # server-side checks inside WordPress
BASE=http://localhost:8080 WP="wp --path=/path/to/wp" USER_LOGIN=sub USER_PASS=… tests/e2e/run-http.sh
(cd tests/e2e && npm ci && BASE=… PAGE_ID=… USER_LOGIN=sub USER_PASS=… npm run browser-check)   # needs Google Chrome
```

## Uninstall

Deleting the plugin from **Plugins** removes its settings and transients. It creates no tables and no AWS
resources.

## License

This library is licensed under the MIT-0 License. See the [LICENSE](LICENSE) file.
