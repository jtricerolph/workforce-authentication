# Workforce Authentication & Permissions Plugin - Complete Plan

## Overview
Create a standalone WordPress plugin that syncs Workforce employees and provides secure authentication using live API validation during first-time registration. **Zero storage of sensitive data (DOB/PIN).**

Includes comprehensive permissions system with:
- Workforce employee sync with live DOB+PIN validation (no sensitive data storage)
- Comprehensive permissions system (per-page, per-module, per-action)
- Hybrid permission model (department-based + role groups + user overrides)
- Optional GM approval workflow with email click-to-approve links

---

## Phase 1: Git Repository & Plugin Foundation

### 1.1 Initialize New Git Repository
```bash
# Create new directory
mkdir workforce-authentication
cd workforce-authentication

# Initialize git
git init

# Create .gitignore
# (Exclude node_modules, vendor, .env, IDE files)

# Initial commit
git add .
git commit -m "Initial commit: Workforce Authentication plugin structure"
```

### 1.2 Plugin Structure
```
workforce-authentication/
├── workforce-authentication.php       (main plugin file)
├── README.md                          (documentation)
├── .gitignore
├── includes/
│   ├── class-wfa-activator.php       (activation/deactivation)
│   ├── class-wfa-api.php             (Workforce API client)
│   ├── class-wfa-sync.php            (employee & department sync)
│   ├── class-wfa-auth.php            (authentication logic)
│   ├── class-wfa-permissions.php     (permissions engine)
│   ├── class-wfa-approval.php        (GM approval workflow)
│   └── class-wfa-admin.php           (admin pages)
├── admin/
│   ├── settings.php                  (API & general settings)
│   ├── users.php                     (synced employees list)
│   ├── permissions.php               (permissions management)
│   ├── departments.php               (department permissions)
│   ├── permission-groups.php         (role groups)
│   ├── pending-approvals.php         (pending users)
│   └── activity-log.php              (auth & access logs)
├── assets/
│   ├── js/
│   │   ├── login-flow.js             (dynamic login form)
│   │   └── permissions-admin.js      (drag-drop permissions UI)
│   └── css/
│       ├── login.css
│       └── admin.css
└── templates/
    └── emails/
        └── approval-request.php      (GM approval email)
```

---

## Phase 2: Database Schema

### 2.1 Core Tables

**`wp_workforce_users`** - User mapping (minimal data)
```sql
CREATE TABLE wp_workforce_users (
  id bigint AUTO_INCREMENT PRIMARY KEY,
  wordpress_user_id bigint NOT NULL UNIQUE,
  workforce_user_id varchar(100) NOT NULL UNIQUE,
  workforce_email varchar(255) NOT NULL,
  is_active tinyint(1) DEFAULT 1,
  first_login_completed tinyint(1) DEFAULT 0,
  approval_status varchar(20) DEFAULT 'pending', -- 'pending', 'approved', 'rejected'
  approved_by bigint NULL,
  approved_at datetime NULL,
  last_synced_at datetime,
  created_at datetime DEFAULT CURRENT_TIMESTAMP,

  KEY wordpress_user_id (wordpress_user_id),
  KEY workforce_user_id (workforce_user_id),
  KEY approval_status (approval_status)
);
```

**`wp_workforce_departments`** - Workforce departments
```sql
CREATE TABLE wp_workforce_departments (
  id bigint AUTO_INCREMENT PRIMARY KEY,
  workforce_department_id varchar(100) NOT NULL UNIQUE,
  name varchar(255) NOT NULL,
  is_active tinyint(1) DEFAULT 1,
  last_synced_at datetime,
  created_at datetime DEFAULT CURRENT_TIMESTAMP,

  KEY workforce_department_id (workforce_department_id)
);
```

**`wp_workforce_user_departments`** - Many-to-many mapping
```sql
CREATE TABLE wp_workforce_user_departments (
  id bigint AUTO_INCREMENT PRIMARY KEY,
  wordpress_user_id bigint NOT NULL,
  department_id bigint NOT NULL,
  created_at datetime DEFAULT CURRENT_TIMESTAMP,

  KEY wordpress_user_id (wordpress_user_id),
  KEY department_id (department_id),
  UNIQUE KEY user_dept (wordpress_user_id, department_id)
);
```

