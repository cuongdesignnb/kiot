# KIOT PC Integration Phase 2A — Management Foundation

## Scope

PR 2A adds the KIOT-side management foundation only:

- database-backed Website PC clients;
- encrypted current and previous HMAC secrets;
- one-time pairing tokens;
- environment fallback and one-time import;
- management UI and permissions;
- signed connection endpoint;
- integration audit events.

Website PC UI changes and Google Sheets are intentionally outside this PR.

## Runtime configuration

The runtime resolver selects exactly one complete source:

1. If any non-deleted `pc_website` client exists in `integration_clients`, only database configuration is eligible.
2. If no database client exists, Phase 1 environment configuration is used as a fallback.
3. Missing, ambiguous, disabled, revoked or incomplete configuration fails closed.

Business services obtain branch, sales channel and reservation TTL through `PcIntegrationRuntimeConfiguration`. They do not combine values from database and environment.

The management UI defaults to read-only because:

```dotenv
PC_INTEGRATION_MANAGEMENT_UI_ENABLED=false
```

Enabling the management UI does not enable the Website PC API client. Each database client starts with `is_enabled=false`.

## Security model

- Laravel encrypted casts protect current and previous secrets at rest.
- Plaintext secrets are returned only by create, rotate and successful one-time pairing responses.
- Page props, audit properties and normal logs never contain plaintext credentials.
- UI modals clear plaintext from component state when closed.
- Secret fingerprints are the first eight hexadecimal characters of SHA-256.
- Rotation moves the current secret to the encrypted previous-secret field for at most 15 minutes.
- HMAC verification checks the current secret first and the previous secret only while its grace timestamp is valid.
- Pairing stores only `SHA-256(pairing_code)`, uses constant-time comparison, expires after at most 10 minutes and allows at most five failed attempts.
- Pairing validates the configured Website URL and requires HTTPS in production.
- Nonce replay protection, timestamp checks, raw-body signing and per-client rate limits remain unchanged from Phase 1.

## API additions

### Pairing

```http
POST /api/integrations/v1/pc/pair
```

Request:

```json
{
  "reference": "one-time-public-reference",
  "pairing_code": "one-time-secret-code",
  "website_url": "https://admin.example.vn"
}
```

The route is not HMAC-signed because it creates the first credential. It is protected by the management rollout flag, HTTPS policy, one-time token, TTL, attempt limit and rate limiting.

### Connection handshake

```http
GET /api/integrations/v1/pc/connection
```

This route uses the existing Phase 1 canonical HMAC contract:

```text
METHOD\nPATH\nTIMESTAMP\nNONCE\nSHA256_BODY
```

The response reports server time, API version, configuration source and implemented capabilities. `price_books` and `google_sheets` remain `false` until PR 2C.

## Database changes

- `integration_clients`: additive client configuration and encrypted credential storage.
- `integration_pairing_tokens`: one-time hashed pairing material and audit metadata.

Both migrations are reversible and do not update product, stock, order, invoice, cashflow, debt or serial data.

## Rollback

1. Set `PC_INTEGRATION_MANAGEMENT_UI_ENABLED=false`.
2. Disable database clients from the UI before code rollback when possible.
3. Roll back the two Phase 2A migrations in reverse order.
4. Phase 1 environment configuration remains unchanged and becomes eligible again only after no database client rows exist.

Do not delete environment credentials automatically during import. They are the emergency rollback source.

## UAT checklist

- [ ] Read-only UI loads while the management flag is off.
- [ ] Owner can create a connection and sees the secret once.
- [ ] Reloading the page never returns the plaintext secret.
- [ ] A viewer cannot mutate or rotate credentials.
- [ ] Pairing succeeds once, then returns `PAIRING_TOKEN_USED`.
- [ ] Expired and wrong pairing codes fail closed.
- [ ] Current and unexpired previous secrets authenticate during rotation grace.
- [ ] Previous secret fails immediately after grace expiry.
- [ ] Signed connection endpoint records handshake time and returns no sensitive fields.
- [ ] Imported environment configuration works without a container restart.
- [ ] API client remains disabled until explicitly enabled.

## Known limitations

- The KIOT-side “Test Connection” button verifies local configuration readiness. The cross-system latency and clock-drift test is implemented in Website PC PR 2B.
- Google credential, template, preview, dry-run, manual sync and schedule functionality belongs to KIOT PR 2C.
- Order sync remains off and is a separate enablement checkpoint.
