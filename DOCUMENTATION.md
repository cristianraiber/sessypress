# SESSYPress - Complete Email Tracking Documentation

## Table of Contents

1. [Overview](#overview)
2. [AWS SES Tracking Methods](#aws-ses-tracking-methods)
3. [Architecture](#architecture)
4. [Features](#features)
5. [Implementation Plan](#implementation-plan)
6. [Database Schema](#database-schema)
7. [API Endpoints](#api-endpoints)
8. [Setup Guide](#setup-guide)

---

## Overview

**SESSYPress** is a WordPress plugin that provides complete email tracking and deliverability monitoring for emails sent through Amazon SES (Simple Email Service).

### Why Two Tracking Methods?

AWS SES provides **two different systems** for email tracking:

1. **SNS Notifications** (legacy/simple)
2. **Event Publishing with Configuration Sets** (modern/comprehensive)

SESSYPress supports **BOTH** methods to ensure maximum compatibility and flexibility.

---

## AWS SES Tracking Methods

### Method 1: SNS Notifications (Legacy)

**Configuration Location:** SES Identity > Notifications tab

**Supported Events:**
- ✅ Bounce (hard & soft)
- ✅ Complaint (spam reports)
- ✅ Delivery (successful delivery)

**Event Field:** `notificationType`

**Limitations:**
- ❌ No open tracking
- ❌ No click tracking
- ❌ No send/reject events
- ❌ No delivery delay tracking
- ❌ Configured per identity (domain/email)

**Best For:**
- Simple bounce/complaint monitoring
- Legacy implementations
- Quick setup without configuration sets

**JSON Structure:**
```json
{
  "notificationType": "Bounce|Complaint|Delivery",
  "mail": { ... },
  "bounce": { ... }
}
```

---

### Method 2: Event Publishing (Modern)

**Configuration Location:** SES Configuration Sets > Event Destinations

**Supported Events:**
- ✅ Send (email accepted by SES)
- ✅ Reject (email rejected by SES - virus/spam)
- ✅ Bounce (hard & soft)
- ✅ Complaint (spam reports)
- ✅ Delivery (successful delivery)
- ✅ Open (email opened - **native SES tracking**)
- ✅ Click (link clicked - **native SES tracking**)
- ✅ Rendering Failure (template errors)
- ✅ Delivery Delay (temporary failures)
- ✅ Subscription (unsubscribe events)

**Event Field:** `eventType`

**Advantages:**
- ✅ **Native open/click tracking** (SES injects tracking automatically)
- ✅ More granular events (send, reject, delay, etc.)
- ✅ Link tags support (tag specific links for analytics)
- ✅ User agent and IP address included
- ✅ Per-configuration-set control
- ✅ Template rendering failure detection

**Best For:**
- Complete email analytics
- Marketing campaigns
- Transactional email monitoring
- Advanced deliverability tracking

**JSON Structure:**
```json
{
  "eventType": "Send|Reject|Bounce|Complaint|Delivery|Open|Click|...",
  "mail": { ... },
  "open": {
    "ipAddress": "...",
    "timestamp": "...",
    "userAgent": "..."
  },
  "click": {
    "ipAddress": "...",
    "timestamp": "...",
    "userAgent": "...",
    "link": "...",
    "linkTags": { ... }
  }
}
```

---

## Architecture

### Core Components

```
┌─────────────────────────────────────────────────────────┐
│                     SESSYPress Plugin                    │
├─────────────────────────────────────────────────────────┤
│                                                           │
│  ┌──────────────────┐        ┌────────────────────┐    │
│  │  SNS Webhook     │        │  Admin Dashboard   │    │
│  │  Endpoint        │◄───────┤  - Statistics      │    │
│  │  (REST API)      │        │  - Event History   │    │
│  └────────┬─────────┘        │  - Configuration   │    │
│           │                   └────────────────────┘    │
│           │                                              │
│           ▼                                              │
│  ┌──────────────────────────────────────────────┐      │
│  │        Event Processor & Router              │      │
│  │  - Detect notification type vs event type    │      │
│  │  - Route to appropriate handler              │      │
│  │  - Store in database                         │      │
│  └───────────────┬──────────────────────────────┘      │
│                  │                                       │
│                  ▼                                       │
│  ┌─────────────────────────────────────────────┐       │
│  │            Database Layer                    │       │
│  │  - wp_ses_email_events (SNS/Event Pub)      │       │
│  │  - wp_ses_email_tracking (manual tracking)  │       │
│  └─────────────────────────────────────────────┘       │
│                                                          │
└─────────────────────────────────────────────────────────┘

         ▲                           ▲
         │                           │
    ┌────┴────┐                 ┌───┴────┐
    │   SNS   │                 │  SNS   │
    │ Notifs  │                 │ Events │
    └────▲────┘                 └───▲────┘
         │                          │
    ┌────┴──────────────────────────┴────┐
    │         Amazon SES                  │
    │  - Identity Notifications           │
    │  - Configuration Set Event Pub      │
    └─────────────────────────────────────┘
```

---

## Features

### 1. Dual Tracking Mode Support

- ✅ **SNS Notifications Mode** (bounce/complaint/delivery)
- ✅ **Event Publishing Mode** (all 10+ event types)
- ✅ **Hybrid Mode** (use both simultaneously)
- ✅ Auto-detection of notification type

### 2. Event Types Tracked

#### SNS Notifications
- Bounce (Permanent/Transient)
- Complaint (abuse/fraud/virus)
- Delivery (successful)

#### Event Publishing
- Send (accepted by SES)
- Reject (virus/bad content)
- Bounce (with detailed subtypes)
- Complaint (with feedback types)
- Delivery (with SMTP response)
- **Open** (IP, user agent, timestamp)
- **Click** (IP, user agent, link, link tags)
- Rendering Failure (template errors)
- Delivery Delay (temporary failures)
- Subscription (unsubscribe events)

### 3. Manual Tracking Fallback

For emails sent **without** SES Configuration Sets:
- ✅ 1x1 pixel open tracking
- ✅ URL rewrite click tracking
- ✅ Unsubscribe link detection
- ✅ Automatic injection into HTML emails

### 4. Admin Dashboard

- 📊 **Statistics Cards**
  - Total sent/delivered/bounced/complained
  - Open rate / Click rate
  - Bounce rate / Complaint rate
  - Unsubscribe count

- 📋 **Event Timeline**
  - Real-time event feed
  - Filter by type/recipient/date
  - Search functionality
  - Export to CSV

- ⚙️ **Configuration**
  - SNS endpoint URL with secret key
  - Enable/disable manual tracking
  - Data retention settings
  - Configuration set recommendations

### 5. Security

- ✅ Secret key validation for SNS requests
- ✅ Nonce verification for admin forms
- ✅ Capability checks (`manage_options`)
- ✅ Prepared SQL statements
- ✅ Input sanitization & output escaping
- ✅ Auto-confirm SNS subscriptions securely

---

## Implementation Plan

### Phase 1: Core Refactoring (Day 1)

**Goal:** Support both SNS Notifications and Event Publishing

#### Tasks:

1. **Event Processor Refactoring**
   - [ ] Create `Event_Detector` class
     - Detect `notificationType` vs `eventType`
     - Route to appropriate handler
   - [ ] Create `SNS_Notification_Handler` (existing functionality)
   - [ ] Create `Event_Publishing_Handler` (new)
   - [ ] Update `SNS_Handler` to use router

2. **Database Schema Updates**
   - [ ] Add `event_source` column (`sns_notification` | `event_publishing` | `manual_tracking`)
   - [ ] Add `event_metadata` column (JSON for IP, user agent, link, etc.)
   - [ ] Add indexes for performance
   - [ ] Migration script for existing data

3. **Event Publishing Handler**
   - [ ] Handle Send events
   - [ ] Handle Reject events
   - [ ] Handle Open events (IP, user agent)
   - [ ] Handle Click events (IP, user agent, link, linkTags)
   - [ ] Handle Rendering Failure events
   - [ ] Handle Delivery Delay events
   - [ ] Handle Subscription events

#### Files to Create/Modify:
```
includes/
├── class-event-detector.php (NEW)
├── class-event-publishing-handler.php (NEW)
├── class-sns-notification-handler.php (REFACTOR from class-sns-handler.php)
├── class-sns-handler.php (UPDATE - router only)
├── class-installer.php (UPDATE - DB schema)
└── admin/
    └── dashboard.php (UPDATE - new event types)
```

---

### Phase 2: Admin UI Enhancements (Day 2)

**Goal:** Better UX for configuration and monitoring

#### Tasks:

1. **Enhanced Dashboard**
   - [ ] Separate stats for SNS Notifications vs Event Publishing
   - [ ] Event timeline with filtering
   - [ ] Click heatmap (most clicked links)
   - [ ] Open rate by time of day chart
   - [ ] Top bouncing recipients list

2. **Configuration Wizard**
   - [ ] Step-by-step AWS setup guide
   - [ ] Configuration Set creation helper
   - [ ] SNS topic/subscription tester
   - [ ] Health check endpoint

3. **Settings Page Improvements**
   - [ ] Toggle: Use manual tracking vs SES native tracking
   - [ ] Configuration Set recommendation
   - [ ] Bulk data export/cleanup tools
   - [ ] Webhook URL copy-to-clipboard

#### Files to Create/Modify:
```
includes/admin/
├── dashboard.php (UPDATE - enhanced stats)
├── settings.php (UPDATE - wizard)
├── class-setup-wizard.php (NEW)
└── class-health-check.php (NEW)
```

---

### Phase 3: Manual Tracking Optimization (Day 3)

**Goal:** Improve fallback tracking for non-config-set emails

#### Tasks:

1. **Smart Tracking Injection**
   - [ ] Detect if Configuration Set is used
   - [ ] Skip injection if SES native tracking is active
   - [ ] Preserve existing tracking parameters
   - [ ] Support AMP emails

2. **Unsubscribe Management**
   - [ ] Global unsubscribe list
   - [ ] Per-list unsubscribe support
   - [ ] One-click unsubscribe (List-Unsubscribe header)
   - [ ] Unsubscribe preference center

3. **Link Analytics**
   - [ ] Link tagging (utm_source, utm_campaign)
   - [ ] Click tracking by campaign
   - [ ] A/B testing link variants

#### Files to Create/Modify:
```
includes/
├── class-tracking-injector.php (UPDATE - smart detection)
├── class-unsubscribe-manager.php (NEW)
└── class-link-analytics.php (NEW)
```

---

### Phase 4: Testing & Documentation (Day 4)

**Goal:** Production-ready release

#### Tasks:

1. **Automated Testing**
   - [ ] Unit tests for event handlers
   - [ ] Integration tests for SNS webhook
   - [ ] Mock SNS notification payloads
   - [ ] Database migration tests

2. **Documentation**
   - [ ] Complete README.md
   - [ ] AWS setup guide with screenshots
   - [ ] Troubleshooting guide
   - [ ] FAQ
   - [ ] Developer API documentation

3. **Performance Optimization**
   - [ ] Database query optimization
   - [ ] Caching for dashboard stats
   - [ ] Background processing for large batches
   - [ ] Database cleanup cron job

4. **Security Audit**
   - [ ] Code review (PHPCS WordPress standards)
   - [ ] Penetration testing
   - [ ] Input validation review
   - [ ] SQL injection prevention check

#### Files to Create:
```
tests/
├── test-event-detector.php
├── test-sns-handler.php
├── test-event-publishing-handler.php
└── fixtures/
    ├── sns-bounce.json
    ├── event-open.json
    └── event-click.json

docs/
├── AWS_SETUP.md
├── TROUBLESHOOTING.md
├── FAQ.md
└── API.md
```

---

### Phase 5: GitHub Release (Day 5)

**Goal:** Public release on GitHub

#### Tasks:

1. **Repository Setup**
   - [ ] Create GitHub repo: `sessypress`
   - [ ] Add .gitignore (WordPress standards)
   - [ ] Add LICENSE (GPL v2)
   - [ ] Add CONTRIBUTING.md
   - [ ] Add CHANGELOG.md

2. **CI/CD Setup**
   - [ ] GitHub Actions for automated tests
   - [ ] PHPCS linting on PRs
   - [ ] Automated release builds

3. **Release Preparation**
   - [ ] Tag version 1.0.0
   - [ ] Create release notes
   - [ ] Package plugin ZIP
   - [ ] Create demo video/screenshots

---

## Database Schema

### Table: `wp_ses_email_events`

Stores all email events (SNS Notifications + Event Publishing)

```sql
CREATE TABLE wp_ses_email_events (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  
  -- Message identifiers
  message_id varchar(255) NOT NULL,
  recipient varchar(255) NOT NULL,
  sender varchar(255) NOT NULL,
  subject varchar(500) DEFAULT NULL,
  
  -- Event classification
  event_source varchar(50) NOT NULL, -- 'sns_notification' | 'event_publishing'
  notification_type varchar(50) DEFAULT NULL, -- SNS: 'Bounce|Complaint|Delivery'
  event_type varchar(50) DEFAULT NULL, -- Event Pub: 'Send|Reject|Bounce|...'
  
  -- Bounce details
  bounce_type varchar(50) DEFAULT NULL,
  bounce_subtype varchar(50) DEFAULT NULL,
  diagnostic_code text DEFAULT NULL,
  
  -- Complaint details
  complaint_type varchar(50) DEFAULT NULL,
  
  -- Delivery details
  smtp_response text DEFAULT NULL,
  
  -- Open/Click details (Event Publishing only)
  event_metadata longtext DEFAULT NULL, -- JSON: {ip, userAgent, link, linkTags}
  
  -- Timestamps
  timestamp datetime NOT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  
  -- Raw payload for debugging
  raw_payload longtext DEFAULT NULL,
  
  PRIMARY KEY (id),
  KEY message_id (message_id),
  KEY recipient (recipient),
  KEY event_source (event_source),
  KEY notification_type (notification_type),
  KEY event_type (event_type),
  KEY timestamp (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Table: `wp_ses_email_tracking`

Stores manual tracking events (plugin-injected tracking)

```sql
CREATE TABLE wp_ses_email_tracking (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  
  message_id varchar(255) NOT NULL,
  tracking_type varchar(20) NOT NULL, -- 'open' | 'click' | 'unsubscribe'
  recipient varchar(255) NOT NULL,
  
  url varchar(1000) DEFAULT NULL, -- For clicks
  user_agent varchar(500) DEFAULT NULL,
  ip_address varchar(45) DEFAULT NULL,
  
  timestamp datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  
  PRIMARY KEY (id),
  KEY message_id (message_id),
  KEY tracking_type (tracking_type),
  KEY recipient (recipient),
  KEY timestamp (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## API Endpoints

### SNS Webhook (Unified)

```
POST /wp-json/sessypress/v1/webhook?key=SECRET_KEY
```

**Handles:**
- SNS subscription confirmations
- SNS notifications (bounce/complaint/delivery)
- Event publishing events (all 10+ types)

**Auto-detects:**
- `Type: SubscriptionConfirmation` → Confirm subscription
- `Type: Notification` → Parse message
  - If `notificationType` exists → SNS Notification handler
  - If `eventType` exists → Event Publishing handler

---

### Tracking Endpoints (Manual)

```
GET /?sessypress=1&action=open&mid=MESSAGE_ID&r=RECIPIENT
GET /?sessypress=1&action=click&mid=MESSAGE_ID&r=RECIPIENT&url=URL
GET /?sessypress=1&action=unsubscribe&mid=MESSAGE_ID&r=RECIPIENT
```

---

## Setup Guide

### Quick Start (SNS Notifications)

1. **Install & Activate Plugin**
2. **Copy Webhook URL** from Dashboard
3. **Create SNS Topics** (Bounces, Complaints, Deliveries)
4. **Create HTTPS Subscriptions** → Use webhook URL
5. **Configure SES Identity** → Link SNS topics
6. **Done!** Check Dashboard for events

### Advanced Setup (Event Publishing)

1. **Complete Quick Start** (above)
2. **Create Configuration Set** in AWS SES
3. **Add Event Destination** → SNS
4. **Select Events:** Send, Reject, Bounce, Complaint, Delivery, Open, Click
5. **Link SNS Topic** (same as Quick Start)
6. **Send Emails** with `X-SES-CONFIGURATION-SET` header
7. **Done!** Open/Click tracking now native

---

## Comparison Matrix

| Feature | SNS Notifications | Event Publishing | Manual Tracking |
|---------|-------------------|------------------|-----------------|
| Bounce tracking | ✅ | ✅ | ❌ |
| Complaint tracking | ✅ | ✅ | ❌ |
| Delivery tracking | ✅ | ✅ | ❌ |
| Open tracking | ❌ | ✅ Native | ✅ Pixel |
| Click tracking | ❌ | ✅ Native | ✅ Rewrite |
| Send events | ❌ | ✅ | ❌ |
| Reject events | ❌ | ✅ | ❌ |
| Rendering failures | ❌ | ✅ | ❌ |
| Delivery delays | ❌ | ✅ | ❌ |
| Unsubscribe events | ❌ | ✅ | ✅ |
| Link tags | ❌ | ✅ | ❌ |
| IP address | ❌ | ✅ | ✅ |
| User agent | ❌ | ✅ | ✅ |
| Configuration | Per Identity | Per Config Set | Global |
| Setup complexity | Low | Medium | None |

---

## Recommended Setup

### For Transactional Emails (order confirmations, password resets)
- Use **Event Publishing** with Configuration Set
- Enable: Send, Reject, Bounce, Complaint, Delivery
- Disable: Open, Click (privacy)

### For Marketing Emails (newsletters, campaigns)
- Use **Event Publishing** with Configuration Set
- Enable: All events (Send, Reject, Bounce, Complaint, Delivery, Open, Click)
- Use link tags for campaign tracking

### For Simple Monitoring (basic bounce management)
- Use **SNS Notifications** only
- Enable: Bounce, Complaint
- Optional: Delivery

### For Maximum Coverage (hybrid)
- Use **both SNS Notifications AND Event Publishing**
- SNS Notifications as fallback for emails without Config Sets
- Event Publishing for advanced tracking

---

## Next Steps

See [IMPLEMENTATION_PLAN.md](./IMPLEMENTATION_PLAN.md) for detailed development timeline.