**`wp_workforce_permission_groups`** - Custom role groups
```sql
CREATE TABLE wp_workforce_permission_groups (
  id bigint AUTO_INCREMENT PRIMARY KEY,
  name varchar(100) NOT NULL,
  description text,
  is_active tinyint(1) DEFAULT 1,
  created_at datetime DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY name (name)
);
```

**`wp_workforce_permissions`** - Granular permissions
```sql
CREATE TABLE wp_workforce_permissions (
  id bigint AUTO_INCREMENT PRIMARY KEY,
  permission_key varchar(100) NOT NULL,      -- e.g., 'cashup.view', 'bookings.edit'
  permission_type varchar(20) NOT NULL,      -- 'page', 'module', 'action'
  resource_name varchar(255) NOT NULL,       -- human-readable name
  resource_identifier varchar(255),          -- page slug, module ID, etc.
  parent_permission_id bigint NULL,          -- for hierarchical permissions
  created_at datetime DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY permission_key (permission_key),
  KEY permission_type (permission_type)
);
```

**`wp_workforce_group_permissions`** - Group → Permissions
```sql
CREATE TABLE wp_workforce_group_permissions (
  id bigint AUTO_INCREMENT PRIMARY KEY,
  group_id bigint NOT NULL,
  permission_id bigint NOT NULL,
  granted tinyint(1) DEFAULT 1,              -- 1=allow, 0=deny
  created_at datetime DEFAULT CURRENT_TIMESTAMP,

  KEY group_id (group_id),
  KEY permission_id (permission_id),
  UNIQUE KEY group_perm (group_id, permission_id)
);
```

**`wp_workforce_department_permissions`** - Department → Permissions
```sql
CREATE TABLE wp_workforce_department_permissions (
  id bigint AUTO_INCREMENT PRIMARY KEY,
  department_id bigint NOT NULL,
  permission_id bigint NOT NULL,
  granted tinyint(1) DEFAULT 1,
  created_at datetime DEFAULT CURRENT_TIMESTAMP,

  KEY department_id (department_id),
  KEY permission_id (permission_id),
  UNIQUE KEY dept_perm (department_id, permission_id)
);
```

**`wp_workforce_user_permissions`** - User overrides
```sql
CREATE TABLE wp_workforce_user_permissions (
  id bigint AUTO_INCREMENT PRIMARY KEY,
  wordpress_user_id bigint NOT NULL,
  permission_id bigint NOT NULL,
  granted tinyint(1) DEFAULT 1,
  override_reason varchar(255),              -- why override was needed
  created_by bigint,                         -- admin who granted
  created_at datetime DEFAULT CURRENT_TIMESTAMP,

  KEY wordpress_user_id (wordpress_user_id),
  KEY permission_id (permission_id),
  UNIQUE KEY user_perm (wordpress_user_id, permission_id)
);
```

**`wp_workforce_user_groups`** - User → Groups
```sql
CREATE TABLE wp_workforce_user_groups (
  id bigint AUTO_INCREMENT PRIMARY KEY,
  wordpress_user_id bigint NOT NULL,
  group_id bigint NOT NULL,
  created_at datetime DEFAULT CURRENT_TIMESTAMP,

  KEY wordpress_user_id (wordpress_user_id),
  KEY group_id (group_id),
  UNIQUE KEY user_group (wordpress_user_id, group_id)
);
```

**`wp_workforce_auth_log`** - Audit trail
```sql
CREATE TABLE wp_workforce_auth_log (
  id bigint AUTO_INCREMENT PRIMARY KEY,
  wordpress_user_id bigint NULL,
  email varchar(255),
  action varchar(50),                        -- 'registration', 'login', 'access_denied'
  resource varchar(255) NULL,                -- page/module accessed
  result varchar(50),                        -- 'success', 'fail', 'denied'
  ip_address varchar(45),
  user_agent varchar(255),
  created_at datetime DEFAULT CURRENT_TIMESTAMP,

  KEY wordpress_user_id (wordpress_user_id),
  KEY email (email),
  KEY action (action),
  KEY created_at (created_at)
);
```

---

## Phase 3: Workforce API & Sync

### 3.1 API Client (`class-wfa-api.php`)

**API Methods:**
```php
// User data
get_users($include_inactive = false)
validate_employee($email, $dob, $pin)      // Live validation - no storage

// Department data
get_departments()
get_user_departments($workforce_user_id)

// Data returned (minimal):
// Users: id, email, active, name
// Departments: id, name, active
```

