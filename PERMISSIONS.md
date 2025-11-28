# Workforce Authentication - Permissions System

The Workforce Authentication plugin includes a powerful permissions system that allows external plugins and PWA apps to register their own permissions and control user access based on department memberships.

## Overview

The permissions system works by:
1. Apps/plugins register permissions during WordPress initialization
2. Administrators assign permissions to departments/teams via the admin interface
3. Users inherit permissions from all departments they belong to
4. Apps check permissions before granting access to features

## Registering Permissions

To register permissions for your app or plugin, use the `wfa_register_permissions` action hook:

```php
<?php
/**
 * Register permissions for your app.
 */
add_action('wfa_register_permissions', function($permissions) {
    // Register a permission
    $permissions->register_permission(
        'my_app.view',                          // Unique permission key
        'View My App',                          // Human-readable name
        'Allows viewing the My App interface',  // Description
        'My App'                                // App/module name
    );

    // Register additional permissions
    $permissions->register_permission(
        'my_app.edit',
        'Edit My App Data',
        'Allows creating and editing data in My App',
        'My App'
    );

    $permissions->register_permission(
        'my_app.delete',
        'Delete My App Data',
        'Allows deleting data in My App',
        'My App'
    );

    $permissions->register_permission(
        'my_app.admin',
        'My App Administration',
        'Full administrative access to My App',
        'My App'
    );
});
```

### Permission Key Naming Convention

Use a namespaced format for permission keys to avoid conflicts:
- Format: `app_name.permission_name`
- Examples:
  - `booking_app.view`
  - `booking_app.create_booking`
  - `booking_app.manage_settings`
  - `inventory.view_stock`
  - `inventory.update_stock`
  - `reports.view`
  - `reports.export`

## Checking Permissions

### Using Helper Functions

The easiest way to check permissions is using the provided helper functions:

```php
<?php
// Check if current user has a permission
if (wfa_user_can('my_app.view')) {
    // User has permission - show the app
    display_my_app();
} else {
    // User doesn't have permission - show error
    wp_die('You do not have permission to access this app.');
}

// Check permission for a specific user
$user_id = 123;
if (wfa_user_can('my_app.edit', $user_id)) {
    // User can edit
}

// Get all permissions for current user
$permissions = wfa_get_user_permissions();
// Returns: array('my_app.view', 'my_app.edit', 'booking_app.view', ...)
```

### Using the Permissions Class Directly

For more advanced use cases, you can access the permissions class directly:

```php
<?php
$permissions = wfa()->permissions;

// Check if user has permission
$user_id = get_current_user_id();
if ($permissions->user_has_permission($user_id, 'my_app.admin')) {
    // Show admin interface
}

// Get all permissions for a user
$user_permissions = $permissions->get_user_permissions($user_id);

// Get departments that have a specific permission
$dept_ids = $permissions->get_departments_with_permission('my_app.view');
```

## Complete Plugin Example

Here's a complete example plugin that uses the permissions system:

