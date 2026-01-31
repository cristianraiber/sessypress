# SESSYPress Troubleshooting Guide

This guide covers common issues and their solutions. For general questions, see [FAQ](FAQ.md).

## Table of Contents

- [Events Not Showing in Dashboard](#events-not-showing-in-dashboard)
- [SNS Subscription Not Confirming](#sns-subscription-not-confirming)
- [Invalid Secret Key Error](#invalid-secret-key-error)
- [Opens/Clicks Not Tracking](#opensclicks-not-tracking)
- [Duplicate Events](#duplicate-events)
- [Performance Issues with Large Datasets](#performance-issues-with-large-datasets)
- [Email Not Sending](#email-not-sending)
- [Webhook Timeout Errors](#webhook-timeout-errors)
- [Configuration Set Not Working](#configuration-set-not-working)
- [Database Errors](#database-errors)

---

## Events Not Showing in Dashboard

### Symptoms
- Dashboard shows "No events found"
- Events missing after sending test email
- Only some event types showing (e.g., no opens/clicks)

### Diagnosis

**Step 1: Check SNS Subscription Status**

1. Go to **SESSYPress** → **Settings**
2. Look for **SNS Endpoint Status**
3. Expected: "Confirmed ✅"
4. If "Pending" or "Not configured" → See [SNS Subscription Not Confirming](#sns-subscription-not-confirming)

**Step 2: Check Webhook Logs**

1. Go to **SESSYPress** → **Logs**
2. Enable debug logging if not already enabled
3. Send test email to `bounce@simulator.amazonses.com`
4. Refresh logs page
5. Look for incoming webhook request

**Expected log entry:**
```
[2026-01-31 10:00:00] INFO: SNS webhook received
[2026-01-31 10:00:01] INFO: Event type: Bounce
[2026-01-31 10:00:01] INFO: Event stored successfully (ID: 123)
```

**If no log entry:**
- Problem: AWS not sending events to WordPress
- Solution: Check AWS configuration (see below)

**If log shows errors:**
- Problem: WordPress receiving but failing to process
- Solution: Check error message and fix accordingly

### Solutions

#### Solution 1: Verify AWS SES Notifications Configuration

**For SNS Notifications (Bounce/Complaint/Delivery):**

1. Go to [AWS SES Console](https://console.aws.amazon.com/ses/)
2. Navigate to **Configuration** → **Verified identities**
3. Click your verified email or domain
4. Click **Notifications** tab
5. Verify each notification type:

| Notification Type | Expected Configuration |
|-------------------|------------------------|
| Bounce notifications | SNS topic: `ses-email-notifications` (or your topic) |
| Complaint notifications | SNS topic: `ses-email-notifications` |
| Delivery notifications | SNS topic: `ses-email-notifications` (optional) |

**If "No SNS topic":**
1. Click **Edit** for each notification type
2. Select your SNS topic from dropdown
3. Check "Include original headers" (recommended)
4. Click **Save changes**

**For Event Publishing (Open/Click/Send):**

1. Go to **Configuration** → **Configuration sets**
2. Click your configuration set
3. Go to **Event destinations** tab
4. Verify:
   - ✅ Event destination exists
   - ✅ Event types enabled: Send, Bounce, Complaint, Delivery, Open, Click
   - ✅ Destination type: Amazon SNS
   - ✅ SNS topic selected

**If no event destination:**
1. Click **Add destination**
2. Select all event types
3. Choose Amazon SNS as destination
4. Select your SNS topic
5. Click **Add destination**

#### Solution 2: Verify SNS Topic Subscription

1. Go to [AWS SNS Console](https://console.aws.amazon.com/sns/)
2. Click **Topics** in sidebar
3. Click your topic (e.g., `ses-email-notifications`)
4. Click **Subscriptions** tab
5. Verify subscription:
   - Protocol: HTTPS
   - Endpoint: `https://yoursite.com/wp-json/sessypress/v1/webhook?key=YOUR_KEY`
   - Status: **Confirmed**

**If status is "Pending confirmation":**
- See [SNS Subscription Not Confirming](#sns-subscription-not-confirming)

**If status is "Deleted" or subscription missing:**
1. Click **Create subscription**
2. Protocol: HTTPS
3. Endpoint: Get from **SESSYPress** → **Settings** (copy webhook URL)
4. Click **Create subscription**
5. Wait 1-2 minutes for auto-confirmation

#### Solution 3: Test with AWS Simulator

Verify your setup works by sending to AWS simulator addresses:

```bash
# Terminal or use WordPress admin to send test email
wp user create testbounce bounce@simulator.amazonses.com --role=subscriber --send-email
```

Or manually:
1. Go to **Users** → **Add New** in WordPress
2. Email: `bounce@simulator.amazonses.com`
3. Check "Send user notification"
4. Click **Add New User**

**Expected result:**
- Event appears in **SESSYPress** → **Dashboard** within 5 seconds
- Event type: Bounce
- Bounce type: Permanent

**Other test addresses:**
- `success@simulator.amazonses.com` → Delivery event
- `complaint@simulator.amazonses.com` → Complaint event
- `ooto@simulator.amazonses.com` → Soft bounce (out of office)

**If simulator works but real emails don't:**
- Problem: SES identity configuration
- Solution: Check that you're sending from verified identity

#### Solution 4: Check Database Table

Verify events are being stored in database:

1. Access your database via phpMyAdmin or MySQL client
2. Run query:
```sql
SELECT * FROM wp_ses_email_events 
ORDER BY timestamp DESC 
LIMIT 10;
```

**If table has events but dashboard empty:**
- Problem: Dashboard query issue
- Solution: Check for JavaScript errors in browser console

**If table is empty:**
- Problem: Events not being inserted
- Solution: Enable debug logging and check logs for database errors

**If table doesn't exist:**
- Problem: Plugin not installed correctly
- Solution: Deactivate and reactivate plugin to run installer

---

## SNS Subscription Not Confirming

### Symptoms
- SNS subscription status stuck on "Pending confirmation"
- WordPress not receiving confirmation request
- Events not showing in dashboard

### Diagnosis

**Check WordPress logs:**

1. Go to **SESSYPress** → **Logs**
2. Enable debug logging if needed
3. Look for log entries like:
```
[2026-01-31 10:00:00] INFO: SNS subscription confirmation received
[2026-01-31 10:00:01] INFO: Subscription confirmed successfully
```

**If no confirmation request in logs:**
- Problem: AWS can't reach your WordPress site
- Solutions below

### Solutions

#### Solution 1: Verify Webhook URL is Correct

1. Go to **SESSYPress** → **Settings**
2. Copy the **Webhook URL** shown (including secret key)
3. Go to AWS SNS Console → Topics → Your topic → Subscriptions
4. Check subscription endpoint URL matches exactly

**Common mistakes:**

❌ **Wrong:**
```
http://yoursite.com/wp-json/sessypress/v1/webhook?key=abc123
```
(HTTP instead of HTTPS)

❌ **Wrong:**
```
https://yoursite.com/wp-json/sessypress/v1/webhook
```
(Missing secret key)

✅ **Correct:**
```
https://yoursite.com/wp-json/sessypress/v1/webhook?key=abc123def456
```

**Fix:**
1. Delete incorrect subscription in SNS Console
2. Create new subscription with correct URL
3. Wait 1-2 minutes for auto-confirmation

#### Solution 2: Ensure Site is Publicly Accessible

AWS SNS needs to reach your WordPress site over the internet.

**Test accessibility:**

```bash
# From external server or another computer
curl -I https://yoursite.com/wp-json/sessypress/v1/webhook?key=YOUR_KEY
```

**Expected response:**
```
HTTP/2 200 OK
Content-Type: application/json
```

**If site is not accessible:**

**Localhost/local development:**
- ❌ AWS can't reach localhost (`localhost`, `127.0.0.1`, `.local`)
- Solutions:
  1. Use ngrok tunnel: `ngrok http 80`
  2. Deploy to staging server
  3. Use CloudFlare Tunnel
  4. Wait until production deployment

**Firewall blocking:**
- Check server firewall allows incoming HTTPS (port 443)
- Whitelist AWS SNS IP ranges (optional):
  - Download from: https://ip-ranges.amazonaws.com/ip-ranges.json
  - Filter for `SNS` service and your region

**Authentication required:**
- If site requires login (basic auth, IP whitelist)
- Exclude webhook endpoint: `/wp-json/sessypress/v1/webhook`

#### Solution 3: Check SSL Certificate

SNS requires valid HTTPS with trusted SSL certificate.

**Test SSL:**
```bash
curl -v https://yoursite.com/wp-json/sessypress/v1/webhook?key=YOUR_KEY
```

**Look for:**
```
* SSL connection using TLSv1.2 / ECDHE-RSA-AES128-GCM-SHA256
* Server certificate:
*  subject: CN=yoursite.com
*  SSL certificate verify ok.
```

**Common SSL issues:**

❌ **Self-signed certificate:**
- AWS SNS rejects self-signed certs
- Solution: Use Let's Encrypt (free, trusted)

❌ **Expired certificate:**
- Solution: Renew certificate

❌ **Mixed HTTP/HTTPS:**
- Site accessible via both HTTP and HTTPS
- Solution: Force HTTPS redirect

**Fix SSL with Let's Encrypt (if hosting on own server):**
```bash
# Install certbot
sudo apt-get install certbot python3-certbot-apache

# Get certificate
sudo certbot --apache -d yoursite.com
```

#### Solution 4: Check .htaccess or Nginx Config

WordPress REST API might be blocked by server config.

**For Apache (.htaccess):**

Check your `.htaccess` file for rules blocking `/wp-json/`:

```apache
# Ensure this is NOT in your .htaccess
# RewriteRule ^wp-json/ - [F,L]
```

**Allow REST API:**
```apache
# Allow WordPress REST API
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule ^wp-json/ - [L]
</IfModule>
```

**For Nginx:**

Check `nginx.conf` or site config:

```nginx
# Ensure REST API is allowed
location /wp-json/ {
    try_files $uri $uri/ /index.php?$args;
}
```

**Test REST API:**
```bash
curl https://yoursite.com/wp-json/
```

**Expected:** JSON response with WordPress REST API info

#### Solution 5: Manually Confirm Subscription (Last Resort)

If automatic confirmation fails, manually confirm:

1. Go to AWS SNS Console → Topics → Your topic → Subscriptions
2. Click the pending subscription
3. Look for **Subscription ARN** or **Token**
4. Copy the token value

5. In WordPress, run this in **Tools** → **Console** (if you have WP-CLI):
```bash
wp eval 'do_action("sessypress_confirm_subscription", "YOUR_TOKEN_HERE");'
```

Or add temporarily to `functions.php`:
```php
add_action( 'init', function() {
    $token = 'YOUR_TOKEN_HERE';
    // Call SNS confirm subscription endpoint
    wp_remote_get( 'https://sns.us-east-1.amazonaws.com/?' . http_build_query([
        'Action' => 'ConfirmSubscription',
        'TopicArn' => 'YOUR_TOPIC_ARN',
        'Token' => $token,
    ]));
});
```

**Note:** Remove this code after confirmation.

---

## Invalid Secret Key Error

### Symptoms
- Logs show: `[ERROR] Invalid secret key`
- SNS webhook returns 403 Forbidden
- Events not being stored

### Diagnosis

**Check logs:**

1. Go to **SESSYPress** → **Logs**
2. Look for entries like:
```
[2026-01-31 10:00:00] ERROR: Invalid secret key
[2026-01-31 10:00:00] INFO: Expected: abc123def456
[2026-01-31 10:00:00] INFO: Received: wrong_key_789
```

This shows the mismatch between expected and received keys.

### Solutions

#### Solution 1: Update SNS Subscription with Correct Key

1. Go to **SESSYPress** → **Settings**
2. Copy the **Secret Key** shown
3. Copy the **Webhook URL** (includes key in URL)
4. Go to AWS SNS Console → Topics → Your topic → Subscriptions
5. Delete old subscription
6. Create new subscription:
   - Protocol: HTTPS
   - Endpoint: Paste webhook URL from step 3
7. Click **Create subscription**

#### Solution 2: Regenerate Secret Key

If you suspect the key is compromised or want a fresh start:

1. Go to **SESSYPress** → **Settings**
2. Click **Regenerate Secret Key**
3. Copy new secret key
4. Update SNS subscription (see Solution 1)

**Important:** Regenerating invalidates old webhooks. Update ALL SNS subscriptions.

#### Solution 3: Check URL Encoding

Secret keys with special characters may cause issues.

**Test:**
```bash
# URL should have properly encoded key
curl -v "https://yoursite.com/wp-json/sessypress/v1/webhook?key=YOUR_KEY"
```

**If key has special characters (+, /, =):**
- URL encode them: `+` → `%2B`, `/` → `%2F`, `=` → `%3D`
- Or regenerate key to get alphanumeric only

#### Solution 4: Check for Multiple Subscriptions

If you have multiple SNS subscriptions with different keys:

1. Go to AWS SNS Console → Topics → Your topic → Subscriptions
2. Look for duplicate subscriptions
3. Delete old/incorrect subscriptions
4. Keep only the one with current secret key

---

## Opens/Clicks Not Tracking

### Symptoms
- Send, Bounce, Complaint events working
- Open events not appearing
- Click events not appearing
- Or partial tracking (opens work, clicks don't)

### Diagnosis

**Check prerequisites:**

- [ ] Using Event Publishing (Configuration Set)
- [ ] Configuration Set has open/click tracking enabled
- [ ] Emails include `X-SES-CONFIGURATION-SET` header
- [ ] Emails are HTML format (not plain text)
- [ ] Email contains links (for click tracking)

### Solutions

#### Solution 1: Enable Native Tracking in Configuration Set

1. Go to AWS SES Console → **Configuration sets**
2. Click your configuration set
3. Under **Open and click tracking**, click **Edit**
4. Check both:
   - ☑️ Enable open tracking
   - ☑️ Enable click tracking
5. Click **Save changes**

#### Solution 2: Verify Configuration Set Header

**Check if header is being added:**

1. Send test email to your address
2. View email source/headers
3. Look for:
```
X-SES-CONFIGURATION-SET: sessypress-tracking
```

**If missing, add in WordPress:**

Go to **SESSYPress** → **Settings** and ensure:
- Event Publishing is enabled
- Configuration Set name is correct

Or add manually in `functions.php`:
```php
add_filter( 'wp_mail', function( $args ) {
    if ( ! isset( $args['headers'] ) ) {
        $args['headers'] = [];
    }
    
    if ( is_string( $args['headers'] ) ) {
        $args['headers'] .= "\nX-SES-CONFIGURATION-SET: sessypress-tracking";
    } else {
        $args['headers'][] = 'X-SES-CONFIGURATION-SET: sessypress-tracking';
    }
    
    return $args;
}, 10, 1 );
```

#### Solution 3: Use HTML Emails

Open/click tracking requires HTML emails. Plain text emails cannot be tracked.

**Test email format:**

```php
// Plain text (won't track)
wp_mail( 'user@example.com', 'Subject', 'Plain text body' );

// HTML (will track)
wp_mail( 
    'user@example.com', 
    'Subject', 
    '<html><body><p>HTML body</p><a href="https://example.com">Link</a></body></html>',
    ['Content-Type: text/html; charset=UTF-8']
);
```

**Ensure Content-Type header:**
```php
$headers = ['Content-Type: text/html; charset=UTF-8'];
wp_mail( $to, $subject, $html_message, $headers );
```

#### Solution 4: Check Email Client Settings

**For open tracking:**

Many email clients block tracking pixels by default:

- **Gmail:** May cache pixels (opens may not be real-time)
- **Apple Mail (iOS 15+):** Privacy Protection prefetches images
- **Outlook:** Blocks external images by default

**Test with different client:**
1. Send to Gmail account
2. Send to Outlook.com account
3. Send to Yahoo account
4. Compare open tracking results

**For click tracking:**

Clicks should work in all clients. If not:

**Check link format:**

❌ **Won't track:**
```html
http://example.com (plain text URL)
```

✅ **Will track:**
```html
<a href="http://example.com">Click here</a>
```

**Links must be:**
- Wrapped in `<a>` tags
- Valid HTTP or HTTPS URLs
- Not `mailto:`, `tel:`, or anchor links

#### Solution 5: Verify Event Destination Includes Open/Click

1. Go to AWS SES Console → **Configuration sets**
2. Click your configuration set
3. **Event destinations** tab
4. Click your event destination
5. Verify event types include:
   - ☑️ Opens
   - ☑️ Clicks

**If unchecked:**
1. Click **Edit**
2. Check Opens and Clicks
3. Click **Save changes**

---

## Duplicate Events

### Symptoms
- Same event appearing multiple times
- Open events triggered repeatedly
- Database growing faster than expected

### Diagnosis

**Check event source:**

1. Go to **SESSYPress** → **Dashboard**
2. Look at **Event Source** column
3. If you see both:
   - `sns_notification`
   - `event_publishing`
   
For the same message_id and event type → You have duplicates.

### Solutions

#### Solution 1: Use Event Publishing Only (Recommended)

Disable SNS Notifications to avoid duplicates:

**Step 1: Disable SNS Notifications in SES**

1. Go to AWS SES Console → **Verified identities**
2. Click your identity
3. **Notifications** tab
4. For each notification type, click **Edit**:
   - Bounce notifications: **-** (disabled)
   - Complaint notifications: **-** (disabled)
   - Delivery notifications: **-** (disabled)
5. Click **Save changes**

**Step 2: Keep Event Publishing**

- Configuration Set event destination should have all event types enabled
- This gives you all events from one source (no duplicates)

#### Solution 2: Use SNS Notifications Only (Limited)

If you don't need opens/clicks:

**Step 1: Disable Event Publishing**

1. Remove Configuration Set header from emails
2. Go to **SESSYPress** → **Settings**
3. Uncheck "Enable Event Publishing"

**Step 2: Keep SNS Notifications**

- Leave SNS topics assigned to verified identity
- Only tracks: Bounce, Complaint, Delivery

**Downside:** No open/click tracking.

#### Solution 3: Filter Event Types in Event Publishing

If you want both methods for different event types:

**SNS Notifications:** Bounce, Complaint, Delivery  
**Event Publishing:** Open, Click, Send only

1. Go to AWS SES Configuration Set → Event destinations
2. Click **Edit** on event destination
3. Uncheck:
   - ☐ Bounces
   - ☐ Complaints
   - ☐ Deliveries
4. Keep checked:
   - ☑️ Sends
   - ☑️ Opens
   - ☑️ Clicks

**Result:** No duplicate bounces/complaints/deliveries.

#### Solution 4: Database Deduplication Query

If you already have duplicates, clean them up:

```sql
-- Find duplicates
SELECT 
    message_id, 
    event_type, 
    recipient, 
    COUNT(*) as count
FROM wp_ses_email_events
GROUP BY message_id, event_type, recipient
HAVING count > 1;

-- Delete duplicates (keep oldest)
DELETE e1 FROM wp_ses_email_events e1
INNER JOIN wp_ses_email_events e2 
WHERE 
    e1.id > e2.id
    AND e1.message_id = e2.message_id
    AND e1.event_type = e2.event_type
    AND e1.recipient = e2.recipient;
```

**Backup first:**
```sql
CREATE TABLE wp_ses_email_events_backup AS 
SELECT * FROM wp_ses_email_events;
```

---

## Performance Issues with Large Datasets

### Symptoms
- Dashboard takes > 5 seconds to load
- Database queries timing out
- High server CPU/memory usage
- WordPress admin sluggish

### Diagnosis

**Check event count:**

```sql
SELECT COUNT(*) FROM wp_ses_email_events;
```

**Performance benchmarks:**

| Event Count | Expected Load Time | Action |
|-------------|-------------------|--------|
| < 10,000 | < 1 second | Normal |
| 10,000 - 100,000 | 1-3 seconds | Optimize |
| 100,000 - 1,000,000 | 3-10 seconds | Archive old events |
| > 1,000,000 | 10+ seconds | Critical - clean up now |

### Solutions

#### Solution 1: Optimize Database Table

Run database optimization:

```sql
-- Optimize table
OPTIMIZE TABLE wp_ses_email_events;

-- Analyze table
ANALYZE TABLE wp_ses_email_events;
```

Or via WordPress:

1. Install **WP-Optimize** plugin
2. Go to **WP-Optimize** → **Database**
3. Select `wp_ses_email_events`
4. Click **Optimize**

#### Solution 2: Add Missing Indexes

Verify indexes exist:

```sql
SHOW INDEX FROM wp_ses_email_events;
```

**Required indexes:**
- `PRIMARY` (id)
- `idx_message_id` (message_id)
- `idx_event_type` (event_type)
- `idx_event_source` (event_source)
- `idx_timestamp` (timestamp)
- `idx_recipient` (recipient)

**If missing, add:**

```sql
ALTER TABLE wp_ses_email_events
ADD INDEX idx_event_type (event_type),
ADD INDEX idx_event_source (event_source),
ADD INDEX idx_timestamp (timestamp),
ADD INDEX idx_recipient (recipient);
```

#### Solution 3: Archive Old Events

**Automatic cleanup:**

1. Go to **SESSYPress** → **Settings**
2. Under **Data Retention**:
   - Auto-delete events older than: **90 days**
3. Click **Save Settings**
4. Click **Delete Old Events Now**

**Manual archive query:**

```sql
-- Archive events older than 90 days to separate table
CREATE TABLE wp_ses_email_events_archive LIKE wp_ses_email_events;

INSERT INTO wp_ses_email_events_archive
SELECT * FROM wp_ses_email_events
WHERE timestamp < DATE_SUB(NOW(), INTERVAL 90 DAY);

DELETE FROM wp_ses_email_events
WHERE timestamp < DATE_SUB(NOW(), INTERVAL 90 DAY);

OPTIMIZE TABLE wp_ses_email_events;
```

#### Solution 4: Disable Delivery Events

Delivery events create the most volume (every successful email).

**In SNS Notifications:**
1. Go to AWS SES → Verified identities → Your identity
2. Notifications tab → Delivery notifications
3. Click **Edit** → Select **-** (disabled)

**In Event Publishing:**
1. Go to Configuration sets → Your set → Event destinations
2. Click **Edit**
3. Uncheck **Deliveries**
4. Click **Save**

**Impact:** Reduces event volume by 30-50% (depending on bounce/complaint rates).

#### Solution 5: Increase PHP Memory Limit

If queries are timing out:

**In `wp-config.php`:**
```php
define( 'WP_MEMORY_LIMIT', '256M' );
define( 'WP_MAX_MEMORY_LIMIT', '512M' );
```

**In `.htaccess`:**
```apache
php_value memory_limit 256M
php_value max_execution_time 300
```

#### Solution 6: Use Query Pagination

SESSYPress automatically paginates, but you can adjust:

1. Go to **SESSYPress** → **Settings**
2. Under **Dashboard**:
   - Events per page: **50** (reduce from 100)
3. Click **Save Settings**

**Lower = faster queries.**

---

## Email Not Sending

### Symptoms
- `wp_mail()` returns false
- Emails not arriving
- No events in SESSYPress dashboard

### Diagnosis

**This is not a SESSYPress issue.** SESSYPress only tracks emails after they're handed to SES.

**Check:**

1. **SES credentials configured?**
   - Using WP Mail SMTP or similar plugin
   - SMTP host: `email-smtp.us-east-1.amazonaws.com`
   - SMTP username/password (IAM credentials)

2. **SES account status?**
   - Go to AWS SES Console → Account dashboard
   - Check: "Your account is in production" or "Sandbox"
   - If sandbox: Can only send to verified addresses

3. **Verified sender?**
   - Email FROM address must be verified in SES
   - Or sending domain must be verified

### Solutions

See **WP Mail SMTP** or your SMTP plugin's documentation.

**Quick test:**

```php
// Test wp_mail
$result = wp_mail(
    'your-verified@email.com',
    'Test Subject',
    'Test message',
    ['Content-Type: text/html; charset=UTF-8']
);

if ( ! $result ) {
    error_log( 'wp_mail failed' );
}
```

---

## Webhook Timeout Errors

### Symptoms
- Logs show: `[ERROR] Webhook timeout`
- SNS retries webhook multiple times
- Some events missing

### Solutions

#### Solution 1: Increase PHP Timeout

**In `.htaccess`:**
```apache
php_value max_execution_time 300
```

**In `wp-config.php`:**
```php
set_time_limit( 300 );
```

#### Solution 2: Optimize Event Processing

Large events (with full headers) may take time to process.

1. Go to **SESSYPress** → **Settings**
2. Under **Event Processing**:
   - ☐ Store full headers (disable if not needed)
   - ☑️ Async processing (enable background jobs)

#### Solution 3: Check Database Connection

Slow database can cause timeouts.

**Test query speed:**
```sql
SELECT BENCHMARK(1000000, (SELECT COUNT(*) FROM wp_ses_email_events));
```

**If slow:**
- Optimize table (see [Performance Issues](#performance-issues-with-large-datasets))
- Consider upgrading database server

---

## Configuration Set Not Working

### Symptoms
- Emails sending successfully
- No open/click events
- Event Publishing events not appearing

### Diagnosis

**Check email headers:**

Send test email and view source. Look for:
```
X-SES-CONFIGURATION-SET: sessypress-tracking
```

### Solutions

#### Solution 1: Verify Header is Added

**In SESSYPress Settings:**
1. Go to **SESSYPress** → **Settings**
2. Event Publishing section:
   - ☑️ Enable Event Publishing
   - Configuration Set Name: `sessypress-tracking`
3. Save settings

**Or add manually:**
```php
add_filter( 'wp_mail', function( $args ) {
    $args['headers'][] = 'X-SES-CONFIGURATION-SET: sessypress-tracking';
    return $args;
}, 10, 1 );
```

#### Solution 2: Check Configuration Set Exists in AWS

1. Go to AWS SES Console → **Configuration sets**
2. Verify your configuration set exists
3. Name matches exactly (case-sensitive)

**If missing:**
1. Click **Create set**
2. Name: `sessypress-tracking`
3. Add event destination (SNS topic)

#### Solution 3: Verify Event Destination

1. Configuration sets → Your set → **Event destinations**
2. Check:
   - Event destination exists
   - Event types enabled (Send, Open, Click, etc.)
   - SNS topic selected
   - Topic subscription confirmed

---

## Database Errors

### Symptoms
- Logs show: `[ERROR] Database insert failed`
- Events not storing
- WordPress database errors

### Diagnosis

**Check database connection:**

```php
// In functions.php temporarily
add_action( 'init', function() {
    global $wpdb;
    $wpdb->show_errors();
    $result = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}ses_email_events LIMIT 1" );
    if ( $wpdb->last_error ) {
        error_log( 'Database error: ' . $wpdb->last_error );
    }
});
```

### Solutions

#### Solution 1: Verify Table Exists

```sql
SHOW TABLES LIKE 'wp_ses_email_events';
```

**If doesn't exist:**
1. Deactivate SESSYPress
2. Reactivate SESSYPress (runs installer)

#### Solution 2: Check Table Structure

```sql
DESCRIBE wp_ses_email_events;
```

**Required columns:**
- id (bigint, primary key, auto_increment)
- message_id (varchar 255)
- recipient (varchar 255)
- event_type (varchar 50)
- event_source (varchar 50)
- timestamp (datetime)
- event_metadata (longtext)

**If columns missing:**
1. Backup table
2. Deactivate and delete plugin
3. Reinstall plugin
4. Import data from backup

#### Solution 3: Check Database Permissions

User needs permissions: SELECT, INSERT, UPDATE, DELETE

```sql
SHOW GRANTS FOR 'wp_user'@'localhost';
```

**If insufficient:**
```sql
GRANT SELECT, INSERT, UPDATE, DELETE ON wordpress.wp_ses_email_events TO 'wp_user'@'localhost';
FLUSH PRIVILEGES;
```

---

## Still Having Issues?

**Before contacting support:**

1. ✅ Enable debug logging
2. ✅ Export logs
3. ✅ Test with simulator addresses
4. ✅ Check AWS configuration
5. ✅ Review this guide

**Contact Support:**

- 📧 Email: support@yourplugin.com
- 🐛 GitHub: [github.com/YOUR_USERNAME/sessypress/issues](https://github.com/YOUR_USERNAME/sessypress/issues)
- 💬 Community: [Forum/Discord link]

**Include in support request:**
- WordPress version
- PHP version
- SESSYPress version
- Error logs (from SESSYPress → Logs)
- Steps to reproduce
- Expected vs actual behavior

**Response time:** 24-48 hours