**Workforce API Details:**
- API Documentation: https://my.workforce.com/api/v2/documentation
- Authentication: OAuth2 bearer token or password authentication
- Rate Limiting: 200 requests per minute
- Base URL: `https://my.workforce.com/api/v2/`
- Key Endpoints:
  - `/api/v2/users/me` - Current user info
  - `/api/v2/users` - List all users
  - `/api/v2/departments` - List all departments

### 3.2 Sync System (`class-wfa-sync.php`)

**Employee Sync:**
1. Fetch all active employees from Workforce
2. Fetch all departments from Workforce
3. For each employee:
   - Create/update WordPress user
   - Map to Workforce ID
   - Set approval_status based on settings
   - Sync department memberships
4. Update active status
5. Log sync results

**Department Sync:**
- Pull all departments from Workforce API
- Create records in `wp_workforce_departments`
- Update department names/status
- Maintain department → user relationships

**Auto-Disable Logic:**
- If Workforce `active=false`:
  - Set `is_active=0`
  - Destroy sessions
  - Block all access
- If reactivated: Re-enable

---

## Phase 4: Authentication System

### 4.1 First-Time Registration Flow

**Step 1: Email Entry**
```
User enters email
→ AJAX: Check if Workforce employee
→ If yes: Check first_login_completed and approval_status
```

**Step 2: Approval Check**
```
If approval_status = 'pending':
  → Show: "Registration pending approval. You'll receive an email when approved."
  → End

If approval_status = 'rejected':
  → Show: "Access denied. Contact administrator."
  → End

If approval_status = 'approved' && first_login_completed = false:
  → Continue to Step 3
```

**Step 3: DOB + PIN Validation (Live)**
```
Show DOB + PIN fields
User enters DOB + PIN
→ LIVE API call: validate_employee(email, dob, pin)
→ If valid:
  - Show "Create Password" form
  - User sets WordPress password
  - Set first_login_completed = true
  - Log user in
→ If invalid:
  - Show error
  - Rate limit: 3 attempts/hour
```

**Step 4: Returning Users**
```
If first_login_completed = true:
  → Standard WordPress password login
```

### 4.2 Rate Limiting
- Max 3 DOB+PIN attempts per hour per email
- Stored in transients: `wfa_attempts_{email_hash}`
- Lockout message with time remaining

### 4.3 Authentication Filter
```php
add_filter('authenticate', 'wfa_authenticate', 30, 3);

function wfa_authenticate($user, $email, $password) {
  // Check Workforce-linked user
  // Verify is_active = 1
  // Verify approval_status = 'approved'
  // Check permissions if accessing protected resource
  // Log attempt
}
```

---

## Phase 5: Permissions System

### 5.1 Permission Registration

**Register Permissions from Other Plugins:**
```php
// In booking-match-api, hotel-cashup-reconciliation, etc.
do_action('wfa_register_permissions', [
  [
    'key' => 'cashup.view',
    'type' => 'page',
    'name' => 'View Cash Up',
    'resource' => 'cash-up-reconciliation'
  ],
  [
    'key' => 'cashup.create',
    'type' => 'action',
    'name' => 'Create Cash Up',
    'resource' => 'cash-up-reconciliation'
  ],
  [
    'key' => 'cashup.submit',
    'type' => 'action',
    'name' => 'Submit Cash Up',
    'resource' => 'cash-up-reconciliation'
  ],
  [
    'key' => 'bookings.view',
    'type' => 'page',
    'name' => 'View Bookings',
    'resource' => 'bookings-page'
  ],
  [
    'key' => 'nawa.module.restaurant',
    'type' => 'module',
    'name' => 'Restaurant Status Module',
    'resource' => 'newbook-assistant-webapp'
  ],
  [
    'key' => 'nawa.module.checks',
    'type' => 'module',
    'name' => 'Checks Module',
    'resource' => 'newbook-assistant-webapp'
  ]
]);
```

**Permission Hierarchy:**
```
Page permissions (top level)
  └─ Module permissions (within page)
      └─ Action permissions (within module)

Example:
cashup (page)
  ├─ cashup.view (action)
  ├─ cashup.create (action)
  ├─ cashup.edit (action)
  └─ cashup.submit (action)
```

