# Threat model

Scope: the `ai-chat-for-amazon-bedrock` WordPress plugin in this repository (version 1.0.0), deployed on a
WordPress site that calls Amazon Bedrock in the site owner's AWS account. Method: data-flow diagram, trust
boundaries, STRIDE per element. Status of each mitigation refers to code in this repository and the tests
under `tests/`.

## System overview

| Element | Description |
|---|---|
| Visitor browser | Renders the chat (`public/js/chat.js`), keeps the conversation in memory, calls the REST endpoint. |
| REST endpoint | `POST /wp-json/aicfab/v1/chat` (`Rest_Controller`). The only public entry point. |
| Chat service | Builds the Converse request, runs the tool loop on the server (`Chat_Service`, `Tool_Registry`). |
| Bedrock client | SigV4-signed HTTPS to `bedrock-runtime.<region>.amazonaws.com` (`Bedrock_Client`, `SigV4`, `Event_Stream_Decoder`). |
| Credential provider | wp-config constants, environment, web identity, container credentials, IMDSv2, or an encrypted stored key (`Credentials`, `Secret_Store`). |
| Site tools | Read-only access to published, public posts and pages (`Site_Tools`). |
| MCP client | JSON-RPC over HTTPS to administrator-configured MCP servers (`Mcp_Client`, `Mcp_Servers`, `Url_Guard`). |
| Admin UI | Settings, connection test, MCP server management; `manage_options` only (`Admin`). |
| Data stores | `wp_options`: `aicfab_settings` (incl. encrypted key), `aicfab_mcp_servers` (incl. encrypted tokens); transients for rate-limit counters and cached temporary credentials (encrypted). No conversation storage. |

## Data flow and trust boundaries

```
 TB1 (internet)              TB2 (WordPress process)                              TB3 (AWS)            TB4 (third party)
┌───────────────┐  HTTPS   ┌─────────────────────────────────────────────┐ HTTPS ┌───────────────┐
│ Visitor       │─────────▶│ REST /aicfab/v1/chat                         │ SigV4 │ Amazon        │
│ browser       │◀─────────│  access policy → nonce → validate → limit    │──────▶│ Bedrock       │
└───────────────┘ JSON/SSE │  Chat_Service ── tool loop ──┬─ Site_Tools   │◀──────│ Runtime       │
                           │                              │   (wp_posts)  │       └───────────────┘
┌───────────────┐  HTTPS   │                              └─ Mcp_Client ──┼──────────────────────────▶┌──────────────┐
│ Administrator │─────────▶│ Admin (manage_options + nonces)               │  HTTPS, public IPs only   │ MCP server   │
└───────────────┘          │ Credentials ── IMDS / container / STS ────────┼──▶ (link-local / AWS)     └──────────────┘
                           │ wp_options: settings, encrypted secrets       │
                           └─────────────────────────────────────────────┘
```

- **TB1** browser ↔ WordPress: all request content is untrusted, including the conversation history.
- **TB2** inside WordPress: other plugins, themes and administrators share the process and database.
- **TB3** WordPress ↔ AWS: authenticated with SigV4; model output is untrusted.
- **TB4** WordPress ↔ MCP servers: third-party services chosen by an administrator; their tool lists and
  results are untrusted.

## Assets

1. AWS credentials able to invoke Bedrock (and the AWS budget they can spend).
2. Non-public site content (drafts, private and password-protected posts, user data).
3. Visitors' messages (may contain personal data).
4. Integrity of the site and of administrator sessions (no XSS, CSRF or privilege escalation).
5. Availability of the site and of the chat.
6. Internal network and cloud metadata endpoints reachable from the web server.

## Threats and mitigations

### Spoofing

