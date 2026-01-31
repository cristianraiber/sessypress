# SES SNS Email Tracker

Track email deliverability through Amazon SNS notifications for WordPress sites using Amazon SES.

## Features

- ✅ **SNS Webhook Endpoint** - Receive bounce, complaint, and delivery notifications
- ✅ **Email Open Tracking** - 1x1 transparent pixel tracking
- ✅ **Link Click Tracking** - URL rewrite tracking for all links
- ✅ **Unsubscribe Tracking** - Automatic detection of unsubscribe links
- ✅ **Admin Dashboard** - Visual stats and event history
- ✅ **Automatic SNS Subscription** - Auto-confirms SNS subscriptions
- ✅ **Secure Endpoint** - Secret key validation for SNS requests

## Tracked Events

### SNS Notifications (from AWS SES)
- **Bounces** - Hard bounces (permanent) and soft bounces (transient)
- **Complaints** - Spam complaints from recipients
- **Deliveries** - Successful email delivery confirmations

### Email Tracking (injected by plugin)
- **Opens** - Email open tracking via 1x1 pixel
- **Clicks** - Link click tracking via URL rewrites
- **Unsubscribes** - Automatic unsubscribe link detection

## Installation

1. Upload the `ses-sns-tracker` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to **Email Tracking > Settings** to configure
4. Copy the SNS endpoint URL from the Dashboard
5. Configure AWS SES and SNS (see Setup Instructions below)

## AWS Setup

### 1. Create SNS Topics

Go to AWS SNS Console and create topics for:
- Bounces (or use one topic for all)
- Complaints
- Deliveries

### 2. Create SNS Subscriptions

For each SNS topic:
1. Click "Create subscription"
2. Protocol: **HTTPS**
3. Endpoint: `https://yoursite.com/wp-json/ses-sns-tracker/v1/ses-sns-webhook?key=YOUR_SECRET_KEY`
4. **Important:** Do NOT enable "Raw message delivery"
5. Create subscription - it will be auto-confirmed by the plugin

### 3. Configure SES Identity Notifications

1. Go to AWS SES > Configuration > Identities
2. Select your verified domain/email
3. Notifications tab > Edit
4. Select your SNS topics for each notification type:
   - Bounces → Your bounce SNS topic
   - Complaints → Your complaint SNS topic
   - Deliveries → Your delivery SNS topic
5. Save

## Testing

### Test Delivery
Send email to any valid address - check Dashboard for delivery event.

### Test Bounce
Send to `bounce@simulator.amazonses.com` - check for bounce event.

### Test Complaint
Send to `complaint@simulator.amazonses.com` - check for complaint event.

### Test Open Tracking
1. Enable "Track email opens" in Settings
2. Send a test email
3. Open the email in an email client
4. Check Dashboard for open event

### Test Click Tracking
1. Enable "Track link clicks" in Settings
2. Send a test email with links
3. Click a link in the email
4. Check Dashboard for click event

## Database Schema

### `wp_ses_email_events` Table
Stores SNS notifications (bounces, complaints, deliveries):
- `message_id` - SES message ID
- `notification_type` - Bounce, Complaint, or Delivery
- `event_type` - Event type (bounce, complaint, delivery)
- `recipient` - Recipient email address
- `sender` - Sender email address
- `subject` - Email subject
- `bounce_type` - Permanent or Transient
- `bounce_subtype` - Detailed bounce reason
- `complaint_type` - Complaint feedback type
- `diagnostic_code` - Detailed error message
- `smtp_response` - SMTP server response
- `timestamp` - Event timestamp
- `raw_payload` - Full JSON payload from SNS

### `wp_ses_email_tracking` Table
Stores tracking events (opens, clicks, unsubscribes):
- `message_id` - SES message ID
- `tracking_type` - open, click, or unsubscribe
- `recipient` - Recipient email address
- `url` - Clicked URL (for click events)
- `user_agent` - Browser user agent
- `ip_address` - Client IP address
- `timestamp` - Event timestamp

## REST API Endpoints

### SNS Webhook
```
POST /wp-json/ses-sns-tracker/v1/ses-sns-webhook?key=YOUR_SECRET_KEY
```

Handles:
- SNS subscription confirmations
- Bounce notifications
- Complaint notifications
- Delivery notifications

### Tracking Pixel (Email Opens)
```
GET /?ses_track=1&ses_action=open&mid=MESSAGE_ID&r=RECIPIENT_EMAIL
```

### Click Tracking
```
GET /?ses_track=1&ses_action=click&mid=MESSAGE_ID&r=RECIPIENT_EMAIL&url=ORIGINAL_URL
```

### Unsubscribe Tracking
```
GET /?ses_track=1&ses_action=unsubscribe&mid=MESSAGE_ID&r=RECIPIENT_EMAIL
```

## Security

- ✅ Secret key validation for SNS endpoints
- ✅ Nonce verification for admin forms
- ✅ Capability checks (`manage_options`)
- ✅ Input sanitization and output escaping
- ✅ Prepared SQL statements (`$wpdb->prepare()`)
- ✅ Auto-confirms SNS subscriptions securely

## Privacy & GDPR

This plugin tracks:
- Email opens (IP address, user agent, timestamp)
- Link clicks (IP address, user agent, clicked URL, timestamp)
- Unsubscribe requests

Make sure to:
- Update your privacy policy
- Inform users about email tracking
- Provide opt-out mechanisms
- Respect data retention settings

## Requirements

- WordPress 6.0+
- PHP 7.4+
- Amazon SES account
- Amazon SNS topics configured
- HTTPS (required for SNS webhooks)

## Changelog

### 1.0.0
- Initial release
- SNS webhook endpoint
- Bounce/complaint/delivery tracking
- Email open tracking
- Link click tracking
- Unsubscribe tracking
- Admin dashboard
- Settings page

## Support

For issues and feature requests, please contact WPChill support.

## License

GPL v2 or later
