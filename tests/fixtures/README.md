# SESSYPress Test Fixtures

This directory contains realistic AWS SES event payloads for testing the SESSYPress plugin.

## File Descriptions

### SNS Notifications (Traditional Method)

**`sns-bounce-notification.json`**
- Notification Type: Bounce
- Bounce Type: Permanent (hard bounce)
- Bounce SubType: General
- Simulates: User unknown / invalid email address
- Status Code: 5.1.1
- Use for: Testing bounce event handling and storage

**`sns-complaint-notification.json`**
- Notification Type: Complaint
- Complaint Feedback Type: abuse
- Simulates: Recipient marking email as spam
- Use for: Testing complaint event handling and sender reputation monitoring

**`sns-delivery-notification.json`**
- Notification Type: Delivery
- Processing Time: 1234ms
- SMTP Response: 250 2.0.0 OK
- Simulates: Successful email delivery
- Use for: Testing delivery confirmation tracking

---

### Event Publishing (Advanced Method)

**`event-send.json`**
- Event Type: Send
- Simulates: Email accepted by SES for sending
- Includes: Configuration Set header, tags
- Use for: Testing send event tracking and campaign tagging

**`event-reject.json`**
- Event Type: Reject
- Reject Reason: Bad content
- Simulates: SES rejecting email due to policy violation or suppression list
- Use for: Testing rejection handling

**`event-bounce.json`**
- Event Type: Bounce (via Event Publishing)
- Bounce Type: Permanent
- Simulates: Same as SNS bounce but via Event Publishing
- Use for: Testing Event Publishing bounce handler

**`event-open.json`**
- Event Type: Open
- Includes: IP address (198.51.100.42), User Agent (iPhone Safari)
- Timestamp: 5 minutes after send
- Simulates: Recipient opening email on mobile device
- Use for: Testing open tracking with metadata extraction

**`event-click.json`**
- Event Type: Click
- Includes: IP address, User Agent (macOS Chrome), Link URL
- Link Tags: Campaign tracking (campaign, post_id)
- UTM Parameters: utm_source, utm_medium, utm_campaign
- Simulates: Recipient clicking link on desktop
- Use for: Testing click tracking and link analytics

**`event-delivery-delay.json`**
- Event Type: DeliveryDelay
- Delay Type: TransientCommunicationFailure
- Status Code: 4.4.1 (temporary failure)
- Expiration Time: 6 hours after initial attempt
- Simulates: Recipient server temporarily unavailable
- Use for: Testing delivery delay notifications

**`event-rendering-failure.json`**
- Event Type: RenderingFailure
- Template Name: WelcomeTemplate
- Error Message: Missing attribute in rendering data
- Simulates: SES template rendering error (SendTemplatedEmail)
- Use for: Testing template failure handling

---

## How to Use These Fixtures

### 1. Manual Testing

Send fixture to webhook endpoint:

```bash
# Replace YOUR_SECRET_KEY with actual key from plugin settings
curl -X POST https://yoursite.com/wp-json/sessypress/v1/webhook?key=YOUR_SECRET_KEY \
  -H "Content-Type: application/json" \
  -H "x-amz-sns-message-type: Notification" \
  --data @event-open.json
```

### 2. Unit Testing

Load fixture in PHPUnit test:

```php
public function test_open_event_processing() {
    $fixture = file_get_contents( __DIR__ . '/fixtures/event-open.json' );
    $data = json_decode( $fixture, true );
    
    $handler = new Event_Publishing_Handler();
    $result = $handler->process_event( $data );
    
    $this->assertTrue( $result );
}
```

### 3. Integration Testing

Simulate SNS webhook request:

```php
public function test_sns_webhook_handles_click_event() {
    $fixture = file_get_contents( __DIR__ . '/fixtures/event-click.json' );
    
    $request = new WP_REST_Request( 'POST', '/sessypress/v1/webhook' );
    $request->set_param( 'key', get_option( 'sessypress_secret_key' ) );
    $request->set_header( 'x-amz-sns-message-type', 'Notification' );
    $request->set_body( $fixture );
    
    $response = rest_do_request( $request );
    
    $this->assertEquals( 200, $response->get_status() );
    
    // Verify event was stored
    global $wpdb;
    $event = $wpdb->get_row( 
        "SELECT * FROM {$wpdb->prefix}ses_email_events 
         WHERE event_type = 'Click' 
         ORDER BY id DESC LIMIT 1"
    );
    
    $this->assertNotNull( $event );
    $this->assertEquals( 'Click', $event->event_type );
}
```

