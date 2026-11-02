# Security setup (read before deploying)

1. Copy `.env.example` to `.env` and fill in real values (or set the same names as server env vars).
   `.env` is git-ignored. Never commit real credentials.
2. Rotate any credential that was ever hardcoded in an older version of this project
   (Supabase DB password, Gmail App Password, reCAPTCHA keys).
3. Set `SITE_BASE_URL` to your public URL (used in verification / reset email links).
4. Serve over HTTPS (session cookies are `Secure` when HTTPS is detected).
5. `uploads/` folders contain private user documents. They are git-ignored; back them up separately.
   The bundled `.htaccess` files only work on Apache. On nginx/Caddy/Railway, add equivalent rules
   (deny PHP execution and directory listing inside `uploads/`), or move uploads to Supabase Storage.
