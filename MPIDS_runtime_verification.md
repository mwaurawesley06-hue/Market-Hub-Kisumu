# MPIDS runtime verification

- cwd: /c/xampp/htdocs/MPDIS
- date: 2026-10-13 19:47:02 UTC
- php: 8.2.12
- db: 127.0.0.1:3306/mpids as root
- sms_provider: mobilesasa
- sms_gateway_simulated: true
- sms_sandbox_secrets_visible: true

## Tables

- audit_logs: 692
- commodities: 16
- markets: 9
- price_records: 40
- rate_limit_buckets: 59
- site_settings: 7
- sms_subscriptions: 3
- users: 3
- ussd_sessions: 15

## Active admin users

| id | name | email |
|---|---|---|
| 1 | Achieng Odera | admin@mpids.co.ke |
| 2 | Wesley Mwaura | wesley@mpids.co.ke |
| 5 | Dup Workflow Tester | dup-test@mpids.local |

## Notes

- SMS gateway is in simulation mode: no real SMS leaves this machine.
- Sandbox OTP and unsubscribe tokens are visible because this request is from loopback.
- `tmp/logs/sms-sim.log` is the simulation log for SMS sends.
