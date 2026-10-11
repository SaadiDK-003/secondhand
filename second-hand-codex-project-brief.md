# Second Hand Marketplace — Codex Project Brief

## Project Name

**Second Hand** / **2nd Hand**

### Recommended Tagline

> **Good Things Deserve a Second Home.**

### Possible Domains

- `second-hand.pk`
- `second-hand.com.pk`

Domain is not finalized yet. The website title/brand should remain **Second Hand** or **2nd Hand** regardless of the final domain.

---

# 1. Project Overview

Build a modern Pakistan-focused online marketplace where users can buy and sell **used / second-hand physical items**.

The platform should be inspired by the usability of OLX Pakistan, but it must **not be a visual or code clone**.

The goal is to create a cleaner, faster, secure, mobile-first marketplace focused specifically on pre-owned products.

Examples of items users should be able to sell:

- Sofas
- Furniture
- Refrigerators
- TVs
- Computers
- Laptops
- Mobile phones
- Tablets
- Home appliances
- Gaming devices
- Wedding clothes
- Bridal dresses
- Sherwanis
- Kids items
- Books
- Sports equipment
- Bicycles
- Tools
- Collectibles
- Other used household items

Initially, avoid unrelated marketplace categories such as:

- Property
- Jobs
- Services
- Animals
- New retail products

These may be considered in future versions.

---

# 2. Main Objective

Create a scalable Laravel marketplace with:

- Excellent performance
- Strong security
- SEO-friendly public pages
- Mobile-first responsive UI
- Easy ad posting
- Powerful filtering
- Fast search
- Optimized images
- Internal buyer/seller messaging
- Admin moderation
- Architecture that can later support Android/iOS apps

---

# 3. Recommended Tech Stack

## Backend

- Laravel 13
- PHP 8.4

## Frontend

- Vue 3
- TypeScript
- Inertia.js
- Server-Side Rendering where appropriate
- Tailwind CSS 4
- shadcn-vue or an equivalent clean Vue component approach

## Database

- PostgreSQL

Use PostgreSQL from the start.

Later enable PostGIS if radius/location-based searching is introduced.

Examples:

- Used sofas within 10 km
- Mobiles near North Nazimabad
- Refrigerators near the buyer's current area

## Cache / Queue / Sessions

- Redis
- Laravel Horizon

Redis should be used for:

- Cache
- Sessions
- Queue
- Rate limiting
- Background jobs
- Notification jobs
- Search indexing jobs
- Image processing jobs

## Search

- Laravel Scout
- Meilisearch

Do not use SQL `%LIKE%` queries as the long-term search solution.

Search should support:

- Keyword search
- Category
- City
- Area
- Price
- Condition
- Category-specific filters
- Sorting

## File Storage

Preferred:

- Cloudflare R2

Alternative:

- AWS S3

Do not store production listing images permanently inside the Laravel public directory.

## CDN / Security Layer

- Cloudflare

Use Cloudflare for:

- DNS
- CDN
- WAF
- DDoS protection
- Asset caching

## Web Server

- Nginx
- PHP-FPM

## Deployment

- Docker
- GitHub
- GitHub Actions

## Monitoring

- Sentry
- Laravel Pulse
- Laravel Telescope for local/development use only

## Admin Panel

- Filament

---

# 4. Architectural Approach

Build the application as a **well-structured Laravel modular monolith**.

Do NOT begin with:

- Microservices
- Kubernetes
- GraphQL
- Elasticsearch
- MongoDB
- Multiple independent backend services
- Separate React frontend unless a clear future requirement justifies it
- Laravel Octane before performance data proves it is needed

Keep the architecture simple but clean enough to scale.

---

# 5. Suggested Laravel Structure

Keep controllers thin.

Suggested structure:

```text
app/

Actions/
DTOs/
Enums/
Events/
Jobs/
Listeners/
Models/
Notifications/
Policies/
Services/
Support/
```

