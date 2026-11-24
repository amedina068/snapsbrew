require "json"
require "openssl"
require "uri"

# Build a Snapsbrew signed URL. Run this on your server only.
# The signing key is a secret.
#
# options holds the other query parameters, for example
# { "format" => "webp", "transparent" => "1" }.
def snapsbrew_signed_url(base_url, signing_key, template_id, variants = [], options = {})
  params = { "template_id" => template_id }.merge(options.transform_keys(&:to_s))

  unless variants.empty?
    # Standard base64, with + / and = padding. Do not use base64url.
    params["variants"] = [JSON.generate(variants)].pack("m0")
  end

  # Sort by key in byte order. Join the raw values, not URL-encoded values.
  keys = params.keys.sort
  canonical = keys.map { |key| "#{key}=#{params[key]}" }.join("&")

  signature = OpenSSL::HMAC.hexdigest("SHA256", signing_key, canonical)

  # URL-encode every value in the final URL.
  query = keys.map { |key| "#{key}=#{URI.encode_www_form_component(params[key].to_s)}" }.join("&")

  "#{base_url.chomp('/')}/signed-url/image.jpg?#{query}&signature=#{signature}"
end
