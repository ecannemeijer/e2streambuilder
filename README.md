# E2 Stream Builder

This site turns an Enigma2 channel list into a personal M3U playlist and an XMLTV guide for TiviMate. Playback goes straight to the receiver. The server does not restream the video.

Create an account with an email address, then add a house. A house is one receiver. The house name becomes the address of that playlist, and the address stays the same if you rename the house later.

- Channels: `https://YOUR-SITE/u/kilder/channels.m3u8`
- Guide: `https://YOUR-SITE/u/kilder/epg.xml.gz`

Those two links stay online, the same way an IPTV playlist stays online. Paste them once into TiviMate. Another account cannot see your houses.

## Publish the channel list

The channel list is read from OpenWebIF. On the house page, drag **Publish house** to the bookmarks bar. Do not click it on this website.

1. On the receiver’s network, open its web page in that same browser, for example `http://192.168.1.10`.
2. Click **Publish house** on that page. The browser reads the bouquets and channels and sends them here.
3. Copy the channels address and the guide address into TiviMate.

Each bouquet becomes a category. Each channel is a stream address on the receiver, on the stream port of that house. The usual Enigma2 port is 8001. A repeated address gets a `#e2=` number so TiviMate can tell the copies apart. That number is not sent to the box.

The first line of the playlist points at that house’s guide:

```text
#EXTM3U url-tvg="https://YOUR-SITE/u/kilder/epg.xml.gz" x-tvg-url="https://YOUR-SITE/u/kilder/epg.xml.gz"
```

The player has to reach the receiver. A phone or TV on another network can load the playlist, but it cannot play the streams.

Publish again when the bouquets on the box change. The links in TiviMate stay the same. You can also download the published `m3u8` from the house page, or email the two links to the account address.

An admin whose browser is on the same network as the receiver can enter the receiver address and press **Publish playlist** instead of using the bookmark. Regular accounts always use the bookmark. They do not see the bouquet list.

## The guide

The guide comes from the Rytec XMLTV sources (`rytec.sources.xml`): the Netherlands, Flanders, the shared Netherlands/Flanders file, Wallonia, Germany, UK/Ireland, and Italy. Each matched channel gets the Rytec id, for example `NPO1.nl`, as `tvg-id`. The guide file lists those Rytec ids only. Matching uses the original service name and the service reference, so a visible suffix such as `NPO1 HD1` does not break the link.

Publishing writes a guide that contains only the channels in that house’s playlist. Every night `xtream-update.php` downloads the Rytec sources again and rebuilds every published house guide from the new programmes. The channel list is not read from the receiver at night. It changes only when you publish again.

## Add it in TiviMate

1. Open TiviMate settings, then Playlists, and add a playlist.
2. Choose an M3U URL and paste the house `channels.m3u8` address.
3. Open EPG, then EPG sources, and paste the house `epg.xml.gz` address.
4. Update the playlist and the EPG.

TiviMate attaches programmes with `tvg-id`.

## Admin

An admin also has the shared receiver tools. Enter the receiver, WebIF port, and stream port next to the house and save. The Playlist page can then read the bouquets directly and offers three live lists:

- All channels: `channels.m3u8`
- Streams only: `playlist.php?type=stream`
- Satellite only: `playlist.php?type=tv`

`channels.m3u` is the same playlist. **Download e2.m3u8** stores it as `data/channels.m3u8`. Prefer a house link in TiviMate. The shared list is the receiver the server can reach.

On the EPG page, **Download this source** fetches one country file and rebuilds the shared guide. **Download all** fetches every enabled source, matches channels, and rebuilds that guide. A failed download keeps the last working guide. The spinner shows the current step. The interval on that page also refreshes the shared guide when the shared playlist or EPG is requested.

EPG mapping shows how a receiver channel is linked:

- MATCHED: linked automatically
- MANUAL: linked by you
- UNMATCHED: no safe link
- CONFLICT: the service reference and the channel name disagree

A manual link is kept and always wins. Clear removes it and lets automatic matching run again.

**Xtream**, next to EPG mapping, builds one shared Xtream catalogue in bouquet order. In TiviMate add a playlist, choose Xtream Codes, and paste the server URL, username, and password from that dialog. The server URL is only the host, without `player_api.php`. TiviMate requests `/live/username/password/id.ts`. This server checks the login and redirects to the receiver.

**EIT** builds a separate guide, `epg-eit.xml.gz`. Create All does not build that file. Create All writes the shared playlist and the shared Rytec guide.

## Logos

Channel pictures come from [tv-logo/tv-logos](https://github.com/tv-logo/tv-logos). Clone that repository once into `logos/` on the server. The folder is listed in `.gitignore`. The web server must serve `/logos/` as ordinary PNG files.

An exact filename wins. `NPO1.nl` looks for `npo1-nl.png`: lowercase, and the last dot becomes a hyphen. The search stays inside that country, so a Dutch id only uses files ending in `-nl.png`.

When that file is missing, the words in the Rytec name, its alternate names, and the name on the receiver are compared with the logo filenames. The logo that shares the most words wins. At least two words must match. One word is enough when only one logo in that country contains it. If two logos share the same number of words, the one with fewer extra words wins. Words shorter than three letters are ignored, along with `hd`, `sd`, `uhd`, `tv`, `channel`, and plain numbers. That is why **Veronica / Disney Jr.** uses `veronica-disney-xd-nl.png`. A channel with no usable overlap stays without a logo.

The same address is written in the guide `<icon>`, the playlist `tvg-logo`, and the Xtream `stream_icon`. A channel with no Rytec id still gets a `tvg-logo` from its name on the receiver.

## Automatic update

On Linux, run the nightly job as the web user so `data/` stays writable:

```cron
15 3 * * * www-data /usr/bin/php /path/to/e2streambuilder/xtream-update.php >> /path/to/e2streambuilder/data/epg/cron.log 2>&1
```

That downloads the Rytec sources, rebuilds the shared guide and every published house guide, then rebuilds the Xtream catalogue. House channel lists stay as published.

Mail and this cron job use `public_base` in `data/settings.json`. Set that to the public site address, for example `https://e2sb.duckdns.org`. Opening the site on another host does not change it. A page you are looking at still uses the host in the browser.

On Windows, Task Scheduler can run `epg-update.bat`. `epg-update.php --force` runs an EPG update immediately. That path refreshes the guide and does not rebuild Xtream.

## Files the web server must hide

`data/` holds the database, settings, and the published playlists. `data/.htaccess` denies it for Apache. Nginx does not read that file, so the server block needs its own deny for `/data/`. Leave `/logos/` public.
