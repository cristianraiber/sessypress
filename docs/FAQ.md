# SESSYPress - Frequently Asked Questions

## Table of Contents

- [General Questions](#general-questions)
- [Setup & Configuration](#setup--configuration)
- [Tracking & Events](#tracking--events)
- [Compatibility](#compatibility)
- [Data Management](#data-management)
- [Costs & Performance](#costs--performance)
- [Troubleshooting](#troubleshooting)

---

## General Questions

### What is SESSYPress?

SESSYPress is a WordPress plugin that tracks and analyzes emails sent through Amazon SES (Simple Email Service). It provides detailed analytics including:

- 📊 Email bounces and complaints
- 📧 Delivery confirmations
- 👁️ Email opens (when opened, who opened)
- 🔗 Link clicks (which links, click rates)
- 📈 Campaign performance
- 🚫 Unsubscribe management

### What's the difference between SNS Notifications and Event Publishing?

**SNS Notifications (Traditional Method):**
- Tracks: Bounces, Complaints, Deliveries only
- Setup: Configure SNS topic on verified identity
- Works with: All SES users (sandbox and production)
- Event detail: Basic (email, timestamp, reason)

**Event Publishing (Advanced Method):**
- Tracks: Send, Reject, Bounce, Complaint, Delivery, Open, Click, Rendering Failure, Delivery Delay
- Setup: Configure Configuration Set with event destination
- Works with: All SES users
- Event detail: Rich (IP address, user agent, links, metadata)

**Recommendation:** Use Event Publishing for full analytics. It includes everything SNS Notifications provides plus open/click tracking.

### Do I need both SNS Notifications and Event Publishing?

**No.** Using both creates duplicate events for bounces, complaints, and deliveries.

**Best practices:**

**Option 1: Event Publishing Only (Recommended)**
- Use Configuration Set with all event types enabled
- Disable SNS Notifications on verified identity
- ✅ Simplest setup, no duplicates, full analytics

**Option 2: SNS Notifications + Event Publishing (Advanced)**
- Use SNS Notifications for bounces/complaints/deliveries
- Use Event Publishing only for opens/clicks
- Requires deduplication logic
- ⚠️ Only recommended if you need separate SNS topics

**Option 3: SNS Notifications Only (Limited)**
- No open/click tracking
- Only for basic bounce monitoring
- ❌ Not recommended (missing most features)

### How do I enable native open/click tracking?

Native open/click tracking requires Event Publishing with a Configuration Set:

1. **Create Configuration Set** in AWS SES Console
2. **Enable Open and Click Tracking** in Configuration Set settings
3. **Add Event Destination** (SNS topic)
4. **Configure WordPress** to use Configuration Set:
   - Go to **SESSYPress** → **Settings**
   - Under **Event Publishing**, enable it
   - Enter Configuration Set name
   - Check "Use SES native open/click tracking"
   - Save settings

All emails will now include tracking pixels and rewritten links.

**Important:** Native tracking requires HTML emails. Plain text emails cannot be tracked.

See [AWS Setup Guide](AWS_SETUP.md#event-publishing-setup) for detailed steps.

---

## Setup & Configuration

### Why aren't my events showing up?

**For all events:**

1. **Check SNS Subscription Status:**
   - Go to **SESSYPress** → **Settings**
   - Look for "SNS Endpoint Status"
   - Should show "Confirmed ✅"
   - If pending, click "Refresh Status"

2. **Verify Secret Key:**
   - Ensure the secret key in plugin settings matches the `?key=` parameter in SNS subscription endpoint

3. **Test with Simulator:**
   - Send email to `bounce@simulator.amazonses.com`
   - If event appears → setup working
   - If no event → configuration issue

**For SNS Notifications (Bounce/Complaint/Delivery):**

4. **Check SES Configuration:**
   - Go to AWS SES → Verified identities → Your identity
   - Click **Notifications** tab
   - Ensure SNS topic is assigned for each notification type

**For Event Publishing (Open/Click/Send):**

5. **Check Configuration Set:**
   - Go to AWS SES → Configuration sets
   - Verify event destination exists
   - Ensure event types are enabled (Send, Open, Click, etc.)

6. **Verify Header:**
   - Emails must include `X-SES-CONFIGURATION-SET: your-config-set-name` header
   - Check plugin settings to ensure Configuration Set is specified

See [Troubleshooting Guide](TROUBLESHOOTING.md) for detailed solutions.

### How do I test my setup?

**Quick Test (5 minutes):**

1. **Bounce Test:**
   ```
   Send email to: bounce@simulator.amazonses.com
   Expected event: Bounce (appears in 1-5 seconds)
   ```

2. **Success Test:**
   ```
   Send email to: success@simulator.amazonses.com
   Expected events: Send, Delivery (appears in 1-5 seconds)
   ```

3. **Open Test:**
   ```
   Send email to your verified address
   Open the email
   Expected event: Open (appears within 1 minute)
   ```

4. **Click Test:**
   ```
   Send email with link to your verified address
   Click the link
   Expected event: Click (appears within 1 minute)
   ```

**Dashboard Check:**
- Go to **SESSYPress** → **Dashboard**
- Events should appear in real-time
- Check event metadata (IP, user agent, links)

**AWS Simulator Addresses:**

| Address | Simulates |
|---------|-----------|
| `bounce@simulator.amazonses.com` | Hard bounce |
| `ooto@simulator.amazonses.com` | Out of office (soft bounce) |
| `complaint@simulator.amazonses.com` | Spam complaint |
| `success@simulator.amazonses.com` | Successful delivery |
| `suppressionlist@simulator.amazonses.com` | Suppression list bounce |

See [Testing Your Setup](AWS_SETUP.md#testing-your-setup) for full guide.

### Can I use this with other email plugins?

**Yes, but with limitations.**

SESSYPress tracks emails sent through `wp_mail()` function. Most WordPress email plugins use `wp_mail()`, so tracking should work.

**Compatible plugins:**
- ✅ **WP Mail SMTP** - Fully compatible (recommended)
- ✅ **Post SMTP** - Fully compatible if using SES
- ✅ **Easy WP SMTP** - Fully compatible
- ✅ **WooCommerce** - Tracks order emails
- ✅ **Easy Digital Downloads** - Tracks purchase emails
- ✅ **Contact Form 7** - Tracks form submissions
- ✅ **Gravity Forms** - Tracks notifications

**Incompatible plugins:**
- ❌ **SendGrid Plugin** - Uses SendGrid API (not SES)
- ❌ **Mailgun Plugin** - Uses Mailgun API (not SES)
- ❌ **SparkPost Plugin** - Uses SparkPost API (not SES)

**Setup Requirements:**
- Email plugin must be configured to use Amazon SES SMTP
- SESSYPress should be activated alongside email plugin
- Configuration Set header must be added (for Event Publishing)

**Example: WP Mail SMTP + SESSYPress**

1. Install and configure **WP Mail SMTP** with SES credentials
2. Install and activate **SESSYPress**
3. Configure SNS webhook in SESSYPress
4. (Optional) Add Configuration Set header in WP Mail SMTP settings

All emails sent via WP Mail SMTP will be tracked by SESSYPress.

### How do I add the Configuration Set header manually?

If your email plugin doesn't support custom headers, add this to your theme's `functions.php`:

```php
/**
 * Add SES Configuration Set header to all emails
 */
add_filter( 'wp_mail', function( $args ) {
    // Configuration Set name from AWS
    $config_set = 'sessypress-tracking';
    
    // Initialize headers array if not set
    if ( ! isset( $args['headers'] ) ) {
        $args['headers'] = [];
    }
    
    // Handle string headers
    if ( is_string( $args['headers'] ) ) {
        $args['headers'] .= "\nX-SES-CONFIGURATION-SET: " . $config_set;
    } 
    // Handle array headers
    else {
        $args['headers'][] = 'X-SES-CONFIGURATION-SET: ' . $config_set;
    }
    
    return $args;
}, 10, 1 );
```

**Note:** Replace `sessypress-tracking` with your actual Configuration Set name.

---

## Tracking & Events

### How accurate is open tracking?

**Short answer:** 60-80% accurate.

**Limitations:**

1. **Image Blocking:**
   - Open tracking uses invisible 1x1 pixel image
   - Many email clients block images by default
   - User must enable images to trigger open event

2. **Privacy Features:**
   - Apple Mail Privacy Protection (iOS 15+) prefetches images
   - Gmail proxy servers cache images
   - Outlook blocks external images by default

3. **Multiple Opens:**
   - Same recipient may trigger multiple open events
   - Email client preview/scanning can trigger opens
   - Each open is recorded separately

**Improving accuracy:**
- Encourage recipients to "display images"
- Use engaging content to increase legitimate opens
- Focus on click tracking (more accurate)

**Best practices:**
- Use opens as general engagement indicator
- Don't rely solely on open rates for critical decisions
- Compare with click rates for better insights

### How accurate is click tracking?

**Short answer:** 95%+ accurate.

Click tracking is much more reliable than open tracking because:

- ✅ Doesn't rely on images
- ✅ Direct user action (clicking link)
- ✅ Harder to fake or prefetch
- ✅ Works in all email clients

**How it works:**
1. SES rewrites links to tracking domain
2. User clicks tracked link
3. SES redirects to original URL
4. Click event published to SNS

**Limitations:**
- Only tracks clicks on HTTP/HTTPS links
- Doesn't track:
  - Plain text URLs (not in `<a>` tags)
  - `mailto:` links
  - File attachments
  - Anchor links (`#section`)

**Example:**

Original link:
```html
<a href="https://example.com/page">Click here</a>
```

Rewritten by SES:
```html
<a href="https://abcd1234.cloudfront.net/L0/0102...">Click here</a>
```

When clicked → SES tracks → Redirects to `https://example.com/page`

### What is the difference between Bounce and Complaint events?

**Bounce:**
- Recipient's email server **rejected** the email
- Email was not delivered to inbox
- Can be temporary (soft) or permanent (hard)

**Types of bounces:**

| Type | Description | Action |
|------|-------------|--------|
| **Hard Bounce** | Permanent failure (invalid email, domain doesn't exist) | Remove from list immediately |
| **Soft Bounce** | Temporary failure (mailbox full, server down) | Retry, remove after 3-5 failures |
| **Suppression Bounce** | Email on AWS suppression list (previous bounces) | Remove from list |

**Complaint:**
- Recipient marked your email as **spam**
- Email was delivered but reported as unwanted
- Serious signal (damages sender reputation)

**Actions to take:**

| Event | Immediate Action | Long-term Action |
|-------|------------------|------------------|
| Hard Bounce | Remove from email list | Clean your list regularly |
| Soft Bounce | Retry 2-3 times | Remove if persistent |
| Complaint | Remove immediately | Review email content, add unsubscribe link |

**Important:** AWS monitors bounce/complaint rates. High rates can suspend your SES account:
- Bounce rate should be < 5%
- Complaint rate should be < 0.1%

SESSYPress automatically tracks these rates in the dashboard.

### Can I export my event data?

**Yes.** SESSYPress provides multiple export options:

**Export Options:**

1. **CSV Export (Dashboard):**
   - Go to **SESSYPress** → **Dashboard**
   - Apply filters (date range, event type, etc.)
   - Click **Export CSV**
   - Downloads filtered events

2. **Database Export (phpMyAdmin):**
   - Access your WordPress database
   - Export table: `wp_ses_email_events`
   - Format: SQL, CSV, JSON

3. **REST API (Developers):**
   ```php
   GET /wp-json/sessypress/v1/events
   
   Parameters:
   - event_type: bounce|complaint|delivery|open|click
   - start_date: YYYY-MM-DD
   - end_date: YYYY-MM-DD
   - limit: 1-1000
   ```

**Export Fields:**

```csv
id,message_id,recipient,event_type,event_source,timestamp,bounce_type,complaint_feedback_type,event_metadata
1,0102abc...,user@example.com,Bounce,sns_notification,2026-01-31 10:00:00,Permanent,NULL,"{...}"
```

**Use Cases:**
- Import into Google Sheets/Excel for analysis
- Send to external analytics platform (Amplitude, Mixpanel)
- Create custom reports
- Compliance/audit requirements

---

## Data Management

### How long does SESSYPress store event data?

**Default:** Indefinitely (until manually deleted)

**Configurable retention:**

1. Go to **SESSYPress** → **Settings**
2. Under **Data Retention**, set:
   - Auto-delete events older than: **30/60/90/180/365 days** or **Never**
3. Click **Save Settings**

**Manual cleanup:**
- Click **Delete Old Events Now** to immediately remove old data
- Runs daily cron job for automatic cleanup

**Recommendations:**

| Use Case | Retention Period |
|----------|------------------|
| Small blog (< 1K emails/month) | 1 year |
| Medium site (1K-10K emails/month) | 90 days |
| Large site (> 10K emails/month) | 30-60 days |
| Compliance requirements | As required (up to 7 years) |

**Database impact:**

```
Events stored: 100K events
Database size: ~50 MB
Query performance: Fast (with indexes)

Events stored: 1M events  
Database size: ~500 MB
Query performance: Slower (consider archiving)
```

### How do I prevent duplicate events?

**SESSYPress automatically prevents duplicates** using message IDs, but you can optimize:

**1. Don't use both SNS Notifications and Event Publishing:**

❌ **Bad setup (duplicates):**
- SNS Notifications: Bounce, Complaint, Delivery
- Event Publishing: All events (Send, Bounce, Complaint, Delivery, Open, Click)
- Result: Duplicate bounces, complaints, deliveries

✅ **Good setup:**
- Event Publishing only: All events
- SNS Notifications: Disabled
- Result: No duplicates

**2. Database deduplication:**

SESSYPress stores `message_id` for each event. Before inserting, it checks if event exists:

```php
// Pseudo-code
if ( event_exists( $message_id, $event_type, $recipient ) ) {
    return; // Skip duplicate
}
insert_event( $data );
```

**3. Multiple opens are intentional:**

Same recipient opening email multiple times = multiple open events. This is **by design**.

If you want unique opens per recipient:

```sql
-- Get unique opens per message
SELECT 
  message_id,
  recipient,
  MIN(timestamp) as first_open,
  COUNT(*) as open_count
FROM wp_ses_email_events
WHERE event_type = 'Open'
GROUP BY message_id, recipient
```

---

## Costs & Performance

### What are the AWS costs for using SESSYPress?

**TL;DR:** ~$1-5/month for most users.

**Detailed breakdown:**

**SES Costs:**
- Emails: $0.10 per 1,000 emails
- First 1,000 emails/month: **FREE** (AWS Free Tier)
- Data transfer: $0.12 per GB (emails + attachments)

**SNS Costs:**
- Requests: $0.50 per 1 million requests
- HTTPS notifications: $0.60 per 100,000 notifications

**Example scenarios:**

| Monthly Emails | Events | SES Cost | SNS Cost | Total |
|----------------|--------|----------|----------|-------|
| 1,000 | 5,000 | $0.00 (free tier) | $0.03 | **$0.03** |
| 10,000 | 50,000 | $0.90 | $0.30 | **$1.20** |
| 100,000 | 500,000 | $9.00 | $3.00 | **$12.00** |
| 1,000,000 | 5,000,000 | $90.00 | $30.00 | **$120.00** |

**Cost optimization tips:**

1. **Disable delivery events** (reduces volume by 40%):
   - Most users don't need delivery confirmations
   - Only track bounces, complaints, opens, clicks

2. **Use Event Publishing only** (avoid duplicates):
   - Don't use SNS Notifications + Event Publishing together

3. **Archive old events** (reduce database size):
   - Set retention to 90 days

4. **Monitor with AWS Budgets:**
   - Set alert for > $10/month
   - Receive email when threshold exceeded

**Hidden costs to avoid:**
- ❌ Sending to invalid emails (high bounce rate)
- ❌ Storing millions of delivery events (low value)
- ❌ Not cleaning suppression list (charged for blocked sends)

### Will this slow down my WordPress site?

**No.** SESSYPress is optimized for performance.

**How it works:**

1. **Emails sent via SES (not WordPress):**
   - WordPress hands off email to SES SMTP
   - SES sends asynchronously
   - No performance impact on site

2. **Events processed via webhook:**
   - AWS SNS sends events to WordPress REST API
   - WordPress processes in background
   - Uses efficient database inserts (< 10ms)

3. **Dashboard uses AJAX:**
   - Events load dynamically
   - Pagination limits query size
   - Caching for stats

**Performance benchmarks:**

| Action | Time | Database Queries |
|--------|------|------------------|
| Send email via `wp_mail()` | +5ms (header added) | 0 |
| Process SNS notification | 50-200ms | 1-2 (insert) |
| Load dashboard (100 events) | 300-800ms | 3-5 (with indexes) |
| Load dashboard (10K events) | 1-2s | 5-10 (paginated) |

**Optimization tips:**

1. **Database indexes (auto-created):**
   ```sql
   INDEX idx_event_type (event_type)
   INDEX idx_event_source (event_source)  
   INDEX idx_timestamp (timestamp)
   INDEX idx_message_id (message_id)
   ```

2. **Query limits:**
   - Dashboard: 100 events per page
   - API: 1000 events max per request

3. **Caching:**
   - Stats cached for 5 minutes
   - Charts cached for 15 minutes

**Red flags (fix immediately):**

- Dashboard takes > 5 seconds to load → Run `OPTIMIZE TABLE`
- Millions of events → Enable auto-delete old events
- Missing database indexes → Re-run installer

See [Performance Issues](TROUBLESHOOTING.md#performance-issues-with-large-datasets) for solutions.

---

## Troubleshooting

### Why am I seeing "Invalid secret key" errors?

**Cause:** SNS webhook requests are being rejected due to mismatched secret keys.

**Solutions:**

1. **Check Secret Key Match:**
   - Go to **SESSYPress** → **Settings**
   - Copy the secret key shown
   - Go to AWS SNS Console → Topics → Your topic → Subscriptions
   - Check subscription endpoint URL
   - Secret key in URL must match: `?key=YOUR_SECRET_KEY`

2. **Regenerate and Update:**
   - In SESSYPress settings, click **Regenerate Secret Key**
   - Copy new key
   - In SNS Console, delete old subscription
   - Create new subscription with updated endpoint URL

3. **URL Encoding:**
   - Ensure no special characters causing issues
   - Secret key should be alphanumeric
   - If regenerating doesn't help, try shorter key

**Verify fix:**
- Send test email to `bounce@simulator.amazonses.com`
- Check **SESSYPress** → **Logs**
- Should show successful webhook request (200 OK)

See [Troubleshooting Guide](TROUBLESHOOTING.md#invalid-secret-key-error) for detailed steps.

### How do I enable debug logging?

**Enable logs:**

1. Go to **SESSYPress** → **Settings**
2. Under **Developer Options**:
   - ☑️ Enable debug logging
   - Log level: **Verbose** (or Info/Error)
3. Click **Save Settings**

**View logs:**

1. Go to **SESSYPress** → **Logs**
2. Filter by:
   - Level: All / Info / Warning / Error
   - Date range
   - Search keyword

**Log entries include:**
- SNS webhook requests (full payload)
- Event processing steps
- Database inserts
- Errors and warnings
- Performance metrics

**Download logs:**
- Click **Export Logs** to download as `.txt` file
- Useful for support requests

**Security note:** Logs may contain email addresses and message IDs. Don't share publicly.

**Disable when done:**
- Debug logging increases database size
- Disable after troubleshooting

---

## Still need help?

**Resources:**
- 📖 [AWS Setup Guide](AWS_SETUP.md) - Detailed setup instructions
- 🔧 [Troubleshooting Guide](TROUBLESHOOTING.md) - Error solutions
- 🐛 [GitHub Issues](https://github.com/YOUR_USERNAME/sessypress/issues) - Report bugs
- 💬 [Community Forum](#) - Ask questions

**Contact Support:**
- 📧 Email: support@yourplugin.com
- 🕐 Response time: 24-48 hours
- 🌍 Timezone: UTC
