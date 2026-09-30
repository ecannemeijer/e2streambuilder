# E2 Stream Builder

This site builds an M3U playlist from an Enigma2 receiver and an XMLTV programme guide for TiviMate.

Open the site in a browser. Copy the addresses from the Playlist page or the EPG page. The copied URLs use the same host you used to open the site.

- Playlist: `http://YOUR-PC/channels.m3u`
- EPG: `http://YOUR-PC/epg.xml.gz`

`playlist.php` and `epg.xml` are the same files. Prefer the `.gz` EPG address in TiviMate.

On a TV, use the PC’s LAN address. A name such as `openwebif.test` only works if the TV can resolve it.

## Add the playlist in TiviMate

1. Open TiviMate settings.
2. Open Playlists and add a playlist.
3. Choose an M3U URL.
4. Paste the playlist URL and save.
5. Let TiviMate load the channels.

The playlist has three variants, also listed on the Playlist page:

- All channels
- Streams only (`playlist.php?type=stream`)
- Satellite only (`playlist.php?type=tv`)

## Add the EPG in TiviMate

1. Open TiviMate settings.
2. Open EPG, then EPG sources.
3. Add a source and paste the `epg.xml.gz` URL.
4. Update the EPG.

TiviMate attaches programmes with `tvg-id`. That value is the channel id inside the XMLTV file, for example `NPO1.nl`. The channel name in the playlist can still show a suffix such as `NPO1 HD1`. Matching uses the original service name and the service reference, so the visible suffix does not break the guide.

## Add Xtream Codes in TiviMate

Press **Xtream** next to EPG mapping. **Build Xtream** builds the live channel list in the same order as the bouquets on the receiver, and rebuilds the guide from the EPG sources already downloaded. It does not download the guide again.

1. Open TiviMate settings.
2. Open Playlists and add a playlist.
3. Choose Xtream Codes.
4. Paste the server URL, username and password from the Playlist page. The server URL is only the host, without `player_api.php`.
5. Let TiviMate load the channels and the guide.

Playback uses the same stream address as the M3U. The receiver must be reachable from the TV, just as with the playlist file.

## Download this source and Download all

On the EPG page each country has **Download this source**. That button fetches only that source, then rebuilds the combined guide.

**Download all** is above the source list and in the status section. It fetches every enabled source, matches your channels, and rebuilds the guide.

A failed download does not delete the last working guide.

## EPG mapping

Open EPG mapping to see which receiver channel is linked to which XMLTV channel.

- MATCHED: linked automatically
- MANUAL: linked by you
- UNMATCHED: no safe link
- CONFLICT: the service reference and the channel name disagree

Pick a channel, search for the XMLTV channel, and save. A manual link is kept and always wins over automatic matching. Clear removes it and lets automatic matching run again.

## Themes

The theme menu in the navigation switches Dark, Light, Ocean, and Amber. The choice is stored in this browser.

## Automatic update

The guide refreshes once per interval when the playlist or the EPG is requested. Change the interval on the EPG page. For a fixed time, schedule `epg-update.bat` in Task Scheduler. `epg-update.php --force` runs an update immediately from the command line.

## How it works

The **How it works** button in the navigation opens a short version of this guide. Opening EPG or EPG mapping shows a loading spinner while the receiver is contacted.
