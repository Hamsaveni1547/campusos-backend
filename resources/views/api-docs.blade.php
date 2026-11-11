<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }} API</title>
    <script src="https://unpkg.com/@stoplight/elements@8.4.2/web-components.min.js"></script>
    <link rel="stylesheet" href="https://unpkg.com/@stoplight/elements@8.4.2/styles.min.css">
    <style>html, body { margin: 0; height: 100%; }</style>
</head>
<body>
    <elements-api apiDescriptionUrl="{{ route('docs.api.json') }}" router="hash" layout="sidebar" tryItCredentialsPolicy="same-origin"></elements-api>
</body>
</html>
