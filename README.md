# PencariMovie Downloader

https://github.com/user-attachments/assets/bc479972-420a-473f-97e5-10384ce216ee

Local Telegram file downloader.

This app runs on your own device and opens in your browser. It searches PencariMovie results, resolves Telegram file links when needed, then downloads or plays supported files through the local downloader.

## Warning

- Use only on a trusted private network.
- Do not expose your bot token or config files.

## Download and run

### Windows

```powershell
Invoke-WebRequest -Uri https://github.com/aiskendi/pencarimovie-downloader/releases/download/v1.0.0/pencarimovie-downloader-windows-x86_64.zip -OutFile pencarimovie.zip; Expand-Archive pencarimovie.zip -DestinationPath . -Force; .\start.bat
```

### Linux

```bash
mkdir pencarimovie-downloader && cd pencarimovie-downloader && curl -L -o pencarimovie.tar.gz https://github.com/aiskendi/pencarimovie-downloader/releases/download/v1.0.0/pencarimovie-downloader-linux-x86_64.tar.gz && tar -xzf pencarimovie.tar.gz && bash start.sh
```

### macOS (not yet tested)

Choose the correct macOS package for your Mac:

- Apple Silicon: `pencarimovie-downloader-mac-arm64.tar.gz`
- Intel Mac: `pencarimovie-downloader-mac-x86_64.tar.gz`

```bash
mkdir pencarimovie-downloader && cd pencarimovie-downloader && curl -L -o pencarimovie.tar.gz https://github.com/aiskendi/pencarimovie-downloader/releases/download/v1.0.0/pencarimovie-downloader-mac-arm64.tar.gz && tar -xzf pencarimovie.tar.gz && bash start.sh
```

### Termux (Android)

The Google Play version of Termux will not work correctly. Install Termux from the official GitHub releases page instead: https://github.com/termux/termux-app/releases

For most modern Android phones with ARM64 CPUs, this APK should work: https://github.com/termux/termux-app/releases/download/v0.118.3/termux-app_v0.118.3+github-debug_arm64-v8a.apk

```bash
pkg install wget proot -y && mkdir pencarimovie-downloader && cd pencarimovie-downloader && wget https://github.com/aiskendi/pencarimovie-downloader/releases/download/v1.0.0/pencarimovie-downloader-linux-aarch64.tar.gz -O pencarimovie.tar.gz && tar -xzf pencarimovie.tar.gz && bash install-termux.sh && bash start-termux.sh
```

Restart on Termux:

```bash
bash restart-termux.sh
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

If the app opens the search page without asking for a bot token, click **Reset** and refresh the browser. The app must have a bot token saved in `storage/config.json` before downloads can work.

Do not share your local URL, `storage/config.json`, or bot token with other people.