Optional domain-based organization:

```text
app/
└── Domain/
    ├── Users/
    ├── Listings/
    ├── Categories/
    ├── Search/
    ├── Messaging/
    ├── Moderation/
    └── Payments/
```

Avoid business logic inside controllers.

Prefer:

- Actions
- Services
- Jobs
- Policies
- Form Requests
- DTOs
- Enums

---

# 6. Core Marketplace Data Model

Do NOT create separate tables such as:

```text
mobiles
sofas
fridges
computers
wedding_clothes
```

Use a generic marketplace data model.

Suggested core tables:

```text
users

categories
category_attributes
category_attribute_options

listings
listing_attribute_values
listing_images

provinces
cities
areas

favorites

conversations
conversation_participants
messages

listing_views

listing_reports
user_reports

blocked_users

notifications

search_histories
saved_searches

featured_listings

payments
subscriptions

activity_logs

prohibited_keywords
moderation_rules
```

Exact schema may evolve during implementation.

---

# 7. Category System

The category system must support unlimited nesting or at least:

```text
Category
    └── Subcategory
```

Initial suggested categories:

```text
Mobiles & Tablets
Computers & Laptops
Electronics
Home Appliances
Furniture
Fashion
Wedding Clothes & Accessories
Kids & Baby Items
Gaming
Bikes & Cycles
Sports & Fitness
Books
Tools & Equipment
Hobbies & Collectibles
Other Used Items
```

---

# 8. Dynamic Category Attributes

Do NOT hard-code category-specific fields into the `listings` table.

Use dynamic attributes.

Example:

## Mobile Phone

```text
Brand
Model
Storage
RAM
PTA Approved
Condition
Warranty
Box Available
```

## Refrigerator

```text
Brand
Capacity
Type
Inverter
Condition
Warranty
```

## Sofa

```text
Seats
Material
Color
Condition
Dimensions
```

## Wedding Clothes

```text
Gender
Dress Type
Size
Designer
Color
Condition
Used Count
```

Suggested tables:

```text
categories
category_attributes
category_attribute_options
listing_attribute_values
```

Attributes should support data types such as:

- text
- number
- boolean
- select
- multi-select

---

# 9. Listing Model

Suggested listing fields:

```text
id
user_id
category_id
title
slug
description
price
currency
is_negotiable
condition
province_id
city_id
area_id
latitude
longitude
status
published_at
expires_at
sold_at
created_at
updated_at
```

Possible statuses:

```text
draft
pending
published
rejected
sold
expired
archived
blocked
```

Use enums wherever appropriate.

---

# 10. Listing Lifecycle

Recommended lifecycle:

```text
Draft
  ↓
Pending
  ↓
Published
  ↓
Sold
  ↓
Archived
```

Moderation outcomes:

```text
Rejected
Blocked
Deleted
```

Listings may automatically expire after a configurable number of days, such as:

- 30 days
- 60 days

Users should be able to renew eligible listings.

---

# 11. Authentication

Initial authentication should support:

- Email
- Password
- Mobile number

Later add:

- Mobile OTP
- Email verification
- Google login

Admin users should support:

- Mandatory 2FA

Use Laravel authentication/security best practices.

---

# 12. User Profile

Seller profile should support:

```text
Name
Profile photo
Joined date
Phone verification status
Email verification status
Number of listings
Number of sold items
Response information
Location
```

Possible future features:

- Verified seller badge
- Ratings
- Reviews
- Seller stores
- Business accounts

---

# 13. Ad Posting Flow

Keep ad posting extremely simple.

Suggested flow:

## Step 1

Select category.

## Step 2

Select subcategory.

## Step 3

Fill category-specific attributes.

## Step 4

Upload images.

Allow approximately:

- Minimum: 1 image
- Maximum: 12 images

## Step 5

Enter:

```text
Title
Description
Price
Negotiable status
Condition
```

## Step 6

