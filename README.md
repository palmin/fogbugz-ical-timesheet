fogbugz-ical-timesheet
======================

PHP bridge from FogBugz timesheet to iCalendar feed.

Remember to set $baseURL in configuration part of timesheet.php at the very least.

Beware if used on non-secure website as email and password is transmitted when requesting
new token and token is sent along on every calendar refresh and can be used to perform
other actions with the FogBugz API.