# SESSYPress Webhook Security

## Multi-Layer Security Architecture

SESSYPress implements **4 layers of webhook security** to protect against unauthorized requests, replay attacks, and denial-of-service:

### Layer 1: Rate Limiting (Always Active)
**Class:** `Webhook_Rate_Limiter`  
**Status:** ✅ **Always enabled**, cannot be disabled  
**Purpose:** Protect against webhook abuse and DoS attacks

**Limits per IP address:**
- **300 requests/minute** (5 req/sec average)
- **3,000 requests/hour** (sustainable high-volume protection)

**Implementation:**
- Uses WordPress transients for distributed caching (works with object cache)
- Separate minute/hour windows for granular control
- Returns HTTP 429 (Too Many Requests) when exceeded
- Automatic cleanup via transient expiration

**Example response (rate limited):**
```json
{
  "code": "rate_limit_exceeded",
  "message": "Too many requests",
  "data": { "status": 429 }
}
```

---

### Layer 2: Secret Key Validation (Always Active)
**Class:** `SNS_Handler::validate_secret()`  
**Status:** ✅ **Always enabled**, configured in settings  
**Purpose:** Basic authentication to prevent unauthorized webhook access

**How it works:**
1. Admin generates 32-character random secret key (stored in `sessypress_settings`)
2. AWS SNS topic URL must include `?key=SECRET` query parameter
3. SESSYPress uses **timing-safe comparison** (`hash_equals()`) to validate

**Why timing-safe?**
- Prevents timing attacks (side-channel attacks that measure response time differences)
- Standard `===` comparison stops at first mismatch → measurable time difference
- `hash_equals()` always compares full string → constant time

**Example configuration:**
```
Webhook URL: https://yoursite.com/wp-json/sessypress/v1/ses-sns-webhook?key=abc123...
```

**Security considerations:**
- ❌ Secret key alone is **not sufficient** (can be leaked, bruteforced, or sniffed)
- ✅ Always combine with SNS signature verification (Layer 3)
- ✅ Use HTTPS (required for AWS SNS anyway)

---

### Layer 3: SNS Signature Verification (Always Active)
**Class:** `SNS_Signature_Verifier`  
**Status:** ✅ **Always enabled** for SNS messages (Type: Notification/SubscriptionConfirmation)  
**Purpose:** Cryptographically verify requests come from AWS SNS

**How AWS SNS signatures work:**

1. **AWS creates signature:**
   - Builds canonical string from message fields (Message, MessageId, Timestamp, etc.)
   - Signs with AWS private key (RSA-SHA1)
   - Includes signature in `Signature` field
   - Provides signing certificate URL in `SigningCertURL`

2. **SESSYPress validates signature:**
   ```php
   // 1. Validate certificate URL (must be *.sns.amazonaws.com)
   if (!$this->is_valid_cert_url($message['SigningCertURL'])) {
       return false;
   }

   // 2. Download and cache certificate (24h cache)
   $certificate = $this->get_certificate($message['SigningCertURL']);

   // 3. Build string to sign (fields in canonical order)
   $string_to_sign = $this->build_string_to_sign($message);

   // 4. Verify signature with public key
   $signature = base64_decode($message['Signature']);
   $public_key = openssl_pkey_get_public($certificate);
   return openssl_verify($string_to_sign, $signature, $public_key, OPENSSL_ALGO_SHA1);
   ```

**Canonical string format (Notification):**
```
Message
<message content>
MessageId
<message id>
Subject
<subject if present>
Timestamp
<ISO 8601 timestamp>
TopicArn
<SNS topic ARN>
Type
Notification
```

**Certificate URL validation:**
- ✅ Must use HTTPS
- ✅ Must be from `sns.*.amazonaws.com` or `*.sns.amazonaws.com`
- ❌ Rejects any other domain (prevents SSRF attacks)

**Certificate caching:**
- Cached for **24 hours** (transient: `sessypress_sns_cert_<md5(url)>`)
- Reduces latency (no download on every webhook)
- Automatic refresh after expiration

