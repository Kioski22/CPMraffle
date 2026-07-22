# CPMraffle Recent Customizations Implementation Plan

This file documents the recent changes added to this codebase so the same feature set can be implemented in another system that started from the same base.

## Goal

Separate this codebase from the original copied system by documenting the custom features that were recently introduced for:

- payment remarks tracking
- buyer and chapter remarks management
- winner eligibility filtering
- winner draw dashboard
- chapter management UI redesign
- visual payment status standardization

## Summary Of What Changed

The recent work introduced six main customization areas:

1. Added a `remarks` column to `buyers`
2. Added a `remarks` column to `users` for chapter accounts
3. Added buyer and chapter UI flows to edit remarks
4. Replaced free-text remarks editing with controlled dropdown values
5. Added a new `pick_a_winner.php` dashboard with eligibility logic
6. Redesigned `chapter_management.php` so it behaves like a wider, cleaner admin dashboard

## Files Changed

These are the primary files that now differ from the original system:

- `buyer_schema.php`
- `buyer_list.php`
- `chapter_management.php`
- `pick_a_winner.php`
- `cpmrafflev2_event_reset.sql`
- `add_remarks_columns.php`
- `tools/add_remarks_columns.php`

## 1. Database Schema Changes

### 1.1 Buyers Table

Add a nullable remarks column:

```sql
ALTER TABLE buyers ADD COLUMN remarks VARCHAR(255) DEFAULT NULL;
```

Purpose:

- store payment-related remarks for buyer-owned tickets
- support winner eligibility filtering
- support buyer dashboard editing

### 1.2 Users Table

Add a nullable remarks column:

```sql
ALTER TABLE users ADD COLUMN remarks VARCHAR(255) DEFAULT NULL;
```

Purpose:

- store payment-related remarks for chapter accounts
- support winner eligibility when a ticket is chapter-owned
- support chapter management editing

### 1.3 Schema Bootstrap Changes

Update `ensureBuyerSchema()` in `buyer_schema.php` so both columns are auto-created when missing.

Required additions:

- add `remarks VARCHAR(255) NULL` to buyer auto-added columns
- add `remarks VARCHAR(255) NULL` to users auto-added columns

### 1.4 Reset SQL Changes

If the other system has an event reset SQL file, add `remarks` to:

- `users`
- `buyers`

This keeps fresh resets aligned with production schema.

## 2. Standard Remarks Values

Do not allow free-text remarks anymore.

Use these fixed values:

- `Paid`
- `Fully Paid`
- `Partially Paid`
- `Pending`
- `Not Paid`

Optional blank/default value:

- empty string mapped to `Select Remarks` in forms

Reason:

- avoids typographical errors
- makes eligibility logic deterministic
- keeps table coloring consistent

## 3. Buyer List Changes

Target file:

- `buyer_list.php`

### 3.1 Backend Changes

Add a new POST action:

- `update_remarks`

Behavior:

- accepts `buyer_id`
- accepts `remarks`
- validates `remarks` against the allowed dropdown values
- updates `buyers.remarks`

Validation rule:

- reject any value not in the controlled list

### 3.2 Data Query Changes

Update the buyer listing query so it selects:

- `b.remarks`

### 3.3 Table Changes

Add a new Remarks column to the buyer list table.

Display the remarks as color pills:

1. Green for `Paid` and `Fully Paid`
2. Yellow for `Partially Paid`
3. Red for `Pending` and `Not Paid`
4. Empty values render visually as `Pending`

### 3.4 Modal Changes

Add an `Edit Remarks` button per row.

Open a modal containing:

- hidden `action=update_remarks`
- hidden `buyer_id`
- remarks dropdown using the fixed options above
- submit button

### 3.5 Permissions

Restrict remark editing to admins using the same permission gate already used for buyer management.

## 4. Chapter Management Changes

Target file:

- `chapter_management.php`

### 4.1 New Dual-Purpose POST Handling

This page now handles two actions:

1. `create_chapter`
2. `update_remarks`

For `update_remarks`:

- accept `chapter_id`
- accept `remarks`
- validate against the fixed list
- update `users.remarks` only where `role='chapter'`

### 4.2 New Chapter Listing Query

Add a chapter list query with:

- `u.id`
- chapter display name via `COALESCE(u.chapter_name, u.name, u.username)`
- `u.username`
- `u.email`
- `u.remarks`
- total allocated tickets
- total activated tickets

This query powers the lower dashboard section.

### 4.3 Page Redesign

The page was redesigned because the original layout was too compressed.

Key layout changes:

1. Increase shell width from roughly `920px` to about `1380px`
2. Add a hero section with summary metrics
3. Split upper content into two columns:
- create chapter form
- workflow/help card
4. Move chapter remarks management into a dedicated wide table card

### 4.4 New Metrics In Hero

Add and compute:

- total chapters
- total allocated tickets
- total activated tickets

### 4.5 Chapter Remarks Table

Add a full-width chapter remarks table with columns:

- Chapter
- Email
- Remarks
- Activation Progress
- Actions

