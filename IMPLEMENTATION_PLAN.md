# SESSYPress Implementation Plan

## Timeline: 5 Days

---

## Day 1: Core Refactoring - Event Publishing Support

### Morning (4h)

#### 1.1 Event Detector Class (1h)
**File:** `includes/class-event-detector.php`

**Responsibilities:**
- Detect SNS message type (SubscriptionConfirmation, Notification, UnsubscribeConfirmation)
- Parse Message payload
- Detect `notificationType` vs `eventType`
- Route to appropriate handler

**Methods:**
```php
detect_message_type( $data ): string
parse_notification( $data ): array
is_sns_notification( $message ): bool
is_event_publishing( $message ): bool
get_handler_for_event( $message ): string
```

**Tests:**
- SNS subscription confirmation
- SNS notification with `notificationType`
- SNS notification with `eventType`
- Invalid/malformed messages

---

#### 1.2 Database Migration (1h)
**File:** `includes/class-installer.php`

**Changes:**
- Add `event_source` column (varchar 50)
- Add `event_metadata` column (longtext)
- Add index on `event_source`
- Add index on `event_type`
- Migration function for existing data

**SQL:**
```sql
ALTER TABLE wp_ses_email_events 
  ADD COLUMN event_source VARCHAR(50) DEFAULT 'sns_notification' AFTER event_type,
  ADD COLUMN event_metadata LONGTEXT DEFAULT NULL AFTER smtp_response,
  ADD INDEX idx_event_source (event_source),
  ADD INDEX idx_event_type (event_type);
```

**Migration Logic:**
- Set `event_source = 'sns_notification'` for all existing records
- Update database version option

---

#### 1.3 Refactor SNS Handler (1h)
**File:** `includes/class-sns-handler.php` → Router only

**New Structure:**
```php
class SNS_Handler {
    private $detector;
    private $sns_notification_handler;
    private $event_publishing_handler;
    
    process( $request ) {
        // Validate secret
        // Detect type
        // Route to handler
    }
}
```

**File:** `includes/class-sns-notification-handler.php` (extract from old SNS_Handler)

Move existing bounce/complaint/delivery logic here.

---

#### 1.4 Event Publishing Handler Skeleton (1h)
**File:** `includes/class-event-publishing-handler.php`

**Methods:**
```php
handle_event( $message ): void
store_send_event( $message ): void
store_reject_event( $message ): void
store_open_event( $message ): void
store_click_event( $message ): void
store_rendering_failure_event( $message ): void
store_delivery_delay_event( $message ): void
store_subscription_event( $message ): void
extract_metadata( $event ): array // IP, user agent, link, etc.
```

**Initial Implementation:**
- Handle Send events
- Handle Reject events
- Store in `wp_ses_email_events` with `event_source = 'event_publishing'`

---

### Afternoon (4h)

#### 1.5 Implement Open Event Handler (1h)
**File:** `includes/class-event-publishing-handler.php`

**Logic:**
```php
private function store_open_event( $message ) {
    $mail = $message['mail'];
    $open = $message['open'];
    
    $metadata = [
        'ip_address' => $open['ipAddress'],
        'user_agent' => $open['userAgent'],
        'timestamp' => $open['timestamp'],
    ];
    
    $this->insert_event([
        'message_id' => $mail['messageId'],
        'event_source' => 'event_publishing',
        'event_type' => 'Open',
        'recipient' => // extract from mail.destination
        'event_metadata' => wp_json_encode($metadata),
        // ...
    ]);
}
```

**Handle:**
- IP address extraction
- User agent parsing
- Timestamp conversion
- Store in events table

---

#### 1.6 Implement Click Event Handler (1h)
**File:** `includes/class-event-publishing-handler.php`

**Logic:**
```php
private function store_click_event( $message ) {
    $mail = $message['mail'];
    $click = $message['click'];
    
    $metadata = [
        'ip_address' => $click['ipAddress'],
        'user_agent' => $click['userAgent'],
        'link' => $click['link'],
        'link_tags' => $click['linkTags'] ?? [],
        'timestamp' => $click['timestamp'],
    ];
    
    // Store click event
}
```

**Handle:**
- Link URL extraction
- Link tags (campaign tracking)
- IP/user agent
- Multiple clicks per recipient

---

#### 1.7 Implement Bounce/Complaint/Delivery for Event Publishing (1h)
**File:** `includes/class-event-publishing-handler.php`

**Re-use logic from SNS Notification Handler:**
- Bounce events (same structure as SNS)
- Complaint events (same structure)
- Delivery events (same structure)

**But store with:**
- `event_source = 'event_publishing'`
- `event_type` instead of `notification_type`

---

#### 1.8 Implement Delivery Delay & Rendering Failure (1h)
**File:** `includes/class-event-publishing-handler.php`