Choose:

```text
Province
City
Area
```

## Step 7

Preview listing.

## Step 8

Publish / Submit for moderation.

---

# 14. Images

Image optimization is critical.

When a user uploads a large image such as:

```text
4032 × 3024
6 MB
```

do not serve the original image directly.

Process images asynchronously.

Generate sizes similar to:

```text
thumbnail
300x225

card
600x450

medium
1000x750

large
1600x1200
```

Preferred formats:

- WebP
- AVIF where supported

Store processed images in Cloudflare R2 or S3.

Use queued image-processing jobs.

Strip unnecessary EXIF metadata where possible.

Validate:

- MIME type
- File extension
- File size
- Actual image dimensions

Do not trust browser-provided file metadata.

---

# 15. Search

Implement:

```text
Laravel Scout
+
Meilisearch
```

Search must eventually support:

```text
Keyword
Category
Subcategory
Province
City
Area
Minimum price
Maximum price
Condition
Brand
Category-specific attributes
```

Sorting:

```text
Most Relevant
Newest
Price Low to High
Price High to Low
Nearest
```

Search indexing should happen asynchronously through queues.

---

# 16. Location System

Create:

```text
provinces
cities
areas
```

Use proper relationships.

Do not store all location values as random plain-text strings.

Structure examples:

```text
Sindh
  Karachi
    North Nazimabad
    Gulshan-e-Iqbal
    DHA
    Clifton

Punjab
  Lahore
  Rawalpindi
  Faisalabad
```

Future support:

- latitude
- longitude
- PostGIS
- radius search

---

# 17. Favorites

Users should be able to favorite listings.

Use a `favorites` table.

Users should have a page:

```text
My Favorites
```

Future:

- Saved searches
- Search alerts

Example:

```text
iPhone 14 Pro
Karachi
Rs 150,000 - 200,000
```

---

# 18. Internal Messaging

Implement buyer/seller chat.

Suggested tables:

```text
conversations
conversation_participants
messages
```

Initial version can use normal HTTP polling or database refresh mechanisms.

Later add:

- Laravel Reverb
- WebSockets
- Real-time messaging
- Typing indicators
- Read receipts

Quick message examples:

```text
Is this still available?
Final price?
Can I see it today?
```

Do not expose private user data unnecessarily.

---

# 19. Phone Number Privacy

Do not place seller phone numbers directly inside public HTML where scrapers can easily collect them.

Preferred flow:

```text
Show Phone Number
```

Only reveal after an intentional user interaction and appropriate anti-abuse checks.

Encourage internal messaging first.

Future protections:

- Authentication requirement
- Rate limits
- CAPTCHA for suspicious behavior
- Logging reveal attempts

---

# 20. Fraud & Abuse Protection

Design moderation from the beginning.

Required features:

```text
Report Listing
Report User
Block User
Ban User
Listing Moderation
Prohibited Keyword Detection
Suspicious URL Detection
Spam Protection
Rate Limiting
Duplicate Listing Detection
IP Logging
Activity Logs
```

Do not create invasive tracking.

Only collect data reasonably required for security and abuse prevention.

Admin should be able to:

```text
Approve listing
Reject listing
Suspend listing
Ban user
Unban user
Review reports
Review activity history
```

---

# 21. Security Requirements

Follow Laravel and OWASP best practices.

Mandatory:

- CSRF protection
- XSS protection
- SQL injection protection through ORM/query bindings
- Authorization policies
- Form Request validation
- Rate limits
- Secure password hashing
- Secure cookies
- HTTPS only in production
- Proper session configuration
- Database indexes
- File upload validation
- Content Security Policy where practical
- Prevent mass assignment vulnerabilities
- Do not expose stack traces in production
- Do not expose `.env`
- Do not commit secrets
- Use environment variables
- Secure admin routes
- 2FA for administrators
- Validate ownership before update/delete operations
- Prevent IDOR vulnerabilities
- Log sensitive admin actions

