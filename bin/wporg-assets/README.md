# WordPress.org listing artwork

The finished files live in `/.wordpress-org/` and are uploaded to the plugin's SVN
`assets/` folder (not `trunk/`). None of them ships in the plugin zip. All of them can be
replaced at any time by committing new files to `assets/`; WordPress.org's cache can take
a few hours to show the change.

| File | Source |
|---|---|
| `icon.svg` | `apps/marketing/public/favicon.svg` in the product repo, unchanged (the master mark) |
| `icon-256x256.png`, `icon-128x128.png` | `marketing/brand/avatar-800.png`, scaled with Lanczos |
| `banner-1544x500.png`, `banner-772x250.png` | `banner.html` here, screenshot at a 1544x500 viewport, then scaled to 772x250 |
| `screenshot-1..4.png` | The plugin's own screens on the local test site, 1280 px wide, cropped |

Brand rules followed: on a banner the full lockup ("Agent Number" + the ring badge as the
10), never the disc alone and never the 10 twice; ground `#0a0a0f`; spectrum
`#4f46e5 #7c3aed #c026d3 #f43f5e`. Public copy says agentnumber10.com, never a bare
"Agent Number 10". The banner chart is an illustration of the Overview chart.

Screenshot captions are the `== Screenshots ==` list in `readme.txt`; screenshot-N.png
matches caption N. The test site shows synthetic data, with no other SEO or AI plugin
active, so no other product's name appears.

Re-render the banner: `python3 -m http.server 8765 --directory <plugin folder>`, open
`http://127.0.0.1:8765/bin/wporg-assets/banner.html` at 1544x500, take a PNG screenshot,
then `convert banner-1544x500.png -filter Lanczos -resize 772x250 banner-772x250.png`.