| # | Threat | Mitigation | Evidence |
|---|---|---|---|
| S1 | An anonymous visitor uses the chat when only users should. | Default access is *logged-in users*; `permission_callback` enforces the setting. The front end shows a login link instead of the widget. | `run-http.sh`: anonymous 401; `access=disabled` 401. |
| S2 | A third-party page makes a logged-in user's browser call the endpoint (CSRF). | WordPress core authenticates cookie REST requests only with a valid `wp_rest` nonce; without it the user is treated as anonymous and refused. | `run-http.sh`: cookie without nonce 401. |
| S3 | A forged `X-Forwarded-For` evades the per-visitor limit. | Forwarded headers are ignored; `REMOTE_ADDR` is used, overridable only through the `aicfab_client_ip` filter by the site owner. | Code review (`Rate_Limiter::identity`). |
| S4 | A malicious host serves a fake credentials endpoint. | Container credentials use only `AWS_CONTAINER_CREDENTIALS_*` set by the platform, with the SDK host allowlist; IMDS is IMDSv2 only; redirects are disabled for all credential requests. | Code review (`Credentials`). |

### Tampering

| # | Threat | Mitigation | Evidence |
|---|---|---|---|
| T1 | The client rewrites history to inject a system prompt, tool calls or tool results. | History is reduced to plain-text `user`/`assistant` turns, alternation is enforced, size and count are bounded; unknown roles are rejected by the REST schema. The system prompt and tools are set only on the server. Tool calls come only from the model and run on the server. | `ValidationTest`; `run-http.sh`: unknown role 400, `toolUse` in history ignored. |
| T2 | Prompt injection (in a message, a post, or an MCP result) makes the model misuse tools. | Tools are read-only (site tools) or limited to administrator-reviewed, pinned MCP tools; site tools can read only content that is already public; at most 4 tool rounds; tool results are truncated. Recommended: a Bedrock Guardrail (setting provided; the admin screen warns when anonymous access is enabled without one). | `checks.php`; `run-http.sh`: codenames of non-public posts never returned. |
| T3 | An MCP server changes its tools after review. | Tool lists are fetched only when an administrator adds or refreshes a server and are pinned; chat requests never re-discover tools. | Code review (`Mcp_Servers`, `Tool_Registry`). |
| T4 | A modified or corrupted response stream. | TLS with peer and host verification; every event-stream message is CRC-checked; size limits on frames. | `EventStreamTest`. |
| T5 | Settings changed by a non-administrator. | Settings API with `manage_options`; `sanitize` refuses non-admins; admin-post handlers check capability and nonce. | `checks.php`: non-admin sanitize ignored. |

### Repudiation

| # | Threat | Mitigation | Evidence |
|---|---|---|---|
| R1 | No record of who changed settings or spent budget. | Out of scope for the plugin by design (it stores no conversations). Bedrock calls are recorded in AWS CloudTrail under the configured principal; README recommends a dedicated IAM role or user, and Bedrock model invocation logging (encrypted, restricted, with retention) where prompts and responses must be auditable. Sites needing per-user audit should add an activity-log plugin. | Documented residual risk. |

### Information disclosure

| # | Threat | Mitigation | Evidence |
|---|---|---|---|
| I1 | AWS secret key leaks from the database, the admin page or logs. | Preferred: IAM role or wp-config constants, so nothing is stored. A stored key is encrypted (XSalsa20-Poly1305, key derived from the site salts) and never rendered back (write-only field). Temporary credentials are cached encrypted. Nothing logs credentials, signing keys or authorization headers. | `checks.php`: ciphertext only, not in page HTML, tampering detected. |
| I2 | Non-public posts reach the model and then the visitor. | Site tools query `post_status=publish`, exclude password-protected posts, re-check each post and the viewable post type, and read `post_content` without running content filters. No admin email or version in `site_info`. | `checks.php`; `run-http.sh`. |
| I3 | Prompts or answers written to logs. | No prompts or responses are logged; the opt-in debug log writes error codes only. | Code review (`Chat_Service::log`). |
| I4 | AWS error details (account, ARNs) shown to visitors. | Visitors get a generic message; only administrators see the AWS error. | `run-http.sh`: non-admin generic 502. |
| I5 | Chat responses cached by a proxy/CDN. | `Cache-Control: no-store` on JSON and SSE responses. | `run-http.sh`. |
| I6 | Visitor messages sent to a third party. | Messages go to Bedrock in the configured Region, and to MCP servers only if an administrator enabled MCP; the README and admin UI say so. | Documentation. |