Never trust:

- client-side validation
- hidden fields
- user-provided IDs
- file extensions
- query string permissions

Every protected action must be validated server-side.

---

# 22. Admin Panel

Use Filament.

Suggested admin sections:

```text
Dashboard

Users
Listings
Pending Listings
Reported Listings
Categories
Category Attributes
Cities
Areas
Reports
Blocked Users
Moderation
Featured Listings
Payments
Subscriptions
Analytics
Application Settings
```

Dashboard metrics may include:

```text
Total Users
Active Users
Total Listings
Published Listings
Pending Listings
Sold Listings
Reported Listings
New Users Today
New Listings Today
```

---

# 23. SEO

SEO must be designed from the beginning.

Use human-readable routes.

Examples:

```text
/karachi
/karachi/mobiles
/karachi/mobile-phones
/karachi/furniture
/karachi/furniture/sofas
/lahore/mobiles/apple-iphone

/ad/iphone-14-pro-256gb-123456
```

Public listing pages should generate:

- Dynamic title
- Meta description
- Canonical URL
- OpenGraph tags
- Twitter/X cards where applicable
- Structured data
- Breadcrumbs
- XML sitemap
- Robots.txt

Avoid duplicate indexed pages created by uncontrolled filter combinations.

---

# 24. Performance Requirements

Primary goals:

- Fast first page load
- Low database query count
- Responsive mobile experience
- Minimal JavaScript where possible
- Lazy-loaded images
- Optimized thumbnails
- CDN delivery
- Cached common data
- Background jobs for expensive work

Use:

- Redis cache
- eager loading
- proper DB indexes
- pagination
- cursor pagination where useful
- queues
- CDN
- HTTP caching where appropriate

Avoid N+1 queries.

Measure performance before introducing advanced infrastructure.

---

# 25. Homepage

Suggested homepage structure:

```text
Header
Logo
Location Selector
Search Bar
Login
Post Ad / Sell Button

Hero Search Section

Browse Categories

Fresh Listings Near You

Recently Added

Popular Searches

Why Use Second Hand?

Footer
```

The **SELL / POST AD** button should be highly visible.

---

# 26. Search Results Page

The listing/search page should support:

```text
Search Query
Location
Category
Filters
Sort
Pagination
```

Desktop:

```text
Left Sidebar Filters
Right Listing Grid
```

Mobile:

```text
Filter Button
Sort Button
Listing Cards
```

Cards should show only important information:

```text
Image
Price
Title
Area / City
Posted Date
Favorite Icon
```

Avoid overly cluttered cards.

---

# 27. Listing Detail Page

Suggested sections:

```text
Image Gallery
Price
Title
Location
Posted Date
Description
Item Details
Category Attributes
Seller Information
Contact Seller
Chat Button
Favorite Button
Report Listing
Similar Listings
```

The page must be mobile-first.

---

# 28. Responsive Design

Design for mobile first.

The majority of users may browse from phones.

Support:

- Mobile
- Tablet
- Desktop

Avoid desktop layouts that become difficult to use on mobile.

Important interactions should remain usable with one hand where practical.

---

# 29. Accessibility

Use accessible HTML.

Requirements:

- semantic elements
- keyboard navigation
- visible focus state
- image alt text
- labels for form fields
- sufficient contrast
- accessible dialogs
- meaningful buttons
- proper heading hierarchy

---

# 30. MVP Scope

Build Version 1 with only essential functionality.

## MVP Features

1. Registration
2. Login
3. Logout
4. User profile
5. Categories
6. Subcategories
7. Dynamic category attributes
8. Provinces
9. Cities
10. Areas
11. Create listing
12. Edit listing
13. Delete listing
14. Multiple images
15. Image optimization
16. Search
17. Filters
18. Listing detail page
19. Seller profile
20. Favorites
21. Internal chat
22. Report listing
23. Report user
24. Block user
25. Admin moderation
26. Mark item as sold
27. Listing expiry
28. SEO URLs
29. Meta tags
30. Sitemap
31. Mobile responsive UI
32. Redis cache
33. Redis queues
34. Meilisearch integration
35. Cloud storage integration

