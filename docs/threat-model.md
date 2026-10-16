# Threat model

Scope: the `ai-chat-for-amazon-bedrock` WordPress plugin in this repository (version 1.0.0), deployed on a
WordPress site that runs on AWS and calls Amazon Bedrock in the site owner's account. Method: data-flow
diagram, trust boundaries, STRIDE per element. Evidence refers to code and tests in this repository.

## System overview

| Element | Description |
|---|---|
| Visitor browser | Renders the chat (`public/js/chat.js`), keeps the conversation in memory, calls the REST endpoint. |
| REST endpoint | `POST /wp-json/aicfab/v1/chat` (`Rest_Controller`). The only public entry point. |
| Chat service | Builds the Converse request, runs the tool loop on the server (`Chat_Service`, `Tool_Registry`). |
| Bedrock client | AWS SDK for PHP `BedrockRuntimeClient` (`Bedrock_Client`, `Stream_State`). Signing, TLS, retries and credential resolution are done by the SDK. |
| Site tools | Read-only access to published, public posts and pages (`Site_Tools`). |
| Admin UI | Settings and connection test; `manage_options` only (`Admin`). |
| Data stores | `wp_options` `aicfab_settings` (no credentials); transients for rate-limit counters and admin notices. No conversation storage. |

## Data flow and trust boundaries

```
 TB1 (internet)              TB2 (WordPress process on AWS)                            TB3 (AWS)
┌───────────────┐  HTTPS   ┌──────────────────────────────────────────────┐  HTTPS  ┌──────────────────┐
│ Visitor       │─────────▶│ REST /aicfab/v1/chat                          │  SigV4  │ Amazon Bedrock   │
│ browser       │◀─────────│  access policy → nonce → validate → limit     │────────▶│ Runtime          │
└───────────────┘ JSON/SSE │  Chat_Service ── tool loop ── Site_Tools      │◀────────│ (+ Guardrail)    │
                           │                               (wp_posts, read) │         └──────────────────┘
┌───────────────┐  HTTPS   │ Admin (manage_options + nonces)               │         ┌──────────────────┐
│ Administrator │─────────▶│ AWS SDK default credential chain ─────────────┼────────▶│ IMDSv2 / ECS /   │
└───────────────┘          │ wp_options: settings (no secrets)             │         │ EKS / STS        │
                           └──────────────────────────────────────────────┘         └──────────────────┘
```

- **TB1** browser ↔ WordPress: all request content is untrusted, including the conversation history.
- **TB2** inside WordPress: other plugins, themes and administrators share the process and database.
- **TB3** WordPress ↔ AWS: authenticated by the SDK with the host's IAM role; model output is untrusted.

## Assets

1. The IAM role's permission to invoke Bedrock (and the AWS budget it can spend).
2. Non-public site content (drafts, private and password-protected posts, user data).
3. Visitors' messages (may contain personal data).
4. Integrity of the site and of administrator sessions (no XSS, CSRF or privilege escalation).
5. Availability of the site and of the chat.

## Threats and mitigations

### Spoofing

| # | Threat | Mitigation | Evidence |
|---|---|---|---|
| S1 | An anonymous visitor uses the chat when only users should. | Default access is *logged-in users*; `permission_callback` enforces the setting; the front end shows a login link instead of the widget. | `run-http.sh`: anonymous 401; `access=disabled` 401. |
| S2 | A third-party page makes a logged-in user's browser call the endpoint (CSRF). | WordPress core authenticates cookie REST requests only with a valid `wp_rest` nonce; without it the user is treated as anonymous and refused. | `run-http.sh`: cookie without nonce 401. |
| S3 | A forged `X-Forwarded-For` evades the per-visitor limit. | Forwarded headers are ignored; `REMOTE_ADDR` is used, overridable only by the site owner through the `aicfab_client_ip` filter. | Code review (`Rate_Limiter::identity`). |
| S4 | Requests to AWS impersonated or credentials obtained from a fake endpoint. | The AWS SDK signs every request and resolves credentials only from the standard providers (IMDSv2, ECS/EKS, web identity, environment); the plugin has no credential code of its own. | Code review; AWS SDK for PHP. |

### Tampering

| # | Threat | Mitigation | Evidence |
|---|---|---|---|
| T1 | The client rewrites history to inject a system prompt, tool calls or tool results. | History is reduced to plain-text `user`/`assistant` turns, alternation is enforced, size and count are bounded; unknown roles are rejected by the REST schema. System prompt and tools are set only on the server; tool calls come only from the model and run on the server. | `ValidationTest`; `run-http.sh`: unknown role 400, `toolUse` in history ignored. |
| T2 | Prompt injection (in a message or a post) makes the model misuse tools. | The only tools are read-only and can read only content that is already public; at most 4 tool rounds; tool results are truncated. Bedrock Guardrail supported; the admin screen warns when anonymous access is enabled without one. | `checks.php`; `run-http.sh`: codenames of non-public posts never returned. |
| T3 | Settings changed by a non-administrator. | Settings API with `manage_options`; `sanitize` refuses non-admins and drops unknown fields; admin-post handlers check capability and nonce. | `checks.php`. |