```php
<?php
/**
 * Plugin Name: My Booking App
 * Description: Example app using Workforce Authentication permissions
 */

// Register permissions on init
add_action('wfa_register_permissions', function($permissions) {
    $permissions->register_permission(
        'booking_app.view',
        'View Booking App',
        'Allows access to the booking app interface',
        'Booking App'
    );

    $permissions->register_permission(
        'booking_app.create',
        'Create Bookings',
        'Allows creating new bookings',
        'Booking App'
    );

    $permissions->register_permission(
        'booking_app.manage',
        'Manage All Bookings',
        'Allows viewing and editing all bookings',
        'Booking App'
    );
});

// Add admin menu page
add_action('admin_menu', function() {
    add_menu_page(
        'Booking App',
        'Bookings',
        'read', // Low capability - we'll check WFA permissions inside
        'booking-app',
        'render_booking_app_page',
        'dashicons-calendar-alt'
    );
});

// Render the app page with permission checks
function render_booking_app_page() {
    // Check if user has view permission
    if (!wfa_user_can('booking_app.view')) {
        wp_die(
            '<h1>Access Denied</h1><p>You do not have permission to access the Booking App. Please contact your administrator.</p>',
            'Access Denied',
            array('response' => 403)
        );
    }

    $can_create = wfa_user_can('booking_app.create');
    $can_manage = wfa_user_can('booking_app.manage');

    ?>
    <div class="wrap">
        <h1>Booking App</h1>

        <?php if ($can_create): ?>
            <a href="<?php echo admin_url('admin.php?page=booking-app&action=create'); ?>" class="button button-primary">
                Create New Booking
            </a>
        <?php endif; ?>

        <div class="bookings-list">
            <?php
            // Show appropriate bookings based on permissions
            if ($can_manage) {
                // Show all bookings
                display_all_bookings();
            } else {
                // Show only user's own bookings
                display_user_bookings(get_current_user_id());
            }
            ?>
        </div>
    </div>
    <?php
}

// REST API endpoint example
add_action('rest_api_init', function() {
    register_rest_route('booking-app/v1', '/bookings', array(
        'methods' => 'GET',
        'callback' => 'get_bookings_endpoint',
        'permission_callback' => function() {
            // Check WFA permission
            return wfa_user_can('booking_app.view');
        }
    ));

    register_rest_route('booking-app/v1', '/bookings', array(
        'methods' => 'POST',
        'callback' => 'create_booking_endpoint',
        'permission_callback' => function() {
            return wfa_user_can('booking_app.create');
        }
    ));
});
```

## Assigning Permissions to Departments

Administrators can assign permissions to departments from the WordPress admin:

1. Navigate to **Workforce Auth → Teams**
2. Click the **Permissions** button next to any team/department
3. Check the permissions you want to grant to that department
4. Permissions are saved automatically as you check/uncheck them

Users will inherit permissions from **all** departments they belong to.

## Advanced Usage

### Programmatically Grant/Revoke Permissions

```php
<?php
$permissions = wfa()->permissions;

// Grant a permission to a department
$dept_id = 5;
$permissions->grant_permission($dept_id, 'my_app.admin');

// Revoke a permission
$permissions->revoke_permission($dept_id, 'my_app.admin');

// Get all permissions for a department
$dept_permissions = $permissions->get_department_permissions($dept_id);
```

### Delete a Permission

```php
<?php
// Remove a permission and all its assignments
$permissions = wfa()->permissions;
$permissions->delete_permission('my_app.old_feature');
```

## Database Structure

The permissions system uses two database tables:

### `wp_workforce_permissions`
Stores registered permissions.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| permission_key | varchar(100) | Unique permission identifier |
| permission_name | varchar(255) | Human-readable name |
| permission_description | text | What the permission grants |
| app_name | varchar(100) | App/module that owns this permission |
| created_at | datetime | When registered |

### `wp_workforce_department_permissions`
Maps permissions to departments.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| department_id | bigint | Department ID |
| permission_key | varchar(100) | Permission key |
| granted_at | datetime | When granted |

## Best Practices

1. **Use descriptive permission names**: Make it clear what each permission allows
2. **Group related permissions**: Use consistent app_name values to group permissions
3. **Check permissions early**: Verify permissions at the entry point of your feature
4. **Provide clear error messages**: Tell users what permission they need
5. **Document your permissions**: List all permissions your app uses in your documentation
6. **Use hierarchical permissions**: Consider having "view" permissions checked alongside "edit" permissions
7. **Cache permission checks**: If checking the same permission repeatedly, cache the result

## Example Permission Hierarchies

### E-commerce App
```
store.view             - View the store interface
store.view_products    - View product catalog
store.edit_products    - Create/edit products
store.manage_orders    - View and process orders
store.settings         - Manage store settings
```

### Reporting Module
```
reports.view           - View reports
reports.export         - Export reports
reports.schedule       - Schedule automated reports
reports.create_custom  - Create custom reports
```

### Content Management
```
content.view           - View content
content.create         - Create new content
content.edit_own       - Edit own content
content.edit_all       - Edit all content
content.publish        - Publish content
content.delete         - Delete content
```

