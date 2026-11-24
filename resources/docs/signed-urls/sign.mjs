import { createHmac } from "node:crypto";

// Build a Snapsbrew signed URL. Run this on your server only.
// The signing key is a secret.
//
// options holds the other query parameters, for example
// { format: "webp", transparent: "1" }.
export function snapsbrewSignedUrl(baseUrl, signingKey, templateId, variants = [], options = {}) {
  const params = { template_id: templateId, ...options };

  if (variants.length > 0) {
    // Standard base64, with + / and = padding. Do not use base64url.
    params.variants = Buffer.from(JSON.stringify(variants), "utf8").toString("base64");
  }

  // Sort by key in byte order. Join the raw values, not URL-encoded values.
  const keys = Object.keys(params).sort();
  const canonical = keys.map((key) => `${key}=${params[key]}`).join("&");

  const signature = createHmac("sha256", signingKey).update(canonical, "utf8").digest("hex");

  // URL-encode every value in the final URL.
  const query = keys
    .map((key) => `${key}=${encodeURIComponent(String(params[key]))}`)
    .concat(`signature=${signature}`)
    .join("&");

  return `${baseUrl.replace(/\/+$/, "")}/signed-url/image.jpg?${query}`;
}
