# PencariMovie Server

Local Telegram file downloader.

This app runs on your own device and opens in your browser. It searches PencariMovie results, resolves Telegram file links when needed, then downloads or plays supported files through the local downloader.

## FAQ

### ❓ What does it do?

- **Direct bot server connection** – Talks straight to Telegram’s MTProto, which is faster than going through a normal user client.
- **No Telegram client needed** – Everything runs in your web browser; no app, no installation.
- **Zero dependencies** – A standalone script powered by FrankenPHP. No PHP, no web server, nothing extra to install.

### ❓ Why do I need it?

- **Privacy first** – Log in with just a bot token, no phone number required. (Ask a family member or friend with Telegram to create a bot for you – it’s super easy.)
- **Works on any device** – Smart TVs, TV boxes, car tablets… if it has a browser, it works. Just open `http://your-lan-ip:port`.
- **Straight to the point** – No cluttered chats, no channels, no bot search. Just pure, focused file searching.

### ❓ How do I get started?

1. Get a bot token from [@CreateNewTelegramBot](https://t.me/CreateNewTelegramBot?start=localserver) (takes 30 seconds).
2. Run the script and open the web panel.
3. Start searching and downloading instantly.

## Stremio / Nuvio Addon

The server includes a built‑in addon compatible with Stremio (and Nuvio). Once the server is running and a bot token is configured, you can add it to your Stremio client:

- **Addon URL**: `http://127.0.0.1:8088` (or replace with your LAN IP if accessing from another device).
- **Endpoints**:
  - `http://127.0.0.1:8088/manifest.json` – addon manifest (automatically used when you add the base URL).

### How to install in Stremio

1. Open Stremio.
2. Go to **Addons** → **Community Addons**.
3. In the **Addon URL** field, enter `http://127.0.0.1:8088/manifest.json` (or your local IP if the server is on another device).
4. Click **Install**.
5. The addon will appear in your addon list; you can now browse and play content from your PencariMovie library.

### Requirements

- The server must be running and the bot token must be validated (the web panel will guide you through setup).
- The addon uses the same token – no additional configuration is needed.
- For streaming, ensure your Stremio player can handle the media formats returned by the server.

## Warning

- Use only on a trusted private network.
- Do not expose your bot token or config files.

## Download and run

### Android App 

ARM64
https://github.com/aiskendi/pencarimovie-downloader/releases/download/v1.0.0/pencarimovie_arm64-v8a.apk

### Termux (Android)

The Google Play version of Termux will not work correctly. Install Termux from the official GitHub releases page instead: https://github.com/termux/termux-app/releases

For most modern Android phones with ARM64 CPUs, this APK should work: https://github.com/termux/termux-app/releases/download/v0.118.3/termux-app_v0.118.3+github-debug_arm64-v8a.apk

```bash
pkg install wget proot -y && wget https://github.com/aiskendi/pencarimovie-downloader/releases/download/v1.0.0/pencarimovie-termux.sh && bash pencarimovie-termux.sh
```

```bash
bash pencarimovie-termux.sh
bash pencarimovie-termux.sh --stop
bash pencarimovie-termux.sh --restart
```


### Windows

```powershell
Invoke-WebRequest -Uri "https://github.com/aiskendi/pencarimovie-downloader/releases/download/v1.0.0/pencarimovie-windows.bat" -OutFile "pencarimovie-windows.bat" -UseBasicParsing; .\pencarimovie-windows.bat
```

```
.\pencarimovie-windows.bat           # Start
.\pencarimovie-windows.bat --stop    # Stop
.\pencarimovie-windows.bat --restart # Restart
```

### Linux

```bash
curl -L -o pencarimovie-linux.sh https://github.com/aiskendi/pencarimovie-downloader/releas
es/download/v1.0.0/pencarimovie-linux.sh && bash pencarimovie-linux.sh
```

```bash
bash pencarimovie-linux.sh
bash pencarimovie-linux.sh --stop
bash pencarimovie-linux.sh --restart
```


### macOS (not yet tested)

Choose the correct macOS package for your Mac:

- Apple Silicon: `pencarimovie-downloader-mac-arm64.tar.gz`
- Intel Mac: `pencarimovie-downloader-mac-x86_64.tar.gz`

```bash
mkdir pencarimovie-downloader && cd pencarimovie-downloader && curl -L -o pencarimovie.tar.gz https://github.com/aiskendi/pencarimovie-downloader/releases/download/v1.0.0/pencarimovie-downloader-mac-arm64.tar.gz && tar -xzf pencarimovie.tar.gz && bash start.sh
```

### On Browser

Open the local app:

```text
http://127.0.0.1:8088
```

On first launch, the app shows the bot-token setup screen before the search page.

1. Paste your Telegram bot token in the **Bot Token** field.
2. Click **Validate & Continue**.
3. Wait until the token is saved locally and synced to PencariMovie.
4. After validation succeeds, the search page opens.
5. Search a title, then click **Download** or **Resolve Link**.

Do not share your local URL or bot token with other people.
