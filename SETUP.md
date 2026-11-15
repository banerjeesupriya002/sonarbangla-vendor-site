# Sonar Bangla Haat — Free vendor onboarding (replaces Emergent)

**What you get**
- `docs/` — the vendor registration website (static, free on GitHub Pages / Cloudflare Pages). Same look & feel as today, new logo, new flow.
- `wordpress-plugin/sbh-vendor-onboarding` — a WordPress plugin for sonarbanglahaat.com. Receives applications, emails **support@sonarbanglahaat.com**, lets you **Approve / Reject with reason** inside WordPress, and emails the vendor.

**New vendor flow:** Welcome → Perks → Sign up (email, name, mobile; saves progress + emails a resume link) → GST (Yes → enter GSTIN + portal captcha → details auto-fill; No/exempt → fill manually) → Products (food categories ask for FSSAI; "Not yet" shows a tip but never blocks) → Documents → Submit.

---
## Step 1 — WordPress (5 min)
1. WordPress admin → **Plugins → Add New → Upload Plugin** → choose `sbh-vendor-onboarding.zip` → Install → **Activate**.
2. A **Vendor Applications** menu appears (with a red count of pending ones).
3. **Make sure WordPress can send email.** Install the free **WP Mail SMTP** plugin and connect it to your email/Brevo account (you already use Brevo — its free plan allows 300 mails/day). Send its built-in test email to confirm.
4. Check that `https://sonarbanglahaat.com/wp-json/sbh/v1/draft/00000000000000000000000000000000` shows a "link expired" message (JSON), not a 404 page. If it is a 404, go to Settings → Permalinks → Save.

## Step 2 — Website on GitHub Pages (free)
GitHub's free plan only serves Pages from a **public** repository. The site contains no secrets, so a new public repo is fine.
1. Create a new **public** repo (e.g. `sonarbangla-vendor-site`) and upload everything inside `docs/` to its root (or keep `docs/` and choose "Branch: main / docs" in Settings → Pages).
2. Settings → Pages → Source = your branch; Custom domain = `vendors.sonarbanglahaat.com`; tick **Enforce HTTPS** when it becomes available.
3. At your domain's DNS: change the `vendors` **CNAME** from Emergent to `<your-github-username>.github.io`. (Remove any old A/AAAA records for `vendors`.)
4. Wait 5–30 minutes for HTTPS to be issued.

*Alternative with a private repo:* Cloudflare Pages (free) can publish a private repo's `docs` folder with the same custom domain.

## Step 3 — Test, then cancel Emergent
1. Open https://vendors.sonarbanglahaat.com and submit a test application with your own email.
2. You should get: a resume email, a confirmation email, and an email to support@ with a **Review application** button.
3. In WordPress → Vendor Applications → open it → **Approve** (vendor gets a private payout-details link) or write a reason and **Reject** (vendor gets the reason).
4. **Before cancelling Emergent**, export/note any applications already sitting in the old admin panel — they do not move automatically.

## Day-to-day
- Pending applications: WordPress → Vendor Applications (filter defaults to *Pending*). Documents open only for logged-in admins.
- Bank details arrive only after approval through the vendor's one-time link; the account number is stored encrypted and visible only to administrators.
- Spam protection: hidden honeypot field + per-IP rate limits. Duplicate email/GSTIN applications are blocked.

## Good to know
- **GST lookup** talks to the GST portal from your WordPress server, using the captcha the vendor types. If the portal is down, changes its page, or blocks the server, the form tells the vendor to fill details manually — registration is never blocked. Bank-grade verification APIs are paid; this stays free.
- Documents are stored in `wp-content/uploads/sbh-vendor-docs/` with unguessable file names and an `.htaccess` deny rule. On **Nginx** hosts, ask your host to add: `location ~* /uploads/sbh-vendor-docs/ { deny all; }`.
- To change the WhatsApp button: edit `docs/assets/config.js`.
- Settings overrides (optional, `wp-config.php`): `define('SBH_ADMIN_EMAIL','you@…');` and `define('SBH_FRONT_URL','https://vendors.sonarbanglahaat.com');`
