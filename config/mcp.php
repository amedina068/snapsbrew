<?php

return [

    /*
    |--------------------------------------------------------------------------
    | MCP Server
    |--------------------------------------------------------------------------
    |
    | When this is false, the app registers no `/mcp` route, no OAuth discovery
    | document and no Passport route, and the account page hides the MCP
    | setup. The REST API keeps working.
    |
    */

    'enabled' => (bool) env('MCP_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Redirect Domains
    |--------------------------------------------------------------------------
    |
    | `POST /oauth/register` is open. Dynamic Client Registration (RFC 7591) has
    | no authentication step, because a connector directory must be able to
    | register itself before any user of ours has seen it.
    |
    | This list is the control that replaces authentication. It decides where an
    | authorization code can be sent. The package default is `*`, which accepts
    | every domain. With `*`, an attacker registers a client, gives it a name
    | that looks official, points the redirect at a host they own, and sends a
    | user to the consent screen. A user who approves it hands them the code.
    | PKCE does not stop this attack, because the attacker is the client.
    |
    | Keep the list at the hosts that run a connector we accept. An MCP client
    | that is not on this list still has a supported path: a Sanctum personal
    | access token, sent as a bearer token, which needs no redirect at all.
    |
    | Set MCP_REDIRECT_DOMAINS to a comma-separated list to change it.
    |
    */

    'redirect_domains' => array_filter(array_map(
        'trim',
        explode(',', (string) env('MCP_REDIRECT_DOMAINS', 'https://claude.ai,https://claude.com'))
    )),

    /*
    |--------------------------------------------------------------------------
    | Allowed Custom Schemes
    |--------------------------------------------------------------------------
    |
    | Native desktop clients use private-use URI schemes for their callback
    | (RFC 8252), for example `cursor://`. A scheme here is accepted with any
    | host, so the domain list above cannot narrow it. Add one only for a client
    | we decided to support.
    |
    | Set MCP_CUSTOM_SCHEMES to a comma-separated list to change it.
    |
    */

    'custom_schemes' => array_filter(array_map(
        'trim',
        explode(',', (string) env('MCP_CUSTOM_SCHEMES', ''))
    )),

    /*
    |--------------------------------------------------------------------------
    | Authorization Server
    |--------------------------------------------------------------------------
    |
    | The issuer identifier that the two discovery documents report (RFC 8414).
    | A null value makes both report `url('/')`, which is correct while the
    | authorization server and the MCP server run on one domain.
    |
    */

    'authorization_server' => env('MCP_AUTHORIZATION_SERVER') ?: null,

];