Display remarks using the same pill colors as the buyer list.

### 4.6 Edit Remarks Modal

Each row should have an `Edit Remarks` button that opens a modal with:

- hidden `action=update_remarks`
- hidden `chapter_id`
- controlled remarks dropdown
- submit button

## 5. Winner Picker Dashboard

Target file:

- `pick_a_winner.php`

This file is new and does not exist in the original base system.

### 5.1 Purpose

Provide an admin-only winner validation dashboard where a ticket number can be entered to determine whether the ticket is eligible to win.

### 5.2 Access Control

Restrict access to:

- admin
- secretariat
- cpmadmin

### 5.3 Ticket Lookup Logic

The dashboard should perform the following checks in order:

1. Confirm the ticket exists in `tickets` or `generated_tickets`
2. If it exists only in `generated_tickets` and not in `tickets`, treat it as not activated
3. Load detailed ticket ownership data from `tickets`
4. Resolve ticket owner using this priority:
   - buyer ownership from `ticket_assignments -> buyers`
   - otherwise chapter ownership from `tickets.assigned_chapter_id -> users`
5. Confirm activation using either:
   - `tickets.status = 'activated'`
   - or a `ticket_activations` record
6. Evaluate payment eligibility from owner remarks

### 5.4 Strict Eligibility Rule

Do not use a substring search like `contains paid`.

Use strict matching only:

- eligible: `Paid`
- eligible: `Fully Paid`
- not eligible: `Partially Paid`
- not eligible: `Pending`
- not eligible: `Not Paid`

Implementation approach:

- normalize remarks to lowercase and trimmed
- treat only `paid` and `fully paid` as valid winning states

### 5.5 Winner Dashboard UI

The page was intentionally styled to feel separate from the original system.

Visual direction:

- large headline-driven layout
- gradient background
- glassmorphism panels
- animated reveal state
- strong ticket-input focus

Main UI sections:

- hero section
- ticket number input
- result panel
- owner/ticket detail chips

### 5.6 Result States

The page should clearly show these states:

- idle
- winner
- error

Typical error reasons:

- ticket not found
- not activated
- no owner
- not paid

## 6. Temporary Migration Helpers

Two migration helpers were created.

### 6.1 Root Web Migration File

File:

- `add_remarks_columns.php`

Purpose:

- run schema updates in production through browser access
- protected by role-based auth

Behavior:

- tries to add `buyers.remarks`
- tries to add `users.remarks`
- renders a status table

Important:

- this file must catch `mysqli_sql_exception`
- duplicate column errors must be treated as `exists`, not fatal

### 6.2 CLI/Tool Migration File

File:

- `tools/add_remarks_columns.php`

Purpose:

- local or maintenance execution path

Behavior:

- same alter logic as root file
- also catches duplicate-column exceptions

### 6.3 Production Hygiene

After successful execution in production:

- remove or disable `add_remarks_columns.php`

This reduces unnecessary exposed admin-only schema tooling.

## 7. Visual Status Rules

To keep buyer and chapter pages consistent, use the same remarks color mapping everywhere.

### Remarks Color Standard

1. `Paid` and `Fully Paid` -> green
2. `Partially Paid` -> yellow
3. `Pending` and `Not Paid` -> red

Apply this to:

- buyer list remarks column
- chapter management remarks column

## 8. Validation Checklist

When recreating the changes in another system, verify the following:

### Database

1. `buyers.remarks` exists
2. `users.remarks` exists
3. reset SQL includes both columns if applicable

### Buyer List

1. buyer table shows a remarks column
2. buyer remarks can be updated from a dropdown
3. invalid custom values are rejected server-side
4. remarks display as color pills

### Chapter Management

1. page is no longer width-compressed
2. hero metrics display correctly
3. chapter remarks table loads all chapters
4. remarks edit modal updates values correctly
5. remarks display as color pills

### Winner Picker

1. not-found tickets fail cleanly
2. generated-only tickets fail as not activated
3. activated unpaid tickets fail
4. activated buyer-owned paid tickets pass
5. activated chapter-owned paid tickets pass
6. `Not Paid` does not pass by accident

## 9. Suggested Implementation Order In Another System

Use this order to minimize breakage:

1. Add schema columns and migration helpers
2. Update schema bootstrap logic
3. Update buyer list remarks editing and display
4. Update chapter management remarks editing and display
5. Redesign chapter management layout
6. Add winner picker page
7. Run final validation with test data

## 10. Notes For Porting

If the target system has drifted from this codebase, pay attention to:

- different auth role names
- different ticket activation rules
- different buyer/chapter table names
- different sidebar/navigation helpers
- different table aliases in existing SQL

The custom behavior itself should remain the same even if implementation details differ.

## 11. Optional Follow-Up Improvements

If you want this separation from the original system to go further, consider these next:

1. Add a sidebar link to `pick_a_winner.php`
2. Add filtering by remarks on buyer and chapter pages
3. Normalize any legacy free-text remarks to the new controlled statuses
4. Add audit logging for remarks updates
5. Add a reusable helper function for rendering remarks pill styles across pages