Do not overbuild the MVP.

---

# 31. Version 2 Ideas

After users start using the marketplace:

```text
Featured Ads
Boost to Top
Urgent Badge
Verified Seller
Seller Shops
Saved Searches
Search Alerts
Push Notifications
WhatsApp Notifications
Recommended Listings
Similar Listings
Business Accounts
Advanced Analytics
```

---

# 32. Monetization

Possible future monetization:

```text
Featured Listing
Top Listing
Urgent Badge
Homepage Promotion
Verified Seller
Business Store
Subscription Plans
Seller Packages
```

Do not add payment complexity until the core marketplace is stable.

---

# 33. Future Mobile Applications

The Laravel backend must be designed so that Android/iOS apps can later reuse it.

Future API:

```text
/api/v1/
```

Possible consumers:

```text
Web
Android
iOS
```

Do not force everything into API architecture in Version 1, but avoid decisions that make future APIs difficult.

---

# 34. Coding Standards

Follow these rules throughout development.

## PHP / Laravel

- PSR-12
- strict typing where practical
- typed return values
- typed properties
- Laravel conventions
- small focused classes
- dependency injection
- policies for authorization
- Form Requests for validation
- database transactions where required

## Vue / TypeScript

- TypeScript required
- reusable components
- avoid huge components
- use composables for reusable state/logic
- no unnecessary global state
- avoid `any` unless unavoidable

## Naming

Use clear descriptive names.

Bad:

```text
data
item2
temp
doStuff()
```

Good:

```text
listing
listingImages
publishListing()
calculateDistance()
```

---

# 35. Testing

Write tests for business-critical functionality.

Use:

- Pest or PHPUnit
- Laravel Feature Tests
- Unit Tests where valuable

Important flows to test:

```text
Register user
Login
Create listing
Edit own listing
Prevent editing another user's listing
Publish listing
Mark listing sold
Favorite listing
Report listing
Search listing
Admin moderation
File validation
Rate limits
```

Do not chase meaningless test coverage percentages.

Focus on important behavior.

---

# 36. Database Rules

Use migrations for every schema change.

Never manually modify production database schemas.

Use:

- foreign keys
- indexes
- unique constraints
- proper nullable constraints
- enums/value objects where useful

Add indexes to common search/filter fields.

Likely indexes:

```text
listings.user_id
listings.category_id
listings.city_id
listings.area_id
listings.status
listings.price
listings.published_at
favorites.user_id
messages.conversation_id
```

Avoid premature over-indexing.

---

# 37. Queue Jobs

Use jobs for expensive/non-blocking tasks.

Examples:

```text
ProcessListingImages
GenerateImageVariants
IndexListingInSearch
RemoveListingFromSearch
SendVerificationEmail
SendNotification
ExpireListings
GenerateSitemap
```

HTTP requests should not wait for long-running work unnecessarily.

---

# 38. Logging

Log important application events.

Examples:

```text
Admin blocked user
Admin rejected listing
Listing reported
Suspicious login
Repeated phone reveal abuse
Payment event
Search indexing failure
Image processing failure
```

Do not log passwords, tokens, or sensitive secrets.

---

# 39. Environment Configuration

Use `.env`.

Expected services may include:

```text
APP_URL
DB_*
REDIS_*
MEILISEARCH_*
R2/S3_*
MAIL_*
SENTRY_*
```

Create `.env.example`.

Never commit real credentials.

---

# 40. Git Workflow

Use Git from the beginning.

Recommended branches:

```text
main
develop
feature/*
fix/*
```

Commits should be small and meaningful.

Examples:

