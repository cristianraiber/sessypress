# AWS SES Setup Guide for SESSYPress

This guide walks you through setting up Amazon SES (Simple Email Service) with SESSYPress to track email events like bounces, complaints, opens, and clicks.

## Table of Contents

- [Prerequisites](#prerequisites)
- [SNS Notifications Setup](#sns-notifications-setup)
- [Event Publishing Setup](#event-publishing-setup)
- [Testing Your Setup](#testing-your-setup)
- [Troubleshooting Common Issues](#troubleshooting-common-issues)

---

## Prerequisites

Before you begin, ensure you have:

### 1. AWS Account
- Create an AWS account at [aws.amazon.com](https://aws.amazon.com)
- Set up billing and payment method
- Consider setting up billing alerts to monitor costs

### 2. SES Identity Verified
You need at least one verified email address or domain in AWS SES.

**To verify an email address:**
1. Go to [AWS SES Console](https://console.aws.amazon.com/ses/)
2. Navigate to **Configuration** → **Verified identities**
3. Click **Create identity**
4. Select **Email address**
5. Enter your email and click **Create identity**
6. Check your inbox for verification email from Amazon
7. Click the verification link

**To verify a domain:**
1. Follow steps 1-3 above
2. Select **Domain**
3. Enter your domain name (e.g., `example.com`)
4. Follow DNS configuration instructions
5. Wait for DNS propagation (can take up to 48 hours)

### 3. SES Sending Limits
- **Sandbox mode**: You can only send to verified addresses (max 200 emails/day, 1/sec)
- **Production mode**: Request production access to send to any address
- To request production access: **Account dashboard** → **Request production access**

### 4. WordPress Site
- SESSYPress plugin installed and activated
- WordPress version 5.0 or higher
- PHP 7.4 or higher

---

## SNS Notifications Setup

SNS Notifications track **Bounces**, **Complaints**, and **Deliveries**. This is the traditional method and works for all SES users.

### Step 1: Create SNS Topic

1. Go to [Amazon SNS Console](https://console.aws.amazon.com/sns/)
2. Click **Topics** in the left sidebar
3. Click **Create topic**
4. Configure:
   - **Type**: Standard
   - **Name**: `ses-email-notifications` (or your choice)
   - **Display name**: `SES Email Notifications`
5. Leave other settings as default
6. Click **Create topic**
7. **Copy the Topic ARN** (looks like `arn:aws:sns:us-east-1:123456789012:ses-email-notifications`)

### Step 2: Create SNS Subscription

1. On the topic details page, click **Create subscription**
2. Configure:
   - **Protocol**: HTTPS
   - **Endpoint**: `https://yoursite.com/wp-json/sessypress/v1/webhook?key=YOUR_SECRET_KEY`
   - Replace `yoursite.com` with your actual domain
   - Replace `YOUR_SECRET_KEY` with the secret key from SESSYPress settings
3. Click **Create subscription**
4. **Status will be "Pending confirmation"** - this is normal

### Step 3: Configure SNS Topic Policy

To allow SES to publish to the SNS topic:

1. On the topic details page, click **Edit**
2. Scroll to **Access policy**
3. Click **Advanced** (JSON editor)
4. Add this policy (merge with existing policy):

```json
{
  "Version": "2008-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Principal": {
        "Service": "ses.amazonaws.com"
      },
      "Action": "SNS:Publish",
      "Resource": "arn:aws:sns:us-east-1:123456789012:ses-email-notifications",
      "Condition": {
        "StringEquals": {
          "AWS:SourceAccount": "123456789012"
        }
      }
    }
  ]
}
```

Replace:
- `arn:aws:sns:us-east-1:123456789012:ses-email-notifications` with your Topic ARN
- `123456789012` with your AWS Account ID

5. Click **Save changes**

### Step 4: Configure SES to Use SNS Topic

1. Go to [AWS SES Console](https://console.aws.amazon.com/ses/)
2. Navigate to **Configuration** → **Verified identities**
3. Click on your verified email or domain
4. Go to the **Notifications** tab
5. Click **Edit** for each notification type:

**Bounce notifications:**
- SNS topic: Select your topic (`ses-email-notifications`)
- Include original headers: Yes (recommended)
- Click **Save changes**

**Complaint notifications:**
- SNS topic: Select your topic (`ses-email-notifications`)
- Include original headers: Yes (recommended)
- Click **Save changes**

**Delivery notifications:**
- SNS topic: Select your topic (`ses-email-notifications`)
- Include original headers: No (optional, reduces costs)
- Click **Save changes**

### Step 5: Confirm SNS Subscription in WordPress

1. Go to your WordPress admin
2. Navigate to **SESSYPress** → **Settings**
3. Check the **SNS Endpoint Status**
4. If it shows "Pending", click **Refresh Status**
5. SESSYPress will automatically confirm the subscription
6. Status should change to **Confirmed** ✅

**Troubleshooting:**
- If subscription doesn't confirm, check your secret key matches
- Ensure your site is publicly accessible (not localhost)
- Check your server firewall allows incoming HTTPS
- View **SNS Logs** in SESSYPress for confirmation request details

---

## Event Publishing Setup

Event Publishing tracks **all email events** including Opens, Clicks, Sends, Rejects, Delivery Delays, and Rendering Failures. This provides more detailed analytics.

### Step 1: Create Configuration Set

1. Go to [AWS SES Console](https://console.aws.amazon.com/ses/)
2. Navigate to **Configuration** → **Configuration sets**
3. Click **Create set**
4. Configure:
   - **Configuration set name**: `sessypress-tracking` (or your choice)
   - **Sending IP pool**: Leave blank (uses shared pool)
5. Click **Create set**

### Step 2: Create SNS Topic for Events (or Reuse Existing)

You can use the same SNS topic from the Notifications setup, or create a new one:

**Option A: Reuse existing topic** (recommended)
- Use the same topic ARN from SNS Notifications setup

**Option B: Create new topic**
- Follow [Step 1](#step-1-create-sns-topic) above
- Name it `ses-event-publishing` to differentiate
- Create subscription with same endpoint

### Step 3: Add Event Destination

1. On the Configuration Set details page, click **Event destinations** tab
2. Click **Add destination**
3. Configure:
   - **Event types**: Select all:
     - ☑️ Sends
     - ☑️ Rejects
     - ☑️ Bounces
     - ☑️ Complaints
     - ☑️ Deliveries
     - ☑️ Opens
     - ☑️ Clicks
     - ☑️ Rendering failures
     - ☑️ Delivery delays
   - **Destination**: Amazon SNS
   - **SNS topic**: Select your topic
4. Click **Next**
5. **Destination name**: `sessypress-sns-destination`
6. Click **Add destination**

### Step 4: Enable Open and Click Tracking

**Important:** Event Publishing requires native tracking to be enabled.

1. On the Configuration Set details page
2. Under **Open and click tracking**, click **Edit**
3. Configure:
   - ☑️ **Open tracking**: Enabled
   - ☑️ **Click tracking**: Enabled
4. Click **Save changes**

### Step 5: Configure WordPress to Use Configuration Set

**Method A: Plugin Setting (Recommended)**

1. Go to **SESSYPress** → **Settings**
2. Under **Event Publishing**:
   - ☑️ Enable Event Publishing
   - **Configuration Set Name**: Enter `sessypress-tracking`
   - ☑️ Use SES native open/click tracking
3. Click **Save Settings**

**Method B: Add Header to wp_mail (Manual)**

If you need more control, you can add the header yourself:

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

### Step 6: Verify Event Publishing Works

1. Send a test email from your WordPress site
2. Go to **SESSYPress** → **Dashboard**
3. You should see:
   - **Send** event immediately
   - **Delivery** event within seconds
   - **Open** event when you open the email
   - **Click** event when you click a link

---

## Testing Your Setup

AWS provides simulator addresses to test different scenarios without sending real emails.

### Bounce Testing

Send emails to these addresses to simulate bounces:

| Address | Bounce Type |
|---------|-------------|
| `bounce@simulator.amazonses.com` | Soft bounce |
| `ooto@simulator.amazonses.com` | Out of office (soft bounce) |
| `complaint@simulator.amazonses.com` | Complaint |
| `success@simulator.amazonses.com` | Successful delivery |
| `suppressionlist@simulator.amazonses.com` | Suppression list |

**Example:**

1. Go to **Users** → **Add New** in WordPress
2. Enter email: `bounce@simulator.amazonses.com`
3. Check "Send user notification"
4. Go to **SESSYPress** → **Dashboard**
5. You should see a **Bounce** event within seconds

### Open/Click Testing

**Open Tracking:**
1. Send email to your verified address
2. Use Configuration Set with open tracking enabled
3. Open the email in your email client
4. Check **SESSYPress** → **Dashboard** for **Open** event

**Click Tracking:**
1. Send email with a link (e.g., password reset email)
2. Use Configuration Set with click tracking enabled
3. Click the link
4. Check **SESSYPress** → **Dashboard** for **Click** event

**Note:** Open tracking uses invisible 1x1 pixel. Some email clients block images by default.

### Manual Testing Checklist

- [ ] Send email to verified address → See **Send** event
- [ ] Send to `success@simulator.amazonses.com` → See **Delivery** event
- [ ] Send to `bounce@simulator.amazonses.com` → See **Bounce** event
- [ ] Send to `complaint@simulator.amazonses.com` → See **Complaint** event
- [ ] Open email → See **Open** event
- [ ] Click link in email → See **Click** event
- [ ] Check SNS subscription status → **Confirmed**
- [ ] Check event metadata → IP address, user agent, link captured

---

## Troubleshooting Common Issues

### SNS Subscription Not Confirming

**Symptoms:**
- Subscription status stuck on "Pending confirmation"
- No events showing in dashboard

**Solutions:**

1. **Check Secret Key:**
   - Go to **SESSYPress** → **Settings**
   - Copy the secret key
   - Ensure it matches the `?key=` parameter in SNS subscription endpoint

2. **Verify Endpoint URL:**
   - Must be HTTPS (not HTTP)
   - Must be publicly accessible (not localhost or private IP)
   - Format: `https://yoursite.com/wp-json/sessypress/v1/webhook?key=YOUR_SECRET_KEY`

3. **Check Server Firewall:**
   - Ensure port 443 (HTTPS) is open
   - Allow incoming connections from AWS IP ranges
   - Check `.htaccess` or nginx config for blocks

4. **View SNS Logs:**
   - Go to **SESSYPress** → **Logs**
   - Look for subscription confirmation requests
   - Check for error messages

5. **Manually Confirm (Last Resort):**
   - In SNS console, find the subscription
   - Look for "Token" in pending confirmation
   - Contact support if token not visible

### Events Not Showing in Dashboard

**For SNS Notifications (Bounce/Complaint/Delivery):**

1. **Verify SES Configuration:**
   - Go to SES → Verified identities → Your identity
   - Check **Notifications** tab
   - Ensure SNS topic is selected for bounce/complaint/delivery

2. **Test with Simulator:**
   - Send to `bounce@simulator.amazonses.com`
   - If event appears → SNS working, issue with real emails
   - If no event → SNS configuration issue

3. **Check SNS Topic Policy:**
   - Ensure SES has permission to publish
   - See [Step 3](#step-3-configure-sns-topic-policy) above

**For Event Publishing (Open/Click/Send):**

1. **Verify Configuration Set:**
   - Go to SES → Configuration sets
   - Check **Event destinations** tab
   - Ensure Opens/Clicks are enabled

2. **Check X-SES-CONFIGURATION-SET Header:**
   - In SESSYPress settings, verify Configuration Set name
   - Or check email headers in sent emails
   - Should contain: `X-SES-CONFIGURATION-SET: sessypress-tracking`

3. **Native Tracking Enabled:**
   - Configuration Set must have open/click tracking enabled
   - Manual tracking won't work with Event Publishing

### "Invalid Secret Key" Error

**Symptoms:**
- SNS webhook returns 403 Forbidden
- Logs show "Invalid secret key"

**Solutions:**

1. **Regenerate Secret Key:**
   - Go to **SESSYPress** → **Settings**
   - Click **Regenerate Secret Key**
   - Copy new key
   - Update SNS subscription endpoint with new key

2. **Update SNS Subscription:**
   - Go to SNS Console → Topics → Your topic
   - Delete old subscription
   - Create new subscription with updated endpoint URL

3. **URL Encoding:**
   - Ensure secret key in URL is properly encoded
   - No special characters causing issues

### Opens/Clicks Not Tracking

**Symptoms:**
- Send/Delivery events work
- Open/Click events not appearing

**Solutions:**

1. **Enable Native Tracking:**
   - Go to Configuration Set
   - Under **Open and click tracking**, click Edit
   - Enable both Open and Click tracking

2. **Use Configuration Set:**
   - Ensure emails include `X-SES-CONFIGURATION-SET` header
   - Check SESSYPress settings

3. **Email Client Blocking:**
   - Some email clients block tracking pixels (opens)
   - Try different email client (Gmail, Outlook)

4. **HTML Email Required:**
   - Open/click tracking only works with HTML emails
   - Plain text emails cannot be tracked

5. **Link Format:**
   - Links must be proper HTML anchor tags
   - Format: `<a href="https://example.com">Link</a>`

### Duplicate Events

**Symptoms:**
- Same event appearing multiple times
- Open events triggered on every email check

**Solutions:**

1. **SNS Deduplication:**
   - This is normal SNS behavior (at-least-once delivery)
   - SESSYPress stores `message_id` to prevent duplicates
   - Check database for duplicate entries

2. **Multiple Opens Normal:**
   - Email clients prefetch/scan emails (security)
   - Same recipient opening multiple times
   - Each open creates new event (by design)

3. **Disable Duplicate Sources:**
   - Don't use both SNS Notifications AND Event Publishing for bounces
   - Choose one method per event type

### Performance Issues with Large Datasets

**Symptoms:**
- Dashboard slow to load
- Database queries timing out
- High server CPU/memory usage

**Solutions:**

1. **Database Optimization:**
   ```sql
   -- Run in phpMyAdmin or MySQL client
   OPTIMIZE TABLE wp_ses_email_events;
   ```

2. **Add Indexes (if missing):**
   ```sql
   ALTER TABLE wp_ses_email_events 
     ADD INDEX idx_event_type (event_type),
     ADD INDEX idx_event_source (event_source),
     ADD INDEX idx_timestamp (timestamp);
   ```

3. **Pagination:**
   - Dashboard auto-paginates at 100 events
   - Use filters to narrow results

4. **Archive Old Events:**
   - Go to **SESSYPress** → **Settings**
   - Under **Data Retention**:
     - Set "Auto-delete events older than: 90 days"
     - Click **Delete Old Events Now** for immediate cleanup

5. **Disable Delivery Events:**
   - Delivery events create most volume
   - In SNS Notifications, disable delivery topic
   - In Event Publishing, uncheck "Deliveries"

---

## AWS Costs Estimation

### SNS Pricing (as of 2026)

- **Requests**: $0.50 per 1 million Amazon SNS requests
- **HTTPS Deliveries**: $0.60 per 100,000 notifications

**Example:** 10,000 emails/month with open/click tracking:
- Events: Send (10K) + Delivery (10K) + Opens (~3K @ 30% rate) + Clicks (~500 @ 5% rate) = ~23,500 events
- SNS Cost: ~$0.24/month

### SES Pricing

- **Emails**: $0.10 per 1,000 emails (first 1,000 free via AWS Free Tier)
- **Data Transfer**: $0.12 per GB

**Example:** 10,000 emails/month:
- Email Cost: $0.90/month
- **Total (SNS + SES): ~$1.14/month**

### Cost Optimization Tips

1. **Disable Delivery Events:** Reduces event volume by ~40%
2. **Use Event Publishing Only:** Avoid duplicate events from SNS Notifications
3. **Archive Old Events:** Clean up database to reduce storage
4. **Monitor with AWS Budgets:** Set alerts for unexpected costs

---

## Next Steps

After completing this setup:

1. ✅ **Test thoroughly** with simulator addresses
2. ✅ **Monitor dashboard** for incoming events
3. ✅ **Configure data retention** to manage database size
4. ✅ **Set up unsubscribe list** for compliance
5. ✅ **Export events** for external analytics

For more help:
- [FAQ](FAQ.md) - Common questions
- [Troubleshooting](TROUBLESHOOTING.md) - Detailed error solutions
- [GitHub Issues](https://github.com/YOUR_USERNAME/sessypress/issues) - Report bugs

---

**Need Help?**

- 📧 Email: support@yourplugin.com
- 💬 Discord: [Join our community](#)
- 📖 Documentation: [Full docs](#)
