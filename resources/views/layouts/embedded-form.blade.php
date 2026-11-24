<!DOCTYPE html>
<html class="h-full bg-white">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Document</title>
  <!-- Styles -->
  @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>

  <div id="app">
    <div>
        {{ $slot }}
    </div>
  </div>
</body>
</html>