**Delivery Delay:**
```php
private function store_delivery_delay_event( $message ) {
    $deliveryDelay = $message['deliveryDelay'];
    
    $metadata = [
        'delay_type' => $deliveryDelay['delayType'],
        'expiration_time' => $deliveryDelay['expirationTime'],
        'reporting_mta' => $deliveryDelay['reportingMTA'],
        'delayed_recipients' => $deliveryDelay['delayedRecipients'],
    ];
    
    // Store with event_type = 'DeliveryDelay'
}
```

**Rendering Failure:**
```php
private function store_rendering_failure_event( $message ) {
    $failure = $message['failure'];
    
    $metadata = [
        'template_name' => $failure['templateName'],
        'error_message' => $failure['errorMessage'],
    ];
    
    // Store with event_type = 'RenderingFailure'
}
```

---

### Testing (Evening - 2h)

#### 1.9 Create Test Fixtures
**Directory:** `tests/fixtures/`

**Files:**
- `sns-bounce-notification.json`
- `sns-complaint-notification.json`
- `sns-delivery-notification.json`
- `event-send.json`
- `event-reject.json`
- `event-bounce.json`
- `event-open.json`
- `event-click.json`
- `event-delivery-delay.json`
- `event-rendering-failure.json`

**Content:** Real AWS SES payloads (from documentation examples)

---

#### 1.10 Manual Testing
- Send test notifications to local endpoint
- Verify database inserts
- Check `event_source` and `event_metadata` columns
- Verify routing works correctly

---

## Day 2: Admin UI Enhancements

### Morning (4h)

#### 2.1 Update Dashboard Stats (2h)
**File:** `includes/admin/dashboard.php`

**Add:**
- Separate stats for SNS Notifications vs Event Publishing
- New metrics:
  - Total Sent (from Send events)
  - Total Rejected (from Reject events)
  - Delivery Delays count
  - Rendering Failures count
  - Subscription events count

**UI Changes:**
- Color-coded badges by event source
- Toggle view: "All Events" | "SNS Only" | "Event Publishing Only"
- Filter by event type dropdown

---

#### 2.2 Event Timeline with Filtering (2h)
**File:** `includes/admin/dashboard.php`

**Features:**
- AJAX-powered event list (no page reload)
- Filters:
  - Event type (Send, Bounce, Open, Click, etc.)
  - Event source (SNS Notification, Event Publishing, Manual)
  - Date range picker
  - Recipient email search
  - Message ID search

**Table Columns:**
- Timestamp
- Event Type (badge with icon)
- Event Source (SNS/Event Pub/Manual)
- Recipient
- Subject
- Details (expand/collapse for metadata)
- Actions (View Raw JSON)

---

### Afternoon (4h)

#### 2.3 Click Heatmap (2h)
**File:** `includes/admin/dashboard.php` + new AJAX endpoint

**Display:**
- Top 10 most clicked links
- Click count per link
- Visual bar chart
- Link to full analytics page

**Query:**
```php
SELECT 
  JSON_EXTRACT(event_metadata, '$.link') as link,
  COUNT(*) as click_count
FROM wp_ses_email_events
WHERE event_type = 'Click'
  AND event_source = 'event_publishing'
GROUP BY link
ORDER BY click_count DESC
LIMIT 10
```

---

#### 2.4 Open Rate by Time Chart (2h)
**File:** `includes/admin/dashboard.php` + Chart.js

**Display:**
- Line chart showing opens by hour of day
- Helps identify best send times
- Group by hour: `HOUR(timestamp)`

**Query:**
```php
SELECT 
  HOUR(timestamp) as hour,
  COUNT(*) as open_count
FROM wp_ses_email_events
WHERE event_type = 'Open'
  AND event_source = 'event_publishing'
GROUP BY hour
ORDER BY hour
```

---

## Day 3: Manual Tracking Optimization

### Morning (4h)

#### 3.1 Smart Tracking Injection (2h)
**File:** `includes/class-tracking-injector.php`

**Goal:** Only inject tracking if SES Configuration Set is NOT used

**Detection Logic:**
```php
private function should_inject_tracking( $args ) {
    // Check if X-SES-CONFIGURATION-SET header is present
    if ( $this->has_configuration_set_header( $args ) ) {
        return false; // SES native tracking will handle it
    }
    
    // Check plugin settings
    $settings = get_option( 'sessypress_settings' );
    if ( ! $settings['enable_manual_tracking'] ) {
        return false;
    }
    
    return true;
}

private function has_configuration_set_header( $args ) {
    if ( ! isset( $args['headers'] ) ) {
        return false;
    }
    
    $headers = is_array( $args['headers'] ) ? $args['headers'] : explode( "\n", $args['headers'] );
    
    foreach ( $headers as $header ) {
        if ( stripos( $header, 'X-SES-CONFIGURATION-SET:' ) === 0 ) {
            return true;
        }
    }
    
    return false;
}
```

