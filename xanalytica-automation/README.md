# XAnalytica Automation

Dedicated automation branch for refreshing 11 XAnalytica users.

Runtime secrets required:
- XANALYTICA_EMAIL
- XANALYTICA_PASSWORD

The job runs hourly but only performs updates at Europe/Stockholm local hours:
01:00, 05:00, 09:00, 13:00, 17:00, 21:00.

Set FORCE_RUN=1 only for an intentional manual test.