### 5.2 Permission Resolution Logic

**Check Order (Hybrid Model):**
```php
function user_can($user_id, $permission_key) {
  // 1. User-specific override (highest priority)
  $user_override = check_user_permission($user_id, $permission_key);
  if ($user_override !== null) {
    return $user_override; // true or false
  }

  // 2. Permission groups (assigned to user)
  $group_permission = check_group_permissions($user_id, $permission_key);
  if ($group_permission !== null) {
    return $group_permission;
  }

  // 3. Department permissions (from Workforce)
  $dept_permission = check_department_permissions($user_id, $permission_key);
  if ($dept_permission !== null) {
    return $dept_permission;
  }

  // 4. WordPress role capabilities (fallback)
  if (user_can($user_id, 'manage_options')) {
    return true; // Admins get all
  }

  // 5. Default: deny
  return false;
}
```

**Multiple Departments:**
- If user in multiple departments
- Check all department permissions
- If ANY department grants access → Allow
- If ALL departments deny or no grants → Deny

### 5.3 Permission Checks in Code

**Page-level check:**
```php
// In plugin code
if (!wfa_user_can('cashup.view')) {
  wp_die('Access denied');
}
```

**Module-level check:**
```php
// In newbook-assistant-webapp
if (!wfa_user_can('nawa.module.restaurant')) {
  // Hide module from menu
}
```

**Action-level check:**
```php
// In AJAX handler
if (!wfa_user_can('cashup.submit')) {
  wp_send_json_error('No permission');
}
```

### 5.4 Shortcode/Template Tag
```php
// In templates
<?php if (wfa_user_can('bookings.edit')): ?>
  <button>Edit Booking</button>
<?php endif; ?>

// Shortcode
[wfa_permission key="cashup.view"]
  Content only visible to authorized users
[/wfa_permission]
```

---

## Phase 6: Approval Workflow

### 6.1 Settings

**Admin Settings Page:**
```
New User Approval: [Require Approval ▼] or [Auto-Approve ▼]

If Require Approval:
  General Manager Email: [gm@hotel.com]
  Approval Link Expiry: [7 days ▼]
  Email Template: [Customize...]
```

### 6.2 Approval Flow

**When New User Synced:**
```
1. Create WordPress user
2. Set approval_status = 'pending' (if require approval)
   OR approval_status = 'approved' (if auto-approve)
3. If require approval:
   → Send email to GM
   → Email contains:
     - Employee name, email, departments
     - One-click approve link
     - One-click reject link
     - Link to admin page for more details
```

**Email Template:**
```
Subject: New Staff Registration - [Employee Name]

A new staff member has been synced from Workforce and is requesting access:

Name: John Smith
Email: john.smith@hotel.com
Workforce ID: 12345
Departments: Front Desk, Reception

Click to approve: https://site.com/wp-admin/admin.php?wfa_approve=[token]
Click to reject: https://site.com/wp-admin/admin.php?wfa_reject=[token]

Or manage in admin panel: [link to pending approvals page]

Token expires in 7 days.
```

### 6.3 One-Click Approval Links

**Token Generation:**
```php
// Create secure token
$token = wp_hash($user_id . $email . time() . wp_salt());
set_transient('wfa_approval_' . $token, [
  'user_id' => $user_id,
  'action' => 'approve', // or 'reject'
  'expires' => time() + (7 * DAY_IN_SECONDS)
], 7 * DAY_IN_SECONDS);

// Generate links
$approve_link = admin_url('admin.php?wfa_approve=' . $token);
$reject_link = admin_url('admin.php?wfa_reject=' . $token);
```

**Link Handler:**
```php
add_action('admin_init', 'wfa_handle_approval_links');

function wfa_handle_approval_links() {
  if (isset($_GET['wfa_approve'])) {
    $token = sanitize_text_field($_GET['wfa_approve']);
    $data = get_transient('wfa_approval_' . $token);

    if ($data) {
      // Update approval_status = 'approved'
      // Set approved_by = current_user_id
      // Set approved_at = now
      // Delete transient
      // Send welcome email to employee
      // Redirect with success message
    }
  }
}
```

### 6.4 Pending Approvals Admin Page

**Admin Interface:**
- Table of pending users
- Columns: Name, Email, Departments, Synced Date
- Actions: Approve, Reject, View Details
- Bulk actions: Approve selected, Reject selected
- Filter by department

