# YouTube to Streamit

**English** · [Español](README.es.md) · [Português (Brasil)](README.pt-BR.md)

WordPress plugin that **automatically imports the videos of your YouTube playlists as episodes of [Streamit](https://iqonic.design/)**, puts them inside their program and season, and **emails you** when a program reaches the episode number you choose (for example, 13).

> Independent project. **It is not affiliated with or endorsed by Iqonic or Streamit.** Streamit is a commercial product of its authors; this repository does not include its code.

## Features

- One Streamit program = one YouTube playlist. Every new video becomes an episode, with title, description and thumbnail (converted to WebP when the server can).
- Own menu **YouTube to Streamit** with seven sections: *Synced programs*, *Playlist videos*, *Settings*, *Tools*, *Imported episodes*, *Sync log* and *System check*.
- Program picker **with search** and **paginated** lists (built for many programs).
- **Choose which videos to import**, one by one, and **detect episodes you already had** — even if their title differs from YouTube's.
- Turn programs on/off with a checkbox, or sync a single one by hand.
- Reads YouTube through its **official API** (recommended) or the **RSS feed** as a fallback, with retries.
- **Works on phones and tablets**: tables become cards on small screens.
- Available in **English, Spanish and Portuguese**.
- A tool to **repair** episodes that were not linked to their program.

## Requirements

- WordPress 5.8+ and PHP 7.4+.
- **Streamit 1.3.x** plugin active (tested with 1.3.9).

## Installation

1. Download `yt-to-streamit-x.y.z.zip` (*Releases* section).
2. In WordPress: **Plugins → Add New → Upload Plugin** and activate “YouTube to Streamit”.
3. Open **YouTube to Streamit** in the WordPress side menu.

## Quick setup

1. **Settings:** choose how you load videos today (embed code or URL, the same as your current episodes), your notification email and the frequency (every 12 hours by default). Save.
2. **Synced programs → + Add program:** search and choose the Streamit program, paste its playlist URL or ID and set the episode that should trigger the notice.
3. On the same page set **Import videos from** (avoids importing the whole history) and **Status when importing**. Start with *Draft*. **Save changes**.
4. **System check → Check + creation test:** everything should be green.
5. Press **Sync now**. A notice links to the **Sync log**, where every video is listed with what happened to it. Review an episode in Streamit and, when it looks right, switch to *Published*.

Automatic sync runs **every 12 hours** by default (you can change it: 30 min, 1 h, 6 h, 12 h or 24 h). It uses WP-Cron, which only fires when someone visits the site. To import right away use **Sync now**.

## If you already have episodes loaded

- **Automatic detection:** the plugin reads the link or embed code of every Streamit episode and recognises the YouTube videos that are already there, **even if the title is different**. Those videos are skipped and shown with a link to the episode.
- **Manual selection:** in *Synced programs* set the **Videos to import** column to “Only the ones I choose” and press **Choose videos**. You will see the playlist with a checkbox per video. In “All new videos” mode everything new is imported except what you uncheck.
- If an old episode has the video **uploaded as a file** (not a YouTube link) it cannot be recognised; use manual mode and leave that video unchecked.
- What you check by hand takes priority over *Import videos from*.

## YouTube API key (recommended)

The YouTube RSS feed fails intermittently (it can answer 404 for hours). With a free API key the plugin reads playlists through the official API and keeps RSS as a fallback.

1. In [Google Cloud](https://console.cloud.google.com/) create a project and enable **YouTube Data API v3**.
2. Create an **API key** and restrict it to that API.
3. Paste it in **Settings**, or better, define it in `wp-config.php` so it is not stored in the database:

```php
define( 'YTS_API_KEY', 'your-key' );
```

## Languages and mobile

- The interface follows your WordPress language: **Settings → General → Site Language**, or per user in **Users → Profile → Language**. English, Spanish (Spain, Chile, Colombia, Mexico, Argentina) and Portuguese (Brazil, Portugal) are included.
- On phones the menu tabs slide sideways, tables turn into cards, the save bar stays at the bottom and fields are sized for touch.

## Troubleshooting

| Symptom | What it means |
|---|---|
| `HTTP 404` when reading the playlist | Temporary failure of YouTube's RSS feed. It retries on its own; use the API key to avoid it. |
| `quota` in the report | The daily API quota is used up (it resets at midnight, US Pacific time). |
| Video skipped for the “start date” | YouTube uses the video's publish date, not the date it was added to the playlist. Adjust *Import videos from*. |
| Episodes not showing in the program | **Tools → Repair links**. |

More detail in [MANTENIMIENTO.md](MANTENIMIENTO.md) (maintenance guide, in Spanish).

## Security

- Every panel action requires administrator permission and a nonce (POST forms).
- The API key is not shown in the panel once saved and can live in `wp-config.php`.
- Data coming from YouTube (video IDs, titles, descriptions) is validated and escaped before use.
- **Deleting** the plugin removes its settings; imported episodes are kept.

## Development

```bash
php tests/smoke-test.php                                          # plugin logic, no WordPress needed (141 checks)
npm install jsdom --no-save && node tests/panel-test.js           # panel JavaScript (42 checks, optional)
npm install puppeteer-core @sparticuz/chromium --no-save && node tests/mobile-test.js   # responsive layout in a real browser (116 checks, optional)
bin/update-translations.sh                                        # refresh .pot/.po/.mo after changing texts
```

Structure and recipes: [MANTENIMIENTO.md](MANTENIMIENTO.md). Changes: [CHANGELOG.md](CHANGELOG.md).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
