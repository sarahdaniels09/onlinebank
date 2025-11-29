Verification Server

This is a minimal verification endpoint template that you should host privately (for example on a small VPS) and keep the Envato personal token secure on the server.

Usage
1. Copy `verification-server/` to a private server.
2. Edit `config.php` and set `ENVATO_API_TOKEN` (your Envato personal token with appropriate scopes).
3. Expose `index.php` over HTTPS on a private URL (for example: https://verify.example.com/).
4. In the installer, provide the License Server URL (e.g. https://verify.example.com/) when verifying purchase codes.

Security
- DO NOT commit `config.php` to public repos. Keep the server private.
- The verification server calls the Envato API and returns a small JSON payload indicating validity.

Response format
- On success (HTTP 200), returns JSON: { "valid": true, "item": { ... }, "buyer": "..." }
- On failure, returns { "valid": false, "message": "..." }
