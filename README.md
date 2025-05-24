# LCD People Manager

A WordPress plugin for managing members and their roles in the LCD organization.

## Features

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