```text
feat: add listing category structure
feat: implement listing image uploads
fix: prevent users editing other listings
refactor: move listing publish logic to action
```

---

# 41. Initial Codex Tasks

Codex should execute the project in phases.

## Phase 1 — Foundation

- Create Laravel project
- Configure PostgreSQL
- Configure Redis
- Configure Vue
- Configure TypeScript
- Configure Inertia
- Configure Tailwind
- Configure authentication
- Add base project structure
- Configure linting/formatting
- Add Docker development environment if useful
- Create `.env.example`

## Phase 2 — Marketplace Core

- Users
- Categories
- Subcategories
- Category attributes
- Locations
- Listings
- Listing attributes
- Listing statuses

## Phase 3 — Listing Management

- Create ad flow
- Edit ad
- Delete ad
- Image upload
- Image processing
- Listing preview
- Listing details

## Phase 4 — Discovery

- Meilisearch
- Scout
- Search page
- Filters
- Sort
- Favorites

## Phase 5 — Messaging

- Conversations
- Messages
- User blocking

## Phase 6 — Moderation

- Reports
- Admin panel
- Listing review
- User suspension
- Prohibited keyword system

## Phase 7 — SEO / Performance

- SSR
- Meta tags
- Sitemap
- Schema
- Caching
- CDN
- Image optimization
- Performance profiling

## Phase 8 — Production

- CI/CD
- Production Docker configuration if chosen
- Cloudflare
- R2/S3
- Queue workers
- Horizon
- Monitoring
- Backups
- Security hardening

---

# 42. Codex Working Instructions

When working on this project, follow these rules:

1. Inspect the existing repository before making architectural decisions.
2. Do not delete existing working functionality unless necessary.
3. Prefer framework conventions over custom abstractions.
4. Do not introduce a package without explaining why it is needed.
5. Avoid outdated Laravel packages.
6. Keep controllers thin.
7. Use Form Requests for validation.
8. Use Policies for authorization.
9. Use Jobs for expensive background processing.
10. Use Services or Actions for reusable business logic.
11. Use database transactions for multi-step critical writes.
12. Add indexes where query patterns justify them.
13. Prevent N+1 queries.
14. Validate uploads server-side.
15. Never expose secrets.
16. Never trust user-supplied ownership IDs.
17. Maintain responsive design.
18. Keep public pages SEO-friendly.
19. Optimize images automatically.
20. Keep code easy for another experienced Laravel developer to understand.
21. Do not overengineer.
22. Before major refactors, explain the reason.
23. After each significant phase, run the relevant tests.
24. Fix existing test failures caused by the current change before moving forward.
25. Keep README documentation updated as infrastructure requirements change.

---

# 43. UI Direction

The UI should feel:

- Modern
- Clean
- Friendly
- Trustworthy
- Lightweight
- Fast
- Mobile-first

Avoid copying OLX branding.

Suggested general direction:

```text
White / neutral backgrounds
Strong readable typography
Rounded but not overly playful cards
Clear price emphasis
Large product photography
Simple category icons
Prominent search
Prominent Sell button
Minimal visual clutter
```

Brand wording:

```text
2nd Hand
Good Things Deserve a Second Home.
```

---

# 44. Success Criteria

The first production-ready version is successful when:

- Users can register securely
- Users can publish used-item listings
- Images are optimized automatically
- Buyers can search/filter quickly
- Buyers can contact sellers
- Users can favorite items
- Users can report abuse
- Admins can moderate the platform
- Public pages are indexable by Google
- Site performs well on mobile
- Architecture can support future expansion

---

# 45. Final Development Principle

Build **Second Hand** as a focused marketplace first.

Do not try to reproduce every OLX feature on day one.

The priorities are:

```text
Fast
Secure
Easy to use
SEO friendly
Mobile friendly
Scalable
Maintainable
```

Launch the marketplace with strong core functionality, observe real user behavior, then expand based on actual usage.