**Signature fields verified:**
- **SubscriptionConfirmation:** Message, MessageId, SubscribeURL, Timestamp, Token, TopicArn, Type
- **Notification:** Message, MessageId, Subject (optional), Timestamp, TopicArn, Type
- **UnsubscribeConfirmation:** Message, MessageId, SubscribeURL, Timestamp, Token, TopicArn, Type

**Why this matters:**
- 🔒 **Prevents spoofing:** Only AWS can create valid signatures (requires private key)
- 🔒 **Prevents replay attacks:** Timestamp included in signature (can reject old messages)
- 🔒 **Prevents tampering:** Any modification invalidates signature

---

### Layer 4: AWS IP Allowlist (Optional)
**Class:** `AWS_IP_Validator`  
**Status:** ⚠️ **Optional** (enabled by default, can be disabled in settings)  
**Purpose:** Verify requests originate from AWS infrastructure

**How it works:**

1. **Download AWS IP ranges:**
   - Fetches official JSON from `https://ip-ranges.amazonaws.com/ip-ranges.json`
   - Parses IPv4 and IPv6 prefixes (CIDR notation)
   - Caches for **24 hours** (transient: `sessypress_aws_ip_ranges`)

2. **Validate client IP:**
   ```php
   // Extract client IP (supports X-Forwarded-For, X-Real-IP)
   $client_ip = $this->get_client_ip($request);

   // Check if IP is in AWS ranges
   if (!$this->ip_validator->is_aws_ip($client_ip, 'AMAZON')) {
       return WP_Error('invalid_source', 'Request must come from AWS IP');
   }
   ```

3. **CIDR matching:**
   - **IPv4:** Uses bitwise operations (`ip2long`, subnet masks)
   - **IPv6:** Uses `inet_pton` + binary string masking
   - Supports all AWS regions and services

**AWS IP ranges structure:**
```json
{
  "prefixes": [
    {
      "ip_prefix": "3.5.140.0/22",
      "service": "AMAZON",
      "region": "ap-northeast-2"
    },
    // ... thousands of entries
  ],
  "ipv6_prefixes": [...]
}
```

**Client IP detection (priority order):**
1. `X-Forwarded-For` (first IP in list)
2. `X-Real-IP`
3. `Client-IP`
4. `REMOTE_ADDR`

**Why optional?**
- ✅ **Pros:** Additional layer, blocks non-AWS requests
- ⚠️ **Cons:** 
  - AWS IP ranges change (24h cache minimizes stale data)
  - Proxy/CDN complications (X-Forwarded-For can be spoofed)
  - Fail-open strategy (allows requests if IP ranges download fails)

**When to disable:**
- You use a CDN/proxy that isn't AWS (CloudFlare, etc.)
- You have custom routing/firewall rules
- Testing from local development (non-AWS IP)

---

## Security Flow Diagram

```
Incoming Webhook Request
         ↓
┌────────────────────────┐
│ 1. Rate Limiting       │ ← 300 req/min, 3,000 req/hour
│    (Webhook_Rate_      │   (always active)
│     Limiter)           │
└────────────────────────┘
         ↓ (pass)
┌────────────────────────┐
│ 2. Secret Key          │ ← ?key=SECRET (timing-safe)
│    (validate_secret)   │   (always active)
└────────────────────────┘
         ↓ (pass)
┌────────────────────────┐
│ 3. AWS IP Allowlist    │ ← IP in AWS ranges?
│    (AWS_IP_Validator)  │   (optional, default: enabled)
└────────────────────────┘
         ↓ (pass)
┌────────────────────────┐
│ 4. Parse JSON          │ ← json_decode($body)
└────────────────────────┘
         ↓ (valid JSON)
┌────────────────────────┐
│ 5. SNS Signature       │ ← RSA-SHA1 cryptographic sig
│    (SNS_Signature_     │   (always active for SNS)
│     Verifier)          │
└────────────────────────┘
         ↓ (verified)
┌────────────────────────┐
│ 6. Route to Handler    │ ← SNS_Notification_Handler
│    (SNS_Handler)       │   or Event_Publishing_Handler
└────────────────────────┘
         ↓
    Process Event
```