---

#### 3.2 Global Unsubscribe List (2h)
**File:** `includes/class-unsubscribe-manager.php`

**New Table:** `wp_ses_unsubscribes`

```sql
CREATE TABLE wp_ses_unsubscribes (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  email varchar(255) NOT NULL,
  reason varchar(500) DEFAULT NULL,
  unsubscribed_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY email (email)
);
```

**Methods:**
```php
class Unsubscribe_Manager {
    is_unsubscribed( $email ): bool
    add_to_unsubscribe_list( $email, $reason = '' ): void
    remove_from_unsubscribe_list( $email ): void
    get_unsubscribe_count(): int
}
```

**Integration:**
- Filter `wp_mail` to block unsubscribed emails
- Admin UI to view/manage unsubscribe list
- Export unsubscribe list to CSV

---

### Afternoon (4h)

#### 3.3 Link Analytics (2h)
**File:** `includes/class-link-analytics.php`

**Features:**
- Track UTM parameters (utm_source, utm_medium, utm_campaign)
- Store campaign metadata with click events
- Dashboard: Click analytics by campaign

**Methods:**
```php
extract_utm_params( $url ): array
get_clicks_by_campaign( $campaign ): int
get_top_campaigns( $limit = 10 ): array
```

---

#### 3.4 Settings Page: Manual Tracking Toggle (2h)
**File:** `includes/admin/settings.php`

**New Settings:**
- ☑️ Enable manual tracking (pixel/click rewrite)
- ☑️ Use SES native tracking (requires Configuration Set)
- Radio: "Prefer SES native" | "Prefer manual" | "Use both"
- Warning: "Manual tracking modifies email HTML"

**Help Text:**
- Explain when to use each method
- Link to AWS Configuration Set setup guide
- Show current tracking mode in Dashboard

---

## Day 4: Testing & Documentation

### Morning (4h)

#### 4.1 Unit Tests (2h)
**Directory:** `tests/`

**Files:**
- `test-event-detector.php`
- `test-sns-notification-handler.php`
- `test-event-publishing-handler.php`
- `test-tracking-injector.php`
- `test-unsubscribe-manager.php`

**Coverage:**
- Event detection logic
- Bounce/complaint/delivery parsing
- Open/click event storage
- Manual tracking injection
- Unsubscribe list management

---

#### 4.2 Integration Tests (2h)
**File:** `tests/test-sns-webhook.php`

**Tests:**
- SNS subscription confirmation
- SNS notification processing
- Event publishing message processing
- Invalid message handling
- Secret key validation

**Mock Requests:**
```php
$request = new WP_REST_Request( 'POST', '/sessypress/v1/webhook' );
$request->set_param( 'key', 'test-secret' );
$request->set_body( file_get_contents( 'fixtures/event-open.json' ) );

$response = $handler->process( $request );
$this->assertEquals( 200, $response->get_status() );
```

---

### Afternoon (4h)

#### 4.3 AWS Setup Guide with Screenshots (2h)
**File:** `docs/AWS_SETUP.md`

**Sections:**
1. Prerequisites (AWS account, SES identity verified)
2. SNS Notifications Setup (step-by-step)
3. Event Publishing Setup (Configuration Sets)
4. Testing Your Setup (simulator addresses)
5. Troubleshooting Common Issues

**Screenshots:**
- AWS SES Console
- SNS Topic creation
- SNS Subscription creation
- Configuration Set setup
- Event Destination configuration

---

#### 4.4 FAQ & Troubleshooting (2h)
**File:** `docs/FAQ.md`

**Questions:**
- What's the difference between SNS Notifications and Event Publishing?
- Do I need both?
- How do I enable native open/click tracking?
- Why aren't my events showing up?
- How do I test my setup?
- Can I use this with other email plugins?
- How do I export my data?
- What are the AWS costs?

**File:** `docs/TROUBLESHOOTING.md`

**Issues:**
- Events not showing in Dashboard
- SNS subscription not confirming
- "Invalid secret key" error
- Opens/clicks not tracking
- Duplicate events
- Performance issues with large datasets

---

## Day 5: GitHub Release & Packaging

### Morning (4h)

#### 5.1 Create GitHub Repository (1h)
**Repo:** `https://github.com/YOUR_USERNAME/sessypress`

**Setup:**
```bash
cd /path/to/plugin
git init
git add .
git commit -m "Initial commit - SESSYPress v1.0.0"
git remote add origin https://github.com/YOUR_USERNAME/sessypress.git
git push -u origin main
```

**Files:**
- `.gitignore` (WordPress standard)
- `LICENSE` (GPL v2)
- `CONTRIBUTING.md`
- `CHANGELOG.md`
- `README.md` (GitHub version with badges)

---