### Repudiation

| # | Threat | Mitigation | Evidence |
|---|---|---|---|
| R1 | No record of who used the model or changed settings. | Bedrock calls are recorded in AWS CloudTrail under the host's IAM role. README recommends Bedrock model invocation logging (encrypted, restricted, with retention) where prompts and responses must be auditable. Sites needing per-user audit should add an activity-log plugin. | Documented residual risk. |

### Information disclosure

| # | Threat | Mitigation | Evidence |
|---|---|---|---|
| I1 | AWS credentials leak from WordPress. | The plugin stores no AWS credentials and has no credential fields; the SDK uses short-lived role credentials. | `checks.php`: no credential inputs, unknown fields dropped; browser check. |
| I2 | Non-public posts reach the model and then the visitor. | Site tools query `post_status=publish`, exclude password-protected posts, re-check each post and the viewable post type, and read `post_content` without running content filters. `site_info` returns only name, tagline and URL. | `checks.php`; `run-http.sh`. |
| I3 | Prompts or answers written to logs. | No prompts or responses are logged; the opt-in debug log writes error codes only. | Code review (`Chat_Service::log`). |
| I4 | AWS error details (account, ARNs) shown to visitors. | Visitors get a generic message; only administrators see the AWS error. | `run-http.sh`: non-admin generic 502. |
| I5 | Chat responses cached by a proxy/CDN. | `Cache-Control: no-store` on JSON and SSE responses. | `run-http.sh`. |
| I6 | Visitor messages sent to a third party. | Messages go only to Amazon Bedrock in the configured Region; the plugin registers suggested privacy-policy text saying so. | Browser check. |

### Denial of service (including cost exhaustion)

| # | Threat | Mitigation | Evidence |
|---|---|---|---|
| D1 | Visitors spend the site owner's Bedrock budget. | Logged-in only by default; per-visitor 60-second window and site-wide daily cap checked **before** calling Bedrock; bounded input, history and output tokens; at most 4 tool rounds. README requires an AWS Budgets alert and Bedrock quotas as the hard limit because transient counters are best-effort under concurrency. | `run-http.sh`: 429 for rate limit and daily cap. |
| D2 | Long-running requests exhaust PHP workers. | SDK timeouts (connect 10 s, request 180 s) with standard retry mode (3 attempts); streaming stops when the client disconnects; rate limit bounds concurrency per visitor. | Code review. |
| D3 | Oversized tool results. | Tool results truncated to 8,000 characters; at most 5 search results; post content capped. | Code review. |

### Elevation of privilege

| # | Threat | Mitigation | Evidence |
|---|---|---|---|
| E1 | Stored or reflected XSS from model output or settings. | The chat renders all model output with `textContent`; admin views escape all output; WordPress Coding Standards security sniffs pass. | Browser check: HTML in model output not interpreted; `composer lint`. |
| E2 | The model invokes arbitrary code or PHP functions. | Tool names map to a fixed server-side registry of three read-only functions; unknown names return an error; no `eval`, `unserialize`, shell or file writes. | `composer lint`; Probe and ASH (semgrep, bandit). |
| E3 | The IAM role has more rights than needed. | README provides a least-privilege policy limited to `bedrock:InvokeModel*` on the chosen inference profile and models. | Documentation. |
| E4 | A vulnerable third-party dependency. | Only the AWS SDK for PHP and its dependencies, pinned in `composer.lock`; scanned by grype (Probe/ASH) and `composer audit`; SBOM produced by syft. | Probe; ASH. |

## Residual risks and assumptions

- WordPress core, PHP, the web server and other installed plugins are trusted and kept up to date; any code in
  the same process can use the host's IAM role.
- Another plugin that bundles a different Guzzle or AWS SDK version can conflict at class-loading time; this is
  a compatibility risk, not a security boundary.
- Model output may be inaccurate or inappropriate; a Bedrock Guardrail is recommended but not mandatory.
- Rate-limit counters are not atomic; AWS Budgets and service quotas are the hard backstop.
- When access is set to *Everyone*, any internet user can consume tokens up to the configured limits.

## Verification summary

| Check | Result |
|---|---|
| PHPUnit (AWS SDK mocked: request mapping, error mapping, stream assembly; input validation; reasoning filter) | 12 tests pass |
| WordPress Coding Standards (incl. security sniffs) and PHPCompatibilityWP 8.1+ | 0 findings |
| In-WordPress checks (`tests/e2e/checks.php`), plugin installed from the built package | 14 pass |
| HTTP end-to-end against Amazon Bedrock (`tests/e2e/run-http.sh`) | 23 pass (3 consecutive runs) |
| Browser checks (`tests/e2e/browser-check.mjs`) | 10 pass |
| Probe (GitLab, automatic on push) and ASH v3.7.1 | see the review ticket |
