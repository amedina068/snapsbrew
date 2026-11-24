import base64
import hashlib
import hmac
import json
from urllib.parse import quote


def snapsbrew_signed_url(base_url, signing_key, template_id, variants=None, options=None):
    """Build a Snapsbrew signed URL. Run this on your server only.

    The signing key is a secret. options holds the other query parameters
    as strings, for example {"format": "webp", "transparent": "1"}.
    """
    params = {"template_id": template_id, **(options or {})}

    if variants:
        # Standard base64, with + / and = padding. Do not use base64url.
        raw = json.dumps(variants, separators=(",", ":"), ensure_ascii=False)
        params["variants"] = base64.b64encode(raw.encode("utf-8")).decode("ascii")

    # Sort by key in byte order. Join the raw values, not URL-encoded values.
    keys = sorted(params)
    canonical = "&".join(f"{key}={params[key]}" for key in keys)

    signature = hmac.new(
        signing_key.encode("utf-8"), canonical.encode("utf-8"), hashlib.sha256
    ).hexdigest()

    # URL-encode every value in the final URL.
    query = "&".join(f"{key}={quote(str(params[key]), safe='')}" for key in keys)

    return f"{base_url.rstrip('/')}/signed-url/image.jpg?{query}&signature={signature}"
