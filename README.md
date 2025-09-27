# Google Calendar Events Manager

A WordPress plugin that imports and displays events from Google Calendar using ICS feeds.

## Features

- Import events from any public or private Google Calendar ICS feed
- Display events in a responsive, customizable layout
- Multiple theme options for event display
- Caching system to improve performance
- Automatic cleanup of past events
- Support for recurring events
- Responsive design that works on all devices
- Customizable date and time formats
- Manual import option
- Import logging

## Installation

1. Upload the `google-calendar-events-manager` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings > Google Calendar and configure your ICS feed URL

## Configuration

1. **ICS Feed URL**: Get this from your Google Calendar settings under "Private address in iCal format"
2. **Theme**: Choose between different display themes
3. **Cache Duration**: Set how often the plugin should check for new events (in hours)
4. **Date/Time Format**: Customize how dates and times are displayed

## Usage

### Shortcode

Use the following shortcode to display events:

```
[gcal_events]
```

#### Shortcode Attributes

- `limit` (number): Maximum number of events to display (0 for no limit)
- `show_past` (yes/no): Whether to show past events (default: no)
- `category` (string): Filter events by category

### Examples

```
[gcal_events limit="5"]
[gcal_events show_past="yes"]
[gcal_events category="Concerts"]
```

## Styling

The plugin includes multiple themes that can be selected in the settings. You can also override the styles by adding custom CSS to your theme's stylesheet.

### Available CSS Classes

- `.gcal-events-container` - Main container for the events list
- `.gcal-event` - Individual event container
- `.gcal-event-title` - Event title
- `.gcal-event-date` - Event date
- `.gcal-event-time` - Event time
- `.gcal-event-location` - Event location
- `.gcal-event-description` - Event description

## Development

### Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- MySQL 5.6 or higher

### Building Assets

This project uses SASS for CSS preprocessing. To compile the SASS files, run:

```bash
npm install
npm run build
```

## Changelog

### 1.0.0
* Initial release

## License

GPL-2.0+

## Support

For support, please open an issue on the [GitHub repository](https://github.com/yourusername/wp-google-calendar-events).

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.