---

## Phase 7: Admin Interface

### 7.1 Settings Page (`admin/settings.php`)

**Tabs:**
1. **API Settings**
   - Workforce API token
   - Test connection button

2. **Sync Settings**
   - Sync frequency (hourly/daily/manual)
   - Auto-disable inactive employees
   - Manual sync button
   - Last sync: [timestamp]

3. **Approval Settings**
   - Require approval / Auto-approve
   - GM email address
   - Email template customization
   - Approval link expiry

4. **Permission Settings**
   - Default permission group for new users
   - WordPress role integration
   - Permission inheritance rules

### 7.2 Users Management (`admin/users.php`)

**User List Table:**
- Columns: Name, Email, Workforce ID, Departments, Groups, Active, Approval Status
- Filters: By department, by group, by status
- Actions: Edit Permissions, Resync, Force Re-registration
- Bulk actions: Assign to Group, Change Status

**Edit User Permissions Modal:**
- User details
- Current groups
- Current departments
- Permission overrides (checkboxes)
- Add override reason
- Save

### 7.3 Permissions Management (`admin/permissions.php`)

**Registered Permissions List:**
- All permissions registered by plugins
- Organized by type (Page, Module, Action)
- Grouped by resource
- Show which groups/departments have each permission

### 7.4 Departments Page (`admin/departments.php`)

**Features:**
- "Sync Departments" button (pull from Workforce)
- List of departments from Workforce
- For each department:
  - Assign bulk permissions (checkboxes)
  - View members count
  - View members list
- Drag-and-drop permission assignment

### 7.5 Permission Groups (`admin/permission-groups.php`)

**Create/Edit Groups:**
- Group name, description
- Assign permissions (checkboxes, organized by resource)
- Assign users to group
- Clone group feature
- Pre-defined templates:
  - "Front Desk Staff"
  - "Manager"
  - "Accountant"
  - "Housekeeping"

### 7.6 Pending Approvals (`admin/pending-approvals.php`)

**Approval Queue:**
- List of pending users
- One-click approve/reject
- Bulk approve/reject
- Email notification option
- Notes field (why approved/rejected)

### 7.7 Activity Log (`admin/activity-log.php`)

**Audit Trail:**
- All auth attempts
- All permission checks (optional, can be verbose)
- Filters: User, action, result, date range
- Export to CSV
- Retention settings

---

## Phase 8: Integration with Existing Plugins

### 8.1 Helper Functions (Public API)

**For other plugins to use:**
```php
// Check permission
wfa_user_can($permission_key, $user_id = null)

// Get Workforce ID
wfa_get_workforce_id($user_id = null)

// Check if active
wfa_is_active($user_id = null)

// Get user departments
wfa_get_user_departments($user_id = null)

// Register permissions
wfa_register_permission($key, $type, $name, $resource)
wfa_register_permissions($permissions_array)

// Log activity
wfa_log_activity($action, $resource = null, $result = 'success')
```

### 8.2 WordPress Integration Recommendation

**Extend WordPress Roles (Not Replace):**
- Keep WordPress native roles (Administrator, Editor, Subscriber, etc.)
- Workforce permissions ADD granular control
- Admins (manage_options capability) bypass all checks
- For others: Check Workforce permissions first
- Fallback to WordPress capabilities if no Workforce permission defined

**Benefits:**
- Maintains compatibility with other plugins
- Admins always have access
- Gradual migration (can use both systems)
- Non-Workforce users still work

### 8.3 Hooks for Other Plugins

**Action Hooks:**
```php
do_action('wfa_user_logged_in', $user_id, $workforce_id);
do_action('wfa_user_synced', $user_id, $workforce_data);
do_action('wfa_user_activated', $user_id);
do_action('wfa_user_deactivated', $user_id);
do_action('wfa_user_approved', $user_id, $approved_by);
do_action('wfa_user_rejected', $user_id, $rejected_by);
do_action('wfa_permission_denied', $user_id, $permission_key, $resource);
```

**Filter Hooks:**
```php
apply_filters('wfa_user_can', $can, $user_id, $permission_key);
apply_filters('wfa_approval_required', $required, $user_data);
apply_filters('wfa_default_permissions', $permissions, $user_id);
apply_filters('wfa_approval_email_content', $content, $user_data);
```

