# WordPress Coding Rules

## Shortcode Rules
1. Shortcode callbacks must be static methods
2. Shortcode must be registered before it's used
3. Use proper nonce verification for AJAX requests

## Resource Loading Rules
1. Resources should only be loaded when needed
2. Use conditional loading based on shortcode presence
3. Properly enqueue scripts and styles

## Error Handling Rules
1. Always check for required parameters
2. Use wp_send_json_error for AJAX failures
3. Properly sanitize all user inputs

## Security Rules
1. Use nonce verification for all AJAX requests
2. Sanitize all user inputs
3. Use prepare() for database queries
4. Check user capabilities for admin actions
