# Bundled fonts

The renderer embeds these fonts in the HTML of a snap, so a render needs no internet access. They are the font families of the starter templates (`App\Support\StarterTemplates`) and of the onboarding templates (`storage/app/templates/onboarding-*.txt`). `FontLibraryTest` fails when a shipped template uses a family that is not here.

Each folder holds one family:

- `font.css`: the `@font-face` rules that Google Fonts serves for `https://fonts.googleapis.com/css2?family=<name>`. The editor asks Google for the same rules.
- One woff2 file for each unicode subset. The files have the regular weight (400) only, because the renderer and the editor ask for the regular weight only.
- `OFL.txt`: the SIL Open Font License 1.1 of the family, from the `google/fonts` repository.

`App\Rendering\FontLibrary` reads this folder first. For a family that is not here, it downloads the same files one time to `storage/app/fonts`.

To add a family, run this command. Then add the `OFL.txt` file of the family.

```
php artisan tinker --execute='config(["services.render.font_cache_path" => resource_path("fonts")]); app(App\Rendering\FontLibrary::class)->embeddedCss("Family Name");'
```
