Installer for OnlineBank

This small web installer helps create a `.env` file from `.env.example`, test the database connection, and optionally run migrations. It can also import an uploaded SQL dump, extract a ZIP containing `vendor/` and `public/` assets, and seed an initial admin user.

Important notes
- After installation, REMOVE the `installer/` directory to prevent misuse.
- Web-hosted installers can be blocked by hosting providers or have functions disabled (proc_open, exec, ZipArchive). If commands fail, follow the manual steps shown by the installer.

How to use
1. Upload the contents of the `installer/` folder to `yourdomain.com/installer/` (or copy the folder into the project and access via browser).
2. Open the installer URL and fill in App and DB settings.
3. Optionally upload a SQL dump (.sql) to import initial schema/data.
4. Optionally upload a ZIP containing `vendor/` and/or `public/` compiled assets to avoid running Composer/NPM on the server.
5. Optionally provide admin name/email/password to create a seeded admin user.
6. Confirm and run the installer.
7. Follow next steps shown by the installer (run composer, build assets if needed, set permissions, remove installer).

Envato license verification
- You can integrate Envato/CodeCanyon license verification during installation. Provide the buyer's purchase code in the "Purchase code" field.
- For automatic verification provide a privately hosted license verification endpoint URL in the "License server URL" field. The installer will POST the purchase code to your verification endpoint and consume the JSON response.
- The installer will save `ENVATO_PURCHASE_CODE` to `.env`. It will also save `LICENSE_SERVER_URL` to `.env` when verification is attempted.

Notes on Envato verification
- The recommended, secure approach is to run Envato API calls from a private verification server you control (which stores your author token securely). The installer will call that verification server; this avoids shipping an author token inside the distributed package.
- If your verification server cannot be reached or does not validate the code, the installer will still save the purchase code to `.env` for manual handling.

Security
- This installer writes `.env`, can import SQL and extract archives, and may run shell commands. Only use it on a private/temporary setup step, and delete it afterwards.
- Inspect uploaded SQL or ZIP contents before running on production.
