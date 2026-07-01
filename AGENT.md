# AGENT.md - lmdbrexelpunchout

This repository is the root of the Dolibarr external module `lmdbrexelpunchout`.

Implementation rules:

- Do not modify Dolibarr core files.
- Keep the module installable under `htdocs/custom/lmdbrexelpunchout`.
- Keep `config_page_url` limited to `setup.php@lmdbrexelpunchout`.
- Preserve Dolibarr v20+ and PHP 8.0+ compatibility.
- Store business settings per entity.
- Keep Punchout imports restricted to the active owner entity of the supplier order.
- Use native Dolibarr supplier order, product and supplier price classes.
- Public return endpoints may only store the returned basket; the real import must remain authenticated and CSRF-protected.
- Do not log Rexel passwords, cXML shared secrets, Punchout tokens, or raw customer-sensitive payloads in syslog.
- Update `README.md`, `ChangeLog.md`, `langs/fr_FR/lmdbrexelpunchout.lang` and `langs/en_US/lmdbrexelpunchout.lang` for user-visible changes.
