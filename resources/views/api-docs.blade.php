<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Mate API Reference</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <style>
        html, body { margin: 0; background: #0d0d11; }
    </style>
</head>
<body>
    {{--
        Scalar renders the OpenAPI spec into a full interactive reference. The bundle is
        self-hosted (public/vendor/scalar) so it satisfies the app CSP (script-src 'self');
        the spec is fetched same-origin (connect-src 'self'). Config is declarative via the
        data-* attributes - no inline script, which the CSP forbids. Emerald accent to match
        the app. This page + the spec route are only reachable when demo mode is on.
    --}}
    <script
        id="api-reference"
        data-url="/api-docs/openapi.yaml"
        data-configuration='{"theme":"default","darkMode":true,"hideDarkModeToggle":false,"customCss":":root{--scalar-color-accent:#10b981;--scalar-color-accent-1:#10b981;} .dark-mode{--scalar-color-accent:#34d399;}","metaData":{"title":"My Mate API Reference"}}'
    ></script>
    <script src="/vendor/scalar/standalone.js"></script>
</body>
</html>