#### 5.2 GitHub Actions CI/CD (1h)
**File:** `.github/workflows/test.yml`

```yaml
name: Tests

on: [push, pull_request]

jobs:
  phpcs:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      - name: PHPCS
        run: |
          composer install
          vendor/bin/phpcs
  
  phpunit:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.0'
      - name: Run tests
        run: |
          composer install
          vendor/bin/phpunit
```

---

#### 5.3 Release Build Script (1h)
**File:** `build.sh`

```bash
#!/bin/bash

# Build plugin ZIP for distribution
VERSION="1.0.0"
PLUGIN_SLUG="sessypress"
OUTPUT_DIR="dist"

# Clean previous builds
rm -rf $OUTPUT_DIR
mkdir -p $OUTPUT_DIR

# Copy plugin files
rsync -av \
  --exclude='.git*' \
  --exclude='node_modules' \
  --exclude='tests' \
  --exclude='build.sh' \
  --exclude='*.md' \
  --exclude='docs' \
  ./ $OUTPUT_DIR/$PLUGIN_SLUG/

# Create ZIP
cd $OUTPUT_DIR
zip -r $PLUGIN_SLUG-$VERSION.zip $PLUGIN_SLUG/
cd ..

echo "✅ Build complete: $OUTPUT_DIR/$PLUGIN_SLUG-$VERSION.zip"
```

---

#### 5.4 Release Notes & Tagging (1h)
**File:** `CHANGELOG.md`

```markdown
# Changelog

## [1.0.0] - 2026-01-31

### Added
- SNS Notifications support (Bounce, Complaint, Delivery)
- Event Publishing support (Send, Reject, Open, Click, etc.)
- Dual-mode tracking (SNS + Event Publishing)
- Manual tracking fallback (pixel + URL rewrite)
- Admin dashboard with statistics
- Click heatmap and open rate charts
- Global unsubscribe list
- Link analytics with UTM tracking
- Automated tests and CI/CD
- Comprehensive documentation

### Security
- Secret key validation for SNS webhooks
- Input sanitization and output escaping
- Prepared SQL statements
- Capability checks for admin pages
```

**Git Tag:**
```bash
git tag -a v1.0.0 -m "Release v1.0.0"
git push origin v1.0.0
```

---

### Afternoon (4h)

#### 5.5 Demo Video & Screenshots (2h)

**Screenshots:**
1. Dashboard overview
2. Event timeline
3. Click heatmap
4. Open rate chart
5. Settings page
6. SNS endpoint configuration
7. Unsubscribe list

**Demo Video (5 minutes):**
1. Plugin installation
2. AWS SES setup
3. SNS subscription
4. Send test email
5. View events in dashboard
6. Click tracking demo
7. Open tracking demo

**Upload to:**
- GitHub README
- WordPress.org assets (if submitting)
- YouTube (unlisted)

---

#### 5.6 Final Code Review & Cleanup (2h)

**Checklist:**
- [ ] All TODOs removed
- [ ] No debug code (`var_dump`, `error_log`, etc.)
- [ ] PHPCS WordPress standards pass
- [ ] All functions documented (PHPDoc)
- [ ] No hardcoded values (use constants/options)
- [ ] Database cleanup on uninstall
- [ ] Translations ready (i18n)
- [ ] Performance optimized (query count, caching)

**Run:**
```bash
composer run phpcs
composer run phpstan
composer run phpunit
```

---

## Success Metrics

### Code Quality
- ✅ 100% PHPCS WordPress standards compliance
- ✅ PHPStan level 5 (no errors)
- ✅ 80%+ unit test coverage
- ✅ All integration tests pass

### Documentation
- ✅ Complete README with examples
- ✅ AWS setup guide with screenshots
- ✅ FAQ covers 10+ common questions
- ✅ Troubleshooting guide for errors
- ✅ Inline code documentation (PHPDoc)

### Performance
- ✅ Dashboard loads in <2s with 10K events
- ✅ Webhook processes notification in <500ms
- ✅ Database queries optimized (indexes used)
- ✅ No N+1 queries in admin pages

### Security
- ✅ No SQL injection vulnerabilities
- ✅ No XSS vulnerabilities
- ✅ No CSRF vulnerabilities
- ✅ Secret key entropy >= 128 bits
- ✅ Input sanitization 100% coverage
- ✅ Output escaping 100% coverage

---

## Post-Release Tasks

### Week 1
- Monitor GitHub issues
- Respond to user questions
- Fix critical bugs (hotfix release)

### Week 2-4
- Collect user feedback
- Plan v1.1 features
- Improve documentation based on FAQs

### Future Enhancements (v2.0)
- WooCommerce integration
- Easy Digital Downloads integration
- Visual email editor
- A/B testing for subject lines
- Scheduled email reports
- Webhooks for external integrations
- REST API for third-party tools
- Multi-site support