### 4. Load Testing

Send multiple fixtures simultaneously:

```bash
# Bash script to send 100 events
for i in {1..100}; do
  curl -X POST https://yoursite.com/wp-json/sessypress/v1/webhook?key=YOUR_KEY \
    -H "Content-Type: application/json" \
    -H "x-amz-sns-message-type: Notification" \
    --data @event-send.json &
done
wait
```

---

## Fixture Characteristics

### Realistic Data

All fixtures use realistic values based on actual AWS SES documentation:

- **Message IDs**: Follow AWS format (`0102018b5e2f5e8f-...`)
- **ARNs**: Valid AWS ARN structure
- **Timestamps**: ISO 8601 format with timezone
- **IP Addresses**: Documentation IP ranges (203.0.113.0/24, 198.51.100.0/24)
- **User Agents**: Real browser user agent strings
- **SMTP Codes**: Standard SMTP status codes (250, 421, 550)

### Headers

Each event includes realistic email headers:
- From, To, Subject
- MIME-Version, Content-Type
- X-SES-CONFIGURATION-SET (for Event Publishing)

### Tags

Event Publishing fixtures include tags:
- `ses:configuration-set`
- `ses:from-domain`
- `ses:caller-identity`
- Custom tags (campaign, utm_source, etc.)

---

## Testing Scenarios

### Happy Path
1. `event-send.json` → Email sent
2. `sns-delivery-notification.json` → Email delivered
3. `event-open.json` → Recipient opens email
4. `event-click.json` → Recipient clicks link

### Bounce Scenarios
1. **Hard Bounce**: `sns-bounce-notification.json` or `event-bounce.json`
2. **Soft Bounce**: Create variation with `bounceType: "Transient"`
3. **Suppression Bounce**: Use `event-reject.json`

### Engagement Tracking
1. **Multiple Opens**: Send `event-open.json` multiple times with different timestamps
2. **Multiple Clicks**: Send `event-click.json` with different link values
3. **Different Devices**: Vary User-Agent header

### Error Handling
1. **Rendering Failure**: `event-rendering-failure.json`
2. **Delivery Delay**: `event-delivery-delay.json`
3. **Complaint**: `sns-complaint-notification.json`

---

## Customizing Fixtures

To create custom variations, modify JSON fields:

### Change Recipient
```json
"destination": ["your-test@example.com"]
```

### Change Event Timestamp
```json
"timestamp": "2026-02-01T10:00:00.000Z"
```

### Add Custom Tags
```json
"tags": {
  "campaign": ["spring-sale"],
  "product_id": ["12345"]
}
```

### Change Bounce Type
```json
"bounce": {
  "bounceType": "Transient",  // Soft bounce
  "bounceSubType": "MailboxFull"
}
```

---

## Validation

All fixtures have been validated against AWS SES documentation:

- [SNS Notification Examples](https://docs.aws.amazon.com/ses/latest/dg/notification-contents.html)
- [Event Publishing Examples](https://docs.aws.amazon.com/ses/latest/dg/event-publishing-retrieving-sns-examples.html)

**Schema validation:**
```bash
# Install ajv-cli
npm install -g ajv-cli

# Validate against schema (if you have schema file)
ajv validate -s ses-event-schema.json -d event-open.json
```

---

## Contributing

When adding new fixtures:

1. **Use realistic data** - Base on AWS documentation examples
2. **Include all required fields** - Refer to SES event structure
3. **Add description** - Update this README with fixture purpose
4. **Test thoroughly** - Ensure fixture processes without errors
5. **Follow naming convention** - `[source]-[event-type].json`

---

## References

- [AWS SES Event Publishing](https://docs.aws.amazon.com/ses/latest/dg/monitor-sending-using-event-publishing.html)
- [SNS Notification Content](https://docs.aws.amazon.com/ses/latest/dg/notification-contents.html)
- [Event Data Structure](https://docs.aws.amazon.com/ses/latest/dg/event-publishing-retrieving-sns-contents.html)
- [AWS Mailbox Simulator](https://docs.aws.amazon.com/ses/latest/dg/send-an-email-from-console.html#send-email-simulator)