### 8.4 Update Existing Plugins

**booking-match-api:**
- Register permissions for all REST endpoints
- Check permissions in permission_callback
- Store workforce_user_id in activity logs

**hotel-cashup-reconciliation:**
- Register page, module, action permissions
- Check before rendering admin pages
- Check before AJAX handlers
- Add workforce_user_id to cash_ups table

**newbook-assistant-webapp:**
- Register module permissions
- Filter available modules by permissions
- Check permissions before rendering modules
- Update currentUser object with permissions

---

## Phase 9: Implementation Timeline

### Week 1: Foundation
- [ ] Initialize Git repository
- [ ] Create plugin structure
- [ ] Setup database schema
- [ ] Build Workforce API client
- [ ] Create admin settings page
- [ ] Test API connectivity

### Week 2: Sync & Authentication
- [ ] Implement employee sync
- [ ] Implement department sync
- [ ] Build custom login flow
- [ ] Implement DOB+PIN live validation
- [ ] Add rate limiting
- [ ] Test full auth flow

### Week 3: Permissions System
- [ ] Build permissions engine
- [ ] Create permission registration system
- [ ] Implement permission check logic
- [ ] Build admin permission pages
- [ ] Test permission resolution

### Week 4: Approval & Integration
- [ ] Implement approval workflow
- [ ] Create email templates
- [ ] Build one-click approval links
- [ ] Create helper functions for other plugins
- [ ] Build activity log viewer
- [ ] Documentation

### Week 5: Existing Plugin Integration
- [ ] Update booking-match-api
- [ ] Update hotel-cashup-reconciliation
- [ ] Update newbook-assistant-webapp
- [ ] Test all integrations
- [ ] Final testing & bug fixes

---

## Security Checklist

✅ **No Sensitive Data Storage**
- DOB/PIN validated live, never stored
- Only essential mapping data persisted

✅ **Rate Limiting**
- 3 attempts/hour for DOB+PIN validation
- Workforce API: 200 req/min (built-in)

✅ **Secure Tokens**
- Approval links use wp_hash() + transients
- 7-day expiry
- One-time use

✅ **Audit Logging**
- All auth attempts logged
- All permission denials logged
- IP address + user agent tracked

✅ **Session Management**
- Force logout on deactivation
- Destroy all sessions on status change

✅ **Input Validation**
- Sanitize all inputs
- Validate all API responses
- Nonce verification on AJAX

✅ **WordPress Security**
- Extends WordPress auth (doesn't bypass)
- Respects WordPress admin privileges
- Uses WordPress encryption/hashing functions

---

## Git Workflow

**Branches:**
```
main              (production-ready)
develop           (integration branch)
feature/*         (feature branches)
hotfix/*          (urgent fixes)
```

**Initial Commits:**
```
1. Initial commit: Plugin structure
2. Database schema & activator
3. Workforce API client
4. Employee sync system
5. Authentication flow
6. Permissions engine
7. Approval workflow
8. Admin interface
9. Integration helpers
10. Documentation
```

---

## Configuration Examples

**Plugin Settings (after install):**
```
Workforce API Token: [your-token-here]
Sync Frequency: Hourly
Auto-Disable Inactive: Yes
Approval Required: Yes
GM Email: manager@hotel.com
Default Permission Group: Front Desk Staff
WordPress Role Integration: Extend (recommended)
```

**Example Permission Group: "Front Desk Staff"**
```
✓ nawa.module.restaurant (View Restaurant Status)
✓ nawa.module.checks (View Checks)
✓ nawa.module.bookings (View Bookings)
✓ bookings.view (View Booking Details)
✗ cashup.view (No cash access)
✗ cashup.create (No cash access)
```

**Example Department Permissions: "Reception"**
```
✓ bookings.view
✓ bookings.create
✓ bookings.edit
✓ nawa.module.restaurant
✓ nawa.module.checks
✓ nawa.module.bookings
```

---

## Next Steps

1. Review this plan and make any necessary adjustments
2. Begin Phase 1: Foundation implementation
3. Set up development environment with test Workforce API credentials
4. Create initial plugin files and structure
5. Implement and test each phase incrementally

This plan provides a complete, secure, and flexible authentication and permissions system that integrates seamlessly with your existing plugins while pulling employee data from Workforce.
