# wpPluginEventSchedules
A custom WordPress plugin built for SMSC Bern 2026, a large-scale student event. The plugin manages personalized event timetables for attendees, featuring:
- CSV import to load event and participant data into a custom database schema
- Token-based access so each attendee gets a unique, shareable link to their personal schedule
- Dynamic timetable view rendered on the frontend from shortcode
- ICS export allowing attendees to add their schedule directly to any calendar app (Google Calendar, Apple Calendar, etc.)

Built from scratch in PHP as a self-teaching project. The code is heavily commented. I used comments to solidify my understanding of PHP and WordPress internals as I went, so they're intentionally verbose rather than production style.