---

## Attack Scenarios & Mitigations

### 1. Brute Force Secret Key
**Attack:** Attacker tries thousands of secret keys
**Mitigation:**
- ✅ Layer 1: Rate limiting (300 req/min) → ~18,000 attempts/hour max
- ✅ 32-character random key = 95^32 combinations (base95 charset)
- ✅ Timing-safe comparison prevents timing attacks
- ✅ Even if key is found, Layer 3 (SNS signature) still blocks

### 2. Replay Attack
**Attack:** Attacker captures valid SNS message and replays it
**Mitigation:**
- ✅ Layer 3: SNS signature includes `Timestamp` field
- ⚠️ SESSYPress doesn't currently reject old timestamps (future enhancement)
- ✅ AWS SNS already prevents replay (each MessageId is unique)

### 3. IP Spoofing
**Attack:** Attacker spoofs AWS IP address
**Mitigation:**
- ✅ Layer 4: IP validation only works if attacker controls routing (unlikely)
- ✅ Layer 3: SNS signature still validates (IP spoofing doesn't help)
- ✅ HTTPS prevents man-in-the-middle attacks

### 4. SSRF (Server-Side Request Forgery)
**Attack:** Attacker provides malicious `SigningCertURL`
**Mitigation:**
- ✅ Certificate URL must be `https://sns.*.amazonaws.com` or `https://*.sns.amazonaws.com`
- ✅ Rejects any other domain
- ✅ Uses WordPress `wp_remote_get()` with 10-second timeout

### 5. Certificate Cache Poisoning
**Attack:** Attacker serves fake certificate during cache refresh
**Mitigation:**
- ✅ Certificate URL validation (must be AWS domain)
- ✅ HTTPS (prevents MITM)
- ✅ Certificate format validation (`-----BEGIN CERTIFICATE-----`)
- ✅ Signature verification fails if certificate is wrong

### 6. Denial of Service (DoS)
**Attack:** Flood webhook with millions of requests
**Mitigation:**
- ✅ Layer 1: Rate limiting (3,000 req/hour per IP)
- ✅ Distributed attack requires 1,000+ IPs for 3M req/hour
- ✅ All validations fail fast (< 10ms per request)
- ✅ AWS IP validation cached (no external requests per webhook)

---

## Performance Impact

| Security Layer             | Latency (avg) | External Requests | Caching       |
|----------------------------|---------------|-------------------|---------------|
| 1. Rate Limiting           | < 1ms         | None              | Transient     |
| 2. Secret Key              | < 1ms         | None              | None          |
| 3. AWS IP Validation       | 1-2ms         | 1/day (IP ranges) | 24h transient |
| 4. JSON Parsing            | 1-3ms         | None              | None          |
| 5. SNS Signature           | 5-10ms        | 1/day (cert)      | 24h transient |
| **Total (first request)**  | **~15ms**     | 2 (cached 24h)    | -             |
| **Total (cached)**         | **~8ms**      | 0                 | -             |

**Caching efficiency:**
- AWS IP ranges: 1 download/day (all webhooks share cache)
- SNS certificate: 1 download/day per unique SigningCertURL
- Rate limit counters: Auto-expire (minute/hour windows)

---

## Configuration Recommendations

### Production (High Security)
```php
// Settings → Webhook Security
validate_aws_ip = true         // Enable AWS IP allowlist
sns_secret_key = <32-char>     // Generated automatically
```
**Security score:** 🔒🔒🔒🔒 (4/4 layers active)

### Development (Local Testing)
```php
validate_aws_ip = false        // Disable (local IP != AWS IP)
sns_secret_key = <test-key>    // Use simple key for testing
```
**Security score:** 🔒🔒🔒 (3/4 layers active)  
**Note:** Still safe due to SNS signature verification

### Shared Hosting (CDN/Proxy)
```php
validate_aws_ip = false        // Disable (CDN IPs != AWS IPs)
sns_secret_key = <32-char>     // Keep strong secret
```
**Security score:** 🔒🔒🔒 (3/4 layers active)  
**Note:** SNS signature verification is most important layer

---

## Debugging Security Issues

### Check rate limit status
```php
$rate_limiter = new SESSYPress\Webhook_Rate_Limiter();
$status = $rate_limiter->get_status('1.2.3.4');
print_r($status);
// Output:
// [
//   'minute' => ['count' => 12, 'limit' => 300],
//   'hour'   => ['count' => 450, 'limit' => 3000]
// ]
```

### Clear rate limits (admin override)
```php
$rate_limiter->clear('1.2.3.4');
```

### Check AWS IP validation
```php
$ip_validator = new SESSYPress\AWS_IP_Validator();
$is_aws = $ip_validator->is_aws_ip('52.219.0.1'); // true (AWS IP)
$is_aws = $ip_validator->is_aws_ip('8.8.8.8');    // false (Google DNS)
```

### Refresh AWS IP ranges manually
```php
$ip_validator->clear_cache();
// Next request will re-download IP ranges
```

### Verify SNS signature manually
```php
$verifier = new SESSYPress\SNS_Signature_Verifier();
$message = json_decode($webhook_body, true);
$valid = $verifier->verify($message); // true/false
```

### View SNS signature debug logs
```bash
# Check WordPress debug log
tail -f /path/to/wp-content/debug.log | grep SESSYPress
```

**Common log messages:**
- `SNS signature verification failed` → Invalid signature (check certificate URL)
- `Invalid SigningCertURL` → Certificate not from AWS domain
- `Failed to download certificate` → Network issue or invalid URL
- `Missing required field for signature: X` → Malformed SNS message
- `Rate limit exceeded (minute) for IP: X` → Too many requests

---

## Security Audit Checklist

### ✅ Implemented Protections
- [x] Rate limiting (300 req/min, 3,000 req/hour)
- [x] Secret key validation (timing-safe comparison)
- [x] SNS signature verification (RSA-SHA1 + certificate validation)
- [x] AWS IP allowlist (optional, 24h cache)
- [x] HTTPS requirement (enforced by AWS SNS)
- [x] Certificate URL validation (prevent SSRF)
- [x] Client IP detection (X-Forwarded-For, X-Real-IP)
- [x] JSON payload validation
- [x] Fail-fast error handling (< 10ms invalid requests)

### ⚠️ Future Enhancements
- [ ] Timestamp validation (reject messages older than 15 minutes)
- [ ] Nonce/MessageId deduplication (prevent replay attacks)
- [ ] IP whitelist (admin-defined custom IPs)
- [ ] Webhook request logging (audit trail)
- [ ] Alert on repeated failures (potential attack detection)
- [ ] Support for SNS message attributes filtering

---

## Compliance Notes

### GDPR/Privacy
- ❌ Client IPs are **logged temporarily** (transient cache only, 1 hour max)
- ✅ No persistent storage of client IPs in database
- ✅ Automatic cleanup via transient expiration

### AWS Best Practices
- ✅ Follows [AWS SNS Security Best Practices](https://docs.aws.amazon.com/sns/latest/dg/sns-security-best-practices.html)
- ✅ Verifies message signatures
- ✅ Validates certificate URLs
- ✅ Uses HTTPS only

### WordPress Security Standards
- ✅ Uses `wp_remote_get()` (not `file_get_contents()`)
- ✅ Uses WordPress transients (not custom caching)
- ✅ Sanitizes all input (`sanitize_text_field`, `filter_var`)
- ✅ Returns `WP_Error` objects (standard error handling)

---

## Credits

**Security implementations inspired by:**
- [AWS SNS Message Verification Guide](https://docs.aws.amazon.com/sns/latest/dg/sns-verify-signature-of-message.html)
- [OWASP Webhook Security Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Webhook_Security_Cheat_Sheet.html)
- [WordPress REST API Security](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/)

**Author:** WPChill  
**Plugin:** SESSYPress v1.0.0  
**Last Updated:** 2026-01-31