## Common Pitfalls & Troubleshooting

### Error: "Cannot use object of type WFA_Permissions as array"

**Problem:** Trying to register permissions using array syntax instead of the object method.

**Incorrect:**
```php
add_action('wfa_register_permissions', function($permissions) {
    // ❌ WRONG - This will cause a fatal error
    $permissions['my_app.view'] = array(
        'name' => 'View My App',
        'description' => 'Allows viewing',
        'app_name' => 'My App'
    );
});
```

**Correct:**
```php
add_action('wfa_register_permissions', function($permissions) {
    // ✅ CORRECT - Use the register_permission() method
    $permissions->register_permission(
        'my_app.view',           // Permission key
        'View My App',           // Permission name
        'Allows viewing',        // Description
        'My App'                 // App name
    );
});
```

### Error: "Call to undefined method WFA_Permissions::register()"

**Problem:** Using the wrong method name. The method is `register_permission()` not `register()`.

**Incorrect:**
```php
add_action('wfa_register_permissions', function($permissions) {
    // ❌ WRONG - Method name is incorrect
    $permissions->register(
        'my_app.view',
        'View My App',
        'Description',
        'My App'
    );
});
```

**Correct:**
```php
add_action('wfa_register_permissions', function($permissions) {
    // ✅ CORRECT - Use register_permission()
    $permissions->register_permission(
        'my_app.view',
        'View My App',
        'Description',
        'My App'
    );
});
```

### WFA_Permissions API Reference

The `$permissions` object passed to the `wfa_register_permissions` hook has these methods:

```php
// Register a new permission
$permissions->register_permission(
    string $permission_key,        // Required: Unique key (e.g., 'my_app.view')
    string $permission_name,       // Required: Display name
    string $permission_description, // Optional: Description (default: '')
    string $app_name               // Optional: App/module name (default: '')
): bool|WP_Error

// Get all registered permissions (optionally filtered by app)
$permissions->get_permissions(string $app_name = ''): array

// Grant permission to a department
$permissions->grant_permission(
    int $department_id,
    string $permission_key
): bool|WP_Error

// Revoke permission from a department
$permissions->revoke_permission(
    int $department_id,
    string $permission_key
): bool

// Check if user has permission
$permissions->user_has_permission(
    int $user_id,
    string $permission_key
): bool

// Get all permissions for a user
$permissions->get_user_permissions(int $user_id): array

// Get all permissions for a department
$permissions->get_department_permissions(int $department_id): array

// Get departments that have a permission
$permissions->get_departments_with_permission(string $permission_key): array

// Delete a permission completely
$permissions->delete_permission(string $permission_key): bool
```

### Parameter Count Errors

**Problem:** Passing too many or too few parameters.

The `register_permission()` method accepts exactly **4 parameters**:
1. `$permission_key` (required)
2. `$permission_name` (required)
3. `$permission_description` (optional, defaults to '')
4. `$app_name` (optional, defaults to '')

**Incorrect:**
```php
// ❌ WRONG - Too many parameters (5th parameter doesn't exist)
$permissions->register_permission(
    'my_app.view',
    'View My App',
    'Description',
    'My App',
    'housekeeping'  // ← This parameter doesn't exist!
);
```

**Correct:**
```php
// ✅ CORRECT - Only 4 parameters
$permissions->register_permission(
    'my_app.view',
    'View My App',
    'Description',
    'My App'
);
```

### WordPress Administrators Always Have Permission

Note that users with the `manage_options` capability (WordPress administrators) automatically pass **all** permission checks, regardless of their department memberships or assigned permissions.

```php
// This will return true for administrators, even without explicit permission grants
wfa_user_can('my_app.view'); // true for admins
```

### Checking If WFA Functions Exist

Always check if Workforce Authentication functions exist before using them:

```php
if (function_exists('wfa_user_can')) {
    $has_permission = wfa_user_can('my_app.view');
} else {
    // Fallback if WFA is not active
    $has_permission = current_user_can('edit_posts');
}
```

## Support

For issues or questions about the permissions system, please open an issue on the GitHub repository.
