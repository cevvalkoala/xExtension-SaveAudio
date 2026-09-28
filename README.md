# xExtension-SaveAudio
FreshRSS extension that adds a save audio button to article headline bars whenever it detects an audio file in the article. Useful for downloading podcasts.

## This extension is built completely via AI.
I don't know much about FreshRSS extensions and I'm just a novice with PHP. So, I won't claim that I thoroughly understand how it works. But I tested it on my personal single-user FreshRSS setup for weeks without issues.

I understand most people have reservations about using slopcoded applications. Still, feel free to analyze, use, debug, revise, or otherwise comment on the code. The code is yours, hoping it will make your RSS setup a bit more useful.

## What it does
I'm an old school podcast listener and say, you're like me and subscribed to the RSS feeds of many podcasts. Say, you  to download the audio files of the episodes. You'll see a down arrow on the headline bar, and clicking it will automatically start the download process on your browser. The arrow will not appear if the article does not contain any audio file.

## Installation
Assuming you already have a running FreshRSS instance, installing the extension is just about dropping its folder into FreshRSS's `extensions/` directory and enabling it from the web UI.

### 1. Put the files in place

The goal is to have the extension's files at `<FreshRSS>/extensions/xExtension-SaveAudio/`:

```bash
cd /.../FreshRSS/extensions
# or depending on your setup, /.../FreshRSS/public/extensions
sudo git clone https://github.com/cevvalkoala/xExtension-SaveAudio
# make sure the web server user can read it (skip if not applicable)
sudo chown -R www-data:www-data xExtension-SaveAudio
```

### 2. Enable it in FreshRSS

1. Open FreshRSS → **Settings (gear) → Extensions**.
2. Find **Save Audio** in the list and click **Enable**.
3. You can also make the article read upon clicking the download button. See the extension's configuration page.
4. Return to your feeds. The next time a feed item containing an audio file appears in one of your feeds, you'll see the download button in the shape of a down arrow on its headline bar.

To update at a later time, I guess `git pull` inside the folder (or re‑copy the files) and hard‑refresh would do the trick.
