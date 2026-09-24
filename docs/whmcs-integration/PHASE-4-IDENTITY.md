# Phase 4: Customer Identity Integration

Pass logged-in customer data to the chatbot so it shows account-specific information.

---

## Files in This Folder

1. **ai-identity-helper.tpl** — Token generator (CREATE this file)
2. **ai-search-with-identity.tpl** — Updated search template (REPLACE your current one)
3. **SECURITY.md** — Security considerations
4. **DEPLOYMENT.md** — Step-by-step deployment guide

---

## What This Does

When a customer logs into WHMCS and uses the chat:

✅ **Before:** "Sorry, I can't access your account data"
✅ **After:** "You have 3 active domains expiring on Jan 15, 2027..."

The chatbot can now:
- Show domains and expiration dates
- Show active services
- Show billing status
- Suggest renewals
- Provide account-specific support

---

## ⚠️ SECURITY REQUIREMENTS (READ CAREFULLY)

### Three Secrets to Manage

1. **APP_KEY** — Signs conversation ownership (already in .env)
   - ✅ Protected: Store in .env, never in git
   - ✅ Already set

2. **IDENTITY_BRIDGE_SECRET** — Signs customer identity tokens
   - ⚠️ CRITICAL: Store in .env, never in git, never in templates
   - ⚠️ Generate and set on chatbot server
   - ⚠️ Copy to WHMCS config only (not in git)

3. **Chat API responses** — Always HTTPS
   - ✅ Encrypted in transit
   - ✅ Token expires quickly (default 1 hour)

### What Can Go Wrong (and how to prevent it)

| Risk | Impact | Prevention |
|------|--------|-----------|
| IDENTITY_BRIDGE_SECRET leaked | Attacker can mint fake tokens | Store in .env only, never commit to git |
| Token intercepted | Attacker impersonates customer | Use HTTPS only, token expires in 1 hour |
| Unauthorized data access | Customer sees other customer's data | WHMCS DB user is READ-ONLY only |
| Cross-origin attacks | Attacker exploits from different domain | CORS whitelist prevents this (already configured) |

---

## Deployment Steps

### Step 1: Generate IDENTITY_BRIDGE_SECRET

On your **chatbot server**:

```bash
# Generate a 32-character hex string
php -r "echo bin2hex(random_bytes(16));" 

# Output: abc123def456...
```

### Step 2: Configure Chatbot Server

Add to `/disk2/hostorio/chat.hostorio.com/.env`:

```
IDENTITY_BRIDGE_SECRET=abc123def456...
```

Restart PHP:
```bash
systemctl restart php-fpm
```

### Step 3: Copy Files to WHMCS

1. **Copy ai-identity-helper.tpl:**
   ```
   Copy: ai-identity-helper.tpl
   To: /disk2/hostorio/my.hostorio.com/templates/ho-whmcs-theme/includes/
   ```

2. **Edit ai-identity-helper.tpl on WHMCS:**
   - Find: `REPLACE_WITH_IDENTITY_BRIDGE_SECRET`
   - Replace with: The secret from Step 1
   - ⚠️ Keep this file SECRET, never commit to git

3. **Update ai-search.tpl:**
   - Replace entire file with: ai-search-with-identity.tpl
   - Or manually add this line at the top:
     ```smarty
     {include file="includes/ai-identity-helper.tpl"}
     ```
   - And add `data-chat-token="{$aiToken}"` to the section tag

### Step 4: Test

1. Log into WHMCS: `my.hostorio.com`
2. Open chat
3. Ask: "What domains do I have?"
4. Should show YOUR actual domains

---

## Troubleshooting

### Token is always empty
- ✅ Check IDENTITY_BRIDGE_SECRET is in chatbot .env
- ✅ Restart PHP on chatbot server
- ✅ Check WHMCS can reach https://chat.hostorio.com/api/identity/token

### Shows wrong customer data
- ✅ Token generation failed silently
- ✅ Check PHP error logs on both servers
- ✅ Verify IDENTITY_BRIDGE_SECRET matches

### Chat doesn't ask for customer info
- ✅ Token is not being sent
- ✅ Check data-chat-token attribute is in section tag
- ✅ Verify ai-identity-helper.tpl is included

### "I can't access your account" message
- ✅ WHMCS database is not configured in chatbot .env
- ✅ Database credentials are wrong
- ✅ Database user doesn't have SELECT permissions

---

## Files NOT to Commit to Git

- `templates/ho-whmcs-theme/includes/ai-identity-helper.tpl`
  (contains IDENTITY_BRIDGE_SECRET)

---

## Verifying Security

### Test 1: Secret Not Exposed

```bash
cd /disk2/hostorio/my.hostorio.com
grep -r "IDENTITY_BRIDGE_SECRET" templates/ --include="*.tpl"

# Should show only ai-identity-helper.tpl in includes/
# If anywhere else, remove it
```

### Test 2: HTTPS Only

```bash
curl -s https://chat.hostorio.com/api/identity/token \
  -X POST \
  -d '{"customer_id":1}' \
  -H "X-Bridge-Secret: test"

# Should reject (invalid secret) — that's good
# If says "not found", secret may not be configured
```

### Test 3: Token Expires

```bash
# Issued token expires in 1 hour by default (configurable)
# After expiry, chat goes back to anonymous mode
# This is safe — users just re-authenticate
```

---

## Database Security

Your WHMCS database user should be READ-ONLY:

```sql
-- Create read-only user for chatbot
CREATE USER 'chatbot_readonly'@'chat.hostorio.com' IDENTIFIED BY 'strong_password';
GRANT SELECT ON whmcs_db.* TO 'chatbot_readonly'@'chat.hostorio.com';
FLUSH PRIVILEGES;
```

Then in chatbot .env:
```
WHMCS_DB_USER=chatbot_readonly
WHMCS_DB_PASS=strong_password
```

---

## Questions?

If token generation fails:
1. Check chatbot server logs: `/var/log/php-fpm.log` or `/var/log/php-errors.log`
2. Verify WHMCS can reach the chatbot: `curl https://chat.hostorio.com/api/identity/token`
3. Verify secret matches on both servers

**DO NOT** put IDENTITY_BRIDGE_SECRET anywhere except .env files on your servers.
