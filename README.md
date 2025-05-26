# LCD People Manager

Manages people and their roles in the LCD organization

## Features

- Custom post type for People records
- User registration integration
- Integration with ActBlue for dues processing
- Integration with Sender.net for email marketing
- Integration with Forminator for volunteer sign-ups
- Admin interface for managing people and their data

## User Registration Integration

The plugin automatically connects WordPress user registrations to People records:

### How it Works

1. **New User Registration**: When someone registers on your WordPress site, the plugin:
   - Searches for an existing Person record matching their email address
   - If found, connects the new user account to the existing Person record
   - If not found, creates a new Person record and connects it to the user account

2. **Login Fallback**: As a backup, the plugin also checks during user login:
   - If a user doesn't have a connected Person record, it searches for one by email
   - Connects existing records or creates new ones as needed

3. **Data Synchronization**: When connecting:
   - Person records are updated with user information (name, email) if fields are empty
   - User registration date is stored in the Person record
   - Default membership status is set to "inactive"

### Hooks and Actions

The plugin provides several WordPress actions that developers can hook into:

- `lcd_person_created_from_registration` - Fired when a new Person record is created from user registration
- `lcd_person_connected_to_user` - Fired when an existing Person record is connected to a user account

### Admin Management

#### User Connections Page

Navigate to **People → User Connections** in the WordPress admin to:

- View statistics on connected vs. unconnected users and people
- Bulk connect existing users to People records
- See a list of unconnected users with potential matches

#### Manual Connection

Administrators can manually connect users to people records using the `connect_user_to_person($user_id, $person_id)` method.

### Database Structure

**User Meta**: 
- `_lcd_person_id` - Stores the connected Person record ID on the user

**Person Meta**:
- `_lcd_person_user_id` - Stores the connected WordPress user ID on the Person record
- `_lcd_person_email` - Person's email address
- `_lcd_person_first_name` - Person's first name
- `_lcd_person_last_name` - Person's last name
- `_lcd_person_registration_date` - Date when the user account was created
- `_lcd_person_membership_status` - Membership status (active, inactive, expired, etc.)

## Integration Features

- Member management with custom post types
- ActBlue integration for membership dues
- Sender.net integration for email communications
- Front-end member profile display

## Member Profile Features

The plugin now includes a front-end member profile that allows users to view their membership information.

### Usage

There are two ways to display member profiles on the front-end:

#### 1. Page Template

Create a new page and select the "Member Profile" template from the Page Attributes section. The profile will be displayed automatically to logged-in users. Users without a linked person record will still see their dashboard with basic contact information from their WordPress user account, but no membership data.

#### 2. Shortcode

Use the `[lcd_member_profile]` shortcode on any page or post to display the member profile. 

Example:
```
[lcd_member_profile]
```

### Shortcode Parameters

- `redirect_login` (default: true) - Whether to show a login message for non-logged-in users or redirect to the login page.

Example:
```
[lcd_member_profile redirect_login="false"]
```

### Linking Users to Person Records

For users to see their profiles, their WordPress user account must be linked to a person record in the admin:

1. Edit a person record
2. In the "Contact Information" section, use the "Connected WordPress User" field to select a user
3. Save the person record

## Developer Notes

The front-end functionality is contained in a separate class for better organization:

- `LCD_People_Frontend` - Located in `/includes/class-lcd-people-frontend.php`
- Template file - Located in `/templates/template-member-profile.php`
- Assets - CSS/JS files are in `/assets/css/frontend.css` and `/assets/js/frontend.js`

### Graceful Handling

The member profile gracefully handles users who don't yet have person records by:
- Displaying basic user information from their WordPress account
- Showing a friendly notice that their member record is being set up
- Providing the full dashboard interface without membership-specific data

## Styling the Profile

You can customize the appearance of the member profile by adding custom CSS to your theme or a CSS plugin.
The profile uses the following main CSS classes:

- `.lcd-member-profile-container` - The overall container
- `.lcd-member-profile` - The profile wrapper
- `.lcd-member-profile-section` - Each section of information
- `.lcd-membership-badge` - Status badges (active, expired, etc.)
- `.lcd-member-no-record-notice` - Notice shown to users without person records 