### Denial of service (including cost exhaustion)

| # | Threat | Mitigation | Evidence |
|---|---|---|---|
| D1 | Visitors spend the site owner's Bedrock budget. | Logged-in only by default; per-visitor per-minute limit and site-wide daily cap checked **before** calling Bedrock; bounded input, history and output tokens; at most 4 tool rounds. README requires an AWS Budgets alert and Bedrock quotas as the hard limit, since transient counters are best-effort under concurrency. | `run-http.sh`: 429 for rate limit and daily cap. |
| D2 | Long-running requests exhaust PHP workers. | Request timeouts (Bedrock 120 s/180 s, MCP 15 s, credentials 1–5 s); streaming stops when the client disconnects; rate limit bounds concurrency per visitor. | Code review. |
| D3 | Oversized or malicious responses (stream frames, MCP replies). | Event-stream frame cap (16 MiB, protocol maximum), MCP response cap 1 MB, tool results truncated to 8,000 characters, at most 200 MCP tools per server and 64 tools per request. | `EventStreamTest`; code review. |

### Elevation of privilege

| # | Threat | Mitigation | Evidence |
|---|---|---|---|
| E1 | Stored or reflected XSS from model output, MCP tool descriptions or settings. | The chat renders all model output with `textContent`; admin views escape all output (`esc_html`, `esc_attr`, `esc_url`); WordPress Coding Standards security sniffs pass. | `browser-check.mjs`: HTML in model output not interpreted; `composer lint`. |
| E2 | SSRF through an MCP server URL (cloud metadata, internal services). | https only, no credentials in URL, every A/AAAA record must be a public unicast address (private, loopback, link-local, CGNAT, ULA, IPv4-mapped and NAT64 ranges rejected), `wp_safe_remote_post` re-validates at request time, redirects disabled. A local-development override exists and is documented as never-on-production. | `ValidationTest`, `checks.php`: metadata, loopback, private, userinfo, non-http schemes rejected. |
| E3 | The model invokes arbitrary code or PHP functions. | Tool names map to a fixed server-side registry; unknown names return an error; no `eval`, `unserialize`, shell or file writes. Semgrep (PHP, JS, secrets, security-audit, OWASP Top 10) reports no findings. | `composer lint`, Semgrep report. |
| E4 | The IAM principal has more rights than needed. | README provides a least-privilege policy limited to `bedrock:InvokeModel*` on the chosen model/profile. | Documentation. |

## Residual risks and assumptions

- WordPress core, PHP, the web server and other installed plugins are trusted and kept up to date; any
  code running in the same process can read the plugin's credentials (encryption at rest does not help
  against code execution).
- Model output may be inaccurate or inappropriate; a Bedrock Guardrail is recommended but not mandatory.
- Rate-limit counters are not atomic; AWS Budgets and service quotas are the hard backstop.
- An administrator who adds an MCP server trusts it with conversation content the model sends to it.
- When access is set to *Everyone*, any internet user can consume tokens up to the configured limits.

## Verification summary

| Check | Result |
|---|---|
| PHPUnit (SigV4 vs botocore, event stream, validation, SSRF policy, reasoning filter) | 33 tests pass |
| WordPress Coding Standards (incl. security sniffs) and PHPCompatibilityWP 7.4+ | 0 findings |
| In-WordPress checks (`tests/e2e/checks.php`) | 27 pass |
| HTTP end-to-end against Amazon Bedrock (`tests/e2e/run-http.sh`) | 26 pass |
| Browser checks (`tests/e2e/browser-check.mjs`) | 10 pass |
| Automated Security Helper v3.7.1, local mode: bandit, checkov, detect-secrets, grype, npm-audit, semgrep, syft | 0 actionable findings; 2 suppressed in `.ash/.ash.yaml` (expected SigV4 signatures in a test fixture, computed from fake credentials). cdk-nag/cfn-nag not applicable (no IaC). |
| gitleaks 8.30.1 (working tree and history) | 0 findings |
| `composer audit`, `npm audit` (development dependencies) | 0 advisories |
